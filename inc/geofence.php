<?php
/**
 * NewUI v4.0 — Geofence Helper
 *
 * Pure-PHP geofence engine: ray-casting point-in-polygon, circle distance
 * check, unit state tracking, and event firing (SSE + broker).
 *
 * USAGE:
 *   require_once __DIR__ . '/geofence.php';
 *
 *   // Check a single point against all active geofences and update state
 *   $events = geofence_check(44.95, -93.25, 'UNIT-7');
 *   // Returns array of state-change events (enter/exit)
 *
 *   // Point-in-polygon test only (no side effects)
 *   $inside = geofence_point_in_polygon($lat, $lng, $polygonCoords);
 *
 *   // Circle containment test only
 *   $inside = geofence_point_in_circle($lat, $lng, $centerLat, $centerLng, $radiusMeters);
 */

require_once __DIR__ . '/sse.php';
require_once __DIR__ . '/broker.php';

// ── Point-in-Polygon (ray-casting algorithm) ────────────────

/**
 * Test whether a point is inside a polygon using the ray-casting algorithm.
 *
 * The polygon is an array of [lng, lat] coordinate pairs (GeoJSON order).
 * The first and last point should be the same (closed ring), but we handle
 * unclosed rings gracefully.
 *
 * @param float $lat   Test point latitude
 * @param float $lng   Test point longitude
 * @param array $ring  Array of [lng, lat] pairs (GeoJSON coordinate order)
 * @return bool
 */
function geofence_point_in_polygon($lat, $lng, array $ring) {
    $n = count($ring);
    if ($n < 3) return false;

    // Ensure the ring is closed
    $first = $ring[0];
    $last  = $ring[$n - 1];
    if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $ring[] = $first;
        $n++;
    }

    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        // ring[i] = [lng, lat] — GeoJSON order
        $yi = $ring[$i][1]; // lat
        $xi = $ring[$i][0]; // lng
        $yj = $ring[$j][1];
        $xj = $ring[$j][0];

        // Ray-casting: does a horizontal ray from (lng, lat) cross this edge?
        if (($yi > $lat) !== ($yj > $lat)) {
            $xIntersect = $xi + ($lat - $yi) / ($yj - $yi) * ($xj - $xi);
            if ($lng < $xIntersect) {
                $inside = !$inside;
            }
        }
    }

    return $inside;
}

// ── Point-in-Circle (Haversine distance) ─────────────────────

/**
 * Test whether a point is within a circle defined by centre + radius.
 *
 * @param float $lat          Test point latitude
 * @param float $lng          Test point longitude
 * @param float $centerLat    Circle centre latitude
 * @param float $centerLng    Circle centre longitude
 * @param float $radiusMeters Radius in metres
 * @return bool
 */
function geofence_point_in_circle($lat, $lng, $centerLat, $centerLng, $radiusMeters) {
    $dist = _geofence_haversine($lat, $lng, $centerLat, $centerLng);
    return $dist <= $radiusMeters;
}

/**
 * Haversine distance in metres between two lat/lng points.
 */
function _geofence_haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; // Earth radius in metres
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// ── Test a point against a single geofence GeoJSON ──────────

/**
 * Test whether a lat/lng is inside a geofence's geometry.
 *
 * Supports GeoJSON types: Polygon, MultiPolygon, and a custom Circle
 * extension (type=Circle, center=[lng,lat], radius=metres).
 *
 * @param float  $lat     Test point latitude
 * @param float  $lng     Test point longitude
 * @param string $geojson Raw GeoJSON string from the markup
 * @return bool
 */
function geofence_test_point($lat, $lng, $geojson) {
    $geo = is_string($geojson) ? json_decode($geojson, true) : $geojson;
    if (!$geo || !isset($geo['type'])) return false;

    $type = $geo['type'];

    // Circle (custom extension — stored as markup_type='circle' with
    // geojson = {"type":"Circle","center":[lng,lat],"radius":1000})
    if ($type === 'Circle' || $type === 'circle') {
        $center = $geo['center'] ?? null;
        $radius = isset($geo['radius']) ? (float) $geo['radius'] : 0;
        if (!$center || $radius <= 0) return false;
        return geofence_point_in_circle($lat, $lng, $center[1], $center[0], $radius);
    }

    // Polygon — coordinates is [ ring, ...holes ]
    if ($type === 'Polygon') {
        $coords = $geo['coordinates'] ?? [];
        if (empty($coords)) return false;
        // Test outer ring (index 0). We ignore holes for simplicity.
        return geofence_point_in_polygon($lat, $lng, $coords[0]);
    }

    // MultiPolygon — coordinates is [ polygon, polygon, ... ]
    if ($type === 'MultiPolygon') {
        $polys = $geo['coordinates'] ?? [];
        foreach ($polys as $polyCoords) {
            if (!empty($polyCoords[0]) && geofence_point_in_polygon($lat, $lng, $polyCoords[0])) {
                return true;
            }
        }
        return false;
    }

    // Feature / FeatureCollection wrappers
    if ($type === 'Feature' && isset($geo['geometry'])) {
        return geofence_test_point($lat, $lng, $geo['geometry']);
    }
    if ($type === 'FeatureCollection' && isset($geo['features'])) {
        foreach ($geo['features'] as $feature) {
            if (geofence_test_point($lat, $lng, $feature)) {
                return true;
            }
        }
        return false;
    }

    return false;
}

// ── Core: check point against ALL active geofences ──────────

/**
 * Check a lat/lng point against all active geofences, update unit state,
 * and fire events on state changes.
 *
 * This is the main entry point called by the location API on each report.
 *
 * @param float  $lat           Latitude
 * @param float  $lng           Longitude
 * @param string $unitId        Unit identifier (same as location_reports.unit_identifier)
 * @return array  List of state-change events [{geofence_id, geofence_name, event, unit}]
 */
function geofence_check($lat, $lng, $unitId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $events = [];

    // Load all active geofences with their markup data
    // Uses legacy mmarkup table (where the map-markups API saves shapes)
    try {
        $fences = db_fetch_all(
            "SELECT g.`id`, g.`name`, g.`alert_on_enter`, g.`alert_on_exit`,
                    g.`alert_channels_json`, g.`notify_users_json`,
                    m.`line_data`, m.`line_type`, m.`line_ident`
             FROM `{$prefix}geofences` g
             JOIN `{$prefix}mmarkup` m ON g.`markup_id` = m.`id`
             WHERE g.`active` = 1"
        );
    } catch (Exception $e) {
        return $events; // Tables might not exist yet
    }

    if (empty($fences)) return $events;

    $now = date('Y-m-d H:i:s');

    foreach ($fences as $fence) {
        $fenceId   = (int) $fence['id'];
        $fenceName = $fence['name'];

        // Convert legacy mmarkup format to GeoJSON for the test function
        $geojson = _geofence_mmarkup_to_geojson(
            $fence['line_data'] ?? '[]',
            $fence['line_type'] ?? 'P',
            $fence['line_ident'] ?? ''
        );

        // Determine if point is inside this fence
        $isInside = geofence_test_point($lat, $lng, $geojson);

        // Get current tracked state
        $prevState = _geofence_get_state($fenceId, $unitId);
        $wasInside = ($prevState === 'inside');

        // Detect transitions
        if ($isInside && !$wasInside) {
            // ENTER event
            _geofence_set_state($fenceId, $unitId, 'inside', $now, null);
            if ((int) $fence['alert_on_enter']) {
                $evt = [
                    'geofence_id'   => $fenceId,
                    'geofence_name' => $fenceName,
                    'event'         => 'enter',
                    'unit'          => $unitId,
                    'lat'           => $lat,
                    'lng'           => $lng,
                    'at'            => $now
                ];
                $events[] = $evt;
                _geofence_fire_event($fence, $evt);
            }
        } elseif (!$isInside && $wasInside) {
            // EXIT event
            _geofence_set_state($fenceId, $unitId, 'outside', null, $now);
            if ((int) $fence['alert_on_exit']) {
                $evt = [
                    'geofence_id'   => $fenceId,
                    'geofence_name' => $fenceName,
                    'event'         => 'exit',
                    'unit'          => $unitId,
                    'lat'           => $lat,
                    'lng'           => $lng,
                    'at'            => $now
                ];
                $events[] = $evt;
                _geofence_fire_event($fence, $evt);
            }
        }
        // No transition: no event fired
    }

    return $events;
}

// ── State Tracking (DB) ──────────────────────────────────────

/**
 * Get the current state of a unit relative to a geofence.
 *
 * @return string 'inside', 'outside', or 'outside' (default if no record)
 */
function _geofence_get_state($fenceId, $unitId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $val = db_fetch_value(
            "SELECT `state` FROM `{$prefix}geofence_unit_state`
             WHERE `geofence_id` = ? AND `unit_identifier` = ?",
            [$fenceId, $unitId]
        );
        return $val ?: 'outside';
    } catch (Exception $e) {
        return 'outside';
    }
}

/**
 * Upsert the state of a unit relative to a geofence.
 */
function _geofence_set_state($fenceId, $unitId, $state, $enteredAt, $exitedAt) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');

    try {
        // Try INSERT first, ON DUPLICATE KEY UPDATE
        if ($state === 'inside') {
            db_query(
                "INSERT INTO `{$prefix}geofence_unit_state`
                 (`geofence_id`, `unit_identifier`, `state`, `entered_at`, `updated_at`)
                 VALUES (?, ?, 'inside', ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `state` = 'inside',
                    `entered_at` = VALUES(`entered_at`),
                    `exited_at` = NULL,
                    `updated_at` = VALUES(`updated_at`)",
                [$fenceId, $unitId, $enteredAt, $now]
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}geofence_unit_state`
                 (`geofence_id`, `unit_identifier`, `state`, `exited_at`, `updated_at`)
                 VALUES (?, ?, 'outside', ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `state` = 'outside',
                    `exited_at` = VALUES(`exited_at`),
                    `updated_at` = VALUES(`updated_at`)",
                [$fenceId, $unitId, $exitedAt, $now]
            );
        }
    } catch (Exception $e) {
        // State tracking is best-effort
    }
}

// ── Event Dispatch (SSE + Broker) ────────────────────────────

/**
 * Fire SSE event and optionally route through broker channels.
 */
function _geofence_fire_event(array $fence, array $evt) {
    // SSE event for real-time UI update.
    // F-007 long-tail fix: scope the event by the responder that triggered
    // the fence, so users in groups not allocated to that responder don't
    // see their movements. Falls back to admin-only if we can't resolve a
    // responder for the unit identifier — fail-closed.
    $sseType = 'geofence:' . $evt['event']; // geofence:enter or geofence:exit
    $unitId  = (string) ($evt['unit'] ?? '');
    $responderId = 0;
    if ($unitId !== '') {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            // Best-effort: match by unit_location_bindings, then fall back to
            // responder.callsign or responder.handle.
            $row = db_fetch_one(
                "SELECT responder_id FROM `{$prefix}unit_location_bindings`
                 WHERE unit_identifier = ? AND active = 1 LIMIT 1",
                [$unitId]
            );
            if (!$row) {
                $row = db_fetch_one(
                    "SELECT id AS responder_id FROM `{$prefix}responder`
                     WHERE callsign = ? OR handle = ? LIMIT 1",
                    [$unitId, $unitId]
                );
            }
            $responderId = (int) ($row['responder_id'] ?? 0);
        } catch (Exception $e) {
            $responderId = 0;
        }
    }
    if ($responderId > 0 && function_exists('sse_publish_for_responder')) {
        sse_publish_for_responder($sseType, $evt, $responderId);
    } elseif (function_exists('sse_publish_for_admin')) {
        sse_publish_for_admin($sseType, $evt);
    } else {
        sse_publish($sseType, $evt);
    }

    // Broker channels (if configured)
    $channels = json_decode($fence['alert_channels_json'], true);
    if (!empty($channels) && is_array($channels)) {
        $body = sprintf(
            'Geofence %s: unit %s %s "%s" at %s',
            strtoupper($evt['event']),
            $evt['unit'],
            $evt['event'] === 'enter' ? 'entered' : 'exited',
            $evt['geofence_name'],
            $evt['at']
        );
        $message = [
            'to'       => 'all',
            'body'     => $body,
            'type'     => 'geofence_alert',
            'priority' => 'high',
            'data'     => $evt
        ];
        foreach ($channels as $ch) {
            broker_send($ch, $message);
        }
    }
}

// ── Utility: list units inside a geofence ────────────────────

/**
 * Get all units currently inside a specific geofence.
 *
 * @param int $fenceId
 * @return array
 */
function geofence_units_inside($fenceId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT `unit_identifier`, `entered_at`, `updated_at`
             FROM `{$prefix}geofence_unit_state`
             WHERE `geofence_id` = ? AND `state` = 'inside'
             ORDER BY `entered_at` ASC",
            [$fenceId]
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Count units currently inside a specific geofence.
 *
 * @param int $fenceId
 * @return int
 */
function geofence_count_inside($fenceId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}geofence_unit_state`
             WHERE `geofence_id` = ? AND `state` = 'inside'",
            [$fenceId]
        );
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Convert legacy mmarkup format to GeoJSON for geofence_test_point().
 *
 * mmarkup stores:
 *   line_data:  JSON array of [lat, lng] pairs (note: lat first, NOT GeoJSON order)
 *   line_type:  'P' (polygon), 'C' (circle), 'L' (line), 'M' (marker)
 *   line_ident: radius in meters (for circles)
 *
 * GeoJSON expects [lng, lat] order for coordinates.
 *
 * @param string $lineData  JSON array of coordinates
 * @param string $lineType  Shape type (P/C/L/M)
 * @param string $lineIdent Radius for circles
 * @return array|string GeoJSON structure for geofence_test_point()
 */
function _geofence_mmarkup_to_geojson($lineData, $lineType, $lineIdent = '') {
    $coords = json_decode($lineData, true);
    if (!is_array($coords) || empty($coords)) {
        return ['type' => 'Polygon', 'coordinates' => [[]]];
    }

    $type = strtoupper(substr($lineType, 0, 1));

    if ($type === 'C') {
        // Circle: center is first coordinate, radius from line_ident
        $center = $coords[0];
        $radius = (float) $lineIdent;
        return [
            'type'   => 'Circle',
            'center' => [
                isset($center[1]) ? (float) $center[1] : 0,  // lng
                isset($center[0]) ? (float) $center[0] : 0,  // lat
            ],
            'radius' => $radius > 0 ? $radius : 500,
        ];
    }

    if ($type === 'P') {
        // Polygon: convert [lat,lng] pairs to GeoJSON [lng,lat] ring
        $ring = [];
        foreach ($coords as $pt) {
            $ring[] = [
                isset($pt[1]) ? (float) $pt[1] : 0,  // lng
                isset($pt[0]) ? (float) $pt[0] : 0,  // lat
            ];
        }
        // Close the ring if not already closed
        if (!empty($ring) && ($ring[0] !== $ring[count($ring) - 1])) {
            $ring[] = $ring[0];
        }
        return [
            'type'        => 'Polygon',
            'coordinates' => [$ring],
        ];
    }

    // Lines and markers can't be geofences meaningfully
    return ['type' => 'Polygon', 'coordinates' => [[]]];
}
