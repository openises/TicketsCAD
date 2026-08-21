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
 * GH #91 follow-up (2026-08-20/21, reported by rjonesbsink): this file used
 * to shell out via exec(), which is a fatal "Call to undefined function
 * exec()" on any host whose disable_functions blocks it (common on shared
 * or hardened hosting) — quietly turning this gate into a no-op instead of
 * actually running the audit. It now spawns the audit via argv-array
 * proc_open() (gh91_proc_run() below — mirrors run_via_proc_open() in
 * tools/check-schema.php and runStreamingImport() in
 * tools/update-lookup-data.php), and degrades to an explicit SKIP — never a
 * silent/false pass — when proc_open() itself is also unavailable. See
 * tests/test_gh91_audit_wrapper_subprocess_fallback.php for the regression
 * proof (spawns real PHP subprocesses with disable_functions set both ways).
 *
 * Usage: php tests/test_api_contract_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== API contract gate ===\n\n";

/**
 * Run a subprocess via argv-array proc_open() — no shell involved, so this
 * keeps working when exec()/shell_exec()/popen() are removed via
 * disable_functions (proc_open is a separate function and is not usually
 * included in the same hardening presets — confirmed for this exact
 * disable_functions shape by tests/test_gh93_streaming_import_popen_followup.php's
 * Test B). stdout and stderr share ONE temp-file sink, matching the
 * interleaving `2>&1` gave the old exec() call. The exit code comes from
 * proc_close()'s own return value, which is reliable here because
 * proc_get_status() is never called first — unlike runStreamingImport()'s
 * polling loop, there is no earlier read to "spend" the real exit code.
 *
 * @param array $argv [$binary, $arg1, $arg2, ...]
 * @return array{0:int,1:string} [exitCode, combinedOutput]
 */
function gh91_proc_run(array $argv): array
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
         "disable_functions) — the API contract audit could not be run\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

[$code, $outText] = gh91_proc_run([$php, $base . '/tools/api_contract_audit.php']);
$out  = ($outText === '') ? [] : preg_split('/\r\n|\r|\n/', $outText);
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
