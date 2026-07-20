<?php
/**
 * NewUI v4.0 API — Streaming PTT proxy (Phase 85b, R1).
 *
 * POST /api/dmr-tx-stream.php
 *   Authorization: session cookie (RBAC: action.dmr_transmit)
 *   Content-Type:  application/octet-stream (raw 8 kHz s16le mono PCM)
 *   Body:          chunked transfer-encoding stream OR fixed-length blob
 *
 * Forwards the request body byte-by-byte to the bridge's /tx/stream
 * endpoint as it arrives. The bridge starts the DMR transmit as soon
 * as it has the request headers; voice bursts go out at the wire
 * cadence (60 ms / superframe burst) as PCM chunks arrive.
 *
 * Key difference from api/dmr-tx-audio.php: NO BUFFERING in PHP.
 * curl_setopt(CURLOPT_READFUNCTION) pulls bytes from php://input
 * lazily, so the request body flows browser -> PHP -> bridge with
 * minimal added latency. Phase 84t-async /tx/audio still buffers the
 * whole blob in PHP before forwarding.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
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

$channelId = (int) ($_GET['channel'] ?? 0);
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

// CRITICAL: release the PHP session lock right now. Streaming TX
// holds this request open for the full PTT duration (seconds), and
// any other request from the same browser would otherwise block on
// the session file lock. Same pattern as api/dmr-stream.php.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Disable output buffering for the response so the bridge's 200
// arrives at the browser the instant we get it (we close the request
// after TX is done).
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) { ob_end_flush(); }

$inputFh = fopen('php://input', 'rb');
if (!$inputFh) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot open request input stream']);
    exit;
}
// Force blocking mode so fread() waits for the browser's next chunk
// instead of returning empty string and tripping curl's EOF heuristic.
@stream_set_blocking($inputFh, true);
$inputBytesRead = 0;
$readCallCount = 0;

$bridgeUrl = sprintf('http://%s:%d/tx/stream', $bridgeHost, $bridgePort);
$ch = curl_init($bridgeUrl);
curl_setopt_array($ch, [
    CURLOPT_UPLOAD         => true,    // POST with READFUNCTION-supplied body
    CURLOPT_POST           => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/octet-stream',
        'Transfer-Encoding: chunked',
        'X-Source: radio-widget-stream',
        // curl autonegotiates Expect:100-continue; turn that off so
        // we don't add round-trip latency to the first byte.
        'Expect:',
    ],
    CURLOPT_RETURNTRANSFER => true,
    // Read each chunk from php://input on demand and hand it to
    // curl as the request body. CRITICAL: fread() can return '' on
    // a stream that's still open if no bytes are buffered yet;
    // returning '' from this callback tells curl "EOF" and closes
    // the body. Loop on feof() so '' only goes back on real EOF.
    CURLOPT_READFUNCTION   => function ($ch, $fh, $maxBytes) use ($inputFh, &$inputBytesRead, &$readCallCount) {
        $readCallCount++;
        while (!feof($inputFh)) {
            $data = fread($inputFh, $maxBytes);
            if ($data !== false && $data !== '') {
                $inputBytesRead += strlen($data);
                return $data;
            }
            if (feof($inputFh)) break;
            // Brief sleep so we don't hot-loop while the network
            // delivers the next packet from the browser.
            usleep(5000);  // 5 ms
        }
        return '';
    },
    // Bridge needs to start TX immediately; don't timeout on slow
    // dispatchers but cap total TX duration at 5 min.
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_CONNECTTIMEOUT => 5,
    // No SSL since bridge is on the LAN behind the same trust boundary
    // as Apache.
]);

$startTs = microtime(true);
$resp    = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err     = curl_error($ch);
curl_close($ch);
@fclose($inputFh);
$elapsed = microtime(true) - $startTs;

if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode ?: 502);
    echo json_encode([
        'error'     => 'bridge tx-stream failed',
        'http_code' => $httpCode,
        'detail'    => $err ?: ($resp ?: ''),
    ]);
    exit;
}

error_log(sprintf(
    '[dmr-tx-stream] forwarded %d bytes in %d read calls over %.2fs (channel %d)',
    $inputBytesRead, $readCallCount, $elapsed, (int) $channel['id']
));
if (function_exists('audit_log')) {
    audit_log(
        'comms',
        'dmr_ptt_stream',
        'dmr_channel',
        (int) $channel['id'],
        sprintf('Streaming PTT transmit (%.1fs, %d bytes)', $elapsed, $inputBytesRead),
        ['channel_id' => (int) $channel['id'],
         'elapsed_sec' => round($elapsed, 2),
         'bytes_forwarded' => $inputBytesRead]
    );
}
http_response_code(200);
echo $resp;
