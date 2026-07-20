<?php
/**
 * Tests for Login Audit Trail
 *
 * Verifies that:
 *   1. Failed login attempts are recorded in login_attempts table
 *   2. Failed attempts are NOT deleted when a successful login occurs
 *   3. Failed attempts are soft-cleared (cleared_at set) instead of deleted
 *   4. Audit log records login_failed events
 *   5. Lockout counter correctly ignores cleared attempts
 *   6. The login_attempts table has the cleared_at column
 *   7. The audit log has login-related entries
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/login-security.php';
require_once __DIR__ . '/../inc/audit.php';

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

echo "=== Login Audit Trail Tests ===\n\n";

// ── Test 1: login_attempts table exists ──
$tableExists = false;
try {
    db_fetch_all("SELECT 1 FROM `{$prefix}login_attempts` LIMIT 0");
    $tableExists = true;
} catch (Exception $e) {}
test('login_attempts table exists', $tableExists);

// ── Test 2: cleared_at column exists ──
$colExists = false;
try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '{$prefix}login_attempts'
           AND COLUMN_NAME = 'cleared_at'"
    );
    $colExists = !empty($cols);
} catch (Exception $e) {}
test('cleared_at column exists on login_attempts', $colExists);

// ── Test 3: Record a failed attempt ──
$testUser = '__test_audit_user_' . time();
$recorded = ls_record_attempt($testUser, false, '10.99.99.99', 'wrong_password');
test('Failed login attempt recorded successfully', $recorded);

// ── Test 4: Failed attempt exists in table ──
$failedRow = null;
try {
    $failedRow = db_fetch_one(
        "SELECT * FROM `{$prefix}login_attempts`
         WHERE `username` = ? AND `success` = 0",
        [$testUser]
    );
} catch (Exception $e) {}
test('Failed attempt found in login_attempts table', $failedRow !== null);
test('Failed attempt has correct failure_reason', $failedRow && $failedRow['failure_reason'] === 'wrong_password');
test('Failed attempt has correct IP address', $failedRow && $failedRow['ip_address'] === '10.99.99.99');

// ── Test 5: Failure count is 1 ──
$failCount = ls_get_recent_failure_count($testUser);
test('Recent failure count is 1', $failCount === 1);

// ── Test 6: Clear attempts (simulates successful login) ──
$cleared = ls_clear_attempts($testUser);
test('ls_clear_attempts() returned true', $cleared);

// ── Test 7: Failed attempt is NOT deleted (soft-clear) ──
$afterClear = null;
try {
    $afterClear = db_fetch_one(
        "SELECT * FROM `{$prefix}login_attempts`
         WHERE `username` = ? AND `success` = 0",
        [$testUser]
    );
} catch (Exception $e) {}
test('Failed attempt still exists after clear (audit trail preserved)', $afterClear !== null);

// ── Test 8: Failed attempt has cleared_at set ──
$hasClearedAt = $afterClear && !empty($afterClear['cleared_at']);
test('Failed attempt has cleared_at timestamp set', $hasClearedAt);

// ── Test 9: Failure count is now 0 (cleared attempts excluded from lockout) ──
$failCountAfterClear = ls_get_recent_failure_count($testUser);
test('Recent failure count is 0 after clear', $failCountAfterClear === 0);

// ── Test 10: Account is not locked after clear ──
$isLocked = ls_is_locked($testUser);
test('Account is not locked after clearing attempts', !$isLocked);

// ── Test 11: Record multiple failures up to lockout ──
$settings = ls_get_settings();
$maxAttempts = $settings['max_attempts'];
for ($i = 0; $i < $maxAttempts; $i++) {
    ls_record_attempt($testUser, false, '10.99.99.99', 'wrong_password');
}
$isLockedNow = ls_is_locked($testUser);
test("Account is locked after {$maxAttempts} failed attempts", $isLockedNow);

// ── Test 12: Clear attempts unlocks the account ──
ls_clear_attempts($testUser);
$isLockedAfterClear = ls_is_locked($testUser);
test('Account is unlocked after clearing attempts', !$isLockedAfterClear);

// ── Test 13: All failed attempts still in table (audit trail preserved) ──
$allAttempts = [];
try {
    $allAttempts = db_fetch_all(
        "SELECT * FROM `{$prefix}login_attempts` WHERE `username` = ? AND `success` = 0",
        [$testUser]
    );
} catch (Exception $e) {}
// Should be 1 (from test 3) + maxAttempts (from test 11) = maxAttempts + 1
$expectedCount = $maxAttempts + 1;
test("All {$expectedCount} failed attempts preserved in audit trail", count($allAttempts) === $expectedCount);

// ── Test 14: audit_login() records to audit log ──
$auditRecorded = audit_login(null, $testUser, 'login_failed', "Test failed login for '{$testUser}'", [
    'ip' => '10.99.99.99',
]);
test('audit_login() records login_failed event', $auditRecorded);

// ── Test 15: Audit log entry exists ──
// Note: for failed logins (user_id=null), the username is stored in the details JSON
// not in the user_name column (which is null for pre-auth events)
$auditEntry = null;
try {
    $auditEntry = db_fetch_one(
        "SELECT * FROM `{$prefix}newui_audit_log`
         WHERE `category` = 'auth' AND `activity` = 'login_failed'
           AND `summary` LIKE ?
         ORDER BY `id` DESC LIMIT 1",
        ['%' . $testUser . '%']
    );
} catch (Exception $e) {}
test('Audit log entry found for login_failed', $auditEntry !== null);
test('Audit log entry has correct severity (MEDIUM=3)', $auditEntry && (int) $auditEntry['severity'] === 3);

// ── Test 16: Record a successful attempt ──
$successRecorded = ls_record_attempt($testUser, true, '10.99.99.99');
test('Successful login attempt recorded', $successRecorded);

// ── Test 17: Both success and failure entries coexist ──
$successRow = null;
try {
    $successRow = db_fetch_one(
        "SELECT * FROM `{$prefix}login_attempts`
         WHERE `username` = ? AND `success` = 1",
        [$testUser]
    );
} catch (Exception $e) {}
test('Successful attempt found alongside failed attempts', $successRow !== null);

// ── Cleanup test data ──
try {
    db_query("DELETE FROM `{$prefix}login_attempts` WHERE `username` = ?", [$testUser]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `summary` LIKE ?", ['%' . $testUser . '%']);
} catch (Exception $e) {}

echo "\n=== Results: $pass passed, $fail failed (of $total) ===\n";
exit($fail > 0 ? 1 : 0);
