<?php
/**
 * Phase 155 (S0) — the ONE atomic path that turns a Scheduled incident
 * (ticket.status = 3) into an Open one (status = 2) when its booked time
 * arrives.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Until this phase the only code that did the 3 -> 2 flip was an inline block
 * inside the incident-LIST read path (api/incidents.php, func=0). That had
 * three real defects, all found by reading it against the GitHub threads
 * (GH#141, GH#147):
 *
 *   1. Nothing ran it when nobody had a dispatch board open. An External API
 *      consumer, a crew's phone and a dispatcher sitting on incident-detail
 *      all saw a stale status=3 indefinitely.
 *   2. It double-fired. It SELECTed the due ids, did one bulk UPDATE, then
 *      looped over the SELECTED ids announcing each one. Two dashboards
 *      polling in the same second both selected the same ids; one UPDATE won,
 *      the other matched zero rows -- but both loops still audited and
 *      published, so every activation produced duplicate audit rows, SSE and
 *      webhooks under normal polling.
 *   3. It was untestable (logic inside an endpoint -- the Phase 117 lesson).
 *
 * THE CONTRACT
 * ------------
 * incident_activate_scheduled_one() is a per-ticket COMPARE-AND-SET:
 *
 *     UPDATE ticket SET status = 2 ... WHERE id = ? AND status = 3
 *        AND booked_date <= NOW() AND not soft-deleted
 *
 * and `rowCount() === 1` means THIS process owns the transition. Only the owner
 * announces it (audit, SSE, the incident.status_changed event, reservation
 * promotion); a loser does nothing at all. Safe to call from any number of
 * processes at once, from any caller: the 60-second systemd timer
 * (tools/scheduled_incidents_tick.php), the lazy hook that is kept in
 * api/incidents.php so an install without a timer still behaves as before,
 * and tests.
 *
 * "Is it due" is decided by the DATABASE clock (NOW() in SQL), never PHP's
 * time(): Phase 143 measured a ~59-minute dev-machine/DB skew, and booked_date
 * is compared against NOW() everywhere else.
 *
 * The announcements name the SYSTEM as the actor. The lazy path runs inside a
 * dispatcher's web request, and the audit row used to name that dispatcher as
 * the person who activated an incident they never touched.
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/incident-write.php';

/**
 * Activate ONE due Scheduled incident, if this caller wins the race.
 *
 * @return array ['activated' => bool, 'promoted' => int, 'blocked' => int]
 *               activated=false means somebody else owns it (or it was not due).
 */
function incident_activate_scheduled_one(int $ticketId): array {
    $result = ['activated' => false, 'promoted' => 0, 'blocked' => 0];
    if ($ticketId <= 0) return $result;
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // The compare-and-set. problemend is cleared for the same reason the
    // reopen path clears it: an Open incident has no end time.
    $stmt = db_query(
        "UPDATE `{$prefix}ticket`
            SET `status` = 2, `problemend` = NULL, `updated` = NOW()
          WHERE `id` = ? AND `status` = 3
            AND `booked_date` IS NOT NULL AND `booked_date` <= NOW()
            AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
        [$ticketId]
    );
    if ($stmt->rowCount() !== 1) {
        return $result;                      // lost the race, or not due: say nothing
    }
    $result['activated'] = true;

    // ── We own the transition: announce it exactly once. ──
    // Every step is independent and best-effort -- the status flip above is
    // the fact; a failed announcement must not undo or hide it.
    $number = function_exists('incnum_display') ? incnum_display($ticketId) : ('#' . $ticketId);

    try {
        incident_status_change_emit($ticketId, 3, 2, [
            'source'     => 'scheduled_activation',
            'actor_type' => 'system',
        ]);
    } catch (Throwable $e) {
        error_log('[scheduled-incidents] status_change emit failed for #' . $ticketId . ': ' . $e->getMessage());
    }

    // The legacy audit row (incident.updated webhook) the old lazy block
    // wrote -- kept so existing subscribers see no change except that it now
    // happens once and names the system.
    try {
        audit_log('incident', 'update', 'ticket', $ticketId,
            "Scheduled incident {$number} auto-activated (booked time reached)",
            ['old_status' => 3, 'new_status' => 2, 'auto_activated' => true],
            AUDIT_INFO, AUDIT_ACTOR_SYSTEM);
    } catch (Throwable $e) {
        error_log('[scheduled-incidents] audit failed for #' . $ticketId . ': ' . $e->getMessage());
    }

    // The incident's own timeline gets the same "status changed" line a
    // dispatcher's manual change writes (action_type 10). ASCII "->" on purpose:
    // `action`.`description` is latin1 on every install that began as a legacy
    // v3 database (and in the base schema), and a "→" there makes the INSERT
    // fail with error 1366 -- which the catch below would swallow, so the line
    // would silently never appear.
    try {
        $now = date('Y-m-d H:i:s');
        db_query(
            "INSERT INTO `{$prefix}action` (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, 0, 10, ?)",
            [$ticketId, $now, 'Status changed: Scheduled -> Open (booked time reached)', $now]
        );
    } catch (Throwable $e) { /* non-fatal */ }

    try {
        if (!function_exists('sse_publish_for_incident') && is_file(__DIR__ . '/sse.php')) {
            require_once __DIR__ . '/sse.php';
        }
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('incident:update', [
                'ticket_id'       => $ticketId,
                'incident_number' => $number,
                'activated'       => true,
                'new_status'      => 2,
                'status_label'    => 'Open',
            ], $ticketId);
        }
    } catch (Throwable $e) { /* SSE is non-fatal */ }

    // Phase 155 (GH#141): dispatch any unit reserved for this call. A no-op on
    // an install with no reservations (or without the feature's schema).
    try {
        if (!function_exists('assign_reservations_promote_for_ticket') && is_file(__DIR__ . '/assign-reservations.php')) {
            require_once __DIR__ . '/assign-reservations.php';
        }
        if (function_exists('assign_reservations_promote_for_ticket')) {
            $p = assign_reservations_promote_for_ticket($ticketId, 0);
            $result['promoted'] = (int) ($p['promoted'] ?? 0);
            $result['blocked']  = (int) ($p['blocked'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('[scheduled-incidents] reservation promotion failed for #' . $ticketId . ': ' . $e->getMessage());
    }

    return $result;
}

/**
 * Activate every due Scheduled incident (oldest booked time first).
 *
 * @param int $limit  upper bound per call, so a backlog (the timer was off for
 *                    a day) cannot stall a request or a tick; the next call
 *                    continues where this one stopped.
 * @return array ['activated' => int, 'promoted' => int, 'blocked' => int, 'due' => int]
 */
function incident_activate_due_scheduled(int $limit = 20): array {
    $out = ['activated' => 0, 'promoted' => 0, 'blocked' => 0, 'due' => 0];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(500, $limit));

    // $limit is an int we just clamped, so inlining it is not an injection
    // surface (LIMIT cannot take a bound parameter under emulated-off PDO).
    $due = db_fetch_all(
        "SELECT `id` FROM `{$prefix}ticket`
          WHERE `status` = 3 AND `booked_date` IS NOT NULL AND `booked_date` <= NOW()
            AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')
          ORDER BY `booked_date` ASC, `id` ASC
          LIMIT " . (int) $limit
    );
    $out['due'] = count($due);
    foreach ($due as $row) {
        $r = incident_activate_scheduled_one((int) $row['id']);
        if (!empty($r['activated'])) {
            $out['activated']++;
            $out['promoted'] += (int) $r['promoted'];
            $out['blocked']  += (int) $r['blocked'];
        }
    }
    return $out;
}
