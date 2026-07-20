<?php
/**
 * Phase 94 Stage 4e — External API: responders (units).
 *
 * GET    /api/external/v1/responders.php           list
 * GET    /api/external/v1/responders.php?id=N      detail
 * POST   /api/external/v1/responders.php           create
 * PATCH  /api/external/v1/responders.php?id=N      update (id may also be in body)
 * DELETE /api/external/v1/responders.php?id=N      soft-delete
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — list or detail
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    ext_api_require_scope('responders:read');
    // Read RBAC: mirror api/responders.php's screen/widget bypass set,
    // plus manage_members as a fallback for write-capable tokens.
    if (!rbac_can('screen.responders')
        && !rbac_can('widget.responders')
        && !rbac_can('responder.view')
        && !rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'screen.responders']);
    }

    // Soft-delete filter — graceful if column doesn't exist (pre-wastebasket
    // installs). Mirrors api/responders.php's column-existence check.
    $softDeleteFilter = '';
    try {
        $colCheck = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
            [$prefix . 'responder']
        );
        if ($colCheck) $softDeleteFilter = 'r.deleted_at IS NULL';
    } catch (Exception $e) { /* ignore */ }

    // Detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            $sql = "SELECT r.*, ut.name AS type_name,
                           us.status_val AS status_name,
                           us.`group` AS status_group,
                           f.name AS facility_name
                    FROM `{$prefix}responder` r
                    LEFT JOIN `{$prefix}unit_types` ut ON r.type = ut.id
                    LEFT JOIN `{$prefix}un_status` us ON r.un_status_id = us.id
                    LEFT JOIN `{$prefix}facilities` f ON r.at_facility = f.id
                    WHERE r.id = ?";
            if ($softDeleteFilter) $sql .= " AND ({$softDeleteFilter})";
            $row = db_fetch_one($sql, [$id]);
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$row) ext_api_error('not_found', 404);
        // Phase 99j-8 — token's owning user must be able to see this
        // responder under the org-scope. Same 404 as not-found.
        require_once __DIR__ . '/../../../inc/org-scope.php';
        if (!org_can_see_row('responder', $id)) ext_api_error('not_found', 404);
        audit_log('external_api', 'read', 'responder', $id,
            "External API GET responder #{$id}",
            ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null]);
        ext_api_response($row);
    }

    // List
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $search = trim((string) ($_GET['search'] ?? ''));

    $where  = [];
    $params = [];
    if ($softDeleteFilter) $where[] = $softDeleteFilter;
    if ($search !== '') {
        $where[] = '(r.name LIKE ? OR r.handle LIKE ? OR r.callsign LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // Phase 99j-8 — org-scope filter (ensures column exists for older installs).
    require_once __DIR__ . '/../../../inc/org-scope.php';
    ensure_org_id_column('responder');
    [$orgFrag, $orgVars] = org_query_filter('r.org_id');
    if ($orgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
        $params = array_merge($params, $orgVars);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $rows = db_fetch_all(
            "SELECT r.id, r.name, r.handle, r.callsign, r.description,
                    r.lat, r.lng, r.type AS type_id,
                    r.un_status_id AS status_id, r.status_about,
                    r.status_updated, r.updated,
                    ut.name AS type_name,
                    us.status_val AS status_name,
                    us.`group` AS status_group
             FROM `{$prefix}responder` r
             LEFT JOIN `{$prefix}unit_types` ut ON r.type = ut.id
             LEFT JOIN `{$prefix}un_status` us ON r.un_status_id = us.id
             {$whereSql}
             ORDER BY r.handle ASC, r.name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    audit_log('external_api', 'list', 'responder', null,
        "External API list responders (count=" . count($rows) . ")",
        ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null,
         'limit' => $limit, 'offset' => $offset, 'search' => $search]);

    ext_api_response(['responders' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — create
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    ext_api_require_scope('responders:write');
    if (!rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    require_once __DIR__ . '/../../../inc/responder-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = responder_upsert_internal($input, $userId, null);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('asset', 'create', 'responder', $result['id'],
        "External API created responder #{$result['id']}: " .
        substr((string) ($input['name'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'callsign'         => $input['callsign'] ?? null,
            'handle'           => $input['handle'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — update (id in ?id= OR body)
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    ext_api_require_scope('responders:write');
    if (!rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/responder-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = responder_upsert_internal($input, $userId, $id);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) ext_api_error('not_found', 404);
        ext_api_error('validation_failed', 422, ['errors' => $errs]);
    }

    audit_log('asset', 'update', 'responder', $result['id'],
        "External API updated responder #{$result['id']}: " .
        substr((string) ($input['name'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']]);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — soft-delete by ?id=
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    ext_api_require_scope('responders:write');
    if (!rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/responder-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = responder_soft_delete_internal($id, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) ext_api_error('not_found', 404);
        if (in_array('has_active_assignments', $errs, true)) {
            ext_api_error('has_active_assignments', 409, [
                'active_count' => $result['active_count'] ?? 0,
            ]);
        }
        ext_api_error('delete_failed', 500, ['errors' => $errs]);
    }

    audit_log('asset', 'delete', 'responder', $id,
        "External API " . (!empty($result['soft']) ? "soft-deleted" : "deleted") .
        " responder #{$id}" . (!empty($result['name']) ? " '{$result['name']}'" : ''),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'soft'             => !empty($result['soft']),
            'via_external_api' => true,
        ]
    );

    ext_api_response(['deleted' => $result['deleted'], 'soft' => $result['soft']]);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['GET', 'POST', 'PATCH', 'DELETE']]);
