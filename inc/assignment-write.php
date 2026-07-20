<?php
/**
 * Phase 94 Stage 4c — Responder-assignment write helpers.
 *
 * Pure write functions extracted from api/incident-assign.php so both
 * the internal session-authed endpoint AND the external bearer-token
 * endpoint (api/external/v1/assignments.php) can share the same logic
 * without duplicating it.
 *
 * Per plan.md §2.4: scope LIMITS but RBAC GRANTS. The CSRF/bearer
 * check happens at the caller; the rbac_can() check happens at the
 * caller; THIS file just does the write.
 *
 * The three helpers mirror api/incident-assign.php's three actions:
 *
 *   assign_create_internal        ← assign
 *   assign_update_status_internal ← update_status (named + numeric path)
 *   assign_unassign_internal      ← unassign
 *
 * Critical behaviour preserved verbatim from the legacy endpoint:
 *
 *   • setResponderStatus() propagation — assigning, responding,
 *     on_scene, clear all propagate to responder.un_status_id so the
 *     situation-widget chip and the unit-card status indicator both
 *     reflect reality (Eric's 2026-06-11 fix for incident #152).
 *   • assigns row timestamp writes — `dispatched`, `responding`,
 *     `on_scene`, `clear` all get stamped to NOW() at their respective
 *     transitions; responding is auto-back-filled when on_scene fires
 *     and responding wasn't already set.
 *   • "no other active assignments" gate — on `clear` and unassign we
 *     only revert the responder to Available if they have NO other
 *     active (uncleared) assignment rows. Otherwise the propagation is
 *     suppressed (the unit is still working a different incident).
 *   • Phase 25 explicit-mapping (un_status.incident_action) and the
 *     Phase 33A hotfix that stops the picked-status path from running
 *     the "reset to Available" block.
 *
 * History:
 *   2026-06-28 — extracted from api/incident-assign.php; canonical
 *                shape mirrors inc/incident-write.php's helpers.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────
//  Internal helpers — duplicated from api/incident-assign.php
//  intentionally with `_assign_` prefix so they don't collide if
//  the legacy endpoint also loads this file later (the legacy
//  endpoint defines its own unprefixed copies). Once Stage 4c.1's
//  legacy refactor lands, those copies can be deleted in favor of
//  the prefixed versions here.
// ─────────────────────────────────────────────────────────────────

/**
 * Find the un_status row that matches an explicit incident_action
 * (Phase 25 mapping: 'dispatched'|'responding'|'on_scene'|'clear').
 * Returns null when nothing maps — caller falls back to legacy defaults.
 */
function _assign_status_id_by_action(string $action): ?int {
    if ($action === '') return null;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT `id` FROM `{$prefix}un_status`
              WHERE incident_action = ?
              ORDER BY sort ASC, id ASC LIMIT 1",
            [$action]
        );
        if ($row) return (int) $row['id'];
    } catch (Exception $e) { /* table may differ on legacy installs */ }
    return null;
}

function _assign_action_by_status_id(int $statusId): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_value(
            "SELECT incident_action FROM `{$prefix}un_status`
              WHERE id = ? LIMIT 1",
            [$statusId]
        );
        return (string) ($r ?: '');
    } catch (Exception $e) { return ''; }
}

function _assign_available_status_id(): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT `id` FROM `{$prefix}un_status` WHERE LOWER(`status_val`) LIKE '%avail%' LIMIT 1"
        );
        if ($row) return (int) $row['id'];
    } catch (Exception $e) { /* fall through */ }
    return 1;
}

function _assign_dispatched_status_id(): int {
    $id = _assign_status_id_by_action('dispatched');
    return $id ?: 4;
}

/**
 * Propagate the assigns timestamp change to the responder's current
 * un_status_id. The 2026-06-11 fix for Eric's incident #152 ("unit TC
 * marked on scene didn't update TC's situation-widget status").
 *
 * Best-effort: schema differences on legacy installs swallowed.
 */
function _assign_set_responder_status(int $responderId, ?int $statusId): void {
    if (!$statusId) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    try {
        db_query(
            "UPDATE `{$prefix}responder`
                SET `un_status_id`   = ?,
                    `status_updated` = ?,
                    `updated`        = ?
              WHERE `id` = ?",
            [$statusId, $now, $now, $responderId]
        );
    } catch (Exception $e) { /* non-fatal */ }
}

/**
 * True if this responder has any OTHER active (uncleared) assignment
 * besides the one we're about to modify. Used as the gate for the
 * "should we revert the unit to Available?" decision.
 */
function _assign_has_other_active(int $responderId, int $excludeAssignId): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $count = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}assigns`
             WHERE `responder_id` = ?
               AND `id` != ?
               AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
            [$responderId, $excludeAssignId]
        );
        return (int) $count > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Append an entry to the incident's action log (best-effort). */
function _assign_log_action(int $ticketId, string $description, int $actionType, int $userId): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    try {
        db_query(
            "INSERT INTO `{$prefix}action`
                (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$ticketId, $now, $description, $userId, $actionType, $now]
        );
    } catch (Exception $e) { /* non-fatal */ }
}

/** Bump the parent ticket's `updated` timestamp (best-effort). */
function _assign_touch_ticket(int $ticketId): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    try {
        db_query(
            "UPDATE `{$prefix}ticket` SET `updated` = ? WHERE `id` = ?",
            [$now, $ticketId]
        );
    } catch (Exception $e) { /* non-fatal */ }
}

/**
 * Phase 116 — set (or clear) the per-unit receiving facility on ONE assignment.
 *
 * `assigns.rec_facility_id` is the destination facility for THIS specific unit's
 * transport (the hospital IT is taking a patient to). It is a per-unit value the
 * legacy TicketsCAD always carried (per-unit "Receiving Facility" dropdown on the
 * dispatch form) but the NewUI rewrite had stopped writing — see the Phase 116 spec.
 * Restoring it lets a mass-casualty incident send each transporting unit to a
 * DIFFERENT hospital, and lets bed automation decrement the correct facility per unit
 * (bed_auto resolves COALESCE(assign, ticket), so this per-unit value wins).
 *
 * Pass 0 to clear. Best-effort: a write failure is logged and swallowed so it can
 * never block a legitimate status change.
 */
function assign_set_rec_facility(int $assignId, int $facilityId, int $userId): void {
    if ($assignId <= 0) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}assigns` SET `rec_facility_id` = ? WHERE `id` = ?",
            [$facilityId > 0 ? $facilityId : null, $assignId]
        );
    } catch (Exception $e) {
        error_log('[assignment-write] set rec_facility (assign ' . $assignId . '): ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────
//  Public helpers
// ─────────────────────────────────────────────────────────────────

/**
 * Create a responder-to-incident assignment.
 *
 * Mirrors api/incident-assign.php's `assign` action body:
 *   - Verify ticket + responder exist
 *   - Reject duplicates (responder already actively assigned to ticket)
 *   - INSERT assigns row with status_id=1, dispatched=NOW()
 *   - Set responder's un_status_id to "Dispatched"
 *   - Append action-log entry (action_type 20)
 *   - Touch ticket
 *
 * @param int    $ticketId    Incident id
 * @param int    $responderId Responder (unit) id
 * @param string $role        Optional role/position label — accepted for API parity
 *                            but the legacy `assigns` schema has no role column.
 *                            Captured in the action-log description for visibility.
 * @param int    $userId      Owning user id (for `assigns.user_id`)
 * @return array ['id' => <assignId>, 'errors' => []] or ['errors' => [...]]
 */
function assign_create_internal(int $ticketId, int $responderId, string $role, int $userId): array {
    if ($ticketId <= 0)    return ['errors' => ['Invalid ticket ID']];
    if ($responderId <= 0) return ['errors' => ['Invalid responder ID']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');

    // Verify ticket exists
    try {
        $ticket = db_fetch_one(
            "SELECT `id` FROM `{$prefix}ticket` WHERE `id` = ?",
            [$ticketId]
        );
    } catch (Exception $e) {
        return ['errors' => ['Database error verifying ticket: ' . $e->getMessage()]];
    }
    if (!$ticket) return ['errors' => ['Ticket not found']];

    // Verify responder exists + get display name for action log
    try {
        $resp = db_fetch_one(
            "SELECT `id`, `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ?",
            [$responderId]
        );
    } catch (Exception $e) {
        return ['errors' => ['Database error verifying responder: ' . $e->getMessage()]];
    }
    if (!$resp) return ['errors' => ['Responder not found']];

    // Reject duplicate active assignment
    try {
        $existing = db_fetch_one(
            "SELECT `id` FROM `{$prefix}assigns`
             WHERE `ticket_id` = ? AND `responder_id` = ?
               AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
            [$ticketId, $responderId]
        );
    } catch (Exception $e) {
        $existing = null;
    }
    if ($existing) {
        return ['errors' => ['Responder is already assigned to this incident']];
    }

    // INSERT — the assignment is created WITHOUT a receiving facility on purpose.
    // The destination hospital (`assigns.rec_facility_id`) is a per-unit value set
    // LATER, when the unit is put into a transport/delivery status that carries a
    // facility (see assign_set_rec_facility() + the picked-status branch of
    // assign_update_status_internal(), Phase 116). At assign time the destination
    // usually isn't known yet, so omitting the column here is deliberate, not an
    // oversight — do not "fix" it by defaulting to the incident's rec_facility.
    try {
        db_query(
            "INSERT INTO `{$prefix}assigns`
                (`as_of`, `status_id`, `ticket_id`, `responder_id`, `user_id`, `dispatched`)
             VALUES (?, 1, ?, ?, ?, ?)",
            [$now, $ticketId, $responderId, $userId, $now]
        );
        $assignId = (int) db_insert_id();
    } catch (Exception $e) {
        return ['errors' => ['Failed to create assignment: ' . $e->getMessage()]];
    }

    // Promote responder to Dispatched
    $dispatchedStatus = _assign_dispatched_status_id();
    try {
        db_query(
            "UPDATE `{$prefix}responder`
                SET `un_status_id` = ?, `status_updated` = ?
              WHERE `id` = ?",
            [$dispatchedStatus, $now, $responderId]
        );
    } catch (Exception $e) { /* non-fatal */ }

    // Action log
    $respName = $resp['handle'] ?: $resp['name'];
    $logDesc  = 'Assigned ' . $respName . ($role !== '' ? ' (' . $role . ')' : '');
    _assign_log_action($ticketId, $logDesc, 20, $userId);
    _assign_touch_ticket($ticketId);

    // Phase 104d (a beta tester GH #11) — a fresh assignment during an
    // auto-close grace window cancels the pending close.
    try {
        require_once __DIR__ . '/auto_close.php';
        auto_close_maybe_cancel($ticketId, $userId);
    } catch (Throwable $e) { error_log('[assignment-write] auto_close_cancel: ' . $e->getMessage()); }

    return [
        'id'     => $assignId,
        'errors' => [],
    ];
}

/**
 * Update an existing assignment's status. Accepts either:
 *   - a legacy named transition string: 'responding' | 'on_scene' | 'clear'
 *   - an explicit un_status row id (Phase 25 picked-status path)
 *
 * The numeric path also resolves the row's `incident_action` and replays
 * the matching legacy branch when one exists; if `incident_action` is
 * empty (Transporting / At Facility / etc.), only the responder status
 * is changed and the assigns row timestamps are left untouched (Phase
 * 33A hotfix).
 *
 * @param int   $assignId        assigns row id
 * @param mixed $newStatusInput  string ('responding'|'on_scene'|'clear')
 *                               OR int (un_status.id)
 * @param int   $userId          Acting user id (for action-log attribution)
 * @return array ['status' => <appliedStatusString>, 'errors' => []]
 */
function assign_update_status_internal(int $assignId, $newStatusInput, int $userId): array {
    if ($assignId <= 0) return ['errors' => ['Invalid assignment ID']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now    = date('Y-m-d H:i:s');

    // Normalize input: integer → numeric path, string → named path
    $newStatusId = 0;
    $newStatus   = '';
    if (is_int($newStatusInput)) {
        $newStatusId = (int) $newStatusInput;
    } elseif (is_string($newStatusInput)) {
        $trim = trim($newStatusInput);
        if (ctype_digit($trim)) {
            $newStatusId = (int) $trim;
        } else {
            $newStatus = $trim;
        }
    } else {
        return ['errors' => ['Invalid new_status — expected string or int']];
    }

    // Resolve numeric → named (Phase 25 mapping). Empty incident_action
    // is allowed and means "picked-status path" downstream.
    if ($newStatusId > 0 && $newStatus === '') {
        $newStatus = _assign_action_by_status_id($newStatusId);
    }

    $validNamed = ['responding', 'on_scene', 'clear', ''];
    if (!in_array($newStatus, $validNamed, true)) {
        return ['errors' => ['Invalid status — must be responding | on_scene | clear (or send new_status_id)']];
    }

    // Fetch assignment + responder display name
    try {
        $assign = db_fetch_one(
            "SELECT `a`.*, `r`.`name` AS `responder_name`, `r`.`handle` AS `responder_handle`
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
             WHERE `a`.`id` = ?",
            [$assignId]
        );
    } catch (Exception $e) {
        return ['errors' => ['Database error: ' . $e->getMessage()]];
    }
    if (!$assign) return ['errors' => ['Assignment not found']];

    // Already cleared? abort
    $isCleared = !empty($assign['clear']) && substr($assign['clear'], 0, 4) !== '0000';
    if ($isCleared) {
        return ['errors' => ['Assignment is already cleared']];
    }

    $ticketId    = (int) $assign['ticket_id'];
    $responderId = (int) $assign['responder_id'];
    $respName    = $assign['responder_handle'] ?: $assign['responder_name'];

    try {
        if ($newStatus === 'responding') {
            db_query(
                "UPDATE `{$prefix}assigns` SET `responding` = ? WHERE `id` = ?",
                [$now, $assignId]
            );
            _assign_log_action($ticketId, $respName . ' responding', 21, $userId);
            _assign_set_responder_status($responderId, _assign_status_id_by_action('responding'));

        } elseif ($newStatus === 'on_scene') {
            // Auto-back-fill responding if it wasn't set
            if (empty($assign['responding']) || substr($assign['responding'], 0, 4) === '0000') {
                db_query(
                    "UPDATE `{$prefix}assigns` SET `responding` = ?, `on_scene` = ? WHERE `id` = ?",
                    [$now, $now, $assignId]
                );
            } else {
                db_query(
                    "UPDATE `{$prefix}assigns` SET `on_scene` = ? WHERE `id` = ?",
                    [$now, $assignId]
                );
            }
            _assign_log_action($ticketId, $respName . ' on scene', 22, $userId);
            _assign_set_responder_status($responderId, _assign_status_id_by_action('on_scene'));

        } elseif ($newStatus === 'clear') {
            db_query(
                "UPDATE `{$prefix}assigns` SET `clear` = ? WHERE `id` = ?",
                [$now, $assignId]
            );
            _assign_log_action($ticketId, $respName . ' cleared', 23, $userId);
            // Phase 25: prefer picked status; else mapped clear; else
            // Available. ONLY revert if no other active assignments.
            if (!_assign_has_other_active($responderId, $assignId)) {
                $clearStatus = $newStatusId > 0 ? $newStatusId
                    : (_assign_status_id_by_action('clear') ?: _assign_available_status_id());
                _assign_set_responder_status($responderId, $clearStatus);
            }
            // Phase 104d (a beta tester GH #11) — if this was the last active
            // unit on the incident and auto-close is enabled, schedule
            // the close for grace-seconds from now. Fails soft.
            try {
                require_once __DIR__ . '/auto_close.php';
                auto_close_maybe_schedule($ticketId, $userId);
            } catch (Throwable $e) { error_log('[assignment-write] auto_close_schedule: ' . $e->getMessage()); }
        } elseif ($newStatusId > 0) {
            // Phase 25 picked-status path: incident_action is empty —
            // Transporting, At Facility, In Quarters, etc. Just update
            // responder.un_status_id and log. Assigns timestamps stay
            // put (Phase 33A hotfix; otherwise the "reset to Available"
            // block would immediately undo the picked status).
            //
            // Phase 104f (a beta tester GH #10, 2026-07-02) — validate
            // extra_data_required here too. The situation-page /s
            // command already refuses statuses missing required extra
            // data (see responder_set_status_internal), but this per-
            // assign endpoint was silently accepting them, which was
            // a beta tester's "incident-detail bypass" report. Read the same
            // extra_data_* columns and enforce the same rule. Extra
            // data payload rides in on `input['extra_data']` from the
            // caller — api/incident-assign.php now forwards it.
            $picked = null;
            try {
                $picked = db_fetch_one(
                    "SELECT `id`, `status_val`,
                            `extra_data_type`, `extra_data_required`, `extra_data_label`
                     FROM `{$prefix}un_status` WHERE `id` = ? LIMIT 1",
                    [$newStatusId]
                );
            } catch (Exception $e) {
                // Older schema without extra_data_* columns — fall
                // back to the legacy read so pre-Phase-95 installs
                // still work.
                $picked = db_fetch_one(
                    "SELECT `id`, `status_val` FROM `{$prefix}un_status` WHERE `id` = ? LIMIT 1",
                    [$newStatusId]
                );
                if ($picked) {
                    $picked['extra_data_type'] = 'none';
                    $picked['extra_data_required'] = 0;
                    $picked['extra_data_label'] = null;
                }
            }
            if ($picked) {
                $edType     = (string) ($picked['extra_data_type'] ?? 'none');
                $edRequired = (int)    ($picked['extra_data_required'] ?? 0);
                $edLabel    = (string) ($picked['extra_data_label'] ?? '');

                // Extra-data payload (Phase 95) supplied by the caller, if any.
                // Read it unconditionally (not only when required) so an optional
                // facility still gets stored below.
                $supplied = null;
                if (isset($GLOBALS['_assign_update_status_input']['extra_data'])) {
                    $ed = $GLOBALS['_assign_update_status_input']['extra_data'];
                    if (is_array($ed) && array_key_exists('value', $ed)) $supplied = $ed['value'];
                }

                if ($edType !== 'none' && $edRequired) {
                    $isEmpty = ($supplied === null || $supplied === ''
                                || (is_array($supplied) && empty($supplied)));
                    if ($isEmpty) {
                        return ['errors' => [
                            'extra_data_required',
                            'label:' . ($edLabel !== '' ? $edLabel : $edType),
                        ]];
                    }
                }
                _assign_set_responder_status($responderId, (int) $picked['id']);
                _assign_log_action($ticketId, $respName . ' status: ' . $picked['status_val'], 21, $userId);

                // Phase 116 — per-unit receiving facility (restore legacy MCI
                // capability). When the status carries a destination facility,
                // store it on THIS assignment (assigns.rec_facility_id), not just
                // the incident. We know the exact assign row here, so each unit on
                // a multi-casualty incident carries its own destination hospital;
                // bed_auto resolves COALESCE(assign, ticket) so this per-unit value
                // takes precedence. See specs/phase-116-per-unit-receiving-facility.
                if ($edType === 'facility' && $supplied !== null && (int) $supplied > 0) {
                    assign_set_rec_facility($assignId, (int) $supplied, $userId);
                }

                // Phase 116 — fire bed automation from THIS path too. The
                // incident-detail per-unit status change funnels through here
                // (api/incident-assign.php), a DIFFERENT path than the situation
                // /s command (responder_set_status_internal), which already fires
                // bed_auto. Without this, a dispatcher who marks a unit into a
                // delivery status on the incident screen would set the per-unit
                // destination above but never move the facility's beds. bed_auto
                // dedups per (assign_id, facility_id), so this can't double-count
                // even if the same status is re-applied. Soft-fail — never blocks
                // the status change.
                try {
                    require_once __DIR__ . '/bed_auto.php';
                    if (function_exists('bed_auto_apply_on_status_change')) {
                        bed_auto_apply_on_status_change(
                            $responderId, (int) $picked['id'], (string) $picked['status_val'], $userId
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[assignment-write] bed_auto: ' . $e->getMessage());
                }

                $newStatus = (string) $picked['status_val'];
            }
        }
    } catch (Exception $e) {
        return ['errors' => ['Failed to update status: ' . $e->getMessage()]];
    }

    _assign_touch_ticket($ticketId);

    return [
        'status' => $newStatus,
        'errors' => [],
    ];
}

/**
 * Unassign — mark the assignment as cleared by stamping `clear` to NOW().
 *
 * Mirrors api/incident-assign.php's `unassign` action body:
 *   - Fetch assigns row
 *   - Stamp `clear` = NOW()
 *   - Reset responder to Available ONLY if no other active assignments
 *   - Action-log entry (action_type 24)
 *   - Touch ticket
 *
 * @return array ['unassigned' => bool, 'errors' => []]
 */
function assign_unassign_internal(int $assignId, int $userId): array {
    if ($assignId <= 0) return ['errors' => ['Invalid assignment ID']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now    = date('Y-m-d H:i:s');

    // Fetch + responder display name
    try {
        $assign = db_fetch_one(
            "SELECT `a`.*, `r`.`name` AS `responder_name`, `r`.`handle` AS `responder_handle`
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
             WHERE `a`.`id` = ?",
            [$assignId]
        );
    } catch (Exception $e) {
        return ['errors' => ['Database error: ' . $e->getMessage()]];
    }
    if (!$assign) return ['errors' => ['Assignment not found']];

    $ticketId    = (int) $assign['ticket_id'];
    $responderId = (int) $assign['responder_id'];
    $respName    = $assign['responder_handle'] ?: $assign['responder_name'];

    // Mark cleared
    try {
        db_query(
            "UPDATE `{$prefix}assigns` SET `clear` = ? WHERE `id` = ?",
            [$now, $assignId]
        );
    } catch (Exception $e) {
        return ['errors' => ['Failed to unassign: ' . $e->getMessage()]];
    }

    // Revert responder to Available iff no other active assignments
    if (!_assign_has_other_active($responderId, $assignId)) {
        $availableStatus = _assign_available_status_id();
        try {
            db_query(
                "UPDATE `{$prefix}responder`
                    SET `un_status_id` = ?, `status_updated` = ?
                  WHERE `id` = ?",
                [$availableStatus, $now, $responderId]
            );
        } catch (Exception $e) { /* non-fatal */ }
    }

    _assign_log_action($ticketId, $respName . ' unassigned', 24, $userId);
    _assign_touch_ticket($ticketId);

    // a beta tester GH #11 (2026-07-04) — unassigning a unit can leave the
    // incident with zero active units, which should schedule auto-close
    // just like clearing does. Only assign_update_status_internal()'s
    // 'clear' branch called auto_close_maybe_schedule() before, so
    // incidents emptied via the Unassign action never got scheduled
    // (his ticket 84 had auto_close_scheduled_at = NULL). Mirror it here.
    try {
        require_once __DIR__ . '/auto_close.php';
        auto_close_maybe_schedule($ticketId, $userId);
    } catch (Throwable $e) {
        error_log('[assignment-write] auto_close_schedule (unassign): ' . $e->getMessage());
    }

    return [
        'unassigned' => true,
        'ticket_id'  => $ticketId,
        'responder_id' => $responderId,
        'errors'     => [],
    ];
}
