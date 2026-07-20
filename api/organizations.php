<?php
/**
 * NewUI v4.0 API - Organizations
 *
 * CRUD for organizations and member-organization assignments.
 *
 * GET  ?action=list                          — All orgs with member counts (admin)
 * GET  ?action=member_orgs&member_id=X       — Orgs for a specific member
 * GET  ?action=user_orgs                     — Orgs for current session user's linked member
 *
 * POST action=save_org                       — Create or update org (admin)
 * POST action=delete_org                     — Delete org with guard (admin)
 * POST action=assign_member                  — Add member to org
 * POST action=unassign_member                — Remove member from org
 * POST action=update_member_org              — Change member type/status within org
 * POST action=set_active_org                 — Store active org in session (any user)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'POST') {
    handlePost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

// ─── GET handlers ──────────────────────────────────────────────────────

function handleGet() {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $rows = db_fetch_all(
            "SELECT o.*,
                    (SELECT COUNT(*) FROM " . db_table('member_organizations') . " mo
                     WHERE mo.org_id = o.id AND mo.status = 'active') AS member_count
             FROM " . db_table('organizations') . " o
             ORDER BY o.sort_order, o.name"
        );
        // Cast member_count to int so JS doesn't get string "0"
        foreach ($rows as &$row) {
            $row['member_count'] = (int) ($row['member_count'] ?? 0);
        }
        unset($row);
        json_response(['organizations' => $rows]);
    }

    if ($action === 'member_orgs') {
        $memberId = intval($_GET['member_id'] ?? 0);
        if (!$memberId) json_error('member_id required');

        $rows = db_fetch_all(
            "SELECT mo.*, o.name AS org_name, o.short_name, o.org_type,
                    mt.name AS type_name, mt.color AS type_color
             FROM " . db_table('member_organizations') . " mo
             JOIN " . db_table('organizations') . " o ON mo.org_id = o.id
             LEFT JOIN " . db_table('member_types') . " mt ON mo.member_type_id = mt.id
             WHERE mo.member_id = ? AND o.active = 1
             ORDER BY o.sort_order, o.name",
            [$memberId]
        );
        json_response(['organizations' => $rows]);
    }

    if ($action === 'user_orgs') {
        $memberRow = null;
        if (!empty($_SESSION['user_id'])) {
            try {
                $memberRow = db_fetch_one(
                    "SELECT id FROM " . db_table('member') . " WHERE user_id = ?",
                    [(int)$_SESSION['user_id']]
                );
            } catch (Exception $e) {
                // user_id column may not exist on legacy member table
                $memberRow = null;
            }
        }

        if (!$memberRow) {
            json_response(['organizations' => [], 'active_org_id' => $_SESSION['active_org_id'] ?? 1]);
        }

        $rows = db_fetch_all(
            "SELECT mo.org_id, o.name AS org_name, o.short_name, mo.member_type_id
             FROM " . db_table('member_organizations') . " mo
             JOIN " . db_table('organizations') . " o ON mo.org_id = o.id
             WHERE mo.member_id = ? AND mo.status = 'active' AND o.active = 1
             ORDER BY o.sort_order, o.name",
            [(int)$memberRow['id']]
        );
        json_response([
            'organizations' => $rows,
            'active_org_id' => $_SESSION['active_org_id'] ?? 1
        ]);
    }

    json_error('Unknown action: ' . $action);
}

// ─── POST handlers ─────────────────────────────────────────────────────

function handlePost() {
    global $current_level, $current_user_id;

    $raw  = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    $action = $input['action'] ?? '';

    // set_active_org — any authenticated user
    if ($action === 'set_active_org') {
        $orgId = intval($input['org_id'] ?? 0);
        if (!$orgId) json_error('org_id required');
        $_SESSION['active_org_id'] = $orgId;

        // Refresh user_orgs if needed
        if (!empty($_SESSION['user_orgs'])) {
            $found = false;
            foreach ($_SESSION['user_orgs'] as $o) {
                if ((int)$o['org_id'] === $orgId) { $found = true; break; }
            }
            if (!$found) json_error('You are not a member of this organization');
        }

        json_response(['success' => true, 'active_org_id' => $orgId]);
    }

    // All other POST actions require admin
    if (!is_admin()) {
        json_error('Admin access required', 403);
    }

    // ── Org CRUD ────────────────────────────────────────────────

    if ($action === 'save_org') {
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if ($name === '') json_error('Organization name is required');

        // Phase 99j-3 (Billy beta 2026-06-29) — hierarchical orgs.
        // parent_org_id is optional (NULL = top-level). Server-side
        // cycle guard: when editing, refuse to set a parent that is
        // this org itself OR any of its existing descendants. Reuses
        // org_descendant_ids() from inc/org-scope.php so the rule
        // stays in one place.
        $parentOrgIdInput = $input['parent_org_id'] ?? null;
        $parentOrgId = ($parentOrgIdInput === null || $parentOrgIdInput === '' || (int)$parentOrgIdInput <= 0)
            ? null : (int) $parentOrgIdInput;
        if ($parentOrgId !== null && $id > 0) {
            if ($parentOrgId === $id) {
                json_error('An organization cannot be its own parent.');
            }
            // Walk this org's descendants — if the chosen parent is
            // already a descendant, we'd create a cycle. Soft-load
            // the helper.
            if (!function_exists('org_descendant_ids')) {
                $scopeFile = __DIR__ . '/../inc/org-scope.php';
                if (is_file($scopeFile)) require_once $scopeFile;
            }
            if (function_exists('org_descendant_ids')) {
                $desc = org_descendant_ids($id);
                if (in_array($parentOrgId, $desc, true)) {
                    json_error('Cannot set parent to a descendant of this organization — would create a cycle.');
                }
            }
        }

        $fields = [
            'name'          => $name,
            'short_name'    => trim($input['short_name'] ?? '') ?: null,
            'org_type'      => trim($input['org_type'] ?? '') ?: null,
            'description'   => trim($input['description'] ?? '') ?: null,
            'contact_name'  => trim($input['contact_name'] ?? '') ?: null,
            'contact_email' => trim($input['contact_email'] ?? '') ?: null,
            'contact_phone' => trim($input['contact_phone'] ?? '') ?: null,
            'address'       => trim($input['address'] ?? '') ?: null,
            'city'          => trim($input['city'] ?? '') ?: null,
            'state'         => trim($input['state'] ?? '') ?: null,
            'zip'           => trim($input['zip'] ?? '') ?: null,
            'active'        => isset($input['active']) ? (int)(bool)$input['active'] : 1,
            'sort_order'    => intval($input['sort_order'] ?? 0),
            'parent_org_id' => $parentOrgId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        try {
            if ($id > 0) {
                $sets = [];
                $vals = [];
                foreach ($fields as $col => $val) {
                    $sets[] = "`$col` = ?";
                    $vals[] = $val;
                }
                $vals[] = $id;
                db_query(
                    "UPDATE " . db_table('organizations') . " SET " . implode(', ', $sets) . " WHERE id = ?",
                    $vals
                );
                audit_log('config', 'update', 'organization', $id, "Updated organization '{$name}'", [
                    'org_type' => $fields['org_type']
                ]);
            } else {
                $fields['created_at'] = date('Y-m-d H:i:s');
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('organizations') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($fields)
                );
                $id = db_insert_id();
                audit_log('config', 'create', 'organization', $id, "Created organization '{$name}'", [
                    'org_type' => $fields['org_type']
                ]);
            }
            json_response(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Failed to save organization: ' . $e->getMessage());
        }
    }

    if ($action === 'delete_org') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('id required');
        if ($id === 1) json_error('Cannot delete the default System Owner organization');

        // Check for member links
        $cnt = db_fetch_one(
            "SELECT COUNT(*) AS cnt FROM " . db_table('member_organizations') . " WHERE org_id = ?",
            [$id]
        );
        if ($cnt && (int)$cnt['cnt'] > 0) {
            json_error("Cannot delete: {$cnt['cnt']} member(s) are assigned to this organization. Remove them first.");
        }

        try {
            $org = db_fetch_one("SELECT name FROM " . db_table('organizations') . " WHERE id = ?", [$id]);
            $orgName = $org ? $org['name'] : "#{$id}";

            db_query("DELETE FROM " . db_table('organizations') . " WHERE id = ?", [$id]);
            audit_log('config', 'delete', 'organization', $id, "Deleted organization '{$orgName}'");
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to delete organization: ' . $e->getMessage());
        }
    }

    // ── Member-Org Assignment ───────────────────────────────────

    if ($action === 'assign_member') {
        $memberId = intval($input['member_id'] ?? 0);
        $orgId    = intval($input['org_id'] ?? 0);
        if (!$memberId || !$orgId) json_error('member_id and org_id required');

        try {
            db_query(
                "INSERT IGNORE INTO " . db_table('member_organizations') .
                " (member_id, org_id, member_type_id, status, join_date, created_at) VALUES (?, ?, ?, 'active', CURDATE(), NOW())",
                [$memberId, $orgId, !empty($input['member_type_id']) ? intval($input['member_type_id']) : null]
            );
            audit_log('personnel', 'assign', 'member_organization', null, "Assigned member #{$memberId} to org #{$orgId}", [
                'member_id' => $memberId,
                'org_id'    => $orgId,
                'member_type_id' => $input['member_type_id'] ?? null
            ]);
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to assign member: ' . $e->getMessage());
        }
    }

    if ($action === 'unassign_member') {
        // Accept either id (row PK) or member_id + org_id
        $rowId    = intval($input['id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        $orgId    = intval($input['org_id'] ?? 0);

        if (!$rowId && (!$memberId || !$orgId)) {
            json_error('Provide id or member_id + org_id');
        }

        try {
            if ($rowId) {
                $row = db_fetch_all("SELECT member_id, org_id FROM " . db_table('member_organizations') . " WHERE id = ?", [$rowId]);
                if (!empty($row)) { $memberId = $row[0]['member_id']; $orgId = $row[0]['org_id']; }
                db_query("DELETE FROM " . db_table('member_organizations') . " WHERE id = ?", [$rowId]);
            } else {
                db_query("DELETE FROM " . db_table('member_organizations') . " WHERE member_id = ? AND org_id = ?", [$memberId, $orgId]);
            }
            audit_log('personnel', 'unassign', 'member_organization', null, "Removed member #{$memberId} from org #{$orgId}", [
                'member_id' => $memberId,
                'org_id'    => $orgId
            ]);
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to unassign member: ' . $e->getMessage());
        }
    }

    if ($action === 'update_member_org') {
        $memberId = intval($input['member_id'] ?? 0);
        $orgId    = intval($input['org_id'] ?? 0);
        if (!$memberId || !$orgId) json_error('member_id and org_id required');

        $sets = [];
        $vals = [];
        if (isset($input['member_type_id'])) {
            $sets[] = 'member_type_id = ?';
            $vals[] = !empty($input['member_type_id']) ? intval($input['member_type_id']) : null;
        }
        if (isset($input['status'])) {
            $sets[] = 'status = ?';
            $vals[] = $input['status'];
        }
        if (isset($input['notes'])) {
            $sets[] = 'notes = ?';
            $vals[] = trim($input['notes']) ?: null;
        }
        if (isset($input['join_date'])) {
            $sets[] = 'join_date = ?';
            $vals[] = !empty($input['join_date']) ? $input['join_date'] : null;
        }
        if (isset($input['role'])) {
            $sets[] = 'role = ?';
            $vals[] = trim($input['role']) ?: null;
        }
        if (empty($sets)) json_error('No fields to update');

        $vals[] = $memberId;
        $vals[] = $orgId;

        try {
            db_query(
                "UPDATE " . db_table('member_organizations') . " SET " . implode(', ', $sets) . " WHERE member_id = ? AND org_id = ?",
                $vals
            );
            audit_log('personnel', 'update', 'member_organization', null, "Updated member #{$memberId} in org #{$orgId}", [
                'member_id' => $memberId,
                'org_id'    => $orgId
            ]);
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to update: ' . $e->getMessage());
        }
    }

    json_error('Unknown action: ' . $action);
}
