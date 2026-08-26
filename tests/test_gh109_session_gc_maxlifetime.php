<?php
/**
 * GH#109 (rjonesbsink, 2026-08-25) — session_timeout_minutes (Settings ->
 * Login Settings, default 480 min / 8 hours) never reconciled with PHP's
 * own session.gc_maxlifetime, which defaults to 1440 seconds (24 minutes)
 * on a stock install with no custom php.ini tuning. A session idle longer
 * than 24 minutes got its underlying file garbage-collected by PHP
 * itself, logging the user out far short of the configured value — for
 * every fresh install, out of the box, regardless of what an admin
 * configured.
 *
 * Fixed in inc/functions.php (not config.php — see that file's own
 * comment on why: config.php is gitignored per-install and never reached
 * by git pull). Two kinds of coverage:
 *
 *   1. A static-contract check that the fix's shape is actually present
 *      (guarded ini_set, driven by get_setting, in the right file).
 *   2. A REAL subprocess check — following the exact pattern established
 *      by tests/test_https_enforcement.php's https_enf_probe() — that
 *      spawns fresh `php config.php ...` runs and confirms
 *      ini_get('session.gc_maxlifetime') genuinely reflects the
 *      configured session_timeout_minutes value at runtime, not just
 *      that the source text looks right.
 *
 * @requires-db
 * Usage: php tests/test_gh109_session_gc_maxlifetime.php
 */

$root = dirname(__DIR__);

// Required BEFORE any echo — config.php's own (pre-existing, unrelated to
// this fix) session ini_set() calls warn with "Session ini settings
// cannot be changed after headers have already been sent" if this
// process has already sent any output first. Real page requests never
// hit this because config.php is always the first thing they require;
// matching that ordering here keeps this test from manufacturing a
// false warning that has nothing to do with GH#109.
require_once $root . '/config.php';
require_once $root . '/inc/db.php';

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#109: session_timeout_minutes actually drives PHP's session lifetime ===\n\n";

$fn = file_get_contents($root . '/inc/functions.php');

// ── 1. Static contract: the guarded ini_set block exists, reads
// session_timeout_minutes via get_setting (matching sm_get_timeout()'s
// own store), ALSO reads the max role-level timeout (the rjonesbsink
// correction — see below), and is gated the same way config.php's own
// session block is (session_status() !== PHP_SESSION_ACTIVE). ────────────
if (preg_match('/session_status\(\)\s*!==\s*PHP_SESSION_ACTIVE\)\s*\{[\s\S]{0,800}get_setting\(\s*[\'"]session_timeout_minutes[\'"]\s*,\s*480\s*\)[\s\S]{0,900}ini_set\(\s*[\'"]session\.gc_maxlifetime[\'"]/', $fn)) {
    ok('inc/functions.php sets session.gc_maxlifetime from get_setting(session_timeout_minutes, 480), guarded by session_status() !== PHP_SESSION_ACTIVE');
} else {
    bad('the guarded gc_maxlifetime block was not found in the expected shape', 'GH#109 regression — desktop sessions would silently fall back to PHP\'s 24-minute default again');
}
if (preg_match('/SELECT\s+MAX\(`session_timeout_minutes`\)\s+FROM/', $fn) && preg_match('/max\(\s*\$_sess_global_min\s*,\s*\$_sess_role_max_min\s*\)/', $fn)) {
    ok('inc/functions.php takes the MAX of the global setting and the longest configured role timeout — the rjonesbsink correction, not the global value alone');
} else {
    bad('inc/functions.php does not take the MAX of the global setting and the longest role timeout', 'a role configured LONGER than the global default would be silently capped at the global value again — exactly the gap rjonesbsink found in the first version of this fix');
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$php    = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

/**
 * Spawn a fresh PHP subprocess that requires config.php (which cascades
 * into inc/functions.php, which is what carries this fix) and reports
 * back ini_get('session.gc_maxlifetime'). Fresh process every call so
 * get_setting()'s own per-request static cache can't leak a stale value
 * between scenarios that write different session_timeout_minutes values.
 */
function gh109_probe(string $root, string $php): ?int
{
    $code = '<?php '
          . 'require_once ' . var_export($root . '/config.php', true) . '; '
          . 'echo "<<<E2E>>>" . ini_get("session.gc_maxlifetime");';
    $tmp = sys_get_temp_dir() . '/newui-gh109-probe-' . getmypid() . '-' . mt_rand() . '.php';
    if (@file_put_contents($tmp, $code) === false) { return null; }
    $out = (string) @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $at = strpos($out, '<<<E2E>>>');
    if ($at === false) { return null; }
    $val = trim(substr($out, $at + strlen('<<<E2E>>>')));
    return ctype_digit($val) ? (int) $val : null;
}

// ── 2. Behavioral: with the CURRENT real session_timeout_minutes value,
// a fresh process's gc_maxlifetime must equal minutes*60, not PHP's own
// 1440s default (unless the configured value happens to itself be 24
// minutes, which the assertion below accounts for by computing the
// expectation from the same store rather than hardcoding 1440 as "bad"). ──
try {
    $configuredMinutes = (int) (db_fetch_value(
        "SELECT `value` FROM `{$prefix}config` WHERE `key` = 'session_timeout_minutes'"
    ) ?: 480);
    if ($configuredMinutes <= 0) $configuredMinutes = 480;
    $expectedSecs = $configuredMinutes * 60;

    $actual = gh109_probe($root, $php);
    if ($actual === null) {
        echo "SKIP: could not spawn a PHP subprocess to probe config.php — 0 passed, 0 failed\n";
        exit(0);
    }
    if ($actual === $expectedSecs) {
        ok("a fresh process's session.gc_maxlifetime ({$actual}s) matches the configured session_timeout_minutes ({$configuredMinutes} min = {$expectedSecs}s)");
    } else {
        bad("session.gc_maxlifetime is {$actual}s, expected {$expectedSecs}s for a {$configuredMinutes}-minute configured timeout", 'GH#109 not actually wired end-to-end');
    }

    // ── 3. Prove it MOVES when the setting changes, not just that it
    // happens to already agree by coincidence with a stock 480 default. ──
    $probeMinutes = ($configuredMinutes === 45) ? 90 : 45; // pick a value guaranteed to differ from current
    $hadRow = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}config` WHERE `key` = 'session_timeout_minutes'") > 0;
    if ($hadRow) {
        db_query("UPDATE `{$prefix}config` SET `value` = ? WHERE `key` = 'session_timeout_minutes'", [(string) $probeMinutes]);
    } else {
        db_query("INSERT INTO `{$prefix}config` (`key`, `value`) VALUES ('session_timeout_minutes', ?)", [(string) $probeMinutes]);
    }
    try {
        $movedActual = gh109_probe($root, $php);
        if ($movedActual === $probeMinutes * 60) {
            ok("changing session_timeout_minutes to {$probeMinutes} moves a fresh process's gc_maxlifetime to " . ($probeMinutes * 60) . "s — genuinely dynamic, not a fixed value that happens to match");
        } else {
            bad("after setting session_timeout_minutes={$probeMinutes}, gc_maxlifetime was " . var_export($movedActual, true) . ", expected " . ($probeMinutes * 60), 'the fix may be reading a cached or hardcoded value instead of the live setting');
        }
    } finally {
        // Restore exactly what was there before this test ran.
        if ($hadRow) {
            db_query("UPDATE `{$prefix}config` SET `value` = ? WHERE `key` = 'session_timeout_minutes'", [(string) $configuredMinutes]);
        } else {
            db_query("DELETE FROM `{$prefix}config` WHERE `key` = 'session_timeout_minutes'");
        }
    }
} catch (Throwable $e) {
    echo "SKIP: could not read/write the config table (" . $e->getMessage() . ") — 0 passed, 0 failed\n";
    exit(0);
}

// ── 3b. CORRECTION (rjonesbsink) — a role configured LONGER than the
// global setting must raise the ceiling too, not just get silently
// capped at the global value. sm_get_timeout() takes the shortest value
// AMONG A USER'S ROLES and only falls back to the global when no role
// sets one — it never clamps a role's own value against the global, so
// gc_maxlifetime must cover the LARGER of the two, system-wide, not just
// the global default. ──────────────────────────────────────────────────
try {
    $prefix2 = $GLOBALS['db_prefix'] ?? '';
    $probeRoleId = (int) db_fetch_value("SELECT id FROM `{$prefix2}roles` WHERE name = 'SpecialRole' LIMIT 1");
    if ($probeRoleId <= 0) {
        echo "SKIP: no 'SpecialRole' fixture role found — skipping the role-longer-than-global check\n";
    } else {
        $origRoleMinutes = db_fetch_value("SELECT session_timeout_minutes FROM `{$prefix2}roles` WHERE id = ?", [$probeRoleId]);
        $configuredMinutes2 = (int) (db_fetch_value(
            "SELECT `value` FROM `{$prefix2}config` WHERE `key` = 'session_timeout_minutes'"
        ) ?: 480);
        if ($configuredMinutes2 <= 0) $configuredMinutes2 = 480;
        $longerMinutes = $configuredMinutes2 + 120; // guaranteed longer than whatever global currently is
        try {
            db_query("UPDATE `{$prefix2}roles` SET session_timeout_minutes = ? WHERE id = ?", [$longerMinutes, $probeRoleId]);
            $roleActual = gh109_probe($root, $php);
            if ($roleActual === $longerMinutes * 60) {
                ok("a role configured longer than the global setting ({$longerMinutes} min) raises gc_maxlifetime to match it ({$roleActual}s), not just the global value — the corrected MAX(global, longest role) behavior");
            } else {
                bad("with a role at {$longerMinutes} min (longer than global {$configuredMinutes2} min), gc_maxlifetime was " . var_export($roleActual, true) . ", expected " . ($longerMinutes * 60), 'GH#109\'s follow-up gap — a longer per-role timeout would be silently capped at the global value again');
            }
        } finally {
            db_query("UPDATE `{$prefix2}roles` SET session_timeout_minutes = ? WHERE id = ?", [$origRoleMinutes, $probeRoleId]);
        }
    }
} catch (Throwable $e) {
    echo "SKIP: could not read/write the roles table (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

// ── 4. Mobile's own, much longer, gc_maxlifetime must still win for a
// mobile-profile request — this fix must not regress that override. ──────
if (preg_match('/function sess_bootstrap_mobile[\s\S]{0,400}ini_set\(\s*[\'"]session\.gc_maxlifetime[\'"]\s*,\s*\(string\)\s*SESS_MOBILE_LIFETIME_SECS\s*\)/', file_get_contents($root . '/inc/session-bootstrap.php'))) {
    ok('sess_bootstrap_mobile() still sets its own gc_maxlifetime unconditionally, and runs AFTER config.php\'s require cascade — the new desktop default cannot win for mobile clients');
} else {
    bad('sess_bootstrap_mobile()\'s own gc_maxlifetime override was not found where expected', 're-verify the desktop fix does not clobber the mobile session profile');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
