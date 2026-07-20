<?php
/**
 * Phase 94 Stage 8 — External API integration tests.
 *
 * Exercises every shipped endpoint with real bearer-token auth via
 * curl-against-localhost. Mints a token in-process via
 * ext_api_mint_token() (no HTTP needed for mint), then makes HTTP
 * calls back into the running web server and asserts on response
 * shape + DB state.
 *
 * Requires a running web server hosting the install. If localhost
 * isn't reachable, the test SKIPS cleanly (returns 0 pass / 0 fail)
 * instead of failing — dev workstations without a configured
 * Apache won't pollute the suite results.
 *
 * Run via the master test runner: php tools/test_all.php
 * Or directly:                    php tests/test_external_api.php
 *
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/external-auth.php';

echo "=== Phase 94 External API Integration Tests ===\n\n";

$pass = 0;
$fail = 0;
$failures = [];

// Pick the base URL to test against. Default is http://localhost for the
// project's standard XAMPP/Apache layout; override via env if needed.
// Tests against HTTP because TLS gate is configurable (off in dev).
$BASE_URL = getenv('EXT_API_BASE_URL') ?: 'http://localhost';

function _ext_assert(bool $cond, string $what, string $detail = '') {
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "  PASS  {$what}\n";
    } else {
        $fail++;
        $failures[] = "{$what}" . ($detail ? " — {$detail}" : '');
        echo "  FAIL  {$what}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

function _ext_curl(string $method, string $url, array $headers = [], $body = null, bool $multipart = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,  // localhost may have a self-signed cert
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body); // array → multipart
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
    }
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['status' => $httpCode, 'body' => $response, 'json' => @json_decode((string) $response, true), 'error' => $error];
}

// ── Pre-flight: is the web server reachable? ────────────────────
$ping = _ext_curl('GET', $BASE_URL . '/api/external/v1/');
if ($ping['status'] === 0) {
    echo "  SKIP  All tests — localhost web server not reachable. ";
    echo "Run with EXT_API_BASE_URL=http://your.host if testing remote.\n\n";
    echo "=== Results: SKIPPED (0 / 0) ===\n";
    exit(0);
}

// Discovery stub responds with no auth required — sanity check
_ext_assert(
    $ping['status'] === 200,
    'discovery stub returns 200 unauthenticated',
    "got HTTP {$ping['status']}"
);
_ext_assert(
    isset($ping['json']['resources']) && in_array('incidents', $ping['json']['resources']),
    'discovery stub lists incidents resource'
);

// ── Mint a test token bound to a real user ──────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';

// Find a real user id to bind the token to (lowest active user)
try {
    $userRow = db_fetch_one("SELECT id FROM `{$prefix}user` ORDER BY id ASC LIMIT 1");
} catch (Exception $e) {
    echo "  SKIP — couldn't query user table: {$e->getMessage()}\n";
    exit(0);
}
if (!$userRow) {
    echo "  SKIP — no users in user table to bind a token to\n";
    exit(0);
}
$bindUserId = (int) $userRow['id'];

// Mint a wildcard-scope token + a read-only token
try {
    $fullToken = ext_api_mint_token($bindUserId, ['*'], $bindUserId,
        ['name' => 'Stage 8 integration test — full', 'rate_limit_per_hour' => 100000]);
    $readToken = ext_api_mint_token($bindUserId, ['incidents:read'], $bindUserId,
        ['name' => 'Stage 8 integration test — read', 'rate_limit_per_hour' => 100000]);
} catch (Exception $e) {
    echo "  FAIL — token mint threw: {$e->getMessage()}\n";
    exit(1);
}
_ext_assert(!empty($fullToken['raw_token']), 'mint full-scope token via ext_api_mint_token()');
_ext_assert(!empty($readToken['raw_token']), 'mint read-only token via ext_api_mint_token()');

$AUTH_FULL = ['Authorization: Bearer ' . $fullToken['raw_token']];
$AUTH_READ = ['Authorization: Bearer ' . $readToken['raw_token']];

// Track created rows for cleanup
$created = ['ticket' => [], 'action' => [], 'assigns' => [], 'member' => [], 'patient' => []];

// ── Auth failures ───────────────────────────────────────────────
echo "\n-- Auth failure paths --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents");
_ext_assert($r['status'] === 401 && ($r['json']['error'] ?? '') === 'missing_token',
    '401 missing_token when no Authorization header');

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents",
    ['Authorization: Bearer tcad_p_definitely-not-a-real-token-12345']);
_ext_assert($r['status'] === 401 && ($r['json']['error'] ?? '') === 'invalid_token',
    '401 invalid_token on garbage bearer');

// Revoke + retry → token_revoked
try {
    db_query("UPDATE `{$prefix}external_api_tokens` SET revoked_at = NOW() WHERE id = ?", [$readToken['id']]);
    $r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents", $AUTH_READ);
    _ext_assert($r['status'] === 401 && ($r['json']['error'] ?? '') === 'token_revoked',
        '401 token_revoked after admin revokes');
    // Un-revoke for subsequent tests
    db_query("UPDATE `{$prefix}external_api_tokens` SET revoked_at = NULL WHERE id = ?", [$readToken['id']]);
} catch (Exception $e) {
    _ext_assert(false, 'revoke + retry flow', $e->getMessage());
}

// ── Scope enforcement ───────────────────────────────────────────
echo "\n-- Scope enforcement --\n";

$r = _ext_curl('POST', "$BASE_URL/api/external/v1/incidents",
    array_merge($AUTH_READ, ['Content-Type: application/json']),
    ['in_types_id' => 1, 'scope' => 'should-fail-on-scope']);
_ext_assert($r['status'] === 403 && ($r['json']['error'] ?? '') === 'forbidden_scope',
    'POST with read-only token → 403 forbidden_scope');

// ── Incidents CRUD ──────────────────────────────────────────────
echo "\n-- Incidents endpoint --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents?limit=2", $AUTH_FULL);
_ext_assert($r['status'] === 200 && !empty($r['json']['ok']),
    'GET /incidents?limit=2 → 200');
_ext_assert(isset($r['json']['data']['incidents']) && is_array($r['json']['data']['incidents']),
    'GET /incidents returns data.incidents array');

// Find a real incident type id to use for create
try {
    $typeRow = db_fetch_one("SELECT id FROM `{$prefix}in_types` ORDER BY id ASC LIMIT 1");
} catch (Exception $e) { $typeRow = null; }
$typeId = $typeRow ? (int) $typeRow['id'] : 1;

$r = _ext_curl('POST', "$BASE_URL/api/external/v1/incidents",
    array_merge($AUTH_FULL, ['Content-Type: application/json']),
    ['in_types_id' => $typeId, 'scope' => 'Stage 8 integration test incident',
     'contact' => 'Test', 'phone' => '555-0000']);
_ext_assert($r['status'] === 201 && !empty($r['json']['data']['id']),
    'POST /incidents create → 201 with new id');
if (!empty($r['json']['data']['id'])) {
    $ticketId = (int) $r['json']['data']['id'];
    $created['ticket'][] = $ticketId;

    $r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents/$ticketId", $AUTH_FULL);
    _ext_assert($r['status'] === 200 && (int)($r['json']['data']['id'] ?? 0) === $ticketId,
        "GET /incidents/$ticketId via clean URL → 200");

    $r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents.php?id=$ticketId", $AUTH_FULL);
    _ext_assert($r['status'] === 200 && (int)($r['json']['data']['id'] ?? 0) === $ticketId,
        "GET /incidents.php?id=$ticketId (direct-file fallback) → 200");

    // 4b action notes
    $r = _ext_curl('POST', "$BASE_URL/api/external/v1/incident-actions.php",
        array_merge($AUTH_FULL, ['Content-Type: application/json']),
        ['ticket_id' => $ticketId, 'note' => 'Stage 8 test note']);
    _ext_assert($r['status'] === 201 && !empty($r['json']['data']['id']),
        'POST /incident-actions → 201 with new id');
    if (!empty($r['json']['data']['id'])) $created['action'][] = (int) $r['json']['data']['id'];

    // 4b — clean URL form
    $r = _ext_curl('POST', "$BASE_URL/api/external/v1/incidents/$ticketId/actions",
        array_merge($AUTH_FULL, ['Content-Type: application/json']),
        ['note' => 'Stage 8 test note via clean URL']);
    _ext_assert($r['status'] === 201,
        "POST /incidents/$ticketId/actions (clean URL via dispatcher) → 201");
    if (!empty($r['json']['data']['id'])) $created['action'][] = (int) $r['json']['data']['id'];
}

// ── Members CRUD ────────────────────────────────────────────────
echo "\n-- Members endpoint --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/members?limit=2", $AUTH_FULL);
_ext_assert($r['status'] === 200, 'GET /members?limit=2 → 200');

$r = _ext_curl('POST', "$BASE_URL/api/external/v1/members",
    array_merge($AUTH_FULL, ['Content-Type: application/json']),
    ['first_name' => 'Stage8', 'last_name' => 'TestUser', 'callsign' => 'S8T']);
_ext_assert($r['status'] === 201 && !empty($r['json']['data']['id']),
    'POST /members create → 201');
if (!empty($r['json']['data']['id'])) {
    $memberId = (int) $r['json']['data']['id'];
    $created['member'][] = $memberId;

    // Stage 4i — member-status PATCH
    try {
        $statusRow = db_fetch_one("SELECT id FROM `{$prefix}member_status` ORDER BY id ASC LIMIT 1");
    } catch (Exception $e) { $statusRow = null; }
    if ($statusRow) {
        $r = _ext_curl('PATCH', "$BASE_URL/api/external/v1/members/$memberId/status",
            array_merge($AUTH_FULL, ['Content-Type: application/json']),
            ['status_id' => (int) $statusRow['id']]);
        _ext_assert($r['status'] === 200,
            "PATCH /members/$memberId/status → 200");
    }

    $r = _ext_curl('DELETE', "$BASE_URL/api/external/v1/members/$memberId", $AUTH_FULL);
    _ext_assert($r['status'] === 200 && !empty($r['json']['data']['deleted']),
        "DELETE /members/$memberId → 200 deleted=true");
}

// ── Responders + status ────────────────────────────────────────
echo "\n-- Responders endpoint --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/responders?limit=2", $AUTH_FULL);
_ext_assert($r['status'] === 200, 'GET /responders?limit=2 → 200');

// ── Facilities + Teams ─────────────────────────────────────────
echo "\n-- Facilities + Teams endpoints --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/facilities?limit=2", $AUTH_FULL);
_ext_assert($r['status'] === 200, 'GET /facilities?limit=2 → 200');

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/teams?limit=2", $AUTH_FULL);
_ext_assert($r['status'] === 200, 'GET /teams?limit=2 → 200');

// ── Incident types ─────────────────────────────────────────────
echo "\n-- Incident types endpoint --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/incident-types?limit=2", $AUTH_FULL);
_ext_assert($r['status'] === 200, 'GET /incident-types?limit=2 → 200');

// ── URL dispatcher edge cases ──────────────────────────────────
echo "\n-- Dispatcher routing --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/widgets", $AUTH_FULL);
_ext_assert($r['status'] === 404 && ($r['json']['error'] ?? '') === 'unknown_resource',
    'unknown resource → 404 unknown_resource');

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents/abc", $AUTH_FULL);
_ext_assert($r['status'] === 404 && ($r['json']['error'] ?? '') === 'invalid_incident_id',
    'non-numeric incident id → 404 invalid_incident_id');

// ── Response envelope shape ────────────────────────────────────
echo "\n-- Response envelope --\n";

$r = _ext_curl('GET', "$BASE_URL/api/external/v1/incidents?limit=1", $AUTH_FULL);
_ext_assert(isset($r['json']['ok']) && $r['json']['ok'] === true,
    'envelope has ok=true on success');
_ext_assert(($r['json']['api_version'] ?? '') === 'v1',
    'envelope has api_version=v1');
_ext_assert(!empty($r['json']['request_id']),
    'envelope has non-empty request_id');

// ── Cleanup ────────────────────────────────────────────────────
echo "\n-- Cleanup --\n";

try {
    foreach ($created['action'] as $id) {
        db_query("DELETE FROM `{$prefix}action` WHERE id = ?", [$id]);
    }
    foreach ($created['ticket'] as $id) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$id]);
        db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$id]);
        db_query("DELETE FROM `{$prefix}allocates` WHERE resource_id = ? AND type = 1", [$id]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$id]);
    }
    foreach ($created['member'] as $id) {
        db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$id]);
    }
    // Drop the test tokens
    db_query("DELETE FROM `{$prefix}external_api_tokens` WHERE id IN (?, ?)",
        [$fullToken['id'], $readToken['id']]);
    db_query("DELETE FROM `{$prefix}external_api_rate_limits` WHERE token_id IN (?, ?)",
        [$fullToken['id'], $readToken['id']]);
    echo "  CLEAN test artifacts removed\n";
} catch (Exception $e) {
    echo "  WARN  cleanup partial: {$e->getMessage()}\n";
}

// ── Summary ────────────────────────────────────────────────────
echo "\n=== Results: " . ($fail === 0 ? 'PASS' : 'FAIL') .
     " ({$pass} pass, {$fail} fail) ===\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
}
exit($fail === 0 ? 0 : 1);
