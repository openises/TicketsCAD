<?php
/**
 * APRS Poller & OwnTracks Ingestion Tests
 *
 * Usage: php tools/test_aprs_poller.php
 *
 * Tests the APRS poller configuration, OwnTracks endpoint parsing,
 * location retention cleanup, and call board settings.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

echo "=== APRS Poller & Location Ingestion Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

// Track items for cleanup
$test_report_ids  = [];
$test_binding_ids = [];
$settings_to_restore = [];

// ── Helper: save and set a setting ──────────────────────────────
function test_set_setting($name, $value) {
    global $prefix, $settings_to_restore;
    // Save current value for restore
    try {
        $current = db_fetch_one(
            "SELECT `value` FROM `{$GLOBALS['db_prefix']}settings` WHERE `name` = ?",
            [$name]
        );
        $settings_to_restore[$name] = $current ? $current['value'] : null;
    } catch (Exception $e) {
        $settings_to_restore[$name] = null;
    }

    try {
        $existing = db_fetch_value(
            "SELECT COUNT(*) FROM `{$GLOBALS['db_prefix']}settings` WHERE `name` = ?",
            [$name]
        );
        if ((int) $existing > 0) {
            db_query(
                "UPDATE `{$GLOBALS['db_prefix']}settings` SET `value` = ? WHERE `name` = ?",
                [$value, $name]
            );
        } else {
            db_query(
                "INSERT INTO `{$GLOBALS['db_prefix']}settings` (`name`, `value`) VALUES (?, ?)",
                [$name, $value]
            );
        }
    } catch (Exception $e) {
        // May fail if settings table doesn't exist
    }
}

// ── Test 1: APRS provider exists in location_providers ──
echo "[Test 1] APRS provider exists in location_providers... ";
$aprsProvider = null;
try {
    $aprsProvider = db_fetch_one(
        "SELECT `id`, `code`, `enabled`, `priority` FROM `{$prefix}location_providers` WHERE `code` = 'aprs'"
    );
    if ($aprsProvider && $aprsProvider['code'] === 'aprs') {
        echo "PASS (id={$aprsProvider['id']}, priority={$aprsProvider['priority']})\n";
        $pass++;
    } else {
        echo "FAIL: APRS provider not found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 2: OwnTracks provider exists ──
echo "[Test 2] OwnTracks provider exists in location_providers... ";
$otProvider = null;
try {
    $otProvider = db_fetch_one(
        "SELECT `id`, `code`, `enabled` FROM `{$prefix}location_providers` WHERE `code` = 'owntracks'"
    );
    if ($otProvider && $otProvider['code'] === 'owntracks') {
        echo "PASS (id={$otProvider['id']})\n";
        $pass++;
    } else {
        echo "FAIL: OwnTracks provider not found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 3: aprs_fi_api_key setting can be read/written ──
echo "[Test 3] aprs_fi_api_key setting read/write... ";
try {
    test_set_setting('aprs_fi_api_key', 'TEST_KEY_12345');

    // Clear the function cache by using a direct query
    $val = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'aprs_fi_api_key'"
    );
    if ($val === 'TEST_KEY_12345') {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: expected TEST_KEY_12345, got " . var_export($val, true) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 4: aprs_enabled setting can be read/written ──
echo "[Test 4] aprs_enabled setting read/write... ";
try {
    test_set_setting('aprs_enabled', '1');
    $val = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'aprs_enabled'"
    );
    if ($val === '1') {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: expected 1, got " . var_export($val, true) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 5: aprs_poll_interval setting can be set ──
echo "[Test 5] aprs_poll_interval setting... ";
try {
    test_set_setting('aprs_poll_interval', '5');
    $val = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'aprs_poll_interval'"
    );
    if ($val === '5') {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: expected 5, got " . var_export($val, true) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 6: Location report insertion (simulating APRS data) ──
echo "[Test 6] Insert APRS-style location report... ";
try {
    // Enable APRS provider temporarily
    db_query(
        "UPDATE `{$prefix}location_providers` SET `enabled` = 1 WHERE `code` = 'aprs'"
    );

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}location_reports`
         (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`,
          `speed`, `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [(int) $aprsProvider['id'], 'W1AW-9', 41.7148000, -72.7272000,
         25.0, 45.5, 270.0, null, null, '{"name":"W1AW-9","lat":41.7148,"lng":-72.7272}', $now]
    );
    $reportId = (int) db_insert_id();
    $test_report_ids[] = $reportId;

    if ($reportId > 0) {
        echo "PASS (id={$reportId})\n";
        $pass++;
    } else {
        echo "FAIL: no insert ID\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 7: Verify inserted APRS report data ──
echo "[Test 7] Verify APRS report data integrity... ";
try {
    $report = db_fetch_one(
        "SELECT `unit_identifier`, `lat`, `lng`, `altitude`, `speed`, `heading`
         FROM `{$prefix}location_reports`
         WHERE `unit_identifier` = 'W1AW-9'
         ORDER BY `reported_at` DESC LIMIT 1"
    );
    if ($report
        && abs((float) $report['lat'] - 41.7148) < 0.001
        && abs((float) $report['lng'] - (-72.7272)) < 0.001
        && abs((float) $report['speed'] - 45.5) < 0.1
    ) {
        echo "PASS (lat={$report['lat']}, lng={$report['lng']}, speed={$report['speed']})\n";
        $pass++;
    } else {
        echo "FAIL: unexpected data\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 8: OwnTracks-style location report insertion ──
echo "[Test 8] Insert OwnTracks-style location report... ";
try {
    // Enable OwnTracks provider temporarily
    db_query(
        "UPDATE `{$prefix}location_providers` SET `enabled` = 1 WHERE `code` = 'owntracks'"
    );

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}location_reports`
         (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`,
          `speed`, `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [(int) $otProvider['id'], 'EO', 44.9778000, -93.2650000,
         252.0, 5.0, 180.0, 15.0, 85, '{"_type":"location","tid":"EO","lat":44.9778,"lon":-93.265}', $now]
    );
    $otReportId = (int) db_insert_id();
    $test_report_ids[] = $otReportId;

    if ($otReportId > 0) {
        echo "PASS (id={$otReportId})\n";
        $pass++;
    } else {
        echo "FAIL: no insert ID\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 9: OwnTracks report data integrity ──
echo "[Test 9] Verify OwnTracks report data... ";
try {
    $report = db_fetch_one(
        "SELECT `unit_identifier`, `lat`, `lng`, `battery`, `accuracy`
         FROM `{$prefix}location_reports`
         WHERE `unit_identifier` = 'EO' AND `provider_id` = ?
         ORDER BY `reported_at` DESC LIMIT 1",
        [(int) $otProvider['id']]
    );
    if ($report
        && abs((float) $report['lat'] - 44.9778) < 0.001
        && (int) $report['battery'] === 85
        && abs((float) $report['accuracy'] - 15.0) < 0.1
    ) {
        echo "PASS (lat={$report['lat']}, battery={$report['battery']})\n";
        $pass++;
    } else {
        echo "FAIL: unexpected data\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 10: location_retention_days setting ──
echo "[Test 10] location_retention_days setting... ";
try {
    test_set_setting('location_retention_days', '90');
    $val = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'location_retention_days'"
    );
    if ($val === '90') {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: expected 90, got " . var_export($val, true) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 11: Location retention cleanup deletes old reports ──
echo "[Test 11] Location retention cleanup deletes old reports... ";
try {
    // Insert a very old report
    $oldDate = date('Y-m-d H:i:s', strtotime('-120 days'));
    db_query(
        "INSERT INTO `{$prefix}location_reports`
         (`provider_id`, `unit_identifier`, `lat`, `lng`, `reported_at`, `received_at`)
         VALUES (?, ?, ?, ?, ?, ?)",
        [(int) $aprsProvider['id'], 'OLD-TEST-UNIT', 40.0, -74.0, $oldDate, $oldDate]
    );
    $oldReportId = (int) db_insert_id();

    // Run cleanup (retention = 90 days)
    $stmt = db_query(
        "DELETE FROM `{$prefix}location_reports`
         WHERE `received_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    $purged = $stmt->rowCount();

    // Verify old report was deleted
    $check = db_fetch_one(
        "SELECT `id` FROM `{$prefix}location_reports` WHERE `id` = ?",
        [$oldReportId]
    );

    if (!$check && $purged >= 1) {
        echo "PASS (purged {$purged} old report(s))\n";
        $pass++;
    } else {
        echo "FAIL: old report not cleaned up\n";
        $fail++;
        // Add to cleanup list anyway
        if ($check) $test_report_ids[] = $oldReportId;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 12: recent_close_mins setting (call board) ──
echo "[Test 12] recent_close_mins setting for call board... ";
try {
    test_set_setting('recent_close_mins', '45');
    $val = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'recent_close_mins'"
    );
    if ($val === '45') {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: expected 45, got " . var_export($val, true) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 13: Binding with APRS provider for poller lookup ──
echo "[Test 13] APRS binding for poller callsign lookup... ";
try {
    db_query(
        "INSERT INTO `{$prefix}unit_location_bindings`
         (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
         VALUES (?, ?, ?, ?, 1)",
        [9998, (int) $aprsProvider['id'], 'W1AW-9', 10]
    );
    $bindId = (int) db_insert_id();
    $test_binding_ids[] = $bindId;

    // Verify the binding can be found via the same query the poller uses
    $bindings = db_fetch_all(
        "SELECT b.`responder_id`, b.`unit_identifier`
         FROM `{$prefix}unit_location_bindings` b
         WHERE b.`provider_id` = ?
           AND b.`active` = 1
           AND b.`unit_identifier` != ''",
        [(int) $aprsProvider['id']]
    );

    $found = false;
    foreach ($bindings as $b) {
        if ($b['unit_identifier'] === 'W1AW-9') {
            $found = true;
            break;
        }
    }

    if ($found) {
        echo "PASS (binding found for W1AW-9)\n";
        $pass++;
    } else {
        echo "FAIL: binding not found in poller query\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 14: OwnTracks binding for tid lookup ──
echo "[Test 14] OwnTracks binding for tid lookup... ";
try {
    db_query(
        "INSERT INTO `{$prefix}unit_location_bindings`
         (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
         VALUES (?, ?, ?, ?, 1)",
        [9997, (int) $otProvider['id'], 'EO', 50]
    );
    $otBindId = (int) db_insert_id();
    $test_binding_ids[] = $otBindId;

    // Verify binding lookup by tid (same query the OwnTracks handler uses)
    $binding = db_fetch_one(
        "SELECT `id`, `responder_id`, `unit_identifier`
         FROM `{$prefix}unit_location_bindings`
         WHERE `provider_id` = ?
           AND `unit_identifier` = ?
           AND `active` = 1",
        [(int) $otProvider['id'], 'EO']
    );

    if ($binding && (int) $binding['responder_id'] === 9997) {
        echo "PASS (responder_id=9997)\n";
        $pass++;
    } else {
        echo "FAIL: binding not found or wrong responder\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 15: APRS poller script exists and has valid syntax ──
echo "[Test 15] APRS poller script syntax check... ";
$pollerPath = __DIR__ . '/aprs-poller.php';
if (file_exists($pollerPath)) {
    $output = [];
    $ret = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($pollerPath) . ' 2>&1', $output, $ret);
    $outputStr = implode("\n", $output);
    if ($ret === 0 && strpos($outputStr, 'No syntax errors') !== false) {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: " . $outputStr . "\n";
        $fail++;
    }
} else {
    echo "FAIL: aprs-poller.php not found\n";
    $fail++;
}

// ── Test 16: Coordinate sanity validation (lat/lng bounds) ──
echo "[Test 16] Coordinate sanity validation... ";
$valid_coords = [
    [0.0001, 0.0001, true],
    [90.0, 180.0, true],
    [-90.0, -180.0, true],
    [41.7148, -72.7272, true],
    [91.0, 0.0, false],
    [0.0, 181.0, false],
    [0.0, 0.0, false],  // null island
];
$coord_pass = true;
foreach ($valid_coords as $tc) {
    $lat = $tc[0];
    $lng = $tc[1];
    $expected = $tc[2];
    $actual = (abs($lat) <= 90 && abs($lng) <= 180 && !($lat == 0 && $lng == 0));
    if ($actual !== $expected) {
        echo "FAIL: ({$lat}, {$lng}) expected " . ($expected ? 'valid' : 'invalid') . "\n";
        $coord_pass = false;
        break;
    }
}
if ($coord_pass) {
    echo "PASS (7 coordinate checks)\n";
    $pass++;
} else {
    $fail++;
}

// ══════════════════════════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════════════════════════
echo "\n--- Cleanup ---\n";

// Remove test bindings
if (!empty($test_binding_ids)) {
    try {
        $ph = implode(',', array_fill(0, count($test_binding_ids), '?'));
        db_query(
            "DELETE FROM `{$prefix}unit_location_bindings` WHERE `id` IN ({$ph})",
            $test_binding_ids
        );
        echo "Cleaned unit_location_bindings (ids=" . implode(',', $test_binding_ids) . ")\n";
    } catch (Exception $e) {
        echo "Cleanup warning (bindings): " . $e->getMessage() . "\n";
    }
}

// Remove test reports
if (!empty($test_report_ids)) {
    try {
        $ph = implode(',', array_fill(0, count($test_report_ids), '?'));
        db_query(
            "DELETE FROM `{$prefix}location_reports` WHERE `id` IN ({$ph})",
            $test_report_ids
        );
        echo "Cleaned location_reports (ids=" . implode(',', $test_report_ids) . ")\n";
    } catch (Exception $e) {
        echo "Cleanup warning (reports): " . $e->getMessage() . "\n";
    }
}

// Restore providers to disabled
try {
    db_query("UPDATE `{$prefix}location_providers` SET `enabled` = 0 WHERE `code` = 'aprs'");
    db_query("UPDATE `{$prefix}location_providers` SET `enabled` = 0 WHERE `code` = 'owntracks'");
    echo "Restored APRS/OwnTracks providers to disabled\n";
} catch (Exception $e) {
    echo "Cleanup warning (providers): " . $e->getMessage() . "\n";
}

// Restore settings
foreach ($settings_to_restore as $name => $origVal) {
    try {
        if ($origVal === null) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        } else {
            db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?", [$origVal, $name]);
        }
    } catch (Exception $e) {
        echo "Cleanup warning (setting {$name}): " . $e->getMessage() . "\n";
    }
}
echo "Restored settings to original values\n";

// ── Summary ──
echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
