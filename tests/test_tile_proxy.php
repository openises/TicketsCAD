<?php
/**
 * test_tile_proxy.php — the server-side map tile proxy.
 *
 * The defect this guards against is specific and was live for months: the
 * `tile_mode` setting was written by three code paths, defaulted to 'proxy' on
 * every install, was described in Settings as "route through server cache —
 * recommended", was surfaced by api/map-config.php… and was read by NOTHING.
 * `git log --all -S tile_mode -- assets/` is empty for the whole history, so
 * there was never a consumer to remove. The browser fetched tiles straight
 * from the provider in both "modes".
 *
 * So the first and most important thing tested here is that the setting now
 * REACHES THE CODE THAT BUILDS THE TILE URL — and it is tested by running the
 * real JS function (via node, when available) against the real
 * window.TILE_PROXY shape that inc/navbar.php emits, not by asserting that
 * some string appears in a file. A test that only greps for wiring would have
 * passed for the entire time the feature was broken.
 *
 * Covered:
 *   1. The mode reaches the URL builder — PHP side (resolve_tile_config)
 *   2. The mode reaches the URL builder — JS side (real MapPrefs, real node)
 *   3. Providers whose terms forbid proxying are refused, in every layer
 *   4. SSRF: no client-supplied URL, no out-of-grid z/x/y, no path traversal
 *   5. Cache: size cap + LRU eviction, and the free-space reserve
 *   6. Attribution survives proxying (it is an obligation, not a URL detail)
 */

require_once __DIR__ . '/../inc/tile-proxy.php';
require_once __DIR__ . '/../inc/tile-config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Server-side tile proxy tests ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The setting reaches the URL-building code (PHP) --\n";
// ─────────────────────────────────────────────────────────────────────────

// THE regression. resolve_tile_config() used to take $mode and copy it to the
// output without ever branching on it. Now the two modes must produce
// different URLs for the same provider.
$proxyCfg  = resolve_tile_config('osm', '', '', 'proxy',  30);
$directCfg = resolve_tile_config('osm', '', '', 'direct', 30);

is_true($proxyCfg['tile_effective_mode'] === 'proxy',
    'proxy mode + permitted provider => effective mode proxy', $proxyCfg['tile_effective_mode']);
is_true($directCfg['tile_effective_mode'] === 'direct',
    'direct mode => effective mode direct', $directCfg['tile_effective_mode']);
is_true(strpos((string) $proxyCfg['tile_proxy_url'], 'api/tile-proxy.php') === 0,
    'proxy mode yields a same-origin proxy URL', (string) $proxyCfg['tile_proxy_url']);
is_true($directCfg['tile_proxy_url'] === '',
    'direct mode yields no proxy URL', (string) $directCfg['tile_proxy_url']);
is_true($proxyCfg['tile_proxy_url'] !== $directCfg['tile_proxy_url'],
    'the two modes actually differ (the bug was that they did not)');

// The proxy URL must carry Leaflet's placeholders through untouched, or the
// layer renders one tile and stops.
foreach (['{z}', '{x}', '{y}'] as $ph) {
    is_true(strpos((string) $proxyCfg['tile_proxy_url'], $ph) !== false,
        "proxy URL keeps the $ph placeholder");
}
// ...and must NOT carry {s}: our endpoint has no subdomains.
is_true(strpos((string) $proxyCfg['tile_proxy_url'], '{s}') === false,
    'proxy URL has no {s} placeholder');

// The configured mode is still reported unchanged, so existing consumers of
// tile_mode keep the value they always got.
is_true($proxyCfg['tile_mode'] === 'proxy', 'configured tile_mode still surfaced verbatim');

// An install that never set the key at all: '' must mean the shipped default
// (proxy), not "off". Phase 41 has been seeding 'proxy' since 2026.
is_true(tile_proxy_effective_mode('', 'osm') === 'proxy',
    'empty tile_mode falls back to the install default (proxy)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Providers whose terms forbid proxying are refused --\n";
// ─────────────────────────────────────────────────────────────────────────

$forbidden = ['mapbox', 'google_street', 'google_sat', 'google_hybrid',
              'bing_road', 'bing_aerial', 'esri_street', 'esri_sat', 'esri_topo',
              'cartodb_positron', 'cartodb_dark'];
$allowed   = ['osm', 'osm_hot', 'opentopomap', 'usgs_topo', 'usgs_imagery',
              'usgs_imagery_topo', 'custom'];

$allRefused = true;
foreach ($forbidden as $p) {
    $v = tile_proxy_verdict($p);
    if ($v['allowed'] !== false) { $allRefused = false; bad("verdict refuses $p"); }
    // Refusal must survive the mode setting: an admin turning proxy on must
    // not be able to make us fetch these.
    if (tile_proxy_effective_mode('proxy', $p) !== 'direct') {
        $allRefused = false; bad("proxy mode still falls back to direct for $p");
    }
    // And the URL builder must refuse independently of the endpoint's check.
    if (tile_proxy_upstream_url($p, 5, 10, 12, 'KEY') !== null) {
        $allRefused = false; bad("upstream URL builder refuses $p");
    }
}
is_true($allRefused, 'all ' . count($forbidden) . ' terms-forbidden providers refused at all 3 layers');

$allAllowed = true;
foreach ($allowed as $p) {
    $v = tile_proxy_verdict($p);
    if ($v['allowed'] !== true) { $allAllowed = false; bad("verdict permits $p"); }
}
is_true($allAllowed, 'all ' . count($allowed) . ' permitted providers allowed');

// An unknown identifier must be refused, not waved through. "No policy on
// record" means nobody checked those terms.
$unknown = tile_proxy_verdict('some_new_provider');
is_true($unknown['allowed'] === false, 'unknown provider is refused by default');
is_true(tile_proxy_upstream_url('some_new_provider', 1, 0, 0) === null,
    'unknown provider builds no upstream URL');

// Every entry must carry a source, so a future maintainer can re-check terms.
$missingSource = [];
foreach (tile_proxy_policy() as $key => $p) {
    if (trim((string) $p['source']) === '') { $missingSource[] = $key; }
    if (trim((string) $p['attribution']) === '' && $key !== 'custom') { $missingSource[] = $key . '(attr)'; }
}
is_true($missingSource === [], 'every provider records a policy source + attribution',
    implode(',', $missingSource));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. SSRF: the endpoint never accepts a URL --\n";
// ─────────────────────────────────────────────────────────────────────────

// Adversarial provider identifiers. None may produce a URL, and none may
// escape the cache directory.
$evilProviders = [
    'http://evil.example.com/a.png',
    '//evil.example.com/a.png',
    '../../../../etc/passwd',
    'osm/../mapbox',
    'osm%2F..%2Fmapbox',
    'file:///etc/passwd',
    'osm ',
    'OSM',                       // case matters: the allowlist is lower-case
    '',
    str_repeat('a', 200),
];
$evilOk = true;
foreach ($evilProviders as $p) {
    if (tile_proxy_valid_provider_id($p) && !in_array($p, $allowed, true)) {
        $evilOk = false; bad('rejects provider id: ' . substr($p, 0, 40));
    }
    if (tile_proxy_upstream_url($p, 1, 0, 0) !== null) {
        $evilOk = false; bad('builds no URL for: ' . substr($p, 0, 40));
    }
}
is_true($evilOk, 'all ' . count($evilProviders) . ' adversarial provider ids refused');

// The HTTP client itself refuses non-http(s) schemes, so even a future caller
// that skipped the policy check cannot reach the filesystem or another service.
foreach (['file:///etc/passwd', 'gopher://x/', 'ftp://x/a', 'javascript:alert(1)',
          'data:text/html,x', '//evil.example.com/x'] as $u) {
    $r = tile_http_get($u, []);
    if ($r['status'] !== 0 || $r['error'] !== 'refused non-http(s) url') {
        bad('tile_http_get refuses ' . $u, 'status=' . $r['status'] . ' err=' . $r['error']);
    }
}
ok('tile_http_get refuses every non-http(s) scheme tried');

// Templates that would smuggle a scheme or split a request line.
$badTemplates = ['javascript:alert(1)', 'data:image/png;base64,AAA', '//evil.example.com/{z}.png',
                 "http://evil\r\nX-Injected: 1/{z}.png", 'ftp://x/{z}.png', '', '   '];
$tplOk = true;
foreach ($badTemplates as $t) {
    if (tile_proxy_sanitize_template($t) !== '') {
        $tplOk = false; bad('template rejected: ' . substr(str_replace(["\r", "\n"], '_', $t), 0, 40));
    }
}
is_true($tplOk, 'all ' . count($badTemplates) . ' hostile URL templates rejected');
is_true(tile_proxy_sanitize_template('https://tiles.example.org/{z}/{x}/{y}.png') !== '',
    'a legitimate https template is accepted');

// Out-of-grid tile coordinates. z=2 has a 4x4 grid, so x=4 does not exist.
$zxyCases = [
    [ 0,  0,  0, 19, true,  'z0 origin tile'],
    [12, 1023, 1479, 19, true,  'a real z12 tile'],
    [19, 0, 0, 19, true,  'z at the provider maximum'],
    [20, 0, 0, 19, false, 'z above the provider maximum'],
    [-1, 0, 0, 19, false, 'negative zoom'],
    [ 2, 4, 0, 19, false, 'x outside the 2^z grid'],
    [ 2, 0, 4, 19, false, 'y outside the 2^z grid'],
    [ 2, -1, 0, 19, false, 'negative x'],
    [ 0, 1, 0, 19, false, 'x=1 at z0 (grid is 1x1)'],
    [23, 0, 0, 22, false, 'z beyond the absolute ceiling'],
];
$zxyOk = true;
foreach ($zxyCases as $c) {
    if (tile_proxy_valid_zxy($c[0], $c[1], $c[2], $c[3]) !== $c[4]) {
        $zxyOk = false; bad('z/x/y bound: ' . $c[5]);
    }
}
is_true($zxyOk, 'all ' . count($zxyCases) . ' z/x/y range cases correct');

// Out-of-range coordinates must also stop the URL builder, not just the endpoint.
is_true(tile_proxy_upstream_url('osm', 2, 4, 0) === null,
    'out-of-grid coordinates build no upstream URL');
is_true(tile_proxy_upstream_url('osm', 25, 0, 0) === null,
    'zoom beyond provider max builds no upstream URL');

// The endpoint source itself must refuse URL-ish parameters and must not have
// a path where a $_GET value becomes the fetch target.
$epSrc = (string) file_get_contents($base . '/api/tile-proxy.php');
is_true(strpos($epSrc, "'url', 'u', 'tile_url'") !== false,
    'endpoint explicitly rejects URL-bearing parameters');
is_true(preg_match('/tile_http_get\s*\(\s*\$_(GET|POST|REQUEST)/', $epSrc) !== 1,
    'endpoint never fetches a request-supplied value');
is_true(preg_match('/tile_proxy_upstream_url\s*\(\s*\$provider\s*,\s*\$z\s*,\s*\$x\s*,\s*\$y/', $epSrc) === 1,
    'endpoint builds the upstream URL server-side from provider + validated ints');
is_true(strpos($epSrc, "require_once __DIR__ . '/auth.php'") !== false,
    'endpoint requires an authenticated session');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Cache bounds: size cap, LRU eviction, free-space reserve --\n";
// ─────────────────────────────────────────────────────────────────────────

// Free-space verdict, including the exact-floor boundary. The floor is space
// that must REMAIN, so landing exactly on it is within policy.
$spaceCases = [
    [2000000, 50000, 1048576, true,  'comfortable free space'],
    [1098576, 50000, 1048576, true,  'lands exactly on the reserve'],
    [1098575, 50000, 1048576, false, 'one byte below the reserve'],
    [1000000, 50000, 1048576, false, 'below the reserve'],
    [5000000, 50000,       0, true,  'no reserve configured'],
];
$spaceOk = true;
foreach ($spaceCases as $c) {
    $v = tile_cache_space_verdict($c[0], $c[1], $c[2]);
    if ($v['ok'] !== $c[3]) { $spaceOk = false; bad('space verdict: ' . $c[4]); }
}
is_true($spaceOk, 'all ' . count($spaceCases) . ' free-space reserve cases correct');

// Undeterminable free space must NOT cache. Opposite of the backup subsystem's
// choice, and deliberately so: skipping a cache write costs a re-fetch, while
// filling a dispatch server's disk costs an outage.
$undet = tile_cache_space_verdict(null, 50000, 1048576);
is_true($undet['ok'] === false && $undet['undetermined'] === true,
    'unknown free space => do not write (fails safe toward the disk)');

// Eviction plan: least-recently-used first, stopping as soon as it is under.
$entries = [
    '/c/newest.tile' => ['size' => 100, 'mtime' => 5000],
    '/c/oldest.tile' => ['size' => 100, 'mtime' => 1000],
    '/c/middle.tile' => ['size' => 100, 'mtime' => 3000],
];
$plan = tile_cache_eviction_plan($entries, 300, 150);
is_true($plan['paths'] === ['/c/oldest.tile', '/c/middle.tile'],
    'eviction removes least-recently-used first', implode(',', $plan['paths']));
is_true($plan['remaining'] === 100, 'eviction stops once under target', (string) $plan['remaining']);
is_true(tile_cache_eviction_plan($entries, 100, 150)['paths'] === [],
    'nothing evicted when already under the cap');
is_true(tile_cache_eviction_plan([], 0, 150)['paths'] === [], 'empty cache evicts nothing');

// Now the REAL eviction against a REAL directory — not the plan in isolation.
$tmp = sys_get_temp_dir() . '/tcad_tile_cache_test_' . getmypid();
@mkdir($tmp . '/osm/10/511', 0755, true);
$made = 0;
for ($i = 0; $i < 10; $i++) {
    $f = $tmp . '/osm/10/511/' . $i . '.tile';
    if (@file_put_contents($f, str_repeat('x', 10240)) !== false) {   // 10 KB each
        @file_put_contents($f . '.meta', json_encode(['expires' => time() + 3600, 'ctype' => 'image/png']));
        // Stagger mtimes so LRU order is well defined.
        @touch($f, time() - (100 - $i));
        $made++;
    }
}
if ($made !== 10) {
    echo "SKIP: could not create the temp cache fixture (filesystem?) — cache eviction not exercised\n";
} else {
    $before = tile_cache_usage($tmp);
    is_true($before['files'] === 10 && $before['bytes'] === 102400,
        'cache usage counts real files and bytes',
        $before['files'] . ' files / ' . $before['bytes'] . ' bytes');

    // Cap at 50 KB; target defaults to 85% of it, so ~42 KB must remain.
    $ev = tile_cache_enforce_cap($tmp, 51200);
    $after = tile_cache_usage($tmp);
    is_true($ev['evicted'] > 0, 'enforcing the cap actually evicted files', (string) $ev['evicted']);
    is_true($after['bytes'] <= 51200, 'cache is at or under the cap afterwards',
        (string) $after['bytes']);
    is_true($after['bytes'] < $before['bytes'], 'cache shrank');
    // The oldest files must be the ones gone.
    is_true(!file_exists($tmp . '/osm/10/511/0.tile'), 'the least-recently-used tile was evicted');
    is_true(file_exists($tmp . '/osm/10/511/9.tile'), 'the most-recently-used tile was kept');
    // Sidecar metadata must go with its tile, or the cache leaks orphans.
    is_true(!file_exists($tmp . '/osm/10/511/0.tile.meta'),
        'eviction removes the sidecar metadata too');

    // A zero/negative cap must be treated as "no cap configured", not as
    // "evict everything" — that would empty the cache on a bad setting.
    $noCap = tile_cache_enforce_cap($tmp, 0);
    is_true($noCap['evicted'] === 0, 'a zero cap evicts nothing');

    // Clean up.
    foreach (glob($tmp . '/osm/10/511/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp . '/osm/10/511'); @rmdir($tmp . '/osm/10'); @rmdir($tmp . '/osm'); @rmdir($tmp);
}

// TTL from upstream headers, with both clamps.
is_true(tile_cache_ttl_from_headers(['cache-control' => 'max-age=86400'], 3600, 2592000) === 86400,
    'upstream max-age is honoured');
is_true(tile_cache_ttl_from_headers(['cache-control' => 'max-age=60'], 3600, 2592000) === TILE_CACHE_MIN_TTL,
    'a very short upstream TTL is floored (else the cache is pointless)');
is_true(tile_cache_ttl_from_headers(['cache-control' => 'max-age=999999999'], 3600, 2592000) === 2592000,
    'upstream TTL is capped by the admin cache-days ceiling');
is_true(tile_cache_ttl_from_headers([], 3600, 2592000) === 3600,
    'no upstream caching headers falls back to the default');
is_true(tile_cache_ttl_from_headers(['cache-control' => 'no-cache'], 3600, 2592000) >= TILE_CACHE_MIN_TTL,
    'no-cache still floors at the minimum rather than disabling the cache');

// Cache paths are built from validated pieces only.
$p = str_replace('\\', '/', tile_cache_path('osm', 12, 1023, 1479));
is_true(substr($p, -strlen('/osm/12/1023/1479.tile')) === '/osm/12/1023/1479.tile',
    'cache path is provider/z/x/y', $p);
is_true(strpos($p, '..') === false, 'cache path contains no traversal');

// THE cache-location property, asserted rather than assumed: the tile cache
// must live OUTSIDE the application directory. The web root is the app root on
// a documented install, and both .htaccess and the nginx hardening config keep
// cache/ reachable — so a tile cache in there would let anyone read which map
// areas this install has viewed, without logging in. That is precisely what
// proxy mode exists to prevent, so putting it back would be self-defeating.
$appRoot = str_replace('\\', '/', realpath($base));
$cacheRoot = str_replace('\\', '/', tile_cache_dir());
is_true(strpos($cacheRoot, $appRoot . '/') !== 0,
    'tile cache is OUTSIDE the application/web root', $cacheRoot);
is_true(strpos($cacheRoot, 'tile-cache') !== false,
    'tile cache directory is named tile-cache', $cacheRoot);

// Docker must persist it on a volume mounted outside the DocumentRoot, or a
// rebuild both re-exposes nothing and re-fetches everything at once.
$composeSrc = (string) @file_get_contents($base . '/docker-compose.yml');
is_true(strpos($composeSrc, 'app_tile_cache:/var/www/tile-cache') !== false,
    'docker mounts the tile cache outside /var/www/html');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Identifying ourselves upstream (OSM blocks generic agents) --\n";
// ─────────────────────────────────────────────────────────────────────────

$ua = tile_proxy_user_agent('', 'cad.example.org');
is_true(strpos($ua, 'TicketsCAD') !== false, 'default User-Agent names the application', $ua);
is_true(strpos($ua, 'cad.example.org') !== false, 'default User-Agent names the install host', $ua);
is_true(!tile_proxy_user_agent_is_generic($ua), 'default User-Agent is not generic');

// A useless override must be replaced, not obeyed: OSM's policy names generic
// agents as grounds for blocking, and blocking arrives without notice.
foreach (['curl/8.1.2', 'Wget/1.21', 'python-requests/2.31', 'PHP/8.2', '', 'x'] as $genericUa) {
    if (!tile_proxy_user_agent_is_generic($genericUa)) {
        bad('detects generic User-Agent: ' . $genericUa);
    }
}
ok('generic User-Agents are detected and replaced');
is_true(tile_proxy_user_agent('Metro ARES CAD (dispatch@example.org)', 'h') ===
        'Metro ARES CAD (dispatch@example.org)',
    'a genuine custom User-Agent is respected');

// Referer: OSM expects one on web-page tile requests, and proxying is exactly
// what removes the browser's.
is_true(tile_proxy_referer('cad.example.org') === 'https://cad.example.org/',
    'a Referer is synthesised from the install host');
is_true(tile_proxy_referer('') === '', 'no host => no Referer rather than a bogus one');
is_true(tile_proxy_referer("evil\r\nX-Injected: 1") === '',
    'a header-injecting host is rejected');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. The mode reaches the real JS URL builder --\n";
// ─────────────────────────────────────────────────────────────────────────

// Static wiring first: navbar must emit the global synchronously. An async
// answer would arrive after the first screenful of tiles had already gone to
// the provider, which is the disclosure proxy mode exists to prevent.
$navSrc = (string) file_get_contents($base . '/inc/navbar.php');
is_true(strpos($navSrc, 'window.TILE_PROXY') !== false,
    'navbar.php injects window.TILE_PROXY server-side');
is_true(strpos($navSrc, 'tile_proxy_policy()') !== false,
    'navbar builds the allow-list from the real policy, not a copy');

$mpSrc = (string) file_get_contents($base . '/assets/js/map-prefs.js');
is_true(strpos($mpSrc, 'window.TILE_PROXY') !== false,
    'map-prefs.js reads window.TILE_PROXY (this is the wiring that never existed)');
// Every basemap must declare which provider its tiles come from, or the
// policy cannot be applied to it.
$defBlock = substr($mpSrc, strpos($mpSrc, 'var TILE_DEFS'), 2600);
$provCount = preg_match_all('/provider:\s*\'/', $defBlock);
is_true($provCount >= 5, 'every built-in basemap declares its provider id', (string) $provCount);
// And no layer may still be built from the raw def URL, bypassing the decision.
is_true(preg_match('/buildLayer\(\s*def\.url/', $mpSrc) !== 1,
    'no basemap is still constructed straight from def.url (bypassing the mode)');

$appSrc = (string) file_get_contents($base . '/assets/js/app.js');
is_true(strpos($appSrc, 'MapPrefs.tileUrlFor') !== false,
    'the dashboard map routes its basemaps through the same decision');

// Now the real thing: execute map-prefs.js and call the actual function.
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_tileproxy_harness_' . getmypid() . '.js';
    $mpPath  = str_replace('\\', '/', $base . '/assets/js/map-prefs.js');
    $js = <<<JS
// Drive the REAL assets/js/map-prefs.js. Stub only the browser objects it
// touches, so the logic under test is production code, not a re-implementation.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var built = [];
global.L = {
    tileLayer: function (url, opts) { built.push({url: url, opts: opts}); return {url: url, options: opts}; },
    layerGroup: function () { return {addTo: function () {}, addLayer: function () {}}; },
    control: { layers: function () { return {addTo: function () { return this; }}; } }
};
global.document = { documentElement: { getAttribute: function () { return 'light'; } } };
global.localStorage = { getItem: function () { return null; }, setItem: function () {} };
global.window = global;

// Exactly the shape inc/navbar.php emits for a proxy-mode install.
global.window.TILE_PROXY = {
    mode: 'proxy',
    endpoint: 'api/tile-proxy.php',
    allowed: ['osm', 'osm_hot', 'opentopomap', 'usgs_topo', 'usgs_imagery', 'usgs_imagery_topo', 'custom']
};

eval(fs.readFileSync(process.argv[2], 'utf8'));

var MP = global.window.MapPrefs;
check('MapPrefs loaded', !!MP);

// THE regression, executed: OSM is permitted, so proxy mode must produce a
// same-origin URL rather than openstreetmap.org.
var osm = MP.tileUrlFor('osm', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
check('proxy mode routes OSM to the local endpoint', osm.indexOf('api/tile-proxy.php') === 0, osm);
check('proxied OSM URL never names the provider host', osm.indexOf('openstreetmap.org') === -1, osm);
check('proxied URL keeps {z}/{x}/{y}', /\{z\}/.test(osm) && /\{x\}/.test(osm) && /\{y\}/.test(osm), osm);

// A forbidden provider must stay direct even with proxy mode on.
var esriDirect = 'https://server.arcgisonline.com/x/{z}/{y}/{x}';
var esri = MP.tileUrlFor('esri_sat', esriDirect);
check('terms-forbidden provider stays direct in proxy mode', esri === esriDirect, esri);
var carto = MP.tileUrlFor('cartodb_dark', 'https://basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png?key={key}');
check('CARTO stays direct in proxy mode', carto.indexOf('cartocdn.com') !== -1, carto);

// isProxied must agree with tileUrlFor.
check('isProxied true for a permitted provider', MP.isProxied('osm') === true);
check('isProxied false for a forbidden provider', MP.isProxied('mapbox') === false);
check('isProxied false for an unknown provider', MP.isProxied('nonesuch') === false);

// Built layers: URL swapped, attribution preserved. Proxying moves where the
// bytes come from; it does not move the obligation to credit the map's authors.
var streetLayer = MP.makeLayer('street');
check('street layer built through the proxy', streetLayer.url.indexOf('api/tile-proxy.php') === 0, streetLayer.url);
check('street layer keeps its attribution when proxied',
      /OpenStreetMap/.test(streetLayer.options.attribution || ''), streetLayer.options.attribution);
check('proxied layer drops the meaningless subdomains option',
      streetLayer.options.subdomains === undefined);

var darkLayer = MP.makeLayer('dark');
check('dark layer stays direct (CARTO terms)', darkLayer.url.indexOf('cartocdn.com') !== -1, darkLayer.url);
check('dark layer keeps its attribution', /CARTO/.test(darkLayer.options.attribution || ''),
      darkLayer.options.attribution);

var terrainLayer = MP.makeLayer('terrain');
check('terrain layer proxied (OpenTopoMap permits it)',
      terrainLayer.url.indexOf('api/tile-proxy.php') === 0, terrainLayer.url);
check('terrain layer keeps CC-BY-SA attribution',
      /OpenTopoMap/.test(terrainLayer.options.attribution || ''), terrainLayer.options.attribution);

// ── Now DIRECT mode: the same code must produce the provider's own URL. ──
global.window.TILE_PROXY = { mode: 'direct', endpoint: 'api/tile-proxy.php', allowed: ['osm'] };
var osmDirect = MP.tileUrlFor('osm', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
check('direct mode returns the provider URL', osmDirect.indexOf('openstreetmap.org') !== -1, osmDirect);
check('direct mode does not use the proxy', osmDirect.indexOf('api/tile-proxy.php') === -1, osmDirect);
var streetDirect = MP.makeLayer('street');
check('direct-mode layer keeps attribution too',
      /OpenStreetMap/.test(streetDirect.options.attribution || ''), streetDirect.options.attribution);

// ── And with the global absent entirely (a page that missed navbar): must
//    degrade to direct, never to a broken URL. ──
delete global.window.TILE_PROXY;
var noCfg = MP.tileUrlFor('osm', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
check('missing TILE_PROXY global degrades to direct', noCfg.indexOf('openstreetmap.org') !== -1, noCfg);

console.log(out.join('\\n'));
JS;
    file_put_contents($harness, $js);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mpPath) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        bad('node harness ran map-prefs.js', trim((string) $raw));
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2) { continue; }
            if ($parts[0] === 'PASS') { ok('[js] ' . $parts[1]); }
            else { bad('[js] ' . $parts[1], $parts[2] ?? ''); }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. Settings + migration wiring --\n";
// ─────────────────────────────────────────────────────────────────────────

$mig = $base . '/sql/run_phase130_tile_proxy_cache.php';
is_true(is_file($mig), 'the cache-settings migration exists');
if (is_file($mig)) {
    $migSrc = (string) file_get_contents($mig);
    is_true(strpos($migSrc, "PHP_SAPI !== 'cli'") !== false,
        'migration carries the CLI-only guard');
    foreach (['tile_cache_max_mb', 'tile_cache_min_free_mb', 'tile_proxy_user_agent'] as $k) {
        is_true(strpos($migSrc, $k) !== false, "migration seeds $k");
    }
    is_true(strpos($migSrc, "db_query(\"UPDATE") === false,
        'migration never overwrites an existing admin value');
}

// Settings must be read from the `settings` store via get_variable(), not the
// separate `config` store — crossing those two makes an admin toggle read as
// its default forever (a documented failure in this codebase).
//
// Tokenised, not grepped: this file's own comments mention get_setting() while
// explaining why it is not used, and a substring search would score that as a
// violation. (A gate that can be tripped by a comment is a gate people learn
// to work around by not writing comments.)
$calledFns = [];
foreach (token_get_all((string) file_get_contents($base . '/inc/tile-proxy.php')) as $i => $tok) {
    if (is_array($tok) && $tok[0] === T_STRING) { $calledFns[$tok[1]] = true; }
}
is_true(isset($calledFns['get_variable']), 'settings read via get_variable()');
is_true(!isset($calledFns['get_setting']),
    'settings NOT read from the other (config) store');

echo "\n";
echo "==========================================================\n";
echo "Tile proxy tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
