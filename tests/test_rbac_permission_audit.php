<?php
/**
 * RBAC permission-code audit gate (Eric, 2026-08-15).
 *
 * ORIGIN. An external-API integrator reported api/external/v1/incidents.php's
 * GET gate reachable by Super Admin tokens only -- neither
 * action.view_incident nor action.view_incidents had ever been seeded, so no
 * role could be granted read access to the incident list. Eric asked for a
 * wider RBAC security pass. tools/rbac_permission_audit.php, run once
 * app-wide, found 29 more dead-code references to permission codes with no
 * row in the permissions table -- one more genuine single-point failure
 * (the reported one) and 28 instances of the same disease riding silently
 * on a WORKING sibling condition in the same OR-chain (a Super Admin bypass,
 * or a correctly-seeded code alongside the dead one).
 *
 * These tests drive the REAL tool (tools/rbac_permission_audit.php) against
 * fixture trees via --path, rather than re-implementing its matching here --
 * same convention as tests/test_ui_consistency_audit.php. The tool always
 * checks the REAL app's permissions table (config.php is loaded before
 * --path is applied), so fixtures are built around one code known to exist
 * (screen.reports) and one that definitely doesn't.
 *
 * Usage: php tests/test_rbac_permission_audit.php
 *
 * GH #91 follow-up (2026-08-20/21, reported by rjonesbsink): rpa_run() used
 * to shell out via exec(), which is a fatal "Call to undefined function
 * exec()" on any host whose disable_functions blocks it (common on shared
 * or hardened hosting) — quietly turning this whole gate into a no-op
 * instead of actually running the audit. It now spawns the audit via
 * argv-array proc_open() (gh91_proc_run() below — mirrors
 * run_via_proc_open() in tools/check-schema.php and runStreamingImport() in
 * tools/update-lookup-data.php), and the file degrades to an explicit
 * SKIP — never a silent/false pass — when proc_open() itself is also
 * unavailable. See tests/test_gh91_audit_wrapper_subprocess_fallback.php
 * for the regression proof (spawns real PHP subprocesses with
 * disable_functions set both ways).
 *
 * @requires-db
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/rbac_permission_audit.php';

// Loaded before any output, same reason as the tool itself: config.php sets
// session ini directives PHP warns about once a byte has already been sent.
require_once $base . '/config.php';

echo "=== RBAC permission-code audit gate ===\n\n";
$pass = 0;
$fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

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
function gh91_proc_run(array $argv): array {
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

/** Run the audit against a directory; return [exitCode, output]. */
function rpa_run(string $tool, string $path = ''): array {
    $argv = [PHP_BINARY, $tool];
    if ($path !== '') { $argv[] = '--path=' . $path; }
    return gh91_proc_run($argv);
}

if (!function_exists('proc_open')) {
    echo "SKIP: this PHP cannot start a subprocess (proc_open() is disabled via " .
         "disable_functions) — the RBAC permission-code audit could not be run\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// ── Fixtures live outside the repo, so a crash cannot leave a fixture
//    behind for the real tool run below to find ─────────────────────────
$tmp = sys_get_temp_dir() . '/rpa_fixtures_' . getmypid();
$bad = $tmp . '/bad';
$good = $tmp . '/good';
foreach ([$bad, $good] as $d) {
    @mkdir($d . '/api', 0777, true);
    @mkdir($d . '/inc', 0777, true);
}
register_shutdown_function(static function () use ($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

// ═══════════════════════════════════════════════════════════════════════════
// KNOWN-BAD tree: a dead code alone, a dead code in a working OR-chain, and
// the SAME dead code hidden inside a docblock (must not false-positive).
// ═══════════════════════════════════════════════════════════════════════════
file_put_contents($bad . '/api/single-point.php', <<<'PHP'
<?php
/**
 * Illustrative docblock example (must NOT be counted as a reference):
 *   rbac_can('action.totally_fake_never_seeded_xyz')
 */
if (!rbac_can('action.totally_fake_never_seeded_xyz')) {
    json_error('Forbidden', 403);
}
PHP);

file_put_contents($bad . '/api/or-chain.php', <<<'PHP'
<?php
// A dead code riding on a real one -- must still be flagged, even though
// the gate itself works today.
if (!rbac_can('screen.reports') && !rbac_can('action.totally_fake_never_seeded_xyz')) {
    json_error('Forbidden', 403);
}
PHP);

[$code, $out] = rpa_run($tool, $bad);
is_true($code === 1, 'audit exits non-zero on a tree with a dead permission code',
    "exit code was $code");
is_true(
    substr_count($out, "action.totally_fake_never_seeded_xyz") >= 2,
    'both real call sites (single-point and OR-chain) are reported',
    $out
);
is_true(
    (bool) preg_match('/single-point\.php:6/', $out),
    'the finding names the real call site line (6), not a docblock line (2-5)',
    $out
);

// ═══════════════════════════════════════════════════════════════════════════
// KNOWN-GOOD tree: only real, seeded codes referenced.
// ═══════════════════════════════════════════════════════════════════════════
file_put_contents($good . '/api/clean.php', <<<'PHP'
<?php
if (!rbac_can('screen.reports')) {
    json_error('Forbidden', 403);
}
PHP);

[$gcode, $gout] = rpa_run($tool, $good);
is_true($gcode === 0, 'audit stays silent on a tree that only references real codes',
    "exit code $gcode; output:\n" . $gout);

// ═══════════════════════════════════════════════════════════════════════════
// The real app tree: every finding from the 2026-08-15 pass was fixed in
// the same commit that added this gate, so the baseline stays empty.
// ═══════════════════════════════════════════════════════════════════════════
[$rcode, $rout] = rpa_run($tool);
$tail = implode("\n", array_slice(explode("\n", $rout), -30));
is_true($rcode === 0, 'no NEW dead permission-code references in the app tree', $tail);

$baselineFile = $base . '/tools/rbac_permission_audit_baseline.txt';
is_true(is_file($baselineFile), 'the baseline file exists');
if (is_file($baselineFile)) {
    $entries = [];
    foreach (file($baselineFile) as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] !== '#') { $entries[] = $l; }
    }
    is_true($entries === [], 'the baseline is empty (every finding was fixed, not grandfathered)',
        count($entries) . ' entries present');
}

// The tool must refuse to run under a web SAPI.
$src = (string) file_get_contents($tool);
is_true(
    strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }") !== false,
    "$tool carries the canonical CLI-only guard"
);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
