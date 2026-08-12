<?php
/**
 * Gate: a deploy must not take the operator's backup directory away from them.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * Twice on live hosts, `php tools/backup_run.php` run over SSH by the operator
 * failed with "Cannot create zip file: error code 5". Repaired by hand both
 * times; the next deploy undid the repair both times. tools/deploy.sh ended
 * with `sudo chown -R www-data:www-data /var/www/newui`, which recursed into
 * the in-webroot backups directory (BACKUP_DIR_LEGACY) and handed it to the web
 * server. That directory has TWO writers:
 *
 *   - tools/backup_run.php and tools/restore.php, as the human operator
 *   - api/backup.php and the scheduled systemd timer, as the web server
 *
 * so an owner shared with neither of them breaks one. There was a second,
 * independent reset in the same script: `git archive` records every entry as
 * root/root, so `sudo tar xzf` reset the ownership and mode of every directory
 * in the archive that already existed on the host — cache/ and uploads/.
 *
 * ── WHY THE MODE LOOKED FINE ─────────────────────────────────────────
 *
 * On Linux, chown clears setgid on FILES but not on DIRECTORIES, so `chown -R`
 * left the mode reading a correct-looking 2770 and moved only the owner.
 * Anything that inspected the mode would have called the directory healthy.
 * That is why the assertions below are about who can write, not about bits.
 *
 * ── WHAT THIS TEST ASSERTS, AND WHY IT IS NOT A GREP ──────────────────
 *
 *   1. The predicate. install_perm_state_ok() is given the EXACT ownership
 *      the old deploy produced and must call it broken — and the documented
 *      correct state and must call it working. Both failure directions are
 *      covered, including the one the docs warn about in the other direction
 *      (hand it entirely to the operator and the web/scheduled backup breaks).
 *   2. The classification. Which directories are shared and which belong to
 *      the web server alone, plus the two exclusions that matter: the keys
 *      directory and anything that could carry a repository.
 *   3. Real filesystem behaviour. install_perm_plan() + install_perm_apply()
 *      are run against a real directory and the result is re-read with stat().
 *   4. Real tar behaviour. The extract command is taken out of tools/deploy.sh
 *      and executed over a directory that is already in a working state, with
 *      a negative control showing that extraction alone leaves it broken —
 *      so the repair step that follows is proved to be doing the work, rather
 *      than passing because there was nothing to repair.
 *
 * A grep for "chown" in deploy.sh would prove none of this.
 *
 * Sections 3 and 4 need a POSIX host and are skipped on Windows; CI is Linux,
 * so they run there. Usage: php tests/test_deploy_permissions.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/install-permissions.php';

$passed  = 0;
$failed  = 0;
$skipped = 0;

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
function skip($label, $why) {
    global $skipped;
    echo "[SKIP] $label — $why\n";
    $skipped++;
}

$isPosix = (PHP_OS_FAMILY !== 'Windows')
        && function_exists('posix_geteuid')
        && function_exists('posix_getgroups');

echo "=== Deploy must preserve shared ownership of the backup directories ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The predicate: is this ownership a working state? --\n";

// Synthetic accounts. Numbers chosen to look like a real Debian host, but
// nothing here reads the local passwd database — the point is the algebra.
$web = ['name' => 'www-data', 'uid' => 33,   'gids' => [33],       'determined' => true];
$op  = ['name' => 'operator', 'uid' => 1000, 'gids' => [1000, 50], 'determined' => true];

// THE BUG, exactly as `chown -R www-data:www-data` left it: owner and group
// both the web server, and — because chown does not strip setgid from a
// directory — a mode that still reads 2770.
test('THE BUG: shared dir owned www-data:www-data mode 2770 is NOT a working state',
    install_perm_state_ok(INSTALL_PERM_SHARED, 33, 33, 02770, $web, $op) === false,
    'the operator is neither the owner nor in the group, so POSIX puts them in "other" (0)');

test('THE FIX: shared dir owned operator:www-data mode 2770 IS a working state',
    install_perm_state_ok(INSTALL_PERM_SHARED, 1000, 33, 02770, $web, $op) === true);

// The other direction, which docs/backup_run.php warns about by name: hand the
// directory entirely to the operator and it is the scheduled/web backup that
// breaks instead. A fix that only tested one direction would pass this state.
test('the opposite mistake — owned operator:operator — is also NOT working',
    install_perm_state_ok(INSTALL_PERM_SHARED, 1000, 1000, 02770, $web, $op) === false,
    'the web server would lose the scheduled and Settings-page backup');

test('setgid missing (mode 0770) is NOT working, even with both writers able to write',
    install_perm_state_ok(INSTALL_PERM_SHARED, 1000, 33, 0770, $web, $op) === false,
    'without setgid each new archive lands in its creator\'s group and the other writer is locked out one file at a time');

test('group cannot write (mode 2750) is NOT working',
    install_perm_state_ok(INSTALL_PERM_SHARED, 1000, 33, 02750, $web, $op) === false);

test('a shared dir cannot be judged at all without an operator account',
    install_perm_state_ok(INSTALL_PERM_SHARED, 1000, 33, 02770, $web, null) === false,
    'must report unknown rather than assume');

// The web-server-only role, and the state the OTHER half of the defect left:
// `git archive` records root/root, so `sudo tar` recreated cache/ as root:root.
test('web-only dir owned www-data mode 0775 IS a working state',
    install_perm_state_ok(INSTALL_PERM_WEB, 33, 33, 0775, $web) === true);

test('THE OTHER HALF: web-only dir left root:root 0755 by tar is NOT working',
    install_perm_state_ok(INSTALL_PERM_WEB, 0, 0, 0755, $web) === false,
    'this is what `sudo tar xzf` of a git archive produces');

test('web-only dir owned by the operator alone is NOT working',
    install_perm_state_ok(INSTALL_PERM_WEB, 1000, 1000, 0755, $web) === false);

// A root web server (some containers) is not subject to mode bits at all.
test('a uid-0 web server is writable anywhere (kernel, not mode bits)',
    install_perm_state_ok(INSTALL_PERM_WEB, 1000, 1000, 0700,
        ['name' => 'root', 'uid' => 0, 'gids' => [0], 'determined' => true]) === true);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The classification --\n";

$targets = install_perm_targets();
$byPath  = [];
$norm    = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };
foreach ($targets as $t) {
    $byPath[$norm($t['path'])] = $t;
}

test('BACKUP_DIR is covered and classified as shared with the operator',
    isset($byPath[$norm(BACKUP_DIR)])
    && $byPath[$norm(BACKUP_DIR)]['role'] === INSTALL_PERM_SHARED,
    'BACKUP_DIR=' . BACKUP_DIR);

// The legacy path is still READ (listing, retention), so it needs the same
// shape wherever it still exists — it is the directory the deploy broke.
if (defined('BACKUP_DIR_LEGACY') && is_dir(BACKUP_DIR_LEGACY)) {
    test('BACKUP_DIR_LEGACY, which is the directory the deploy broke, is shared too',
        isset($byPath[$norm(BACKUP_DIR_LEGACY)])
        && $byPath[$norm(BACKUP_DIR_LEGACY)]['role'] === INSTALL_PERM_SHARED);
} else {
    skip('BACKUP_DIR_LEGACY classification', 'not present on this install (nothing to keep working)');
}
test('BACKUP_DIR_LEGACY is never created — that would restore a web-served directory',
    !array_filter($targets, function ($t) {
        return defined('BACKUP_DIR_LEGACY')
            && rtrim(str_replace('\\', '/', $t['path']), '/') === rtrim(str_replace('\\', '/', BACKUP_DIR_LEGACY), '/')
            && !empty($t['create']);
    }));

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
foreach (['uploads', 'cache', 'cache/weather'] as $rel) {
    test("$rel is classified web-server-only",
        isset($byPath[$norm($root . '/' . $rel)])
        && $byPath[$norm($root . '/' . $rel)]['role'] === INSTALL_PERM_WEB,
        'every writer is a request path; a second owner would only widen access');
}

// GHSA-x9x6-w4fg-pmcc — Zello recordings now live in a private directory
// outside $root (inc/zello_audio_dir.php), still web-server-only (one
// writer, the proxy daemon, running as the web user per its systemd unit).
require_once __DIR__ . '/../inc/zello_audio_dir.php';
test('zello_audio_dir() (private) is classified web-server-only',
    isset($byPath[$norm(zello_audio_dir())])
    && $byPath[$norm(zello_audio_dir())]['role'] === INSTALL_PERM_WEB,
    'one writer (the proxy daemon, running as the web user) — no shared group needed');
test('cache/zello-audio (legacy) is NOT unconditionally created',
    !array_filter($targets, function ($t) use ($root, $norm) {
        return $norm($t['path']) === $norm($root . '/cache/zello-audio') && !empty($t['create']);
    }),
    'creating it would put a served directory back on disk for no reason');

// The exclusions. Private key material is 0700 owner-only by the code that
// creates it (inc/field-encrypt.php); giving it a shared group would be a
// security regression dressed up as a permissions fix.
require_once $root . '/inc/field-encrypt.php';
test('FE_KEYS_DIR is NOT touched by this policy',
    !isset($byPath[$norm(FE_KEYS_DIR)]),
    'keys are 0700 owner-only by design and live outside the install tree');

test('the install root itself is not a target',
    !isset($byPath[$norm($root)]));

$ancestors = array_filter(array_keys($byPath), function ($p) use ($norm, $root) {
    return strpos($norm($root) . '/', $p . '/') === 0;
});
test('no target is an ancestor of the install root',
    empty($ancestors),
    'would sweep in .git and break the next `git pull`');

test('every shared target asks for setgid',
    count(array_filter($targets, function ($t) { return $t['role'] === INSTALL_PERM_SHARED; })) > 0
    && (INSTALL_PERM_MODE_SHARED & 02000) === 02000);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. Real filesystem: plan → apply → re-read with stat() --\n";

$tmpRoot      = null;
$scratchReady = false;   // section 4 reuses section 3's scratch dir and identities
if (!$isPosix) {
    skip('apply against a real directory', 'needs a POSIX host (ownership is not modelled on Windows); runs in CI');
} else {
    $groups = @posix_getgroups();
    $myUid  = posix_geteuid();
    $tmpRoot = sys_get_temp_dir() . '/newui-perm-' . getmypid() . '-' . bin2hex(random_bytes(4));

    if (!is_array($groups) || empty($groups) || !@mkdir($tmpRoot, 0700, true)) {
        skip('apply against a real directory', 'could not create a scratch directory or read this account\'s groups');
    } else {
        // A group this account really belongs to: chgrp to it is permitted for
        // a non-root user, so the whole path can be exercised without sudo.
        $gid = (int) $groups[0];
        $scratchReady = true;
        $dir = $tmpRoot . '/backups';
        mkdir($dir, 0700);

        // Stand in for the web server with an account that is NOT this one but
        // does hold $gid — i.e. exactly the relationship a real install has.
        $fakeWeb = ['name' => 'test-web', 'uid' => $myUid + 424242, 'gids' => [$gid], 'determined' => true];
        $me      = ['name' => 'test-op',  'uid' => $myUid, 'gids' => $groups, 'determined' => true];

        $target = [['path' => $dir, 'label' => 'scratch', 'role' => INSTALL_PERM_SHARED,
                    'purpose' => 'test', 'create' => false]];

        $plan = install_perm_plan($fakeWeb, $me, $target);
        test('a 0700 shared directory is planned as a repair, not left alone',
            ($plan[0]['state'] ?? '') === 'fix',
            'state=' . ($plan[0]['state'] ?? '?') . ' reason=' . ($plan[0]['reason'] ?? ''));

        install_perm_apply($plan, false);

        clearstatcache(true, $dir);
        $st = stat($dir);
        test('apply really changed the filesystem: mode is now 2770',
            (($st['mode'] ?? 0) & 07777) === 02770,
            sprintf('mode is %04o', ($st['mode'] ?? 0) & 07777));
        test('apply really changed the filesystem: group is now the shared group',
            (int) ($st['gid'] ?? -1) === $gid);

        $after = install_perm_plan($fakeWeb, $me, $target);
        test('re-planning the repaired directory reports it working',
            ($after[0]['state'] ?? '') === 'ok',
            'state=' . ($after[0]['state'] ?? '?') . ' reason=' . ($after[0]['reason'] ?? ''));

        // Preserve first: a second run must not churn a directory that works.
        $results = install_perm_apply($after, false);
        test('a working directory is left alone on the next deploy',
            $results === [],
            'apply must be a no-op when nothing is broken');

        // ── Negative control: the dangerous path is refused ──────────
        $repo = $tmpRoot . '/looks-like-a-checkout';
        mkdir($repo, 0700);
        mkdir($repo . '/.git', 0700);
        $before = stat($repo);
        $rplan  = install_perm_plan($fakeWeb, $me, [[
            'path' => $repo, 'label' => 'carries .git', 'role' => INSTALL_PERM_WEB,
            'purpose' => 'test', 'create' => false]]);
        test('NEGATIVE CONTROL: a directory carrying .git is refused, not repaired',
            ($rplan[0]['state'] ?? '') === 'unsafe',
            'state=' . ($rplan[0]['state'] ?? '?'));

        install_perm_apply($rplan, false);
        clearstatcache(true, $repo);
        $now = stat($repo);
        test('NEGATIVE CONTROL: and it is left byte-for-byte alone',
            (int) $now['uid'] === (int) $before['uid']
            && (int) $now['gid'] === (int) $before['gid']
            && ($now['mode'] & 07777) === ($before['mode'] & 07777));

        // The install root, asked for directly, is refused for the same reason.
        $rootPlan = install_perm_plan($fakeWeb, $me, [[
            'path' => $root, 'label' => 'the install root', 'role' => INSTALL_PERM_WEB,
            'purpose' => 'test', 'create' => false]]);
        test('NEGATIVE CONTROL: the install root itself is refused',
            ($rootPlan[0]['state'] ?? '') === 'unsafe');
    }
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. Real tar: extraction, then the deploy's repair step --\n";

/** Run a command as an argv array — no shell, nothing interpolated. */
function dp_run(array $argv, ?string $cwd = null): array {
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($argv, $spec, $pipes, $cwd);
    if (!is_resource($p)) {
        return ['code' => -1, 'out' => '', 'err' => 'could not start ' . ($argv[0] ?? '?')];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => (string) $out, 'err' => (string) $err];
}

// `tools/deploy.sh` is the maintainer's own deployment script and is
// deliberately EXCLUDED from the public release snapshot — a released copy of
// TicketsCAD has no such file. So its absence is a legitimate state, not a
// failure: this section can only run where the script exists.
//
// It failed the v4.2.3 public CI run by asserting the file was readable on a
// tree that is not supposed to contain it. A test that cannot run must SKIP
// with a reason, never fail — otherwise the release gate reports a defect that
// does not exist and blocks a security release for no reason.
$deployShPath = $root . '/tools/deploy.sh';
$deploySh     = is_readable($deployShPath)
    ? (string) @file_get_contents($deployShPath)
    : '';

$extract = null;
if ($deploySh === '') {
    skip('the deploy\'s tar extract command could be read out of the script',
        'tools/deploy.sh is not present — expected on a public release snapshot, '
        . 'which excludes the maintainer deployment script');
} else {
    // Take the extract command out of the script rather than restating it here,
    // so this exercises what the deploy actually runs.
    if (preg_match('/^\s*sudo tar\s+(.+)$/m', $deploySh, $m)) {
        $extract = preg_split('/\s+/', trim($m[1]));
    }
    test('the deploy\'s tar extract command could be read out of the script',
        is_array($extract) && in_array('-C', $extract, true),
        'looked for a "sudo tar … -C …" line');
}

if (!$scratchReady || !is_array($extract)) {
    skip('extraction followed by repair', !$isPosix
        ? 'needs a POSIX host and GNU tar; runs in CI'
        : 'no scratch directory or no extract command to reconstruct');
} else {
    $probe = dp_run(['tar', '--version']);
    if ($probe['code'] !== 0) {
        skip('extraction followed by repair', 'tar is not available on this host');
    } else {
        // Stage an archive shaped like `git archive HEAD`: a cache/ directory
        // entry with an ordinary mode, plus a file inside it.
        $stage = $tmpRoot . '/stage';
        mkdir($stage . '/cache', 0755, true);
        file_put_contents($stage . '/cache/.gitignore', "*\n");
        chmod($stage . '/cache', 0755);
        $tarball = $tmpRoot . '/deploy.tar.gz';
        $mk = dp_run(['tar', 'czf', $tarball, '-C', $stage, 'cache']);

        if ($mk['code'] !== 0) {
            skip('extraction followed by repair', 'could not build a test archive: ' . trim($mk['err']));
        } else {
            // Rebuild the deploy's own argv, substituting our paths for the
            // tarball and $WEBROOT. This runs what the script runs.
            $webroot = $tmpRoot . '/webroot';
            $argvOut = ['tar'];
            $prevWasC = false;
            foreach ($extract as $a) {
                if ($prevWasC)                 { $argvOut[] = $webroot; $prevWasC = false; continue; }
                if ($a === '-C')               { $argvOut[] = '-C'; $prevWasC = true; continue; }
                if (strpos($a, '/tmp/') === 0) { $argvOut[] = $tarball; continue; }
                $argvOut[] = $a;
            }

            // WHAT IS BEING PROVED, AND WHY IT IS NOT "the tar flag works".
            //
            // The deploy extracts as root, and --no-overwrite-dir does preserve
            // owner, group and mode there — measured on GNU tar 1.35, a
            // directory at ejosterberg:www-data 2770 came through untouched,
            // where without the flag it became ejosterberg:ejosterberg 0755.
            // But this test runs as an ordinary user, and a non-root tar
            // behaves differently: it cannot chown at all, and it drops the
            // setgid bit even with the flag (2770 -> 0770 on the same tar).
            //
            // So asserting "extraction preserves the mode" would be asserting
            // something that is only true in a configuration this test is not
            // running in — the exact mistake this project has a rule about.
            // What is asserted instead is the guarantee the deploy actually
            // makes: whatever the extraction does to a directory, the repair
            // step that follows it puts the directory back into a working
            // state. That is true as root and as anyone else.
            $dir = $webroot . '/cache';
            mkdir($dir, 0700, true);
            chown($dir, $myUid);
            chgrp($dir, $gid);
            chmod($dir, 02770);
            clearstatcache(true, $dir);

            $scratch = [['path' => $dir, 'label' => 'cache', 'role' => INSTALL_PERM_SHARED,
                         'purpose' => 'test', 'create' => false]];

            $before = install_perm_plan($fakeWeb, $me, $scratch);
            test('the host starts in a working state before the deploy runs',
                ($before[0]['state'] ?? '') === 'ok',
                'state=' . ($before[0]['state'] ?? '?'));

            $rx = dp_run($argvOut);
            test('the deploy\'s own extract command runs cleanly over the existing tree',
                $rx['code'] === 0, trim($rx['err']));
            test('the extracted file really landed', is_file($dir . '/.gitignore'));

            clearstatcache(true, $dir);
            $afterTar = install_perm_plan($fakeWeb, $me, $scratch);

            if (($afterTar[0]['state'] ?? '') === 'ok') {
                // Nothing to repair means the repair assertion below would pass
                // without proving anything. Say so instead of banking a pass.
                skip('the repair after extraction',
                     sprintf('this host\'s tar left the directory working (mode %04o), '
                           . 'so there is nothing for the repair step to prove',
                           fileperms($dir) & 07777));
            } else {
                test('NEGATIVE CONTROL: extraction alone leaves the directory broken',
                    ($afterTar[0]['state'] ?? '') === 'fix',
                    sprintf('mode is now %04o — %s', fileperms($dir) & 07777,
                            $afterTar[0]['reason'] ?? ''));

                install_perm_apply($afterTar, false);
                clearstatcache(true, $dir);
                $afterFix = install_perm_plan($fakeWeb, $me, $scratch);
                test('and the deploy\'s repair step puts it back into a working state',
                    ($afterFix[0]['state'] ?? '') === 'ok',
                    sprintf('mode is %04o — %s', fileperms($dir) & 07777,
                            $afterFix[0]['reason'] ?? ''));
                test('specifically: setgid is back, so new archives keep the shared group',
                    (fileperms($dir) & 02000) === 02000);
            }
        }
    }
}

// ── Clean up ─────────────────────────────────────────────────────────
if ($tmpRoot !== null && is_dir($tmpRoot)) {
    $rm = function (string $d) use (&$rm) {
        foreach (array_diff((array) @scandir($d), ['.', '..']) as $e) {
            $p = $d . '/' . $e;
            if (is_dir($p) && !is_link($p)) { $rm($p); } else { @unlink($p); }
        }
        @rmdir($d);
    };
    @chmod($tmpRoot, 0700);
    $rm($tmpRoot);
}

if ($skipped > 0) {
    echo "\n($skipped check(s) skipped on this host — see the SKIP lines above.)\n";
}
echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
