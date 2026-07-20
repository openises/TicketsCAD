<?php
/**
 * Phase 103 wrapper (GH #20, 2026-07-07) — facility bed-count automation
 * schema, as a REAL migration.
 *
 * sql/phase-103-facility-bed-automation.sql existed but was referenced by
 * NOTHING in the install pipeline: fresh installs never got
 * facilities.bed_auto_mode, and updated installs only run sql/run_*.php
 * files — so on any install that didn't apply the .sql by hand, the
 * "Automatic" bed mode silently couldn't be enabled (the facility save
 * swallowed the unknown-column error). This is why bed delivery "did
 * nothing" on beta installs even after the bed_delivery status flags
 * shipped.
 *
 * Idempotent — guarded ALTER + CREATE IF NOT EXISTS.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 103 — facility bed automation schema\n";
echo "===========================================\n\n";

try {
    $hasMode = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'bed_auto_mode'",
        [$prefix . 'facilities']
    );
    if ($hasMode) {
        echo "skip: facilities.bed_auto_mode present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}facilities`
                  ADD COLUMN `bed_auto_mode` VARCHAR(16) NOT NULL DEFAULT 'manual'
                  COMMENT 'Bed-count automation mode: manual | auto'");
        echo "added: facilities.bed_auto_mode\n";
    }

    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_bed_auto_log` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `assign_id`     INT NOT NULL,
        `facility_id`   INT NOT NULL,
        `responder_id`  INT NOT NULL,
        `ticket_id`     INT NOT NULL,
        `delta_a`       INT NOT NULL DEFAULT 0,
        `delta_o`       INT NOT NULL DEFAULT 0,
        `status_id`     INT NOT NULL DEFAULT 0,
        `status_val`    VARCHAR(64) DEFAULT '',
        `applied_by`    INT NOT NULL DEFAULT 0,
        `applied_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_assign_facility` (`assign_id`, `facility_id`),
        KEY `idx_facility_time` (`facility_id`, `applied_at`),
        KEY `idx_responder`     (`responder_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "ok: facility_bed_auto_log table ready\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
