<?php
/**
 * Phase 112 Phase 3 — radio read-out delivery (DMR).
 *
 * NOTHING in this test keys a radio: the auto-fire path uses an injected TX
 * callable, the operator-approve path is a pure DB insert into the Phase
 * 85f approval queue, and the Zello path is a pure DB insert into
 * zello_outbox (all verified + cleaned up). Verifies the safety model:
 * operator-approve default, auto-fire degrades to approval unless the extra
 * OFF-by-default switch is on (Zello included — Zello channels gateway onto
 * RF), DMR scripts carry the §97.119 sign-off while Zello scripts don't,
 * and the poll routes dmr/zello rules through the delivery layer with
 * ledger statuses.
 *
 * Usage: php tests/test_weather_radio.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/weather_radio.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 112 Phase 3 — radio read-out (dry, no TX) ===\n\n";

$alert = [
    'nws_id' => 'urn:test:wxradio:1', 'event' => 'Tornado Warning',
    'severity' => 'Extreme', 'area_desc' => 'Hennepin, MN',
    'headline' => 'Tornado Warning issued', 'instruction' => 'Take cover now.',
];

// ── Setting snapshots (restore at the end) ──────────────────────────────────
$origAuto = weather_setting('weather_radio_allow_autofire', '0');
$origCall = weather_setting('weather_tts_callsign', '');
function _set($k, $v) { global $prefix;
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES (?,?)
              ON DUPLICATE KEY UPDATE `value`=?", [$k, $v, $v]); }
_set('weather_radio_allow_autofire', '0');
_set('weather_tts_callsign', 'N0NKI');

// ── Seed a test DMR channel (unique TG so nothing real matches) ─────────────
$TG = 999912;
db_query("DELETE FROM `{$prefix}dmr_channels` WHERE talkgroup = ?", [$TG]);
db_query("INSERT INTO `{$prefix}dmr_channels`
            (label, talkgroup, bridge_host, bridge_port, bridge_token,
             usrp_listen_port, usrp_send_port, enabled, created_at)
          VALUES ('ZZTEST_WXR', ?, '127.0.0.1', 1, 'test-token', 1, 1, 1, NOW())", [$TG]);
// inbound_call_id is a 14-char deterministic hash: 'wx' + md5(nws_id:rule_id)[0..11]
$callIdFor = static function ($nwsId, $ruleId) { return 'wx' . substr(md5($nwsId . ':' . $ruleId), 0, 12); };
db_query("DELETE FROM `{$prefix}ai_pending_responses` WHERE inbound_call_id IN (?,?)",
    [$callIdFor('urn:test:wxradio:1', 991), $callIdFor('urn:test:wxradio:1', 992)]);

$ruleApprove = ['id' => 991, 'label' => 'TG test approve', 'target' => 'dmr',
                'target_ref' => (string) $TG, 'action_mode' => 'operator_approve'];
$ruleAuto    = ['id' => 992, 'label' => 'TG test auto', 'target' => 'dmr',
                'target_ref' => (string) $TG, 'action_mode' => 'auto_fire'];

// ── Safety: script carries the station ID ───────────────────────────────────
$script = weather_radio_script($alert);
t('read-out script pulls configured settings + ends with the §97.119 sign-off',
    strpos($script, 'Tornado Warning') !== false && strpos($script, 'N0NKI clear.') !== false);

// ── operator_approve → a pending_approval card in the Phase 85f queue ───────
$r1 = weather_radio_deliver($alert, $ruleApprove);
t('operator_approve enqueues (ledger=queued)', $r1['ok'] && $r1['ledger'] === 'queued');
$row = db_fetch_one(
    "SELECT channel_id, caller_callsign, draft_response, status FROM `{$prefix}ai_pending_responses`
      WHERE inbound_call_id = ? ORDER BY id DESC LIMIT 1",
    [$callIdFor('urn:test:wxradio:1', 991)]);
t('queue row: status pending_approval, draft = the read-out script, caller NWS',
    $row && $row['status'] === 'pending_approval' &&
    $row['caller_callsign'] === 'NWS' &&
    strpos((string) $row['draft_response'], 'N0NKI clear.') !== false);

// ── auto_fire WITHOUT the extra switch → degrades to operator approval ───────
$txCalled = false;
$fakeTx = static function ($url, $payload, $token) use (&$txCalled) {
    $txCalled = true;
    return ['code' => 200, 'body' => ['ok' => true]];
};
$r2 = weather_radio_deliver($alert, $ruleAuto, $fakeTx);
t('auto_fire with switch OFF degrades to the approval queue (never keys)',
    $r2['ledger'] === 'queued' && $txCalled === false);

// ── auto_fire WITH the switch → calls the bridge TX path (injected here) ─────
_set('weather_radio_allow_autofire', '1');
$r3 = weather_radio_deliver($alert, $ruleAuto, $fakeTx);
t('auto_fire with switch ON transmits via the bridge path (ledger=sent)',
    $r3['ok'] && $r3['ledger'] === 'sent' && $txCalled === true);
// Bridge failure is a visible 'failed', not silent.
$failTx = static function () { return ['code' => 502, 'body' => null]; };
$r4 = weather_radio_deliver($alert, $ruleAuto, $failTx);
t('bridge failure records ledger=failed with detail', !$r4['ok'] && $r4['ledger'] === 'failed');
_set('weather_radio_allow_autofire', '0');

// ── notify mode + zello + missing channel ────────────────────────────────────
$r5 = weather_radio_deliver($alert, ['id' => 993, 'label' => 'n', 'target' => 'dmr',
    'target_ref' => (string) $TG, 'action_mode' => 'notify']);
t('notify mode = tray only, never TX', $r5['ok'] && strpos($r5['detail'], 'no TX') !== false);
$r7 = weather_radio_deliver($alert, ['id' => 995, 'label' => 'x', 'target' => 'dmr',
    'target_ref' => '424242', 'action_mode' => 'operator_approve']);
t('unknown talkgroup = failed with a clear detail', !$r7['ok'] && strpos($r7['detail'], 'no enabled DMR channel') !== false);

// ── Zello read-out (Phase 6) — all dry: pure DB inserts, proxy not involved ──
// Ensure a zello_* setting exists so weather_zello_configured() passes; use a
// dedicated marker we can remove without touching real Zello config.
_set('zello_wx_test_marker', '1');
db_query("DELETE FROM `{$prefix}zello_outbox` WHERE source IN ('weather','weather-approve') AND channel = 'wxtest-chan'");
db_query("DELETE FROM `{$prefix}ai_pending_responses` WHERE inbound_call_id IN (?,?)",
    [$callIdFor('urn:test:wxradio:1', 996), $callIdFor('urn:test:wxradio:1', 997)]);

// Zello scripts carry NO §97.119 suffix (IP service; an RF gateway IDs itself).
$zscript = weather_radio_script($alert, false);
t('zello script has NO callsign sign-off',
    strpos($zscript, 'Tornado Warning') !== false && strpos($zscript, 'N0NKI') === false);

// operator_approve → approval card with target_kind=zello + target_ref.
$z1 = weather_radio_deliver($alert, ['id' => 996, 'label' => 'z approve', 'target' => 'zello',
    'target_ref' => 'wxtest-chan', 'action_mode' => 'operator_approve']);
t('zello operator_approve enqueues an approval card (ledger=queued)', $z1['ok'] && $z1['ledger'] === 'queued');
$zrow = db_fetch_one(
    "SELECT target_kind, target_ref, draft_response, status FROM `{$prefix}ai_pending_responses`
      WHERE inbound_call_id = ? ORDER BY id DESC LIMIT 1",
    [$callIdFor('urn:test:wxradio:1', 996)]);
t('zello card: target_kind=zello, target_ref=channel, draft has no callsign',
    $zrow && $zrow['target_kind'] === 'zello' && $zrow['target_ref'] === 'wxtest-chan' &&
    $zrow['status'] === 'pending_approval' && strpos((string) $zrow['draft_response'], 'N0NKI') === false);

// auto_fire WITHOUT the switch → degrades to approval (Zello gateways onto RF).
$z2 = weather_radio_deliver($alert, ['id' => 997, 'label' => 'z auto', 'target' => 'zello',
    'target_ref' => 'wxtest-chan', 'action_mode' => 'auto_fire']);
$obCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}zello_outbox` WHERE channel = 'wxtest-chan' AND kind = 'tts'");
t('zello auto_fire with switch OFF degrades to the approval queue (no outbox row)',
    $z2['ledger'] === 'queued' && $obCount === 0);

// auto_fire WITH the switch → zello_outbox kind='tts' (the proxy keys it).
_set('weather_radio_allow_autofire', '1');
db_query("DELETE FROM `{$prefix}ai_pending_responses` WHERE inbound_call_id = ?",
    [$callIdFor('urn:test:wxradio:1', 997)]);
$z3 = weather_radio_deliver($alert, ['id' => 997, 'label' => 'z auto', 'target' => 'zello',
    'target_ref' => 'wxtest-chan', 'action_mode' => 'auto_fire']);
$obRow = db_fetch_one(
    "SELECT kind, source, status, body FROM `{$prefix}zello_outbox`
      WHERE channel = 'wxtest-chan' ORDER BY id DESC LIMIT 1");
t('zello auto_fire with switch ON queues zello_outbox tts (ledger=queued)',
    $z3['ok'] && $z3['ledger'] === 'queued' && $obRow &&
    $obRow['kind'] === 'tts' && $obRow['source'] === 'weather' && $obRow['status'] === 'queued' &&
    strpos((string) $obRow['body'], 'Tornado Warning') !== false);
_set('weather_radio_allow_autofire', '0');

// Approve-path wiring guards (the decide API queues the outbox row on approve).
$dec = rd($base . '/api/radio-ai-decide.php');
t('decide API routes zello approvals to zello_outbox (kind=tts, weather-approve)',
    $dec !== false && strpos($dec, "'zello'") !== false &&
    strpos($dec, 'weather-approve') !== false && strpos($dec, 'zello_outbox') !== false);
$pen = rd($base . '/api/radio-ai-pending.php');
t('pending API surfaces target_kind/target_ref (with pre-migration fallback)',
    $pen !== false && strpos($pen, 'target_kind') !== false && strpos($pen, 'target_ref') !== false);
$wjs = rd($base . '/assets/js/radio-ai-approval.js');
t('widget card labels Zello destinations', $wjs !== false && strpos($wjs, "target_kind === 'zello'") !== false);
t('migration for target columns exists', is_file($base . '/sql/run_weather_zello_readout.php'));
t('deliver layer checks Zello is configured (skipped, not failed)',
    strpos((string) rd($base . '/inc/weather_radio.php'), "'ledger' => 'skipped'") !== false);

// ── Poll wiring ──────────────────────────────────────────────────────────────
$eng = rd($base . '/inc/weather_alerts.php');
t('poll routes dmr/zello rules through weather_radio_deliver with ledger marks',
    $eng !== false &&
    strpos($eng, "'tray','chat','sms','email','dmr','zello'") !== false &&
    strpos($eng, 'weather_radio_deliver($alert, $rule)') !== false);
$api = rd($base . '/api/weather-alerts.php');
t('autofire switch is in the admin API (default-off save semantics)',
    $api !== false && strpos($api, "'weather_radio_allow_autofire'") !== false &&
    strpos($api, "!empty(\$input['weather_radio_allow_autofire']) ? '1' : '0'") !== false);
$adm = rd($base . '/weather-alerts.php');
t('admin page exposes the unattended-keying switch (wxRadioAutofire)',
    $adm !== false && strpos($adm, 'id="wxRadioAutofire"') !== false);

// ── Cleanup + restore ────────────────────────────────────────────────────────
db_query("DELETE FROM `{$prefix}ai_pending_responses` WHERE inbound_call_id IN (?,?,?,?)",
    [$callIdFor('urn:test:wxradio:1', 991), $callIdFor('urn:test:wxradio:1', 992),
     $callIdFor('urn:test:wxradio:1', 996), $callIdFor('urn:test:wxradio:1', 997)]);
db_query("DELETE FROM `{$prefix}dmr_channels` WHERE talkgroup = ?", [$TG]);
db_query("DELETE FROM `{$prefix}zello_outbox` WHERE channel = 'wxtest-chan'");
db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'zello_wx_test_marker'");
_set('weather_radio_allow_autofire', $origAuto ?: '0');
_set('weather_tts_callsign', $origCall ?: '');

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
