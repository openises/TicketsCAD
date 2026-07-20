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
 */

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
        'cartodb_positron'  => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
        'cartodb_dark'      => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
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
 * @return array{
 *   tile_provider:string, tile_server_url:string, tile_url:string,
 *   tile_api_key:string, tile_mode:string, tile_cache_days:?int,
 *   tile_is_quadkey:bool, has_custom_tile:bool
 * }
 */
function resolve_tile_config(string $provider, string $serverUrl, string $apiKey, string $mode, ?int $cacheDays): array
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

    return [
        'tile_provider'   => $provider,
        'tile_server_url' => $serverUrl,
        'tile_url'        => $url,
        'tile_api_key'    => $apiKey,
        'tile_mode'       => $mode,
        'tile_cache_days' => $cacheDays,
        'tile_is_quadkey' => $isQuadkey,
        'has_custom_tile' => ($url !== ''),
    ];
}
