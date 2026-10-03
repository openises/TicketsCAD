<?php
/**
 * Phase 155 -- inbound-call bridge heartbeat, connection status and the
 * admin "Send test call" action.
 *
 * Why this exists: a beta tester minted a trunk token and could not tell
 * whether their bridge was running, mis-configured, or simply idle -- a quiet
 * phone line and a dead bridge looked identical. These tests drive the REAL
 * endpoints (api/sip-ingest.php for the heartbeat, api/sip-trunks.php for the
 * status list and the test call) through CLI probes, never hand-seeded rows
 * standing in for what the writers produce.
 *
 * @requires-db
 * Usage: php tests/test_phase155_sip_bridge_status.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 155 -- bridge heartbeat, connection status, test call ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

/** Run a probe script with an ARGV list (bypasses cmd.exe quoting on Windows -- see
 *  tests/test_inbound_calls_sip_trunks_admin.php's own note). Returns decoded JSON or null. */
function p155_run(array $argv): ?array {
    $cmd = array_merge([PHP_BINARY ?: 'php'], $argv);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}
function p155_trunks(string $method, string $action, int $userId, string $payload = ''): ?array {
    return p155_run([__DIR__ . '/_p149_sip_trunks_probe.php', $method, $action, (string) $userId, $payload]);
}
function p155_ingest(string $token, array $body): ?array {
    return p155_run([__DIR__ . '/_p155_ingest_probe.php', $token, json_encode($body)]);
}
function p155_find(?array $list, int $id): ?array {
    foreach (($list['trunks'] ?? []) as $r) { if ((int) $r['id'] === $id) return $r; }
    return null;
}

$adminId      = test_admin_user_id();
$dispatcherId = 900015155;
$trunkIds     = [];

$cleanup = function () use ($prefix, $dispatcherId, &$trunkIds) {
    foreach ($trunkIds as $id) {
        try {
            db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE `call_id` IN (SELECT `id` FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?)", [$id]);
            db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$id]);
            db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$id]);
            db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'pbx_trunk' AND `target_id` = ?", [$id]);
        } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$dispatcherId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$dispatcherId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {

    // ══════════════════════════════════════════════════════════════════
    // Migration: idempotent and self-verifying
    // ══════════════════════════════════════════════════════════════════
    echo "--- Migration ---\n\n";

    $mig = __DIR__ . '/../sql/run_phase155_pbx_trunk_heartbeat.php';
    $run = function () use ($mig): array {
        $proc = proc_open([PHP_BINARY ?: 'php', $mig], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($proc), $out];
    };
    [$code1, $out1] = $run();
    t('migration exits 0', $code1 === 0);
    t('migration reports both columns verified', strpos($out1, 'both heartbeat columns verified present') !== false);
    [$code2, $out2] = $run();
    t('a second run is a clean no-op (idempotent)', $code2 === 0 && substr_count($out2, '[SKIP]') === 2);
    foreach (['last_heartbeat_at', 'bridge_info'] as $col) {
        t("pbx_trunks.$col exists", (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'pbx_trunks', $col]));
    }

    // ══════════════════════════════════════════════════════════════════
    // Pure classification
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- inbound_trunk_connection_state() ---\n\n";
    require_once __DIR__ . '/../inc/inbound-calls.php';
    t('null age -> never', inbound_trunk_connection_state(null) === 'never');
    t('0s -> connected', inbound_trunk_connection_state(0) === 'connected');
    t('120s (the limit) -> connected', inbound_trunk_connection_state(120) === 'connected');
    t('121s -> silent', inbound_trunk_connection_state(121) === 'silent');
    t('a custom freshness window is honoured', inbound_trunk_connection_state(50, 30) === 'silent');

    // ══════════════════════════════════════════════════════════════════
    // Fixture: Dispatcher (no action.manage_calls) + a trunk made by the REAL endpoint
    // ══════════════════════════════════════════════════════════════════
    db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
        [$dispatcherId, 'p155fixturedisp', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 3)", [$dispatcherId]);

    $r = p155_trunks('POST', 'trunk_create', $adminId, json_encode(['label' => 'P155 Status Trunk']));
    $trunkId = (int) ($r['trunk_id'] ?? 0);
    $token   = (string) ($r['bearer_token'] ?? '');
    if ($trunkId > 0) $trunkIds[] = $trunkId;
    t('fixture trunk created through the real endpoint', $trunkId > 0 && $token !== '');

    // ══════════════════════════════════════════════════════════════════
    // Before any bridge: "never"
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Before the bridge connects ---\n\n";
    $row = p155_find(p155_trunks('GET', 'trunks', $adminId), $trunkId);
    t('the trunk list reports heartbeat support', $row !== null && $row['heartbeat_supported'] === true);
    t('a brand-new trunk is "never" connected', $row !== null && $row['connection_state'] === 'never');
    t('heartbeat_age_seconds is null before any heartbeat', $row !== null && $row['heartbeat_age_seconds'] === null);

    // ══════════════════════════════════════════════════════════════════
    // Real heartbeat through the real ingest endpoint
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Heartbeat through api/sip-ingest.php ---\n\n";

    // Pin updated_at far in the past so we can prove a heartbeat does not touch it.
    db_query("UPDATE `{$prefix}pbx_trunks` SET `updated_at` = '2020-01-01 00:00:00' WHERE `id` = ?", [$trunkId]);
    $callsBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);

    $hb = p155_ingest($token, ['event' => 'heartbeat', 'bridge' => 'sip-bridge 1.1.0 (threecx)']);
    t('heartbeat is accepted', is_array($hb) && !empty($hb['ok']) && !empty($hb['heartbeat']));
    t('heartbeat reply says it was recorded', is_array($hb) && $hb['recorded'] === true);
    t('heartbeat reply names the trunk the TOKEN resolved to', is_array($hb) && ($hb['trunk_label'] ?? '') === 'P155 Status Trunk');
    t('heartbeat reply reports the trunk enabled', is_array($hb) && $hb['trunk_enabled'] === true);

    $callsAfter = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
    t('a heartbeat never creates a call row', $callsBefore === $callsAfter);
    $stored = db_fetch_one("SELECT `last_heartbeat_at`, `bridge_info`, `updated_at` FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('last_heartbeat_at was stamped', !empty($stored['last_heartbeat_at']));
    t('bridge_info stores what the bridge said about itself', $stored['bridge_info'] === 'sip-bridge 1.1.0 (threecx)');
    t('a heartbeat does NOT bump updated_at (it is not a config change)', substr((string) $stored['updated_at'], 0, 4) === '2020');

    $row = p155_find(p155_trunks('GET', 'trunks', $adminId), $trunkId);
    t('the list now reports the trunk connected', $row !== null && $row['connection_state'] === 'connected');
    t('the list carries the bridge description', $row !== null && $row['bridge_info'] === 'sip-bridge 1.1.0 (threecx)');
    t('the heartbeat age is small and non-negative', $row !== null && $row['heartbeat_age_seconds'] !== null
        && $row['heartbeat_age_seconds'] >= 0 && $row['heartbeat_age_seconds'] < 30);
    t('the list still never exposes the raw bearer_token', $row !== null && !array_key_exists('bearer_token', $row));

    // Control characters / oversize junk in the info string is sanitised and capped.
    p155_ingest($token, ['event' => 'heartbeat', 'bridge' => "evil\x07\x1b[31m" . str_repeat('x', 400)]);
    $info = (string) db_fetch_value("SELECT `bridge_info` FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('control characters are stripped from bridge_info', !preg_match('/[\x00-\x1f]/', $info));
    t('bridge_info is capped at 120 characters', strlen($info) <= 120);

    // A heartbeat that is too old reads as "silent"
    db_query("UPDATE `{$prefix}pbx_trunks` SET `last_heartbeat_at` = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE `id` = ?", [$trunkId]);
    $row = p155_find(p155_trunks('GET', 'trunks', $adminId), $trunkId);
    t('a 10-minute-old heartbeat reads as silent', $row !== null && $row['connection_state'] === 'silent');

    // ══════════════════════════════════════════════════════════════════
    // Auth: a wrong token records nothing
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Heartbeat authentication ---\n\n";
    db_query("UPDATE `{$prefix}pbx_trunks` SET `last_heartbeat_at` = NULL, `bridge_info` = NULL WHERE `id` = ?", [$trunkId]);
    $bad = p155_ingest('not-the-token', ['event' => 'heartbeat', 'bridge' => 'impostor']);
    t('a heartbeat with a wrong token is refused', is_array($bad) && isset($bad['error']));
    $stored = db_fetch_one("SELECT `last_heartbeat_at`, `bridge_info` FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('...and nothing was recorded', $stored['last_heartbeat_at'] === null && $stored['bridge_info'] === null);
    $none = p155_ingest('', ['event' => 'heartbeat']);
    t('a heartbeat with no token is refused', is_array($none) && isset($none['error']));

    // ══════════════════════════════════════════════════════════════════
    // Disabled trunk: heartbeat still recorded (admin can see "connected but off"), calls dropped
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Disabled trunk ---\n\n";
    db_query("UPDATE `{$prefix}pbx_trunks` SET `enabled` = 0 WHERE `id` = ?", [$trunkId]);
    $hb = p155_ingest($token, ['event' => 'heartbeat', 'bridge' => 'sip-bridge 1.1.0 (ami)']);
    t('a disabled trunk still accepts and records a heartbeat', is_array($hb) && $hb['recorded'] === true);
    t('...and tells the bridge the trunk is disabled', is_array($hb) && $hb['trunk_enabled'] === false);
    $drop = p155_ingest($token, ['event' => 'ringing', 'call_id' => 'p155-disabled-1', 'caller_number' => '+16125550101']);
    t('a real call event on a disabled trunk is still dropped', is_array($drop) && ($drop['dropped'] ?? '') === 'trunk disabled');
    t('...no call row was created for it', (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ? AND `provider_call_id` = 'p155-disabled-1'", [$trunkId]) === 0);

    $r = p155_trunks('POST', 'trunk_test_call', $adminId, json_encode(['id' => $trunkId]));
    t('a test call on a disabled trunk is refused with an explanation', is_array($r) && isset($r['error']) && stripos($r['error'], 'enable') !== false);
    db_query("UPDATE `{$prefix}pbx_trunks` SET `enabled` = 1 WHERE `id` = ?", [$trunkId]);

    // ══════════════════════════════════════════════════════════════════
    // Test call
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Send test call ---\n\n";

    $r = p155_trunks('POST', 'trunk_test_call', $dispatcherId, json_encode(['id' => $trunkId]));
    t('a Dispatcher (no action.manage_calls) cannot send a test call', is_array($r) && isset($r['error']));

    $constituentsBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents`");
    $r = p155_trunks('POST', 'trunk_test_call', $adminId, json_encode(['id' => $trunkId]));
    t('the test call is accepted', is_array($r) && !empty($r['success']) && !empty($r['provider_call_id']));
    $pid    = (string) ($r['provider_call_id'] ?? '');
    $callId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ? AND `provider_call_id` = ?", [$trunkId, $pid]);
    t('its provider id is recognisably a test', strpos($pid, 'test-') === 0);

    $call = db_fetch_one("SELECT * FROM `{$prefix}inbound_calls` WHERE `id` = ?", [$callId]);
    t('a real ringing call row exists (the same path a PBX event takes)', $call && $call['state'] === 'ringing');
    t('the banner number slot reads TEST CALL, not a believable number', $call && $call['caller_number'] === 'TEST CALL');
    t('...with no digits in it at all', $call && !preg_match('/\d/', (string) $call['caller_number']));
    t('no Constituents record was fabricated for it',
        (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents`") === $constituentsBefore
        && $call && $call['constituent_id'] === null);
    t('the action was audit-logged', (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'pbx_trunk' AND `target_id` = ? AND `activity` = 'test_call'", [$trunkId]) >= 1);

    $r = p155_trunks('POST', 'trunk_test_call_end', $adminId, json_encode(['id' => $trunkId, 'provider_call_id' => 'not-a-test-id']));
    t('trunk_test_call_end refuses an id this endpoint did not mint (never touches a real call)', is_array($r) && isset($r['error']));
    t('...and the test call is still ringing after that refusal',
        db_fetch_value("SELECT `state` FROM `{$prefix}inbound_calls` WHERE `id` = ?", [$callId]) === 'ringing');

    $r = p155_trunks('POST', 'trunk_test_call_end', $adminId, json_encode(['id' => $trunkId, 'provider_call_id' => $pid]));
    t('ending the test call succeeds', is_array($r) && !empty($r['success']));
    $call = db_fetch_one("SELECT * FROM `{$prefix}inbound_calls` WHERE `id` = ?", [$callId]);
    t('the test call is closed as ended, not abandoned (abandoned would file it under Missed Calls)', $call && $call['state'] === 'ended');
    t('...and marked reviewed, so the server never offers it as a missed call either', $call && $call['reviewed_at'] !== null);
    $sse = db_fetch_all("SELECT `event_type` FROM `{$prefix}sse_events` WHERE `payload` LIKE ? ORDER BY `id`", ['%"call_id":' . $callId . ',%']);
    $types = array_column($sse, 'event_type');
    t('open browsers are told it ended (call:ended)', in_array('call:ended', $types, true));
    t('...and never told it was abandoned (that would add it to their Missed Calls list)', !in_array('call:abandoned', $types, true));
    t('the end was audit-logged on the call', (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'ended'", [$callId]) === 1);

    // ══════════════════════════════════════════════════════════════════
    // Pre-migration install: the page must still load
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Install that has not run the migration yet ---\n\n";
    try {
        db_query("ALTER TABLE `{$prefix}pbx_trunks` DROP COLUMN `last_heartbeat_at`, DROP COLUMN `bridge_info`");
        $row = p155_find(p155_trunks('GET', 'trunks', $adminId), $trunkId);
        t('the trunk list still loads without the heartbeat columns', $row !== null);
        t('...and honestly reports heartbeat support as unavailable', $row !== null && $row['heartbeat_supported'] === false);
        $hb = p155_ingest($token, ['event' => 'heartbeat', 'bridge' => 'x']);
        t('a heartbeat on that schema does not fail the request', is_array($hb) && !empty($hb['ok']));
        t('...but says it could not record it', is_array($hb) && $hb['recorded'] === false);
    } finally {
        $run();   // put the columns back no matter what happened above
    }
    t('columns restored by the migration', (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'last_heartbeat_at'",
        [$prefix . 'pbx_trunks']));

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
