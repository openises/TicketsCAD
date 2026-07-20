<?php
/**
 * Schema audit regression gate (Eric 2026-07-07).
 *
 * Runs tools/schema_audit.php — which validates every alias-qualified
 * SQL column reference and INSERT column list against the live schema —
 * and fails if any NEW mismatch (not in tools/schema_audit_baseline.txt)
 * appears. This is the guard against the GH #71 class of bug
 * (member.username, constituents.name, user.pass, personnel, ...):
 * code written against a remembered schema instead of the real one,
 * usually silenced by a try/catch into a feature that quietly no-ops.
 *
 * If this test fails: either fix the query (the usual case) or — for a
 * genuinely install-dependent column/table with a verified guard — add
 * its key to the baseline WITH a comment in the commit explaining why.
 *
 * Usage: php tests/test_schema_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== Schema audit gate ===\n\n";

exec(escapeshellarg($php) . ' ' . escapeshellarg($base . '/tools/schema_audit.php') . ' 2>&1', $out, $code);
$tail = array_slice($out, -20);
echo implode("\n", $tail) . "\n\n";

if ($code === 0) {
    echo "[PASS] no new schema mismatches\n";
    echo "\n=== 1 passed, 0 failed ===\n";
    exit(0);
}
echo "[FAIL] schema audit found NEW mismatches (see above)\n";
echo "\n=== 0 passed, 1 failed ===\n";
exit(1);
