<?php
/**
 * Phase 155 (GH#151 + GH#129) — end to end, PHP -> real Python control plane
 * -> real UDP leg -> rx_state notification -> real PHP endpoint over HTTP.
 *
 * NO bridge program, NO radio, NO DVMProject: the "bridge" is this test
 * sending USRP-format UDP datagrams from PHP. What this proves is OUR wiring:
 *   * the admin API really attaches / re-attaches / detaches a leg in the
 *     running audio-matrix service (a real MatrixCore + control_http server +
 *     the real hot-attach factory, started by services/audio-matrix/tests/
 *     run_control_plane.py), and frees the UDP port when it does
 *   * audio sent to a channel's listen port is received by that leg
 *   * the leg's rx_state notifications reach api/matrix-channel-state.php
 *     over real HTTP (a real `php -S`), which stores last_rx_at and publishes
 *     a comm:rx_state SSE event keyed on the numeric channel id
 *   * disable / re-enable restores the channel's standing patches
 *   * withdrawing the DVMProject acknowledgment detaches its leg
 *
 * Sections skip (loudly) if no Python 3 or no `php -S` is available.
 *
 * @requires-db
 * Usage: php tests/test_voice_bridge_e2e.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/channel_registry.php';
require_once 'inc/voice-bridge-channels.php';
require_once 'inc/matrix-routes.php';
require_once 'tests/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
function finish() {
    global $pass, $fail;
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 -- digital voice bridge, end to end ===\n\n";

// ── helpers ──────────────────────────────────────────────────────────────
function find_python() {
    foreach (['python3', 'python'] as $bin) {
        $p = @proc_open([$bin, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) { continue; }
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($p) === 0 && preg_match('/Python 3\./', $o)) { return $bin; }
    }
    return null;
}
function free_tcp_port() {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    if (!is_resource($s)) { return null; }
    $n = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($n, strrpos($n, ':') + 1);
}
function free_udp_port() {
    $s = @stream_socket_server('udp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND);
    if (!is_resource($s)) { return null; }
    $n = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($n, strrpos($n, ':') + 1);
}
function udp_port_in_use($port) {
    $s = @stream_socket_server('udp://127.0.0.1:' . $port, $en, $es, STREAM_SERVER_BIND);
    if (is_resource($s)) { fclose($s); return false; }
    return true;
}
function wait_for($fn, $secs = 12.0) {
    $end = microtime(true) + $secs;
    while (microtime(true) < $end) { if ($fn()) { return true; } usleep(60000); }
    return (bool) $fn();
}
function ctl($method, $path, $body = null) {
    global $cpPort, $TOKEN;
    $ch = curl_init('http://127.0.0.1:' . $cpPort . $path);
    $h = ['Authorization: Bearer ' . $TOKEN];
    $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'body' => json_decode((string) $raw, true)];
}
function legs() {
    $r = ctl('GET', '/legs');
    $by = [];
    foreach (($r['body']['legs'] ?? []) as $l) { $by[$l['channel_id']] = $l; }
    return $by;
}
function usrp_datagram($seq, $payload = '') {
    return 'USRP' . pack('N', $seq) . str_repeat("\0", 24) . $payload;
}
function pcm_frame($v = 1000) { return str_repeat(pack('v', $v & 0xFFFF), 160); }
function probe($endpoint, $method, $query, $userId, $payload = '') {
    $cmd = [PHP_BINARY, __DIR__ . '/_p155_voice_bridge_probe.php', $endpoint, $method, $query, (string) $userId, $payload];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) { return ['status' => 0, 'body' => null, 'raw' => '']; }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/\nHTTP (\d+)\s*$/', $out, $m)) { $status = (int) $m[1]; $out = substr($out, 0, -strlen($m[0])); }
    $b = json_decode(trim($out), true);
    return ['status' => $status, 'body' => is_array($b) ? $b : null, 'raw' => trim($out)];
}

$python = find_python();
if ($python === null) {
    echo "SKIP: no Python 3 interpreter on PATH -- the live-service sections cannot run here.\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ── state to restore ─────────────────────────────────────────────────────
$origSettings = [];
foreach (['matrix_control_url', 'matrix_control_token', 'dvm_policy_ack'] as $k) {
    $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
    $origSettings[$k] = ($v === false) ? null : $v;
}
$tmp = sys_get_temp_dir() . '/p155e2e-' . getmypid() . '-' . mt_rand();
@mkdir($tmp, 0777, true);
$cp = null; $cpPipes = []; $web = null;
$createdIds = [];
$sinkKey = 'p155e2e:sink';
$cleanup = function () use (&$cp, &$cpPipes, &$web, $tmp, $prefix, &$origSettings, &$createdIds, $sinkKey) {
    if (is_resource($cp)) {
        if (isset($cpPipes[0]) && is_resource($cpPipes[0])) { @fclose($cpPipes[0]); }
        @proc_terminate($cp); @proc_close($cp);
    }
    if (is_resource($web)) { @proc_terminate($web); @proc_close($web); }
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
    foreach ($createdIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$id, $id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    try {
        $sink = db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?", [$sinkKey]);
        if ($sink) {
            db_query("DELETE FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$sink['id'], $sink['id']]);
            db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$sink['id']]);
        }
    } catch (Throwable $e) {}
    foreach ($origSettings as $k => $v) {
        try {
            if ($v === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$k]); }
            else { vbc_setting_set($k, $v); }
        } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}sse_events` WHERE event_type = 'comm:rx_state' AND payload LIKE '%p155e2e%'"); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE user_name = 'p155-probe'"); } catch (Throwable $e) {}
};
register_shutdown_function($cleanup);

// ── start the PHP web server (so the leg's rx_state POSTs reach the real endpoint) ──
$webPort = free_tcp_port();
$docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
$web = @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $webPort, '-t', $docroot],
    [1 => ['file', $tmp . '/web.out', 'a'], 2 => ['file', $tmp . '/web.err', 'a']], $wp, $docroot, getenv() ?: null, ['bypass_shell' => true]);
$webUp = false;
for ($i = 0; $web && $i < 100; $i++) {
    $c = @fsockopen('127.0.0.1', $webPort, $e1, $e2, 0.2);
    if ($c) { fclose($c); $webUp = true; break; }
    usleep(50000);
}

// ── start the real control plane ─────────────────────────────────────────
$TOKEN = 'p155-e2e-' . bin2hex(random_bytes(6));
$cmd = [$python, __DIR__ . '/../services/audio-matrix/tests/run_control_plane.py', '--token', $TOKEN];
if ($webUp) { $cmd[] = '--php-base-url'; $cmd[] = 'http://127.0.0.1:' . $webPort; }
$cp = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/cp.out', 'a'], 2 => ['file', $tmp . '/cp.err', 'a']], $cpPipes, null, null, ['bypass_shell' => true]);
$cpPort = null;
for ($i = 0; $cp && $i < 420; $i++) {
    $o = (string) @file_get_contents($tmp . '/cp.out');
    if (preg_match('/READY (\d+)/', $o, $m)) { $cpPort = (int) $m[1]; break; }
    usleep(60000);
}
if ($cpPort === null) {
    echo "SKIP: could not start the audio-matrix control plane helper. stderr: " . trim((string) @file_get_contents($tmp . '/cp.err')) . "\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
echo "(control plane on $cpPort, web on " . ($webUp ? $webPort : 'n/a -- rx_state HTTP checks skipped') . ")\n\n";

vbc_setting_set('matrix_control_url', 'http://127.0.0.1:' . $cpPort);
vbc_setting_set('matrix_control_token', $TOKEN);
vbc_setting_set('dvm_policy_ack', '');
$admin = test_admin_user_id();

// ══ 1. create attaches a real leg ═══════════════════════════════════════
echo "1. Create through the admin API attaches a live leg\n";
$udp1 = free_udp_port();
$slug = 'e2e-' . substr(md5(uniqid('', true)), 0, 6);
$key = 'usrp:' . $slug;
$body = json_encode(['adapter' => 'usrp_bridge', 'slug' => $slug, 'label' => 'p155e2e bridge', 'regulatory_class' => 'amateur',
    'mode' => 'other', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001, 'listen_host' => '127.0.0.1',
    'listen_port' => $udp1, 'rx_hang_ms' => 300, 'enabled' => 1]);
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $admin, $body);
t('POST create returns 200 with the channel (the live service accepted it)', $r['status'] === 200 && !empty($r['body']['id']));
$chId = (int) ($r['body']['id'] ?? 0);
if ($chId) { $createdIds[] = $chId; }
$lg = legs();
t('GET /legs on the running service lists the leg, listen-only, on the configured port',
    isset($lg[$key]) && $lg[$key]['listen_only'] === true && (int) $lg[$key]['listen_port'] === $udp1 && $lg[$key]['running'] === true);
t('the UDP port is genuinely bound now', udp_port_in_use($udp1));
t('the create was audited', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'voice_bridge.create' AND target_id = ?", [(string) $chId]) === 1);
$list = probe('voice-bridge-channels.php', 'GET', 'action=list', $admin);
$row = null;
foreach (($list['body']['channels'] ?? []) as $c) { if ((int) $c['id'] === $chId) { $row = $c; } }
t('the admin list reports the leg as attached', $row && $row['leg'] && $row['leg']['attached'] === true);

// ══ 2. audio in, rx_state out ═══════════════════════════════════════════
echo "\n2. Audio sent to the listen port is received; rx_state reaches PHP\n";
db_query("DELETE FROM `{$prefix}sse_events` WHERE event_type = 'comm:rx_state'");
$sock = stream_socket_client('udp://127.0.0.1:' . $udp1, $en, $es, 2);
for ($i = 0; $i < 6; $i++) { fwrite($sock, usrp_datagram($i, pcm_frame(2000 + $i))); usleep(10000); }
fwrite($sock, usrp_datagram(6));     // bare header = end of transmission
t('the leg counted 6 frames and 1 call', wait_for(function () use ($key) { $l = legs(); return isset($l[$key]) && (int) $l[$key]['rx_frames'] === 6 && (int) $l[$key]['rx_calls'] === 1; }));
t('...and the bare header ended the call by length', (function () use ($key) { $l = legs(); return (int) ($l[$key]['rx_eot_by_length'] ?? 0) === 1; })());
if ($webUp) {
    t('api/matrix-channel-state.php stored last_rx_at for the channel (the leg POSTed rx_state over real HTTP)',
        wait_for(function () use ($prefix, $chId) { return db_fetch_value("SELECT last_rx_at FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$chId]) !== null
            && db_fetch_value("SELECT last_rx_at FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$chId]) !== false; }));
    $evs = [];
    wait_for(function () use ($prefix, &$evs) {
        $evs = db_fetch_all("SELECT payload, visibility_scope FROM `{$prefix}sse_events` WHERE event_type = 'comm:rx_state' ORDER BY id");
        return count($evs) >= 2;
    });
    $states = array_map(function ($e) { $p = json_decode($e['payload'], true); return [$p['rx'] ?? '', (int) ($p['channel_id'] ?? 0), $p['channel_key'] ?? '']; }, $evs);
    t('two comm:rx_state SSE events were published: started then ended',
        count($states) >= 2 && $states[0][0] === 'started' && $states[1][0] === 'ended');
    t('...keyed on the NUMERIC comm_channels id (what the console strips use) and carrying the channel key',
        count($states) >= 2 && $states[0][1] === $chId && $states[0][2] === $key);
    t('...as an entitled-scope event (screen.console holders, not the public)', count($evs) >= 1 && $evs[0]['visibility_scope'] === 'entitled');
} else {
    echo "SKIP (these checks only): php -S could not start\n";
}

// a datagram from the wrong source is not heard (127.0.0.2 is loopback on Linux and Windows)
$before = (int) (legs()[$key]['rx_frames'] ?? 0);
$bad = @stream_socket_client('udp://127.0.0.1:' . $udp1, $en, $es, 2, STREAM_CLIENT_CONNECT, stream_context_create(['socket' => ['bindto' => '127.0.0.2:0']]));
if ($bad) {
    fwrite($bad, usrp_datagram(100, pcm_frame(500)));
    t('a datagram from 127.0.0.2 is dropped by the live leg and counted',
        wait_for(function () use ($key) { return (int) (legs()[$key]['rx_dropped_source'] ?? 0) >= 1; })
        && (int) legs()[$key]['rx_frames'] === $before);
} else {
    echo "(127.0.0.2 not usable here; wrong-source covered by the Python suite)\n";
}

// ══ 3. edit re-attaches and moves the port ══════════════════════════════
echo "\n3. Edit through the API moves the live socket\n";
$udp2 = free_udp_port();
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $admin, json_encode(['id' => $chId, 'listen_port' => $udp2, 'rx_hang_ms' => 700]));
t('POST update returns 200', $r['status'] === 200);
t('the OLD port is released and the NEW one is bound', wait_for(function () use ($udp1, $udp2) { return !udp_port_in_use($udp1) && udp_port_in_use($udp2); }));
t('the live leg reports the new port and the new end-of-call silence (700 ms)',
    (int) (legs()[$key]['listen_port'] ?? 0) === $udp2 && (int) (legs()[$key]['rx_hang_ms'] ?? 0) === 700);
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $admin, json_encode(['id' => $chId, 'listen_port' => 80]));
t('an invalid edit is refused (400) and the running leg is untouched', $r['status'] === 400 && udp_port_in_use($udp2) && (int) legs()[$key]['listen_port'] === $udp2);
$blocker = @stream_socket_server('udp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND);
$busy = (int) substr(stream_socket_get_name($blocker, false), strrpos(stream_socket_get_name($blocker, false), ':') + 1);
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $admin, json_encode(['id' => $chId, 'listen_port' => $busy]));
t('an edit onto a UDP port another program holds is refused by the live service (502)', $r['status'] === 502);
t('...the database was rolled back to the working port', (int) vbc_get($chId)['config']['listen_port'] === $udp2);
t('...and the previous leg was put back and is still listening', (int) (legs()[$key]['listen_port'] ?? 0) === $udp2 && udp_port_in_use($udp2));
fclose($blocker);

// ══ 4. disable / re-enable restores standing patches ════════════════════
echo "\n4. Disable detaches; re-enable re-attaches AND restores patches\n";
db_query("INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order, capabilities_json)
          VALUES (?, 'local_chat', 'p155e2e sink', 'internal', 1, 0, 900, ?)", [$sinkKey, json_encode(['voice_rx' => true, 'voice_tx' => true])]);
$sinkId = (int) db_insert_id();
ctl('POST', '/channels', ['id' => $sinkKey, 'name' => 'p155e2e sink', 'reg_class' => 'internal']);
$routeId = matrix_route_create(['src_channel_id' => $chId, 'dst_channel_id' => $sinkId], $admin);
$applied = ctl('POST', '/routes', ['src' => $key, 'dst' => $sinkKey]);
t('a standing patch OUT of the channel to another channel is live', $applied['status'] === 200 && count(ctl('GET', '/routes')['body']) === 1);
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $admin, json_encode(['id' => $chId, 'enabled' => 0]));
t('disabling returns 200, detaches the leg and frees the port', $r['status'] === 200 && wait_for(function () use ($key, $udp2) { return !isset(legs()[$key]) && !udp_port_in_use($udp2); }));
t('...and the live matrix no longer carries the patch (the channel left it)', count(ctl('GET', '/routes')['body']) === 0);
t('...but the standing patch row is still in the database', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_routes` WHERE id = ?", [$routeId]) === 1);
ctl('POST', '/channels', ['id' => $sinkKey, 'name' => 'p155e2e sink', 'reg_class' => 'internal']);   // idempotent re-add (a sink is not ours)
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $admin, json_encode(['id' => $chId, 'enabled' => 1]));
t('re-enabling returns 200 and re-attaches the leg', $r['status'] === 200 && wait_for(function () use ($key, $udp2) { return isset(legs()[$key]) && udp_port_in_use($udp2); }));
t('...and the standing patch is RESTORED in the live matrix',
    wait_for(function () use ($key, $sinkKey) {
        foreach ((ctl('GET', '/routes')['body'] ?? []) as $rt) { if ($rt['src'] === $key && $rt['dst'] === $sinkKey) { return true; } }
        return false; }));

// ══ 5. DVMProject: acknowledgment gate and withdrawal ═══════════════════
echo "\n5. DVMProject: acknowledgment gate, live attach, withdrawal\n";
$udp3 = free_udp_port();
$slug3 = 'e2e-dvm-' . substr(md5(uniqid('', true)), 0, 6);
$body3 = json_encode(['adapter' => 'dvmproject', 'slug' => $slug3, 'label' => 'p155e2e dvm', 'regulatory_class' => 'amateur', 'mode' => 'p25',
    'talkgroup' => '9001', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001, 'listen_host' => '127.0.0.1',
    'listen_port' => $udp3, 'rx_hang_ms' => 400, 'enabled' => 1]);
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $admin, $body3);
t('without the acknowledgment a dvmproject channel is refused and nothing binds', $r['status'] === 403 && !udp_port_in_use($udp3));
foreach (db_fetch_all("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?", ['dvm:' . $slug3]) as $lk) { $createdIds[] = (int) $lk['id']; }
probe('voice-bridge-channels.php', 'POST', 'action=policy_ack', $admin, json_encode(['acknowledged' => 1]));
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $admin, $body3);
$dvmId = (int) ($r['body']['id'] ?? 0);
if ($dvmId) { $createdIds[] = $dvmId; }
t('after acknowledging, the dvmproject channel is created and its leg is live', $r['status'] === 200 && wait_for(function () use ($slug3, $udp3) { return isset(legs()['dvm:' . $slug3]) && udp_port_in_use($udp3); }));
$r = probe('voice-bridge-channels.php', 'POST', 'action=policy_ack', $admin, json_encode(['acknowledged' => 0]));
t('withdrawing the acknowledgment detaches the DVMProject leg in the running service and frees its port',
    $r['status'] === 200 && wait_for(function () use ($slug3, $udp3) { return !isset(legs()['dvm:' . $slug3]) && !udp_port_in_use($udp3); }));
t('...while the usrp_bridge channel keeps running (it needs no acknowledgment)', isset(legs()[$key]));
t('...and the DVMProject channel is disabled in the database', (int) vbc_get($dvmId)['enabled'] === 0);

// ══ 6. delete ═══════════════════════════════════════════════════════════
echo "\n6. Delete\n";
$r = probe('voice-bridge-channels.php', 'POST', 'action=delete', $admin, json_encode(['id' => $chId]));
t('delete returns 200, removes the patch row, detaches the leg and frees the port',
    $r['status'] === 200 && (int) $r['body']['routes_removed'] === 1 && wait_for(function () use ($key, $udp2) { return !isset(legs()[$key]) && !udp_port_in_use($udp2); }));
t('...and the channel is gone from the database', vbc_get($chId) === null);

finish();
