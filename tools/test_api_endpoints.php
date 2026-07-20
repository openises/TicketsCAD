<?php
/**
 * API Endpoint Integration Tests
 * Tests that API endpoints respond correctly with proper auth
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 */
require __DIR__ . '/../config.php';

echo "=== API Endpoint Tests ===\n\n";
$pass = 0;
$fail = 0;

// Shared authenticated cookie file (login once, reuse across tests)
$_sharedCookieFile = null;

function getAuthCookie() {
    global $_sharedCookieFile;
    if ($_sharedCookieFile !== null) return $_sharedCookieFile;

    $_sharedCookieFile = tempnam(sys_get_temp_dir(), 'apicookie');

    // Step 1: GET login page to get CSRF token + session cookie
    $ch = curl_init('http://localhost/newui/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $_sharedCookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $_sharedCookieFile);
    $html = curl_exec($ch);
    curl_close($ch);

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';

    // Step 2: POST login — DON'T follow redirect so we capture the Set-Cookie
    // from the 302 response (session_regenerate_id issues new cookie)
    $ch = curl_init('http://localhost/newui/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username'   => 'admin',
        'password'   => 'testing',
        'csrf_token' => $csrf,
    ]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $_sharedCookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $_sharedCookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $loginResp = curl_exec($ch);
    $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If login returned 200 with TFA form, handle 2FA verification
    if ($loginCode === 200 && strpos($loginResp, 'tfa_verify') !== false) {
        // Generate a valid TOTP code using the PHP includes
        require_once __DIR__ . '/../inc/tfa.php';
        require_once __DIR__ . '/../inc/totp.php';

        // Find admin user ID
        $adminRow = db_fetch_one("SELECT id FROM " . db_table('user') . " WHERE user = 'admin'");
        $adminId = $adminRow ? (int) $adminRow['id'] : 1;

        // Decrypt the TOTP secret and generate a valid code
        $tfaRow = null;
        try {
            $tfaRow = db_fetch_one(
                "SELECT `secret_encrypted` FROM " . db_table('user_tfa') . " WHERE `user_id` = ? AND `confirmed` = 1",
                [$adminId]
            );
        } catch (Exception $e) {}

        $tfaCode = null;
        if ($tfaRow && !empty($tfaRow['secret_encrypted'])) {
            $secret = tfa_decrypt($tfaRow['secret_encrypted']);
            if ($secret) {
                $tfaCode = totp_get_code($secret);
            }
        }

        if ($tfaCode) {
            // Extract new CSRF token from the TFA form
            preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginResp, $csrfMatch);
            $tfaCsrf = $csrfMatch[1] ?? $csrf;

            // POST the TFA verification
            $ch = curl_init('http://localhost/newui/login.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'tfa_verify' => '1',
                'tfa_code'   => $tfaCode,
                'csrf_token' => $tfaCsrf,
            ]));
            curl_setopt($ch, CURLOPT_COOKIEFILE, $_sharedCookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $_sharedCookieFile);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $tfaResp = curl_exec($ch);
            $tfaCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $loginResp = $tfaResp;
            $loginCode = $tfaCode2;
        }
    }

    // If login returned 200 (form re-rendered), credentials may be wrong
    if ($loginCode === 200 && strpos($loginResp, 'alert-danger') !== false) {
        echo "WARNING: Login failed — check credentials\n";
    }

    // Follow the redirect manually to save the updated cookie
    if ($loginCode === 302 || $loginCode === 301) {
        preg_match('/Location:\s*(.+)/i', $loginResp, $locMatch);
        if (!empty($locMatch[1])) {
            $redirectUrl = trim($locMatch[1]);
            if (strpos($redirectUrl, 'http') !== 0) {
                $redirectUrl = 'http://localhost/newui/' . ltrim($redirectUrl, '/');
            }
            $ch = curl_init($redirectUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $_sharedCookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $_sharedCookieFile);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    return $_sharedCookieFile;
}

// Helper: simulate authenticated request
function testEndpoint($url, $desc, $expectKey = null) {
    $cookieFile = getAuthCookie();

    $ch = curl_init('http://localhost/newui/' . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http_code' => $httpCode, 'body' => $response, 'json' => json_decode($response, true)];
}

// Test RBAC API
echo "[Test 1] GET api/rbac.php (list roles)... ";
$r = testEndpoint('api/rbac.php', 'RBAC roles list');
if ($r['http_code'] === 200 && isset($r['json']['roles']) && count($r['json']['roles']) >= 6) {
    echo "PASS (" . count($r['json']['roles']) . " roles)\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}, roles=" . ($r['json']['roles'] ? count($r['json']['roles']) : 'missing') . "\n";
    $fail++;
}

// Test RBAC role detail
echo "[Test 2] GET api/rbac.php?role_id=1 (Super Admin detail)... ";
$r = testEndpoint('api/rbac.php?role_id=1', 'Super Admin detail');
if ($r['http_code'] === 200 && isset($r['json']['role']) && $r['json']['role']['name'] === 'Super Admin') {
    $permCount = count($r['json']['permissions'] ?? []);
    echo "PASS (name=Super Admin, $permCount permissions listed)\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}\n";
    $fail++;
}

// Test RBAC permissions list
echo "[Test 3] GET api/rbac.php?permissions=1... ";
$r = testEndpoint('api/rbac.php?permissions=1', 'All permissions');
if ($r['http_code'] === 200 && isset($r['json']['grouped'])) {
    $cats = array_keys($r['json']['grouped']);
    echo "PASS (categories: " . implode(', ', $cats) . ")\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}\n";
    $fail++;
}

// Test incidents API
echo "[Test 4] GET api/incidents.php... ";
$r = testEndpoint('api/incidents.php', 'Incidents list');
if ($r['http_code'] === 200 && is_array($r['json'])) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}\n";
    $fail++;
}

// Test responders API
echo "[Test 5] GET api/responders.php... ";
$r = testEndpoint('api/responders.php', 'Responders list');
if ($r['http_code'] === 200 && is_array($r['json'])) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}\n";
    $fail++;
}

// Test stats API
echo "[Test 6] GET api/statistics.php... ";
$r = testEndpoint('api/statistics.php', 'Stats');
if ($r['http_code'] === 200 && is_array($r['json'])) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}\n";
    $fail++;
}

// Test SSE stream rejects without auth
echo "[Test 7] GET api/stream.php (no auth)... ";
$ch = curl_init('http://localhost/newui/api/stream.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode === 401 || strpos($response, 'Not authenticated') !== false) {
    echo "PASS (rejects unauthenticated)\n";
    $pass++;
} else {
    echo "FAIL: HTTP $httpCode\n";
    $fail++;
}

// Test road conditions API
echo "[Test 8] GET api/road-conditions.php... ";
$r = testEndpoint('api/road-conditions.php', 'Road conditions');
if ($r['http_code'] === 200) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: HTTP {$r['http_code']}\n";
    $fail++;
}

// Cleanup
if ($_sharedCookieFile && file_exists($_sharedCookieFile)) {
    unlink($_sharedCookieFile);
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
