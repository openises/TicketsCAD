<?php
/**
 * Phase 85c smoke test — full WS path without a browser.
 *
 * 1. Mint a token directly via the DB (skipping the session check on
 *    /api/dmr-token.php since this runs from CLI).
 * 2. Open a WebSocket to ws://localhost:8092/, auth, ptt_start,
 *    stream audio PCM, ptt_end, await tx_ack.
 * 3. Print everything that comes back so failures are obvious.
 *
 * Usage:
 *   php tools/dmr_proxy_smoke.php                 — 3 s of 440 Hz tone
 *   php tools/dmr_proxy_smoke.php /tmp/foo.wav    — load + ffmpeg-transcode
 *                                                   any audio file to
 *                                                   8 kHz s16le mono PCM
 *                                                   and stream that
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Mint a token ───────────────────────────────────────────────
$token = bin2hex(random_bytes(32));
$user = 'cli-smoke';
$userId = 1;
$level = 0;

db_query(
    "DELETE FROM `{$prefix}dmr_ws_tokens` WHERE `created` < DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
);
db_query(
    "INSERT INTO `{$prefix}dmr_ws_tokens`
        (`token`, `user_id`, `user`, `user_level`, `channel_id`, `created`)
     VALUES (?, ?, ?, ?, NULL, NOW())",
    [$token, $userId, $user, $level]
);
echo "[+] Token minted: " . substr($token, 0, 16) . "…\n";

// ── Load audio source ───────────────────────────────────────────
$audioFile = $argv[1] ?? '';
if ($audioFile) {
    if (!is_readable($audioFile)) {
        fwrite(STDERR, "audio file not readable: {$audioFile}\n");
        exit(1);
    }
    // ffmpeg → 8 kHz s16le mono raw PCM on stdout. -loglevel error
    // suppresses the version banner so PHP's exec doesn't choke on it.
    $cmd = sprintf(
        'ffmpeg -hide_banner -loglevel error -i %s -ac 1 -ar 8000 -f s16le pipe:1',
        escapeshellarg($audioFile)
    );
    $pcm = shell_exec($cmd);
    if ($pcm === null || strlen($pcm) === 0) {
        fwrite(STDERR, "ffmpeg produced no output (file format ok?)\n");
        exit(1);
    }
    echo "[+] Loaded " . strlen($pcm) . " bytes of PCM from {$audioFile} (" .
         number_format(strlen($pcm) / 16000, 2) . "s)\n";
} else {
    // 3 s of 440 Hz tone at 8 kHz s16le mono
    $samples = 8000 * 3;
    $pcm = '';
    for ($i = 0; $i < $samples; $i++) {
        $val = (int) (16000 * sin(2 * M_PI * 440 * $i / 8000));
        $pcm .= pack('v', $val & 0xFFFF);
    }
    echo "[+] Generated " . strlen($pcm) . " bytes of PCM tone\n";
}

// ── WS handshake + send ─────────────────────────────────────────
$sock = stream_socket_client('tcp://localhost:8092', $errno, $errstr, 5);
if (!$sock) { fwrite(STDERR, "connect failed: $errstr\n"); exit(1); }

$wsKey = base64_encode(random_bytes(16));
$req =
    "GET / HTTP/1.1\r\n" .
    "Host: localhost:8092\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "Sec-WebSocket-Version: 13\r\n" .
    "Sec-WebSocket-Key: $wsKey\r\n" .
    "\r\n";
fwrite($sock, $req);

// Read response headers
$resp = '';
while (!feof($sock)) {
    $line = fgets($sock);
    if ($line === false) break;
    $resp .= $line;
    if ($line === "\r\n") break;
}
if (strpos($resp, '101 Switching Protocols') === false) {
    fwrite(STDERR, "WS handshake failed:\n$resp\n");
    exit(1);
}
echo "[+] WS handshake OK\n";

// ── Helpers: RFC6455 frame encode/decode (minimal) ─────────────
function ws_send($sock, string $payload, bool $binary = false) {
    $opcode = $binary ? 0x82 : 0x81;  // FIN | (binary=0x2|text=0x1)
    $len = strlen($payload);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
    $frame = chr($opcode);
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }
    $frame .= $mask . $masked;
    fwrite($sock, $frame);
}

function ws_recv($sock): ?array {
    $hdr = fread($sock, 2);
    if (strlen($hdr) < 2) return null;
    $b0 = ord($hdr[0]); $b1 = ord($hdr[1]);
    $opcode = $b0 & 0x0F;
    $len = $b1 & 0x7F;
    if ($len === 126) $len = unpack('n', fread($sock, 2))[1];
    elseif ($len === 127) $len = unpack('J', fread($sock, 8))[1];
    $payload = '';
    while (strlen($payload) < $len) {
        $chunk = fread($sock, $len - strlen($payload));
        if ($chunk === false || $chunk === '') break;
        $payload .= $chunk;
    }
    return ['opcode' => $opcode, 'data' => $payload];
}

// ── Read hello, then send auth ────────────────────────────────
$msg = ws_recv($sock);
echo "[<] " . substr($msg['data'], 0, 200) . "\n";

ws_send($sock, json_encode(['cmd' => 'auth', 'token' => $token]));
$msg = ws_recv($sock);
echo "[<] " . substr($msg['data'], 0, 200) . "\n";

ws_send($sock, json_encode(['cmd' => 'ptt_start']));
echo "[+] sent ptt_start\n";

// Send PCM in 320-byte chunks (20 ms each), paced to wire rate
$chunkSize = 320;
$chunks = str_split($pcm, $chunkSize);
foreach ($chunks as $i => $chunk) {
    ws_send($sock, $chunk, true);
    // 20 ms per chunk = wire rate
    usleep(20000);
}
echo "[+] sent " . count($chunks) . " PCM chunks\n";

ws_send($sock, json_encode(['cmd' => 'ptt_end']));
echo "[+] sent ptt_end\n";

// Read responses (tx_started + eventually tx_ack)
// 90s — bridge pacing + slow PHP usleep can stretch a 3-sec tone to ~60s wire time.
stream_set_timeout($sock, 120);
while (true) {
    $msg = ws_recv($sock);
    if ($msg === null) break;
    if ($msg['opcode'] === 0x8) { echo "[<] WS close\n"; break; }
    echo "[<] " . substr($msg['data'], 0, 300) . "\n";
    if (strpos($msg['data'], 'tx_ack') !== false) break;
}

fclose($sock);
echo "[+] done\n";
