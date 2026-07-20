<?php
/**
 * NewUI v4.0 API - Vehicles (Fleet Management + Privacy)
 *
 * GET  /api/vehicles.php                — List all vehicles (privacy-filtered)
 * GET  /api/vehicles.php?id=X           — Get single vehicle
 * GET  /api/vehicles.php?member_id=X    — Get vehicles for a member
 * GET  /api/vehicles.php?types=1        — Get vehicle types
 * POST /api/vehicles.php                — Create or update vehicle
 * POST /api/vehicles.php action=delete  — Delete vehicle
 *
 * PRIVACY MODEL:
 *   When is_private=1, plate/VIN/insurance fields are redacted UNLESS:
 *   1. Requesting user owns the vehicle (member.user_id = session user), OR
 *   2. Requesting user is supervisor (level <= 1)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_all_v($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all_v] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

/**
 * Apply privacy redaction to a vehicle record.
 * Redacts plate, VIN, insurance when is_private=1 unless user is owner or supervisor.
 */
function applyPrivacy($vehicle) {
    global $current_user_id;
    $userLevel = (int)($_SESSION['level'] ?? 99);

    // No redaction needed for agency vehicles or non-private records
    if (!$vehicle['is_private'] || $vehicle['is_agency_vehicle']) {
        $vehicle['redacted'] = false;
        return $vehicle;
    }

    // Check if user is owner
    $isOwner = false;
    if (!empty($vehicle['member_id'])) {
        $ownerUser = safe_fetch_all_v(
            "SELECT user_id FROM " . db_table('member') . " WHERE id = ?",
            [(int)$vehicle['member_id']]
        );
        if (!empty($ownerUser) && (int)($ownerUser[0]['user_id'] ?? 0) === (int)$current_user_id) {
            $isOwner = true;
        }
    }

    // Supervisors (level 0=Super, 1=Admin) can see everything
    if ($userLevel <= 1 || $isOwner) {
        $vehicle['redacted'] = false;
        return $vehicle;
    }

    // Redact sensitive fields
    $vehicle['plate_number'] = null;
    $vehicle['plate_state'] = null;
    $vehicle['vin'] = null;
    $vehicle['insurance_carrier'] = null;
    $vehicle['insurance_policy'] = null;
    $vehicle['insurance_exp'] = null;
    $vehicle['registration_exp'] = null;
    $vehicle['redacted'] = true;

    return $vehicle;
}

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'POST') {
    handlePost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

function handleGet() {
    // Vehicle types
    if (!empty($_GET['types'])) {
        $types = safe_fetch_all_v(
            "SELECT * FROM " . db_table('newui_vehicle_types') . " WHERE active = 1 ORDER BY sort_order, name"
        );
        json_response(['types' => $types]);
    }

    // Single vehicle
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $rows = safe_fetch_all_v(
            "SELECT v.*, vt.name AS type_name, vt.icon AS type_icon,
                    CONCAT(m.first_name, ' ', m.last_name) AS owner_name, m.callsign AS owner_callsign
             FROM " . db_table('newui_vehicles') . " v
             LEFT JOIN " . db_table('newui_vehicle_types') . " vt ON v.vehicle_type_id = vt.id
             LEFT JOIN " . db_table('member') . " m ON v.member_id = m.id
             WHERE v.id = ?",
            [$id]
        );
        if (empty($rows)) json_error('Vehicle not found', 404);
        json_response(['vehicle' => applyPrivacy($rows[0])]);
    }

    // Vehicles for a specific member
    if (!empty($_GET['member_id'])) {
        $memberId = intval($_GET['member_id']);
        $rows = safe_fetch_all_v(
            "SELECT v.*, vt.name AS type_name, vt.icon AS type_icon
             FROM " . db_table('newui_vehicles') . " v
             LEFT JOIN " . db_table('newui_vehicle_types') . " vt ON v.vehicle_type_id = vt.id
             WHERE v.member_id = ?
             ORDER BY v.is_agency_vehicle DESC, v.year DESC",
            [$memberId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = applyPrivacy($row);
        }
        json_response(['vehicles' => $result]);
    }

    // List all vehicles
    $where = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[] = "v.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['type_id'])) {
        $where[] = "v.vehicle_type_id = ?";
        $params[] = intval($_GET['type_id']);
    }
    if (isset($_GET['agency']) && $_GET['agency'] !== '') {
        $where[] = "v.is_agency_vehicle = ?";
        $params[] = intval($_GET['agency']);
    }
    if (!empty($_GET['search'])) {
        $term = '%' . trim($_GET['search']) . '%';
        $where[] = "(v.make LIKE ? OR v.model LIKE ? OR v.callsign LIKE ?
                     OR v.color LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
    }

    // Phase 99j-6 — org-scope filter.
    require_once __DIR__ . '/../inc/org-scope.php';
    ensure_org_id_column('newui_vehicles');
    [$orgFrag, $orgVars] = org_query_filter('v.org_id');
    if ($orgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
        $params = array_merge($params, $orgVars);
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = safe_fetch_all_v(
        "SELECT v.*, vt.name AS type_name, vt.icon AS type_icon,
                CONCAT(m.first_name, ' ', m.last_name) AS owner_name, m.callsign AS owner_callsign
         FROM " . db_table('newui_vehicles') . " v
         LEFT JOIN " . db_table('newui_vehicle_types') . " vt ON v.vehicle_type_id = vt.id
         LEFT JOIN " . db_table('member') . " m ON v.member_id = m.id
         {$whereSQL}
         ORDER BY v.is_agency_vehicle DESC, v.status ASC, m.last_name, m.first_name
         LIMIT 500",
        $params
    );

    $result = [];
    foreach ($rows as $row) {
        $result[] = applyPrivacy($row);
    }

    // Also return types and members for forms
    $types = safe_fetch_all_v(
        "SELECT * FROM " . db_table('newui_vehicle_types') . " WHERE active = 1 ORDER BY sort_order"
    );
    $members = safe_fetch_all_v(
        "SELECT id, first_name, last_name, callsign FROM " . db_table('member') . " ORDER BY last_name, first_name"
    );

    json_response([
        'vehicles' => $result,
        'types'    => $types,
        'members'  => $members
    ]);
}

function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // Delete vehicle
    if (($input['action'] ?? '') === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        // Phase 99j-6b — org-scope gate.
        require_once __DIR__ . '/../inc/org-scope.php';
        if (!org_can_see_row('newui_vehicles', $id)) {
            json_error('Vehicle not found', 404);
        }
        try {
            $v = safe_fetch_all_v("SELECT make, model, callsign FROM " . db_table('newui_vehicles') . " WHERE id = ?", [$id]);
            $vDesc = !empty($v) ? trim($v[0]['make'] . ' ' . $v[0]['model']) : "#{$id}";
            db_query("DELETE FROM " . db_table('newui_vehicles') . " WHERE id = ?", [$id]);
            audit_log('asset', 'delete', 'vehicle', $id, "Deleted vehicle '{$vDesc}'");
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Save vehicle type (settings panel)
    if (($input['action'] ?? '') === 'save_type') {
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Type name is required');
        $id = intval($input['id'] ?? 0);
        $desc = trim($input['description'] ?? '');
        $icon = trim($input['icon'] ?? 'bi-truck');
        $order = intval($input['sort_order'] ?? 0);

        try {
            if ($id > 0) {
                db_query(
                    "UPDATE " . db_table('newui_vehicle_types') . " SET `name` = ?, `description` = ?, `icon` = ?, `sort_order` = ? WHERE id = ?",
                    [$name, $desc, $icon, $order, $id]
                );
                audit_log('config', 'update', 'vehicle_type', $id, "Updated vehicle type '{$name}'");
            } else {
                db_query(
                    "INSERT INTO " . db_table('newui_vehicle_types') . " (`name`, `description`, `icon`, `sort_order`) VALUES (?, ?, ?, ?)",
                    [$name, $desc, $icon, $order]
                );
                $id = db_insert_id();
                audit_log('config', 'create', 'vehicle_type', $id, "Created vehicle type '{$name}'");
            }
        } catch (Exception $e) {
            json_error('Failed to save type: ' . $e->getMessage());
        }
        json_response(['success' => true, 'id' => $id]);
    }

    // Delete vehicle type
    if (($input['action'] ?? '') === 'delete_type') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $vt = safe_fetch_all_v("SELECT name FROM " . db_table('newui_vehicle_types') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('newui_vehicle_types') . " WHERE id = ?", [$id]);
            audit_log('config', 'delete', 'vehicle_type', $id, "Deleted vehicle type '" . (!empty($vt) ? $vt[0]['name'] : "#{$id}") . "'");
        } catch (Exception $e) {
            json_error('Failed to delete type: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Create or Update vehicle
    $fields = [
        'member_id'         => !empty($input['member_id']) ? intval($input['member_id']) : null,
        'vehicle_type_id'   => !empty($input['vehicle_type_id']) ? intval($input['vehicle_type_id']) : null,
        'callsign'          => trim($input['callsign'] ?? ''),
        'year'              => !empty($input['year']) ? intval($input['year']) : null,
        'make'              => trim($input['make'] ?? ''),
        'model'             => trim($input['model'] ?? ''),
        'color'             => trim($input['color'] ?? ''),
        'plate_number'      => trim($input['plate_number'] ?? '') ?: null,
        'plate_state'       => trim($input['plate_state'] ?? '') ?: null,
        'vin'               => trim($input['vin'] ?? '') ?: null,
        'registration_exp'  => !empty($input['registration_exp']) ? $input['registration_exp'] : null,
        'insurance_carrier' => trim($input['insurance_carrier'] ?? '') ?: null,
        'insurance_policy'  => trim($input['insurance_policy'] ?? '') ?: null,
        'insurance_exp'     => !empty($input['insurance_exp']) ? $input['insurance_exp'] : null,
        'is_agency_vehicle' => !empty($input['is_agency_vehicle']) ? 1 : 0,
        'is_private'        => isset($input['is_private']) ? (int)$input['is_private'] : 1,
        'status'            => $input['status'] ?? 'Active',
        'notes'             => trim($input['notes'] ?? ''),
        'updated_at'        => date('Y-m-d H:i:s')
    ];

    $id = intval($input['id'] ?? 0);

    $vLabel = trim($fields['make'] . ' ' . $fields['model']);

    try {
        if ($id > 0) {
            $setParts = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setParts[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $id;
            db_query(
                "UPDATE " . db_table('newui_vehicles') . " SET " . implode(', ', $setParts) . " WHERE id = ?",
                $params
            );
            audit_log('asset', 'update', 'vehicle', $id, "Updated vehicle '{$vLabel}'", [
                'callsign' => $fields['callsign'] ?: null
            ]);
        } else {
            $fields['created_at'] = date('Y-m-d H:i:s');
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            db_query(
                "INSERT INTO " . db_table('newui_vehicles') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                array_values($fields)
            );
            $id = db_insert_id();
            audit_log('asset', 'create', 'vehicle', $id, "Created vehicle '{$vLabel}'", [
                'callsign' => $fields['callsign'] ?: null,
                'member_id' => $fields['member_id']
            ]);
        }
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage());
    }

    json_response(['success' => true, 'id' => $id]);
}
