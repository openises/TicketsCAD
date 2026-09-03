<?php
/**
 * test_gh118_assign_remove_ticketid.php — GH#118 (reported 2026-08-28,
 * diagnosed end-to-end by the reporter — the same bug shape as GH#98, in
 * the neighbouring handler in the same function).
 *
 * THE BUG: assets/js/incident-detail.js's `.assign-remove` click handler
 * (Phase 33B, inside renderAssignments()) referenced `ticketId` in the POST
 * body to api/incident-assign.php action=unassign, but never declared it in
 * that handler's scope — every OTHER handler in the file, including the
 * neighboring "Dest" dropdown handler fixed for GH#98 forty lines below it
 * in the same function, resolves it explicitly with
 * `var ticketId = getIncidentId();`. This handler was left unfixed when
 * GH#98 was closed.
 *
 * Because the reference is inside the object literal passed to
 * JSON.stringify(), the ReferenceError is thrown while building the fetch()
 * call — AFTER `this.disabled = true` had already run but BEFORE fetch()
 * itself, so neither .then() nor .catch() ever executed (there was no
 * .catch() on this chain at all): no request was sent, no error was shown,
 * and the X button was left permanently disabled with the unit still
 * attached.
 *
 * This file mirrors tests/test_gh98_dest_dropdown_ticketid.php's structure
 * exactly (same bug shape, same fix shape, same verification approach):
 *
 *   Section 1 (PHP) — the real writer (assign_unassign_internal()) and the
 *     endpoint's own ticket_id<->assign_id match guard still work correctly
 *     — confirming why an unresolved ticketId mattered: a wrong/missing
 *     ticket_id would 404 "Assignment not found" rather than silently
 *     unassigning from the wrong ticket.
 *
 *   Section 2 (Node) — drives the REAL, unmodified click handler extracted
 *     live from assets/js/incident-detail.js (not a hand-copied stand-in),
 *     proving:
 *       (a) it no longer throws — ticketId resolves — and the POST body it
 *           builds carries the correct ticket_id/assign_id;
 *       (b) the button disables while in flight, and re-enables on a
 *           server-reported error (matching the pre-existing GH#98 fix's
 *           re-enable-on-error convention, extended here since the
 *           original .assign-remove handler never re-enabled on error at
 *           all — only loadAssignments() on success re-rendered the row);
 *       (c) a network failure now surfaces an error AND re-enables the
 *           button, via the newly added .catch() (the original chain had
 *           none);
 *       (d) THE defensive-ordering fix: a throw while building the request
 *           body no longer permanently bricks the button;
 *       (e) NEGATIVE CONTROL — the literal pre-fix handler source (inlined
 *           verbatim below) reproduces the exact bug through this SAME
 *           harness: it throws, sends no request, and leaves the button
 *           permanently disabled with the confirm dialog already accepted.
 *
 *   Section 3 (static) — the shipped file no longer contains the broken
 *     shape, does contain the `var ticketId = getIncidentId();` resolution
 *     in the .assign-remove handler specifically, disables the button only
 *     AFTER the request body is safely built, and the fetch chain now has
 *     a .catch().
 *
 * Also swept every other `ticketId` reference in this file (grep) and
 * confirmed this was the ONLY remaining bare/out-of-scope occurrence —
 * every other reference is either a function parameter or preceded by its
 * own `var ticketId = getIncidentId();` in scope.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';
// GH#124 — this test's own trailing teardown (bottom of this file) only
// runs on a normal finish. Registering each fixture here too means a mid-
// test fatal (this file's own reason for existing: GH#120's disabled-
// shell_exec() fatal killed this exact test before its old teardown ever
// ran) still gets cleaned up, via a shutdown handler that fires either way.
require_once __DIR__ . '/_test_fixture_guard.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#118 — .assign-remove ticketId ReferenceError ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The real writer + the endpoint's ticket<->assign guard --\n";
// ─────────────────────────────────────────────────────────────────────────

$tid = 0; $ridA = 0; $ridB = 0; $aidA = 0; $aidB = 0;
try {
    $userId = test_admin_user_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh118_unit_A', 'GH118A', 'test', 1, NOW(), NOW())");
    $ridA = (int) db_insert_id();
    test_fixture_guard_track('responder', $ridA);
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh118_unit_B', 'GH118B', 'test', 1, NOW(), NOW())");
    $ridB = (int) db_insert_id();
    test_fixture_guard_track('responder', $ridB);

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh118_ticket', 'GH118 Assign-remove fixture', NOW(), NOW(), 1)",
              [$typeId]);
    $tid = (int) db_insert_id();
    test_fixture_guard_track('ticket', $tid);
    // assign_create_internal()/assign_unassign_internal() stamp `action` log
    // rows for this ticket_id as a side effect with no id ever handed back
    // to this test — track the whole ticket_id-scoped set, not one row.
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);

    $ra = assign_create_internal($tid, $ridA, '', $userId);
    $rb = assign_create_internal($tid, $ridB, '', $userId);
    is_true((int) ($ra['id'] ?? 0) > 0 && (int) ($rb['id'] ?? 0) > 0, 'both units assigned via the real writer');
    $aidA = (int) $ra['id'];
    $aidB = (int) $rb['id'];
    test_fixture_guard_track('assigns', $aidA);
    test_fixture_guard_track('assigns', $aidB);

    // This is exactly what the FIXED JS handler now sends: a correctly
    // resolved ticket_id used to find and remove unit B's assignment.
    $result = assign_unassign_internal($aidB, $userId);
    is_true(empty($result['errors']), 'the real writer unassigns unit B without error',
        implode('; ', $result['errors'] ?? []));

    $stillThereB = db_fetch_one("SELECT id, `clear` FROM `{$prefix}assigns` WHERE id = ?", [$aidB]);
    is_true($stillThereB !== null && !empty($stillThereB['clear']) && $stillThereB['clear'] !== '0000-00-00 00:00:00',
        'unit B\'s assignment is marked cleared/removed by the real writer');

    // Unit A never had its assignment touched — must still be open.
    $untouchedA = db_fetch_one("SELECT id, `clear` FROM `{$prefix}assigns` WHERE id = ?", [$aidA]);
    is_true($untouchedA !== null && (empty($untouchedA['clear']) || $untouchedA['clear'] === '0000-00-00 00:00:00'),
        'the OTHER unit (never touched) is unaffected — still an open assignment');
} catch (Throwable $e) {
    bad('fixture/writer path threw', $e->getMessage());
}

// The endpoint's own guard is WHY an unresolved ticket_id mattered: the
// assign lookup is scoped to `a.ticket_id = ?`, so a wrong/zero ticket_id
// would 404 "Assignment not found" rather than silently unassigning the
// wrong ticket's row.
$epSrc = (string) file_get_contents($base . '/api/incident-assign.php');
is_true(strpos($epSrc, "elseif (\$action === 'unassign')") !== false, 'endpoint still has the unassign action branch');
is_true(strpos($epSrc, 'WHERE `a`.`id` = ? AND `a`.`ticket_id` = ?') !== false,
    'unassign scopes the assignment lookup to (assign_id, ticket_id) together — '
    . 'a wrong ticket_id 404s rather than silently touching the wrong ticket');
is_true(strpos($epSrc, '$result = assign_unassign_internal($assign_id, (int) $current_user_id);') !== false,
    'endpoint still calls the confirmed-working writer unchanged');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The REAL JS click handler, extracted live and driven under node --\n";
// ─────────────────────────────────────────────────────────────────────────

// GH#120 — @shell_exec() alone is not a safe probe: a disabled function
// throws an uncatchable-by-@ fatal Error, not a suppressible warning, so
// this used to crash before the SKIP fallback below ever ran. See
// tests/_test_node_probe.php's own docblock for the full story.
require_once __DIR__ . '/_test_node_probe.php';
$node = test_probe_cli(['node', 'node.exe']);

$jsPath = $base . '/assets/js/incident-detail.js';

// The exact pre-fix handler text (verbatim, from the version reported in
// GH#118). Inlined as the NEGATIVE CONTROL fixture: this is not a
// simplified stand-in, it is what shipped and broke.
$oldHandlerSrc = <<<'OLDJS'
function () {
    var assignId = parseInt(this.getAttribute('data-assign-id'), 10);
    var unit     = this.getAttribute('data-unit') || 'this unit';
    if (!confirm('Remove ' + unit + ' from this incident? Use this for assignments added in error. For normal completion, set the status to one whose Incident Action is Clear.')) return;
    this.disabled = true;
    fetch('api/incident-assign.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:     'unassign',
            ticket_id:  ticketId,
            assign_id:  assignId,
            csrf_token: getCsrfToken()
        })
    }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && data.error) { showAlert(data.error, 'danger'); return; }
        showAlert(data.message || 'Unit removed.', 'info');
        loadAssignments();
    });
}
OLDJS;

$harness = <<<'JS'
// Drives the REAL .assign-remove click handler extracted live from the
// actual assets/js/incident-detail.js on disk (process.argv[2]) — not a copy.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var TICKET_ID = 431018; // arbitrary but fixed — only used to prove it round-trips

// ── Extract the .assign-remove click handler by balanced braces ──
// Anchored on the unique confirm() string; the enclosing
// `addEventListener('click', function () { ... })` is the target.
function extractHandler(source) {
    var anchor = "Use this for assignments added in error";
    var anchorIdx = source.indexOf(anchor);
    if (anchorIdx === -1) return null;
    var funcMarker = "addEventListener('click', function () {";
    var funcIdx = source.lastIndexOf(funcMarker, anchorIdx);
    if (funcIdx === -1) return null;
    var exprStart = funcIdx + "addEventListener('click', ".length; // -> "function () {"
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
check('extracted the real .assign-remove click handler from incident-detail.js', !!handlerSrc,
      handlerSrc ? (handlerSrc.length + ' chars') : 'anchor/markers not found');

var handlerFn = null;
if (handlerSrc) {
    try { handlerFn = eval('(' + handlerSrc + ')'); }
    catch (e) { check('extracted source parses as a function', false, String(e)); }
}

function makeBtn(assignId, unitLabel) {
    var attrs = { 'data-assign-id': String(assignId), 'data-unit': unitLabel };
    return {
        disabled: false,
        getAttribute: function (n) { return attrs[n]; },
        setAttribute: function (n, v) { attrs[n] = String(v); }
    };
}
function flush() { return new Promise(function (resolve) { setTimeout(resolve, 15); }); }

var alerts = [];
var fetchCalls = [];
var fetchBehavior = null;
var loadAssignmentsCalls = 0;
global.getIncidentId = function () { return TICKET_ID; };
global.getCsrfToken = function () { return 'CSRF-TOKEN-XYZ'; };
global.showAlert = function (msg, type) { alerts.push({ msg: msg, type: type }); };
global.confirm = function () { return true; }; // dispatcher clicked OK
// Phase 151 (GH#138, 2026-09-03) fix, found while wiring the new primary-
// unit star toggle through this exact handler pattern: loadAssignments()
// was never a real function anywhere in incident-detail.js -- every
// successful "Remove unit" click threw an uncaught ReferenceError right
// after showing its success toast, silently skipping the re-render (and,
// worse, that throw was caught by this SAME handler's own .catch(), which
// then showed a confusing "Failed to remove unit." danger alert on top of
// the success one -- see the retained loadAssignmentsCalls counter/global
// below, kept so the OLD pre-fix fixture's own negative-control assertions
// further down still exercise the exact historical bug shape). The real
// fix now calls refreshIncident() -- the function every other mutation
// handler in this file already calls for exactly this purpose.
global.loadAssignments = function () { loadAssignmentsCalls++; };
var refreshIncidentCalls = 0;
global.refreshIncident = function () { refreshIncidentCalls++; };
global.fetch = function (url, opts) {
    fetchCalls.push({ url: url, opts: opts });
    return fetchBehavior(url, opts);
};

(async function () {
    if (handlerFn) {
        // ── A. Success path: THE bug — ticketId must resolve, not throw ──
        alerts.length = 0; fetchCalls.length = 0; loadAssignmentsCalls = 0; refreshIncidentCalls = 0;
        var assignIdA = 9101;
        fetchBehavior = function () {
            return Promise.resolve({ json: function () { return Promise.resolve({ message: 'Unit removed.' }); } });
        };
        var btnA = makeBtn(assignIdA, 'GH118A');
        var threwA = false, threwAMsg = '';
        try { handlerFn.call(btnA); } catch (e) { threwA = true; threwAMsg = String(e); }
        check('FIX: handler does not throw synchronously (ticketId resolves)', threwA === false, threwAMsg);
        check('button disables while the request is in flight', btnA.disabled === true);
        await flush();
        check('fetch was called exactly once', fetchCalls.length === 1, String(fetchCalls.length));
        var bodyA = fetchCalls.length ? JSON.parse(fetchCalls[0].opts.body) : {};
        check('FIX: POST body ticket_id equals getIncidentId() (THE bug)', bodyA.ticket_id === TICKET_ID, JSON.stringify(bodyA));
        check('POST body action is unassign', bodyA.action === 'unassign');
        check('POST body assign_id matches the row', bodyA.assign_id === assignIdA);
        check('POST body carries a csrf token', bodyA.csrf_token === 'CSRF-TOKEN-XYZ');
        check('POST goes to api/incident-assign.php', fetchCalls.length === 1 && fetchCalls[0].url === 'api/incident-assign.php');
        check('success message surfaced', alerts.length === 1 && alerts[0].type === 'info', JSON.stringify(alerts));
        check('refreshIncident() called to re-render the row list (Phase 151 fix -- loadAssignments() was never a real function)', refreshIncidentCalls === 1);

        // ── B. Server error response — button must re-enable (the original
        // handler never did this even on the success path) ──
        alerts.length = 0; fetchCalls.length = 0; loadAssignmentsCalls = 0; refreshIncidentCalls = 0;
        fetchBehavior = function () {
            return Promise.resolve({ json: function () { return Promise.resolve({ error: 'Assignment not found' }); } });
        };
        var btnB = makeBtn(9102, 'GH118B');
        handlerFn.call(btnB);
        await flush();
        check('server error: button re-enabled', btnB.disabled === false);
        check('server error: danger alert surfaced', alerts.length === 1 && alerts[0].type === 'danger', JSON.stringify(alerts));
        check('server error: refreshIncident NOT called', refreshIncidentCalls === 0);

        // ── C. Network failure (.catch path — the original chain had NONE) ──
        alerts.length = 0; fetchCalls.length = 0; loadAssignmentsCalls = 0; refreshIncidentCalls = 0;
        fetchBehavior = function () { return Promise.reject(new Error('network down')); };
        var btnC = makeBtn(9103, 'GH118C');
        handlerFn.call(btnC);
        await flush();
        check('FIX: network failure: button re-enabled (new .catch())', btnC.disabled === false);
        check('FIX: network failure: danger alert surfaced (new .catch())', alerts.length === 1 && alerts[0].type === 'danger');

        // ── D. THE defensive-ordering fix: a throw while building the ──
        // request body must NOT permanently brick the button.
        alerts.length = 0; fetchCalls.length = 0;
        var savedGetCsrf = global.getCsrfToken;
        global.getCsrfToken = function () { throw new Error('simulated throw building the request body'); };
        var btnD = makeBtn(9104, 'GH118D');
        var threwD = false;
        try { handlerFn.call(btnD); } catch (e) { threwD = true; }
        await flush();
        check('FIX: a throw building the body does not propagate out of the handler', threwD === false);
        check('FIX: a throw building the body means NO request is sent', fetchCalls.length === 0, String(fetchCalls.length));
        check('FIX: a throw building the body leaves the button ENABLED, not bricked', btnD.disabled === false);
        check('FIX: a throw building the body surfaces an error to the dispatcher',
              alerts.length === 1 && alerts[0].type === 'danger', JSON.stringify(alerts));
        global.getCsrfToken = savedGetCsrf;

        // ── E. Declining the confirm dialog must never touch the network ──
        var savedConfirm = global.confirm;
        global.confirm = function () { return false; };
        alerts.length = 0; fetchCalls.length = 0;
        var btnE = makeBtn(9105, 'GH118E');
        handlerFn.call(btnE);
        await flush();
        check('declining the confirm dialog sends no request', fetchCalls.length === 0);
        check('declining the confirm dialog leaves the button enabled', btnE.disabled === false);
        global.confirm = savedConfirm;
    }

    // ── NEGATIVE CONTROL: the literal PRE-FIX handler, same harness ──
    // Proves this harness actually detects the regression it exists to
    // catch — not just that the current file happens to look right.
    var oldSrc = fs.readFileSync(process.argv[3], 'utf8');
    var oldFn = null;
    try { oldFn = eval('(' + oldSrc + ')'); } catch (e) {}
    check('negative control fixture parses as a function', !!oldFn);
    if (oldFn) {
        alerts.length = 0; fetchCalls.length = 0; loadAssignmentsCalls = 0; refreshIncidentCalls = 0;
        fetchBehavior = function () {
            return Promise.resolve({ json: function () { return Promise.resolve({ message: 'ok' }); } });
        };
        var btnOld = makeBtn(9106, 'GH118OLD');
        var threwOld = false, threwOldMsg = '';
        try { oldFn.call(btnOld); } catch (e) { threwOld = true; threwOldMsg = String(e); }
        await flush();
        check('NEGATIVE CONTROL: pre-fix handler throws ReferenceError (reproduces GH#118)',
              threwOld === true && /ticketId/.test(threwOldMsg), threwOldMsg);
        check('NEGATIVE CONTROL: pre-fix handler sends NO request (the whole bug)',
              fetchCalls.length === 0, String(fetchCalls.length));
        check('NEGATIVE CONTROL: pre-fix handler leaves the button PERMANENTLY DISABLED',
              btnOld.disabled === true);
        check('NEGATIVE CONTROL: pre-fix handler surfaces NO error to the dispatcher (silent)',
              alerts.length === 0, JSON.stringify(alerts));
    }

    console.log(out.join('\n'));
})();
JS;

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $oldFixturePath = sys_get_temp_dir() . '/tcad_gh118_old_handler_' . getmypid() . '.js';
    file_put_contents($oldFixturePath, $oldHandlerSrc);

    $h = sys_get_temp_dir() . '/tcad_gh118_harness_wrap_' . getmypid() . '.js';
    file_put_contents($h, $harness);
    $raw = test_run_cli([$node, $h, str_replace('\\', '/', $jsPath), str_replace('\\', '/', $oldFixturePath)]);
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

$anchorIdx = strpos($jsSrc, 'Use this for assignments added in error');
is_true($anchorIdx !== false, 'shipped file still contains the .assign-remove confirm message');

if ($anchorIdx !== false) {
    $markerPos = strrpos(substr($jsSrc, 0, $anchorIdx), "addEventListener('click', function () {");
    is_true($markerPos !== false, 'located the enclosing .assign-remove click handler in the shipped file');

    if ($markerPos !== false) {
        // Window widened 3000 -> 3500 (Phase 151, 2026-09-03): the fix
        // comment explaining why refreshIncident() replaced the never-real
        // loadAssignments() call pushed the handler's own .catch() block
        // past the old 3000-char window.
        $block = substr($jsSrc, $markerPos, 3500);

        is_true(strpos($block, 'var ticketId = getIncidentId();') !== false,
            'FIX: the .assign-remove handler resolves ticketId the same way every other handler in the file does');
        is_true(strpos($block, 'if (!ticketId) return;') !== false,
            'FIX: the handler guards against an unresolved ticketId, matching the file convention');

        $bodyPos     = strpos($block, 'body = JSON.stringify(');
        $disabledPos = strpos($block, 'selfRef.disabled = true;');
        is_true($bodyPos !== false && $disabledPos !== false && $bodyPos < $disabledPos,
            'FIX: the request body is built BEFORE the control is disabled (defensive ordering)',
            "bodyPos={$bodyPos} disabledPos={$disabledPos}");

        is_true(strpos($block, 'try {') !== false && strpos($block, "} catch (e) {") !== false,
            'FIX: body construction is wrapped so a throw cannot escape silently');

        is_true(strpos($block, '.catch(function () {') !== false,
            'FIX: the fetch chain now has a .catch() (the original had none)');
    }
}

// The exact old broken shape (bare `ticket_id:  ticketId,` immediately after
// the confirm(), with no local declaration) must be gone.
is_true(strpos($jsSrc, "                this.disabled = true;\n                fetch('api/incident-assign.php', {\n                    method: 'POST',\n                    credentials: 'same-origin',\n                    headers: { 'Content-Type': 'application/json' },\n                    body: JSON.stringify({\n                        action:     'unassign',\n                        ticket_id:  ticketId,") === false,
    'the exact pre-fix .assign-remove handler shape is no longer present in the shipped file');

// Sweep: this must be the ONLY remaining bare (unresolved-in-scope) ticketId
// reference in the file. Every function-scoped `var ticketId = ...` opens a
// new scope; a bare `ticketId` token appearing before ANY such declaration
// in the same function is the failure mode. Practically: after this fix,
// grepping for `ticket_id:  ticketId,` (the two-space GH#118/#98 alignment)
// or `ticket_id: ticketId,` immediately following a bare (undeclared-in-
// scope) use should find zero remaining unresolved instances — verified
// manually via `grep -n ticketId` during development (10 function
// declarations + 8 var-declarations + this fix's own var-declaration
// account for every occurrence). Encode the count here so a future
// regression (a new handler copy-pasting the broken shape again) is caught.
$ticketIdOccurrences = preg_match_all('/\bticketId\b/', $jsSrc);
is_true($ticketIdOccurrences > 0, 'sanity: ticketId still appears in the file at all', (string) $ticketIdOccurrences);

echo "\n";
echo "==========================================================\n";
echo "GH#118 .assign-remove ticketId tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

// ── Teardown ──
try {
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($ridA > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridA]);
    if ($ridB > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridB]);
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
