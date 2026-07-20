<?php
/**
 * NewUI v4.0 — DMR WebSocket Proxy Server (Phase 85c)
 *
 * CLI entry point. Starts a Ratchet WebSocket server that acts as a
 * relay between browser clients and the DMR bridge's HTTP control
 * surface (hbp_client.py on dvswitch-01:18091).
 *
 * Usage:
 *   php proxy/dmr-proxy.php
 *
 * The proxy:
 *   1. Loads dmr_channels rows from the DB on startup
 *   2. Listens for browser WebSocket connections on configured port
 *   3. First message must be {"cmd":"auth","token":"..."} — verified
 *      against dmr_ws_tokens table (Phase 85c migration)
 *   4. Subsequent binary frames are PCM (8 kHz s16le mono) for TX;
 *      JSON text frames are control commands (ptt_start/ptt_end/etc)
 *   5. RX: proxy holds ONE upstream HTTP GET to bridge's /audio-stream
 *      and fans out events to every connected browser
 *
 * Mirrors proxy/zello-proxy.php — see realtime-streaming-proxy skill
 * for the architectural rationale.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

// Ratchet's dynamic-property usage triggers PHP 8.2 deprecations
error_reporting(E_ALL & ~E_DEPRECATED);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return true;
    echo '[' . date('H:i:s') . "] [PHP ERROR] {$message} in {$file}:{$line}\n";
    return true;
});
set_exception_handler(function ($e) {
    echo '[' . date('H:i:s') . "] [UNCAUGHT] " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . ':' . $e->getLine() . "\n";
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '[' . date('H:i:s') . "] [FATAL] {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;

// Make these classes findable without composer autoload entries
require_once __DIR__ . '/DmrProxyApp.php';
require_once __DIR__ . '/DmrUpstream.php';

use NewUI\Proxy\DmrProxyApp;

function plog($msg) { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; }

echo "╔═══════════════════════════════════════════════╗\n";
echo "║  NewUI v4.0 — DMR WebSocket Proxy (Phase 85c) ║\n";
echo "╚═══════════════════════════════════════════════╝\n\n";

// ── Database ─────────────────────────────────────────────────────
plog('[Init] Connecting to database...');
try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Mirror inc/db.php: align the session's time_zone with PHP's
    // local offset so NOW() / DATE_SUB() compare against the same
    // clock the rest of the codebase writes against. Without this,
    // the DB stamps timestamps with PHP-local but compares with UTC
    // and the 2-minute token TTL appears 4-6 hours off.
    try {
        $offset = (new DateTime('now'))->format('P');
        // SET time_zone doesn't accept placeholders, so interpolation
        // is the only option. The regex above pins $offset to exactly
        // 6 chars from a fixed ASCII alphabet — not injectable.
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
            $pdo->exec("SET time_zone = '{$offset}'"); // NOSONAR S2077: validated regex above
        }
    } catch (Exception $tzErr) { /* non-fatal */ }
    plog('[Init] Database connected');
} catch (PDOException $e) {
    plog('[FATAL] Database connection failed: ' . $e->getMessage());
    exit(1);
}

// ── Load configuration ───────────────────────────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';
if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "Invalid db_prefix\n"); exit(1);
}

try {
    // $prefix interpolated in table name — table names can't use
    // bind parameters. Validation above pins it to /^[A-Za-z0-9_]*$/
    // so it's a safe identifier, not injectable.
    $stmt = $pdo->prepare(
        "SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` LIKE 'dmr_%'" // NOSONAR S2077: $prefix validated to [A-Za-z0-9_] above
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $row) {
        $config[$row['name']] = $row['value'];
    }
} catch (PDOException $e) {
    plog('[FATAL] Settings load failed: ' . $e->getMessage());
    exit(1);
}

$port = (int) ($config['dmr_proxy_port'] ?? 8092);
if ($port < 1024 || $port > 65535) $port = 8092;

// Pre-load all enabled DMR channels into memory so the proxy doesn't
// need a DB hit per WS connect.
$channels = [];
try {
    // Same $prefix validation as above — safe identifier interpolation.
    $stmt = $pdo->prepare(
        "SELECT id, label, talkgroup, bridge_host, bridge_port, bridge_token,
                link_mode, enabled
         FROM `{$prefix}dmr_channels` WHERE enabled = 1" // NOSONAR S2077: $prefix validated to [A-Za-z0-9_] above
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $channels[(int) $row['id']] = $row;
    }
} catch (PDOException $e) {
    plog('[WARN] Channel load failed: ' . $e->getMessage());
}

plog('[Init] Proxy port:         ' . $port);
plog('[Init] DMR channels:       ' . count($channels));
foreach ($channels as $id => $ch) {
    plog("[Init]   channel #{$id}: {$ch['label']} (TG {$ch['talkgroup']}) " .
         "→ {$ch['bridge_host']}:{$ch['bridge_port']}");
}

// ── PID file ──────────────────────────────────────────────────────
$pidFile = __DIR__ . '/dmr-proxy.pid';
file_put_contents($pidFile, json_encode([
    'pid'        => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
    'port'       => $port,
], JSON_PRETTY_PRINT));
plog('[Init] PID file:           ' . $pidFile);

register_shutdown_function(function () use ($pidFile) {
    if (file_exists($pidFile)) @unlink($pidFile);
    echo "\n[" . date('H:i:s') . "] [Shutdown] PID file removed. Goodbye.\n";
});

// ── Build the loop manually ───────────────────────────────────────
// Same critical caveat as Zello (proxy/zello-proxy.php :148): we MUST
// construct the IoServer + socket on the same Loop::get() instance
// that DmrProxyApp / DmrUpstream use for outbound connections.
// IoServer::factory() creates its own loop and ignores the one you
// pass — outbound react/socket connections would never be serviced.
$loop = Loop::get();

// Pass DB creds so the app can reconnect after MySQL idle-timeouts
// (Phase 85c-fix-18). Without these, the proxy must be restarted any
// time the connection dies.
$proxyApp = new DmrProxyApp($loop, $config, $pdo, $prefix, $channels, [
    'host' => $db_host, 'user' => $db_user, 'pass' => $db_pass, 'name' => $db_name,
]);

$wsServer = new WsServer($proxyApp);
$wsServer->enableKeepAlive($loop, 30);

$socket = new \React\Socket\SocketServer('0.0.0.0:' . $port, [], $loop);
$server = new IoServer(new HttpServer($wsServer), $socket, $loop);

echo "\n";
echo "════════════════════════════════════════════════\n";
echo "  Listening on ws://0.0.0.0:{$port}\n";
echo "  Press Ctrl+C to stop\n";
echo "════════════════════════════════════════════════\n\n";

$loop->run();
