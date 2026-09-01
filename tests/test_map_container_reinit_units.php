<?php
/**
 * test_map_container_reinit_units.php — sibling of GH#121 (2026-08-29's
 * incident-detail.js fix), found 2026-08-31 during a codebase-wide sweep
 * for the same Leaflet container-reinit defect.
 *
 * THE BUG (before this fix): assets/js/units.js's initMap() unconditionally
 * called `L.map('unitsMap', ...)` on every invocation, in both branches of
 * its api/map-config.php fetch (the .then() success branch AND the
 * .catch() failure branch), with no teardown of a prior instance. Leaflet
 * stamps the CONTAINER DOM NODE itself (an internal _leaflet_id) the first
 * time L.map() runs on it — the stamp lives on the DOM node, not on this
 * file's `map` JS variable — so a second L.map() call on the same node
 * throws "Map container is already initialized." regardless of what `map`
 * currently holds.
 *
 * initMap() is called from loadUnits()'s success handler on every
 * invocation of loadUnits(). units.js exposes `window.loadUnits = loadUnits`
 * specifically so units.php's inline script can re-trigger a reload after a
 * mutation: `window.UnitActions.onMutate = function () { if (typeof
 * window.loadUnits === 'function') window.loadUnits(); ... }` — wired to
 * fire after every quick-action modal on the units list page (dispatch,
 * status change, note). So the SECOND unit mutation made from the units
 * list page (via the row quick-action buttons) after the first page load
 * threw here — the identical shape and root cause as GH#121, on a
 * different page, reached via a different reload trigger (a global
 * mutation callback rather than an inline-edit save handler).
 *
 * THE FIX mirrors incident-detail.js's _teardownDetailMap() idiom exactly:
 * a new _teardownUnitsMap() helper (`try { map.remove(); } catch (e) {}
 * map = null; markerGroup = null; markers = [];`) is called at the top of
 * initMap(), and again immediately before each of the two L.map() calls
 * inside the map-config fetch's .then()/.catch() branches (a second
 * initMap() call can race ahead of the first's fetch resolving).
 *
 * This file proves the fix the same way test_gh121_map_container_reinit.php
 * did: drive the REAL _teardownUnitsMap()/initMap() extracted live from the
 * shipped file against a fake Leaflet that faithfully reproduces the real
 * container-stamping mechanism, with a NEGATIVE CONTROL (the literal
 * pre-fix initMap() body, reconstructed verbatim from before this commit)
 * run through the SAME harness to prove it would have caught the original
 * defect.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = realpath(__DIR__ . '/..');

echo "=== Map container re-init — units.js (GH#121 sibling) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

$jsPath = $base . '/assets/js/units.js';
$jsSrc  = (string) file_get_contents($jsPath);

/** Run a node harness script (as a string) with CLI args. Returns [name => ['ok'=>bool,'detail'=>str]]. */
function unitsmap_run_js(string $node, string $harnessJs, array $args): array {
    $h = sys_get_temp_dir() . '/tcad_unitsmap_harness_' . getmypid() . '_' . mt_rand() . '.js';
    file_put_contents($h, $harnessJs);
    $cmd = $node . ' ' . escapeshellarg($h);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $raw = @shell_exec($cmd . ' 2>&1');
    @unlink($h);
    $out = [];
    if (!is_string($raw)) return $out;
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', trim($line), 3);
        if (count($parts) < 2) continue;
        if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
        $out[$parts[1]] = ['ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
    }
    return $out;
}

function unitsmap_apply_results(array $results, string $prefix): void {
    if (!$results) {
        bad($prefix . 'node harness produced no parseable output');
        return;
    }
    foreach ($results as $name => $r) {
        $r['ok'] ? ok($prefix . $name) : bad($prefix . $name, $r['detail']);
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The REAL _teardownUnitsMap()/initMap(), driven against a fake Leaflet --\n";
// ─────────────────────────────────────────────────────────────────────────

// The pre-fix initMap() — reconstructed from this file's history before
// the _teardownUnitsMap() helper existed. Trimmed (addMapLayers/
// updateMapMarkers stubbed as no-ops via globals) but preserves the exact
// defect mechanism: L.map() called unconditionally in both the fetch
// success and failure branches, matching test_gh121's own precedent of a
// faithful-but-trimmed negative-control fixture.
$oldInitMapSrc = <<<'OLDJS'
function initMap() {
    var container = document.getElementById('unitsMap');
    if (!container || typeof L === 'undefined') return;

    fetch('api/map-config.php')
        .then(function (r) { return r.json(); })
        .then(function (cfg) {
            var defLat = cfg.def_lat || 39.8283;
            var defLng = cfg.def_lng || -98.5795;
            var defZoom = cfg.def_zoom || 5;

            map = L.map('unitsMap', { zoomControl: true }).setView([defLat, defLng], defZoom);
            addMapLayers(map);
            markerGroup = L.featureGroup().addTo(map);
            updateMapMarkers();
            setTimeout(function () { map.invalidateSize(); }, 200);
        })
        .catch(function () {
            map = L.map('unitsMap', { zoomControl: true }).setView([39.8283, -98.5795], 5);
            addMapLayers(map);
            markerGroup = L.featureGroup().addTo(map);
            updateMapMarkers();
            setTimeout(function () { map.invalidateSize(); }, 200);
        });
}
OLDJS;

$harness1 = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// units.js's initMap() has NO synchronous L.map() branch at all — unlike
// incident-detail.js/unit-detail.js's hasCoords branch, EVERY L.map() call
// here happens inside an async api/map-config.php fetch .then()/.catch().
// So a throw from a re-entrant call never surfaces as a synchronous
// exception a caller's try/catch can see — it surfaces as an UNHANDLED
// PROMISE REJECTION (visible in a real browser console as "Uncaught (in
// promise)"), because the pre-fix .catch() handler ALSO unconditionally
// called L.map() with no further .catch() after it. Track rejections
// globally rather than relying on try/catch around each initMap() call.
var unhandled = [];
process.on('unhandledRejection', function (reason) { unhandled.push(String(reason)); });

function extractBalanced(source, marker, fromIdx) {
    var i = source.indexOf(marker, fromIdx || 0);
    if (i === -1) return null;
    var b = source.indexOf('{', i);
    if (b === -1) return null;
    var depth = 0, k = b;
    for (; k < source.length; k++) {
        var c = source[k];
        if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) { k++; break; } }
    }
    if (depth !== 0) return null;
    return { text: source.slice(i, k), end: k };
}

function extractFns(source) {
    var td = extractBalanced(source, 'function _teardownUnitsMap() {', 0);
    var im = extractBalanced(source, 'function initMap() {', td ? td.end : 0);
    if (!im) return null;
    return (td ? td.text + '\n' : '') + im.text;
}

function makeFakeLeaflet(container) {
    var seq = 0;
    return {
        map: function () {
            if (container.stamped) {
                throw new Error('Map container is already initialized.');
            }
            container.stamped = true;
            var myId = ++seq;
            var removed = false;
            var obj = {
                setView: function () { return obj; },
                invalidateSize: function () {},
                remove: function () {
                    if (removed) return;
                    removed = true;
                    container.stamped = false;
                },
                isRemoved: function () { return removed; },
                _id: myId
            };
            return obj;
        },
        featureGroup: function () {
            var o = { addTo: function () { return o; }, clearLayers: function () {}, getLayers: function () { return []; } };
            return o;
        },
        tileLayer: function () {
            var o = { addTo: function () { return o; } };
            return o;
        },
        control: { layers: function () { var o = { addTo: function () { return o; } }; return o; } }
    };
}

function makeHarness(fnSrc, container, fetchImpl) {
    var map = null, markers = [], markerGroup = null;
    global.L = makeFakeLeaflet(container);
    global.document = { getElementById: function (id) { return id === 'unitsMap' ? container : null; } };
    global.window = {}; // no TypeIcons/MapPrefs -> plain fallback paths
    global.escHtml = function (s) { return s == null ? '' : String(s); };
    global.addMapLayers = function () {};   // stubbed — not the code under test
    global.updateMapMarkers = function () {}; // stubbed — not the code under test
    global.fetch = fetchImpl || function () {
        return Promise.resolve({ json: function () { return Promise.resolve({}); } });
    };
    eval(fnSrc);
    return {
        initMap: (typeof initMap === 'function') ? initMap : null,
        hasTeardown: typeof _teardownUnitsMap === 'function',
        getMap: function () { return map; },
        getMarkerGroup: function () { return markerGroup; }
    };
}

function flush(ms) { return new Promise(function (r) { setTimeout(r, ms || 30); }); }

(async function () {
    var srcPath = process.argv[2];
    var oldFixturePath = process.argv[3];
    var src = fs.readFileSync(srcPath, 'utf8');
    var extracted = extractFns(src);
    check('extracted _teardownUnitsMap()+initMap() from the shipped file', !!extracted,
        extracted ? (extracted.length + ' chars') : 'anchor/markers not found');

    if (extracted) {
        // ── A. Success-branch fetch, initMap() called twice — the bug
        // scenario: a unit mutation reload calling initMap() a second
        // time after the map-config fetch resolves both times. ──
        var containerA = { stamped: false };
        var hA = makeHarness(extracted, containerA);
        check('shipped file defines a _teardownUnitsMap() helper', hA.hasTeardown);

        var before = unhandled.length;
        hA.initMap();
        await flush(30);
        check('first initMap() call does not produce an unhandled rejection', unhandled.length === before,
            unhandled.slice(before).join('; '));
        var firstMap = hA.getMap();
        check('first call created a map instance', !!firstMap);
        check('container is stamped after the first call', containerA.stamped === true);

        before = unhandled.length;
        hA.initMap();
        await flush(30);
        check('FIX: second initMap() call (loadUnits() re-run via UnitActions.onMutate after a unit mutation) produces NO unhandled rejection',
            unhandled.length === before, unhandled.slice(before).join('; '));
        check('FIX: the first map instance was torn down (map.remove() was called on it)',
            !!firstMap && firstMap.isRemoved() === true);
        var secondMap = hA.getMap();
        check('FIX: a distinct new map instance replaced the old one',
            !!secondMap && secondMap !== firstMap, secondMap === firstMap ? 'same instance reused' : '');
        check('container ends up stamped exactly once (no leak, no double-stamp)',
            containerA.stamped === true);

        // ── B. Two rapid initMap() calls racing before the first
        // map-config fetch resolves (both go through the success branch,
        // resolved in the same microtask flush). ──
        var containerB = { stamped: false };
        var hB = makeHarness(extracted, containerB);
        before = unhandled.length;
        hB.initMap();
        hB.initMap();
        await flush(50);
        check('rapid re-entry: no unhandled rejection from either racing call',
            unhandled.length === before, unhandled.slice(before).join('; '));
        check('rapid re-entry: the container is NOT left double-stamped after both async creations settle',
            containerB.stamped === true);

        // ── C. The .catch() (map-config fetch failure) branch, called
        // twice — must ALSO tear down before its own L.map() call. ──
        var containerC2 = { stamped: false };
        var failingFetch = function () { return Promise.reject(new Error('network error')); };
        var hC2 = makeHarness(extracted, containerC2, failingFetch);
        before = unhandled.length;
        hC2.initMap();
        await flush(30);
        check('catch-branch: first call (fetch fails) creates a map with no unhandled rejection',
            containerC2.stamped === true && unhandled.length === before, unhandled.slice(before).join('; '));

        before = unhandled.length;
        hC2.initMap();
        await flush(30);
        check('FIX: catch-branch second call produces NO unhandled rejection (also torn down before recreating)',
            unhandled.length === before, unhandled.slice(before).join('; '));

        // ── D. NEGATIVE CONTROL: the literal pre-fix initMap() (no
        // teardown helper existed at all) — same harness must catch it.
        // The pre-fix code has no synchronous L.map() branch at all, so
        // the reproduction surfaces as an UNHANDLED REJECTION (the
        // pre-fix .catch() handler ALSO unconditionally calls L.map(),
        // with nothing after it to catch that second failure — a real,
        // separate defect this fix also happens to close), not a
        // synchronous throw. ──
        var oldSrc = fs.readFileSync(oldFixturePath, 'utf8');
        var containerD = { stamped: false };
        var hD = null;
        try { hD = makeHarness(oldSrc, containerD); } catch (e) {}
        check('negative-control fixture parses/evaluates', !!hD);
        if (hD) {
            check('negative-control fixture (as shipped pre-fix) has NO teardown helper', hD.hasTeardown === false);
            before = unhandled.length;
            hD.initMap();
            await flush(30);
            check('NEGATIVE CONTROL: first pre-fix initMap() call produces no unhandled rejection (matches real pre-fix behavior)',
                unhandled.length === before, unhandled.slice(before).join('; '));

            before = unhandled.length;
            hD.initMap();
            await flush(30);
            var newRejections = unhandled.slice(before);
            check('NEGATIVE CONTROL: second pre-fix initMap() call produces an UNHANDLED REJECTION "Map container is already initialized" — reproduces the defect',
                newRejections.length > 0 && /already initialized/i.test(newRejections.join('; ')),
                newRejections.join('; ') || '(no rejection — harness would not have caught the original defect)');
        }
    }

    console.log(out.join('\n'));
})();
JS;

if ($node === null) {
    echo "SKIP: node not available — Section 1 JS execution checks were not run\n";
} else {
    $oldFixturePath = sys_get_temp_dir() . '/tcad_unitsmap_old_initmap_' . getmypid() . '.js';
    file_put_contents($oldFixturePath, $oldInitMapSrc);
    $results = unitsmap_run_js($node, $harness1, [
        str_replace('\\', '/', $jsPath),
        str_replace('\\', '/', $oldFixturePath),
    ]);
    @unlink($oldFixturePath);
    unitsmap_apply_results($results, '[js:teardown] ');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Static source assertions on the shipped file --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(strpos($jsSrc, 'function _teardownUnitsMap() {') !== false,
    'shipped file defines the _teardownUnitsMap() helper');

$initMapIdx = strpos($jsSrc, 'function initMap() {');
is_true($initMapIdx !== false, 'shipped file still defines initMap()');

if ($initMapIdx !== false) {
    $window = substr($jsSrc, $initMapIdx, 300);
    is_true(strpos($window, '_teardownUnitsMap();') !== false,
        'FIX: initMap() calls _teardownUnitsMap() before doing anything else');
}

// Both the success (.then) and failure (.catch) branches must ALSO tear
// down again immediately before their own L.map() call (the async-race
// protection — a second initMap() call can race ahead of the first fetch).
$mapConfigIdx = strpos($jsSrc, "fetch('api/map-config.php')");
is_true($mapConfigIdx !== false, 'shipped file still has the map-config fetch');
if ($mapConfigIdx !== false) {
    $window2 = substr($jsSrc, $mapConfigIdx, 1600);
    $teardownOccurrences = substr_count($window2, '_teardownUnitsMap();');
    is_true($teardownOccurrences >= 2,
        'FIX: both the success and failure fetch branches tear down before their own L.map() call',
        "found {$teardownOccurrences} occurrence(s) in the fetch block, expected >= 2");
}

// window.loadUnits must still be exposed — this is the mechanism
// units.php's UnitActions.onMutate uses to re-trigger the reload; the fix
// must not accidentally break that wiring.
is_true(strpos($jsSrc, 'window.loadUnits = loadUnits;') !== false,
    'window.loadUnits is still exposed for units.php\'s UnitActions.onMutate reload path');

// units.php's own reload trigger must still be intact (confirms the
// reachable-more-than-once call path this bug depended on still exists,
// i.e. this test is not proving a fix for an unreachable code path).
$unitsPhp = (string) file_get_contents($base . '/units.php');
is_true(strpos($unitsPhp, 'window.loadUnits()') !== false,
    'units.php still calls window.loadUnits() from UnitActions.onMutate (confirms the reload path this bug depended on is real and live)');

echo "\n";
echo "==========================================================\n";
echo "units.js map container re-init tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
