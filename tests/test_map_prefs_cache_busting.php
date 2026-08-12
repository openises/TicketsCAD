<?php
/**
 * (Eric, 2026-08-12) — map-prefs.js was included on every map-bearing page
 * as a bare `<script src="assets/js/map-prefs.js">`, with none of the
 * `?v=<?php echo asset_v(...) ?>` cache-busting every sibling JS include
 * uses. Cloudflare fronts your-server.example.com and caches static assets
 * for 4 hours (`Cache-Control: max-age=14400`) keyed on the URL alone -- an
 * unversioned URL means a deploy that changes map-prefs.js is invisible to
 * every visitor until the edge cache happens to expire, or someone purges
 * it by hand. This is exactly how a live fix (RainViewer's radar tiles
 * returning "Zoom Level Not Supported" past z7, fixed with maxNativeZoom:7
 * earlier the same day) kept reproducing on new-incident.php after it was
 * already fixed on disk and deployed: the origin server had the fix, the
 * CDN edge was still serving the pre-fix bytes.
 *
 * Every page that includes map-prefs.js must instead do so via asset_v(),
 * matching the pattern already used for map-defaults.js, map-layer-prefs.js,
 * and the rest of the map JS family (see inc/navbar.php, map-overlays.php).
 */

$root = dirname(__DIR__);

$pages = [
    'facilities.php', 'facility-detail.php', 'facility-edit.php',
    'incident-detail.php', 'index.php', 'map-overlays.php', 'net-control.php',
    'new-incident.php', 'settings.php', 'situation.php', 'unit-detail.php',
    'unit-edit.php', 'units.php',
];

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

echo "=== map-prefs.js cache-busting across every including page ===\n\n";

foreach ($pages as $page) {
    $path = $root . '/' . $page;
    if (!file_exists($path)) {
        test("$page exists", false, 'file not found');
        continue;
    }
    $src = file_get_contents($path);

    test("$page: no bare (unversioned) map-prefs.js include",
        strpos($src, '<script src="assets/js/map-prefs.js"></script>') === false);

    test("$page: includes map-prefs.js via asset_v()",
        (bool) preg_match(
            '/<script src="assets\/js\/map-prefs\.js\?v=<\?php echo asset_v\(\'assets\/js\/map-prefs\.js\'\); \?>"><\/script>/',
            $src
        ));
}

// Guard the helper itself hasn't drifted away from a filemtime-based value —
// a version string that never changes between deploys defeats the entire
// point just as thoroughly as no version string at all. Uses a throwaway
// fixture file under NEWUI_ROOT rather than touching a real source file's
// mtime.
require_once $root . '/config.php';
$fixtureRel = 'assets/js/_test_asset_v_fixture.js';
$fixtureAbs = $root . '/' . $fixtureRel;
file_put_contents($fixtureAbs, '/* fixture */');
touch($fixtureAbs, 1000000000);
clearstatcache();
$v1 = asset_v($fixtureRel);
touch($fixtureAbs, 1000000001);
clearstatcache();
$v2 = asset_v($fixtureRel);
@unlink($fixtureAbs);
test('asset_v() changes when the file\'s mtime changes', $v1 !== $v2);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
