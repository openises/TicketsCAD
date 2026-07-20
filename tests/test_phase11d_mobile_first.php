<?php
/**
 * Phase 11d — mobile-first role flag + custom-role fallback fix.
 *
 * Eric reported 2026-06-11: creating a custom role ("Internal Auditor")
 * and assigning it to a user caused the user to be redirected to the
 * mobile interface on login. Root cause: my Phase 11 fallback set
 * user.level=4 for custom roles, which matched the legacy
 * "Field Unit → mobile.php" redirect. Plus, the redirect itself was
 * hardcoded against role_id=6 which wouldn't survive a rename.
 *
 * Tests verify:
 *   - Migration adds roles.mobile_first column
 *   - The role with legacy_level=4 has mobile_first=1
 *   - Other roles have mobile_first=0
 *   - login.php now uses the mobile_first flag (not hardcoded role_id=6)
 *   - api/config-admin.php fallback is 3 (not 4) for custom roles
 *   - assets/js/config.js hidden-input fallback is '3' (not '4')
 *   - api/rbac.php save_role accepts mobile_first
 *   - The role-edit form has the mobile_first checkbox
 *   - The self-heal worked: no users left with user.level=4 because
 *     of a custom-role fallback
 */

require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 11d — mobile_first role flag tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Schema ────────────────────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'mobile_first'",
        [$prefix . 'roles']
    );
    if ($col) {
        ok('roles.mobile_first column exists');
    } else {
        bad('roles.mobile_first column missing');
    }
} catch (Exception $e) {
    bad('mobile_first column check', $e->getMessage());
}

// ── Backfill: Field Unit (legacy_level=4) gets mobile_first=1 ────────
try {
    $rows = db_fetch_all(
        "SELECT id, name, mobile_first FROM `{$prefix}roles` WHERE legacy_level = 4"
    );
    if (empty($rows)) {
        bad('no role with legacy_level=4 found (expected Field Unit)');
    } else {
        $allFlagged = true;
        foreach ($rows as $r) {
            if ((int) $r['mobile_first'] !== 1) {
                $allFlagged = false;
                bad("role id={$r['id']} ({$r['name']}) has legacy_level=4 but mobile_first={$r['mobile_first']}");
            }
        }
        if ($allFlagged) {
            ok('Role(s) with legacy_level=4 have mobile_first=1');
        }
    }
} catch (Exception $e) {
    bad('Field Unit backfill check', $e->getMessage());
}

// ── No other roles got mobile_first=1 by accident ────────────────────
try {
    $cnt = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}roles`
         WHERE mobile_first = 1 AND (legacy_level IS NULL OR legacy_level <> 4)"
    );
    if ($cnt === 0) {
        ok('No other roles accidentally set mobile_first=1');
    } else {
        bad("{$cnt} non-Field-Unit role(s) have mobile_first=1");
    }
} catch (Exception $e) {
    bad('cross-check', $e->getMessage());
}

// ── login.php source uses mobile_first instead of role_id=6 ──────────
$lg = file_get_contents($base . '/login.php');
if (strpos($lg, 'r.mobile_first = 1') !== false) {
    ok('login.php uses mobile_first flag for redirect');
} else {
    bad('login.php does NOT use mobile_first flag');
}
if (strpos($lg, 'ur.role_id = 6 LIMIT 1') === false) {
    ok('login.php no longer hardcodes role_id=6');
} else {
    bad('login.php still hardcodes role_id=6');
}

// ── api/config-admin.php fallback is 3 (not 4) ──────────────────────
$ca = file_get_contents($base . '/api/config-admin.php');
if (strpos($ca, "fallback 4 = Member") === false &&
    strpos($ca, ' ?? 4;') === false) {
    ok('api/config-admin.php no longer uses level=4 fallback for custom roles');
} else {
    bad('api/config-admin.php still has level=4 fallback');
}
if (strpos($ca, '$lvl = $roleLegacyLevel !== null ? (int) $roleLegacyLevel : 3;') !== false) {
    ok('api/config-admin.php uses level=3 (Read-Only) fallback for custom roles');
} else {
    bad('api/config-admin.php does NOT use level=3 fallback');
}

// ── assets/js/config.js fallback is '3' ─────────────────────────────
$cj = file_get_contents($base . '/assets/js/config.js');
if (strpos($cj, "derived.value = '3';") !== false) {
    ok('config.js hidden-input fallback is "3"');
} else {
    bad('config.js does NOT use "3" fallback');
}
if (strpos($cj, "derived.value = '4';") === false) {
    ok('config.js no longer uses "4" fallback');
} else {
    bad('config.js still has "4" fallback');
}

// ── api/rbac.php save_role accepts mobile_first ─────────────────────
$rb = file_get_contents($base . '/api/rbac.php');
if (strpos($rb, "array_key_exists('mobile_first', \$input)") !== false &&
    strpos($rb, 'SET mobile_first = ?') !== false) {
    ok('api/rbac.php save_role accepts + persists mobile_first');
} else {
    bad('api/rbac.php save_role does NOT handle mobile_first');
}

// ── config.js role-edit form has the mobile_first checkbox ─────────
if (strpos($cj, 'id="rbacRoleEditMobileFirst"') !== false &&
    strpos($cj, 'Send users with this role to the mobile interface') !== false) {
    ok('config.js role-edit form has mobile_first checkbox + label');
} else {
    bad('config.js role-edit form missing mobile_first checkbox');
}
if (strpos($cj, "mobile_first: newMobileFirst") !== false) {
    ok('config.js role-edit submit sends mobile_first field');
} else {
    bad('config.js role-edit submit does NOT send mobile_first');
}

// ── Self-heal: no user.level=4 from custom-role fallback ────────────
try {
    $orphans = db_fetch_all(
        "SELECT u.id, u.user, r.name AS role_name
         FROM `{$prefix}user` u
         JOIN `{$prefix}user_roles` ur ON ur.user_id = u.id
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         JOIN `{$prefix}roles` r ON r.id = ur.role_id
         WHERE u.level = 4
           AND r.legacy_level IS NULL
           AND r.mobile_first = 0"
    );
    if (empty($orphans)) {
        ok('Self-heal succeeded: no users on custom roles still have level=4');
    } else {
        bad('Self-heal incomplete: ' . count($orphans) . ' user(s) still wrong');
        foreach ($orphans as $o) echo "      {$o['user']} on '{$o['role_name']}'\n";
    }
} catch (Exception $e) {
    bad('self-heal check', $e->getMessage());
}

// ── Migration runner exists ─────────────────────────────────────────
if (file_exists($base . '/sql/run_phase11d_mobile_first.php')) {
    ok('sql/run_phase11d_mobile_first.php exists');
} else {
    bad('Phase 11d migration runner missing');
}

echo "\n";
echo "===========================================\n";
echo "Phase 11d mobile_first: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
