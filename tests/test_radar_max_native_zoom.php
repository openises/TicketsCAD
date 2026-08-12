<?php
/**
 * Eric (2026-08-12) — the RainViewer radar layer on incident-detail.php
 * (and every other page sharing assets/js/map-prefs.js's makeRadarLayer())
 * showed "Zoom Level Not Supported" placeholder tiles once zoomed past
 * RainViewer's native zoom 7, with nothing telling the operator radar was
 * the layer at fault.
 *
 * situation.php solved this for its OWN radar layer on 2026-07-05 (#53
 * follow-up) with maxNativeZoom: 7 — Leaflet stops requesting native tiles
 * past that zoom and upscales the last real tile instead (blurry, but no
 * error placeholder — the blur itself signals "approximate here"). The
 * shared map-prefs.js layer never got the same option, so every OTHER map
 * page kept showing the raw error tiles. This is a static-contract check
 * (no JS runtime in CI — docs/CI-ENVIRONMENT.md) confirming both radar
 * layers now agree.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$mapPrefs = file_get_contents($root . '/assets/js/map-prefs.js');
$situation = file_get_contents($root . '/situation.php');

// The shared layer (incident-detail, unit-detail, dashboard, etc.)
if (preg_match('/function makeRadarLayer\(\)\s*\{(.*?)\n    \}/s', $mapPrefs, $m)) {
    test('map-prefs.js makeRadarLayer() sets maxNativeZoom: 7 (matches situation.php)',
        (bool) preg_match('/maxNativeZoom\s*:\s*7\b/', $m[1]));
    test('map-prefs.js makeRadarLayer() keeps maxZoom: 19 (base map/markers still zoom fully)',
        (bool) preg_match('/maxZoom\s*:\s*19\b/', $m[1]));
} else {
    test('found makeRadarLayer() in map-prefs.js to check', false, 'function not found — did it move/rename?');
}

// The reference implementation this was matched to — regression guard so
// a future edit to situation.php's own radar layer can't silently drift
// away from the shared one again.
test('situation.php\'s own radar layer still sets maxNativeZoom: 7 (the value this fix was matched to)',
    (bool) preg_match('/L\.tileLayer\([^)]*maxNativeZoom\s*:\s*7\b/s', $situation));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
