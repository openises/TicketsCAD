<?php
/**
 * NewUI v4.0 API — Push-to-talk audio TX
 *
 * POST /api/dmr-tx-audio.php
 *   multipart/form-data:
 *     audio: Blob (webm/opus or wav)
 *     mime:  audio MIME type
 *     channel: optional dmr_channels.id
 *
 * Forwards the captured PTT audio to the bridge's /tx/audio endpoint
 * which transcodes to 8 kHz PCM, encodes to AMBE, and transmits via
 * the existing HBP TX path.
 *
 * RBAC: action.dmr_transmit (or admin). Same gate as /api/dvswitch.php.
 *
 * Phase 84s — radio widget bidirectional voice.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
// csrf_verify lives in inc/functions.php, already loaded via config.php.
ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$rbacOk = function_exists('rbac_can') && rbac_can('action.dmr_transmit');
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing required permission: action.dmr_transmit']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No audio uploaded']);
    exit;
}

$tmpPath = $_FILES['audio']['tmp_name'];
if (!is_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload not readable']);
    exit;
}
$mime = (string) ($_POST['mime'] ?? $_FILES['audio']['type'] ?? 'application/octet-stream');
$channelId = (int) ($_POST['channel'] ?? 0);

$prefix = $GLOBALS['db_prefix'] ?? '';
if ($channelId > 0) {
    $channel = db_fetch_one(
        "SELECT id, label, bridge_host, bridge_port, bridge_token
         FROM `{$prefix}dmr_channels` WHERE id = ? LIMIT 1",
        [$channelId]
    );
} else {
    $channel = db_fetch_one(
        "SELECT id, label, bridge_host, bridge_port, bridge_token
         FROM `{$prefix}dmr_channels` WHERE enabled = 1
         ORDER BY id LIMIT 1"
    );
}
if (!$channel) {
    http_response_code(404);
    echo json_encode(['error' => 'No DMR channel available']);
    exit;
}

$bridgeHost = (string) $channel['bridge_host'];
$bridgePort = (int)    $channel['bridge_port'];
$token      = (string) $channel['bridge_token'];
if ($bridgeHost === '' || $bridgePort <= 0 || $token === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Channel missing bridge_host / bridge_port / bridge_token']);
    exit;
}
$bridgeBase = sprintf('http://%s:%d', $bridgeHost, $bridgePort);

// Forward to bridge's /tx/audio endpoint
$audioBytes = file_get_contents($tmpPath);
if ($audioBytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Read failed']);
    exit;
}

$bridgeUrl = $bridgeBase . '/tx/audio';
$ch = curl_init($bridgeUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $audioBytes,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: ' . $mime,
        'X-Audio-Mime: ' . $mime,
        'X-Source: radio-widget',
        'Expect:',
    ],
    CURLOPT_RETURNTRANSFER => true,
    // Phase 84t: bridge now returns 202 immediately and transmits in
    // the background; ffmpeg transcode is the only sync work. Keep the
    // timeout generous enough for a slow VM but well under Cloudflare's
    // ~100 s tunnel ceiling so we never surface a 504 page.
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

// 200 (legacy sync) and 202 (new async) are both success.
if ($resp === false || $code < 200 || $code >= 300) {
    http_response_code($code ?: 502);
    echo json_encode([
        'error' => 'bridge tx-audio failed',
        'detail' => $err ?: ($resp ?: ''),
        'http_code' => $code,
    ]);
    exit;
}

if (function_exists('audit_log')) {
    audit_log(
        'comms',                       // category
        'dmr_ptt_transmit',            // activity
        'dmr_channel',                 // targetType
        (int) $channel['id'],          // targetId
        'PTT transmit ' . strlen($audioBytes) . ' bytes (' . $mime . ')',
        [
            'channel_id' => (int) $channel['id'],
            'bytes'      => strlen($audioBytes),
            'mime'       => $mime,
        ]
    );
}
http_response_code(200);
echo $resp;
