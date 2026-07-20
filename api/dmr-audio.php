<?php
/**
 * NewUI v4.0 API — DMR audio playback proxy
 *
 * GET /api/dmr-audio.php?msg_id=<dmr_messages.id>&token=<bridge_token>
 *
 * Phase 77b — DVR-style dispatcher playback. Fetches dmr_messages.audio_path
 * + channel.bridge_host, calls the bridge's /recording endpoint with the
 * admin-supplied bearer token, and streams the WAV back to the browser
 * with HTTP Range support so HTML5 audio can scrub long recordings.
 *
 * The bridge holds the raw audio files; this endpoint is the auth +
 * scope-check + proxy boundary. We do NOT cache audio bytes on the
 * TicketsCAD VM because each call is small (≤ 30 s × 16 kbps = 480 KB)
 * and the bridge serves them straight from disk under a minute or two
 * of CPU per shift.
 *
 * The browser-side admin panel asks the user for the bridge bearer
 * once per session and keeps it in sessionStorage (mirrors how
 * dvswitch-admin.js already handles the test-modal endpoints).
 *
 * RBAC: the same gate as the rest of api/dvswitch.php — admin role
 * required. Future enhancement: a dedicated action.play_dmr_audio
 * permission so dispatchers can replay without full admin.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
ini_set('display_errors', '0');

// Phase 82b — DVR playback is "receive" capability. Backwards compat:
// is_admin always allowed; old `action.play_dmr_audio` keeps working
// for any installs that already granted it before the three-permission
// split landed.
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

$prefix = $GLOBALS['db_prefix'] ?? '';
$msgId  = (int) ($_GET['msg_id'] ?? 0);
// Phase 85c-fix-9: legacy callers passed `token` from sessionStorage,
// pre-dmr_channels.bridge_token. The token now lives in the channels
// table — fetch it server-side and never expose it to the browser.
// The `token` query param is accepted for backwards compatibility
// but ignored when the channel has its own token configured.
$clientToken = (string) ($_GET['token'] ?? '');

if ($msgId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'msg_id required']);
    exit;
}

try {
    $row = db_fetch_one(
        "SELECT m.id, m.channel_id, m.audio_path, m.audio_format, m.duration_ms,
                c.bridge_host, c.bridge_port, c.bridge_token, c.label
         FROM `{$prefix}dmr_messages` m
         LEFT JOIN `{$prefix}dmr_channels` c ON m.channel_id = c.id
         WHERE m.id = ?",
        [$msgId]
    );
} catch (Exception $e) {
    error_log('[dmr-audio] lookup failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'lookup failed']);
    exit;
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'message not found']);
    exit;
}
if (empty($row['audio_path'])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'no audio recorded for this call']);
    exit;
}
if (empty($row['bridge_host']) || empty($row['bridge_port'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'channel has no bridge host configured']);
    exit;
}

$bridgeUrl = sprintf(
    'http://%s:%d/recording?path=%s',
    $row['bridge_host'],
    (int) $row['bridge_port'],
    rawurlencode($row['audio_path'])
);

// Prefer server-stored bridge token; fall back to the (now-legacy)
// client-supplied one only if the channel has none.
$effectiveToken = !empty($row['bridge_token']) ? $row['bridge_token'] : $clientToken;
if ($effectiveToken === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'channel has no bridge token configured']);
    exit;
}

// Forward Range header from the browser so HTML5 audio scrubbing works
// without re-fetching every byte on every seek.
$forwardHeaders = ['Authorization: Bearer ' . $effectiveToken];
if (!empty($_SERVER['HTTP_RANGE'])) {
    $forwardHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$h = curl_init();
curl_setopt_array($h, [
    CURLOPT_URL            => $bridgeUrl,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT        => 60,
    // Stream response headers + body straight to the client instead of
    // buffering — keeps memory bounded for long recordings.
    CURLOPT_HEADER         => false,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
        echo $chunk;
        @ob_flush();
        @flush();
        return strlen($chunk);
    },
    CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) {
        $trim = trim($headerLine);
        // Phase 85c-fix-13: capture the bridge's status code from the
        // status line and propagate it to the browser BEFORE any
        // body bytes get echoed via WRITEFUNCTION. Setting
        // http_response_code() after the first echo logs "headers
        // already sent" and the browser sees a 200 even when the
        // bridge said 404 — which mis-routes <audio> media decode.
        if (stripos($trim, 'HTTP/') === 0) {
            if (preg_match('/^HTTP\/[0-9.]+\s+(\d{3})/', $trim, $m)) {
                http_response_code((int) $m[1]);
            }
            return strlen($headerLine);
        }
        if ($trim === '') return strlen($headerLine);
        // Forward Content-Type, Content-Length, Content-Range, Accept-Ranges.
        if (preg_match('#^(Content-Type|Content-Length|Content-Range|Accept-Ranges):#i', $trim)) {
            header($trim, false);
        }
        return strlen($headerLine);
    },
]);

// Mirror the bridge's response code.
curl_exec($h);
$status = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
$err    = curl_error($h);
curl_close($h);

if ($status === 0) {
    http_response_code(502);
    error_log('[dmr-audio] bridge connect failed: ' . $err);
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'bridge unreachable: ' . $err]);
    exit;
}

// Status was already set in HEADERFUNCTION when the bridge's response
// line came back. Nothing more to do here.
