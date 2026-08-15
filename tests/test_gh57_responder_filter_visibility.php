<?php
/**
 * GH#57 follow-up (cbyrdmo, 2026-08-14) — "select summary > select run
 * report > click incidents > it does not reset the prompt to include
 * responder dropdown. Facility log, notes log has the same error. Unit
 * log gives you the responder dropdown but if you select incident it
 * reverts back to responder dropdown missing. You have to click reports
 * from the main menu to get it to reappear."
 *
 * Two distinct defects, both in assets/js/reports.js's selectReportType():
 *
 *   1. showResponder's whitelist (`type === 'unit_log' || type ===
 *      'dispatch_log'`) never included 'facility_log' or 'notes_log', even
 *      though api/reports.php's notes_log case already accepts and applies
 *      a responder_id filter — the dropdown was simply never shown for
 *      those two report types, on ANY tab visit, not just after switching.
 *      Confirmed by reading api/reports.php directly rather than trusting
 *      the JS whitelist's own shape.
 *   2. Once a responder is picked on Unit Log, switching to a tab where the
 *      field is hidden never cleared responderFilter.value — the exact same
 *      bug class GH#57's FIRST fix (see test_gh57_reports_tab_state_reset.php)
 *      already fixed for incidentFilter, just never back-ported to this
 *      sibling field. runReport() reads responderFilter.value
 *      unconditionally, so a stale pick silently rode along on every
 *      later report request until a full page reload.
 *
 * A THIRD, companion defect found while fixing #1: api/reports.php's
 * facility_log case builds $where_parts without ever checking $responder_id
 * (unlike unit_log/dispatch_log, which both already do) — so making the
 * dropdown visible for Facility Log without also fixing this would have
 * shipped a control that appears but silently filters nothing.
 */

$root = dirname(__DIR__);
$src  = (string) file_get_contents($root . '/assets/js/reports.js');

$pass = 0; $fail = 0;
function test57rv(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// Isolate selectReportType()'s own body, same technique as
// test_gh57_reports_tab_state_reset.php, so assertions can't accidentally
// match an unrelated line elsewhere in this 600+ line file.
if (preg_match('/function selectReportType\([^)]*\)\s*\{(.*?)\r?\n    \}\r?\n/s', $src, $m) !== 1) {
    echo "[FAIL] could not isolate selectReportType() from assets/js/reports.js — file structure changed?\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$fn = $m[1];
$pass++; echo "[PASS] isolated selectReportType()'s function body\n";

// ── Defect 1: facility_log and notes_log must be in the whitelist ──
test57rv("showResponder's whitelist includes 'facility_log'",
    (bool) preg_match('/showResponder\s*=\s*\([^)]*type === .facility_log./', $fn));
test57rv("showResponder's whitelist includes 'notes_log'",
    (bool) preg_match('/showResponder\s*=\s*\([^)]*type === .notes_log./', $fn));
// unit_log/dispatch_log must still be there — this is an EXTENSION, not a
// replacement of the existing working cases.
test57rv("showResponder's whitelist still includes 'unit_log' (not regressed)",
    (bool) preg_match('/showResponder\s*=\s*\([^)]*type === .unit_log./', $fn));
test57rv("showResponder's whitelist still includes 'dispatch_log' (not regressed)",
    (bool) preg_match('/showResponder\s*=\s*\([^)]*type === .dispatch_log./', $fn));

// ── Defect 2: the value must be cleared when the field is hidden, gated
//    on !showResponder, AFTER the toggle — same structural proof style as
//    the existing incidentFilter test (a bare grep for the clear statement
//    would also match an unrelated line and prove nothing). ──
$togglePos = strpos($fn, "responderFilterCol.classList.toggle('d-none', !showResponder)");
test57rv('the responder-filter column is toggled on showResponder', $togglePos !== false);
test57rv('a clear statement for responderFilter.value exists after the toggle',
    $togglePos !== false
    && preg_match('/responderFilter\.value\s*=\s*[\'"]0[\'"]/', substr($fn, $togglePos)) === 1,
    "expected responderFilter.value = '0' after the toggle call");
test57rv('the clear is gated on !showResponder, not unconditional',
    $togglePos !== false
    && preg_match('/if\s*\(\s*!showResponder\s*\)\s*\{[^}]*responderFilter\.value\s*=\s*[\'"]0[\'"]/s',
        substr($fn, $togglePos)) === 1);

// NEGATIVE CONTROL — prove the clear-statement check can actually fail.
// This is exactly the shape the bug had before this fix: toggle present,
// clear absent.
$crippled = preg_replace(
    '/(responderFilterCol\.classList\.toggle\(\'d-none\', !showResponder\);)\s*\n\s*if \(!showResponder\) \{\s*\n\s*responderFilter\.value = \'0\';\s*\n\s*\}/',
    '$1',
    $fn,
    1,
    $count
);
test57rv('negative control could locate and strip the clear-on-hide branch', $count === 1);
if ($count === 1) {
    $crippledTogglePos = strpos($crippled, "responderFilterCol.classList.toggle('d-none', !showResponder)");
    $stillHasClear = $crippledTogglePos !== false
        && preg_match('/responderFilter\.value\s*=\s*[\'"]0[\'"]/', substr($crippled, $crippledTogglePos)) === 1;
    test57rv('NEGATIVE CONTROL: with the clear branch stripped, the clear-statement check FAILS',
        !$stillHasClear,
        $stillHasClear ? 'it still passed — the test proves nothing' : '');
}

// runReport() must still read responderFilter.value unconditionally — the
// fix belongs in selectReportType(), matching the existing incidentFilter
// convention, not a second "is this tab active" check in runReport().
if (preg_match('/function runReport\(\)\s*\{(.*?)\r?\n    \}\r?\n/s', $src, $rm) === 1) {
    test57rv('runReport() still reads responderFilter.value directly (fix lives in the tab-switch handler)',
        strpos($rm[1], 'responderFilter.value') !== false);
} else {
    echo "SKIP: could not isolate runReport() to check\n";
}

// ── Defect 3 (companion, backend): api/reports.php's facility_log case
//    must actually apply $responder_id, or the newly-visible dropdown is
//    cosmetic. ──
$apiSrc = (string) file_get_contents($root . '/api/reports.php');
if (preg_match('/case \'facility_log\':(.*?)case \'/s', $apiSrc, $fm) !== 1) {
    echo "[FAIL] could not isolate the facility_log case from api/reports.php — file structure changed?\n";
    $fail++;
} else {
    $facilityCase = $fm[1];
    test57rv("facility_log's WHERE builder checks \$responder_id",
        (bool) preg_match('/if\s*\(\s*\$responder_id\s*>\s*0\s*\)\s*\{/', $facilityCase));
    test57rv("facility_log applies the check against a.responder_id, matching unit_log's own pattern",
        strpos($facilityCase, '`a`.`responder_id` = ?') !== false);
}

// notes_log must be unchanged — it already worked server-side; this fix
// only needed to expose the UI control for it, not touch its backend.
if (preg_match('/case \'notes_log\':(.*?)case \'/s', $apiSrc, $nm) === 1) {
    test57rv("notes_log's existing responder_id support is unchanged",
        strpos($nm[1], '$responder_id > 0') !== false);
} else {
    echo "SKIP: could not isolate notes_log case to confirm it is unchanged\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
