<?php
/**
 * Run Geofences — Create geofencing schema tables.
 *
 * Purpose:  Creates geofences and geofence_events tables for geographic
 *           boundary monitoring. Links to map_markups for geometry data.
 * Usage:    php sql/run_geofences.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   [OK]/[WARN] per table creation.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Geofencing Schema Setup ===\n\n";

// ── geofences table ──────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}geofences` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `markup_id`           INT          NOT NULL COMMENT 'FK to map_markups.id',
        `name`                VARCHAR(128) NOT NULL,
        `active`              TINYINT(1)   NOT NULL DEFAULT 1,
        `alert_on_enter`      TINYINT(1)   NOT NULL DEFAULT 1,
        `alert_on_exit`       TINYINT(1)   NOT NULL DEFAULT 1,
        `alert_channels_json` TEXT         DEFAULT NULL COMMENT 'JSON array of broker channel codes',
        `notify_users_json`   TEXT         DEFAULT NULL COMMENT 'JSON array of user IDs to notify',
        `created_by`          INT          NOT NULL DEFAULT 0,
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_markup_id` (`markup_id`),
        KEY `idx_active`    (`active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] geofences table\n";
} catch (Exception $e) {
    echo "[WARN] geofences: " . $e->getMessage() . "\n";
}

// ── geofence_unit_state table ────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}geofence_unit_state` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `geofence_id`     INT          NOT NULL,
        `unit_identifier` VARCHAR(128) NOT NULL,
        `state`           ENUM('inside','outside') NOT NULL DEFAULT 'outside',
        `entered_at`      DATETIME     DEFAULT NULL,
        `exited_at`       DATETIME     DEFAULT NULL,
        `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_fence_unit` (`geofence_id`, `unit_identifier`),
        KEY `idx_state` (`state`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] geofence_unit_state table\n";
} catch (Exception $e) {
    echo "[WARN] geofence_unit_state: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
