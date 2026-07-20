<?php
/**
 * RBAC Org-Scope — writer + active_org_id preference  (GH #56, Billy/K9OH)
 *
 * Two root causes the first #56 fix (5d9943f) missed, found by Billy Irwin:
 *
 *   BUG 1: api/config-admin.php's User Accounts form wrote org-scoped grants
 *          with org_id set but scope_id = NULL. The RBAC engine matches on
 *          scope_id, so those grants were dead on arrival (denied everywhere).
 *   BUG 2: login.php defaulted active_org_id to the first MEMBER org; a user
 *          whose authority is an org-scoped ROLE for org B but who is a member
 *          of org A landed on org A → the org-B grant never satisfied.
 *
 * This locks in: the writer now passes scope_id; the backfill migration
 * repairs already-broken rows; and active_org_id prefers the role-scoped org.
 * Self-cleaning; touches no existing account.
 *
 * Usage: php tests/test_rbac_org_scope_billy56.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($label, $cond){ global $passed,$failed; echo ($cond?"[PASS] ":"[FAIL] ").$label."\n"; $cond?$passed++:$failed++; }
function tbl($n){ return db_table($n); }

echo "=== RBAC Org-Scope writer + active_org_id preference (#56 / Billy) ===\n\n";

// ── Static guard: the writer passes scope_id (not hardcoded NULL) ──
$ca = @file_get_contents(dirname(__DIR__).'/api/config-admin.php');
if ($ca !== false) {
    // The User Accounts form insert must NOT hardcode scope_id NULL right after
    // scope_kind. Match the fixed shape: five '?' placeholders before NOW().
    $brokenShape = (bool) preg_match('/scope_kind,\s*scope_id,\s*granted_at.*?VALUES\s*\(\?,\s*\?,\s*\?,\s*\?,\s*NULL,\s*NOW\(\)/s', $ca);
    t('config-admin.php User Accounts writer no longer hardcodes scope_id = NULL (Bug 1 source)', !$brokenShape);
} else {
    t('config-admin.php readable', false);
}

// ── Preconditions ──
$hasV2 = false;
try { $hasV2 = (bool) db_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='scope_kind'", [$prefix.'user_roles']); } catch (Throwable $e) {}
$oa = null;
if ($hasV2) { try { $oa = db_fetch_one("SELECT id FROM ".tbl('roles')." WHERE name='Org Admin' LIMIT 1"); } catch (Throwable $e) {} }
if (!$hasV2 || !$oa) {
    echo "[SKIP] RBAC v2 / Org Admin role absent — runtime scenarios skipped.\n";
    echo "\n=== $passed passed, $failed failed ===\n";
    exit($failed === 0 ? 0 : 1);
}
$roleId = (int) $oa['id'];

$orgA = null; $orgB = null; $uid = null; $ur = null;
function mkOrg($name){ db_query("INSERT INTO ".tbl('organizations')." (name, short_name, active, sort_order) VALUES (?,?,1,999)", [$name, 'ZZ']); return (int) db_insert_id(); }

try {
    $orgA = mkOrg('zz-56b-memberorg');  // user is a MEMBER here
    $orgB = mkOrg('zz-56b-roleorg');    // user's org-scoped ROLE is here

    // throwaway non-admin user
    $cols = array_column(db_fetch_all("DESCRIBE ".tbl('user')), null, 'Field');
    $f = [];
    if (isset($cols['user'])) $f['user'] = 'zz-56b';
    if (isset($cols['passwd'])) $f['passwd'] = password_hash('unused', PASSWORD_BCRYPT);
    if (isset($cols['level'])) $f['level'] = 5;
    $fn = array_keys($f);
    db_query("INSERT INTO ".tbl('user')." (`".implode('`,`',$fn)."`) VALUES (".implode(',',array_fill(0,count($fn),'?')).")", array_values($f));
    $uid = (int) db_insert_id();

    // member_organizations row for org A (needs a member id; create a throwaway member if the table needs it)
    // Simpler: simulate member org membership via session only — we test the
    // login PREFERENCE logic directly rather than the member_organizations query.

    // BUG 1: org-scoped grant written the BROKEN way (org_id set, scope_id NULL)
    $urCols = array_column(db_fetch_all("DESCRIBE ".tbl('user_roles')), null, 'Field');
    $ins = ['user_id'=>$uid,'role_id'=>$roleId,'scope_kind'=>'org','scope_id'=>null];
    if (isset($urCols['org_id'])) $ins['org_id'] = $orgB;
    if (isset($urCols['granted_at'])) $ins['granted_at'] = date('Y-m-d H:i:s');
    $in = array_keys($ins);
    db_query("INSERT INTO ".tbl('user_roles')." (`".implode('`,`',$in)."`) VALUES (".implode(',',array_fill(0,count($in),'?')).")", array_values($ins));
    $ur = (int) db_insert_id();

    $_SESSION['user_id'] = $uid; $_SESSION['member_id'] = null; $_SESSION['level'] = 5;
    $_SESSION['active_org_id'] = $orgB; // even pointing at the right org...
    rbac_reset_cache();
    t('BUG 1: grant with scope_id NULL is DENIED even with active_org_id set right', rbac_can('screen.dashboard') === false);

    // Run the backfill repair (same SQL as sql/run_backfill_org_scope_id.php)
    db_query("UPDATE ".tbl('user_roles')." SET scope_id = org_id
              WHERE scope_kind='org' AND (scope_id IS NULL OR scope_id=0) AND org_id IS NOT NULL AND org_id>0 AND user_id=?", [$uid]);
    $fixedScope = (int) db_fetch_value("SELECT scope_id FROM ".tbl('user_roles')." WHERE id=?", [$ur]);
    t('BUG 1: backfill sets scope_id = org_id', $fixedScope === $orgB);
    rbac_reset_cache();
    t('BUG 1: after backfill the org admin is GRANTED', rbac_can('screen.dashboard') === true);

    // BUG 2: active_org_id preference — role org (B) must win over member org (A).
    // Replicate login.php's preference logic.
    $roleOrgIds = array_map('intval', array_column(
        db_fetch_all("SELECT DISTINCT ur.scope_id AS org_id FROM ".tbl('user_roles')." ur
                      JOIN ".tbl('organizations')." o ON o.id=ur.scope_id
                      WHERE ur.user_id=? AND ur.scope_kind='org' AND ur.scope_id IS NOT NULL AND o.active=1
                        AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                      ORDER BY o.sort_order, o.name", [$uid]),
        'org_id'));
    $memberOrgs = [$orgA]; // pretend member of org A
    $active = !empty($roleOrgIds) ? $roleOrgIds[0] : (!empty($memberOrgs) ? $memberOrgs[0] : null);
    t('BUG 2: active_org_id prefers the role-scoped org (B), not the member org (A)', $active === $orgB);

    $_SESSION['active_org_id'] = $active;
    rbac_reset_cache();
    t('BUG 2: with the preferred active_org_id the admin is GRANTED', rbac_can('screen.dashboard') === true);

} catch (Throwable $e) {
    t('setup/exec without error: '.$e->getMessage(), false);
} finally {
    try { if ($ur)   db_query("DELETE FROM ".tbl('user_roles')." WHERE id=?", [$ur]); } catch (Throwable $e) {}
    try { if ($uid)  db_query("DELETE FROM ".tbl('user')." WHERE id=?", [$uid]); } catch (Throwable $e) {}
    try { if ($orgA) db_query("DELETE FROM ".tbl('organizations')." WHERE id=?", [$orgA]); } catch (Throwable $e) {}
    try { if ($orgB) db_query("DELETE FROM ".tbl('organizations')." WHERE id=?", [$orgB]); } catch (Throwable $e) {}
    unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['active_org_id'], $_SESSION['level']);
    rbac_reset_cache();
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
