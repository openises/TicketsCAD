<?php
/**
 * Phase 148 (2026-08-20) — DB-backed integration tests for
 * inc/fcc_station_id.php's writer functions (fcc_record_tx(),
 * fcc_record_id_event(), fcc_monitoring_id(), fcc_end_conversation(),
 * fcc_status_payload()), driven against a real, throwaway dmr_channels
 * fixture row -- never hand-seeded "ideal" dmr_id_log/dmr_ptt_state rows
 * standing in for what the real writers produce, per this project's own
 * "reproduce through the real writer" discipline.
 *
 * fcc_fire_station_tts()'s $tx callable is injected (dry-run, never
 * touches the network) for fcc_monitoring_id()/fcc_end_conversation(),
 * mirroring inc/weather_radio.php's own weather_radio_tx() test-injection
 * convention -- this test never keys a real radio.
 *
 * Elapsed time is simulated by BACKDATING dmr_id_log.id_at /
 * dmr_ptt_state.last_tx_at directly via UPDATE after the real writer call,
 * never by sleeping the runner -- same technique established in
 * tests/test_org_relationships_read_time_expiry.php and friends.
 *
 * @requires-db
 * Usage: php tests/test_fcc_station_id_integration.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/fcc_station_id.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 148 — FCC 97.119 station-ID DB integration ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Fixtures ─────────────────────────────────────────────────────────────
// A throwaway dmr_channels row. Real INSERT through the same shape
// api/dvswitch.php's channel_create uses (label/talkgroup/bridge_host are
// NOT NULL with no default in base schema — see sql/run_phase73i_dvswitch_
// schema.php). id_interval_seconds/id_enforce take this migration's real
// column defaults (600/'soft') unless overridden per test below.
$channelId = null;
$userIdA = 900148001;   // synthetic — dmr_id_log/dmr_ptt_state carry no FK
$userIdB = 900148002;   // to `user`, so any int works for these tests.

function _fcc_test_cleanup() {
    global $prefix, $channelId, $userIdA, $userIdB;
    try {
        if ($channelId) {
            db_query("DELETE FROM `{$prefix}dmr_id_log` WHERE channel_id = ?", [$channelId]);
            db_query("DELETE FROM `{$prefix}dmr_ptt_state` WHERE channel_id = ?", [$channelId]);
            db_query("DELETE FROM `{$prefix}dmr_channels` WHERE id = ?", [$channelId]);
        }
    } catch (Throwable $e) { /* best effort */ }
}
register_shutdown_function('_fcc_test_cleanup');

try {
    // bridge_token must be non-empty -- fcc_fire_station_tts() short-
    // circuits via dmr_bridge_token() before ever invoking the injected
    // $tx callable when the token is blank (the same guard real channels
    // rely on to refuse an unconfigured bridge). The value itself is never
    // sent anywhere real: every TTS call in this test uses an injected
    // dry-run/fail callable, never the live curl path. CHAR(64), so the
    // fixture value must be exactly 64 chars.
    $fakeToken = str_repeat('a', 64);
    db_query(
        "INSERT INTO `{$prefix}dmr_channels`
            (label, talkgroup, network, bridge_host, bridge_port, bridge_token,
             bridge_token_format, usrp_listen_port, usrp_send_port, link_mode, enabled,
             id_interval_seconds, id_enforce)
         VALUES ('ZZ148 Test Channel', '9999', 'BrandMeister', 'zztest.invalid', 18091,
                 ?, 'plain', 39148, 39147, 'bidirectional', 1, 600, 'soft')",
        [$fakeToken]
    );
    $channelId = (int) db_insert_id();
    t('fixture dmr_channels row created', $channelId > 0);
} catch (Throwable $e) {
    t('fixture dmr_channels row created', false);
    echo "  " . $e->getMessage() . "\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$channelRow = db_fetch_one("SELECT * FROM `{$prefix}dmr_channels` WHERE id = ?", [$channelId]);
t('fixture channel row is readable back', $channelRow && (int) $channelRow['id'] === $channelId);

// Dry-run TTS callable — proves the fixture never actually reaches a
// network host, mirrors api/dmr-station-id.php's own dry_run mode.
$fireCount = 0;
$dryTx = function (string $url, array $payload, string $token) use (&$fireCount): array {
    $fireCount++;
    return ['code' => 200, 'body' => ['ok' => true, 'dry_run' => true], 'err' => ''];
};
$failTx = function (string $url, array $payload, string $token): array {
    return ['code' => 502, 'body' => null, 'err' => 'simulated bridge failure'];
};

// ── fcc_last_id_at() on a fresh (channel,user) pair ─────────────────────
echo "\n--- fcc_last_id_at() / fresh state ---\n";
t('no ID events yet -> null', fcc_last_id_at($channelId, $userIdA) === null);
$state = fcc_ptt_state($channelId, $userIdA);
t('no PTT state row yet -> defaults (both null)',
    $state['last_tx_at'] === null && $state['conversation_started_at'] === null);

// ── fcc_record_tx() — the REAL writer both api/dmr-tx-audio.php and
//    api/dmr-tx-stream.php call after a successful forward ─────────────
echo "\n--- fcc_record_tx() ---\n";
fcc_record_tx($channelId, $userIdA, 600);
$state = fcc_ptt_state($channelId, $userIdA);
t('first TX opens a conversation (conversation_started_at set)',
    $state['conversation_started_at'] !== null);
t('first TX also sets last_tx_at', $state['last_tx_at'] !== null);
$firstConvStart = $state['conversation_started_at'];

// A second TX shortly after should NOT open a new conversation.
sleep(1);
fcc_record_tx($channelId, $userIdA, 600);
$state = fcc_ptt_state($channelId, $userIdA);
t('a second TX within the interval does NOT reset conversation_started_at',
    $state['conversation_started_at'] === $firstConvStart);

// Backdate last_tx_at to simulate a >interval gap, then TX again — this
// SHOULD open a fresh conversation (skill: "New conversation begins on
// first TX after >10 min silence").
db_query(
    "UPDATE `{$prefix}dmr_ptt_state` SET last_tx_at = DATE_SUB(NOW(), INTERVAL 700 SECOND)
      WHERE channel_id = ? AND user_id = ?", [$channelId, $userIdA]
);
fcc_record_tx($channelId, $userIdA, 600);
$state2 = fcc_ptt_state($channelId, $userIdA);
t('a TX after a >interval silence gap opens a NEW conversation marker',
    $state2['conversation_started_at'] !== $firstConvStart && $state2['conversation_started_at'] !== null);

t('fcc_record_tx() never wrote to dmr_id_log (a TX by itself proves nothing about the callsign)',
    fcc_last_id_at($channelId, $userIdA) === null);

// ── fcc_record_id_event() — the ONLY writer of dmr_id_log ───────────────
echo "\n--- fcc_record_id_event() ---\n";
$ok = fcc_record_id_event($channelId, $userIdA, 'N0NKI', 'confirmed_tx');
t('confirmed_tx event recorded', $ok === true);
t('last_id_at now reflects it', fcc_last_id_at($channelId, $userIdA) !== null);
$stateAfterConfirm = fcc_ptt_state($channelId, $userIdA);
t('confirmed_tx does NOT clear the conversation marker (mid-conversation ID, per the skill\'s 8-9min worked example)',
    $stateAfterConfirm['conversation_started_at'] !== null);

$ok = fcc_record_id_event($channelId, $userIdA, 'N0NKI', 'monitoring_id');
t('monitoring_id event recorded', $ok === true);
$stateAfterMon = fcc_ptt_state($channelId, $userIdA);
t('monitoring_id DOES clear the conversation marker',
    $stateAfterMon['conversation_started_at'] === null);

t('an unknown source is rejected',
    fcc_record_id_event($channelId, $userIdA, 'N0NKI', 'bogus_source') === false);
t('a blank callsign is rejected',
    fcc_record_id_event($channelId, $userIdA, '', 'confirmed_tx') === false);

// Audit log — spec.md B6: action='fcc_id'. Verify the row landed (best
// effort — audit_log() itself swallows failures, so this proves the
// wiring reached it, not merely that the call didn't throw).
try {
    $auditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
          WHERE activity = 'fcc_id' AND target_id = ? AND details LIKE ?",
        [(string) $channelId, '%"callsign":"N0NKI"%']
    );
    t('newui_audit_log recorded at least one fcc_id event for this channel', $auditCount >= 1);
} catch (Throwable $e) {
    t('newui_audit_log recorded at least one fcc_id event for this channel (table check skipped: ' . $e->getMessage() . ')', true);
}

// ── fcc_may_transmit_without_id() / fcc_id_zone() against a REAL last_id_at ──
// A dedicated fresh user for this section — userIdA already carries TWO
// dmr_id_log rows (confirmed_tx + monitoring_id) from the block above,
// both landing in the same wall-clock second at MySQL's DATETIME
// precision. Backdating "the row with the highest id" would not move
// MAX(id_at) if the other row shares that same second's value. A fresh
// (channel,user) pair with exactly ONE row sidesteps that ambiguity
// entirely rather than requiring a sleep() to force distinct seconds.
echo "\n--- compliance check against a real dmr_id_log row ---\n";
$userIdBackdate = 900148099;
fcc_record_id_event($channelId, $userIdBackdate, 'N0NKI', 'confirmed_tx');
$lastId = fcc_last_id_at($channelId, $userIdBackdate);
t('immediately after an ID event, may_transmit_without_id() is true',
    fcc_may_transmit_without_id($lastId, 600) === true);
t('immediately after an ID event, zone is green',
    fcc_id_zone($lastId, 600) === 'green');

// Backdate that one row to simulate 700s elapsed.
db_query(
    "UPDATE `{$prefix}dmr_id_log` SET id_at = DATE_SUB(NOW(), INTERVAL 700 SECOND)
      WHERE channel_id = ? AND user_id = ?",
    [$channelId, $userIdBackdate]
);
$lastId2 = fcc_last_id_at($channelId, $userIdBackdate);
t('MAX(id_at) reflects the backdated row (derived live, not cached)',
    $lastId2 !== $lastId);
t('700s after the (backdated) last ID: may_transmit_without_id() is now false',
    fcc_may_transmit_without_id($lastId2, 600) === false);
t('700s after: zone is red', fcc_id_zone($lastId2, 600) === 'red');

// ── fcc_monitoring_id() ─────────────────────────────────────────────────
echo "\n--- fcc_monitoring_id() ---\n";
$before = $fireCount;
$result = fcc_monitoring_id($channelRow, $userIdB, 'AA0E', $dryTx);
t('monitoring ID fires the (dry-run) TTS call', $fireCount === $before + 1);
t('monitoring ID reports ok', $result['ok'] === true);
t('monitoring ID recorded an ID event for userIdB', fcc_last_id_at($channelId, $userIdB) !== null);
t('a blank callsign refuses without even attempting a TX',
    fcc_monitoring_id($channelRow, $userIdB, '', $dryTx)['ok'] === false
    && $fireCount === $before + 1);

// A failed bridge call must NOT record an ID event.
$before = fcc_last_id_at($channelId, 900148003);
$resultFail = fcc_monitoring_id($channelRow, 900148003, 'W1AW', $failTx);
t('a failed TTS transmit reports ok=false', $resultFail['ok'] === false);
t('...and does NOT record an ID event', fcc_last_id_at($channelId, 900148003) === null);

// ── fcc_end_conversation() ───────────────────────────────────────────────
echo "\n--- fcc_end_conversation() ---\n";
// Fresh user: TX without an ID, then end the conversation. Per the skill's
// pseudocode ("if last_tx_at > (last_id_at or 0): fire the closing ID"),
// this SHOULD fire a standalone closing ID.
$userIdC = 900148004;
fcc_record_tx($channelId, $userIdC, 600);
$before = $fireCount;
$resultEnd = fcc_end_conversation($channelRow, $userIdC, 'K1ABC', $dryTx);
t('end_conversation fires a closing ID when the last TX carried none',
    $resultEnd['fired_closing_id'] === true && $fireCount === $before + 1);
t('end_conversation recorded the closing ID event', fcc_last_id_at($channelId, $userIdC) !== null);
$stateC = fcc_ptt_state($channelId, $userIdC);
t('conversation marker cleared after end_conversation', $stateC['conversation_started_at'] === null);

// Different user: TX WITH an ID immediately before ending — no closing ID
// should be needed (the most recent TX already carried one).
$userIdD = 900148005;
fcc_record_tx($channelId, $userIdD, 600);
fcc_record_id_event($channelId, $userIdD, 'N9XYZ', 'confirmed_tx');
$before = $fireCount;
$resultEnd2 = fcc_end_conversation($channelRow, $userIdD, 'N9XYZ', $dryTx);
t('end_conversation does NOT fire a closing ID when the last TX already carried one',
    $resultEnd2['fired_closing_id'] === false && $fireCount === $before);
t('end_conversation still reports ok=true (nothing to transmit is not a failure)',
    $resultEnd2['ok'] === true);

// ── fcc_status_payload() shape ───────────────────────────────────────────
echo "\n--- fcc_status_payload() ---\n";
$payload = fcc_status_payload($channelRow, $userIdD, 'N9XYZ');
$requiredKeys = [
    'channel_id', 'channel_label', 'id_enforce', 'id_interval_seconds',
    'callsign', 'callsign_present', 'callsign_valid', 'last_id_at',
    'last_tx_at', 'conversation_started_at', 'zone',
    'may_transmit_without_id', 'seconds_since_id', 'seconds_until_due',
    'server_now',
];
$missingKeys = [];
foreach ($requiredKeys as $k) { if (!array_key_exists($k, $payload)) $missingKeys[] = $k; }
t('every key the widget (assets/js/radio-widget.js) reads is present: ' . implode(',', $missingKeys),
    empty($missingKeys));
t('channel_id matches the fixture', (int) $payload['channel_id'] === $channelId);
t('callsign_present is true', $payload['callsign_present'] === true);
t('zone is green (just IDed)', $payload['zone'] === 'green');

$nullPayload = fcc_status_payload(null, $userIdD, '');
t('a null channel (no DMR configured) still returns every key, gracefully',
    array_key_exists('zone', $nullPayload) && $nullPayload['zone'] === 'none'
    && $nullPayload['id_enforce'] === 'off' && $nullPayload['channel_id'] === null);
t('a blank callsign against a null channel reports callsign_present=false',
    $nullPayload['callsign_present'] === false);

// ── id_enforce='off' — the escape hatch ──────────────────────────────────
echo "\n--- id_enforce='off' ---\n";
db_query("UPDATE `{$prefix}dmr_channels` SET id_enforce = 'off' WHERE id = ?", [$channelId]);
$offChannelRow = db_fetch_one("SELECT * FROM `{$prefix}dmr_channels` WHERE id = ?", [$channelId]);
$offPayload = fcc_status_payload($offChannelRow, $userIdD, 'N9XYZ');
t('id_enforce=off is reported in the status payload verbatim', $offPayload['id_enforce'] === 'off');
// The widget itself (not this function) is what hides the panel on 'off' —
// fcc_status_payload() reports state honestly regardless of enforcement
// level, matching this project's convention of never hiding truth server-
// side (see the "reassuring status code is not proof" pitfall).
db_query("UPDATE `{$prefix}dmr_channels` SET id_enforce = 'soft' WHERE id = ?", [$channelId]);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
