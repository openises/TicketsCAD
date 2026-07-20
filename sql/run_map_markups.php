<?php
/**
 * Run Map Markups — Create markup tables and seed default categories.
 *
 * Purpose:  Creates markup_categories and map_markups tables for the
 *           map drawing/annotation system. Seeds default categories
 *           (Hazard Zone, Evacuation Route, Staging Area, etc.).
 * Usage:    php sql/run_map_markups.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   [OK]/[WARN] per table; seed confirmation.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Map Markups Schema Setup ===\n\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}markup_categories` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(64)  NOT NULL,
        `icon`        VARCHAR(64)  DEFAULT 'bi-geo-alt',
        `color`       VARCHAR(8)   DEFAULT '#FF0000',
        `description` VARCHAR(255) DEFAULT '',
        `sort_order`  INT          NOT NULL DEFAULT 0,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_cat_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] markup_categories table\n";
} catch (Exception $e) { echo "[WARN] " . $e->getMessage() . "\n"; }

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}map_markups` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `category_id`  INT          DEFAULT NULL,
        `name`         VARCHAR(128) NOT NULL,
        `description`  TEXT         DEFAULT NULL,
        `markup_type`  VARCHAR(32)  NOT NULL DEFAULT 'polygon',
        `geojson`      TEXT         NOT NULL,
        `style`        TEXT         DEFAULT NULL,
        `visible`      TINYINT(1)  NOT NULL DEFAULT 1,
        `ident`        VARCHAR(64)  DEFAULT '',
        `notes`        TEXT         DEFAULT NULL,
        `apply_to`     VARCHAR(64)  DEFAULT 'base_map',
        `created_by`   INT          NOT NULL DEFAULT 0,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_category` (`category_id`),
        KEY `idx_visible`  (`visible`),
        KEY `idx_apply_to` (`apply_to`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] map_markups table\n";
} catch (Exception $e) { echo "[WARN] " . $e->getMessage() . "\n"; }

// Seed default categories
$cats = [
    ['Region Boundary',    'bi-bounding-box',    '#3366FF'],
    ['Banners',            'bi-flag',             '#FF6600'],
    ['Facility Catchment', 'bi-hospital',         '#00CC66'],
    ['Ring Fence',         'bi-circle',           '#FF0000'],
    ['Exclusion Zone',     'bi-exclamation-triangle', '#CC0000'],
    ['Basemap',            'bi-map',              '#666666'],
    ['Event Zone',         'bi-calendar-event',   '#9933FF'],
    ['Search Area',        'bi-search',           '#0099FF']
];

$seeded = 0;
foreach ($cats as $i => $c) {
    try {
        db_query("INSERT IGNORE INTO `{$prefix}markup_categories` (name, icon, color, sort_order) VALUES (?, ?, ?, ?)",
            [$c[0], $c[1], $c[2], $i + 1]);
        $seeded++;
    } catch (Exception $e) {}
}
echo "[OK] $seeded categories seeded\n\nDone.\n";
