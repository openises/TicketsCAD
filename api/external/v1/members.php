<?php
/**
 * Phase 94 Stage 4d — External API: members (personnel).
 *
 * GET    /api/external/v1/members.php           list
 * GET    /api/external/v1/members.php?id=N      detail
 * POST   /api/external/v1/members.php           create
 * DELETE /api/external/v1/members.php?id=N      soft-delete
 *
 * (PATCH lands once the internal members.php's partial-update logic
 * is factored out — currently complex enough to warrant a separate slice.)
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
    ext_api_require_scope('members:read');
    if (!rbac_can('action.view_members') && !rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.view_members']);
    }

    // Detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            $row = db_fetch_one(
                "SELECT m.*, mt.name AS type_name, ms.status_val AS status_name, t.team AS team_name
                 FROM " . db_table('member') . " m
                 LEFT JOIN " . db_table('member_types') . " mt ON mt.id = m.member_type_id
                 LEFT JOIN " . db_table('member_status') . " ms ON ms.id = m.member_status_id
                 LEFT JOIN " . db_table('teams') . " t ON t.id = m.team_id
                 WHERE m.id = ? AND (m.deleted_at IS NULL OR m.deleted_at = '0000-00-00 00:00:00')",
                [$id]
            );
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$row) ext_api_error('not_found', 404);
        // Phase 99j-8 — token must be allowed to see this member.
        require_once __DIR__ . '/../../../inc/org-scope.php';
        if (!org_can_see_member($id)) ext_api_error('not_found', 404);
        audit_log('external_api', 'read', 'member', $id, "External API GET member #{$id}",
            ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null]);
        ext_api_response($row);
    }

    // List
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $search = trim((string) ($_GET['search'] ?? ''));

    $where = ['(m.deleted_at IS NULL OR m.deleted_at = \'0000-00-00 00:00:00\')'];
    $params = [];
    if ($search !== '') {
        $where[] = '(m.first_name LIKE ? OR m.last_name LIKE ? OR m.callsign LIKE ? OR m.email LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // Phase 99j-8 — org-scope filter via the junction.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    [$memOrgFrag, $memOrgVars] = org_member_query_filter('m.id');
    if ($memOrgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $memOrgFrag) . ')';
        $params = array_merge($params, $memOrgVars);
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    try {
        $rows = db_fetch_all(
            "SELECT m.id, m.first_name, m.last_name, m.middle_name, m.callsign,
                    m.title, m.email, m.phone_cell, m.available,
                    m.member_type_id, m.member_status_id, m.team_id,
                    mt.name AS type_name, ms.status_val AS status_name,
                    t.team AS team_name
             FROM " . db_table('member') . " m
             LEFT JOIN " . db_table('member_types') . " mt ON mt.id = m.member_type_id
             LEFT JOIN " . db_table('member_status') . " ms ON ms.id = m.member_status_id
             LEFT JOIN " . db_table('teams') . " t ON t.id = m.team_id
             {$whereSql}
             ORDER BY m.last_name ASC, m.first_name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    audit_log('external_api', 'list', 'member', null,
        "External API list members (count=" . count($rows) . ")",
        ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null, 'limit' => $limit, 'offset' => $offset, 'search' => $search]);

    ext_api_response(['members' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — create
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    ext_api_require_scope('members:write');
    if (!rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
    }

    $raw = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    require_once __DIR__ . '/../../../inc/member-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = member_create_internal($input, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('personnel', 'create', 'member', $result['id'],
        "External API created member #{$result['id']}: " .
        substr((string) ($input['first_name'] ?? ''), 0, 40) . ' ' .
        substr((string) ($input['last_name'] ?? ''), 0, 40),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'callsign'         => $input['callsign'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — partial update
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    ext_api_require_scope('members:write');
    if (!rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    // Dispatcher injects id from /members/<id>; body override accepted.
    $memberId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    if ($memberId <= 0) ext_api_error('invalid_id', 400);

    // Fields may be top-level OR nested under `fields` (mirrors the
    // incidents PATCH shape).
    $fields = isset($input['fields']) && is_array($input['fields'])
        ? $input['fields']
        : array_diff_key($input, array_flip(['id', 'fields']));
    if (empty($fields)) ext_api_error('validation_failed', 422, ['errors' => ['no fields to update']]);

    // Pre-check the member exists (and isn't already soft-deleted)
    try {
        $exists = db_fetch_value(
            "SELECT id FROM " . db_table('member') . "
             WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
            [$memberId]
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!$exists) ext_api_error('not_found', 404);

    require_once __DIR__ . '/../../../inc/member-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = member_update_internal($memberId, $fields, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('personnel', 'update', 'member', $memberId,
        "External API updated member #{$memberId} (" . implode(', ', $result['fields_changed']) . ")",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'fields_changed'   => $result['fields_changed'],
            'via_external_api' => true,
        ]
    );

    ext_api_response([
        'id'             => $memberId,
        'fields_changed' => $result['fields_changed'],
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — soft-delete by ?id=N
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    ext_api_require_scope('members:write');
    if (!rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/member-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = member_soft_delete($id, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    audit_log('personnel', 'delete', 'member', $id,
        "External API soft-deleted member #{$id}",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['deleted' => $result['deleted']]);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['GET', 'POST', 'DELETE']]);
