<?php
/**
 * test_gh133_facility_marker_wiring.php — GH#133.
 *
 * "New Incident Form when creating incident at facility location does not
 * plot" (reported by a beta tester, corroborated by rjonesbsink). The backend
 * (api/incident-types.php) has always returned lat/lng for every facility,
 * but assets/js/new-incident.js's populateFacilities() only ever populated
 * the <select> options — it never stashed the coordinates anywhere, and
 * there was no change listener on #facility to call the existing setMarker()
 * function. The data reached the browser; nothing in the browser used it.
 *
 * The fix stashes each option's lat/lng as data-lat/data-lng and wires a
 * change listener on #facility (only) that calls setMarker(). "Receiving
 * Facility" (#rec_facility) is a transport destination, not the incident's
 * own location, and must NOT move the map — this is asserted as a negative
 * case below, not just assumed from the label text.
 *
 * This runs the REAL populateFacilities() function, extracted verbatim from
 * the shipped file and executed under node against a minimal DOM double —
 * not a hand-written reimplementation of what it's supposed to do. A test
 * that only grepped for "setMarker" being mentioned somewhere in the file
 * would have passed the entire time this bug was live (the old file already
 * had a working setMarker() — it was simply never called from here).
 */

$base = realpath(__DIR__ . '/..');

echo "=== GH#133 — facility-selection map plotting ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Backend still returns lat/lng for every facility --\n";
// ─────────────────────────────────────────────────────────────────────────

$apiSrc = (string) file_get_contents($base . '/api/incident-types.php');
is_true(strpos($apiSrc, '`lat`, `lng`') !== false || preg_match('/SELECT[^;]*facilities[^;]*lat[^;]*lng/is', $apiSrc) === 1,
    'api/incident-types.php selects lat/lng for facilities (the data the fix depends on)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Static wiring in the real file --\n";
// ─────────────────────────────────────────────────────────────────────────

$jsPath = $base . '/assets/js/new-incident.js';
$jsSrc  = (string) file_get_contents($jsPath);

is_true(strpos($jsSrc, "function populateFacilities(facilities)") !== false,
    'populateFacilities() exists');
is_true(preg_match('/facSel\.addEventListener\(\s*[\'"]change[\'"]/', $jsSrc) === 1,
    'a change listener is attached to the "Incident at Facility" select');
is_true(preg_match('/opt\.dataset\.lat\s*=\s*f\.lat/', $jsSrc) === 1,
    'each facility option carries its lat in a data attribute');
is_true(preg_match('/opt\.dataset\.lng\s*=\s*f\.lng/', $jsSrc) === 1,
    'each facility option carries its lng in a data attribute');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The real populateFacilities(), executed under node --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the real-function execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_gh133_harness_' . getmypid() . '.js';
    $jsPathFwd = str_replace('\\', '/', $jsPath);
    $js = <<<'JS'
var fs = require('fs');
var srcPath = process.argv[2];
var src = fs.readFileSync(srcPath, 'utf8');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// Extract the real populateFacilities() body by brace-matching — this is the
// shipped function text, not a reimplementation.
var marker = 'function populateFacilities(facilities) {';
var start = src.indexOf(marker);
check('populateFacilities() found in source for extraction', start !== -1);
if (start === -1) {
    console.log(out.join('\n'));
    process.exit(0);
}
var i = start + marker.length;
var depth = 1;
while (depth > 0 && i < src.length) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') depth--;
    i++;
}
var fnSrc = src.slice(start, i);
check('extracted function body is non-trivial', fnSrc.length > 200, String(fnSrc.length));

// Minimal DOM double. option.value mimics real <option> string coercion so
// select.value = '5' matches an option built from a numeric f.id, exactly
// as it does in a live browser.
function makeOption() {
    var v = '';
    return {
        get value() { return v; },
        set value(x) { v = String(x); },
        textContent: '',
        dataset: {}
    };
}
function makeSelect(id) {
    var _value = '';
    var el = {
        id: id,
        options: [],
        selectedIndex: 0,
        _listeners: {},
        appendChild: function (opt) { el.options.push(opt); },
        get value() { return _value; },
        set value(v) {
            _value = String(v);
            var idx = -1;
            for (var k = 0; k < el.options.length; k++) {
                if (el.options[k].value === _value) { idx = k; break; }
            }
            el.selectedIndex = idx === -1 ? 0 : idx;
        },
        addEventListener: function (evt, fn) {
            el._listeners[evt] = el._listeners[evt] || [];
            el._listeners[evt].push(fn);
        },
        dispatchEvent: function (evt) {
            (el._listeners[evt.type] || []).forEach(function (fn) { fn.call(el, evt); });
            return true;
        }
    };
    return el;
}

var markerCalls = [];
global.setMarker = function (lat, lng) { markerCalls.push([lat, lng]); };
global.Event = function (type) { this.type = type; };

function freshDom(search) {
    var facSel = makeSelect('facility');
    var recSel = makeSelect('rec_facility');
    global.document = {
        getElementById: function (id) {
            if (id === 'facility') return facSel;
            if (id === 'rec_facility') return recSel;
            return null;
        },
        createElement: function (tag) { return tag === 'option' ? makeOption() : {}; }
    };
    global.window = { location: { search: search || '' } };
    global.URLSearchParams = function (qs) {
        var params = {};
        (qs || '').replace(/^\?/, '').split('&').forEach(function (pair) {
            if (!pair) return;
            var kv = pair.split('=');
            params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
        });
        return { get: function (k) { return Object.prototype.hasOwnProperty.call(params, k) ? params[k] : null; } };
    };
    return { facSel: facSel, recSel: recSel };
}

var facilities = [
    { id: 5, name: 'General Hospital', type: 'Hospital', lat: 44.5, lng: -93.2 },
    { id: 6, name: 'No-Coords Clinic', type: 'Clinic', lat: null, lng: null }
];

// -- Scenario A: options populated on BOTH selects, coords stashed on both --
var domA = freshDom('');
eval(fnSrc);
populateFacilities(facilities);

check('facility select gets one option per facility', domA.facSel.options.length === 2,
      String(domA.facSel.options.length));
check('rec_facility select gets one option per facility too (display only)',
      domA.recSel.options.length === 2, String(domA.recSel.options.length));
check('facility option 1 carries its lat', domA.facSel.options[0].dataset.lat === 44.5,
      String(domA.facSel.options[0].dataset.lat));
check('facility option 1 carries its lng', domA.facSel.options[0].dataset.lng === -93.2,
      String(domA.facSel.options[0].dataset.lng));
check('the no-coords facility carries no lat/lng data attribute',
      domA.facSel.options[1].dataset.lat === undefined && domA.facSel.options[1].dataset.lng === undefined);

// -- Scenario B: THE regression, reproduced and proven fixed. Selecting a
//    facility with coordinates must plot it. --
domA.facSel.value = '5';
domA.facSel.dispatchEvent(new global.Event('change'));
check('selecting a facility WITH coordinates calls setMarker exactly once',
      markerCalls.length === 1, String(markerCalls.length));
check('setMarker is called with the facility\'s own lat/lng',
      markerCalls.length === 1 && markerCalls[0][0] === 44.5 && markerCalls[0][1] === -93.2,
      JSON.stringify(markerCalls));

// -- Scenario C: a facility with no coordinates must not crash and must not
//    fabricate a call (NaN must never reach setMarker). --
domA.facSel.value = '6';
domA.facSel.dispatchEvent(new global.Event('change'));
check('selecting a facility with NO coordinates does not call setMarker again',
      markerCalls.length === 1, String(markerCalls.length));

// -- Scenario D: "Receiving Facility" must NEVER drive the map, even though
//    it also carries lat/lng data attributes (harmless — just unused). --
check('rec_facility never received a change listener at all',
      !domA.recSel._listeners.change || domA.recSel._listeners.change.length === 0);

// -- Scenario E: the dashboard's ?facility=<id> deep link (Phase 115) must
//    ALSO plot the pre-selected facility, not just a manual pick. --
markerCalls.length = 0;
var domE = freshDom('?facility=5');
eval(fnSrc);
populateFacilities(facilities);
check('the ?facility= deep-link preselect also plots the facility',
      markerCalls.length === 1 && markerCalls[0][0] === 44.5 && markerCalls[0][1] === -93.2,
      JSON.stringify(markerCalls));

// -- Scenario F: an empty facility list must not throw. --
markerCalls.length = 0;
var domF = freshDom('');
eval(fnSrc);
var threw = false;
try { populateFacilities([]); } catch (e) { threw = true; }
check('an empty facilities list does not throw', !threw);

console.log(out.join('\n'));
JS;
    file_put_contents($harness, $js);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($jsPathFwd) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        bad('node harness ran the real populateFacilities()', trim((string) $raw));
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2) { continue; }
            if ($parts[0] === 'PASS') { ok('[js] ' . $parts[1]); }
            else { bad('[js] ' . $parts[1], $parts[2] ?? ''); }
        }
    }
}

echo "\n";
echo "==========================================================\n";
echo "GH#133 facility marker tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
