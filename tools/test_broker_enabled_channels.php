<?php
/**
 * Broker Enabled Delivery Channels Tests
 *
 * Covers the broker_enabled_channels setting that backs the
 * "Enabled delivery channels" UI card in the Message Routing panel.
 * Verifies the same insert-or-update persistence path used by
 * api/routing.php (action=save_enabled_channels) writes a JSON array
 * that _broker_get_enabled_channels() reads back correctly, and that
 * local_chat is always present.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';
require __DIR__ . '/../inc/broker.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Broker Enabled Channels Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

// Capture the original value so we can restore it after the run.
$original = null;
try {
    $original = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'broker_enabled_channels'"
    );
} catch (Exception $e) {
    $original = null;
}

/**
 * Mirror of the API persistence path (api/routing.php action=
 * save_enabled_channels): validate against the registry, force
 * local_chat on, upsert as a JSON array.
 */
function _test_save_enabled_channels(array $requested) {
    global $prefix, $_broker_channels;
    $valid = array_keys($_broker_channels);
    $selected = [];
    foreach ($requested as $code) {
        $code = (string) $code;
        if (in_array($code, $valid, true) && !in_array($code, $selected, true)) {
            $selected[] = $code;
        }
    }
    if (!in_array('local_chat', $selected, true)) {
        array_unshift($selected, 'local_chat');
    }
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('broker_enabled_channels', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [json_encode(array_values($selected))]
    );
    return array_values($selected);
}

// Test 1: Save a multi-channel set, read it back through the broker.
echo "[Test 1] Save + read-back round-trips... ";
_test_save_enabled_channels(['local_chat', 'sms', 'smtp']);
$read = _broker_get_enabled_channels();
if (in_array('local_chat', $read, true) && in_array('sms', $read, true) && in_array('smtp', $read, true)) {
    echo "PASS (read: " . implode(', ', $read) . ")\n";
    $pass++;
} else {
    echo "FAIL (read: " . implode(', ', $read) . ")\n";
    $fail++;
}

// Test 2: local_chat is forced on even if the client omits it.
echo "[Test 2] local_chat always present... ";
$saved = _test_save_enabled_channels(['sms']); // intentionally omit local_chat
$read = _broker_get_enabled_channels();
if (in_array('local_chat', $saved, true) && in_array('local_chat', $read, true)) {
    echo "PASS (saved: " . implode(', ', $saved) . ")\n";
    $pass++;
} else {
    echo "FAIL (saved: " . implode(', ', $saved) . ")\n";
    $fail++;
}

// Test 3: Unregistered channel codes are rejected.
echo "[Test 3] Bogus channel codes filtered out... ";
$saved = _test_save_enabled_channels(['local_chat', 'totally_fake_channel', 'slack']);
if (!in_array('totally_fake_channel', $saved, true) && in_array('slack', $saved, true)) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL (saved: " . implode(', ', $saved) . ")\n";
    $fail++;
}

// Test 4: Stored value is a valid JSON array.
echo "[Test 4] Stored as JSON array... ";
$raw = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'broker_enabled_channels'");
$decoded = json_decode($raw, true);
if (is_array($decoded) && in_array('local_chat', $decoded, true)) {
    echo "PASS (raw: $raw)\n";
    $pass++;
} else {
    echo "FAIL (raw: $raw)\n";
    $fail++;
}

// Test 5: Disabling a channel makes broker_broadcast skip it.
echo "[Test 5] Disabled channel skipped by broadcast... ";
_test_save_enabled_channels(['local_chat']); // only local_chat enabled
$results = broker_broadcast(['body' => 'enabled-channels test broadcast', 'type' => 'alert']);
if (isset($results['local_chat']) && !isset($results['smtp']) && !isset($results['sms'])) {
    echo "PASS (delivered to local_chat only)\n";
    $pass++;
} else {
    echo "FAIL (channels: " . implode(', ', array_keys($results)) . ")\n";
    $fail++;
}

// Restore the original setting (or remove the row if there was none).
try {
    if ($original === null) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'broker_enabled_channels'");
    } else {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('broker_enabled_channels', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$original]
        );
    }
} catch (Exception $e) {}
db_query("DELETE FROM `{$prefix}chat_messages` WHERE body = 'enabled-channels test broadcast'");
db_query("DELETE FROM `{$prefix}messages` WHERE body = 'enabled-channels test broadcast'");

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
