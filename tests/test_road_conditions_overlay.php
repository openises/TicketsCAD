<?php
/**
 * maps-comprehensive-2026-06 — Feature 1: road-conditions map overlay.
 *
 * Verifies:
 *   - api/road-conditions.php has a ?map=1 GET action that joins roadinfo with
 *     the condition icon/title/description and filters out rows with no lat/lng
 *   - the dashboard (app.js) registers a roadConditions L.layerGroup, adds it to
 *     the layer control, loads it, and wires the "Roads" control-bar button
 *   - the situation map (situation.php) registers + loads the same overlay
 *   - the marker CSS exists
 *
 * DB-independent: source-content assertions. No MySQL required.
 */

declare(strict_types=1);

$pass = 0; $fail = 0; $failures = [];
function ok(bool $v, string $what): void {
    global $pass, $fail, $failures;
    if ($v) { $pass++; return; }
    $fail++; $failures[] = "FAIL: $what";
}
function has(string $h, string $n, string $what): void { ok(strpos($h, $n) !== false, $what); }

$root = __DIR__ . '/..';

// ── API: ?map=1 endpoint ──
$api = file_get_contents($root . '/api/road-conditions.php');
ok($api !== false, 'road-conditions.php readable');
has($api, "isset(\$_GET['map'])", 'API has a ?map=1 action');
// Filters out reports with no coordinates.
has($api, "`lat` IS NOT NULL", 'API map query requires lat not null');
has($api, "`lng` IS NOT NULL", 'API map query requires lng not null');
has($api, "`lat` <> 0", 'API map query excludes lat 0');
// Joins the condition icon/title/description for marker + popup.
has($api, "c.`icon` AS `condition_icon`", 'API map query joins condition icon');
has($api, "c.`title` AS `condition_title`", 'API map query joins condition title');
has($api, "c.`description` AS `condition_description`", 'API map query joins condition description');
has($api, "'reports'", "API map response key is 'reports'");
// PDO prepared-statement / db helper usage (no raw concatenation of input).
has($api, 'db_fetch_all(', 'API uses db_fetch_all helper');
// CSRF + admin already enforced on writes (unchanged); confirm still present.
has($api, 'csrf_verify', 'API still enforces CSRF on writes');

// Lint the API for syntax errors.
$lint = shell_exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($root . '/api/road-conditions.php') . ' 2>&1');
ok(strpos((string) $lint, 'No syntax errors') !== false, 'road-conditions.php lints clean');

// GH#80 — server-side lat/lng bounds validation on the save handler (the
// coordinates this fix makes easier to populate from the browser).
has($api, '$lat < -90 || $lat > 90', 'API validates latitude bounds');
has($api, '$lng < -180 || $lng > 180', 'API validates longitude bounds');
has($api, '$lat !== 0.0', 'API bounds check explicitly allows the 0/0 "no location" sentinel through');

// ── Dashboard app.js wiring ──
$app = file_get_contents($root . '/assets/js/app.js');
ok($app !== false, 'app.js readable');
has($app, 'roadConditions', 'app.js registers a roadConditions layer key');
has($app, 'layerGroups.roadConditions = L.layerGroup()', 'app.js creates the roadConditions layerGroup');
has($app, 'Road Conditions', 'app.js adds Road Conditions to the layer control');
has($app, 'function loadRoadConditions', 'app.js has a loader function');
has($app, 'function addRoadConditionMarker', 'app.js builds markers + popups');
has($app, 'function toggleRoadConditions', 'app.js has the Roads-button toggle');
has($app, "action === 'road-conditions'", 'app.js wires the dead Roads button');
has($app, 'api/road-conditions.php?map=1', 'app.js fetches the map endpoint');
has($app, 'fitBounds', 'app.js focuses the view on the reports when toggled on');
// ES5 discipline: no arrow funcs / let / const introduced in our additions.
$ourBlock = substr($app, strpos($app, 'function loadRoadConditions'));
$ourBlock = substr($ourBlock, 0, strpos($ourBlock, 'function addMapSearch') !== false
    ? strpos($ourBlock, 'function addMapSearch') : strlen($ourBlock));
ok(strpos($ourBlock, '=>') === false, 'road-conditions JS uses no arrow functions (ES5)');

// ── Situation map wiring ──
$sit = file_get_contents($root . '/situation.php');
ok($sit !== false, 'situation.php readable');
has($sit, 'roadConditionsGroup', 'situation.php registers the overlay group');
has($sit, 'loadRoadConditionsOverlay', 'situation.php loads the overlay');
has($sit, 'Road Conditions', 'situation.php adds it to the layer control');
has($sit, 'api/road-conditions.php?map=1', 'situation.php fetches the map endpoint');

// ── Marker CSS ──
$css = file_get_contents($root . '/assets/css/dashboard.css');
has($css, '.road-condition-marker', 'dashboard.css defines the marker badge');

// ── GH#80 — address lookup on the Road Conditions form ──
// The reported gap: Latitude/Longitude were plain number inputs defaulting to
// 0 with no connection to the Address field. Reuses Geocode.search() exactly
// as the GH#39 Places-panel Lookup already does in this same file/page — no
// embedded map, no new API endpoint, no RBAC change.
$settingsSrc = file_get_contents($root . '/settings.php');
ok($settingsSrc !== false, 'settings.php readable');
has($settingsSrc, 'id="btnRoadCondLookup"', 'settings.php has the Lookup button');
has($settingsSrc, 'id="roadCondGeoStatus"', 'settings.php has the geocode status line');
has($settingsSrc, 'id="roadCondNoMapHint"', 'settings.php has the no-map-location hint');
// The pitfall this codebase has hit before: a <button> inside a <form> with
// no explicit type defaults to type=submit and reloads the page on click.
has($settingsSrc, 'type="button" class="btn btn-outline-secondary" id="btnRoadCondLookup"',
    'the Lookup button is explicitly type="button" (not a page-reloading submit)');

$cfgJs = file_get_contents($root . '/assets/js/config.js');
ok($cfgJs !== false, 'config.js readable');
has($cfgJs, 'function roadCondGeocodeLookup', 'config.js has the road-conditions lookup function');
has($cfgJs, 'function updateRoadCondMapHint', 'config.js has the no-map-hint updater');
// Reuses the shared Geocode helper from the road-conditions section of this
// file — never a hardcoded geocoder call. Scope the check to the section
// itself so a Geocode.search() call anywhere else in this large file (e.g.
// GH#39's Places-panel Lookup) doesn't produce a false pass.
$rcSectionStart = strpos($cfgJs, 'function bindRoadConditionsPanel');
ok($rcSectionStart !== false, 'config.js has bindRoadConditionsPanel()');
$rcSectionEnd = strpos($cfgJs, 'function openRoadCondForm');
ok($rcSectionEnd !== false && $rcSectionEnd > $rcSectionStart, 'config.js has openRoadCondForm() after bindRoadConditionsPanel()');
$rcSection = ($rcSectionStart !== false && $rcSectionEnd !== false)
    ? substr($cfgJs, $rcSectionStart, ($rcSectionEnd + 400) - $rcSectionStart)
    : '';
has($rcSection, 'Geocode.search(', 'the road-conditions section calls the shared Geocode.search()');
// Belt-and-suspenders on top of tools/geocode_audit.php: no literal geocoder
// hostname introduced by this change.
foreach (['nominatim.openstreetmap.org', 'maps.googleapis.com', 'locationiq.com'] as $host) {
    ok(strpos($rcSection, $host) === false, "road-conditions section names no hardcoded host ($host)");
}
// ES5 discipline, matching the rest of this file's convention.
ok(strpos($rcSection, '=>') === false, 'road-conditions lookup JS uses no arrow functions (ES5)');
ok(strpos($rcSection, 'let ') === false && strpos($rcSection, 'const ') === false,
    'road-conditions lookup JS uses no let/const (ES5 — var only)');

// ── Report ──
echo "Feature 1 — road-conditions map overlay tests\n";
echo "=============================================\n";
if ($fail > 0) {
    foreach ($failures as $m) echo "$m\n";
}
// Runner-compatible results line ("N passed, M failed").
echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
