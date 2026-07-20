<?php
/**
 * maps-comprehensive-2026-06 — Feature 2: warn-location alert radius.
 *
 * Verifies the proximity-warnings fix:
 *   - per-location `warnings.radius` (meters) drives the alert when set (>0)
 *   - null/0 radius falls back to the global default (warn_proximity + units)
 *   - Haversine distance math is correct (meters)
 *   - unit-to-meters and meters-to-unit conversions round-trip
 *   - the new-incident banner honors the returned unit label (no hard "mi")
 *
 * DB-independent: re-implements the math the way api/proximity-warnings.php
 * does and asserts the source file wires it up. No MySQL required.
 */

declare(strict_types=1);

$pass = 0; $fail = 0; $failures = [];
function assertTrue(bool $v, string $what): void {
    global $pass, $fail, $failures;
    if ($v) { $pass++; return; }
    $fail++; $failures[] = "FAIL: $what";
}
function assertNear(float $a, float $b, float $tol, string $what): void {
    global $pass, $fail, $failures;
    if (abs($a - $b) <= $tol) { $pass++; return; }
    $fail++; $failures[] = "FAIL: $what (got $a, expected ~$b ±$tol)";
}

// ── Reference implementations (mirror api/proximity-warnings.php) ──
function _t_haversine_meters($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 == 0 || $lon1 == 0 || $lat2 == 0 || $lon2 == 0) return PHP_FLOAT_MAX;
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt(min(1.0, $a)), sqrt(max(0.0, 1.0 - $a)));
    return $R * $c;
}
function _t_units_to_meters($v, $u) {
    $v = (float) $v; if ($v <= 0) return 0.0;
    switch (strtoupper((string) $u)) {
        case 'K': return $v * 1000.0;
        case 'N': return $v * 1852.0;
        default:  return $v * 1609.344;
    }
}

// ── Haversine sanity: ~1 km apart in latitude ──
// 0.0089982° latitude ≈ 1000 m. Use a known pair near 44.95N.
$d = _t_haversine_meters(44.95, -93.25, 44.95 + 0.0089982, -93.25);
assertNear($d, 1000.0, 5.0, 'haversine: ~1 km north is ~1000 m');

// Same point → ~0 m
$d0 = _t_haversine_meters(44.95, -93.25, 44.95, -93.25);
assertNear($d0, 0.0, 1.0, 'haversine: same point is ~0 m');

// ── Unit conversions ──
assertNear(_t_units_to_meters(1.0, 'M'), 1609.344, 0.01, '1 mile = 1609.344 m');
assertNear(_t_units_to_meters(1.0, 'K'), 1000.0, 0.01, '1 km = 1000 m');
assertNear(_t_units_to_meters(1.0, 'N'), 1852.0, 0.01, '1 nm = 1852 m');
// Legacy tenths: warn_proximity=10 + Miles → 1.0 mile → 1609.344 m
assertNear(_t_units_to_meters(10 / 10, 'M'), 1609.344, 0.01, 'legacy 10 tenths-mile = 1.0 mi = 1609.344 m');

// ── Effective-radius selection logic ──
// per-row radius wins when > 0; else global default.
function _t_effective($rowRadius, $globalMeters) {
    $r = (float) $rowRadius;
    return $r > 0 ? $r : $globalMeters;
}
assertTrue(_t_effective(250, 1609.344) === 250.0, 'per-location radius 250 m overrides global');
assertTrue(_t_effective(0, 1609.344) === 1609.344, 'radius 0 falls back to global');
assertTrue(_t_effective(null, 1609.344) === 1609.344, 'radius null falls back to global');
// A 300m hazard radius vs a point 1000m away → NOT in range (regression from
// the old behavior where only the global 0.1mi=161m global ever applied).
$dist = _t_haversine_meters(44.95, -93.25, 44.95 + 0.0089982, -93.25); // ~1000 m
assertTrue($dist >= _t_effective(300, 0), 'point 1000m away is outside a 300m hazard radius');
assertTrue($dist <  _t_effective(1500, 0), 'point 1000m away is inside a 1500m hazard radius');

// ── Source-file wiring assertions ──
$api = file_get_contents(__DIR__ . '/../api/proximity-warnings.php');
assertTrue($api !== false, 'proximity-warnings.php readable');
assertTrue(strpos($api, 'haversine_meters') !== false, 'API computes distance in meters');
assertTrue(strpos($api, "\$row['radius']") !== false, 'API reads per-location radius');
assertTrue(strpos($api, 'proximity_units_to_meters') !== false, 'API converts global default to meters');
assertTrue(strpos($api, "'radius_source'") !== false, 'API reports which radius drove the match');
assertTrue(strpos($api, "'unit'") !== false, 'API returns a unit label for the banner');
// Defensive fallback query for installs without the radius column.
assertTrue(substr_count($api, 'FROM " . db_table(\'warnings\')') >= 2
        || substr_count($api, 'db_table(\'warnings\')') >= 2, 'API has a radius-less fallback query');

// ── new-incident banner honors the unit label ──
$js = file_get_contents(__DIR__ . '/../assets/js/new-incident.js');
assertTrue($js !== false, 'new-incident.js readable');
assertTrue(strpos($js, "(w.unit || 'mi')") !== false, 'banner uses returned unit label, not hard-coded mi');

// ── settings.php exposes the global default control ──
$settings = file_get_contents(__DIR__ . '/../settings.php');
assertTrue(strpos($settings, 'data-warn-setting="warn_proximity"') !== false, 'settings has warn_proximity control');
assertTrue(strpos($settings, 'data-warn-setting="warn_proximity_units"') !== false, 'settings has units control');
assertTrue(strpos($settings, 'btnSaveWarnProximity') !== false, 'settings has a save button');

// ── config.js wires the load/save ──
$cfg = file_get_contents(__DIR__ . '/../assets/js/config.js');
assertTrue(strpos($cfg, 'bindWarnProximityDefault') !== false, 'config.js binds the global-default control');
assertTrue(strpos($cfg, "section=settings") !== false, 'config.js saves via the settings section');

// ── Report ──
echo "Feature 2 — warn-location alert radius tests\n";
echo "============================================\n";
if ($fail > 0) {
    foreach ($failures as $m) echo "$m\n";
}
// Runner-compatible results line ("N passed, M failed").
echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
