<?php
/**
 * NewUI v4.0 — Personnel Accountability Report engine (Phase 16a).
 *
 * Public API:
 *
 *   par_enabled(): bool
 *     Master on/off flag. Off by default — admin opts in via Settings.
 *
 *   par_resolve_cadence(int $ticketId): array
 *     Returns ['cadence_minutes' => N, 'source' => 'override|type|agency|fallback',
 *              'first_window_s' => N, 'retry_window_s' => N,
 *              'escalate_after_misses' => N, 'chat_channel' => '...'].
 *
 *   par_initiate_cycle(int $ticketId, string $kind, ?int $byUserId, ?string $notes): array
 *     Initiates a PAR cycle: row in par_cycles + one par_unit_acks row per
 *     currently-assigned unit. Updates ticket.par_last_cycle_at. Emits SSE
 *     event 'par.initiated'. Returns the new cycle row + acks.
 *
 *   par_ack_unit(int $cycleId, int $responderId, array $args): array
 *     Marks a unit as acked. $args may include: by_user_id, via, member_count,
 *     comments, notes. Emits SSE event 'par.unit_acked'. If all acks are
 *     in, also emits 'par.cycle_complete'.
 *
 *   par_abort_cycle(int $cycleId, int $byUserId, ?string $reason): bool
 *     Soft-cancel a cycle. Used when dispatcher decides PAR is no longer
 *     needed (e.g., scene cleared) before timer expiry.
 *
 *   par_cycle_summary(int $cycleId): array
 *     Returns the cycle row + acks for UI rendering.
 *
 *   par_due_at(int $ticketId): ?int
 *     Returns Unix-timestamp when the next PAR is due for this incident.
 *     Used by the units page timer Eric asked for.
 */

require_once __DIR__ . '/audit.php';

function par_enabled(): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'par_enabled' LIMIT 1"
        );
        return ((int) $v) === 1;
    } catch (Exception $e) { return false; }
}

/**
 * Cadence resolution priority (Phase 16 spec):
 *   1. ticket.par_cadence_override_min  — per-incident override
 *   2. par_config WHERE scope='incident_type' AND in_types_id = ticket.in_types_id
 *   3. par_config WHERE scope='agency_default'
 *   4. settings.par_default_cadence_min
 *   5. Hardcoded fallback: 20 minutes
 *
 * If the resolved cadence is 0, PAR is disabled for this incident.
 */
function par_resolve_cadence(int $ticketId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Defaults from agency_default + settings
    $out = [
        'cadence_minutes'       => 20,
        'source'                => 'fallback',
        'first_window_s'        => 60,
        'retry_window_s'        => 120,
        'escalate_after_misses' => 2,
        'chat_channel'          => '',
    ];

    // Layer 4: settings.par_default_cadence_min
    try {
        $v = (int) db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name`='par_default_cadence_min' LIMIT 1");
        if ($v >= 0) $out['cadence_minutes'] = $v;
        $out['source'] = 'settings_default';
        $f = (int) db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name`='par_first_window_s' LIMIT 1");
        if ($f > 0) $out['first_window_s'] = $f;
        $r = (int) db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name`='par_retry_window_s' LIMIT 1");
        if ($r > 0) $out['retry_window_s'] = $r;
        $m = (int) db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name`='par_max_misses' LIMIT 1");
        if ($m > 0) $out['escalate_after_misses'] = $m;
        $c = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name`='par_escalation_chat_channel' LIMIT 1");
        if (is_string($c)) $out['chat_channel'] = $c;
    } catch (Exception $e) {}

    // Layer 3: agency_default row in par_config
    try {
        $row = db_fetch_one(
            "SELECT cadence_minutes, first_cycle_window_s, retry_cycle_window_s,
                    escalate_after_misses, chat_channel
               FROM `{$prefix}par_config`
              WHERE scope = 'agency_default' LIMIT 1"
        );
        if ($row) {
            $out['cadence_minutes']       = (int) $row['cadence_minutes'];
            $out['first_window_s']        = (int) $row['first_cycle_window_s'];
            $out['retry_window_s']        = (int) $row['retry_cycle_window_s'];
            $out['escalate_after_misses'] = (int) $row['escalate_after_misses'];
            $out['chat_channel']          = (string) ($row['chat_channel'] ?? '');
            $out['source']                = 'agency_default';
        }
    } catch (Exception $e) {}

    // Layer 2: per-incident-type override
    try {
        $tk = db_fetch_one(
            "SELECT t.in_types_id, t.par_cadence_override_min
               FROM `{$prefix}ticket` t WHERE t.id = ? LIMIT 1",
            [$ticketId]
        );
        if ($tk && $tk['in_types_id']) {
            // Phase 32 (2026-06-12) — is_disabled. When the incident-type
            // par_config row has is_disabled=1, treat as explicit
            // opt-out: cadence is forced to 0 (which par_due_at gates
            // on) with source='incident_type' so a downstream caller
            // can see WHY PAR is off for this type.
            // Old installs without the column fall through gracefully.
            $row = null;
            try {
                $row = db_fetch_one(
                    "SELECT cadence_minutes, first_cycle_window_s, retry_cycle_window_s,
                            escalate_after_misses, chat_channel, is_disabled
                       FROM `{$prefix}par_config`
                      WHERE scope = 'incident_type' AND in_types_id = ? LIMIT 1",
                    [(int) $tk['in_types_id']]
                );
            } catch (Exception $e) {
                // Pre-Phase-32 schema — retry without is_disabled.
                $row = db_fetch_one(
                    "SELECT cadence_minutes, first_cycle_window_s, retry_cycle_window_s,
                            escalate_after_misses, chat_channel
                       FROM `{$prefix}par_config`
                      WHERE scope = 'incident_type' AND in_types_id = ? LIMIT 1",
                    [(int) $tk['in_types_id']]
                );
                if ($row) $row['is_disabled'] = 0;
            }
            if ($row) {
                if (!empty($row['is_disabled'])) {
                    $out['cadence_minutes'] = 0;
                    $out['source']          = 'incident_type';
                } else {
                    $out['cadence_minutes']       = (int) $row['cadence_minutes'];
                    $out['first_window_s']        = (int) $row['first_cycle_window_s'];
                    $out['retry_window_s']        = (int) $row['retry_cycle_window_s'];
                    $out['escalate_after_misses'] = (int) $row['escalate_after_misses'];
                    $out['chat_channel']          = (string) ($row['chat_channel'] ?? '');
                    $out['source']                = 'incident_type';
                }
            }
        }

        // Layer 1: per-incident override (only the cadence itself can
        // be overridden on the ticket; window settings stay at the
        // resolved-type/agency value).
        if ($tk && $tk['par_cadence_override_min'] !== null) {
            $out['cadence_minutes'] = (int) $tk['par_cadence_override_min'];
            $out['source'] = 'incident_override';
        }
    } catch (Exception $e) {}

    return $out;
}

/**
 * When is the next PAR cycle due for this incident?
 * Returns a Unix timestamp, or null if PAR is disabled / no cadence.
 */
function par_due_at(int $ticketId): ?int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!par_enabled()) return null;
    $cad = par_resolve_cadence($ticketId);
    if ($cad['cadence_minutes'] <= 0) return null;

    // Phase 30A (2026-06-12) — only fire alarms when someone explicitly
    // opted this incident into PAR. The install-default fallbacks
    // ('settings_default', 'fallback') were causing alarms across every
    // legacy incident in every install. Eric: "incidents that are old
    // and don't appear from the UI to show any PAR cadence configured.
    // It's displaying — for the cadence. Why am I seeing warnings?"
    //
    //   * incident_override : per-ticket override input
    //   * incident_type     : per-incident-type cadence in Settings
    //   * agency_default    : explicit par_config row scope=agency_default
    //
    // The two below are starting-value defaults, not opt-ins:
    //   * settings_default  : install default for par_default_cadence_min
    //   * fallback          : hard-coded 20 min
    if (!in_array($cad['source'], ['incident_override','incident_type','agency_default'], true)) {
        return null;
    }

    // Phase 30A (2026-06-12) — PAR is an accountability roll-call. An
    // incident with no units assigned has nothing to PAR. Eric:
    // "I'm seeing PAR check warnings for incidents that do not have
    // any units assigned yet. I'd call that a bug."
    $assigned = par_assigned_units($ticketId);
    if (empty($assigned)) return null;

    // Phase 31 (2026-06-12) — per-unit timers.
    //
    // Eric: "The timer starts when a unit is dispatched, then resets
    // when they indicate responding, and again when they indicate
    // arrived. ... we'll call for a PAR check of all assigned units
    // when the first unit reaches the time limit."
    //
    // For each uncleared assigned unit, compute their last reset
    // event (status transition through a resets_par=1 status, or PAR
    // ack). The incident-level "next PAR due" is the MIN across the
    // per-unit nexts: the first unit to expire drives the alarm.
    $cadenceSecs = $cad['cadence_minutes'] * 60;
    $minDueAt    = null;
    foreach ($assigned as $unit) {
        $rid = (int) $unit['id'];
        $lastActivity = par_unit_last_activity_at($ticketId, $rid);
        if ($lastActivity === null) {
            // No measurable activity — fall back to the ticket-level
            // par_last_cycle_at or incident creation time so we don't
            // skip this unit entirely.
            $last = null;
            try {
                $last = db_fetch_value(
                    "SELECT par_last_cycle_at FROM `{$prefix}ticket` WHERE id = ? LIMIT 1",
                    [$ticketId]
                );
            } catch (Exception $e) {}
            $lastTs = ($last && $last !== '0000-00-00 00:00:00') ? strtotime($last) : null;
            if (!$lastTs) {
                try {
                    $created = db_fetch_value(
                        "SELECT `date` FROM `{$prefix}ticket` WHERE id = ? LIMIT 1",
                        [$ticketId]
                    );
                    $lastTs = $created ? strtotime($created) : time();
                } catch (Exception $e) { $lastTs = time(); }
            }
            $lastActivity = $lastTs;
        }
        $unitDueAt = $lastActivity + $cadenceSecs;
        if ($minDueAt === null || $unitDueAt < $minDueAt) {
            $minDueAt = $unitDueAt;
        }
    }
    return $minDueAt;
}

/**
 * Phase 31 (2026-06-12) — compute the per-unit "last reset event"
 * timestamp for PAR cadence purposes.
 *
 * Considers, in order:
 *   1. assigns timestamps (dispatched / responding / on_scene) BUT
 *      only the ones whose matching incident_action has at least
 *      one un_status row with resets_par=1.
 *   2. Most recent par_unit_acks.acked_at for this responder on
 *      this ticket.
 *   3. responder.status_updated if the responder's current
 *      un_status has resets_par=1.
 *   4. Floor at ticket.date so a freshly-created incident with a
 *      not-yet-stamped assigns row doesn't compute as 1970.
 *
 * Returns the latest of those as a Unix timestamp, or null if no
 * signal is available.
 */
function par_unit_last_activity_at(int $ticketId, int $responderId): ?int {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Which incident_action transitions count as resets?
    // Default: dispatched + responding + on_scene (pre-Phase-31
    // compat). With the resets_par column, derive the set from
    // un_status rows that have resets_par=1.
    $resetsActions = ['dispatched' => true, 'responding' => true, 'on_scene' => true];
    $hasResetsParCol = false;
    try {
        $hasResetsParCol = (bool) db_fetch_one(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'resets_par' LIMIT 1",
            [$prefix . 'un_status']
        );
    } catch (Exception $e) {}
    if ($hasResetsParCol) {
        try {
            $rows = db_fetch_all(
                "SELECT DISTINCT incident_action
                   FROM `{$prefix}un_status`
                  WHERE resets_par = 1 AND incident_action <> ''"
            );
            $resetsActions = [];
            foreach ($rows as $r) {
                $resetsActions[(string) $r['incident_action']] = true;
            }
        } catch (Exception $e) {}
    }

    $candidates = [];

    // 1. Assigns timestamps (only those whose action resets)
    try {
        $a = db_fetch_one(
            "SELECT dispatched, responding, on_scene
               FROM `{$prefix}assigns`
              WHERE ticket_id = ? AND responder_id = ?
                AND (clear IS NULL OR DATE_FORMAT(clear, '%y') = '00')
              ORDER BY id DESC LIMIT 1",
            [$ticketId, $responderId]
        );
        if ($a) {
            foreach (['dispatched','responding','on_scene'] as $col) {
                if (empty($resetsActions[$col])) continue;
                if (empty($a[$col]) || substr((string) $a[$col], 0, 4) === '0000') continue;
                $t = strtotime((string) $a[$col]);
                if ($t) $candidates[] = $t;
            }
        }
    } catch (Exception $e) {}

    // 2. Most recent acked_at for this responder on this ticket
    try {
        $lastAck = db_fetch_value(
            "SELECT MAX(a.acked_at)
               FROM `{$prefix}par_unit_acks` a
               JOIN `{$prefix}par_cycles`    c ON c.id = a.par_cycle_id
              WHERE a.responder_id = ?
                AND a.state = 'acked'
                AND c.ticket_id = ?",
            [$responderId, $ticketId]
        );
        if ($lastAck) {
            $t = strtotime((string) $lastAck);
            if ($t) $candidates[] = $t;
        }
    } catch (Exception $e) {}

    // 3. responder.status_updated, if current status resets PAR
    if ($hasResetsParCol) {
        try {
            $row = db_fetch_one(
                "SELECT r.status_updated
                   FROM `{$prefix}responder` r
                   JOIN `{$prefix}un_status` s ON s.id = r.un_status_id
                  WHERE r.id = ? AND s.resets_par = 1",
                [$responderId]
            );
            if ($row && !empty($row['status_updated'])) {
                $t = strtotime((string) $row['status_updated']);
                if ($t) $candidates[] = $t;
            }
        } catch (Exception $e) {}
    }

    // If we have ANY real activity signal, return the latest. Don't
    // contaminate the MAX with ticket.date — that would pick "now"
    // (incident-just-created) over real status timestamps that
    // happened a few minutes ago. Bug caught by Phase 31 regression
    // test on 2026-06-12.
    if (!empty($candidates)) return max($candidates);

    // No activity signal at all (no resetting status transition,
    // no ack, no responder status). Fall back to incident creation
    // so the cadence has SOMETHING to count from instead of returning
    // null (which would have suppressed the alarm entirely).
    try {
        $created = db_fetch_value(
            "SELECT `date` FROM `{$prefix}ticket` WHERE id = ? LIMIT 1",
            [$ticketId]
        );
        if ($created) {
            $t = strtotime((string) $created);
            if ($t) return $t;
        }
    } catch (Exception $e) {}

    return null;
}

/**
 * Phase 29B (2026-06-12) — broadcast a PAR-overdue alert through the
 * existing internal-messaging system.
 *
 * Inserts an urgent broadcast (priority='urgent', is_broadcast=1) into
 * internal_messages with one message_recipients row per user holding
 * `action.manage_par`. The existing notification-tray + bell badge +
 * audio + SSE push then handles delivery — same surface dispatchers
 * already watch.
 *
 * Spam guard: caller (api/par.php?action=overdue + scheduler cron)
 * must check ticket.par_last_overdue_broadcast_at and skip if within
 * one cadence interval. This function unconditionally inserts.
 *
 * Returns the inserted message id, or 0 on failure.
 */
function par_broadcast_overdue(int $ticketId, int $overdueSecs): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $tk = db_fetch_one(
            "SELECT id, scope, in_types_id
               FROM `{$prefix}ticket` WHERE id = ? LIMIT 1",
            [$ticketId]
        );
        if (!$tk) return 0;
    } catch (Exception $e) {
        return 0;
    }

    // Phase 99p — use the case number throughout PAR alert text.
    require_once __DIR__ . '/incident-number.php';
    $incNum  = incnum_display((int) $ticketId, $tk['incident_number'] ?? null);
    $scope   = (string) ($tk['scope'] ?? ('Incident ' . $incNum));
    $mins    = max(1, (int) round($overdueSecs / 60));
    $subject = 'PAR OVERDUE: Incident ' . $incNum . ' — ' . $scope;
    $body    = sprintf(
        "Personnel Accountability Report is %d minute(s) past due for incident %s (%s).\n\n" .
        "Open the incident and Initiate PAR now, or Cancel if no PAR is needed.\n\n" .
        "Direct link: incident-detail.php?id=%d",
        $mins, $incNum, $scope, $ticketId
    );

    // Find users with manage_par permission. Fall back to admins if
    // the perm isn't in use (so we always have a recipient set).
    // role_permissions is a junction (role_id, permission_id) so we
    // resolve the permission code through the permissions table.
    $recipients = [];
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT u.id
               FROM `{$prefix}user` u
               JOIN `{$prefix}user_roles` ur ON ur.user_id = u.id
               JOIN `{$prefix}roles` r ON r.id = ur.role_id
               LEFT JOIN `{$prefix}role_permissions` rp ON rp.role_id = ur.role_id
               LEFT JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
              WHERE p.code = 'action.manage_par'
                 OR r.is_super = 1"
        );
        foreach ($rows as $r) $recipients[] = (int) $r['id'];
    } catch (Exception $e) {}
    if (empty($recipients)) {
        // Fallback: legacy admins by level
        try {
            $rows = db_fetch_all(
                "SELECT id FROM `{$prefix}user` WHERE level IN (0, 1)"
            );
            foreach ($rows as $r) $recipients[] = (int) $r['id'];
        } catch (Exception $e) {}
    }
    if (empty($recipients)) return 0;

    // System sender — fall back to user_id 1 if there's no scheduler user.
    $fromUser = (int) ($_SESSION['user_id'] ?? 0);
    if ($fromUser <= 0) {
        try {
            $fromUser = (int) db_fetch_value(
                "SELECT id FROM `{$prefix}user` ORDER BY id LIMIT 1");
        } catch (Exception $e) { $fromUser = 1; }
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}internal_messages`
                (from_user_id, subject, body, priority, is_broadcast)
             VALUES (?, ?, ?, 'urgent', 1)",
            [$fromUser, $subject, $body]
        );
        $messageId = (int) db_insert_id();
        foreach ($recipients as $uid) {
            try {
                db_query(
                    "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id)
                     VALUES (?, ?)",
                    [$messageId, $uid]
                );
            } catch (Exception $e) {}
        }

        // SSE push so the notification tray + bell badge + audio
        // refresh in real time on every connected client.
        if (function_exists('sse_publish')) {
            try {
                sse_publish('message:broadcast', [
                    'message_id'   => $messageId,
                    'from_user_id' => $fromUser,
                    'subject'      => $subject,
                    'body'         => $body,
                    'priority'     => 'urgent',
                    'is_broadcast' => true,
                    'recipients'   => count($recipients),
                    'context'      => [
                        'kind'      => 'par_overdue',
                        'ticket_id' => $ticketId,
                        'overdue_s' => $overdueSecs,
                    ],
                ]);
            } catch (Exception $e) {}
        }

        // Mark on the ticket so subsequent polls don't re-spam.
        try {
            db_query(
                "UPDATE `{$prefix}ticket` SET par_last_overdue_broadcast_at = NOW() WHERE id = ?",
                [$ticketId]
            );
        } catch (Exception $e) {}

        if (function_exists('audit_log')) {
            audit_log('par', 'overdue_broadcast', 'ticket', $ticketId,
                "PAR-overdue broadcast sent ({$mins} min overdue, {$messageId} message id)");
        }
        return $messageId;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Find the units currently assigned to an incident.
 * For PAR purposes "assigned" means rows in `assigns` with the
 * incident's ticket_id where the responder is active.
 *
 * Phase 16e (2026-06-11) standby decision: par_standby_unit_behavior
 * controls whether units in non-active status (status_val like
 * 'Standby', 'Staging', 'Available') are included in the PAR
 * expectation list. Values:
 *   'include'      — count standby units (some agencies want this)
 *   'exclude'      — never count them
 *   'recommended'  — DEFAULT: include if cadence > 0, exclude otherwise.
 *                    Matches the published convention of "PAR everyone
 *                    on scene" while letting agencies opt out.
 */
function par_assigned_units(int $ticketId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Determine standby behavior.
    $behavior = 'recommended';
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}settings`
              WHERE name = 'par_standby_unit_behavior' LIMIT 1");
        if (in_array($v, ['include','exclude','recommended'], true)) {
            $behavior = $v;
        }
    } catch (Exception $e) {}

    // Phase 27 hotfix (2026-06-12) — column/table names had been wrong
    // since this function shipped, so every PAR cycle silently captured
    // zero units. Correct names:
    //   assigns.responder_id    (was `assigns.responder`)
    //   responder.un_status_id  (was `responder.currstatus`)
    //   un_status table         (was `unit_statuses` — doesn't exist)
    //   un_status.status_val    (was `un_status.name`)
    // Also exclude cleared assigns so a unit cleared from this incident
    // isn't included in its PAR roster.
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT r.id, r.name, r.un_status_id
               FROM `{$prefix}assigns` a
               JOIN `{$prefix}responder` r ON r.id = a.responder_id
              WHERE a.ticket_id = ?
                AND (a.clear IS NULL OR DATE_FORMAT(a.clear,'%y') = '00')
              ORDER BY r.name",
            [$ticketId]
        );
    } catch (Exception $e) {
        return [];
    }

    if ($behavior === 'include') return $rows;

    // For 'exclude' and 'recommended' (default), filter out units whose
    // current status name suggests standby/staging/available. We do a
    // best-effort string match because status configurations vary
    // wildly between installs.
    $standbyKeywords = ['standby','staging','available','offduty','off duty','reserve'];
    $filtered = [];
    foreach ($rows as $r) {
        $statusLabel = '';
        try {
            $statusLabel = (string) db_fetch_value(
                "SELECT status_val FROM `{$prefix}un_status` WHERE id = ? LIMIT 1",
                [(int) ($r['un_status_id'] ?? 0)]);
        } catch (Exception $e) {}
        $isStandby = false;
        $lower = strtolower($statusLabel);
        foreach ($standbyKeywords as $kw) {
            if (strpos($lower, $kw) !== false) { $isStandby = true; break; }
        }
        if ($isStandby) continue;
        $filtered[] = $r;
    }
    return $filtered;
}

/**
 * Initiate a PAR cycle. Returns the cycle row + initial acks.
 */
function par_initiate_cycle(
    int $ticketId,
    string $kind = 'manual',
    ?int $byUserId = null,
    ?string $notes = null,
    ?int $timestamp = null
): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    // 2026-06-11 — Mayday is a safety-critical emergency action and
    // must work even when PAR is disabled. Auto-enable for this
    // cycle and add a marker to the audit log. The master switch is
    // unchanged; the next normal-cycle attempt will still be gated.
    if (!par_enabled() && $kind !== 'mayday') {
        return ['error' => 'PAR is not enabled (Settings → PAR Checks → Enable)'];
    }
    $maydayWithParOff = ($kind === 'mayday' && !par_enabled());
    if ($maydayWithParOff) {
        audit_log('par', 'mayday_with_par_off', 'ticket', $ticketId,
            "MAYDAY declared on incident #{$ticketId} while PAR was disabled — cycle ran via emergency override");
    }
    if ($timestamp === null) $timestamp = time();
    $now = date('Y-m-d H:i:s', $timestamp);

    $cad = par_resolve_cadence($ticketId);
    if ($cad['cadence_minutes'] <= 0 && $kind === 'scheduled') {
        return ['error' => 'PAR cadence is 0 for this incident — scheduled PAR disabled'];
    }

    $units = par_assigned_units($ticketId);

    try {
        db_query('START TRANSACTION');

        db_query(
            "INSERT INTO `{$prefix}par_cycles`
                (ticket_id, initiated_at, initiated_by, initiated_kind,
                 cycle_window_s, status, notes)
             VALUES (?, ?, ?, ?, ?, 'pending', ?)",
            [$ticketId, $now, $byUserId, $kind, $cad['first_window_s'], $notes]
        );
        $cycleId = (int) db_insert_id();

        foreach ($units as $u) {
            db_query(
                "INSERT INTO `{$prefix}par_unit_acks`
                    (par_cycle_id, responder_id, expected, state)
                 VALUES (?, ?, 1, 'pending')",
                [$cycleId, (int) $u['id']]
            );
        }

        db_query(
            "UPDATE `{$prefix}ticket` SET par_last_cycle_at = ? WHERE id = ?",
            [$now, $ticketId]
        );

        db_query('COMMIT');

        audit_log('par', 'initiate', 'par_cycle', $cycleId,
            "Initiated {$kind} PAR for incident #{$ticketId} ({" .
            count($units) . "} units)", [
                'ticket_id' => $ticketId,
                'kind'      => $kind,
                'unit_ids'  => array_column($units, 'id'),
            ]);

        // Best-effort SSE publish.
        if (function_exists('sse_publish')) {
            try {
                sse_publish('par.initiated', [
                    'cycle_id'        => $cycleId,
                    'ticket_id'       => $ticketId,
                    'kind'            => $kind,
                    'cycle_window_s'  => $cad['first_window_s'],
                    'units'           => array_map(function ($u) {
                        return ['id' => (int) $u['id'], 'name' => (string) $u['name']];
                    }, $units),
                ]);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        try { db_query('ROLLBACK'); } catch (Exception $e2) {}
        return ['error' => $e->getMessage()];
    }

    return par_cycle_summary($cycleId);
}

function par_ack_unit(int $cycleId, int $responderId, array $args = []): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $byUserId    = isset($args['by_user_id']) ? (int) $args['by_user_id'] : null;
    $via         = isset($args['via']) ? (string) $args['via'] : 'dispatcher_manual';
    $memberCount = isset($args['member_count']) ? (int) $args['member_count'] : null;
    $comments    = isset($args['comments']) ? trim((string) $args['comments']) : null;
    $notes       = isset($args['notes']) ? trim((string) $args['notes']) : null;
    $now         = date('Y-m-d H:i:s');

    if (!in_array($via, ['mobile','dispatcher_manual','sse','voice_radio'], true)) {
        $via = 'dispatcher_manual';
    }

    try {
        db_query(
            "UPDATE `{$prefix}par_unit_acks`
                SET state = 'acked', acked_at = ?, acked_by = ?,
                    acked_via = ?, member_count = ?, comments = ?, notes = ?
              WHERE par_cycle_id = ? AND responder_id = ?",
            [$now, $byUserId, $via, $memberCount, $comments, $notes, $cycleId, $responderId]
        );

        audit_log('par', 'ack', 'par_unit_ack', null,
            "Unit #{$responderId} acked PAR cycle #{$cycleId} via {$via}", [
                'cycle_id'     => $cycleId,
                'responder_id' => $responderId,
                'via'          => $via,
                'member_count' => $memberCount,
            ]);

        // Check whether cycle is now fully acked.
        $pending = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks`
              WHERE par_cycle_id = ? AND state = 'pending'",
            [$cycleId]
        );
        if ($pending === 0) {
            db_query(
                "UPDATE `{$prefix}par_cycles`
                    SET status = 'complete', completed_at = ?
                  WHERE id = ?",
                [$now, $cycleId]
            );
            if (function_exists('sse_publish')) {
                try { sse_publish('par.cycle_complete', ['cycle_id' => $cycleId]); }
                catch (Exception $e) {}
            }
        }

        // Phase 18 polish (2026-06-11) — sensitivity-aware ack-comment
        // distribution. If the unit's ack carried a free-text comment
        // AND the cycle's ticket has a non-Standard security label, the
        // comment is forwarded only through routing rules that honor
        // the label's broadcast gate. We let the routing engine do the
        // work — it already consults seclabel_resolve() for any
        // message that carries a ticket_id. Here we just stamp the
        // ticket_id on the outbound message so the gate engages.
        if ($comments !== null && $comments !== '' &&
            function_exists('broker_send') && function_exists('seclabel_resolve')) {
            try {
                $ticketId = (int) db_fetch_value(
                    "SELECT ticket_id FROM `{$prefix}par_cycles` WHERE id = ?", [$cycleId]);
                if ($ticketId > 0) {
                    $unitName = (string) db_fetch_value(
                        "SELECT name FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
                    broker_send('local_chat', [
                        'from'       => 'PAR ack',
                        'subject'    => "Ack on #{$ticketId}: " . $unitName,
                        'body'       => "{$unitName} acked PAR" .
                                        ($memberCount !== null ? " ({$memberCount} on the floor)" : "") .
                                        ".\nComment: {$comments}",
                        'priority'   => 'normal',
                        'ticket_id'  => $ticketId,   // ← engages security label gate in router.php
                    ]);
                }
            } catch (Exception $e) { /* non-fatal */ }
        }

        if (function_exists('sse_publish')) {
            try {
                sse_publish('par.unit_acked', [
                    'cycle_id'     => $cycleId,
                    'responder_id' => $responderId,
                    'via'          => $via,
                    'member_count' => $memberCount,
                ]);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    return par_cycle_summary($cycleId);
}

function par_abort_cycle(int $cycleId, ?int $byUserId, ?string $reason): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}par_cycles`
                SET status = 'aborted', completed_at = NOW(),
                    notes = CONCAT_WS(' | ', notes, ?)
              WHERE id = ?",
            ['aborted: ' . ($reason ?: 'no reason'), $cycleId]
        );
        db_query(
            "UPDATE `{$prefix}par_unit_acks`
                SET state = 'aborted'
              WHERE par_cycle_id = ? AND state = 'pending'",
            [$cycleId]
        );
        audit_log('par', 'abort', 'par_cycle', $cycleId,
            "Aborted PAR cycle #{$cycleId}" . ($reason ? ": {$reason}" : ''));
        return true;
    } catch (Exception $e) { return false; }
}

/**
 * Phase 16b (2026-06-11) — scheduler sweep.
 *
 * Designed to be called once per minute by a cron job (tools/par_tick.php).
 * Each sweep does two things:
 *
 *   1. For every active incident with par_enabled, if the next-due
 *      timestamp has passed, initiate a 'scheduled' PAR cycle.
 *
 *   2. For every PAR cycle in 'pending' status whose cycle_window_s has
 *      elapsed, mark still-pending units as 'missed' and trigger
 *      escalation (chat + SSE).
 *
 * Returns ['cycles_started' => N, 'units_missed' => M].
 */
function par_run_scheduler(?int $now = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($now === null) $now = time();
    $started = 0; $missed = 0;
    if (!par_enabled()) {
        return ['cycles_started' => 0, 'units_missed' => 0, 'reason' => 'disabled'];
    }

    // ── Auto-initiate due cycles ─────────────────────────────────────
    try {
        // Status semantics: legacy tickets use status = 0 for "open".
        // Don't auto-PAR closed or wastebasketed incidents.
        $active = db_fetch_all(
            "SELECT id FROM `{$prefix}ticket`
              WHERE status IN (0, 1, 2)
                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
        );
    } catch (Exception $e) {
        // deleted_at may not exist; retry without
        try {
            $active = db_fetch_all(
                "SELECT id FROM `{$prefix}ticket` WHERE status IN (0, 1, 2)"
            );
        } catch (Exception $e2) { $active = []; }
    }
    foreach ($active as $row) {
        $tid = (int) $row['id'];
        $cad = par_resolve_cadence($tid);
        if ($cad['cadence_minutes'] <= 0) continue;
        $due = par_due_at($tid);
        if ($due && $now >= $due) {
            $r = par_initiate_cycle($tid, 'scheduled', null,
                'Auto-initiated by scheduler', $now);
            if (isset($r['cycle'])) $started++;
        }
    }

    // ── Mark elapsed-window units as missed + escalate ─────────────────
    try {
        $cycles = db_fetch_all(
            "SELECT id, ticket_id, initiated_at, cycle_window_s
               FROM `{$prefix}par_cycles`
              WHERE status = 'pending'"
        );
    } catch (Exception $e) { $cycles = []; }
    foreach ($cycles as $c) {
        $cycleStart = strtotime($c['initiated_at']);
        $elapsed = $now - $cycleStart;
        if ($elapsed <= (int) $c['cycle_window_s']) continue;

        // Window elapsed — mark remaining pending acks as missed.
        try {
            $pending = db_fetch_all(
                "SELECT id, responder_id FROM `{$prefix}par_unit_acks`
                  WHERE par_cycle_id = ? AND state = 'pending'",
                [(int) $c['id']]
            );
            foreach ($pending as $p) {
                db_query(
                    "UPDATE `{$prefix}par_unit_acks`
                        SET state = 'missed' WHERE id = ?",
                    [(int) $p['id']]
                );
                $missed++;
                par_escalate_missed_unit(
                    (int) $c['id'],
                    (int) $c['ticket_id'],
                    (int) $p['responder_id']
                );
            }
            // If no acks remained pending, mark cycle complete.
            $stillPending = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}par_unit_acks`
                  WHERE par_cycle_id = ? AND state = 'pending'",
                [(int) $c['id']]
            );
            if ($stillPending === 0) {
                db_query(
                    "UPDATE `{$prefix}par_cycles`
                        SET status = 'complete', completed_at = ?
                      WHERE id = ?",
                    [date('Y-m-d H:i:s', $now), (int) $c['id']]
                );
            }
        } catch (Exception $e) {}
    }

    return ['cycles_started' => $started, 'units_missed' => $missed];
}

/**
 * Phase 16b — chat + SSE escalation for a missed unit.
 */
function par_escalate_missed_unit(int $cycleId, int $ticketId, int $responderId): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Unit name for the chat post
    $unitName = (string) db_fetch_value(
        "SELECT name FROM `{$prefix}responder` WHERE id = ?", [$responderId]);

    audit_log('par', 'missed', 'par_unit_ack', null,
        "Unit '{$unitName}' missed PAR cycle #{$cycleId} for incident #{$ticketId}", [
            'cycle_id'     => $cycleId,
            'ticket_id'    => $ticketId,
            'responder_id' => $responderId,
            'unit_name'    => $unitName,
        ]);

    if (function_exists('sse_publish')) {
        try {
            sse_publish('par.unit_missed', [
                'cycle_id'     => $cycleId,
                'ticket_id'    => $ticketId,
                'responder_id' => $responderId,
                'unit_name'    => $unitName,
            ]);
        } catch (Exception $e) {}
    }

    // Best-effort chat post to the configured escalation channel.
    try {
        $channel = (string) db_fetch_value(
            "SELECT value FROM `{$prefix}settings`
              WHERE name = 'par_escalation_chat_channel' LIMIT 1");
        if ($channel !== '' && function_exists('broker_send')) {
            // Fix: broker_send signature is (channel, message_array). Earlier this
            // passed a single array and silently dropped on the floor (PHP arity
            // mismatch warning at runtime).
            broker_send($channel, [
                'from'    => 'PAR scheduler',
                'subject' => 'Missed PAR check',
                'body'    => "Unit {$unitName} missed PAR for incident #{$ticketId}",
                'priority'=> 'urgent',
            ]);
        }
    } catch (Exception $e) {}
}

function par_cycle_summary(int $cycleId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $cycle = db_fetch_one(
            "SELECT * FROM `{$prefix}par_cycles` WHERE id = ? LIMIT 1",
            [$cycleId]
        );
        if (!$cycle) return ['error' => 'cycle not found'];
        $acks = db_fetch_all(
            "SELECT a.*, r.name AS unit_name
               FROM `{$prefix}par_unit_acks` a
               JOIN `{$prefix}responder` r ON r.id = a.responder_id
              WHERE a.par_cycle_id = ?
              ORDER BY r.name",
            [$cycleId]
        );
        return [
            'cycle' => $cycle,
            'acks'  => $acks,
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Issue #22 (2026-07-02) — mobile ack authorization helper.
 *
 * Return true if `$userId` "owns" `$responderId` in the same three ways
 * api/mobile-data.php resolves the current user's responder:
 *   1. responder.user_id = the caller's user id (direct link)
 *   2. responder.personal_for_member_id = the caller's member id
 *      (Phase 69 personal-resource unit)
 *   3. responder.name or responder.handle matches the caller's username
 *
 * Used by api/par.php's ack gate so a Field Unit role responder can
 * acknowledge PAR for their own unit but not for anyone else's.
 */
function par_user_owns_responder(int $userId, int $responderId): bool {
    if ($userId <= 0 || $responderId <= 0) return false;
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        // Path 1: direct responder.user_id link.
        $hit = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}responder`
              WHERE id = ? AND user_id = ?",
            [$responderId, $userId]
        );
        if ($hit > 0) return true;

        // Grab session-lookup fields once.
        $u = db_fetch_one(
            "SELECT user, member FROM `{$prefix}user` WHERE id = ? LIMIT 1",
            [$userId]
        );
        if (!$u) return false;
        $memberId = (int) ($u['member'] ?? 0);
        $username = (string) ($u['user'] ?? '');

        // Path 2: personal-resource unit (Phase 69).
        if ($memberId > 0) {
            $hit = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}responder`
                  WHERE id = ? AND personal_for_member_id = ?",
                [$responderId, $memberId]
            );
            if ($hit > 0) return true;
        }

        // Path 3: username matches responder name or handle.
        if ($username !== '') {
            $hit = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}responder`
                  WHERE id = ? AND (name = ? OR handle = ?)",
                [$responderId, $username, $username]
            );
            if ($hit > 0) return true;
        }
    } catch (Exception $e) {
        // Soft-fail — a schema mismatch here shouldn't grant ownership.
        return false;
    }
    return false;
}
