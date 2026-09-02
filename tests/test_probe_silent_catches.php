<?php
/**
 * GH#130 (rjonesbsink) — tools/probe_silent_catches.php:59-63 carried a
 * stale hand-copy of api/incident-types.php's facilities query: it still
 * filtered on a `hide` column GH#40 had already dropped in favor of
 * `deleted_at IS NULL`. Running the probe "confirmed" a real SQL error
 * (Unknown column 'hide') against a query the app no longer runs — the
 * probe's own line-number citation (39) had also drifted; the real query
 * now starts at line 56.
 *
 * Drives the REAL tool against the real live dev database (this probe has
 * no --path fixture mode of its own — it is a live-schema diagnostic, not
 * a static-source scanner) and asserts the specific facilities probe now
 * succeeds and no longer cites the removed column.
 *
 * Usage: php tests/test_probe_silent_catches.php
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/probe_silent_catches.php';

echo "=== probe_silent_catches.php facilities-query regression (GH#130) ===\n\n";
$pass = 0;
$fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);
$outStr = implode("\n", $out);

is_true(strpos($outStr, "Unknown column 'hide'") === false,
    'the removed `hide` column is never cited as a live SQL error',
    $outStr);
is_true(strpos($outStr, 'facilities with hide column') === false,
    'the stale probe label naming the dropped column is gone',
    $outStr);
is_true(strpos($outStr, 'api/incident-types.php:56') !== false,
    'the facilities probe cites the query\'s real current line (56, not the stale 39)',
    $outStr);
is_true(preg_match('/✓ \[api\/incident-types\.php:56\][^\n]*facilities/', $outStr) === 1,
    'the facilities probe reports success (deleted_at IS NULL, matching GH#40)',
    $outStr);
is_true(strpos($outStr, '0 queries fail and need fixing') !== false,
    'the full probe suite reports zero failures',
    $outStr);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
