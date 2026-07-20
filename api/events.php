<?php
/**
 * NewUI v4.0 API - Events & Participant Management
 *
 * GET  /api/events.php                    — List all events (with filters)
 * GET  /api/events.php?id=X              — Single event with participants
 * GET  /api/events.php?upcoming=1        — Next 30 days of events
 * GET  /api/events.php?member_id=X       — Events a member is registered for
 * POST /api/events.php action=save        — Create/update event
 * POST /api/events.php action=delete      — Delete event
 * POST /api/events.php action=register    — Register for event (self or admin)
 * POST /api/events.php action=unregister  — Unregister from event
 * POST /api/events.php action=update_participant — Update participant status/hours
 * POST /api/events.php action=checkin     — Check in participant
 * POST /api/events.php action=checkout    — Check out participant (records hours)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_events($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_events] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

if ($method === 'GET') {
    handleEventGet();
} elseif ($method === 'POST') {
    handleEventPost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

function handleEventGet() {
    // Single event with participants
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $event = safe_fetch_events(
            "SELECT e.*, CONCAT(m.first_name, ' ', m.last_name) AS created_by_name
             FROM " . db_table('newui_events') . " e
             LEFT JOIN " . db_table('member') . " m ON e.created_by = m.id
             WHERE e.id = ?",
            [$id]
        );
        if (empty($event)) json_error('Event not found', 404);

        $participants = safe_fetch_events(
            "SELECT ep.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name,
                    m.callsign AS member_callsign
             FROM " . db_table('newui_event_participants') . " ep
             LEFT JOIN " . db_table('member') . " m ON ep.member_id = m.id
             WHERE ep.event_id = ?
             ORDER BY ep.status, m.last_name",
            [$id]
        );

        // Members list for registration — Phase 99j-5 org-scope filter.
        require_once __DIR__ . '/../inc/org-scope.php';
        [$memOrgFrag, $memOrgVars] = org_member_query_filter('m.id');
        $members = safe_fetch_events(
            "SELECT m.id, m.first_name, m.last_name, m.callsign FROM " . db_table('member') . " m
             WHERE 1=1 {$memOrgFrag} ORDER BY m.last_name, m.first_name",
            $memOrgVars
        );

        json_response([
            'event'        => $event[0],
            'participants' => $participants,
            'members'      => $members,
        ]);
    }

    // Events for a specific member
    if (!empty($_GET['member_id'])) {
        $memberId = intval($_GET['member_id']);
        $events = safe_fetch_events(
            "SELECT e.*, ep.status AS participant_status, ep.role AS participant_role,
                    ep.hours_worked
             FROM " . db_table('newui_events') . " e
             INNER JOIN " . db_table('newui_event_participants') . " ep ON e.id = ep.event_id
             WHERE ep.member_id = ?
             ORDER BY e.start_date DESC",
            [$memberId]
        );
        json_response(['events' => $events]);
    }

    // Upcoming events
    if (!empty($_GET['upcoming'])) {
        $days = min(max(intval($_GET['days'] ?? 30), 1), 365);
        $until = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $events = safe_fetch_events(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM " . db_table('newui_event_participants') . " WHERE event_id = e.id AND status != 'cancelled') AS participant_count
             FROM " . db_table('newui_events') . " e
             WHERE e.start_date >= NOW() AND e.start_date <= ?
               AND e.status != 'cancelled'
             ORDER BY e.start_date ASC",
            [$until]
        );
        json_response(['events' => $events]);
    }

    // List all events with filters
    $where = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[] = 'e.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['event_type'])) {
        $where[] = 'e.event_type = ?';
        $params[] = $_GET['event_type'];
    }
    if (!empty($_GET['search'])) {
        $term = '%' . trim($_GET['search']) . '%';
        $where[] = '(e.name LIKE ? OR e.location LIKE ?)';
        $params = array_merge($params, [$term, $term]);
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $events = safe_fetch_events(
        "SELECT e.*,
                (SELECT COUNT(*) FROM " . db_table('newui_event_participants') . " WHERE event_id = e.id AND status != 'cancelled') AS participant_count
         FROM " . db_table('newui_events') . " e
         {$whereSQL}
         ORDER BY e.start_date DESC
         LIMIT 200",
        $params
    );

    json_response(['events' => $events]);
}

function handleEventPost() {
    global $current_user_id, $current_level;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    $action = $input['action'] ?? '';

    // QA #3 — this POST handler had NO CSRF check and NO RBAC, so any logged-in
    // user (incl. Read-Only) could create/edit/delete events and register /
    // check in / check out ANY member. Verify CSRF on every mutation, then gate:
    //   • event CRUD + roster actions (checkin/checkout/update_participant) →
    //     require action.manage_schedule
    //   • register / unregister → allow a member to manage their OWN signup
    //     (by member id, not user id), else require action.manage_schedule.
    if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $canManageSched = function_exists('rbac_can') && rbac_can('action.manage_schedule');
    $myMemberId = (int) ($_SESSION['member_id'] ?? 0);
    if (in_array($action, ['save', 'delete', 'checkin', 'checkout', 'update_participant'], true)
        && !$canManageSched) {
        json_error('Schedule management permission required', 403);
    }
    if (in_array($action, ['register', 'unregister'], true)
        && (int) ($input['member_id'] ?? 0) !== $myMemberId && !$canManageSched) {
        json_error('You can only manage your own event registration', 403);
    }

    // ── Event CRUD ──
    if ($action === 'save') {
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Event name is required');
        $id = intval($input['id'] ?? 0);

        $fields = [
            'name'              => $name,
            'event_type'        => in_array(strtolower($input['event_type'] ?? ''), ['drill','exercise','deployment','meeting','training','other']) ? strtolower($input['event_type']) : 'other',
            'description'       => trim($input['description'] ?? ''),
            'start_date'        => $input['start_date'] ?? date('Y-m-d H:i:s'),
            'end_date'          => !empty($input['end_date']) ? $input['end_date'] : null,
            'location'          => trim($input['location'] ?? '') ?: null,
            'max_participants'  => !empty($input['max_participants']) ? intval($input['max_participants']) : null,
            'required_cert_ids' => !empty($input['required_cert_ids']) ? json_encode($input['required_cert_ids']) : null,
            'status'            => $input['status'] ?? 'planned',
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        try {
            if ($id > 0) {
                $setParts = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setParts[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                db_query("UPDATE " . db_table('newui_events') . " SET " . implode(', ', $setParts) . " WHERE id = ?", $params);
            } else {
                $fields['created_by'] = $current_user_id;
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('newui_events') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($fields)
                );
                $id = db_insert_id();
            }
        } catch (Exception $e) {
            json_error('Failed to save event: ' . $e->getMessage());
        }
        audit_log('personnel', $id > 0 ? 'update' : 'create', 'event', $id, ($id > 0 ? 'Updated' : 'Created') . " event '{$name}'", [
            'event_type' => $fields['event_type'],
            'start_date' => $fields['start_date'],
            'status' => $fields['status']
        ]);
        json_response(['success' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM " . db_table('newui_events') . " WHERE id = ?", [$id]);
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        audit_log('personnel', 'delete', 'event', $id, "Deleted event #{$id}");
        json_response(['success' => true]);
    }

    // ── Participant management ──
    if ($action === 'register') {
        $eventId  = intval($input['event_id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        // QA #2/#3 — self-signup keys on the MEMBER id (was compared to the
        // user id). Authorization is already enforced by the top-level gate;
        // this flag just records whether it was a self-registration.
        $selfSignup = (!empty($input['self_signup']) || $memberId === $myMemberId) ? 1 : 0;
        $role = trim($input['role'] ?? '');

        if (!$eventId || !$memberId) json_error('Missing event_id or member_id');

        // Check max_participants
        $event = safe_fetch_events(
            "SELECT * FROM " . db_table('newui_events') . " WHERE id = ?",
            [$eventId]
        );
        if (empty($event)) json_error('Event not found', 404);
        $event = $event[0];

        if ($event['max_participants']) {
            $currentCount = 0;
            try {
                $currentCount = db_fetch_value(
                    "SELECT COUNT(*) FROM " . db_table('newui_event_participants') . "
                     WHERE event_id = ? AND status != 'cancelled'",
                    [$eventId]
                );
            } catch (Exception $e) {}
            if ((int) $currentCount >= (int) $event['max_participants']) {
                json_error('Event is full (max ' . $event['max_participants'] . ' participants)');
            }
        }

        // Check prerequisite certs
        if ($event['required_cert_ids'] && !empty($input['check_prereqs'])) {
            $certIds = json_decode($event['required_cert_ids'], true);
            if (is_array($certIds)) {
                foreach ($certIds as $certId) {
                    try {
                        $held = db_fetch_value(
                            "SELECT COUNT(*) FROM " . db_table('member_certifications') . "
                             WHERE member_id = ? AND certification_id = ?
                               AND (expiration_date IS NULL OR expiration_date >= CURDATE())",
                            [$memberId, intval($certId)]
                        );
                    } catch (Exception $e) {
                        $held = 0;
                    }
                    if ((int) $held === 0) {
                        json_error('Missing required certification for this event');
                    }
                }
            }
        }

        try {
            db_query(
                "INSERT INTO " . db_table('newui_event_participants') . "
                 (event_id, member_id, status, self_signup, role)
                 VALUES (?, ?, 'registered', ?, ?)
                 ON DUPLICATE KEY UPDATE status = 'registered', self_signup = VALUES(self_signup)",
                [$eventId, $memberId, $selfSignup, $role ?: null]
            );
        } catch (Exception $e) {
            json_error('Failed to register: ' . $e->getMessage());
        }
        audit_log('personnel', 'assign', 'event_participant', null, ($selfSignup ? 'Self-registered' : 'Admin registered') . " member #{$memberId} for event #{$eventId}", [
            'event_id' => $eventId,
            'member_id' => $memberId,
            'self_signup' => $selfSignup,
            'role' => $role ?: null
        ]);
        json_response(['success' => true]);
    }

    if ($action === 'unregister') {
        $eventId  = intval($input['event_id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        if (!$eventId || !$memberId) json_error('Missing event_id or member_id');

        try {
            db_query(
                "UPDATE " . db_table('newui_event_participants') . "
                 SET status = 'cancelled'
                 WHERE event_id = ? AND member_id = ?",
                [$eventId, $memberId]
            );
        } catch (Exception $e) {
            json_error('Failed to unregister: ' . $e->getMessage());
        }
        audit_log('personnel', 'unassign', 'event_participant', null, "Unregistered member #{$memberId} from event #{$eventId}", [
            'event_id' => $eventId,
            'member_id' => $memberId
        ]);
        json_response(['success' => true]);
    }

    if ($action === 'update_participant') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing participant id');

        $setParts = [];
        $params = [];
        if (isset($input['status'])) {
            $setParts[] = 'status = ?';
            $params[] = $input['status'];
        }
        if (isset($input['role'])) {
            $setParts[] = 'role = ?';
            $params[] = $input['role'];
        }
        if (isset($input['hours_worked'])) {
            $setParts[] = 'hours_worked = ?';
            $params[] = floatval($input['hours_worked']);
        }
        if (isset($input['notes'])) {
            $setParts[] = 'notes = ?';
            $params[] = trim($input['notes']);
        }
        if (empty($setParts)) json_error('Nothing to update');

        $params[] = $id;
        try {
            db_query(
                "UPDATE " . db_table('newui_event_participants') . " SET " . implode(', ', $setParts) . " WHERE id = ?",
                $params
            );
        } catch (Exception $e) {
            json_error('Failed to update: ' . $e->getMessage());
        }
        audit_log('personnel', 'update', 'event_participant', $id, "Updated event participant #{$id}", [
            'status' => $input['status'] ?? null,
            'role' => $input['role'] ?? null,
            'hours_worked' => isset($input['hours_worked']) ? floatval($input['hours_worked']) : null
        ]);
        json_response(['success' => true]);
    }

    if ($action === 'checkin') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing participant id');
        try {
            db_query(
                "UPDATE " . db_table('newui_event_participants') . "
                 SET status = 'attended', check_in_time = NOW()
                 WHERE id = ?",
                [$id]
            );
        } catch (Exception $e) {
            json_error('Failed to check in: ' . $e->getMessage());
        }
        audit_log('personnel', 'update', 'event_participant', $id, "Checked in event participant #{$id}");
        json_response(['success' => true]);
    }

    if ($action === 'checkout') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing participant id');

        // Calculate hours from check_in_time
        try {
            $row = safe_fetch_events(
                "SELECT check_in_time FROM " . db_table('newui_event_participants') . " WHERE id = ?",
                [$id]
            );
            $hours = null;
            if (!empty($row) && $row[0]['check_in_time']) {
                $inTime = strtotime($row[0]['check_in_time']);
                $hours = round((time() - $inTime) / 3600, 2);
            }

            db_query(
                "UPDATE " . db_table('newui_event_participants') . "
                 SET check_out_time = NOW(), hours_worked = ?
                 WHERE id = ?",
                [$hours, $id]
            );
        } catch (Exception $e) {
            json_error('Failed to check out: ' . $e->getMessage());
        }
        audit_log('personnel', 'update', 'event_participant', $id, "Checked out event participant #{$id}", [
            'hours_worked' => $hours
        ]);
        json_response(['success' => true, 'hours_worked' => $hours]);
    }

    json_error('Unknown action: ' . $action);
}
