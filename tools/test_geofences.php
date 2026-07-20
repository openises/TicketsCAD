<?php
/**
 * Geofence system tests.
 * Usage: /c/xampp/8.2.4/php/php.exe tools/test_geofences.php
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/rbac.php';
require __DIR__ . '/../inc/audit.php';
require __DIR__ . '/../inc/geofence.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Geofence Tests ===\n\n";
$pass = 0; $fail = 0;

// ── Test 1: Tables created ───────────────────────────────────
echo "[Test 1] geofences table exists... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}geofences`");
    $names = array_column($cols, 'Field');
    if (in_array('markup_id', $names) && in_array('alert_on_enter', $names) && in_array('active', $names)) {
        echo "PASS (" . count($cols) . " columns)\n"; $pass++;
    } else { echo "FAIL: missing columns\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

echo "[Test 1b] geofence_unit_state table exists... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}geofence_unit_state`");
    $names = array_column($cols, 'Field');
    if (in_array('geofence_id', $names) && in_array('unit_identifier', $names) && in_array('state', $names)) {
        echo "PASS (" . count($cols) . " columns)\n"; $pass++;
    } else { echo "FAIL: missing columns\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Create a test markup (polygon around downtown Minneapolis) ─
$testPolygon = json_encode([
    'type' => 'Polygon',
    'coordinates' => [[
        [-93.28, 44.96],
        [-93.24, 44.96],
        [-93.24, 44.99],
        [-93.28, 44.99],
        [-93.28, 44.96]
    ]]
]);
$testCircle = json_encode([
    'type' => 'Circle',
    'center' => [-93.26, 44.975],
    'radius' => 500
]);

$markupId = null;
$circleMarkupId = null;
try {
    // Insert into legacy mmarkup table (used by geofence system)
    // Polygon: line_data is JSON array of [lat, lng] pairs, line_type = 'P'
    $polyCoords = json_decode($testPolygon, true);
    $legacyPolyCoords = [];
    if (isset($polyCoords['coordinates'][0])) {
        foreach ($polyCoords['coordinates'][0] as $pt) {
            $legacyPolyCoords[] = [$pt[1], $pt[0]]; // GeoJSON [lng,lat] → legacy [lat,lng]
        }
    }
    db_query(
        "INSERT INTO `{$prefix}mmarkup` (`line_name`, `line_type`, `line_data`, `line_status`, `_by`, `_from`, `_on`)
         VALUES (?, 'P', ?, 1, 1, 'test', NOW())",
        ['Test Geofence Zone', json_encode($legacyPolyCoords)]
    );
    $markupId = (int) db_insert_id();

    // Circle: line_data is JSON array with one [lat, lng] center, line_ident = radius
    $circleData = json_decode($testCircle, true);
    $centerLat = $circleData['center'][1] ?? 0; // GeoJSON [lng,lat]
    $centerLng = $circleData['center'][0] ?? 0;
    $radius = $circleData['radius'] ?? 500;
    db_query(
        "INSERT INTO `{$prefix}mmarkup` (`line_name`, `line_type`, `line_data`, `line_ident`, `line_status`, `_by`, `_from`, `_on`)
         VALUES (?, 'C', ?, ?, 1, 1, 'test', NOW())",
        ['Test Circle Zone', json_encode([[$centerLat, $centerLng]]), (string) $radius]
    );
    $circleMarkupId = (int) db_insert_id();
} catch (Exception $e) {
    echo "SETUP FAIL: Could not create test markups: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Test 2: Create geofence from markup ──────────────────────
echo "[Test 2] Create geofence from markup... ";
$fenceId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}geofences`
         (`markup_id`, `name`, `active`, `alert_on_enter`, `alert_on_exit`,
          `alert_channels_json`, `notify_users_json`, `created_by`)
         VALUES (?, ?, 1, 1, 1, ?, ?, 1)",
        [$markupId, 'Downtown Zone', '["local_chat"]', '[1]']
    );
    $fenceId = (int) db_insert_id();
    echo "PASS (id=$fenceId)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Test 3: Point inside polygon returns true ────────────────
echo "[Test 3] Point inside polygon... ";
$insideLat = 44.975;
$insideLng = -93.26;
$result = geofence_test_point($insideLat, $insideLng, $testPolygon);
if ($result === true) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL: expected true\n"; $fail++; }

// ── Test 4: Point outside polygon returns false ──────────────
echo "[Test 4] Point outside polygon... ";
$outsideLat = 45.10;
$outsideLng = -93.50;
$result = geofence_test_point($outsideLat, $outsideLng, $testPolygon);
if ($result === false) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL: expected false\n"; $fail++; }

// ── Test 5: Circle geofence — point within radius ───────────
echo "[Test 5] Circle geofence: point within radius... ";
// Center is [-93.26, 44.975], radius 500m. A point very close to center should be inside.
$result = geofence_test_point(44.975, -93.26, $testCircle);
if ($result === true) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL: expected true\n"; $fail++; }

echo "[Test 5b] Circle geofence: point outside radius... ";
// A point far away should be outside
$result = geofence_test_point(45.10, -93.50, $testCircle);
if ($result === false) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL: expected false\n"; $fail++; }

// ── Test 6: State tracking — enter detected ──────────────────
echo "[Test 6] State tracking: enter detected... ";
$events = geofence_check($insideLat, $insideLng, 'TEST-UNIT-1');
$enterFound = false;
foreach ($events as $evt) {
    if ($evt['geofence_id'] === $fenceId && $evt['event'] === 'enter' && $evt['unit'] === 'TEST-UNIT-1') {
        $enterFound = true;
    }
}
if ($enterFound) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL: no enter event for fence $fenceId\n"; $fail++; }

// Verify state is tracked in DB
echo "[Test 6b] State persisted as 'inside'... ";
try {
    $state = db_fetch_value(
        "SELECT `state` FROM `{$prefix}geofence_unit_state`
         WHERE `geofence_id` = ? AND `unit_identifier` = ?",
        [$fenceId, 'TEST-UNIT-1']
    );
    if ($state === 'inside') {
        echo "PASS\n"; $pass++;
    } else { echo "FAIL: state='$state'\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Test 7: State tracking — exit detected ───────────────────
echo "[Test 7] State tracking: exit detected... ";
$events = geofence_check($outsideLat, $outsideLng, 'TEST-UNIT-1');
$exitFound = false;
foreach ($events as $evt) {
    if ($evt['geofence_id'] === $fenceId && $evt['event'] === 'exit' && $evt['unit'] === 'TEST-UNIT-1') {
        $exitFound = true;
    }
}
if ($exitFound) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL: no exit event\n"; $fail++; }

echo "[Test 7b] State persisted as 'outside'... ";
try {
    $state = db_fetch_value(
        "SELECT `state` FROM `{$prefix}geofence_unit_state`
         WHERE `geofence_id` = ? AND `unit_identifier` = ?",
        [$fenceId, 'TEST-UNIT-1']
    );
    if ($state === 'outside') {
        echo "PASS\n"; $pass++;
    } else { echo "FAIL: state='$state'\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Test 8: Inactive geofence skipped ────────────────────────
echo "[Test 8] Inactive geofence skipped... ";
// Deactivate the fence, then move the unit back inside — should get no events
try {
    db_query("UPDATE `{$prefix}geofences` SET `active` = 0 WHERE `id` = ?", [$fenceId]);
    // Reset state to outside so an enter would normally fire
    db_query(
        "UPDATE `{$prefix}geofence_unit_state` SET `state` = 'outside'
         WHERE `geofence_id` = ? AND `unit_identifier` = ?",
        [$fenceId, 'TEST-UNIT-1']
    );
    $events = geofence_check($insideLat, $insideLng, 'TEST-UNIT-1');
    if (empty($events)) {
        echo "PASS (no events from inactive fence)\n"; $pass++;
    } else { echo "FAIL: got " . count($events) . " events\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Test 9: No-transition — already inside, stays inside ─────
echo "[Test 9] No-transition: no duplicate events... ";
try {
    // Reactivate and set state to inside
    db_query("UPDATE `{$prefix}geofences` SET `active` = 1 WHERE `id` = ?", [$fenceId]);
    db_query(
        "UPDATE `{$prefix}geofence_unit_state` SET `state` = 'inside'
         WHERE `geofence_id` = ? AND `unit_identifier` = ?",
        [$fenceId, 'TEST-UNIT-1']
    );
    // Check with a point still inside — no enter event expected
    $events = geofence_check($insideLat, $insideLng, 'TEST-UNIT-1');
    $spurious = false;
    foreach ($events as $evt) {
        if ($evt['geofence_id'] === $fenceId) $spurious = true;
    }
    if (!$spurious) {
        echo "PASS\n"; $pass++;
    } else { echo "FAIL: spurious event fired\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Cleanup ──────────────────────────────────────────────────
echo "\nCleaning up... ";
try {
    db_query("DELETE FROM `{$prefix}geofence_unit_state` WHERE `geofence_id` = ?", [$fenceId]);
    db_query("DELETE FROM `{$prefix}geofences` WHERE `id` = ?", [$fenceId]);
    if ($markupId) db_query("DELETE FROM `{$prefix}mmarkup` WHERE `id` = ?", [$markupId]);
    if ($circleMarkupId) db_query("DELETE FROM `{$prefix}mmarkup` WHERE `id` = ?", [$circleMarkupId]);
    // Clean up chat messages and SSE events generated by geofence tests
    try { db_query("DELETE FROM `{$prefix}chat_messages` WHERE `body` LIKE '%Geofence%' OR `body` LIKE '%TEST-UNIT%'"); } catch (Exception $e) {}
    try { db_query("DELETE FROM `{$prefix}sse_events` WHERE `payload` LIKE '%geofence%' OR `payload` LIKE '%TEST-UNIT%'"); } catch (Exception $e) {}
    try { db_query("DELETE FROM `{$prefix}messages` WHERE `body` LIKE '%Geofence%' OR `body` LIKE '%TEST-UNIT%'"); } catch (Exception $e) {}
    echo "OK\n";
} catch (Exception $e) { echo "WARN: " . $e->getMessage() . "\n"; }

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
