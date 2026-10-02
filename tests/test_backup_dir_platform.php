<?php
/**
 * Gate: the fix for GHSA-rrp6-pqhj-w5wj must not put database archives into a
 * DIFFERENT web root, and must not report itself healthy when it cannot tell.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * v4.2.3 moved backups "above the web root":
 *
 *     define('BACKUP_DIR', dirname(NEWUI_ROOT) . '/backups');
 *
 * dirname() is above the web root on a POSIX layout (/var/www/newui →
 * /var/www). @rjonesbsink reported what it does on a stock Windows/IIS box.
 * The application lives at C:\inetpub\wwwroot\TicketsV4, so:
 *
 *     dirname(NEWUI_ROOT)  =  C:\inetpub\wwwroot
 *
 * which is the physical path of **Default Web Site**, bound to `*:80`. His site
 * list, verbatim:
 *
 *     Default Web Site  %SystemDrive%\inetpub\wwwroot     *:80:
 *     TicketsV4         C:\inetpub\wwwroot\TicketsV4      *:8089:
 *     Tickets           C:\inetpub\wwwroot\ticketscad     *:8081:,*:8443:
 *     phpmyadmin        C:\inetpub\wwwroot\phpMyAdmin     *:8085:
 *
 * So a complete database archive became reachable at
 * `http://<host>/backups/ticketscad-*.zip` — on a site that carries none of
 * this application's deny rules, on a port the application does not use.
 *
 * And the health check said OK, because health_check_web_exposure() probes only
 * THIS install's own base URL. Critical → resolved, with the data more exposed
 * than before, behind a green tick. That last part is the worse half: a check
 * that reports success for the region it never looked at is not a weak check,
 * it is a misleading one.
 *
 * The remediation text was the second defect. It printed `mkdir -p`, `mv` and
 * `sudo chown … www-data` unconditionally, which on Windows rendered as
 * `mkdir -p C:\inetpub\wwwroot/backups` — POSIX verbs, a group that does not
 * exist, mixed separators. An IIS administrator could paste none of it, and
 * following it by hand is precisely what moved the archive to port 80.
 *
 * ── WHAT THIS FILE ASSERTS ───────────────────────────────────────────
 *
 *   1. The Windows default does not resolve inside inetpub\wwwroot, or inside
 *      the application tree, on any of the four real layouts above.
 *   2. The POSIX default is unchanged — it was correct there.
 *   3. The remediation text is platform-correct: no POSIX-only verbs and no
 *      mixed separators in the Windows branch. Both branches are asserted from
 *      whichever machine runs this, because a test that can only see its own
 *      platform's text is how the defect shipped.
 *   4. The destination check catches an archive in a web-served directory —
 *      driven through backup_dir() / health_check_backups() with the real
 *      `backup_dir` setting, not by hand-building the result array.
 *   5. The blind-spot disclosure is present whenever the HTTP self-test could
 *      not settle the question, INCLUDING when the verdict is 'ok'.
 *   6. Archives v4.2.3 already wrote are not orphaned by the new default.
 *
 * Usage: php tests/test_backup_dir_platform.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup.php';
require_once __DIR__ . '/../inc/backup_schedule.php';
require_once __DIR__ . '/../inc/health-check.php';

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

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$n = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };

echo "=== Backup directory: platform-correct default + destination check ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The Windows default never lands in a site root --\n";

// The real reported layouts, plus XAMPP, which has the identical shape
// (C:\xampp\htdocs is the DocumentRoot, so dirname() is served there too).
$winInstalls = [
    'C:\\inetpub\\wwwroot\\TicketsV4',
    'C:\\inetpub\\wwwroot\\ticketscad',
    'C:\\inetpub\\wwwroot\\phpMyAdmin',
    'C:\\xampp\\htdocs\\newui',
    'D:\\sites\\ticketscad',
];
foreach ($winInstalls as $app) {
    $d  = backup_default_dir_for($app, true);
    $dN = $n($d);
    test("Windows default for $app is not inside inetpub\\wwwroot",
        stripos($dN, '/inetpub/wwwroot') === false, 'got ' . $d);
    test("Windows default for $app is not inside the application tree",
        strpos($dN . '/', $n($app) . '/') !== 0, 'got ' . $d);
    test("Windows default for $app is not the v4.2.3 sibling",
        $dN !== $n(dirname($app)) . '/backups', 'got ' . $d);
    test("Windows default for $app is an absolute Windows path",
        preg_match('/^[A-Za-z]:\\\\/', $d) === 1, 'got ' . $d);
    test("Windows default for $app uses backslashes throughout",
        strpos($d, '/') === false, 'got ' . $d);
}

test('the Windows default is under ProgramData',
    stripos($n(backup_default_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true)),
            '/programdata/ticketscad/backups') !== false,
    backup_default_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true));

// Determinism. BACKUP_DIR is a define(); a value that moves when unrelated
// files appear or vanish would silently relocate an install's archives.
$a = backup_default_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true);
$b = backup_default_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true);
test('the default is deterministic — same input, same answer', $a === $b);
test('the default does not depend on what is on disk (no is_dir/file_exists in it)',
    preg_match('/function backup_default_dir_for.*?\n\}/s',
        (string) file_get_contents($root . '/inc/backup.php'), $m) === 1
    && strpos($m[0], 'is_dir') === false
    && strpos($m[0], 'file_exists') === false
    && strpos($m[0], 'realpath') === false,
    'a constant whose value tracks the filesystem relocates archives behind the operator');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The POSIX default is unchanged (it was right there) --\n";
test('/var/www/newui  → /var/www/backups',
    backup_default_dir_for('/var/www/newui', false) === '/var/www/backups');
test('/srv/ticketscad → /srv/backups',
    backup_default_dir_for('/srv/ticketscad', false) === '/srv/backups');
test('the POSIX default is still outside the application tree',
    strpos('/var/www/backups/', '/var/www/newui/') !== 0);

test('BACKUP_DIR on THIS machine matches backup_default_dir_for(NEWUI_ROOT)',
    defined('BACKUP_DIR') && BACKUP_DIR === backup_default_dir_for($root));
test('BACKUP_DIR on THIS machine is outside the application tree',
    defined('BACKUP_DIR') && strpos($n(BACKUP_DIR) . '/', $n($root) . '/') !== 0,
    'BACKUP_DIR=' . BACKUP_DIR);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. Remediation text is platform-correct --\n";

$winText = health_backup_move_remedy('C:\\inetpub\\wwwroot\\backups', true);
$nixText = health_backup_move_remedy('/var/www/html/backups', false);

// The exact verbs that shipped, and that an IIS administrator cannot run.
$posixOnly = ['mkdir -p', 'sudo ', 'chown', 'chmod', 'id -un', 'www-data', "\n  mv ", "\n  ls "];
foreach ($posixOnly as $bad) {
    test('Windows remediation contains no `' . trim($bad) . '`',
        strpos($winText, $bad) === false,
        'the shipped text rendered as: mkdir -p C:\\inetpub\\wwwroot/backups');
}
test('Windows remediation uses PowerShell verbs',
    strpos($winText, 'New-Item') !== false && strpos($winText, 'Move-Item') !== false
    && strpos($winText, 'icacls') !== false);
test('Windows remediation has no mixed path separators',
    preg_match('#[A-Za-z]:\\\\[^\s\']*/#', $winText) !== 1,
    'BACKUP_DIR_LEGACY is built with "/", so an un-normalised path renders as '
    . 'C:\\inetpub\\wwwroot\\TicketsV4/backups');
test('Windows remediation never suggests moving archives INTO inetpub\\wwwroot',
    preg_match('/(Suggested:|New-Item[^\n]*)[^\n]*inetpub\\\\wwwroot/i', $winText) !== 1);
test('Windows remediation warns off C:\\inetpub\\wwwroot by name',
    stripos($winText, 'Do NOT use C:\\inetpub\\wwwroot') !== false,
    'the reader is standing in front of exactly that directory');

// The platform-neutral instruction has to come FIRST: it is the whole fix, it
// needs no shell, and it is the only line that is right on every server.
foreach (['windows' => $winText, 'posix' => $nixText] as $k => $txt) {
    test("[$k] remediation leads with the backup_dir setting, not a shell command",
        strpos($txt, 'Settings → Backup / Maintenance') !== false
        && strpos($txt, 'Settings → Backup / Maintenance') < 120,
        substr($txt, 0, 80));
    test("[$k] remediation points at the advisory",
        strpos($txt, 'advisory-2026-07-30-exposed-directories.md') !== false);
}
test('POSIX remediation keeps the shell commands that are correct there',
    strpos($nixText, 'mkdir -p') !== false && strpos($nixText, 'chown') !== false);
test('POSIX remediation contains no PowerShell',
    strpos($nixText, 'New-Item') === false && strpos($nixText, 'icacls') === false);

test('health_os_account() gives an IIS-shaped placeholder when asked for Windows',
    strpos(health_os_account(true), '\\') !== false,
    health_os_account(true));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. The destination check grades what it can and cannot know --\n";

$x = backup_dir_exposure($root . '/backups');
test('a directory inside the application tree is CERTAIN exposure',
    $x['served'] === true && $x['state'] === 'in_app_tree');

$sd = getenv('SystemDrive');
$sd = ($sd !== false && trim((string) $sd) !== '') ? rtrim((string) $sd, '\\/') : 'C:';
$x = backup_dir_exposure($sd . '/inetpub/wwwroot/backups');
test('%SystemDrive%\\inetpub\\wwwroot\\backups is CERTAIN exposure — the exact regression',
    $x['served'] === true && $x['state'] === 'in_default_site_root',
    'state=' . $x['state'] . ' — this is Default Web Site, bound to *:80');
test('…and it says WHY, naming the site and the port',
    stripos($x['why'], 'default web site') !== false && strpos($x['why'], '80') !== false);

// A synthetic document root, on disk, examined by the real function.
$sandbox = sys_get_temp_dir() . '/tcad-expo-' . getmypid();
$cleanup = [];
@mkdir($sandbox . '/wwwroot/backups', 0777, true);
@mkdir($sandbox . '/quiet/backups', 0777, true);
$cleanup[] = $sandbox;

if (is_dir($sandbox . '/wwwroot/backups')) {
    $x = backup_dir_exposure($sandbox . '/wwwroot/backups');
    test('a directory under something named wwwroot is SUSPECT (warn, not critical)',
        $x['suspect'] === true && $x['served'] === false
        && $x['state'] === 'looks_like_site_root', 'state=' . $x['state']);

    @file_put_contents($sandbox . '/quiet/index.php', "<?php\n");
    $x = backup_dir_exposure($sandbox . '/quiet/backups');
    test('a directory whose parent holds index.php is SUSPECT',
        $x['suspect'] === true && $x['state'] === 'looks_like_site_root',
        'state=' . $x['state']);
    @unlink($sandbox . '/quiet/index.php');

    $x = backup_dir_exposure($sandbox . '/quiet/backups');
    test('an ordinary directory is "no local evidence", NOT a clean bill of health',
        $x['served'] === false && $x['suspect'] === false
        && $x['state'] === 'no_local_evidence', 'state=' . $x['state']);
    test('…and it carries the blind-spot text, because that is the honest answer',
        !empty($x['blind_spot']) && stripos($x['blind_spot'], 'other web sites') !== false);
} else {
    echo "[SKIP] filesystem sandbox could not be created — heuristic cases not exercised\n";
}

// A bare index.html must NOT be enough. The first draft flagged %TEMP% as a
// document root because one was lying there, and a check that fires on innocent
// layouts is a check that gets muted.
if (is_dir($sandbox . '/quiet/backups')) {
    @file_put_contents($sandbox . '/quiet/index.html', "hi\n");
    $x = backup_dir_exposure($sandbox . '/quiet/backups');
    test('a lone index.html in an ordinarily-named parent is NOT enough to accuse',
        $x['suspect'] === false, 'state=' . $x['state'] . ' — temp dirs have these');
    @unlink($sandbox . '/quiet/index.html');

    // …but the stock Debian/Ubuntu DocumentRoot is exactly that, and it IS one.
    @mkdir($sandbox . '/html/backups', 0777, true);
    @file_put_contents($sandbox . '/html/index.html', "Apache2 Default Page\n");
    $x = backup_dir_exposure($sandbox . '/html/backups');
    test('…while /…/html holding an index page IS flagged (the POSIX twin of this bug)',
        $x['suspect'] === true, 'an install at /var/www/html/newui gets /var/www/html/backups');
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. health_check_backups() through the REAL resolution path --\n";
// Driven by setting the real `backup_dir` setting and letting backup_dir() and
// backup_dirs_all() resolve it, rather than hand-building a directory list. A
// hand-seeded result would pass while the production resolver did something
// else entirely — the failure mode this project keeps rediscovering.

/**
 * Ask a FRESH PHP process what the resolver decides.
 *
 * This has to be a separate process, and the reason is itself production
 * behaviour worth pinning down: get_variable() caches the whole settings table
 * in a static on first read, so a value written during a request is not visible
 * to that same request. Asserting in-process would therefore prove nothing
 * about what a real request does — it would prove something about the cache.
 *
 * proc_open with an ARGV ARRAY, never a command string: this project gates
 * against shelling out (tests/test_no_shell_command_execution.php), and an argv
 * array is the form that does not hand a string to a shell to re-parse.
 * Returns null when it cannot run, so the caller skips rather than fails.
 */
function bdp_ask_fresh_process(string $root, string $expr): ?array
{
    if (!function_exists('proc_open')) { return null; }
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) { return null; }

    $code = '<?php require_once ' . var_export($root . '/config.php', true) . ';'
          . ' require_once ' . var_export($root . '/inc/backup_schedule.php', true) . ';'
          . ' require_once ' . var_export($root . '/inc/health-check.php', true) . ';'
          . ' ob_start(); $__v = (' . $expr . '); $__noise = ob_get_clean();'
          . ' echo "<<<BDP>>>" . json_encode($__v);';
    $tmp = sys_get_temp_dir() . '/tcad-bdp-' . getmypid() . '-' . mt_rand() . '.php';
    if (@file_put_contents($tmp, $code) === false) { return null; }

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([$bin, '-d', 'display_errors=0', $tmp], $desc, $pipes);
    if (!is_resource($proc)) { @unlink($tmp); return null; }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);

    $at = strpos($out, '<<<BDP>>>');
    if ($at === false) { return null; }
    $json = json_decode(substr($out, $at + 9), true);
    return is_array($json) ? $json : null;
}

$dbOk = false;
try {
    db_query("SELECT 1");
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

if (!$dbOk) {
    echo "[SKIP] no database on this host — the settings-driven path cannot be exercised\n";
} else {
    $original = backup_setting('backup_dir', '');
    // A directory that is unambiguously inside the served tree, with an archive
    // in it. cache/ is gitignored and the sub-directory is removed below.
    $exposedDir = $root . '/cache/tcad-expo-test-' . getmypid();
    @mkdir($exposedDir, 0777, true);
    $fake = $exposedDir . '/ticketscad-19700101-000000.zip';
    @file_put_contents($fake, "not a real archive\n");

    $expr = '["dir" => backup_dir(), "all" => backup_dirs_all(), "hc" => health_check_backups()]';
    try {
        backup_setting_set('backup_dir', $exposedDir);
        $r = bdp_ask_fresh_process($root, $expr);

        if ($r === null) {
            echo "[SKIP] could not start a second PHP process — settings-driven path not exercised\n";
        } else {
            $hc = $r['hc'] ?? [];
            test('backup_dir() honours the setting (the real resolver, in a real request)',
                $n((string) ($r['dir'] ?? '')) === $n($exposedDir), (string) ($r['dir'] ?? ''));
            test('an archive in a web-served directory is reported CRITICAL',
                ($hc['severity'] ?? '') === 'critical',
                'severity=' . ($hc['severity'] ?? '?') . ' summary=' . ($hc['summary'] ?? ''));
            test('…and the ACTIVE directory itself is flagged, not just counted elsewhere',
                !empty($hc['active_web_served']),
                'state=' . ($hc['active_state'] ?? '?'));
            test('…and the archive is counted', (int) ($hc['exposed_archives'] ?? 0) >= 1);
            test('…and the summary says it is published',
                stripos((string) ($hc['summary'] ?? ''), 'published') !== false,
                (string) ($hc['summary'] ?? ''));
            test('…and the remedy is not empty', trim((string) ($hc['remedy'] ?? '')) !== '');
            test('…and the remedy is correct for THIS platform',
                DIRECTORY_SEPARATOR === '\\'
                    ? strpos((string) ($hc['remedy'] ?? ''), 'mkdir -p') === false
                    : strpos((string) ($hc['remedy'] ?? ''), 'New-Item') === false);
        }

        // Now the clean case, and the point of the whole exercise: even when
        // this directory is clear, the limits of the check are stated.
        $safeDir = $sandbox . '/quiet/backups';
        if (is_dir($safeDir)) {
            backup_setting_set('backup_dir', $safeDir);
            $r = bdp_ask_fresh_process($root, $expr);
            if ($r === null) {
                echo "[SKIP] could not start a second PHP process — clean case not exercised\n";
            } else {
                $hc = $r['hc'] ?? [];
                // NOT asserted on severity: a machine that still holds archives
                // in a legacy in-webroot directory is correctly CRITICAL for
                // that reason, and this developer's box does. The claim under
                // test is about the ACTIVE directory's own verdict.
                test('a directory with no local evidence of exposure is not itself flagged',
                    empty($hc['active_web_served']) && empty($hc['active_suspect']),
                    'state=' . ($hc['active_state'] ?? '?'));
                test('the blind spot is disclosed even when the active directory passes',
                    trim((string) ($hc['blind_spot'] ?? '')) !== '',
                    'an "ok" that silently covers one address is how this shipped');
                test('…and it says plainly that other sites/ports are not covered',
                    stripos((string) ($hc['blind_spot'] ?? ''), 'another site') !== false
                    || stripos((string) ($hc['blind_spot'] ?? ''), 'other web sites') !== false
                    || stripos((string) ($hc['blind_spot'] ?? ''), 'reverse proxy') !== false,
                    (string) ($hc['blind_spot'] ?? ''));
            }
        }
    } finally {
        backup_setting_set('backup_dir', $original);
        @unlink($fake);
        foreach (glob($exposedDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($exposedDir);
    }

    $restored = bdp_ask_fresh_process($root, 'backup_setting("backup_dir", "")');
    test('the backup_dir setting was restored',
        $restored === null || (string) $restored === $original,
        'left as: ' . var_export($restored, true));
}

test('the Status page no longer hard-codes "IN THE WEB ROOT" as the label',
    strpos((string) file_get_contents($root . '/status.php'), '>IN THE WEB ROOT<') === false,
    'on Windows the archives were in a DIFFERENT site\'s web root, not this one\'s');

// The remedy must be about the directory that actually holds exposed archives.
// The common shape is an ACTIVE directory that is fine plus older archives left
// somewhere published; naming the active directory as the source produced
// `Move-Item -Path <target>\ticketscad-* -Destination <target>\` — an
// instruction to move a directory onto itself, which is worse than no
// instruction because it looks like one.
$r = health_check_backups();
if (($r['severity'] ?? '') !== 'ok') {
    test('the remedy names the offending directory, not the destination',
        $n((string) ($r['offender_dir'] ?? '')) !== $n(BACKUP_DIR)
        || !empty($r['active_web_served']) || !empty($r['active_suspect']),
        'offender=' . ($r['offender_dir'] ?? '?'));
    test('the remedy never moves a directory onto itself',
        preg_match('/Move-Item -Path \'([^\']*)\\\\ticketscad-\*\' -Destination \'([^\']*)\\\\\'/',
            (string) $r['remedy'], $mm) !== 1 || $n($mm[1]) !== $n($mm[2]),
        (string) $r['remedy']);
} else {
    echo "[SKIP] this host has no exposed archives — the offending-directory case is not present\n";
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. Nothing v4.2.3 wrote gets orphaned --\n";

test('BACKUP_DIR_LEGACY_SIBLING is the v4.2.3 default, exactly',
    defined('BACKUP_DIR_LEGACY_SIBLING')
    && $n(BACKUP_DIR_LEGACY_SIBLING) === $n(dirname($root)) . '/backups');
test('BACKUP_DIR_LEGACY is still the pre-4.2.3 in-tree path',
    defined('BACKUP_DIR_LEGACY') && $n(BACKUP_DIR_LEGACY) === $n($root) . '/backups');

$legacy = array_map($n, backup_legacy_dirs());
if (DIRECTORY_SEPARATOR === '\\') {
    test('on Windows the v4.2.3 sibling is treated as a THIRD legacy location',
        in_array($n(BACKUP_DIR_LEGACY_SIBLING), $legacy, true),
        'an install that ran 4.2.3 has archives there; dropping it hides them '
        . 'from Settings → Backup / Maintenance while leaving them downloadable on port 80');
} else {
    test('on POSIX the sibling IS the current default, so it is not listed twice',
        !in_array($n(BACKUP_DIR), $legacy, true));
}
test('the current default is never listed as legacy',
    !in_array($n(BACKUP_DIR), $legacy, true));

$all = array_map($n, backup_dirs_all());
foreach (backup_legacy_dirs() as $d) {
    if (!is_dir($d)) continue;
    test('an existing legacy directory stays listable: ' . $d,
        in_array($n($d), $all, true));
}

// Retention must still never reach into a legacy directory.
$sched = (string) file_get_contents($root . '/inc/backup_schedule.php');
test('retention still prunes only the ACTIVE directory',
    preg_match('/backup_prune[^;]*backup_(dirs_all|legacy_dirs)/', $sched) !== 1);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 7. The HTTP canary proves reachability without disclosing anything --\n";
// Driven for real against a hostname that cannot resolve (RFC 2606 .invalid),
// so no request leaves the machine and the assertions are about what the probe
// DOES: what it writes, what it asks for, and what it cleans up.

$probeDir = $sandbox . '/quiet/backups';
if (is_dir($probeDir)) {
    $savedHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = 'tcad-selftest.invalid:8089';
    // 2026-10-01 (found while syncing to the public repo): PHP's CLI SAPI
    // defaults $_SERVER['SCRIPT_NAME'] to the literal FILESYSTEM path of
    // the script being run -- there is no real HTTP request, so there is
    // no real URL path to reflect. _health_self_base_url() (correctly,
    // for a genuine web request, where a server always sets SCRIPT_NAME
    // from the URL, never the disk path) treats SCRIPT_NAME's directory
    // as the app's own URL prefix and folds it into the candidate URLs
    // this probe tries. Left at its CLI default, that prefix becomes
    // wherever THIS test file happens to be checked out on disk -- and
    // tools/release-snapshot.sh's own documented default staging
    // directory is literally named "ticketscad-public-build", which trips
    // this test's very next assertion (no tried URL may contain
    // "ticketscad-") for a reason that has nothing to do with a real
    // archive ever being probed. Pin SCRIPT_NAME to a realistic, fixed
    // in-app value -- what a genuine web request to the real health-check
    // page would set it to -- so this test exercises the real HTTP-context
    // behavior regardless of which directory the checkout happens to live
    // in on disk.
    $savedScript = $_SERVER['SCRIPT_NAME'] ?? null;
    $_SERVER['SCRIPT_NAME'] = '/tools/check-health.php';
    $before = glob($probeDir . '/*') ?: [];
    $p = health_check_backup_probe($probeDir, true);
    $after  = glob($probeDir . '/*') ?: [];
    if ($savedHost === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $savedHost; }
    if ($savedScript === null) { unset($_SERVER['SCRIPT_NAME']); } else { $_SERVER['SCRIPT_NAME'] = $savedScript; }

    test('the probe ran', !empty($p['checked']), (string) ($p['reason'] ?? ''));
    test('it tried the DEFAULT ports, not just the app\'s own',
        !empty(array_filter($p['tried'] ?? [], function ($u) {
            return preg_match('#^https?://tcad-selftest\.invalid/#', $u) === 1;
        })),
        implode(' ', $p['tried'] ?? []) . ' — port 80 is where the other site answers');
    test('it never requests a real archive',
        !in_array(true, array_map(function ($u) { return strpos($u, 'ticketscad-') !== false; },
            $p['tried'] ?? []), true),
        'an archive URL in a proxy log or cache is a disclosure in its own right');
    test('the canary is deleted afterwards', $before === $after,
        'left behind: ' . implode(', ', array_map('basename', array_diff($after, $before))));
    test('an unreachable host is NOT reported as exposed', empty($p['exposed']));
    test('the canary filename would not be mistaken for an archive',
        !in_array(true, array_map(function ($u) {
            return preg_match('#/ticketscad-[^/]*\.(zip|gz)$#', $u) === 1;
        }, $p['tried'] ?? []), true));
    test('the canary filename has no leading dot (servers deny dotfiles → false clean)',
        !in_array(true, array_map(function ($u) {
            return preg_match('#/\.[^/]+$#', $u) === 1;
        }, $p['tried'] ?? []), true));
} else {
    echo "[SKIP] filesystem sandbox unavailable — canary behaviour not exercised\n";
}

// No HTTP context at all must be reported as "could not tell", never as a pass.
$savedHost = $_SERVER['HTTP_HOST'] ?? null;
$savedName = $_SERVER['SERVER_NAME'] ?? null;
unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
$p = health_check_backup_probe($root, true);
if ($savedHost !== null) { $_SERVER['HTTP_HOST'] = $savedHost; }
if ($savedName !== null) { $_SERVER['SERVER_NAME'] = $savedName; }
test('with no request to work from, the probe says so instead of passing',
    ($p['checked'] ?? null) === false && empty($p['exposed']) && !empty($p['reason']));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 8. The other half of the disclosure: web_exposure --\n";
$hcSrc = (string) file_get_contents($root . '/inc/health-check.php');
test('health_check_web_exposure() publishes a blind_spot',
    preg_match("/'blind_spot'\s*=>/", $hcSrc) === 1);
test('the Status page renders it', strpos((string) file_get_contents($root . '/status.php'),
    'we.blind_spot') !== false);
test('the Status page renders the backup blind spot too',
    strpos((string) file_get_contents($root . '/status.php'), 'bk.blind_spot') !== false);
test('the Status page can show a WARN backup verdict, not only critical/ok',
    strpos((string) file_get_contents($root . '/status.php'), "bk.severity === 'warn'") !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 9. Docs give Windows-correct advice --\n";
$iis  = (string) @file_get_contents($root . '/docs/INSTALL-WINDOWS-IIS.md');
$hard = (string) @file_get_contents($root . '/docs/WEB-SERVER-HARDENING.md');
$adv  = (string) @file_get_contents($root . '/docs/security/advisory-2026-07-30-exposed-directories.md');

test('the Windows/IIS guide names a safe backup directory',
    stripos($iis, 'ProgramData\\TicketsCAD\\backups') !== false);
test('the Windows/IIS guide warns that C:\\inetpub\\wwwroot is the Default Web Site',
    stripos($iis, 'Default Web Site') !== false && stripos($iis, 'inetpub\\wwwroot') !== false);
test('the Windows/IIS guide gives PowerShell, not mkdir -p',
    stripos($iis, 'New-Item -ItemType Directory') !== false);
test('the hardening guide states the per-platform default',
    stripos($hard, 'ProgramData') !== false);
test('the advisory records the Windows follow-up',
    stripos($adv, 'inetpub') !== false && stripos($adv, '4.2.4') !== false);

// ─────────────────────────────────────────────────────────────────────
// Tidy up the sandbox.
foreach ([$sandbox . '/html', $sandbox . '/quiet/backups', $sandbox . '/quiet',
          $sandbox . '/wwwroot/backups', $sandbox . '/wwwroot', $sandbox] as $d) {
    foreach (glob($d . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($d);
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
