<?php
/**
 * inc/dmr_stream_relay.php — the cURL-based NDJSON relay behind
 * api/dmr-stream.php.
 *
 * Reported by kmk1971 (openises/tickets#10, filed against the legacy repo by
 * mistake -- see specs/handoff.md): the old fopen()+non-blocking+feof()
 * reader died every ~10-12s even during live traffic. Two compounding bugs:
 * the stream context's `http.timeout` (10s) governs the http:// wrapper's
 * READ timeout, not just the initial connect, so any >10s gap between bridge
 * NDJSON lines killed the socket; and feof() on a non-blocking http://
 * wrapper stream can spuriously report EOF whenever its internal buffer is
 * momentarily empty. Both fire during completely normal silence on a quiet
 * DMR talkgroup between transmissions, which is not a stall.
 *
 * THE TEST: stand up a REAL loopback HTTP server (PHP's built-in dev server)
 * that serves NDJSON with a deliberate 14-SECOND silent gap in the middle --
 * longer than the old code's ~10-12s failure window -- then drive the real
 * dmr_stream_relay() against it and confirm events on BOTH SIDES of the gap
 * are forwarded. A test that only re-derives the callback logic in isolation
 * would pass even if the real file regressed back to fopen(); this drives
 * the actual production function against actual HTTP over an actual gap.
 *
 * Usage: php tests/test_dmr_stream_relay.php
 */

$base = realpath(__DIR__ . '/..');
require_once $base . '/inc/dmr_stream_relay.php';

$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

echo "=== inc/dmr_stream_relay.php — survives a silence gap longer than the old failure window ===\n\n";

// ── Structural: the buggy pattern is actually gone ────────────────────────
$src = (string) file_get_contents($base . '/api/dmr-stream.php');
is_true(strpos($src, 'stream_set_blocking') === false && strpos($src, 'feof(') === false,
    'api/dmr-stream.php no longer uses fopen()/stream_set_blocking()/feof() directly');
is_true(strpos($src, 'dmr_stream_relay(') !== false,
    'api/dmr-stream.php delegates to the real relay function');

$relaySrc = (string) file_get_contents($base . '/inc/dmr_stream_relay.php');
is_true(strpos($relaySrc, 'CURLOPT_TIMEOUT') !== false && preg_match('/CURLOPT_TIMEOUT\s*=>\s*0\b/', $relaySrc) === 1,
    'the relay sets no read-inactivity ceiling (silence between transmissions is normal, not a stall)');

// ── Functional: a real loopback server with a real 14s gap ───────────────
$router = $base . '/tests/__dmr_stream_relay_probe_server.php';
file_put_contents($router, <<<'PHP'
<?php
// Minimal NDJSON fixture: two bursts separated by a 14-second silent gap --
// longer than the old code's observed ~10-12s failure window. Flushes
// explicitly after every line so PHP's dev server actually streams it
// instead of buffering the whole response until the script exits.
if (($_SERVER['REQUEST_URI'] ?? '') !== '/audio-stream') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/x-ndjson');
while (ob_get_level() > 0) { ob_end_flush(); }

echo json_encode(['event' => 'call_start', 'call_id' => 'pre-gap', 'src_id' => 1]) . "\n";
flush();
echo json_encode(['event' => 'audio', 'call_id' => 'pre-gap', 'seq' => 1]) . "\n";
flush();

sleep(14); // the gap this test exists to survive

echo json_encode(['event' => 'audio', 'call_id' => 'post-gap', 'seq' => 1]) . "\n";
flush();
echo json_encode(['event' => 'call_end', 'call_id' => 'post-gap', 'duration_ms' => 500]) . "\n";
flush();
PHP
);

$php = PHP_BINARY;
$port = 18391 + (getmypid() % 500); // spread across parallel test runs
$cmd = escapeshellarg($php) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
     . ' > ' . escapeshellarg(sys_get_temp_dir() . '/tcad_dmr_relay_server_' . getmypid() . '.log') . ' 2>&1';

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);

if (!is_resource($proc)) {
    echo "SKIP: could not start the PHP built-in dev server for the loopback probe\n";
    @unlink($router);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// Give the dev server a moment to bind before the first request.
usleep(400000);

try {
    $events = [];
    $keepalives = 0;
    $result = dmr_stream_relay(
        'http://127.0.0.1:' . $port . '/audio-stream',
        'unused-test-token',
        function (string $event, array $msg) use (&$events) {
            $events[] = $event . ':' . ($msg['call_id'] ?? '?');
        },
        function () use (&$keepalives) {
            $keepalives++;
        },
        // Generous 25s cap -- comfortably longer than the fixture's 14s gap
        // (proving the gap itself doesn't trip anything) but still bounded,
        // so a genuine regression back to the old dead-at-~10s behaviour
        // fails fast instead of hanging the test suite.
        (function () {
            $deadline = time() + 25;
            return function () use ($deadline) { return time() >= $deadline; };
        })(),
        5 // shorter keepalive interval than production, so the 14s gap proves ticks keep firing
    );

    echo "  events received: " . implode(', ', $events) . "\n";
    echo "  keepalive ticks during the gap: $keepalives\n";

    is_true(in_array('call_start:pre-gap', $events, true) && in_array('audio:pre-gap', $events, true),
        'events from BEFORE the 14s gap were forwarded');
    is_true(in_array('audio:post-gap', $events, true) && in_array('call_end:post-gap', $events, true),
        'events from AFTER the 14s gap were ALSO forwarded -- the connection survived a gap '
        . 'longer than the old code\'s ~10-12s failure window',
        'this is the exact defect: the old fopen()-based reader would have died before these arrived');
    is_true($keepalives >= 1,
        'at least one keepalive tick fired during the silent gap (CURLOPT_PROGRESSFUNCTION kept ticking with no data flowing)');
    is_true($result['errno'] === 0 || $result['errno'] === CURLE_ABORTED_BY_CALLBACK,
        'the relay ended cleanly (either the server closed normally, or our own shouldStop() cap fired) -- no real curl error',
        'errno=' . $result['errno'] . ' error=' . $result['error']);
} finally {
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    $pid = proc_get_status($proc)['pid'] ?? null;
    if ($pid) {
        if (stripos(PHP_OS, 'WIN') === 0) {
            @shell_exec('taskkill /T /F /PID ' . (int) $pid . ' 2>&1');
        } else {
            @proc_terminate($proc);
        }
    }
    proc_close($proc);
    @unlink($router);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
