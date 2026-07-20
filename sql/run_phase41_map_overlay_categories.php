<?php
/**
 * Phase 41 — Map Overlay Categories.
 *
 * Eric: parade routes, precincts, zones — needs grouping + toggleable
 * layers on the dispatcher map. The mmarkup data already exists (drawn
 * via Alert Zones panel). What's missing is:
 *
 *   - A real category model with name + color + icon
 *   - mmarkup → mmarkup_cats foreign key
 *   - Sort order for predictable display
 *   - Default visibility (e.g. precincts ON, parades OFF until needed)
 *
 * Adds columns to the legacy mmarkup_cats table + a category_id on
 * mmarkup itself. Seeds a few common categories so admins land on
 * a populated panel.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 41 — Map overlay categories\n";
echo "=================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

// 1. Beef up mmarkup_cats
$additions = [
    'color'             => "VARCHAR(16) NULL DEFAULT '#1976d2'",
    'icon'              => "VARCHAR(32) NULL",
    'sort_order'        => "INT NOT NULL DEFAULT 0",
    'default_visible'   => "TINYINT(1) NOT NULL DEFAULT 1",
    'description'       => "VARCHAR(255) NULL",
    'archived_at'       => "DATETIME NULL",
];
foreach ($additions as $col => $def) {
    if (!_has_col('mmarkup_cats', $col)) {
        try {
            db_query("ALTER TABLE `{$prefix}mmarkup_cats` ADD COLUMN `{$col}` {$def}");
            echo "[OK] mmarkup_cats.{$col} added\n";
        } catch (Exception $e) {
            echo "[WARN] mmarkup_cats.{$col}: " . $e->getMessage() . "\n";
        }
    }
}

// 2. Wire mmarkup to mmarkup_cats (FK column)
if (!_has_col('mmarkup', 'category_id')) {
    try {
        db_query("ALTER TABLE `{$prefix}mmarkup` ADD COLUMN `category_id` BIGINT(4) NULL");
        db_query("ALTER TABLE `{$prefix}mmarkup` ADD KEY `idx_category` (`category_id`)");
        echo "[OK] mmarkup.category_id + index added\n";
    } catch (Exception $e) {
        echo "[WARN] mmarkup.category_id: " . $e->getMessage() . "\n";
    }
} else {
    echo "[skip] mmarkup.category_id already present\n";
}

// 3. Seed a few common categories so admins see a populated UI.
$seeds = [
    ['category' => 'Precincts',     'color' => '#1976d2', 'icon' => 'shield',
     'sort_order' => 10, 'default_visible' => 1, 'description' => 'Patrol-precinct boundaries'],
    ['category' => 'Zones',         'color' => '#388e3c', 'icon' => 'map',
     'sort_order' => 20, 'default_visible' => 1, 'description' => 'Operational zones (response areas)'],
    ['category' => 'Parade Routes', 'color' => '#f57c00', 'icon' => 'flag',
     'sort_order' => 30, 'default_visible' => 0, 'description' => 'Marked routes for parades, runs, processions'],
    ['category' => 'Hazards',       'color' => '#d32f2f', 'icon' => 'exclamation-triangle',
     'sort_order' => 40, 'default_visible' => 1, 'description' => 'Hazard areas, restricted access, etc.'],
    ['category' => 'Other',         'color' => '#757575', 'icon' => 'circle',
     'sort_order' => 99, 'default_visible' => 1, 'description' => 'Uncategorised'],
];

$seeded = 0;
foreach ($seeds as $s) {
    try {
        $exists = db_fetch_value(
            "SELECT id FROM `{$prefix}mmarkup_cats` WHERE category = ? LIMIT 1",
            [$s['category']]
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}mmarkup_cats`
                    (category, color, icon, sort_order, default_visible, description, _by, _from)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 'phase41')",
                [$s['category'], $s['color'], $s['icon'], $s['sort_order'], $s['default_visible'], $s['description']]
            );
            $seeded++;
        }
    } catch (Exception $e) {
        echo "[WARN] seed '{$s['category']}': " . $e->getMessage() . "\n";
    }
}
echo "[OK] seeded {$seeded} categor" . ($seeded === 1 ? "y" : "ies") . "\n";

echo "\nDone.\n";
