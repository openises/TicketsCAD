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
require_once __DIR__ . '/../inc/rbac.php';   // is_admin() — privacy redaction gate

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

    // Supervisors can see everything.
    // 2026-07-29 — was `$userLevel <= 1` only, while vehicles.php gates on
    // rbac_require_screen('screen.vehicles'). After the Phase 12 RBAC
    // migration an admin whose legacy user.level is 4 got their own agency's
    // plates/VINs redacted. Phase 128 removed the level term entirely —
    // is_admin() is the whole test now.
    if (is_admin() || $isOwner) {
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
                    CONCAT(m.first_name, ' ', m.last_name) AS owner_name, m.callsign AS owner_callsign,
                    oo.name AS owner_org_name, oo.short_name AS owner_org_short_name
             FROM " . db_table('newui_vehicles') . " v
             LEFT JOIN " . db_table('newui_vehicle_types') . " vt ON v.vehicle_type_id = vt.id
             LEFT JOIN " . db_table('member') . " m ON v.member_id = m.id
             LEFT JOIN " . db_table('organizations') . " oo ON v.owner_org_id = oo.id
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
                CONCAT(m.first_name, ' ', m.last_name) AS owner_name, m.callsign AS owner_callsign,
                oo.name AS owner_org_name, oo.short_name AS owner_org_short_name
         FROM " . db_table('newui_vehicles') . " v
         LEFT JOIN " . db_table('newui_vehicle_types') . " vt ON v.vehicle_type_id = vt.id
         LEFT JOIN " . db_table('member') . " m ON v.member_id = m.id
         LEFT JOIN " . db_table('organizations') . " oo ON v.owner_org_id = oo.id
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
    // Same soft-delete gap as GH#52 (facilities) — a member who has left the
    // roster (deleted_at set) still showed up as a selectable owner here.
    // Defensive: older installs may lack deleted_at on member.
    $hasDeletedAt = false;
    try {
        $hasDeletedAt = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
            [($GLOBALS['db_prefix'] ?? '') . 'member']
        ) > 0;
    } catch (Exception $e) { $hasDeletedAt = false; }
    $memberNotDeleted = $hasDeletedAt ? ' WHERE `deleted_at` IS NULL' : '';
    // COALESCE to the legacy field1/field2/field4 columns for upgrade
    // installs where the modern columns weren't backfilled -- same latent
    // bug found and fixed in api/equipment.php's identical query 2026-08-06
    // (Chris Byrd, Google Group -- "it shows the nulls" on Equipment's
    // owner dropdown). This query has the same shape and was never
    // exercised against an upgrade install this session, so fixing it here
    // too rather than waiting for a matching report.
    $members = safe_fetch_all_v(
        "SELECT id,
                COALESCE(NULLIF(last_name, ''),  field1) AS last_name,
                COALESCE(NULLIF(first_name, ''), field2) AS first_name,
                COALESCE(NULLIF(callsign, ''),   field4) AS callsign
         FROM " . db_table('member')
        . "{$memberNotDeleted} ORDER BY last_name, first_name"
    );

    // Agencies selectable as an owner (GH: Chris Byrd, Google Group
    // 2026-08-06 — "how do I add an Agency so I can assign it as an
    // owner"). Reuses the same `organizations` table api/organizations.php
    // already manages; active only, same as that endpoint's own list.
    $organizations = safe_fetch_all_v(
        "SELECT id, name, short_name FROM " . db_table('organizations')
        . " WHERE active = 1 ORDER BY sort_order, name"
    );

    json_response([
        'vehicles'      => $result,
        'types'         => $types,
        'members'       => $members,
        'organizations' => $organizations
    ]);
}

function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // Issue #20 (public repo, security): this endpoint performed 2 INSERTs
    // and 2 DELETEs with no CSRF verification at all — a browser-default
    // (SameSite=Lax cookies) was the only thing standing between it and a
    // cross-site POST. The single client apiPost() wrapper (assets/js/
    // vehicles.js) and every config.js caller (assets/js/config.js, via
    // apiPostDirect()) already carry csrf_token as of this fix.
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

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
    //
    // Owner type — Chris Byrd, Google Group 2026-08-06: adding a specific
    // Agency as a vehicle's owner, not just a person. `member_id` and
    // `owner_org_id` are mutually exclusive owner slots; the edit form only
    // ever shows one selector at a time (assets/js/vehicles.js), but the
    // server enforces it too rather than trusting the client to never send
    // both — whichever the client actually populated wins, and the other
    // is forced null so a stale value from a prior save can't linger.
    $ownerOrgId = !empty($input['owner_org_id']) ? intval($input['owner_org_id']) : null;
    $memberId   = $ownerOrgId === null && !empty($input['member_id']) ? intval($input['member_id']) : null;

    $fields = [
        'member_id'         => $memberId,
        'owner_org_id'      => $ownerOrgId,
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
        // Derived, not trusted from the client alone: an agency owner
        // ALWAYS implies an agency vehicle, regardless of what the client
        // sent, so applyPrivacy()'s "no redaction for agency vehicles"
        // check can't be defeated by a client that forgets to set this.
        'is_agency_vehicle' => ($ownerOrgId !== null || !empty($input['is_agency_vehicle'])) ? 1 : 0,
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
            // dead_control_audit.php check (c), 2026-08-20: newui_vehicles.org_id
            // (the org-SCOPING column ensure_org_id_column()/org_query_filter()
            // above filter on — distinct from `owner_org_id`, which IS correctly
            // written above and means "which agency owns this vehicle", not
            // "which org can see this row") was never attempted on write. Same
            // fix shape as the sibling facilities/responder/teams/equipment
            // org_id restorations in the same change. Create-only.
            global $current_user_id;
            require_once __DIR__ . '/../inc/org-scope.php';
            ensure_org_id_column('newui_vehicles');
            $orgId = (isset($input['org_id']) && (int) $input['org_id'] > 0) ? (int) $input['org_id'] : null;
            if ($orgId === null) {
                try { $orgId = org_user_home_id((int) $current_user_id); } catch (Exception $e) { $orgId = null; }
            }
            $fields['org_id'] = $orgId;
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
