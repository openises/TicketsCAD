<?php
/**
 * GH#141 (Phase 155) — reserving a unit for a Scheduled incident.
 *
 * THE PROBLEM (rjonesbsink, GH#141)
 * ---------------------------------
 * Assigning a unit to a Scheduled call (ticket.status = 3, booked_date in the
 * future) went through the same path as assigning it to a live one: an
 * `assigns` row with `dispatched = NOW()` and the unit flipped to Dispatched on
 * the spot. A unit pre-committed to something hours away read as busy
 * (identical to one en route to a real emergency) and dropped out of the
 * "available" pool, though it was free until the booked time. This is
 * inherited from the legacy v3 app, not a v4 regression -- but it is wrong.
 *
 * THE DESIGN (and why not "just a flag on assigns")
 * -------------------------------------------------
 * Roughly 45 places read "any uncleared assigns row" as "this unit has live
 * work", and three writers act on it (responder_set_status_internal() closes
 * every open row of a unit when it is set Available; incident_clear_stragglers()
 * refuses to reset a unit that has any other open row; the dispatch gate warns
 * on one). A "pending" flag on `assigns` would need every one of those sites to
 * join and filter; one missed site silently stamps or closes a booking, and no
 * test can enumerate them. So a reservation is NOT an assignment: it lives in
 * its own table, `assign_reservations`, which none of those readers or writers
 * ever sees. At the booked time -- or `scheduled_assign_lead_minutes` before --
 * each reservation is PROMOTED by calling the ordinary, unmodified
 * assign_create_internal(), so `assigns.dispatched` is stamped when the unit is
 * really dispatched (the Intervals report stays truthful).
 *
 * It is a SETTING, off by default (`scheduled_assign_mode` = 'immediate' =
 * today's behaviour everywhere, including every upgraded install): Settings ->
 * Incident Lifecycle -> "Units assigned to Scheduled incidents".
 *
 * DERIVE AT READ TIME, DON'T DEPEND ON A JOB FLIPPING A FLAG (the Phase 143
 * lesson, applied)
 * --------------------------------------------------------------------------
 * Whether a reservation is DUE is never stored: it is the SQL predicate
 * assign_reservations_due_sql() evaluated against the database clock on every
 * read. A dispatcher screen therefore shows "DUE -- not yet dispatched" for a
 * reservation whose time has come but which nothing has promoted yet, instead
 * of the old silent, permanent "Dispatched" lie. What cannot be derived is the
 * unit's own stored status (responder.un_status_id); flipping it is the one
 * thing a job (or the lazy hook) must do -- and lateness FAILS VISIBLE: a red
 * Status-page row and a DUE badge, not a wrong status. The reservation's own
 * `state` records the OUTCOME of an action, never "time has passed".
 *
 * Reservations on a SOFT-DELETED incident are excluded by join, not mutated, so
 * restoring it from the wastebasket restores them.
 *
 * A BUSY UNIT AT ACTIVATION is not silently double-booked: the existing GH#82/83
 * gate decides. A hard block (Dispatch level: Unavailable) holds the
 * reservation; the implied "already has another active call" warning holds it
 * for a dispatcher UNLESS the unit is Multi-Assign (responder.multi = 1), in
 * which case it is promoted -- so Multi-Assign means the same thing here as
 * everywhere else. A held reservation shows a reason and is resolved by a human
 * ("Dispatch now" or "Release"); it is never retried automatically (an
 * auto-retry would dispatch a unit hours late when it finally became free).
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/assignment-write.php';
require_once __DIR__ . '/assign-effects.php';

/** Hours either side of a booking within which another reservation of the same unit is "near". */
if (!defined('ASSIGN_RESERVATION_CONFLICT_HOURS')) {
    define('ASSIGN_RESERVATION_CONFLICT_HOURS', 4);
}

// ─────────────────────────────────────────────────────────────────────────
// Settings readers
// ─────────────────────────────────────────────────────────────────────────

/** Does the reservation table exist? Cached per process. */
function assign_reservations_table_exists(bool $forget = false): bool {
    static $exists = null;
    if ($forget) { $exists = null; }
    if ($exists !== null) return $exists;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $exists = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
            [$prefix . 'assign_reservations']);
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * 'immediate' (today's behaviour) or 'reserve'.
 *
 * Anything other than exactly 'reserve' is 'immediate' -- an absent, empty or
 * garbled value must never change how dispatch works. It is ALSO 'immediate'
 * when the table is missing (an install that has not run the migration yet): a
 * missing migration must never block dispatch.
 */
function assign_reservation_mode(): string {
    $v = function_exists('get_variable') ? get_variable('scheduled_assign_mode') : false;
    if ($v !== 'reserve') return 'immediate';
    if (!assign_reservations_table_exists()) {
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log('[assign-reservations] scheduled_assign_mode=reserve but the assign_reservations table is missing -- '
                . 'dispatching immediately. Run: php sql/run_migrations.php');
        }
        return 'immediate';
    }
    return 'reserve';
}

/** Minutes BEFORE the booked time at which a reserved unit is dispatched. 0..1440, default 0. */
function assign_reservation_lead_minutes(): int {
    $v = function_exists('get_variable') ? get_variable('scheduled_assign_lead_minutes') : false;
    if ($v === false || $v === null || $v === '') return 0;
    $n = (int) $v;
    if ($n < 0) return 0;
    if ($n > 1440) return 1440;
    return $n;
}

/**
 * The one definition of what an admin may save for the two settings, shared by
 * api/config-admin.php (the writer) so the reader above and the form agree.
 *
 * @return string|null the normalized value, or null when $key is not one of ours
 */
function assign_reservation_normalize_setting(string $key, $value): ?string {
    if ($key === 'scheduled_assign_mode') {
        return ((string) $value === 'reserve') ? 'reserve' : 'immediate';
    }
    if ($key === 'scheduled_assign_lead_minutes') {
        $n = (int) $value;
        if ($n < 0) $n = 0;
        if ($n > 1440) $n = 1440;
        return (string) $n;
    }
    return null;
}

/**
 * SQL text (never a cached boolean) for "this reservation is due", for the
 * ticket aliased $t: the incident is already Open, or it is Scheduled and its
 * booked time is within the lead window. Evaluated by the database against its
 * own clock on every read -- never PHP's time(): Phase 143 measured a ~59 min
 * dev-machine/DB skew.
 */
function assign_reservations_due_sql(string $t = 't', ?int $leadMinutes = null): string {
    $lead = $leadMinutes === null ? assign_reservation_lead_minutes() : max(0, min(1440, $leadMinutes));
    $t = preg_replace('/[^A-Za-z0-9_]/', '', $t);
    return "({$t}.`status` = 2 OR ({$t}.`status` = 3 AND {$t}.`booked_date` IS NOT NULL"
         . " AND {$t}.`booked_date` <= DATE_ADD(NOW(), INTERVAL {$lead} MINUTE)))";
}

/**
 * Should a NEW assignment to this incident be a reservation instead of a
 * dispatch? Only a Scheduled incident whose booked time is still beyond the
 * lead window. Decided by the DB clock.
 */
function assign_reservation_wanted(int $ticketId): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $lead = assign_reservation_lead_minutes();
    try {
        return (bool) db_fetch_value(
            "SELECT 1 FROM `{$prefix}ticket`
              WHERE `id` = ? AND `status` = 3 AND `booked_date` IS NOT NULL
                AND `booked_date` > DATE_ADD(NOW(), INTERVAL {$lead} MINUTE)
                AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            [$ticketId]);
    } catch (Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Small helpers
// ─────────────────────────────────────────────────────────────────────────

function _assign_res_unit_label(int $responderId): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one("SELECT `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ?", [$responderId]);
    } catch (Throwable $e) { $r = null; }
    if (!$r) return 'unit #' . $responderId;
    return (string) ($r['handle'] ?: $r['name'] ?: ('unit #' . $responderId));
}

/** The `fields_changed=['reservations']` SSE refresh (no new event type; see event-bus.js). */
function _assign_res_publish(int $ticketId, array $extra = []): void {
    try {
        if (!function_exists('sse_publish_for_incident') && is_file(__DIR__ . '/sse.php')) {
            require_once __DIR__ . '/sse.php';
        }
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('incident:update',
                array_merge(['ticket_id' => $ticketId, 'fields_changed' => ['reservations']], $extra), $ticketId);
        }
    } catch (Throwable $e) { /* SSE is non-fatal */ }
}

function _assign_res_audit(string $activity, int $reservationId, string $summary, array $details, ?array $actor = null): void {
    try {
        // Deliberately NOT webhook-mapped (inc/webhooks.php): nothing was
        // dispatched. The promotion's own `incident|assign|assigns` row is what
        // drives assign.created.
        audit_log('incident', $activity, 'assigns', $reservationId, $summary, $details, AUDIT_INFO, $actor);
    } catch (Throwable $e) {
        error_log('[assign-reservations] audit failed: ' . $e->getMessage());
    }
}

/** One reservation row by id, or null. */
function assign_reservation_get(int $id): ?array {
    if ($id <= 0 || !assign_reservations_table_exists()) return null;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one("SELECT * FROM `{$prefix}assign_reservations` WHERE `id` = ?", [$id]);
    } catch (Throwable $e) { $row = null; }
    return $row ?: null;
}

// ─────────────────────────────────────────────────────────────────────────
// Reserve / release
// ─────────────────────────────────────────────────────────────────────────

/**
 * Reserve a unit for a Scheduled incident WITHOUT dispatching it: no `assigns`
 * row, no status change on the unit.
 *
 * Deliberately NOT run through the dispatch gate: a reservation commits the
 * unit for LATER, so the unit's status NOW (off duty, on another call) says
 * nothing about whether the booking is sound. The gate runs when the
 * reservation is promoted.
 *
 * @return array ['id' => 0, 'reserved' => true, 'reservation_id' => int,
 *                'promotes_at' => ?string, 'conflicts' => array, 'errors' => []]
 *            or ['errors' => [...]]
 */
function assign_reserve_internal(int $ticketId, int $responderId, string $role, int $userId): array {
    if ($ticketId <= 0)    return ['errors' => ['Invalid ticket ID']];
    if ($responderId <= 0) return ['errors' => ['Invalid responder ID']];
    if (!assign_reservations_table_exists()) {
        return ['errors' => ['Reservations are not installed on this system. Run: php sql/run_migrations.php']];
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $role = substr(trim($role), 0, 64);

    try {
        $ticket = db_fetch_one(
            "SELECT `id`, `booked_date` FROM `{$prefix}ticket`
              WHERE `id` = ? AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            [$ticketId]);
    } catch (Throwable $e) {
        return ['errors' => ['Database error verifying ticket: ' . $e->getMessage()]];
    }
    if (!$ticket) return ['errors' => ['Ticket not found']];

    try {
        $resp = db_fetch_one("SELECT `id`, `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ?", [$responderId]);
    } catch (Throwable $e) {
        return ['errors' => ['Database error verifying responder: ' . $e->getMessage()]];
    }
    if (!$resp) return ['errors' => ['Responder not found']];

    // Already actually assigned to this incident?
    try {
        $has = db_fetch_value(
            "SELECT `id` FROM `{$prefix}assigns`
              WHERE `ticket_id` = ? AND `responder_id` = ?
                AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
            [$ticketId, $responderId]);
    } catch (Throwable $e) { $has = false; }
    if ($has) return ['errors' => ['Responder is already assigned to this incident']];

    $lead = assign_reservation_lead_minutes();
    $promotesAt = null;
    try {
        $promotesAt = db_fetch_value(
            "SELECT DATE_FORMAT(DATE_SUB(`booked_date`, INTERVAL {$lead} MINUTE), '%Y-%m-%d %H:%i:%s')
               FROM `{$prefix}ticket` WHERE `id` = ? AND `booked_date` IS NOT NULL
                AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')", [$ticketId]);
        if ($promotesAt === false) $promotesAt = null;
    } catch (Throwable $e) { /* leave null */ }

    // The INSERT. A second live reservation for the same (ticket, unit) is
    // refused by the DATABASE (uk_active), which is the real guard -- the
    // pre-check above for live assigns is advisory; this is not.
    try {
        db_query(
            "INSERT INTO `{$prefix}assign_reservations`
                (`ticket_id`, `responder_id`, `role`, `reserved_by`, `state`, `active_key`)
             VALUES (?, ?, ?, ?, 'pending', 1)",
            [$ticketId, $responderId, $role, $userId]);
        $resId = (int) db_insert_id();
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
            return ['errors' => ['Responder is already reserved for this incident']];
        }
        return ['errors' => ['Failed to reserve unit: ' . $e->getMessage()]];
    }

    $name = (string) ($resp['handle'] ?: $resp['name']);
    $when = $ticket['booked_date'] ? substr((string) $ticket['booked_date'], 0, 16) : 'the booked time';
    _assign_log_action($ticketId,
        'Reserved ' . $name . ($role !== '' ? ' (' . $role . ')' : '') . ' for ' . $when, 27, $userId);
    _assign_touch_ticket($ticketId);
    _assign_res_audit('reserve', $resId, "Reserved '{$name}' for incident #{$ticketId}", [
        'ticket_id' => $ticketId, 'responder_id' => $responderId, 'reservation_id' => $resId,
        'role' => $role, 'booked_date' => $ticket['booked_date'], 'promotes_at' => $promotesAt,
    ]);
    _assign_res_publish($ticketId);

    // Advisory only, never blocking: this unit is also reserved elsewhere around the same time.
    $conflicts = [];
    try {
        $h = (int) ASSIGN_RESERVATION_CONFLICT_HOURS;
        $rows = db_fetch_all(
            "SELECT r.`ticket_id`, t.`incident_number`, t.`booked_date`
               FROM `{$prefix}assign_reservations` r
               JOIN `{$prefix}ticket` t ON t.`id` = r.`ticket_id`
              WHERE r.`responder_id` = ? AND r.`ticket_id` <> ? AND r.`state` IN ('pending','blocked')
                AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
                AND t.`booked_date` IS NOT NULL
                AND ABS(TIMESTAMPDIFF(MINUTE, t.`booked_date`, (SELECT `booked_date` FROM `{$prefix}ticket` WHERE `id` = ?))) <= {$h} * 60",
            [$responderId, $ticketId, $ticketId]);
        foreach ($rows as $c) {
            $conflicts[] = [
                'ticket_id'       => (int) $c['ticket_id'],
                'incident_number' => ($c['incident_number'] !== null && $c['incident_number'] !== '') ? (string) $c['incident_number'] : ('#' . (int) $c['ticket_id']),
                'booked_date'     => $c['booked_date'],
            ];
        }
    } catch (Throwable $e) { /* advisory */ }

    return ['id' => 0, 'reserved' => true, 'reservation_id' => $resId, 'promotes_at' => $promotesAt,
            'conflicts' => $conflicts, 'errors' => []];
}

/**
 * Release (cancel) a reservation. Compare-and-set on the live states.
 *
 * @return array ['released' => bool, 'ticket_id' => int, 'errors' => string[]]
 */
function assign_reservation_release(int $reservationId, int $userId, string $reason = 'released by a dispatcher'): array {
    $row = assign_reservation_get($reservationId);
    if (!$row) return ['released' => false, 'ticket_id' => 0, 'errors' => ['Reservation not found']];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ticketId = (int) $row['ticket_id'];
    try {
        $stmt = db_query(
            "UPDATE `{$prefix}assign_reservations`
                SET `state` = 'cancelled', `active_key` = NULL, `closed_at` = NOW(), `outcome_note` = ?
              WHERE `id` = ? AND `state` IN ('pending','blocked')",
            [substr($reason, 0, 255), $reservationId]);
    } catch (Throwable $e) {
        return ['released' => false, 'ticket_id' => $ticketId, 'errors' => ['Failed to release reservation: ' . $e->getMessage()]];
    }
    if ($stmt->rowCount() !== 1) {
        return ['released' => false, 'ticket_id' => $ticketId, 'errors' => ['Reservation is already ' . $row['state']]];
    }
    $name = _assign_res_unit_label((int) $row['responder_id']);
    _assign_log_action($ticketId, 'Reservation for ' . $name . ' released (' . $reason . ')', 27, $userId);
    _assign_res_audit('reserve_release', $reservationId, "Released reservation for '{$name}' on incident #{$ticketId}", [
        'ticket_id' => $ticketId, 'responder_id' => (int) $row['responder_id'],
        'reservation_id' => $reservationId, 'reason' => $reason,
    ], $userId > 0 ? null : AUDIT_ACTOR_SYSTEM);
    _assign_res_publish($ticketId);
    return ['released' => true, 'ticket_id' => $ticketId, 'errors' => []];
}

/**
 * Cancel every live reservation on an incident -- called by the status writer
 * when the incident closes (a reservation on a closed call can never be
 * promoted). Returns how many were cancelled.
 */
function assign_reservations_cancel_for_ticket(int $ticketId, string $reason, int $userId = 0): int {
    if (!assign_reservations_table_exists()) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $n = 0;
    try {
        $ids = db_fetch_all(
            "SELECT `id` FROM `{$prefix}assign_reservations`
              WHERE `ticket_id` = ? AND `state` IN ('pending','blocked') ORDER BY `id`", [$ticketId]);
    } catch (Throwable $e) { return 0; }
    foreach ($ids as $r) {
        $res = assign_reservation_release((int) $r['id'], $userId, $reason);
        if (!empty($res['released'])) $n++;
    }
    return $n;
}

// ─────────────────────────────────────────────────────────────────────────
// Promotion
// ─────────────────────────────────────────────────────────────────────────

/**
 * Promote ONE reservation into a real assignment, through the unmodified
 * assign_create_internal().
 *
 * Runs in a transaction holding `SELECT ... FOR UPDATE` on the reservation row,
 * so two concurrent callers (the tick and a dispatcher's "Dispatch now"; two
 * timers) serialize on it: exactly one sees 'pending' and promotes, the other
 * blocks until it commits and then finds the row already promoted. Exactly one
 * `assigns` row results. (`responder` is MyISAM and is not rolled back, but the
 * status write is idempotent, so a retry converges.)
 *
 * @param bool $force  a dispatcher's explicit "Dispatch now": answers the
 *                     gate's WARN. NEVER bypasses a hard block (Dispatch level
 *                     Unavailable).
 * @param int  $userId acting user; 0 = the system (the timer / activation)
 * @return array ['promoted' => bool, 'blocked' => bool, 'hard_block' => bool,
 *                'skipped' => string, 'assign_id' => int, 'message' => string,
 *                'errors' => string[]]
 *         blocked = the reservation is now held for a dispatcher; hard_block =
 *         held because of an unconditional refusal that `force` cannot clear.
 */
function assign_reservations_promote_one(int $reservationId, bool $force = false, int $userId = 0): array {
    $out = ['promoted' => false, 'blocked' => false, 'hard_block' => false, 'skipped' => '',
            'assign_id' => 0, 'message' => '', 'errors' => []];
    if (!assign_reservations_table_exists()) {
        $out['errors'][] = 'Reservations are not installed on this system.';
        return $out;
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $pdo = db();
    $ownTx = false;
    $actor = $userId > 0 ? null : AUDIT_ACTOR_SYSTEM;

    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $ownTx = true; }

        $stmt = $pdo->prepare("SELECT * FROM `{$prefix}assign_reservations` WHERE `id` = ? FOR UPDATE");
        $stmt->execute([$reservationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            $out['errors'][] = 'Reservation not found';
            return $out;
        }
        if (!in_array($row['state'], ['pending', 'blocked'], true)) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            $out['skipped'] = 'already_' . $row['state'];
            return $out;
        }

        $ticketId = (int) $row['ticket_id'];
        $responderId = (int) $row['responder_id'];
        $t = db_fetch_one(
            "SELECT `status`, `deleted_at` FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
        $deleted = !$t || (!empty($t['deleted_at']) && strpos((string) $t['deleted_at'], '0000-00-00') !== 0);
        if ($deleted) {
            // Excluded, not mutated: restoring the incident from the wastebasket
            // makes this reservation promotable again.
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            $out['skipped'] = 'ticket_deleted';
            return $out;
        }
        if ((int) $t['status'] === 1) {
            db_query(
                "UPDATE `{$prefix}assign_reservations`
                    SET `state` = 'cancelled', `active_key` = NULL, `closed_at` = NOW(), `outcome_note` = 'incident closed'
                  WHERE `id` = ?", [$reservationId]);
            if ($ownTx && $pdo->inTransaction()) $pdo->commit();
            $out['skipped'] = 'ticket_closed';
            return $out;
        }

        $res = assign_create_internal($ticketId, $responderId, (string) $row['role'],
            (int) $row['reserved_by'], $force, ['from_reservation' => $reservationId]);

        // 'from_reservation' makes assign_create_internal() skip its reservation
        // branch, so a `reserved` answer here is impossible; if it ever happened
        // (a future change to that branch) it must not be mistaken for a dispatch.
        if (!empty($res['reserved'])) {
            $res = ['errors' => ['Internal error: promotion produced another reservation instead of a dispatch']];
        }

        $unitName = _assign_res_unit_label($responderId);

        if (!empty($res['id']) && empty($res['errors'])) {
            $assignId = (int) $res['id'];
            db_query(
                "UPDATE `{$prefix}assign_reservations`
                    SET `state` = 'promoted', `active_key` = NULL, `closed_at` = NOW(),
                        `promoted_assign_id` = ?, `outcome_note` = NULL
                  WHERE `id` = ?", [$assignId, $reservationId]);
            if ($pdo->inTransaction() && $ownTx) $pdo->commit();
            $out['promoted'] = true;
            $out['assign_id'] = $assignId;
            $out['message'] = $unitName . ' dispatched from its reservation';

            // After the commit: announce exactly like any other dispatch.
            assign_emit_created_effects($ticketId, $responderId, $assignId, $unitName, [
                'scope' => (string) db_fetch_value("SELECT `scope` FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]),
                'actor' => $actor,
                'reservation_id' => $reservationId,
            ]);
            _assign_res_audit('reserve_promote', $reservationId,
                "Reservation for '{$unitName}' promoted to a dispatch on incident #{$ticketId}", [
                    'ticket_id' => $ticketId, 'responder_id' => $responderId, 'reservation_id' => $reservationId,
                    'assign_id' => $assignId, 'reserved_by' => (int) $row['reserved_by'],
                    'promoted_by' => $userId > 0 ? $userId : 'system',
                ], $actor);
            _assign_res_publish($ticketId, ['reservation_promoted' => true]);
            return $out;
        }

        // Not dispatched. Decide the reservation's fate from WHY.
        $note = '';
        $newState = 'blocked';
        if (!empty($res['needs_confirmation'])) {
            $note = (string) ($res['message'] ?? 'needs a dispatcher to confirm');
        } else {
            $first = (string) ($res['errors'][0] ?? 'could not be dispatched');
            $note = $first;
            if (strpos($first, 'already assigned') !== false) {
                // A dispatcher already put the unit on this incident directly.
                $newState = 'cancelled';
                $note = 'the unit was dispatched to this incident directly';
            } elseif (strpos($first, 'Responder not found') !== false || strpos($first, 'Ticket not found') !== false) {
                $newState = 'cancelled';
            }
        }
        $note = substr($note, 0, 255);
        if ($newState === 'blocked') {
            // active_key stays 1: still live, awaiting a human.
            db_query("UPDATE `{$prefix}assign_reservations` SET `state` = 'blocked', `outcome_note` = ? WHERE `id` = ?",
                [$note, $reservationId]);
        } else {
            db_query(
                "UPDATE `{$prefix}assign_reservations`
                    SET `state` = 'cancelled', `active_key` = NULL, `closed_at` = NOW(), `outcome_note` = ?
                  WHERE `id` = ?", [$note, $reservationId]);
        }
        if ($pdo->inTransaction() && $ownTx) $pdo->commit();

        $out['blocked'] = ($newState === 'blocked');
        // assign_create_internal() sets 'blocked' only for the unconditional refusal
        // (Dispatch level: Unavailable) that no `force` can bypass.
        $out['hard_block'] = !empty($res['blocked']);
        $out['message'] = $note;
        if ($newState === 'blocked') {
            _assign_log_action($ticketId, 'Reservation for ' . $unitName . ' needs a dispatcher: ' . $note, 27, $userId);
            _assign_res_audit('reserve_blocked', $reservationId,
                "Reservation for '{$unitName}' on incident #{$ticketId} held for a dispatcher", [
                    'ticket_id' => $ticketId, 'responder_id' => $responderId,
                    'reservation_id' => $reservationId, 'reason' => $note,
                ], $actor);
            _assign_res_publish($ticketId, ['reservation_blocked' => true]);
        } else {
            _assign_res_publish($ticketId);
        }
        return $out;
    } catch (Throwable $e) {
        try { if ($ownTx && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) { /* connection gone */ }
        error_log('[assign-reservations] promote_one #' . $reservationId . ' failed: ' . $e->getMessage());
        $out['errors'][] = 'Promotion failed: ' . $e->getMessage();
        return $out;
    }
}

/**
 * Promote every PENDING reservation on one incident -- called when the incident
 * becomes Open (booked time reached, or a dispatcher opened it by hand).
 * 'blocked' ones are left for a human, by design.
 *
 * @return array ['promoted' => int, 'blocked' => int, 'skipped' => int]
 */
function assign_reservations_promote_for_ticket(int $ticketId, int $userId = 0): array {
    $out = ['promoted' => 0, 'blocked' => 0, 'skipped' => 0];
    if (!assign_reservations_table_exists()) return $out;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $ids = db_fetch_all(
            "SELECT `id` FROM `{$prefix}assign_reservations`
              WHERE `ticket_id` = ? AND `state` = 'pending' ORDER BY `id`", [$ticketId]);
    } catch (Throwable $e) { return $out; }
    foreach ($ids as $r) {
        $p = assign_reservations_promote_one((int) $r['id'], false, $userId);
        if (!empty($p['promoted'])) $out['promoted']++;
        elseif (!empty($p['blocked'])) $out['blocked']++;
        else $out['skipped']++;
    }
    return $out;
}

/**
 * Promote every DUE pending reservation (oldest booked time first): the
 * incident is Open already, or Scheduled and within the lead window. Called by
 * tools/scheduled_incidents_tick.php; works whatever scheduled_assign_mode is
 * NOW (an admin switching the mode back must not strand reservations already
 * made).
 *
 * @return array ['checked' => int, 'promoted' => int, 'blocked' => int]
 */
function assign_reservations_promote_due(int $limit = 100): array {
    $out = ['checked' => 0, 'promoted' => 0, 'blocked' => 0];
    if (!assign_reservations_table_exists()) return $out;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(500, $limit));
    try {
        $rows = db_fetch_all(
            "SELECT r.`id`
               FROM `{$prefix}assign_reservations` r
               JOIN `{$prefix}ticket` t ON t.`id` = r.`ticket_id`
              WHERE r.`state` = 'pending'
                AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
                AND " . assign_reservations_due_sql('t') . "
              ORDER BY t.`booked_date` ASC, r.`id` ASC
              LIMIT " . (int) $limit);
    } catch (Throwable $e) {
        error_log('[assign-reservations] promote_due query failed: ' . $e->getMessage());
        return $out;
    }
    $out['checked'] = count($rows);
    foreach ($rows as $r) {
        $p = assign_reservations_promote_one((int) $r['id'], false, 0);
        if (!empty($p['promoted'])) $out['promoted']++;
        elseif (!empty($p['blocked'])) $out['blocked']++;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Read side
// ─────────────────────────────────────────────────────────────────────────

/**
 * Reservations of one incident for the detail screen: every live one
 * (pending / blocked), plus the last five finished, each with `due` derived by
 * the database clock and `promotes_at` (the booked time minus the lead).
 *
 * @return array<int,array>
 */
function assign_reservations_for_ticket(int $ticketId): array {
    if (!assign_reservations_table_exists()) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $lead = assign_reservation_lead_minutes();
    $select = "SELECT r.`id`, r.`responder_id`, r.`role`, r.`reserved_by`, r.`reserved_at`, r.`state`,
                      r.`active_key`, r.`outcome_note`, r.`closed_at`, r.`promoted_assign_id`,
                      (r.`state` IN ('pending','blocked') AND " . assign_reservations_due_sql('t', $lead) . ") AS due,
                      DATE_FORMAT(DATE_SUB(t.`booked_date`, INTERVAL {$lead} MINUTE), '%Y-%m-%d %H:%i:%s') AS promotes_at,
                      t.`booked_date`,
                      res.`handle` AS responder_handle, res.`name` AS responder_name,
                      u.`user` AS reserved_by_name
                 FROM `{$prefix}assign_reservations` r
                 JOIN `{$prefix}ticket` t ON t.`id` = r.`ticket_id`
                 LEFT JOIN `{$prefix}responder` res ON res.`id` = r.`responder_id`
                 LEFT JOIN `{$prefix}user` u ON u.`id` = r.`reserved_by`
                WHERE r.`ticket_id` = ?
                  AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')";
    try {
        // `active_key` IS NOT NULL is the table's own definition of "live" (the
        // unique key uk_active is built on it), so the read uses the very same
        // column the database constraint does.
        $live = db_fetch_all($select . " AND r.`state` IN ('pending','blocked') AND r.`active_key` IS NOT NULL ORDER BY r.`id` ASC", [$ticketId]);
        $done = db_fetch_all($select . " AND r.`state` IN ('promoted','cancelled') ORDER BY r.`closed_at` DESC, r.`id` DESC LIMIT 5", [$ticketId]);
    } catch (Throwable $e) {
        error_log('[assign-reservations] for_ticket query failed: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach (array_merge($live, $done) as $r) {
        $out[] = [
            'id'                => (int) $r['id'],
            'responder_id'      => (int) $r['responder_id'],
            'responder_name'    => (string) ($r['responder_name'] ?? ''),
            'responder_handle'  => (string) ($r['responder_handle'] ?? ''),
            'role'              => (string) $r['role'],
            'reserved_by_name'  => (string) ($r['reserved_by_name'] ?? ''),
            'reserved_at'       => $r['reserved_at'],
            'state'             => (string) $r['state'],
            // live = the table's own definition (active_key is set while a
            // reservation is pending or held, NULL once finished -- the same
            // column the unique key uk_active is built on).
            'live'              => ($r['active_key'] !== null),
            'due'               => (bool) (int) $r['due'],
            'booked_date'       => $r['booked_date'],
            'promotes_at'       => $r['promotes_at'],
            'outcome_note'      => $r['outcome_note'] !== null ? (string) $r['outcome_note'] : '',
            'closed_at'         => $r['closed_at'],
            'promoted_assign_id' => $r['promoted_assign_id'] !== null ? (int) $r['promoted_assign_id'] : null,
        ];
    }
    return $out;
}

/**
 * What each of these units is committed to LATER -- one batched read, however
 * many units (the unit lists call this once per page, not once per row).
 *
 * Two kinds, so the chip is useful in BOTH modes of scheduled_assign_mode:
 *   'reserved'             a live reservation (reserve mode)
 *   'scheduled_dispatched' an open assignment on a Scheduled incident whose
 *                          booked time is still ahead (immediate mode: the unit
 *                          was dispatched when it was assigned, today's
 *                          behaviour -- the chip at least says WHICH call and WHEN)
 * `due` is true for a reservation whose time has come but which nothing has
 * promoted yet ("DUE -- not yet dispatched"), derived by the database clock.
 * Soft-deleted incidents are excluded by join.
 *
 * @param int[] $responderIds
 * @return array<int,array<int,array>> responder_id => list of commitments
 */
function unit_future_commitments(array $responderIds): array {
    $ids = [];
    foreach ($responderIds as $id) { $id = (int) $id; if ($id > 0) $ids[$id] = $id; }
    $ids = array_values($ids);
    if (!$ids) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $lead = assign_reservation_lead_minutes();
    $out = [];

    if (assign_reservations_table_exists()) {
        try {
            $rows = db_fetch_all(
                "SELECT r.`responder_id`, r.`ticket_id`, r.`state`, t.`incident_number`, t.`booked_date`,
                        (" . assign_reservations_due_sql('t', $lead) . ") AS due
                   FROM `{$prefix}assign_reservations` r
                   JOIN `{$prefix}ticket` t ON t.`id` = r.`ticket_id`
                  WHERE r.`responder_id` IN ({$ph}) AND r.`state` IN ('pending','blocked')
                    AND r.`active_key` IS NOT NULL
                    AND t.`status` IN (2, 3)
                    AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
                  ORDER BY t.`booked_date` ASC, r.`id` ASC",
                $ids);
            foreach ($rows as $r) {
                $out[(int) $r['responder_id']][] = [
                    'kind'            => 'reserved',
                    'ticket_id'       => (int) $r['ticket_id'],
                    'incident_number' => ($r['incident_number'] !== null && $r['incident_number'] !== '') ? (string) $r['incident_number'] : ('#' . (int) $r['ticket_id']),
                    'booked_date'     => $r['booked_date'],
                    'due'             => (bool) (int) $r['due'],
                    'held'            => ($r['state'] === 'blocked'),
                ];
            }
        } catch (Throwable $e) {
            error_log('[assign-reservations] unit_future_commitments (reserved) failed: ' . $e->getMessage());
        }
    }

    try {
        $rows = db_fetch_all(
            "SELECT a.`responder_id`, a.`ticket_id`, t.`incident_number`, t.`booked_date`
               FROM `{$prefix}assigns` a
               JOIN `{$prefix}ticket` t ON t.`id` = a.`ticket_id`
              WHERE a.`responder_id` IN ({$ph})
                AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`,'%y') = '00')
                AND t.`status` = 3 AND t.`booked_date` IS NOT NULL AND t.`booked_date` > NOW()
                AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
              ORDER BY t.`booked_date` ASC, a.`id` ASC",
            $ids);
        foreach ($rows as $r) {
            $out[(int) $r['responder_id']][] = [
                'kind'            => 'scheduled_dispatched',
                'ticket_id'       => (int) $r['ticket_id'],
                'incident_number' => ($r['incident_number'] !== null && $r['incident_number'] !== '') ? (string) $r['incident_number'] : ('#' . (int) $r['ticket_id']),
                'booked_date'     => $r['booked_date'],
                'due'             => false,
                'held'            => false,
            ];
        }
    } catch (Throwable $e) {
        error_log('[assign-reservations] unit_future_commitments (dispatched) failed: ' . $e->getMessage());
    }
    return $out;
}
