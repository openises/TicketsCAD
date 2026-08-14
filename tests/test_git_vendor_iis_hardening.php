<?php
/**
 * 2026-08-14 (Ron Jones, @rjonesbsink) — .git/ and vendor/ were reachable
 * over HTTP on IIS: measured on a stock IIS 10 / Windows 11 git-clone
 * install, GET /.git/config, /.git/HEAD, /.git/refs/heads/main,
 * /.git/objects/info/packs, /.git/index, and
 * /vendor/composer/installed.json all returned 200. Apache (.htaccess) and
 * nginx (docs/nginx/ticketscad-hardening.conf) already deny both directories
 * — this was an IIS-only gap.
 *
 * Neither directory can carry a git-tracked web.config:
 *   - .git/ is git's own internal directory. It is never versioned content;
 *     nothing inside it exists in any commit, so nothing can ship there via
 *     `git clone` itself.
 *   - vendor/ is excluded by `.gitignore`'s `/vendor/` DIRECTORY pattern.
 *     Per git's own documented behaviour, once a parent directory is
 *     excluded, files inside it cannot be re-included even with a `!`
 *     negation — confirmed against THIS project's own .gitignore below.
 *
 * The fix: inc/navbar.php calls served_dir_harden() (inc/served-dir.php,
 * the same runtime template already used for keys/, backups/,
 * cache/zello-audio/) for both directories, force=true, wired into the one
 * include every authenticated page pulls in — matching backup_schedule_tick()
 * immediately above it for the same reason.
 *
 * This test drives the REAL served_dir_harden() function against real
 * synthetic directories (not a re-implementation of its logic), and
 * separately proves the .gitignore claim above against the real file.
 *
 * @requires-db
 * Usage: php tests/test_git_vendor_iis_hardening.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/served-dir.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== .git/ and vendor/ IIS hardening ===\n\n";

$root = realpath(__DIR__ . '/..');

// ── Part 1 — structural: navbar.php actually wires this in ──────────────
$navbarSrc = (string) file_get_contents($root . '/inc/navbar.php');
t('navbar.php requires served-dir.php',
    strpos($navbarSrc, "require_once __DIR__ . '/served-dir.php';") !== false);
t('navbar.php hardens .git/ with force=true',
    strpos($navbarSrc, "served_dir_harden(NEWUI_ROOT . '/.git'") !== false
    && strpos($navbarSrc, "served_dir_harden(NEWUI_ROOT . '/.git'") < strpos($navbarSrc, "served_dir_harden(NEWUI_ROOT . '/vendor'")
    && (bool) preg_match('/served_dir_harden\(NEWUI_ROOT \. \'\/\.git\'.*?, true\);/', $navbarSrc));
t('navbar.php hardens vendor/ with force=true',
    strpos($navbarSrc, "served_dir_harden(NEWUI_ROOT . '/vendor'") !== false
    && (bool) preg_match('/served_dir_harden\(NEWUI_ROOT \. \'\/vendor\'.*?, true\);/', $navbarSrc));

// ── Part 2 — confirm the .gitignore claim this whole design rests on ────
$gitignore = (string) file_get_contents($root . '/.gitignore');
t('.gitignore excludes vendor/ as a directory pattern (why a tracked '
    . 'vendor/web.config is impossible, per git\'s own re-include rules)',
    (bool) preg_match('/^\/vendor\/\s*$/m', $gitignore));

// ── Part 3 — drive the REAL served_dir_harden() against synthetic dirs ──
$sandbox = sys_get_temp_dir() . '/tcad_gitvendor_test_' . getmypid();
$gitDir    = $sandbox . '/.git';
$vendorDir = $sandbox . '/vendor';
@mkdir($gitDir, 0777, true);
@mkdir($vendorDir, 0777, true);

try {
    served_dir_harden($gitDir, 'Git repository metadata (test)', true);
    served_dir_harden($vendorDir, 'Composer dependencies (test)', true);

    t('.git/ gets a web.config', is_file($gitDir . '/web.config'));
    t('.git/ gets a .htaccess', is_file($gitDir . '/.htaccess'));
    t('vendor/ gets a web.config', is_file($vendorDir . '/web.config'));
    t('vendor/ gets a .htaccess', is_file($vendorDir . '/.htaccess'));

    $gitWc = (string) file_get_contents($gitDir . '/web.config');
    $vendorWc = (string) file_get_contents($vendorDir . '/web.config');
    t('.git/web.config uses Request Filtering (the one shipped mechanism)',
        strpos($gitWc, '<fileExtensions allowUnlisted="false" />') !== false);
    t('vendor/web.config uses Request Filtering (the one shipped mechanism)',
        strpos($vendorWc, '<fileExtensions allowUnlisted="false" />') !== false);
    t('.git/web.config disables directory browsing',
        strpos($gitWc, '<directoryBrowse enabled="false" />') !== false);
    t('vendor/web.config disables directory browsing',
        strpos($vendorWc, '<directoryBrowse enabled="false" />') !== false);

    // Idempotent — a second call must not error or clobber.
    served_dir_harden($gitDir, 'Git repository metadata (test)', true);
    t('re-running is a clean no-op', file_get_contents($gitDir . '/web.config') === $gitWc);
} finally {
    @unlink($gitDir . '/web.config');
    @unlink($gitDir . '/.htaccess');
    @unlink($vendorDir . '/web.config');
    @unlink($vendorDir . '/.htaccess');
    @rmdir($gitDir);
    @rmdir($vendorDir);
    @rmdir($sandbox);
}

// ── Part 4 — the real, on-disk directories, hardened the same way
// navbar.php does it (force=true) — driven HERE rather than assumed,
// because on a fresh checkout (CI, or any install nobody has loaded a
// page on yet) nothing has triggered navbar.php's call, and asserting
// on that ambient state made this exact assertion fail in CI the first
// time it shipped: the sandbox in Part 3 already proves the mechanism
// works, so Part 4 only needs to prove it also runs clean against the
// REAL paths, which requires actually calling it. served_dir_harden()
// is idempotent and never overwrites an existing file, so this is safe
// to run against a real, in-use install. ─────────────────────────────
if (defined('NEWUI_ROOT') && is_dir(NEWUI_ROOT . '/.git')) {
    served_dir_harden(NEWUI_ROOT . '/.git', 'Git repository metadata', true);
    t('on THIS install, .git/web.config exists on disk right now',
        is_file(NEWUI_ROOT . '/.git/web.config'));
} else {
    echo "SKIP: no .git directory on this install (ZIP install?) — nothing to confirm on disk.\n";
}
if (defined('NEWUI_ROOT') && is_dir(NEWUI_ROOT . '/vendor')) {
    served_dir_harden(NEWUI_ROOT . '/vendor', 'Composer dependencies', true);
    t('on THIS install, vendor/web.config exists on disk right now',
        is_file(NEWUI_ROOT . '/vendor/web.config'));
} else {
    echo "SKIP: no vendor directory on this install (composer install not yet run) — nothing to confirm on disk.\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
