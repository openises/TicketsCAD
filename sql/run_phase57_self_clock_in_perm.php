<?php
/**
 * Phase 57 (2026-06-14) — RBAC: action.self_clock_in
 *
 * Adds the permission and grants it to the operationally-active roles
 * (everyone except Read-Only by default). Admins can revoke per-role
 * on the Roles & Permissions page if they want to block certain user
 * categories from self-activating as personal resources.
 *
 * Idempotent — safe to re-run.
 *
 * Run:  php sql/run_phase57_self_clock_in_perm.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

global $prefix;

echo "Phase 57 — adding action.self_clock_in permission\n";

try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`)
         VALUES ('action.self_clock_in', 'Self Clock-In as Personal Resource', 'action')"
    );
    $permId = db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE code = 'action.self_clock_in'"
    );
    echo "  permission id = " . ($permId ?: 'NULL') . "\n";
} catch (Exception $e) {
    echo "  permission insert: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$permId) { echo "  failed to resolve permission id\n"; exit(1); }

// Grant to the operationally-active legacy-level roles. Skip Read-Only
// (level 3) by name since legacy_level alone doesn't separate it from
// Operator on some installs.
$skipRoleNames = ['read-only', 'readonly', 'read only'];
$roles = db_fetch_all(
    "SELECT id, name, legacy_level FROM `{$prefix}roles` ORDER BY legacy_level"
);

$granted = 0;
$revoked = 0;
foreach ($roles as $r) {
    $nameLower = strtolower($r['name']);
    $shouldHave = !in_array($nameLower, $skipRoleNames, true);

    if ($shouldHave) {
        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)",
                [(int) $r['id'], (int) $permId]
            );
            echo "  granted to role: " . $r['name'] . " (id={$r['id']}, level={$r['legacy_level']})\n";
            $granted++;
        } catch (Exception $e) {
            echo "  grant failed for role {$r['id']}: " . $e->getMessage() . "\n";
        }
    } else {
        // Read-Only — actively revoke in case a prior buggy run granted it.
        try {
            db_query(
                "DELETE FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
                [(int) $r['id'], (int) $permId]
            );
            echo "  revoked from role: " . $r['name'] . " (id={$r['id']}, level={$r['legacy_level']})\n";
            $revoked++;
        } catch (Exception $e) {
            echo "  revoke failed for role {$r['id']}: " . $e->getMessage() . "\n";
        }
    }
}

echo "Phase 57 done. Granted to $granted role(s), revoked from $revoked.\n";
echo "Admins can revoke per-role on the Roles & Permissions page (roles.php).\n";
