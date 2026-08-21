<?php
/**
 * Gate: tools/update-lookup-data.php extracts via PHP ZipArchive FIRST, and
 * explains itself plainly if the shell fallback is ever reached and blocked.
 *
 * ── THE BUG (openises/TicketsCAD #93, reported by @rjonesbsink) ────────────
 *
 * extractZip() used to try `unzip` and PowerShell via exec() FIRST, with
 * ZipArchive only as a last resort. On any PHP where a hardened host's
 * `disable_functions` directive removes exec() — a common hardening setting,
 * completely unrelated to whether unzip exists or the download is intact —
 * calling exec() is a fatal "Call to undefined function", which PHP's `@`
 * operator does not suppress. The script died at extraction with a bare
 * "exit 255" and no message, after a perfectly good 188MB download, and
 * nothing said why. The reporter's own environment had exactly:
 *
 *     disable_functions = shell_exec, exec, system, passthru, popen
 *
 * ── THE FIX THIS FILE PROVES ────────────────────────────────────────────
 *
 *   1. ZipArchive (native, in-process, unaffected by disable_functions) is
 *      tried FIRST. Under the reporter's exact disable_functions setting,
 *      extraction now succeeds via ZipArchive without ever touching exec().
 *   2. The shell fallback (unzip / PowerShell) is reached only when
 *      ZipArchive itself is unavailable. Before calling exec() at all, it
 *      checks function_exists('exec') — both to avoid the fatal above, and
 *      to report the real cause ("disable_functions blocks exec()") instead
 *      of a generic "no working extraction method found".
 *   3. The docblock's stale size estimates (~90MB/~15MB/~500MB) are refreshed
 *      to the real, verified figures from the issue (~189MB/~54MB/~1.2GB).
 *
 * ── WHY SUBPROCESSES, NOT MOCKS ─────────────────────────────────────────
 *
 * `disable_functions` is an ini directive resolved once at PHP startup —
 * function_exists('exec') cannot be faked in-process (PHP does not allow
 * redefining or undefining a builtin mid-run). So this file spawns real `php`
 * subprocesses with `-d disable_functions=...` (and, for the
 * ZipArchive-unavailable case, `-n` to skip loading the zip extension too),
 * matching this codebase's own established technique for proving a runtime
 * condition rather than asserting it from source text alone. See
 * tools/check-schema.php's run_via_proc_open() / tests/test_runner_end_to_end.php
 * for the sibling "spawn a real PHP to prove it" pattern.
 *
 * tools/update-lookup-data.php exposes its functions for testing via an
 * UPDATE_LOOKUP_DATA_LIBRARY_ONLY guard (added by this fix) — the same
 * pattern documented in CLAUDE.md for api/owntracks-config.php's
 * OT_CONFIG_LIBRARY_ONLY: reusable/testable logic must not be permanently
 * buried behind a script's own top-level execution.
 *
 * Usage: php tests/test_gh93_lookup_data_extraction.php
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

// ── Scratch workspace (portable — sys_get_temp_dir(), not a session path) ──
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh93_lookup_' . uniqid();
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

// ── Build a tiny, real ZIP fixture — one top-level file + one nested file,
// mirroring how FCC archives nest EN.dat/HD.dat one directory down. ────────
$fixtureZip = $work . DIRECTORY_SEPARATOR . 'fixture.zip';
$zip = new ZipArchive();
test('can create the test fixture .zip with ZipArchive',
    $zip->open($fixtureZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
$zip->addFromString('TOP.dat', "top-level-fixture-content\n");
$zip->addFromString('sub/NESTED.dat', "nested-fixture-content\n");
$zip->close();
test('fixture .zip was actually written to disk', filesize($fixtureZip) > 0);

// ── PHP harness: loads update-lookup-data.php in library mode and calls
// extractZip() directly, so we exercise the REAL function, not a copy. ─────
$harness = $work . DIRECTORY_SEPARATOR . 'harness.php';
file_put_contents($harness, <<<'HARNESS'
<?php
define('UPDATE_LOOKUP_DATA_LIBRARY_ONLY', true);
require $argv[1];
$ok = extractZip($argv[2], $argv[3]);
echo "\n___RESULT___" . ($ok ? '1' : '0') . "___\n";
HARNESS
);

/**
 * Run a php subprocess via argv-array proc_open (no shell — same shape as
 * tools/check-schema.php's run_via_proc_open()). Returns [combinedOutput, exitCode].
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

function extract_result(string $output): ?bool
{
    if (preg_match('/___RESULT___([01])___/', $output, $m) === 1) {
        return $m[1] === '1';
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════
// Test A — baseline: default php.ini (ZipArchive available), no flags.
// Proves plain extraction correctness before touching disable_functions.
// ═══════════════════════════════════════════════════════════════════════
$destA = $work . DIRECTORY_SEPARATOR . 'outA';
[$outA, $exitA] = run_php_subprocess([], [$harness, $target, $fixtureZip, $destA]);
test('A: subprocess ran the harness cleanly (exit 0)', $exitA === 0, "exit=$exitA output=$outA");
test('A: extractZip() reports success', extract_result($outA) === true, $outA);
test('A: extraction used ZipArchive (not a shell fallback)',
    strpos($outA, 'Extracted with PHP ZipArchive') !== false, $outA);
test('A: TOP.dat was actually extracted with correct content',
    is_file($destA . '/TOP.dat') && trim((string) file_get_contents($destA . '/TOP.dat')) === 'top-level-fixture-content');
test('A: nested sub/NESTED.dat was actually extracted with correct content',
    is_file($destA . '/sub/NESTED.dat') && trim((string) file_get_contents($destA . '/sub/NESTED.dat')) === 'nested-fixture-content');

// ═══════════════════════════════════════════════════════════════════════
// Test B — THE CORE REGRESSION PROOF: the reporter's EXACT disable_functions
// setting, with ZipArchive still available (the ordinary case on a hardened
// host that also has php-zip). Extraction must succeed via ZipArchive alone,
// never touching exec() — this is the scenario that used to exit 255 with
// no message before the ZipArchive-first reorder.
// ═══════════════════════════════════════════════════════════════════════
$destB = $work . DIRECTORY_SEPARATOR . 'outB';
$disableFlags = ['-d', 'disable_functions=shell_exec,exec,system,passthru,popen'];
[$outB, $exitB] = run_php_subprocess($disableFlags, [$harness, $target, $fixtureZip, $destB]);
test('B: subprocess ran cleanly under the reporter\'s exact disable_functions (exit 0)',
    $exitB === 0, "exit=$exitB output=$outB");
test('B: extractZip() still reports success with exec() disabled',
    extract_result($outB) === true, $outB);
test('B: extraction still used ZipArchive under disable_functions (the actual fix)',
    strpos($outB, 'Extracted with PHP ZipArchive') !== false, $outB);
test('B: no fatal error / undefined-function crash occurred',
    stripos($outB, 'Fatal error') === false && stripos($outB, 'Call to undefined function') === false, $outB);
test('B: extracted content is correct under disable_functions',
    is_file($destB . '/TOP.dat') && trim((string) file_get_contents($destB . '/TOP.dat')) === 'top-level-fixture-content');

// ═══════════════════════════════════════════════════════════════════════
// Test C — worst case: ZipArchive unavailable AND exec() disabled. This
// must fail GRACEFULLY with the explicit disable_functions message, never
// a silent/fatal crash.
//
// -n skips loading php.ini entirely (which is how the zip extension gets
// left out — there is no portable "disable just this one extension" ini
// flag), but on a modular-extension Linux build that also takes PDO out
// with it, and config.php's require chain does a DB timezone lookup that
// only catches Exception — not the bare Error a missing PDO class throws
// — so it fatals before extractZip() is ever reached, proving nothing
// about the fix. Re-enabling mysqlnd/pdo/pdo_mysql explicitly alongside
// -n keeps PDO available (config.php loads normally) while zip stays out,
// which is also the more realistic shape of a real disable_functions host
// anyway: exec() and php-zip are independent settings, but PDO is not
// something a working install would ever be missing. Harmless on a build
// where PDO is compiled in statically (no-op, may warn to stderr about an
// extension_dir it can't find — irrelevant, checked for below only via
// specific substrings, not "zero warnings").
// ═══════════════════════════════════════════════════════════════════════
$destC = $work . DIRECTORY_SEPARATOR . 'outC';
$noZipFlags = ['-n', '-d', 'extension=mysqlnd', '-d', 'extension=pdo', '-d', 'extension=pdo_mysql'];
[$outC, $exitC] = run_php_subprocess(array_merge($noZipFlags, $disableFlags), [$harness, $target, $fixtureZip, $destC]);
test('C: subprocess ran (did not crash the PHP process itself)', $exitC === 0 || $exitC === 1, "exit=$exitC output=$outC");
test('C: extractZip() reports failure (as it must — no extraction method exists)',
    extract_result($outC) === false, $outC);
test('C: failure message explicitly names disable_functions/exec() as the cause',
    strpos($outC, 'disable_functions blocks exec()') !== false, $outC);
test('C: no fatal "Call to undefined function" crash — the function_exists() guard worked',
    stripos($outC, 'Fatal error') === false && stripos($outC, 'Call to undefined function') === false, $outC);

// ═══════════════════════════════════════════════════════════════════════
// Test D — structural: ZipArchive is tried before exec() in source order,
// so this can't silently regress back to the old (broken) ordering.
// ═══════════════════════════════════════════════════════════════════════
$src = (string) file_get_contents($target);
$fnStart = strpos($src, 'function extractZip(');
test('D: extractZip() function found in source', $fnStart !== false);
$zipArchivePos = strpos($src, "class_exists('ZipArchive')", $fnStart === false ? 0 : $fnStart);
$execCallPos   = strpos($src, "exec(\$cmd", $fnStart === false ? 0 : $fnStart);
test('D: both ZipArchive check and the unzip exec() call are present to compare',
    $zipArchivePos !== false && $execCallPos !== false);
test('D: ZipArchive is checked BEFORE the shell exec() fallback in source order',
    $zipArchivePos !== false && $execCallPos !== false && $zipArchivePos < $execCallPos);

// ═══════════════════════════════════════════════════════════════════════
// Test E — docblock size figures were refreshed (no stale ~90MB/~15MB/~500MB).
// ═══════════════════════════════════════════════════════════════════════
test('E: stale ~90MB amateur estimate is gone', strpos($src, '~90MB') === false);
test('E: stale ~15MB GMRS estimate is gone', strpos($src, '~15MB') === false);
test('E: stale ~500MB free-disk estimate is gone', strpos($src, '~500MB') === false);
test('E: refreshed amateur size (~189MB) is present', strpos($src, '189MB') !== false);
test('E: refreshed GMRS size (~54MB) is present', strpos($src, '54MB') !== false);
test('E: refreshed peak-disk figure (~1.2GB) is present', strpos($src, '1.2GB') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
