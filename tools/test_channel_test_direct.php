<?php
/**
 * Regression test — direct channel-test buttons (a beta tester, 2026-06-26).
 *
 * BUG: the "Test SMS / Test Email / Test Slack" buttons in Settings POSTed
 *   action=broadcast with NO csrf_token, so api/chat.php returned 403 and
 *   config.js misreported it as "did not run — enable SMS as a delivery
 *   channel". They were ALSO gated by broker_enabled_channels, so verifying
 *   provider credentials wrongly required enabling routing first.
 *
 * FIX: api/chat.php action=test_channel sends via broker_send($channel)
 *   DIRECTLY (bypasses the enabled-channels gate); config.js test buttons
 *   send the CSRF token and call test_channel, surfacing the real provider
 *   result/error.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';
require __DIR__ . '/../inc/broker.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Direct Channel-Test (test_channel) Tests ===\n\n";
$pass = 0; $fail = 0;
function t($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS: $name\n"; }
    else { $fail++; echo "  FAIL: $name\n"; }
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── broker_send() runs a channel regardless of broker_enabled_channels ──
$orig = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='broker_enabled_channels'");
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('broker_enabled_channels', '[\"local_chat\"]')
          ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
$enabled = _broker_get_enabled_channels();
t("precondition: sms is NOT an enabled channel", !in_array('sms', $enabled, true));
$r = broker_send('sms', ['to' => '+15550000000', 'body' => 'regression test', 'type' => 'test']);
t("broker_send('sms') ran the channel despite it being disabled (returns array)", is_array($r));
t("result carries a 'success' key (provider verdict, not a routing skip)", is_array($r) && array_key_exists('success', $r));
t("result carries an 'error' describing the provider state", is_array($r) && array_key_exists('error', $r));

// cleanup: remove the logged test message + restore the original setting
if (is_array($r) && !empty($r['message_id'])) {
    try { db_query("DELETE FROM `{$prefix}messages` WHERE `id` = ?", [$r['message_id']]); } catch (Exception $e) {}
}
if ($orig === null || $orig === false) {
    db_query("DELETE FROM `{$prefix}settings` WHERE `name`='broker_enabled_channels'");
} else {
    db_query("UPDATE `{$prefix}settings` SET `value`=? WHERE `name`='broker_enabled_channels'", [$orig]);
}

// ── api/chat.php exposes test_channel via broker_send (not broker_broadcast) ──
$chat = file_get_contents(__DIR__ . '/../api/chat.php');
t("chat.php handles action 'test_channel'", strpos($chat, "\$action === 'test_channel'") !== false);
t("test_channel sends via broker_send(\$channel) directly", preg_match('/test_channel.*broker_send\(\$channel/s', $chat) === 1);
t("test_channel is admin-gated", preg_match('/test_channel.*is_admin\(\)/s', $chat) === 1);

// ── config.js test buttons send CSRF + call test_channel (no bare broadcast) ──
$cfg = file_get_contents(__DIR__ . '/../assets/js/config.js');
t("config.js Test SMS calls test_channel channel:'sms'", strpos($cfg, "channel: 'sms'") !== false);
t("config.js Test Email calls test_channel channel:'smtp'", strpos($cfg, "channel: 'smtp'") !== false);
t("config.js Test Slack calls test_channel channel:'slack'", strpos($cfg, "channel: 'slack'") !== false);
t("config.js test buttons send the X-CSRF-Token header", strpos($cfg, "'X-CSRF-Token': csrfToken") !== false);
t("config.js no longer uses the CSRF-less action:'broadcast' test path", strpos($cfg, "action: 'broadcast'") === false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
