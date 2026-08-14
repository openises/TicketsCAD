<?php
/**
 * NewUI v4.0 API - Weather Proxy
 *
 * Caching proxy for OpenWeatherMap requests. All OWM traffic routes through
 * this endpoint so that:
 *   - The API key stays server-side (never exposed to the browser)
 *   - Tile images and JSON responses are cached on disk
 *   - Multiple connected clients share a single cache
 *   - Outbound request rate is controlled to stay within free-tier limits
 *
 * Tile requests:
 *   GET weather-proxy.php?type=tile&layer=clouds_cls&z=5&x=10&y=12
 *
 * City weather requests:
 *   GET weather-proxy.php?type=cities&bbox=-93.5,44.8,-93.0,45.1
 */

require_once __DIR__ . '/auth.php';

// ── Configuration ────────────────────────────────────────────────────────────

// The OpenWeatherMap API key may be stored under either the GUI-canonical
// key (owm_api_key, written by the Map Settings panel) or the legacy key
// (openweathermaps_api, used by the original implementation).
$owm_key = get_variable('owm_api_key');
if (!$owm_key) {
    $owm_key = get_variable('openweathermaps_api');
}
if (!$owm_key) {
    json_error('OpenWeatherMap API key not configured', 503);
}

// Cache directory (auto-created)
$cache_dir = __DIR__ . '/../cache/weather';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

// Cache TTLs in seconds
$tile_ttl   = 1800;  // 30 minutes — OWM tiles update ~every 30 min
$cities_ttl = 900;   // 15 minutes — city weather data

// ── Bounds for an upstream that is NOT answering ─────────────────────────
//
// A weather overlay is not one request. A 1920x1080 map viewport is roughly 40
// tiles, so every second spent waiting here is forty worker-seconds per pan —
// the same arithmetic that made the map tile proxy nearly able to exhaust a
// small server's request slots during an outage (docs/OFFLINE-OPERATION.md D2).
// The old 10s timeout, measured at 10.01s against a black-holed host, cost
// about 400 worker-seconds for one pan with the overlay on.
//
// Three changes, mirroring the tile proxy's, and deliberately different in
// scope from one another:
//
//   * Shorter per-call bounds. A weather tile is tens of kilobytes.
//   * A BREAKER, per layer-source, on the server: once OpenWeatherMap has
//     failed three times in a row nothing touches the network until the
//     cool-off expires, so the second pan costs nothing at all.
//   * A NEGATIVE CACHE, per tile, in the browser: a failed tile is served with
//     a short max-age so panning back over the same ground does not even reach
//     us. Previously a failure carried no cache headers, so the browser
//     forgot instantly and re-requested every dead tile on every pan.
//
// A cached tile is still served when the breaker is open — stale beats blank,
// and during a weather event a half-hour-old radar picture is worth having.
const WX_CONNECT_TIMEOUT   = 3;
const WX_READ_TIMEOUT      = 6;
const WX_BREAKER_THRESHOLD = 3;
const WX_BREAKER_COOLOFF   = 60;
const WX_FAIL_MAX_AGE      = 60;

// Breaker state lives in a platform-aware directory outside the served
// tree: cache/ is documented web-reachable, and this is operational state,
// not content. dirname(NEWUI_ROOT) alone is correct on POSIX and inside
// C:\inetpub\wwwroot on a stock Windows/IIS install — the same mistake
// GHSA-x9x6-w4fg-pmcc's round 2 made for zello-audio; see served_dir_above_root().
if (!defined('NEWUI_ROOT')) {
    define('NEWUI_ROOT', dirname(__DIR__));
}
require_once __DIR__ . '/../inc/served-dir.php';
$wx_state_dir = served_dir_above_root(NEWUI_ROOT, 'runtime-state');
if (!is_dir($wx_state_dir)) {
    @mkdir($wx_state_dir, 0755, true);
}
served_dir_harden($wx_state_dir, 'Runtime state (weather circuit-breaker, bridge health)', true);
$wx_breaker_file = $wx_state_dir . '/weather-breaker.json';

/**
 * PURE: is this upstream response evidence that OPENWEATHERMAP is down, as
 * opposed to evidence that one tile does not exist?
 *
 * A 404 is a healthy provider telling the truth about coverage. Counting it
 * would blank the overlay for everyone the first time a dispatcher zoomed past
 * the edge of a layer.
 */
function wx_upstream_is_down(int $status, bool $transportFailed): bool
{
    if ($transportFailed) return true;
    if ($status === 0)    return true;
    if ($status === 429)  return true;   // rate-limited: backing off IS the fix
    if ($status === 401 || $status === 403) return false;  // bad key, not an outage
    return $status >= 500 && $status <= 599;
}

/**
 * PURE breaker decision. Same three states, and the same shape, as
 * tile_breaker_decide() and geocode_breaker_decide() — a reader of one should
 * recognise the others.
 *
 * @return array{open:bool,half_open:bool,retry_in:int,fails:int}
 */
function wx_breaker_decide(array $state, int $now,
                           int $threshold = WX_BREAKER_THRESHOLD,
                           int $cooloff = WX_BREAKER_COOLOFF): array
{
    $fails    = max(0, (int) ($state['fails'] ?? 0));
    $openedAt = (int) ($state['opened_at'] ?? 0);
    if ($threshold <= 0 || $fails < $threshold) {
        return ['open' => false, 'half_open' => false, 'retry_in' => 0, 'fails' => $fails];
    }
    $elapsed = $now - $openedAt;
    if ($openedAt > 0 && $elapsed < $cooloff) {
        return ['open' => true, 'half_open' => false,
                'retry_in' => max(1, $cooloff - $elapsed), 'fails' => $fails];
    }
    return ['open' => false, 'half_open' => true, 'retry_in' => 0, 'fails' => $fails];
}

function wx_breaker_read(string $file): array
{
    if (!is_file($file)) return ['fails' => 0, 'opened_at' => 0];
    $raw = @file_get_contents($file);
    $st = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($st) ? ['fails' => max(0, (int) ($st['fails'] ?? 0)),
                            'opened_at' => (int) ($st['opened_at'] ?? 0)]
                         : ['fails' => 0, 'opened_at' => 0];
}

function wx_breaker_write(string $file, array $state): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
    @file_put_contents($file, json_encode($state), LOCK_EX);
}

/** Request-path gate; re-arms the half-open window so only one request probes. */
function wx_breaker_check(string $file, ?int $now = null): array
{
    $now = $now ?? time();
    $st  = wx_breaker_read($file);
    $d   = wx_breaker_decide($st, $now);
    if ($d['half_open']) {
        $st['opened_at'] = $now;
        wx_breaker_write($file, $st);
    }
    return $d;
}

function wx_breaker_record_failure(string $file, ?int $now = null): void
{
    $now = $now ?? time();
    $st  = wx_breaker_read($file);
    $st['fails']++;
    if ($st['fails'] >= WX_BREAKER_THRESHOLD && (int) $st['opened_at'] === 0) {
        $st['opened_at'] = $now;
    }
    wx_breaker_write($file, $st);
}

function wx_breaker_record_success(string $file): void
{
    $st = wx_breaker_read($file);
    if ($st['fails'] === 0 && (int) $st['opened_at'] === 0) return;
    wx_breaker_write($file, ['fails' => 0, 'opened_at' => 0]);
}

/**
 * Serve the "no tile available" reply.
 *
 * A 1x1 transparent PNG with a 200 status, NOT an error status — deliberately,
 * and for the same reason the map tile proxy does it: Leaflet turns an error
 * status into a `tileerror` and, depending on the build, a broken-image tile
 * that can wedge the layer. A transparent pixel leaves the basemap and every
 * incident marker, unit and geofence exactly where they were. The short
 * max-age is the negative cache: panning back over dead ground costs nothing.
 */
function wx_serve_blank_tile(): void
{
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=' . WX_FAIL_MAX_AGE);
    header('X-Weather-Proxy: error');
    echo base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );
    exit;
}

// Rate limiting: max outbound OWM requests per minute
$rate_limit     = 55;  // free tier allows 60/min, leave headroom
$rate_file      = $cache_dir . '/_rate.json';

// Allowed tile layer names (whitelist to prevent arbitrary URL construction)
$allowed_layers = [
    'clouds', 'clouds_cls',
    'precipitation', 'precipitation_cls',
    'rain', 'rain_cls',
    'snow',
    'pressure', 'pressure_cntr',
    'temp',
    'wind',
];

// ── Rate Limiter ─────────────────────────────────────────────────────────────

function check_rate_limit(string $rate_file, int $max_per_minute): bool
{
    $now = time();
    $window_start = $now - 60;

    $data = ['timestamps' => []];
    if (file_exists($rate_file)) {
        $raw = @file_get_contents($rate_file);
        if ($raw) {
            $data = json_decode($raw, true) ?: ['timestamps' => []];
        }
    }

    // Prune timestamps older than 60 seconds
    $data['timestamps'] = array_values(array_filter(
        $data['timestamps'],
        function ($ts) use ($window_start) { return $ts >= $window_start; }
    ));

    if (count($data['timestamps']) >= $max_per_minute) {
        return false; // rate limit exceeded
    }

    $data['timestamps'][] = $now;
    @file_put_contents($rate_file, json_encode($data), LOCK_EX);
    return true;
}

// ── Request Handling ─────────────────────────────────────────────────────────

$type = $_GET['type'] ?? '';

if ($type === 'tile') {
    handle_tile_request($owm_key, $cache_dir, $tile_ttl, $allowed_layers, $rate_file, $rate_limit, $wx_breaker_file);
} elseif ($type === 'cities') {
    handle_cities_request($owm_key, $cache_dir, $cities_ttl, $rate_file, $rate_limit, $wx_breaker_file);
} else {
    json_error('Invalid type parameter. Use "tile" or "cities".', 400);
}

// ── Tile Handler ─────────────────────────────────────────────────────────────

function handle_tile_request(
    string $owm_key,
    string $cache_dir,
    int $ttl,
    array $allowed_layers,
    string $rate_file,
    int $rate_limit,
    string $breaker_file
): void {
    $layer = $_GET['layer'] ?? '';
    $z = (int) ($_GET['z'] ?? 0);
    $x = (int) ($_GET['x'] ?? 0);
    $y = (int) ($_GET['y'] ?? 0);

    // Validate layer name
    if (!in_array($layer, $allowed_layers, true)) {
        json_error('Invalid layer name', 400);
    }

    // Validate zoom/coords are reasonable
    if ($z < 0 || $z > 20 || $x < 0 || $y < 0) {
        json_error('Invalid tile coordinates', 400);
    }

    // Cache path: cache/weather/tiles/{layer}/{z}/{x}/{y}.png
    $tile_dir  = $cache_dir . '/tiles/' . $layer . '/' . $z . '/' . $x;
    $tile_file = $tile_dir . '/' . $y . '.png';

    // Serve from cache if fresh
    if (file_exists($tile_file) && (time() - filemtime($tile_file)) < $ttl) {
        serve_cached_tile($tile_file);
        return;
    }

    // Breaker: once OpenWeatherMap is known unreachable, stop paying the
    // timeout. Checked BEFORE the rate limiter because an open breaker means
    // we are making no outbound call at all, so there is no rate to limit.
    $breaker = wx_breaker_check($breaker_file);
    if ($breaker['open']) {
        if (file_exists($tile_file)) {
            serve_cached_tile($tile_file);   // stale beats blank
            return;
        }
        wx_serve_blank_tile();
    }

    // Rate limit check
    if (!check_rate_limit($rate_file, $rate_limit)) {
        // Serve stale cache if available, otherwise a blank tile.
        if (file_exists($tile_file)) {
            serve_cached_tile($tile_file);
            return;
        }
        // Was a 429 with a plain-text body. Leaflet renders a non-2xx as a
        // tile error; a transparent pixel with a short max-age keeps the map
        // usable and stops the browser hammering us for the whole minute.
        wx_serve_blank_tile();
    }

    // Fetch from OWM
    $url = 'https://tile.openweathermap.org/map/' . $layer . '/' . $z . '/' . $x . '/' . $y . '.png?appid=' . $owm_key;

    $ctx = stream_context_create([
        'http' => [
            'timeout' => WX_READ_TIMEOUT,
            'ignore_errors' => true,
            'header' => "User-Agent: NewUI-WeatherProxy/1.0\r\n"
        ]
    ]);

    $data = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $hline) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hline, $hm)) { $status = (int) $hm[1]; }
    }

    if ($data === false || strlen($data) < 100) {
        // Only a transport failure or a broken upstream counts toward the
        // breaker. A 404 means this layer has no tile here, which is a true
        // answer from a working provider.
        if (wx_upstream_is_down($status, $data === false)) {
            wx_breaker_record_failure($breaker_file);
        }
        // Serve stale cache as fallback
        if (file_exists($tile_file)) {
            serve_cached_tile($tile_file);
            return;
        }
        wx_serve_blank_tile();
    }
    wx_breaker_record_success($breaker_file);

    // Cache the tile
    if (!is_dir($tile_dir)) {
        mkdir($tile_dir, 0755, true);
    }
    @file_put_contents($tile_file, $data, LOCK_EX);

    // Serve it
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . $ttl);
    header('X-Cache: MISS');
    echo $data;
    exit;
}

function serve_cached_tile(string $file): void
{
    $age = time() - filemtime($file);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . max(0, 1800 - $age));
    header('X-Cache: HIT');
    readfile($file);
    exit;
}

// ── City Weather Handler ─────────────────────────────────────────────────────

function handle_cities_request(
    string $owm_key,
    string $cache_dir,
    int $ttl,
    string $rate_file,
    int $rate_limit,
    string $breaker_file
): void {
    $bbox = $_GET['bbox'] ?? '';

    // Validate bbox format: west,south,east,north
    if (!preg_match('/^-?\d+\.?\d*,-?\d+\.?\d*,-?\d+\.?\d*,-?\d+\.?\d*$/', $bbox)) {
        json_error('Invalid bbox format. Expected: west,south,east,north', 400);
    }

    // Round bbox to 1 decimal place for better cache hit rates
    // (slight viewport differences share the same cached response)
    $parts = array_map(function ($v) {
        return round((float) $v, 1);
    }, explode(',', $bbox));
    $cache_key = implode('_', $parts);

    $json_dir  = $cache_dir . '/cities';
    $json_file = $json_dir . '/' . md5($cache_key) . '.json';

    // Serve from cache if fresh
    if (file_exists($json_file) && (time() - filemtime($json_file)) < $ttl) {
        $age = time() - filemtime($json_file);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=' . max(0, $ttl - $age));
        header('X-Cache: HIT');
        readfile($json_file);
        exit;
    }

    // Breaker — see the tile handler. A city-weather request is one call per
    // pan rather than forty, but paying a 6s timeout on a dead link every time
    // the operator moves the map is still time the console does not have.
    $breaker = wx_breaker_check($breaker_file);
    if ($breaker['open']) {
        if (file_exists($json_file)) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Cache: STALE');
            readfile($json_file);
            exit;
        }
        // An empty list, not an error: the plugin renders no markers and the
        // map carries on. `retry_in` says when it is worth asking again.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, max-age=' . WX_FAIL_MAX_AGE);
        header('X-Weather-Proxy: breaker-open');
        echo json_encode(['list' => [], 'cnt' => 0,
                          'unavailable' => true, 'retry_in' => $breaker['retry_in']]);
        exit;
    }

    // Rate limit check
    if (!check_rate_limit($rate_file, $rate_limit)) {
        // Serve stale cache if available
        if (file_exists($json_file)) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Cache: STALE');
            readfile($json_file);
            exit;
        }
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo json_encode(['error' => 'Rate limit exceeded, try again shortly']);
        exit;
    }

    // Fetch from OWM
    $url = 'https://api.openweathermap.org/data/2.5/box/city'
        . '?APPID=' . $owm_key
        . '&cnt=300&format=json&units=metric'
        . '&bbox=' . $parts[0] . ',' . $parts[1] . ',' . $parts[2] . ',' . $parts[3] . ',10';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => WX_READ_TIMEOUT,
            'ignore_errors' => true,
            'header' => "User-Agent: NewUI-WeatherProxy/1.0\r\n"
        ]
    ]);

    $data = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $hline) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hline, $hm)) { $status = (int) $hm[1]; }
    }

    if ($data === false) {
        if (wx_upstream_is_down($status, true)) {
            wx_breaker_record_failure($breaker_file);
        }
        // Serve stale cache as fallback
        if (file_exists($json_file)) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Cache: STALE');
            readfile($json_file);
            exit;
        }
        json_error('Failed to fetch weather data from OWM', 502);
    }
    wx_breaker_record_success($breaker_file);

    // Cache the response
    if (!is_dir($json_dir)) {
        mkdir($json_dir, 0755, true);
    }
    @file_put_contents($json_file, $data, LOCK_EX);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . $ttl);
    header('X-Cache: MISS');
    echo $data;
    exit;
}
