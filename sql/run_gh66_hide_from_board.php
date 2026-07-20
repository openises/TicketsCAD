<?php
/**
 * GH #66 (a beta tester / Eric decision 2026-07-08) — per-status "Hide from
 * Boards" flag.
 *
 * a beta tester runs ~680 responders; most are off-shift at any moment and
 * drown the live displays. New un_status.hide_from_board flag: units
 * whose CURRENT status has it set are filtered out of the situation
 * screen's Units tab and the dashboard Units/Responders widget listing.
 * Dispatch pickers, roster pages, and unit management are deliberately
 * unaffected — the flag hides units from the BOARDS, it doesn't make
 * them undispatchable.
 *
 * Idempotent — guarded ALTER, no values seeded (operator opts statuses in).
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #66 — un_status.hide_from_board\n";
echo "==================================\n\n";

try {
    $has = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'hide_from_board'",
        [$prefix . 'un_status']
    );
    if ($has) {
        echo "skip: hide_from_board present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}un_status`
                  ADD COLUMN `hide_from_board` TINYINT(1) NOT NULL DEFAULT 0
                  COMMENT 'Units in this status are hidden from situation/units-widget listings'");
        echo "added: un_status.hide_from_board\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
