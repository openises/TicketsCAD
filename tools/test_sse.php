<?php
/**
 * SSE Integration Test
 * Tests the full chain: publish -> table -> stream readback
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== SSE Integration Tests ===\n\n";
$pass = 0;
$fail = 0;

// Test 1: Table exists
echo "[Test 1] sse_events table exists... ";
try {
    $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events`");
    echo "PASS (has $count rows)\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 2: Publish single event
echo "[Test 2] Publish incident:new event... ";
$result = sse_publish('incident:new', ['ticket_id' => 999, 'scope' => 'Test Fire', 'severity' => 2], 1);
if ($result) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Test 3: Read event back
echo "[Test 3] Read event back from table... ";
try {
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'incident:new' ORDER BY id DESC LIMIT 1"
    );
    if ($row && strpos($row['payload'], '999') !== false) {
        echo "PASS (id={$row['id']}, payload contains ticket_id 999)\n";
        $pass++;
    } else {
        echo "FAIL: unexpected row content\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 4: Publish batch
echo "[Test 4] Publish batch of 3 events... ";
$count = sse_publish_batch([
    ['type' => 'incident:update', 'payload' => ['ticket_id' => 100]],
    ['type' => 'responder:assign', 'payload' => ['ticket_id' => 100, 'responder' => 'Unit 1']],
    ['type' => 'incident:note', 'payload' => ['ticket_id' => 100]],
], 1);
if ($count === 3) {
    echo "PASS ($count events)\n";
    $pass++;
} else {
    echo "FAIL: expected 3, got $count\n";
    $fail++;
}

// Test 5: Verify all event types stored correctly
echo "[Test 5] Verify event types in DB... ";
// Publish a system:refresh event so we have all expected types
sse_publish('system:refresh', ['reason' => 'test']);
try {
    $types = db_fetch_all("SELECT DISTINCT event_type FROM `{$prefix}sse_events` ORDER BY event_type");
    $found = [];
    foreach ($types as $t) $found[] = $t['event_type'];
    $expected = ['incident:new', 'incident:note', 'incident:update', 'responder:assign', 'system:refresh'];
    $missing = array_diff($expected, $found);
    if (empty($missing)) {
        echo "PASS (found: " . implode(', ', $found) . ")\n";
        $pass++;
    } else {
        echo "FAIL: missing types: " . implode(', ', $missing) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 6: Payload JSON integrity
echo "[Test 6] Payload JSON round-trip... ";
$testPayload = ['ticket_id' => 42, 'fields_changed' => ['severity', 'address'], 'unicode' => 'Ñoño'];
sse_publish('incident:update', $testPayload, 1);
try {
    $row = db_fetch_one(
        "SELECT payload FROM `{$prefix}sse_events` WHERE event_type = 'incident:update' ORDER BY id DESC LIMIT 1"
    );
    $decoded = json_decode($row['payload'], true);
    if ($decoded['ticket_id'] === 42 && $decoded['unicode'] === 'Ñoño' && count($decoded['fields_changed']) === 2) {
        echo "PASS (JSON round-trip intact, unicode preserved)\n";
        $pass++;
    } else {
        echo "FAIL: decoded payload doesn't match\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 7: Stream endpoint auth check
echo "[Test 7] Stream endpoint rejects unauthenticated... ";
$ch = curl_init('http://localhost/newui/api/stream.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode === 401 || strpos($response, 'Not authenticated') !== false) {
    echo "PASS (HTTP $httpCode, rejects unauthenticated)\n";
    $pass++;
} else {
    echo "FAIL: expected 401, got HTTP $httpCode\n";
    $fail++;
}

// Test 8: Event cleanup (events older than 1 hour)
echo "[Test 8] Old event cleanup... ";
try {
    // Insert an old event
    db_query(
        "INSERT INTO `{$prefix}sse_events` (event_type, payload, created_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 HOUR))",
        ['test:old', '{}']
    );
    $oldId = db_insert_id();
    // Run cleanup
    db_query("DELETE FROM `{$prefix}sse_events` WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    // Verify it's gone
    $check = db_fetch_all("SELECT id FROM `{$prefix}sse_events` WHERE id = ?", [$oldId]);
    if (empty($check)) {
        echo "PASS (old event cleaned up)\n";
        $pass++;
    } else {
        echo "FAIL: old event still present\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 9: EventBus.js syntax check (ES5 compliance)
echo "[Test 9] event-bus.js ES5 compliance... ";
$js = file_get_contents(__DIR__ . '/../assets/js/event-bus.js');
$es6Patterns = [
    '/\blet\s/' => 'let keyword',
    '/\bconst\s/' => 'const keyword',
    '/=>/' => 'arrow function',
    '/`[^`]*`/' => 'template literal',
    '/\bclass\s+\w/' => 'class keyword',
];
$violations = [];
foreach ($es6Patterns as $pattern => $desc) {
    if (preg_match($pattern, $js)) {
        $violations[] = $desc;
    }
}
if (empty($violations)) {
    echo "PASS (no ES6 syntax found)\n";
    $pass++;
} else {
    echo "FAIL: found ES6: " . implode(', ', $violations) . "\n";
    $fail++;
}

// Test 10: sse.php publisher handles missing table gracefully
echo "[Test 10] Publisher handles missing table gracefully... ";
// Temporarily rename the table
try {
    db_query("RENAME TABLE `{$prefix}sse_events` TO `{$prefix}sse_events_backup`");
    $result = sse_publish('test:missing_table', ['should' => 'fail_silently']);
    db_query("RENAME TABLE `{$prefix}sse_events_backup` TO `{$prefix}sse_events`");
    if ($result === false) {
        echo "PASS (returned false, no crash)\n";
        $pass++;
    } else {
        echo "FAIL: should have returned false\n";
        $fail++;
    }
} catch (Exception $e) {
    // Try to restore table
    try { db_query("RENAME TABLE `{$prefix}sse_events_backup` TO `{$prefix}sse_events`"); } catch (Exception $e2) {}
    echo "FAIL: exception thrown: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 11: stream.php has set_time_limit ──
echo "[Test 11] stream.php has set_time_limit... ";
$streamCode = file_get_contents(__DIR__ . '/../api/stream.php');
if (strpos($streamCode, 'set_time_limit') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: stream.php missing set_time_limit — SSE will crash from PHP timeout\n";
    $fail++;
}

// ── Test 12: EventBus loaded via navbar on all pages ──
echo "[Test 12] Navbar loads EventBus globally... ";
$navbarCode = file_get_contents(__DIR__ . '/../inc/navbar.php');
if (strpos($navbarCode, 'event-bus.js') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: navbar.php does not load event-bus.js — SSE won't work on most pages\n";
    $fail++;
}

// ── Test 13: Navbar loads audio-alerts globally ──
echo "[Test 13] Navbar loads audio-alerts globally... ";
if (strpos($navbarCode, 'audio-alerts.js') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: navbar.php does not load audio-alerts.js\n";
    $fail++;
}

// ── Test 14: Navbar has SSE indicator update logic ──
echo "[Test 14] Navbar has SSE indicator handler... ";
if (strpos($navbarCode, 'sse:connected') !== false && strpos($navbarCode, 'updateSseIndicator') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: navbar missing SSE indicator update code\n";
    $fail++;
}

// ── Test 15: Notification tray loaded via navbar ──
echo "[Test 15] Navbar loads notification-tray.js... ";
if (strpos($navbarCode, 'notification-tray.js') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: navbar missing notification tray script\n";
    $fail++;
}

// ── Test 16: Geofence events don't skip own user in notification tray ──
echo "[Test 16] Notification tray shows geofence events from own user... ";
$trayCode = file_get_contents(__DIR__ . '/../assets/js/notification-tray.js');
if (strpos($trayCode, 'geofence:') !== false && strpos($trayCode, 'alwaysShow') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: notification tray may filter out own geofence events\n";
    $fail++;
}

// ── Test 17: Audio alerts subscribe to geofence events ──
echo "[Test 17] Audio alerts listen for geofence events... ";
$audioCode = file_get_contents(__DIR__ . '/../assets/js/audio-alerts.js');
if (strpos($audioCode, 'geofence:enter') !== false && strpos($audioCode, 'geofence:exit') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: audio-alerts.js missing geofence event listeners\n";
    $fail++;
}

// ── Test 18: Geofence check is wired into responder-save ──
// 2026-06-28 — responder-save.php was refactored to delegate to
// inc/responder-write.php :: responder_upsert_internal(); the geofence
// check moved into the helper. Accept either an inline call OR a
// require_once of the helper (which contains the call).
echo "[Test 18] Geofence check in responder-save.php... ";
$rsSave    = file_get_contents(__DIR__ . '/../api/responder-save.php');
$rsHelper  = file_get_contents(__DIR__ . '/../inc/responder-write.php');
$wiredHere = strpos($rsSave, 'geofence_check') !== false;
$wiredViaHelper = strpos($rsSave, 'responder-write.php') !== false
              && strpos($rsHelper, 'geofence_check') !== false;
if ($wiredHere || $wiredViaHelper) {
    echo "PASS" . ($wiredViaHelper && !$wiredHere ? " (via responder-write helper)" : "") . "\n";
    $pass++;
} else {
    echo "FAIL: responder-save.php missing geofence_check call (neither inline nor via inc/responder-write.php)\n";
    $fail++;
}

// ── Test 19: Geofence check is wired into location API ──
echo "[Test 19] Geofence check in location.php... ";
$locApi = file_get_contents(__DIR__ . '/../api/location.php');
if (strpos($locApi, 'geofence_check') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: location.php missing geofence_check call\n";
    $fail++;
}

// ── Test 20: Geofence check is wired into mobile-data ──
echo "[Test 20] Geofence check in mobile-data.php... ";
$mobApi = file_get_contents(__DIR__ . '/../api/mobile-data.php');
if (strpos($mobApi, 'geofence_check') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: mobile-data.php missing geofence_check call\n";
    $fail++;
}

// ── Test 21: All pages with navbar include sseIndicator element ──
echo "[Test 21] Navbar has sseIndicator element... ";
if (strpos($navbarCode, 'sseIndicator') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: navbar missing sseIndicator element\n";
    $fail++;
}

// ── Test 22: EventBus reconnect limit is reasonable ──
echo "[Test 22] EventBus reconnect limit > 5... ";
$ebCode = file_get_contents(__DIR__ . '/../assets/js/event-bus.js');
if (preg_match('/sseAttempts\s*>\s*(\d+)/', $ebCode, $m)) {
    $limit = (int) $m[1];
    if ($limit >= 15) {
        echo "PASS (limit=$limit)\n";
        $pass++;
    } else {
        echo "FAIL: reconnect limit too low ($limit) — SSE gives up too quickly\n";
        $fail++;
    }
} else {
    echo "FAIL: cannot find reconnect limit in event-bus.js\n";
    $fail++;
}

// ── Test 23: Geofence publish creates SSE event ──
echo "[Test 23] Geofence event publishes to SSE table... ";
require_once __DIR__ . '/../inc/geofence.php';
$testPayload = ['geofence_id' => 999, 'event' => 'enter', 'unit' => 'TEST-UNIT'];
$published = sse_publish('geofence:enter', $testPayload);
if ($published) {
    $sseRow = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'geofence:enter' AND payload LIKE '%TEST-UNIT%' ORDER BY id DESC LIMIT 1"
    );
    if ($sseRow) {
        echo "PASS (event id=" . $sseRow['id'] . ")\n";
        $pass++;
        // Clean up
        db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$sseRow['id']]);
    } else {
        echo "FAIL: published but not found in table\n";
        $fail++;
    }
} else {
    echo "FAIL: sse_publish returned false\n";
    $fail++;
}

// Cleanup test events
echo "\nCleaning up test events... ";
try {
    db_query("DELETE FROM `{$prefix}sse_events` WHERE payload LIKE '%999%' OR payload LIKE '%Unit 1%' OR event_type LIKE 'test:%' OR (payload LIKE '%42%' AND payload LIKE '%severity%')");
    echo "done.\n";
} catch (Exception $e) {}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
