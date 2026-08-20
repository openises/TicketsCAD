<?php
/**
 * NewUI v4.0 — Per-user map LAYER VISIBILITY preferences.
 *
 * The defect this exists to fix (Eric, 2026-07-31): "If I edit what map layers
 * are visible, that choice is not respected when I reload the page. I don't
 * want to see my facilities on my map but I want to load them if I need them."
 *
 * What was actually happening, in three different shapes:
 *
 *   1. situation.php persisted NOTHING. Units (EOC), Facilities (EOC), Event
 *      Zones, Weather Alerts, both radars, the four weather tiles and Road
 *      Conditions were built, added to the map, listed in the layer control —
 *      and no code anywhere read or wrote their state. Toggling was per-page-
 *      load by construction.
 *
 *   2. The dashboard (assets/js/app.js) DID save to localStorage and DID
 *      restore on load — but the restore loop only ever called addTo(). The
 *      data layer groups are unconditionally `.addTo(map)` at construction, so
 *      a layer the user turned OFF was re-added on every load and the restore
 *      had no branch that could remove it. Turning a layer ON persisted;
 *      turning one OFF could not. That asymmetry is exactly the direction Eric
 *      reported, and it is why this looked like "nothing persists" from the
 *      one seat that cared.
 *
 *   3. Everything that did persist went to localStorage — per BROWSER, not per
 *      user. Two dispatchers sharing a console overwrite each other; one
 *      dispatcher on two machines gets neither.
 *
 * So visibility is now stored SERVER-SIDE, PER USER, in the Phase 17
 * `user_screen_prefs` table under the screen key 'map-layers' — reusing
 * inc/screen-prefs.php's prefs_get/prefs_set/prefs_reset rather than inventing
 * a third preference mechanism. prefs_get()'s optional $defaults argument does
 * precisely what is needed here: merge a user's saved overrides on top of a
 * caller-supplied default catalog, by id, leaving unknown//new layers at their
 * default. Reset is just prefs_reset() — the row goes away and the admin
 * default applies again.
 *
 * Three layers of precedence, lowest first:
 *
 *   shipped default   map_layer_catalog()      — matches what each surface did
 *                                                before this change, so an
 *                                                untouched install is unchanged
 *   admin default     settings.map_layer_defaults (JSON, via get_variable)
 *   user override     user_screen_prefs row, screen='map-layers'
 *
 * NOTE this is layer VISIBILITY only. It is deliberately unrelated to the tile
 * PROVIDER/mode work in inc/tile-config.php + inc/tile-proxy.php: that decides
 * where basemap bytes come from, this decides which overlays are switched on.
 * Neither reads the other's settings keys.
 */

require_once __DIR__ . '/screen-prefs.php';

/** The screen key under which visibility lives in user_screen_prefs. */
const MAP_LAYER_PREFS_SCREEN = 'map-layers';

/** The `settings` row (name/value store, read via get_variable) holding the org default. */
const MAP_LAYER_PREFS_SETTING = 'map_layer_defaults';

/**
 * Every toggleable map layer, by STABLE ID.
 *
 * Ids are surface-independent on purpose: the dashboard's "Facilities" and the
 * situation screen's "Facilities (EOC)" are the same decision to the operator,
 * so they share the id `facilities` and one preference covers both. Labels here
 * are for the admin defaults UI; each surface keeps rendering its own label in
 * its own layer control (they carry colour swatches and per-page wording).
 *
 * `default` MUST match what the surfaces did before this change, or an install
 * that upgrades would silently gain or lose layers nobody asked to change.
 *
 * @return array<string, array{label:string, group:string, default:bool}>
 */
function map_layer_catalog(): array {
    return [
        // ── Operational data ──
        'incidents'       => ['label' => 'Incidents',        'group' => 'Operational', 'default' => true],
        'units'           => ['label' => 'Units',            'group' => 'Operational', 'default' => true],
        // GH #74 / GH #73 (2026-08-17) — the live-GPS tracking overlay
        // (assets/js/unit-tracking.js) is a SEPARATE layer from the
        // status-coloured 'units' roster layer above; see situation.php's
        // tracker.getLayerGroup() registration for why the two are distinct.
        // Cataloguing it here (matching SHIPPED_DEFAULTS in
        // assets/js/map-layer-prefs.js) is what makes a toggle of it survive
        // a reload — an id map_layer_prefs_set() doesn't recognise is
        // silently dropped by its `if (isset($catalog[$id]))` guard.
        'units_live'      => ['label' => 'Units — live GPS', 'group' => 'Operational', 'default' => true],
        'facilities'      => ['label' => 'Facilities',       'group' => 'Operational', 'default' => true],
        'event_zones'     => ['label' => 'Event Zones',      'group' => 'Operational', 'default' => true],
        'markups'         => ['label' => 'Map Markups',      'group' => 'Operational', 'default' => false],
        'road_conditions' => ['label' => 'Road Conditions',  'group' => 'Operational', 'default' => false],

        // ── Weather ──
        // Weather Alerts are ON by default (situation.php added the group to the
        // map at construction); the weather TILE overlays were all off.
        'weather_alerts'  => ['label' => 'Weather Alerts',   'group' => 'Weather', 'default' => true],
        'radar'           => ['label' => 'Radar — Global',   'group' => 'Weather', 'default' => false],
        'radar_us'        => ['label' => 'Radar — US (NWS)', 'group' => 'Weather', 'default' => false],
        'temperature'     => ['label' => 'Temperature',      'group' => 'Weather', 'default' => false],
        'precipitation'   => ['label' => 'Precipitation',    'group' => 'Weather', 'default' => false],
        'rain'            => ['label' => 'Rain',             'group' => 'Weather', 'default' => false],
        'snow'            => ['label' => 'Snow',             'group' => 'Weather', 'default' => false],
        'wind'            => ['label' => 'Wind',             'group' => 'Weather', 'default' => false],
        'clouds'          => ['label' => 'Clouds',           'group' => 'Weather', 'default' => false],
        'pressure'        => ['label' => 'Pressure',         'group' => 'Weather', 'default' => false],
        'city_weather'    => ['label' => 'City Weather',     'group' => 'Weather', 'default' => false],

        // ── Reference ──
        'grid'            => ['label' => 'Grid (graticule)', 'group' => 'Reference', 'default' => false],
    ];
}

/**
 * The org-wide default visibility map: shipped defaults with the administrator's
 * `settings.map_layer_defaults` overrides applied.
 *
 * Unknown ids in the stored JSON are ignored rather than trusted — a layer that
 * no longer exists must not reappear in the payload, and a typo must not create
 * a phantom layer.
 *
 * @return array<string, bool>   id => visible
 */
function map_layer_admin_defaults(): array {
    $catalog = map_layer_catalog();
    $out = [];
    foreach ($catalog as $id => $meta) {
        $out[$id] = (bool) $meta['default'];
    }

    // Settings live in the `settings` table (name/value) and are read with
    // get_variable(). NOT get_setting() — that is the separate `config` store
    // the Settings UI never writes, and crossing the two makes an admin toggle
    // read as its default forever.
    if (!function_exists('get_variable')) return $out;

    try {
        $raw = get_variable(MAP_LAYER_PREFS_SETTING);
    } catch (Throwable $e) {
        return $out;
    }
    if ($raw === false || $raw === null || $raw === '') return $out;

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) return $out;

    foreach ($decoded as $id => $vis) {
        if (isset($out[$id])) {
            $out[$id] = _map_layer_truthy($vis);
        }
    }
    return $out;
}

/**
 * Coerce whatever came out of JSON / a form post into a boolean.
 * Strings matter: json_decode of `"false"` is the string "false", which is
 * truthy in PHP, and that would turn every layer on.
 */
function _map_layer_truthy($v): bool {
    if (is_bool($v))   return $v;
    if (is_int($v))    return $v !== 0;
    if (is_float($v))  return $v != 0.0;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'off');
    }
    return (bool) $v;
}

/**
 * Build the $defaults structure prefs_get() merges against: the admin default
 * expressed in the {id,label,visible,pos} column shape screen-prefs speaks.
 */
function _map_layer_defaults_struct(): array {
    $catalog = map_layer_catalog();
    $admin   = map_layer_admin_defaults();
    $cols = [];
    $pos = 0;
    foreach ($catalog as $id => $meta) {
        $cols[] = [
            'id'      => $id,
            'label'   => $meta['label'],
            'visible' => $admin[$id] ?? (bool) $meta['default'],
            'pos'     => $pos++,
        ];
    }
    return ['columns' => $cols, 'sort' => ['col' => '', 'dir' => 'asc'], 'options' => []];
}

/**
 * The EFFECTIVE layer visibility for one user: shipped default, then admin
 * default, then that user's own overrides.
 *
 * Degrades to the admin default (never to an error, never to a blank map) if
 * the prefs table is missing or unreadable — prefs_get() already swallows the
 * query failure and returns the defaults it was handed.
 *
 * @return array{
 *   layers: array<int, array{id:string,label:string,group:string,visible:bool,default:bool,overridden:bool}>,
 *   visible: array<string,bool>,
 *   defaults: array<string,bool>,
 *   has_overrides: bool
 * }
 */
function map_layer_prefs_get(int $userId): array {
    $catalog  = map_layer_catalog();
    $defaults = map_layer_admin_defaults();

    $merged = ['columns' => []];
    if ($userId > 0) {
        $merged = prefs_get($userId, MAP_LAYER_PREFS_SCREEN, _map_layer_defaults_struct());
    } else {
        // Not signed in (or no session yet): the admin default IS the answer.
        $merged = _map_layer_defaults_struct();
    }

    $visibleById = [];
    foreach ($merged['columns'] as $c) {
        if (isset($c['id']) && isset($catalog[$c['id']])) {
            $visibleById[$c['id']] = !empty($c['visible']);
        }
    }

    $layers = [];
    $hasOverrides = false;
    foreach ($catalog as $id => $meta) {
        $def = $defaults[$id] ?? (bool) $meta['default'];
        $vis = array_key_exists($id, $visibleById) ? $visibleById[$id] : $def;
        if ($vis !== $def) $hasOverrides = true;
        $layers[] = [
            'id'         => $id,
            'label'      => $meta['label'],
            'group'      => $meta['group'],
            'visible'    => $vis,
            'default'    => $def,
            'overridden' => $vis !== $def,
        ];
        $visibleById[$id] = $vis;
    }

    return [
        'layers'        => $layers,
        'visible'       => $visibleById,
        'defaults'      => $defaults,
        'has_overrides' => $hasOverrides,
    ];
}

/**
 * The compact id=>bool map the browser needs, nothing else. Used by navbar.php
 * to inject window.MAP_LAYER_PREFS synchronously.
 *
 * @return array{layers:array<string,bool>, defaults:array<string,bool>}
 */
function map_layer_prefs_for_js(int $userId): array {
    $p = map_layer_prefs_get($userId);
    return ['layers' => $p['visible'], 'defaults' => $p['defaults']];
}

/**
 * Persist one user's overrides. $visibility is a partial id => truthy map;
 * ids not present keep whatever they already resolve to, so a surface that
 * only knows about four layers can save without clobbering the other fifteen.
 *
 * Returns false on failure — the caller logs. Never throws: a preference that
 * cannot be saved must not take a dispatcher's map down with it.
 */
function map_layer_prefs_set(int $userId, array $visibility): bool {
    if ($userId <= 0) return false;
    $catalog = map_layer_catalog();

    // Start from what this user currently resolves to, so a partial save is a
    // merge rather than a replace.
    $current = map_layer_prefs_get($userId)['visible'];

    foreach ($visibility as $id => $vis) {
        if (isset($catalog[$id])) {
            $current[$id] = _map_layer_truthy($vis);
        }
    }

    $cols = [];
    $pos = 0;
    foreach ($catalog as $id => $meta) {
        $cols[] = [
            'id'      => $id,
            'label'   => $meta['label'],
            'visible' => $current[$id] ?? (bool) $meta['default'],
            'pos'     => $pos++,
        ];
    }

    return prefs_set($userId, MAP_LAYER_PREFS_SCREEN, [
        'columns' => $cols,
        'sort'    => ['col' => '', 'dir' => 'asc'],
        'options' => [],
    ]);
}

/** Drop this user's overrides so the administrator default applies again. */
function map_layer_prefs_reset(int $userId): bool {
    if ($userId <= 0) return false;
    return prefs_reset($userId, MAP_LAYER_PREFS_SCREEN);
}

/**
 * Write the org-wide default. Admin-gated by the CALLER (api/map-layer-prefs.php
 * checks action.manage_config) — this function does no authorisation of its own.
 *
 * Stored as JSON in `settings`.map_layer_defaults so it is read back by
 * get_variable(), the same store the Settings UI writes.
 */
function map_layer_prefs_set_admin_defaults(array $visibility): bool {
    $catalog = map_layer_catalog();
    $store = [];
    foreach ($catalog as $id => $meta) {
        $store[$id] = array_key_exists($id, $visibility)
            ? _map_layer_truthy($visibility[$id])
            : (bool) $meta['default'];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [MAP_LAYER_PREFS_SETTING, json_encode($store)]
        );
        return true;
    } catch (Exception $e) {
        error_log('[map-layer-prefs] admin default save failed: ' . $e->getMessage());
        return false;
    }
}
