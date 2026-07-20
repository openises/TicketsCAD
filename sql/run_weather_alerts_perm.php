<?php
/**
 * Migration: RBAC permission `action.manage_weather_alerts` (Phase 112)
 *
 * Gates the weather-alert settings panel + all area/rule CRUD. Granted by
 * default to Super Admin (role 1) AND Org Admin (role 2) — configuring weather
 * coverage is a normal administrative task, unlike the narrowly-held bulk
 * delete. An admin can grant it to any other role from the Roles UI.
 *
 * NOTE: the amateur-radio DMR read-out (Phase 3) is ADDITIONALLY gated by the
 * existing radio-TX permission; this permission only governs configuration.
 *
 * IMPORTANT: the base "Super Admin gets EVERYTHING" seed in rbac.sql only ran
 * once at install, so a permission added later is NOT retroactively granted.
 * This migration grants it explicitly, and re-asserts every run (self-heals a
 * permission-exists-but-grant-missing state).
 *
 * Safety: idempotent. INSERT IGNORE on the permission + grants.
 *
 * Usage: php sql/run_weather_alerts_perm.php   (also auto-run by run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
         VALUES (?, ?, ?, ?)",
        [
            'action.manage_weather_alerts',
            'Manage Weather Alerts',
            'action',
            'Configure NWS weather-alert coverage areas, routing rules, and read-out settings',
        ]
    );

    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_weather_alerts']
    );

    if ($permId > 0) {
        // Grant to Super Admin (1) + Org Admin (2). Re-assert every run.
        foreach ([1, 2] as $roleId) {
            $roleExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId]);
            if ($roleExists) {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            }
        }
        echo "[OK] RBAC permission action.manage_weather_alerts ready (Super Admin + Org Admin)\n";
    } else {
        echo "[WARN] action.manage_weather_alerts: could not resolve permission id\n";
    }
} catch (Exception $e) {
    echo "[WARN] action.manage_weather_alerts: " . $e->getMessage() . "\n";
}
