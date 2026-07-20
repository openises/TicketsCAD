<?php
/**
 * RBAC Org-Admin Scope Tests  (GitHub issue #56)
 *
 * a beta tester: "I have an Org Admin assigned to a specific Org. With all the
 * boxes checked, the org admin cannot see any screens and gets access
 * denied on every page."
 *
 * Root cause: org-scoped grants are only satisfied when
 * $_SESSION['active_org_id'] === grant.scope_id (inc/rbac.php). But
 * active_org_id was derived ONLY from member_organizations at login. An
 * Org Admin assigned via an org-scoped user_roles row, but with no
 * matching member_organizations row, landed with active_org_id = NULL —
 * so EVERY org-scoped screen/widget/action grant failed and they were
 * denied everywhere despite full permissions.
 *
 * Fix (login.php): active_org_id / user_orgs are now the UNION of the
 * member's org memberships AND the orgs the user holds an org-scoped role
 * for. This test reproduces the exact scenario against the real RBAC
 * engine and asserts old-behaviour-denies / new-behaviour-grants. It is
 * fully self-cleaning and touches no existing account.
 *
 * Usage: php tests/test_rbac_org_admin_scope.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';

$passed = 0; $failed = 0;
function t($label, $cond) {
    global $passed, $failed;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $passed++ : $failed++;
}
function tbl($n) { return db_table($n); }

echo "=== RBAC Org-Admin Scope (#56) ===\n\n";

// ── Preconditions: RBAC v2 + Org Admin role + organizations present ──
$hasV2 = false;
try {
    $hasV2 = (bool) db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope_kind'",
        [($GLOBALS['db_prefix'] ?? '') . 'user_roles']
    );
} catch (Throwable $e) { $hasV2 = false; }

$oa = null;
if ($hasV2) {
    try { $oa = db_fetch_one("SELECT id,is_super FROM " . tbl('roles') . " WHERE name='Org Admin' LIMIT 1"); }
    catch (Throwable $e) { $oa = null; }
}
if (!$hasV2 || !$oa) {
    echo "[SKIP] RBAC v2 schema / Org Admin role not present on this install — nothing to test.\n";
    echo "\n=== $passed passed, $failed failed ===\n";
    exit(0);
}

$orgAdminRoleId = (int) $oa['id'];
$createdOrg = null; $createdUser = null; $createdUR = null;

try {
    // Throwaway org
    db_query("INSERT INTO " . tbl('organizations') . " (name, short_name, active, sort_order) VALUES (?,?,1,999)",
        ['zz-test-56-org', 'ZZ56']);
    $createdOrg = (int) db_insert_id();

    // Throwaway non-admin user (no member_organizations row) — mirror the
    // legacy user table (user/passwd), non-zero level so it is NOT an admin.
    $cols = array_column(db_fetch_all("DESCRIBE " . tbl('user')), null, 'Field');
    $fields = [];
    if (isset($cols['user']))          $fields['user']     = 'zz-test-56';
    elseif (isset($cols['username']))  $fields['username'] = 'zz-test-56';
    if (isset($cols['passwd']))        $fields['passwd']   = password_hash('unused', PASSWORD_BCRYPT);
    elseif (isset($cols['password']))  $fields['password'] = password_hash('unused', PASSWORD_BCRYPT);
    if (isset($cols['level']))         $fields['level']    = 5;   // 0 = super in legacy; keep non-admin
    if (isset($cols['email']))         $fields['email']    = 'zz56@example.invalid';
    $fn = array_keys($fields);
    db_query("INSERT INTO " . tbl('user') . " (`" . implode('`,`', $fn) . "`) VALUES (" .
        implode(',', array_fill(0, count($fn), '?')) . ")", array_values($fields));
    $createdUser = (int) db_insert_id();

    // Org-scoped Org Admin assignment
    $urCols = array_column(db_fetch_all("DESCRIBE " . tbl('user_roles')), null, 'Field');
    $ur = ['user_id' => $createdUser, 'role_id' => $orgAdminRoleId, 'scope_kind' => 'org', 'scope_id' => $createdOrg];
    if (isset($urCols['org_id']))     $ur['org_id'] = $createdOrg;
    if (isset($urCols['granted_at'])) $ur['granted_at'] = date('Y-m-d H:i:s');
    $un = array_keys($ur);
    db_query("INSERT INTO " . tbl('user_roles') . " (`" . implode('`,`', $un) . "`) VALUES (" .
        implode(',', array_fill(0, count($un), '?')) . ")", array_values($ur));
    $createdUR = (int) db_insert_id();

    // Simulate this user's session (no member / no member_org — a beta tester's case)
    $_SESSION['user_id']   = $createdUser;
    $_SESSION['member_id'] = null;
    $_SESSION['level']     = 5;

    // The test user must not be an admin, else rbac_require_screen would
    // pass them regardless and the test would be meaningless.
    t('throwaway Org Admin is NOT a super/admin', function_exists('is_admin') ? (is_admin(true) === false) : true);

    // OLD behaviour: active_org_id from member_organizations only -> NULL -> denied.
    $_SESSION['active_org_id'] = null;   // no member orgs
    rbac_reset_cache();
    t('OLD: org-scoped admin with NULL active_org_id is DENIED screens (the bug)',
        rbac_can('screen.dashboard') === false);

    // NEW behaviour: union the org-scoped role's org -> active_org_id set -> granted.
    $roleOrgs = db_fetch_all(
        "SELECT DISTINCT ur.scope_id AS org_id FROM " . tbl('user_roles') . " ur
         JOIN " . tbl('organizations') . " o ON o.id = ur.scope_id
         WHERE ur.user_id = ? AND ur.scope_kind = 'org' AND ur.scope_id IS NOT NULL AND o.active = 1
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
        [$createdUser]
    );
    $_SESSION['active_org_id'] = !empty($roleOrgs) ? (int) $roleOrgs[0]['org_id'] : null;
    rbac_reset_cache();
    t('NEW: active_org_id resolves to the role-scoped org', (int) $_SESSION['active_org_id'] === $createdOrg);
    t('NEW: org-scoped admin is now GRANTED their screens', rbac_can('screen.dashboard') === true);
    t('NEW: a non-granted permission is still correctly denied',
        rbac_can('screen.__definitely_not_a_real_permission__') === false);

} catch (Throwable $e) {
    t('setup/exec without error: ' . $e->getMessage(), false);
} finally {
    // Teardown (reverse order); reset the session + cache we mutated.
    try { if ($createdUR)   db_query("DELETE FROM " . tbl('user_roles') . " WHERE id=?", [$createdUR]); } catch (Throwable $e) {}
    try { if ($createdUser) db_query("DELETE FROM " . tbl('user') . " WHERE id=?", [$createdUser]); } catch (Throwable $e) {}
    try { if ($createdOrg)  db_query("DELETE FROM " . tbl('organizations') . " WHERE id=?", [$createdOrg]); } catch (Throwable $e) {}
    unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['active_org_id'], $_SESSION['level']);
    rbac_reset_cache();
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
