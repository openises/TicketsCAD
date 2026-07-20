<?php
/**
 * NewUI v4.0 API - Mobile Unit Data
 *
 * Provides data for the mobile field unit interface.
 *
 * GET  /api/mobile-data.php                — Dashboard data (status, assignment, history)
 * GET  /api/mobile-data.php?action=statuses — Available unit statuses
 * POST /api/mobile-data.php action=add_note — Add note to assigned incident
 * POST /api/mobile-data.php action=start_mileage — Start mileage trip
 * POST /api/mobile-data.php action=stop_mileage  — Stop mileage trip
 * POST /api/mobile-data.php action=report_location — Report GPS position
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Safe query helper
function safe_mobile_fetch($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_mobile_fetch] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET — Read operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // Return available unit statuses
    // Phase 69: column names corrected — un_status uses `bg_color`+`sort`,
    // not `color`+`sort_order`. The `hide` column is a 'y'/'n' enum, not
    // a 0/1 int (seed migration history thing). Alias bg_color back to
    // the JS-expected `color` field.
    if (isset($_GET['action']) && $_GET['action'] === 'statuses') {
        // Phase 95 (2026-06-28) — include extra_data_* columns so
        // the mobile UI can prompt for configured extra-data when a
        // status is selected. Two-tier fetch: full set first, then
        // legacy fallback for pre-Phase-95 installs.
        $statuses = safe_mobile_fetch(
            "SELECT `id`, `status_val`, `description`,
                    `bg_color` AS `color`, `text_color`,
                    `dispatch`, `incident_action`, `resets_par`,
                    `extra_data_type`, `extra_data_required`,
                    `extra_data_label`, `extra_data_target`
             FROM `{$prefix}un_status`
             WHERE (`hide` IS NULL OR `hide` = '' OR `hide` = 'n' OR `hide` = '0' OR `hide` = 0)
             ORDER BY `sort`, `id`"
        );
        if (empty($statuses)) {
            $statuses = safe_mobile_fetch(
                "SELECT `id`, `status_val`, `description`,
                        `bg_color` AS `color`, `text_color`,
                        `dispatch`, `incident_action`, `resets_par`
                 FROM `{$prefix}un_status`
                 WHERE (`hide` IS NULL OR `hide` = '' OR `hide` = 'n' OR `hide` = '0' OR `hide` = 0)
                 ORDER BY `sort`, `id`"
            );
            foreach ($statuses as &$s) {
                $s['extra_data_type']     = 'none';
                $s['extra_data_required'] = 0;
                $s['extra_data_label']    = null;
                $s['extra_data_target']   = 'action_log';
            }
            unset($s);
        }
        json_response(['statuses' => $statuses]);
    }

    // ── Find the responder linked to this user ─────────────────
    // Phase 69: added the personal_for_member_id resolver path (Phase 54
    // introduced personal units — a member's self-clocked-in resource
    // lives on `responder.personal_for_member_id`, NOT `responder.user_id`
    // necessarily). Without this path a clocked-in responder using
    // mobile.php saw an empty assignment list even though they were
    // dispatched on incidents. Also dropped the broken `r.member_id`
    // JOIN — that column doesn't exist on this schema.
    $responderId = null;

    // Path 1: direct user_id linkage on the responder row
    $respRow = safe_mobile_fetch(
        "SELECT `id`, `name`, `un_status_id`, `status_about`, `handle`
         FROM `{$prefix}responder`
         WHERE `user_id` = ? AND (`deleted_at` IS NULL)
         LIMIT 1",
        [$current_user_id]
    );

    // Path 2 (Phase 69): personal-resource unit for this member
    if (empty($respRow) && $current_member_id) {
        $respRow = safe_mobile_fetch(
            "SELECT `id`, `name`, `un_status_id`, `status_about`, `handle`
             FROM `{$prefix}responder`
             WHERE `personal_for_member_id` = ? AND (`deleted_at` IS NULL)
             LIMIT 1",
            [$current_member_id]
        );
    }

    // Path 3: match by username on name or handle
    if (empty($respRow)) {
        $respRow = safe_mobile_fetch(
            "SELECT `id`, `name`, `un_status_id`, `status_about`, `handle`
             FROM `{$prefix}responder`
             WHERE (`name` = ? OR `handle` = ?) AND (`deleted_at` IS NULL)
             LIMIT 1",
            [$current_user, $current_user]
        );
    }

    $responder = !empty($respRow) ? $respRow[0] : null;
    $responderId = $responder ? (int) $responder['id'] : 0;

    // ── Phase 116c (GH #85 / a beta tester's SAG-vehicle case) — crew visibility ──
    // A driver/communicator assigned to a UNIT (via unit_personnel_assignments)
    // must see that unit's incident on mobile, even though the unit isn't "their"
    // responder. Resolve the units this user actively crews and treat the user's
    // own responder + those units as one set for the assignment/incident lookups.
    // Read-only visibility — the crew view the vehicle's call; the vehicle is what
    // gets dispatched, not the individuals. Guarded for installs without the table.
    $crewUnitIds = [];
    try {
        $crewRows = safe_mobile_fetch(
            "SELECT DISTINCT upa.`responder_id`
               FROM `{$prefix}unit_personnel_assignments` upa
               JOIN `{$prefix}member` m ON m.`id` = upa.`member_id`
              WHERE m.`user_id` = ?
                AND upa.`status` IN ('active','standby')
                AND (upa.`released_at` IS NULL OR DATE_FORMAT(upa.`released_at`,'%y') = '00')",
            [$current_user_id]
        );
        foreach ($crewRows as $cr) {
            $rid = (int) $cr['responder_id'];
            if ($rid > 0) $crewUnitIds[] = $rid;
        }
    } catch (Exception $e) { /* older install without unit_personnel_assignments */ }

    // If the user has no responder of their own but crews a unit, surface that
    // unit as their mobile context so the header shows the vehicle.
    if (!$responder && !empty($crewUnitIds)) {
        $vehRow = safe_mobile_fetch(
            "SELECT `id`, `name`, `un_status_id`, `status_about`, `handle`
               FROM `{$prefix}responder`
              WHERE `id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
            [$crewUnitIds[0]]
        );
        $responder   = !empty($vehRow) ? $vehRow[0] : null;
        $responderId = $responder ? (int) $responder['id'] : 0;
    }

    // The full set of responder ids whose incidents this user should see:
    // their own responder plus every unit they crew.
    $viewResponderIds = array_values(array_unique(array_filter(
        array_merge([$responderId], $crewUnitIds),
        function ($v) { return (int) $v > 0; }
    )));

    // ── Get current status info ────────────────────────────────
    // Phase 69: column is `bg_color`, not `color`.
    $currentStatus = null;
    if ($responder && !empty($responder['un_status_id'])) {
        $stRow = safe_mobile_fetch(
            "SELECT `id`, `status_val`, `bg_color` AS `color`
             FROM `{$prefix}un_status`
             WHERE `id` = ?",
            [(int) $responder['un_status_id']]
        );
        $currentStatus = !empty($stRow) ? $stRow[0] : null;
    }

    // ── Get current assignment ─────────────────────────────────
    // Phase 69: ticket columns are `street`/`lng`, NOT `address`/`lon`.
    // The `apt` column doesn't exist either. `in_types.type` is the
    // text label; FK from ticket is `in_types_id`, not `type`. The old
    // query died silently leaving the mobile UI showing "No active
    // assignment" for everyone, dispatched or not.
    $assignment = null;
    if (!empty($viewResponderIds)) {
        $ph = implode(',', array_fill(0, count($viewResponderIds), '?'));
        $aRows = safe_mobile_fetch(
            "SELECT a.`id` AS assign_id, a.`ticket_id`,
                    t.`street` AS `address`, t.`city`, t.`state`,
                    t.`scope` AS `nature`, t.`description`,
                    t.`lat`, t.`lng` AS `lon`,
                    t.`status` AS ticket_status, t.`severity`,
                    t.`contact`, t.`phone`,
                    t.`incident_number`,
                    it.`type` AS incident_type,
                    it.`color` AS type_color,
                    r.`name` AS assigned_unit_name, r.`handle` AS assigned_unit_handle
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}ticket` t ON t.`id` = a.`ticket_id`
             LEFT JOIN `{$prefix}in_types` it ON it.`id` = t.`in_types_id`
             LEFT JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
             WHERE a.`responder_id` IN ($ph)
               AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`,'%y') = '00')
               AND t.`status` = 2
               AND (t.`deleted_at` IS NULL)
             ORDER BY a.`id` DESC
             LIMIT 1",
            $viewResponderIds
        );
        $assignment = !empty($aRows) ? $aRows[0] : null;
    }

    // ── Recent assignments (last 5 closed) ─────────────────────
    // Phase 69: same schema fixes as the current-assignment query.
    $recentAssignments = [];
    if (!empty($viewResponderIds)) {
        $ph = implode(',', array_fill(0, count($viewResponderIds), '?'));
        $recentAssignments = safe_mobile_fetch(
            "SELECT a.`ticket_id`,
                    t.`street` AS `address`, t.`city`,
                    t.`scope` AS `nature`, t.`description`,
                    it.`type` AS incident_type,
                    a.`clear` AS cleared_at
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}ticket` t ON t.`id` = a.`ticket_id`
             LEFT JOIN `{$prefix}in_types` it ON it.`id` = t.`in_types_id`
             WHERE a.`responder_id` IN ($ph)
               AND a.`clear` IS NOT NULL
               AND DATE_FORMAT(a.`clear`,'%y') != '00'
             ORDER BY a.`clear` DESC
             LIMIT 5",
            $viewResponderIds
        );
    }

    // ── Active mileage trip ────────────────────────────────────
    $activeMileage = null;
    if ($responderId > 0) {
        $mRows = safe_mobile_fetch(
            "SELECT `id`, `start_odo`, `started_at`, `ticket_id`, `notes`
             FROM `{$prefix}mileage_log`
             WHERE `responder_id` = ? AND `ended_at` IS NULL
             ORDER BY `id` DESC LIMIT 1",
            [$responderId]
        );
        $activeMileage = !empty($mRows) ? $mRows[0] : null;
    }

    json_response([
        'responder'          => $responder,
        'responder_id'       => $responderId,
        'current_status'     => $currentStatus,
        'assignment'         => $assignment,
        'recent_assignments' => $recentAssignments,
        'active_mileage'     => $activeMileage,
        'user'               => $current_user,
        'csrf_token'         => csrf_token()
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body');
    }

    // CSRF check
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    // RBAC enforcement (specs/rbac-enforcement-2026-06).
    // All mobile write actions (note, mileage, location) gate on
    // action.change_unit_status — NOT report_location, which no default role
    // holds. GET (read) stays open to viewers.
    if (!rbac_can('action.change_unit_status')) {
        json_error('Insufficient permissions: change unit status', 403);
    }

    $action = $input['action'] ?? '';

    // ── Add note to assigned incident ──────────────────────────
    if ($action === 'add_note') {
        $ticketId = (int) ($input['ticket_id'] ?? 0);
        $note = trim($input['note'] ?? '');
        if (!$ticketId || !$note) {
            json_error('Missing ticket_id or note');
        }

        // Phase 72: the action table columns are
        // `ticket_id, date, description, user, action_type, responder`
        // — the old INSERT referenced `action`, `a_time`, `a_user`,
        // none of which exist, so every mobile-app note was silently
        // discarded for years. action_type 11 matches the existing
        // seed rows for free-text notes (see SELECT DISTINCT
        // action_type FROM action: 55 rows already use 11).
        try {
            // Best-effort responder lookup so the note is attributed
            // to the unit the responder is operating from. Same
            // resolution path the GET handler uses above.
            $noteResponderId = null;
            try {
                $resp = db_fetch_one(
                    "SELECT id FROM `{$prefix}responder`
                      WHERE (user_id = ?
                             OR personal_for_member_id = ?)
                        AND (deleted_at IS NULL)
                      ORDER BY (user_id = ?) DESC
                      LIMIT 1",
                    [$current_user_id, (int) ($current_member_id ?? 0), $current_user_id]
                );
                if ($resp) $noteResponderId = (int) $resp['id'];
            } catch (Exception $eR) { /* leave null */ }

            db_query(
                "INSERT INTO `{$prefix}action`
                 (`ticket_id`, `date`, `description`, `user`,
                  `action_type`, `responder`, `updated`)
                 VALUES (?, NOW(), ?, ?, 11, ?, NOW())",
                [$ticketId, $note, (int) $current_user_id, $noteResponderId]
            );
            audit_log('incident', 'update', 'ticket', $ticketId,
                "Mobile note added by '{$current_user}'");

            // Issue #13 (a beta tester 2026-07-05) — push the new note to any dispatcher
            // viewing this incident. The desktop note path (api/incident-update.php)
            // emits incident:note; this mobile path emitted nothing, so mobile
            // notes never surfaced in an open CAD incident window. Best-effort.
            if (is_file(__DIR__ . '/../inc/sse.php')) {
                require_once __DIR__ . '/../inc/sse.php';
                if (function_exists('sse_publish_for_incident')) {
                    try {
                        sse_publish_for_incident('incident:note', ['ticket_id' => $ticketId], $ticketId);
                    } catch (Throwable $sseE) { /* non-fatal */ }
                }
            }
        } catch (Exception $e) {
            json_error('Failed to add note: ' . $e->getMessage());
        }
        json_response(['success' => true, 'message' => 'Note added']);
    }

    // ── Start mileage trip ─────────────────────────────────────
    if ($action === 'start_mileage') {
        $responderId = (int) ($input['responder_id'] ?? 0);
        $startOdo = isset($input['start_odo']) ? (float) $input['start_odo'] : null;
        $ticketId = !empty($input['ticket_id']) ? (int) $input['ticket_id'] : null;

        if (!$responderId) {
            json_error('Missing responder_id');
        }

        // Close any existing open trip first
        try {
            db_query(
                "UPDATE `{$prefix}mileage_log`
                 SET `ended_at` = NOW()
                 WHERE `responder_id` = ? AND `ended_at` IS NULL",
                [$responderId]
            );
        } catch (Exception $e) {
            // non-fatal
        }

        try {
            db_query(
                "INSERT INTO `{$prefix}mileage_log`
                 (`responder_id`, `user_id`, `ticket_id`, `start_odo`, `started_at`)
                 VALUES (?, ?, ?, ?, NOW())",
                [$responderId, $current_user_id, $ticketId, $startOdo]
            );
            $id = db_insert_id();
            audit_log('asset', 'create', 'mileage_log', $id,
                "Mileage trip started by '{$current_user}'");
        } catch (Exception $e) {
            json_error('Failed to start mileage: ' . $e->getMessage());
        }
        json_response(['success' => true, 'mileage_id' => $id]);
    }

    // ── Stop mileage trip ──────────────────────────────────────
    if ($action === 'stop_mileage') {
        $mileageId = (int) ($input['mileage_id'] ?? 0);
        $endOdo = isset($input['end_odo']) ? (float) $input['end_odo'] : null;
        $notes = trim($input['notes'] ?? '');

        if (!$mileageId) {
            json_error('Missing mileage_id');
        }

        try {
            db_query(
                "UPDATE `{$prefix}mileage_log`
                 SET `end_odo` = ?, `ended_at` = NOW(), `notes` = ?
                 WHERE `id` = ? AND `user_id` = ?",
                [$endOdo, $notes ?: null, $mileageId, $current_user_id]
            );
            audit_log('asset', 'update', 'mileage_log', $mileageId,
                "Mileage trip ended by '{$current_user}'");
        } catch (Exception $e) {
            json_error('Failed to stop mileage: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Report GPS location ────────────────────────────────────
    if ($action === 'report_location') {
        // Phase 73v — CRITICAL: previously trusted client-supplied
        // responder_id, so any logged-in user could move ANY unit on
        // the map by POSTing someone else's id. Fix: resolve the
        // caller's own responder server-side, the same 3-path lookup
        // the GET handler uses. The client-supplied responder_id is
        // discarded.
        $resolved = safe_mobile_fetch(
            "SELECT `id` FROM `{$prefix}responder`
             WHERE `user_id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
            [$current_user_id]
        );
        if (empty($resolved) && $current_member_id) {
            $resolved = safe_mobile_fetch(
                "SELECT `id` FROM `{$prefix}responder`
                 WHERE `personal_for_member_id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
                [$current_member_id]
            );
        }
        if (empty($resolved)) {
            $resolved = safe_mobile_fetch(
                "SELECT `id` FROM `{$prefix}responder`
                 WHERE (`name` = ? OR `handle` = ?) AND (`deleted_at` IS NULL) LIMIT 1",
                [$current_user, $current_user]
            );
        }
        $responderId = !empty($resolved) ? (int) $resolved[0]['id'] : 0;

        $lat = isset($input['lat']) ? (float) $input['lat'] : null;
        $lng = isset($input['lng']) ? (float) $input['lng'] : null;
        $accuracy = isset($input['accuracy']) ? (float) $input['accuracy'] : null;

        if (!$responderId) {
            json_error('No responder linked to this account — clock in first', 403);
        }
        if ($lat === null || $lng === null) {
            json_error('Missing lat or lng');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_error('Coordinates out of range');
        }

        try {
            // Update responder position. Phase 69: column is `lng`, not `lon`.
            db_query(
                "UPDATE `{$prefix}responder`
                 SET `lat` = ?, `lng` = ?, `updated` = NOW()
                 WHERE `id` = ?",
                [$lat, $lng, $responderId]
            );

            // Also insert into location_reports — Phase 69: the table is
            // keyed by `provider_id` + `unit_identifier`, not `responder_id`
            // + `provider_code`. Look up the browser_gps provider id and
            // write a row tagged 'unit-<rid>' so the resolver picks it up.
            try {
                $intProvId = db_fetch_value(
                    "SELECT `id` FROM `{$prefix}location_providers` WHERE `code` = 'internal' LIMIT 1"
                );
                if ($intProvId) {
                    db_query(
                        "INSERT INTO `{$prefix}location_reports`
                         (`provider_id`, `unit_identifier`, `lat`, `lng`,
                          `accuracy`, `reported_at`, `received_at`)
                         VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                        [(int) $intProvId, 'unit-' . $responderId, $lat, $lng, $accuracy]
                    );
                }
            } catch (Exception $e) {
                // location_reports table may not exist on a stripped-down
                // install — keep going, the responder UPDATE still ran.
            }

            // Check geofences for this position update
            try {
                require_once __DIR__ . '/../inc/geofence.php';
                $unitId = 'unit-' . $responderId;
                geofence_check($lat, $lng, $unitId);
            } catch (Exception $e) {
                // Non-fatal — geofence tables may not exist
            }
        } catch (Exception $e) {
            json_error('Failed to report location: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
