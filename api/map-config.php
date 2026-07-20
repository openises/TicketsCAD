<?php
/**
 * NewUI v4.0 API - Map Configuration
 *
 * GET /api/map-config.php
 *
 * Returns map settings: default coords, zoom, weather availability, area title.
 * Note: The actual OWM API key is never sent to the browser — only a boolean
 * flag indicating whether weather layers are available (key configured).
 */

require_once __DIR__ . '/auth.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// Read each setting from the GUI-canonical key first, then fall back to the
// legacy key. The Map Settings panel in Settings writes default_lat/default_lng/
// default_zoom/area_title/owm_api_key; legacy installs and api/map-config.php's
// original implementation used def_lat/def_lng/def_zoom/map_area_title/
// openweathermaps_api. Reading both keeps the GUI working AND honors any value
// a legacy install already had.
$owm_key = get_variable('owm_api_key');
if ($owm_key === '' || $owm_key === null) {
    $owm_key = get_variable('openweathermaps_api') ?: '';
}

$lat = get_variable('default_lat');
if ($lat === '' || $lat === null) $lat = get_variable('def_lat');

$lng = get_variable('default_lng');
if ($lng === '' || $lng === null) $lng = get_variable('def_lng');

$zoom = get_variable('default_zoom');
if ($zoom === '' || $zoom === null) $zoom = get_variable('def_zoom');

$title = get_variable('area_title');
if ($title === '' || $title === null) $title = get_variable('map_area_title');

// ── Configurable tile provider (specs/configurable-tile-providers-2026-06) ──
//
// The Settings → Tile Providers panel persists these keys to `settings`.
// Until this endpoint surfaced them, no live map read them — the configured
// provider only previewed in Settings. We now return enough for map-prefs.js
// to merge the provider into its TILE_DEFS registry so every map gains it.
//
// Resolution + URL sanitization live in inc/tile-config.php so they can be
// unit-tested without the auth/session machinery this endpoint requires.
//
// Defensive: each get_variable() returns false when the row is missing
// (different installs have different schema/settings ages) — coerce to ''.
require_once __DIR__ . '/../inc/tile-config.php';

$tile_provider   = (string) (get_variable('tile_provider')   ?: '');
$tile_server_url = (string) (get_variable('tile_server_url') ?: '');
$tile_api_key    = (string) (get_variable('tile_api_key')    ?: '');
$tile_mode       = (string) (get_variable('tile_mode')       ?: '');
$tile_cache_raw  = get_variable('tile_cache_days');
$tile_cache_days = ($tile_cache_raw !== false && $tile_cache_raw !== '' && $tile_cache_raw !== null)
                        ? (int) $tile_cache_raw : null;

// tile_api_key is a tile-scoped key (Bing/Mapbox tile keys are designed to
// be embedded in client tile URLs) — flagged in the security review, not a
// master credential.
$tile = resolve_tile_config($tile_provider, $tile_server_url, $tile_api_key, $tile_mode, $tile_cache_days);

json_response(array_merge([
    'def_lat'         => (float) ($lat !== '' && $lat !== null ? $lat : 39.8283),
    'def_lng'         => (float) ($lng !== '' && $lng !== null ? $lng : -98.5795),
    'def_zoom'        => (int)   ($zoom !== '' && $zoom !== null ? $zoom : 5),
    'owm_api'         => $owm_key !== '' ? 'enabled' : '',
    'area_title'      => $title ?: '',
], $tile));
