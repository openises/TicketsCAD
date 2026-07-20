<?php
/**
 * test_map_config_tiles.php — tile-provider resolution + surfacing.
 *
 * The resolution + URL sanitization logic lives in inc/tile-config.php
 * (resolve_tile_config / tile_sanitize_url / tile_provider_templates) so it
 * can be tested directly without the auth/session machinery the endpoint
 * needs. We test:
 *   1. resolve_tile_config() resolves a known provider KEY to its template.
 *   2. provider=custom uses the admin-supplied tile_server_url.
 *   3. Legacy installs (only tile_server_url, no tile_provider) still work.
 *   4. URL sanitization blocks javascript:/data:/protocol-relative/ftp.
 *   5. {q} quadkey detection (tile_is_quadkey).
 *   6. tile_api_key is surfaced (tile-scoped, by design).
 *   7. The endpoint (api/map-config.php) actually wires the helper in and
 *      merges the tile payload into its JSON response.
 *
 * Spec: specs/configurable-tile-providers-2026-06/ (Phase A).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tile-config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Configurable Tile Providers — map-config surfacing tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── 1. Known provider KEY → its template (bing_road, a {q} provider) ─
$r = resolve_tile_config('bing_road', '', 'BING_TEST_KEY', 'direct', 30);
if (strpos($r['tile_url'], 'virtualearth.net') !== false && strpos($r['tile_url'], '{q}') !== false) {
    ok('bing_road KEY resolves to its {q} virtualearth template');
} else {
    bad('bing_road template resolution', $r['tile_url']);
}
if ($r['tile_is_quadkey'] === true) { ok('tile_is_quadkey true for Bing template'); }
else { bad('tile_is_quadkey for Bing', var_export($r['tile_is_quadkey'], true)); }
if ($r['tile_api_key'] === 'BING_TEST_KEY') { ok('tile_api_key surfaced (tile-scoped key — by design)'); }
else { bad('tile_api_key surfaced', $r['tile_api_key']); }
if ($r['tile_cache_days'] === 30) { ok('tile_cache_days passes through as int'); }
else { bad('tile_cache_days', var_export($r['tile_cache_days'], true)); }
if ($r['has_custom_tile'] === true) { ok('has_custom_tile true for resolved provider'); }
else { bad('has_custom_tile true', var_export($r['has_custom_tile'], true)); }

// Mapbox KEY (an XYZ {key} provider, not quadkey)
$r = resolve_tile_config('mapbox', '', 'pk.test', '', null);
if (strpos($r['tile_url'], 'api.mapbox.com') !== false && $r['tile_is_quadkey'] === false) {
    ok('mapbox KEY resolves to XYZ template, not quadkey');
} else {
    bad('mapbox resolution', json_encode($r));
}

// ── 2. provider=custom uses tile_server_url ─────────────────────────
$r = resolve_tile_config('custom', 'https://tiles.example.org/{z}/{x}/{y}.png', '', '', null);
if ($r['tile_url'] === 'https://tiles.example.org/{z}/{x}/{y}.png') {
    ok('provider=custom resolves to admin tile_server_url');
} else {
    bad('custom resolution', $r['tile_url']);
}
if ($r['tile_is_quadkey'] === false) { ok('tile_is_quadkey false for plain XYZ custom URL'); }
else { bad('tile_is_quadkey false for XYZ', var_export($r['tile_is_quadkey'], true)); }

// A custom Bing-style {q} URL should be flagged quadkey too.
$r = resolve_tile_config('custom', 'https://my.bing.proxy/tiles/{q}.png', '', '', null);
if ($r['tile_is_quadkey'] === true) { ok('custom {q} URL flagged as quadkey'); }
else { bad('custom {q} quadkey flag', var_export($r['tile_is_quadkey'], true)); }

// ── 3. Legacy install: only tile_server_url, no tile_provider ───────
$r = resolve_tile_config('', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', '', 'proxy', 60);
if ($r['tile_url'] === 'https://tile.openstreetmap.org/{z}/{x}/{y}.png') {
    ok('legacy install (only tile_server_url) resolves as custom XYZ');
} else {
    bad('legacy tile_server_url resolution', $r['tile_url']);
}
if ($r['tile_mode'] === 'proxy') { ok('tile_mode surfaced (proxy)'); }
else { bad('tile_mode surfaced', $r['tile_mode']); }

// ── 4. URL sanitization (security) ──────────────────────────────────
$attacks = [
    'javascript:alert(1)//{z}/{x}/{y}' => 'javascript: scheme',
    'data:image/png;base64,AAAA'       => 'data: scheme',
    '//evil.example/{z}/{x}/{y}.png'    => 'protocol-relative //',
    'ftp://x/{z}/{x}/{y}'               => 'ftp: scheme',
    "vbscript:msgbox(1)"               => 'vbscript: scheme',
];
foreach ($attacks as $url => $label) {
    $r = resolve_tile_config('custom', $url, '', '', null);
    if ($r['tile_url'] === '' && $r['has_custom_tile'] === false) {
        ok("sanitization blocks $label");
    } else {
        bad("sanitization blocks $label", $r['tile_url']);
    }
}
// http and https (case-insensitive) are allowed.
if (tile_sanitize_url('HTTPS://OK.example/{z}/{x}/{y}') === 'HTTPS://OK.example/{z}/{x}/{y}') {
    ok('https (any case) is allowed');
} else { bad('https allowed'); }
if (tile_sanitize_url('http://selfhosted.local:8080/{z}/{x}/{y}.png') !== '') {
    ok('plain http allowed (self-hosted/proxy tile servers)');
} else { bad('http allowed'); }

// Empty stays empty.
$r = resolve_tile_config('', '', '', '', null);
if ($r['tile_url'] === '' && $r['has_custom_tile'] === false) {
    ok('nothing configured → empty tile_url, has_custom_tile false');
} else {
    bad('empty config', json_encode($r));
}

// ── 5. Template map sync guard ──────────────────────────────────────
// The server template list MUST cover the key-bearing providers the
// Settings panel offers. Spot-check the ones that matter (Bing quadkey,
// Mapbox key).
$tpl = tile_provider_templates();
foreach (['osm', 'bing_road', 'bing_aerial', 'mapbox', 'esri_sat'] as $key) {
    if (isset($tpl[$key]) && $tpl[$key] !== '') { ok("template registry has '$key'"); }
    else { bad("template registry has '$key'"); }
}
if (!isset($tpl['custom'])) { ok("template registry correctly omits 'custom' (uses tile_server_url)"); }
else { bad("template registry should omit 'custom'"); }

// ── 6. Server template map stays in sync with config.js TILE_URLS ───
$cfgJs = file_get_contents($base . '/assets/js/config.js');
foreach ($tpl as $key => $url) {
    // The config.js TILE_URLS uses the same key + same URL string.
    if (strpos($cfgJs, "'" . $url . "'") !== false || strpos($cfgJs, '"' . $url . '"') !== false) {
        ok("config.js TILE_URLS contains the '$key' template (server↔client in sync)");
    } else {
        bad("config.js TILE_URLS sync for '$key'", 'URL not found in config.js');
    }
}

// ── 7. Endpoint wires the helper + merges the tile payload ──────────
$code = file_get_contents($base . '/api/map-config.php');
if (strpos($code, "require_once __DIR__ . '/../inc/tile-config.php'") !== false) {
    ok('map-config.php includes inc/tile-config.php');
} else {
    bad('map-config.php includes helper');
}
if (strpos($code, 'resolve_tile_config(') !== false) {
    ok('map-config.php calls resolve_tile_config()');
} else {
    bad('map-config.php calls resolve_tile_config()');
}
if (preg_match('/json_response\s*\(\s*array_merge\(/', $code)) {
    ok('map-config.php merges the tile payload into its JSON response');
} else {
    bad('map-config.php merges tile payload');
}

echo "\n";
echo "==========================================================\n";
echo "Map-config tile tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

if ($fail > 0) exit(1);
