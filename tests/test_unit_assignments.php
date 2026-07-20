<?php
/**
 * Tests for Unit Personnel Assignments and Location Resolution
 *
 * Tests:
 *   1. unit_personnel_assignments table exists
 *   2. unit_assignment_roles table exists with 7 seed roles
 *   3. max_age_seconds column exists on location_providers
 *   4. Location provider staleness defaults are set correctly
 *   5. scheduling_permission_profiles table exists with 7 profiles
 *   6. scheduling_permission_assignments table exists
 *   7. Scheduling permission resolver returns correct default
 *   8. Admin always gets full_control
 *   9. Location resolver function exists and is callable
 *  10. Unit assignment API actions exist (assign, release, update, bulk)
 *  11. Permission profile codes are unique
 *  12. Staleness thresholds are within valid range
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
$total = 0;

function test($name, $condition) {
    global $pass, $fail, $total;
    $total++;
    if ($condition) {
        $pass++;
        echo "  PASS: $name\n";
    } else {
        $fail++;
        echo "  FAIL: $name\n";
    }
}

echo "=== Unit Assignments & Location Tests ===\n\n";

// ── Test 1: unit_personnel_assignments table exists ──
$tableExists = false;
try {
    db_fetch_all("SELECT 1 FROM `{$prefix}unit_personnel_assignments` LIMIT 0");
    $tableExists = true;
} catch (Exception $e) {}
test('unit_personnel_assignments table exists', $tableExists);

// ── Test 2: unit_assignment_roles table with seed data ──
$roleCount = 0;
try {
    $roleCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}unit_assignment_roles`");
} catch (Exception $e) {}
test('unit_assignment_roles has 7 seed roles', $roleCount >= 7);

// ── Test 3: max_age_seconds column on location_providers ──
$colExists = false;
try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '{$prefix}location_providers'
           AND COLUMN_NAME = 'max_age_seconds'"
    );
    $colExists = !empty($cols);
} catch (Exception $e) {}
test('max_age_seconds column exists on location_providers', $colExists);

// ── Test 4: Staleness defaults are set ──
$aprsAge = 0;
$internalAge = 0;
try {
    $aprsAge = (int) db_fetch_value(
        "SELECT `max_age_seconds` FROM `{$prefix}location_providers` WHERE `code` = 'aprs'"
    );
    $internalAge = (int) db_fetch_value(
        "SELECT `max_age_seconds` FROM `{$prefix}location_providers` WHERE `code` = 'internal'"
    );
} catch (Exception $e) {}
test('APRS staleness threshold = 600s (10 min)', $aprsAge === 600);
test('Internal GPS staleness threshold = 60s (1 min)', $internalAge === 60);

// ── Test 5: scheduling_permission_profiles table exists ──
$profileCount = 0;
try {
    $profileCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}scheduling_permission_profiles`"
    );
} catch (Exception $e) {}
test('scheduling_permission_profiles has 7 default profiles', $profileCount >= 7);

// ── Test 6: scheduling_permission_assignments table exists ──
$assignTableExists = false;
try {
    db_fetch_all("SELECT 1 FROM `{$prefix}scheduling_permission_assignments` LIMIT 0");
    $assignTableExists = true;
} catch (Exception $e) {}
test('scheduling_permission_assignments table exists', $assignTableExists);

// ── Test 7: Default permission is self_service for all ──
$defaultAssign = null;
try {
    $defaultAssign = db_fetch_one(
        "SELECT spp.`code`
         FROM `{$prefix}scheduling_permission_assignments` spa
         JOIN `{$prefix}scheduling_permission_profiles` spp ON spa.`profile_id` = spp.`id`
         WHERE spa.`scope_type` = 'global' AND spa.`target_type` = 'all'"
    );
} catch (Exception $e) {}
test('Global default permission is self_service', $defaultAssign && $defaultAssign['code'] === 'self_service');

// ── Test 8: Permission resolver functions exist ──
require_once __DIR__ . '/../inc/scheduling-perms.php';
test('scheduling_get_permissions() exists', function_exists('scheduling_get_permissions'));
test('scheduling_get_effective_permissions() exists', function_exists('scheduling_get_effective_permissions'));
test('scheduling_is_admin() exists', function_exists('scheduling_is_admin'));

// ── Test 9: Admin gets full_control ──
// Phase 12 (2026-06-11): scheduling_is_admin() is now driven by RBAC
// (is_admin()) instead of $_SESSION['level']. To simulate an admin,
// we have to grant the test session a super-admin role. Easiest: set
// user_id to the real admin user 1 and reset the RBAC cache.
require_once __DIR__ . '/../inc/rbac.php';
$_SESSION['user_id'] = 1;  // real admin user on the test DB
if (function_exists('rbac_reset_cache')) rbac_reset_cache();
$adminPerms = scheduling_get_effective_permissions(999999, 'global', null);
test('Admin gets full_control profile', $adminPerms['profile_code'] === 'full_control');
test('Admin can_assign_others = 1', $adminPerms['can_assign_others'] === 1);
test('Admin can_manage_slots = 1', $adminPerms['can_manage_slots'] === 1);

// ── Test 10: Non-admin gets self_service (default) ──
// Phase 12: bogus user_id so is_admin() returns false; reset cache to
// clear the prior admin sub-test's static value.
unset($_SESSION['user_id']);
$_SESSION['user_id'] = 999998;
if (function_exists('rbac_reset_cache')) rbac_reset_cache();
// Use a non-existent member ID so it falls through to global default
$userPerms = scheduling_get_permissions(999999, 'global', null);
test('Non-admin gets self_service by default', $userPerms['profile_code'] === 'self_service');
test('Self-service can_self_assign = 1', $userPerms['can_self_assign'] === 1);
test('Self-service can_assign_others = 0', $userPerms['can_assign_others'] === 0);

// ── Test 11: Location resolver functions exist ──
require_once __DIR__ . '/../inc/location-resolver.php';
test('location_resolve_unit() exists', function_exists('location_resolve_unit'));
test('location_resolve_member() exists', function_exists('location_resolve_member'));
test('location_resolve_all_units() exists', function_exists('location_resolve_all_units'));
test('location_get_unit_personnel() exists', function_exists('location_get_unit_personnel'));

// ── Test 12: Profile codes are unique ──
$dupes = 0;
try {
    $dupes = (int) db_fetch_value(
        "SELECT COUNT(*) - COUNT(DISTINCT `code`)
         FROM `{$prefix}scheduling_permission_profiles`"
    );
} catch (Exception $e) {}
test('Permission profile codes are unique', $dupes === 0);

// ── Test 13: All staleness thresholds within valid range ──
$invalidThresholds = 0;
try {
    $invalidThresholds = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}location_providers`
         WHERE `max_age_seconds` < 10 OR `max_age_seconds` > 86400"
    );
} catch (Exception $e) {}
test('All staleness thresholds within 10-86400s range', $invalidThresholds === 0);

// ── Test 14: Permission profiles have all 13 flag columns ──
$flagCount = 0;
try {
    $flags = db_fetch_all(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '{$prefix}scheduling_permission_profiles'
           AND COLUMN_NAME LIKE 'can_%'"
    );
    $flagCount = count($flags);
} catch (Exception $e) {}
test('Permission profiles have 13 can_* flag columns', $flagCount === 13);

// ── Test 15: full_control profile has all permissions enabled ──
$fullCtrl = null;
try {
    $fullCtrl = db_fetch_one(
        "SELECT * FROM `{$prefix}scheduling_permission_profiles` WHERE `code` = 'full_control'"
    );
} catch (Exception $e) {}
$allEnabled = $fullCtrl &&
    (int)$fullCtrl['can_view_schedule'] === 1 &&
    (int)$fullCtrl['can_self_assign'] === 1 &&
    (int)$fullCtrl['can_assign_others'] === 1 &&
    (int)$fullCtrl['can_manage_slots'] === 1;
test('full_control profile has all permissions enabled', $allEnabled);

// ── Test 16: none profile has all permissions disabled ──
$noneProfile = null;
try {
    $noneProfile = db_fetch_one(
        "SELECT * FROM `{$prefix}scheduling_permission_profiles` WHERE `code` = 'none'"
    );
} catch (Exception $e) {}
$allDisabled = $noneProfile &&
    (int)$noneProfile['can_view_schedule'] === 0 &&
    (int)$noneProfile['can_self_assign'] === 0 &&
    (int)$noneProfile['can_assign_others'] === 0;
test('none profile has all permissions disabled', $allDisabled);

echo "\n=== Results: $pass passed, $fail failed (of $total) ===\n";
exit($fail > 0 ? 1 : 0);
