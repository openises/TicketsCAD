<?php
/**
 * NewUI v4.0 API - Proximity Warnings
 *
 * GET /api/proximity-warnings.php?lat=XX.XXXX&lng=YY.YYYY
 *   Returns location warnings within the configured proximity radius.
 *   Uses the Haversine formula to calculate distance between coordinates.
 *
 * Response:
 *   { "warnings": [ { id, title, street, city, state, description, distance, created_by, created_on } ] }
 *
 * Radius logic (maps-comprehensive-2026-06):
 *   Each warn location stores its own `radius` (METERS) via the Warn Locations
 *   editor. When that per-row radius is set (> 0) it drives the alert for that
 *   hazard. When it's null/0, we fall back to the GLOBAL default below so old
 *   rows that predate the per-location field keep their prior behavior.
 *
 * Settings used (global fallback only):
 *   warn_proximity       — radius in tenths of a mile/km (e.g. 10 = 1.0 mile)
 *   warn_proximity_units — 'M' (miles), 'K' (km), 'N' (nautical miles)
 */

require_once __DIR__ . '/auth.php';

// Suppress PHP warnings from corrupting JSON output
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// ── Validate input ──
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;

if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) {
    json_response(['warnings' => []]);
}

// ── Global fallback config ──
// The global default is expressed in whole display units (miles/km/nm). We
// convert it to METERS once, up front, so every distance comparison happens
// in a single unit (meters) regardless of which hazard's radius is in play.
$globalProximityUnits = intval(get_variable('warn_proximity')) / 10; // tenths → whole units
$unit = strtoupper(get_variable('warn_proximity_units') ?: 'M');
$globalDefaultMeters = proximity_units_to_meters($globalProximityUnits, $unit);

if ($globalDefaultMeters <= 0) {
    // Global default disabled. Per-location radii still fire on their own, so
    // only bail out entirely when there is no fallback AND nothing can match.
    // We continue: rows with their own radius are still evaluated below.
    $globalDefaultMeters = 0;
}

/**
 * Convert a distance expressed in display units to meters.
 * 'M' miles, 'K' km, 'N' nautical miles.
 */
function proximity_units_to_meters($value, $unit) {
    $value = (float) $value;
    if ($value <= 0) return 0.0;
    switch (strtoupper((string) $unit)) {
        case 'K': return $value * 1000.0;          // km → m
        case 'N': return $value * 1852.0;          // nautical miles → m
        case 'M':
        default:  return $value * 1609.344;        // statute miles → m
    }
}

/** Human-readable unit label for the display badge. */
function proximity_unit_label($unit) {
    switch (strtoupper((string) $unit)) {
        case 'K': return 'km';
        case 'N': return 'nm';
        case 'M':
        default:  return 'mi';
    }
}

// ── Haversine distance in METERS ──
function haversine_meters($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 == 0 || $lon1 == 0 || $lat2 == 0 || $lon2 == 0) {
        return PHP_FLOAT_MAX;
    }
    $earthRadiusM = 6371000.0; // mean Earth radius, meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt(min(1.0, $a)), sqrt(max(0.0, 1.0 - $a)));
    return $earthRadiusM * $c;
}

/** Convert meters back to the display unit for the response badge. */
function proximity_meters_to_units($meters, $unit) {
    $meters = (float) $meters;
    switch (strtoupper((string) $unit)) {
        case 'K': return $meters / 1000.0;
        case 'N': return $meters / 1852.0;
        case 'M':
        default:  return $meters / 1609.344;
    }
}

// ── Fetch all warnings ──
// Prefer the query that includes the per-location `radius` column. Older
// installations may not have it yet; fall back to the radius-less query so
// nothing crashes (those rows then use the global default).
try {
    $rows = db_fetch_all(
        "SELECT `id`, `title`, `street`, `city`, `state`, `lat`, `lng`,
                `radius`, `description`, `_by`, `_on`
         FROM " . db_table('warnings') . "
         ORDER BY `id`"
    );
} catch (Exception $e) {
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `title`, `street`, `city`, `state`, `lat`, `lng`,
                    `description`, `_by`, `_on`
             FROM " . db_table('warnings') . "
             ORDER BY `id`"
        );
    } catch (Exception $e2) {
        // warnings table may not exist at all
        ini_set('display_errors', $prevDisplay);
        json_response(['warnings' => []]);
    }
}

// ── Filter by proximity ──
$warnings = [];
$unitLabel = proximity_unit_label($unit);
foreach ($rows as $row) {
    $distMeters = haversine_meters(
        $lat, $lng,
        floatval($row['lat']), floatval($row['lng'])
    );

    // Per-location radius (meters) wins when set; otherwise the global default.
    $rowRadius = isset($row['radius']) ? (float) $row['radius'] : 0.0;
    $effectiveMeters = $rowRadius > 0 ? $rowRadius : $globalDefaultMeters;

    // If neither a per-row radius nor a global default is configured, this
    // hazard has no active alert radius — skip it (matches prior "disabled").
    if ($effectiveMeters <= 0) {
        continue;
    }

    if ($distMeters < $effectiveMeters) {
        $warnings[] = [
            'id'           => intval($row['id']),
            'title'        => $row['title'] ?? '',
            'street'       => $row['street'] ?? '',
            'city'         => $row['city'] ?? '',
            'state'        => $row['state'] ?? '',
            'description'  => $row['description'] ?? '',
            // Distance shown in the admin's chosen display unit, plus the
            // unit label so the new-incident banner no longer hard-codes "mi".
            'distance'     => round(proximity_meters_to_units($distMeters, $unit), 1),
            'distance_m'   => round($distMeters, 1),
            'unit'         => $unitLabel,
            'radius_m'     => round($effectiveMeters, 1),
            'radius_source' => $rowRadius > 0 ? 'location' : 'global',
            'created_by'   => $row['_by'] ?? '',
            'created_on'   => $row['_on'] ?? ''
        ];
    }
}

// Sort by distance ascending (meters, the canonical comparison unit)
usort($warnings, function ($a, $b) {
    return $a['distance_m'] <=> $b['distance_m'];
});

ini_set('display_errors', $prevDisplay);

// Top-level `unit` lets the client label the badge once for all rows.
json_response(['warnings' => $warnings, 'unit' => $unitLabel]);
