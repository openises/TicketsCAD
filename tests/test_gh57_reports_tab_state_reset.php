<?php
/**
 * GH#57 follow-up (Chris Byrd, 2026-08-13) — after running the After Action
 * report (which fills the hidden #incidentFilter text box), switching back
 * to any other Reports tab and running THAT report silently re-applied the
 * old incident-number filter, because selectReportType() (assets/js/
 * reports.js) hid the field on tab-switch without ever clearing its VALUE,
 * and runReport() reads that value unconditionally. Only a full page
 * reload (which rebuilds the DOM from scratch) cleared it.
 *
 * This asserts the STRUCTURE of the fix, not just that a string exists
 * somewhere in the file: the clear statement must sit inside the
 * `!showIncident` branch, after the visibility toggle it is meant to pair
 * with -- a bare grep for `incidentFilter.value = ''` would also match an
 * unrelated line elsewhere in the file and prove nothing.
 */

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/assets/js/reports.js');

$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// Isolate selectReportType()'s own body (up to the next top-level function),
// so the assertions below can't accidentally match code in a DIFFERENT
// function elsewhere in this 600+ line file.
if (preg_match('/function selectReportType\([^)]*\)\s*\{(.*?)\r?\n    \}\r?\n/s', $src, $m) !== 1) {
    echo "[FAIL] could not isolate selectReportType() from assets/js/reports.js — file structure changed?\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$fn = $m[1];
$pass++; echo "[PASS] isolated selectReportType()'s function body\n";

test('the incident-filter column is still toggled on showIncident',
    (bool) preg_match('/showIncident\s*=.*after_action.*\n\s*incidentFilterCol\.classList\.toggle\(.d-none., !showIncident\)/s', $fn));

// The real regression: the value must be cleared, gated on !showIncident,
// AFTER the toggle line above -- not merely present somewhere in the file.
$togglePos = strpos($fn, "incidentFilterCol.classList.toggle('d-none', !showIncident)");
test('a clear statement for incidentFilter.value exists after the toggle',
    $togglePos !== false
    && preg_match('/incidentFilter\.value\s*=\s*[\'"]{2}/', substr($fn, $togglePos)) === 1,
    'expected incidentFilter.value = \'\' after the toggle call');

test('the clear is gated on !showIncident, not unconditional',
    $togglePos !== false
    && preg_match('/if\s*\(\s*!showIncident\s*\)\s*\{[^}]*incidentFilter\.value\s*=\s*[\'"]{2}/s',
        substr($fn, $togglePos)) === 1);

// runReport() must still read the (now-reliably-empty-when-hidden) value
// unconditionally -- the fix belongs in selectReportType(), not by adding a
// second, redundant "is this tab active" check in runReport() that could
// drift out of sync with it.
if (preg_match('/function runReport\(\)\s*\{(.*?)\r?\n    \}\r?\n/s', $src, $rm) === 1) {
    test("runReport() still reads incidentFilter.value directly (fix lives in the tab-switch handler, not here)",
        strpos($rm[1], 'incidentFilter.value') !== false);
} else {
    echo "SKIP: could not isolate runReport() to check\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
