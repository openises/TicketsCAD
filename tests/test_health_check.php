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

echo "=== Installation Health Checker Tests (GH #41) ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$lib  = $root . '/inc/health-check.php';

// ── Library loads ─────────────────────────────────────────────
echo "-- Library --\n";

test('inc/health-check.php exists', is_file($lib));

$lintOk = false;
try {
    $out = [];
    $rc  = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lib) . ' 2>&1', $out, $rc);
    $lintOk = ($rc === 0);
} catch (Throwable $e) {
    $lintOk = false;
}
test('inc/health-check.php lints (php -l)', $lintOk);

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
test('dirs check covers cache/zello-audio (recordings dir)', in_array('cache/zello-audio', $paths, true));

$shapeOk = true;
foreach (($dirs['dirs'] ?? []) as $d) {
    foreach (['path', 'exists', 'writable', 'severity'] as $k) {
        if (!array_key_exists($k, $d)) { $shapeOk = false; }
    }
    if (!array_key_exists('owner', $d)) { $shapeOk = false; }
    if (!in_array($d['severity'], ['ok', 'warn', 'critical'], true)) { $shapeOk = false; }
}
test('each dir entry has path/exists/writable/owner/severity', $shapeOk);

// Missing-but-creatable dir must be WARN (works on all platforms).
$missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hc_missing_' . uniqid();
$res = health_check_dirs([$missing]);
$entry = null;
foreach (($res['dirs'] ?? []) as $d) {
    if ($d['path'] === $missing) { $entry = $d; break; }
}
test('missing-but-creatable dir flagged as warn',
    $entry !== null && $entry['exists'] === false && $entry['severity'] === 'warn');

// Exists-but-unwritable dir must be CRITICAL (POSIX chmod semantics).
if (PHP_OS_FAMILY === 'Windows') {
    test('unwritable dir flagged as critical (SKIPPED on Windows — chmod 000 has no effect on NTFS; verified on Linux installs)', true);
} elseif (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    test('unwritable dir flagged as critical (SKIPPED as root — root bypasses mode bits)', true);
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

$cliLintOk = false;
try {
    $out = [];
    $rc  = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($cliFile) . ' 2>&1', $out, $rc);
    $cliLintOk = ($rc === 0);
} catch (Throwable $e) {
    $cliLintOk = false;
}
test('tools/check-health.php lints (php -l)', $cliLintOk);
test('CLI suggests fixes but never executes them (chown echoed, no shell_exec/system/exec of fixes)',
    strpos($cliSrc, 'chown') !== false
    && strpos($cliSrc, 'shell_exec') === false
    && strpos($cliSrc, 'system(') === false
    && strpos($cliSrc, 'exec(') === false);
test('CLI documents CLI-vs-web-user caveat', stripos($cliSrc, 'web user') !== false || stripos($cliSrc, 'web server user') !== false);
test('CLI implements exit-code contract (0/1/2)', strpos($cliSrc, 'exit(2)') !== false && strpos($cliSrc, 'exit(') !== false);

// Run it for real: exit code 0 (ok) or 1 (warnings) is acceptable on a
// healthy dev box; 2 (critical) means this machine genuinely has a
// broken dir/unreadable file and the test surfaces it.
$cliRc  = 255;
$cliOut = [];
try {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cliFile) . ' 2>&1', $cliOut, $cliRc);
} catch (Throwable $e) {
    // leave 255
}
test('CLI runs and exits 0 or 1 on this machine (got ' . $cliRc . ')', $cliRc === 0 || $cliRc === 1);

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

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
