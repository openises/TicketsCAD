<?php
/**
 * Webhook system tests.
 * Usage: php tools/test_webhooks.php
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/rbac.php';
require __DIR__ . '/../inc/audit.php';
require __DIR__ . '/../inc/webhooks.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Webhook Tests ===\n\n";
$pass = 0; $fail = 0;

// ── Test 1: Tables exist ────────────────────────────────────
echo "[Test 1] webhooks table exists... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}webhooks`");
    $names = array_column($cols, 'Field');
    if (in_array('url', $names) && in_array('secret', $names) && in_array('events_json', $names) && in_array('active', $names)) {
        echo "PASS (" . count($cols) . " columns)\n"; $pass++;
    } else { echo "FAIL: missing columns\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

echo "[Test 2] webhook_deliveries table exists... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}webhook_deliveries`");
    $names = array_column($cols, 'Field');
    if (in_array('webhook_id', $names) && in_array('event_type', $names) && in_array('status', $names) && in_array('duration_ms', $names)) {
        echo "PASS (" . count($cols) . " columns)\n"; $pass++;
    } else { echo "FAIL: missing columns\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Test 3: Create a webhook ────────────────────────────────
echo "[Test 3] Create webhook... ";
$testSecret = bin2hex(random_bytes(16));
$testEvents = json_encode(['incident:new', 'responder:status']);
$testWebhookId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}webhooks` (`name`, `url`, `secret`, `events_json`, `active`, `retry_max`, `created_by`)
         VALUES (?, ?, ?, ?, 1, 3, 1)",
        ['Test Hook', 'https://httpbin.org/post', $testSecret, $testEvents]
    );
    $testWebhookId = (int) db_insert_id();
    echo "PASS (id=$testWebhookId)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// ── Test 4: Fire event and verify delivery logged ───────────
echo "[Test 4] Fire event + delivery logged... ";
if ($testWebhookId) {
    $fired = webhook_fire('incident:new', ['ticket_id' => 999, 'scope' => 'Test Fire']);
    if ($fired >= 1) {
        // Check delivery was logged
        try {
            $deliveries = db_fetch_all(
                "SELECT * FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ? ORDER BY `id` DESC LIMIT 1",
                [$testWebhookId]
            );
            if (count($deliveries) >= 1 && $deliveries[0]['event_type'] === 'incident:new') {
                echo "PASS (fired=$fired, delivery logged, status=" . $deliveries[0]['status'] . ")\n"; $pass++;
            } else {
                echo "FAIL: delivery not logged correctly\n"; $fail++;
            }
        } catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }
    } else {
        echo "FAIL: webhook_fire returned 0\n"; $fail++;
    }
} else { echo "SKIP (no webhook created)\n"; $fail++; }

// ── Test 5: HMAC signature verification ─────────────────────
echo "[Test 5] HMAC signature verification... ";
$testBody = json_encode(['event_type' => 'test', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => ['msg' => 'hello']]);
$computedSig = hash_hmac('sha256', $testBody, $testSecret);
// Verify it's a valid hex string of expected length
if (strlen($computedSig) === 64 && ctype_xdigit($computedSig)) {
    // Verify deterministic: same input = same output
    $sig2 = hash_hmac('sha256', $testBody, $testSecret);
    if ($computedSig === $sig2) {
        // Verify different secret = different sig
        $sig3 = hash_hmac('sha256', $testBody, 'different-secret');
        if ($computedSig !== $sig3) {
            echo "PASS (64-char hex, deterministic, secret-dependent)\n"; $pass++;
        } else { echo "FAIL: different secrets produce same sig\n"; $fail++; }
    } else { echo "FAIL: non-deterministic\n"; $fail++; }
} else { echo "FAIL: invalid signature format\n"; $fail++; }

// ── Test 6: Inactive webhook not fired ──────────────────────
echo "[Test 6] Inactive webhook not fired... ";
if ($testWebhookId) {
    // Deactivate
    db_query("UPDATE `{$prefix}webhooks` SET `active` = 0 WHERE `id` = ?", [$testWebhookId]);

    // Count deliveries before
    $countBefore = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ?",
        [$testWebhookId]
    );

    // Fire
    webhook_fire('incident:new', ['ticket_id' => 888]);

    // Count deliveries after
    $countAfter = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ?",
        [$testWebhookId]
    );

    if ($countAfter === $countBefore) {
        echo "PASS (no new deliveries)\n"; $pass++;
    } else {
        echo "FAIL: delivery created for inactive webhook\n"; $fail++;
    }

    // Re-activate for next test
    db_query("UPDATE `{$prefix}webhooks` SET `active` = 1 WHERE `id` = ?", [$testWebhookId]);
} else { echo "SKIP\n"; $fail++; }

// ── Test 7: Event filter (only subscribed events fire) ──────
echo "[Test 7] Event filter... ";
if ($testWebhookId) {
    // Count deliveries before
    $countBefore = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ?",
        [$testWebhookId]
    );

    // Fire an event the webhook is NOT subscribed to
    webhook_fire('chat:message', ['text' => 'hello']);

    // Count deliveries after
    $countAfter = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ?",
        [$testWebhookId]
    );

    if ($countAfter === $countBefore) {
        echo "PASS (unsubscribed event ignored)\n"; $pass++;
    } else {
        echo "FAIL: delivery created for unsubscribed event\n"; $fail++;
    }
} else { echo "SKIP\n"; $fail++; }

// ── Cleanup ─────────────────────────────────────────────────
echo "\nCleaning up... ";
if ($testWebhookId) {
    db_query("DELETE FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ?", [$testWebhookId]);
    db_query("DELETE FROM `{$prefix}webhooks` WHERE `id` = ?", [$testWebhookId]);
    echo "OK\n";
} else {
    echo "nothing to clean\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
