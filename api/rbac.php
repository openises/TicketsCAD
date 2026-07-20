<?php
/**
 * NewUI v4.0 API - RBAC Management
 *
 * GET  /api/rbac.php                    — List all roles with permission counts
 * GET  /api/rbac.php?role_id=X          — Get role with all permissions (checked/unchecked)
 * GET  /api/rbac.php?user_id=X          — Get user's assigned roles
 * GET  /api/rbac.php?permissions=1      — List all permissions grouped by category
 * POST action=save_role                 — Create/update role
 * POST action=delete_role               — Delete role (not built-in roles 1-6)
 * POST action=set_permissions           — Set role's permissions (full replacement)
 * POST action=assign_role               — Assign role to user
 * POST action=remove_role               — Remove role from user
 * POST action=migrate_levels            — Migrate legacy user.level to RBAC roles
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/rbac_grant.php';
require_once __DIR__ . '/../inc/audit.php';

$method = $_SERVER['REQUEST_METHOD'];

// Only Super Admin and Org Admin can manage RBAC
if ($current_level > 1 && !rbac_can('action.manage_roles')) {
    json_error('Insufficient permissions', 403);
}

// GH #77 (a beta tester) — the RBAC-v2 redesign (run_rbac_v2.php A8) added a second
// canonical `resource.verb` code for every permission and linked the old
// `screen./action.` codes to it via permissions.deprecated_alias_of. Both rows
// share a display name, so the roles editor listed "two of every permission".
// inc/rbac.php resolves the two codes to one another, so both work — the fix is
// to LIST canonical rows only (deprecated_alias_of IS NULL). Guard for installs
// that never ran RBAC v2 (no such column).
$rbacAliasCol = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ?
        AND column_name = 'deprecated_alias_of'",
    [($GLOBALS['db_prefix'] ?? '') . 'permissions']);
// Fragments — a bare-table WHERE and a `p`-aliased WHERE (both queries that
// need this filter currently have no WHERE of their own).
$RBAC_CANON_WHERE   = $rbacAliasCol ? 'WHERE `deprecated_alias_of` IS NULL' : '';
$RBAC_CANON_WHERE_P = $rbacAliasCol ? 'WHERE p.`deprecated_alias_of` IS NULL' : '';

if ($method === 'GET') {
    // Phase 11 (2026-06-11): migration status — used by the Roles &
    // Permissions UI to hide the "Migrate Legacy Levels to Roles"
    // button once all users have RBAC grants. Reports:
    //   - needs_migration: true iff there's at least one user with no
    //     active grant
    //   - legacy_only_users: count of users with no active grant
    //   - total_users
    //   - users_with_grants
    if (($_GET['action'] ?? '') === 'migration_status') {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $totalUsers = 0;
        $usersWithGrants = 0;
        try {
            $totalUsers = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}user`"
            );
            $usersWithGrants = (int) db_fetch_value(
                "SELECT COUNT(DISTINCT ur.user_id)
                 FROM `{$prefix}user_roles` ur
                 INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
                 WHERE ur.expires_at IS NULL OR ur.expires_at > NOW()"
            );
        } catch (Exception $e) {
            // user_roles table missing — pre-RBAC-v2 install — migration
            // is definitely needed.
        }
        $legacyOnly = max(0, $totalUsers - $usersWithGrants);
        json_response([
            'needs_migration'    => $legacyOnly > 0,
            'legacy_only_users'  => $legacyOnly,
            'total_users'        => $totalUsers,
            'users_with_grants'  => $usersWithGrants,
        ]);
    }

    // 2026-06-11 — Permission audit: returns every permission +
    // which non-system roles grant it. Permissions granted by ZERO
    // non-system roles are flagged so admins can spot newly-added
    // capabilities that haven't been wired into any role yet.
    if (($_GET['action'] ?? '') === 'permission_audit') {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            // All permissions
            $perms = db_fetch_all(
                "SELECT id, code, name, category, description, deprecated_alias_of
                   FROM `{$prefix}permissions`
                  {$RBAC_CANON_WHERE}
                  ORDER BY category, code"
            );
            // Roles indexed by id (so we can look up names cheaply)
            $rolesById = [];
            foreach (db_fetch_all("SELECT id, name, is_system, is_super, legacy_level FROM `{$prefix}roles` ORDER BY id") as $r) {
                $rolesById[(int) $r['id']] = $r;
            }
            // Grants per permission_id → list of role ids
            $grantsByPerm = [];
            $rows = db_fetch_all(
                "SELECT rp.permission_id, rp.role_id
                   FROM `{$prefix}role_permissions` rp"
            );
            foreach ($rows as $g) {
                $pid = (int) $g['permission_id'];
                if (!isset($grantsByPerm[$pid])) $grantsByPerm[$pid] = [];
                $grantsByPerm[$pid][] = (int) $g['role_id'];
            }
            // GH #77 — the list above shows CANONICAL rows only, but a grant may
            // sit on a deprecated alias id. Fold each alias's grants onto its
            // canonical bucket so a grant made via the old code isn't miscounted
            // as "ungranted to human roles" (a false-positive audit banner).
            if ($rbacAliasCol) {
                foreach (db_fetch_all(
                    "SELECT a.id AS alias_id, c.id AS canon_id
                       FROM `{$prefix}permissions` a
                       JOIN `{$prefix}permissions` c ON c.code = a.deprecated_alias_of
                      WHERE a.deprecated_alias_of IS NOT NULL AND a.deprecated_alias_of <> ''"
                ) as $m) {
                    $aliasId = (int) $m['alias_id'];
                    $canonId = (int) $m['canon_id'];
                    if (!empty($grantsByPerm[$aliasId])) {
                        if (!isset($grantsByPerm[$canonId])) $grantsByPerm[$canonId] = [];
                        $grantsByPerm[$canonId] = array_merge(
                            $grantsByPerm[$canonId], $grantsByPerm[$aliasId]);
                    }
                }
            }
            // Phase 99u-1 (a beta tester/Eric beta 2026-06-29) — pull the
            // set of admin-dismissed permission ids so the audit can
            // stop counting reviewed-and-acknowledged ones.
            $dismissedSet = rbac_dismissed_permission_ids();

            $out = [];
            $ungrantedCount = 0;     // pre-dismissal — legacy field
            $unreviewedCount = 0;    // post-dismissal — drives the banner
            foreach ($perms as $p) {
                $pid = (int) $p['id'];
                $rolesGranted = [];
                // array_unique — a role may hold BOTH the canonical and its
                // alias grant; count it once (GH #77 alias fold, above).
                foreach (array_unique($grantsByPerm[$pid] ?? []) as $rid) {
                    if (isset($rolesById[$rid])) {
                        $rolesGranted[] = [
                            'id'        => $rid,
                            'name'      => $rolesById[$rid]['name'],
                            'is_system' => (int) $rolesById[$rid]['is_system'],
                            'is_super'  => (int) $rolesById[$rid]['is_super'],
                        ];
                    }
                }
                // Phase 99u-2 followup (Eric beta 2026-06-30): the
                // "reviewed" question is whether ANY non-Super-Admin
                // role grants this permission. Operator / Dispatcher /
                // Org Admin / etc. are system roles BUT they're admin-
                // configurable defaults — when one of them grants a
                // permission, that IS the review signal we care about.
                // Only Super Admin (is_super=1) doesn't count, because
                // the seed migration auto-grants new perms to Super
                // Admin by convention — its grants don't represent a
                // human decision about who should hold the permission.
                $nonSuperCount = 0;
                foreach ($rolesGranted as $rg) {
                    if (!$rg['is_super']) $nonSuperCount++;
                }
                $isUngrantedToHumanRoles = ($nonSuperCount === 0);
                $isDismissed = isset($dismissedSet[$pid]);
                $isUnreviewed = $isUngrantedToHumanRoles && !$isDismissed;

                if ($isUngrantedToHumanRoles) $ungrantedCount++;
                if ($isUnreviewed)            $unreviewedCount++;

                $out[] = [
                    'id'                       => $pid,
                    'code'                     => $p['code'],
                    'name'                     => $p['name'],
                    'category'                 => $p['category'],
                    'description'              => $p['description'],
                    'deprecated_alias_of'      => $p['deprecated_alias_of'],
                    'roles_granted'            => $rolesGranted,
                    'granted_count'            => count($rolesGranted),
                    'ungranted_to_human_roles' => $isUngrantedToHumanRoles,
                    'dismissed'                => $isDismissed,
                    'unreviewed'               => $isUnreviewed,
                ];
            }
            json_response([
                'permissions' => $out,
                'summary' => [
                    'total'                    => count($perms),
                    'ungranted_to_human_roles' => $ungrantedCount,
                    'dismissed'                => count($dismissedSet),
                    'unreviewed'               => $unreviewedCount,
                ],
            ]);
        } catch (Exception $e) {
            json_error($e->getMessage(), 500);
        }
    }

    // List all permissions grouped by category
    if (!empty($_GET['permissions'])) {
        try {
            $rows = db_fetch_all("SELECT * FROM " . db_table('permissions') . " {$RBAC_CANON_WHERE} ORDER BY category, code");
        } catch (Exception $e) {
            $rows = [];
        }
        // Group by category
        $grouped = [];
        foreach ($rows as $r) {
            $cat = $r['category'];
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $grouped[$cat][] = $r;
        }
        json_response(['permissions' => $rows, 'grouped' => $grouped]);
    }

    // Get user's grants with full scope detail (rbac-redesign-2026-05)
    if (!empty($_GET['user_id']) && !empty($_GET['grants'])) {
        $uid = intval($_GET['user_id']);
        $includeExpired = !empty($_GET['include_expired']);
        try {
            $grants = rbac_user_grants($uid, $includeExpired);
        } catch (Throwable $e) {
            $grants = [];
        }
        json_response(['user_id' => $uid, 'grants' => $grants]);
    }

    // Get user's roles (legacy view — assignment_id + org_id only)
    if (!empty($_GET['user_id'])) {
        $uid = intval($_GET['user_id']);
        try {
            $roles = db_fetch_all(
                "SELECT ur.id AS assignment_id, ur.org_id, ur.scope_kind, ur.scope_id,
                        ur.expires_at, r.id AS role_id, r.name, r.description
                 FROM " . db_table('user_roles') . " ur
                 JOIN " . db_table('roles') . " r ON ur.role_id = r.id
                 WHERE ur.user_id = ?
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.sort_order",
                [$uid]
            );
        } catch (Exception $e) {
            $roles = [];
        }
        json_response(['user_id' => $uid, 'roles' => $roles]);
    }

    // Get single role with permissions
    if (!empty($_GET['role_id'])) {
        $roleId = intval($_GET['role_id']);
        try {
            $role = db_fetch_one("SELECT * FROM " . db_table('roles') . " WHERE id = ?", [$roleId]);
        } catch (Exception $e) {
            json_error('Role not found', 404);
        }
        if (!$role) json_error('Role not found', 404);

        // Get all permissions with checked status for this role
        try {
            // GH #77 (a beta tester) — GROUP BY p.id so a permission granted by
            // DUPLICATE role_permissions rows collapses to ONE row. Older
            // installs accumulate duplicate grants from re-runnable seeds; the
            // earlier fix only deduped the permissions table, not
            // role_permissions, so the per-role editor still rendered "two of
            // every permission". MAX(rp.role_id) keeps `granted` correct.
            //
            // RBAC-v2 (run_rbac_v2.php A8) is the real doubling source: it
            // added a canonical `resource.verb` code aliased to each old
            // `screen./action.` code via deprecated_alias_of. We list CANONICAL
            // rows only ({$RBAC_CANON_WHERE_P}), but a role's grant may have
            // historically landed on the OLD (alias) permission id. So `granted`
            // must be TRUE if the role holds a grant on the canonical row OR on
            // any alias that points to it (deprecated_alias_of stores the
            // canonical CODE). Otherwise the checkbox would read "off" while
            // rbac_can(old_code) — which resolves alias->canonical — reads on.
            // No data migration: the next Save() full-replaces this role's
            // grants with canonical ids, consolidating naturally over time.
            if ($rbacAliasCol) {
                $perms = db_fetch_all(
                    "SELECT p.*, IF(MAX(rp.role_id) IS NOT NULL, 1, 0) AS granted
                     FROM " . db_table('permissions') . " p
                     LEFT JOIN " . db_table('permissions') . " ap
                        ON (ap.id = p.id OR ap.`deprecated_alias_of` = p.code)
                     LEFT JOIN " . db_table('role_permissions') . " rp
                        ON rp.permission_id = ap.id AND rp.role_id = ?
                     WHERE p.`deprecated_alias_of` IS NULL
                     GROUP BY p.id
                     ORDER BY p.category, p.code",
                    [$roleId]
                );
            } else {
                $perms = db_fetch_all(
                    "SELECT p.*, IF(MAX(rp.role_id) IS NOT NULL, 1, 0) AS granted
                     FROM " . db_table('permissions') . " p
                     LEFT JOIN " . db_table('role_permissions') . " rp
                        ON p.id = rp.permission_id AND rp.role_id = ?
                     GROUP BY p.id
                     ORDER BY p.category, p.code",
                    [$roleId]
                );
            }
        } catch (Exception $e) {
            $perms = [];
        }

        // Users with this role.
        //
        // 2026-06-11 (Phase 10b bug-fix): join `user` and filter
        // expires_at to mirror the canonical rbac_user_roles() query in
        // inc/rbac.php — was counting orphans (whose user was deleted)
        // and expired time-bound grants. Caught by Eric 2026-06-11
        // when a deleted-user grant kept the Dispatcher count at 2
        // even though only one real user (demo) holds it.
        //
        // 2026-06-30 (Eric beta) — extended to also RETURN the user
        // list itself. roles.js used to scan its locally-fetched
        // user table for `state.users[j].role_id === this_role.id`,
        // but that user-list query (api/config-admin.php) returns
        // each user's MOST RECENT grant only. A user with multiple
        // grants (e.g. Super Admin granted 2026-06-22 + Dispatcher
        // granted 2026-06-21) would appear in Dispatcher's count
        // but NOT in Dispatcher's list — the bug Eric caught on
        // /roles.php. Returning the canonical list here makes the
        // count and the list consistent by construction.
        $assignedUsers = [];
        try {
            $assignedUsers = db_fetch_all(
                "SELECT DISTINCT u.id, u.user,
                        ur.granted_at, ur.expires_at,
                        ur.scope_kind, ur.scope_id
                 FROM " . db_table('user_roles') . " ur
                 INNER JOIN " . db_table('user') . " u ON u.id = ur.user_id
                 WHERE ur.role_id = ?
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY u.user",
                [$roleId]
            );
        } catch (Exception $e) {}

        json_response([
            'role'           => $role,
            'permissions'    => $perms,
            'assigned_users' => $assignedUsers,
            'user_count'     => count($assignedUsers),
        ]);
    }

    // List all roles.
    //
    // 2026-06-11 (Phase 10b bug-fix): user_count was counting orphans
    // (user_roles rows whose user was deleted) and expired time-bound
    // grants. INNER JOIN `user` excludes orphans; expires_at filter
    // excludes expired grants. Matches the canonical rbac_user_roles()
    // query in inc/rbac.php so the roles page agrees with the actual
    // permission resolution.
    try {
        $rows = db_fetch_all(
            "SELECT r.*,
                    (SELECT COUNT(*)
                       FROM " . db_table('role_permissions') . " rp
                      WHERE rp.role_id = r.id) AS perm_count,
                    (SELECT COUNT(DISTINCT ur.user_id)
                       FROM " . db_table('user_roles') . " ur
                       INNER JOIN " . db_table('user') . " u ON u.id = ur.user_id
                      WHERE ur.role_id = r.id
                        AND (ur.expires_at IS NULL OR ur.expires_at > NOW())) AS user_count
             FROM " . db_table('roles') . " r
             ORDER BY r.sort_order, r.name"
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['roles' => $rows]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
    // Phase 99u-2 followup (Eric beta 2026-06-30): $prefix wasn't defined
    // at this scope, so anything using `{$prefix}roles` upstream of the
    // legacy line-521 init was silently building queries against an
    // unprefixed table name. Harmless on installs with no prefix, broken
    // elsewhere. Define it once at the top of the POST block.
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // ── Save role ──
    if ($action === 'save_role') {
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Role name is required');
        $id = intval($input['id'] ?? 0);
        $desc = trim($input['description'] ?? '');
        $orgId = !empty($input['org_id']) ? intval($input['org_id']) : null;
        $isDefault = intval($input['is_default'] ?? 0);
        $sortOrder = intval($input['sort_order'] ?? 0);
        // Phase 11d (2026-06-11): explicit "send users to mobile.php on
        // login" flag, supersedes the hardcoded role_id=6 check in
        // login.php. Only honored when the column exists (post-Phase-11d).
        $mobileFirstSent = array_key_exists('mobile_first', $input);
        $mobileFirst = $mobileFirstSent ? (int) (!empty($input['mobile_first']) ? 1 : 0) : null;

        try {
            if ($id > 0) {
                db_query(
                    "UPDATE " . db_table('roles') . " SET name = ?, description = ?, org_id = ?, is_default = ?, sort_order = ? WHERE id = ?",
                    [$name, $desc, $orgId, $isDefault, $sortOrder, $id]
                );
                audit_log('admin', 'update', 'role', $id, "Updated role '{$name}'");
            } else {
                db_query(
                    "INSERT INTO " . db_table('roles') . " (name, description, org_id, is_default, sort_order) VALUES (?, ?, ?, ?, ?)",
                    [$name, $desc, $orgId, $isDefault, $sortOrder]
                );
                $id = db_insert_id();
                audit_log('admin', 'create', 'role', $id, "Created role '{$name}'");
            }
            // Phase 11d: persist mobile_first if the caller sent it.
            // Wrapped in its own try/catch because the column is
            // Phase-11d-only — older installs would error.
            if ($mobileFirst !== null && $id > 0) {
                try {
                    db_query(
                        "UPDATE " . db_table('roles') . " SET mobile_first = ? WHERE id = ?",
                        [$mobileFirst, $id]
                    );
                } catch (Exception $eMf) { /* pre-Phase-11d column missing */ }
            }
        } catch (Exception $e) {
            json_error('Failed to save role: ' . $e->getMessage());
        }
        json_response(['success' => true, 'id' => $id]);
    }

    // ── Delete role ──
    if ($action === 'delete_role') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');

        // Phase 11c (2026-06-11): per Eric — every role is editable AND
        // deletable. The previous is_system / id<=6 guard is gone. The
        // ONLY hard block is the lockout-safety check: refuse if
        // deleting this role would leave the install with zero users
        // having a super-admin role. Without this guard the admin can
        // delete their own only path to administrative access.
        $role = db_fetch_one(
            "SELECT name, is_super FROM " . db_table('roles') . " WHERE id = ?",
            [$id]
        );
        if (!$role) json_error('Unknown role', 404);

        // Lockout safety: if this role grants is_super, check whether
        // any OTHER super-granting role has at least one active user.
        if ((int) ($role['is_super'] ?? 0) === 1) {
            $otherSuperUsers = (int) db_fetch_value(
                "SELECT COUNT(DISTINCT ur.user_id)
                 FROM " . db_table('user_roles') . " ur
                 JOIN " . db_table('roles') . " r ON r.id = ur.role_id
                 JOIN " . db_table('user') . " u ON u.id = ur.user_id
                 WHERE r.is_super = 1
                   AND r.id <> ?
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
                [$id]
            );
            if ($otherSuperUsers === 0) {
                json_error(
                    'Refusing to delete the only role granting super-admin '
                    . 'access. Create a replacement super-admin role first '
                    . '(or grant another user a super-admin role), then '
                    . 'delete this one.'
                );
            }
        }

        try {
            db_query("DELETE FROM " . db_table('role_permissions') . " WHERE role_id = ?", [$id]);
            db_query("DELETE FROM " . db_table('user_roles') . " WHERE role_id = ?", [$id]);
            db_query("DELETE FROM " . db_table('roles') . " WHERE id = ?", [$id]);
            audit_log('admin', 'delete', 'role', $id, "Deleted role '" . $role['name'] . "'");
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Set permissions for a role (full replacement) ──
    if ($action === 'set_permissions') {
        $roleId = intval($input['role_id'] ?? 0);
        if (!$roleId) json_error('role_id required');

        $permIds = $input['permission_ids'] ?? [];
        if (!is_array($permIds)) json_error('permission_ids must be an array');

        try {
            // Clear existing
            db_query("DELETE FROM " . db_table('role_permissions') . " WHERE role_id = ?", [$roleId]);

            // Insert new
            foreach ($permIds as $pid) {
                $pid = intval($pid);
                if ($pid > 0) {
                    db_query(
                        "INSERT IGNORE INTO " . db_table('role_permissions') . " (role_id, permission_id) VALUES (?, ?)",
                        [$roleId, $pid]
                    );
                }
            }
            $count = count($permIds);
            audit_log('admin', 'update', 'role_permissions', $roleId, "Set {$count} permissions for role #{$roleId}");
        } catch (Exception $e) {
            json_error('Failed to set permissions: ' . $e->getMessage());
        }
        json_response(['success' => true, 'count' => count($permIds)]);
    }

    // ── Phase 99u-2 (a beta tester/Eric beta 2026-06-29) — per-cell
    // grant toggle used by the permissions matrix. Cleaner audit
    // log than set_permissions ("Granted permission 'X' to role
    // 'Y'") and a single round-trip per cell click. Idempotent.
    // System roles are rejected — they're seed-managed. ──
    if ($action === 'set_role_permission') {
        $roleId = intval($input['role_id'] ?? 0);
        $permId = intval($input['permission_id'] ?? 0);
        $grant  = !empty($input['grant']);
        if (!$roleId || !$permId) json_error('role_id and permission_id required');
        try {
            // Phase 99u-2 followup (Eric beta 2026-06-30): gate on
            // is_super, not is_system. Admins legitimately need to edit
            // system roles like Operator / Dispatcher / Org Admin —
            // those are the admin-configurable defaults, not immutable
            // seed roles. Only Super Admin (is_super=1) is protected,
            // because revoking permissions from Super Admin can lock
            // every user out of the system.
            $role = db_fetch_one("SELECT name, is_system, is_super FROM `{$prefix}roles` WHERE id = ?", [$roleId]);
            if (!$role) json_error('role not found', 404);
            if ((int) $role['is_super'] === 1) json_error('cannot edit Super Admin grants', 403);
            $perm = db_fetch_one("SELECT code, name FROM `{$prefix}permissions` WHERE id = ?", [$permId]);
            if (!$perm) json_error('permission not found', 404);
            if ($grant) {
                db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)",
                    [$roleId, $permId]);
                audit_log('config', 'update', 'role_permissions', $roleId,
                    "Granted permission '{$perm['code']}' to role '{$role['name']}'",
                    ['permission_id' => $permId, 'permission_code' => $perm['code'], 'role_id' => $roleId]);
            } else {
                db_query("DELETE FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
                    [$roleId, $permId]);
                audit_log('config', 'update', 'role_permissions', $roleId,
                    "Revoked permission '{$perm['code']}' from role '{$role['name']}'",
                    ['permission_id' => $permId, 'permission_code' => $perm['code'], 'role_id' => $roleId]);
            }
            json_response(['success' => true, 'role_id' => $roleId, 'permission_id' => $permId, 'granted' => $grant]);
        } catch (Exception $e) {
            json_error('toggle failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Phase 99u-1 (a beta tester/Eric beta 2026-06-29) — permission-audit
    // dismissal endpoints. Acknowledge a permission as intentionally
    // un-granted to all non-system roles; banner stops counting it.
    // The dismissal itself is provenance-free (no reason field per
    // Eric's call); the audit_log entry below records WHO did it,
    // which is sufficient context. ──
    if ($action === 'dismiss_permission') {
        $pid = intval($input['permission_id'] ?? 0);
        if (!$pid) json_error('permission_id required');
        try {
            $perm = db_fetch_one("SELECT code, name FROM `{$prefix}permissions` WHERE id = ?", [$pid]);
            if (!$perm) json_error('permission not found', 404);
            rbac_dismiss_permission($pid, (int) $current_user_id);
            audit_log('config', 'update', 'permission', $pid,
                "Dismissed permission '{$perm['code']}' from audit (intentionally un-granted)",
                ['permission_code' => $perm['code'], 'permission_name' => $perm['name']]);
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('dismiss failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'undismiss_permission') {
        $pid = intval($input['permission_id'] ?? 0);
        if (!$pid) json_error('permission_id required');
        try {
            $perm = db_fetch_one("SELECT code, name FROM `{$prefix}permissions` WHERE id = ?", [$pid]);
            if (!$perm) json_error('permission not found', 404);
            rbac_undismiss_permission($pid);
            audit_log('config', 'update', 'permission', $pid,
                "Re-opened review of permission '{$perm['code']}' (back in audit count)",
                ['permission_code' => $perm['code'], 'permission_name' => $perm['name']]);
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('undismiss failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Assign role to user (legacy alias of grant_role with scope=global/org) ──
    if ($action === 'assign_role') {
        $userId = intval($input['user_id'] ?? 0);
        $roleId = intval($input['role_id'] ?? 0);
        if (!$userId || !$roleId) json_error('user_id and role_id required');
        $orgId = !empty($input['org_id']) ? intval($input['org_id']) : null;
        $scopeKind = $orgId ? 'org' : 'global';
        try {
            $gid = rbac_grant_role($userId, $roleId, $scopeKind, $orgId, null, 'legacy assign_role', $current_user_id);
            json_response(['success' => true, 'grant_id' => $gid]);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 403);
        }
    }

    // ── Grant role with full scope/expiry (new in rbac-redesign-2026-05) ──
    if ($action === 'grant_role') {
        $userId    = intval($input['user_id'] ?? 0);
        $roleId    = intval($input['role_id'] ?? 0);
        $scopeKind = (string) ($input['scope_kind'] ?? 'global');
        $scopeId   = isset($input['scope_id']) && $input['scope_id'] !== '' ? intval($input['scope_id']) : null;
        $expiresAt = !empty($input['expires_at']) ? (string) $input['expires_at'] : null;
        $reason    = !empty($input['reason']) ? (string) $input['reason'] : null;
        $delegBy   = isset($input['delegated_by']) && $input['delegated_by'] !== '' ? intval($input['delegated_by']) : null;
        // Delegation depth: a new delegate grant sits one level below the
        // delegating user's own grant of this role. If they hold it directly
        // (or not via delegation) that's depth 0 -> the new grant is depth 1.
        // A delegate-of-a-delegate is depth 2, which rbac_grant_role() rejects
        // once it exceeds rbac.delegation_max_depth. Defensive: NULL MAX -> 0.
        $delegDepth = 0;
        if ($scopeKind === 'delegate' && $delegBy) {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            try {
                $parentDepth = (int) (db_fetch_value(
                    "SELECT MAX(`delegation_depth`) FROM `{$prefix}user_roles`
                     WHERE `user_id` = ? AND `role_id` = ? AND `scope_kind` = 'delegate'
                       AND (`expires_at` IS NULL OR `expires_at` > NOW())",
                    [$delegBy, $roleId]
                ) ?? 0);
            } catch (Throwable $e) {
                $parentDepth = 0;
            }
            $delegDepth = $parentDepth + 1;
        }
        try {
            $gid = rbac_grant_role($userId, $roleId, $scopeKind, $scopeId, $expiresAt, $reason, $current_user_id, $delegBy, $delegDepth);
            json_response(['success' => true, 'grant_id' => $gid]);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    // ── Remove role from user (legacy alias of revoke_grant) ──
    if ($action === 'remove_role') {
        $id     = intval($input['assignment_id'] ?? 0);
        $userId = intval($input['user_id'] ?? 0);
        $roleId = intval($input['role_id'] ?? 0);
        if (!$id && (!$userId || !$roleId)) {
            json_error('Provide assignment_id or user_id + role_id');
        }
        try {
            if (!$id) {
                $row = db_fetch_one(
                    "SELECT id FROM " . db_table('user_roles') .
                    " WHERE user_id = ? AND role_id = ? LIMIT 1",
                    [$userId, $roleId]
                );
                if (empty($row)) json_error('Grant not found', 404);
                $id = (int) $row['id'];
            }
            rbac_revoke_grant($id, 'legacy remove_role', $current_user_id);
            json_response(['success' => true]);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 403);
        }
    }

    // ── Revoke a specific grant by id ──
    if ($action === 'revoke_grant') {
        $gid    = intval($input['grant_id'] ?? 0);
        $reason = !empty($input['reason']) ? (string) $input['reason'] : null;
        if (!$gid) json_error('grant_id required');
        try {
            rbac_revoke_grant($gid, $reason, $current_user_id);
            json_response(['success' => true]);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 403);
        }
    }

    // ── Cron-style sweep — admin only ──
    if ($action === 'expire_due_grants') {
        try {
            $count = rbac_expire_due_grants();
            json_response(['success' => true, 'expired' => $count]);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 500);
        }
    }

    // ── Migrate legacy levels to RBAC ──
    if ($action === 'migrate_levels') {
        $levelToRole = [
            0 => 1,  // Super → Super Admin
            1 => 2,  // Admin → Org Admin
            2 => 3,  // Operator → Dispatcher
            3 => 5,  // Guest → Read-Only
            4 => 6,  // Unit → Field Unit
            5 => 5,  // Stats → Read-Only
            6 => 5,  // Service → Read-Only
            7 => 5,  // Facility → Read-Only
            8 => 5,  // Member → Read-Only
        ];

        $migrated = 0;
        try {
            $users = db_fetch_all("SELECT id, level FROM " . db_table('user'));
            foreach ($users as $u) {
                $level = (int) $u['level'];
                $roleId = $levelToRole[$level] ?? 5;
                // Only add if not already assigned
                $existing = db_fetch_all(
                    "SELECT id FROM " . db_table('user_roles') . " WHERE user_id = ? AND role_id = ?",
                    [(int) $u['id'], $roleId]
                );
                if (empty($existing)) {
                    db_query(
                        "INSERT INTO " . db_table('user_roles') . " (user_id, role_id) VALUES (?, ?)",
                        [(int) $u['id'], $roleId]
                    );
                    $migrated++;
                }
            }
            audit_log('admin', 'migrate', 'rbac', null, "Migrated {$migrated} users from legacy levels to RBAC roles");
        } catch (Exception $e) {
            json_error('Migration failed: ' . $e->getMessage());
        }
        json_response(['success' => true, 'migrated' => $migrated, 'total_users' => count($users ?? [])]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
