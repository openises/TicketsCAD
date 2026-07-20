<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/rbac.php';
require __DIR__ . '/../inc/audit.php';
require __DIR__ . '/../inc/sse.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Facility Capacity Tests ===\n\n";
$pass = 0; $fail = 0;

// Create tables first
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}capacity_categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(64) NOT NULL,
        `icon` VARCHAR(64) DEFAULT 'bi-hospital', `unit_label` VARCHAR(32) DEFAULT 'beds',
        `sort_order` INT NOT NULL DEFAULT 0, UNIQUE KEY `uk_cap_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_capacity` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `facility_id` INT NOT NULL, `category_id` INT NOT NULL,
        `total` INT NOT NULL DEFAULT 0, `available` INT NOT NULL DEFAULT 0,
        `notes` VARCHAR(255) DEFAULT '', `updated_by` INT NOT NULL DEFAULT 0,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_fac_cat` (`facility_id`, `category_id`), KEY `idx_facility` (`facility_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Seed defaults
    $catCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}capacity_categories`");
    if ($catCount === 0) {
        $seedCats = [['ICU Beds','bi-heart-pulse','beds',1],['General Beds','bi-hospital','beds',2],
            ['Pediatric Beds','bi-emoji-smile','beds',3],['ER Beds','bi-lightning','beds',4],
            ['Shelter Spots','bi-house','spots',5],['Cots','bi-moon','cots',6],
            ['Ventilators','bi-wind','units',7],['Decon Stations','bi-droplet','stations',8]];
        foreach ($seedCats as $c) {
            db_query("INSERT IGNORE INTO `{$prefix}capacity_categories` (name, icon, unit_label, sort_order) VALUES (?,?,?,?)", $c);
        }
    }
} catch (Exception $e) { echo "Setup error: " . $e->getMessage() . "\n"; }

// Test 1: Tables exist
echo "[Test 1] capacity_categories table... ";
try {
    $cats = db_fetch_all("SELECT * FROM `{$prefix}capacity_categories` ORDER BY sort_order");
    echo "PASS (" . count($cats) . " categories)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; $cats = []; }

// Test 2: facility_capacity table
echo "[Test 2] facility_capacity table... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}facility_capacity`");
    echo "PASS (" . count($cols) . " columns)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 3: Default categories seeded
echo "[Test 3] Default categories... ";
$names = array_column($cats, 'name');
if (in_array('ICU Beds', $names) && in_array('Shelter Spots', $names) && in_array('Ventilators', $names)) {
    echo "PASS (" . implode(', ', $names) . ")\n"; $pass++;
} else { echo "FAIL\n"; $fail++; }

// Test 4: Insert capacity record
echo "[Test 4] Insert capacity... ";
$facId = 1; // First facility
$catId = $cats[0]['id'];
try {
    db_query(
        "INSERT INTO `{$prefix}facility_capacity` (facility_id, category_id, total, available, updated_by)
         VALUES (?, ?, 20, 15, 1)
         ON DUPLICATE KEY UPDATE total = 20, available = 15",
        [$facId, $catId]
    );
    echo "PASS\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 5: Query back
echo "[Test 5] Query capacity... ";
try {
    $row = db_fetch_one(
        "SELECT fc.*, cc.name AS category_name FROM `{$prefix}facility_capacity` fc
         JOIN `{$prefix}capacity_categories` cc ON fc.category_id = cc.id
         WHERE fc.facility_id = ? AND fc.category_id = ?",
        [$facId, $catId]
    );
    if ($row && (int)$row['total'] === 20 && (int)$row['available'] === 15) {
        echo "PASS (total=20, available=15, cat={$row['category_name']})\n"; $pass++;
    } else { echo "FAIL\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 6: Upsert (update on duplicate key)
echo "[Test 6] Upsert updates existing... ";
try {
    db_query(
        "INSERT INTO `{$prefix}facility_capacity` (facility_id, category_id, total, available, updated_by)
         VALUES (?, ?, 20, 8, 1)
         ON DUPLICATE KEY UPDATE total = VALUES(total), available = VALUES(available)",
        [$facId, $catId]
    );
    $updated = db_fetch_one("SELECT available FROM `{$prefix}facility_capacity` WHERE facility_id = ? AND category_id = ?", [$facId, $catId]);
    if ((int)$updated['available'] === 8) {
        echo "PASS (available updated 15→8)\n"; $pass++;
    } else { echo "FAIL\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Cleanup
try { db_query("DELETE FROM `{$prefix}facility_capacity` WHERE facility_id = ? AND category_id = ?", [$facId, $catId]); } catch (Exception $e) {}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
