<?php
/**
 * test_gh98_dest_dropdown_ticketid.php — GH#98 (reported 2026-08-20,
 * diagnosed end-to-end by the reporter).
 *
 * THE BUG: assets/js/incident-detail.js's per-unit "Dest" dropdown change
 * handler (Phase 116) referenced `ticketId` in the POST body to
 * api/incident-assign.php action=set_rec_facility, but never declared it in
 * that handler's scope — every OTHER handler in the file resolves it
 * explicitly with `var ticketId = getIncidentId();` (8 other call sites).
 * This handler was the sole exception.
 *
 * Because the reference is inside the object literal passed to
 * JSON.stringify(), the ReferenceError is thrown while building the fetch()
 * call — AFTER `selfRef.disabled = true` had already run but BEFORE fetch()
 * itself, so neither .then() nor .catch() ever executed: no request was
 * sent, no error was shown, and the select was left permanently disabled.
 * The value appeared to "revert" on the next status change only because
 * nothing was ever actually written — render correctly fell back to the
 * call's own receiving facility since assigns.rec_facility_id stayed NULL.
 *
 * CONFIRMED WORKING, and deliberately NOT re-tested here beyond a source
 * sanity check: the API endpoint (api/incident-assign.php
 * action=set_rec_facility) and its writer (assign_set_rec_facility()) —
 * see tools/test_bed_auto_dest_dropdown.php, which already drives that
 * writer directly. The reporter verified the endpoint directly too. Only
 * the ONE change handler's variable scoping (and its disable-before-safety-
 * net ordering) was broken.
 *
 * This file proves two independent things:
 *
 *   Section 1 (PHP) — the real writer still persists rec_facility_id
 *     correctly, and the endpoint's own ticket_id<->assign_id match guard
 *     is exactly why an unresolved ticketId would have mattered (a wrong or
 *     missing ticket_id 404s "Assignment not found" rather than silently
 *     writing to the wrong ticket).
 *
 *   Section 2 (Node) — drives the REAL, unmodified change handler function
 *     extracted live from assets/js/incident-detail.js (not a hand-copied
 *     stand-in) under node, proving:
 *       (a) it no longer throws — ticketId resolves — and the POST body it
 *           builds carries the correct ticket_id/assign_id/facility_id;
 *       (b) the control disables while in flight and re-enables after any
 *           outcome (success, server error, network failure);
 *       (c) THE defensive fix: a throw while building the request body
 *           (e.g. getCsrfToken() throwing) no longer permanently bricks the
 *           control — it re-enables and surfaces an error;
 *       (d) NEGATIVE CONTROL — the literal pre-fix handler source (inlined
 *           verbatim below, not the current file) reproduces the exact bug
 *           through this SAME harness: it throws, sends no request, and
 *           leaves the control permanently disabled. This proves the
 *           harness would have caught the original defect, so its passing
 *           assertions above mean something.
 *
 *   Section 3 (static) — the shipped file no longer contains the broken
 *     shape, does contain the `var ticketId = getIncidentId();` resolution,
 *     and disables the control only AFTER the request body is safely built
 *     (never before).
 *
 * A quick audit of every other `.disabled = true` assignment in this file
 * found each one sits inside a function that already resolves `ticketId`
 * (or has no ticketId dependency at all) BEFORE any DOM/control mutation —
 * this handler was the sole exception, so no other handler needed the
 * same defensive-ordering fix. Not re-verified per-handler here (out of
 * scope per the fix instructions); see the commit for the grep evidence.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#98 — Dest dropdown ticketId ReferenceError ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The real writer + the endpoint's ticket<->assign guard --\n";
// ─────────────────────────────────────────────────────────────────────────

$tid = 0; $ridA = 0; $ridB = 0; $fidA = 0; $fidB = 0; $aidA = 0; $aidB = 0;
try {
    $userId = test_admin_user_id();

    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, updated, _by, _on)
              VALUES ('gh98_fac_A', 'test', 0, 0, NOW(), ?, NOW())", [$userId]);
    $fidA = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, updated, _by, _on)
              VALUES ('gh98_fac_B', 'test', 0, 0, NOW(), ?, NOW())", [$userId]);
    $fidB = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh98_unit_A', 'GH98A', 'test', 1, NOW(), NOW())");
    $ridA = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh98_unit_B', 'GH98B', 'test', 1, NOW(), NOW())");
    $ridB = (int) db_insert_id();

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, rec_facility, _by)
              VALUES (?, 2, 0, 'gh98_ticket', 'GH98 Dest dropdown fixture', NOW(), NOW(), ?, 1)",
              [$typeId, $fidA]);
    $tid = (int) db_insert_id();

    $ra = assign_create_internal($tid, $ridA, '', $userId);
    $rb = assign_create_internal($tid, $ridB, '', $userId);
    is_true((int) ($ra['id'] ?? 0) > 0 && (int) ($rb['id'] ?? 0) > 0, 'both units assigned via the real writer');
    $aidA = (int) $ra['id'];
    $aidB = (int) $rb['id'];

    // This is exactly what the FIXED JS handler now sends: a correctly
    // resolved ticket_id used to find the assign, and assign_set_rec_facility()
    // called with the assign id + chosen facility id. rec_facility_id starts
    // NULL (both units inherit the call's facility) — the same starting
    // state the reporter verified.
    $before = db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidB]);
    is_true($before === null, 'unit B starts with rec_facility_id NULL (inheriting the call facility)');

    assign_set_rec_facility($aidB, $fidB, $userId);
    $after = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidB]);
    is_true($after === $fidB, 'the real writer persists the per-unit facility to assigns.rec_facility_id',
        "expected {$fidB}, got {$after}");

    // Unit A never had its Dest touched — must still be NULL (inheriting).
    $untouchedA = db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidA]);
    is_true($untouchedA === null, 'the OTHER unit (never touched) is unaffected — still inherits the call facility');
} catch (Throwable $e) {
    bad('fixture/writer path threw', $e->getMessage());
}

// The endpoint's own guard is WHY an unresolved ticket_id mattered: the
// assign lookup is scoped to `a.ticket_id = ?`, so a wrong/zero ticket_id
// would 404 "Assignment not found" (or be rejected earlier as "Invalid
// ticket ID") rather than silently doing the right thing by accident.
$epSrc = (string) file_get_contents($base . '/api/incident-assign.php');
is_true(strpos($epSrc, "\$ticket_id <= 0") !== false && strpos($epSrc, "Invalid ticket ID") !== false,
    'endpoint refuses a zero/invalid ticket_id outright');
is_true(strpos($epSrc, 'WHERE `a`.`id` = ? AND `a`.`ticket_id` = ?') !== false,
    'set_rec_facility scopes the assignment lookup to (assign_id, ticket_id) together — '
    . 'a wrong ticket_id 404s rather than silently touching the wrong ticket');
is_true(strpos($epSrc, 'assign_set_rec_facility($assign_id, $facility_id, (int) $current_user_id);') !== false,
    'endpoint still calls the confirmed-working writer unchanged');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The REAL JS change handler, extracted live and driven under node --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

$jsPath = $base . '/assets/js/incident-detail.js';

/** Run a node harness script (as a string) with one CLI arg. Returns [name => ['ok'=>bool,'detail'=>str]]. */
function gh98_run_js(string $node, string $harnessJs, string $arg): array {
    $h = sys_get_temp_dir() . '/tcad_gh98_harness_' . getmypid() . '_' . mt_rand() . '.js';
    file_put_contents($h, $harnessJs);
    $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' ' . escapeshellarg($arg) . ' 2>&1');
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

// The exact pre-fix handler text (verbatim, from the version reported in
// GH#98 — see git history at assets/js/incident-detail.js:2749-2765 before
// this fix). Inlined as the NEGATIVE CONTROL fixture: this is not a
// simplified stand-in, it is what shipped and broke.
$oldHandlerSrc = <<<'OLDJS'
function () {
    var assignId = parseInt(this.getAttribute('data-assign-id'), 10);
    var facId = parseInt(this.value, 10) || 0;
    var selfRef = this;
    selfRef.disabled = true;
    fetch('api/incident-assign.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:      'set_rec_facility',
            ticket_id:   ticketId,
            assign_id:   assignId,
            facility_id: facId,
            csrf_token:  getCsrfToken()
        })
    }).then(function (r) { return r.json(); }).then(function (data) {
        selfRef.disabled = false;
        if (data && data.error) { showAlert(data.error, 'danger'); return; }
        selfRef.setAttribute('data-current', String(facId));
        showAlert(data.message || 'Destination updated.', 'info');
    }).catch(function () {
        selfRef.disabled = false;
        showAlert('Failed to set destination.', 'danger');
    });
}
OLDJS;

$harness = <<<'JS'
// Drives the REAL change handler extracted live from the actual
// assets/js/incident-detail.js on disk (process.argv[2]) — not a copy.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var TICKET_ID = 431009; // arbitrary but fixed — only used to prove it round-trips

// ── Extract the "Dest" dropdown's change handler by balanced braces ──
// Anchored on the unique 'set_rec_facility' action string; the enclosing
// `addEventListener('change', function () { ... })` is the target. There is
// exactly one occurrence of 'set_rec_facility' in this file (confirmed).
function extractHandler(source) {
    var anchor = "'set_rec_facility'";
    var anchorIdx = source.indexOf(anchor);
    if (anchorIdx === -1) return null;
    var funcMarker = "addEventListener('change', function () {";
    var funcIdx = source.lastIndexOf(funcMarker, anchorIdx);
    if (funcIdx === -1) return null;
    var exprStart = funcIdx + "addEventListener('change', ".length; // -> "function () {"
    var braceStart = source.indexOf('{', exprStart);
    if (braceStart === -1) return null;
    var depth = 0, i = braceStart;
    for (; i < source.length; i++) {
        var c = source[i];
        if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) { i++; break; } }
    }
    if (depth !== 0) return null;
    return source.slice(exprStart, i); // "function () { ... }"
}

var srcPath = process.argv[2];
var src = fs.readFileSync(srcPath, 'utf8');
var handlerSrc = extractHandler(src);
check('extracted the real change handler from incident-detail.js', !!handlerSrc,
      handlerSrc ? (handlerSrc.length + ' chars') : 'anchor/markers not found');

var handlerFn = null;
if (handlerSrc) {
    try { handlerFn = eval('(' + handlerSrc + ')'); }
    catch (e) { check('extracted source parses as a function', false, String(e)); }
}

function makeSelect(assignId, currentFacId, chosenValue) {
    var attrs = { 'data-assign-id': String(assignId), 'data-current': String(currentFacId) };
    return {
        value: String(chosenValue),
        disabled: false,
        getAttribute: function (n) { return attrs[n]; },
        setAttribute: function (n, v) { attrs[n] = String(v); }
    };
}
function flush() { return new Promise(function (resolve) { setTimeout(resolve, 15); }); }

var alerts = [];
var fetchCalls = [];
var fetchBehavior = null;
global.getIncidentId = function () { return TICKET_ID; };
global.getCsrfToken = function () { return 'CSRF-TOKEN-XYZ'; };
global.showAlert = function (msg, type) { alerts.push({ msg: msg, type: type }); };
global.fetch = function (url, opts) {
    fetchCalls.push({ url: url, opts: opts });
    return fetchBehavior(url, opts);
};

(async function () {
    if (handlerFn) {
        // ── A. Success path: THE bug — ticketId must resolve, not throw ──
        alerts.length = 0; fetchCalls.length = 0;
        var facilityChosen = 771, assignIdA = 9001;
        fetchBehavior = function () {
            return Promise.resolve({ json: function () { return Promise.resolve({ message: 'Destination updated.' }); } });
        };
        var selA = makeSelect(assignIdA, 0, facilityChosen);
        var threwA = false, threwAMsg = '';
        try { handlerFn.call(selA); } catch (e) { threwA = true; threwAMsg = String(e); }
        check('FIX: handler does not throw synchronously (ticketId resolves)', threwA === false, threwAMsg);
        check('control disables while the request is in flight', selA.disabled === true);
        await flush();
        check('fetch was called exactly once', fetchCalls.length === 1, String(fetchCalls.length));
        var bodyA = fetchCalls.length ? JSON.parse(fetchCalls[0].opts.body) : {};
        check('FIX: POST body ticket_id equals getIncidentId() (THE bug)', bodyA.ticket_id === TICKET_ID, JSON.stringify(bodyA));
        check('POST body assign_id matches the row', bodyA.assign_id === assignIdA);
        check('POST body facility_id matches the chosen option', bodyA.facility_id === facilityChosen);
        check('POST body carries a csrf token', bodyA.csrf_token === 'CSRF-TOKEN-XYZ');
        check('POST goes to api/incident-assign.php', fetchCalls.length === 1 && fetchCalls[0].url === 'api/incident-assign.php');
        check('control re-enabled after a successful response', selA.disabled === false);
        check('data-current updated to the newly chosen facility', selA.getAttribute('data-current') === String(facilityChosen));
        check('success message surfaced', alerts.length === 1 && alerts[0].type === 'info', JSON.stringify(alerts));

        // ── B. Server error response ──
        alerts.length = 0; fetchCalls.length = 0;
        fetchBehavior = function () {
            return Promise.resolve({ json: function () { return Promise.resolve({ error: 'Facility not found' }); } });
        };
        var selB = makeSelect(9002, 3, 999);
        handlerFn.call(selB);
        await flush();
        check('server error: control re-enabled', selB.disabled === false);
        check('server error: danger alert surfaced', alerts.length === 1 && alerts[0].type === 'danger', JSON.stringify(alerts));
        check('server error: data-current NOT advanced', selB.getAttribute('data-current') === '3');

        // ── C. Network failure (.catch path) ──
        alerts.length = 0; fetchCalls.length = 0;
        fetchBehavior = function () { return Promise.reject(new Error('network down')); };
        var selC = makeSelect(9003, 0, 42);
        handlerFn.call(selC);
        await flush();
        check('network failure: control re-enabled', selC.disabled === false);
        check('network failure: danger alert surfaced', alerts.length === 1 && alerts[0].type === 'danger');

        // ── D. THE defensive-ordering fix: a throw while building the ──
        // request body must NOT permanently brick the control. This is the
        // reporter's specific ask, separate from the ticketId bug itself.
        alerts.length = 0; fetchCalls.length = 0;
        var savedGetCsrf = global.getCsrfToken;
        global.getCsrfToken = function () { throw new Error('simulated throw building the request body'); };
        var selD = makeSelect(9004, 0, 42);
        var threwD = false;
        try { handlerFn.call(selD); } catch (e) { threwD = true; }
        await flush();
        check('FIX: a throw building the body does not propagate out of the handler', threwD === false);
        check('FIX: a throw building the body means NO request is sent', fetchCalls.length === 0, String(fetchCalls.length));
        check('FIX: a throw building the body leaves the control ENABLED, not bricked', selD.disabled === false);
        check('FIX: a throw building the body surfaces an error to the dispatcher',
              alerts.length === 1 && alerts[0].type === 'danger', JSON.stringify(alerts));
        global.getCsrfToken = savedGetCsrf;
    }

    // ── NEGATIVE CONTROL: the literal PRE-FIX handler, same harness ──
    // Proves this harness actually detects the regression it exists to
    // catch — not just that the current file happens to look right.
    var oldSrc = fs.readFileSync(process.argv[3], 'utf8');
    var oldFn = null;
    try { oldFn = eval('(' + oldSrc + ')'); } catch (e) {}
    check('negative control fixture parses as a function', !!oldFn);
    if (oldFn) {
        alerts.length = 0; fetchCalls.length = 0;
        fetchBehavior = function () {
            return Promise.resolve({ json: function () { return Promise.resolve({ message: 'ok' }); } });
        };
        var selOld = makeSelect(9005, 0, 42);
        var threwOld = false, threwOldMsg = '';
        try { oldFn.call(selOld); } catch (e) { threwOld = true; threwOldMsg = String(e); }
        await flush();
        check('NEGATIVE CONTROL: pre-fix handler throws ReferenceError (reproduces GH#98)',
              threwOld === true && /ticketId/.test(threwOldMsg), threwOldMsg);
        check('NEGATIVE CONTROL: pre-fix handler sends NO request (the whole bug)',
              fetchCalls.length === 0, String(fetchCalls.length));
        check('NEGATIVE CONTROL: pre-fix handler leaves the control PERMANENTLY DISABLED',
              selOld.disabled === true);
        check('NEGATIVE CONTROL: pre-fix handler surfaces NO error to the dispatcher (silent)',
              alerts.length === 0, JSON.stringify(alerts));
    }

    console.log(out.join('\n'));
})();
JS;

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $oldFixturePath = sys_get_temp_dir() . '/tcad_gh98_old_handler_' . getmypid() . '.js';
    file_put_contents($oldFixturePath, $oldHandlerSrc);

    $h = sys_get_temp_dir() . '/tcad_gh98_harness_wrap_' . getmypid() . '.js';
    file_put_contents($h, $harness);
    $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' '
        . escapeshellarg(str_replace('\\', '/', $jsPath)) . ' '
        . escapeshellarg(str_replace('\\', '/', $oldFixturePath)) . ' 2>&1');
    @unlink($h);
    @unlink($oldFixturePath);

    $results = [];
    if (is_string($raw)) {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', trim($line), 3);
            if (count($parts) < 2) continue;
            if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
            $results[$parts[1]] = ['ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
        }
    }
    if (!$results) {
        bad('node harness ran incident-detail.js', 'no parseable output: ' . substr((string) $raw, 0, 2000));
    } else {
        foreach ($results as $name => $r) {
            $r['ok'] ? ok('[js] ' . $name) : bad('[js] ' . $name, $r['detail']);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Static source assertions on the shipped file --\n";
// ─────────────────────────────────────────────────────────────────────────

$jsSrc = (string) file_get_contents($jsPath);

$anchorIdx = strpos($jsSrc, "'set_rec_facility'");
is_true($anchorIdx !== false, 'shipped file still contains the set_rec_facility action');

if ($anchorIdx !== false) {
    $markerPos = strrpos(substr($jsSrc, 0, $anchorIdx), "addEventListener('change', function () {");
    is_true($markerPos !== false, 'located the enclosing change handler in the shipped file');

    if ($markerPos !== false) {
        // Grab a reasonably-sized window forward for the ordering/content checks
        // (the handler body, including its explanatory comments, is well
        // under 3500 chars).
        $block = substr($jsSrc, $markerPos, 3500);

        is_true(strpos($block, 'var ticketId = getIncidentId();') !== false,
            'FIX: the handler resolves ticketId the same way every other handler in the file does');
        is_true(strpos($block, 'if (!ticketId) return;') !== false,
            'FIX: the handler guards against an unresolved ticketId, matching the file convention');

        $bodyPos     = strpos($block, 'body = JSON.stringify(');
        $disabledPos = strpos($block, 'selfRef.disabled = true;');
        is_true($bodyPos !== false && $disabledPos !== false && $bodyPos < $disabledPos,
            'FIX: the request body is built BEFORE the control is disabled (defensive ordering)',
            "bodyPos={$bodyPos} disabledPos={$disabledPos}");

        is_true(strpos($block, 'try {') !== false && strpos($block, "} catch (e) {") !== false,
            'FIX: body construction is wrapped so a throw cannot escape silently');

        // The exact old broken shape (bare `ticket_id:   ticketId,` immediately
        // preceded by no local declaration) must be gone from THIS handler.
        // We check by confirming the literal old handler text (verbatim,
        // whitespace-sensitive) no longer appears anywhere in the file.
        is_true(strpos($jsSrc, "                            var assignId = parseInt(this.getAttribute('data-assign-id'), 10);\n                            var facId = parseInt(this.value, 10) || 0;\n                            var selfRef = this;\n                            selfRef.disabled = true;\n                            fetch('api/incident-assign.php'") === false,
            'the exact pre-fix handler shape is no longer present in the shipped file');
    }
}

echo "\n";
echo "==========================================================\n";
echo "GH#98 Dest dropdown ticketId tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

// ── Teardown ──
try {
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($ridA > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridA]);
    if ($ridB > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridB]);
    if ($fidA > 0) db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$fidA]);
    if ($fidB > 0) db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$fidB]);
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
