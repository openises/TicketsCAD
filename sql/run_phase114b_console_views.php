<?php
/**
 * Phase 114b migration — console views (designer-authored layouts)
 *
 * Two tables (specs/phase-114-audio-matrix/console-designer.md §4):
 *
 *   console_views       — named layouts shown as tabs on console.php.
 *                         owner_user_id NULL = shared (admin-authored);
 *                         non-NULL = personal clone (slice b3). rbac_json
 *                         and is_default_for_json are reserved for b3
 *                         (role visibility + per-role default view).
 *
 *   console_view_strips — ordered strips per view. overrides_json holds
 *                         presentation overrides (label, short_label,
 *                         color, ptt_color, ptt_mode); controls_json an
 *                         ordered list of enabled palette controls.
 *
 * Idempotent — picked up automatically by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 114b — console views\n";
echo "==========================\n\n";

$stmts = [
    "CREATE TABLE IF NOT EXISTS `{$prefix}console_views` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `name`               VARCHAR(80) NOT NULL,
        `icon`               VARCHAR(48) DEFAULT NULL,
        `owner_user_id`      INT DEFAULT NULL,
        `based_on_view_id`   INT DEFAULT NULL,
        `rbac_json`          TEXT DEFAULT NULL,
        `is_default_for_json` TEXT DEFAULT NULL,
        `sort_order`         INT NOT NULL DEFAULT 100,
        `created_by`         INT DEFAULT NULL,
        `created_at`         DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         DATETIME DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_owner_sort` (`owner_user_id`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `{$prefix}console_view_strips` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `view_id`        INT NOT NULL,
        `channel_id`     INT NOT NULL,
        `position`       INT NOT NULL DEFAULT 0,
        `width`          TINYINT NOT NULL DEFAULT 1,
        `overrides_json` TEXT DEFAULT NULL,
        `controls_json`  TEXT DEFAULT NULL,
        KEY `idx_view_pos` (`view_id`, `position`),
        KEY `idx_channel` (`channel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($stmts as $i => $sql) {
    echo "[" . ($i + 1) . "/" . count($stmts) . "] ";
    try {
        db_query($sql);
        $first = trim(strtok($sql, "\n"));
        echo "OK: " . substr($first, 0, 60) . "...\n";
    } catch (Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
