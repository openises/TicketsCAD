<?php
/**
 * NewUI v4.0 API — Unit Personnel Assignments
 *
 * Manages personnel-to-unit assignments. When a member is assigned to a unit,
 * the unit's location becomes the member's effective location and vice versa.
 *
 * GET  /api/unit-assignments.php                     — list all active assignments
 * GET  /api/unit-assignments.php?responder_id=X      — assignments for a unit
 * GET  /api/unit-assignments.php?member_id=X         — assignments for a member
 * GET  /api/unit-assignments.php?roles=1             — list configurable roles
 * POST action=assign       — assign member to unit
 * POST action=release      — release member from unit
 * POST action=update       — update assignment (role, status, notes)
 * POST action=bulk_assign  — assign multiple members to a unit
 * POST action=save_role    — create/update assignment role (admin)
 * POST action=delete_role  — delete assignment role (admin)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — Read operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // GET ?roles=1 — list configurable assignment roles
    if (isset($_GET['roles'])) {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `code`, `name`, `description`, `sort_order`, `active`
                 FROM `{$prefix}unit_assignment_roles`
                 ORDER BY `sort_order` ASC, `name` ASC"
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['roles' => $rows]);
    }

    // GET ?responder_id=X — assignments for a specific unit
    if (isset($_GET['responder_id'])) {
        $rid = (int) $_GET['responder_id'];
        $includeReleased = isset($_GET['include_released']) && $_GET['include_released'] === '1';

        $where = "upa.`responder_id` = ?";
        $params = [$rid];
        if (!$includeReleased) {
            $where .= " AND upa.`status` != 'released'";
        }

        try {
            $rows = db_fetch_all(
                "SELECT upa.`id`, upa.`responder_id`, upa.`member_id`, upa.`role`,
                        upa.`status`, upa.`assigned_at`, upa.`released_at`,
                        upa.`assigned_by`, upa.`notes`,
                        CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                        m.`callsign` AS `member_callsign`,
                        m.`phone_cell` AS `member_phone`,
                        r.`name` AS `unit_name`, r.`handle` AS `unit_handle`
                 FROM `{$prefix}unit_personnel_assignments` upa
                 LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
                 LEFT JOIN `{$prefix}responder` r ON upa.`responder_id` = r.`id`
                 WHERE $where
                 ORDER BY upa.`assigned_at` DESC",
                $params
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['assignments' => $rows]);
    }

    // GET ?member_id=X — assignments for a specific member
    if (isset($_GET['member_id'])) {
        $mid = (int) $_GET['member_id'];
        $includeReleased = isset($_GET['include_released']) && $_GET['include_released'] === '1';

        $where = "upa.`member_id` = ?";
        $params = [$mid];
        if (!$includeReleased) {
            $where .= " AND upa.`status` != 'released'";
        }

        try {
            $rows = db_fetch_all(
                "SELECT upa.`id`, upa.`responder_id`, upa.`member_id`, upa.`role`,
                        upa.`status`, upa.`assigned_at`, upa.`released_at`,
                        upa.`assigned_by`, upa.`notes`,
                        r.`name` AS `unit_name`, r.`handle` AS `unit_handle`,
                        r.`callsign` AS `unit_callsign`
                 FROM `{$prefix}unit_personnel_assignments` upa
                 LEFT JOIN `{$prefix}responder` r ON upa.`responder_id` = r.`id`
                 WHERE $where
                 ORDER BY upa.`assigned_at` DESC",
                $params
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['assignments' => $rows]);
    }

    // GET (no params) — all active assignments
    try {
        $rows = db_fetch_all(
            "SELECT upa.`id`, upa.`responder_id`, upa.`member_id`, upa.`role`,
                    upa.`status`, upa.`assigned_at`, upa.`released_at`,
                    upa.`assigned_by`, upa.`notes`,
                    CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                    m.`callsign` AS `member_callsign`,
                    r.`name` AS `unit_name`, r.`handle` AS `unit_handle`
             FROM `{$prefix}unit_personnel_assignments` upa
             LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
             LEFT JOIN `{$prefix}responder` r ON upa.`responder_id` = r.`id`
             WHERE upa.`status` != 'released'
             ORDER BY r.`name` ASC, upa.`assigned_at` DESC"
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['assignments' => $rows, 'count' => count($rows)]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // ── assign — assign a member to a unit ────────────────────
    if ($action === 'assign') {
        $responderId = isset($input['responder_id']) ? (int) $input['responder_id'] : 0;
        $memberId    = isset($input['member_id'])    ? (int) $input['member_id']    : 0;
        $role        = trim($input['role'] ?? 'operator');
        $notes       = trim($input['notes'] ?? '');

        if (!$responderId || !$memberId) {
            json_error('responder_id and member_id are required');
        }

        // Check if already actively assigned to this unit
        try {
            $existing = db_fetch_one(
                "SELECT `id` FROM `{$prefix}unit_personnel_assignments`
                 WHERE `responder_id` = ? AND `member_id` = ? AND `status` != 'released'",
                [$responderId, $memberId]
            );
        } catch (Exception $e) {
            $existing = null;
        }

        if ($existing) {
            json_error('Member is already assigned to this unit');
        }

        // One-unit-per-person rule: check if member is assigned to ANY other unit.
        // Pull multiple identifier columns so the confirmation prompt can show
        // something friendly even when the legacy `name` column is NULL — beta
        // tester a beta tester 2026-06-26 saw "assigned to Unit #5" because
        // his responder row had name=NULL. Fall back name → handle → callsign
        // → "Unit #<id>" so the operator always sees SOMETHING actionable.
        $force = !empty($input['force']);
        try {
            $otherUnit = db_fetch_one(
                "SELECT upa.`id`, upa.`responder_id`,
                        r.`name` AS `unit_name`,
                        r.`handle` AS `unit_handle`,
                        r.`callsign` AS `unit_callsign`
                 FROM `{$prefix}unit_personnel_assignments` upa
                 LEFT JOIN `{$prefix}responder` r ON upa.`responder_id` = r.`id`
                 WHERE upa.`member_id` = ? AND upa.`status` != 'released'",
                [$memberId]
            );
        } catch (Exception $e) {
            $otherUnit = null;
        }

        if ($otherUnit && !$force) {
            // Pick the best-available human-readable identifier for the
            // current unit. trim() catches all-whitespace values.
            $friendlyName = '';
            foreach (['unit_name', 'unit_handle', 'unit_callsign'] as $col) {
                if (!empty($otherUnit[$col]) && trim($otherUnit[$col]) !== '') {
                    $friendlyName = trim($otherUnit[$col]);
                    break;
                }
            }
            if ($friendlyName === '') {
                $friendlyName = 'an unnamed unit (id ' . (int) $otherUnit['responder_id']
                    . ' — please give it a name on the unit-edit page)';
            }

            // Return a special response that the UI can use to prompt for confirmation
            json_response([
                'needs_confirmation' => true,
                'message' => 'This member is currently assigned to ' . $friendlyName . '. Reassign?',
                'current_unit_name' => $friendlyName,
                'current_unit_id'   => (int) $otherUnit['responder_id'],
                'current_assignment_id' => (int) $otherUnit['id'],
            ]);
            exit;
        }

        // If force=true, release the existing assignment first
        if ($otherUnit && $force) {
            try {
                db_query(
                    "UPDATE `{$prefix}unit_personnel_assignments`
                     SET `status` = 'released', `released_at` = NOW()
                     WHERE `id` = ?",
                    [(int) $otherUnit['id']]
                );
                audit_log('personnel', 'update', 'unit_assignment', (int) $otherUnit['id'],
                    "Auto-released member #{$memberId} from unit #{$otherUnit['responder_id']} for reassignment");
            } catch (Exception $e) {
                // Non-fatal
            }
        }

        try {
            db_query(
                "INSERT INTO `{$prefix}unit_personnel_assignments`
                 (`responder_id`, `member_id`, `role`, `status`, `assigned_by`, `notes`)
                 VALUES (?, ?, ?, 'active', ?, ?)",
                [$responderId, $memberId, $role, $current_user_id, $notes]
            );
            $id = (int) db_insert_id();

            audit_log('personnel', 'create', 'unit_assignment', $id,
                "Assigned member #{$memberId} to unit #{$responderId} as {$role}");

            // Phase 56 (2026-06-14) — mutual exclusion. Per Eric: "if a
            // responder gets assigned to a unit, it should remove them
            // as a personal resource." Otherwise dispatch sees the
            // person twice (once on the multi-person unit, once as
            // their personal resource), which double-counts them on PAR
            // checks and clutters the board. Best-effort; never block
            // a unit assignment for a personal-unit housekeeping failure.
            //
            // Don't clock-out the personal unit if THIS assignment IS to
            // the member's own personal unit — that's a no-op / pathological
            // case and we'd just keep flipping the status.
            try {
                require_once __DIR__ . '/../inc/personnel-units.php';
                $personal = pu_get_personal_unit((int) $memberId);
                if ($personal && (int) $personal['id'] !== (int) $responderId
                              && !empty(pu_status_for_member((int) $memberId)['clocked_in'])) {
                    pu_clock_out((int) $memberId);
                    audit_log('personnel_unit', 'auto_clock_out', 'responder',
                        (int) $personal['id'],
                        "Auto-clocked-out personal unit (member #{$memberId} assigned to unit #{$responderId})",
                        ['triggered_by_assignment' => $id]);
                }
            } catch (Throwable $e) { /* swallow */ }

            // Auto-create location bindings from personnel's comm identifiers
            // This makes the unit inherit the member's location providers
            $autoBindCount = 0;
            try {
                $autoBindCount = _autoBindPersonnelLocation($responderId, $memberId, $id);
            } catch (Exception $e) {
                // Non-fatal
            }

            // Publish SSE event for real-time updates
            try {
                require_once __DIR__ . '/../inc/sse.php';
                sse_publish('unit:assignment', [
                    'action'       => 'assign',
                    'assignment_id' => $id,
                    'responder_id' => $responderId,
                    'member_id'    => $memberId,
                    'role'         => $role,
                    'auto_bindings' => $autoBindCount,
                ]);
            } catch (Exception $e) {
                // SSE is optional
            }

            json_response(['success' => true, 'id' => $id, 'auto_bindings' => $autoBindCount]);
        } catch (Exception $e) {
            json_error('Assignment failed: ' . $e->getMessage(), 500);
        }
    }

    // ── release — release member from unit ────────────────────
    if ($action === 'release') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Assignment id is required');
        }

        try {
            db_query(
                "UPDATE `{$prefix}unit_personnel_assignments`
                 SET `status` = 'released', `released_at` = NOW()
                 WHERE `id` = ? AND `status` != 'released'",
                [$id]
            );

            audit_log('personnel', 'update', 'unit_assignment', $id,
                "Released assignment #{$id}");

            // Deactivate auto-created location bindings from this assignment
            try {
                db_query(
                    "UPDATE `{$prefix}unit_location_bindings`
                     SET `active` = 0
                     WHERE `source` = 'personnel' AND `assignment_id` = ?",
                    [$id]
                );
            } catch (Exception $e) {}

            try {
                require_once __DIR__ . '/../inc/sse.php';
                sse_publish('unit:assignment', [
                    'action'       => 'release',
                    'assignment_id' => $id,
                ]);
            } catch (Exception $e) {}

            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Release failed: ' . $e->getMessage(), 500);
        }
    }

    // ── update — update assignment details ────────────────────
    if ($action === 'update') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Assignment id is required');
        }

        $sets = [];
        $params = [];

        if (isset($input['role'])) {
            $sets[] = '`role` = ?';
            $params[] = trim($input['role']);
        }
        if (isset($input['status'])) {
            $validStatuses = ['active', 'standby', 'released'];
            if (!in_array($input['status'], $validStatuses)) {
                json_error('Invalid status. Must be: active, standby, or released');
            }
            $sets[] = '`status` = ?';
            $params[] = $input['status'];
            if ($input['status'] === 'released') {
                $sets[] = '`released_at` = NOW()';
            }
        }
        if (isset($input['notes'])) {
            $sets[] = '`notes` = ?';
            $params[] = trim($input['notes']);
        }

        if (empty($sets)) {
            json_error('Nothing to update');
        }

        $params[] = $id;
        try {
            db_query(
                "UPDATE `{$prefix}unit_personnel_assignments`
                 SET " . implode(', ', $sets) . "
                 WHERE `id` = ?",
                $params
            );

            audit_log('personnel', 'update', 'unit_assignment', $id,
                "Updated assignment #{$id}");

            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Update failed: ' . $e->getMessage(), 500);
        }
    }

    // ── bulk_assign — assign multiple members to a unit ───────
    if ($action === 'bulk_assign') {
        $responderId = isset($input['responder_id']) ? (int) $input['responder_id'] : 0;
        $memberIds   = $input['member_ids'] ?? [];
        $role        = trim($input['role'] ?? 'operator');

        if (!$responderId || empty($memberIds)) {
            json_error('responder_id and member_ids[] are required');
        }

        $assigned = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($memberIds as $mid) {
            $mid = (int) $mid;
            if (!$mid) continue;

            try {
                // Check if already assigned
                $existing = db_fetch_one(
                    "SELECT `id` FROM `{$prefix}unit_personnel_assignments`
                     WHERE `responder_id` = ? AND `member_id` = ? AND `status` != 'released'",
                    [$responderId, $mid]
                );

                if ($existing) {
                    $skipped++;
                    continue;
                }

                db_query(
                    "INSERT INTO `{$prefix}unit_personnel_assignments`
                     (`responder_id`, `member_id`, `role`, `status`, `assigned_by`)
                     VALUES (?, ?, ?, 'active', ?)",
                    [$responderId, $mid, $role, $current_user_id]
                );
                $assigned++;
            } catch (Exception $e) {
                $errors[] = "Member #{$mid}: " . $e->getMessage();
            }
        }

        audit_log('personnel', 'create', 'unit_assignment', $responderId,
            "Bulk assigned {$assigned} members to unit #{$responderId}");

        json_response([
            'success'  => true,
            'assigned' => $assigned,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    // ── save_role — create/update assignment role (admin) ─────
    if ($action === 'save_role') {
        if (!is_admin()) {
            json_error('Admin access required', 403);
        }

        $roleId   = isset($input['id']) ? (int) $input['id'] : 0;
        $code     = trim($input['code'] ?? '');
        $name     = trim($input['name'] ?? '');
        $desc     = trim($input['description'] ?? '');
        $order    = isset($input['sort_order']) ? (int) $input['sort_order'] : 50;
        $active   = isset($input['active']) ? (int) $input['active'] : 1;

        if ($name === '') {
            json_error('Role name is required');
        }
        if ($code === '') {
            // Auto-generate code from name
            $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
            $code = preg_replace('/_+/', '_', trim($code, '_'));
        }

        try {
            if ($roleId) {
                db_query(
                    "UPDATE `{$prefix}unit_assignment_roles`
                     SET `code` = ?, `name` = ?, `description` = ?, `sort_order` = ?, `active` = ?
                     WHERE `id` = ?",
                    [$code, $name, $desc, $order, $active, $roleId]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}unit_assignment_roles`
                     (`code`, `name`, `description`, `sort_order`, `active`)
                     VALUES (?, ?, ?, ?, ?)",
                    [$code, $name, $desc, $order, $active]
                );
                $roleId = (int) db_insert_id();
            }
            json_response(['success' => true, 'id' => $roleId]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── delete_role — delete assignment role (admin) ──────────
    if ($action === 'delete_role') {
        if (!is_admin()) {
            json_error('Admin access required', 403);
        }

        $roleId = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$roleId) {
            json_error('Role id is required');
        }

        try {
            db_query(
                "DELETE FROM `{$prefix}unit_assignment_roles` WHERE `id` = ?",
                [$roleId]
            );
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    json_error('Unknown action: ' . $action, 400);
}

json_error('Method not allowed', 405);

/**
 * Auto-create location bindings when personnel are assigned to a unit.
 *
 * Looks up the member's comm identifiers and callsigns, matches them to
 * enabled location providers, and creates bindings on the unit with
 * source='personnel' so they're automatically removed on release.
 *
 * Priority is set to 100+ (higher number = lower priority than unit's own
 * hardware trackers which are typically 10-60).
 *
 * @param  int $responderId  The unit
 * @param  int $memberId     The member being assigned
 * @param  int $assignmentId The assignment record ID
 * @return int Number of bindings created
 */
function _autoBindPersonnelLocation(int $responderId, int $memberId, int $assignmentId): int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $created = 0;

    // Get enabled location providers
    $providers = [];
    try {
        $providerRows = db_fetch_all(
            "SELECT `id`, `code` FROM `{$prefix}location_providers` WHERE `enabled` = 1"
        );
        foreach ($providerRows as $p) {
            $providers[$p['code']] = (int) $p['id'];
        }
    } catch (Exception $e) {
        return 0;
    }

    if (empty($providers)) return 0;

    // Get member's callsign (primary identifier for APRS, Meshtastic, etc.)
    $member = null;
    try {
        $member = db_fetch_one(
            "SELECT `id`, `callsign`, `phone_cell` FROM `{$prefix}member` WHERE `id` = ?",
            [$memberId]
        );
    } catch (Exception $e) {
        return 0;
    }

    if (!$member) return 0;

    $identifiers = []; // [provider_id => unit_identifier]

    // Callsign → APRS binding
    if (!empty($member['callsign']) && isset($providers['aprs'])) {
        $identifiers[] = [
            'provider_id'     => $providers['aprs'],
            'unit_identifier' => $member['callsign'],
            'priority'        => 100,
        ];
    }

    // Callsign → Meshtastic binding
    if (!empty($member['callsign']) && isset($providers['meshtastic'])) {
        $identifiers[] = [
            'provider_id'     => $providers['meshtastic'],
            'unit_identifier' => $member['callsign'],
            'priority'        => 110,
        ];
    }

    // Member ID → OwnTracks binding (using 2-char TID convention)
    if (isset($providers['owntracks'])) {
        // Generate TID from member name initials or callsign
        $tid = '';
        if (!empty($member['callsign'])) {
            $tid = substr($member['callsign'], 0, 2);
        } else {
            try {
                $mDetail = db_fetch_one(
                    "SELECT `first_name`, `last_name` FROM `{$prefix}member` WHERE `id` = ?",
                    [$memberId]
                );
                if ($mDetail) {
                    $tid = strtoupper(substr($mDetail['first_name'] ?? '', 0, 1) . substr($mDetail['last_name'] ?? '', 0, 1));
                }
            } catch (Exception $e) {}
        }
        if ($tid !== '') {
            $identifiers[] = [
                'provider_id'     => $providers['owntracks'],
                'unit_identifier' => $tid,
                'priority'        => 120,
            ];
        }
    }

    // Internal GPS → binding using member ID
    if (isset($providers['internal'])) {
        $identifiers[] = [
            'provider_id'     => $providers['internal'],
            'unit_identifier' => 'unit-' . $responderId,
            'priority'        => 130,
        ];
    }

    // Get member's comm identifiers for additional provider matches.
    // Schema audit 2026-07-07: identifiers live in `values_json` (an array
    // per mode), not a scalar `identifier_value` column — the old query
    // threw and the catch silently skipped comm-identifier bindings for
    // every unit assignment.
    try {
        $commIds = [];
        $rows = db_fetch_all(
            "SELECT mci.`values_json`, cm.`code` AS `mode_code`
             FROM `{$prefix}member_comm_identifiers` mci
             JOIN `{$prefix}comm_modes` cm ON mci.`comm_mode_id` = cm.`id`
             WHERE mci.`member_id` = ?",
            [$memberId]
        );
        foreach ($rows as $row) {
            $vals = json_decode((string) $row['values_json'], true);
            if (!is_array($vals)) { continue; }
            foreach ($vals as $v) {
                $v = is_array($v) ? (string) reset($v) : (string) $v;
                if ($v !== '') {
                    $commIds[] = ['identifier_value' => $v, 'mode_code' => $row['mode_code']];
                }
            }
        }
        $modeToProvider = [
            'aprs'       => 'aprs',
            'meshtastic' => 'meshtastic',
            'dmr'        => 'dmr',
        ];
        foreach ($commIds as $ci) {
            $provCode = $modeToProvider[$ci['mode_code']] ?? null;
            if ($provCode && isset($providers[$provCode]) && !empty($ci['identifier_value'])) {
                $identifiers[] = [
                    'provider_id'     => $providers[$provCode],
                    'unit_identifier' => $ci['identifier_value'],
                    'priority'        => 105,
                ];
            }
        }
    } catch (Exception $e) {
        // comm tables may not exist
    }

    // Create the bindings
    foreach ($identifiers as $bind) {
        try {
            // Check if binding already exists
            $existing = db_fetch_one(
                "SELECT `id` FROM `{$prefix}unit_location_bindings`
                 WHERE `responder_id` = ? AND `provider_id` = ? AND `unit_identifier` = ?",
                [$responderId, $bind['provider_id'], $bind['unit_identifier']]
            );

            if ($existing) {
                // Re-activate existing binding
                db_query(
                    "UPDATE `{$prefix}unit_location_bindings`
                     SET `active` = 1, `source` = 'personnel', `assignment_id` = ?, `priority` = ?
                     WHERE `id` = ?",
                    [$assignmentId, $bind['priority'], (int) $existing['id']]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}unit_location_bindings`
                     (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`, `source`, `assignment_id`)
                     VALUES (?, ?, ?, ?, 1, 'personnel', ?)",
                    [$responderId, $bind['provider_id'], $bind['unit_identifier'], $bind['priority'], $assignmentId]
                );
            }
            $created++;
        } catch (Exception $e) {
            // Skip duplicates or errors
        }
    }

    return $created;
}
