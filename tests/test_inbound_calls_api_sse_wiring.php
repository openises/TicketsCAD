<?php
/**
 * Phase 149 — a real claim through api/inbound-calls.php must publish a
 * REAL row to sse_events, not just update the inbound_calls row.
 *
 * Found live 2026-08-22 (video-production verification against two real
 * logged-in browser sessions on your-server.example.com): api/inbound-
 * calls.php never required inc/sse.php, so inc/inbound-calls.php's
 * _p149_sse() helper -- guarded by function_exists('sse_publish_for_call')
 * so a claim/release/reassign/force_reclaim never fatals when that file is
 * absent -- silently no-op'd on EVERY action this endpoint dispatches. A
 * claim itself succeeded in full (the row updated, the claimant's own New
 * Incident tab opened correctly with the right call_id) but NO other
 * logged-in dispatcher's screen ever updated -- confirmed two ways: (1) a
 * second browser session's `window.CallAlert._calls[id].state` stayed
 * 'ringing' for 10+ seconds after the first session's claim genuinely
 * succeeded, and (2) a direct query of sse_events showed call:ringing /
 * call:abandoned / call:wrapup rows (all published via api/sip-ingest.php,
 * which DOES require inc/sse.php) but never a single call:claimed row,
 * across four real claims. This is the exact same gap class
 * tests/test_inbound_calls_ingest_sse_wiring.php's own docblock already
 * documents for api/sip-ingest.php on this same date -- that fix was never
 * mirrored onto this file. tools/inbound_calls_tick.php had the identical
 * gap for its 'call:stale'/'call:ended' publishes and was fixed alongside
 * this file's own fix (see that file's comment); it is not re-tested here
 * since it is a CLI-only scheduled sweep, not an HTTP endpoint.
 *
 * @requires-http — hits http://localhost (or P149_BASE_URL) via a live web
 * server; skipped when NEWUI_TEST_NO_HTTP=1.
 * Usage: php tests/test_inbound_calls_api_sse_wiring.php
 *        P149_BASE_URL=https://your-server.example.com php tests/test_inbound_calls_api_sse_wiring.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sip_token.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/inbound-calls.php';

$pass = 0; $fail = 0;
function t2($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 — api/inbound-calls.php's claim action really publishes to sse_events (live HTTP) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$BASE_URL = getenv('P149_BASE_URL') ?: (getenv('EXT_API_BASE_URL') ?: 'http://localhost');

function p149b_curl(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $response, 'json' => @json_decode((string) $response, true)];
}

// Pre-flight: is the web server reachable at all?
$ping = p149b_curl('GET', $BASE_URL . '/login.php');
if ($ping['status'] === 0) {
    echo "SKIP: web server not reachable at {$BASE_URL} — run with P149_BASE_URL=http://your.host\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

$trunkId = 900014981;
$cleanup = function () use ($prefix, $trunkId) {
    try {
        $ids = db_fetch_all("SELECT id FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
        foreach ($ids as $r) db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE `call_id` = ?", [(int) $r['id']]);
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}sse_events` WHERE `event_type` = 'call:claimed' AND `payload` LIKE '%p149-api-wiring-fixture%'", []); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

$token = sip_token_mint();
db_query(
    "INSERT INTO `{$prefix}pbx_trunks`
        (`id`, `label`, `org_id`, `bearer_token`, `mute_bypass_enabled`, `wrapup_seconds`, `reassign_grace_seconds`, `enabled`)
     VALUES (?, 'P149 API wiring fixture trunk', NULL, ?, 1, 90, 20, 1)",
    [$trunkId, $token]
);

try {
    // Ring the call through the REAL webhook receiver first (this path is
    // already proven wired — tests/test_inbound_calls_ingest_sse_wiring.php).
    $ring = p149b_curl('POST', $BASE_URL . '/api/sip-ingest.php',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ['event' => 'ringing', 'call_id' => 'p149-api-wiring-fixture', 'caller_number' => '+15550187', 'event_ts' => gmdate('c')]
    );
    t2('ringing webhook accepted (200)', $ring['status'] === 200);

    $call = db_fetch_one("SELECT * FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ? AND `provider_call_id` = 'p149-api-wiring-fixture'", [$trunkId]);
    t2('the ring created a real inbound_calls row in state=ringing', $call && $call['state'] === 'ringing');
    if (!$call) { echo "\n=== $pass passed, $fail failed ===\n"; exit(1); }
    $callId = (int) $call['id'];

    $beforeMaxId = (int) (db_fetch_value("SELECT MAX(id) FROM `{$prefix}sse_events`") ?? 0);

    // Claim it directly through inc/inbound-calls.php's real writer -- this
    // reproduces the exact call api/inbound-calls.php?action=claim makes,
    // without needing an authenticated session over HTTP (claiming itself
    // isn't the bug; the MISSING REQUIRE in the API file is). The fixture
    // proves the underlying function correctly publishes once inc/sse.php
    // is loaded -- which is exactly what api/inbound-calls.php now does at
    // the top of the file (the actual fix under test).
    $claim = inbound_call_claim($callId, 990100001, 'p149-api-wiring-fixture-user');
    t2('the claim itself succeeds', $claim['ok'] === true);

    $sseRow = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'call:claimed' ORDER BY id ASC LIMIT 1",
        [$beforeMaxId]
    );
    t2('a REAL sse_events row was published for the claim (the actual regression)', $sseRow !== null);
    if ($sseRow) {
        $payload = json_decode((string) $sseRow['payload'], true);
        t2('the published payload names the correct call id', is_array($payload) && (int) ($payload['call_id'] ?? 0) === $callId);
        t2('the published payload names the claimant', is_array($payload) && ($payload['claimed_by_name'] ?? null) === 'p149-api-wiring-fixture-user');
        t2('the published event carries visibility_scope=entitled', $sseRow['visibility_scope'] === 'entitled');
    }

    // Static proof the fix is what it claims to be: api/inbound-calls.php's
    // source now requires inc/sse.php (tokenized, not grepped, so a
    // reformat can't fool this the way a substring scan could).
    $src = file_get_contents(__DIR__ . '/../api/inbound-calls.php');
    $tokens = token_get_all($src);
    $requiresSse = false;
    foreach ($tokens as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && stripos($tok[1], 'inc/sse.php') !== false) {
            $requiresSse = true;
            break;
        }
    }
    t2('api/inbound-calls.php\'s source now requires inc/sse.php', $requiresSse);

    $tickSrc = file_get_contents(__DIR__ . '/../tools/inbound_calls_tick.php');
    $tickTokens = token_get_all($tickSrc);
    $tickRequiresSse = false;
    foreach ($tickTokens as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && stripos($tok[1], 'inc/sse.php') !== false) {
            $tickRequiresSse = true;
            break;
        }
    }
    t2('tools/inbound_calls_tick.php\'s source now requires inc/sse.php', $tickRequiresSse);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
