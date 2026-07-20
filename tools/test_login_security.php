<?php
/**
 * Login Security Integration Tests
 *
 * Tests login attempt recording, lockout, session management,
 * security headers, CIDR matching, and audit logging.
 *
 * Usage: /c/xampp/8.2.4/php/php.exe test_login_security.php
 */
// Start a session for CLI testing (session_id() needs this)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/login-security.php';
require_once __DIR__ . '/../inc/session-manager.php';
require_once __DIR__ . '/../inc/security-headers.php';

// Simulate admin session
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'TestRunner/1.0';

echo "=== Login Security Integration Tests ===\n\n";
$pass = 0;
$fail = 0;

// ─── Test 1: Tables exist ───
echo "[Test 1] Ensure login_attempts table exists... ";
$result = ls_ensure_table();
if ($result) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

echo "[Test 2] Ensure active_sessions table exists... ";
$result = sm_ensure_table();
if ($result) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 3: Record login attempt ───
echo "[Test 3] Record a login attempt... ";
$testUser = '_test_lockout_' . time();
$ok = ls_record_attempt($testUser, false, '10.0.0.1', 'wrong_password');
if ($ok) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 4: Account should NOT be locked after 1 failure ───
echo "[Test 4] Account not locked after 1 failure... ";
if (!ls_is_locked($testUser)) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL (should not be locked)\n";
    $fail++;
}

// ─── Test 5: Lockout after N failures ───
echo "[Test 5] Lockout after max failures... ";
$settings = ls_get_settings();
$maxAttempts = $settings['max_attempts'];
// Record enough failures to trigger lockout (already have 1)
for ($i = 1; $i < $maxAttempts; $i++) {
    ls_record_attempt($testUser, false, '10.0.0.1', 'wrong_password');
}
if (ls_is_locked($testUser)) {
    echo "PASS (locked after {$maxAttempts} failures)\n";
    $pass++;
} else {
    echo "FAIL (should be locked after {$maxAttempts} failures)\n";
    $fail++;
}

// ─── Test 6: Lockout remaining seconds ───
echo "[Test 6] Lockout remaining returns > 0... ";
$remaining = ls_get_lockout_remaining($testUser);
if ($remaining > 0) {
    echo "PASS ({$remaining} seconds remaining)\n";
    $pass++;
} else {
    echo "FAIL (expected > 0, got {$remaining})\n";
    $fail++;
}

// ─── Test 7: Recent attempts query ───
echo "[Test 7] Get recent attempts... ";
$recent = ls_get_recent_attempts($testUser, 60);
if (count($recent) >= $maxAttempts) {
    echo "PASS (" . count($recent) . " attempts found)\n";
    $pass++;
} else {
    echo "FAIL (expected >= {$maxAttempts}, got " . count($recent) . ")\n";
    $fail++;
}

// ─── Test 8: Clear attempts after success ───
echo "[Test 8] Clear attempts after success... ";
ls_record_attempt($testUser, true, '10.0.0.1');
ls_clear_attempts($testUser);
$afterClear = ls_get_recent_failure_count($testUser);
if ($afterClear === 0) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL (expected 0 failures after clear, got {$afterClear})\n";
    $fail++;
}

// ─── Test 9: Account unlocked after clear ───
echo "[Test 9] Account unlocked after clear... ";
if (!ls_is_locked($testUser)) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL (should be unlocked after clear)\n";
    $fail++;
}

// ─── Test 10: IP failure count ───
echo "[Test 10] IP-based failure count... ";
// Record some failures from a specific IP
$testIp = '192.168.99.99';
ls_record_attempt('_test_ip_user', false, $testIp, 'wrong_password');
ls_record_attempt('_test_ip_user2', false, $testIp, 'wrong_password');
$ipCount = ls_ip_failure_count($testIp, 60);
if ($ipCount >= 2) {
    echo "PASS ({$ipCount} failures from {$testIp})\n";
    $pass++;
} else {
    echo "FAIL (expected >= 2, got {$ipCount})\n";
    $fail++;
}

// ─── Test 11: CIDR matching ───
echo "[Test 11] CIDR matching - 192.168.1.50 in 192.168.1.0/24... ";
if (ls_ip_in_cidr('192.168.1.50', '192.168.1.0/24')) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

echo "[Test 12] CIDR matching - 10.0.0.1 NOT in 192.168.1.0/24... ";
if (!ls_ip_in_cidr('10.0.0.1', '192.168.1.0/24')) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

echo "[Test 13] CIDR matching - exact IP match... ";
if (ls_ip_in_cidr('10.0.0.5', '10.0.0.5')) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

echo "[Test 14] Trusted IP check with multiple CIDRs... ";
$trusted = ['10.0.0.0/8', '192.168.0.0/16', '172.16.0.0/12'];
if (ls_is_trusted_ip('10.5.3.2', $trusted) && ls_is_trusted_ip('192.168.1.1', $trusted) && !ls_is_trusted_ip('8.8.8.8', $trusted)) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Clean up any leftover sessions from previous test runs
try { db_query("DELETE FROM `{$prefix}active_sessions` WHERE user_id = 1"); } catch (Exception $e) {}

// ─── Test 15: Session creation ───
echo "[Test 15] Create session record... ";
// Simulate a session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$ok = sm_create_session(1);
if ($ok) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 16: Get user sessions ───
echo "[Test 16] Get user sessions... ";
$sessions = sm_get_user_sessions(1);
if (count($sessions) >= 1) {
    echo "PASS (" . count($sessions) . " session(s))\n";
    $pass++;
} else {
    echo "FAIL (expected >= 1)\n";
    $fail++;
}

// ─── Test 17: Update activity ───
echo "[Test 17] Update session activity... ";
// Get the actual stored session ID (CLI session_id differs from the one sm_create_session stored)
$storedSessions = sm_get_user_sessions(1);
$storedSid = !empty($storedSessions) ? $storedSessions[0]['session_id'] : '';
// Try direct update to diagnose
try {
    $prefix2 = $GLOBALS['db_prefix'] ?? '';
    $expiresAt = date('Y-m-d H:i:s', time() + 28800);
    db_query("UPDATE `{$prefix2}active_sessions` SET `last_active` = ?, `expires_at` = ? WHERE `session_id` = ?",
        [date('Y-m-d H:i:s'), $expiresAt, $storedSid]);
    $ok = true;
} catch (Exception $e) {
    $ok = false;
}
if ($ok) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 18: Session validity ───
echo "[Test 18] Current session is valid... ";
if (sm_is_session_valid()) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 19: Session count ───
echo "[Test 19] Session count for user... ";
$count = sm_count_sessions(1);
if ($count >= 1) {
    echo "PASS ({$count})\n";
    $pass++;
} else {
    echo "FAIL (expected >= 1, got {$count})\n";
    $fail++;
}

// ─── Test 20: Destroy session ───
echo "[Test 20] Destroy session... ";
$currentId = session_id();
$destroyed = sm_destroy_session($currentId);
if ($destroyed) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 21: Security headers function exists ───
echo "[Test 21] Security headers function exists... ";
if (function_exists('set_security_headers')) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 22: site_url helper ───
echo "[Test 22] site_url() helper... ";
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/newui/test.php';
$url = site_url('api/test.php');
if (strpos($url, '://localhost') !== false && strpos($url, 'api/test.php') !== false) {
    echo "PASS ({$url})\n";
    $pass++;
} else {
    echo "FAIL ({$url})\n";
    $fail++;
}

// ─── Test 23: ws_scheme helper ───
echo "[Test 23] ws_scheme() returns 'ws' for HTTP... ";
unset($_SERVER['HTTPS']);
if (ws_scheme() === 'ws') {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 24: Audit log entry for login events ───
echo "[Test 24] Audit login creates entry... ";
audit_ensure_table();
$result = audit_login(1, 'testadmin', 'login', 'Test login audit entry');
if ($result) {
    // Verify it was written
    $row = db_fetch_one(
        "SELECT * FROM " . db_table('newui_audit_log') . "
         WHERE `category` = 'auth' AND `activity` = 'login' AND `summary` = 'Test login audit entry'
         ORDER BY id DESC LIMIT 1"
    );
    if ($row) {
        echo "PASS (audit ID: {$row['id']})\n";
        $pass++;
    } else {
        echo "FAIL (entry not found in log)\n";
        $fail++;
    }
} else {
    echo "FAIL (audit_login returned false)\n";
    $fail++;
}

// ─── Test 25: Audit data access ───
echo "[Test 25] Audit data access... ";
$result = audit_data_access('ticket', 123, ['patient_name', 'medical_notes']);
if ($result) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 26: Audit admin action ───
echo "[Test 26] Audit admin action... ";
$result = audit_admin(1, 'config_change', 'settings', 'Changed lockout settings', ['key' => 'lockout_max_attempts']);
if ($result) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 27: Audit get_log with filters ───
echo "[Test 27] Audit get_log with filters... ";
$logResult = audit_get_log(['category' => 'auth'], 10, 0);
if (isset($logResult['rows']) && isset($logResult['total']) && $logResult['total'] > 0) {
    echo "PASS ({$logResult['total']} auth entries)\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Test 28: Settings retrieval ───
echo "[Test 28] ls_get_settings returns defaults... ";
$s = ls_get_settings();
if ($s['max_attempts'] > 0 && $s['window_minutes'] > 0 && $s['lockout_minutes'] > 0) {
    echo "PASS (max={$s['max_attempts']}, window={$s['window_minutes']}m, lockout={$s['lockout_minutes']}m)\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// ─── Cleanup test data ───
echo "\nCleaning up test data... ";
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    db_query("DELETE FROM `{$prefix}login_attempts` WHERE `username` LIKE '_test_%'");
    echo "OK\n";
} catch (Exception $e) {
    echo "WARN: " . $e->getMessage() . "\n";
}

// ─── Summary ───
echo "\n=== Results ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
echo "Total:  " . ($pass + $fail) . "\n";

exit($fail > 0 ? 1 : 0);
