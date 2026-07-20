<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/rbac.php';
require __DIR__ . '/../inc/audit.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Map Markup Tests ===\n\n";
$pass = 0; $fail = 0;

// Test 1: Tables exist
echo "[Test 1] markup_categories table... ";
try {
    $cats = db_fetch_all("SELECT * FROM `{$prefix}markup_categories` ORDER BY sort_order");
    echo "PASS (" . count($cats) . " categories)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 2: map_markups table
echo "[Test 2] map_markups table... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}map_markups`");
    $names = array_column($cols, 'Field');
    if (in_array('geojson', $names) && in_array('category_id', $names) && in_array('visible', $names)) {
        echo "PASS (" . count($cols) . " columns)\n"; $pass++;
    } else { echo "FAIL: missing columns\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 3: Default categories seeded
echo "[Test 3] Default categories seeded... ";
if (count($cats) >= 6) {
    $names = array_column($cats, 'name');
    if (in_array('Region Boundary', $names) && in_array('Exclusion Zone', $names)) {
        echo "PASS (" . implode(', ', $names) . ")\n"; $pass++;
    } else { echo "FAIL: missing expected categories\n"; $fail++; }
} else { echo "FAIL: only " . count($cats) . " categories\n"; $fail++; }

// Test 4: Create a markup
echo "[Test 4] Create markup... ";
$testGeojson = json_encode([
    'type' => 'Polygon',
    'coordinates' => [[[-93.3, 44.9], [-93.2, 44.9], [-93.2, 45.0], [-93.3, 45.0], [-93.3, 44.9]]]
]);
$testStyle = json_encode(['color' => '#FF0000', 'opacity' => 0.7, 'weight' => 2, 'fillOpacity' => 0.3]);
try {
    db_query(
        "INSERT INTO `{$prefix}map_markups` (category_id, name, description, markup_type, geojson, style, visible, ident, apply_to, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$cats[0]['id'], 'Test Zone A', 'Test markup', 'polygon', $testGeojson, $testStyle, 1, 'TZA', 'base_map', 1]
    );
    $testId = db_insert_id();
    echo "PASS (id=$testId)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; $testId = null; }

// Test 5: Query back
echo "[Test 5] Query markup back... ";
try {
    $m = db_fetch_one("SELECT * FROM `{$prefix}map_markups` WHERE id = ?", [$testId]);
    if ($m && $m['name'] === 'Test Zone A' && $m['markup_type'] === 'polygon') {
        $geo = json_decode($m['geojson'], true);
        if ($geo['type'] === 'Polygon') {
            echo "PASS (GeoJSON round-trip OK)\n"; $pass++;
        } else { echo "FAIL: GeoJSON type mismatch\n"; $fail++; }
    } else { echo "FAIL\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 6: Toggle visibility
echo "[Test 6] Toggle visibility... ";
try {
    db_query("UPDATE `{$prefix}map_markups` SET visible = NOT visible WHERE id = ?", [$testId]);
    $vis = (int) db_fetch_value("SELECT visible FROM `{$prefix}map_markups` WHERE id = ?", [$testId]);
    if ($vis === 0) {
        db_query("UPDATE `{$prefix}map_markups` SET visible = NOT visible WHERE id = ?", [$testId]);
        $vis2 = (int) db_fetch_value("SELECT visible FROM `{$prefix}map_markups` WHERE id = ?", [$testId]);
        if ($vis2 === 1) { echo "PASS (toggled 1→0→1)\n"; $pass++; }
        else { echo "FAIL: second toggle\n"; $fail++; }
    } else { echo "FAIL: expected 0, got $vis\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 7: Filter by category
echo "[Test 7] Filter by category... ";
try {
    $filtered = db_fetch_all(
        "SELECT * FROM `{$prefix}map_markups` WHERE category_id = ?",
        [$cats[0]['id']]
    );
    if (count($filtered) >= 1) { echo "PASS (" . count($filtered) . " markups in category)\n"; $pass++; }
    else { echo "FAIL: no results\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 8: Style JSON round-trip
echo "[Test 8] Style JSON round-trip... ";
try {
    $style = json_decode($m['style'], true);
    if ($style['color'] === '#FF0000' && $style['opacity'] === 0.7 && $style['weight'] === 2) {
        echo "PASS\n"; $pass++;
    } else { echo "FAIL: style mismatch\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Cleanup
if ($testId) {
    db_query("DELETE FROM `{$prefix}map_markups` WHERE id = ?", [$testId]);
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
