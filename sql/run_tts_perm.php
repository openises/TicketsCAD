<?php
/**
 * Phase 113 — RBAC permission `action.manage_tts`.
 *
 * Gates the Voice & Speech settings panel + all engine/application CRUD +
 * Test-Listen. Granted by default to Super Admin (1) + Org Admin (2), like
 * the other configuration permissions. Idempotent + re-asserts the grant
 * every run (self-heals a permission-exists-but-grant-missing state, since
 * the base "Super Admin gets everything" seed only ran at install).
 *
 * Usage: php sql/run_tts_perm.php  (also auto-run by run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
         VALUES (?, ?, ?, ?)",
        [
            'action.manage_tts',
            'Manage Voice & Speech',
            'action',
            'Configure text-to-speech engines and per-application voice routing',
        ]
    );
    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?", ['action.manage_tts']
    );
    if ($permId > 0) {
        foreach ([1, 2] as $roleId) {
            if (db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId])) {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            }
        }
        echo "[OK] RBAC permission action.manage_tts ready (Super Admin + Org Admin)\n";
    } else {
        echo "[WARN] action.manage_tts: could not resolve permission id\n";
    }
} catch (Exception $e) {
    echo "[WARN] action.manage_tts: " . $e->getMessage() . "\n";
}
