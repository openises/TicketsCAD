<?php
/**
 * NewUI v4.0 — Self-service Diagnostics facts (GH #8 / #13 tester assist).
 *
 * The companion page diagnostics.php runs the *client-side* tests (does the SSE
 * stream connect in THIS browser, does Web Push work on THIS device). This
 * endpoint supplies the SERVER-side facts those tests need + a health summary a
 * beta tester can screenshot instead of hunting through logs:
 *   - push_enabled + VAPID configured (+ the public key, needed to subscribe)
 *   - the two seed push routes present + enabled
 *   - how many push subscriptions THIS user has, and whether they're expired
 *   - SSE stream settings
 *
 * GET /api/diagnostics.php  → JSON. Read-only. Any signed-in user (testers
 * aren't admins). Never leaks the VAPID PRIVATE key.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$uid = (int) ($_SESSION['user_id'] ?? 0);

// ── POST action=push_test — send a Web Push to the CALLER'S OWN device(s).
//    Non-admin (any tester can self-test), CSRF-guarded, own subscriptions only. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    if (($input['action'] ?? '') !== 'push_test') {
        json_error('Unknown action');
    }
    if ($uid <= 0) json_error('Not signed in', 401);
    require_once __DIR__ . '/../inc/push.php';
    try {
        $subs = db_fetch_all(
            "SELECT id FROM `{$prefix}push_subscriptions` WHERE user_id = ? AND channel = 'web'", [$uid]);
        if (empty($subs)) {
            json_response(['ok' => false, 'sent' => 0,
                'error' => 'This device is not subscribed yet — enable push first.']);
        }
        $sent = 0; $errors = [];
        foreach ($subs as $sub) {
            $r = push_send_test((int) $sub['id'], 'TicketsCAD test',
                'Web Push is working. ' . gmdate('H:i:s') . ' UTC');
            if (!empty($r['ok'])) { $sent++; }
            else { $errors[] = ($r['error'] ?? 'error') . ' ' . ($r['reason'] ?? ''); }
        }
        json_response(['ok' => $sent > 0, 'sent' => $sent, 'total' => count($subs),
            'errors' => array_slice($errors, 0, 3)]);
    } catch (Throwable $e) {
        json_error_safe('Test push failed', $e, 'diagnostics-pushtest');
    }
}

function _diag_setting(string $prefix, string $name): string {
    try {
        return (string) db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1", [$name]);
    } catch (Exception $e) { return ''; }
}
function _diag_fetch_all(string $sql, array $p = []): array {
    try { return db_fetch_all($sql, $p); } catch (Exception $e) { return []; }
}

// ── Push prerequisites ──
$pushEnabled = _diag_setting($prefix, 'push_enabled') === '1';
$vapidPub    = _diag_setting($prefix, 'push_vapid_public_key');
$vapidPriv   = _diag_setting($prefix, 'push_vapid_private_key');
$vapidOk     = ($vapidPub !== '' && $vapidPriv !== '');
// Detect the Web Push library ROBUSTLY. The composer autoloader isn't registered
// on this GET path (inc/push.php only requires it in the push_test POST branch),
// so a bare class_exists() is a FALSE NEGATIVE even when the library IS installed
// and push actually delivers — which is exactly what bit GH #8 (a beta tester ran
// `composer install`, push worked, but this line still read "not detected"
// 2026-07-14). Load the autoloader if present, then confirm; fall back to the
// filesystem so the answer never depends on autoloader state.
if (!class_exists('Minishlink\\WebPush\\WebPush')
    && is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
$libOk       = class_exists('Minishlink\\WebPush\\WebPush')
    || is_dir(__DIR__ . '/../vendor/minishlink/web-push');

// ── Push routes ──
$routes = _diag_fetch_all(
    "SELECT `name`, `enabled` FROM `{$prefix}message_routes`
      WHERE `dest_channel` = 'push' ORDER BY `priority`");
$routeOut = [];
$anyEnabledRoute = false;
foreach ($routes as $r) {
    $en = (int) $r['enabled'] === 1;
    if ($en) $anyEnabledRoute = true;
    $routeOut[] = ['name' => (string) $r['name'], 'enabled' => $en];
}

// ── This user's subscriptions ──
$mySubs = 0; $myGone = 0;
try {
    $rows = _diag_fetch_all(
        "SELECT last_error FROM `{$prefix}push_subscriptions`
          WHERE user_id = ? AND channel = 'web'", [$uid]);
    $mySubs = count($rows);
    foreach ($rows as $s) {
        if (strpos((string) ($s['last_error'] ?? ''), 'gone:') === 0) $myGone++;
    }
} catch (Exception $e) { /* pre-push install */ }

// ── Radio / Zello proxy (GH task #67 — widget "flapping") ──
// Two legs must both be healthy or the widget reconnect-loops:
//   1. the proxy daemon is listening on 127.0.0.1:<port> (server-side, here)
//   2. the browser can reach it through Apache's mod_proxy_wstunnel at
//      wss://<host>/zello-ws (client-side — tested by the Diagnostics page JS)
$zelloService = _diag_setting($prefix, 'zello_service');
$zello = ['configured' => $zelloService !== '', 'service' => $zelloService];
if ($zello['configured']) {
    $zport = (int) (_diag_setting($prefix, 'zello_proxy_port') ?: 8090);
    if ($zport < 1024 || $zport > 65535) $zport = 8090;
    // Is the proxy daemon accepting TCP on its local port?
    $listening = false;
    $sock = @fsockopen('127.0.0.1', $zport, $errno, $errstr, 2);
    if ($sock) { $listening = true; fclose($sock); }
    // Daemon PID / uptime (best-effort).
    $pidUp = null;
    $pidFile = NEWUI_ROOT . '/proxy/zello-proxy.pid';
    if (is_file($pidFile)) {
        $pd = json_decode((string) @file_get_contents($pidFile), true);
        if (!empty($pd['started_at']) && ($ts = strtotime($pd['started_at']))) $pidUp = time() - $ts;
    }
    $user  = _diag_setting($prefix, 'zello_username');
    $cred  = _diag_setting($prefix, 'zello_password') ?: _diag_setting($prefix, 'zello_auth_token');
    $chan  = _diag_setting($prefix, 'zello_dispatch_channel') ?: _diag_setting($prefix, 'zello_network');
    $zello += [
        'proxy_mode'        => _diag_setting($prefix, 'zello_proxy_mode'),
        'proxy_port'        => $zport,
        'daemon_listening'  => $listening,        // leg 1 (server → daemon)
        'daemon_uptime_s'   => $pidUp,
        'creds_present'     => ($user !== '' && $cred !== ''),
        'channel_present'   => ($chan !== ''),
        // leg 2 path — the browser tries wss://<host>/zello-ws on HTTPS, or a
        // direct ws://<host>:<port> on plain HTTP. The JS builds the final URL.
        'ws_path'           => '/zello-ws',
    ];
}

json_response([
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    'user_id'     => $uid,
    'zello' => $zello,
    'push' => [
        'enabled'           => $pushEnabled,
        'vapid_configured'  => $vapidOk,
        'vapid_public_key'  => $vapidOk ? $vapidPub : '',   // public only — never the private key
        'library_loaded'    => $libOk,
        'routes'            => $routeOut,
        'any_enabled_route' => $anyEnabledRoute,
        'my_subscriptions'  => $mySubs,
        'my_live_subscriptions' => max(0, $mySubs - $myGone),
    ],
    'sse' => [
        'stream_url'   => 'api/stream.php',
        // Informational: the server holds the stream open ~5 min then the client
        // reconnects. If a proxy caps it shorter, reconnects just happen more often.
        'expected_hold_seconds' => 300,
    ],
]);
