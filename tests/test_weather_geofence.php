<?php
/**
 * Phase 112 Phase 4 — weather geofence cross-ref.
 *
 * "Units Alpha and Delta are inside the Tornado Warning" — cross-reference an
 * alert's storm polygon against live unit positions (and the active event's
 * zone geometry from Phase 109). Tests the engine with INJECTED units/zones
 * (no live location data needed), the config gate, and the API/situation/admin
 * wiring.
 *
 * Usage: php tests/test_weather_geofence.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/weather_alerts.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 112 Phase 4 — weather geofence cross-ref ===\n\n";

// A storm polygon over downtown Minneapolis (roughly 44.95–45.00 N, 93.30–93.20 W).
$alert = [
    'polygon' => '{"type":"Polygon","coordinates":[[[-93.30,45.00],[-93.20,45.00],[-93.20,44.95],[-93.30,44.95],[-93.30,45.00]]]}',
    'centroid_lat' => 44.975, 'centroid_lng' => -93.25,
];
$units = [
    ['responder_id' => 1, 'unit_identifier' => 'Alpha',  'lat' => 44.98,  'lng' => -93.27],  // inside
    ['responder_id' => 2, 'unit_identifier' => 'Delta',  'lat' => 44.97,  'lng' => -93.25],  // inside
    ['responder_id' => 3, 'unit_identifier' => 'Echo',   'lat' => 46.78,  'lng' => -92.10],  // Duluth — outside
    ['responder_id' => 4, 'unit_identifier' => 'NoFix',  'lat' => 0,      'lng' => 0],       // no position
];

// ── Config gate ─────────────────────────────────────────────────────────────
$orig = weather_setting('weather_geofence_units', '1');
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_geofence_units','1')
          ON DUPLICATE KEY UPDATE `value`='1'");
t('geofence cross-ref defaults ON', weather_geofence_enabled());

// ── Units inside the polygon (injected) ─────────────────────────────────────
$inside = weather_units_in_alert($alert, $units);
$names = array_map(static function ($u) { return $u['unit_identifier']; }, $inside);
t('units INSIDE the storm polygon are flagged (Alpha, Delta)',
    count($inside) === 2 && in_array('Alpha', $names, true) && in_array('Delta', $names, true));
t('a unit outside (Echo) and a unit with no fix are NOT flagged',
    !in_array('Echo', $names, true) && !in_array('NoFix', $names, true));
t('an alert with NO polygon flags nothing',
    weather_units_in_alert(['polygon' => null], $units) === []);

// MultiPolygon support (the NWS shape for multi-cell warnings).
$multi = ['polygon' => '{"type":"MultiPolygon","coordinates":[[[[-93.30,45.00],[-93.20,45.00],[-93.20,44.95],[-93.30,44.95],[-93.30,45.00]]]]}'];
t('MultiPolygon alert geometry works',
    count(weather_units_in_alert($multi, $units)) === 2);

// ── Zones overlapping the polygon (injected) ────────────────────────────────
$zones = [
    // Zone with a vertex inside the warning polygon
    ['id' => 1, 'name' => 'Zone 3',  'geo_json' => '{"type":"Polygon","coordinates":[[[-93.28,44.99],[-93.26,44.99],[-93.26,44.97],[-93.28,44.97],[-93.28,44.99]]]}'],
    // Point zone inside
    ['id' => 2, 'name' => 'Parking', 'geo_json' => '{"type":"Point","coordinates":[-93.25,44.96]}'],
    // Zone far away (St. Cloud-ish)
    ['id' => 3, 'name' => 'Remote',  'geo_json' => '{"type":"Point","coordinates":[-94.16,45.56]}'],
];
$zin = weather_zones_in_alert($alert, $zones);
$znames = array_map(static function ($z) { return $z['name']; }, $zin);
t('zones overlapping the warning are flagged (Zone 3 + Parking)',
    count($zin) === 2 && in_array('Zone 3', $znames, true) && in_array('Parking', $znames, true));
t('a far-away zone is NOT flagged', !in_array('Remote', $znames, true));

// Small-warning-inside-big-zone: alert centroid inside the zone catches it.
$bigZone = [['id' => 9, 'name' => 'Whole Park',
    'geo_json' => '{"type":"Polygon","coordinates":[[[-93.40,45.05],[-93.10,45.05],[-93.10,44.90],[-93.40,44.90],[-93.40,45.05]]]}']];
$zin2 = weather_zones_in_alert($alert, $bigZone);
t('a small warning wholly inside a big zone still flags the zone (centroid check)',
    count($zin2) === 1 && $zin2[0]['name'] === 'Whole Park');

// ── OFF switch kills the whole cross-ref ───────────────────────────────────
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_geofence_units','0')
          ON DUPLICATE KEY UPDATE `value`='0'");
t('setting OFF ⇒ units_in_alert AND zones_in_alert return empty',
    weather_units_in_alert($alert, $units) === [] &&
    weather_zones_in_alert($alert, $zones) === []);
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_geofence_units',?)
          ON DUPLICATE KEY UPDATE `value`=?", [$orig ?: '1', $orig ?: '1']);

// ── Wiring guards ───────────────────────────────────────────────────────────
$api = rd($base . '/api/weather-alerts.php');
t('active-alerts API computes units_inside + zones_inside live',
    $api !== false &&
    strpos($api, 'weather_units_in_alert($a)') !== false &&
    strpos($api, 'weather_zones_in_alert($a)') !== false &&
    strpos($api, "'weather_geofence_units'") !== false);
$eng = rd($base . '/inc/weather_alerts.php');
t('tray notification names the units inside (units_inside in the SSE payload)',
    $eng !== false && strpos($eng, "'units_inside' => \$unitsInside") !== false);
t('active-alerts query returns polygon + centroid for the map',
    $eng !== false && (bool) preg_match('/`polygon`,`centroid_lat`,`centroid_lng`\s*\n\s*FROM `\{\$prefix\}weather_alerts`/', $eng));
$sit = rd($base . '/situation.php');
t('situation renders alert polygons w/ severity colours + units/zones in popup',
    $sit !== false &&
    strpos($sit, 'weatherAlertGroup') !== false &&
    strpos($sit, 'Weather Alerts') !== false &&
    strpos($sit, 'Units inside:') !== false &&
    strpos($sit, 'Zones affected:') !== false);
$adm = rd($base . '/weather-alerts.php');
t('admin page exposes the geofence toggle (wxGeofenceUnits)',
    $adm !== false && strpos($adm, 'id="wxGeofenceUnits"') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
