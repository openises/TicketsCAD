<?php
/**
 * test_tile_attribution.php — the map credit for the configured tile provider.
 *
 * The configured ("custom") tile provider's credit was the literal string
 * "Configured provider" (assets/js/map-prefs.js customOpts()). An install
 * serving tiles made from OpenStreetMap data from its own server — SOSM's
 * pre-rendered downloads, or the mbtileserver route docs/OFFLINE-OPERATION.md
 * recommends — therefore could not show "(c) OpenStreetMap contributors", which
 * the OpenStreetMap licence requires, while the offline guide told readers
 * TicketsCAD already displayed it. Found answering Kurt Hess on the Group,
 * 2026-10-13.
 *
 * Now there is a `tile_attribution` setting. This test proves:
 *   1. tile_attribution_normalize() keeps it plain, single-line and bounded.
 *   2. resolve_tile_config() carries it into the map-config payload, and the
 *      old five-argument call still works.
 *   3. The endpoint, the save path, the Settings field and the preview are
 *      wired to the same key.
 *   4. The REAL map-prefs.js (run under node) puts the credit on the built
 *      layer, escapes markup, and falls back when it is empty.
 *   5. The docs no longer claim the credit is shown when it is not.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tile-config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Configured tile provider — map credit (attribution) ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true($cond, $name, $why = '') { if ($cond) { ok($name); } else { bad($name, $why); } }

$OSM = "\u{00A9} OpenStreetMap contributors";

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. tile_attribution_normalize() --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(function_exists('tile_attribution_normalize'), 'tile_attribution_normalize() exists');
is_true(tile_attribution_normalize($OSM) === $OSM, 'the OpenStreetMap credit passes through unchanged',
    tile_attribution_normalize($OSM));
is_true(tile_attribution_normalize('') === '', 'empty stays empty');
is_true(tile_attribution_normalize("   \t  ") === '', 'whitespace-only becomes empty');
is_true(tile_attribution_normalize("  Map data\n\r\t(c)  OSM  ") === 'Map data (c) OSM',
    'control characters become spaces and whitespace collapses',
    tile_attribution_normalize("  Map data\n\r\t(c)  OSM  "));
is_true(tile_attribution_normalize("a\x00b\x7Fc") === 'a b c', 'NUL and DEL are removed',
    bin2hex(tile_attribution_normalize("a\x00b\x7Fc")));

$long = str_repeat("\u{00A9}", 250);
$cut  = tile_attribution_normalize($long);
is_true(mb_strlen($cut, 'UTF-8') === TILE_ATTRIBUTION_MAX, 'capped at TILE_ATTRIBUTION_MAX characters',
    (string) mb_strlen($cut, 'UTF-8'));
is_true(mb_check_encoding($cut, 'UTF-8'), 'the cap never splits a multi-byte character');
is_true(TILE_ATTRIBUTION_MAX === 200, 'the cap is 200, matching the Settings field maxlength');

// Escaping is the renderer's job: escaping here as well would show "&amp;copy;".
$markup = '<b>x</b> & "y"';
is_true(tile_attribution_normalize($markup) === $markup,
    'stored as typed (not HTML-escaped server side; the map escapes it once)',
    tile_attribution_normalize($markup));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. resolve_tile_config() payload --\n";
// ─────────────────────────────────────────────────────────────────────────

$r = resolve_tile_config('custom', 'https://tiles.example.org/{z}/{x}/{y}.png', '', 'direct', 30, "  $OSM \n");
is_true(array_key_exists('tile_attribution', $r), 'payload carries tile_attribution');
is_true($r['tile_attribution'] === $OSM, 'payload value is normalised', var_export($r['tile_attribution'], true));
is_true($r['has_custom_tile'] === true, 'custom URL still resolves alongside it');

$r5 = resolve_tile_config('custom', 'https://tiles.example.org/{z}/{x}/{y}.png', '', 'direct', 30);
is_true(($r5['tile_attribution'] ?? null) === '', 'the five-argument call still works and defaults to empty');

$rk = resolve_tile_config('osm', '', '', 'direct', null, 'Something');
is_true($rk['tile_attribution'] === 'Something', 'carried for a known provider key too (the setting is the admin\'s)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Wiring: endpoint, save path, Settings field, preview --\n";
// ─────────────────────────────────────────────────────────────────────────

$mc = (string) file_get_contents($base . '/api/map-config.php');
is_true(preg_match("/get_variable\\(\\s*'tile_attribution'\\s*\\)/", $mc) === 1,
    'api/map-config.php reads the tile_attribution setting');
is_true(preg_match('/resolve_tile_config\([^;]*\$tile_attribution\s*\)/', $mc) === 1,
    'api/map-config.php passes it to resolve_tile_config()');

$ca = (string) file_get_contents($base . '/api/config-admin.php');
is_true(preg_match("/\\\$key === 'tile_attribution'\\)\\s*\\{[^}]*tile_attribution_normalize\\(/s", $ca) === 1,
    'api/config-admin.php normalises the value on save');
$pNorm = strpos($ca, "\$key === 'tile_attribution'");
$pUpsert = strpos($ca, 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
is_true($pNorm !== false && $pUpsert !== false && $pNorm < $pUpsert,
    'normalisation happens before the settings upsert');

$sp = (string) file_get_contents($base . '/settings.php');
$formStart = strpos($sp, 'id="tileProviderForm"');
$formEnd   = $formStart === false ? false : strpos($sp, '</form>', $formStart);
$field     = strpos($sp, 'data-key="tile_attribution"');
is_true($field !== false, 'Settings has a field bound to tile_attribution');
is_true($formStart !== false && $formEnd !== false && $field > $formStart && $field < $formEnd,
    'the field sits inside the tile provider form, so Save collects it');
is_true(preg_match('/id="setTileAttribution"[^>]*maxlength="200"/s', $sp) === 1,
    'the field is limited to 200 characters, like the server');
is_true(strpos($sp, 'for="setTileAttribution"') !== false, 'the field has a label');
is_true(strpos($sp, 'OpenStreetMap contributors') !== false, 'the help text names the credit OSM tiles need');

$cj = (string) file_get_contents($base . '/assets/js/config.js');
is_true(preg_match("/attribution:\\s*attrText\\s*\\?\\s*esc\\(attrText\\)/", $cj) === 1,
    'the Settings preview shows the credit, escaped');
is_true(preg_match("/getElementById\\('setTileAttribution'\\)[\\s\\S]{0,120}addEventListener\\('change',\\s*updateTilePreview\\)/", $cj) === 1,
    'the preview refreshes when the credit changes');

$mp = (string) file_get_contents($base . '/assets/js/map-prefs.js');
is_true(strpos($mp, "attribution: 'Configured provider'") === false,
    'map-prefs.js no longer hard-codes the custom credit');
is_true(strpos($mp, 'attribution: customAttribution(cfg)') !== false,
    'map-prefs.js builds the custom credit from the payload');
is_true(preg_match('/function customAttribution\(cfg\)\s*\{[^}]*safeEsc\(text\)/s', $mp) === 1,
    'the credit goes through the file\'s one escaper (safeEsc)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. The real map-prefs.js under node --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_tileattr_harness_' . getmypid() . '.js';
    $mpPath  = str_replace('\\', '/', $base . '/assets/js/map-prefs.js');
    $js = <<<'JS'
// Drive the REAL assets/js/map-prefs.js through MapPrefs.init(), which fetches
// api/map-config.php. Only the browser objects it touches are stubbed.
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

global.L = {
    tileLayer: function (url, opts) { return { url: url, options: opts }; },
    layerGroup: function () { return { addTo: function () {}, addLayer: function () {} }; },
    control: { layers: function () { return { addTo: function () { return this; } }; } }
};
global.document = { documentElement: { getAttribute: function () { return 'light'; } } };
global.localStorage = { getItem: function () { return null; }, setItem: function () {} };
global.window = global;

function payload(attr, extra) {
    var cfg = {
        has_custom_tile: true,
        tile_provider: 'custom',
        tile_url: 'http://tiles.example.org/{z}/{x}/{y}.png',
        tile_api_key: '',
        tile_effective_mode: 'direct'
    };
    if (attr !== undefined) { cfg.tile_attribution = attr; }
    for (var k in (extra || {})) { cfg[k] = extra[k]; }
    return cfg;
}

// Each scenario evaluates a fresh copy: MapPrefs caches its config promise.
function load(cfg) {
    global.fetch = function () {
        return Promise.resolve({ ok: true, json: function () { return Promise.resolve(cfg); } });
    };
    delete global.window.MapPrefs;
    eval(src);
    var MP = global.window.MapPrefs;
    return MP.init().then(function () { return MP.makeLayer('custom'); });
}

(async function () {
    var layer = await load(payload('© OpenStreetMap contributors'));
    check('custom layer is built from the configured URL', layer.url.indexOf('tiles.example.org') !== -1, layer.url);
    check('the OpenStreetMap credit reaches the map', layer.options.attribution === '© OpenStreetMap contributors',
          layer.options.attribution);

    layer = await load(payload('<img src=x onerror=alert(1)>'));
    check('markup is escaped, never rendered', layer.options.attribution === '&lt;img src=x onerror=alert(1)&gt;',
          layer.options.attribution);

    layer = await load(payload('Tom & "Jerry\'s" maps'));
    check('ampersand and double quotes are escaped', layer.options.attribution === 'Tom &amp; &quot;Jerry\'s&quot; maps',
          layer.options.attribution);

    layer = await load(payload(''));
    check('empty falls back to "Configured provider"', layer.options.attribution === 'Configured provider',
          layer.options.attribution);

    layer = await load(payload(undefined));
    check('an older server without the field falls back too', layer.options.attribution === 'Configured provider',
          layer.options.attribution);

    layer = await load(payload('© OpenStreetMap contributors',
        { tile_effective_mode: 'proxy', tile_proxy_url: 'api/tile-proxy.php?p=custom&z={z}&x={x}&y={y}' }));
    check('the credit survives proxy mode', layer.url.indexOf('api/tile-proxy.php') === 0 &&
          layer.options.attribution === '© OpenStreetMap contributors', layer.url + ' / ' + layer.options.attribution);

    console.log(out.join('\n'));
})().catch(function (e) { console.log('FAIL|harness threw|' + (e && e.stack ? e.stack.split('\n')[0] : e)); });
JS;
    file_put_contents($harness, $js);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mpPath) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        bad('node harness ran map-prefs.js', trim((string) $raw));
    } else {
        $seen = 0;
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', trim($line), 3);
            if (count($parts) < 2) { continue; }
            $seen++;
            if ($parts[0] === 'PASS') { ok('[js] ' . $parts[1]); }
            else { bad('[js] ' . $parts[1], $parts[2] ?? ''); }
        }
        is_true($seen >= 7, 'every JS scenario reported', (string) $seen);
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Documentation tells the truth --\n";
// ─────────────────────────────────────────────────────────────────────────

$off = (string) file_get_contents($base . '/docs/OFFLINE-OPERATION.md');
is_true(strpos($off, 'TicketsCAD
  displays it already; do not remove it') === false && strpos($off, 'displays it already; do not remove it') === false,
    'the offline guide no longer claims the credit is already shown');
is_true(strpos($off, 'Map credit') !== false, 'the offline guide tells readers to fill in Map credit');

$help = (string) file_get_contents($base . '/help.php');
is_true(strpos($help, '<strong>Map credit</strong>') !== false, 'help.php mentions Map credit for custom URLs');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
