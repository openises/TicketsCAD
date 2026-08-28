<?php
/**
 * test_gh120_node_probe_disabled_functions.php — GH#120 (reported
 * 2026-08-28, against this session's own GH#117/#118 test files).
 *
 * THE BUG: tests/test_gh117_zello_windows_diagnostics.php and
 * tests/test_gh118_assign_remove_ticketid.php both probed for node (and,
 * in #117's case, php) availability with `@shell_exec($cand . ' --version
 * 2>&1')` as their FIRST move. When shell_exec is listed in php.ini's
 * disable_functions — a real, documented hardening posture; this
 * project's own security docs recommend disabling the exec/shell_exec/
 * system/passthru/popen family — PHP 8 does not return false with a
 * suppressible warning: it throws `Error: Call to undefined function
 * shell_exec()`, and `@` cannot suppress a fatal Error. So the probe
 * itself crashed the whole test file with exit 255, and the tests' own
 * "SKIP: node not available" fallback further down was unreachable.
 *
 * A grep sweep found this exact `@shell_exec(...)` probe-without-a-guard
 * pattern in 55 files across tests/ and tools/ — most pre-dating this
 * session. Fixing all 55 in one pass was judged too large and too risky
 * to do reliably in the same change (see the GH#120 fix commit message
 * for the full reasoning) — this file instead builds and proves the
 * SHARED, reusable fix (tests/_test_node_probe.php) and applies it to
 * the two files GH#120 actually reported, so future test files have a
 * safe primitive to reach for and the broader sweep can be done as its
 * own, separately-verified follow-up.
 *
 * THE FIX (tests/_test_node_probe.php): test_probe_cli()/test_run_cli()
 * guard every execution attempt with function_exists() first, and prefer
 * proc_open() over shell_exec() when available — proc_open is a SEPARATE
 * function from the exec/shell_exec/popen family disable_functions
 * commonly targets (the same distinction this project's own GH#93 fix
 * already established), so on a host shaped like the one GH#120's
 * reporter described (shell_exec/exec/system/passthru/popen disabled,
 * proc_open NOT disabled), the underlying Node-driven checks can
 * actually RUN rather than merely skip.
 *
 * This file proves, via REAL subprocess runs with genuinely varied
 * -d disable_functions= flags (not a mocked/simulated disable — an ini
 * directive can only be set at process startup, so a real subprocess is
 * the only honest way to test this):
 *
 *   Section 1 — with nothing disabled, test_run_cli()/test_probe_cli()
 *     work correctly (baseline sanity).
 *   Section 2 — with shell_exec (and the rest of the exec family)
 *     disabled but proc_open available (the reporter's EXACT
 *     disable_functions list): the helper still runs real commands via
 *     proc_open — full coverage, not a skip.
 *   Section 3 — with BOTH shell_exec and proc_open disabled: the helper
 *     returns null cleanly, no crash, no fatal error in the output.
 *   Section 4 — NEGATIVE CONTROL: the exact PRE-FIX pattern (a bare
 *     `@shell_exec(...)` probe, no guard) really does crash with a fatal
 *     Error under disable_functions=shell_exec, proving this test would
 *     have caught the original bug.
 *   Section 5 — functional: the two ACTUALLY-FIXED files
 *     (test_gh117_zello_windows_diagnostics.php,
 *     test_gh118_assign_remove_ticketid.php) are run for real, twice —
 *     once with the reporter's exact disable_functions list (must pass
 *     with FULL coverage, matching their unrestricted assertion counts)
 *     and once with proc_open also disabled (must degrade to a clean
 *     SKIP, exit 0, never exit 255).
 *   Section 6 — static: neither fixed file contains a bare `@shell_exec(`
 *     call any more.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = realpath(__DIR__ . '/..');
$php = PHP_BINARY ?: 'php';

echo "=== GH#120 — node-probe test harness survives disabled exec functions ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

/** Run a PHP snippet as a real subprocess with the given disable_functions list. */
function gh120_run_snippet(string $php, string $snippetPath, string $disableFunctions = ''): array {
    $argv = [$php];
    if ($disableFunctions !== '') $argv[] = '-d disable_functions=' . $disableFunctions;
    $argv[] = $snippetPath;
    // Build the command as a single string here ONLY because we need the
    // "-d key=value" form as one argv element with an embedded space,
    // which proc_open's array form handles fine — no shell involved.
    $cmd = $argv;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return ['exit' => -1, 'out' => ''];
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'out' => $out];
}

function gh120_write_snippet(string $body): string {
    $p = sys_get_temp_dir() . '/tcad_gh120_snippet_' . getmypid() . '_' . mt_rand() . '.php';
    file_put_contents($p, "<?php\n" . $body);
    return $p;
}

$probePath = str_replace('\\', '/', $base . '/tests/_test_node_probe.php');

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Baseline: nothing disabled --\n";
// ─────────────────────────────────────────────────────────────────────────
$s1 = gh120_write_snippet("require '{$probePath}'; var_dump(test_run_cli([PHP_BINARY, '-r', 'echo 42;']));");
$r1 = gh120_run_snippet($php, $s1);
@unlink($s1);
is_true($r1['exit'] === 0 && strpos($r1['out'], 'string(2) "42"') !== false,
    'test_run_cli() executes a real command and returns its output when nothing is disabled',
    "exit={$r1['exit']} out=" . trim($r1['out']));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. shell_exec/exec/system/passthru/popen disabled, proc_open available (the reporter's EXACT list) --\n";
// ─────────────────────────────────────────────────────────────────────────
$s2 = gh120_write_snippet("require '{$probePath}'; var_dump(test_run_cli([PHP_BINARY, '-r', 'echo 43;']));");
$r2 = gh120_run_snippet($php, $s2, 'shell_exec,exec,system,passthru,popen');
@unlink($s2);
is_true($r2['exit'] === 0 && strpos($r2['out'], 'string(2) "43"') !== false
    && strpos($r2['out'], 'Fatal error') === false,
    'FIX: test_run_cli() STILL runs the real command via proc_open (full coverage, not a skip) when only the exec/shell_exec/popen family is disabled',
    "exit={$r2['exit']} out=" . trim($r2['out']));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. BOTH shell_exec and proc_open disabled — must degrade cleanly, never crash --\n";
// ─────────────────────────────────────────────────────────────────────────
$s3 = gh120_write_snippet("require '{$probePath}'; var_dump(test_run_cli([PHP_BINARY, '-r', 'echo 44;']));");
$r3 = gh120_run_snippet($php, $s3, 'shell_exec,proc_open');
@unlink($s3);
is_true($r3['exit'] === 0 && strpos($r3['out'], 'NULL') !== false && strpos($r3['out'], 'Fatal error') === false,
    'FIX: test_run_cli() returns null (no crash, no fatal) when NEITHER execution mechanism is available',
    "exit={$r3['exit']} out=" . trim($r3['out']));

$s3b = gh120_write_snippet("require '{$probePath}'; var_dump(test_probe_cli(['php','php.exe']));");
$r3b = gh120_run_snippet($php, $s3b, 'shell_exec,proc_open');
@unlink($s3b);
is_true($r3b['exit'] === 0 && strpos($r3b['out'], 'NULL') !== false && strpos($r3b['out'], 'Fatal error') === false,
    'FIX: test_probe_cli() also returns null cleanly under the same conditions',
    "exit={$r3b['exit']} out=" . trim($r3b['out']));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. NEGATIVE CONTROL: the exact pre-fix pattern really does crash --\n";
// ─────────────────────────────────────────────────────────────────────────
$s4 = gh120_write_snippet("\$probe = @shell_exec('node --version 2>&1'); echo 'reached next line';");
$r4 = gh120_run_snippet($php, $s4, 'shell_exec');
@unlink($s4);
is_true($r4['exit'] !== 0 && strpos($r4['out'], 'reached next line') === false
    && stripos($r4['out'], 'Fatal error') !== false && stripos($r4['out'], 'shell_exec') !== false,
    'NEGATIVE CONTROL: a bare @shell_exec() call under disable_functions=shell_exec crashes with a fatal Error before the next line runs — proving this harness would have caught GH#120\'s original bug',
    "exit={$r4['exit']} out=" . trim($r4['out']));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. The two ACTUALLY-FIXED files, run for real under both conditions --\n";
// ─────────────────────────────────────────────────────────────────────────
$fixedFiles = [
    'test_gh117_zello_windows_diagnostics.php' => ['min_pass_full' => 25],
    'test_gh118_assign_remove_ticketid.php'    => ['min_pass_full' => 40],
];
foreach ($fixedFiles as $file => $expect) {
    $path = $base . '/tests/' . $file;

    // 5a — reporter's exact list (proc_open available): must reach FULL
    // coverage, not degrade to a skip.
    $rFull = gh120_run_snippet($php, $path, 'shell_exec,exec,system,passthru,popen');
    $matched = preg_match('/=== (\d+) passed, (\d+) failed ===/', $rFull['out'], $m);
    is_true($rFull['exit'] === 0 && $matched === 1 && (int) $m[2] === 0 && (int) $m[1] >= $expect['min_pass_full'],
        "FIX: {$file} reaches full coverage (>= {$expect['min_pass_full']} passed, 0 failed) under the reporter's exact disable_functions list",
        "exit={$rFull['exit']} tail=" . substr(trim($rFull['out']), -300));

    // 5b — shell_exec AND proc_open both disabled: must degrade to a
    // clean SKIP and exit 0, never 255.
    $rSkip = gh120_run_snippet($php, $path, 'shell_exec,proc_open');
    $matched2 = preg_match('/=== (\d+) passed, (\d+) failed ===/', $rSkip['out'], $m2);
    is_true($rSkip['exit'] === 0 && $matched2 === 1 && (int) $m2[2] === 0
        && strpos($rSkip['out'], 'SKIP') !== false,
        "FIX: {$file} degrades to a clean SKIP (exit 0, 0 failed) when BOTH shell_exec and proc_open are disabled — never the old exit 255",
        "exit={$rSkip['exit']} tail=" . substr(trim($rSkip['out']), -300));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Static: the two fixed files contain no remaining LIVE @shell_exec( call --\n";
// ─────────────────────────────────────────────────────────────────────────
// Tokenized, not grepped: both files deliberately keep an explanatory
// comment mentioning "@shell_exec(" as example text (documenting the bug
// this fix closes), so a plain substring/grep check would false-positive
// on the comment itself — the exact "comment contains the thing it's
// warning about" trap this project's own CLAUDE.md already documents for
// a different file. A real function-call token sequence (T_STRING
// "shell_exec" immediately followed by "(", NOT inside a comment token)
// is the only thing that actually matters here.
function gh120_has_live_shell_exec_call(string $src): bool {
    $tokens = token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'shell_exec') {
            // Find the next non-whitespace token; a real call has '(' next.
            for ($j = $i + 1; $j < $n; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_WHITESPACE) continue;
                if ($next === '(') return true;
                break;
            }
        }
    }
    return false;
}
foreach (array_keys($fixedFiles) as $file) {
    $src = (string) file_get_contents($base . '/tests/' . $file);
    is_true(!gh120_has_live_shell_exec_call($src),
        "{$file} contains no remaining LIVE shell_exec(...) call (comments mentioning it as example text are fine)");
    is_true(strpos($src, "require_once __DIR__ . '/_test_node_probe.php';") !== false,
        "{$file} requires the shared, hardening-safe helper");
}
// Sanity: the detector itself must actually detect a real call, or the
// two assertions above would pass vacuously.
is_true(gh120_has_live_shell_exec_call('<?php $x = @shell_exec("node -v");'),
    'sanity: the tokenizing detector DOES flag a genuine shell_exec(...) call');
is_true(!gh120_has_live_shell_exec_call('<?php // mentions shell_exec( in a comment only'),
    'sanity: the tokenizing detector does NOT flag the string inside a comment');

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
