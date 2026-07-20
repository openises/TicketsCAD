<?php
/**
 * NewUI v4.0 - Zello WebSocket Proxy Server
 *
 * CLI entry point. Starts a Ratchet WebSocket server that acts as a relay
 * between browser clients and the Zello Channel API.
 *
 * Usage:
 *   php proxy/zello-proxy.php
 *
 * The proxy:
 *   1. Reads Zello settings from the database
 *   2. Listens for browser WebSocket connections on the configured port
 *   3. Authenticates browsers via their PHP session
 *   4. Connects upstream to Zello servers using JWT or dev auth token
 *   5. Relays text messages bidirectionally
 *   6. Logs all messages to the zello_messages table
 */

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

// Suppress PHP 8.2 deprecation warnings from Ratchet's dynamic properties
// (must be AFTER config.php which sets error_reporting(E_ALL))
error_reporting(E_ALL & ~E_DEPRECATED);

// Catch fatal errors and uncaught exceptions so the proxy doesn't die silently
set_error_handler(function ($severity, $message, $file, $line) {
    // Don't report suppressed or deprecated errors
    if (!(error_reporting() & $severity)) return true;
    echo '[' . date('H:i:s') . '] ' . "[PHP ERROR] {$message} in {$file}:{$line}\n";
    return true;
});

set_exception_handler(function ($e) {
    echo '[' . date('H:i:s') . '] ' . "[UNCAUGHT EXCEPTION] " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '[' . date('H:i:s') . '] ' . "[FATAL ERROR] {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use NewUI\Proxy\ZelloProxyApp;

/**
 * Timestamped console logging for the proxy.
 * All proxy files use this function so every line has a time prefix.
 */
function plog($msg) {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

// ── Banner ───────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════╗\n";
echo "║  NewUI v4.0 — Zello WebSocket Proxy Server  ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

// ── Database connection ──────────────────────────────────────────
plog('[Init] Connecting to database...');
try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    plog('[Init] Database connected');
} catch (PDOException $e) {
    plog('[FATAL] Database connection failed: ' . $e->getMessage());
    exit(1);
}

// ── Load Zello settings ──────────────────────────────────────────
plog('[Init] Loading Zello settings...');
// Phase 41 (Sonar S2077): $prefix comes from config.php — whitelist the
// chars before interpolating so a botched config can't smuggle SQL.
$prefix = $GLOBALS['db_prefix'] ?? '';
if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "Invalid db_prefix value\n"); exit(1);
}
try {
    $stmt = $pdo->prepare("SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` LIKE 'zello_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $row) {
        $config[$row['name']] = $row['value'];
    }
} catch (PDOException $e) {
    plog('[FATAL] Failed to load settings: ' . $e->getMessage());
    exit(1);
}

$port = (int) ($config['zello_proxy_port'] ?? 8090);
if ($port < 1024 || $port > 65535) {
    $port = 8090;
}

$service = $config['zello_service'] ?? '';
if ($service === '') {
    plog('[WARN] Zello service type not configured. Set it in Config > Zello Network Radio.');
    plog('[WARN] Proxy will start but cannot connect upstream until configured.');
}

$wsUrl   = $config['zello_ws_url'] ?? 'wss://zello.io/ws';
$channel = $config['zello_dispatch_channel'] ?? '(not set)';

plog('[Init] Service type:     ' . ($service ?: '(not configured)'));
plog('[Init] WebSocket URL:    ' . $wsUrl);
plog('[Init] Dispatch channel: ' . $channel);
plog('[Init] Proxy port:       ' . $port);

// ── Write PID file for health monitoring ─────────────────────────
$pidFile = __DIR__ . '/zello-proxy.pid';
$pidData = [
    'pid'        => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
    'port'       => $port,
];
file_put_contents($pidFile, json_encode($pidData, JSON_PRETTY_PRINT));
plog('[Init] PID file:         ' . $pidFile);

// Clean up PID file on shutdown
register_shutdown_function(function () use ($pidFile) {
    if (file_exists($pidFile)) {
        @unlink($pidFile);
    }
    echo "\n[" . date('H:i:s') . '] ' . "[Shutdown] PID file removed. Goodbye.\n";
});

plog('[Init] Starting WebSocket server on port ' . $port . '...');

// IMPORTANT: We must construct IoServer manually instead of using IoServer::factory()
// because factory() ignores the $loop parameter and creates its own event loop.
// This would cause outbound connections (upstream to Zello) to be on a different
// loop than the inbound server, meaning they would never be processed.
$loop = Loop::get();

$proxyApp = new ZelloProxyApp(
    $loop,
    $config,
    $pdo,
    $prefix,
    $dsn,
    $db_user,
    $db_pass
);

$wsServer = new WsServer($proxyApp);
$wsServer->enableKeepAlive($loop, 30); // 30s keepalive pings

// Create the socket server on OUR loop (not IoServer::factory's internal loop)
$socket = new \React\Socket\SocketServer('0.0.0.0:' . $port, [], $loop);
$server = new IoServer(
    new HttpServer($wsServer),
    $socket,
    $loop
);

// Phase D (messaging-send-gaps-2026-06) — drain the zello_outbox queue on a
// periodic timer so routed Zello sends (queued by the web process, which can't
// reach this event loop) get relayed upstream. 2s cadence is responsive enough
// for dispatch text without hammering the DB.
$loop->addPeriodicTimer(2.0, function () use ($proxyApp) {
    try {
        $proxyApp->pollZelloOutbox();
    } catch (\Exception $e) {
        plog('[Proxy] outbox timer error: ' . $e->getMessage());
    }
});

echo "\n";
echo "════════════════════════════════════════════════\n";
echo "  Listening on ws://0.0.0.0:{$port}\n";
echo "  Press Ctrl+C to stop\n";
echo "════════════════════════════════════════════════\n\n";

$loop->run();
