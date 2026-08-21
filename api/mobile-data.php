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
require_once __DIR__ . '/../inc/mobile-assignments.php';

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

// GH #77 — the units (responder ids) this user actively crews via
// unit_personnel_assignments. This is the Phase 116c / GH #85 query the
// GET dashboard header/assignment lookup below already runs to build
// $crewUnitIds — factored out so every mobile action that needs "which
// unit is this crew member operating from" calls the exact same query
// instead of each re-deriving (and drifting from) its own copy. That
// drift is exactly what GH #77 was: add_note and report_location each
// had their own narrower resolver that never consulted crew assignments
// at all, so a user who only crews a unit (no personal responder row)
// passed the header/assignment lookup and was rejected by the note/
// location paths in the same request.
function mobile_crew_unit_ids($prefix, $userId) {
    $ids = [];
    try {
        $crewRows = safe_mobile_fetch(
            "SELECT DISTINCT upa.`responder_id`
               FROM `{$prefix}unit_personnel_assignments` upa
               JOIN `{$prefix}member` m ON m.`id` = upa.`member_id`
              WHERE m.`user_id` = ?
                AND upa.`status` IN ('active','standby')
                AND (upa.`released_at` IS NULL OR DATE_FORMAT(upa.`released_at`,'%y') = '00')",
            [$userId]
        );
        foreach ($crewRows as $cr) {
            $rid = (int) $cr['responder_id'];
            if ($rid > 0) $ids[] = $rid;
        }
    } catch (Exception $e) { /* older install without unit_personnel_assignments */ }
    return $ids;
}

// GH #77 — the ONE canonical "which responder is this logged-in user"
// resolver, shared by add_note and report_location (and mirroring the
// GET handler's own resolution below, which stays inline there because
// it also needs the full responder row plus the complete crew-id LIST
// for multi-unit assignment visibility, not just a single id).
//
//   Path 1: responder.user_id = this user
//   Path 2 (Phase 69): responder.personal_for_member_id = this user's member
//   Path 3: name/handle match on username (legacy fallback)
//   Path 4 (Phase 116c / GH #85): no personal responder, but the user
//           actively crews a unit — fall back to the first one.
//
// Returns 0 if none of the four resolve.
function mobile_resolve_responder_id($prefix, $userId, $memberId, $username) {
    $row = safe_mobile_fetch(
        "SELECT `id` FROM `{$prefix}responder`
         WHERE `user_id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
        [$userId]
    );
    if (empty($row) && $memberId) {
        $row = safe_mobile_fetch(
            "SELECT `id` FROM `{$prefix}responder`
             WHERE `personal_for_member_id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
            [$memberId]
        );
    }
    if (empty($row)) {
        $row = safe_mobile_fetch(
            "SELECT `id` FROM `{$prefix}responder`
             WHERE (`name` = ? OR `handle` = ?) AND (`deleted_at` IS NULL) LIMIT 1",
            [$username, $username]
        );
    }
    if (!empty($row)) {
        return (int) $row[0]['id'];
    }
    $crewIds = mobile_crew_unit_ids($prefix, $userId);
    return !empty($crewIds) ? $crewIds[0] : 0;
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
        // GH#52 (2026-08-14) — added slot 2 (extra_data_*_2) to both
        // tiers; the mobile UI never surfaced slot 2 at all before this,
        // even when Settings had it configured, because these columns
        // simply weren't in the payload the client had to work with.
        $statuses = safe_mobile_fetch(
            "SELECT `id`, `status_val`, `description`,
                    `bg_color` AS `color`, `text_color`,
                    `dispatch`, `incident_action`, `resets_par`,
                    `extra_data_type`, `extra_data_required`,
                    `extra_data_label`, `extra_data_target`,
                    `extra_data_type_2`, `extra_data_required_2`,
                    `extra_data_label_2`, `extra_data_target_2`
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
                $s['extra_data_type']       = 'none';
                $s['extra_data_required']   = 0;
                $s['extra_data_label']      = null;
                $s['extra_data_target']     = 'action_log';
                $s['extra_data_type_2']     = 'none';
                $s['extra_data_required_2'] = 0;
                $s['extra_data_label_2']    = null;
                $s['extra_data_target_2']   = 'action_log';
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
    // GH #77 — now shared via mobile_crew_unit_ids() (identical query) so
    // add_note and report_location resolve the same crew set instead of
    // maintaining their own copy that can silently drift from this one.
    $crewUnitIds = mobile_crew_unit_ids($prefix, $current_user_id);

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

    // ── Get current assignment(s) ──────────────────────────────
    // Phase 69: ticket columns are `street`/`lng`, NOT `address`/`lon`.
    // The `apt` column doesn't exist either. `in_types.type` is the
    // text label; FK from ticket is `in_types_id`, not `type`. The old
    // query died silently leaving the mobile UI showing "No active
    // assignment" for everyone, dispatched or not.
    //
    // GH#82 (2026-08-18) — this used to be `ORDER BY a.id DESC LIMIT 1`:
    // newest assignment only. If a unit was given a second concurrent
    // call, the crew's ORIGINAL still-active incident disappeared from
    // this screen entirely, not just deprioritized. mobile_active_
    // assignments() (inc/mobile-assignments.php) now returns every active
    // assignment, oldest first, so `assignment` (kept for backward
    // compatibility with existing mobile.js) is the unit's original call,
    // and `assignments` carries the full list for any additional ones.
    $assignments = [];
    if (!empty($viewResponderIds)) {
        $assignments = mobile_active_assignments($prefix, $viewResponderIds);
    }
    $assignment = !empty($assignments) ? $assignments[0] : null;

    // ── Recent assignments (last 5 closed) ─────────────────────
    // Phase 69: same schema fixes as the current-assignment query.
    // Soft-delete sweep (issue #25 follow-up) — the current-assignment
    // query above already excludes deleted_at; this "recent" one (full
    // street address + description) did not.
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
               AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
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
        // GH#82 — every currently-active assignment (assignment == assignments[0]),
        // so a unit on more than one live call isn't hidden from its own crew.
        'assignments'        => $assignments,
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
            // to the unit the responder is operating from. GH #77: this
            // used to run its own narrower (user_id / personal_for_member_id
            // only, no crew fallback) lookup that had drifted from the GET
            // handler's — a crew-only member (no personal responder row)
            // got their note written with responder=NULL, unattributed to
            // the unit they're crewing, even though the mobile header
            // correctly showed that unit for the same user in the same
            // request. Now calls the shared resolver so this can't drift
            // again.
            $noteResponderId = mobile_resolve_responder_id(
                $prefix, $current_user_id, $current_member_id, $current_user
            );
            if (!$noteResponderId) $noteResponderId = null;

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

        // GH#96 (2026-08-20) — org_id, same session-derived convention as
        // inc/responder-write.php's _phase95_record_mileage_log(). Schema-
        // resilience guard: the column may not exist yet on an install that
        // hasn't run sql/run_gh96_mileage_log_org_id.php.
        require_once __DIR__ . '/../inc/org-scope.php';
        $mobileMileageOrgId = $_SESSION['active_org_id'] ?? null;
        if ($mobileMileageOrgId === null) {
            try { $mobileMileageOrgId = org_user_home_id((int) $current_user_id); } catch (Exception $e) { $mobileMileageOrgId = null; }
        }
        $hasMileageOrgIdCol = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ? AND column_name = 'org_id'",
            [$prefix . 'mileage_log']
        );

        try {
            if ($hasMileageOrgIdCol) {
                db_query(
                    "INSERT INTO `{$prefix}mileage_log`
                     (`responder_id`, `user_id`, `ticket_id`, `start_odo`, `started_at`, `org_id`)
                     VALUES (?, ?, ?, ?, NOW(), ?)",
                    [$responderId, $current_user_id, $ticketId, $startOdo, $mobileMileageOrgId]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}mileage_log`
                     (`responder_id`, `user_id`, `ticket_id`, `start_odo`, `started_at`)
                     VALUES (?, ?, ?, ?, NOW())",
                    [$responderId, $current_user_id, $ticketId, $startOdo]
                );
            }
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
        // caller's own responder server-side. The client-supplied
        // responder_id is discarded.
        //
        // GH #77: the 3-path lookup this used to run in-line never
        // consulted crew assignments (Phase 116c / GH #85), so a user who
        // crews a unit but has no personal responder row of their own was
        // rejected here with "No responder linked to this account — clock
        // in first" even though the SAME request's dashboard header
        // correctly resolved and displayed their crewed unit. Now shares
        // the canonical resolver (still 100% server-side — no client input
        // involved — so the Phase 73v security fix is unchanged).
        $responderId = mobile_resolve_responder_id(
            $prefix, $current_user_id, $current_member_id, $current_user
        );

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
