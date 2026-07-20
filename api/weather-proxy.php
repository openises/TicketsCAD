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
    handle_tile_request($owm_key, $cache_dir, $tile_ttl, $allowed_layers, $rate_file, $rate_limit);
} elseif ($type === 'cities') {
    handle_cities_request($owm_key, $cache_dir, $cities_ttl, $rate_file, $rate_limit);
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
    int $rate_limit
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

    // Rate limit check
    if (!check_rate_limit($rate_file, $rate_limit)) {
        // Serve stale cache if available, otherwise 429
        if (file_exists($tile_file)) {
            serve_cached_tile($tile_file);
            return;
        }
        http_response_code(429);
        header('Retry-After: 60');
        echo 'Rate limit exceeded';
        exit;
    }

    // Fetch from OWM
    $url = 'https://tile.openweathermap.org/map/' . $layer . '/' . $z . '/' . $x . '/' . $y . '.png?appid=' . $owm_key;

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "User-Agent: NewUI-WeatherProxy/1.0\r\n"
        ]
    ]);

    $data = @file_get_contents($url, false, $ctx);

    if ($data === false || strlen($data) < 100) {
        // Serve stale cache as fallback
        if (file_exists($tile_file)) {
            serve_cached_tile($tile_file);
            return;
        }
        http_response_code(502);
        echo 'Failed to fetch tile from OWM';
        exit;
    }

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
    int $rate_limit
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
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: NewUI-WeatherProxy/1.0\r\n"
        ]
    ]);

    $data = @file_get_contents($url, false, $ctx);

    if ($data === false) {
        // Serve stale cache as fallback
        if (file_exists($json_file)) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Cache: STALE');
            readfile($json_file);
            exit;
        }
        json_error('Failed to fetch weather data from OWM', 502);
    }

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
