<?php
/**
 * test_tile_default_basemap.php — which basemap a map starts on.
 *
 * Until 2026-10-15 an admin-configured tile provider (Settings → Map Settings)
 * was only ever an EXTRA entry in each map's layer switcher, fetched after the
 * map had already drawn its first basemap. Every map therefore started on
 * OpenStreetMap or CARTO, which need the internet, so an install whose own
 * tile server was its only map source (docs/OFFLINE-OPERATION.md section 5)
 * showed blank maps until each user switched layers by hand. The APRS map,
 * the Mesh Console map and five Settings maps did not offer it at all.
 *
 * Now:
 *   - `tile_default_basemap` ('user' | 'configured') says which map everyone
 *     starts on; a user's own choice still wins;
 *   - inc/navbar.php writes the tile settings into the page (window.TILE_CONFIG)
 *     so a map can START on the configured provider without a fetch;
 *   - every map page builds its basemaps through assets/js/map-prefs.js.
 *
 * This test drives the real code: the PHP payload in fresh processes
 * (get_variable() caches the settings table for the life of a process), and
 * the real map-prefs.js under node.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tile-config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Default basemap for everyone ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true($cond, $name, $why = '') { if ($cond) { ok($name); } else { bad($name, $why); } }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The setting's values --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(tile_default_basemap_normalize('configured') === 'configured', "'configured' is kept");
is_true(tile_default_basemap_normalize(' Configured ') === 'configured', 'case and spaces are tolerated');
is_true(tile_default_basemap_normalize('user') === 'user', "'user' is kept");
is_true(tile_default_basemap_normalize('') === 'user', "empty means 'user' (today's behaviour)");
is_true(tile_default_basemap_normalize('satellite') === 'user', "an unknown value is stored as 'user'");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The payload (real settings, a fresh process per scenario) --\n";
// ─────────────────────────────────────────────────────────────────────────

$keys = ['tile_provider', 'tile_server_url', 'tile_api_key', 'tile_mode', 'tile_attribution', 'tile_default_basemap'];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = db_fetch_value("SELECT `value` FROM settings WHERE `name` = ?", [$k]);
}
register_shutdown_function(static function () use ($saved) {
    foreach ($saved as $k => $v) {
        if ($v === false || $v === null) {
            db_query("DELETE FROM settings WHERE `name` = ?", [$k]);
        } else {
            db_query("INSERT INTO settings (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$k, $v]);
        }
    }
});

function set_tile_settings(array $values): void {
    foreach ($values as $k => $v) {
        db_query("INSERT INTO settings (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$k, $v]);
    }
}

/** Run tile_config_payload() + the bootstrap in a new PHP process. */
function payload_in_fresh_process(string $base): array {
    $script = sys_get_temp_dir() . '/tcad_tiledefault_' . getmypid() . '.php';
    file_put_contents($script, '<?php ' .
        'require ' . var_export($base . '/config.php', true) . ';' .
        'require_once ' . var_export($base . '/inc/tile-config.php', true) . ';' .
        '$p = tile_config_payload();' .
        'echo json_encode(["payload" => $p, "bootstrap" => tile_config_client_bootstrap($p)]);');
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $start = strpos($out, '{"payload"');
    $j = $start === false ? null : json_decode(substr($out, $start), true);
    return is_array($j) ? $j : ['error' => $out];
}

set_tile_settings(['tile_provider' => 'custom', 'tile_server_url' => 'http://tiles.example.lan/{z}/{x}/{y}.png',
    'tile_api_key' => '', 'tile_mode' => 'direct', 'tile_attribution' => "\u{00A9} OpenStreetMap contributors",
    'tile_default_basemap' => 'configured']);
$r = payload_in_fresh_process($base);
is_true(($r['payload']['tile_default_basemap'] ?? '') === 'configured',
    "configured provider + 'configured' -> the payload says 'configured'", json_encode($r));
is_true(($r['bootstrap']['tile_url'] ?? '') === 'http://tiles.example.lan/{z}/{x}/{y}.png',
    'the page bootstrap carries the tile URL', json_encode($r['bootstrap'] ?? $r));
is_true(($r['bootstrap']['tile_attribution'] ?? '') === "\u{00A9} OpenStreetMap contributors",
    'the page bootstrap carries the map credit');

set_tile_settings(['tile_default_basemap' => 'user']);
$r = payload_in_fresh_process($base);
is_true(($r['payload']['tile_default_basemap'] ?? '') === 'user', "'user' stays 'user'", json_encode($r));

set_tile_settings(['tile_provider' => '', 'tile_server_url' => '', 'tile_default_basemap' => 'configured']);
$r = payload_in_fresh_process($base);
is_true(($r['payload']['tile_default_basemap'] ?? '') === 'user' && empty($r['payload']['has_custom_tile']),
    "'configured' with no provider configured reads as 'user'", json_encode($r));

set_tile_settings(['tile_provider' => 'osm', 'tile_server_url' => '', 'tile_api_key' => 'k-123',
    'tile_mode' => 'proxy', 'tile_default_basemap' => 'configured']);
$r = payload_in_fresh_process($base);
$proxied = ($r['payload']['tile_effective_mode'] ?? '') === 'proxy';
is_true($proxied, 'an OpenStreetMap provider in proxy mode is proxied', json_encode($r['payload'] ?? $r));
is_true(($r['bootstrap']['tile_api_key'] ?? 'x') === '',
    'the tile API key is NOT written into the page when tiles come through the proxy');
is_true(($r['payload']['tile_api_key'] ?? '') === 'k-123',
    'api/map-config.php still returns it (unchanged contract)');

set_tile_settings(['tile_provider' => 'mapbox_streets', 'tile_api_key' => 'pk.test', 'tile_mode' => 'proxy']);
$r = payload_in_fresh_process($base);
is_true(($r['bootstrap']['tile_effective_mode'] ?? '') === 'direct' && ($r['bootstrap']['tile_api_key'] ?? '') === 'pk.test',
    'a provider fetched directly keeps its key (it is in every tile request anyway)', json_encode($r['bootstrap'] ?? $r));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Wiring --\n";
// ─────────────────────────────────────────────────────────────────────────

$nav = (string) file_get_contents($base . '/inc/navbar.php');
is_true(strpos($nav, 'tile_config_client_bootstrap(tile_config_payload())') !== false,
    'inc/navbar.php builds window.TILE_CONFIG from the shared payload');
is_true(preg_match('/window\.TILE_CONFIG\s*=\s*<\?php echo json_encode\(\$_tile_config_js, JSON_HEX_TAG/', $nav) === 1,
    'it is written with JSON_HEX_TAG, so admin text can never close the script element');
is_true(preg_match("/if \(!empty\(\\\$_SESSION\['user_id'\]\)\) \{\s*try \{\s*require_once __DIR__ \. '\/tile-config\.php';/", $nav) === 1,
    'it is written for signed-in pages only');

$mc = (string) file_get_contents($base . '/api/map-config.php');
is_true(strpos($mc, '$tile = tile_config_payload();') !== false,
    'api/map-config.php uses the same payload, so the two cannot disagree');

$ca = (string) file_get_contents($base . '/api/config-admin.php');
is_true(preg_match("/\\\$key === 'tile_default_basemap'\\)\\s*\\{[^}]*tile_default_basemap_normalize\\(/s", $ca) === 1,
    'the settings save normalises tile_default_basemap');

$sp = (string) file_get_contents($base . '/settings.php');
$fs = strpos($sp, 'id="tileProviderForm"');
$fe = $fs === false ? false : strpos($sp, '</form>', $fs);
$f  = strpos($sp, 'data-key="tile_default_basemap"');
is_true($f !== false && $fs !== false && $fe !== false && $f > $fs && $f < $fe,
    'Map Settings has the "Default basemap for everyone" field inside the tile provider form');
is_true(strpos($sp, '<option value="user">') !== false && strpos($sp, '<option value="configured">') !== false,
    'it offers both values');
is_true(substr_count($sp, '<option value="" selected>Install default</option>') === 2,
    'Display Preferences offers "Install default" for both themes, selected by default');

$off = (string) file_get_contents($base . '/docs/OFFLINE-OPERATION.md');
is_true(strpos($off, 'Default basemap for everyone: **The provider configured above**') !== false,
    'the offline guide tells readers to make the configured provider the default');
is_true(strpos($off, 'Settings → Map Settings') === false && strpos($off, 'Settings → Tile Providers') !== false,
    'the offline guide names the right menu (the tile settings are under Tile Providers)');
$help = (string) file_get_contents($base . '/help.php');
is_true(strpos($help, '<strong>Default basemap for everyone</strong>') !== false,
    'the help page explains the setting');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Every map builds its basemaps through map-prefs.js --\n";
// ─────────────────────────────────────────────────────────────────────────

// A literal OpenStreetMap tile layer is allowed only as the fallback for a
// page where map-prefs.js did not load: within the same few lines as a
// MapPrefs check. A new map that hard-codes OpenStreetMap fails here.
// public-board.php is exempt: anonymous visitors, its own tile setting.
$pages = array_merge(glob($base . '/*.php') ?: [], glob($base . '/assets/js/*.js') ?: [], glob($base . '/inc/*.php') ?: []);
$offenders = [];
foreach ($pages as $file) {
    $rel = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $base)) + 1);
    if ($rel === 'public-board.php' || $rel === 'assets/js/map-prefs.js') { continue; }
    $lines = file($file);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'tile.openstreetmap.org') === false) { continue; }
        $window = implode('', array_slice($lines, max(0, $i - 3), 4));
        if (strpos($window, 'tileLayer(') === false) { continue; }    // a URL in a list or help text, not a layer
        // A ternary fallback: `MapPrefs ? MapPrefs.makeLayer(...) : L.tileLayer(...)`.
        if (strpos($window, 'MapPrefs') !== false || preg_match('/\bMP\s*\?/', $window)) { continue; }
        // An else-branch fallback: `if (window.MapPrefs) {...} else { L.tileLayer(...) }`.
        $near = implode('', array_slice($lines, max(0, $i - 2), 3));
        $wide = implode('', array_slice($lines, max(0, $i - 15), 16));
        if (preg_match('/\belse\b/', $near) && strpos($wide, 'MapPrefs') !== false) { continue; }
        $offenders[] = $rel . ':' . ($i + 1);
    }
}
is_true($offenders === [], 'no map builds an OpenStreetMap layer except as the map-prefs.js fallback',
    implode(', ', $offenders));

$must = [
    'aprs-map.php'              => ["assets/js/map-prefs.js?v=", "getInstallDefault()", "makeLayer('custom')"],
    'mesh-console.php'          => ["assets/js/map-prefs.js?v="],
    'assets/js/mesh-console.js' => ['MapPrefs.addDefaultBasemap(meshMap)'],
    'assets/js/units.js'        => ["MP.makeLayer('custom')", 'MP.getBasemap()'],
    'assets/js/facilities.js'   => ["MP.makeLayer('custom')", 'MP.getBasemap()'],
    'situation.php'             => ["MP.makeLayer('custom')", 'window.MapPrefs.getBasemap() : (isDark'],
    'assets/js/app.js'          => ["window.MapPrefs.makeLayer('custom')", 'window.MapPrefs.getBasemap()'],
    'assets/js/config.js'       => ['MapPrefs.addDefaultBasemap(warnLocMap)', 'MapPrefs.addDefaultBasemap(_gfDrawMap)',
                                    "MapPrefs.makeLayer('street') : L.tileLayer",
                                    'MapPrefs.addDefaultBasemap(map)', 'MapPrefs.addDefaultBasemap(_mapDefaultsMap)',
                                    "getInstallDefault() === 'configured'"],
];
foreach ($must as $rel => $needles) {
    $src = (string) file_get_contents($base . '/' . $rel);
    foreach ($needles as $n) {
        is_true(strpos($src, $n) !== false, "$rel uses the shared basemap ($n)");
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. The real map-prefs.js under node --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_tiledefault_harness_' . getmypid() . '.js';
    $mpPath  = str_replace('\\', '/', $base . '/assets/js/map-prefs.js');
    $js = <<<'JS'
// Drive the REAL assets/js/map-prefs.js with only browser objects stubbed.
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var store = {};
var theme = 'light';
var fetches = 0;
var controls = [];
global.L = {
    tileLayer: function (url, opts) { return { url: url, options: opts, addTo: function () { return this; } }; },
    layerGroup: function () { return { addTo: function () { return this; }, addLayer: function () {} }; },
    control: { layers: function (base, over) {
        var c = { base: base, added: [], addTo: function () { return this; },
                  addBaseLayer: function (l, n) { this.added.push(n); } };
        controls.push(c); return c; } }
};
global.document = { documentElement: { getAttribute: function () { return theme; } } };
global.localStorage = {
    getItem: function (k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem: function (k, v) { store[k] = String(v); }
};
global.window = global;
global.fetch = function () { fetches++; return Promise.resolve({ ok: true, json: function () { return Promise.resolve(null); } }); };

function load(cfg) {
    store = {}; theme = 'light'; fetches = 0; controls = [];
    if (cfg === undefined) { delete global.window.TILE_CONFIG; } else { global.window.TILE_CONFIG = cfg; }
    delete global.window.TILE_PROXY;
    delete global.window.MapPrefs;
    eval(src);
    return global.window.MapPrefs;
}
function cfgWith(def, extra) {
    var c = { has_custom_tile: true, tile_provider: 'custom', tile_url: 'http://tiles.example.lan/{z}/{x}/{y}.png',
              tile_api_key: '', tile_effective_mode: 'direct', tile_proxy_url: '',
              tile_attribution: '© OpenStreetMap contributors', tile_default_basemap: def };
    for (var k in (extra || {})) { c[k] = extra[k]; }
    return c;
}
var fakeMap = { on: function () {}, hasLayer: function () { return false; } };

(async function () {
    // The case that motivated this: an offline install whose own tile server
    // is the install default. The FIRST basemap must be that server.
    var MP = load(cfgWith('configured'));
    check('install default "configured" -> the map starts on the configured provider', MP.getBasemap() === 'custom', MP.getBasemap());
    var first = MP.createTileLayer();
    check('the first tile layer is the configured server, built synchronously', first.url === 'http://tiles.example.lan/{z}/{x}/{y}.png', first.url);
    check('...with its map credit', first.options.attribution === '© OpenStreetMap contributors', first.options.attribution);
    check('no fetch was needed to get there', fetches === 0, String(fetches));
    check('getInstallDefault() reports it', MP.getInstallDefault() === 'configured');
    await MP.init();
    check('init() resolves from the page bootstrap without fetching', fetches === 0, String(fetches));

    // A user's own choice wins.
    MP = load(cfgWith('configured'));
    store.ticketsMapPrefs = JSON.stringify({ basemapLight: 'street' });
    check("a user who chose Street keeps Street", MP.getBasemap() === 'street', MP.getBasemap());
    MP.setBasemap('light', '');
    check("clearing the choice ('Install default') returns to the install default", MP.getBasemap() === 'custom', MP.getBasemap());
    check('getUserChoice() is empty after clearing', MP.getUserChoice() === '');

    // Today's behaviour is unchanged by default.
    MP = load(cfgWith('user'));
    check("install default 'user' -> Street in light mode", MP.getBasemap() === 'street', MP.getBasemap());
    theme = 'dark';
    check("...and Dark in dark mode", MP.getBasemap() === 'dark', MP.getBasemap());
    check('the configured provider is still offered', MP.getCustomLabel() === 'Configured');

    MP = load(cfgWith('configured', { has_custom_tile: false, tile_url: '' }));
    check("'configured' with no provider falls back to Street", MP.getBasemap() === 'street', MP.getBasemap());

    // A stale choice of a provider that no longer exists falls back safely.
    MP = load(cfgWith('user', { has_custom_tile: false, tile_url: '' }));
    store.ticketsMapPrefs = JSON.stringify({ basemapLight: 'custom' });
    check('a saved choice of a removed provider falls back to Street', MP.getBasemap() === 'street', MP.getBasemap());

    // Proxy mode: the bootstrap's proxy URL is used.
    MP = load(cfgWith('configured', { tile_effective_mode: 'proxy', tile_proxy_url: 'api/tile-proxy.php?provider=custom&z={z}&x={x}&y={y}' }));
    check('in proxy mode the configured map comes through the proxy', MP.createTileLayer().url.indexOf('api/tile-proxy.php') === 0);

    // The layer switcher lists the configured provider exactly once.
    MP = load(cfgWith('configured'));
    var ctl = MP.addLayerControl(fakeMap, { includeWeather: false });
    await MP.init();
    await Promise.resolve();
    check('the layer switcher lists the configured provider', !!(controls[0] && controls[0].base['Configured']));
    check('...exactly once (the late fold-in does not add it again)', controls[0] && controls[0].added.length === 0, JSON.stringify(controls[0] && controls[0].added));

    // Pages without the navbar still fetch, as before.
    MP = load(undefined);
    await MP.init();
    check('with no page bootstrap, map-prefs.js still fetches api/map-config.php', fetches === 1, String(fetches));
    check("...and starts on Street", MP.getBasemap() === 'street');

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
        is_true($seen >= 19, 'every JS scenario reported', (string) $seen);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
