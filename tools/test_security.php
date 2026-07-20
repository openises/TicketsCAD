<?php
/**
 * Security Integration Tests
 *
 * Tests CSRF tokens, password hashing/verification, XSS escaping,
 * SQL injection prevention, session management, RBAC permissions,
 * field encryption, and login lockout.
 *
 * Usage: php tools/test_security.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/field-encrypt.php';
require_once __DIR__ . '/../inc/login-security.php';
require_once __DIR__ . '/../inc/session-manager.php';

// Start session before any output so session functions work in CLI
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

echo "=== Security Tests ===\n\n";
$pass = 0;
$fail = 0;

// ── CSRF Token Tests ────────────────────────────────────────

echo "[Test 1] CSRF token generation... ";
$_SESSION = [];
$token = csrf_token();
if (is_string($token) && strlen($token) === 64 && ctype_xdigit($token)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] expected 64-char hex, got " . strlen($token) . " chars\n";
    $fail++;
}

echo "[Test 2] CSRF token consistency within session... ";
$token2 = csrf_token();
if ($token === $token2) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] tokens differ within same session\n";
    $fail++;
}

echo "[Test 3] CSRF token verification (valid)... ";
if (csrf_verify($token)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] valid token rejected\n";
    $fail++;
}

echo "[Test 4] CSRF token verification (invalid)... ";
if (!csrf_verify('0000000000000000000000000000000000000000000000000000000000000000')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] invalid token accepted\n";
    $fail++;
}

echo "[Test 5] CSRF token verification (empty)... ";
if (!csrf_verify('')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] empty token accepted\n";
    $fail++;
}

// ── Password Hashing Tests ──────────────────────────────────

echo "[Test 6] Bcrypt password hash + verify... ";
$hash = hash_new_password('TestPass123!');
$result = verify_password('TestPass123!', $hash);
if ($result['valid'] && !$result['needs_rehash']) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] valid=" . ($result['valid'] ? 'Y' : 'N') . " rehash=" . ($result['needs_rehash'] ? 'Y' : 'N') . "\n";
    $fail++;
}

echo "[Test 7] Bcrypt wrong password rejected... ";
$result = verify_password('WrongPass!', $hash);
if (!$result['valid']) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] wrong password accepted\n";
    $fail++;
}

echo "[Test 8] Legacy MD5 hash verification... ";
$md5Hash = md5('legacypassword');
$result = verify_password('legacypassword', $md5Hash);
if ($result['valid'] && $result['needs_rehash']) {
    echo "[PASS] (valid + needs_rehash)\n";
    $pass++;
} else {
    echo "[FAIL] valid=" . ($result['valid'] ? 'Y' : 'N') . " rehash=" . ($result['needs_rehash'] ? 'Y' : 'N') . "\n";
    $fail++;
}

echo "[Test 9] Legacy MD5 lowercase variant... ";
$md5HashLower = md5(strtolower('LegacyPass'));
$result = verify_password('LegacyPass', $md5HashLower);
if ($result['valid'] && $result['needs_rehash']) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 10] Empty password rejected against hash... ";
$result = verify_password('', $hash);
if (!$result['valid']) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] empty password accepted\n";
    $fail++;
}

// ── XSS Escaping Tests ─────────────────────────────────────

echo "[Test 11] e() escapes HTML tags... ";
$escaped = e('<script>alert("xss")</script>');
if (strpos($escaped, '<script>') === false && strpos($escaped, '&lt;script&gt;') !== false) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] HTML not escaped: $escaped\n";
    $fail++;
}

echo "[Test 12] e() escapes quotes... ";
$escaped = e('" onmouseover="alert(1)"');
if (strpos($escaped, '&quot;') !== false && strpos($escaped, '" onmouseover') === false) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] quotes not escaped\n";
    $fail++;
}

echo "[Test 13] e() escapes ampersands... ";
$escaped = e('Tom & Jerry');
if ($escaped === 'Tom &amp; Jerry') {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] got: $escaped\n";
    $fail++;
}

// ── SQL Injection Prevention ────────────────────────────────

echo "[Test 14] Parameterized query blocks injection... ";
try {
    // This should safely handle the injection attempt without error
    $result = db_fetch_all(
        "SELECT id FROM " . db_table('user') . " WHERE `user` = ? LIMIT 1",
        ["admin' OR '1'='1"]
    );
    // Should return 0 rows (no user named "admin' OR '1'='1")
    if (count($result) === 0) {
        echo "[PASS] (injection returned 0 rows)\n";
        $pass++;
    } else {
        echo "[FAIL] injection returned " . count($result) . " rows\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] exception: " . $e->getMessage() . "\n";
    $fail++;
}

echo "[Test 15] db_table() sanitizes table names... ";
$sanitized = db_table("user; DROP TABLE--");
if (strpos($sanitized, ';') === false && strpos($sanitized, '-') === false) {
    echo "[PASS] ($sanitized)\n";
    $pass++;
} else {
    echo "[FAIL] unsanitized: $sanitized\n";
    $fail++;
}

// ── Session Management Tests ────────────────────────────────

echo "[Test 16] Session manager table exists/can be created... ";
if (sm_ensure_table()) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] could not create active_sessions table\n";
    $fail++;
}

echo "[Test 17] Session create/read/destroy cycle... ";
$_SESSION = ['user_id' => 9999, 'user' => '_test_session_user_'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'TestRunner/1.0';
$testSessionId = 'test_session_' . bin2hex(random_bytes(16));
// Mock session_id
$origSessId = session_id();
try {
    sm_create_session(9999);
    // Check it exists
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $sessions = db_fetch_all(
        "SELECT * FROM `{$prefix}active_sessions` WHERE user_id = 9999 ORDER BY id DESC LIMIT 1"
    );
    if (count($sessions) > 0) {
        // Cleanup
        db_query("DELETE FROM `{$prefix}active_sessions` WHERE user_id = 9999");
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] session not found in DB\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── RBAC Permission Tests ───────────────────────────────────

echo "[Test 18] rbac_can() grants all to Super Admin (level 0)... ";
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
if (rbac_can('action.manage_config') && rbac_can('screen.settings') && rbac_can('action.delete_incident')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] super admin denied a permission\n";
    $fail++;
}

echo "[Test 19] Legacy level 2 (Operator) blocked from settings... ";
$_SESSION = ['user_id' => 999, 'level' => 2, 'user' => 'operator_test'];
// Force legacy fallback by using a non-existent user
$blocked = ['screen.settings', 'action.manage_users', 'action.manage_config'];
$allBlocked = true;
foreach ($blocked as $perm) {
    if (_rbac_legacy_check($perm, 2)) {
        $allBlocked = false;
    }
}
if ($allBlocked) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] operator not blocked from admin permissions\n";
    $fail++;
}

echo "[Test 20] Legacy level 3 (Guest) is read-only... ";
$canView = _rbac_legacy_check('screen.dashboard', 3);
$cannotCreate = !_rbac_legacy_check('action.create_incident', 3);
$cannotManage = !_rbac_legacy_check('action.manage_config', 3);
if ($canView && $cannotCreate && $cannotManage) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] guest permissions incorrect\n";
    $fail++;
}

echo "[Test 21] Legacy level 99 has minimal access... ";
$canDash = _rbac_legacy_check('screen.dashboard', 99);
$cannotSearch = !_rbac_legacy_check('screen.search', 99);
if ($canDash && $cannotSearch) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

// ── Field Encryption Tests ──────────────────────────────────

echo "[Test 22] RSA key generation... ";
$keyStatus = fe_key_status();
if ($keyStatus['exists'] && $keyStatus['valid']) {
    echo "[PASS] (keys exist and validate)\n";
    $pass++;
} else {
    // Try generating
    if (fe_ensure_keys()) {
        $keyStatus = fe_key_status();
        if ($keyStatus['valid']) {
            echo "[PASS] (generated new keys)\n";
            $pass++;
        } else {
            echo "[FAIL] keys generated but invalid\n";
            $fail++;
        }
    } else {
        echo "[SKIP] cannot generate keys (openssl may not be configured)\n";
    }
}

echo "[Test 23] RSA encrypt/decrypt round-trip... ";
$pubPem = fe_get_public_key();
$privPem = fe_get_private_key();
if ($pubPem && $privPem) {
    $testData = 'SensitivePassword123!';
    $encrypted = '';
    $pubKey = openssl_pkey_get_public($pubPem);
    if ($pubKey && openssl_public_encrypt($testData, $encrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING)) {
        $decrypted = '';
        $privKey = openssl_pkey_get_private($privPem);
        if ($privKey && openssl_private_decrypt($encrypted, $decrypted, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            if ($decrypted === $testData) {
                echo "[PASS]\n";
                $pass++;
            } else {
                echo "[FAIL] decrypted text mismatch\n";
                $fail++;
            }
        } else {
            echo "[FAIL] decryption failed\n";
            $fail++;
        }
    } else {
        echo "[FAIL] encryption failed\n";
        $fail++;
    }
} else {
    echo "[SKIP] keys not available\n";
}

echo "[Test 24] fe_decrypt_field() passthrough for non-encrypted... ";
$result = fe_decrypt_field('plaintext_value');
if ($result === 'plaintext_value') {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] passthrough failed\n";
    $fail++;
}

// ── Login Lockout Tests ─────────────────────────────────────

echo "[Test 25] Login attempt table exists/can be created... ";
if (ls_ensure_table()) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 26] Record and read login attempt... ";
$testUser = '_test_lockout_' . time();
$_SERVER['HTTP_USER_AGENT'] = 'TestRunner/1.0';
ls_record_attempt($testUser, false, '127.0.0.1', 'wrong_password');
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $cnt = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}login_attempts` WHERE username = ?",
        [$testUser]
    );
    if ($cnt >= 1) {
        echo "[PASS] ($cnt attempts recorded)\n";
        $pass++;
    } else {
        echo "[FAIL] no attempts found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

echo "[Test 27] Login lockout after max failures... ";
// Record enough failures to trigger lockout (default 5)
for ($i = 0; $i < 6; $i++) {
    ls_record_attempt($testUser, false, '127.0.0.1', 'wrong_password');
}
if (ls_is_locked($testUser)) {
    echo "[PASS] (locked after repeated failures)\n";
    $pass++;
} else {
    echo "[FAIL] account not locked after 6+ failures\n";
    $fail++;
}

echo "[Test 28] Lockout cleared after successful login... ";
ls_record_attempt($testUser, true, '127.0.0.1');
ls_clear_attempts($testUser);
if (!ls_is_locked($testUser)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] still locked after clear\n";
    $fail++;
}

// Cleanup lockout test data
try {
    db_query("DELETE FROM `{$prefix}login_attempts` WHERE username = ?", [$testUser]);
} catch (Exception $e) {
    // ignore cleanup errors
}

// ── Security Headers Check ──────────────────────────────────

echo "[Test 29] fe_is_https() returns bool... ";
$result = fe_is_https();
if (is_bool($result)) {
    echo "[PASS] (https=" . ($result ? 'true' : 'false') . ")\n";
    $pass++;
} else {
    echo "[FAIL] not a boolean\n";
    $fail++;
}

echo "[Test 30] hash_equals used in CSRF (timing-safe)... ";
// Verify the csrf_verify function uses hash_equals by checking source
$funcSource = file_get_contents(__DIR__ . '/../inc/functions.php');
if (strpos($funcSource, 'hash_equals') !== false) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] hash_equals not found in functions.php\n";
    $fail++;
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
