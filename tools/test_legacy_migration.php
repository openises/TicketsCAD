<?php
/**
 * NewUI v4.0 — Legacy Migration Tests
 *
 * Run from CLI:
 *   php test_legacy_migration.php
 *
 * Tests:
 *   1. Can read legacy settings table
 *   2. Mapping produces correct NewUI keys
 *   3. Dry run doesn't modify data
 *   4. Migration inserts settings
 *   5. Duplicate run doesn't overwrite
 */

// Fresh-install guard (QA automation 2026-07-07): this suite migrates
// settings out of a legacy /tickets install. Without one, skip cleanly.
$__legacyCfg = __DIR__ . '/../../tickets/incs/mysql.inc.php';
if (!file_exists($__legacyCfg)) {
    echo "SKIP: no legacy tickets/ install found — nothing to migrate\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

require_once __DIR__ . '/migrate_legacy_settings.php';

$passed = 0;
$failed = 0;
$total  = 0;

function test($name, $condition, $detail = '') {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        echo "  PASS: {$name}\n";
        $passed++;
    } else {
        echo "  FAIL: {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

echo "=== Legacy Migration Tests ===\n\n";

// ── Test 1: Can read legacy settings table ───────────────────────
echo "Test 1: Read legacy settings table\n";
try {
    $legacySettings = read_legacy_settings();
    test('Legacy settings readable', true);
    test('Returns array', is_array($legacySettings));
    test('Has at least one setting', count($legacySettings) > 0, 'Count: ' . count($legacySettings));
    echo "  INFO: Found " . count($legacySettings) . " legacy settings\n";
} catch (Exception $e) {
    test('Legacy settings readable', false, $e->getMessage());
    echo "  SKIP: Remaining Test 1 checks skipped\n";
}

echo "\n";

// ── Test 2: Key mapping produces correct NewUI keys ──────────────
echo "Test 2: Key mapping correctness\n";

// Test explicit mappings
test('_title maps to org_name', resolve_newui_key('_title') === 'org_name');
test('_location maps to org_location', resolve_newui_key('_location') === 'org_location');
test('_email maps to email_from', resolve_newui_key('_email') === 'email_from');
test('_email_name maps to email_from_name', resolve_newui_key('_email_name') === 'email_from_name');
test('_smtp_host maps to smtp_host', resolve_newui_key('_smtp_host') === 'smtp_host');
test('_smtp_port maps to smtp_port', resolve_newui_key('_smtp_port') === 'smtp_port');
test('_smtp_user maps to smtp_user', resolve_newui_key('_smtp_user') === 'smtp_user');
test('_smtp_pass maps to smtp_pass', resolve_newui_key('_smtp_pass') === 'smtp_pass');
test('_smtp_secure maps to smtp_encryption', resolve_newui_key('_smtp_secure') === 'smtp_encryption');
test('_map_lat maps to default_lat', resolve_newui_key('_map_lat') === 'default_lat');
test('_map_lng maps to default_lng', resolve_newui_key('_map_lng') === 'default_lng');
test('_map_zoom maps to default_zoom', resolve_newui_key('_map_zoom') === 'default_zoom');
test('_timezone maps to timezone', resolve_newui_key('_timezone') === 'timezone');
test('_inc_num maps to incident_numbers', resolve_newui_key('_inc_num') === 'incident_numbers');
test('_version maps to legacy_version', resolve_newui_key('_version') === 'legacy_version');

// Test prefix-based mappings
test('_sms_provider maps to sms_provider', resolve_newui_key('_sms_provider') === 'sms_provider');
test('_sms_api_key maps to sms_api_key', resolve_newui_key('_sms_api_key') === 'sms_api_key');
test('_mail_footer maps to email_footer', resolve_newui_key('_mail_footer') === 'email_footer');
test('_mail_template maps to email_template', resolve_newui_key('_mail_template') === 'email_template');

// Test unmapped keys return null
test('Unknown key returns null', resolve_newui_key('_unknown_random_key') === null);
test('Empty prefix _sms alone returns null', resolve_newui_key('_sms') === null);

echo "\n";

// ── Test 3: Dry run doesn't modify data ──────────────────────────
echo "Test 3: Dry run (build_migration_plan) is read-only\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Snapshot current NewUI settings count
try {
    $beforeCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings`");
} catch (Exception $e) {
    $beforeCount = -1;
}

$plan = build_migration_plan();

try {
    $afterCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings`");
} catch (Exception $e) {
    $afterCount = -1;
}

test('Plan returns mapped array', is_array($plan['mapped']));
test('Plan returns skipped array', is_array($plan['skipped']));
test('Settings count unchanged after dry run',
    $beforeCount === $afterCount,
    "Before: {$beforeCount}, After: {$afterCount}"
);

echo "\n";

// ── Test 4: Migration inserts settings ───────────────────────────
echo "Test 4: Migration inserts settings\n";

// Insert a test setting into legacy DB to verify round-trip
// We use a unique key that won't collide with real data
$testLegacyKey = '_test_migration_' . time();
$testValue     = 'test_value_' . time();
$testNewuiKey  = null;

// We can't write to legacy DB (read-only policy), so we test with
// whatever mapped settings exist in the plan.

$mappedCount = 0;
$readyCount  = 0;
foreach ($plan['mapped'] as $item) {
    $mappedCount++;
    if (!$item['conflict']) {
        $readyCount++;
    }
}

test('Plan has mapped settings', $mappedCount > 0, "Found {$mappedCount} mapped");

if ($readyCount > 0) {
    // Execute migration
    $result = execute_migration();
    test('Migration returns inserted count', isset($result['inserted']));
    test('Migration inserted at least one', $result['inserted'] > 0,
        "Inserted: {$result['inserted']}");
    test('No migration errors', empty($result['errors']),
        !empty($result['errors']) ? implode('; ', $result['errors']) : '');

    echo "  INFO: Inserted {$result['inserted']}, conflicts {$result['skipped_conflict']}, unmapped {$result['skipped_unmapped']}\n";
} else {
    echo "  INFO: All mapped settings already exist (conflict). Testing with existing data.\n";
    // Still run to verify it doesn't crash
    $result = execute_migration();
    test('Migration handles all-conflict gracefully', $result['inserted'] === 0);
    test('No migration errors', empty($result['errors']));
}

echo "\n";

// ── Test 5: Duplicate run doesn't overwrite ──────────────────────
echo "Test 5: Duplicate run is idempotent\n";

// If we inserted settings in Test 4, change one and verify it isn't overwritten
$plan2 = build_migration_plan();

$hasConflict = false;
foreach ($plan2['mapped'] as $item) {
    if ($item['conflict']) {
        $hasConflict = true;
        break;
    }
}
test('After migration, previously-inserted settings show as conflicts', $hasConflict);

// Run migration again
$result2 = execute_migration();
test('Second migration inserts zero new settings', $result2['inserted'] === 0,
    "Inserted: {$result2['inserted']}");

// Verify a known setting wasn't overwritten
// Pick the first mapped+conflict item and verify its value matches the NewUI value
$verifyOk = true;
foreach ($plan2['mapped'] as $item) {
    if ($item['conflict']) {
        try {
            $currentVal = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?",
                [$item['newui_key']]
            );
            // The existing value should match what was there before (not replaced by legacy value)
            if ($currentVal !== $item['existing']) {
                $verifyOk = false;
            }
        } catch (Exception $e) {
            $verifyOk = false;
        }
        break; // Only need to check one
    }
}
test('Existing settings not overwritten by duplicate run', $verifyOk);

echo "\n";

// ── Summary ──────────────────────────────────────────────────────
echo "================================\n";
echo "Results: {$passed} passed, {$failed} failed, {$total} total\n";
echo "================================\n";

exit($failed > 0 ? 1 : 0);
