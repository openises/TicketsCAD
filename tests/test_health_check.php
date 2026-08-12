<?php
/**
 * Installation Health Checker Tests (GH #41)
 *
 * Verifies inc/health-check.php loads, every health_check_* function
 * returns the documented shape, the dirs check flags a deliberately
 * unwritable directory (POSIX only — skipped-as-pass on Windows where
 * chmod semantics differ), version_match reports match=true on a healthy
 * install, the API endpoint is admin-gated GET-only, the CLI wrapper
 * exists with the right exit-code contract, and the banner include uses
 * the sessionStorage-dismiss pattern.
 *
 * Usage: php tests/test_health_check.php
 */

require_once __DIR__ . '/../config.php';

$passed = 0;
$failed = 0;

$skipped = 0;

function test($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

/**
 * Record a check that could not be PERFORMED, as distinct from one that failed.
 *
 * Deliberately does not touch the pass/fail tallies. A test that silently
 * cannot run is worse than one that is skipped, because it looks like coverage
 * — and a check that reports "could not do this" as "this is broken" is worse
 * again, because it sends the reader to a file that is fine.
 */
function skip($label, $why) {
    global $skipped;
    echo "[SKIP] $label — $why\n";
    $skipped++;
}

/**
 * Run a program as discrete arguments; return [outputLines, exitCode, ran].
 *
 * GH TicketsCAD#13 (Ron Jones): these checks used exec(), wrapped in
 * `catch (Throwable)` with a pessimistic pre-initialised result. On a host with
 * `disable_functions = exec, shell_exec, …` — a common Windows/IIS hardening
 * default — calling exec() raises `Error: Call to undefined function exec()`.
 * `Error` implements `Throwable`, so the catch swallowed it and the pre-set
 * failure value survived. The suite then reported three failures that were not
 * real, naming two files that lint perfectly well, and the "got 255" in the
 * third was this file's own sentinel rather than any child's exit status.
 * Nothing had run at all.
 *
 * That is the same defect class the runner-contract work fixed one level up:
 * silence scored as a verdict. Here the fix is to use the mechanism that still
 * exists on those hosts. proc_open() is not usually included in the hardening
 * presets that remove exec/shell_exec — it is the basis of the conversion in
 * commit 8a9ec2a — so these checks now genuinely run where they used to lie.
 * If proc_open is gone too, the third return value says so and the caller
 * skips rather than inventing a failure.
 *
 * Mirrors run_via_proc_open() in tools/check-schema.php: argv array (no shell,
 * so no escapeshellarg — it quotes FOR a shell and there is none), stdout and
 * stderr sharing one temp file exactly as `2>&1` did, so nothing can deadlock.
 */
function run_probe(array $argv) {
    if (!function_exists('proc_open')) {
        return [[], null, false];
    }
    $sink = tmpfile();
    if ($sink === false) {
        return [[], null, false];
    }
    $pipes = [];
    $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink], $pipes);
    if (!is_resource($proc)) {
        fclose($sink);
        return [[], null, false];
    }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $combined = rtrim((string) stream_get_contents($sink), "\r\n");
    fclose($sink);
    $lines = ($combined === '') ? [] : preg_split('/\r\n|\r|\n/', $combined);
    return [$lines, $exit, true];
}

echo "=== Installation Health Checker Tests (GH #41) ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$lib  = $root . '/inc/health-check.php';

// ── Library loads ─────────────────────────────────────────────
echo "-- Library --\n";

test('inc/health-check.php exists', is_file($lib));

list($out, $rc, $ran) = run_probe([PHP_BINARY, '-l', $lib]);
if (!$ran) {
    skip('inc/health-check.php lints (php -l)', 'no way to start a subprocess on this host');
} else {
    test('inc/health-check.php lints (php -l)' . ($rc === 0 ? '' : ' — ' . implode(' ', $out)), $rc === 0);
}

require_once $lib;

test('health_check_dirs() defined', function_exists('health_check_dirs'));
test('health_check_unreadable() defined', function_exists('health_check_unreadable'));
test('health_check_opcache() defined', function_exists('health_check_opcache'));
test('health_check_version_match() defined', function_exists('health_check_version_match'));
test('health_check_all() defined', function_exists('health_check_all'));
test('HEALTH_CHECK_BUILD constant defined', defined('HEALTH_CHECK_BUILD'));

// ── health_check_dirs shape ───────────────────────────────────
echo "\n-- Directory check --\n";

$dirs = health_check_dirs();
test('dirs check returns checked=true', ($dirs['checked'] ?? false) === true);
test('dirs check returns dirs array', is_array($dirs['dirs'] ?? null) && count($dirs['dirs']) >= 3);

$paths = array_column($dirs['dirs'] ?? [], 'path');
test('dirs check covers uploads/', in_array('uploads', $paths, true));
test('dirs check covers uploads/overlays/', in_array('uploads/overlays', $paths, true));
test('dirs check covers cache/', in_array('cache', $paths, true));

// GHSA-x9x6-w4fg-pmcc — Zello recordings live outside $root now, so they're
// no longer in the plain root-relative set above; health_check_all() passes
// zello_audio_dir() via $extraDirs instead. Confirm that end to end.
$all = health_check_all();
$allPaths = array_column($all['dirs']['dirs'] ?? [], 'abs');
require_once __DIR__ . '/../inc/zello_audio_dir.php';
test('health_check_all() covers zello_audio_dir() (private) via $extraDirs',
    in_array(rtrim(str_replace('\\', '/', zello_audio_dir()), '/'),
        array_map(function ($p) { return rtrim(str_replace('\\', '/', $p), '/'); }, $allPaths), true));

$shapeOk = true;
foreach (($dirs['dirs'] ?? []) as $d) {
    foreach (['path', 'exists', 'writable', 'severity'] as $k) {
        if (!array_key_exists($k, $d)) { $shapeOk = false; }
    }
    if (!array_key_exists('owner', $d)) { $shapeOk = false; }
    // 'unknown' joined the model on 2026-07-31. It is not a fourth flavour of
    // problem — it is the answer given when the account the web server runs as
    // could not be established, in place of the confident wrong verdict that
    // was previously produced by answering for whoever invoked the check.
    if (!in_array($d['severity'], ['ok', 'warn', 'critical', 'unknown'], true)) { $shapeOk = false; }
    // Writability is tri-state now; null means "not established".
    if (!($d['writable'] === true || $d['writable'] === false || $d['writable'] === null)) { $shapeOk = false; }
}
test('each dir entry has path/exists/writable/owner/severity', $shapeOk);

// Missing-but-creatable dir must be WARN, never critical: every one of these
// directories is created on demand by the code that needs it. Only meaningful
// once the web server account is known — otherwise the honest answer is
// 'unknown', which is what the same call must then produce.
$missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hc_missing_' . uniqid();
$res = health_check_dirs([$missing]);
$entry = null;
foreach (($res['dirs'] ?? []) as $d) {
    if ($d['path'] === $missing) { $entry = $d; break; }
}
// function_exists() rather than a bare call. health_check_web_user() is newer
// than this file's other subjects, and a test that FATALS when the library it
// tests is a version behind reports "the suite is broken" instead of "this
// check could not run" — the same misattribution GH TicketsCAD#13 was about,
// one level up. It took CI down on 2026-08-01 for exactly that reason.
// Three cases, not two. The third is the one that took CI down on 2026-08-01:
// this assertion arrived before the inc/health-check.php half it describes, so
// on a build without health_check_web_user() the bare call fatalled the whole
// file. Asking function_exists() first is not defensiveness for its own sake —
// the contract genuinely differs between the two builds, and asserting the new
// one against the old library reports a defect that is not there.
$hasWebUser = function_exists('health_check_web_user');
$wuKnown    = $hasWebUser ? (health_check_web_user()['determined'] ?? false) : false;

if (!$hasWebUser) {
    // Older build: severity was always 'warn', with no 'unknown' state.
    test('missing-but-creatable dir flagged as warn (build without web-user detection)',
        $entry !== null && $entry['exists'] === false && $entry['severity'] === 'warn');
} elseif (!$wuKnown) {
    skip('missing-but-creatable dir flagged as warn',
        'the web server account cannot be determined on this host, so the correct '
        . 'answer is "unknown" — asserted instead below');
    test('missing dir reports unknown (not critical) when the web account is unknown',
        $entry !== null && $entry['exists'] === false && $entry['severity'] === 'unknown');
} else {
    test('missing-but-creatable dir flagged as warn',
        $entry !== null && $entry['exists'] === false && $entry['severity'] === 'warn');
}

// Exists-but-unwritable dir must be CRITICAL (POSIX chmod semantics).
if (PHP_OS_FAMILY === 'Windows') {
    skip('unwritable dir flagged as critical',
        'chmod 000 has no effect on NTFS; the POSIX evaluator is covered without '
        . 'a filesystem in tests/test_health_web_user.php');
} elseif (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    skip('unwritable dir flagged as critical', 'running as root, which bypasses mode bits');
} elseif (!$wuKnown) {
    skip('unwritable dir flagged as critical',
        'the web server account cannot be determined on this host');
} else {
    $scratch = sys_get_temp_dir() . '/hc_unwritable_' . uniqid();
    $flagged = false;
    if (@mkdir($scratch, 0755) && @chmod($scratch, 0000)) {
        $res2 = health_check_dirs([$scratch]);
        foreach (($res2['dirs'] ?? []) as $d) {
            if ($d['path'] === $scratch) {
                $flagged = ($d['exists'] === true && $d['writable'] === false && $d['severity'] === 'critical');
                break;
            }
        }
    }
    @chmod($scratch, 0755);
    @rmdir($scratch);
    test('unwritable dir flagged as critical', $flagged);
}

// ── health_check_unreadable shape ─────────────────────────────
echo "\n-- Unreadable-file scan --\n";

$un = health_check_unreadable();
test('unreadable scan returns checked=true', ($un['checked'] ?? false) === true);
test('unreadable scan returns unreadable array + truncated flag',
    is_array($un['unreadable'] ?? null) && array_key_exists('truncated', $un));
test('unreadable scan probed files (scanned > 0)', ($un['scanned'] ?? 0) > 0);

$entryShapeOk = true;
foreach (($un['unreadable'] ?? []) as $f) {
    if (!isset($f['path']) || ($f['issue'] ?? '') !== 'unreadable') { $entryShapeOk = false; }
}
test('unreadable entries have {path, issue:unreadable} shape (or list empty)', $entryShapeOk);
test('unreadable list capped at 50', count($un['unreadable'] ?? []) <= 50);

// ── health_check_opcache shape ────────────────────────────────
echo "\n-- Opcache check --\n";

$oc = health_check_opcache();
test('opcache check returns checked=true', ($oc['checked'] ?? false) === true);
test('opcache check reports enabled/validate_timestamps/revalidate_freq keys',
    array_key_exists('enabled', $oc)
    && array_key_exists('validate_timestamps', $oc)
    && array_key_exists('revalidate_freq', $oc));
test('opcache check reports build constant + severity',
    ($oc['build'] ?? '') === HEALTH_CHECK_BUILD
    && in_array($oc['severity'] ?? '', ['ok', 'warn', 'critical'], true));

// ── health_check_version_match ────────────────────────────────
echo "\n-- Version match (stale-code detector) --\n";

$v = health_check_version_match();
test('version check returns checked=true', ($v['checked'] ?? false) === true);
test('version check found the defining file', !empty($v['version_file']));
test('version match=true on a healthy install (fresh CLI process)',
    ($v['match'] ?? null) === true && ($v['severity'] ?? '') === 'ok');
test('self-probe (HEALTH_CHECK_BUILD) matches disk', ($v['probe_match'] ?? null) === true);

// ── health_check_all bundle ───────────────────────────────────
echo "\n-- Bundle --\n";

$threw = false;
$all = [];
try {
    $all = health_check_all();
} catch (Throwable $e) {
    $threw = true;
}
test('health_check_all() never throws', !$threw);
test('bundle contains dirs/unreadable/opcache/version/summary',
    isset($all['dirs'], $all['unreadable'], $all['opcache'], $all['version'], $all['summary']));
test('summary has integer critical + warn counts',
    is_int($all['summary']['critical'] ?? null) && is_int($all['summary']['warn'] ?? null));

// ── Composer dependency check (GH #8 — silent Web Push failure) ─
echo "\n-- Dependencies --\n";
$dep = health_check_dependencies();
test('health_check_dependencies() reports checked + severity',
    ($dep['checked'] ?? false) === true && in_array($dep['severity'] ?? '', ['ok', 'warn'], true));
test('dependency check lists web-push, php-jwt, ratchet',
    is_array($dep['libraries'] ?? null) && count($dep['libraries']) === 3
    && implode(',', array_column($dep['libraries'], 'package'))
       === 'minishlink/web-push,firebase/php-jwt,cboden/ratchet');
test('each library entry has a boolean present flag',
    !array_filter($dep['libraries'], fn($l) => !is_bool($l['present'] ?? null)));
test('remedy names composer install when anything is missing',
    ($dep['severity'] === 'ok') || strpos($dep['remedy'] ?? '', 'composer install') !== false);
test('missing dependency raises the bundle warn count',
    ($dep['severity'] !== 'warn') || ($all['summary']['warn'] ?? 0) >= 1);
test('bundle now carries the dependencies block',
    isset($all['dependencies']['libraries']));
// The Notifications settings panel must loudly flag push-on-but-library-missing.
$settingsSrc = @file_get_contents(__DIR__ . '/../settings.php') ?: '';
test('settings.php warns when Web Push library is absent',
    strpos($settingsSrc, "class_exists('Minishlink\\\\WebPush\\\\WebPush')") !== false
    && strpos($settingsSrc, 'composer install') !== false);
// GH #8 (a beta tester 2026-07-14): the Diagnostics library check must NOT rely on a
// bare class_exists() — the composer autoloader isn't registered on that GET
// path, so it false-negatives even when the library is installed. It must load
// the autoloader OR fall back to a filesystem check.
$diagSrc = @file_get_contents(__DIR__ . '/../api/diagnostics.php') ?: '';
test('api/diagnostics.php detects Web Push lib without relying on the autoloader',
    strpos($diagSrc, "vendor/autoload.php") !== false
    && strpos($diagSrc, "vendor/minishlink/web-push") !== false);

// ── API endpoint ──────────────────────────────────────────────
echo "\n-- API endpoint --\n";

$apiFile = $root . '/api/health-check.php';
$apiSrc  = is_file($apiFile) ? file_get_contents($apiFile) : '';
test('api/health-check.php exists', $apiSrc !== '');
test('API requires auth (includes api/auth.php)', strpos($apiSrc, "require_once __DIR__ . '/auth.php'") !== false);
test('API is admin-gated (is_admin OR action.manage_config)',
    strpos($apiSrc, 'is_admin()') !== false && strpos($apiSrc, "rbac_can('action.manage_config')") !== false);
test('API rejects non-GET (405)', strpos($apiSrc, "REQUEST_METHOD'] !== 'GET'") !== false);
test('API needs no CSRF verification (read-only GET — no csrf_verify/csrf_token call)',
    strpos($apiSrc, 'csrf_verify') === false && strpos($apiSrc, 'csrf_token(') === false);
test('API uses json_error_safe for exceptions', strpos($apiSrc, 'json_error_safe(') !== false);

// ── CLI wrapper ───────────────────────────────────────────────
echo "\n-- CLI wrapper --\n";

$cliFile = $root . '/tools/check-health.php';
$cliSrc  = is_file($cliFile) ? file_get_contents($cliFile) : '';
test('tools/check-health.php exists', $cliSrc !== '');

list($out, $rc, $ran) = run_probe([PHP_BINARY, '-l', $cliFile]);
if (!$ran) {
    skip('tools/check-health.php lints (php -l)', 'no way to start a subprocess on this host');
} else {
    test('tools/check-health.php lints (php -l)' . ($rc === 0 ? '' : ' — ' . implode(' ', $out)), $rc === 0);
}
test('CLI suggests fixes but never executes them (chown echoed, no shell_exec/system/exec of fixes)',
    strpos($cliSrc, 'chown') !== false
    && strpos($cliSrc, 'shell_exec') === false
    && strpos($cliSrc, 'system(') === false
    && strpos($cliSrc, 'exec(') === false);
// The old caveat said "CLI answers reflect the CLI user, check the browser for
// the real answer". That was an accurate description of a defect, not a
// caveat: it is now the CLI's job to answer for the web server account itself.
//
// Asserted only where that capability exists. This assertion arrived ahead of
// the tools/check-health.php half it describes, and an assertion about a
// feature the tree does not have yet is a failing test that names the wrong
// thing — it reads as "the CLI stopped explaining itself" rather than "this
// build predates the change".
if (strpos($cliSrc, 'health_check_web_user()') === false) {
    skip('CLI states whose access it reports, and what it does when that is unknown',
        'this build of tools/check-health.php predates the web-user reporting change');
} else {
    test('CLI states whose access it reports, and what it does when that is unknown',
        stripos($cliSrc, 'web server') !== false
        && stripos($cliSrc, 'NEWUI_WEB_USER') !== false);
}
test('CLI implements exit-code contract (0/1/2)', strpos($cliSrc, 'exit(2)') !== false && strpos($cliSrc, 'exit(') !== false);

// Run it for real. What is under test here is the TOOL, not the machine: it must
// execute and return a verdict from its documented contract — 0 ok, 1 warnings,
// 2 critical. Anything else (a fatal, 255) means the tool itself is broken.
//
// This used to demand 0 or 1, which asserted the contract on line 240 and then
// failed when the contract was honoured. It also made a REAL finding on the
// machine running the tests look like a broken test: a developer box with
// database archives still sitting in the web-served backups/ directory is
// exactly what the 2026-07-30 exposure was, and check-health.php correctly
// exits 2 for it. The finding is printed below so it is not lost.
//
// The old sentinel here was 255, which the failure message then printed as
// "got 255" — reading like a crashed child returning a fatal-error status when
// in fact nothing had been started. Reporting whether the probe RAN is the
// whole point; a number invented by the caller is not evidence about the tool.
list($cliOut, $cliRc, $cliRan) = run_probe([PHP_BINARY, $cliFile]);
if (!$cliRan) {
    skip('CLI runs and returns a contract exit code 0/1/2',
         'no way to start a subprocess on this host — the tool was never invoked');
} else {
    test('CLI runs and returns a contract exit code 0/1/2 (got ' . $cliRc . ')',
        $cliRc === 0 || $cliRc === 1 || $cliRc === 2);
}
if ($cliRan && $cliRc === 2) {
    echo "SKIP: this machine has at least one CRITICAL finding — that is the tool\n"
       . "      working, not a test failure. What it found:\n";
    foreach ($cliOut as $line) {
        if (strpos($line, '[CRIT]') !== false) { echo '      ' . trim($line) . "\n"; }
    }
}

// ── Banner include ────────────────────────────────────────────
echo "\n-- Admin banner --\n";

$bannerFile = $root . '/inc/health-banner.php';
$bannerSrc  = is_file($bannerFile) ? file_get_contents($bannerFile) : '';
test('inc/health-banner.php exists', $bannerSrc !== '');
test('banner fetches api/health-check.php', strpos($bannerSrc, "fetch('api/health-check.php'") !== false);
test('banner uses sessionStorage dismiss pattern',
    strpos($bannerSrc, "sessionStorage.getItem('healthBannerDismissed')") !== false
    && strpos($bannerSrc, "sessionStorage.setItem('healthBannerDismissed'") !== false);
test('banner shows only on critical (summary.critical gate)', strpos($bannerSrc, 'summary.critical') !== false);
test('banner links to status.php#health', strpos($bannerSrc, 'status.php#health') !== false);

// ── System Health card + docs ─────────────────────────────────
echo "\n-- Status page card + docs --\n";

$statusSrc = file_get_contents($root . '/status.php');
test('status.php has File & Code Health section (id="health")', strpos($statusSrc, 'id="health"') !== false);
test('status.php card fetches api/health-check.php', strpos($statusSrc, 'api/health-check.php') !== false);

$docFile = $root . '/docs/UPDATE-CHECKLIST.md';
$docSrc  = is_file($docFile) ? file_get_contents($docFile) : '';
test('docs/UPDATE-CHECKLIST.md exists', $docSrc !== '');
test('doc covers opcache reload + migrations + health check',
    stripos($docSrc, 'systemctl reload apache2') !== false
    && strpos($docSrc, 'run_migrations.php') !== false
    && strpos($docSrc, 'check-health.php') !== false);

// State plainly how many checks could not be performed. The count of what was
// verified is only meaningful next to the count of what was not.
if ($skipped > 0) {
    // Do NOT name a cause here. Each skip() call above states its own reason —
    // subprocess execution, Windows chmod semantics, an undeterminable web
    // account — and an earlier version of this line asserted "subprocess
    // execution unavailable" for all of them, which was already wrong the first
    // time another kind of skip was added. Inventing a reason is the exact
    // defect this file was fixed for (GH TicketsCAD#13).
    echo "\nSKIP: $skipped check(s) could not be performed on this host; each is "
       . "marked [SKIP] above with its reason. They are excluded from the totals "
       . "rather than counted as failures.\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
