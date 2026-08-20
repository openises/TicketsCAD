<?php
/**
 * Phase 128 — the legacy `user.level` concept is gone from authorisation.
 *
 * Companion spec: specs/phase-128-eliminate-legacy-levels/{spec,plan,tasks}.md
 *
 * What this proves, in the order it matters:
 *
 *   1. THE PRODUCTION FAILURE. A user whose legacy level disagrees with
 *      their assigned role is authorised by the ROLE — in both
 *      directions. Level 4 + Org Admin is allowed; level 0 ("Super", the
 *      most privileged value the old system had) + Read-Only is refused.
 *      That second case is the one that matters most: a level must not be
 *      able to GRANT anything, ever again.
 *
 *   2. THE MIGRATION IS AUTHORITATIVE. sql/run_rbac_v2.php step A9 is
 *      executed against the real schema — not grepped — with the awkward
 *      cases it used to get wrong: a NULL level, an unrecognised level,
 *      and a user whose only grant has EXPIRED. Afterwards nobody is left
 *      without a role, and the verification step agrees.
 *
 *      A9 had never run successfully on any install: it selected
 *      `u.username`, a column that does not exist (the login column is
 *      `user`), MySQL raised 1054, and the step's catch printed one line
 *      and carried on with exit code 0. That is why levels kept coming
 *      back — the one-time migration was a no-op and the runtime fallback
 *      covered for it.
 *
 *   3. THE FALLBACK IS DELETED, not merely unused. _rbac_legacy_check()
 *      and its allowlists are gone, and rbac_can() contains no read of
 *      $_SESSION['level'].
 *
 *   4. AN UNMIGRATED INSTALL FAILS LOUD. Login refuses, the API edge
 *      returns 503, the navbar carries a banner — all three quoting the
 *      same command.
 *
 * Self-skips the live sections on a virgin / unreachable database.
 *
 * Usage: php tests/test_rbac_legacy_elimination.php
 */

$base = realpath(__DIR__ . '/..');

// Bootstrap BEFORE any output — config.php sets session ini values.
$dbReady = false;
if (is_file($base . '/config.php')) {
    try {
        require_once $base . '/config.php';
        require_once $base . '/inc/rbac.php';
        $pfx = $GLOBALS['db_prefix'] ?? '';
        db_fetch_value("SELECT 1 FROM `{$pfx}role_permissions` LIMIT 1");
        $dbReady = true;
    } catch (Throwable $e) {
        $dbReady = false;
    }
}

$pass = 0; $fail = 0;
function rle_ok(string $m): void { global $pass; $pass++; echo "[PASS] $m\n"; }
function rle_bad(string $m, string $extra = ''): void {
    global $fail; $fail++;
    echo "[FAIL] $m" . ($extra !== '' ? " — $extra" : '') . "\n";
}
function rle_src(string $rel): string {
    global $base;
    $s = @file_get_contents($base . '/' . $rel);
    return $s === false ? '' : $s;
}

echo "=== Phase 128 — legacy level eliminated from authorisation ===\n\n";

// ─────────────────────────────────────────────────────────────────────
// 1. The runtime fallback is DELETED
// ─────────────────────────────────────────────────────────────────────
echo "-- the fallback is gone --\n";

!function_exists('_rbac_legacy_check')
    ? rle_ok('_rbac_legacy_check() no longer exists')
    : rle_bad('_rbac_legacy_check() is still defined — the fallback is back');

$rbacSrc = rle_src('inc/rbac.php');
foreach (['$level2Allowed', '$level3Allowed', '$level4Allowed'] as $sym) {
    (strpos($rbacSrc, $sym . ' =') === false)
        ? rle_ok("$sym allowlist removed from inc/rbac.php")
        : rle_bad("$sym allowlist still present in inc/rbac.php");
}

/**
 * Blank out comments so a note ABOUT the deleted code does not read as
 * the code itself. (The audit tool learned this the same way.)
 */
function rle_code_only(string $src): string {
    $out = '';
    foreach (@token_get_all($src) as $t) {
        $text = is_array($t) ? $t[1] : $t;
        $drop = is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true);
        $out .= $drop ? preg_replace('/[^\n]/', ' ', $text) : $text;
    }
    return $out;
}

// rbac_can() itself must not read the session level. Isolate the function
// body so a comment elsewhere in the file cannot mask a real read.
if (preg_match('/function rbac_can\s*\(.*?\n\}/s', rle_code_only($rbacSrc), $m)) {
    (strpos($m[0], "\$_SESSION['level']") === false)
        ? rle_ok('rbac_can() contains no read of $_SESSION[\'level\']')
        : rle_bad('rbac_can() still reads $_SESSION[\'level\']');
    // ...and the no-grants branch must DENY, not grant.
    (preg_match('/\$cache === false\s*\)\s*\{\s*return false;/', $m[0]) === 1)
        ? rle_ok('rbac_can() denies when grants cannot be loaded')
        : rle_bad('rbac_can() does not clearly deny on an unloadable grant cache');
} else {
    rle_bad('could not isolate rbac_can() in inc/rbac.php');
}

function_exists('rbac_schema_ready')
    ? rle_ok('rbac_schema_ready() exists')
    : rle_bad('rbac_schema_ready() missing');
function_exists('rbac_unmigrated_message')
    ? rle_ok('rbac_unmigrated_message() exists')
    : rle_bad('rbac_unmigrated_message() missing');
// The historical name stays as an alias so upgrade tooling keeps working.
function_exists('_rbac_v2_schema_present')
    ? rle_ok('_rbac_v2_schema_present() kept as a compatibility alias')
    : rle_bad('_rbac_v2_schema_present() alias removed — breaks tools/upgrade/smoke_test.php');

// ─────────────────────────────────────────────────────────────────────
// 2. An unmigrated install fails LOUD, on all three surfaces
// ─────────────────────────────────────────────────────────────────────
echo "\n-- an unmigrated install refuses, and says what to run --\n";

if (function_exists('rbac_unmigrated_message')) {
    $msg = rbac_unmigrated_message();
    (strpos($msg, 'run_migrations.php') !== false)
        ? rle_ok('the refusal names the command that fixes it')
        : rle_bad('the refusal does not name run_migrations.php', $msg);
}

$loginSrc = rle_src('login.php');
(strpos($loginSrc, 'rbac_schema_ready()') !== false
 && strpos($loginSrc, 'rbacUnmigrated') !== false)
    ? rle_ok('login.php refuses when the RBAC schema is absent')
    : rle_bad('login.php does not gate on rbac_schema_ready()');
// Both login steps — password AND second factor.
(substr_count($loginSrc, '$rbacUnmigrated') >= 3)
    ? rle_ok('both login steps (password and 2FA) are behind the refusal')
    : rle_bad('the 2FA step is not behind the migration refusal');

$authSrc = rle_src('api/auth.php');
(strpos($authSrc, 'rbac_unmigrated_message()') !== false && strpos($authSrc, '503') !== false)
    ? rle_ok('api/auth.php returns 503 + the instruction on an unmigrated install')
    : rle_bad('api/auth.php does not fail closed with the instruction');
// A session that predates the breakage must not be silently trusted.
(strpos($authSrc, '$current_level') === false
 || strpos($authSrc, '$current_level    =') === false)
    ? rle_ok('api/auth.php no longer publishes a $current_level global')
    : rle_bad('api/auth.php still defines $current_level for endpoints to gate on');

$navSrc = rle_src('inc/navbar.php');
(strpos($navSrc, 'rbacUnmigratedBanner') !== false)
    ? rle_ok('inc/navbar.php shows a blocking banner to sessions already open')
    : rle_bad('inc/navbar.php has no unmigrated-install banner');

// ─────────────────────────────────────────────────────────────────────
// 3. settings.php — Eric's 2026-07-29 decision
// ─────────────────────────────────────────────────────────────────────
echo "\n-- settings.php is gated on an administrative permission --\n";

$setSrc = rle_src('settings.php');
(strpos($setSrc, "rbac_can('action.manage_config')") !== false)
    ? rle_ok('settings.php gates on action.manage_config')
    : rle_bad('settings.php does not gate on action.manage_config');
(preg_match('/\$userLevel\s*>\s*1/', $setSrc) !== 1)
    ? rle_ok('settings.php no longer gates on the legacy level')
    : rle_bad('settings.php still gates on the legacy level');
// Not screen.settings: Operator holds it, and widening the gate to make
// the error go away is the failure mode Eric named explicitly.
(!preg_match("/rbac_require_screen\(\s*'screen\.settings'/", $setSrc))
    ? rle_ok('settings.php was NOT widened to screen.settings (Operator holds that)')
    : rle_bad('settings.php gates on screen.settings — Operator can now open Settings');

// ─────────────────────────────────────────────────────────────────────
// 4. THE PRODUCTION FAILURE — role beats a disagreeing level
// ─────────────────────────────────────────────────────────────────────
echo "\n-- role beats a disagreeing legacy level (both directions) --\n";

if (!$dbReady) {
    echo "SKIP: database not reachable/seeded — live authorisation checks skipped\n";
} else {
    $pfx  = $GLOBALS['db_prefix'] ?? '';
    $uid  = 999911;                 // synthetic actor, never a real account
    $saved = $_SESSION ?? [];

    /** Grant exactly one role to the probe user and evaluate as them. */
    $actAs = function (int $roleId, int $legacyLevel) use ($pfx, $uid) {
        db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$uid]);
        db_query(
            "INSERT INTO `{$pfx}user_roles`
                (user_id, role_id, org_id, scope_kind, scope_id, granted_at, reason)
             VALUES (?, ?, NULL, 'global', NULL, NOW(), 'test_rbac_legacy_elimination probe')",
            [$uid, $roleId]
        );
        $_SESSION['user_id'] = $uid;
        $_SESSION['level']   = $legacyLevel;
        unset($_SESSION['role_name'], $_SESSION['role_id'], $_SESSION['active_org_id']);
        rbac_reset_cache();
    };

    try {
        // ── 4a. Level says "nobody" (4), role says Org Admin. ────────────
        // This is your deployment, exactly: role_id=2, user.level=4. The old
        // `$_SESSION['level'] > 1` gate refused her on every report.
        $actAs(2, 4);
        rle_ok('probe: role=Org Admin(2), legacy level=4 (the your deployment state)');

        ((int) $_SESSION['level'] > 1)
            ? rle_ok('the legacy gate would still refuse this user (bug reproduced)')
            : rle_bad('probe does not reproduce the legacy denial');

        rbac_can('action.view_reports')
            ? rle_ok('level 4 + Org Admin CAN run reports (role wins)')
            : rle_bad('level 4 + Org Admin still refused reports');
        rbac_can('action.view_audit')
            ? rle_ok('level 4 + Org Admin CAN view the audit log (role wins)')
            : rle_bad('level 4 + Org Admin refused the audit log');
        // action.manage_config is Super-Admin-only by design (sql/rbac.sql's
        // Org Admin exclusion list, present since before this phase) --
        // "role wins" for THIS permission means the role's genuine denial
        // holds, not that level=4 grants it. This assertion originally
        // expected true; it was quietly passing only because of the RBAC
        // canonical-alias privilege leak (2026-08-16 fix, see sql/rbac.sql
        // and tests/test_rbac_canonical_alias_leak.php) that had granted
        // Org Admin action.manage_config's canonical alias -- i.e. this
        // test was unknowingly asserting the bug as correct behaviour.
        // is_admin()'s own documented contract (inc/rbac.php) is
        // `is_super OR rbac_can('action.manage_config')`, so it must be
        // false here too: a pure Org Admin is neither.
        !rbac_can('action.manage_config')
            ? rle_ok('level 4 + Org Admin is correctly REFUSED Settings (role wins, and the role withholds it)')
            : rle_bad('level 4 + Org Admin was granted action.manage_config -- Super-Admin-only permission leaked to Org Admin');
        !is_admin()
            ? rle_ok('is_admin() is false for level 4 + Org Admin (neither is_super nor action.manage_config)')
            : rle_bad('is_admin() true for an Org Admin holding neither is_super nor action.manage_config');

        // ── 4b. The inverse, and the one that actually matters. ──────────
        // Level 0 was "Super" — the most privileged value the old system
        // had, and the value _rbac_legacy_check() used to answer `true`
        // to for EVERY permission. With only a Read-Only grant the answer
        // must be no. A level may never grant.
        $actAs(5, 0);
        rle_ok('probe: role=Read-Only(5), legacy level=0 ("Super" in the old system)');

        !rbac_can('action.manage_config')
            ? rle_ok('level 0 + Read-Only CANNOT open Settings (level does not grant)')
            : rle_bad('level 0 granted Settings to a Read-Only user — level still authorises');
        !rbac_can('action.view_reports')
            ? rle_ok('level 0 + Read-Only CANNOT run reports')
            : rle_bad('level 0 granted reports to a Read-Only user');
        !rbac_can('action.manage_roles')
            ? rle_ok('level 0 + Read-Only CANNOT manage roles')
            : rle_bad('level 0 granted role management to a Read-Only user');
        !is_admin()
            ? rle_ok('is_admin() is false for level 0 + Read-Only')
            : rle_bad('is_admin() true purely because the legacy level said 0');

        // ── 4c. And with NO session level at all, the role still decides.
        $actAs(1, 99);
        unset($_SESSION['level']);
        rbac_reset_cache();
        is_admin()
            ? rle_ok('a Super Admin with no $_SESSION[\'level\'] at all is still admin')
            : rle_bad('authorisation depends on $_SESSION[\'level\'] being present');
    } catch (Throwable $e) {
        rle_bad('live authorisation checks errored', $e->getMessage());
    } finally {
        try { db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$uid]); }
        catch (Throwable $e) { /* nothing to clean */ }
        $_SESSION = $saved;
        if (function_exists('rbac_reset_cache')) rbac_reset_cache();
    }
}

// ─────────────────────────────────────────────────────────────────────
// 5. The migration is authoritative — executed, not grepped
// ─────────────────────────────────────────────────────────────────────
echo "\n-- the one-time level->role migration actually runs --\n";

// Comments in this file discuss the old broken query by name, so compare
// the CODE, not the prose.
$mig     = rle_src('sql/run_rbac_v2.php');
$migCode = rle_code_only($mig);
(strpos($migCode, 'u.username') === false)
    ? rle_ok('A9 no longer selects the non-existent u.username column')
    : rle_bad('A9 still selects u.username — it will raise MySQL 1054 and no-op');
// The real login column, quoted because `user` is a reserved word.
(strpos($migCode, 'u.`user` AS username') !== false)
    ? rle_ok('A9 selects the real login column, u.`user`')
    : rle_bad('A9 does not select u.`user` — check the orphan query');
(strpos($mig, 'VERIFY every user holds an active role') !== false)
    ? rle_ok('A9b verification step present')
    : rle_bad('A9b verification step missing — the migration does not check its own outcome');
(strpos($mig, 'rrbv2_critical_failures') !== false)
    ? rle_ok('the runner records critical failures and exits non-zero')
    : rle_bad('the runner still swallows every failure with exit code 0');

if (!$dbReady) {
    echo "SKIP: database not reachable/seeded — live migration run skipped\n";
} else {
    $pfx = $GLOBALS['db_prefix'] ?? '';
    // Users the old A9 would have mishandled, if it had ever run at all.
    // (`user`.`level` is NOT NULL DEFAULT 0 in base_schema.sql, so a NULL
    // cannot be inserted here — the migration's NULL handling is defensive
    // cover for prefixed/older schemas and is exercised by inspection, not
    // by a row we are unable to create.)
    $cases = [
        999921 => ['level' => 0,  'want' => 1, 'why' => 'level 0 maps to Super Admin'],
        999922 => ['level' => 1,  'want' => 2, 'why' => 'level 1 maps to Org Admin'],
        999923 => ['level' => 77, 'want' => 5, 'why' => 'an unrecognised level lands on Read-Only'],
    ];
    $expiredUid = 999924;   // has a grant, but it lapsed — still an orphan
    $made = [];

    try {
        foreach ($cases as $id => $c) {
            db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$id]);
            db_query("DELETE FROM `{$pfx}user` WHERE id = ?", [$id]);
            db_query(
                "INSERT INTO `{$pfx}user` (id, `user`, `passwd`, `level`)
                 VALUES (?, ?, '', ?)",
                [$id, 'phase128_probe_' . $id, $c['level']]
            );
            $made[] = $id;
        }
        // The expired-grant case: A9's old `LEFT JOIN ... WHERE ur.id IS
        // NULL` counted this user as already migrated, because a row
        // existed — even though rbac_user_roles() returns nothing for
        // them and they hold no permissions at all.
        db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$expiredUid]);
        db_query("DELETE FROM `{$pfx}user` WHERE id = ?", [$expiredUid]);
        db_query(
            "INSERT INTO `{$pfx}user` (id, `user`, `passwd`, `level`) VALUES (?, ?, '', 2)",
            [$expiredUid, 'phase128_probe_expired']
        );
        db_query(
            "INSERT INTO `{$pfx}user_roles`
                (user_id, role_id, org_id, scope_kind, scope_id, granted_at, expires_at, reason)
             VALUES (?, 3, NULL, 'global', NULL, NOW(), DATE_SUB(NOW(), INTERVAL 1 DAY), 'expired probe')",
            [$expiredUid]
        );
        $made[] = $expiredUid;

        // Run the real migration as its own process, exactly as
        // sql/run_migrations.php does.
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg($base . '/sql/run_rbac_v2.php') . ' 2>&1', $out, $code);
        $text = implode("\n", $out);

        ($code === 0)
            ? rle_ok('sql/run_rbac_v2.php exits 0 on a healthy run')
            : rle_bad('sql/run_rbac_v2.php exited non-zero', 'exit ' . $code . "\n" . $text);
        (strpos($text, '[fail] legacy users') === false)
            ? rle_ok('the level->role step did not fail')
            : rle_bad('the level->role step failed', $text);

        foreach ($cases as $id => $c) {
            $got = (int) db_fetch_value(
                "SELECT role_id FROM `{$pfx}user_roles` WHERE user_id = ? LIMIT 1", [$id]
            );
            ($got === $c['want'])
                ? rle_ok('migration: ' . $c['why'])
                : rle_bad('migration: ' . $c['why'], "got role_id={$got}, want {$c['want']}");
        }

        $expiredRoles = db_fetch_all(
            "SELECT role_id FROM `{$pfx}user_roles`
              WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())",
            [$expiredUid]
        );
        (count($expiredRoles) > 0)
            ? rle_ok('migration: a user whose only grant EXPIRED is re-granted, not skipped')
            : rle_bad('migration: an expired-grant user was treated as already migrated');

        // Per-user reporting — Eric asked for the migration to say what it did.
        (strpos($text, 'phase128_probe_') !== false || strpos($text, 'no orphans found') !== false)
            ? rle_ok('the migration reports per user what it did')
            : rle_bad('the migration produced no per-user report', $text);

        // And the verification agrees: nobody left behind.
        $orphans = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$pfx}user` u
              WHERE NOT EXISTS (
                    SELECT 1 FROM `{$pfx}user_roles` ur
                     WHERE ur.user_id = u.id
                       AND (ur.expires_at IS NULL OR ur.expires_at > NOW()))"
        );
        ($orphans === 0)
            ? rle_ok('after the migration, every user holds an active role')
            : rle_bad('users remain with no active role after the migration', (string) $orphans);

        // Idempotent: a second run changes nothing and still exits 0.
        $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$pfx}user_roles`");
        $out2 = [];
        exec(escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg($base . '/sql/run_rbac_v2.php') . ' 2>&1', $out2, $code2);
        $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$pfx}user_roles`");
        ($code2 === 0 && $before === $after)
            ? rle_ok('re-running the migration is idempotent (no duplicate grants)')
            : rle_bad('re-running the migration was not idempotent',
                      "exit {$code2}, grants {$before} -> {$after}");
    } catch (Throwable $e) {
        rle_bad('live migration run errored', $e->getMessage());
    } finally {
        foreach ($made as $id) {
            try {
                db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$id]);
                db_query("DELETE FROM `{$pfx}user` WHERE id = ?", [$id]);
            } catch (Throwable $e) { /* nothing to clean */ }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// 6. user.level survives as a COLUMN — we are not dropping data
// ─────────────────────────────────────────────────────────────────────
echo "\n-- the column stays; only the authorisation goes --\n";

if (!$dbReady) {
    echo "SKIP: database not reachable — column check skipped\n";
} else {
    $pfx = $GLOBALS['db_prefix'] ?? '';
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'level'",
        [$pfx . 'user']
    );
    !empty($col)
        ? rle_ok('user.level still exists (the migration reads it; dropping data is not reversible)')
        : rle_bad('user.level was dropped — the migration can no longer run on an upgrade');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
