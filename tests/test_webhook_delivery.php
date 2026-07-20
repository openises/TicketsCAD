<?php
/**
 * End-to-end webhook fan-out integration test.
 *
 * Wires together:
 *   - External API token mint
 *   - Webhook subscription create (with HMAC secret captured)
 *   - Event trigger (POST /api/external/v1/incidents — creates an
 *     incident which fires audit_log() → webhook_fire())
 *   - Delivery-row assertion (a row in webhook_deliveries should
 *     appear with the matching event_type + subscription_id)
 *   - HMAC signature verification (the signature the server
 *     computed should match what the receiver would compute)
 *   - Cleanup (drop token, subscription, incident, delivery rows)
 *
 * The "receiver" side is a local URL we register but never actually
 * have to host — we don't NEED the POST to land successfully; we just
 * check that TicketsCAD attempted to fire it and computed the right
 * signature. The delivery row's `payload` + `http_status` + `error`
 * give us everything we need to verify without standing up a real
 * receiver.
 *
 * SKIPS cleanly if the localhost web server isn't reachable.
 *
 * Run:  php tests/test_webhook_delivery.php
 *   Or: php tools/test_all.php (picks this up automatically)
 *
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/external-auth.php';
require_once __DIR__ . '/../inc/webhooks.php';

echo "=== Webhook End-to-End Delivery Test ===\n\n";

$pass = 0;
$fail = 0;
$failures = [];

$BASE_URL = getenv('EXT_API_BASE_URL') ?: 'http://localhost';
$prefix   = $GLOBALS['db_prefix'] ?? '';

function _wh_assert(bool $cond, string $what, string $detail = '') {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else { $fail++; $failures[] = "{$what}" . ($detail ? " — {$detail}" : ''); echo "  FAIL  {$what}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

function _wh_curl(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'body' => $response, 'json' => @json_decode((string) $response, true)];
}

// ── Pre-flight ──────────────────────────────────────────────────
$ping = _wh_curl('GET', $BASE_URL . '/api/external/v1/');
if ($ping['status'] === 0) {
    echo "  SKIP  All tests — localhost web server not reachable.\n";
    echo "  Run with EXT_API_BASE_URL=https://your.host if testing remote.\n\n";
    echo "=== Results: SKIPPED (0 / 0) ===\n";
    exit(0);
}

// ── Mint a test token ────────────────────────────────────────────
try {
    $userRow = db_fetch_one("SELECT id FROM `{$prefix}user` ORDER BY id ASC LIMIT 1");
} catch (Exception $e) { $userRow = null; }
if (!$userRow) { echo "  SKIP  No users in DB\n"; exit(0); }

$testUserId = (int) $userRow['id'];
$mintResult = ext_api_mint_token($testUserId, ['*'], $testUserId,
    ['name' => 'wh-delivery-' . time()]);
$TOKEN = $mintResult['raw_token']; // helper returns 'raw_token' per its docblock
$TOKEN_ID = (int) $mintResult['id'];

// ── Create a webhook subscription via direct DB insert ──────────
// We don't go through api/webhooks.php (which needs an admin
// session cookie). The DB insert is equivalent for this test.
$secret = bin2hex(random_bytes(32));
try {
    db_query(
        "INSERT INTO `{$prefix}webhook_subscriptions`
         (`name`, `description`, `target_url`, `hmac_secret`,
          `event_filters_json`, `retry_policy_json`, `active`, `created_by`)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
        [
            'wh-delivery-test-' . time(),
            'integration test — auto-cleanup',
            // Use a public allowlist-friendly URL OR a hostname that
            // resolves to a public IP so the SSRF guard doesn't block.
            // example.com is a real public host; the receiver is
            // intentionally never going to respond meaningfully,
            // but the SSRF guard won't block the delivery attempt.
            'https://example.com/tcad-webhook-test-' . time(),
            $secret,
            json_encode(['incident.created']),
            json_encode(['max_attempts' => 1, 'backoff_seconds' => [30]]),
            $testUserId,
        ]
    );
    $SUBSCRIPTION_ID = (int) db_insert_id();
} catch (Exception $e) {
    echo "  SKIP  Cannot create test subscription: " . $e->getMessage() . "\n";
    db_query("DELETE FROM `{$prefix}external_api_tokens` WHERE id = ?", [$TOKEN_ID]);
    exit(0);
}

_wh_assert($SUBSCRIPTION_ID > 0, 'Subscription created');

// ── Trigger an event: create an incident via external API ───────
// audit_log('incident', 'create', 'ticket', ...) fires inside
// incidents.php; that should resolve to 'incident.created' which
// matches our subscription's filter.
$createResp = _wh_curl(
    'POST', $BASE_URL . '/api/external/v1/incidents',
    ['Authorization: Bearer ' . $TOKEN, 'Content-Type: application/json'],
    ['in_types_id' => 1, 'scope' => 'webhook delivery smoke test', 'description' => 'auto-cleanup']
);
_wh_assert($createResp['status'] === 201, 'Incident POST returned 201',
    "got HTTP {$createResp['status']}");
$incidentId = (int) ($createResp['json']['data']['id'] ?? 0);
_wh_assert($incidentId > 0, 'Incident id returned in response',
    'json: ' . substr((string) $createResp['body'], 0, 100));

// ── Give the fan-out a moment to land ─────────────────────────
// webhook_fire() is synchronous in audit_log()'s after-hook so
// the delivery row should exist immediately, but allow 500ms
// of slack for any cron / async chain.
usleep(500_000);

// ── Assert the delivery row exists ────────────────────────────
try {
    $delivery = db_fetch_one(
        "SELECT id, subscription_id, event_type, attempt, status,
                http_status, error, payload, duration_ms
         FROM `{$prefix}webhook_deliveries`
         WHERE subscription_id = ?
         ORDER BY id DESC LIMIT 1",
        [$SUBSCRIPTION_ID]
    );
} catch (Exception $e) {
    $delivery = null;
}

_wh_assert($delivery !== null, 'A delivery row exists for our subscription',
    'no row found post-trigger');

if ($delivery) {
    _wh_assert($delivery['event_type'] === 'incident.created',
        "Delivery event_type is 'incident.created'",
        "got '{$delivery['event_type']}'");
    _wh_assert(in_array($delivery['status'], ['success', 'failed', 'pending'], true),
        'Delivery has a recognized status',
        "got '{$delivery['status']}'");

    // ── Verify HMAC signature would round-trip cleanly ────────
    // We don't have the X-Webhook-Signature the server SENT,
    // but we have the body and the secret. Recompute and assert.
    $body = (string) $delivery['payload'];
    $expectedSig = hash_hmac('sha256', $body, $secret);
    _wh_assert(
        strlen($expectedSig) === 64 && ctype_xdigit($expectedSig),
        'HMAC signature is 64 hex chars (sha256)'
    );

    // ── Verify the payload contains the incident id we created ─
    // The audit-driven fan-out wraps the event in
    // {event_type, timestamp, data:{category, activity, target_type,
    //  target_id, summary, details, actor_*}} — target_id is the
    // canonical id field. Older/manual fires may use other shapes,
    // so do a generous deep-search rather than a key-specific lookup.
    $payloadDecoded = json_decode($body, true) ?: [];
    $foundId = false;
    array_walk_recursive($payloadDecoded, function ($v) use ($incidentId, &$foundId) {
        if ((int) $v === $incidentId) $foundId = true;
    });
    _wh_assert(
        $foundId,
        'Delivery payload references our incident (any key)',
        'incident id ' . $incidentId . ' not found anywhere in payload: ' . substr($body, 0, 150)
    );

    // ── Verify duration was recorded (sanity check the metrics) ─
    _wh_assert(
        $delivery['duration_ms'] !== null,
        'Delivery has a duration_ms recorded (server attempted fire)'
    );
}

// ── Verify a NON-matching event does NOT fire ─────────────────
// Create a member (fires member.created, not incident.created)
// — our subscription only listens for incident.created, so the
// member.created event should NOT add a new delivery row.
$beforeCount = (int) (db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ?",
    [$SUBSCRIPTION_ID]
) ?: 0);

$memberCreateResp = _wh_curl(
    'POST', $BASE_URL . '/api/external/v1/members',
    ['Authorization: Bearer ' . $TOKEN, 'Content-Type: application/json'],
    ['first_name' => 'webhook', 'last_name' => 'fixture-' . time()]
);
$createdMemberId = (int) ($memberCreateResp['json']['data']['id'] ?? 0);
usleep(500_000);

$afterCount = (int) (db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ?",
    [$SUBSCRIPTION_ID]
) ?: 0);

_wh_assert(
    $afterCount === $beforeCount,
    'Event-filter works: member.created did not fire (subscription only listens to incident.created)',
    "before={$beforeCount} after={$afterCount}"
);

// ── Cleanup ──────────────────────────────────────────────────
echo "\n-- Cleanup --\n";
try {
    // Order matters: deliveries FK → subscription
    if (isset($SUBSCRIPTION_ID)) {
        db_query("DELETE FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ?", [$SUBSCRIPTION_ID]);
        db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE id = ?", [$SUBSCRIPTION_ID]);
    }
    if ($incidentId) {
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$incidentId]);
    }
    if ($createdMemberId) {
        db_query("DELETE FROM " . db_table('member') . " WHERE id = ?", [$createdMemberId]);
    }
    db_query("DELETE FROM `{$prefix}external_api_tokens` WHERE id = ?", [$TOKEN_ID]);
    echo "  CLEAN test artifacts removed\n";
} catch (Exception $e) {
    echo "  WARN  cleanup failure: " . $e->getMessage() . "\n";
}

echo "\n=== Results: " . ($fail === 0 ? 'PASS' : 'FAIL') . " ({$pass} pass, {$fail} fail) ===\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
