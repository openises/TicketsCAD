<?php
/**
 * test_map_container_reinit_unit_detail.php — sibling of GH#121
 * (2026-08-29's incident-detail.js fix), found 2026-08-31 during a
 * codebase-wide sweep for the same Leaflet container-reinit defect.
 *
 * THE BUG (before this fix): assets/js/unit-detail.js's initMap(resp,
 * resolved) unconditionally called `L.map('unitMap', ...)` on every
 * invocation, in both the hasCoords branch and the async no-coords
 * (map-config fetch) branch, with no teardown of a prior instance.
 * Leaflet stamps the CONTAINER DOM NODE itself (an internal _leaflet_id)
 * the first time L.map() runs on it — the stamp lives on the DOM node, not
 * on this file's `map` JS variable — so a second L.map() call on the same
 * node throws "Map container is already initialized." regardless of what
 * `map` currently holds.
 *
 * unit-detail.js's loadUnit(id) calls initMap(resp, data.resolved_location
 * || null) on every successful load, and loadUnit() is re-invoked from at
 * least 8 other places in the file: after a dispatch-level change, a note
 * add, a personnel assign/release, a location-binding add/remove, and
 * several other quick-action modals (see the loadUnit(...) call sites
 * throughout the file). So the SECOND unit-detail action of any kind after
 * the first page load threw here — the identical shape and root cause as
 * GH#121, on a different page.
 *
 * THE FIX mirrors incident-detail.js's _teardownDetailMap() idiom exactly:
 * a new _teardownUnitMap() helper (`try { map.remove(); } catch (e) {}
 * map = null; marker = null;`) is called at the top of initMap(), and
 * again immediately before the second L.map() call inside the no-coords
 * branch's fetch .then() (a second initMap() call can race ahead of the
 * first's map-config fetch resolving).
 *
 * This file proves the fix the same way test_gh121_map_container_reinit.php
 * did: drive the REAL _teardownUnitMap()/initMap() extracted live from the
 * shipped file against a fake Leaflet that faithfully reproduces the real
 * container-stamping mechanism, with a NEGATIVE CONTROL (the literal
 * pre-fix initMap() body, reconstructed verbatim from before this commit)
 * run through the SAME harness to prove it would have caught the original
 * defect.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = realpath(__DIR__ . '/..');

echo "=== Map container re-init — unit-detail.js (GH#121 sibling) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

$jsPath = $base . '/assets/js/unit-detail.js';
$jsSrc  = (string) file_get_contents($jsPath);

/** Run a node harness script (as a string) with CLI args. Returns [name => ['ok'=>bool,'detail'=>str]]. */
function udmap_run_js(string $node, string $harnessJs, array $args): array {
    $h = sys_get_temp_dir() . '/tcad_udmap_harness_' . getmypid() . '_' . mt_rand() . '.js';
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

function udmap_apply_results(array $results, string $prefix): void {
    if (!$results) {
        bad($prefix . 'node harness produced no parseable output');
        return;
    }
    foreach ($results as $name => $r) {
        $r['ok'] ? ok($prefix . $name) : bad($prefix . $name, $r['detail']);
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The REAL _teardownUnitMap()/initMap(), driven against a fake Leaflet --\n";
// ─────────────────────────────────────────────────────────────────────────

// The pre-fix initMap(resp, resolved) — reconstructed from this file's
// history before the _teardownUnitMap() helper existed. Trimmed to the
// minimum needed to reproduce the defect mechanism (no teardown anywhere),
// matching test_gh121_map_container_reinit.php's own precedent of a
// faithful-but-trimmed negative-control fixture.
$oldInitMapSrc = <<<'OLDJS'
function initMap(resp, resolved) {
    var container = document.getElementById('unitMap');
    if (!container || typeof L === 'undefined') return;

    var lat = null, lng = null, sourceTag = null;
    if (resolved && resolved.lat !== null && resolved.lng !== null) {
        var rl = parseFloat(resolved.lat);
        var rg = parseFloat(resolved.lng);
        if (!isNaN(rl) && !isNaN(rg) && (rl !== 0 || rg !== 0)) {
            lat = rl; lng = rg;
            sourceTag = resolved.provider_name || resolved.provider_code || 'live';
        }
    }
    if (lat === null && resp.lat && resp.lng && (resp.lat !== 0 || resp.lng !== 0)) {
        lat = parseFloat(resp.lat);
        lng = parseFloat(resp.lng);
        sourceTag = 'static';
    }
    var hasCoords = lat !== null && lng !== null;

    if (hasCoords) {
        map = L.map('unitMap', { zoomControl: true }).setView([lat, lng], 15);
    } else {
        fetch('api/map-config.php')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                map = L.map('unitMap', { zoomControl: true })
                    .setView([cfg.def_lat || 39.8283, cfg.def_lng || -98.5795], cfg.def_zoom || 5);
                setTimeout(function () { map.invalidateSize(); }, 200);
            })
            .catch(function () {});
        return;
    }

    var color = (resolved && resolved.color && resolved.lat !== null) ? resolved.color : (resp.status_bg_color || '#0d6efd');
    marker = L.circleMarker([lat, lng], { radius: 10, color: color, fillColor: color, fillOpacity: 0.8, weight: 2 }).addTo(map);
    setTimeout(function () { map.invalidateSize(); }, 200);
}
OLDJS;

$harness1 = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

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
    var td = extractBalanced(source, 'function _teardownUnitMap() {', 0);
    var im = extractBalanced(source, "function initMap(resp, resolved) {", td ? td.end : 0);
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
        marker: function () {
            var o = { addTo: function () { return o; }, bindPopup: function () { return o; } };
            return o;
        },
        circleMarker: function () {
            var o = { addTo: function () { return o; }, bindPopup: function () { return o; }, bindTooltip: function () { return o; } };
            return o;
        },
        tileLayer: function () {
            var o = { addTo: function () { return o; } };
            return o;
        }
    };
}

function makeHarness(fnSrc, container) {
    var map = null, marker = null;
    global.L = makeFakeLeaflet(container);
    global.document = { getElementById: function (id) { return id === 'unitMap' ? container : null; } };
    global.window = {}; // no MapPrefs -> falls back to L.tileLayer branch
    global.escHtml = function (s) { return s == null ? '' : String(s); };
    global.fetch = function () {
        return Promise.resolve({ json: function () { return Promise.resolve({}); } });
    };
    eval(fnSrc);
    return {
        initMap: (typeof initMap === 'function') ? initMap : null,
        hasTeardown: typeof _teardownUnitMap === 'function',
        getMap: function () { return map; },
        getMarker: function () { return marker; }
    };
}

function flush(ms) { return new Promise(function (r) { setTimeout(r, ms || 30); }); }

(async function () {
    var srcPath = process.argv[2];
    var oldFixturePath = process.argv[3];
    var src = fs.readFileSync(srcPath, 'utf8');
    var extracted = extractFns(src);
    check('extracted _teardownUnitMap()+initMap() from the shipped file', !!extracted,
        extracted ? (extracted.length + ' chars') : 'anchor/markers not found');

    if (extracted) {
        // ── A. hasCoords branch, called twice — the bug scenario: any
        // quick-action reload calling initMap() a second time. ──
        var containerA = { stamped: false };
        var hA = makeHarness(extracted, containerA);
        check('shipped file defines a _teardownUnitMap() helper', hA.hasTeardown);

        var threwFirst = false, firstErr = '';
        try { hA.initMap({ lat: 44.9, lng: -93.2, status_bg_color: '#0d6efd' }, null); } catch (e) { threwFirst = true; firstErr = String(e); }
        check('first initMap() call (with coords) does not throw', !threwFirst, firstErr);
        var firstMap = hA.getMap();
        check('first call created a map instance', !!firstMap);
        check('container is stamped after the first call', containerA.stamped === true);

        var threwSecond = false, secondErr = '';
        try { hA.initMap({ lat: 44.95, lng: -93.3, status_bg_color: '#0d6efd' }, null); } catch (e) { threwSecond = true; secondErr = String(e); }
        check('FIX: second initMap() call (loadUnit() re-run after a status/note/assignment change) does NOT throw',
            !threwSecond, secondErr);
        check('FIX: the first map instance was torn down (map.remove() was called on it)',
            !!firstMap && firstMap.isRemoved() === true);
        var secondMap = hA.getMap();
        check('FIX: a distinct new map instance replaced the old one',
            !!secondMap && secondMap !== firstMap, secondMap === firstMap ? 'same instance reused' : '');
        check('container ends up stamped exactly once (no leak, no double-stamp)',
            containerA.stamped === true);
        check('marker was reset by the teardown before the second call re-added it',
            hA.getMarker() !== null, 'a fresh marker should exist after a hasCoords re-init');

        // ── B. no-coords (async) branch, raced twice before the first
        // map-config fetch resolves. ──
        var containerB = { stamped: false };
        var hB = makeHarness(extracted, containerB);
        var threwB1 = false, threwB2 = false;
        try { hB.initMap({}, null); } catch (e) { threwB1 = true; }
        try { hB.initMap({}, null); } catch (e) { threwB2 = true; }
        check('no-coords branch: neither synchronous call throws (map creation is deferred to the fetch)',
            !threwB1 && !threwB2);
        await flush(50);
        check('no-coords branch: the container is NOT left double-stamped after both async creations settle',
            containerB.stamped === true);

        // ── C. NEGATIVE CONTROL: the literal pre-fix initMap() (no
        // teardown helper existed at all) — same harness must catch it. ──
        var oldSrc = fs.readFileSync(oldFixturePath, 'utf8');
        var containerC = { stamped: false };
        var hC = null;
        try { hC = makeHarness(oldSrc, containerC); } catch (e) {}
        check('negative-control fixture parses/evaluates', !!hC);
        if (hC) {
            check('negative-control fixture (as shipped pre-fix) has NO teardown helper', hC.hasTeardown === false);
            var threwOldFirst = false;
            try { hC.initMap({ lat: 44.9, lng: -93.2, status_bg_color: '#0d6efd' }, null); } catch (e) { threwOldFirst = true; }
            check('NEGATIVE CONTROL: first pre-fix initMap() call does not throw (matches real pre-fix behavior)',
                !threwOldFirst);
            var threwOldSecond = false, oldErr = '';
            try { hC.initMap({ lat: 44.95, lng: -93.3, status_bg_color: '#0d6efd' }, null); } catch (e) { threwOldSecond = true; oldErr = String(e); }
            check('NEGATIVE CONTROL: second pre-fix initMap() call THROWS "Map container is already initialized" — reproduces the defect',
                threwOldSecond === true && /already initialized/i.test(oldErr), oldErr);
        }
    }

    console.log(out.join('\n'));
})();
JS;

if ($node === null) {
    echo "SKIP: node not available — Section 1 JS execution checks were not run\n";
} else {
    $oldFixturePath = sys_get_temp_dir() . '/tcad_udmap_old_initmap_' . getmypid() . '.js';
    file_put_contents($oldFixturePath, $oldInitMapSrc);
    $results = udmap_run_js($node, $harness1, [
        str_replace('\\', '/', $jsPath),
        str_replace('\\', '/', $oldFixturePath),
    ]);
    @unlink($oldFixturePath);
    udmap_apply_results($results, '[js:teardown] ');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Static source assertions on the shipped file --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(strpos($jsSrc, 'function _teardownUnitMap() {') !== false,
    'shipped file defines the _teardownUnitMap() helper');

$initMapIdx = strpos($jsSrc, 'function initMap(resp, resolved) {');
is_true($initMapIdx !== false, 'shipped file still defines initMap(resp, resolved)');

if ($initMapIdx !== false) {
    $window = substr($jsSrc, $initMapIdx, 400);
    is_true(strpos($window, '_teardownUnitMap();') !== false,
        'FIX: initMap() calls _teardownUnitMap() before doing anything else');
}

// The no-coords branch's fetch.then() must ALSO tear down before creating
// a second map instance (the async-race protection).
$noCoordsIdx = strpos($jsSrc, "fetch('api/map-config.php')");
is_true($noCoordsIdx !== false, 'shipped file still has the no-coords map-config fetch branch');
if ($noCoordsIdx !== false) {
    $window2 = substr($jsSrc, $noCoordsIdx, 500);
    is_true(strpos($window2, '_teardownUnitMap();') !== false,
        'FIX: the no-coords branch also tears down before its L.map() call (async-race protection)');
}

// loadUnit()'s call to initMap() must still be present (this fix does not
// touch the defensive-ordering half of GH#121 — root-cause only, since the
// teardown alone eliminates the throw under normal use).
is_true(strpos($jsSrc, 'initMap(resp, data.resolved_location || null);') !== false,
    'loadUnit() still calls initMap() with the resolved-location argument');

echo "\n";
echo "==========================================================\n";
echo "unit-detail.js map container re-init tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
