<?php
/**
 * Phase 112 — NWS (National Weather Service) provider.
 *
 * Fetches and NORMALIZES api.weather.gov alerts into a flat internal struct
 * the rest of the weather engine understands. The normalize half is PURE
 * (no I/O) so it is unit-tested against a captured fixture — tests never hit
 * the live API. The fetch half is isolated and only exercised by the live
 * poller / an admin "test poll".
 *
 * NWS facts (probed 2026-07-05):
 *  - Free, no API key, but a descriptive User-Agent WITH a contact is required
 *    (generic UAs get 403). We pass "TicketsCAD-WeatherAlerts (<contact>)".
 *  - GET https://api.weather.gov/alerts/active?area=MN | ?zone=MNZ060 | ?point=lat,lng
 *    → GeoJSON FeatureCollection. Each feature.properties carries event,
 *    severity, urgency, certainty, messageType (Alert/Update/Cancel), areaDesc,
 *    onset/effective/expires/ends, headline, description, instruction,
 *    geocode.UGC. feature.geometry is a Polygon/MultiPolygon (may be null).
 *
 * This provider is the ONLY US-specific piece; inc/weather_alerts.php routes
 * through a provider seam (weather_provider setting) so a future MeteoAlarm/EU
 * adapter can be dropped in without touching the fan-out.
 */

if (!defined('WEATHER_NWS_BASE')) {
    define('WEATHER_NWS_BASE', 'https://api.weather.gov');
}

/**
 * Build the query string for a coverage area row.
 * @return string e.g. "area=MN" | "zone=MNZ060&zone=MNZ061" | "point=44.98,-93.27"
 *                Empty string if the area is not expressible as an NWS filter.
 */
function weather_nws_area_query(array $area): string
{
    $kind = (string) ($area['kind'] ?? '');
    if ($kind === 'state') {
        $code = strtoupper(trim((string) ($area['state_code'] ?? '')));
        return $code !== '' ? 'area=' . rawurlencode($code) : '';
    }
    if ($kind === 'zones') {
        $zones = array_filter(array_map('trim', explode(',', (string) ($area['zones'] ?? ''))));
        if (empty($zones)) return '';
        return implode('&', array_map(static function ($z) {
            return 'zone=' . rawurlencode(strtoupper($z));
        }, $zones));
    }
    if ($kind === 'point_radius') {
        $lat = $area['lat'] ?? null;
        $lng = $area['lng'] ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '') return '';
        // NWS ?point= returns alerts touching the point. We ALSO post-filter by
        // distance-to-centroid in the engine so the radius is honored even for
        // large alert polygons. Query by point to keep the payload small.
        return 'point=' . rawurlencode(((float) $lat) . ',' . ((float) $lng));
    }
    return '';
}

/**
 * Live fetch of active alerts for a prepared query string.
 * Isolated I/O — not called by unit tests.
 *
 * @return array{ok:bool,status:int,features:array,error:?string}
 */
function weather_nws_fetch(string $query, string $uaContact, int $timeout = 12): array
{
    $out = ['ok' => false, 'status' => 0, 'features' => [], 'error' => null];

    $contact = trim($uaContact);
    if ($contact === '') {
        $out['error'] = 'weather_ua_contact is not set';
        return $out;
    }
    $ua = 'TicketsCAD-WeatherAlerts (' . $contact . ')';
    $url = WEATHER_NWS_BASE . '/alerts/active' . ($query !== '' ? ('?' . $query) : '');

    if (!function_exists('curl_init')) {
        $out['error'] = 'curl extension unavailable';
        return $out;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . $ua,
            'Accept: application/geo+json',
        ],
    ]);
    $body = curl_exec($ch);
    $out['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $out['error'] = 'curl: ' . curl_error($ch);
        curl_close($ch);
        return $out;
    }
    curl_close($ch);

    if ($out['status'] < 200 || $out['status'] >= 300) {
        $out['error'] = 'HTTP ' . $out['status'];
        return $out;
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        $out['error'] = 'invalid JSON';
        return $out;
    }
    $out['ok'] = true;
    $out['features'] = isset($json['features']) && is_array($json['features']) ? $json['features'] : [];
    return $out;
}

/**
 * Normalize ONE GeoJSON feature into the internal alert struct. PURE.
 * Unknown/missing fields degrade to null/'' — never throws.
 */
function weather_nws_normalize(array $feature): ?array
{
    $p = isset($feature['properties']) && is_array($feature['properties']) ? $feature['properties'] : [];
    $nwsId = (string) ($feature['id'] ?? $p['id'] ?? '');
    if ($nwsId === '') return null; // an alert with no id is unusable for de-dup

    // geocode.UGC → CSV of zone/county codes
    $ugc = '';
    if (isset($p['geocode']['UGC']) && is_array($p['geocode']['UGC'])) {
        $ugc = implode(',', array_map('strval', $p['geocode']['UGC']));
    }

    $geometry = $feature['geometry'] ?? null;
    $centroid = weather_geometry_centroid($geometry);

    return [
        'nws_id'       => $nwsId,
        'event'        => _wx_str($p['event'] ?? null),
        'severity'     => _wx_str($p['severity'] ?? null),
        'urgency'      => _wx_str($p['urgency'] ?? null),
        'certainty'    => _wx_str($p['certainty'] ?? null),
        'message_type' => _wx_str($p['messageType'] ?? null),
        'area_desc'    => _wx_str($p['areaDesc'] ?? null),
        'headline'     => _wx_str($p['headline'] ?? null),
        'description'  => _wx_str($p['description'] ?? null),
        'instruction'  => _wx_str($p['instruction'] ?? null),
        'onset'        => _wx_datetime($p['onset'] ?? ($p['effective'] ?? null)),
        'expires'      => _wx_datetime($p['expires'] ?? null),
        'ends'         => _wx_datetime($p['ends'] ?? null),
        'geocode_ugc'  => $ugc,
        'polygon'      => $geometry !== null ? json_encode($geometry) : null,
        'centroid_lat' => $centroid['lat'] ?? null,
        'centroid_lng' => $centroid['lng'] ?? null,
    ];
}

/**
 * Normalize a whole FeatureCollection (or a bare features array). PURE.
 * @return array<int,array> normalized alerts (nulls dropped)
 */
function weather_nws_normalize_collection($geojson): array
{
    $features = [];
    if (isset($geojson['features']) && is_array($geojson['features'])) {
        $features = $geojson['features'];
    } elseif (is_array($geojson)) {
        $features = $geojson; // already a bare features array
    }
    $out = [];
    foreach ($features as $f) {
        if (!is_array($f)) continue;
        $n = weather_nws_normalize($f);
        if ($n !== null) $out[] = $n;
    }
    return $out;
}

/**
 * Approximate centroid of a GeoJSON geometry (Polygon/MultiPolygon/Point).
 * Simple average of exterior-ring vertices — good enough for a radius filter.
 * PURE. Returns ['lat'=>float,'lng'=>float] or [] when not derivable.
 */
function weather_geometry_centroid($geometry): array
{
    if (!is_array($geometry) || !isset($geometry['type'])) return [];
    $type = $geometry['type'];
    $coords = $geometry['coordinates'] ?? null;
    if ($coords === null) return [];

    $pts = [];
    $collect = static function ($ring) use (&$pts) {
        foreach ($ring as $pt) {
            if (is_array($pt) && isset($pt[0], $pt[1]) && is_numeric($pt[0]) && is_numeric($pt[1])) {
                // GeoJSON is [lng, lat]
                $pts[] = [(float) $pt[1], (float) $pt[0]];
            }
        }
    };

    if ($type === 'Point') {
        if (isset($coords[0], $coords[1])) return ['lat' => (float) $coords[1], 'lng' => (float) $coords[0]];
        return [];
    }
    if ($type === 'Polygon') {
        if (isset($coords[0]) && is_array($coords[0])) $collect($coords[0]);
    } elseif ($type === 'MultiPolygon') {
        foreach ($coords as $poly) {
            if (isset($poly[0]) && is_array($poly[0])) $collect($poly[0]);
        }
    }
    if (empty($pts)) return [];
    $sLat = 0.0; $sLng = 0.0;
    foreach ($pts as $pt) { $sLat += $pt[0]; $sLng += $pt[1]; }
    $n = count($pts);
    return ['lat' => $sLat / $n, 'lng' => $sLng / $n];
}

/**
 * List a state's NWS COUNTY zones (id + name) — the building block for the
 * "pick your counties" area picker, valid for any US state/territory.
 * GET /zones?area=XX&type=county. Cached to disk for 30 days (county lists
 * are near-static) so the picker doesn't hammer NWS.
 *
 * @param string        $state   2-letter code
 * @param string        $ua      UA contact (required by NWS)
 * @param callable|null $fetcher injectable for tests: fn($url,$ua): array{ok,body}
 * @return array{ok:bool, counties:array<int,array{id:string,name:string}>, error:?string}
 */
function weather_nws_counties(string $state, string $ua, ?callable $fetcher = null): array
{
    // Validate the WHOLE input is a 2-letter code — truncating first would
    // silently accept "Minnesota" as "MI" (the wrong state).
    $state = strtoupper(trim($state));
    if (!preg_match('/^[A-Z]{2}$/', $state)) {
        return ['ok' => false, 'counties' => [], 'error' => 'a 2-letter state code is required'];
    }

    // Disk cache (cache/ is the gitignored weather-tile cache dir).
    $cacheDir  = dirname(__DIR__) . '/cache';
    $cacheFile = $cacheDir . '/nws_counties_' . $state . '.json';
    if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 30 * 86400) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            return ['ok' => true, 'counties' => $cached, 'error' => null];
        }
    }

    $url = WEATHER_NWS_BASE . '/zones?area=' . rawurlencode($state) . '&type=county';
    if ($fetcher === null) {
        $fetcher = static function (string $url, string $ua): array {
            if (trim($ua) === '' || !function_exists('curl_init')) {
                return ['ok' => false, 'body' => null];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: TicketsCAD-WeatherAlerts (' . trim($ua) . ')',
                    'Accept: application/geo+json',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['ok' => ($code >= 200 && $code < 300 && is_string($body)),
                    'body' => is_string($body) ? json_decode($body, true) : null];
        };
    }

    $res = $fetcher($url, $ua);
    if (empty($res['ok']) || !is_array($res['body'])) {
        return ['ok' => false, 'counties' => [], 'error' => 'NWS zones fetch failed'];
    }

    $counties = [];
    foreach (($res['body']['features'] ?? []) as $f) {
        $props = $f['properties'] ?? [];
        $id = (string) ($props['id'] ?? '');
        $name = (string) ($props['name'] ?? '');
        if ($id !== '' && $name !== '') $counties[] = ['id' => $id, 'name' => $name];
    }
    usort($counties, static function ($a, $b) { return strcmp($a['name'], $b['name']); });

    if (!empty($counties)) {
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        @file_put_contents($cacheFile, json_encode($counties));
    }
    return ['ok' => !empty($counties), 'counties' => $counties,
            'error' => empty($counties) ? 'no counties returned' : null];
}

/** Trim a scalar to string, null → null. */
function _wx_str($v): ?string
{
    if ($v === null) return null;
    $s = trim((string) $v);
    return $s === '' ? null : $s;
}

/** ISO-8601 → 'Y-m-d H:i:s' (local of the timestamp), null on failure. */
function _wx_datetime($v): ?string
{
    if ($v === null || $v === '') return null;
    $t = strtotime((string) $v);
    return $t === false ? null : date('Y-m-d H:i:s', $t);
}
