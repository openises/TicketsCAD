<?php
/**
 * Phase 94 Stage 4f — External API: facilities.
 *
 * GET    /api/external/v1/facilities.php           list
 * GET    /api/external/v1/facilities.php?id=N      detail
 * POST   /api/external/v1/facilities.php           create
 * PATCH  /api/external/v1/facilities.php?id=N      update (id may also be in body)
 * DELETE /api/external/v1/facilities.php?id=N      soft-delete (sets hide=1)
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
    ext_api_require_scope('facilities:read');
    // Read RBAC: mirror api/facilities.php's screen/widget bypass set,
    // plus manage_facilities as a fallback for write-capable tokens.
    if (!rbac_can('screen.facilities')
        && !rbac_can('widget.facilities')
        && !rbac_can('facility.view')
        && !rbac_can('action.manage_facilities')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'screen.facilities']);
    }

    // Detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            $row = db_fetch_one(
                "SELECT f.*, ft.name AS type_name, ft.icon AS type_icon,
                        fs.status_val AS status_name,
                        fs.bg_color AS status_bg, fs.text_color AS status_text
                 FROM `{$prefix}facilities` f
                 LEFT JOIN `{$prefix}fac_types` ft ON f.type = ft.id
                 LEFT JOIN `{$prefix}fac_status` fs ON f.status_id = fs.id
                 WHERE f.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$row) ext_api_error('not_found', 404);
        // Phase 99j-8 — org-scope gate.
        require_once __DIR__ . '/../../../inc/org-scope.php';
        if (!org_can_see_row('facilities', $id)) ext_api_error('not_found', 404);
        audit_log('external_api', 'read', 'facility', $id,
            "External API GET facility #{$id}",
            ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null]);
        ext_api_response($row);
    }

    // List
    $limit       = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset      = max(0, (int) ($_GET['offset'] ?? 0));
    $search      = trim((string) ($_GET['search'] ?? ''));
    $includeHidden = !empty($_GET['include_hidden']) && $_GET['include_hidden'] !== '0';

    // Detect which soft-delete column this install has — modern installs
    // use deleted_at, legacy installs use hide. Both, neither, or one
    // are all valid states.
    $hasDeletedAt = false; $hasHide = false; $hideSelect = '';
    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND COLUMN_NAME IN ('deleted_at', 'hide')",
            [$prefix . 'facilities']
        );
        foreach ($cols as $c) {
            if ($c['COLUMN_NAME'] === 'deleted_at') $hasDeletedAt = true;
            if ($c['COLUMN_NAME'] === 'hide')       $hasHide = true;
        }
    } catch (Exception $e) { /* assume neither */ }

    $where  = [];
    $params = [];
    if (!$includeHidden) {
        if ($hasDeletedAt) $where[] = 'f.deleted_at IS NULL';
        if ($hasHide)      $where[] = '(f.hide IS NULL OR f.hide = 0)';
    }
    if ($search !== '') {
        $where[] = '(f.name LIKE ? OR f.handle LIKE ? OR f.callsign LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // Phase 99j-8 — org-scope filter.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    ensure_org_id_column('facilities');
    [$orgFrag, $orgVars] = org_query_filter('f.org_id');
    if ($orgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
        $params = array_merge($params, $orgVars);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    if ($hasHide) $hideSelect = ', f.hide';

    try {
        $rows = db_fetch_all(
            "SELECT f.id, f.name, f.handle, f.callsign, f.description,
                    f.street, f.city, f.state, f.lat, f.lng,
                    f.contact_phone AS phone, f.contact_email,
                    f.type AS type_id, f.status_id, f.updated {$hideSelect},
                    ft.name AS type_name, ft.icon AS type_icon,
                    fs.status_val AS status_name
             FROM `{$prefix}facilities` f
             LEFT JOIN `{$prefix}fac_types` ft ON f.type = ft.id
             LEFT JOIN `{$prefix}fac_status` fs ON f.status_id = fs.id
             {$whereSql}
             ORDER BY f.name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    audit_log('external_api', 'list', 'facility', null,
        "External API list facilities (count=" . count($rows) . ")",
        ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null,
         'limit' => $limit, 'offset' => $offset, 'search' => $search]);

    ext_api_response(['facilities' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — create
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    ext_api_require_scope('facilities:write');
    if (!rbac_can('action.manage_facilities')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_facilities']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    require_once __DIR__ . '/../../../inc/facility-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = facility_upsert_internal($input, $userId, null);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('asset', 'create', 'facility', $result['id'],
        "External API created facility #{$result['id']}: " .
        substr((string) ($input['name'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — update (id in ?id= OR body)
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    ext_api_require_scope('facilities:write');
    if (!rbac_can('action.manage_facilities')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_facilities']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/facility-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = facility_upsert_internal($input, $userId, $id);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) ext_api_error('not_found', 404);
        ext_api_error('validation_failed', 422, ['errors' => $errs]);
    }

    audit_log('asset', 'update', 'facility', $result['id'],
        "External API updated facility #{$result['id']}: " .
        substr((string) ($input['name'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']]);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — soft-delete via hide=1
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    ext_api_require_scope('facilities:write');
    if (!rbac_can('action.manage_facilities')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_facilities']);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/facility-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = facility_soft_delete_internal($id, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) ext_api_error('not_found', 404);
        ext_api_error('delete_failed', 500, ['errors' => $errs]);
    }

    audit_log('asset', 'delete', 'facility', $id,
        "External API soft-deleted facility #{$id}" .
        (!empty($result['name']) ? " '{$result['name']}'" : ''),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['deleted' => $result['deleted']]);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['GET', 'POST', 'PATCH', 'DELETE']]);
