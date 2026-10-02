<?php
/**
 * Phase 152 prerequisite #7 — fail-loud channel connect/disconnect + TX
 * confirmation, PHP side. services/audio-matrix/tests/test_browser_leg.py's
 * "4. notify_fn(channel_id, label, event, state) call sequence" section
 * covers the Python side of the SAME feature (proving BrowserLegServer/
 * BrowserLeg call the notifier at the right moments, in the right order).
 *
 * This file proves the OTHER half: the real api/matrix-channel-state.php
 * endpoint actually turns a notify_fn call into a real, correctly-scoped
 * SSE event, AND the three standing wiring points this project has been
 * bitten by skipping before (event-bus.js's SSE_TYPES, notification-
 * tray.js's EVENT_META, api/stream.php's entitled-prefix map) are all
 * genuinely present — a source-level check, not just "the file exists".
 *
 * NOT @requires-http for the wiring checks (source_get_contents); the
 * live endpoint checks below drive api/matrix-channel-state.php through a
 * real ephemeral php -S server, matching this project's established
 * pattern for Bearer-gated matrix-* endpoints
 * (tests/test_phase152_id_policy.php).
 *
 * Usage: php tests/test_phase152_channel_state_wiring.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 prerequisite #7 -- channel_state/tx_state wiring ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Static three-file wiring guard ───────────────────────────────────
echo "1. The standing three-file SSE wiring\n";

$eventBus = (string) @file_get_contents(NEWUI_ROOT . '/assets/js/event-bus.js');
t('event-bus.js SSE_TYPES includes comm:channel_state', strpos($eventBus, "'comm:channel_state'") !== false);
t('event-bus.js SSE_TYPES includes comm:tx_state', strpos($eventBus, "'comm:tx_state'") !== false);

$tray = (string) @file_get_contents(NEWUI_ROOT . '/assets/js/notification-tray.js');
t('notification-tray.js EVENT_META has a comm:channel_state entry (rare, worth a tray notice)',
    strpos($tray, "'comm:channel_state'") !== false);
t('notification-tray.js deliberately has NO comm:tx_state entry (fires on every PTT -- would flood the tray; belongs on the strip TX lamp instead)',
    strpos($tray, "'comm:tx_state'") === false);

$stream = (string) @file_get_contents(NEWUI_ROOT . '/api/stream.php');
t('api/stream.php entitled-prefix map has comm:% -> screen.console',
    preg_match("/'comm:%'\\s*=>\\s*\\['screen\\.console'\\]/", $stream) === 1);

$endpoint = (string) @file_get_contents(NEWUI_ROOT . '/api/matrix-channel-state.php');
t('api/matrix-channel-state.php exists and publishes comm:channel_state', strpos($endpoint, 'comm:channel_state') !== false);
t('api/matrix-channel-state.php publishes comm:tx_state too', strpos($endpoint, 'comm:tx_state') !== false);
t('api/matrix-channel-state.php uses entitled scope (reaches every console viewer, not just admins)',
    strpos($endpoint, "'entitled'") !== false);
t('api/matrix-channel-state.php is Bearer-gated like every other matrix-*.php endpoint',
    strpos($endpoint, 'Bearer ') !== false && strpos($endpoint, 'matrix_control_token') !== false);

$browserPy = (string) @file_get_contents(NEWUI_ROOT . '/services/audio-matrix/legs/browser.py');
t('legs/browser.py has the TX-confirmation echo (tx_started/tx_ended)',
    strpos($browserPy, 'tx_started') !== false && strpos($browserPy, 'tx_ended') !== false);
t('legs/browser.py TX confirmation is gated on a LIVE OUTGOING ROUTE, not just non-silent audio '
    . '(a mic with nowhere to go is not "transmitting")',
    strpos($browserPy, '_has_live_outgoing_route') !== false);

// ── 2. Live endpoint: auth + real SSE publish ───────────────────────────
$httpCapable = function_exists('proc_open') && function_exists('curl_init');
if (!$httpCapable) {
    echo "\nSKIP: proc_open/curl unavailable in this environment — real end-to-end HTTP not run.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

function p152cs2_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152cs2_start_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152cs2_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152cs2-' . getmypid() . '-' . mt_rand();
    if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
    $logdir = $tmpdir . '/logs';
    @mkdir($logdir, 0777, true);
    $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
    $env = array_merge($_ENV ?: [], getenv() ?: []);
    $desc = [1 => ['file', $logdir . '/out.log', 'a'], 2 => ['file', $logdir . '/err.log', 'a']];
    $proc = @proc_open([$bin, '-S', '127.0.0.1:' . $port, '-t', $docroot], $desc, $pipes, $docroot, $env);
    if (!is_resource($proc)) return null;
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if (is_resource($c)) { fclose($c); return ['proc' => $proc, 'port' => $port, 'tmpdir' => $tmpdir]; }
        usleep(50000);
    }
    @proc_terminate($proc);
    @proc_close($proc);
    return null;
}

function p152cs2_stop_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152cs2_rrmdir($srv['tmpdir']);
}

function p152cs2_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152cs2_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function p152cs2_post(string $url, array $headers, ?array $body): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => $raw !== false ? @json_decode($raw, true) : null];
}

echo "\n2. Live endpoint over real HTTP\n";

$savedToken = db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = 'matrix_control_token'");
$testToken = 'zz152-cs2-token-' . mt_rand(100000, 999999);
if ($savedToken === false || $savedToken === null) {
    db_query("INSERT INTO {$prefix}settings (name, value) VALUES ('matrix_control_token', ?)", [$testToken]);
} else {
    db_query("UPDATE {$prefix}settings SET value = ? WHERE name = 'matrix_control_token'", [$testToken]);
}
register_shutdown_function(function () use ($prefix, $savedToken) {
    if ($savedToken === false || $savedToken === null) {
        try { db_query("DELETE FROM {$prefix}settings WHERE name = 'matrix_control_token'"); } catch (Exception $e) {}
    } else {
        try { db_query("UPDATE {$prefix}settings SET value = ? WHERE name = 'matrix_control_token'", [$savedToken]); } catch (Exception $e) {}
    }
});

$srv = p152cs2_start_server();
t('ephemeral php -S test server started', $srv !== null);

if ($srv !== null) {
    $base = 'http://127.0.0.1:' . $srv['port'];
    $url = $base . '/api/matrix-channel-state.php';

    try {
        $r = p152cs2_post($url, ['Content-Type: application/json'], ['event' => 'channel_state', 'channel_id' => 'browser:999', 'state' => 'connected']);
        t('no Bearer token -> 401', $r['status'] === 401);

        $r = p152cs2_post($url, ['Content-Type: application/json', 'Authorization: Bearer wrong-token'],
            ['event' => 'channel_state', 'channel_id' => 'browser:999', 'state' => 'connected']);
        t('wrong Bearer token -> 401', $r['status'] === 401);

        $countBefore = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}sse_events WHERE event_type = 'comm:channel_state'");
        $r = p152cs2_post($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $testToken],
            ['event' => 'channel_state', 'channel_id' => 'browser:999', 'label' => 'zz152-test-user', 'state' => 'connected']);
        t('real token, channel_state=connected -> 200', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);
        $countAfter = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}sse_events WHERE event_type = 'comm:channel_state'");
        t('exactly one new comm:channel_state event was published', $countAfter === $countBefore + 1);

        $evt = db_fetch_one(
            "SELECT payload, visibility_scope FROM {$prefix}sse_events WHERE event_type = 'comm:channel_state' ORDER BY id DESC LIMIT 1"
        );
        t('the published event is entitled-scoped (reaches screen.console holders, not just admins)',
            $evt && $evt['visibility_scope'] === 'entitled');
        $payload = $evt ? json_decode($evt['payload'], true) : null;
        t('payload carries the real channel_id', $payload && $payload['channel_id'] === 'browser:999');
        t('payload carries the real label', $payload && $payload['label'] === 'zz152-test-user');
        t('payload carries state=connected', $payload && $payload['state'] === 'connected');

        $r = p152cs2_post($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $testToken],
            ['event' => 'tx_state', 'channel_id' => 'browser:999', 'label' => 'zz152-test-user', 'tx' => 'started']);
        t('real token, tx_state=started -> 200', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);
        $evt2 = db_fetch_one(
            "SELECT payload, visibility_scope FROM {$prefix}sse_events WHERE event_type = 'comm:tx_state' ORDER BY id DESC LIMIT 1"
        );
        t('the published tx_state event is ALSO entitled-scoped', $evt2 && $evt2['visibility_scope'] === 'entitled');
        $payload2 = $evt2 ? json_decode($evt2['payload'], true) : null;
        t('tx_state payload carries tx=started (not "state")', $payload2 && ($payload2['tx'] ?? null) === 'started');

        $r = p152cs2_post($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $testToken],
            ['event' => 'channel_state', 'channel_id' => 'browser:999', 'state' => 'not_a_real_state']);
        t('an invalid state value is rejected, not silently accepted', $r['status'] === 400);

        $r = p152cs2_post($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $testToken],
            ['event' => 'nonsense', 'channel_id' => 'browser:999']);
        t('an unknown event kind is rejected', $r['status'] === 400);
    } finally {
        p152cs2_stop_server($srv);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
