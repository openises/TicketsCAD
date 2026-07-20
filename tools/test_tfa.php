<?php
/**
 * NewUI v4.0 - Two-Factor Authentication Tests
 *
 * Tests TOTP code generation, verification, Base32, backup codes,
 * CIDR matching, and the enrollment flow.
 *
 * Usage:  php test_tfa.php
 *     or: /c/xampp/8.2.4/php/php.exe test_tfa.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/tfa.php';

$passed = 0;
$failed = 0;

function assert_true($condition, $label)
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS  {$label}\n";
        $passed++;
    } else {
        echo "  FAIL  {$label}\n";
        $failed++;
    }
}

function assert_equal($expected, $actual, $label)
{
    global $passed, $failed;
    if ($expected === $actual) {
        echo "  PASS  {$label}\n";
        $passed++;
    } else {
        echo "  FAIL  {$label} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
        $failed++;
    }
}

// ═══════════════════════════════════════════════════════════════
echo "=== Base32 Encode/Decode ===\n";
// ═══════════════════════════════════════════════════════════════

// Known test vector: "Hello!" in Base32 is "JBSWY3DPEE"
// Actually RFC 4648 test vectors:
$testVectors = [
    ''       => '',
    'f'      => 'MY',
    'fo'     => 'MZXQ',
    'foo'    => 'MZXW6',
    'foob'   => 'MZXW6YQ',
    'fooba'  => 'MZXW6YTB',
    'foobar' => 'MZXW6YTBOI',
];

foreach ($testVectors as $plain => $encoded) {
    $result = base32_encode($plain);
    assert_equal($encoded, $result, "base32_encode('{$plain}') = '{$encoded}'");
}

// Decode round-trip
foreach ($testVectors as $plain => $encoded) {
    if ($plain === '') continue; // empty is trivial
    $result = base32_decode($encoded);
    assert_equal($plain, $result, "base32_decode('{$encoded}') = '{$plain}'");
}

// Round-trip with random data
$randomBytes = random_bytes(20);
$encoded = base32_encode($randomBytes);
$decoded = base32_decode($encoded);
assert_equal($randomBytes, $decoded, "Base32 round-trip with 20 random bytes");

// ═══════════════════════════════════════════════════════════════
echo "\n=== TOTP Code Generation (RFC 6238 Test Vectors) ===\n";
// ═══════════════════════════════════════════════════════════════

// RFC 6238 Appendix B test vectors use the ASCII string
// "12345678901234567890" as the shared secret for SHA1.
// The Base32 encoding of that string:
$testSecret = base32_encode('12345678901234567890');

// RFC 6238 test vectors (SHA1, 8 digits, 30-second period):
// Time (sec)        | TOTP (8 digits)
// 59                | 94287082
// 1111111109        | 07081804
// 1111111111        | 14050471
// 1234567890        | 89005924
// 2000000000        | 69279037
// 20000000000       | 65353130

$rfcVectors = [
    [59,           '94287082', 8],
    [1111111109,   '07081804', 8],
    [1111111111,   '14050471', 8],
    [1234567890,   '89005924', 8],
    [2000000000,   '69279037', 8],
    [20000000000,  '65353130', 8],
];

foreach ($rfcVectors as $v) {
    $time = $v[0];
    $expected = $v[1];
    $digits = $v[2];
    $result = totp_get_code($testSecret, $time, $digits, 30);
    assert_equal($expected, $result, "RFC 6238 TOTP at t={$time}: {$expected}");
}

// Standard 6-digit codes (most common use case)
echo "\n=== TOTP 6-digit codes ===\n";
$secret6 = totp_generate_secret(20);
$code6 = totp_get_code($secret6);
assert_equal(6, strlen($code6), "6-digit TOTP code has correct length");
assert_true(ctype_digit($code6), "6-digit TOTP code is all digits");

// ═══════════════════════════════════════════════════════════════
echo "\n=== TOTP Verification with Window ===\n";
// ═══════════════════════════════════════════════════════════════

$verifySecret = totp_generate_secret(20);

// Current code should verify
$currentCode = totp_get_code($verifySecret);
assert_true(totp_verify($verifySecret, $currentCode), "Current TOTP code verifies");

// Code from 30 seconds ago (window=1 should accept)
$pastCode = totp_get_code($verifySecret, time() - 30);
assert_true(totp_verify($verifySecret, $pastCode, 1), "Code from -1 period verifies with window=1");

// Code from 30 seconds in future (window=1 should accept)
$futureCode = totp_get_code($verifySecret, time() + 30);
assert_true(totp_verify($verifySecret, $futureCode, 1), "Code from +1 period verifies with window=1");

// Code from 90 seconds ago (window=1 should reject)
$oldCode = totp_get_code($verifySecret, time() - 90);
$shouldFail = !totp_verify($verifySecret, $oldCode, 1);
assert_true($shouldFail, "Code from -3 periods REJECTED with window=1");

// Wrong code
assert_true(!totp_verify($verifySecret, '000000', 0), "Wrong code rejected");

// ═══════════════════════════════════════════════════════════════
echo "\n=== TOTP URI Generation ===\n";
// ═══════════════════════════════════════════════════════════════

$uriSecret = 'JBSWY3DPEHPK3PXP';
$uri = totp_get_uri($uriSecret, 'testuser', 'TicketsCAD');
assert_true(strpos($uri, 'otpauth://totp/') === 0, "URI starts with otpauth://totp/");
assert_true(strpos($uri, 'secret=' . $uriSecret) !== false, "URI contains secret");
assert_true(strpos($uri, 'issuer=TicketsCAD') !== false, "URI contains issuer");
assert_true(strpos($uri, 'testuser') !== false, "URI contains account name");

// ═══════════════════════════════════════════════════════════════
echo "\n=== Backup Code Generation ===\n";
// ═══════════════════════════════════════════════════════════════

$codes = totp_generate_backup_codes(8);
assert_equal(8, count($codes), "Generated 8 backup codes");

$allValid = true;
foreach ($codes as $c) {
    if (strlen($c) !== 8 || !ctype_digit($c)) {
        $allValid = false;
        break;
    }
}
assert_true($allValid, "All backup codes are 8-digit numbers");

// All unique
assert_equal(8, count(array_unique($codes)), "All backup codes are unique");

// ═══════════════════════════════════════════════════════════════
echo "\n=== Backup Code Verification (One-Time Use) ===\n";
// ═══════════════════════════════════════════════════════════════

$testCodes = totp_generate_backup_codes(4);
$storedCodes = $testCodes;

// First use: valid
$result = totp_verify_backup_code($testCodes[0], $storedCodes);
assert_true($result['valid'], "First backup code verifies");
assert_equal(3, count($result['remaining']), "One code consumed, 3 remaining");

// Second use of same code: invalid (one-time use)
$result2 = totp_verify_backup_code($testCodes[0], $result['remaining']);
assert_true(!$result2['valid'], "Same backup code rejected on second use");

// Different code still works
$result3 = totp_verify_backup_code($testCodes[1], $result['remaining']);
assert_true($result3['valid'], "Different backup code still works");
assert_equal(2, count($result3['remaining']), "Now 2 codes remaining");

// Invalid code
$result4 = totp_verify_backup_code('99999999', $storedCodes);
assert_true(!$result4['valid'], "Invalid backup code rejected");

// ═══════════════════════════════════════════════════════════════
echo "\n=== CIDR Matching ===\n";
// ═══════════════════════════════════════════════════════════════

// Private ranges
assert_true(tfa_ip_in_cidr('10.0.0.1', '10.0.0.0/8'),       "10.0.0.1 in 10.0.0.0/8");
assert_true(tfa_ip_in_cidr('10.255.255.255', '10.0.0.0/8'),  "10.255.255.255 in 10.0.0.0/8");
assert_true(tfa_ip_in_cidr('172.16.0.1', '172.16.0.0/12'),   "172.16.0.1 in 172.16.0.0/12");
assert_true(tfa_ip_in_cidr('172.31.255.255', '172.16.0.0/12'), "172.31.255.255 in 172.16.0.0/12");
assert_true(tfa_ip_in_cidr('192.168.1.1', '192.168.0.0/16'), "192.168.1.1 in 192.168.0.0/16");
assert_true(tfa_ip_in_cidr('192.168.255.255', '192.168.0.0/16'), "192.168.255.255 in 192.168.0.0/16");

// Not in private ranges
assert_true(!tfa_ip_in_cidr('8.8.8.8', '10.0.0.0/8'),       "8.8.8.8 NOT in 10.0.0.0/8");
assert_true(!tfa_ip_in_cidr('8.8.8.8', '172.16.0.0/12'),     "8.8.8.8 NOT in 172.16.0.0/12");
assert_true(!tfa_ip_in_cidr('8.8.8.8', '192.168.0.0/16'),    "8.8.8.8 NOT in 192.168.0.0/16");

// Exact match (/32)
assert_true(tfa_ip_in_cidr('1.2.3.4', '1.2.3.4/32'),         "1.2.3.4 in 1.2.3.4/32 (exact)");
assert_true(!tfa_ip_in_cidr('1.2.3.5', '1.2.3.4/32'),        "1.2.3.5 NOT in 1.2.3.4/32");

// /24 subnet
assert_true(tfa_ip_in_cidr('192.168.1.100', '192.168.1.0/24'), "192.168.1.100 in 192.168.1.0/24");
assert_true(!tfa_ip_in_cidr('192.168.2.1', '192.168.1.0/24'), "192.168.2.1 NOT in 192.168.1.0/24");

// Localhost
assert_true(tfa_ip_in_cidr('127.0.0.1', '127.0.0.0/8'),      "127.0.0.1 in 127.0.0.0/8");

// ═══════════════════════════════════════════════════════════════
echo "\n=== Remember Token / Device Fingerprint ===\n";
// ═══════════════════════════════════════════════════════════════

// Device fingerprint is deterministic for same request
$fp1 = tfa_device_fingerprint();
$fp2 = tfa_device_fingerprint();
assert_equal($fp1, $fp2, "Device fingerprint is consistent within request");
assert_equal(64, strlen($fp1), "Fingerprint is a 64-char SHA-256 hex string");
assert_true(ctype_xdigit($fp1), "Fingerprint contains only hex characters");

// ═══════════════════════════════════════════════════════════════
echo "\n=== Encryption Round-Trip ===\n";
// ═══════════════════════════════════════════════════════════════

$plaintext = 'JBSWY3DPEHPK3PXP';
$encrypted = tfa_encrypt($plaintext);
$decrypted = tfa_decrypt($encrypted);
assert_equal($plaintext, $decrypted, "Encryption round-trip preserves data");

// Different plaintext produces different ciphertext
$encrypted2 = tfa_encrypt('DIFFERENT_SECRET');
assert_true($encrypted !== $encrypted2, "Different inputs produce different ciphertext");

// Each encryption uses random IV so same plaintext gives different ciphertext
$encrypted3 = tfa_encrypt($plaintext);
assert_true($encrypted !== $encrypted3, "Same plaintext encrypted twice gives different ciphertext (random IV)");

// Invalid input returns false
assert_true(tfa_decrypt('not_valid_base64!!!') === false || tfa_decrypt('') === false, "Invalid ciphertext returns false");

// ═══════════════════════════════════════════════════════════════
echo "\n=== Secret Generation ===\n";
// ═══════════════════════════════════════════════════════════════

$secret1 = totp_generate_secret(20);
$secret2 = totp_generate_secret(20);

assert_true(strlen($secret1) === 32, "Secret is 32 Base32 characters (160 bits)");
assert_true($secret1 !== $secret2, "Two generated secrets are different");
assert_true(preg_match('/^[A-Z2-7]+$/', $secret1) === 1, "Secret contains only Base32 characters");

// Decode and verify length
$decoded = base32_decode($secret1);
assert_equal(20, strlen($decoded), "Decoded secret is 20 bytes");

// ═══════════════════════════════════════════════════════════════
echo "\n=== Database Enrollment Flow (requires DB) ===\n";
// ═══════════════════════════════════════════════════════════════

try {
    // Check if table exists
    $tableExists = false;
    try {
        db_fetch_one("SELECT 1 FROM " . db_table('user_tfa') . " LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {
        echo "  SKIP  user_tfa table not found — run sql/run_01_tfa.php first\n";
    }

    if ($tableExists) {
        // Use a test user ID that won't conflict (negative)
        $testUserId = 99999;

        // Clean up any previous test data
        try {
            db_query("DELETE FROM " . db_table('user_tfa') . " WHERE `user_id` = ?", [$testUserId]);
            db_query("DELETE FROM " . db_table('tfa_remember_tokens') . " WHERE `user_id` = ?", [$testUserId]);
        } catch (Exception $e) {}

        // Not enrolled initially
        assert_true(!tfa_is_enabled($testUserId), "User not enrolled initially");

        // Enroll
        $enrollment = tfa_enroll($testUserId, 'testuser');
        assert_true(!empty($enrollment['secret']), "Enrollment returns secret");
        assert_true(!empty($enrollment['uri']), "Enrollment returns URI");
        assert_equal(8, count($enrollment['backup_codes']), "Enrollment returns 8 backup codes");

        // Enrolled but NOT yet confirmed — tfa_is_enabled checks confirmed=1
        assert_true(!tfa_is_enabled($testUserId), "User is NOT enabled before code confirmation");

        // Confirm enrollment with valid code
        $code = totp_get_code($enrollment['secret']);
        assert_true(tfa_confirm_enroll($testUserId, $code), "Confirm enrollment with valid code");

        // Verify login with TOTP code.
        // Phase 73bb: TOTP codes are now one-time-use within their
        // counter window (RFC 6238 §5.2). The enrollment confirm above
        // recorded the current counter; we have to compute a code for
        // the NEXT counter (30 s ahead) so it's a fresh redemption.
        $nextCounterTs = (((int) floor(time() / 30)) + 1) * 30;
        $loginCode = totp_get_code($enrollment['secret'], $nextCounterTs);
        // We also need the verifier to look at that future counter —
        // its default ±1 window covers it when the test runs within 30s.
        assert_true(tfa_verify_login($testUserId, $loginCode), "Login verification with TOTP code");

        // Verify login with backup code
        $backupResult = tfa_verify_login($testUserId, $enrollment['backup_codes'][0]);
        assert_true($backupResult, "Login verification with backup code");

        // Same backup code should not work again
        $backupResult2 = tfa_verify_login($testUserId, $enrollment['backup_codes'][0]);
        assert_true(!$backupResult2, "Used backup code rejected on second attempt");

        // Regenerate backup codes
        $newCodes = tfa_regenerate_backup_codes($testUserId);
        assert_true(is_array($newCodes), "Regenerated backup codes returned");
        assert_equal(8, count($newCodes), "8 new backup codes generated");

        // Disable
        tfa_disable($testUserId);
        assert_true(!tfa_is_enabled($testUserId), "User not enrolled after tfa_disable()");

        // Clean up test data
        try {
            db_query("DELETE FROM " . db_table('user_tfa') . " WHERE `user_id` = ?", [$testUserId]);
            db_query("DELETE FROM " . db_table('tfa_remember_tokens') . " WHERE `user_id` = ?", [$testUserId]);
        } catch (Exception $e) {}

        echo "  (Database tests completed)\n";
    }
} catch (Exception $e) {
    echo "  SKIP  Database tests skipped: " . $e->getMessage() . "\n";
}

// ═══════════════════════════════════════════════════════════════
echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\n*** SOME TESTS FAILED ***\n";
    exit(1);
} else {
    echo "\nAll tests passed.\n";
    exit(0);
}
