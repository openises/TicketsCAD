<?php
/**
 * Migration: RBAC permission `action.bulk_delete_members`
 * (Eric request, 2026-07-04 — GH #55 follow-on)
 *
 * Bulk removal of roster members is a bigger hammer than editing/deleting
 * one member, so it gets its own permission instead of riding on
 * `action.manage_members`. Eric's requirement: do NOT expose it to every
 * administrator. Default grant is therefore Super Admin ONLY; an admin can
 * grant it to any other role from the Roles UI if they choose.
 *
 * IMPORTANT: the base "Super Admin gets EVERYTHING" seed in rbac.sql
 * (INSERT ... SELECT 1, id FROM permissions) only ran once at install, so a
 * permission added later is NOT retroactively granted to Super Admin. This
 * migration grants it explicitly.
 *
 * Safety: idempotent. INSERT IGNORE on the permission and on the grant, and
 * the grant is re-asserted every run (so a permission-exists-but-grant-missing
 * state self-heals). Touches only Super Admin (role 1) — no other role.
 *
 * 2026-07-07 heal: sql/run_00_rbac.php's Org Admin mapping used to exclude
 * only action.manage_config, so on fresh installs (rbac.sql had already
 * seeded the permission rows) it accidentally granted bulk delete to Org
 * Admin (role 2) — and run_rbac_v2's canonical-alias mirroring copied that
 * grant onto bulk_delete_members.do. This migration now REVOKES the
 * bulk-delete grant (old code + canonical alias) from the seeded default
 * roles 2 and 3, restoring Eric's Super-Admin-only default. Admins can
 * still grant it deliberately from the Roles UI afterwards — this runs
 * once per file hash, not on every request.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // 1) Ensure the permission row exists.
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
         VALUES (?, ?, ?, ?)",
        [
            'action.bulk_delete_members',
            'Bulk Delete Members',
            'action',
            'Remove multiple member records at once (roster bulk actions)',
        ]
    );

    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.bulk_delete_members']
    );

    if ($permId > 0) {
        // 2) Grant to Super Admin (role 1) ONLY. Re-assert every run so a
        //    partially-applied state repairs itself. Do NOT grant to Org
        //    Admin (2) or Dispatcher (3) — Eric wants this narrowly held.
        $superExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = 1");
        if ($superExists) {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (1, ?)",
                [$permId]
            );
        }
        // 3) Heal the run_00_rbac.php over-grant: revoke from the seeded
        //    default Org Admin (2) + Dispatcher (3) roles. Covers both the
        //    legacy code and the run_rbac_v2 canonical alias
        //    (bulk_delete_members.do). Custom roles (id > 6) are never
        //    touched — an admin may have granted those deliberately.
        $permIds = array_map('intval', array_column(db_fetch_all(
            "SELECT id FROM `{$prefix}permissions`
             WHERE `code` IN ('action.bulk_delete_members', 'bulk_delete_members.do')"
        ), 'id'));
        $revoked = 0;
        foreach ($permIds as $pid) {
            $stmt = db_query(
                "DELETE FROM `{$prefix}role_permissions` WHERE `role_id` IN (2, 3) AND `permission_id` = ?",
                [$pid]
            );
            $revoked += $stmt ? $stmt->rowCount() : 0;
        }

        echo "[OK] RBAC permission action.bulk_delete_members ready (granted to Super Admin only"
            . ($revoked > 0 ? ", revoked $revoked stray default-role grant(s)" : '') . ")\n";
    } else {
        echo "[WARN] action.bulk_delete_members: could not resolve permission id\n";
    }
} catch (Exception $e) {
    // permissions/role_permissions tables might not exist yet (RBAC not installed).
    echo "[WARN] action.bulk_delete_members: " . $e->getMessage() . "\n";
}
