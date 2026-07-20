<?php
/**
 * Phase 94 Stage 4g — External API: teams.
 *
 * GET    /api/external/v1/teams.php           list
 * GET    /api/external/v1/teams.php?id=N      detail (team + members)
 * POST   /api/external/v1/teams.php           create
 * PATCH  /api/external/v1/teams.php?id=N      update
 * DELETE /api/external/v1/teams.php?id=N      hard delete (no soft-delete column on teams)
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';

$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — list or detail
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    ext_api_require_scope('teams:read');
    // Read RBAC: mirror the screen.teams perm + fallback to write perm.
    if (!rbac_can('screen.teams') && !rbac_can('action.manage_teams')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'screen.teams']);
    }

    // Detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            $team = db_fetch_one(
                "SELECT t.id, t.`team` AS name, t.mission AS description,
                        t.ttypes_id AS team_type_id, t.leader AS leader_id,
                        t.leader_dpty AS deputy_id, t.formed,
                        t.nims_resource_type, t.nims_typing_level, t.rtlt_code,
                        tt.type AS type_name
                 FROM " . db_table('teams') . " t
                 LEFT JOIN " . db_table('team_types') . " tt ON t.ttypes_id = tt.id
                 WHERE t.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$team) ext_api_error('not_found', 404);

        $members = [];
        try {
            $members = db_fetch_all(
                "SELECT tm.id AS assignment_id, tm.member_id, tm.role,
                        tm.position_code, tm.assigned_date,
                        COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                        COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                        COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign
                 FROM " . db_table('team_members') . " tm
                 JOIN " . db_table('member') . " m ON tm.member_id = m.id
                 WHERE tm.team_id = ?
                 ORDER BY FIELD(tm.role, 'Leader', 'Deputy', 'Member', 'Observer'), last_name",
                [$id]
            );
        } catch (Exception $e) { /* members table may differ */ }

        audit_log('external_api', 'read', 'team', $id,
            "External API GET team #{$id}",
            ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null]);

        ext_api_response(['team' => $team, 'members' => $members]);
    }

    // List
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $search = trim((string) ($_GET['search'] ?? ''));

    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(t.`team` LIKE ? OR t.mission LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.`team` AS name, t.mission AS description,
                    t.ttypes_id AS team_type_id, t.leader AS leader_id,
                    t.leader_dpty AS deputy_id, t.formed,
                    t.nims_resource_type, t.nims_typing_level, t.rtlt_code,
                    tt.type AS type_name,
                    (SELECT COUNT(*) FROM " . db_table('team_members') . " tm
                     WHERE tm.team_id = t.id) AS member_count
             FROM " . db_table('teams') . " t
             LEFT JOIN " . db_table('team_types') . " tt ON t.ttypes_id = tt.id
             {$whereSql}
             ORDER BY t.`team` ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    audit_log('external_api', 'list', 'team', null,
        "External API list teams (count=" . count($rows) . ")",
        ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null,
         'limit' => $limit, 'offset' => $offset, 'search' => $search]);

    ext_api_response(['teams' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — create
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    ext_api_require_scope('teams:write');
    if (!rbac_can('action.manage_teams')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_teams']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    require_once __DIR__ . '/../../../inc/team-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = team_upsert_internal($input, $userId, null);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('asset', 'create', 'team', $result['id'],
        "External API created team #{$result['id']}: " .
        substr((string) ($input['name'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — update
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    ext_api_require_scope('teams:write');
    if (!rbac_can('action.manage_teams')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_teams']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/team-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = team_upsert_internal($input, $userId, $id);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) ext_api_error('not_found', 404);
        ext_api_error('validation_failed', 422, ['errors' => $errs]);
    }

    audit_log('asset', 'update', 'team', $result['id'],
        "External API updated team #{$result['id']}: " .
        substr((string) ($input['name'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id']]);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — hard delete (no soft-delete column on teams)
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    ext_api_require_scope('teams:write');
    if (!rbac_can('action.manage_teams')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_teams']);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    require_once __DIR__ . '/../../../inc/team-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = team_soft_delete_internal($id, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) ext_api_error('not_found', 404);
        ext_api_error('delete_failed', 500, ['errors' => $errs]);
    }

    audit_log('asset', 'delete', 'team', $id,
        "External API deleted team #{$id}" .
        (!empty($result['name']) ? " '{$result['name']}'" : ''),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['deleted' => $result['deleted']]);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['GET', 'POST', 'PATCH', 'DELETE']]);
