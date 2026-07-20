<?php
/**
 * API ↔ JS contract regression gate (Eric 2026-07-07).
 *
 * Runs tools/api_contract_audit.php — which flags JavaScript data-key
 * reads that no PHP (or bundled Python service) can emit — and fails
 * on any NEW key not in tools/api_contract_baseline.txt. This guards
 * the second half of the field-mismatch disease: SQL-vs-database is
 * covered by test_schema_audit.php; this covers API-vs-JavaScript
 * (the EOC Units tab class: currstatus / location / last_seen_ago /
 * primary_person dropped at the output mapping).
 *
 * If this test fails: usually the JS is reading a key the endpoint
 * doesn't send (fix the JS to the API's real key, or add the field to
 * the API). For a verified false positive (JS-local data the heuristics
 * can't classify), add the key to the baseline WITH a commit comment.
 *
 * Usage: php tests/test_api_contract_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== API contract gate ===\n\n";

exec(escapeshellarg($php) . ' ' . escapeshellarg($base . '/tools/api_contract_audit.php') . ' 2>&1', $out, $code);
$tail = array_slice($out, -25);
echo implode("\n", $tail) . "\n\n";

if ($code === 0) {
    echo "[PASS] no new API-contract mismatches\n";
    echo "\n=== 1 passed, 0 failed ===\n";
    exit(0);
}
echo "[FAIL] contract audit found NEW mismatches (see above)\n";
echo "\n=== 0 passed, 1 failed ===\n";
exit(1);
