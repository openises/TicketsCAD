<?php
/**
 * Gate: GEOCODE_CACHE_DIR / TILE_CACHE_DIR must be created owned by the web
 * server, and a broken cache directory must be VISIBLE, not silent.
 *
 * ── THE BUG (found during an internal audit, 2026-08-19) ──────────────────
 *
 * `geocode_cache_dir()` (inc/geocode.php) lazily creates GEOCODE_CACHE_DIR —
 * a directory ABOVE the web root, per served_dir_above_root() — the first
 * time anything calls it. On your-server.example.com, a CLI/SSH process
 * running as the `ejosterberg` Linux user won that race before any real
 * Apache request did, so the directory came up owned ejosterberg:ejosterberg
 * mode 0700. The real web server process (www-data) could not read or write
 * a single byte of it from that point on. `geocode_cache_write()` is
 * documented "best effort: a cache we cannot write is not an error" — so
 * this failed with NO log line, NO user-visible symptom, and every geocode
 * lookup on that host silently bypassed the server-side cache Nominatim's
 * usage policy requires, for weeks, until an unrelated audit found it by
 * hand over SSH.
 *
 * `tile_cache_dir()` (inc/tile-proxy.php) has the identical shape (mode 0755
 * instead of 0700, same lazy-first-caller race).
 *
 * ── THE TWO-PART FIX THIS FILE GATES ───────────────────────────────────────
 *
 *   1. PROVISIONING: inc/install-permissions.php's install_perm_targets()
 *      now creates both directories ('create' => true, was false) via
 *      tools/fix-permissions.php — already run on every tools/deploy.sh
 *      deploy — so the web server owns them BEFORE either kind of process
 *      can race to create them the wrong way. Section 1 below.
 *
 *   2. VISIBILITY: inc/health-check.php's health_check_geocode_cache_writable()
 *      / health_check_tile_cache_writable() report a real write test —
 *      exists+writable=ok, exists+NOT writable=critical (the exact bug
 *      above), missing+parent-writable=warn (a cache that has simply never
 *      been touched is normal, not a fault), missing+parent-not-writable=
 *      critical, account undetermined=unknown (never ok, never critical).
 *      Section 2 below reproduces every one of those states, including the
 *      production bug itself: an existing directory a DIFFERENT account
 *      cannot write into.
 *
 * Ownership/identity sections need a POSIX host and are skipped on Windows,
 * following the same convention as tests/test_deploy_permissions.php and
 * tests/test_health_web_user.php — CI is Linux, so they run there.
 *
 * Usage: php tests/test_cache_dir_writable.php
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

echo "=== Cache directories must be provisioned correctly, and a broken one must be visible ===\n\n";

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Provisioning: install_perm_targets() must proactively create these --\n";

$targets = install_perm_targets();
$byLabel = [];
foreach ($targets as $t) {
    $byLabel[$t['label']] = $t;
}

test('GEOCODE_CACHE_DIR is covered by the deploy-time permission policy',
    isset($byLabel['GEOCODE_CACHE_DIR']));
test('GEOCODE_CACHE_DIR is classified web-server-only',
    ($byLabel['GEOCODE_CACHE_DIR']['role'] ?? '') === INSTALL_PERM_WEB);
test('THE FIX: GEOCODE_CACHE_DIR is now created proactively, not left to a lazy first caller',
    !empty($byLabel['GEOCODE_CACHE_DIR']['create']),
    'create=false is what let a CLI process win the ownership race on your-server.example.com');

test('TILE_CACHE_DIR is covered by the deploy-time permission policy',
    isset($byLabel['TILE_CACHE_DIR']));
test('TILE_CACHE_DIR is classified web-server-only',
    ($byLabel['TILE_CACHE_DIR']['role'] ?? '') === INSTALL_PERM_WEB);
test('THE FIX: TILE_CACHE_DIR is now created proactively, not left to a lazy first caller',
    !empty($byLabel['TILE_CACHE_DIR']['create']));

// End to end: a fresh (not-yet-existing) directory really gets created,
// owned by the web account, mode 0775 — not merely planned.
if (!$isPosix) {
    skip('install_perm_apply() really creates the directory', 'needs a POSIX host; runs in CI');
} else {
    $groups = @posix_getgroups();
    $myUid  = posix_geteuid();
    if (!is_array($groups) || empty($groups)) {
        skip('install_perm_apply() really creates the directory', 'could not read this account\'s groups');
    } else {
        $tmpRoot = sys_get_temp_dir() . '/newui-cachedir-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($tmpRoot, 0755, true);
        $gid = (int) $groups[0];
        $fakeWeb = ['name' => 'test-web', 'uid' => $myUid + 424242, 'gids' => [$gid], 'determined' => true];
        $me      = ['name' => 'test-op',  'uid' => $myUid, 'gids' => $groups, 'determined' => true];

        $freshDir = $tmpRoot . '/geocode-cache';
        $target   = [['path' => $freshDir, 'label' => 'GEOCODE_CACHE_DIR', 'role' => INSTALL_PERM_WEB,
                      'purpose' => 'test', 'create' => true]];

        $plan = install_perm_plan($fakeWeb, $me, $target);
        test('a missing web-only dir with create=true is planned to be created',
            ($plan[0]['state'] ?? '') === 'create',
            'state=' . ($plan[0]['state'] ?? '?'));

        $applyResult = install_perm_apply($plan, false);
        clearstatcache(true, $freshDir);
        test('the directory now really exists on disk', is_dir($freshDir));
        $st = @stat($freshDir);
        test('mode is 0775 (INSTALL_PERM_MODE_WEB)',
            is_array($st) && (($st['mode'] ?? 0) & 07777) === INSTALL_PERM_MODE_WEB);

        // Ownership: chown() to an account that is not this process (and that
        // this test made up — uid+424242 is not a real account) requires
        // root. That is not a gap in install_perm_apply() — it is the exact
        // reason tools/fix-permissions.php's own docblock says
        // `sudo php tools/fix-permissions.php`, and it is why
        // tools/deploy.sh runs it via sudo on the real hosts. What IS this
        // test's job, run unprivileged: prove the tool is HONEST about it —
        // it must report the chown failure rather than silently claim
        // success, so an operator who forgets `sudo` sees why nothing
        // changed instead of a reassuring "done".
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            test('running as root: it really is owned by the web account',
                is_array($st) && (int) ($st['uid'] ?? -1) === $fakeWeb['uid']);
        } else {
            $applied = $applyResult[0] ?? ['ok' => null, 'detail' => ''];
            test('NOT running as root: chown to a foreign account fails, and says so honestly',
                $applied['ok'] === false && strpos((string) $applied['detail'], 'chown failed') !== false,
                'ok=' . var_export($applied['ok'] ?? null, true) . ' detail=' . ($applied['detail'] ?? ''));
            skip('it is owned by the web account', 'needs root — this process is uid ' .
                (function_exists('posix_geteuid') ? posix_geteuid() : '?') .
                '; the real hosts run this via `sudo php tools/fix-permissions.php`');
        }

        // Clean up.
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
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Visibility: health_check_*_cache_writable() must report a real write test --\n";

require_once __DIR__ . '/../inc/health-check.php';
require_once __DIR__ . '/../inc/geocode.php';
require_once __DIR__ . '/../inc/tile-proxy.php';

test('health_check_geocode_cache_writable() is defined', function_exists('health_check_geocode_cache_writable'));
test('health_check_tile_cache_writable() is defined', function_exists('health_check_tile_cache_writable'));
test('health_check_cache_dir_writable() is defined', function_exists('health_check_cache_dir_writable'));

// (a) Not configured — must never throw, must never claim a verdict.
$notConfigured = health_check_cache_dir_writable('', 'Test cache', 'fix it');
test('an empty dir path is reported checked=false, severity=ok — never a guessed verdict',
    $notConfigured['checked'] === false && $notConfigured['severity'] === 'ok');

// (b) health_check_all() actually wires both checks in.
$all = health_check_all();
test('health_check_all() carries geocode_cache_writable',
    isset($all['geocode_cache_writable']) && array_key_exists('checked', $all['geocode_cache_writable']));
test('health_check_all() carries tile_cache_writable',
    isset($all['tile_cache_writable']) && array_key_exists('checked', $all['tile_cache_writable']));

// (c) On THIS install, right now, the check must never report the ACTUAL
// regression this file exists to catch: a directory that EXISTS but cannot
// be written to (the exact your-server.example.com shape — wrong owner from
// a lazy first caller). "Does not exist yet, and nobody has run
// tools/fix-permissions.php on this checkout" is a legitimate, common state
// — a bare CI runner never runs the deploy-time provisioning step, so its
// nearest ancestor is not writable by the (guessed) web account either — and
// must NOT be conflated with a genuine break. Distinguish the two rather
// than asserting away severity entirely: exists=true + critical is always a
// real finding; exists=false + critical is only ever "never provisioned".
$realGeo  = health_check_geocode_cache_writable();
$realTile = health_check_tile_cache_writable();
foreach ([['GEOCODE_CACHE_DIR', $realGeo], ['TILE_CACHE_DIR', $realTile]] as $pair) {
    [$label, $r] = $pair;
    $existingButBroken = ($r['severity'] ?? '') === 'critical' && ($r['exists'] ?? null) === true;
    test("$label on this host is never reported existing-but-broken (the actual regression)",
        !$existingButBroken,
        'severity=' . ($r['severity'] ?? '?') . ' exists=' . var_export($r['exists'] ?? null, true)
        . ' note=' . ($r['note'] ?? ''));
}
if (!$isPosix) {
    skip('missing-but-creatable is a warning, never critical', 'needs POSIX identity model; runs in CI');
    skip('THE BUG ITSELF: an existing directory the web account cannot write is CRITICAL',
        'needs POSIX identity model; runs in CI');
    skip('a genuinely unwritable parent (dir missing) is critical', 'needs POSIX identity model; runs in CI');
    skip('an undeterminable web account reports unknown, never ok/critical (exists case)',
        'needs POSIX identity model; runs in CI');
    skip('a real write probe leaves no litter behind', 'needs POSIX identity model; runs in CI');
} else {
    $groups = @posix_getgroups();
    $myUid  = posix_geteuid();
    $tmpRoot = sys_get_temp_dir() . '/newui-cachewrite-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($tmpRoot, 0755, true);

    $asMe = [
        'determined' => true, 'is_this_process' => true, 'name' => 'test-web',
        'uid' => $myUid, 'gids' => is_array($groups) ? $groups : [], 'basis' => 'injected by tests',
    ];
    // A different account entirely — the shape www-data has relative to a
    // directory a CLI process created: not the owner, not in the group.
    $blind = [
        'determined' => true, 'is_this_process' => false, 'name' => 'www-data',
        'uid' => $myUid + 424242, 'gids' => [424242], 'basis' => 'injected by tests',
    ];
    $undet = [
        'determined' => false, 'is_this_process' => false, 'name' => null,
        'uid' => null, 'gids' => [], 'basis' => null,
    ];

    // (d) Missing directory, parent writable by the account in question ->
    // warn, never critical. A cache nobody has looked up an address into
    // yet is a completely normal state.
    $notYet = $tmpRoot . '/not-created-yet';
    $r = health_check_cache_dir_writable($notYet, 'Test cache', 'fix it', $asMe);
    test('missing-but-creatable is a warning, never critical',
        $r['severity'] === 'warn' && $r['exists'] === false,
        'severity=' . $r['severity']);

    // (e) THE PRODUCTION BUG ITSELF: the directory exists, but the account
    // that would actually be serving requests cannot write into it —
    // exactly your-server.example.com's GEOCODE_CACHE_DIR before the fix.
    $broken = $tmpRoot . '/broken-owner';
    mkdir($broken, 0700);
    // Owned by US (the test process), which stands in for the CLI process
    // that won the race; $blind stands in for www-data, which owns nothing
    // here and is in no group that does either.
    $r = health_check_cache_dir_writable($broken, 'Test cache', 'sudo php tools/fix-permissions.php', $blind);
    test('THE BUG ITSELF: an existing directory the web account cannot write is CRITICAL',
        $r['severity'] === 'critical' && $r['exists'] === true && $r['writable'] === false,
        'severity=' . $r['severity'] . ' note=' . ($r['note'] ?? ''));
    test('the critical note tells the reader what actually happened, not just that it failed',
        strpos((string) $r['note'], 'silently skipped') !== false
        && strpos((string) $r['note'], 'fix-permissions.php') !== false);
    @chmod($broken, 0775);

    // (f) A missing directory whose nearest existing ancestor is ALSO
    // unwritable — can never self-heal, must be critical, not a shrug.
    $unreachable = $tmpRoot . '/locked-parent';
    mkdir($unreachable, 0700);
    $childOfLocked = $unreachable . '/child';
    $r = health_check_cache_dir_writable($childOfLocked, 'Test cache', 'fix it', $blind);
    test('a genuinely unwritable parent (dir missing) is critical',
        $r['severity'] === 'critical' && $r['exists'] === false,
        'severity=' . $r['severity']);
    @chmod($unreachable, 0775);

    // (g) Undetermined account -> unknown, never a guessed ok/critical —
    // exists case (missing case is covered structurally the same way
    // health_check_dirs() already proves this in test_health_web_user.php).
    $existsDir = $tmpRoot . '/exists-ok';
    mkdir($existsDir, 0775);
    $r = health_check_cache_dir_writable($existsDir, 'Test cache', 'fix it', $undet);
    test('an undeterminable web account reports unknown, never ok/critical (exists case)',
        $r['severity'] === 'unknown' && $r['writable'] === null);

    // (h) The real write-probe: writable + is_this_process=true actually
    // writes, reads back and cleans up — and never leaves a stray file.
    $writableDir = $tmpRoot . '/writable';
    mkdir($writableDir, 0775);
    $before = array_diff((array) @scandir($writableDir), ['.', '..']);
    $r = health_check_cache_dir_writable($writableDir, 'Test cache', 'fix it', $asMe);
    $after = array_diff((array) @scandir($writableDir), ['.', '..']);
    test('a real write probe reports ok when the directory genuinely works',
        $r['severity'] === 'ok' && $r['writable'] === true);
    test('a real write probe leaves no litter behind',
        empty($before) && empty($after),
        'before=' . implode(',', $before) . ' after=' . implode(',', $after));

    // (i) The identity-correctness guarantee test_health_web_user.php
    // established for health_check_dirs() must hold here too: when we are
    // NOT asking as the account in question, this must NOT attempt (and
    // cannot prove) a real write — it reports from mode bits alone. Prove
    // it by giving $blind a uid that is nonetheless the mode-bit "other"
    // class on a 0775 dir (readable+writable... no, 0775 is rwxrwxr-x, so
    // "other" gets r-x only, no write) so the simulated verdict is
    // correctly "not writable" without ever touching the filesystem.
    $simDir = $tmpRoot . '/simulated';
    mkdir($simDir, 0775);
    $beforeSim = array_diff((array) @scandir($simDir), ['.', '..']);
    $r = health_check_cache_dir_writable($simDir, 'Test cache', 'fix it', $blind);
    $afterSim = array_diff((array) @scandir($simDir), ['.', '..']);
    test('asked as a DIFFERENT account, the verdict is mode-bit-derived (0775, other=r-x) not "ok"',
        $r['severity'] === 'critical' && $r['writable'] === false);
    test('and no write was attempted while impersonating a different account',
        empty($beforeSim) && empty($afterSim));

    // Clean up.
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

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Code-level defense in depth: lazy mkdir() modes match the provisioned mode --\n";

// Not a grep — tokenize so the assertion survives incidental reformatting
// and cannot be satisfied by a mode value living in a comment.
function _cdw_mkdir_modes(string $file): array {
    $modes = [];
    $tokens = token_get_all((string) @file_get_contents($file));
    for ($i = 0; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'mkdir') {
            // Walk forward to the first integer-literal argument (the mode).
            for ($j = $i + 1; $j < min($i + 12, count($tokens)); $j++) {
                $tj = $tokens[$j];
                if (is_array($tj) && $tj[0] === T_LNUMBER) {
                    $modes[] = (int) octdec(ltrim($tj[1], '0'));
                    break;
                }
            }
        }
    }
    return $modes;
}

$geoModes  = _cdw_mkdir_modes(__DIR__ . '/../inc/geocode.php');
$tileModes = _cdw_mkdir_modes(__DIR__ . '/../inc/tile-proxy.php');

test('inc/geocode.php no longer creates the cache directory mode 0700 (owner-only)',
    !in_array(0700, $geoModes, true),
    'modes found: ' . implode(',', array_map(function ($m) { return sprintf('0%o', $m); }, $geoModes)));
test('inc/geocode.php mkdir()s use 0775 (INSTALL_PERM_MODE_WEB), the mode fix-permissions.php converges to',
    in_array(0775, $geoModes, true));
test('inc/tile-proxy.php mkdir()s use 0775 (INSTALL_PERM_MODE_WEB)',
    in_array(0775, $tileModes, true),
    'modes found: ' . implode(',', array_map(function ($m) { return sprintf('0%o', $m); }, $tileModes)));

if ($skipped > 0) {
    echo "\n($skipped check(s) skipped on this host — see the SKIP lines above.)\n";
}
echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
