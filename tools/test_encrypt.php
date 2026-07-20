<?php
/**
 * NewUI v4.0 - Field Encryption Test Suite
 *
 * Tests RSA key generation, encrypt/decrypt round-trip,
 * timestamp validation, HTTPS detection, and transparent passthrough.
 *
 * Run: php tools/test_encrypt.php
 * Or:  /c/xampp/8.2.4/php/php.exe tools/test_encrypt.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/field-encrypt.php';
require_once __DIR__ . '/../inc/security.php';

$passed = 0;
$failed = 0;

function test($name, $result, $detail = '')
{
    global $passed, $failed;
    if ($result) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}";
        if ($detail) echo " -- {$detail}";
        echo "\n";
        $failed++;
    }
}

echo "=== Field Encryption Tests ===\n\n";

// ── 1. OpenSSL extension available ──
echo "-- Prerequisites --\n";
test('OpenSSL extension loaded', extension_loaded('openssl'));

// ── 2. Key generation ──
echo "\n-- Key Generation --\n";

// Clean up any existing test keys to test fresh generation
$backupPriv = null;
$backupPub = null;
if (file_exists(FE_PRIVATE_KEY)) {
    $backupPriv = file_get_contents(FE_PRIVATE_KEY);
    $backupPub = file_get_contents(FE_PUBLIC_KEY);
    unlink(FE_PRIVATE_KEY);
    unlink(FE_PUBLIC_KEY);
}

$genResult = fe_generate_keypair();
test('fe_generate_keypair() returns true', $genResult === true);
test('Private key file exists', file_exists(FE_PRIVATE_KEY));
test('Public key file exists', file_exists(FE_PUBLIC_KEY));

// ── 3. Public key PEM format ──
echo "\n-- Key Format --\n";
$pubPem = fe_get_public_key();
test('fe_get_public_key() returns string', is_string($pubPem) && strlen($pubPem) > 0);
test('Public key starts with PEM header',
    strpos($pubPem, '-----BEGIN PUBLIC KEY-----') === 0);
test('Public key ends with PEM footer',
    strpos($pubPem, '-----END PUBLIC KEY-----') !== false);

$privPem = fe_get_private_key();
test('fe_get_private_key() returns string', is_string($privPem) && strlen($privPem) > 0);
test('Private key starts with PEM header',
    strpos($privPem, '-----BEGIN PRIVATE KEY-----') === 0 ||
    strpos($privPem, '-----BEGIN RSA PRIVATE KEY-----') === 0);

// ── 4. Key validation ──
echo "\n-- Key Validation --\n";
$status = fe_key_status();
test('fe_key_status() exists=true', $status['exists'] === true);
test('fe_key_status() valid=true', $status['valid'] === true);
test('fe_key_status() created is set', !empty($status['created']));

// ── 5. Encrypt/decrypt round-trip (simulating what Web Crypto does) ──
echo "\n-- Encrypt/Decrypt Round-trip --\n";

// Simulate the envelope that field-encrypt.js creates
$testValue = 'MyS3cretP@ssw0rd!';
$envelope = json_encode(array(
    'value' => $testValue,
    'ts'    => round(microtime(true) * 1000), // JS-style millisecond timestamp
    'nonce' => bin2hex(random_bytes(16)),
));

// Encrypt with public key (simulating browser side)
$pubKey = openssl_pkey_get_public($pubPem);
$encrypted = '';
$encOk = openssl_public_encrypt($envelope, $encrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
test('openssl_public_encrypt() succeeds', $encOk === true);

$b64 = base64_encode($encrypted);
test('Encrypted base64 is not empty', strlen($b64) > 0);

// Decrypt with fe_decrypt
$decrypted = fe_decrypt($b64);
test('fe_decrypt() returns the envelope JSON', $decrypted === $envelope);

// Full round-trip through fe_decrypt_field with ENC: prefix
$encFieldValue = 'ENC:' . $b64;
$plaintext = fe_decrypt_field($encFieldValue);
test('fe_decrypt_field("ENC:...") returns original value', $plaintext === $testValue);

// ── 6. Timestamp validation ──
echo "\n-- Timestamp Validation --\n";

// Fresh timestamp should pass
$freshEnvelope = json_encode(array(
    'value' => 'fresh',
    'ts'    => round(microtime(true) * 1000),
    'nonce' => bin2hex(random_bytes(16)),
));
openssl_public_encrypt($freshEnvelope, $freshEncrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
$freshResult = fe_decrypt_field('ENC:' . base64_encode($freshEncrypted));
test('Fresh timestamp passes validation', $freshResult === 'fresh');

// Old timestamp (10 minutes ago) should fail
$oldEnvelope = json_encode(array(
    'value' => 'old',
    'ts'    => round((microtime(true) - 600) * 1000), // 10 min ago
    'nonce' => bin2hex(random_bytes(16)),
));
openssl_public_encrypt($oldEnvelope, $oldEncrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
$oldResult = fe_decrypt_field('ENC:' . base64_encode($oldEncrypted));
test('Expired timestamp (10 min old) is rejected', $oldResult === false);

// Future timestamp (far future) should fail
$futureEnvelope = json_encode(array(
    'value' => 'future',
    'ts'    => round((microtime(true) + 600) * 1000), // 10 min in future
    'nonce' => bin2hex(random_bytes(16)),
));
openssl_public_encrypt($futureEnvelope, $futureEncrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
$futureResult = fe_decrypt_field('ENC:' . base64_encode($futureEncrypted));
test('Far-future timestamp is rejected', $futureResult === false);

// ── 7. Invalid nonce format ──
echo "\n-- Nonce Validation --\n";
$badNonceEnvelope = json_encode(array(
    'value' => 'badnonce',
    'ts'    => round(microtime(true) * 1000),
    'nonce' => 'not-a-hex-string!!',
));
openssl_public_encrypt($badNonceEnvelope, $badNonceEncrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
$badNonceResult = fe_decrypt_field('ENC:' . base64_encode($badNonceEncrypted));
test('Invalid nonce format is rejected', $badNonceResult === false);

// ── 8. Transparent passthrough ──
echo "\n-- Transparent Passthrough --\n";
test('Non-encrypted value passes through',
    fe_decrypt_field('plain text value') === 'plain text value');
test('Empty string passes through',
    fe_decrypt_field('') === '');
test('Value starting with "ENCRYPT" passes through (not "ENC:")',
    fe_decrypt_field('ENCRYPT:notreal') === 'ENCRYPT:notreal');

// ── 9. HTTPS detection ──
echo "\n-- HTTPS Detection --\n";

// Save original values
$origHttps = isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : null;
$origProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : null;
$origPort  = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : null;

// Test: no HTTPS indicators
unset($_SERVER['HTTPS']);
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$_SERVER['SERVER_PORT'] = '80';
test('fe_is_https() returns false on HTTP (port 80)', fe_is_https() === false);

// Test: HTTPS=on
$_SERVER['HTTPS'] = 'on';
test('fe_is_https() returns true when HTTPS=on', fe_is_https() === true);
unset($_SERVER['HTTPS']);

// Test: X-Forwarded-Proto
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
test('fe_is_https() returns true via X-Forwarded-Proto', fe_is_https() === true);
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

// Test: port 443
$_SERVER['SERVER_PORT'] = '443';
test('fe_is_https() returns true on port 443', fe_is_https() === true);

// Restore originals
if ($origHttps !== null) $_SERVER['HTTPS'] = $origHttps; else unset($_SERVER['HTTPS']);
if ($origProto !== null) $_SERVER['HTTP_X_FORWARDED_PROTO'] = $origProto; else unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
if ($origPort !== null) $_SERVER['SERVER_PORT'] = $origPort; else unset($_SERVER['SERVER_PORT']);

// ── 10. Invalid ciphertext handling ──
echo "\n-- Error Handling --\n";
test('fe_decrypt_field("ENC:invalidbase64!!!") returns false',
    fe_decrypt_field('ENC:invalidbase64!!!') === false);
test('fe_decrypt() with garbage returns false',
    fe_decrypt('not-valid-base64') === false);
test('fe_decrypt() with valid base64 but bad ciphertext returns false',
    fe_decrypt(base64_encode('this is not RSA ciphertext')) === false);

// ── 11. fe_ensure_keys() is idempotent ──
echo "\n-- Idempotent Key Generation --\n";
$firstPub = fe_get_public_key();
$ensureResult = fe_ensure_keys();
$secondPub = fe_get_public_key();
test('fe_ensure_keys() returns true when keys exist', $ensureResult === true);
test('fe_ensure_keys() does not regenerate existing keys', $firstPub === $secondPub);

// Restore backup keys if we had them
if ($backupPriv !== null) {
    file_put_contents(FE_PRIVATE_KEY, $backupPriv);
    file_put_contents(FE_PUBLIC_KEY, $backupPub);
}

// ── Summary ──
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
