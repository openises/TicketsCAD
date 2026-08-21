<?php
/**
 * GH #91 follow-up (openises/TicketsCAD, reported by rjonesbsink,
 * 2026-08-20/21): the five test wrappers around this project's static/
 * schema audit gates —
 *
 *   tests/test_dead_control_audit.php
 *   tests/test_schema_audit.php
 *   tests/test_api_contract_audit.php
 *   tests/test_legacy_level_audit.php
 *   tests/test_rbac_permission_audit.php
 *
 * — used to shell out via exec() to run their underlying
 * tools/*_audit.php scripts. On any host whose disable_functions blocks
 * exec() (common on shared/hardened hosting — the reporter's own case, and
 * exactly what tools/update-lookup-data.php was fixed for twice already
 * this same night, GH#93 and its popen() follow-up), calling exec() is a
 * fatal "Call to undefined function exec()". None of the five wrappers
 * guarded against that, so on the reporter's host each one printed only its
 * own header and exited 0 with NO assertions run — a green line in the
 * suite that never actually checked anything the gate exists to check.
 * Running the underlying tools/*_audit.php scripts directly worked fine
 * (proving the audits themselves were never the problem, only the
 * wrappers' exec() shell-out).
 *
 * ── THE FIX THIS FILE PROVES ────────────────────────────────────────────
 *
 *   1. All five wrappers now run their underlying tool via argv-array
 *      proc_open() (gh91_proc_run() / lla_run_audit(), added to each
 *      wrapper — mirrors run_via_proc_open() in tools/check-schema.php and
 *      runStreamingImport() in tools/update-lookup-data.php), which keeps
 *      working when exec()/shell_exec()/popen() are disabled, because
 *      proc_open() is a separate function most hardening presets leave
 *      alone.
 *   2. If proc_open() itself is ALSO disabled (harsher than the reporter's
 *      own setting), each wrapper degrades to an explicit
 *      "SKIP: ..." + the canonical "0 passed, 0 failed" summary and exits
 *      0 — never a silent/false pass, never a fatal crash — so
 *      tools/test_all.php's classifier (tools/suite_contract.php) records
 *      it as a legitimate, declared skip.
 *   3. On an ordinary host (both flags off, or only exec/shell_exec/popen
 *      disabled with proc_open still there), each wrapper keeps running
 *      its real underlying audit and reporting genuine pass/fail counts —
 *      the common case must never regress.
 *
 * ── WHY SUBPROCESSES, NOT MOCKS ─────────────────────────────────────────
 *
 * disable_functions is an ini directive resolved once at PHP startup;
 * function_exists('exec')/function_exists('proc_open') cannot be faked
 * in-process. This file spawns REAL php subprocesses with
 * `-d disable_functions=...`, mirroring
 * tests/test_gh93_streaming_import_popen_followup.php's own established
 * technique (itself citing tests/test_gh93_lookup_data_extraction.php) for
 * proving a runtime condition rather than asserting it from source text
 * alone. Test B below deliberately reuses that exact same
 * disable_functions string — `shell_exec,exec,system,passthru,popen` with
 * proc_open conspicuously absent — which that file's own Test B already
 * proved leaves proc_open() reachable; this file re-proves it specifically
 * for these five wrappers rather than assuming the earlier proof transfers.
 *
 * Two of the five wrappers (test_schema_audit.php, test_api_contract_audit.php)
 * need no database. The other three (test_dead_control_audit.php,
 * test_legacy_level_audit.php, test_rbac_permission_audit.php) do — but
 * their OWN "no database connection" SKIP is a legitimate, pre-existing,
 * correctly-declared skip unrelated to the bug this file exists to prove
 * fixed, so Scenario B tolerates it (and still asserts the canonical
 * summary shape) on a checkout with no local config.php. Scenario A (the
 * worst case) is unaffected either way: every wrapper's
 * function_exists('proc_open') check runs BEFORE config.php is ever
 * loaded, so it is provably exercised regardless of database availability.
 *
 * Usage: php tests/test_gh91_audit_wrapper_subprocess_fallback.php
 */

$root = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

if (!function_exists('proc_open')) {
    // This OUTER test process itself needs proc_open() to spawn the probes
    // below — if the environment running the suite has it disabled too,
    // there is no way to exercise anything here.
    echo "SKIP: this PHP cannot start a subprocess (proc_open() is disabled via " .
         "disable_functions) — cannot spawn the probe subprocesses this file needs\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

/**
 * Run a php subprocess via argv-array proc_open (no shell), with a hard
 * wall-clock timeout so a wedged child cannot hang this file forever.
 * stdout+stderr share ONE temp-file sink (matches how each wrapper's own
 * gh91_proc_run() captures its child, and how the old exec(...'2>&1') did).
 *
 * @return array{0:string,1:int|null,2:bool} [combinedOutput, exitCode, timedOut]
 */
function run_php_subprocess(array $phpFlags, array $scriptArgv, int $timeoutSec = 90): array
{
    global $php;
    $cmdArgv = array_merge([$php], $phpFlags, $scriptArgv);
    $sink = tmpfile();
    if ($sink === false) return ['(could not open temp sink)', 127, false];
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = @proc_open($cmdArgv, $descriptors, $pipes);
    if (!is_resource($proc)) { fclose($sink); return ['(failed to start subprocess)', 127, false]; }
    fclose($pipes[0]);

    $start = microtime(true);
    $exit = null;
    $timedOut = false;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) { $exit = $status['exitcode']; break; }
        if (microtime(true) - $start > $timeoutSec) {
            $timedOut = true;
            @proc_terminate($proc, 9);
            usleep(200000);
            $status = proc_get_status($proc);
            $exit = $status['exitcode'] ?? -1;
            break;
        }
        usleep(50000);
    }
    proc_close($proc);
    rewind($sink);
    $out = (string) stream_get_contents($sink);
    fclose($sink);
    return [$out, $exit, $timedOut];
}

function tail_lines(string $text, int $n = 10): string
{
    $lines = explode("\n", trim($text));
    return implode("\n", array_slice($lines, -$n));
}

$hasConfig = is_file($root . '/config.php');
if (!$hasConfig) {
    echo "NOTE: config.php not present in this checkout — the three DB-backed " .
         "wrappers (test_dead_control_audit.php, test_legacy_level_audit.php, " .
         "test_rbac_permission_audit.php) will legitimately self-skip on their " .
         "own pre-existing \"no database connection\" check once they get past " .
         "the proc_open-availability check this file exists to prove. That is a " .
         "real, correctly-declared skip, not the bug under test — Scenario A " .
         "(proc_open() itself disabled) is unaffected and still fully proven,\n" .
         "since every wrapper's function_exists('proc_open') check runs BEFORE " .
         "config.php is ever loaded.\n\n";
}

$wrappers = [
    'test_schema_audit.php'          => ['needsDb' => false],
    'test_api_contract_audit.php'    => ['needsDb' => false],
    'test_dead_control_audit.php'    => ['needsDb' => true],
    'test_legacy_level_audit.php'    => ['needsDb' => true],
    'test_rbac_permission_audit.php' => ['needsDb' => true],
];

// Scenario A — worst case: exec/shell_exec/popen AND proc_open all disabled.
$harshFlags = ['-d', 'disable_functions=shell_exec,exec,system,passthru,popen,proc_open'];

// Scenario B — the reporter's own exact shape: exec/shell_exec/popen
// disabled, proc_open conspicuously NOT in the list (already independently
// proven to leave proc_open() reachable by
// tests/test_gh93_streaming_import_popen_followup.php's Test B).
$reporterFlags = ['-d', 'disable_functions=shell_exec,exec,system,passthru,popen'];

foreach ($wrappers as $file => $meta) {
    $path = $root . '/tests/' . $file;
    echo "\n-- $file --\n";

    if (!is_file($path)) {
        test("$file exists", false, $path);
        continue;
    }
    test("$file exists", true);

    // ── Scenario A: proc_open() ALSO disabled — must degrade honestly ────
    [$outA, $exitA, $timedOutA] = run_php_subprocess($harshFlags, [$path]);
    test("$file (proc_open disabled): did not time out", !$timedOutA);
    test("$file (proc_open disabled): exits 0 (a declared SKIP is not a failure)",
        $exitA === 0, "exit=$exitA; tail:\n" . tail_lines($outA));
    test("$file (proc_open disabled): declares an explicit SKIP",
        stripos($outA, 'skip') !== false, tail_lines($outA, 15));
    test("$file (proc_open disabled): reports the canonical \"0 passed, 0 failed\" " .
        "(never a false pass, and never a nonzero count it cannot back up)",
        (bool) preg_match('/\b0\s+passed,\s+0\s+failed\b/', $outA), tail_lines($outA, 15));
    test("$file (proc_open disabled): no fatal \"Call to undefined function\" crash " .
        "(the function_exists('proc_open') guard worked)",
        stripos($outA, 'Call to undefined function') === false
        && stripos($outA, 'Fatal error') === false, tail_lines($outA, 15));

    // ── Scenario B: the reporter's exact shape — proc_open() still there ─
    [$outB, $exitB, $timedOutB] = run_php_subprocess($reporterFlags, [$path]);
    test("$file (exec/shell_exec/popen disabled, proc_open available): did not time out",
        !$timedOutB);
    test("$file (exec/shell_exec/popen disabled, proc_open available): " .
        "no fatal \"Call to undefined function\" crash — the exact bug this fix closes",
        stripos($outB, 'Call to undefined function') === false
        && stripos($outB, 'Fatal error') === false, tail_lines($outB, 15));
    test("$file (exec/shell_exec/popen disabled, proc_open available): " .
        "reports a canonical summary line",
        (bool) preg_match('/\d+\s+passed,\s+\d+\s+failed/', $outB), tail_lines($outB, 15));

    if ($meta['needsDb'] && !$hasConfig) {
        // The wrapper's OWN "no database connection" self-skip is legitimate
        // here — this checkout has no config.php at all. Already asserted the
        // canonical summary shape above; nothing more to check for this file
        // in Scenario B.
        continue;
    }

    // With proc_open() available and the database reachable, the wrapper
    // must run its REAL underlying audit and report genuine work — it must
    // NOT silently fall back to a skip just because exec()/shell_exec()/
    // popen() are gone. That fallback belongs to Scenario A only.
    test("$file (exec/shell_exec/popen disabled, proc_open available): " .
        "did NOT silently skip — real assertions actually ran",
        (bool) preg_match('/([1-9]\d*)\s+passed,\s+\d+\s+failed/', $outB), tail_lines($outB, 15));
    test("$file (exec/shell_exec/popen disabled, proc_open available): exits 0 (clean pass)",
        $exitB === 0, "exit=$exitB; tail:\n" . tail_lines($outB, 15));
}

// ── Structural: every wrapper's own internal helper is proc_open-based ───
// and no wrapper regains a raw exec()/shell_exec()/popen() call site.
echo "\n-- Structural: no exec()/shell_exec()/popen() call sites remain --\n";
foreach (array_keys($wrappers) as $file) {
    $path = $root . '/tests/' . $file;
    if (!is_file($path)) continue;
    $toks = token_get_all((string) file_get_contents($path));
    $callSites = [];
    foreach ($toks as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING) continue;
        $name = strtolower($t[1]);
        if (!in_array($name, ['exec', 'shell_exec', 'popen', 'system', 'passthru'], true)) continue;
        for ($j = $i + 1; $j < count($toks); $j++) {
            $nt = $toks[$j];
            if (is_array($nt) && in_array($nt[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $text = is_array($nt) ? $nt[1] : $nt;
            if ($text === '(') { $callSites[] = $name . '()'; }
            break;
        }
    }
    test("$file has no exec()/shell_exec()/popen()/system()/passthru() call sites",
        $callSites === [], 'found: ' . implode(', ', $callSites));
    test("$file uses proc_open()",
        stripos((string) file_get_contents($path), 'proc_open(') !== false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
