<?php
/**
 * Phase 114b-b2.5 migration — free-form strip layout
 *
 * Eric's 2026-07-07 designer feedback: strips must be sizable in BOTH
 * dimensions and components (PTT, buttons, labels) must be placeable
 * anywhere INSIDE a strip on a fine grid — draw.io-style, "a grid
 * layout within a grid layout".
 *
 * Adds console_view_strips.layout_json:
 *   {"x":0,"y":0,"w":3,"h":14}  — the strip's rectangle on the view
 *   canvas (12-column outer grid, 20px rows).
 *
 * controls_json (existing column) is repurposed from a flat list of
 * control keys to an array of positioned components:
 *   [{"type":"ptt","x":0,"y":8,"w":12,"h":3,
 *     "props":{"color":"#dc3545","mode":"momentary","text":"PTT"}}, ...]
 * on the strip's inner grid (12 columns, 14px rows). Legacy flat lists
 * are converted to a default positioned set at read time by
 * api/console-views.php — no data migration needed.
 *
 * Idempotent — picked up automatically by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 114b-b2.5 — free-form strip layout\n";
echo "========================================\n\n";

// Ordering fix (2026-07-07): this file sorts BEFORE
// run_phase114b_console_views.php in the master runner ('3' < '_' in
// ASCII), so on a fresh install it used to run before console_view_strips
// existed, swallow the ALTER error, exit 0, and get recorded as applied —
// leaving every fresh install permanently without layout_json. If the
// table is missing, delegate to the 114b runner (idempotent) first, and
// treat any remaining failure as fatal so the master runner records it.
try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'console_view_strips']
    );
    if (!$tableExists) {
        echo "console_view_strips missing — running run_phase114b_console_views.php first\n";
        require __DIR__ . '/run_phase114b_console_views.php';
    }

    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'layout_json'",
        [$prefix . 'console_view_strips']
    );
    if ($exists) {
        echo "skip: layout_json already present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}console_view_strips`
                  ADD COLUMN `layout_json` TEXT DEFAULT NULL AFTER `width`");
        echo "added: console_view_strips.layout_json\n";
    }
} catch (Exception $e) {
    echo "ERR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
