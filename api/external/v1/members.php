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

/**
 * GH#76 Phase 144 (2026-08-18) — external API team_id compatibility shim.
 *
 * Internal code no longer writes member.team_id (inc/member-write.php's
 * whitelist dropped it) — team assignment moved to team_members
 * exclusively. This is the ONE path that still accepts team_id as an
 * input field, to keep the external contract stable for third-party
 * integrators rather than force a version bump. It does NOT write
 * member.team_id either; it upserts a team_members row tagged
 * source='external_api' instead, additively (never removes any OTHER
 * team_members row the member has, regardless of source).
 *
 * Uses the existing UNIQUE(team_id, member_id) key on team_members for
 * idempotency — INSERT ... ON DUPLICATE KEY UPDATE, same pattern as
 * api/teams.php's add_member action.
 */
function ext_api_sync_team_id_shim(int $memberId, $teamIdInput): void {
    $teamId = (int) $teamIdInput;
    if ($teamId <= 0) return;
    try {
        db_query(
            "INSERT INTO " . db_table('team_members') . "
             (team_id, member_id, role, assigned_date, source)
             VALUES (?, ?, 'Member', CURDATE(), 'external_api')
             ON DUPLICATE KEY UPDATE source = 'external_api'",
            [$teamId, $memberId]
        );
    } catch (Exception $e) {
        // Non-fatal — the member create/update itself already succeeded.
        error_log("[ext_api_sync_team_id_shim] failed for member {$memberId}, team {$teamId}: " . $e->getMessage());
    }
}

/**
 * Resolve the display-convenience team_id/team_name for a GET response.
 * Prefers the row this shim itself wrote (source='external_api'); falls
 * back to the first team_members row of ANY source; else null/null. There
 * is no "primary" concept in this codebase (see CLAUDE.md) — this is a
 * display convenience for a legacy-shaped field, not a new source of truth.
 */
function ext_api_resolve_team_display(int $memberId): array {
    try {
        $row = db_fetch_one(
            "SELECT tm.team_id, t.team AS team_name
             FROM " . db_table('team_members') . " tm
             JOIN " . db_table('teams') . " t ON t.id = tm.team_id
             WHERE tm.member_id = ?
             ORDER BY (tm.source = 'external_api') DESC, tm.id ASC
             LIMIT 1",
            [$memberId]
        );
    } catch (Exception $e) {
        return ['team_id' => null, 'team_name' => null];
    }
    return $row
        ? ['team_id' => (int) $row['team_id'], 'team_name' => $row['team_name']]
        : ['team_id' => null, 'team_name' => null];
}

/**
 * Full team_memberships[] array for a member — mirrors the internal
 * single-member GET's shape (api/members.php).
 */
function ext_api_team_memberships(int $memberId): array {
    try {
        return db_fetch_all(
            "SELECT tm.id, tm.team_id, t.team AS team_name, tm.role, tm.position_code, tm.assigned_date
             FROM " . db_table('team_members') . " tm
             JOIN " . db_table('teams') . " t ON t.id = tm.team_id
             WHERE tm.member_id = ?
             ORDER BY t.team",
            [$memberId]
        );
    } catch (Exception $e) {
        return [];
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET — list or detail
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    ext_api_require_scope('members:read');
    // action.view_members was dropped 2026-08-15 (tools/rbac_permission_
    // audit.php) -- no such permission was ever seeded. roster.view is the
    // real, already-seeded code (roster.php's own page gate is
    // screen.roster; roster.view is the RBAC-aware read-access check the
    // rest of the app uses alongside it).
    if (!rbac_can('roster.view') && !rbac_can('action.manage_members')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'roster.view']);
    }

    // Detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            // GH#76 Phase 144 (2026-08-18): team_id/team_name no longer come
            // from a JOIN on the (now-compat-only) member.team_id column —
            // that would return a stale value for any member whose team
            // assignment happened via team_members. Resolved below instead.
            $row = db_fetch_one(
                "SELECT m.*, mt.name AS type_name, ms.status_val AS status_name
                 FROM " . db_table('member') . " m
                 LEFT JOIN " . db_table('member_types') . " mt ON mt.id = m.member_type_id
                 LEFT JOIN " . db_table('member_status') . " ms ON ms.id = m.member_status_id
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

        $teamDisplay = ext_api_resolve_team_display($id);
        $row['team_id']   = $teamDisplay['team_id'];
        $row['team_name'] = $teamDisplay['team_name'];
        $row['team_memberships'] = ext_api_team_memberships($id);

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
        // GH#76 Phase 144 (2026-08-18): team_id/team_name dropped from this
        // SELECT's JOIN — resolved per-row below via
        // ext_api_resolve_team_display() so list results match GET detail's
        // resolution instead of returning the stale legacy column.
        $rows = db_fetch_all(
            "SELECT m.id, m.first_name, m.last_name, m.middle_name, m.callsign,
                    m.title, m.email, m.phone_cell, m.available,
                    m.member_type_id, m.member_status_id,
                    mt.name AS type_name, ms.status_val AS status_name
             FROM " . db_table('member') . " m
             LEFT JOIN " . db_table('member_types') . " mt ON mt.id = m.member_type_id
             LEFT JOIN " . db_table('member_status') . " ms ON ms.id = m.member_status_id
             {$whereSql}
             ORDER BY m.last_name ASC, m.first_name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        foreach ($rows as &$r) {
            $teamDisplay = ext_api_resolve_team_display((int) $r['id']);
            $r['team_id']   = $teamDisplay['team_id'];
            $r['team_name'] = $teamDisplay['team_name'];
        }
        unset($r);
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

    // GH#76 Phase 144 (2026-08-18) — team_id compat shim. member_create_internal()
    // no longer writes member.team_id (see inc/member-write.php); this is the
    // ONE path that still honours an incoming team_id, by upserting
    // team_members with source='external_api' instead.
    if (array_key_exists('team_id', $input) && !empty($input['team_id'])) {
        ext_api_sync_team_id_shim((int) $result['id'], $input['team_id']);
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

    // GH#76 Phase 144 (2026-08-18) — team_id compat shim. Extracted from
    // $fields BEFORE it reaches member_update_internal(), which no longer
    // accepts team_id at all (see inc/member-write.php). Without this, a
    // PATCH whose ONLY field is team_id would fail with "no fields to
    // update" purely because the internal whitelist dropped the column —
    // even though this endpoint's own contract still promises to accept it.
    $teamIdProvided = array_key_exists('team_id', $fields);
    $teamIdValue    = $fields['team_id'] ?? null;
    unset($fields['team_id']);

    $fieldsChanged = [];
    if (!empty($fields)) {
        try {
            $result = member_update_internal($memberId, $fields, $userId);
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!empty($result['errors'])) {
            ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
        }
        $fieldsChanged = $result['fields_changed'];
    } elseif (!$teamIdProvided) {
        // $fields was non-empty when checked above, so this can only be
        // reached if it contained team_id alone and nothing else — handled
        // below. Defensive guard in case that assumption ever breaks.
        ext_api_error('validation_failed', 422, ['errors' => ['no fields to update']]);
    }

    // SHIPPED DEFAULT (flagged in the GH#76 design spec for Eric's explicit
    // confirmation before release): clearing team_id via PATCH (null/0/
    // empty) does NOT delete the mirrored team_members row. Rationale: only
    // a genuinely human-initiated removal (Teams tab or the roster card,
    // both source=NULL) represents a deliberate "take this person off the
    // team" decision — an external caller clearing a legacy compat field
    // should not silently evict someone a coordinator placed there through
    // the UI. A non-empty team_id upserts additively (never removes any
    // OTHER team_members row the member has, regardless of source).
    if ($teamIdProvided && !empty($teamIdValue)) {
        ext_api_sync_team_id_shim($memberId, $teamIdValue);
        $fieldsChanged[] = 'team_id';
    }

    audit_log('personnel', 'update', 'member', $memberId,
        "External API updated member #{$memberId} (" . implode(', ', $fieldsChanged) . ")",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'fields_changed'   => $fieldsChanged,
            'via_external_api' => true,
        ]
    );

    ext_api_response([
        'id'             => $memberId,
        'fields_changed' => $fieldsChanged,
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
