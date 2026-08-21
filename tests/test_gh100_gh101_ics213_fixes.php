<?php
/**
 * test_gh100_gh101_ics213_fixes.php — GH#100 + GH#101 (both reported
 * 2026-08-2x against the ICS-213 / Winlink export feature; independent
 * root causes, fixed in one pass).
 *
 * GH#100 — api/winlink-export.php's incident query selected
 *   `it.name AS incident_type_name`, but `in_types` has never had a `name`
 *   column (it's `type`). Every export threw SQLSTATE[42S22], and the
 *   surrounding `catch (Exception $e) { $incident = null; }` silently
 *   turned that into the SAME blank-form fallback a genuinely missing
 *   incident produces — so every real export came back blank, for every
 *   ticket, indistinguishable from "not found". A second, independent bug
 *   the JOIN failure was masking: generateICS213()'s Status line read
 *   `$incident['curstat']`, but `ticket` has never had a `curstat` column
 *   either (it's `status`). Fixed: the SELECT now reads
 *   `` it.`type` AS incident_type_name ``, the Status line now prefers a
 *   computed `status_text` label (matching api/incident-detail.php's own
 *   $status_labels convention) falling back to the raw `status` int, the
 *   Severity line now renders through severity_label() instead of a bare
 *   integer, and the catch block now error_log()s so a future query error
 *   is distinguishable from "no such incident" (inc/responder-write.php's
 *   bed_auto catch is the reference log-and-swallow pattern).
 *
 * GH#101 — assets/js/ics-forms.js's loadIncidentData() fetched
 *   api/incident-detail.php and passed the WHOLE response envelope
 *   ({incident, assignments, actions}) into autoPopulateFromIncident()
 *   and into the module's `incidentData` variable — every field read
 *   (inc.id, inc.scope, inc.street, inc.type_name, ...) actually lives
 *   under resp.incident, so every field read as undefined (hence the
 *   reported "Incident #undefined" title). setFieldIfEmpty() silently
 *   skips falsy values, so nothing errored and nothing populated. Two
 *   more, independent field-name bugs were in the same function:
 *   inc.incident_type_name (api actually returns type_name) and inc.date
 *   (api never returns a bare `date` key; the real field is
 *   problemstart). Fixed: unwrap to `resp.incident` (falling back to
 *   `resp` itself so a future flattening of the endpoint degrades rather
 *   than breaking again), and both field names corrected. Per the fix
 *   instructions, autoPopulateFromIncident()'s field COVERAGE was
 *   deliberately NOT expanded beyond fixing the bug (the reporter
 *   explicitly flagged the endpoint returns 49 fields and only 6 are
 *   read, and called broadening that a separate scope decision).
 *
 * This file proves four independent things:
 *
 *   Section 1 (PHP) — the exact pre-fix `it.name` query really throws
 *     SQLSTATE[42S22] against a real in_types table (not a typo theory),
 *     the fixed query resolves the real `type` value, and `ticket` really
 *     has `status` and never `curstat`.
 *
 *   Section 2 (PHP, end-to-end) — drives the REAL, unmodified
 *     api/winlink-export.php endpoint (via CLI subprocess, same
 *     discipline as tests/_gh96_mileage_report_probe.php) against a real
 *     fixture incident, and confirms the exported XML actually carries
 *     the incident's real type/location/severity-label/status-label/
 *     description — not the blank-form fallback every export produced
 *     before the fix. Also confirms the ticket_id-omitted blank-form path
 *     still degrades gracefully (never broke; must not regress).
 *
 *   Section 3 (JS, Node) — drives the REAL, unmodified
 *     loadIncidentData()/autoPopulateFromIncident() functions extracted
 *     live from assets/js/ics-forms.js (not hand-copied stand-ins) against
 *     the REAL api/incident-detail.php response for the SAME fixture
 *     incident (captured live via tests/_incident_detail_can_manage_sharing_probe.php,
 *     not a synthetic mock of what the endpoint is assumed to return),
 *     proving the fields populate correctly — and a NEGATIVE CONTROL
 *     (the literal pre-fix `incidentData = resp; autoPopulateFromIncident(resp);`
 *     shape) reproduces "Incident #undefined" through the same harness,
 *     proving the harness would have caught the original defect.
 *
 *   Section 4 (static) — the shipped files no longer contain the broken
 *     shapes and do contain the fixed ones.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/severity.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#100 + GH#101 — ICS-213 blank-form / undefined-field fixes ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- Fixture: a real incident type + ticket (direct INSERT — the code\n";
echo "   under test here is the export endpoint / the JS auto-populate\n";
echo "   function, not the ticket writer; tests/test_gh98_dest_dropdown_ticketid.php\n";
echo "   sets the same precedent for fixture creation) --\n";
// ─────────────────────────────────────────────────────────────────────────

$typeId = 0; $tid = 0; $userId = 0;
try {
    $userId = test_admin_user_id();

    db_query("INSERT INTO {$prefix}in_types (type, description, protocol, set_severity, watch, `group`, sort)
              VALUES ('GH100Type', 'GH100/101 fixture type', '', 0, 0, 'Test', 999)");
    $typeId = (int) db_insert_id();

    // A non-zero, non-default severity so "raw integer" vs "label" is
    // actually distinguishable in the exported/rendered output.
    $sevValues = severity_valid_values();
    $sevValue  = $sevValues ? max($sevValues) : 1;
    if ($sevValue === 0 && count($sevValues) > 1) $sevValue = $sevValues[1];
    $sevLabel  = severity_label($sevValue);

    db_query("INSERT INTO {$prefix}ticket
                (in_types_id, status, severity, scope, description, street, city, date, problemstart, _by)
              VALUES (?, 2, ?, 'GH100 fixture case', 'GH100/101 fixture incident description', '123 Test St', 'Testville', NOW(), NOW(), ?)",
              [$typeId, $sevValue, $userId]);
    $tid = (int) db_insert_id();

    is_true($typeId > 0 && $tid > 0, 'fixture incident type + ticket created', "typeId=$typeId tid=$tid");
} catch (Throwable $e) {
    bad('fixture setup', $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. GH#100 root cause, verified directly against the real schema --\n";
// ─────────────────────────────────────────────────────────────────────────

if ($tid > 0) {
    // NEGATIVE CONTROL: the exact pre-fix query text really throws.
    $threw = false; $threwMsg = '';
    try {
        db_fetch_one(
            "SELECT t.*, it.name AS incident_type_name
             FROM {$prefix}ticket t
             LEFT JOIN {$prefix}in_types it ON t.in_types_id = it.id
             WHERE t.id = ?",
            [$tid]
        );
    } catch (Throwable $e) {
        $threw = true; $threwMsg = $e->getMessage();
    }
    is_true($threw && (stripos($threwMsg, '42S22') !== false || stripos($threwMsg, "'name'") !== false || stripos($threwMsg, 'name') !== false),
        'NEGATIVE CONTROL: the pre-fix `it.name` column really does not exist on a real in_types table',
        $threwMsg);

    // The fixed query, run for real, resolves the real `type` value.
    $fixedRow = null; $fixedErr = '';
    try {
        $fixedRow = db_fetch_one(
            "SELECT t.*, it.`type` AS incident_type_name
             FROM {$prefix}ticket t
             LEFT JOIN {$prefix}in_types it ON t.in_types_id = it.id
             WHERE t.id = ?",
            [$tid]
        );
    } catch (Throwable $e) {
        $fixedErr = $e->getMessage();
    }
    is_true($fixedRow !== null && $fixedRow['incident_type_name'] === 'GH100Type',
        'FIX: it.`type` AS incident_type_name resolves the real type label',
        $fixedRow['incident_type_name'] ?? $fixedErr);

    // `ticket` really has `status`, never `curstat` — root cause of bug 2.
    is_true($fixedRow !== null && array_key_exists('status', $fixedRow) && !array_key_exists('curstat', $fixedRow),
        'FIX target confirmed: ticket row has `status`, never had `curstat`');
} else {
    bad('root-cause SQL checks skipped — no fixture ticket');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. GH#100: the REAL api/winlink-export.php endpoint, end-to-end --\n";
// ─────────────────────────────────────────────────────────────────────────

$phpBin = PHP_BINARY ?: 'php';
$probe  = $base . '/tests/_gh100_winlink_export_probe.php';

function gh100_run_probe(string $phpBin, string $probe, string $qs, int $userId): string {
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($probe) . ' '
         . escapeshellarg($qs) . ' ' . escapeshellarg((string) $userId) . ' 2>&1';
    return (string) @shell_exec($cmd);
}

if ($tid > 0) {
    $xml = gh100_run_probe($phpBin, $probe, "form=ics213&ticket_id={$tid}", $userId);

    is_true(strpos($xml, '<?xml') === 0, 'endpoint returned XML (not a JSON error / blank stdout)',
        substr($xml, 0, 200));

    $sx = @simplexml_load_string($xml);
    is_true($sx !== false, 'exported XML parses as well-formed XML', substr($xml, 0, 300));

    if ($sx !== false) {
        $subject = (string) $sx->variables->subject;
        $message = (string) $sx->variables->message;
        $incName = (string) $sx->variables->incidentname;

        // THE bug: before the fix, EVERY export rendered the blank-form
        // fallback (subject "Incident", empty message) for every ticket,
        // real or not.
        is_true(strpos($subject, 'GH100Type') !== false,
            'FIX: exported <subject> carries the real incident type (was always blank)', $subject);
        is_true(strpos($subject, 'GH100 fixture case') !== false,
            'FIX: exported <subject> carries the real case name', $subject);
        is_true($incName === 'GH100 fixture case', 'FIX: <incidentname> is the real scope', $incName);

        is_true(strpos($message, 'Type: GH100Type') !== false,
            'FIX: message body carries "Type: <real type>"', $message);
        is_true(strpos($message, 'Location: 123 Test St, Testville') !== false,
            'FIX: message body carries the real street/city', $message);
        is_true(strpos($message, 'Severity: ' . $sevLabel) !== false,
            'FIX (nice-to-have): message body renders severity_label(), not a bare integer', $message);
        is_true(strpos($message, 'Severity: ' . $sevValue . "\n") === false,
            'severity is NOT rendered as the raw integer', $message);
        is_true(strpos($message, 'Status: Open') !== false,
            'FIX: message body carries "Status: Open" (was always blank — curstat never existed)', $message);
        is_true(strpos($message, 'Description: GH100/101 fixture incident description') !== false,
            'FIX: message body carries the real description', $message);
    }

    // The genuinely-no-ticket-id blank-form path must still degrade
    // gracefully (this always worked; must not regress).
    $blankXml = gh100_run_probe($phpBin, $probe, 'form=ics213', $userId);
    $sxBlank  = @simplexml_load_string($blankXml);
    is_true($sxBlank !== false, 'blank-form (no ticket_id) export still parses as well-formed XML');
    if ($sxBlank !== false) {
        is_true((string) $sxBlank->variables->subject === '',
            'blank-form export has an empty subject, as before (no regression)');
    }
} else {
    bad('endpoint end-to-end checks skipped — no fixture ticket');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. GH#101: the REAL JS functions, driven under node against the\n";
echo "   REAL api/incident-detail.php response for the SAME fixture --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probeVer = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probeVer) && preg_match('/^v\d+/', trim($probeVer))) { $node = $cand; break; }
}

if ($tid === 0) {
    bad('GH#101 JS checks skipped — no fixture ticket');
} else {
    // Capture the REAL envelope api/incident-detail.php returns for this
    // exact fixture ticket — not a synthetic mock of what we assume it
    // returns. Same probe tests/test_org_sharing_manual_ui_wiring.php uses.
    $detailProbe = $base . '/tests/_incident_detail_can_manage_sharing_probe.php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($detailProbe) . ' '
         . escapeshellarg((string) $userId) . ' ' . escapeshellarg((string) $tid) . ' 2>&1';
    $rawEnvelope = (string) @shell_exec($cmd);
    $envelope = json_decode($rawEnvelope, true);

    is_true(is_array($envelope) && isset($envelope['incident']) && is_array($envelope['incident']),
        'captured the REAL api/incident-detail.php envelope for the fixture ticket',
        substr($rawEnvelope, 0, 300));

    if (is_array($envelope) && isset($envelope['incident'])) {
        $inc = $envelope['incident'];

        // Root cause of the two field-name bugs, proven against the REAL
        // captured shape (not assumed from memory).
        is_true(!array_key_exists('incident_type_name', $inc) && array_key_exists('type_name', $inc),
            'ROOT CAUSE confirmed: the real envelope has type_name, never incident_type_name');
        is_true(!array_key_exists('date', $inc) && array_key_exists('problemstart', $inc),
            'ROOT CAUSE confirmed: the real envelope has problemstart, never a bare date key');
        is_true($inc['type_name'] === 'GH100Type', 'captured envelope carries the real type_name', (string) ($inc['type_name'] ?? ''));

        if ($node === null) {
            echo "SKIP: node not available — the JS execution checks were not run\n";
        } else {
            $envelopePath = sys_get_temp_dir() . '/tcad_gh101_envelope_' . getmypid() . '.json';
            file_put_contents($envelopePath, json_encode($envelope));

            $jsPath = str_replace('\\', '/', $base . '/assets/js/ics-forms.js');

            $harness = <<<'JS'
// Drives the REAL loadIncidentData()/autoPopulateFromIncident() functions
// extracted live from the actual assets/js/ics-forms.js on disk
// (process.argv[2]), against the REAL api/incident-detail.php envelope
// captured live for the fixture ticket (process.argv[3]) — not hand-copied
// stand-ins, not a synthetic mock.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail === undefined ? '' : String(detail))); }

function extractNamedFunction(source, name) {
    var marker = 'function ' + name + '(';
    var idx = source.indexOf(marker);
    if (idx === -1) return null;
    var braceStart = source.indexOf('{', idx);
    if (braceStart === -1) return null;
    var depth = 0, i = braceStart;
    for (; i < source.length; i++) {
        var c = source[i];
        if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) { i++; break; } }
    }
    if (depth !== 0) return null;
    return source.slice(idx, i);
}

var srcPath = process.argv[2];
var envelopePath = process.argv[3];
var src = fs.readFileSync(srcPath, 'utf8');
var envelope = JSON.parse(fs.readFileSync(envelopePath, 'utf8'));

var loadSrc = extractNamedFunction(src, 'loadIncidentData');
var popSrc  = extractNamedFunction(src, 'autoPopulateFromIncident');
check('extracted the real loadIncidentData() from ics-forms.js', !!loadSrc, loadSrc ? loadSrc.length + ' chars' : 'not found');
check('extracted the real autoPopulateFromIncident() from ics-forms.js', !!popSrc, popSrc ? popSrc.length + ' chars' : 'not found');

var loadFn = null, popFn = null;
try { loadFn = eval('(' + loadSrc + ')'); } catch (e) { check('loadIncidentData parses', false, String(e)); }
try { popFn  = eval('(' + popSrc  + ')'); } catch (e) { check('autoPopulateFromIncident parses', false, String(e)); }

function flush() { return new Promise(function (resolve) { setTimeout(resolve, 15); }); }

function freshFieldStubs() {
    var fields = {};
    global.setFieldIfEmpty = function (key, value) { fields[key] = value; };
    return fields;
}
function freshTitleEl() {
    var el = { value: '' };
    global.document = { getElementById: function (id) { return id === 'formTitle' ? el : null; } };
    return el;
}

(async function () {
    if (loadFn && popFn) {
        // ── A. FIX: real loadIncidentData + real autoPopulateFromIncident,
        //    fed the REAL captured envelope ──
        global.autoPopulateFromIncident = popFn;
        global.currentTemplate = { form_type: '213' };
        var fields = freshFieldStubs();
        var titleEl = freshTitleEl();
        var alerts = [];
        global.showAlert = function (type, msg) { alerts.push({ type: type, msg: msg }); };
        global.incidentData = null;
        global.fetch = function () {
            return Promise.resolve({ json: function () { return Promise.resolve(envelope); } });
        };

        var threw = false, threwMsg = '';
        try { loadFn(431011); } catch (e) { threw = true; threwMsg = String(e); }
        check('FIX: loadIncidentData does not throw synchronously', threw === false, threwMsg);
        await flush();

        check('FIX: incidentData is unwrapped to the flat incident (not the envelope)',
              global.incidentData && global.incidentData.type_name === 'GH100Type',
              JSON.stringify(global.incidentData));
        check('FIX: title populated from the real scope (was "Incident #undefined")',
              titleEl.value === 'GH100 fixture case', titleEl.value);
        check('FIX: incident_name field populated', fields.incident_name === 'GH100 fixture case', fields.incident_name);
        check('FIX: subject uses the real type_name', fields.subject === 'GH100Type - GH100 fixture case', fields.subject);
        check('FIX: message body carries the real type', /Type: GH100Type/.test(fields.message || ''), fields.message);
        check('FIX: message body carries the real location',
              /Location: 123 Test St, Testville/.test(fields.message || ''), fields.message);
        check('FIX: message body carries the real description',
              /Description: GH100\/101 fixture incident description/.test(fields.message || ''), fields.message);
        check('FIX: op_period_from populated from problemstart (not the nonexistent date key)',
              typeof fields.op_period_from === 'string' && fields.op_period_from.length > 0, fields.op_period_from);
        check('success alert fired (fields actually populated, not silently empty)', alerts.length === 1 && alerts[0].type === 'info');

        // ── B. NEGATIVE CONTROL: the literal pre-fix loadIncidentData shape
        //    (`incidentData = resp; autoPopulateFromIncident(resp);`), same
        //    envelope, same (now-fixed) autoPopulateFromIncident. Proves the
        //    harness would have caught the original defect. ──
        var oldLoad = function (ticketId) {
            if (!ticketId) return;
            fetch('api/incident-detail.php?id=' + ticketId)
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.error) { showAlert('warning', 'x'); return; }
                    incidentData = resp;
                    autoPopulateFromIncident(resp);
                    showAlert('info', 'ok');
                });
        };
        var fieldsOld = freshFieldStubs();
        var titleElOld = freshTitleEl();
        global.incidentData = null;
        oldLoad(431011);
        await flush();
        check('NEGATIVE CONTROL: pre-fix shape reproduces "Incident #undefined" (envelope never unwrapped)',
              titleElOld.value === 'Incident #undefined', titleElOld.value);
        check('NEGATIVE CONTROL: pre-fix shape also corrupts incident_name with the same fallback',
              fieldsOld.incident_name === 'Incident #undefined', JSON.stringify(fieldsOld));
        check('NEGATIVE CONTROL: pre-fix shape leaves subject as the bare "Incident - " fallback',
              fieldsOld.subject === 'Incident - ', fieldsOld.subject);
    }

    console.log(out.join('\n'));
})();
JS;

            $h = sys_get_temp_dir() . '/tcad_gh101_harness_' . getmypid() . '.js';
            file_put_contents($h, $harness);
            $rawOut = @shell_exec($node . ' ' . escapeshellarg($h) . ' '
                . escapeshellarg($jsPath) . ' ' . escapeshellarg($envelopePath) . ' 2>&1');
            @unlink($h);
            @unlink($envelopePath);

            $results = [];
            if (is_string($rawOut)) {
                foreach (explode("\n", trim($rawOut)) as $line) {
                    $parts = explode('|', trim($line), 3);
                    if (count($parts) < 2) continue;
                    if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
                    $results[] = ['name' => $parts[1], 'ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
                }
            }
            if (!$results) {
                bad('node harness ran ics-forms.js', 'no parseable output: ' . substr((string) $rawOut, 0, 2000));
            } else {
                foreach ($results as $r) {
                    $r['ok'] ? ok('[js] ' . $r['name']) : bad('[js] ' . $r['name'], $r['detail']);
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Static: shipped files no longer contain the broken shapes --\n";
// ─────────────────────────────────────────────────────────────────────────

$winlinkSrc = (string) file_get_contents($base . '/api/winlink-export.php');
is_true(strpos($winlinkSrc, 'it.name AS incident_type_name') === false,
    'winlink-export.php no longer selects it.name');
is_true(strpos($winlinkSrc, 'it.`type` AS incident_type_name') !== false,
    'winlink-export.php selects it.`type` AS incident_type_name');
is_true(strpos($winlinkSrc, "\$incident['curstat']") === false,
    'winlink-export.php no longer reads $incident[\'curstat\']');
is_true(strpos($winlinkSrc, "status_text") !== false,
    'winlink-export.php reads a status_text label');
is_true(strpos($winlinkSrc, 'severity_label(') !== false,
    'winlink-export.php renders severity through severity_label()');
// The catch block around the incident query must log, not swallow silently
// (inc/responder-write.php's bed_auto catch is the reference pattern). Find
// the SELECT's own catch (the incident-query one, identified by the
// $incident = null; assignment inside it — the file has a second, unrelated
// catch around the settings lookup a few lines later that must NOT be
// required to log).
$incQueryPos = strpos($winlinkSrc, '$incident = db_fetch_one(');
$catchPos    = $incQueryPos !== false ? strpos($winlinkSrc, 'catch (Exception $e) {', $incQueryPos) : false;
$closeBrace  = $catchPos !== false ? strpos($winlinkSrc, "\n}", $catchPos) : false; // end of the if($ticketId){...} block
$catchBlock  = ($catchPos !== false && $closeBrace !== false) ? substr($winlinkSrc, $catchPos, $closeBrace - $catchPos) : '';
is_true($catchBlock !== '' && strpos($catchBlock, 'error_log(') !== false && strpos($catchBlock, '$incident = null;') !== false,
    'the incident-query catch block logs the exception (was a bare silent swallow)',
    substr($catchBlock, 0, 120));

$jsSrc = (string) file_get_contents($base . '/assets/js/ics-forms.js');
is_true(strpos($jsSrc, 'incidentData = resp;') === false || strpos($jsSrc, 'var inc = (resp && resp.incident)') !== false,
    'ics-forms.js no longer assigns incidentData straight from the raw envelope without unwrapping');
is_true(strpos($jsSrc, 'resp.incident') !== false,
    'ics-forms.js unwraps resp.incident');

// Scope the field-name checks to autoPopulateFromIncident() itself — the
// file has an UNRELATED, out-of-scope `inc.date` read inside a search-
// results renderer (a different `inc` loop variable entirely) that this fix
// must not touch (per the fix instructions: do not expand the field set
// beyond fixing this bug).
$fnStart = strpos($jsSrc, 'function autoPopulateFromIncident(');
$fnEnd   = $fnStart !== false ? strpos($jsSrc, "\n    function setFieldIfEmpty", $fnStart) : false;
$fnBody  = ($fnStart !== false && $fnEnd !== false) ? substr($jsSrc, $fnStart, $fnEnd - $fnStart) : '';
is_true($fnBody !== '', 'located autoPopulateFromIncident() in the shipped file');
is_true(strpos($fnBody, 'inc.incident_type_name') === false,
    'autoPopulateFromIncident() no longer reads inc.incident_type_name');
is_true(strpos($fnBody, 'inc.type_name') !== false,
    'autoPopulateFromIncident() reads inc.type_name');
is_true(preg_match('/\binc\.date\b/', $fnBody) !== 1,
    'autoPopulateFromIncident() no longer reads a bare inc.date');
is_true(strpos($fnBody, 'inc.problemstart') !== false,
    'autoPopulateFromIncident() reads inc.problemstart');

echo "\n";
echo "==========================================================\n";
echo "GH#100 + GH#101 ICS-213 tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

// ── Teardown ──
try {
    if ($tid > 0)    db_query("DELETE FROM {$prefix}action WHERE ticket_id = ?", [$tid]);
    if ($tid > 0)    db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$tid]);
    if ($typeId > 0) db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$typeId]);
} catch (Throwable $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
