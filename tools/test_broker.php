<?php
/**
 * Message Broker Integration Tests
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';
require __DIR__ . '/../inc/broker.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Message Broker Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

// Ensure tables exist
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` INT NOT NULL DEFAULT 0,
        `user_name` VARCHAR(64) NOT NULL DEFAULT 'system', `channel` VARCHAR(64) NOT NULL DEFAULT 'general',
        `recipient` VARCHAR(64) NOT NULL DEFAULT 'all', `body` TEXT NOT NULL,
        `msg_type` VARCHAR(32) NOT NULL DEFAULT 'text', `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
        `ticket_id` INT DEFAULT NULL, `signal_id` INT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`), KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `channel` VARCHAR(64) NOT NULL,
        `direction` ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
        `msg_type` VARCHAR(32) NOT NULL DEFAULT 'general', `sender` VARCHAR(128) NOT NULL DEFAULT 'system',
        `recipient` VARCHAR(256) NOT NULL DEFAULT '', `subject` VARCHAR(256) DEFAULT '',
        `body` TEXT NOT NULL, `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
        `status` VARCHAR(32) NOT NULL DEFAULT 'pending', `error` TEXT DEFAULT NULL,
        `payload` TEXT DEFAULT NULL, `delivered_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Test 1: Channel registry
echo "[Test 1] Channels registered... ";
$statuses = broker_channel_statuses();
$codes = array_column($statuses, 'code');
if (in_array('local_chat', $codes) && in_array('smtp', $codes) && in_array('sms', $codes) && in_array('slack', $codes)) {
    echo "PASS (found: " . implode(', ', $codes) . ")\n";
    $pass++;
} else {
    echo "FAIL (found: " . implode(', ', $codes) . ")\n";
    $fail++;
}

// Test 2: Local chat send
echo "[Test 2] Local chat send... ";
$result = broker_send('local_chat', [
    'body' => 'Test message from broker',
    'to'   => 'all',
    'type' => 'text'
]);
if ($result['success']) {
    echo "PASS (message_id={$result['message_id']})\n";
    $pass++;
} else {
    echo "FAIL: " . ($result['error'] ?? 'unknown') . "\n";
    $fail++;
}

// Test 3: Chat message stored in chat_messages
echo "[Test 3] Chat stored in chat_messages table... ";
try {
    $msg = db_fetch_one("SELECT * FROM `{$prefix}chat_messages` WHERE body = 'Test message from broker' ORDER BY id DESC LIMIT 1");
    if ($msg && $msg['user_name'] === 'admin' && $msg['channel'] === 'general') {
        echo "PASS (id={$msg['id']}, user=admin, channel=general)\n";
        $pass++;
    } else {
        echo "FAIL\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 4: Message logged in messages table
echo "[Test 4] Message logged in messages table... ";
try {
    $log = db_fetch_one("SELECT * FROM `{$prefix}messages` WHERE channel = 'local_chat' ORDER BY id DESC LIMIT 1");
    if ($log && $log['direction'] === 'outbound' && $log['status'] === 'delivered') {
        echo "PASS (status=delivered)\n";
        $pass++;
    } else {
        echo "FAIL: status=" . ($log['status'] ?? 'missing') . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 5: SSE event published on chat
echo "[Test 5] SSE event published for chat... ";
try {
    $evt = db_fetch_one("SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'chat:message' ORDER BY id DESC LIMIT 1");
    if ($evt && strpos($evt['payload'], 'Test message from broker') !== false) {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: SSE event not found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 6: Unknown channel fails gracefully
echo "[Test 6] Unknown channel fails gracefully... ";
$result = broker_send('nonexistent_channel', ['body' => 'test']);
if (!$result['success'] && strpos($result['error'], 'Unknown channel') !== false) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Test 7: Empty body rejected
echo "[Test 7] Empty body rejected... ";
$result = broker_send('local_chat', ['body' => '', 'to' => 'all']);
if (!$result['success']) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: should have been rejected\n";
    $fail++;
}

// Test 8: SMS status is not_configured
echo "[Test 8] SMS status not_configured... ";
$smsStatus = null;
foreach ($statuses as $s) {
    if ($s['code'] === 'sms') $smsStatus = $s['status'];
}
if ($smsStatus === 'not_configured') {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: status=$smsStatus\n";
    $fail++;
}

// Test 9: Slack status is not_configured
echo "[Test 9] Slack status not_configured... ";
$slackStatus = null;
foreach ($statuses as $s) {
    if ($s['code'] === 'slack') $slackStatus = $s['status'];
}
if ($slackStatus === 'not_configured') {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL: status=$slackStatus\n";
    $fail++;
}

// Test 10: Broadcast sends to enabled channels only
echo "[Test 10] Broadcast to enabled channels... ";
$results = broker_broadcast(['body' => 'Broadcast test', 'type' => 'alert']);
// By default only local_chat is enabled
if (isset($results['local_chat']) && $results['local_chat']['success'] && !isset($results['smtp'])) {
    echo "PASS (sent to local_chat only, others skipped)\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Cleanup
db_query("DELETE FROM `{$prefix}chat_messages` WHERE body LIKE '%Test message from broker%' OR body LIKE '%Broadcast test%'");
db_query("DELETE FROM `{$prefix}messages` WHERE body LIKE '%Test message from broker%' OR body LIKE '%Broadcast test%'");
db_query("DELETE FROM `{$prefix}sse_events` WHERE event_type = 'chat:message' AND payload LIKE '%Test message%'");

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
