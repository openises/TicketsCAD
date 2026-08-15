<?php
/**
 * Duplicate DOM id audit regression gate (GH#37 follow-up, 2026-08-08).
 *
 * Runs tools/duplicate_id_audit.php — which finds every literal `id="..."`
 * that appears more than once in settings.php — and fails if any NEW finding
 * (not in tools/duplicate_id_audit_exceptions.txt) appears. This is the guard
 * against the exact bug a QA review caught before release: the new GH#37
 * Audit Log export dropdown reused id="btnAuditExport", already used by the
 * unrelated Roles & Permissions -> Audit Trail export button. Because
 * document.getElementById() silently resolves to whichever element comes
 * first in the DOM, the collision broke one button's click handler and made
 * the other one fire an unrelated CSV download as a side effect — nothing in
 * the test suite could have caught that except reading the rendered HTML.
 *
 * If this test fails: rename one of the colliding ids to something specific
 * to its own panel (the fix here renamed the new dropdown toggle to
 * btnAuditLogExport). This is a same-page collision, not a bug in either
 * feature considered alone — both work fine in isolation, which is exactly
 * why it's easy to miss without this gate.
 *
 * Usage: php tests/test_duplicate_id_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== Duplicate DOM id audit gate ===\n\n";

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

exec(escapeshellarg($php) . ' ' . escapeshellarg($base . '/tools/duplicate_id_audit.php') . ' 2>&1', $out, $code);
$tail = array_slice($out, -30);
echo implode("\n", $tail) . "\n\n";
t('no new duplicate DOM id findings in settings.php', $code === 0);

/*
 * 2026-08-14 regression: \bid matched inside data-id="..." / data-col-id="..."
 * because \b treats '-' as a non-word char, so those false-positived as real
 * id="..." duplicates the moment two elements shared the same data-*id value.
 * Drive the REAL tool against a synthetic fixture, not a reimplementation of
 * its regex.
 */
$fixtureRel = 'tests/_fixtures/dup_id_audit_fixture.php';
$fixtureAbs = $base . '/' . $fixtureRel;
@mkdir(dirname($fixtureAbs), 0777, true);
file_put_contents($fixtureAbs, <<<'PHP'
<div data-id="row5">a</div>
<span data-col-id="row5">b</span>
<a data-row-id="row5">c</a>
<input id="realdupe">
<button id="realdupe">
PHP
);

exec(escapeshellarg($php) . ' ' . escapeshellarg($base . '/tools/duplicate_id_audit.php')
    . ' ' . escapeshellarg('--files=' . $fixtureRel) . ' 2>&1', $fout, $fcode);
$foutStr = implode("\n", $fout);
echo $foutStr . "\n\n";

t('data-id/data-col-id/data-row-id sharing a value is NOT flagged (no real id="..." collision)',
    strpos($foutStr, 'row5') === false);
t('a genuine repeated id="..." (realdupe) IS still flagged', $fcode === 1 && strpos($foutStr, 'realdupe') !== false);

@unlink($fixtureAbs);
@rmdir(dirname($fixtureAbs));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
