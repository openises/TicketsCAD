<?php
/**
 * Phase 114b-b3 migration — personal console views
 *
 * console_views.owner_user_id and .based_on_view_id already existed in the
 * b2 schema (run_phase114b_console_views.php), reserved for this slice.
 * This migration adds the one new column b3 needs:
 *
 *   console_views.is_shared  TINYINT NOT NULL DEFAULT 0
 *     Only meaningful when owner_user_id IS NOT NULL (a personal view).
 *     0 = private to its owner. 1 = "available for others to adopt" —
 *     visible to every screen.console holder as a read-only clone source
 *     (never as a live tab forced onto anyone). See inc/console-views.php's
 *     docblock for the full sharing model.
 *
 * Idempotent — picked up automatically by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 114b-b3 — personal console views\n";
echo "=======================================\n\n";

try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'console_views']
    );
    if (!$tableExists) {
        echo "console_views missing — running run_phase114b_console_views.php first\n";
        require __DIR__ . '/run_phase114b_console_views.php';
    }

    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'is_shared'",
        [$prefix . 'console_views']
    );
    if ($exists) {
        echo "skip: is_shared already present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}console_views`
                  ADD COLUMN `is_shared` TINYINT NOT NULL DEFAULT 0 AFTER `based_on_view_id`");
        echo "added: console_views.is_shared\n";
    }

    // Helpful for console_shared_personal_views()'s browse query.
    $idxExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_owner_shared'",
        [$prefix . 'console_views']
    );
    if ($idxExists) {
        echo "skip: idx_owner_shared already present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}console_views`
                  ADD INDEX `idx_owner_shared` (`is_shared`, `owner_user_id`)");
        echo "added: index idx_owner_shared\n";
    }
} catch (Exception $e) {
    echo "ERR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
