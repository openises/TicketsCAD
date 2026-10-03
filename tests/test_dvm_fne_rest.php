<?php
/**
 * Phase 155 (GH#151) — inc/dvm-fne-rest.php, the read-only DVMProject FNE REST
 * status client.
 *
 * The "FNE" here is tests/_fake_dvm_fne.php, a SIMULATOR written from the FNE
 * REST documentation (TN.1100). Nothing in this file proves a real FNE
 * answers that way; it proves OUR client behaves correctly against that
 * documented shape.
 *
 * Proves:
 *   * N reads cost ONE PUT /auth (the token is cached, not re-minted)
 *   * a rejected token costs exactly ONE re-auth, and a worker whose token
 *     was rejected first checks whether another worker already replaced it
 *   * several concurrent PHP processes with an empty cache cause ONE auth,
 *     not a thrash (the FNE invalidates a client's token on every re-auth)
 *   * the password is sent only as its SHA-256 hex, never in the clear
 *   * link state: connected / degraded / down, and UNKNOWN (never
 *     "connected") whenever REST is unconfigured or unreachable
 *   * redirects are not followed; URL rules; the TLS-verify setting
 *
 * @requires-db
 * Usage: php tests/test_dvm_fne_rest.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/dvm-fne-rest.php';
require_once 'inc/voice-bridge-channels.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 -- DVMProject FNE REST status client ===\n\n";

$origSettings = [];
foreach (['dvm_fne_rest_url', 'dvm_fne_rest_password', 'dvm_fne_rest_verify_tls', 'dvm_fne_ping_stale_secs'] as $k) {
    $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
    $origSettings[$k] = ($v === false) ? null : $v;
}
$proc = null; $tmp = sys_get_temp_dir() . '/p155fne-' . getmypid() . '-' . mt_rand();
@mkdir($tmp, 0777, true);
$stateFile = $tmp . '/state.json';
$logFile = $tmp . '/log.jsonl';
$createdChannelIds = [];
register_shutdown_function(function () use (&$proc, $tmp, $prefix, &$origSettings, &$createdChannelIds) {
    if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); }
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
    foreach ($createdChannelIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($origSettings as $k => $v) {
        try {
            if ($v === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$k]); }
            else { vbc_setting_set($k, $v); }
        } catch (Throwable $e) {}
    }
    try { dvm_fne_token_forget(); } catch (Throwable $e) {}
});

function free_port() {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    if (!is_resource($s)) { return null; }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function control(array $patch) {
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . '/__control');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_POSTFIELDS => json_encode($patch)]);
    curl_exec($ch); curl_close($ch);
}

function logLines() {
    global $logFile;
    $out = [];
    foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $j = json_decode($l, true);
        if (is_array($j) && !preg_match('#^/__control#', $j['uri'])) { $out[] = $j; }
    }
    return $out;
}
function countReq($method, $uri) {
    $n = 0;
    foreach (logLines() as $l) { if ($l['method'] === $method && $l['uri'] === $uri) { $n++; } }
    return $n;
}
function resetLog() { global $logFile; file_put_contents($logFile, ''); dvm_fne_auth_call_count(true); }

$PW = 'fne-secret-pw-p155';
$now = time();
$peers = [
    ['peerId' => 9000100, 'connected' => true, 'connectionState' => 4, 'lastPing' => $now - 3, 'config' => ['identity' => 'Bridge A', 'software' => 'dvmbridge test']],
    ['peerId' => 9000101, 'connected' => true, 'connectionState' => 3, 'lastPing' => $now - 3, 'config' => ['identity' => 'Bridge B']],
    ['peerId' => 9000102, 'connected' => true, 'connectionState' => 4, 'lastPing' => $now - 400, 'config' => ['identity' => 'Bridge C']],
    ['peerId' => 9000103, 'connected' => false, 'connectionState' => 0, 'lastPing' => $now - 3, 'config' => ['identity' => 'Bridge D']],
];
$tgs = [['name' => 'TAC 1', 'source' => ['tgid' => 1, 'slot' => 1]], ['name' => 'EVENT', 'source' => ['tgid' => 9001, 'slot' => 1]]];
file_put_contents($stateFile, json_encode(['password_sha256' => hash('sha256', $PW), 'peers' => $peers, 'tgs' => $tgs]));
file_put_contents($logFile, '');

// ══ 1. pure classification ══════════════════════════════════════════════
echo "1. dvm_fne_classify_peer() (pure)\n";
$c = function ($peer, $stale = 30) use ($now) { return dvm_fne_classify_peer($peer, $now, $stale); };
t('connected + running + fresh ping -> connected', $c($peers[0])['state'] === 'connected');
t('a peer the FNE does not list -> down', $c(null)['state'] === 'down');
t('connected:false -> down', $c($peers[3])['state'] === 'down');
t('connectionState 3 (not running) -> degraded, and the reason names the state',
    $c($peers[1])['state'] === 'degraded' && strpos($c($peers[1])['reason'], 'connection state 3') !== false);
t('a stale ping -> degraded, and the reason says how stale', $c($peers[2])['state'] === 'degraded' && strpos($c($peers[2])['reason'], '400') !== false);
t('...the same peer is connected when the threshold is raised above its age', $c($peers[2], 600)['state'] === 'connected');
t('no recorded ping -> degraded', $c(['peerId' => 1, 'connected' => true, 'connectionState' => 4])['state'] === 'degraded');
t('a ping far in the FUTURE is degraded with a clock-skew reason (never silently "connected")',
    $c(['peerId' => 1, 'connected' => true, 'connectionState' => 4, 'lastPing' => $now + 500])['state'] === 'degraded'
    && stripos($c(['peerId' => 1, 'connected' => true, 'connectionState' => 4, 'lastPing' => $now + 500])['reason'], 'clock') !== false);
t('classification never returns "unknown" (that answer belongs to "we could not ask")',
    !in_array('unknown', [$c($peers[0])['state'], $c(null)['state'], $c($peers[2])['state']], true));

// ══ 2. URL validation ═══════════════════════════════════════════════════
echo "\n2. URL validation\n";
function bad_url($u) { try { dvm_fne_rest_validate_url($u); return false; } catch (InvalidArgumentException $e) { return true; } }
foreach (['', 'fne.local:9990', 'ftp://127.0.0.1', 'http://fne.example.org', 'http://8.8.8.8:9990', 'http://[::1]:9990',
          'http://127.0.0.1:9990/api', 'http://u:p@127.0.0.1:9990', 'http://127.0.0.1:9990?x=1', 'http://127.0.0.1:99999'] as $u) {
    t("refused: " . ($u === '' ? '(empty)' : $u), bad_url($u));
}
$v = dvm_fne_rest_validate_url('http://127.0.0.1');
t('loopback http gets the default port 9990 and no warning', $v['url'] === 'http://127.0.0.1:9990' && $v['warning'] === null);
$v = dvm_fne_rest_validate_url('https://10.1.2.3');
t('https gets the default port 9443 and no warning', $v['url'] === 'https://10.1.2.3:9443' && $v['warning'] === null);
$v = dvm_fne_rest_validate_url('http://192.168.1.5:9990/');
t('plain http across the private network is accepted WITH a replayable-digest warning',
    $v['url'] === 'http://192.168.1.5:9990' && stripos((string) $v['warning'], 'replay') !== false);

// ══ 3. unconfigured ═════════════════════════════════════════════════════
echo "\n3. REST not configured\n";
vbc_setting_set('dvm_fne_rest_url', '');
vbc_setting_set('dvm_fne_rest_password', '');
t('dvm_fne_rest_configured() is false', dvm_fne_rest_configured() === false);
$r = dvm_fne_peer_state(9000100);
t('link state is UNKNOWN, never connected, when REST is not configured', $r['state'] === 'unknown');
$ch = ['id' => 1, 'adapter' => 'dvmproject', 'enabled' => 1, 'config' => ['fne' => ['peer_id' => 9000100]]];
t('vbc_probe_fields() reads unknown for a configured peer id with no REST', vbc_probe_fields($ch)['state'] === 'unknown');
t('...and usrp_bridge channels are always unknown', vbc_probe_fields(['adapter' => 'usrp_bridge', 'enabled' => 1, 'config' => []])['state'] === 'unknown');
t('...a disabled channel is not probed at all', vbc_probe_fields(['adapter' => 'dvmproject', 'enabled' => 0, 'config' => ['fne' => ['peer_id' => 9000100]]]) === []);

// ══ start the simulator ═════════════════════════════════════════════════
$port = free_port();
$env = array_merge(getenv() ?: [], ['FAKE_FNE_STATE' => $stateFile, 'FAKE_FNE_LOG' => $logFile]);
$proc = @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/_fake_dvm_fne.php'],
    [1 => ['file', $tmp . '/out.log', 'a'], 2 => ['file', $tmp . '/err.log', 'a']], $pipes, $tmp, $env, ['bypass_shell' => true]);
$up = false;
for ($i = 0; $proc && $i < 100; $i++) {
    $cn = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($cn) { fclose($cn); $up = true; break; }
    usleep(50000);
}
if (!$up) {
    echo "SKIP (rest of this file): could not start the php -S simulator\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:' . $port);
vbc_setting_set('dvm_fne_rest_password', $PW);
vbc_setting_set('dvm_fne_rest_verify_tls', '1');
vbc_setting_set('dvm_fne_ping_stale_secs', '30');
dvm_fne_token_forget();
resetLog();

// ══ 4. token handling ═══════════════════════════════════════════════════
echo "\n4. Token handling\n";
for ($i = 0; $i < 5; $i++) { $q = dvm_fne_peers(); }
t('five peer queries succeed', $q['ok'] && count($q['peers']) === 4);
t('...and cost exactly ONE PUT /auth (the token is cached on disk, not re-minted per request)',
    countReq('PUT', '/auth') === 1 && dvm_fne_auth_call_count() === 1);
t('...and five GET /peer/query', countReq('GET', '/peer/query') === 5);
$authReq = null; $getReq = null;
foreach (logLines() as $l) {
    if ($l['uri'] === '/auth' && $authReq === null) { $authReq = $l; }
    if ($l['uri'] === '/peer/query' && $getReq === null) { $getReq = $l; }
}
t('the auth request body is exactly {"auth": sha256(password)}',
    $authReq && json_decode($authReq['body'], true) === ['auth' => hash('sha256', $PW)]);
$allLog = (string) file_get_contents($logFile);
t('the PLAINTEXT password never appears anywhere in what the FNE received', strpos($allLog, $PW) === false);
t('GET requests carry the X-DVM-Auth-Token the FNE issued', $getReq && strpos($getReq['token'], 'tok1-') === 0);
$tokFile = dvm_fne_token_path();
t('the cached token file exists, is not world-readable (where the OS has modes), and never stores the password',
    is_file($tokFile) && strpos((string) file_get_contents($tokFile), $PW) === false
    && (DIRECTORY_SEPARATOR === '\\' || (fileperms($tokFile) & 0077) === 0));

// a rejected token -> exactly one re-auth
resetLog();
$firstTok = dvm_fne_token(null)['token'];
control(['invalidate_token' => true]);     // as if another client on the FNE side re-authenticated
$q = dvm_fne_peers();
t('after the token is invalidated the next read still succeeds', $q['ok']);
$seq = [];
foreach (logLines() as $l) { $seq[] = $l['method'] . ' ' . $l['uri'] . ($l['uri'] === '/peer/query' ? ($l['token'] === $firstTok ? ' (old token)' : ' (new token)') : ''); }
t('...the exact sequence is: GET with the old token (rejected), ONE PUT /auth, GET with the new token',
    $seq === ['GET /peer/query (old token)', 'PUT /auth', 'GET /peer/query (new token)']);

// another worker already refreshed: do not auth again
$cachedNow = dvm_fne_token(null);
$a1 = countReq('PUT', '/auth');
$t2 = dvm_fne_token('some-older-token-that-was-rejected');
t('a worker whose token was rejected first checks the cache: another worker already replaced it, so NO new auth',
    $t2['ok'] && $t2['token'] === $cachedNow['token'] && countReq('PUT', '/auth') === $a1);
$t3 = dvm_fne_token($cachedNow['token']);
t('...but if the cached token is the very one that was rejected, it re-authenticates once',
    $t3['ok'] && $t3['token'] !== $cachedNow['token'] && countReq('PUT', '/auth') === $a1 + 1);

// concurrent workers, empty cache
dvm_fne_token_forget();
resetLog();
control(['auth_delay_ms' => 900]);   // a slow auth: callers overlap, so a missing lock shows up as several auths
$workers = [];
for ($i = 0; $i < 6; $i++) {
    $wp = [];
    $wproc = proc_open([PHP_BINARY, __DIR__ . '/_p155_fne_worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wp, null, null, ['bypass_shell' => true]);
    $workers[] = ['proc' => $wproc, 'pipes' => $wp];
}
$outs = [];
foreach ($workers as $w) {
    $outs[] = trim(stream_get_contents($w['pipes'][1]));
    fclose($w['pipes'][1]); fclose($w['pipes'][2]);
    proc_close($w['proc']);
}
$okAll = count(array_filter($outs, function ($o) { return strpos($o, 'OK 4 ') === 0; })) === 6;
t('six PHP processes started at the same instant with an EMPTY token cache all succeed', $okAll);
t('...and together cause exactly ONE PUT /auth (single-flight under the lock; a thrash would invalidate each other\'s tokens)',
    countReq('PUT', '/auth') === 1);
control(['auth_delay_ms' => 0]);
t('...no worker saw a 401', (function () {
    foreach (logLines() as $l) { if ($l['uri'] === '/peer/query' && $l['token'] === '') { return false; } }
    return true; })());

// wrong password
dvm_fne_token_forget();
vbc_setting_set('dvm_fne_rest_password', 'not-the-password');
$q = dvm_fne_peers();
t('a wrong REST password fails cleanly with a message (and caches nothing)',
    !$q['ok'] && stripos((string) $q['error'], 'refused') !== false && !is_file($tokFile));
t('...and the wrong password is not in the error text', strpos((string) $q['error'], 'not-the-password') === false);
vbc_setting_set('dvm_fne_rest_password', $PW);
dvm_fne_token_forget();
resetLog();

// ══ 5. link state end to end ════════════════════════════════════════════
echo "\n5. Link state through the simulator\n";
foreach ([[9000100, 'connected'], [9000101, 'degraded'], [9000102, 'degraded'], [9000103, 'down'], [9999999, 'down']] as $case) {
    $r = dvm_fne_peer_state($case[0]);
    t("peer {$case[0]} reads {$case[1]}" . ($r['reason'] !== '' ? " ({$r['reason']})" : ''), $r['state'] === $case[1]);
}
t('an unreachable REST endpoint reads UNKNOWN, never connected', (function () use ($port, $PW) {
    vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:9');
    $r = dvm_fne_peer_state(9000100);
    vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:' . $port);
    return $r['state'] === 'unknown' && stripos($r['reason'], 'unreachable') !== false;
})());
dvm_fne_token_forget();
$r = dvm_fne_peer_state_cached(9000100);
$reqsAfterFirst = count(logLines());
$r2 = dvm_fne_peer_state_cached(9000100);
t('dvm_fne_peer_state_cached(): a second call in the same request makes no new FNE request',
    $r['state'] === 'connected' && $r2['state'] === 'connected' && count(logLines()) === $reqsAfterFirst);
t('tg exists: TG 9001 is on the FNE', dvm_fne_tg_exists(9001) === true);
t('tg exists: TG 7777 is not', dvm_fne_tg_exists(7777) === false);
t('tg exists: null (could not ask) when REST is unreachable', (function () use ($port) {
    vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:9');
    $r = dvm_fne_tg_exists(9001);
    vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:' . $port);
    return $r === null;
})());

// the console probe path, through a real channel row
$chRow = vbc_create('dvmproject', 'fne-' . substr(md5(uniqid('', true)), 0, 6), 'FNE probe test', 'amateur',
    ['mode' => 'p25', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001, 'listen_host' => '127.0.0.1',
     'listen_port' => 43000 + (getmypid() % 500), 'rx_hang_ms' => 400, 'fne_peer_id' => '9000101'], false);
$createdChannelIds[] = $chRow;
vbc_setting_set('dvm_policy_ack', 'tester');
vbc_update($chRow, ['enabled' => 1]);
$pf = vbc_probe_fields(vbc_get($chRow));
t('vbc_probe_fields() carries the FNE verdict into comm_channel_state: peer 9000101 is degraded, with the reason as last_error',
    $pf['state'] === 'degraded' && strpos((string) ($pf['last_error'] ?? ''), 'FNE:') === 0);
vbc_setting_set('dvm_policy_ack', '');

// an unreachable REST endpoint must not leak its address to console operators via last_error
vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:9');
dvm_fne_token_forget();
$pfDown = vbc_probe_fields(vbc_get($chRow));
t('an unreachable FNE reads unknown, and last_error is generic: it does NOT contain the FNE address or port (it reaches every console operator)',
    $pfDown['state'] === 'unknown' && isset($pfDown['last_error'])
    && strpos($pfDown['last_error'], '127.0.0.1') === false && strpos($pfDown['last_error'], ':9') === false
    && stripos($pfDown['last_error'], 'curl') === false);
vbc_setting_set('dvm_fne_rest_url', 'http://127.0.0.1:' . $port);
dvm_fne_token_forget();

// redirects are not followed
control(['redirect_peer_query' => 'http://127.0.0.1:9/should-never-be-fetched']);
$q = dvm_fne_peers();
t('a redirect from the FNE is NOT followed (SSRF): the query fails with the HTTP status', !$q['ok'] && strpos((string) $q['error'], '302') !== false);
control(['redirect_peer_query' => '']);

// TLS verify setting
$on = dvm_fne_ssl_options(true);
$off = dvm_fne_ssl_options(false);
t('the verify-TLS setting is honoured: on = verify peer and host', $on[CURLOPT_SSL_VERIFYPEER] === true && $on[CURLOPT_SSL_VERIFYHOST] === 2);
t('...off = neither', $off[CURLOPT_SSL_VERIFYPEER] === false && $off[CURLOPT_SSL_VERIFYHOST] === 0);
vbc_setting_set('dvm_fne_rest_verify_tls', '0');
t('...and dvm_fne_rest_settings() reads the stored flag', dvm_fne_rest_settings()['verify_tls'] === false);
vbc_setting_set('dvm_fne_rest_verify_tls', '1');
vbc_setting_set('dvm_fne_ping_stale_secs', '9999999');
t('an out-of-range stale threshold falls back to 30 rather than disabling the check', dvm_fne_rest_settings()['stale_secs'] === 30);

// only read-only calls were ever made
$verbs = [];
foreach (logLines() as $l) { $verbs[$l['method'] . ' ' . $l['uri']] = true; }
$allowed = ['PUT /auth', 'GET /status', 'GET /peer/query', 'GET /tg/query'];
t('only PUT /auth and read-only GETs (/status, /peer/query, /tg/query) were ever sent to the FNE',
    array_diff(array_keys($verbs), $allowed) === []);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
