<?php
/**
 * GH#102 (openises/TicketsCAD issue #102, rjonesbsink) — facility
 * self-release schema, as a REAL migration.
 *
 * `facility_bed_auto_log` (Phase 103) only ever records automatic
 * decrements. This adds its release-side counterpart,
 * `facility_bed_release_log`, so a facility account's self-release
 * (inc/facility-bed-release.php) has a durable, attributable audit
 * trail distinct from an automatic decrement or a dispatcher's manual
 * edit — surfaced by api/reports.php's new `facility_bed_adjustments`
 * report.
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS. The same DDL is also
 * self-healed inline by inc/facility-bed-release.php (matching
 * api/facility-portal.php's own established defensive pattern for
 * capacity_categories/facility_capacity), so an install that hasn't run
 * migrations yet still works — this migration is the canonical,
 * schema-manifest-tracked source.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH#102 — facility bed self-release schema\n";
echo "===========================================\n\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_bed_release_log` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `facility_id`      INT NOT NULL,
        `delta_a`          INT NOT NULL DEFAULT 0,
        `delta_o`          INT NOT NULL DEFAULT 0,
        `note`             VARCHAR(500) DEFAULT '',
        `released_by`      INT NOT NULL DEFAULT 0,
        `released_by_name` VARCHAR(191) DEFAULT '',
        `applied_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_facility_time` (`facility_id`, `applied_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "ok: facility_bed_release_log table ready\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
