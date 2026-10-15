<?php
/**
 * The built-in Dark and Light basemaps and CARTO's key requirement (2026-10-15).
 *
 * CARTO began requiring a free key on basemaps.cartocdn.com in September 2026.
 * A request without one is answered with a tile reading "API KEY REQUIRED",
 * which is what every map's Dark layer showed: Eric reported it on 2026-10-15
 * ("an API key is required on maps when I select dark... previously working a
 * few weeks ago"). GH#150 had given the CARTO presets of the CONFIGURED provider
 * a key, but the Dark and Light layers every map offers (assets/js/map-prefs.js,
 * plus copies in app.js, aprs-map.php and config.js) still called the old
 * keyless address — and Dark is where every dark-mode user's maps start.
 *
 * What this proves:
 *   1. tile_carto_key_resolve(): the dedicated carto_api_key wins; a CARTO
 *      configured provider's own key is used when it is empty; nothing else
 *      leaks in as a CARTO key; stray characters cannot alter the URL.
 *   2. tile_config_payload() (read from the real settings table, in a fresh
 *      process because get_variable() caches) and the page bootstrap carry it.
 *   3. The REAL map-prefs.js, under node: with a key, Dark and Light are CARTO's
 *      keyed address and stay direct; without one they are OpenStreetMap with a
 *      colour filter, proxied like Street, and never the keyless CARTO address.
 *   4. The CSP allows the keyed host: a CSP `*.basemaps.cartocdn.com` does not
 *      match the bare `basemaps.cartocdn.com` the keyed address uses.
 *   5. No page still builds the keyless CARTO address.
 *   6. The Settings field exists and is handled as a secret.
 *
 * Usage: php tests/test_carto_builtin_key.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tile-config.php';
require_once __DIR__ . '/../inc/settings-secrets.php';
require_once __DIR__ . '/../inc/security-headers.php';

$pass = 0; $fail = 0;
function t($label, $cond, $detail = '') {
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ((!$cond && $detail !== '') ? " -- $detail" : '') . "\n";
    $cond ? $pass++ : $fail++;
}

$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== Built-in Dark/Light basemaps and the CARTO key ===\n\n";

// ── 1. The resolver ──────────────────────────────────────────────────────
t('dedicated CARTO key wins', tile_carto_key_resolve('abc123', 'cartodb_dark', 'other') === 'abc123');
t('dedicated key wins over a non-CARTO provider key', tile_carto_key_resolve('abc123', 'mapbox', 'mbx') === 'abc123');
t('empty dedicated key + CARTO configured provider uses that provider key',
    tile_carto_key_resolve('', 'cartodb_positron', 'pos-key_1') === 'pos-key_1');
t('a non-CARTO provider key is never used as a CARTO key',
    tile_carto_key_resolve('', 'mapbox', 'pk.mapbox') === '');
t('no key anywhere resolves to empty', tile_carto_key_resolve('', 'osm', '') === '');
t('surrounding whitespace is trimmed', tile_carto_key_resolve("  k1  \n", '', '') === 'k1');
t('characters that could alter the URL are dropped',
    tile_carto_key_resolve('ab&c=d#e f"<g>', '', '') === 'abcdefg',
    tile_carto_key_resolve('ab&c=d#e f"<g>', '', ''));

// ── 2. Payload (real settings table, fresh process) + page bootstrap ──────
$boot = tile_config_client_bootstrap(['carto_api_key' => 'K1']);
t('page bootstrap carries carto_api_key', ($boot['carto_api_key'] ?? null) === 'K1');
$boot = tile_config_client_bootstrap([]);
t('page bootstrap carries an empty carto_api_key when there is none', ($boot['carto_api_key'] ?? null) === '');

$orig = db_fetch_one("SELECT value FROM settings WHERE name = 'carto_api_key'");
try {
    if ($orig) {
        db_query("UPDATE settings SET value = ? WHERE name = 'carto_api_key'", ['TESTCARTOKEY42']);
    } else {
        db_query("INSERT INTO settings (name, value) VALUES ('carto_api_key', ?)", ['TESTCARTOKEY42']);
    }
    $probe = '<?php require ' . var_export($base . '/config.php', true) . '; require_once '
        . var_export($base . '/inc/tile-config.php', true)
        . '; echo json_encode(tile_config_payload());';
    $probeFile = tempnam(sys_get_temp_dir(), 'carto') . '.php';
    file_put_contents($probeFile, $probe);
    $out = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($probeFile) . ' 2>&1');
    @unlink($probeFile);
    $payload = json_decode(trim($out), true);
    t('tile_config_payload() reads carto_api_key from the settings table',
        is_array($payload) && ($payload['carto_api_key'] ?? null) === 'TESTCARTOKEY42', substr($out, 0, 200));
} finally {
    if ($orig) {
        db_query("UPDATE settings SET value = ? WHERE name = 'carto_api_key'", [$orig['value']]);
    } else {
        db_query("DELETE FROM settings WHERE name = 'carto_api_key'");
    }
}

// ── 3. The real map-prefs.js under node ───────────────────────────────────
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}
if ($node === null) {
    t('node is available to run map-prefs.js (required for the layer checks)', false);
} else {
    $mpPath  = str_replace('\\', '/', $base . '/assets/js/map-prefs.js');
    $harness = sys_get_temp_dir() . '/tcad_carto_harness_' . getmypid() . '.js';
    $js = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

function makeStub(url, opts) {
    var handlers = {};
    var box = { style: {} };
    return {
        url: url, options: opts, box: box,
        on: function (ev, fn) { (handlers[ev] = handlers[ev] || []).push(fn); return this; },
        fire: function (ev) { var self = this; (handlers[ev] || []).forEach(function (fn) { fn.call(self); }); },
        getContainer: function () { return box; },
        addTo: function () { return this; }
    };
}
global.L = {
    tileLayer: makeStub,
    layerGroup: function () { return { addTo: function () {}, addLayer: function () {} }; },
    control: { layers: function () { return { addTo: function () { return this; } }; } }
};
global.document = { documentElement: { getAttribute: function () { return 'dark'; } } };
global.localStorage = { getItem: function () { return null; }, setItem: function () {} };
global.window = global;
global.window.TILE_PROXY = { mode: 'proxy', endpoint: 'api/tile-proxy.php',
    allowed: ['osm', 'osm_hot', 'opentopomap', 'usgs_topo', 'usgs_imagery', 'usgs_imagery_topo', 'custom'] };

var src = fs.readFileSync(process.argv[2], 'utf8');

// (a) No CARTO key: the page bootstrap says so.
global.window.TILE_CONFIG = { has_custom_tile: false, tile_default_basemap: 'user', carto_api_key: '' };
eval(src);
var MP = global.window.MapPrefs;
check('dark mode starts on Dark', MP.getBasemap() === 'dark', MP.getBasemap());
check('hasCartoKey() is false without a key', MP.hasCartoKey() === false);
var d = MP.makeLayer('dark');
check('keyless Dark never uses CARTO', d.url.indexOf('cartocdn') === -1, d.url);
check('keyless Dark is proxied OpenStreetMap', d.url.indexOf('api/tile-proxy.php?provider=osm') === 0, d.url);
check('keyless Dark credits OpenStreetMap only', /OpenStreetMap/.test(d.options.attribution) && !/CARTO/.test(d.options.attribution), d.options.attribution);
d.fire('add');
check('keyless Dark is recoloured when added', /invert/.test(d.box.style.filter || ''), d.box.style.filter);
var l = MP.makeLayer('light');
check('keyless Light never uses CARTO', l.url.indexOf('cartocdn') === -1, l.url);
l.fire('add');
check('keyless Light is recoloured when added', /grayscale/.test(l.box.style.filter || ''), l.box.style.filter);
var s = MP.makeLayer('street');
s.fire('add');
check('Street is never recoloured', !s.box.style.filter, s.box.style.filter);

// (b) A CARTO key, in a fresh copy of the module.
delete global.window.MapPrefs;
global.window.TILE_CONFIG = { has_custom_tile: false, tile_default_basemap: 'user', carto_api_key: 'AbC+/1' };
eval(src);
MP = global.window.MapPrefs;
check('hasCartoKey() is true with a key', MP.hasCartoKey() === true);
var dk = MP.makeLayer('dark');
check('keyed Dark is CARTO Dark Matter at the keyed address',
      dk.url === 'https://basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png?key=AbC%2B%2F1', dk.url);
check('keyed Dark stays direct in proxy mode (CARTO terms)', dk.url.indexOf('api/tile-proxy.php') === -1, dk.url);
check('keyed Dark credits CARTO', /CARTO/.test(dk.options.attribution), dk.options.attribution);
dk.fire('add');
check('keyed Dark is not recoloured', !dk.box.style.filter, dk.box.style.filter);
var lk = MP.makeLayer('light');
check('keyed Light is CARTO Positron at the keyed address',
      lk.url === 'https://basemaps.cartocdn.com/rastertiles/light_all/{z}/{x}/{y}.png?key=AbC%2B%2F1', lk.url);

console.log(out.join('\n'));
JS;
    file_put_contents($harness, $js);
    $raw = (string) @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mpPath) . ' 2>&1');
    @unlink($harness);
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    $seen = 0;
    foreach ($lines as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 2 || ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL')) { continue; }
        $seen++;
        t('map-prefs.js: ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
    }
    t('the node harness ran every layer check', $seen === 15, 'saw ' . $seen . ': ' . substr($raw, 0, 300));
}

// ── 4. CSP ───────────────────────────────────────────────────────────────
$csp = build_csp_policy();
$imgSrc = '';
foreach (explode(';', $csp) as $directive) {
    if (strpos(trim($directive), 'img-src ') === 0) { $imgSrc = ' ' . trim($directive) . ' '; }
}
t('CSP img-src allows the bare keyed CARTO host', strpos($imgSrc, ' https://basemaps.cartocdn.com ') !== false, $imgSrc);

// ── 5. No page builds the keyless CARTO address any more ──────────────────
// Comments are skipped (several explain why the old address is gone): PHP
// comments by the tokenizer, JavaScript comments by the same regex- and
// string-aware stripper the UI audit uses.
require_once $base . '/tools/ui_extract.php';
$offenders = [];
$scan = array_merge(glob($base . '/*.php') ?: [], glob($base . '/assets/js/*.js') ?: [], glob($base . '/inc/*.php') ?: []);
foreach ($scan as $file) {
    $raw = (string) file_get_contents($file);
    if (substr($file, -3) === '.js') {
        $code = ui_js_strip_comments($raw);
    } else {
        $code = '';
        foreach (token_get_all($raw) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { continue; }
                $code .= ($tok[0] === T_INLINE_HTML) ? ui_js_strip_comments($tok[1]) : $tok[1];
            } else {
                $code .= $tok;
            }
        }
    }
    if (preg_match('#\{s\}\.basemaps\.cartocdn\.com|basemaps\.cartocdn\.com/(dark_all|light_all)/#', $code)) {
        $offenders[] = substr($file, strlen($base) + 1);
    }
}
t('no page builds the keyless CARTO address (it draws "API KEY REQUIRED")', $offenders === [], implode(', ', $offenders));

// ── 6. Settings ──────────────────────────────────────────────────────────
$settings = (string) file_get_contents($base . '/settings.php');
t('Settings has the CARTO key field, saved as carto_api_key',
    (bool) preg_match('/id="setCartoApiKey"[^>]*data-key="carto_api_key"/s', $settings));
t('the CARTO key field is treated as a secret in the form',
    (bool) preg_match('/id="setCartoApiKey"[^>]*data-secret="1"/s', $settings));
t('carto_api_key is masked by the settings API', is_secret_setting_key('carto_api_key') === true);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
