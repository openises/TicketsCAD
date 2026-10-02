<?php
/**
 * GH#150 (rjonesbsink, 2026-09-21) — CARTO now requires a free API key on
 * basemaps.cartocdn.com (confirmed against CARTO's own basemap-styles repo
 * and docs.carto.com/faqs/carto-basemaps): the old unkeyed
 * {s}.basemaps.cartocdn.com/<style>/{z}/{x}/{y}.png path is served an "API
 * key required" watermark tile. The authenticated path drops the {s}
 * subdomain, moves under /rastertiles/, and takes the key as a plain ?key=
 * query parameter.
 *
 * Three independent things were fixed:
 *   1. inc/tile-config.php's cartodb_positron/cartodb_dark templates never
 *      carried a {key} placeholder at all, so the existing generic
 *      substitution mechanism (which already worked correctly for Mapbox)
 *      had nothing to substitute into -- the admin's configured API Key was
 *      silently a no-op for these two presets.
 *   2. assets/js/config.js's TILE_URLS mirror had the same gap, plus
 *      TILE_INFO never listed these two providers at all (no "API Key
 *      Required" badge shown).
 *   3. A genuinely separate bug the reporter found while working around #1:
 *      the Tile URL field's provider-change handler overwrote the field
 *      unconditionally on every dropdown change, with no dirty-check, so a
 *      manual edit never survived switching between two presets.
 *
 * Usage: php tests/test_gh150_cartodb_key.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tile-config.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#150 -- CARTO basemap API key ===\n\n";

// ── 1. PHP canonical templates carry {key}, and resolve the new path shape ──
$templates = tile_provider_templates();
t('cartodb_positron template contains {key}', strpos($templates['cartodb_positron'] ?? '', '{key}') !== false);
t('cartodb_dark template contains {key}', strpos($templates['cartodb_dark'] ?? '', '{key}') !== false);
t('cartodb_positron uses the new /rastertiles/ path (not the watermarked unkeyed path)',
    strpos($templates['cartodb_positron'] ?? '', '/rastertiles/light_all/') !== false);
t('cartodb_dark uses the new /rastertiles/ path', strpos($templates['cartodb_dark'] ?? '', '/rastertiles/dark_all/') !== false);
t('cartodb templates no longer carry the {s} subdomain placeholder (the new endpoint is a single host)',
    strpos($templates['cartodb_positron'] ?? '', '{s}') === false && strpos($templates['cartodb_dark'] ?? '', '{s}') === false);

// ── 2. resolve_tile_config() resolves the right RAW template and carries the
//      configured key through as its own field. It deliberately does NOT
//      substitute {key} into tile_url itself -- for 'direct' mode (the only
//      mode CartoDB's proxy=false policy ever allows) substitution happens
//      client-side in assets/js/config.js, using this same {key}-bearing
//      template plus the separately-returned tile_api_key; see the generic
//      str_replace(['{key}','{access_token}'], ...) this project already
//      uses for Mapbox, now able to do the same job for CartoDB because the
//      template finally carries the placeholder. ──
$cfg = resolve_tile_config('cartodb_positron', '', 'MY_TEST_KEY', 'direct', 30);
t('resolve_tile_config() resolves the new CartoDB template with {key} intact for the client to substitute',
    ($cfg['tile_url'] ?? '') === $templates['cartodb_positron'],
    'got tile_url=' . ($cfg['tile_url'] ?? '(none)'));
t('resolve_tile_config() carries the configured API key through as tile_api_key',
    ($cfg['tile_api_key'] ?? '') === 'MY_TEST_KEY');

$cfgDark = resolve_tile_config('cartodb_dark', '', 'ANOTHER_KEY', 'direct', 30);
t('resolve_tile_config() resolves the Dark Matter template the same way',
    ($cfgDark['tile_url'] ?? '') === $templates['cartodb_dark']);
t('resolve_tile_config() carries the Dark Matter key through too',
    ($cfgDark['tile_api_key'] ?? '') === 'ANOTHER_KEY');

// CartoDB's own proxy policy is "refused" (CARTO's terms grant no re-serving
// right) -- confirm that's still true after this fix, since a flip here
// would silently route CartoDB tiles through the server-side substitution
// path instead of the client-side one this fix actually relies on.
t('CartoDB stays a terms-refused (never proxied) provider',
    $cfg['tile_proxy_allowed'] === false && $cfg['tile_effective_mode'] === 'direct');

// An install with no key configured yet gets the same (keyless, CARTO-side
// watermarked) behavior as before this fix -- never worse, just still fixable
// now via the existing API Key field instead of being structurally impossible.
$cfgNoKey = resolve_tile_config('cartodb_positron', '', '', 'direct', 30);
t('no configured key: resolver still returns a tile_url (no crash / no exception)',
    isset($cfgNoKey['tile_url']) && $cfgNoKey['tile_url'] !== '');

// ── 3. JS mirror (TILE_URLS) matches the PHP canonical templates ──
$js = (string) @file_get_contents(__DIR__ . '/../assets/js/config.js');
t('assets/js/config.js TILE_URLS.cartodb_positron carries {key} and the new path',
    (bool) preg_match('/cartodb_positron:\s*\'https:\/\/basemaps\.cartocdn\.com\/rastertiles\/light_all\/\{z\}\/\{x\}\/\{y\}\.png\?key=\{key\}\'/', $js));
t('assets/js/config.js TILE_URLS.cartodb_dark carries {key} and the new path',
    (bool) preg_match('/cartodb_dark:\s*\'https:\/\/basemaps\.cartocdn\.com\/rastertiles\/dark_all\/\{z\}\/\{x\}\/\{y\}\.png\?key=\{key\}\'/', $js));

// ── 4. TILE_INFO now has entries for both, correctly marked key-required ──
t('TILE_INFO has a cartodb_positron entry with key: true',
    (bool) preg_match('/cartodb_positron:\s*\{[^}]*key:\s*true/', $js));
t('TILE_INFO has a cartodb_dark entry with key: true',
    (bool) preg_match('/cartodb_dark:\s*\{[^}]*key:\s*true/', $js));

// ── 5. The provider-dropdown dirty-check fix exists (structural -- config.js
//      is a page-bound, document.getElementById-heavy file with no Node
//      harness in this codebase, matching the established convention for
//      this class of file elsewhere, e.g. the Phase 154 widget shell tests) ──
t('a dirty-check baseline variable exists', strpos($js, '_tileUrlAutoFilled') !== false);
t('the provider-change handler only auto-fills when the field is empty or still matches the last auto-fill',
    (bool) preg_match('/urlInput\.value\.trim\(\)\s*===\s*\'\'\s*\|\|\s*urlInput\.value\s*===\s*_tileUrlAutoFilled/', $js));
t('loadTileProvider() re-baselines the dirty-check after loading the real saved value',
    (bool) preg_match('/_tileUrlAutoFilled\s*=\s*loadedUrlInput\.value/', $js));

// ── 6. settings.php dropdown reflects the real key requirement ──
$settingsPhp = (string) @file_get_contents(__DIR__ . '/../settings.php');
t('settings.php no longer lists CartoDB under the "no key required" optgroup',
    (bool) preg_match('/Free.*no key required[\s\S]{0,900}?optgroup/', $settingsPhp, $noKeyGroup)
    && strpos($noKeyGroup[0], 'cartodb_positron') === false);
t('settings.php lists CartoDB under "Requires API key"',
    (bool) preg_match('/Requires API key[\s\S]{0,600}cartodb_positron/', $settingsPhp));

// ── 7. help.php documents the key requirement, not "no key needed" ──
$helpPhp = (string) @file_get_contents(__DIR__ . '/../help.php');
t('help.php\'s "Requires an API key" table now includes CartoDB',
    (bool) preg_match('/Requires an API key[\s\S]{0,600}CartoDB/', $helpPhp));
t('help.php\'s "Free — no key required" table no longer lists CartoDB',
    (bool) preg_match('/Free.{0,30}no key required[\s\S]{0,2200}?<\/table>/', $helpPhp, $freeTable)
    && strpos($freeTable[0], 'CartoDB') === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
