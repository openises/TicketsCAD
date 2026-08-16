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
require_once __DIR__ . '/../inc/dmr_token.php';
ini_set('display_errors', '0');

// action.play_dmr_audio dropped 2026-08-15 (tools/rbac_permission_audit.php)
// -- no such permission was ever seeded; action.dmr_receive already covers
// DMR audio access.
$rbacOk = function_exists('rbac_can') && rbac_can('action.dmr_receive');
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
$token      = dmr_bridge_token($channel);
if ($bridgeHost === '' || $bridgePort <= 0 || $token === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => dmr_token_missing_reason($channel)]);
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

// Reported by kmk1971 (openises/tickets#10, filed against the legacy repo by
// mistake -- see specs/handoff.md): the old fopen()-based reader died every
// ~10-12s even during live traffic, isolated to this relay -- raw curl -N
// against the same bridge, same bearer, during a live transmission delivered
// 157 NDJSON lines cleanly in ~2s, and this endpoint's own eventCounts log
// line never fired, i.e. zero events forwarded per connection lifetime.
// Full root-cause writeup is in inc/dmr_stream_relay.php, which now owns the
// actual read loop -- pulled out of this file so it can be driven by a test
// against a real loopback server (tests/test_dmr_stream_relay.php), not just
// exercised through this endpoint's session/RBAC-gated HTTP entry point.
require_once __DIR__ . '/../inc/dmr_stream_relay.php';

$result = dmr_stream_relay(
    $streamUrl,
    $token,
    function (string $event, array $msg): void {
        echo "event: " . $event . "\n";
        echo "data: " . json_encode($msg) . "\n\n";
        @flush();
    },
    function (): void {
        echo ":keepalive\n\n";
        @flush();
    },
    function () use ($startTime, $maxRuntime): bool {
        return connection_aborted() !== 0 || (time() - $startTime) >= $maxRuntime;
    }
);

// CURLE_ABORTED_BY_CALLBACK (42) is our own deliberate stop (client gone or
// the runtime cap), not a bridge failure -- must not be surfaced as one. Any
// other error before a single event was ever forwarded is a real connect
// failure, matching the old fopen()-failed branch's behaviour.
if ($result['errno'] !== 0 && $result['errno'] !== CURLE_ABORTED_BY_CALLBACK && empty($result['eventCounts'])) {
    echo "event: error\ndata: " . json_encode(['error' => 'bridge connect failed', 'detail' => $result['error']]) . "\n\n";
    @flush();
}

// Write the counts at exit so we can correlate with widget behaviour.
if (!empty($result['eventCounts'])) {
    @error_log("[dmr-stream] pid=" . getmypid() . " forwarded: " .
        json_encode($result['eventCounts']));
}
