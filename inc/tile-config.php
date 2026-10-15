<?php
/**
 * inc/tile-config.php — configurable tile-provider resolution.
 *
 * Pure, side-effect-free helpers shared by api/map-config.php (which
 * surfaces the result to the client) and the test suite. Keeping the
 * resolution + sanitization here means it is unit-testable without the
 * auth/session machinery the endpoint needs.
 *
 * Spec: specs/configurable-tile-providers-2026-06/ (Phase A).
 *
 * 2026-07-31: resolve_tile_config() now also reports the mode that will
 * ACTUALLY be used and, when that is proxy, the same-origin URL to use. Until
 * this change `$mode` came in, was copied to the output, and was read by
 * nothing — no JS consumer for it has ever existed in any commit. See
 * inc/tile-proxy.php for the whole story and the per-provider policy.
 */

require_once __DIR__ . '/tile-proxy.php';

/**
 * Known-provider tile URL templates.
 *
 * MUST stay in sync with the TILE_URLS map in assets/js/config.js (the
 * Settings → Tile Providers panel). 'custom' is intentionally absent here
 * — a custom provider uses the admin-supplied tile_server_url instead of a
 * canned template.
 *
 * @return array<string,string>
 */
function tile_provider_templates(): array
{
    return [
        // ── Free, no key required (recommend these) ──
        'osm'               => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'osm_hot'           => 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
        'usgs_topo'         => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}',
        'usgs_imagery'      => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryOnly/MapServer/tile/{z}/{y}/{x}',
        'usgs_imagery_topo' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}',
        // GH#150 (rjonesbsink, 2026-09-21): CARTO now requires a free API
        // key on basemaps.cartocdn.com — an unkeyed request to the old
        // {s}.basemaps.cartocdn.com/<style>/... path gets served an
        // "API key required" watermark tile, confirmed against CARTO's own
        // basemap-styles repo. The authenticated path drops the {s}
        // subdomain and moves under /rastertiles/, with the key as a plain
        // ?key= query parameter — the existing generic {key} substitution
        // (inc/tile-proxy.php, assets/js/config.js) already handles it once
        // the template carries the placeholder, same as the Mapbox row below.
        'cartodb_positron'  => 'https://basemaps.cartocdn.com/rastertiles/light_all/{z}/{x}/{y}.png?key={key}',
        'cartodb_dark'      => 'https://basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png?key={key}',
        'esri_street'       => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
        'esri_sat'          => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        'esri_topo'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
        // ── Unofficial scrapes — kept for backward compat only ──
        // Google never published a free tile URL ToS for this style.
        // New deployments should use OSM / USGS / Esri above.
        'google_street'     => 'https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
        'google_sat'        => 'https://mt{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
        'google_hybrid'     => 'https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
        // ── Retired / discontinued by the provider ──
        // Bing Maps for Enterprise — Microsoft shut down Free/Basic
        // accounts on 2025-06-30; paid Enterprise accounts run through
        // 2028-06-30. NEW DEPLOYMENTS SHOULD NOT USE BING. Microsoft's
        // replacement is Azure Maps (different URL, XYZ not quadkey).
        // Entries kept so existing configs don't crash at load time;
        // the labels in settings.php mark them as retired and help.php
        // documents the migration path.
        'bing_road'         => 'https://ecn.t{s}.tiles.virtualearth.net/tiles/r{q}?g=1&mkt=en-US',
        'bing_aerial'       => 'https://ecn.t{s}.tiles.virtualearth.net/tiles/a{q}?g=1',
        // ── API key required ──
        'mapbox'            => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={key}',
    ];
}

/**
 * Sanitize a tile URL template to an http(s) absolute URL.
 *
 * The resolved URL ends up as a Leaflet tile <img src>. An admin-supplied
 * template must not be able to smuggle a javascript:/data: scheme (XSS) or
 * a protocol-relative // host (scheme confusion). We require an explicit
 * http or https scheme; everything else resolves to '' (no provider).
 *
 * @param string $url
 * @return string the sanitized URL, or '' if rejected/empty
 */
function tile_sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'https' && $scheme !== 'http') {
        return '';
    }
    return $url;
}

/**
 * Resolve the configured tile provider into a client-facing payload.
 *
 * @param string      $provider   tile_provider setting ('', 'custom', or a
 *                                 known key like 'bing_road')
 * @param string      $serverUrl  tile_server_url setting (used for custom /
 *                                 legacy installs that set only this)
 * @param string      $apiKey     tile_api_key setting (tile-scoped — client
 *                                 visible by design for Bing/Mapbox)
 * @param string      $mode       tile_mode setting ('proxy' | 'direct' | '')
 * @param int|null    $cacheDays  tile_cache_days setting, or null
 * @param string      $attribution tile_attribution setting: the map credit for
 *                                 the configured provider (plain text; the
 *                                 browser escapes it before Leaflet renders it)
 * @return array{
 *   tile_provider:string, tile_server_url:string, tile_url:string,
 *   tile_api_key:string, tile_mode:string, tile_cache_days:?int,
 *   tile_is_quadkey:bool, has_custom_tile:bool, tile_attribution:string
 * }
 */
function resolve_tile_config(string $provider, string $serverUrl, string $apiKey, string $mode, ?int $cacheDays, string $attribution = ''): array
{
    $templates = tile_provider_templates();

    $url = '';
    if ($provider === 'custom') {
        $url = $serverUrl;
    } elseif ($provider !== '' && isset($templates[$provider])) {
        $url = $templates[$provider];
    } elseif ($serverUrl !== '') {
        // Legacy installs may set only tile_server_url (no tile_provider).
        // Treat that as a custom XYZ source.
        $url = $serverUrl;
    }

    $url = tile_sanitize_url($url);
    $isQuadkey = ($url !== '' && strpos($url, '{q}') !== false);

    // Which provider identity does the proxy policy apply to? A legacy install
    // that set only tile_server_url is effectively 'custom'.
    $policyKey = $provider;
    if ($policyKey === '' && $serverUrl !== '') {
        $policyKey = 'custom';
    }

    $effective = ($url === '') ? 'direct' : tile_proxy_effective_mode($mode, $policyKey);
    $verdict   = tile_proxy_verdict($policyKey);

    // The same-origin URL Leaflet should use when proxying. Built here rather
    // than in JS so exactly one place decides what a proxied tile URL is.
    $proxyUrl = '';
    if ($effective === 'proxy') {
        $proxyUrl = 'api/tile-proxy.php?provider=' . rawurlencode($policyKey)
                  . '&z={z}&x={x}&y={y}';
    }

    return [
        'tile_provider'   => $provider,
        'tile_server_url' => $serverUrl,
        'tile_url'        => $url,
        'tile_api_key'    => $apiKey,
        // The CONFIGURED mode, unchanged — existing consumers keep their value.
        'tile_mode'       => $mode,
        // The mode that will actually be used. Differs from tile_mode whenever
        // the provider's terms forbid us proxying for them.
        'tile_effective_mode' => $effective,
        'tile_proxy_allowed'  => $verdict['allowed'],
        'tile_proxy_reason'   => $verdict['reason'],
        'tile_proxy_caveat'   => $verdict['caveat'],
        'tile_proxy_url'      => $proxyUrl,
        'tile_cache_days' => $cacheDays,
        'tile_is_quadkey' => $isQuadkey,
        'has_custom_tile' => ($url !== ''),
        'tile_attribution' => tile_attribution_normalize($attribution),
    ];
}

/**
 * Normalise the administrator's map credit (the tile_attribution setting).
 *
 * The credit for the configured tile provider was hard-coded to "Configured
 * provider", so an install serving OpenStreetMap-derived tiles from its own
 * server (the route docs/OFFLINE-OPERATION.md recommends) could not show the
 * "(c) OpenStreetMap contributors" credit the OpenStreetMap licence requires.
 *
 * Plain text only. Control characters become spaces, runs of whitespace
 * collapse, and it is capped at TILE_ATTRIBUTION_MAX characters. It is NOT
 * HTML-escaped here: Leaflet inserts attribution as HTML, so the one place
 * that renders it (assets/js/map-prefs.js customAttribution()) escapes it, and
 * escaping twice would show "&amp;copy;" on the map.
 */
const TILE_ATTRIBUTION_MAX = 200;

/**
 * Which basemap a map starts on when the user has not chosen one themselves
 * (the tile_default_basemap setting).
 *
 *   'user'       — Street in light mode, Dark in dark mode. Today's behaviour,
 *                  and the default on every install.
 *   'configured' — the provider configured in Settings → Tile Providers. For an
 *                  install whose maps must work without the internet: until
 *                  2026-10-15 a configured provider was only ever an EXTRA entry
 *                  in the layer switcher, so every map still started on
 *                  OpenStreetMap and drew nothing offline.
 *
 * A user's own choice (Display Preferences, or the layer switcher on a map)
 * always wins over this.
 */
const TILE_DEFAULT_BASEMAP_VALUES = ['user', 'configured'];

function tile_default_basemap_normalize(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, TILE_DEFAULT_BASEMAP_VALUES, true) ? $value : 'user';
}

/**
 * The CARTO key the BUILT-IN Dark and Light basemaps use (2026-10-15).
 *
 * CARTO began requiring a free key on basemaps.cartocdn.com in September 2026;
 * a request without one is answered with an "API KEY REQUIRED" watermark tile
 * instead of the map. GH#150 gave the CARTO presets of the configured provider
 * a key, but the Dark and Light layers every map offers (assets/js/map-prefs.js)
 * kept calling the old keyless address, and Dark is where every dark-mode user's
 * maps start.
 *
 * The dedicated carto_api_key setting wins. Failing that, when the configured
 * provider is itself a CARTO preset, its tile_api_key is the same kind of key
 * and is used. Otherwise '' — and the built-in Dark and Light are then drawn
 * from OpenStreetMap (map-prefs.js), never the watermark.
 *
 * The key cannot be kept from the browser: CARTO designs it to travel in every
 * tile URL, and its terms do not allow the tile proxy to fetch for it. Only the
 * characters a URL can carry unescaped are kept, so a stray paste cannot change
 * the URL around it.
 */
function tile_carto_key_resolve(string $cartoKey, string $provider, string $tileApiKey): string
{
    $clean = static function (string $key): string {
        return (string) preg_replace('/[^A-Za-z0-9._~-]/', '', trim($key));
    };
    $key = $clean($cartoKey);
    if ($key !== '') {
        return $key;
    }
    if (strpos($provider, 'cartodb_') === 0) {
        return $clean($tileApiKey);
    }
    return '';
}

/**
 * The tile settings every map needs, read from the `settings` table: the
 * resolve_tile_config() payload plus `tile_default_basemap`. One function, so
 * api/map-config.php and the page bootstrap in inc/navbar.php cannot disagree.
 *
 * `tile_default_basemap` is 'configured' only when a provider really is
 * configured; a 'configured' setting with no provider reads as 'user'.
 */
function tile_config_payload(): array
{
    $get = static function (string $name): string {
        if (!function_exists('get_variable')) { return ''; }
        $v = get_variable($name);
        return ($v === false || $v === null) ? '' : (string) $v;
    };
    $cacheRaw = $get('tile_cache_days');
    $tile = resolve_tile_config(
        $get('tile_provider'),
        $get('tile_server_url'),
        $get('tile_api_key'),
        $get('tile_mode'),
        $cacheRaw !== '' ? (int) $cacheRaw : null,
        $get('tile_attribution')
    );
    $default = tile_default_basemap_normalize($get('tile_default_basemap'));
    $tile['tile_default_basemap'] = ($default === 'configured' && $tile['has_custom_tile']) ? 'configured' : 'user';
    $tile['carto_api_key'] = tile_carto_key_resolve(
        $get('carto_api_key'),
        $get('tile_provider'),
        $get('tile_api_key')
    );
    return $tile;
}

/**
 * The part of tile_config_payload() written into every signed-in page as
 * window.TILE_CONFIG (inc/navbar.php), so a map can start on the configured
 * provider without waiting for a fetch.
 *
 * Only what assets/js/map-prefs.js needs to build the layer. The tile API key
 * is included only when the browser fetches tiles directly, because then it
 * is in every tile request anyway; when tiles come through the proxy the
 * browser never needs it, so it is not written into the page.
 */
function tile_config_client_bootstrap(array $payload): array
{
    $proxied = ($payload['tile_effective_mode'] ?? '') === 'proxy' && !empty($payload['tile_proxy_url']);
    return [
        'has_custom_tile'      => (bool) ($payload['has_custom_tile'] ?? false),
        'tile_provider'        => (string) ($payload['tile_provider'] ?? ''),
        'tile_url'             => (string) ($payload['tile_url'] ?? ''),
        'tile_api_key'         => $proxied ? '' : (string) ($payload['tile_api_key'] ?? ''),
        'tile_effective_mode'  => (string) ($payload['tile_effective_mode'] ?? ''),
        'tile_proxy_url'       => (string) ($payload['tile_proxy_url'] ?? ''),
        'tile_attribution'     => (string) ($payload['tile_attribution'] ?? ''),
        'tile_default_basemap' => (string) ($payload['tile_default_basemap'] ?? 'user'),
        // CARTO is never proxied, so the built-in Dark and Light layers always
        // need the key in the browser (tile_carto_key_resolve()).
        'carto_api_key'        => (string) ($payload['carto_api_key'] ?? ''),
    ];
}

function tile_attribution_normalize(string $text): string
{
    if (function_exists('mb_convert_encoding')) {
        // Replace any invalid UTF-8 rather than store bytes a browser would mangle.
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, TILE_ATTRIBUTION_MAX, 'UTF-8');
    } else {
        $text = substr($text, 0, TILE_ATTRIBUTION_MAX);
    }
    return rtrim($text);
}
