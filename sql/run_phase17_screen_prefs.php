<?php
/**
 * Phase 17 (2026-06-11) — Per-user column preferences.
 *
 * Eric's ask: customizable visible columns, default sort, column order
 * for most screens in the UI. This migration adds the storage layer.
 *
 *   user_screen_prefs (user_id, screen) — JSON blob of per-screen prefs
 *
 * Shape of the JSON: {
 *   "columns": [
 *     {"id": "name",      "visible": true,  "pos": 0},
 *     {"id": "handle",    "visible": false, "pos": 1},
 *     ...
 *   ],
 *   "sort": {"col": "name", "dir": "asc"}
 * }
 *
 * The application layer (inc/screen-prefs.php) reads + merges with the
 * screen's default column catalog so omissions inherit defaults.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 17 — User screen prefs\n";
echo "============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $r = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'user_screen_prefs']
    );
    if (!$r) {
        db_query("
            CREATE TABLE `{$prefix}user_screen_prefs` (
                `user_id`    INT UNSIGNED NOT NULL,
                `screen`     VARCHAR(48)  NOT NULL,
                `prefs_json` TEXT         NOT NULL,
                `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `screen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[OK] Created user_screen_prefs\n";
    } else {
        echo "[OK] user_screen_prefs already exists\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
