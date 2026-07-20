<?php
/**
 * Phase 37 — Per-role session timeout migration.
 *
 *   - Adds `session_timeout_minutes` (nullable INT) to roles.
 *   - Backfills from the hard-coded legacy `timeout_role_*` settings.
 *   - Deletes the legacy setting rows.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 37 — Per-role session timeouts\n";
echo "====================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// 1. Add column if not present
try {
    $has = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?",
        [$prefix . 'roles', 'session_timeout_minutes']
    );
    if (!$has) {
        db_query("ALTER TABLE `{$prefix}roles`
                  ADD COLUMN `session_timeout_minutes` INT NULL DEFAULT NULL
                  COMMENT 'NULL = use system default from settings.session_timeout_minutes'
                  AFTER `description`");
        echo "[OK] Added roles.session_timeout_minutes column.\n";
    } else {
        echo "[skip] roles.session_timeout_minutes already exists.\n";
    }
} catch (Exception $e) {
    echo "[ERR] Could not add column: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Backfill from legacy timeout_role_* settings — match by role.name (case-insensitive)
$legacy = [
    'super'    => 'timeout_role_super',
    'admin'    => 'timeout_role_admin',
    'operator' => 'timeout_role_operator',
    'guest'    => 'timeout_role_guest',
    'member'   => 'timeout_role_member',
    'unit'     => 'timeout_role_unit',
];

$migrated = 0;
foreach ($legacy as $needle => $key) {
    try {
        $val = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = ?", [$key]);
        if ($val === null || $val === '') continue;
        $minutes = max(0, (int) $val);
        if ($minutes === 0) continue;

        $affected = db_query(
            "UPDATE `{$prefix}roles`
                SET session_timeout_minutes = ?
              WHERE LOWER(name) LIKE ?
                AND session_timeout_minutes IS NULL",
            [$minutes, '%' . strtolower($needle) . '%']
        )->rowCount();

        if ($affected > 0) {
            $migrated += $affected;
            echo "[OK] Backfilled \"{$key}\" = {$minutes}m -> {$affected} role row(s) matching '{$needle}'.\n";
        }
    } catch (Exception $e) {
        echo "[WARN] {$key}: " . $e->getMessage() . "\n";
    }
}

if ($migrated === 0) {
    echo "[skip] No legacy timeout_role_* rows found to backfill (this is fine).\n";
}

// 3. Delete legacy setting rows (UI no longer references them after Phase 37)
$deleted = 0;
foreach (array_values($legacy) as $key) {
    try {
        $deleted += db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$key])->rowCount();
    } catch (Exception $e) {
        echo "[WARN] delete {$key}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] Removed {$deleted} legacy timeout_role_* setting row(s).\n";

echo "\nDone.\n";
