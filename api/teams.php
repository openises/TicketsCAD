<?php
/**
 * NewUI v4.0 API - Teams Management
 *
 * GET  /api/teams.php              — List all teams with members + ICS positions
 * GET  /api/teams.php?id=X         — Get single team with full member list
 * POST /api/teams.php              — Create or update team
 * POST action=delete               — Delete team
 * POST action=add_member           — Add member to team
 * POST action=remove_member        — Remove member from team
 * POST action=update_member_role   — Update member role/position in team
 *
 * Legacy DB mapping:
 *   teams.team       = name
 *   teams.mission    = description
 *   teams.ttypes_id  = team type ID
 *   teams.leader     = leader member ID
 *   teams.leader_dpty = deputy member ID
 *   member.field1    = surname
 *   member.field2    = first name
 *   member.field4    = callsign  (PRE-RELEASE-FIXES #17 — was incorrectly field26)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/team-write.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_teams($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_teams] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

/**
 * Get a member display name from the legacy member table.
 * Returns array of id, name, callsign for lightweight display.
 */
function get_member_display_list() {
    // 2026-06-26 (a beta tester): read modern column names
    // (first_name / last_name / callsign / member_type_id / available)
    // populated by api/members.php's saveMember endpoint. The legacy
    // field1..field65 columns are still present for back-compat with
    // v3.44-upgrade installs, but on fresh installs the modern columns
    // are where the data actually lives — reading field1/field2/field4
    // returned NULL/empty for every member, so the Team-Leader and
    // Deputy-Leader dropdowns were empty even after members were added.
    // COALESCE keeps it working on upgrade installs where the legacy
    // fields might still be populated and the modern columns weren't
    // backfilled.
    return safe_fetch_teams(
        "SELECT m.id,
                COALESCE(NULLIF(m.last_name, ''),  m.field1) AS last_name,
                COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                COALESCE(NULLIF(m.callsign, ''),   m.field4) AS callsign,
                COALESCE(NULLIF(m.available, ''),  m.field8) AS available,
                mt.name AS type_name, ms.status_val AS status_name
         FROM " . db_table('member') . " m
         LEFT JOIN " . db_table('member_types') . " mt
                ON mt.id = COALESCE(m.member_type_id, m.field3)
         LEFT JOIN " . db_table('member_status') . " ms ON m.member_status_id = ms.id
         WHERE COALESCE(m.deleted_at, '0000-00-00') = '0000-00-00'
         ORDER BY last_name, first_name"
    );
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
    // Single team with full details
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $team = safe_fetch_teams(
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

        if (empty($team)) json_error('Team not found', 404);

        // Team members from junction table. Same modern-column COALESCE
        // pattern as get_member_display_list() — works on fresh AND
        // upgrade installs.
        $members = safe_fetch_teams(
            "SELECT tm.id AS assignment_id, tm.member_id, tm.role,
                    tm.position_code, tm.assigned_date, tm.notes,
                    COALESCE(NULLIF(m.last_name, ''),  m.field1) AS last_name,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.callsign, ''),   m.field4) AS callsign,
                    COALESCE(NULLIF(m.available, ''),  m.field8) AS available,
                    ip.title AS position_title, ip.category AS position_category
             FROM " . db_table('team_members') . " tm
             JOIN " . db_table('member') . " m ON tm.member_id = m.id
             LEFT JOIN " . db_table('ics_positions') . " ip ON tm.position_code = ip.code
             WHERE tm.team_id = ?
             ORDER BY FIELD(tm.role, 'Leader', 'Deputy', 'Member', 'Observer'), last_name",
            [$id]
        );

        // ICS positions for dropdown
        $ics_positions = safe_fetch_teams(
            "SELECT id, code, title, category FROM " . db_table('ics_positions') . "
             WHERE active = 1 ORDER BY sort_order, code"
        );

        // Available members (not already on this team). Same modern-
        // column COALESCE pattern as get_member_display_list().
        $available = safe_fetch_teams(
            "SELECT m.id,
                    COALESCE(NULLIF(m.last_name, ''),  m.field1) AS last_name,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.callsign, ''),   m.field4) AS callsign
             FROM " . db_table('member') . " m
             WHERE m.id NOT IN (
                 SELECT member_id FROM " . db_table('team_members') . " WHERE team_id = ?
             )
             AND COALESCE(m.deleted_at, '0000-00-00') = '0000-00-00'
             ORDER BY last_name, first_name",
            [$id]
        );

        json_response([
            'team'           => $team[0],
            'members'        => $members,
            'ics_positions'  => $ics_positions,
            'available_members' => $available
        ]);
    }

    // List all teams — Phase 99j-6 org-scope filter.
    require_once __DIR__ . '/../inc/org-scope.php';
    ensure_org_id_column('teams');
    [$orgFrag, $orgVars] = org_query_filter('t.org_id');
    $teams = safe_fetch_teams(
        "SELECT t.id, t.`team` AS name, t.mission AS description,
                t.ttypes_id AS team_type_id, t.leader AS leader_id,
                t.leader_dpty AS deputy_id, t.formed,
                t.nims_resource_type, t.nims_typing_level,
                tt.type AS type_name,
                (SELECT COUNT(*)
                   FROM " . db_table('team_members') . " tm
                   INNER JOIN " . db_table('member') . " m ON tm.member_id = m.id
                  WHERE tm.team_id = t.id) AS member_count
         FROM " . db_table('teams') . " t
         LEFT JOIN " . db_table('team_types') . " tt ON t.ttypes_id = tt.id
         WHERE 1=1 {$orgFrag}
         ORDER BY t.`team`",
        $orgVars
    );

    // Team types for dropdown
    $team_types = safe_fetch_teams(
        "SELECT * FROM " . db_table('team_types') . " ORDER BY type"
    );

    // ICS positions for reference
    $ics_positions = safe_fetch_teams(
        "SELECT id, code, title, category FROM " . db_table('ics_positions') . "
         WHERE active = 1 ORDER BY sort_order, code"
    );

    // All members for assignment
    $all_members = get_member_display_list();

    json_response([
        'teams'         => $teams,
        'team_types'    => $team_types,
        'ics_positions' => $ics_positions,
        'all_members'   => $all_members
    ]);
}

function handlePost() {
    global $current_user_id;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // RBAC + CSRF enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_teams; reads (GET) stay open to viewers.
    if (!rbac_can('action.manage_teams')) {
        json_error('Insufficient permissions: manage teams', 403);
    }
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // ── Delete team ──
    // 2026-06-28: delegates to inc/team-write.php :: team_soft_delete_
    // internal() (hard delete underneath; cascades to team_members).
    // Audit category normalized from legacy 'personnel|delete|team' to
    // canonical 'asset|delete|team' for webhook fan-out parity with
    // the external API path.
    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        // Phase 99j-6b — org-scope gate.
        require_once __DIR__ . '/../inc/org-scope.php';
        if (!org_can_see_row('teams', $id)) {
            json_error('Team not found', 404);
        }
        try {
            $result = team_soft_delete_internal($id, (int) ($current_user_id ?? 0));
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        if (!empty($result['errors'])) {
            $errs = $result['errors'];
            if (in_array('not_found', $errs, true)) json_error('Team not found', 404);
            if (in_array('invalid_id', $errs, true)) json_error('Missing id');
            json_error('Delete failed: ' . implode(', ', $errs), 422);
        }
        $tName = $result['name'] ?? "#{$id}";
        audit_log('asset', 'delete', 'team', $id, "Deleted team '{$tName}'");
        json_response(['success' => true]);
    }

    // ── Add member to team ──
    if ($action === 'add_member') {
        $teamId = intval($input['team_id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        if (!$teamId || !$memberId) json_error('Missing team_id or member_id');

        $role = trim($input['role'] ?? 'Member');
        $posCode = trim($input['position_code'] ?? '') ?: null;

        try {
            db_query(
                "INSERT INTO " . db_table('team_members') . "
                 (team_id, member_id, role, position_code, assigned_date)
                 VALUES (?, ?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE role = VALUES(role), position_code = VALUES(position_code)",
                [$teamId, $memberId, $role, $posCode]
            );
            audit_log('personnel', 'assign', 'team_member', null, "Added member #{$memberId} to team #{$teamId} as {$role}", [
                'team_id' => $teamId,
                'member_id' => $memberId,
                'role' => $role,
                'position_code' => $posCode
            ]);
        } catch (Exception $e) {
            json_error('Failed to add member: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Remove member from team ──
    if ($action === 'remove_member') {
        $id = intval($input['assignment_id'] ?? 0);
        if (!$id) json_error('Missing assignment_id');
        try {
            $tm = safe_fetch_teams("SELECT team_id, member_id FROM " . db_table('team_members') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('team_members') . " WHERE id = ?", [$id]);
            audit_log('personnel', 'unassign', 'team_member', $id, "Removed member from team", [
                'team_id' => !empty($tm) ? $tm[0]['team_id'] : null,
                'member_id' => !empty($tm) ? $tm[0]['member_id'] : null
            ]);
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Update member role in team ──
    if ($action === 'update_member_role') {
        $id = intval($input['assignment_id'] ?? 0);
        if (!$id) json_error('Missing assignment_id');

        $role = trim($input['role'] ?? 'Member');
        $posCode = trim($input['position_code'] ?? '') ?: null;
        $notes = trim($input['notes'] ?? '') ?: null;

        try {
            db_query(
                "UPDATE " . db_table('team_members') . "
                 SET role = ?, position_code = ?, notes = ?
                 WHERE id = ?",
                [$role, $posCode, $notes, $id]
            );
            audit_log('personnel', 'update', 'team_member', $id, "Updated team member role to '{$role}'", [
                'role' => $role,
                'position_code' => $posCode
            ]);
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Create or update team ──
    // 2026-06-28: delegates to inc/team-write.php :: team_upsert_internal()
    // — handles both create and update paths, plus PRE-RELEASE-FIXES #18
    // auto-promotion of leader/deputy into team_members. Audit category
    // normalized from legacy 'personnel|create|team' + 'personnel|update|
    // team' to canonical 'asset|create|team' + 'asset|update|team' for
    // webhook fan-out parity with the external API path.
    $name = trim($input['name'] ?? '');
    if (!$name) json_error('Team name is required');

    try {
        $result = team_upsert_internal($input, (int) ($current_user_id ?? 0));
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage());
    }

    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) json_error('Team not found', 404);
        if (in_array('name is required', $errs, true)) json_error('Team name is required');
        json_error('Save failed: ' . implode(', ', $errs), 422);
    }

    $id    = (int) $result['id'];
    $isNew = !empty($result['is_new']);

    if ($isNew) {
        audit_log('asset', 'create', 'team', $id, "Created team '{$name}'");
    } else {
        audit_log('asset', 'update', 'team', $id, "Updated team '{$name}'");
    }

    json_response(['success' => true, 'id' => $id]);
}
