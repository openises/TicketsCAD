<?php
/**
 * Migration: `capacity_categories` + `facility_capacity` tables
 * (QA hardening, 2026-07-07).
 *
 * These two tables were created LAZILY by api/facility-capacity.php on
 * its first request, so a virgin install had neither until someone
 * opened the facility capacity UI — and the schema-audit gate flagged
 * every facility-capacity query as targeting a missing table.
 * Provisioning now creates them (and seeds the default categories) up
 * front. DDL and seed mirror api/facility-capacity.php exactly; the
 * lazy copy there remains as a self-heal for pre-migration installs.
 *
 * NOTE: sql/facility_beds.sql ships an OLDER, divergent design
 * (`newui_facility_capacity` with a category VARCHAR) that nothing in
 * the API layer reads — the API's category_id/uk_fac_cat schema below
 * is the live one.
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS + count-guarded seed.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}capacity_categories` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(64) NOT NULL,
        `icon`        VARCHAR(64) DEFAULT 'bi-hospital',
        `unit_label`  VARCHAR(32) DEFAULT 'beds',
        `sort_order`  INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_cap_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_capacity` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `facility_id`  INT NOT NULL,
        `category_id`  INT NOT NULL,
        `total`        INT NOT NULL DEFAULT 0,
        `available`    INT NOT NULL DEFAULT 0,
        `notes`        VARCHAR(255) DEFAULT '',
        `updated_by`   INT NOT NULL DEFAULT 0,
        `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_fac_cat` (`facility_id`, `category_id`),
        KEY `idx_facility` (`facility_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default categories (same list as api/facility-capacity.php).
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}capacity_categories`");
    if ($count === 0) {
        $cats = [
            ['ICU Beds',       'bi-heart-pulse',  'beds',     1],
            ['General Beds',   'bi-hospital',     'beds',     2],
            ['Pediatric Beds', 'bi-emoji-smile',  'beds',     3],
            ['ER Beds',        'bi-lightning',    'beds',     4],
            ['Shelter Spots',  'bi-house',        'spots',    5],
            ['Cots',           'bi-moon',         'cots',     6],
            ['Ventilators',    'bi-wind',         'units',    7],
            ['Decon Stations', 'bi-droplet',      'stations', 8],
        ];
        foreach ($cats as $c) {
            db_query(
                "INSERT IGNORE INTO `{$prefix}capacity_categories` (name, icon, unit_label, sort_order)
                 VALUES (?, ?, ?, ?)", $c
            );
        }
        echo "[OK] capacity_categories seeded (" . count($cats) . " defaults)\n";
    }
    echo "[OK] capacity_categories + facility_capacity tables ready\n";
} catch (Exception $e) {
    echo "[FAIL] facility capacity tables: " . $e->getMessage() . "\n";
    exit(1);
}
