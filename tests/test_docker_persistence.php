<?php
/**
 * Docker persistence tests — everything the app WRITES must be on a volume.
 *
 * Regression gate for the data-loss defect found by the mgitnix/mgitwin video
 * QA (findings C4 / C3): `backups/` was not mounted, so an admin who followed
 * the documented Docker update — take a backup, then
 * `docker compose up -d --build` — destroyed the backup with the container in
 * the very same breath. The database survived (db_data is a volume); the
 * restore point did not.
 *
 * The rule this file enforces: for every directory the PHP code writes to,
 * docker-compose.yml must mount something at the container path. Adding a new
 * write path without a volume now fails the suite instead of silently eating
 * a user's data six months later.
 *
 * Usage: php tests/test_docker_persistence.php
 */

require_once __DIR__ . '/../config.php';

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

echo "=== Docker persistence tests (no write path off-volume) ===\n\n";

$root    = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$compose = (string) @file_get_contents($root . '/docker-compose.yml');
$entry   = (string) @file_get_contents($root . '/docker-entrypoint.sh');

test('docker-compose.yml exists', $compose !== '');
test('docker-entrypoint.sh exists', $entry !== '');

// ── Where does the code actually write? ───────────────────────
// Derived from the constants, not from memory: if BACKUP_DIR or FE_KEYS_DIR
// ever moves, this test follows it to the new place.
require_once $root . '/inc/backup.php';
require_once $root . '/inc/field-encrypt.php';
require_once $root . '/inc/served-dir.php';
require_once $root . '/inc/tile-proxy.php';
require_once $root . '/inc/geocode.php';
require_once $root . '/inc/zello_audio_dir.php';

$norm = function (string $p): string {
    return rtrim(str_replace('\\', '/', $p), '/');
};
$rootN = $norm($root);

/** Map a host path under (or beside) the app root to its container path. */
$toContainer = function (string $abs) use ($norm, $rootN): ?string {
    $abs = $norm($abs);
    // Collapse a trailing "/.." segment (FE_KEYS_DIR is NEWUI_ROOT . '/../keys').
    $abs = preg_replace('#/[^/]+/\.\./#', '/', $abs . '/');
    $abs = rtrim((string) $abs, '/');
    if (strpos($abs, $rootN . '/') === 0) {
        return '/var/www/html/' . substr($abs, strlen($rootN) + 1);
    }
    $parent = $norm(dirname($rootN));
    if (strpos($abs, $parent . '/') === 0) {
        return '/var/www/' . substr($abs, strlen($parent) + 1);
    }
    return null;
};

// The image is Linux whatever the developer's laptop is, so the backup path is
// asked for explicitly as the POSIX default rather than read from BACKUP_DIR.
// Since 2026-08-02 that constant is platform-aware — on a Windows workstation it
// resolves to %ProgramData%\TicketsCAD\backups, which has no container path and
// would fail this test for a reason that has nothing to do with Docker. Still
// derived from the code, not a literal: backup_default_dir_for() is the same
// function the constant is defined from.
// FE_KEYS_DIR is asked for the same way, and for the same reason, since
// 2026-08-03 (GHSA-3jmh-c6f6-64jc): it is platform-aware too, AND it may resolve
// to wherever a given machine's existing keys already are. Reading the constant
// happened to work on a Windows box with keys beside the checkout and would fail
// on one without them — a Docker test failing for a reason that has nothing to
// do with Docker. fe_default_keys_dir_for() is the same function the constant is
// built from.
// TILE_CACHE_DIR, GEOCODE_CACHE_DIR, and zello_audio_dir() joined the
// platform-aware group on 2026-08-14 (the round-2 zello-audio IIS exposure
// and its sweep of the same pattern elsewhere) — same reasoning, same fix:
// ask served_dir_above_root() / zello_audio_dir() for the POSIX answer
// explicitly rather than reading a constant this Windows box has already
// resolved to %ProgramData%.
$writePaths = [
    'uploads'                        => $root . '/uploads',
    'cache'                          => $root . '/cache',
    'backups (BACKUP_DIR)'           => backup_default_dir_for($root, false),
    'keys (FE_KEYS_DIR)'             => fe_default_keys_dir_for($root, false),
    'tiles (TILE_CACHE_DIR)'         => served_dir_above_root($root, 'tile-cache', false),
    'geocode cache (GEOCODE_CACHE_DIR)' => served_dir_above_root($root, 'geocode-cache', false),
    'zello audio (zello_audio_dir)'  => zello_audio_dir($root, false),
];

// Container paths mounted by the `app` service.
preg_match('/\n  app:\n(.*?)(?=\n  [a-z0-9_-]+:\n|\nvolumes:)/s', $compose, $m);
$appBlock = $m[1] ?? '';
test('found the app service block in docker-compose.yml', $appBlock !== '');

$mounted = [];
if (preg_match('/volumes:\n(.*?)(?=\n    [a-z_]+:|\Z)/s', $appBlock, $vm)) {
    foreach (explode("\n", $vm[1]) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '-') {
            continue;
        }
        $spec = trim(substr($line, 1));
        $spec = trim(explode('#', $spec)[0]);          // strip inline comment
        $bits = explode(':', $spec);
        if (count($bits) >= 2) {
            $mounted[] = rtrim($bits[1], '/');
        }
    }
}
test('parsed at least 4 mounts from the app service', count($mounted) >= 4,
    'parsed: ' . implode(', ', $mounted));

foreach ($writePaths as $label => $hostPath) {
    $cpath = $toContainer($hostPath);
    test("write path $label maps to a container path", $cpath !== null,
        'host path: ' . $hostPath);
    if ($cpath === null) {
        continue;
    }
    test("$label ($cpath) is mounted as a volume", in_array($cpath, $mounted, true),
        'mounted: ' . implode(', ', $mounted) . ' — an unmounted write path is '
        . 'DESTROYED by `docker compose up -d --build`');
}

// ── The named volume must be declared, or compose refuses to start ──
echo "\n-- Volume declarations --\n";
preg_match('/\nvolumes:\n(.*)$/s', $compose, $vd);
$declared = $vd[1] ?? '';
foreach (['db_data', 'app_uploads', 'app_cache', 'app_backups', 'app_keys'] as $vol) {
    test("volume `$vol` is declared", preg_match('/^\s{2}' . preg_quote($vol, '/') . ':\s*$/m', $declared) === 1);
}

// ── The entrypoint must create + own the mounted dirs ─────────
echo "\n-- Entrypoint prepares the mounted dirs --\n";
// v4.2.3 moved backups OUT of the web root ($APP is the DocumentRoot), so the
// path the entrypoint prepares moved with it. Asserted against BACKUP_DIR's own
// shape rather than a literal, so it keeps following the constant.
test('entrypoint creates/chowns the backups dir', strpos($entry, '$APP/../backups') !== false,
    'a fresh named volume mounts in root-owned; www-data could not write there');
test('the backups dir the entrypoint prepares is OUTSIDE the DocumentRoot',
    strpos($entry, '$APP/backups"') === false && strpos($entry, "\$APP/backups'") === false,
    'inside /var/www/html, an archive is downloadable by anyone who guesses the filename');
test('entrypoint still handles uploads/cache/keys',
    strpos($entry, '$APP/uploads') !== false
    && strpos($entry, '$APP/cache') !== false
    && strpos($entry, '$APP/../keys') !== false);

// ── Documentation tells the story, incl. the one-time migration ──
echo "\n-- Docs --\n";
$dockerDoc = (string) @file_get_contents($root . '/docs/DOCKER.md');
test('docs/DOCKER.md lists app_backups in the volume table',
    strpos($dockerDoc, 'app_backups') !== false);
test('docs/DOCKER.md documents the one-time rescue for older compose files',
    stripos($dockerDoc, 'docker compose cp app:/var/www/html/backups') !== false);
test('docs/DOCKER.md warns that off-volume paths die on --build',
    stripos($dockerDoc, 'writable layer') !== false);

$switchDoc = (string) @file_get_contents($root . '/docs/SWITCH-FROM-ZIP-TO-GIT.md');
test('SWITCH-FROM-ZIP-TO-GIT.md tells Docker users to check the backups mount',
    strpos($switchDoc, '/var/www/html/backups') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
