<?php
/**
 * Phase 73x regression tests — rate limit + APRS regex + DMR
 * audio_path normalization.
 */

require_once __DIR__ . '/../inc/rate-limit.php';

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── rate_limit_ok works ───────────────────────────────────────────
$bucket = 'test:' . uniqid('', true);
tcheck(rate_limit_ok($bucket, 5, 60) === true, '1st hit passes');
tcheck(rate_limit_ok($bucket, 5, 60) === true, '2nd hit passes');
tcheck(rate_limit_ok($bucket, 5, 60) === true, '3rd hit passes');
tcheck(rate_limit_ok($bucket, 5, 60) === true, '4th hit passes');
tcheck(rate_limit_ok($bucket, 5, 60) === true, '5th hit passes (at limit)');
tcheck(rate_limit_ok($bucket, 5, 60) === false, '6th hit blocked');
tcheck(rate_limit_ok($bucket, 5, 60) === false, '7th hit blocked');

// Different bucket has its own counter
$other = 'test:' . uniqid('', true);
tcheck(rate_limit_ok($other, 5, 60) === true, 'other bucket independent');

// Very short window expires
$short = 'test:' . uniqid('', true);
rate_limit_ok($short, 1, 1);
sleep(2);
tcheck(rate_limit_ok($short, 1, 1) === true, 'window expiry resets counter');

// ── APRS callsign regex applied ──────────────────────────────────
$loc = file_get_contents(__DIR__ . '/../api/location.php');
tcheck(strpos($loc, "preg_match('/^[A-Z0-9\\-]{1,10}\$/i', \$callsign)") !== false,
    'location.php applies callsign whitelist for test_aprs');
tcheck(strpos($loc, "preg_match('/^[A-Za-z0-9.\\-]{8,40}\$/', \$apiKey)") !== false,
    'location.php applies api_key shape check');

// ── DMR audio_path path-traversal defence (Phase 82 relaxation) ──
// Phase 82 changed the bridge to send paths like
//   "minnesota-statewide/2026/06/15/file.wav"
// so the WAV writer can preserve the dated directory structure. The
// validation no longer basenames the value but still rejects `..`,
// leading slashes, URL-encoded traversal, and characters outside a
// tight whitelist. These tests pin the new defence in place.
$dmr = file_get_contents(__DIR__ . '/../api/dmr-ingest.php');
tcheck(strpos($dmr, "preg_match('#(^|/)\\.\\.(/|\$)#'") !== false,
    "dmr-ingest rejects '..' segments anywhere in the relative path");
tcheck(strpos($dmr, "stripos(\$candidate, '%2e%2e')") !== false,
    'dmr-ingest rejects URL-encoded traversal in audio_path');
tcheck(strpos($dmr, "preg_match('#^[A-Za-z0-9._/-]{1,256}\$#'") !== false,
    'dmr-ingest enforces tight whitelist on relative audio_path');
tcheck(strpos($dmr, "\$candidate[0] === '/'") !== false,
    'dmr-ingest rejects absolute audio_path');

// ── Ingest endpoints actually wired the limiter ──────────────────
tcheck(strpos($loc, "rate_limit_ok('ot-ingest:' . \$srcIp,") !== false,
    'OwnTracks ingest wired to rate limiter');
tcheck(strpos(file_get_contents(__DIR__ . '/../api/mesh.php'),
    "rate_limit_ok('mesh-ingest:' . \$srcIp,") !== false,
    'Mesh ingest wired to rate limiter');
tcheck(strpos($dmr, "rate_limit_ok('dmr-ingest:' . \$srcIp,") !== false,
    'DMR ingest wired to rate limiter');

echo "Phase 73x rate-limit / APRS / DMR regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
