<?php
/**
 * NewUI v4.0 API — Zello recorded audio playback
 *
 * GET /api/zello-audio.php?id=<zello_messages.id>
 *
 * GHSA-x9x6-w4fg-pmcc — recordings used to be served as static files
 * straight out of cache/zello-audio/, reachable with no session, no RBAC
 * check, and no audit entry. This is the authenticated equivalent of
 * api/dmr-audio.php: require action.zello_receive (or admin), look the
 * message up by id rather than trust a client-supplied path, write an
 * audit entry, then stream the .ogg with Range support so the browser's
 * <audio> element can scrub it.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/zello_audio_dir.php';
ini_set('display_errors', '0');

$rbacOk = function_exists('rbac_can') && rbac_can('action.zello_receive');
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required permission: action.zello_receive']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$id     = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'id required']);
    exit;
}

try {
    $row = db_fetch_one(
        "SELECT id, channel, message_type, media_url, sender_display
         FROM `{$prefix}zello_messages` WHERE id = ?",
        [$id]
    );
} catch (Exception $e) {
    error_log('[zello-audio] lookup failed: ' . $e->getMessage());
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
if ($row['message_type'] !== 'voice' || empty($row['media_url'])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'no audio recorded for this message']);
    exit;
}

$path = zello_audio_resolve($row['media_url']);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'recording file is missing']);
    exit;
}

audit_log(
    'comms',
    'view',
    'zello_message',
    $id,
    'Played back Zello recording' . (!empty($row['channel']) ? " on channel '{$row['channel']}'" : ''),
    ['sender' => $row['sender_display'] ?? null]
);

$size = filesize($path);
$mime = 'audio/ogg';

// HTTP Range support so the browser's <audio> element can scrub without
// re-downloading the whole clip on every seek.
$start = 0;
$end   = $size - 1;
$isRange = false;

if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $isRange = true;
    if ($m[1] !== '') $start = (int) $m[1];
    if ($m[2] !== '') $end   = (int) $m[2];
    if ($end > $size - 1) $end = $size - 1;
    if ($start > $end) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
}

$length = $end - $start + 1;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: private, no-store');

if ($isRange) {
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
} else {
    http_response_code(200);
}

$fh = fopen($path, 'rb');
if ($fh === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'could not open recording']);
    exit;
}

fseek($fh, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    @ob_flush();
    @flush();
}
fclose($fh);
