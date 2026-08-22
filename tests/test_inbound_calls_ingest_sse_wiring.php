<?php
/**
 * Phase 149 — a real HTTP webhook against the REAL api/sip-ingest.php
 * must publish a REAL row to sse_events, not just create the
 * inbound_calls row.
 *
 * Found live 2026-08-22 (Browser-pane verification against a real logged-
 * in session): api/sip-ingest.php never required inc/sse.php, so
 * inc/inbound-calls.php's _p149_sse() helper — guarded by
 * function_exists('sse_publish_for_call') so ingest never fatals when
 * that file is absent — silently no-op'd on every real webhook. Three
 * real webhooks landed correct rows in `inbound_calls` with ZERO rows
 * ever appearing in `sse_events`; a logged-in dispatcher's screen never
 * rang. tests/test_inbound_calls_ingest.php drives
 * inbound_calls_ingest_event() directly (also without requiring
 * inc/sse.php), so it independently verified the SAME state-machine
 * correctness this test does NOT re-check — it could never have caught
 * this, because it never went through api/sip-ingest.php's own require
 * list. This file exists specifically to prove the two pieces are wired
 * TOGETHER over real HTTP, the same class of gap
 * tests/test_inbound_calls_sse_wiring.php's own docblock already names
 * ("plumbing exists, nobody wired the last mile").
 *
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 * Usage: php tests/test_inbound_calls_ingest_sse_wiring.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sip_token.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 — api/sip-ingest.php really publishes to sse_events (live HTTP) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$BASE_URL = getenv('P149_BASE_URL') ?: (getenv('EXT_API_BASE_URL') ?: 'http://localhost');

function p149_curl(string $method, string $url, array $headers = [], $body = null): array {
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
    $err      = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => $response, 'json' => @json_decode((string) $response, true), 'error' => $err];
}

// Pre-flight: is the web server reachable at all?
$ping = p149_curl('GET', $BASE_URL . '/login.php');
if ($ping['status'] === 0) {
    echo "SKIP: web server not reachable at {$BASE_URL} — run with P149_BASE_URL=http://your.host/path\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

$trunkId = 900014980;
$cleanup = function () use ($prefix, $trunkId) {
    try {
        $ids = db_fetch_all("SELECT id FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
        foreach ($ids as $r) db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE `call_id` = ?", [(int) $r['id']]);
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}sse_events` WHERE `event_type` = 'call:ringing' AND `payload` LIKE '%p149-http-wiring-fixture%'", []); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

$token = sip_token_mint();
db_query(
    "INSERT INTO `{$prefix}pbx_trunks`
        (`id`, `label`, `org_id`, `bearer_token`, `mute_bypass_enabled`, `wrapup_seconds`, `reassign_grace_seconds`, `enabled`)
     VALUES (?, 'P149 HTTP wiring fixture trunk', NULL, ?, 1, 90, 20, 1)",
    [$trunkId, $token]
);

try {
    $beforeMaxId = (int) (db_fetch_value("SELECT MAX(id) FROM `{$prefix}sse_events`") ?? 0);

    $result = p149_curl('POST', $BASE_URL . '/api/sip-ingest.php',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ['event' => 'ringing', 'call_id' => 'p149-http-wiring-fixture', 'caller_number' => '+16125550199', 'event_ts' => gmdate('c')]
    );

    t('the real HTTP webhook is accepted (200)', $result['status'] === 200);
    t('the webhook response reports ok=true', is_array($result['json']) && !empty($result['json']['ok']));

    $call = db_fetch_one("SELECT * FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ? AND `provider_call_id` = 'p149-http-wiring-fixture'", [$trunkId]);
    t('the ingest created a real inbound_calls row', $call !== null);
    t('the row landed in state=ringing', $call && $call['state'] === 'ringing');

    // THE regression: a real sse_events row for THIS call, not merely a
    // correctly-updated inbound_calls row.
    $sseRow = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'call:ringing' ORDER BY id ASC LIMIT 1",
        [$beforeMaxId]
    );
    t('a REAL sse_events row was published by the live HTTP webhook (the actual regression)', $sseRow !== null);
    if ($sseRow) {
        $payload = json_decode((string) $sseRow['payload'], true);
        t('the published payload names the correct call id', is_array($payload) && (int) ($payload['call_id'] ?? 0) === (int) $call['id']);
        t('the published event carries visibility_scope=entitled', $sseRow['visibility_scope'] === 'entitled');
    }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
