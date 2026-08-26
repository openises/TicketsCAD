<?php
/**
 * GH#114 (rjonesbsink, 2026-08-25) — user.login ("Last login", Settings ->
 * User Accounts) was read and displayed on screen but never written by
 * any code path: login.php's complete_login() only ever recorded the
 * timestamp into $_SESSION['login_at'], which dies with the session.
 * Every account showed "never" regardless of real login history.
 * tools/dead_control_audit.php had already caught this (phantom:user.login)
 * but it was wrongly baselined as a false positive alongside several
 * genuinely-ambiguous columns.
 *
 * Two pieces, both tested here:
 *   1. complete_login() now writes user.login on every successful login
 *      — driven through the REAL function, not a hand-simulated UPDATE.
 *   2. sql/run_gh114_backfill_user_login.php recovers historical values
 *      for accounts that logged in before this fix existed, from
 *      newui_audit_log's existing auth/login history.
 *
 * @requires-db
 * Usage: php tests/test_gh114_user_login_write.php
 */

// Only config.php + inc/db.php are needed in THIS process — complete_login()
// itself is driven in an isolated subprocess in section 3 below, since it
// ends with header('Location: ...'); exit; by design and would otherwise
// terminate this whole test script before section 4 ever runs.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#114: user.login is written on login and backfillable from audit history ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Static contract: complete_login() writes user.login. ───────────────
$loginSrc = file_get_contents($root . '/login.php');
if (preg_match('/function complete_login[\s\S]{0,5000}UPDATE\s*"\s*\.\s*db_table\(\s*[\'"]user[\'"]\s*\)\s*\.\s*"\s*SET\s*`login`\s*=\s*NOW\(\)/', $loginSrc)) {
    ok('complete_login() writes user.login = NOW() on every successful login');
} else {
    bad('complete_login() does not appear to write user.login', 'GH#114 regression — Settings -> User Accounts would show "never" again');
}

// ── 2. The stale phantom-column baseline entry is gone. ───────────────────
$baseline = file_get_contents($root . '/tools/dead_control_phantom_baseline.txt');
if (preg_match('/^phantom:user\.login\s*$/m', $baseline)) {
    bad('tools/dead_control_phantom_baseline.txt still lists phantom:user.login', 'the column now has a real writer — this baseline entry is stale and should have been removed in the same fix');
} else {
    ok('phantom:user.login has been removed from the audit baseline (the column now has a real writer)');
}

// ── 3. Functional: drive the REAL complete_login() through a throwaway
// fixture user and confirm user.login is actually written to a fresh
// timestamp — not just that the source text looks right. ─────────────────
try {
    $testUser = 'gh114_test_' . bin2hex(random_bytes(4));
    db_query(
        "INSERT INTO `{$prefix}user` (`user`, `passwd`, `login`) VALUES (?, ?, NULL)",
        [$testUser, password_hash('x', PASSWORD_BCRYPT)]
    );
    $uid = (int) db_insert_id();

    try {
        $before = db_fetch_value("SELECT `login` FROM `{$prefix}user` WHERE id = ?", [$uid]);
        if ($before !== null) {
            bad('fixture user starts with login IS NULL as expected', 'setup assumption violated — got ' . var_export($before, true));
        } else {
            ok('fixture user starts with login IS NULL, matching a never-logged-in account');
        }

        // complete_login() ends with header('Location: ...'); exit; by
        // design (it's meant to be the tail end of a real HTTP request) —
        // calling it in-process would terminate this whole test script
        // before section 4 ever runs. Drive it in an isolated subprocess
        // instead (same pattern this project already uses for functions
        // with exit()/header() side effects, e.g.
        // test_https_enforcement.php's own subprocess probes) and check
        // the persisted DB row from this process afterward.
        $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $code = '<?php '
              . '$_SERVER["REQUEST_METHOD"] = "GET"; '
              . 'error_reporting(E_ERROR | E_PARSE); '
              . 'ob_start(); require ' . var_export($root . '/login.php', true) . '; ob_end_clean(); '
              . '@complete_login(["id" => ' . (int) $uid . ', "user" => ' . var_export($testUser, true) . '], "day", "127.0.0.1");';
        $tmp = sys_get_temp_dir() . '/newui-gh114-probe-' . getmypid() . '-' . mt_rand() . '.php';
        file_put_contents($tmp, $code);
        @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        $after = db_fetch_value("SELECT `login` FROM `{$prefix}user` WHERE id = ?", [$uid]);
        if ($after !== null && strtotime((string) $after) > (time() - 30)) {
            ok('complete_login() wrote a fresh user.login timestamp for the fixture user (driven in an isolated subprocess, since the function exits by design)');
        } else {
            bad('complete_login() did not write a fresh user.login timestamp', 'got ' . var_export($after, true));
        }
    } finally {
        db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$uid]);
    }
} catch (Throwable $e) {
    echo "SKIP: could not drive complete_login() against a fixture user (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

// ── 4. Functional: the backfill migration recovers a value from audit
// history for an account whose login.user.login is NULL but which has a
// real auth/login row — driving the REAL migration script, not
// reimplementing its query. ────────────────────────────────────────────
try {
    $testUser2 = 'gh114_backfill_' . bin2hex(random_bytes(4));
    db_query(
        "INSERT INTO `{$prefix}user` (`user`, `passwd`, `login`) VALUES (?, ?, NULL)",
        [$testUser2, password_hash('x', PASSWORD_BCRYPT)]
    );
    $uid2 = (int) db_insert_id();
    $auditTime = date('Y-m-d H:i:s', time() - 3600);

    try {
        db_query(
            "INSERT INTO `{$prefix}newui_audit_log`
                (`event_time`, `user_id`, `user_name`, `ip_address`, `category`, `activity`, `severity`, `summary`)
             VALUES (?, ?, ?, '127.0.0.1', 'auth', 'login', 1, 'Login successful')",
            [$auditTime, $uid2, $testUser2]
        );

        $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $out = (string) @shell_exec(
            escapeshellarg($php) . ' ' . escapeshellarg($root . '/sql/run_gh114_backfill_user_login.php') . ' 2>&1'
        );
        $backfilled = db_fetch_value("SELECT `login` FROM `{$prefix}user` WHERE id = ?", [$uid2]);
        if ($backfilled !== null && abs(strtotime((string) $backfilled) - strtotime($auditTime)) < 2) {
            ok('sql/run_gh114_backfill_user_login.php recovered the fixture user\'s login timestamp from its audit/login history');
        } else {
            bad('the backfill script did not recover the expected timestamp', "expected ~{$auditTime}, got " . var_export($backfilled, true) . "\nscript output: " . trim($out));
        }
    } finally {
        db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE user_id = ? AND category = 'auth' AND activity = 'login' AND summary = 'Login successful' AND event_time = ?", [$uid2, $auditTime]);
        db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$uid2]);
    }
} catch (Throwable $e) {
    echo "SKIP: could not drive the backfill migration against a fixture user (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
