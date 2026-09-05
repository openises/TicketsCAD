<?php
/**
 * SQL-injection taint-analysis regression gate (Eric 2026-09).
 *
 * Runs tools/sqli_taint_audit.php -- a Semgrep taint-mode scan against
 * .semgrep/newui-sql-injection.yml -- and fails if any NEW finding (not
 * in tools/sqli_taint_baseline.txt) appears. Companion to the legacy
 * `tickets` repo's identical gate, built the same day after a reported
 * SQL injection there turned into a full-codebase sweep; this repo
 * shares no code with that one, but the same class of bug is worth
 * gating here too.
 *
 * Requires Docker (to run the semgrep/semgrep image) -- SKIPs cleanly,
 * never fails, when Docker isn't reachable, matching this project's
 * proc_open()-unavailable degradation convention (see
 * test_schema_audit.php). GitHub Actions' ubuntu-latest runners have
 * Docker by default; a local dev machine without it running will SKIP.
 *
 * If this test fails: read the flagged line. If it's a genuine SQL
 * injection, fix it the same way the legacy sweep did -- a bound `?`
 * placeholder for a VALUE position, or db_table()/a literal allowlist
 * for an IDENTIFIER position. If it's a false positive (the value IS
 * checked against a literal allowlist or db_table() first, just in a
 * syntactic shape Semgrep's OSS taint engine doesn't recognize), add it
 * to the baseline WITH a comment in the commit explaining why.
 *
 * Usage: php tests/test_sqli_taint_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== SQL-injection taint audit gate ===\n\n";

// Mirrors gh91_proc_run() in test_schema_audit.php -- argv-array
// proc_open(), no shell, degrades to SKIP rather than a silent/false
// pass when proc_open() itself is unavailable.
function sqli_taint_proc_run(array $argv): array
{
    $sink = tmpfile();
    if ($sink === false) {
        return [127, '(could not open a temporary file to capture output)'];
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = @proc_open($argv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fclose($sink);
        return [127, '(failed to start the subprocess)'];
    }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $out = rtrim((string) stream_get_contents($sink), "\r\n");
    fclose($sink);
    return [$exit, $out];
}

if (!function_exists('proc_open')) {
    echo "SKIP: this PHP cannot start a subprocess (proc_open() is disabled via " .
         "disable_functions) — the SQL-injection audit could not be run\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

[$code, $outText] = sqli_taint_proc_run([$php, $base . '/tools/sqli_taint_audit.php']);
$out = ($outText === '') ? [] : preg_split('/\r\n|\r|\n/', $outText);
echo implode("\n", $out) . "\n\n";

if ($code === 2) {
    echo "SKIP: the audit tool could not run (Docker unavailable or semgrep errored) — see output above\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

if ($code === 0) {
    echo "[PASS] no new SQL-injection findings\n";
    echo "\n=== 1 passed, 0 failed ===\n";
    exit(0);
}
echo "[FAIL] SQL-injection audit found NEW findings (see above)\n";
echo "\n=== 0 passed, 1 failed ===\n";
exit(1);
