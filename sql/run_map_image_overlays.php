<?php
/**
 * Run Map Image Overlays — Create the map_image_overlays table and RBAC permission.
 *
 * Phase 110 (GH #43) — Special-Event Map Image Overlay. Event organizers
 * hand agencies a venue map (booth layout, race course, parade route) as
 * a PDF or image; this feature drapes the actual artwork over the base
 * map instead of hand-tracing it with markups.
 *
 * Purpose:  Creates the map_image_overlays table (uploaded overlay images
 *           with three-corner geo-anchoring) and adds the
 *           action.manage_map_overlays RBAC permission.
 * Usage:    php sql/run_map_image_overlays.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS and existence
 *           checks before INSERT. Safe to re-run.
 * Output:   [OK]/[--] per step; [ERR] + exit(1) on failure.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Map Image Overlays Schema Setup (Phase 110 / GH #43) ===\n\n";

// ── map_image_overlays table ─────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}map_image_overlays` (
        `id`          INT NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(128) NOT NULL,
        `file_path`   VARCHAR(255) NOT NULL,
        `mime`        VARCHAR(64)  NOT NULL,
        `anchor_json` TEXT DEFAULT NULL,
        `opacity`     DECIMAL(3,2) DEFAULT 0.70,
        `enabled`     TINYINT(1)   DEFAULT 1,
        `sort_order`  INT          DEFAULT 0,
        `created_by`  INT          DEFAULT NULL,
        `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_enabled` (`enabled`),
        KEY `idx_sort`    (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] map_image_overlays table ready\n";
} catch (Exception $e) {
    echo "[ERR] map_image_overlays: " . $e->getMessage() . "\n";
    exit(1);
}

// ── RBAC permission ──────────────────────────────────────────
try {
    $exists = db_fetch_one(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_map_overlays']
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, ?, ?)",
            ['action.manage_map_overlays', 'Manage Map Image Overlays', 'action', 'Upload, position, and delete special-event map image overlays']
        );
        $permId = db_insert_id();

        // Grant to Super Admin (role 1), Org Admin (role 2), and Dispatcher (role 3)
        foreach ([1, 2, 3] as $roleId) {
            try {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            } catch (Exception $e) {
                // Role might not exist in this install
            }
        }
        echo "[OK] RBAC permission action.manage_map_overlays added (granted to Super Admin, Org Admin, Dispatcher)\n";
    } else {
        echo "[--] RBAC permission action.manage_map_overlays already exists\n";
    }
} catch (Exception $e) {
    // Permissions table might not exist yet (RBAC not installed) — not fatal;
    // the API falls back to the legacy is_admin() check in that case.
    echo "[--] RBAC skipped: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
