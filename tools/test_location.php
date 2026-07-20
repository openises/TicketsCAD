<?php
/**
 * Location Providers Integration Tests
 *
 * Usage: php tools/test_location.php
 *
 * Tests table creation, provider seeding, location report ingestion,
 * unit bindings, and priority resolution. Cleans up all test data.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

echo "=== Location Providers Integration Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

// Track IDs for cleanup
$test_report_ids  = [];
$test_binding_ids = [];

// ── Test 1: location_providers table exists with correct columns ──
echo "[Test 1] location_providers table exists with correct columns... ";
try {
    $row = db_fetch_one(
        "SELECT `id`, `code`, `name`, `enabled`, `priority`,
                `config_json`, `icon`, `color`, `created_at`
         FROM `{$prefix}location_providers`
         LIMIT 1"
    );
    echo "PASS\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 2: location_reports table exists with correct columns ──
echo "[Test 2] location_reports table exists with correct columns... ";
try {
    db_query(
        "SELECT `id`, `provider_id`, `unit_identifier`, `lat`, `lng`,
                `altitude`, `speed`, `heading`, `accuracy`, `battery`,
                `raw_data`, `reported_at`, `received_at`
         FROM `{$prefix}location_reports`
         LIMIT 0"
    );
    echo "PASS\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 3: unit_location_bindings table exists with correct columns ──
echo "[Test 3] unit_location_bindings table exists with correct columns... ";
try {
    db_query(
        "SELECT `id`, `responder_id`, `provider_id`, `unit_identifier`,
                `priority`, `active`, `created_at`
         FROM `{$prefix}unit_location_bindings`
         LIMIT 0"
    );
    echo "PASS\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 4: Default providers seeded (7 providers) ──
echo "[Test 4] Default providers seeded (7 providers)... ";
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}location_providers`");
    if ($count >= 7) {
        echo "PASS ({$count} providers)\n";
        $pass++;
    } else {
        echo "FAIL: expected >= 7, got {$count}\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 5: Verify expected provider codes exist ──
echo "[Test 5] Expected provider codes exist... ";
try {
    $codes = ['aprs', 'meshtastic', 'owntracks', 'opengts', 'dmr', 'internal', 'google_lat'];
    $missing = [];
    foreach ($codes as $code) {
        $found = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}location_providers` WHERE `code` = ?",
            [$code]
        );
        if ((int) $found === 0) {
            $missing[] = $code;
        }
    }
    if (empty($missing)) {
        echo "PASS (all 7 codes found)\n";
        $pass++;
    } else {
        echo "FAIL: missing codes: " . implode(', ', $missing) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 6: Only internal GPS is enabled by default ──
echo "[Test 6] Only internal GPS enabled by default... ";
try {
    $enabled = db_fetch_all(
        "SELECT `code` FROM `{$prefix}location_providers` WHERE `enabled` = 1"
    );
    $enabledCodes = array_column($enabled, 'code');
    if (count($enabledCodes) === 1 && $enabledCodes[0] === 'internal') {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: enabled providers: " . implode(', ', $enabledCodes) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Get the 'internal' provider ID for use in later tests ──
$internalProvider = null;
try {
    $internalProvider = db_fetch_one(
        "SELECT `id`, `code` FROM `{$prefix}location_providers` WHERE `code` = 'internal'"
    );
} catch (Exception $e) {
    // Will cause later tests to fail
}

// ── Enable APRS for multi-provider tests ──
$aprsProvider = null;
try {
    $aprsProvider = db_fetch_one(
        "SELECT `id`, `code`, `priority` FROM `{$prefix}location_providers` WHERE `code` = 'aprs'"
    );
    if ($aprsProvider) {
        db_query(
            "UPDATE `{$prefix}location_providers` SET `enabled` = 1 WHERE `id` = ?",
            [(int) $aprsProvider['id']]
        );
    }
} catch (Exception $e) {
    // Will affect priority test
}

// ── Test 7: Insert a location report ──
echo "[Test 7] Insert location report... ";
try {
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}location_reports`
         (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`, `speed`,
          `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [(int) $internalProvider['id'], 'TEST-UNIT-001', 40.7128000, -74.0060000,
         10.5, 5.2, 180.0, 15.0, 85, '{"test":"internal_gps"}', $now]
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

// ── Test 8: Query latest position for a unit ──
echo "[Test 8] Query latest position for unit... ";
try {
    $pos = db_fetch_one(
        "SELECT lr.`unit_identifier`, lr.`lat`, lr.`lng`, lr.`battery`,
                lp.`code` AS `provider_code`
         FROM `{$prefix}location_reports` lr
         JOIN `{$prefix}location_providers` lp ON lr.`provider_id` = lp.`id`
         WHERE lr.`unit_identifier` = ?
         ORDER BY lr.`reported_at` DESC
         LIMIT 1",
        ['TEST-UNIT-001']
    );
    if ($pos && abs((float) $pos['lat'] - 40.7128) < 0.001 && (int) $pos['battery'] === 85) {
        echo "PASS (lat={$pos['lat']}, battery={$pos['battery']})\n";
        $pass++;
    } else {
        echo "FAIL: unexpected position data\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 9: Bind a responder to a provider ──
echo "[Test 9] Bind responder to provider... ";
try {
    // Use responder_id = 9999 (test placeholder — does not need to exist for FK test)
    db_query(
        "INSERT INTO `{$prefix}unit_location_bindings`
         (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
         VALUES (?, ?, ?, ?, 1)",
        [9999, (int) $internalProvider['id'], 'TEST-UNIT-001', 50]
    );
    $bindId = (int) db_insert_id();
    $test_binding_ids[] = $bindId;
    if ($bindId > 0) {
        echo "PASS (binding id={$bindId})\n";
        $pass++;
    } else {
        echo "FAIL: no insert ID\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 10: Priority resolution — two providers, highest priority wins ──
echo "[Test 10] Priority resolution (two providers, highest priority wins)... ";
try {
    // Insert a second report from APRS (priority 10, lower=higher)
    // with slightly different coordinates
    $aprsTime = date('Y-m-d H:i:s', strtotime('+1 second'));
    db_query(
        "INSERT INTO `{$prefix}location_reports`
         (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`, `speed`,
          `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [(int) $aprsProvider['id'], 'TEST-UNIT-001', 40.7200000, -74.0100000,
         12.0, 0.0, 90.0, 50.0, null, '{"test":"aprs_report"}', $aprsTime]
    );
    $aprsReportId = (int) db_insert_id();
    $test_report_ids[] = $aprsReportId;

    // Also bind responder to APRS provider
    db_query(
        "INSERT INTO `{$prefix}unit_location_bindings`
         (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
         VALUES (?, ?, ?, ?, 1)",
        [9999, (int) $aprsProvider['id'], 'TEST-UNIT-001', 50]
    );
    $aprsBindId = (int) db_insert_id();
    $test_binding_ids[] = $aprsBindId;

    // Query with priority resolution: ORDER BY provider priority ASC, pick first
    $best = db_fetch_one(
        "SELECT lr.`lat`, lr.`lng`, lp.`code` AS `provider_code`, lp.`priority`
         FROM `{$prefix}location_reports` lr
         JOIN `{$prefix}location_providers` lp ON lr.`provider_id` = lp.`id`
         WHERE lr.`unit_identifier` = ?
           AND lp.`enabled` = 1
         ORDER BY lp.`priority` ASC, lr.`reported_at` DESC
         LIMIT 1",
        ['TEST-UNIT-001']
    );

    // APRS has priority 10 (lower=higher), internal has 60, so APRS should win
    if ($best && $best['provider_code'] === 'aprs' && abs((float) $best['lat'] - 40.72) < 0.001) {
        echo "PASS (resolved to {$best['provider_code']}, priority={$best['priority']})\n";
        $pass++;
    } else {
        $code = $best['provider_code'] ?? 'null';
        echo "FAIL: expected aprs, got {$code}\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 11: Deactivate binding and verify it's excluded ──
echo "[Test 11] Deactivate binding excludes from all_units query... ";
try {
    // Deactivate the APRS binding
    db_query(
        "UPDATE `{$prefix}unit_location_bindings` SET `active` = 0 WHERE `id` = ?",
        [$aprsBindId]
    );

    // Query active bindings for responder 9999
    $activeBindings = db_fetch_all(
        "SELECT b.`unit_identifier`, lp.`code`
         FROM `{$prefix}unit_location_bindings` b
         JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
         WHERE b.`responder_id` = 9999 AND b.`active` = 1"
    );

    if (count($activeBindings) === 1 && $activeBindings[0]['code'] === 'internal') {
        echo "PASS (1 active binding: internal)\n";
        $pass++;
    } else {
        echo "FAIL: expected 1 active binding, got " . count($activeBindings) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
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

// Restore APRS to disabled
if ($aprsProvider) {
    try {
        db_query(
            "UPDATE `{$prefix}location_providers` SET `enabled` = 0 WHERE `id` = ?",
            [(int) $aprsProvider['id']]
        );
        echo "Restored APRS provider to disabled\n";
    } catch (Exception $e) {
        echo "Cleanup warning (aprs restore): " . $e->getMessage() . "\n";
    }
}

// ── Summary ──
echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
