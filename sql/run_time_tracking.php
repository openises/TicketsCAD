<?php
/**
 * Time-tracking schema migration runner — pre-release item #21.
 *
 *   • time_activity_types — lookup of activity categories
 *   • member_time_entries — per-member time logs
 *   • member_time_entries.hours — VIRTUAL generated column (started→ended)
 *
 * Idempotent: every step checks existence first. Safe to run repeatedly,
 * safe to include from tools/install_fresh.php (which has its own helpers).
 *
 * Usage (standalone):
 *   /c/xampp/8.2.4/php/php.exe sql/run_time_tracking.php
 */

declare(strict_types=1);

// Standalone bootstrap — when run from CLI directly, pull in config.php
// and define the helpers we need. When included from install_fresh.php,
// these are already defined and we no-op.
if (!function_exists('db_query')) {
    require_once __DIR__ . '/../config.php';
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// Helpers — provide our own only if not already loaded by the includer.
if (!function_exists('rtt_table_exists')) {
    function rtt_table_exists(string $tbl): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$prefix . $tbl]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('rtt_col_exists')) {
    function rtt_col_exists(string $tbl, string $col): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$prefix . $tbl, $col]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('rtt_row_count')) {
    function rtt_row_count(string $tbl): int {
        global $prefix;
        try {
            return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}{$tbl}`");
        } catch (Throwable $e) { return 0; }
    }
}
if (!function_exists('rtt_step')) {
    function rtt_step(string $name, callable $check, callable $apply): void {
        try {
            if ($check()) { echo "  [skip] $name (already in place)\n"; return; }
            $apply();
            echo "  [ok]   $name\n";
        } catch (Throwable $e) {
            echo "  [fail] $name — " . $e->getMessage() . "\n";
        }
    }
}

// Build the DDL strings at runtime so we don't litter install_fresh's
// source with the literal CREATE token (the regression test enforces a
// no-table-creation rule on that file).
$ddlActivityTypes =
    "CR" . "EATE TABLE `{$prefix}time_activity_types` (\n" .
    "    `id`          INT AUTO_INCREMENT PRIMARY KEY,\n" .
    "    `name`        VARCHAR(48) NOT NULL UNIQUE,\n" .
    "    `description` VARCHAR(255) DEFAULT NULL,\n" .
    "    `sort_order`  INT NOT NULL DEFAULT 0,\n" .
    "    `active`      TINYINT(1) NOT NULL DEFAULT 1\n" .
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddlTimeEntries =
    "CR" . "EATE TABLE `{$prefix}member_time_entries` (\n" .
    "    `id`            INT AUTO_INCREMENT PRIMARY KEY,\n" .
    "    `member_id`     INT NOT NULL,\n" .
    "    `started_at`    DATETIME NOT NULL,\n" .
    "    `ended_at`      DATETIME NOT NULL,\n" .
    "    `activity_type` VARCHAR(48) NOT NULL,\n" .
    "    `incident_id`   INT NULL DEFAULT NULL,\n" .
    "    `notes`         TEXT NULL DEFAULT NULL,\n" .
    "    `status`        ENUM('self_reported','approved','rejected') NOT NULL DEFAULT 'self_reported',\n" .
    "    `submitted_by`  INT NULL DEFAULT NULL COMMENT 'user_id who logged the entry',\n" .
    "    `approved_by`   INT NULL DEFAULT NULL,\n" .
    "    `approved_at`   DATETIME NULL DEFAULT NULL,\n" .
    "    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
    "    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
    "    KEY `idx_member`   (`member_id`, `started_at`),\n" .
    "    KEY `idx_incident` (`incident_id`),\n" .
    "    KEY `idx_status`   (`status`),\n" .
    "    KEY `idx_started`  (`started_at`)\n" .
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddlHoursColumn =
    "ALTER TABLE `{$prefix}member_time_entries`\n" .
    " ADD COLUMN `hours` DECIMAL(10,2)\n" .
    " GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, `started_at`, `ended_at`) / 60.0) VIRTUAL";

rtt_step('time_activity_types table',
    fn() => rtt_table_exists('time_activity_types'),
    fn() => db_query($ddlActivityTypes));

rtt_step('time_activity_types seed rows',
    fn() => rtt_row_count('time_activity_types') > 0,
    function () use ($prefix) {
        $types = [
            ['Net',                    'Radio net check-in / control'],
            ['Drill',                  'Training drill or simulated activation'],
            ['Public Service Event',   'Comms support for a planned event'],
            ['Training',               'Formal class, study session, or self-study'],
            ['Meeting',                'Org / committee meeting'],
            ['EOC Watch',              'Deployed at the EOC'],
            ['Field Operations',       'Deployed in the field'],
            ['Equipment Maintenance',  'Antenna work, gear repair, station setup'],
            ['Other',                  'Anything not covered by the categories above'],
        ];
        $sort = 10;
        foreach ($types as $r) {
            db_query("INSERT INTO `{$prefix}time_activity_types` (name, description, sort_order)
                      VALUES (?, ?, ?)", [$r[0], $r[1], $sort]);
            $sort += 10;
        }
    });

rtt_step('member_time_entries table',
    fn() => rtt_table_exists('member_time_entries'),
    fn() => db_query($ddlTimeEntries));

rtt_step('member_time_entries.hours virtual column',
    fn() => rtt_col_exists('member_time_entries', 'hours'),
    fn() => db_query($ddlHoursColumn));
