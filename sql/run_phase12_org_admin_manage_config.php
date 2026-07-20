<?php
/**
 * Phase 12 — grant action.manage_config to Org Admin role.
 *
 * Phase 12's is_admin() helper recognizes a user as admin iff:
 *   - any active role grant is on a role with is_super=1, OR
 *   - the user holds the action.manage_config permission.
 *
 * The 6 system roles backfilled in Phase 11 left Org Admin (role 2)
 * without action.manage_config, even though under the legacy model
 * Administrator-tier users (level 1) had full admin access. Phase 12
 * preserves that by adding action.manage_config to Org Admin.
 *
 * Idempotent — only INSERTs the role_permissions row if it isn't
 * already present.
 *
 * Usage:  php sql/run_phase12_org_admin_manage_config.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 12 — grant action.manage_config to Org Admin\n";
echo "==================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // Identify the Org Admin role by name (survives renames? Only
    // partially — but the default install has it). Fall back to id=2
    // if the name lookup misses (custom rename of the row).
    $orgAdmin = db_fetch_one(
        "SELECT id FROM `{$prefix}roles` WHERE id = 2 LIMIT 1"
    );
    if (!$orgAdmin) {
        echo "[--] Role id=2 not present; nothing to do.\n";
        exit(0);
    }
    $orgAdminId = (int) $orgAdmin['id'];

    $perm = db_fetch_one(
        "SELECT id FROM `{$prefix}permissions` WHERE code = 'action.manage_config' LIMIT 1"
    );
    if (!$perm) {
        echo "[--] permission 'action.manage_config' not found; skipped.\n";
        exit(0);
    }
    $permId = (int) $perm['id'];

    // INSERT IGNORE because role_permissions has a unique key on (role_id,
    // permission_id) in the v2 schema.
    $stmt = db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)",
        [$orgAdminId, $permId]
    );
    $inserted = $stmt ? $stmt->rowCount() : 0;
    if ($inserted > 0) {
        echo "[OK] Granted action.manage_config to role id={$orgAdminId} (Org Admin)\n";
    } else {
        echo "[OK] Grant already present — no change\n";
    }
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
