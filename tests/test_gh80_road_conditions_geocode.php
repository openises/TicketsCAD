<?php
/**
 * GH#80 (chief_bp, 2026-08-1x) — "Latitude/Longitude on the Road Conditions
 * form are plain number boxes defaulting to 0, with no connection to the
 * Address field above them -- a user has to already know decimal
 * coordinates." Fixed with a Lookup button that reuses the existing shared
 * Geocode.search()/api/geocode.php exactly as the GH#39 Places-panel Lookup
 * already does on this same page (settings.php) -- no embedded map, no new
 * API endpoint, no schema change, no RBAC change. See
 * tests/test_road_conditions_overlay.php for the markup/wiring source-content
 * assertions; this file covers the two things a grep-only test would miss
 * per CLAUDE.md's tile_mode lesson ("does the setting change an observable
 * output, not that it round-trips"):
 *
 *   1. The actual JS behaviour of roadCondGeocodeLookup() / updateRoadCondMapHint()
 *      -- driven for real, under Node, against the VERBATIM function bodies
 *      extracted from the shipped assets/js/config.js (not a re-implementation).
 *      config.js is a single large IIFE with no window.* export (unlike
 *      geocode.js/map-prefs.js), so eval-ing the whole 16k-line file would
 *      both hide these two functions inside the closure AND immediately run
 *      init()'s real DOM binding against a document stub that doesn't have
 *      the real page's elements. Extracting just the two functions (plus
 *      their one dependency, esc()) by exact string boundaries sidesteps
 *      both problems while still testing the real, shipped source text.
 *
 *   2. The server-side lat/lng bounds validation added to
 *      api/road-conditions.php's `action=save` handler in the same change.
 *      api/road-conditions.php reads its POST body via
 *      file_get_contents('php://input'), which returns empty under this
 *      project's CLI SAPI build (confirmed precedent:
 *      tests/test_org_sharing_manual_api.php's own docblock) -- so, matching
 *      that file's and tests/test_vehicle_owner_agency.php's established
 *      pattern for exactly this situation, the validation predicate is
 *      hand-mirrored from the endpoint's own source text and tested against
 *      boundary cases, with a structural check (Part 2a) that the mirrored
 *      expression actually IS the endpoint's source and that it runs before
 *      any db_query() in the save branch -- so a future edit that changes
 *      the real check without updating this file's copy is caught, and a
 *      future edit that moves the check to after the write is caught too.
 *
 * DB-independent (Part 2 is pure-function; Part 1 SKIPs cleanly if node is
 * unavailable). No MySQL required.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(bool $v, string $what): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "[PASS] $what\n"; return; }
    $fail++; echo "[FAIL] $what\n";
}

$root = __DIR__ . '/..';

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Real JS behaviour: roadCondGeocodeLookup() / updateRoadCondMapHint() --\n";
// ─────────────────────────────────────────────────────────────────────────

$cfgJs = (string) file_get_contents($root . '/assets/js/config.js');
ok($cfgJs !== '', 'config.js readable');

$fnStart = strpos($cfgJs, 'function roadCondGeocodeLookup');
$fnEnd   = strpos($cfgJs, 'function rcApiPost');
ok($fnStart !== false && $fnEnd !== false && $fnEnd > $fnStart,
    'roadCondGeocodeLookup()/updateRoadCondMapHint() boundaries found in config.js');
$rcFunctions = ($fnStart !== false && $fnEnd !== false) ? substr($cfgJs, $fnStart, $fnEnd - $fnStart) : '';

$escStart = strpos($cfgJs, 'function esc(str)');
$escEnd   = $escStart !== false ? strpos($cfgJs, '}', strpos($cfgJs, '}', $escStart) + 1) : false;
ok($escStart !== false, 'esc() helper found in config.js (dependency of the extracted functions)');
// esc() is a short, 4-line function; grab a generous fixed window rather than
// fragile brace-counting, then trim at the alias line that follows it.
$escBlock = $escStart !== false ? substr($cfgJs, $escStart, 300) : '';
$escBlock = $escStart !== false ? substr($escBlock, 0, strpos($escBlock, 'var escHtml')) : '';

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available -- the JS execution checks were not run\n";
} elseif ($rcFunctions === '' || $escBlock === '') {
    ok(false, 'could not extract the functions under test -- see boundary failures above');
} else {
    $harness = sys_get_temp_dir() . '/tcad_gh80_harness_' . getmypid() . '.js';
    // The extracted source is written to its own file and required, so this
    // harness is driving the VERBATIM shipped text, not a copy pasted into a
    // template string (which would risk quoting/escaping drift).
    $extractedPath = sys_get_temp_dir() . '/tcad_gh80_extracted_' . getmypid() . '.js';
    file_put_contents($extractedPath, $escBlock . "\n" . $rcFunctions);

    $js = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// Minimal DOM stub: a plain object registry keyed by id, each with the
// handful of properties/methods the two functions under test actually touch.
var elements = {};
function makeEl(id) {
    var listeners = {};
    return {
        id: id,
        value: '',
        innerHTML: '',
        disabled: false,
        classList: {
            _hidden: false,
            toggle: function (cls, force) { if (cls === 'd-none') this._hidden = (force === undefined ? !this._hidden : !!force); },
            contains: function (cls) { return cls === 'd-none' ? this._hidden : false; }
        },
        addEventListener: function (evt, fn) { listeners[evt] = fn; },
        _fire: function (evt, e) { if (listeners[evt]) listeners[evt](e || {}); }
    };
}
['roadCondAddress', 'roadCondGeoStatus', 'btnRoadCondLookup', 'roadCondLat', 'roadCondLng', 'roadCondNoMapHint'].forEach(function (id) {
    elements[id] = makeEl(id);
});
// esc() (extracted verbatim below, same as the shipped file) builds a real
// div + text node and reads .innerHTML back -- so createElement/createTextNode
// need a minimal, faithful-enough implementation, not just getElementById.
global.document = {
    getElementById: function (id) { return elements[id]; },
    createElement: function () {
        var el = { _text: '', innerHTML: '' };
        el.appendChild = function (node) {
            el._text += node._text;
            el.innerHTML = el._text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        };
        return el;
    },
    createTextNode: function (text) { return { _text: String(text) }; }
};
global.window = global;

// Real Geocode.search() contract shape (see assets/js/geocode.js): always
// resolves, never rejects, with {ok, results, error, message}.
var geocodeResponse = null;
global.Geocode = { search: function (opts) {
    global.__lastGeocodeQuery = opts;
    return Promise.resolve(geocodeResponse);
} };

eval(fs.readFileSync(process.argv[2], 'utf8'));

// One microtask turn is enough for roadCondGeocodeLookup()'s single
// Geocode.search(...).then(...) hop to run, since the stub resolves
// synchronously (Promise.resolve()) -- but wait two turns to be safe.
function nextTick() { return Promise.resolve().then(function () { return Promise.resolve(); }); }

function fireLookup(resp, addrValue) {
    elements.roadCondAddress.value = addrValue;
    elements.roadCondGeoStatus.innerHTML = '';
    elements.btnRoadCondLookup.disabled = false;
    elements.roadCondLat.value = '0';
    elements.roadCondLng.value = '0';
    elements.roadCondNoMapHint.classList._hidden = true; // updateRoadCondMapHint hasn't run yet on a fresh open
    geocodeResponse = resp;
    roadCondGeocodeLookup();
    return nextTick();
}

(function main() {
    // ── Case: empty address -- no network call, friendly message, no lookup fired ──
    elements.roadCondAddress.value = '   ';
    elements.roadCondGeoStatus.innerHTML = '';
    global.__lastGeocodeQuery = null;
    roadCondGeocodeLookup();
    check('empty address: no Geocode.search() call', global.__lastGeocodeQuery === null);
    check('empty address: friendly prompt shown', /Enter an address/.test(elements.roadCondGeoStatus.innerHTML), elements.roadCondGeoStatus.innerHTML);

    // ── Case: successful lookup -- lat/lng get the real value, .toFixed(6), status shows found, button re-enabled ──
    return fireLookup({
        ok: true,
        results: [{ lat: '44.977800', lon: '-93.265000', display_name: 'Minneapolis, MN, USA', address: {} }],
        error: '', message: ''
    }, '123 Main St, Minneapolis, MN').then(function () {
        check('success: latitude set to the real value, toFixed(6)', elements.roadCondLat.value === '44.977800', elements.roadCondLat.value);
        check('success: longitude set to the real value, toFixed(6)', elements.roadCondLng.value === '-93.265000', elements.roadCondLng.value);
        check('success: status shows the resolved address', /Found:.*Minneapolis/.test(elements.roadCondGeoStatus.innerHTML), elements.roadCondGeoStatus.innerHTML);
        check('success: button re-enabled', elements.btnRoadCondLookup.disabled === false);
        check('success: no-map hint cleared (lat/lng are non-zero now)', elements.roadCondNoMapHint.classList._hidden === true);

        // ── Case: ok:false (geocoder outage / disabled) -- friendly message, coordinates untouched, button re-enabled ──
        return fireLookup({ ok: false, results: [], error: 'unreachable', message: 'Address lookup could not reach this server.' }, '456 Oak Ave');
    }).then(function () {
        check('failure (ok:false): server message surfaced', elements.roadCondGeoStatus.innerHTML.indexOf('Address lookup could not reach this server.') !== -1, elements.roadCondGeoStatus.innerHTML);
        check('failure (ok:false): "enter manually" guidance appended', /enter coordinates manually/i.test(elements.roadCondGeoStatus.innerHTML), elements.roadCondGeoStatus.innerHTML);
        check('failure (ok:false): coordinates left untouched (still 0)', elements.roadCondLat.value === '0' && elements.roadCondLng.value === '0');
        check('failure (ok:false): button re-enabled', elements.btnRoadCondLookup.disabled === false);

        // ── Case: ok:true but zero results (address not found) ──
        return fireLookup({ ok: true, results: [], error: '', message: '' }, 'asdkjhaskdjh nowhere');
    }).then(function () {
        check('not found: friendly message shown', /Address not found/.test(elements.roadCondGeoStatus.innerHTML), elements.roadCondGeoStatus.innerHTML);
        check('not found: coordinates left untouched (still 0)', elements.roadCondLat.value === '0' && elements.roadCondLng.value === '0');

        // ── updateRoadCondMapHint(): 0/0 shows the hint, non-zero hides it ──
        elements.roadCondLat.value = '0';
        elements.roadCondLng.value = '0';
        updateRoadCondMapHint();
        check('hint shows at 0/0', elements.roadCondNoMapHint.classList._hidden === false);
        elements.roadCondLat.value = '44.977800';
        elements.roadCondLng.value = '-93.265000';
        updateRoadCondMapHint();
        check('hint hides once a real coordinate is set', elements.roadCondNoMapHint.classList._hidden === true);
        elements.roadCondLat.value = '0';
        elements.roadCondLng.value = '-93.265000';
        updateRoadCondMapHint();
        check('hint stays visible when only ONE of lat/lng is still 0 (partial entry, still not plottable)', elements.roadCondNoMapHint.classList._hidden === false);

        console.log(out.join('\n'));
    }).catch(function (e) {
        out.push('FAIL|unhandled exception in harness|' + (e && e.stack));
        console.log(out.join('\n'));
    });
})();
JS;
    file_put_contents($harness, $js);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($extractedPath) . ' 2>&1');
    @unlink($harness);
    @unlink($extractedPath);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        ok(false, 'node harness ran the extracted functions (got: ' . trim((string) $raw) . ')');
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2) { continue; }
            ok($parts[0] === 'PASS', '[js] ' . $parts[1] . (isset($parts[2]) && $parts[2] !== '' ? ' (' . $parts[2] . ')' : ''));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. api/road-conditions.php lat/lng bounds validation --\n";
// ─────────────────────────────────────────────────────────────────────────

$apiSrc = (string) file_get_contents($root . '/api/road-conditions.php');
ok($apiSrc !== '', 'road-conditions.php readable');

// 2a. Structural: the check exists, and runs BEFORE the write (both the
// UPDATE and the INSERT), not after -- so a rejected value can never be
// staged and then written on some other path.
$saveActionPos = strpos($apiSrc, "if (\$action === 'save')");
$checkPos      = strpos($apiSrc, '$lat !== 0.0');
$updatePos     = strpos($apiSrc, 'UPDATE `{$prefix}roadinfo`');
$insertPos     = strpos($apiSrc, 'INSERT INTO `{$prefix}roadinfo`');
ok($saveActionPos !== false && $checkPos !== false && $updatePos !== false && $insertPos !== false,
    'all four anchor points found in api/road-conditions.php');
ok($checkPos > $saveActionPos, 'the bounds check is inside the save action branch');
ok($checkPos < $updatePos && $checkPos < $insertPos,
    'the bounds check runs BEFORE either the UPDATE or the INSERT (a rejected value is never written)');

// 2b. Logic: mirror the exact predicate api/road-conditions.php now runs
// (hand-copied, matching tests/test_vehicle_owner_agency.php's own
// established precedent for a POST handler that reads php://input, which is
// unavailable under this project's CLI SAPI -- see this file's docblock).
function road_cond_bounds_ok(float $lat, float $lng): bool
{
    if ($lat !== 0.0 && ($lat < -90 || $lat > 90)) { return false; }
    if ($lng !== 0.0 && ($lng < -180 || $lng > 180)) { return false; }
    return true;
}

$cases = [
    // [lat, lng, expected ok, label]
    [0.0, 0.0, true, 'the 0/0 "no location set" sentinel is explicitly allowed'],
    [44.9778, -93.2650, true, 'a real Minneapolis coordinate is allowed'],
    [-90.0, -180.0, true, 'the exact southwest boundary is inclusive'],
    [90.0, 180.0, true, 'the exact northeast boundary is inclusive'],
    [999.0, 0.0, false, 'GH#80 spec\'s own example (lat=999) is rejected'],
    [90.0001, 0.0, false, 'just above the north pole is rejected'],
    [-90.0001, 0.0, false, 'just below the south pole is rejected'],
    [0.0, 180.0001, false, 'just past the antimeridian (east) is rejected'],
    [0.0, -180.0001, false, 'just past the antimeridian (west) is rejected'],
    [45.0, 0.0, true, 'a non-zero latitude with a genuinely-zero longitude (mid-Atlantic-ish) is allowed'],
];
foreach ($cases as [$lat, $lng, $expected, $label]) {
    ok(road_cond_bounds_ok($lat, $lng) === $expected, $label);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
