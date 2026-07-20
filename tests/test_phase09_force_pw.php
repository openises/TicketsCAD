<?php
/**
 * Phase 9 force-password-change — regression tests.
 *
 * Covers:
 *   - migration is idempotent
 *   - user.must_change_password column + index present
 *   - settings.force_pw_change_for_new_users seeded with default '1'
 *   - inc/force-pw-change.php exposes force_pw_change_redirect()
 *     and handles the allow-list scripts correctly
 *   - login.php source wires the session flag
 *   - api/profile.php source clears the flag atomically and returns
 *     forced_change_cleared
 *   - api/auth.php source rejects non-profile endpoints with 423
 *   - api/config-admin.php source handles the must_change_password
 *     field on user create / update
 *   - settings.php UI has the system toggle + per-user checkbox
 *   - assets/js/config.js wires the toggle into the user form
 *   - 5-language caption seeds are present
 *   - all 34 authenticated page entry files call force_pw_change_redirect()
 *     (so a forced user can't slip through any of them)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/force-pw-change.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 9 force-password-change Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Schema ────────────────────────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'must_change_password'",
        [$prefix . 'user']
    );
    if ($col && stripos($col['COLUMN_TYPE'], 'tinyint') !== false) {
        ok('user.must_change_password column exists (tinyint)');
    } else {
        bad('user.must_change_password missing or wrong type', var_export($col, true));
    }
} catch (Exception $e) {
    bad('schema query failed', $e->getMessage());
}

try {
    $idx = db_fetch_one(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND INDEX_NAME   = 'idx_must_change_password'
         LIMIT 1",
        [$prefix . 'user']
    );
    if ($idx) {
        ok('idx_must_change_password index exists');
    } else {
        bad('idx_must_change_password index missing');
    }
} catch (Exception $e) {
    bad('index query failed', $e->getMessage());
}

// ── Setting ──────────────────────────────────────────────────────────────
try {
    $val = db_fetch_value(
        "SELECT value FROM `{$prefix}settings` WHERE name = 'force_pw_change_for_new_users' LIMIT 1"
    );
    if ($val === '1') {
        ok('Setting force_pw_change_for_new_users defaulted to 1 (ON)');
    } elseif ($val !== null) {
        ok('Setting force_pw_change_for_new_users present (value: ' . $val . ')');
    } else {
        bad('Setting force_pw_change_for_new_users missing');
    }
} catch (Exception $e) {
    bad('setting query failed', $e->getMessage());
}

// ── Middleware helper ────────────────────────────────────────────────────
if (function_exists('force_pw_change_redirect')) {
    ok('force_pw_change_redirect() function defined');
} else {
    bad('force_pw_change_redirect() missing');
}

// Verify the helper's allow-list logic via the source. We can't actually
// invoke the helper here (it would issue a header() redirect inside a
// non-HTTP context) — instead source-grep for the script-name checks.
$helperSrc = file_get_contents($base . '/inc/force-pw-change.php');
if (strpos($helperSrc, "'profile.php'") !== false) {
    ok('force_pw_change_redirect() allow-lists profile.php');
} else {
    bad('force_pw_change_redirect() does NOT allow profile.php');
}
if (strpos($helperSrc, "'login.php'") !== false) {
    ok('force_pw_change_redirect() allow-lists login.php (for logout)');
} else {
    bad('force_pw_change_redirect() does NOT allow login.php');
}

// ── login.php wires session flag ─────────────────────────────────────────
$lg = file_get_contents($base . '/login.php');
if (strpos($lg, 'must_change_password') !== false &&
    strpos($lg, "\$_SESSION['must_change_password']") !== false) {
    ok('login.php seeds $_SESSION[must_change_password] from user row');
} else {
    bad('login.php does NOT seed must_change_password session flag');
}

// ── api/profile.php clears the flag + returns forced_change_cleared ──────
$ap = file_get_contents($base . '/api/profile.php');
if (strpos($ap, 'must_change_password = 0') !== false) {
    ok('api/profile.php clears must_change_password in the password UPDATE');
} else {
    bad('api/profile.php does NOT clear the flag atomically');
}
if (strpos($ap, 'forced_change_cleared') !== false) {
    ok('api/profile.php returns forced_change_cleared in JSON response');
} else {
    bad('api/profile.php does NOT return forced_change_cleared');
}

// ── api/auth.php rejects non-profile endpoints when forced ───────────────
$apa = file_get_contents($base . '/api/auth.php');
if (strpos($apa, 'force_pw_change') !== false &&
    strpos($apa, '423') !== false) {
    ok('api/auth.php returns 423 with code force_pw_change when locked');
} else {
    bad('api/auth.php does NOT enforce force-pw-change at API edge');
}

// ── api/config-admin.php handles must_change_password field ──────────────
$ac = file_get_contents($base . '/api/config-admin.php');
if (strpos($ac, "force_pw_change_for_new_users") !== false &&
    strpos($ac, '$forceChangePw') !== false) {
    ok('api/config-admin.php reads system setting + per-user override');
} else {
    bad('api/config-admin.php does NOT handle must_change_password');
}
if (strpos($ac, 'SET `must_change_password`') !== false) {
    ok('api/config-admin.php writes must_change_password on save');
} else {
    bad('api/config-admin.php does NOT write must_change_password');
}

// ── tools/create_admin.php arms the first-login forced change ────────────
// (Regression: the script printed "first login will prompt to change
//  password" but never set the flag — fixed 2026-06-21.)
$ca = file_get_contents($base . '/tools/create_admin.php');
if (strpos($ca, 'must_change_password') !== false &&
    strpos($ca, '$hasMustChange') !== false) {
    ok('create_admin.php sets must_change_password (column-guarded)');
} else {
    bad('create_admin.php does NOT set must_change_password on create');
}
if (strpos($ca, 'must_change_password = 1') !== false) {
    ok('create_admin.php --force path re-arms must_change_password');
} else {
    bad('create_admin.php --force path does NOT set must_change_password');
}

// ── settings.php has the UI ─────────────────────────────────────────────
$st = file_get_contents($base . '/settings.php');
if (strpos($st, 'id="setForcePwChangeNew"') !== false) {
    ok('settings.php Login Settings panel has system-wide toggle');
} else {
    bad('settings.php missing system-wide toggle');
}
if (strpos($st, 'id="userForcePw"') !== false) {
    ok('settings.php User Accounts form has per-user checkbox');
} else {
    bad('settings.php missing per-user checkbox');
}

// ── config.js wires the toggle ──────────────────────────────────────────
$cj = file_get_contents($base . '/assets/js/config.js');
if (strpos($cj, 'userForcePw') !== false &&
    strpos($cj, 'must_change_password') !== false) {
    ok('config.js wires userForcePw checkbox into user form');
} else {
    bad('config.js does NOT wire userForcePw');
}

// ── profile.php has the forced-mode banner + tab lockdown ────────────────
$pp = file_get_contents($base . '/profile.php');
if (strpos($pp, '$forcePwMode') !== false &&
    strpos($pp, "force_pw.banner_title") !== false) {
    ok('profile.php detects forced mode and shows banner');
} else {
    bad('profile.php does NOT have forced-mode wiring');
}
if (strpos($pp, "force_pw'] === '1'") !== false ||
    strpos($pp, 'force_pw=1') !== false) {
    ok('profile.php honours ?force_pw=1 URL parameter');
} else {
    bad('profile.php does NOT honour ?force_pw=1');
}

// ── Caption seeds in 5 languages ────────────────────────────────────────
foreach (['en','de','nl','fr','es'] as $lang) {
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}captions_i18n` WHERE caption_key=? AND lang=?",
            ['force_pw.banner_title', $lang]
        );
        if ($v) {
            ok("Caption force_pw.banner_title [{$lang}]: {$v}");
        } else {
            bad("Caption force_pw.banner_title [{$lang}] missing");
        }
    } catch (Exception $e) {
        bad("Caption query [{$lang}]", $e->getMessage());
    }
}

// ── All authenticated pages call force_pw_change_redirect() ─────────────
$pages = [
    'index.php', 'profile.php', 'new-incident.php', 'roster.php',
    'incident-list.php', 'incident-detail.php',
    'units.php', 'unit-detail.php', 'unit-edit.php',
    'facilities.php', 'facility-detail.php', 'facility-edit.php',
    'facility-board.php', 'teams.php', 'scheduling.php',
    'messaging.php', 'callboard.php', 'constituents.php',
    'search.php', 'sop.php', 'reports.php',
    'help.php', 'about.php', 'quick-start.php', 'links.php',
    'mobile.php', 'ics-forms.php', 'settings.php',
    'status.php', 'status-time.php', 'roles.php',
    'time-approvals.php', 'import-export.php', 'situation.php',
];
$missingPages = [];
foreach ($pages as $page) {
    $src = file_get_contents($base . '/' . $page);
    if ($src === false || strpos($src, 'force_pw_change_redirect()') === false) {
        $missingPages[] = $page;
    }
}
if (empty($missingPages)) {
    ok('All 34 authenticated pages call force_pw_change_redirect()');
} else {
    bad('Pages missing force_pw_change_redirect()', implode(', ', $missingPages));
}

// ── Migration runner ────────────────────────────────────────────────────
$mig = $base . '/sql/run_force_pw_change.php';
if (file_exists($mig)) {
    ok('sql/run_force_pw_change.php exists');
    $migSrc = file_get_contents($mig);
    if (strpos($migSrc, 'information_schema') !== false &&
        strpos($migSrc, 'INSERT IGNORE') !== false) {
        ok('migration is idempotent (info-schema guards + INSERT IGNORE)');
    } else {
        bad('migration missing idempotency guards');
    }
} else {
    bad('migration runner missing');
}

// ── Summary ────────────────────────────────────────────────────────────
echo "\n";
echo "===========================================\n";
echo "Phase 9 force-pw: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
