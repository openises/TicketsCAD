<?php
/**
 * NewUI v4.0 API - Equipment Management
 *
 * GET  /api/equipment.php                    — List all equipment with type/member joins
 * GET  /api/equipment.php?id=X               — Get single item with activity log
 * GET  /api/equipment.php?member_id=X        — Get equipment assigned to a member
 * GET  /api/equipment.php?owner_member_id=X  — Get personal equipment owned by a member
 * GET  /api/equipment.php?team_id=X          — Get equipment assigned to a team
 * GET  /api/equipment.php?types=1            — Get equipment types
 * POST /api/equipment.php                    — Create or update equipment item
 * POST /api/equipment.php action=delete           — Delete item
 * POST /api/equipment.php action=checkout         — Check out to member
 * POST /api/equipment.php action=checkin          — Check in equipment
 * POST /api/equipment.php action=save_type        — Save equipment type
 * POST /api/equipment.php action=delete_type      — Delete equipment type
 *
 * OWNERSHIP MODEL:
 *   ownership='organization' — Agency/org-owned, tracked with asset tags, checkout/checkin
 *   ownership='personal'     — Volunteer-owned, listed for availability & lost+found
 *   available_for_events=1   — Owner offers this for org use during events
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_all_eq($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all_eq] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

/**
 * Ensure the 'size' column exists on newui_equipment.
 * Called lazily on first save that includes a size value.
 */
function ensureSizeColumn() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'newui_equipment'
             AND COLUMN_NAME = 'size'"
        );
        if (!$exists) {
            db_query("ALTER TABLE " . db_table('newui_equipment') .
                " ADD COLUMN `size` varchar(8) DEFAULT NULL COMMENT 'Clothing size' AFTER `model`");
        }
    } catch (Exception $e) {
        // Non-fatal — size column just won't be saved
    }
}

/**
 * Ensure the Clothing/Uniform equipment type exists.
 */
function ensureClothingType() {
    try {
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('newui_equipment_types') . " WHERE `name` = 'Clothing/Uniform'"
        );
        if (!$exists) {
            db_query(
                "INSERT INTO " . db_table('newui_equipment_types') .
                " (`name`, `description`, `icon`, `requires_checkout`, `sort_order`) VALUES (?, ?, ?, ?, ?)",
                ['Clothing/Uniform', 'Uniforms, vests, jackets, boots', 'bi-person-badge', 1, 10]
            );
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

/**
 * GH#38 -- whether newui_equipment_log has been migrated to soft-delete
 * (sql/run_equipment_log_soft_delete.php). On an install that hasn't run
 * migrations yet, `deleted_at IS NULL` would 42S22 and safe_fetch_all_eq's
 * try/catch would silently return an EMPTY log -- worse than not filtering
 * at all. Mirrors inc/ics-forms-write.php's ics_forms_has_soft_delete().
 */
function equipmentLogHasSoftDelete() {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND COLUMN_NAME IN ('deleted_at', 'deleted_by')",
            [($GLOBALS['db_prefix'] ?? '') . 'newui_equipment_log']
        );
        return $cached = ($n === 2);
    } catch (Throwable $e) {
        return $cached = false;
    }
}

// Ensure Clothing/Uniform type + size column exist (lazy migration)
ensureClothingType();
ensureSizeColumn();

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'POST') {
    handlePost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

function handleGet() {
    // Equipment types
    if (!empty($_GET['types'])) {
        $types = safe_fetch_all_eq(
            "SELECT * FROM " . db_table('newui_equipment_types') . " WHERE active = 1 ORDER BY sort_order, name"
        );
        json_response(['types' => $types]);
    }

    // Single equipment item with log
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $rows = safe_fetch_all_eq(
            "SELECT e.*, et.name AS type_name, et.icon AS type_icon, et.requires_checkout,
                    CONCAT(m.first_name, ' ', m.last_name) AS assigned_member_name,
                    m.callsign AS assigned_member_callsign,
                    t.`team` AS assigned_team_name,
                    CONCAT(om.first_name, ' ', om.last_name) AS owner_name,
                    om.callsign AS owner_callsign
             FROM " . db_table('newui_equipment') . " e
             LEFT JOIN " . db_table('newui_equipment_types') . " et ON e.equipment_type_id = et.id
             LEFT JOIN " . db_table('member') . " m ON e.assigned_member_id = m.id
             LEFT JOIN " . db_table('teams') . " t ON e.assigned_team_id = t.id
             LEFT JOIN " . db_table('member') . " om ON e.owner_member_id = om.id
             WHERE e.id = ?",
            [$id]
        );
        if (empty($rows)) json_error('Equipment not found', 404);

        // Activity log
        //
        // GH#34 (Chris Byrd, 2026-08-07): "By:" showed the wrong person,
        // "off by 2 based on position in roster". Root cause: performed_by
        // is written as $current_user_id (api/auth.php: the LOGIN account's
        // `user`.id), but this query joined it against `member`.id -- a
        // completely different id sequence (member records aren't created
        // 1:1 with login accounts, and `user`.`member` links them, often
        // NULL). Whichever member happened to share that numeric id showed
        // up instead of the actual logged-in user -- verified directly: on
        // this dev box, user.id=3 (the real admin, linked member.id=13,
        // "ERIC OSTERBERG") joined against member.id=3 and showed "Sarah
        // Chen" instead. member_id (who equipment is checked out TO) was
        // never affected -- that column is genuinely a member.id throughout
        // the checkout/checkin write path, so only the "By:" line was wrong.
        $logHasSoftDelete = equipmentLogHasSoftDelete();
        $logNotDeleted = $logHasSoftDelete ? ' AND el.deleted_at IS NULL' : '';
        $log = safe_fetch_all_eq(
            "SELECT el.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name,
                    COALESCE(NULLIF(TRIM(CONCAT(u.name_f, ' ', u.name_l)), ''), u.`user`) AS performed_by_name
             FROM " . db_table('newui_equipment_log') . " el
             LEFT JOIN " . db_table('member') . " m ON el.member_id = m.id
             LEFT JOIN " . db_table('user') . " u ON el.performed_by = u.id
             WHERE el.equipment_id = ?{$logNotDeleted}
             ORDER BY el.created_at DESC
             LIMIT 100",
            [$id]
        );

        // GH#38 (Chris Byrd, 2026-08-07) -- admin-only delete of activity log
        // entries, "like the delete function on the ICS Forms." Unlike ICS
        // forms there's no creator/ownership exception -- see
        // sql/run_equipment_log_soft_delete.php's docblock for why. Gated on
        // the migration having run too, or the button would appear and then
        // 400 on click.
        $canDeleteLog = $logHasSoftDelete && (is_admin() || rbac_can('action.delete_equipment_log'));
        foreach ($log as $i => $row) {
            $log[$i]['can_delete'] = $canDeleteLog;
        }

        json_response(['equipment' => $rows[0], 'log' => $log]);
    }

    // Equipment assigned to a specific member (checked out to them)
    if (!empty($_GET['member_id'])) {
        $memberId = intval($_GET['member_id']);
        $rows = safe_fetch_all_eq(
            "SELECT e.*, et.name AS type_name, et.icon AS type_icon
             FROM " . db_table('newui_equipment') . " e
             LEFT JOIN " . db_table('newui_equipment_types') . " et ON e.equipment_type_id = et.id
             WHERE e.assigned_member_id = ?
             ORDER BY et.sort_order, e.name",
            [$memberId]
        );
        json_response(['equipment' => $rows]);
    }

    // Personal equipment owned by a member
    if (!empty($_GET['owner_member_id'])) {
        $ownerId = intval($_GET['owner_member_id']);
        $rows = safe_fetch_all_eq(
            "SELECT e.*, et.name AS type_name, et.icon AS type_icon
             FROM " . db_table('newui_equipment') . " e
             LEFT JOIN " . db_table('newui_equipment_types') . " et ON e.equipment_type_id = et.id
             WHERE e.owner_member_id = ? AND e.ownership = 'personal'
             ORDER BY et.sort_order, e.name",
            [$ownerId]
        );
        json_response(['equipment' => $rows]);
    }

    // Equipment for a specific team
    if (!empty($_GET['team_id'])) {
        $teamId = intval($_GET['team_id']);
        $rows = safe_fetch_all_eq(
            "SELECT e.*, et.name AS type_name, et.icon AS type_icon
             FROM " . db_table('newui_equipment') . " e
             LEFT JOIN " . db_table('newui_equipment_types') . " et ON e.equipment_type_id = et.id
             WHERE e.assigned_team_id = ?
             ORDER BY et.sort_order, e.name",
            [$teamId]
        );
        json_response(['equipment' => $rows]);
    }

    // List all equipment
    $where = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[] = "e.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['type_id'])) {
        $where[] = "e.equipment_type_id = ?";
        $params[] = intval($_GET['type_id']);
    }
    if (!empty($_GET['condition'])) {
        $where[] = "e.`condition` = ?";
        $params[] = $_GET['condition'];
    }
    if (!empty($_GET['ownership'])) {
        $where[] = "e.ownership = ?";
        $params[] = $_GET['ownership'];
    }
    if (isset($_GET['available_for_events']) && $_GET['available_for_events'] !== '') {
        $where[] = "e.available_for_events = ?";
        $params[] = intval($_GET['available_for_events']);
    }
    if (!empty($_GET['search'])) {
        $term = '%' . trim($_GET['search']) . '%';
        $where[] = "(e.name LIKE ? OR e.serial_number LIKE ? OR e.asset_tag LIKE ?
                     OR e.make LIKE ? OR e.model LIKE ? OR e.location LIKE ?)";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
    }

    // Phase 99j-6 — org-scope filter on equipment.
    require_once __DIR__ . '/../inc/org-scope.php';
    ensure_org_id_column('newui_equipment');
    [$orgFrag, $orgVars] = org_query_filter('e.org_id');
    if ($orgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
        $params = array_merge($params, $orgVars);
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = safe_fetch_all_eq(
        "SELECT e.*, et.name AS type_name, et.icon AS type_icon,
                CONCAT(m.first_name, ' ', m.last_name) AS assigned_member_name,
                m.callsign AS assigned_member_callsign,
                t.`team` AS assigned_team_name,
                CONCAT(om.first_name, ' ', om.last_name) AS owner_name,
                om.callsign AS owner_callsign
         FROM " . db_table('newui_equipment') . " e
         LEFT JOIN " . db_table('newui_equipment_types') . " et ON e.equipment_type_id = et.id
         LEFT JOIN " . db_table('member') . " m ON e.assigned_member_id = m.id
         LEFT JOIN " . db_table('teams') . " t ON e.assigned_team_id = t.id
         LEFT JOIN " . db_table('member') . " om ON e.owner_member_id = om.id
         {$whereSQL}
         ORDER BY e.ownership ASC, et.sort_order, e.name
         LIMIT 500",
        $params
    );

    // Also return types and members for forms
    $types = safe_fetch_all_eq(
        "SELECT * FROM " . db_table('newui_equipment_types') . " WHERE active = 1 ORDER BY sort_order"
    );
    // Phase 99j-5 — org-scope the members picker (equipment-assignment dropdown).
    require_once __DIR__ . '/../inc/org-scope.php';
    [$memOrgFrag, $memOrgVars] = org_member_query_filter('m.id');
    // COALESCE to the legacy field1/field2/field4 columns for upgrade
    // installs where the modern columns weren't backfilled -- same pattern
    // as api/teams.php's get_member_display_list(). Without it, an upgrade
    // install's member dropdown renders "null, null" for every member whose
    // name still lives in the legacy fields. GH: Chris Byrd, Google Group
    // 2026-08-06 ("Also under ownership>Personal>Owner it shows the nulls").
    $members = safe_fetch_all_eq(
        "SELECT m.id,
                COALESCE(NULLIF(m.last_name, ''),  m.field1) AS last_name,
                COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                COALESCE(NULLIF(m.callsign, ''),   m.field4) AS callsign
         FROM " . db_table('member') . " m
         WHERE 1=1 {$memOrgFrag} ORDER BY last_name, first_name",
        $memOrgVars
    );
    $teams = safe_fetch_all_eq(
        // Canonical column is `team` (Phase 123); aliased to `name` so the JS
        // contract for this endpoint is unchanged.
        "SELECT id, `team` AS name FROM " . db_table('teams') . " WHERE active = 1 ORDER BY `team`"
    );

    json_response([
        'equipment' => $rows,
        'types'     => $types,
        'members'   => $members,
        'teams'     => $teams
    ]);
}

function handlePost() {
    global $current_user_id;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // RBAC + CSRF enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_members; reads (GET) stay open to viewers.
    if (!rbac_can('action.manage_members')) {
        json_error('Insufficient permissions: manage equipment', 403);
    }
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // Delete equipment
    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        // Phase 99j-6b — org-scope gate.
        require_once __DIR__ . '/../inc/org-scope.php';
        if (!org_can_see_row('newui_equipment', $id)) {
            json_error('Equipment not found', 404);
        }
        try {
            $eq = safe_fetch_all_eq("SELECT name FROM " . db_table('newui_equipment') . " WHERE id = ?", [$id]);
            $eqName = !empty($eq) ? $eq[0]['name'] : "#{$id}";
            db_query("DELETE FROM " . db_table('newui_equipment_log') . " WHERE equipment_id = ?", [$id]);
            db_query("DELETE FROM " . db_table('newui_equipment') . " WHERE id = ?", [$id]);
            audit_log('asset', 'delete', 'equipment', $id, "Deleted equipment '{$eqName}'");
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // GH#38 (Chris Byrd, 2026-08-07): "Would like to be able to delete the
    // Activity Log entries for equipment checked in and out... Be able to
    // delete like the delete function on the ICS Forms." Admin-only, no
    // ownership exception (Eric: "admin-only is the right default for
    // something that removes an audit trail entry") -- unlike ICS forms
    // there is no creator-may-delete-their-own-draft carve-out here.
    // Soft delete only (deleted_at/deleted_by), restorable from Settings ->
    // Wastebasket like every other soft-deletable type in this app.
    if ($action === 'delete_log_entry') {
        if (!is_admin() && !rbac_can('action.delete_equipment_log')) {
            json_error('Forbidden — requires equipment log delete permission', 403);
        }
        if (!equipmentLogHasSoftDelete()) {
            json_error('This install has not run the equipment log migration yet '
                . '(php sql/run_equipment_log_soft_delete.php)', 400);
        }
        $logId = intval($input['log_id'] ?? $input['id'] ?? 0);
        if (!$logId) json_error('Missing log_id');

        $row = safe_fetch_all_eq(
            "SELECT id, equipment_id FROM " . db_table('newui_equipment_log') . "
             WHERE id = ? AND deleted_at IS NULL",
            [$logId]
        );
        if (empty($row)) json_error('Log entry not found', 404);

        try {
            db_query(
                "UPDATE " . db_table('newui_equipment_log') . "
                 SET `deleted_at` = NOW(), `deleted_by` = ?
                 WHERE id = ?",
                [$current_user_id, $logId]
            );
            audit_log('asset', 'delete', 'equipment_log', $logId,
                "Deleted equipment activity log entry #{$logId}",
                ['equipment_id' => $row[0]['equipment_id']]
            );
        } catch (Exception $e) {
            json_error('Failed to delete log entry: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Check out equipment to a member
    if ($action === 'checkout') {
        $id = intval($input['id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        if (!$id || !$memberId) json_error('Missing id or member_id');

        try {
            db_query(
                "UPDATE " . db_table('newui_equipment') . "
                 SET assigned_member_id = ?, assigned_team_id = NULL,
                     status = 'Checked Out', updated_at = NOW()
                 WHERE id = ?",
                [$memberId, $id]
            );
            db_query(
                "INSERT INTO " . db_table('newui_equipment_log') . "
                 (equipment_id, `action`, member_id, performed_by, notes, created_at)
                 VALUES (?, 'checkout', ?, ?, ?, NOW())",
                [$id, $memberId, $current_user_id, trim($input['notes'] ?? '')]
            );
            audit_log('asset', 'assign', 'equipment', $id, "Checked out equipment #{$id} to member #{$memberId}", [
                'member_id' => $memberId
            ]);
        } catch (Exception $e) {
            json_error('Failed to checkout: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Check in equipment
    if ($action === 'checkin') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');

        // Get current assignment for log
        $current = safe_fetch_all_eq(
            "SELECT assigned_member_id FROM " . db_table('newui_equipment') . " WHERE id = ?",
            [$id]
        );
        $prevMember = !empty($current) ? $current[0]['assigned_member_id'] : null;

        $newCondition = !empty($input['condition']) ? $input['condition'] : null;
        $conditionSQL = $newCondition ? ", `condition` = ?" : "";
        $params = $newCondition
            ? [$newCondition, $id]
            : [$id];

        try {
            db_query(
                "UPDATE " . db_table('newui_equipment') . "
                 SET assigned_member_id = NULL, assigned_team_id = NULL,
                     status = 'Available'{$conditionSQL}, updated_at = NOW()
                 WHERE id = ?",
                $params
            );
            db_query(
                "INSERT INTO " . db_table('newui_equipment_log') . "
                 (equipment_id, `action`, member_id, performed_by, notes, created_at)
                 VALUES (?, 'checkin', ?, ?, ?, NOW())",
                [$id, $prevMember, $current_user_id, trim($input['notes'] ?? '')]
            );
            audit_log('asset', 'unassign', 'equipment', $id, "Checked in equipment #{$id}", [
                'previous_member_id' => $prevMember
            ]);
        } catch (Exception $e) {
            json_error('Failed to checkin: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Save equipment type (settings panel)
    if ($action === 'save_type') {
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Type name is required');
        $id = intval($input['id'] ?? 0);
        $desc = trim($input['description'] ?? '');
        $icon = trim($input['icon'] ?? 'bi-box');
        $checkout = !empty($input['requires_checkout']) ? 1 : 0;
        $order = intval($input['sort_order'] ?? 0);

        try {
            if ($id > 0) {
                db_query(
                    "UPDATE " . db_table('newui_equipment_types') . "
                     SET `name` = ?, `description` = ?, `icon` = ?, `requires_checkout` = ?, `sort_order` = ?
                     WHERE id = ?",
                    [$name, $desc, $icon, $checkout, $order, $id]
                );
                audit_log('config', 'update', 'equipment_type', $id, "Updated equipment type '{$name}'");
            } else {
                db_query(
                    "INSERT INTO " . db_table('newui_equipment_types') . "
                     (`name`, `description`, `icon`, `requires_checkout`, `sort_order`)
                     VALUES (?, ?, ?, ?, ?)",
                    [$name, $desc, $icon, $checkout, $order]
                );
                $id = db_insert_id();
                audit_log('config', 'create', 'equipment_type', $id, "Created equipment type '{$name}'");
            }
        } catch (Exception $e) {
            json_error('Failed to save type: ' . $e->getMessage());
        }
        json_response(['success' => true, 'id' => $id]);
    }

    // Delete equipment type
    if ($action === 'delete_type') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $et = safe_fetch_all_eq("SELECT name FROM " . db_table('newui_equipment_types') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('newui_equipment_types') . " WHERE id = ?", [$id]);
            audit_log('config', 'delete', 'equipment_type', $id, "Deleted equipment type '" . (!empty($et) ? $et[0]['name'] : "#{$id}") . "'");
        } catch (Exception $e) {
            json_error('Failed to delete type: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Create or Update equipment item
    $name = trim($input['name'] ?? '');
    if (!$name) json_error('Equipment name is required');

    $fields = [
        'equipment_type_id'  => !empty($input['equipment_type_id']) ? intval($input['equipment_type_id']) : null,
        'ownership'          => ($input['ownership'] ?? 'organization') === 'personal' ? 'personal' : 'organization',
        'owner_member_id'    => !empty($input['owner_member_id']) ? intval($input['owner_member_id']) : null,
        'available_for_events' => !empty($input['available_for_events']) ? 1 : 0,
        'name'               => $name,
        'serial_number'      => trim($input['serial_number'] ?? '') ?: null,
        'asset_tag'          => trim($input['asset_tag'] ?? '') ?: null,
        'make'               => trim($input['make'] ?? '') ?: null,
        'model'              => trim($input['model'] ?? '') ?: null,
        'size'               => trim($input['size'] ?? '') ?: null,
        'purchase_date'      => !empty($input['purchase_date']) ? $input['purchase_date'] : null,
        'purchase_cost'      => !empty($input['purchase_cost']) ? floatval($input['purchase_cost']) : null,
        'warranty_exp'       => !empty($input['warranty_exp']) ? $input['warranty_exp'] : null,
        'condition'          => $input['condition'] ?? 'Good',
        'assigned_member_id' => !empty($input['assigned_member_id']) ? intval($input['assigned_member_id']) : null,
        'assigned_team_id'   => !empty($input['assigned_team_id']) ? intval($input['assigned_team_id']) : null,
        'location'           => trim($input['location'] ?? '') ?: null,
        'notes'              => trim($input['notes'] ?? ''),
        'status'             => $input['status'] ?? 'Available',
        'updated_at'         => date('Y-m-d H:i:s')
    ];

    $id = intval($input['id'] ?? 0);

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
                "UPDATE " . db_table('newui_equipment') . " SET " . implode(', ', $setParts) . " WHERE id = ?",
                $params
            );
            audit_log('asset', 'update', 'equipment', $id, "Updated equipment '{$name}'");
        } else {
            $fields['created_at'] = date('Y-m-d H:i:s');
            // dead_control_audit.php check (c), 2026-08-20: newui_equipment.org_id
            // is filtered on every read (ensure_org_id_column()/org_query_filter()
            // above) but was never attempted on write — same gap, same fix shape,
            // as facility_upsert_internal()/responder_upsert_internal()/
            // team_upsert_internal()'s org_id restoration in the same change.
            // Create-only (matching that same convention — org assignment is a
            // create-time decision, never touched by a general edit).
            require_once __DIR__ . '/../inc/org-scope.php';
            ensure_org_id_column('newui_equipment');
            $orgId = (isset($input['org_id']) && (int) $input['org_id'] > 0) ? (int) $input['org_id'] : null;
            if ($orgId === null) {
                try { $orgId = org_user_home_id((int) $current_user_id); } catch (Exception $e) { $orgId = null; }
            }
            $fields['org_id'] = $orgId;
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            db_query(
                "INSERT INTO " . db_table('newui_equipment') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                array_values($fields)
            );
            $id = db_insert_id();
            audit_log('asset', 'create', 'equipment', $id, "Created equipment '{$name}'", [
                'ownership' => $fields['ownership']
            ]);
        }
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage());
    }

    json_response(['success' => true, 'id' => $id]);
}
