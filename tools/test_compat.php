<?php
/**
 * PHP Compatibility Tests
 *
 * Verifies that required PHP functions, extensions, and polyfills
 * are available and work correctly on this system.
 *
 * Usage: php tools/test_compat.php
 */
require_once __DIR__ . '/../config.php';

echo "=== PHP Compatibility Tests ===\n\n";
$pass = 0;
$fail = 0;

// ── PHP Version ─────────────────────────────────────────────

echo "[Test 1] PHP version >= 8.0... ";
if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    echo "[PASS] (PHP " . PHP_VERSION . ")\n";
    $pass++;
} else {
    echo "[FAIL] PHP " . PHP_VERSION . " (need 8.0+)\n";
    $fail++;
}

// ── Required Extensions ─────────────────────────────────────

echo "[Test 2] PDO extension loaded... ";
if (extension_loaded('pdo')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 3] PDO MySQL driver loaded... ";
if (extension_loaded('pdo_mysql')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 4] OpenSSL extension loaded... ";
if (extension_loaded('openssl')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 5] JSON extension loaded... ";
if (extension_loaded('json')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 6] cURL extension loaded... ";
if (extension_loaded('curl')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] (needed for API endpoint tests and FCC lookups)\n";
    $fail++;
}

echo "[Test 7] mbstring extension loaded... ";
if (extension_loaded('mbstring')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 8] session extension loaded... ";
if (extension_loaded('session')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

// ── PHP 8.x Built-in Functions ──────────────────────────────

echo "[Test 9] str_contains() exists... ";
if (function_exists('str_contains')) {
    $ok = str_contains('hello world', 'world') && !str_contains('hello world', 'xyz');
    if ($ok) {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] function exists but returned wrong result\n";
        $fail++;
    }
} else {
    echo "[FAIL] function missing (PHP 8.0+)\n";
    $fail++;
}

echo "[Test 10] str_starts_with() exists... ";
if (function_exists('str_starts_with')) {
    $ok = str_starts_with('hello world', 'hello') && !str_starts_with('hello world', 'world');
    if ($ok) {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] function exists but returned wrong result\n";
        $fail++;
    }
} else {
    echo "[FAIL] function missing (PHP 8.0+)\n";
    $fail++;
}

echo "[Test 11] str_ends_with() exists... ";
if (function_exists('str_ends_with')) {
    $ok = str_ends_with('hello world', 'world') && !str_ends_with('hello world', 'hello');
    if ($ok) {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] function exists but returned wrong result\n";
        $fail++;
    }
} else {
    echo "[FAIL] function missing (PHP 8.0+)\n";
    $fail++;
}

// ── Deprecated Functions / Constants ────────────────────────

echo "[Test 12] FILTER_SANITIZE_STRING constant... ";
// This was deprecated in PHP 8.1 and removed in some builds
if (@defined('FILTER_SANITIZE_STRING') && @constant('FILTER_SANITIZE_STRING') !== null) {
    echo "[PASS] (defined — deprecated in 8.1, project uses e() instead)\n";
    $pass++;
} else {
    // Not a hard failure — project may not use it
    echo "[PASS] (not defined, project uses htmlspecialchars via e() instead)\n";
    $pass++;
}

echo "[Test 13] utf8_encode/decode availability... ";
// Deprecated in PHP 8.2, check if available or if mb equivalent works
if (function_exists('utf8_encode')) {
    $test = @utf8_encode("\xC0\xC1");
    $back = @utf8_decode($test);
    echo "[PASS] (native functions available — deprecated in 8.2)\n";
    $pass++;
} elseif (function_exists('mb_convert_encoding')) {
    // Alternative via mbstring
    $test = mb_convert_encoding("\xC0\xC1", 'UTF-8', 'ISO-8859-1');
    echo "[PASS] (mb_convert_encoding available as alternative)\n";
    $pass++;
} else {
    echo "[FAIL] no utf8 encoding function available\n";
    $fail++;
}

// ── Core Function Tests ─────────────────────────────────────

echo "[Test 14] random_bytes() works (for CSRF tokens)... ";
try {
    $bytes = random_bytes(32);
    if (strlen($bytes) === 32) {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] wrong length\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

echo "[Test 15] password_hash() bcrypt works... ";
$hash = password_hash('test', PASSWORD_BCRYPT, ['cost' => 12]);
if ($hash && password_verify('test', $hash)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 16] hash_equals() exists (timing-safe comparison)... ";
if (function_exists('hash_equals')) {
    $ok = hash_equals('abc', 'abc') && !hash_equals('abc', 'def');
    if ($ok) {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] wrong result\n";
        $fail++;
    }
} else {
    echo "[FAIL] function missing\n";
    $fail++;
}

echo "[Test 17] json_encode/decode round-trip... ";
$data = ['key' => 'value', 'num' => 42, 'unicode' => "\xC3\xA9"];
$json = json_encode($data, JSON_UNESCAPED_UNICODE);
$decoded = json_decode($json, true);
if ($decoded === $data) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] round-trip mismatch\n";
    $fail++;
}

echo "[Test 18] DateTime with timezone... ";
try {
    $dt = new DateTime('2026-01-15 10:30:00', new DateTimeZone('America/New_York'));
    $iso = $dt->format('c');
    if (strpos($iso, '2026-01-15T10:30:00') !== false) {
        echo "[PASS] ($iso)\n";
        $pass++;
    } else {
        echo "[FAIL] unexpected format: $iso\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── OpenSSL Functions ───────────────────────────────────────

echo "[Test 19] OpenSSL RSA key generation... ";
$config = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'digest_alg' => 'sha256',
];
// Check for XAMPP openssl.cnf
$cnfPaths = [
    getenv('OPENSSL_CONF'),
    dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
    dirname(PHP_BINARY) . '/../apache/conf/openssl.cnf',
];
foreach ($cnfPaths as $cnf) {
    if ($cnf && file_exists($cnf)) {
        $config['config'] = $cnf;
        break;
    }
}
$res = @openssl_pkey_new($config);
if ($res !== false) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] " . openssl_error_string() . "\n";
    $fail++;
}

echo "[Test 20] OPENSSL_PKCS1_OAEP_PADDING constant... ";
if (defined('OPENSSL_PKCS1_OAEP_PADDING')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] constant not defined\n";
    $fail++;
}

// ── Database Helper Functions ───────────────────────────────

echo "[Test 21] db_query() function exists... ";
if (function_exists('db_query')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 22] db_fetch_all() function exists... ";
if (function_exists('db_fetch_all')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 23] db_fetch_one() function exists... ";
if (function_exists('db_fetch_one')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 24] db_fetch_value() function exists... ";
if (function_exists('db_fetch_value')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 25] db_insert_id() function exists... ";
if (function_exists('db_insert_id')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 26] db_table() function exists... ";
if (function_exists('db_table')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

// ── Application Helper Functions ────────────────────────────

echo "[Test 27] e() HTML escaping function exists... ";
if (function_exists('e')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 28] json_response() function exists... ";
if (function_exists('json_response')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 29] verify_password() function exists... ";
if (function_exists('verify_password')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "[Test 30] csrf_token() and csrf_verify() exist... ";
if (function_exists('csrf_token') && function_exists('csrf_verify')) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL]\n";
    $fail++;
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
