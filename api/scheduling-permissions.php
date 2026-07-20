<?php
/**
 * NewUI v4.0 API — Scheduling Permissions
 *
 * Manages permission profiles and assignments for shift scheduling.
 *
 * GET  /api/scheduling-permissions.php                    — list profiles
 * GET  /api/scheduling-permissions.php?assignments=1      — list assignments
 * GET  /api/scheduling-permissions.php?resolve=1          — resolve effective perms for current user
 * POST action=save_profile     — create/update permission profile (admin)
 * POST action=delete_profile   — delete permission profile (admin)
 * POST action=save_assignment  — create/update permission assignment (admin)
 * POST action=delete_assignment — delete permission assignment (admin)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/scheduling-perms.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — Read operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // GET ?resolve=1 — resolve effective permissions for current user
    // Optional: &scope_type=template&scope_id=3
    if (isset($_GET['resolve'])) {
        $scopeType = isset($_GET['scope_type']) ? $_GET['scope_type'] : 'global';
        $scopeId   = isset($_GET['scope_id']) ? (int) $_GET['scope_id'] : null;

        // Get member_id for current user
        $memberId = 0;
        try {
            $member = db_fetch_one(
                "SELECT `id` FROM `{$prefix}member` WHERE `user_id` = ?",
                [$current_user_id]
            );
            if ($member) $memberId = (int) $member['id'];
        } catch (Exception $e) {}

        if ($memberId) {
            $perms = scheduling_get_effective_permissions($memberId, $scopeType, $scopeId);
        } else {
            // No member record — admin gets full, others get view_only
            $perms = scheduling_is_admin() ? _sched_perm_full_control() : _sched_perm_default();
        }

        json_response(['permissions' => $perms]);
    }

    // GET ?assignments=1 — list all permission assignments
    if (isset($_GET['assignments'])) {
        $scopeType = isset($_GET['scope_type']) ? $_GET['scope_type'] : null;
        $scopeId   = isset($_GET['scope_id']) ? (int) $_GET['scope_id'] : null;

        $assignments = scheduling_get_assignments($scopeType, $scopeId);
        json_response(['assignments' => $assignments]);
    }

    // GET (no params) — list all profiles
    $profiles = scheduling_get_all_profiles();
    json_response(['profiles' => $profiles]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations (admin only)
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Admin only for all write operations
    if (!is_admin()) {
        json_error('Admin access required', 403);
    }

    $action = $input['action'] ?? '';

    // ── save_profile — create/update permission profile ───────
    if ($action === 'save_profile') {
        $id   = isset($input['id']) ? (int) $input['id'] : 0;
        $code = trim($input['code'] ?? '');
        $name = trim($input['name'] ?? '');
        $desc = trim($input['description'] ?? '');

        if ($name === '') {
            json_error('Profile name is required');
        }
        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        }

        // Permission flags
        $flags = [];
        $flagNames = [
            'can_view_schedule', 'can_view_own', 'can_view_others', 'can_view_available',
            'can_self_assign', 'can_self_remove', 'can_mark_unavailable',
            'can_swap', 'can_request_cover',
            'can_assign_others', 'can_remove_others', 'can_change_status', 'can_manage_slots',
        ];

        foreach ($flagNames as $flag) {
            if (isset($input[$flag])) {
                $flags[$flag] = (int) $input[$flag];
            }
        }

        $sortOrder = isset($input['sort_order']) ? (int) $input['sort_order'] : 50;
        $active = isset($input['active']) ? (int) $input['active'] : 1;

        try {
            if ($id) {
                // Update
                $sets = ['`code` = ?', '`name` = ?', '`description` = ?', '`sort_order` = ?', '`active` = ?'];
                $params = [$code, $name, $desc, $sortOrder, $active];

                foreach ($flags as $flag => $val) {
                    $sets[] = "`{$flag}` = ?";
                    $params[] = $val;
                }

                $params[] = $id;
                db_query(
                    "UPDATE `{$prefix}scheduling_permission_profiles`
                     SET " . implode(', ', $sets) . "
                     WHERE `id` = ?",
                    $params
                );
            } else {
                // Insert
                $cols = ['`code`', '`name`', '`description`', '`sort_order`', '`active`'];
                $vals = ['?', '?', '?', '?', '?'];
                $params = [$code, $name, $desc, $sortOrder, $active];

                foreach ($flags as $flag => $val) {
                    $cols[] = "`{$flag}`";
                    $vals[] = '?';
                    $params[] = $val;
                }

                db_query(
                    "INSERT INTO `{$prefix}scheduling_permission_profiles`
                     (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")",
                    $params
                );
                $id = (int) db_insert_id();
            }

            audit_log('config', 'update', 'sched_perm_profile', $id,
                "Saved scheduling permission profile '{$name}'");

            json_response(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── delete_profile — delete permission profile ────────────
    if ($action === 'delete_profile') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) json_error('Profile id is required');

        try {
            // Don't allow deleting built-in profiles
            $profile = db_fetch_one(
                "SELECT `code` FROM `{$prefix}scheduling_permission_profiles` WHERE `id` = ?",
                [$id]
            );
            $builtIn = ['none', 'view_only', 'view_own', 'view_own_available', 'self_service', 'team_lead', 'full_control'];
            if ($profile && in_array($profile['code'], $builtIn)) {
                json_error('Cannot delete built-in profiles. Deactivate them instead.');
            }

            db_query("DELETE FROM `{$prefix}scheduling_permission_profiles` WHERE `id` = ?", [$id]);
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    // ── save_assignment — create/update permission assignment ──
    if ($action === 'save_assignment') {
        $id         = isset($input['id']) ? (int) $input['id'] : 0;
        $profileId  = isset($input['profile_id']) ? (int) $input['profile_id'] : 0;
        $scopeType  = $input['scope_type'] ?? 'global';
        $scopeId    = isset($input['scope_id']) ? (int) $input['scope_id'] : null;
        $targetType = $input['target_type'] ?? 'all';
        $targetId   = isset($input['target_id']) ? (int) $input['target_id'] : null;

        if (!$profileId) {
            json_error('profile_id is required');
        }

        $validScopes = ['global', 'template', 'event', 'role'];
        if (!in_array($scopeType, $validScopes)) {
            json_error('Invalid scope_type');
        }

        $validTargets = ['all', 'member', 'team', 'member_type'];
        if (!in_array($targetType, $validTargets)) {
            json_error('Invalid target_type');
        }

        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}scheduling_permission_assignments`
                     SET `profile_id` = ?, `scope_type` = ?, `scope_id` = ?,
                         `target_type` = ?, `target_id` = ?
                     WHERE `id` = ?",
                    [$profileId, $scopeType, $scopeId, $targetType, $targetId, $id]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}scheduling_permission_assignments`
                     (`profile_id`, `scope_type`, `scope_id`, `target_type`, `target_id`)
                     VALUES (?, ?, ?, ?, ?)",
                    [$profileId, $scopeType, $scopeId, $targetType, $targetId]
                );
                $id = (int) db_insert_id();
            }

            audit_log('config', 'update', 'sched_perm_assign', $id,
                "Saved scheduling permission assignment #{$id}");

            json_response(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── delete_assignment — remove a permission assignment ─────
    if ($action === 'delete_assignment') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) json_error('Assignment id is required');

        try {
            db_query(
                "DELETE FROM `{$prefix}scheduling_permission_assignments` WHERE `id` = ?",
                [$id]
            );
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    json_error('Unknown action: ' . $action, 400);
}

json_error('Method not allowed', 405);
