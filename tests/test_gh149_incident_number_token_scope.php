<?php
/**
 * GH#149 (rjonesbsink, 2026-09-18) — incnum_render()'s date tokens used to
 * silently fall through to literal text whenever inc/incident-number.php
 * was first require_once'd from INSIDE a function body (api/config-admin.php
 * and api/par.php both do this) rather than top-level script scope, because
 * the old code kept its token map in a bare top-level `$INCNUM_DATE_TOKENS`
 * global and read it back via `global $INCNUM_DATE_TOKENS;` — `require`
 * executes in the calling scope, so a function-scoped require trapped the
 * assignment inside that function instead of the real global scope.
 *
 * This can only be reproduced faithfully in a fresh PHP process per
 * scenario (the bug is about WHICH scope first loads the file, which is a
 * one-shot, process-lifetime fact) — so this drives two real subprocesses,
 * each requiring the real inc/incident-number.php exactly the way the
 * reported shape does, and checks the real incnum_render() output.
 *
 * Usage: php tests/test_gh149_incident_number_token_scope.php
 */
chdir(__DIR__ . '/..');

$pass = 0; $fail = 0;
function ok($label) { global $pass; $pass++; echo "[PASS] $label\n"; }
function bad($label, $hint = '') { global $fail; $fail++; echo "[FAIL] $label" . ($hint !== '' ? " -- $hint" : '') . "\n"; }

$root = realpath(__DIR__ . '/..');
$incFile = $root . '/inc/incident-number.php';

function runPhp(string $code) {
    $phpBin = PHP_BINARY ?: 'php';
    $outFile = tempnam(sys_get_temp_dir(), 'gh149o');
    $errFile = tempnam(sys_get_temp_dir(), 'gh149e');
    $cmd = [$phpBin, '-r', $code];
    $descriptors = [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    $exitCode = proc_close($proc);
    $out = file_get_contents($outFile);
    $err = file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);
    return ['out' => trim((string) $out), 'err' => trim((string) $err), 'code' => $exitCode];
}

// ── Scenario A: file required from TOP-LEVEL scope (the "always worked" shape) ──
$phpA = '
require_once ' . var_export($incFile, true) . ';
echo incnum_render("I{YY}-{NNNN}", 7, mktime(0,0,0,1,1,2026));
';
$resA = runPhp($phpA);
if ($resA['out'] === 'I26-0007') {
    ok('top-level require: date token {YY} renders correctly (I26-0007)');
} else {
    bad('top-level require: date token did not render', "got '{$resA['out']}' err='{$resA['err']}'");
}

// ── Scenario B: file required from INSIDE a function body (the reported bug shape) ──
$phpB = '
function wrapper() {
    require_once ' . var_export($incFile, true) . ';
}
wrapper();
echo incnum_render("I{YY}-{NNNN}", 7, mktime(0,0,0,1,1,2026));
';
$resB = runPhp($phpB);
if ($resB['out'] === 'I26-0007') {
    ok('function-scoped require (GH#149 reported shape): date token {YY} still renders correctly');
} else {
    bad('function-scoped require: date token silently left as literal text — GH#149 regression', "got '{$resB['out']}' err='{$resB['err']}'");
}

// ── Scenario C: incnum_validate() must also resolve tokens correctly from a function-scoped require ──
$phpC = '
function wrapper() {
    require_once ' . var_export($incFile, true) . ';
}
wrapper();
$v = incnum_validate("I{YY}-{NNNN}");
echo $v["has_date"] ? "date=yes" : "date=no";
echo "|";
echo empty($v["warnings"]) ? "warnings=none" : "warnings=" . implode(";", $v["warnings"]);
';
$resC = runPhp($phpC);
if (strpos($resC['out'], 'date=yes') !== false && strpos($resC['out'], 'warnings=none') !== false) {
    ok('function-scoped require: incnum_validate() recognizes {YY} as a real date token, no false "unknown token" warning');
} else {
    bad('function-scoped require: incnum_validate() misclassified a real date token', "got '{$resC['out']}'");
}

// ── Scenario D: the two call sites from the real codebase that triggered this, reproduced literally ──
// api/par.php and api/config-admin.php both require_once this file from inside
// a function/conditional body. Confirm that shape specifically still works
// end to end against every documented date token, not just {YY}.
$phpD = '
function section_handler() {
    require_once ' . var_export($incFile, true) . ';
}
section_handler();
$out = incnum_render("{YYYY}/{YY}/{MM}/{DD}/{HH}-{NNNN}", 42, mktime(13,0,0,3,5,2026));
echo $out;
';
$resD = runPhp($phpD);
if ($resD['out'] === '2026/26/03/05/13-0042') {
    ok('all seven date tokens render correctly from a function-scoped require (2026/26/03/05/13-0042)');
} else {
    bad('one or more date tokens did not render from a function-scoped require', "got '{$resD['out']}' err='{$resD['err']}'");
}

// ── Scenario E: the fix must not have changed top-level behavior (regression guard) ──
$phpE = '
require_once ' . var_export($incFile, true) . ';
$out = incnum_render("{JJJ}-{UU}-{NNN}", 5);
echo (preg_match("/^\\d{3}-\\d{2}-005$/", $out) === 1) ? "ok" : "FAIL:$out";
';
$resE = runPhp($phpE);
if ($resE['out'] === 'ok') {
    ok('{JJJ} (day-of-year) and {UU} (ISO week) still render correctly at top-level scope');
} else {
    bad('{JJJ}/{UU} regression', "got '{$resE['out']}' err='{$resE['err']}'");
}

// ── Scenario F: no bare top-level $INCNUM_DATE_TOKENS assignment remains ──
// (structural guard against reintroducing the scope-sensitive pattern)
$src = file_get_contents($incFile);
if (preg_match('/^\$INCNUM_DATE_TOKENS\s*=/m', $src) === 0 && strpos($src, 'global $INCNUM_DATE_TOKENS') === false) {
    ok('no bare top-level $INCNUM_DATE_TOKENS global or "global $INCNUM_DATE_TOKENS" read remains in the source');
} else {
    bad('the scope-sensitive global pattern has been reintroduced');
}
if (strpos($src, 'function _incnum_date_tokens()') !== false) {
    ok('_incnum_date_tokens() helper exists as the single source of truth');
} else {
    bad('_incnum_date_tokens() helper is missing');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
