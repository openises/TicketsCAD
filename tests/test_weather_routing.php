<?php
/**
 * Phase 112 Phase 2 — route weather alerts to messaging (chat/SMS/email).
 *
 * The Phase 1 engine matched area+rule and delivered only the 'tray' target.
 * Phase 2 delivers chat/sms/email targets through the broker, keyed by the same
 * dispatch-once ledger. Tests the pure text/mapping helpers + an integration
 * check that a chat-target rule now routes (claims a dispatch slot), which the
 * Phase 1 tray-only query previously filtered out.
 *
 * Usage: php tests/test_weather_routing.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/weather_provider_nws.php';
require_once __DIR__ . '/../inc/weather_alerts.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 112 Phase 2 — weather routing to messaging ===\n\n";

// ── Pure helpers ─────────────────────────────────────────────────────────
$alert = [
    'event' => 'Tornado Warning', 'severity' => 'Extreme', 'area_desc' => 'Hennepin, MN',
    'headline' => 'Tornado Warning issued', 'instruction' => 'Take cover now.',
];
$msg = weather_build_message_text($alert);
t('message text includes event + area + instruction',
    strpos($msg, 'Tornado Warning') !== false &&
    strpos($msg, 'Hennepin, MN') !== false &&
    strpos($msg, 'Take cover now.') !== false);

$long = ['event' => 'X', 'area_desc' => 'Y', 'instruction' => str_repeat('abcde ', 100)];
$truncated = weather_build_message_text($long, 120);
$lastChar = function_exists('mb_substr') ? mb_substr($truncated, -1) : substr($truncated, -3);
t('long message is truncated to the cap with an ellipsis',
    (function_exists('mb_strlen') ? mb_strlen($truncated) : strlen($truncated)) <= 121 &&
    ($lastChar === '…' || substr($truncated, -3) === '...'));

t('target→channel mapping (chat→local_chat, sms→sms, email→email, dmr, zello)',
    weather_target_channel('chat') === 'local_chat' &&
    weather_target_channel('sms') === 'sms' &&
    weather_target_channel('email') === 'email' &&
    weather_target_channel('dmr') === 'dmr' &&
    weather_target_channel('zello') === 'zello');

// ── Phase 3 content layer: radio read-out script (pure) ──────────────────
$script = weather_build_readout_script($alert, [
    'prefix' => 'Weather bulletin from the National Weather Service.',
    'callsign' => 'N0NKI', 'max_seconds' => 45,
]);
t('read-out script carries prefix + event + area + instruction',
    strpos($script, 'National Weather Service') !== false &&
    strpos($script, 'Tornado Warning') !== false &&
    strpos($script, 'Hennepin, MN') !== false &&
    strpos($script, 'Take cover') !== false);
t('read-out ends with the §97.119 callsign sign-off', substr($script, -13) === 'N0NKI clear.' || strpos($script, 'N0NKI clear.') !== false);
$noCall = weather_build_readout_script($alert, ['callsign' => '']);
t('no callsign (non-amateur target) → no station ID appended',
    strpos($noCall, ' clear.') === false);
$longAlert = ['event' => 'Flood Warning', 'area_desc' => 'Zone', 'instruction' => str_repeat('move to higher ground ', 60)];
$capped = weather_build_readout_script($longAlert, ['callsign' => 'N0NKI', 'max_seconds' => 20]);
$wc = count(preg_split('/\s+/', trim($capped)));
t('read-out is word-budgeted to the max_seconds cap', $wc <= (int) (20 * 2.4) + 4 && strpos($capped, 'N0NKI clear.') !== false);

// ── Integration: a chat-target rule now routes (was tray-only in Phase 1) ──
$origEnabled = (string) get_variable('weather_alerts_enabled');
$fixture = [
    'id' => 'urn:test:wxroute:tornado-1',
    'geometry' => ['type' => 'Point', 'coordinates' => [-93.27, 44.98]],
    'properties' => [
        'event' => 'Tornado Warning', 'severity' => 'Extreme', 'urgency' => 'Immediate',
        'messageType' => 'Alert', 'areaDesc' => 'Hennepin, MN',
        'headline' => 'TEST', 'instruction' => 'Take cover.',
        'expires' => '2099-07-05T20:45:00-05:00', 'geocode' => ['UGC' => ['MNC053']],
    ],
];
$injected = static function (array $area, string $ua) use ($fixture) {
    return ['ok' => true, 'status' => 200, 'features' => [$fixture]];
};

// Seed a marker area + a CHAT-target rule.
db_query("DELETE FROM `{$prefix}weather_alert_areas` WHERE `label`='TEST_WXROUTE_AREA'");
db_query("INSERT INTO `{$prefix}weather_alert_areas` (`label`,`kind`,`state_code`,`active`,`sort_order`)
          VALUES ('TEST_WXROUTE_AREA','state','MN',1,0)");
$areaId = (int) db_insert_id();
db_query("INSERT INTO `{$prefix}weather_alert_rules`
          (`label`,`area_id`,`target`,`min_severity`,`min_urgency`,`message_types`,`action_mode`,`active`)
          VALUES ('TEST_WXROUTE_CHAT',?, 'chat','Severe','Expected','Alert,Update','notify',1)", [$areaId]);
$ruleId = (int) db_insert_id();
db_query("DELETE FROM `{$prefix}weather_alerts` WHERE `nws_id` LIKE 'urn:test:wxroute:%'");

db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_alerts_enabled','1')
          ON DUPLICATE KEY UPDATE `value`='1'");
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_ua_contact','test@example.com')
          ON DUPLICATE KEY UPDATE `value`='test@example.com'");

$s = weather_poll_run(false, $injected);
t('poll acted on the chat rule (notified >= 1)', $s['notified'] >= 1);

$alertRow = db_fetch_one("SELECT id FROM `{$prefix}weather_alerts` WHERE `nws_id`='urn:test:wxroute:tornado-1'");
$alertRowId = (int) ($alertRow['id'] ?? 0);
$disp = db_fetch_one(
    "SELECT status, detail FROM `{$prefix}weather_alert_dispatch` WHERE `alert_id`=? AND `rule_id`=?",
    [$alertRowId, $ruleId]);
t('chat rule claimed a dispatch ledger row (routing evaluated it)', $disp !== null);
t('dispatch row records the chat target attempt (sent or failed)',
    $disp && in_array($disp['status'], ['sent', 'failed'], true));

// Re-poll: dedup — identical Alert does not re-notify.
$s2 = weather_poll_run(false, $injected);
t('dedup: identical Alert to chat does not re-notify', $s2['notified'] === 0);

// ── Cleanup + restore ─────────────────────────────────────────────────────
db_query("DELETE FROM `{$prefix}weather_alert_dispatch` WHERE `rule_id`=?", [$ruleId]);
db_query("DELETE FROM `{$prefix}weather_alert_rules` WHERE `id`=?", [$ruleId]);
db_query("DELETE FROM `{$prefix}weather_alert_areas` WHERE `id`=?", [$areaId]);
db_query("DELETE FROM `{$prefix}weather_alerts` WHERE `nws_id` LIKE 'urn:test:wxroute:%'");
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_alerts_enabled',?)
          ON DUPLICATE KEY UPDATE `value`=?", [$origEnabled ?: '0', $origEnabled ?: '0']);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
