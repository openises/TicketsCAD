<?php
/**
 * Gate: tools/update-lookup-data.php runs the amateur/GMRS/zip-code
 * importers via argv-array proc_open() — streaming their output live — and
 * never touches popen() again.
 *
 * ── THE BUG (openises/TicketsCAD #93 follow-up, 2026-08-20) ────────────────
 *
 * The originally-shipped GH#93 fix reordered extractZip() to try PHP's
 * native ZipArchive before falling back to a shell (exec()) extractor, so a
 * host whose disable_functions blocks exec() no longer dies silently at
 * extraction. But this file ALSO called `popen($importCmd, 'r')` three times
 * — once each for the amateur, GMRS, and zip-code imports — purely to stream
 * the importer's live progress to the terminal while it ran (these imports
 * can take several minutes; silence looks like a hang). popen() is in the
 * SAME disable_functions family as exec() — the reporter's own php.ini
 * listed both:
 *
 *     disable_functions = shell_exec, exec, system, passthru, popen
 *
 * — so a host that blocks popen() specifically (independent of exec(),
 * since disable_functions entries are independent settings) would get past
 * the now-fixed extraction step, download and extract cleanly, and then hit
 * the IDENTICAL "PHP Fatal error: Uncaught Error: Call to undefined function
 * popen()" crash one step later, at import. This file proves that follow-up
 * bug is fixed.
 *
 * ── THE FIX THIS FILE PROVES ────────────────────────────────────────────
 *
 *   1. All three popen() call sites are converted to a new helper,
 *      runStreamingImport(array $cmdArgv), which uses argv-array proc_open()
 *      — no shell, unaffected by popen() specifically being disabled.
 *   2. Output still streams LIVE — not buffered until the child exits — so a
 *      dispatcher/admin watching a multi-minute import still sees progress.
 *      This is proven directly (Test D below), not just asserted: a child
 *      script that sleeps between each printed line is run through the real
 *      runStreamingImport(), and the OUTER test process polls the helper's
 *      own combined output over wall-clock time, checking that content
 *      arrives in multiple chunks, spread out over time, well before the
 *      child (and therefore the helper) has exited — which a "read
 *      everything once at the end" implementation could never produce.
 *   3. If proc_open() itself is ALSO disabled (harsher than the reporter's
 *      own setting), runStreamingImport() fails gracefully with an explicit
 *      message naming proc_open()/disable_functions as the cause, exactly
 *      mirroring extractZip()'s exec() guard — never a silent/fatal crash.
 *
 * ── WHY SUBPROCESSES, NOT MOCKS ─────────────────────────────────────────
 *
 * `disable_functions` is an ini directive resolved once at PHP startup —
 * function_exists('popen')/function_exists('proc_open') cannot be faked
 * in-process. This file spawns real `php` subprocesses with
 * `-d disable_functions=...`, matching tests/test_gh93_lookup_data_extraction.php's
 * own established technique for proving a runtime condition rather than
 * asserting it from source text alone.
 *
 * tools/update-lookup-data.php exposes runStreamingImport() for testing via
 * the same UPDATE_LOOKUP_DATA_LIBRARY_ONLY guard the extraction test uses.
 *
 * Usage: php tests/test_gh93_streaming_import_popen_followup.php
 */

$root   = realpath(__DIR__ . '/..');
$target = $root . '/tools/update-lookup-data.php';
$php    = PHP_BINARY;

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

if (!is_file($target)) {
    echo "SKIP: tools/update-lookup-data.php not found at $target\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ── Scratch workspace ───────────────────────────────────────────────────
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh93b_streaming_' . uniqid();
mkdir($work, 0755, true);
register_shutdown_function(function () use ($work) {
    if (!is_dir($work)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($work);
});

// ── A tiny "child importer": prints a handful of distinct lines, EXITS 0.
// Used for Tests A/B/C where only correctness (not timing) matters. ────────
$childFast = $work . DIRECTORY_SEPARATOR . 'child_fast.php';
file_put_contents($childFast, <<<'CHILD'
<?php
echo "importing record 1\n";
echo "importing record 2\n";
fwrite(STDERR, "a warning on stderr\n");
echo "importing record 3\n";
exit(0);
CHILD
);

// A child that fails, to prove a nonzero exit code round-trips correctly.
$childFail = $work . DIRECTORY_SEPARATOR . 'child_fail.php';
file_put_contents($childFail, <<<'CHILD'
<?php
echo "about to fail\n";
exit(7);
CHILD
);

// A "slow" child for the live-streaming proof (Test D): prints one line,
// sleeps, prints another, sleeps, prints a third. The sleeps are the whole
// point — they give a genuinely-live reader multiple, separated
// opportunities to observe partial output before the child exits.
$childSlow = $work . DIRECTORY_SEPARATOR . 'child_slow.php';
file_put_contents($childSlow, <<<'CHILD'
<?php
echo "slow line 1\n";
usleep(500000);
echo "slow line 2\n";
usleep(500000);
echo "slow line 3\n";
exit(0);
CHILD
);

// ── PHP harness: loads update-lookup-data.php in library mode and calls
// runStreamingImport() directly, so we exercise the REAL function. ─────────
$harness = $work . DIRECTORY_SEPARATOR . 'harness.php';
file_put_contents($harness, <<<'HARNESS'
<?php
define('UPDATE_LOOKUP_DATA_LIBRARY_ONLY', true);
require $argv[1];
$exit = runStreamingImport([PHP_BINARY, $argv[2]]);
echo "\n___EXIT___" . ($exit === null ? 'NULL' : $exit) . "___\n";
HARNESS
);

/**
 * Run a php subprocess via argv-array proc_open (no shell). Returns
 * [combinedOutput, exitCode]. Blocking/whole-output capture — fine for
 * Tests A/B/C, which only need the final result, not timing.
 */
function run_php_subprocess(array $phpFlags, array $scriptArgv): array
{
    global $php;
    $cmdArgv = array_merge([$php], $phpFlags, $scriptArgv);
    $sink = tmpfile();
    if ($sink === false) return ['(could not open temp sink)', 127];
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = proc_open($cmdArgv, $descriptors, $pipes);
    if (!is_resource($proc)) { fclose($sink); return ['(failed to start subprocess)', 127]; }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $out = (string) stream_get_contents($sink);
    fclose($sink);
    return [$out, $exit];
}

/**
 * Run a php subprocess and POLL its combined stdout+stderr over wall-clock
 * time while it runs, recording each non-empty chunk of NEW output together
 * with the elapsed time (seconds since spawn) it was observed at. This is
 * how Test D proves runStreamingImport() streams live rather than buffering
 * until exit: a buffered implementation produces exactly one chunk, at
 * (roughly) the process's total runtime; a live one produces several,
 * spread across the runtime.
 *
 * Deliberately mirrors runStreamingImport()'s own approach (file-based
 * stdout/stderr descriptors, polled — never a pipe, so this outer probe
 * itself cannot deadlock either) rather than reusing a blocking sink.
 *
 * @return array{events: array<array{t: float, text: string}>, output: string, exit: int}
 */
function run_php_subprocess_polling(array $phpFlags, array $scriptArgv, int $pollMs = 40): array
{
    global $php;
    $cmdArgv = array_merge([$php], $phpFlags, $scriptArgv);
    $outFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh93b_probe_' . bin2hex(random_bytes(6)) . '.out';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $outFile, 'w']];
    $pipes = [];
    $proc = proc_open($cmdArgv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        @unlink($outFile);
        return ['events' => [], 'output' => '', 'exit' => 127];
    }
    fclose($pipes[0]);

    $events = [];
    $start  = microtime(true);
    $pos    = 0;

    $read = function () use (&$pos, &$events, $outFile, $start) {
        clearstatcache(true, $outFile);
        $size = @filesize($outFile);
        if ($size === false || $size <= $pos) return;
        $fh = @fopen($outFile, 'rb');
        if (!$fh) return;
        fseek($fh, $pos, SEEK_SET);
        $chunk = fread($fh, $size - $pos);
        fclose($fh);
        if ($chunk === false || $chunk === '') return;
        $pos = $size;
        $events[] = ['t' => microtime(true) - $start, 'text' => $chunk];
    };

    // Exit code from proc_get_status()'s 'exitcode', taken at the exact poll
    // where 'running' first flips false — NOT from proc_close()'s return
    // value, which returns -1 once that one real reading has already been
    // consumed by proc_get_status() (this bit runStreamingImport() itself
    // the same way on CI's Linux runner; see that function's own comment).
    $exit = null;
    while (true) {
        $status = proc_get_status($proc);
        $read();
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        usleep($pollMs * 1000);
    }
    proc_close($proc);
    $read();

    $output = (string) @file_get_contents($outFile);
    @unlink($outFile);
    return ['events' => $events, 'output' => $output, 'exit' => $exit];
}

function extract_harness_exit(string $output): ?string
{
    if (preg_match('/___EXIT___(NULL|-?\d+)___/', $output, $m) === 1) {
        return $m[1];
    }
    return null;
}

$disableFlags = ['-d', 'disable_functions=shell_exec,exec,system,passthru,popen'];

// ═══════════════════════════════════════════════════════════════════════
// Test A — baseline: default php.ini, no flags. Proves plain correctness
// before touching disable_functions at all.
// ═══════════════════════════════════════════════════════════════════════
[$outA, $exitA] = run_php_subprocess([], [$harness, $target, $childFast]);
test('A: harness ran cleanly (exit 0)', $exitA === 0, "exit=$exitA output=$outA");
test('A: runStreamingImport() reports the child\'s real exit code (0)',
    extract_harness_exit($outA) === '0', $outA);
test('A: all three of the child\'s stdout lines were captured',
    strpos($outA, 'importing record 1') !== false
    && strpos($outA, 'importing record 2') !== false
    && strpos($outA, 'importing record 3') !== false, $outA);
test('A: the child\'s stderr line was captured too (stdout+stderr combined, like the old 2>&1)',
    strpos($outA, 'a warning on stderr') !== false, $outA);
test('A: no fatal error occurred', stripos($outA, 'Fatal error') === false, $outA);

// ═══════════════════════════════════════════════════════════════════════
// Test A2 — a failing child's nonzero exit code round-trips correctly.
// ═══════════════════════════════════════════════════════════════════════
[$outA2, $exitA2] = run_php_subprocess([], [$harness, $target, $childFail]);
test('A2: harness itself ran cleanly (exit 0 — the CHILD failed, not the harness)',
    $exitA2 === 0, "exit=$exitA2 output=$outA2");
test('A2: runStreamingImport() reports the failing child\'s real exit code (7)',
    extract_harness_exit($outA2) === '7', $outA2);
test('A2: the failing child\'s output was still captured before it exited',
    strpos($outA2, 'about to fail') !== false, $outA2);

// ═══════════════════════════════════════════════════════════════════════
// Test B — THE CORE REGRESSION PROOF: the reporter's EXACT disable_functions
// setting (popen blocked, proc_open still available — the ordinary shape of
// a hardened-but-not-maximally-locked-down host). Import must still run to
// completion via proc_open, never touching popen() — this is the scenario
// that used to crash with "Call to undefined function popen()" before this
// follow-up.
// ═══════════════════════════════════════════════════════════════════════
[$outB, $exitB] = run_php_subprocess($disableFlags, [$harness, $target, $childFast]);
test('B: harness ran cleanly under the reporter\'s exact disable_functions (exit 0)',
    $exitB === 0, "exit=$exitB output=$outB");
test('B: runStreamingImport() still reports the child\'s real exit code (0) with popen() disabled',
    extract_harness_exit($outB) === '0', $outB);
test('B: output was still captured with popen() disabled',
    strpos($outB, 'importing record 1') !== false
    && strpos($outB, 'importing record 3') !== false, $outB);
test('B: no fatal "Call to undefined function popen()" crash — the bug this follow-up fixes',
    stripos($outB, 'Call to undefined function popen') === false
    && stripos($outB, 'Fatal error') === false, $outB);

// ═══════════════════════════════════════════════════════════════════════
// Test C — worst case: proc_open() ALSO disabled (harsher than the
// reporter's own setting). Must fail GRACEFULLY with the explicit
// disable_functions/proc_open message, never a silent/fatal crash, and the
// caller (update-lookup-data.php's own call sites) must be able to tell
// this apart from "the import ran and failed" via the null return.
// ═══════════════════════════════════════════════════════════════════════
$harshFlags = ['-d', 'disable_functions=shell_exec,exec,system,passthru,popen,proc_open'];
[$outC, $exitC] = run_php_subprocess($harshFlags, [$harness, $target, $childFast]);
test('C: harness process itself did not crash (proc_open unavailable is handled, not fatal)',
    $exitC === 0, "exit=$exitC output=$outC");
test('C: runStreamingImport() returns null when proc_open() is disabled (distinguishable from a real exit code)',
    extract_harness_exit($outC) === 'NULL', $outC);
test('C: failure message explicitly names disable_functions/proc_open() as the cause',
    strpos($outC, 'disable_functions blocks proc_open()') !== false, $outC);
test('C: no fatal "Call to undefined function proc_open()" crash — the function_exists() guard worked',
    stripos($outC, 'Fatal error') === false
    && stripos($outC, 'Call to undefined function') === false, $outC);

// ═══════════════════════════════════════════════════════════════════════
// Test D — THE LIVE-STREAMING PROOF: output must arrive in the OUTER
// process's view progressively, across wall-clock time, not as a single
// chunk once the child (and therefore runStreamingImport()) has exited.
// The child sleeps 0.5s between three printed lines (~1.0-1.1s total
// runtime); a genuinely live reader will observe at least two separate
// chunks, with the FIRST chunk arriving well before the process's total
// runtime — a "read the whole file once after proc_close()" implementation
// would instead produce exactly one chunk, arriving at (about) the full
// ~1s runtime.
// ═══════════════════════════════════════════════════════════════════════
$probe = run_php_subprocess_polling([], [$harness, $target, $childSlow]);
$events = $probe['events'];
$totalOut = $probe['output'];

test('D: harness (proc_open probe) ran cleanly (exit 0)', $probe['exit'] === 0,
    'events=' . count($events) . ' output=' . $totalOut);
test('D: all three slow lines were eventually captured',
    strpos($totalOut, 'slow line 1') !== false
    && strpos($totalOut, 'slow line 2') !== false
    && strpos($totalOut, 'slow line 3') !== false, $totalOut);
test('D: output arrived in MULTIPLE separate chunks over time (proof of live streaming, not buffering)',
    count($events) >= 2,
    count($events) . ' chunk(s) observed — a buffered ("read once at exit") implementation would show 1');

if (count($events) >= 1) {
    $firstChunkTime = $events[0]['t'];
    $lastChunkTime  = $events[count($events) - 1]['t'];
    // The child's total runtime is ~1.0-1.1s (two 0.5s sleeps). A live
    // reader sees its first content almost immediately (well under the
    // total runtime); a buffered reader would only ever see content at
    // (about) the full runtime. 0.7s is a generous cutoff — comfortably
    // below the ~1.0s total but well above normal poll/process-spawn
    // overhead, to avoid flaking under load from concurrent sessions.
    test('D: the FIRST chunk of output arrived well before the child finished (< 0.7s into a ~1.0-1.1s run)',
        $firstChunkTime < 0.7,
        "first chunk at {$firstChunkTime}s; all event times: " . implode(', ', array_map(fn($e) => round($e['t'], 3), $events)));
    test('D: output was still arriving late in the run too (last chunk observed >= 0.3s in)',
        $lastChunkTime >= 0.3,
        "last chunk at {$lastChunkTime}s");
} else {
    test('D: (skipped timing checks — no chunks captured at all, see chunk-count failure above)', false);
}

// ═══════════════════════════════════════════════════════════════════════
// Test E — structural: no popen()/pclose() call sites remain anywhere in
// tools/update-lookup-data.php (comments mentioning them in prose are fine —
// this is a real call-site check, not a substring ban).
// ═══════════════════════════════════════════════════════════════════════
$src  = (string) file_get_contents($target);
$toks = token_get_all($src);
$callSites = [];
foreach ($toks as $i => $t) {
    if (!is_array($t) || $t[0] !== T_STRING) continue;
    $name = strtolower($t[1]);
    if ($name !== 'popen' && $name !== 'pclose') continue;
    // Next significant token should be '(' for this to be a real call.
    for ($j = $i + 1; $j < count($toks); $j++) {
        $nt = $toks[$j];
        if (is_array($nt) && ($nt[0] === T_WHITESPACE || $nt[0] === T_COMMENT || $nt[0] === T_DOC_COMMENT)) continue;
        $text = is_array($nt) ? $nt[1] : $nt;
        if ($text === '(') { $callSites[] = $name . '()'; }
        break;
    }
}
test('E: no popen()/pclose() call sites remain in tools/update-lookup-data.php',
    $callSites === [], 'found: ' . implode(', ', $callSites));

test('E: runStreamingImport() is present and typed `array $cmdArgv` (blocks the string-command form)',
    preg_match('/function\s+runStreamingImport\s*\(\s*array\s+\$cmdArgv\s*\)/', $src) === 1);

test('E: all three import call sites (amateur/GMRS/zipcodes) now call runStreamingImport()',
    substr_count($src, '$exitCode = runStreamingImport(') === 3,
    'found ' . substr_count($src, '$exitCode = runStreamingImport(')
    . ' (total mentions of runStreamingImport( including the definition: ' . substr_count($src, 'runStreamingImport(') . ')');

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
