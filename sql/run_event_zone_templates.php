<?php
/**
 * Migration: Phase 109 Slice D — event zone templates.
 *
 * The July event has the same zones every year. Save an event's zone set as a
 * named template so next year is one click to set up (optionally tied to an
 * incident type). Pairs with the geo_json geometry already on event_zones.
 *
 * Safety: idempotent. CREATE TABLE IF NOT EXISTS. Touches nothing else.
 *
 * Usage: php sql/run_event_zone_templates.php  (auto-run by run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 109 Slice D — event_zone_templates\n";

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}event_zone_templates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL,
            `incident_type_id` INT NULL,
            `zones_json` MEDIUMTEXT NOT NULL,
            `created_by` INT NULL,
            `created_at` DATETIME NOT NULL,
            KEY `type_idx` (`incident_type_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "[OK] event_zone_templates table ready\n";
} catch (Throwable $e) {
    if (stripos($e->getMessage(), 'already exists') !== false) {
        echo "[--] event_zone_templates already present\n";
    } else {
        echo "[WARN] event_zone_templates: " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
