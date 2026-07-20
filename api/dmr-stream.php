<?php
/**
 * NewUI v4.0 API — Live DMR audio stream (SSE)
 *
 * GET /api/dmr-stream.php?channel=<dmr_channels.id>
 *
 * Phase 84s — radio widget live audio + DVR.
 *
 * Opens an SSE stream to the dispatcher's browser carrying audio frames
 * as they arrive from the bridge — NOT a per-call playback that waits
 * for the terminator. Each event is one of:
 *
 *   event: audio
 *   data:  {"call_id": "...", "ts": <unix>, "pcm": "<base64 PCM s16le 8 kHz>"}
 *
 *   event: call_start
 *   data:  {"call_id": "...", "src_id": <int>, "talkgroup": <int>, "callsign": "..."}
 *
 *   event: call_end
 *   data:  {"call_id": "...", "duration_ms": <int>}
 *
 *   event: transcript
 *   data:  {"call_id": "...", "text": "..."}
 *
 * This endpoint connects to the bridge's /audio-stream endpoint (over
 * the bridge_host + bearer token from dmr_channels) and proxies events
 * line-by-line to the browser. The bridge endpoint sends NDJSON; this
 * endpoint wraps each line as a named SSE event for the browser.
 *
 * RBAC: action.dmr_receive (or legacy action.play_dmr_audio, or admin).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
ini_set('display_errors', '0');

$rbacOk = function_exists('rbac_can') && (
    rbac_can('action.dmr_receive')
    || rbac_can('action.play_dmr_audio')
);
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required permission: action.dmr_receive']);
    exit;
}

// CRITICAL: release the session-file lock BEFORE entering the
// long-lived SSE loop. PHP locks the session for the duration of
// any request that called session_start(); a 5-minute SSE means
// every OTHER request from the same browser blocks for the full
// session duration (or until PHP's max_execution_time kills them,
// surfacing as 134s timeouts on /api/statistics.php etc). Apache
// prefork can't multiplex around this — the lock is per-session,
// not per-worker.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$prefix     = $GLOBALS['db_prefix'] ?? '';
$channelId  = (int) ($_GET['channel'] ?? 0);

// Pick the channel — default to the first enabled DMR channel if no id
// is supplied. Future enhancement: select by talkgroup or callsign.
if ($channelId > 0) {
    $channel = db_fetch_one(
        "SELECT id, label, bridge_host, bridge_port, bridge_token, talkgroup
         FROM `{$prefix}dmr_channels` WHERE id = ? LIMIT 1",
        [$channelId]
    );
} else {
    $channel = db_fetch_one(
        "SELECT id, label, bridge_host, bridge_port, bridge_token, talkgroup
         FROM `{$prefix}dmr_channels` WHERE enabled = 1
         ORDER BY id LIMIT 1"
    );
}
if (!$channel) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No DMR channel available']);
    exit;
}

$bridgeHost = (string) $channel['bridge_host'];
$bridgePort = (int)    $channel['bridge_port'];
$token      = (string) $channel['bridge_token'];
if ($bridgeHost === '' || $bridgePort <= 0 || $token === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Channel missing bridge_host / bridge_port / bridge_token']);
    exit;
}
$bridgeBase = sprintf('http://%s:%d', $bridgeHost, $bridgePort);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');  // disable nginx buffering
// Disable PHP/Apache output buffering so each frame flushes immediately.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(1);
// Phase 84-followup-7: bound the stream lifetime instead of running
// forever. Apache prefork keeps one child per concurrent connection,
// and SSE clients that lose their network silently (Cloudflare Tunnel
// hangup, laptop sleep, etc) can leave PHP holding the socket in
// CLOSE-WAIT for hours because connection_aborted() only fires after
// a write fails — and the bridge upstream may stay quiet between
// keepalives. Cap at 5 minutes; the widget's EventSource auto-reconnects.
$maxRuntime = 300;  // 5 min
$startTime  = time();
set_time_limit($maxRuntime + 30);
ignore_user_abort(false);

// Initial keepalive
echo ":connected channel=" . (int) $channel['id'] . " label=" . preg_replace('/[^A-Za-z0-9_-]/', '', $channel['label']) . "\n\n";
@flush();

$streamUrl = $bridgeBase . '/audio-stream';
$ctx = stream_context_create([
    'http' => [
        'header'  => "Authorization: Bearer {$token}\r\nAccept: application/x-ndjson\r\n",
        'timeout' => 10,
        'ignore_errors' => true,
    ],
]);
$fp = @fopen($streamUrl, 'r', false, $ctx);
if (!$fp) {
    echo "event: error\ndata: {\"error\":\"bridge connect failed\"}\n\n";
    @flush();
    exit;
}
stream_set_blocking($fp, false);
stream_set_timeout($fp, 5);

$lastKeepalive = time();

while (!feof($fp)) {
    if (connection_aborted()) break;
    // Hard cap on stream lifetime so PHP releases the worker even if
    // the upstream goes silent and the client never writes back.
    if ((time() - $startTime) >= $maxRuntime) break;

    $line = stream_get_line($fp, 65536, "\n");
    if ($line === false || $line === '') {
        // Periodic keepalive comment so the browser knows we're alive
        if (time() - $lastKeepalive >= 15) {
            echo ":keepalive\n\n";
            @flush();
            $lastKeepalive = time();
        }
        usleep(50000); // 50 ms
        continue;
    }

    $line = trim($line);
    if ($line === '') continue;

    $msg = json_decode($line, true);
    if (!is_array($msg) || empty($msg['event'])) continue;

    $event = preg_replace('/[^a-z_]/', '', strtolower((string) $msg['event']));
    if ($event === '') $event = 'message';
    unset($msg['event']);

    // Phase 85c-fix-12: per-event counter for diagnosis. One write
    // per process lifetime (5-min cap) so the log doesn't bloat.
    if (!isset($eventCounts)) $eventCounts = [];
    $eventCounts[$event] = ($eventCounts[$event] ?? 0) + 1;

    echo "event: " . $event . "\n";
    echo "data: " . json_encode($msg) . "\n\n";
    @flush();
    $lastKeepalive = time();
}

// Write the counts at exit so we can correlate with widget behaviour.
if (!empty($eventCounts)) {
    @error_log("[dmr-stream] pid=" . getmypid() . " forwarded: " .
        json_encode($eventCounts));
}

fclose($fp);
