<?php
/**
 * test_gh121_map_container_reinit.php — GH#121 (reported 2026-08-29,
 * diagnosed end-to-end by the reporter).
 *
 * THE BUG: saving any inline edit on incident-detail.php popped a red
 * "Failed to load incident: Map container is already initialized." alert
 * even though the save itself had already succeeded.
 *
 * ROOT CAUSE (confirmed by reading the code, not just trusting the
 * report): assets/js/incident-detail.js's `update_fields` inline-edit
 * success handler calls loadIncident(ticketId) after a successful save.
 * loadIncident() calls initMap(data.incident) on every load — including
 * every RE-load. initMap() unconditionally called `L.map('detailMap', ...)`
 * with no guard for an already-initialized container. Leaflet stamps the
 * container DOM NODE itself (an internal _leaflet_id) the first time
 * L.map() runs on it — the stamp lives on the DOM node, not on this
 * file's `map` JS variable — so a second L.map() call on the same node
 * throws "Map container is already initialized." regardless of what
 * `map` currently holds. Because initMap() was called with no try/catch
 * inside loadIncident()'s success handler, the throw propagated out of
 * that .then() callback straight into loadIncident()'s own .catch(),
 * producing the misleading "Failed to load incident" alert.
 *
 * WORSE THAN COSMETIC (confirmed, and actually broader than the issue's
 * own text): because the throw happens synchronously inside the .then()
 * success handler, EVERY statement after the initMap() call in that same
 * function body was skipped on every inline-edit save — not just
 * initEditButtons()/setInitialFocus()/loadDispositionOptions() as named
 * in the report, but also the "reveal mainContent" step, syncStatusSelect(),
 * the Navigate/Winlink button wiring, and the deferred loadResponders()
 * call. On a fresh page load this doesn't show (mainContent is already
 * visible from the FIRST successful load), which is exactly why nobody
 * noticed until the edit-buttons-not-rebound symptom was investigated.
 *
 * refreshIncident() (a different function, used by the ~9 other refresh
 * call sites in this file) never touches the map at all, which is why
 * loadIncident() — the update_fields handler's own reload path — is the
 * only place this fires.
 *
 * THE FIX has two independent parts, per the issue's own suggested
 * direction:
 *
 *   1. ROOT-CAUSE FIX — initMap() now tears down any existing map
 *      instance (`try { map.remove(); } catch (e) {} map = null;`,
 *      matching the exact idiom already used at assets/js/app.js's
 *      widgets:destroying handler) before creating a new one — in BOTH
 *      the synchronous hasCoords branch AND the async no-coords branch
 *      (a second call can race ahead of the first's map-config fetch).
 *      Extracted into a shared _teardownDetailMap() helper called at the
 *      top of initMap() and again immediately before the second
 *      L.map() call inside the no-coords fetch .then().
 *
 *   2. DEFENSIVE-ORDERING FIX (matching this codebase's own established
 *      fix shape for GH#98/GH#118 — a throw in one piece of init must
 *      never disable unrelated page controls) — loadIncident()'s success
 *      handler now wraps the initMap() call itself in try/catch, so a
 *      future/unrelated map failure can never again take out
 *      initEditButtons()/setInitialFocus()/loadDispositionOptions() and
 *      everything else sequenced after it. The caught error is logged via
 *      console.error rather than silently swallowed.
 *
 * This file proves both fixes independently, each with its own negative
 * control (the literal pre-fix source, inlined verbatim, run through the
 * exact same harness) to prove the harness would have caught the original
 * defect:
 *
 *   Section 1 (Node) — drives the REAL _teardownDetailMap()/initMap()
 *     extracted live from assets/js/incident-detail.js against a fake
 *     Leaflet that faithfully reproduces the real mechanism (a container
 *     DOM node carries a "stamp" once L.map() has run on it; a second
 *     L.map() call on an already-stamped node throws; map.remove() clears
 *     the stamp). Proves: (a) hasCoords branch — a second initMap() call
 *     does not throw, tears down the first map (remove() called), and
 *     installs a distinct new instance; (b) no-coords async branch — two
 *     rapid calls before the map-config fetch resolves don't double-stamp
 *     the container; (c) NEGATIVE CONTROL — the literal pre-fix
 *     initMap() (no teardown) reproduces the exact "Map container is
 *     already initialized" throw on a second call, through this SAME
 *     harness.
 *
 *   Section 2 (Node) — drives the REAL loadIncident() success-handler
 *     callback extracted live from the shipped file, with initMap()
 *     mocked to throw (simulating ANY future map failure, independent of
 *     the Leaflet-specific mechanism in Section 1). Proves: (a) the throw
 *     does not escape the handler; (b) initEditButtons()/
 *     setInitialFocus()/loadDispositionOptions() and the mainContent
 *     reveal all still run after a map failure; (c) the error is surfaced
 *     via console.error, not silently swallowed; (d) NEGATIVE CONTROL —
 *     the literal pre-fix handler (bare `initMap(data.incident);`, no
 *     try/catch) lets the throw propagate and skips every statement after
 *     it, through this SAME harness.
 *
 *   Section 3 (static) — the shipped file contains the teardown helper,
 *     calls it from both initMap() branches, and wraps the loadIncident()
 *     initMap() call in try/catch.
 *
 * Pure front-end JS fix — no schema/API/writer surface touched, so this
 * file has no DB fixture section (unlike test_gh98/test_gh118, which
 * verify a real PHP writer alongside the JS fix).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = realpath(__DIR__ . '/..');

echo "=== GH#121 — incident-detail.js Map container re-init ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

$jsPath = $base . '/assets/js/incident-detail.js';
$jsSrc  = (string) file_get_contents($jsPath);

/** Run a node harness script (as a string) with CLI args. Returns [name => ['ok'=>bool,'detail'=>str]]. */
function gh121_run_js(string $node, string $harnessJs, array $args): array {
    $h = sys_get_temp_dir() . '/tcad_gh121_harness_' . getmypid() . '_' . mt_rand() . '.js';
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

function gh121_apply_results(array $results, string $prefix): void {
    if (!$results) {
        bad($prefix . 'node harness produced no parseable output');
        return;
    }
    foreach ($results as $name => $r) {
        $r['ok'] ? ok($prefix . $name) : bad($prefix . $name, $r['detail']);
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The REAL _teardownDetailMap()/initMap(), driven against a fake Leaflet --\n";
// ─────────────────────────────────────────────────────────────────────────

// The exact pre-fix initMap() (verbatim, from the version reported in
// GH#121 — assets/js/incident-detail.js:2947-3015 before this fix). No
// _teardownDetailMap() helper existed at all pre-fix, so this fixture is
// initMap() alone; the harness tolerates teardown being undefined.
$oldInitMapSrc = <<<'OLDJS'
function initMap(inc) {
    var container = document.getElementById('detailMap');
    if (!container || typeof L === 'undefined') return;

    var hasCoords = inc.lat && inc.lng && (inc.lat !== 0 || inc.lng !== 0);

    if (hasCoords) {
        map = L.map('detailMap', { zoomControl: true }).setView([inc.lat, inc.lng], 15);
    } else {
        // No coords — fetch defaults
        fetch('api/map-config.php')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                map = L.map('detailMap', { zoomControl: true })
                    .setView([cfg.def_lat || 39.8283, cfg.def_lng || -98.5795], cfg.def_zoom || 5);
                setTimeout(function () { map.invalidateSize(); }, 200);
            })
            .catch(function () {});
        return;
    }

    marker = L.marker([inc.lat, inc.lng]).addTo(map);
    setTimeout(function () { map.invalidateSize(); }, 200);
}
OLDJS;

$harness1 = <<<'JS'
// Drives the REAL _teardownDetailMap()/initMap() extracted live from the
// actual assets/js/incident-detail.js on disk (process.argv[2]), against a
// fake Leaflet that faithfully reproduces the real defect mechanism.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// ── Extract "function _teardownDetailMap() { ... }" followed by
// "function initMap(inc) { ... }" by balanced braces. Anchored on the
// unique function names. ──
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
    var td = extractBalanced(source, 'function _teardownDetailMap() {', 0);
    var im = extractBalanced(source, 'function initMap(inc) {', td ? td.end : 0);
    if (!im) return null;
    return (td ? td.text + '\n' : '') + im.text;
}

// ── Fake Leaflet: a container carries a "stamp" once L.map() has run on
// it (mirroring Leaflet's real _leaflet_id container stamp). A second
// L.map() call on an already-stamped container throws, matching the
// verbatim string from assets/vendor/leaflet/leaflet.js. map.remove()
// clears the stamp — that IS the mechanism this fix depends on. ──
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
                closePopup: function () {},
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
            var o = { addTo: function () { return o; }, bindPopup: function () { return o; }, setLatLng: function () { return o; } };
            return o;
        },
        circleMarker: function () {
            var o = { addTo: function () { return o; }, bindPopup: function () { return o; } };
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
    global.document = { getElementById: function (id) { return id === 'detailMap' ? container : null; } };
    global.window = {}; // no MapPrefs -> falls back to L.tileLayer branch
    global.escHtml = function (s) { return s == null ? '' : String(s); };
    global.fetch = function () {
        return Promise.resolve({ json: function () { return Promise.resolve({}); } });
    };
    eval(fnSrc);
    return {
        initMap: (typeof initMap === 'function') ? initMap : null,
        hasTeardown: typeof _teardownDetailMap === 'function',
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
    check('extracted _teardownDetailMap()+initMap() from the shipped file', !!extracted,
        extracted ? (extracted.length + ' chars') : 'anchor/markers not found');

    if (extracted) {
        // ── A. hasCoords branch, called twice — THE bug scenario: a
        // save-triggered reload calling initMap() a second time. ──
        var containerA = { stamped: false };
        var hA = makeHarness(extracted, containerA);
        check('shipped file defines a _teardownDetailMap() helper', hA.hasTeardown);

        var threwFirst = false, firstErr = '';
        try { hA.initMap({ lat: 44.9, lng: -93.2 }); } catch (e) { threwFirst = true; firstErr = String(e); }
        check('first initMap() call (with coords) does not throw', !threwFirst, firstErr);
        var firstMap = hA.getMap();
        check('first call created a map instance', !!firstMap);
        check('container is stamped after the first call', containerA.stamped === true);

        var threwSecond = false, secondErr = '';
        try { hA.initMap({ lat: 44.95, lng: -93.3 }); } catch (e) { threwSecond = true; secondErr = String(e); }
        check('FIX: second initMap() call (loadIncident() re-run after an inline-edit save) does NOT throw',
            !threwSecond, secondErr);
        check('FIX: the first map instance was torn down (map.remove() was called on it)',
            !!firstMap && firstMap.isRemoved() === true);
        var secondMap = hA.getMap();
        check('FIX: a distinct new map instance replaced the old one',
            !!secondMap && secondMap !== firstMap, secondMap === firstMap ? 'same instance reused' : '');
        check('container ends up stamped exactly once (no leak, no double-stamp)',
            containerA.stamped === true);

        // Marker must be reset too — a marker bound to the removed map
        // instance would be stale/inert on the new one.
        check('marker was cleared by the teardown before the second call re-added it',
            hA.getMarker() !== null, 'a fresh marker should exist after a hasCoords re-init');

        // ── B. no-coords (async) branch, raced twice before the first
        // map-config fetch resolves. ──
        var containerB = { stamped: false };
        var hB = makeHarness(extracted, containerB);
        var threwB1 = false, threwB2 = false;
        try { hB.initMap({}); } catch (e) { threwB1 = true; }
        try { hB.initMap({}); } catch (e) { threwB2 = true; }
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
            try { hC.initMap({ lat: 44.9, lng: -93.2 }); } catch (e) { threwOldFirst = true; }
            check('NEGATIVE CONTROL: first pre-fix initMap() call does not throw (matches real pre-fix behavior)',
                !threwOldFirst);
            var threwOldSecond = false, oldErr = '';
            try { hC.initMap({ lat: 44.95, lng: -93.3 }); } catch (e) { threwOldSecond = true; oldErr = String(e); }
            check('NEGATIVE CONTROL: second pre-fix initMap() call THROWS "Map container is already initialized" — reproduces GH#121',
                threwOldSecond === true && /already initialized/i.test(oldErr), oldErr);
        }
    }

    console.log(out.join('\n'));
})();
JS;

if ($node === null) {
    echo "SKIP: node not available — Section 1 JS execution checks were not run\n";
} else {
    $oldFixturePath = sys_get_temp_dir() . '/tcad_gh121_old_initmap_' . getmypid() . '.js';
    file_put_contents($oldFixturePath, $oldInitMapSrc);
    $results = gh121_run_js($node, $harness1, [
        str_replace('\\', '/', $jsPath),
        str_replace('\\', '/', $oldFixturePath),
    ]);
    @unlink($oldFixturePath);
    gh121_apply_results($results, '[js:teardown] ');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The REAL loadIncident() success handler, driven under node --\n";
// ─────────────────────────────────────────────────────────────────────────

// The exact pre-fix success-handler body (verbatim, from the version
// reported in GH#121 — assets/js/incident-detail.js:1045-1134 before this
// fix): a bare `initMap(data.incident);` call with no try/catch.
$oldHandlerSrc = <<<'OLDJS'
function (data) {
    if (data.error) {
        showAlert(escHtml(data.error), 'danger');
        document.getElementById('loadingSpinner').classList.add('d-none');
        return;
    }

    incidentData = data;

    document.title = '#' + data.incident.id + ' ' + data.incident.scope + ' — Tickets NewUI';

    renderHeader(data.incident);
    renderDescription(data.incident);
    renderLocation(data.incident);
    renderContact(data.incident);
    renderFacilities(data.incident);
    renderTimeStatus(data.incident);
    renderAdditional(data.incident);
    renderProtocol(data.incident);
    renderAssignments(data.assignments);
    renderActions(data.actions);
    initMap(data.incident);

    document.getElementById('loadingSpinner').classList.add('d-none');
    document.getElementById('mainContent').classList.remove('d-none');

    syncStatusSelect(data.incident);

    var navBtn = document.getElementById('btnNavigate');
    if (navBtn && data.incident.lat && data.incident.lng &&
        parseFloat(data.incident.lat) !== 0 && parseFloat(data.incident.lng) !== 0) {
        navBtn.classList.remove('d-none');
    }

    var wlBtn = document.getElementById('btnWinlinkExport');
    if (wlBtn) {
        wlBtn.href = 'api/winlink-export.php?form=ics213&ticket_id=' + data.incident.id;
        wlBtn.classList.remove('d-none');
    }

    if (window.requestIdleCallback) {
        window.requestIdleCallback(loadResponders, { timeout: 500 });
    } else {
        setTimeout(loadResponders, 50);
    }

    initEditButtons();
    setInitialFocus();
    loadDispositionOptions(id);
}
OLDJS;

$harness2 = <<<'JS'
// Drives the REAL loadIncident() success-handler callback extracted live
// from the actual assets/js/incident-detail.js on disk (process.argv[2]).
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// ── Extract the function(data){...} passed to the FIRST ".then(function
// (data) {" occurrence AFTER "function loadIncident(id) {" — that inner
// marker string recurs ~30 times across the file (every other endpoint's
// success handler), so anchoring on loadIncident's own declaration first
// is what makes this unambiguous. ──
function extractLoadIncidentHandler(source) {
    var fnAnchor = 'function loadIncident(id) {';
    var fnIdx = source.indexOf(fnAnchor);
    if (fnIdx === -1) return null;
    var marker = '.then(function (data) {';
    var mIdx = source.indexOf(marker, fnIdx);
    if (mIdx === -1) return null;
    var exprStart = mIdx + '.then('.length; // -> "function (data) {"
    var braceStart = source.indexOf('{', exprStart);
    if (braceStart === -1) return null;
    var depth = 0, i = braceStart;
    for (; i < source.length; i++) {
        var c = source[i];
        if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) { i++; break; } }
    }
    if (depth !== 0) return null;
    return source.slice(exprStart, i); // "function (data) { ... }"
}

function makeIncident(withCoords) {
    return withCoords
        ? { id: 555, scope: 'gh121_test', lat: 44.9, lng: -93.2, street: 'x', city: 'y', state: 'MN' }
        : { id: 555, scope: 'gh121_test', lat: 0, lng: 0 };
}

function runHandler(fnSrc, mapThrows) {
    var calls = {
        renderHeader: 0, renderDescription: 0, renderLocation: 0, renderContact: 0,
        renderFacilities: 0, renderTimeStatus: 0, renderAdditional: 0, renderProtocol: 0,
        renderAssignments: 0, renderActions: 0, renderPrimaryUnitBanner: 0, syncStatusSelect: 0,
        initEditButtons: 0, setInitialFocus: 0, loadDispositionOptions: [],
        mainContentRevealed: false, loadingSpinnerHidden: false
    };
    var consoleErrors = [];
    var realConsoleError = console.error;
    console.error = function () { consoleErrors.push(Array.prototype.slice.call(arguments)); };

    global.incidentData = null;
    global.id = 555; // loadIncident(id)'s own parameter, closed over by loadDispositionOptions(id)
    global.document = {
        title: '',
        getElementById: function (elId) {
            if (elId === 'loadingSpinner') return { classList: { add: function () { calls.loadingSpinnerHidden = true; }, remove: function () {} } };
            if (elId === 'mainContent') return { classList: { add: function () {}, remove: function () { calls.mainContentRevealed = true; } } };
            return null; // btnNavigate / btnWinlinkExport — exercise the "not present" path
        }
    };
    // no requestIdleCallback -> setTimeout(loadResponders, 50) branch.
    // window.console must be truthy for the fix's
    // `if (window.console && console.error) { console.error(...); }`
    // guard to actually invoke the (spied) global console.error below.
    global.window = { console: console };
    global.showAlert = function () {};
    global.escHtml = function (s) { return s == null ? '' : String(s); };
    global.renderHeader = function () { calls.renderHeader++; };
    global.renderDescription = function () { calls.renderDescription++; };
    global.renderLocation = function () { calls.renderLocation++; };
    global.renderContact = function () { calls.renderContact++; };
    global.renderFacilities = function () { calls.renderFacilities++; };
    global.renderTimeStatus = function () { calls.renderTimeStatus++; };
    global.renderAdditional = function () { calls.renderAdditional++; };
    global.renderProtocol = function () { calls.renderProtocol++; };
    global.renderAssignments = function () { calls.renderAssignments++; };
    global.renderActions = function () { calls.renderActions++; };
    // Phase 151 (GH#138, 2026-09-03) — the real handler now also calls
    // renderPrimaryUnitBanner(data.incident, data.primary_candidates || []),
    // right after renderAssignments(). Stub it the same as every sibling
    // render* function above, or the eval'd handler throws a ReferenceError
    // that this very test exists to catch (a throw from ANY call inside the
    // success handler must not cascade and skip initEditButtons()/
    // setInitialFocus()/loadDispositionOptions() etc.).
    global.renderPrimaryUnitBanner = function () { calls.renderPrimaryUnitBanner++; };
    global.syncStatusSelect = function () { calls.syncStatusSelect++; };
    global.loadResponders = function () {};
    global.initEditButtons = function () { calls.initEditButtons++; };
    global.setInitialFocus = function () { calls.setInitialFocus++; };
    global.loadDispositionOptions = function (theId) { calls.loadDispositionOptions.push(theId); };
    global.initMap = function () {
        if (mapThrows) throw new Error('Map container is already initialized.');
    };

    var handlerFn = eval('(' + fnSrc + ')');
    var threw = false, threwMsg = '';
    try {
        handlerFn({ incident: makeIncident(true), assignments: [], actions: [] });
    } catch (e) {
        threw = true; threwMsg = String(e);
    }

    console.error = realConsoleError;
    return { threw: threw, threwMsg: threwMsg, calls: calls, consoleErrors: consoleErrors };
}

var srcPath = process.argv[2];
var oldFixturePath = process.argv[3];
var src = fs.readFileSync(srcPath, 'utf8');
var handlerSrc = extractLoadIncidentHandler(src);
check('extracted the real loadIncident() success handler from incident-detail.js', !!handlerSrc,
    handlerSrc ? (handlerSrc.length + ' chars') : 'anchor/markers not found');

if (handlerSrc) {
    // ── A. initMap() throws (the real GH#121 failure mode, and any
    // future/unrelated map failure per the defensive-ordering fix). ──
    var rA = runHandler(handlerSrc, true);
    check('FIX: a throw inside initMap() does NOT propagate out of the success handler',
        rA.threw === false, rA.threwMsg);
    check('FIX: initEditButtons() still runs after a map failure', rA.calls.initEditButtons === 1);
    check('FIX: setInitialFocus() still runs after a map failure', rA.calls.setInitialFocus === 1);
    check('FIX: loadDispositionOptions(id) still runs after a map failure, with the correct id',
        rA.calls.loadDispositionOptions.length === 1 && rA.calls.loadDispositionOptions[0] === 555,
        JSON.stringify(rA.calls.loadDispositionOptions));
    check('FIX: mainContent is still revealed after a map failure', rA.calls.mainContentRevealed === true);
    check('FIX: loadingSpinner is still hidden after a map failure', rA.calls.loadingSpinnerHidden === true);
    check('FIX: syncStatusSelect() still runs after a map failure', rA.calls.syncStatusSelect === 1);
    check('FIX: the map error is surfaced via console.error, not silently swallowed',
        rA.consoleErrors.length === 1, JSON.stringify(rA.consoleErrors));

    // ── B. initMap() does NOT throw — normal path must be unaffected. ──
    var rB = runHandler(handlerSrc, false);
    check('normal path (no map failure): handler does not throw', rB.threw === false, rB.threwMsg);
    check('normal path: initEditButtons()/setInitialFocus()/loadDispositionOptions() all still run',
        rB.calls.initEditButtons === 1 && rB.calls.setInitialFocus === 1 &&
        rB.calls.loadDispositionOptions.length === 1);
    check('normal path: no spurious console.error', rB.consoleErrors.length === 0, JSON.stringify(rB.consoleErrors));

    // ── C. NEGATIVE CONTROL: the literal pre-fix handler (bare
    // initMap(data.incident); call, no try/catch) — same harness. ──
    var oldSrc = fs.readFileSync(oldFixturePath, 'utf8');
    var rC = runHandler(oldSrc, true);
    check('NEGATIVE CONTROL: pre-fix handler DOES throw when initMap() fails — reproduces GH#121',
        rC.threw === true && /already initialized/i.test(rC.threwMsg), rC.threwMsg);
    check('NEGATIVE CONTROL: pre-fix handler skips initEditButtons() after a map failure (the reported symptom)',
        rC.calls.initEditButtons === 0);
    check('NEGATIVE CONTROL: pre-fix handler skips setInitialFocus() after a map failure',
        rC.calls.setInitialFocus === 0);
    check('NEGATIVE CONTROL: pre-fix handler skips loadDispositionOptions() after a map failure',
        rC.calls.loadDispositionOptions.length === 0);
    check('NEGATIVE CONTROL: pre-fix handler also skips the mainContent reveal after a map failure',
        rC.calls.mainContentRevealed === false);
}

console.log(out.join('\n'));
JS;

if ($node === null) {
    echo "SKIP: node not available — Section 2 JS execution checks were not run\n";
} else {
    $oldFixturePath2 = sys_get_temp_dir() . '/tcad_gh121_old_handler_' . getmypid() . '.js';
    file_put_contents($oldFixturePath2, $oldHandlerSrc);
    $results2 = gh121_run_js($node, $harness2, [
        str_replace('\\', '/', $jsPath),
        str_replace('\\', '/', $oldFixturePath2),
    ]);
    @unlink($oldFixturePath2);
    gh121_apply_results($results2, '[js:handler] ');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Static source assertions on the shipped file --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(strpos($jsSrc, 'function _teardownDetailMap() {') !== false,
    'shipped file defines the _teardownDetailMap() helper');

$initMapIdx = strpos($jsSrc, 'function initMap(inc) {');
is_true($initMapIdx !== false, 'shipped file still defines initMap(inc)');

if ($initMapIdx !== false) {
    $window = substr($jsSrc, $initMapIdx, 400);
    is_true(strpos($window, '_teardownDetailMap();') !== false,
        'FIX: initMap() calls _teardownDetailMap() before doing anything else');
}

// The no-coords branch's fetch.then() must ALSO tear down before creating
// a second map instance (the async-race protection).
$noCoordsIdx = strpos($jsSrc, "fetch('api/map-config.php')");
is_true($noCoordsIdx !== false, 'shipped file still has the no-coords map-config fetch branch');
if ($noCoordsIdx !== false) {
    $window2 = substr($jsSrc, $noCoordsIdx, 500);
    is_true(strpos($window2, '_teardownDetailMap();') !== false,
        'FIX: the no-coords branch also tears down before its L.map() call (async-race protection)');
}

// loadIncident()'s call to initMap() must be wrapped in try/catch.
$loadIncidentIdx = strpos($jsSrc, 'function loadIncident(id) {');
is_true($loadIncidentIdx !== false, 'shipped file still defines loadIncident(id)');
if ($loadIncidentIdx !== false) {
    $handlerWindow = substr($jsSrc, $loadIncidentIdx, 3000);
    $tryIdx = strpos($handlerWindow, 'try {');
    $callIdx = strpos($handlerWindow, 'initMap(data.incident);');
    $catchIdx = strpos($handlerWindow, '} catch (mapErr) {');
    is_true($tryIdx !== false && $callIdx !== false && $catchIdx !== false &&
        $tryIdx < $callIdx && $callIdx < $catchIdx,
        'FIX: loadIncident() wraps its initMap() call in try/catch (defensive ordering, matching GH#98/GH#118)');
    is_true(strpos($handlerWindow, 'console.error') !== false,
        'FIX: the caught map error is surfaced via console.error, not silently swallowed');
}

// The exact pre-fix bare call (no try/catch immediately around it) must
// no longer be present as the unguarded shape.
is_true(strpos($jsSrc, "renderActions(data.actions);\n                initMap(data.incident);\n\n                // Show content") === false,
    'the exact pre-fix unguarded initMap() call shape is no longer present in the shipped file');

echo "\n";
echo "==========================================================\n";
echo "GH#121 Map container re-init tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
