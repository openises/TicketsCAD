<?php
/**
 * Roster bulk_delete — cross-org IDOR guard (2026-07-05)
 *
 * A background QA agent found that api/members.php's bulk_delete handler read
 * an ARRAY ($input['ids']) and so bypassed the single-target org-scope gate
 * (which keys off $input['id']). A user granted action.bulk_delete_members in
 * an ORG scope could therefore soft-delete members of OTHER orgs (cross-org
 * IDOR). The fix runs org_can_see_member() per id before deleting; Super Admins
 * (null visible-set) are unaffected.
 *
 * This locks in: the handler calls the gate, and an org-scoped user cannot see
 * (hence cannot bulk-delete) a member in another org. Self-cleaning.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/member-write.php';

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function tbl($n) { return db_table($n); }

echo "=== Bulk delete cross-org IDOR guard ===\n\n";

// ── Static guard: the handler gates per id before deleting ──
$api = @file_get_contents($base . '/api/members.php');
if ($api !== false) {
    t("bulk_delete requires inc/org-scope.php",
        (bool) preg_match('#bulk_delete.*?require_once __DIR__ \. .\/\.\./inc/org-scope\.php.#s', $api));
    t("bulk_delete skips ids the org-scope gate denies (org_can_see_member per id)",
        (bool) preg_match('/foreach \(\$ids as \$id\)\s*\{\s*if \(!org_can_see_member\(\$id\)\)\s*\{\s*\$failed\[\]/s', $api));
} else {
    t("api/members.php readable", false);
}

// ── Runtime: an org-scoped user cannot see a member in another org ──
$prefix = $GLOBALS['db_prefix'] ?? '';
$hasV2 = false;
try {
    $hasV2 = (bool) db_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='scope_kind'", [$prefix . 'user_roles']);
} catch (Throwable $e) {}
$orgAdmin = null;
if ($hasV2) { try { $orgAdmin = db_fetch_one("SELECT id FROM " . tbl('roles') . " WHERE is_super=0 AND name='Org Admin' LIMIT 1"); } catch (Throwable $e) {} }
if (!$hasV2 || !$orgAdmin) {
    echo "[SKIP] RBAC v2 / non-super Org Admin role absent — runtime scenario skipped.\n";
    echo "\n=== $passed passed, $failed failed ===\n";
    exit($failed === 0 ? 0 : 1);
}

$orgA = null; $orgB = null; $uid = null; $mA = null; $mB = null; $ur = null;
function mkOrg2($n) { db_query("INSERT INTO " . tbl('organizations') . " (name, short_name, active, sort_order) VALUES (?,?,1,999)", [$n, 'ZZ']); return (int) db_insert_id(); }
function mkMember2($f, $l) { $r = member_create_internal(['first_name' => $f, 'last_name' => $l], 0); return (int) ($r['id'] ?? 0); }
function relinkOrg($mid, $oid) {
    db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id=?", [$mid]);
    db_query("INSERT INTO " . tbl('member_organizations') . " (member_id, org_id, status, join_date, created_at) VALUES (?,?, 'active', CURDATE(), NOW())", [$mid, $oid]);
}

try {
    $orgA = mkOrg2('zz-idor-userorg');   // the user can see this org
    $orgB = mkOrg2('zz-idor-otherorg');  // the target member lives here (invisible)

    $mA = mkMember2('zzIdor', 'OrgA'); relinkOrg($mA, $orgA);
    $mB = mkMember2('zzIdor', 'OrgB'); relinkOrg($mB, $orgB);

    // throwaway user with an Org Admin role scoped to org A only
    $cols = array_column(db_fetch_all("DESCRIBE " . tbl('user')), null, 'Field');
    $f = [];
    if (isset($cols['user']))   $f['user'] = 'zz-idor-u';
    if (isset($cols['passwd'])) $f['passwd'] = password_hash('unused', PASSWORD_BCRYPT);
    if (isset($cols['level']))  $f['level'] = 1;
    db_query("INSERT INTO " . tbl('user') . " (`" . implode('`,`', array_keys($f)) . "`) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")", array_values($f));
    $uid = (int) db_insert_id();

    $ins = ['user_id' => $uid, 'role_id' => (int) $orgAdmin['id'], 'scope_kind' => 'org', 'scope_id' => $orgA];
    $urCols = array_column(db_fetch_all("DESCRIBE " . tbl('user_roles')), null, 'Field');
    if (isset($urCols['org_id']))     $ins['org_id'] = $orgA;
    if (isset($urCols['granted_at'])) $ins['granted_at'] = date('Y-m-d H:i:s');
    db_query("INSERT INTO " . tbl('user_roles') . " (`" . implode('`,`', array_keys($ins)) . "`) VALUES (" . implode(',', array_fill(0, count($ins), '?')) . ")", array_values($ins));
    $ur = (int) db_insert_id();

    $GLOBALS['_org_scope_cache'] = [];
    $_SESSION['user_id'] = $uid;

    t("org-scoped user CAN see a member in their own org (A)", org_can_see_member($mA) === true);
    t("org-scoped user CANNOT see a member in another org (B) — the IDOR the fix closes",
        org_can_see_member($mB) === false);

    // Simulate the handler's per-id loop: only the visible member is deletable.
    $ids = [$mA, $mB];
    $deletable = [];
    foreach ($ids as $id) { if (org_can_see_member($id)) { $deletable[] = $id; } }
    t("bulk loop would delete only the own-org member, skipping the cross-org one",
        $deletable === [$mA]);

} catch (Throwable $e) {
    t("setup/exec without error: " . $e->getMessage(), false);
} finally {
    foreach ([$mA, $mB] as $m) { if ($m) {
        try { db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id=?", [$m]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member') . " WHERE id=?", [$m]); } catch (Throwable $e) {}
    } }
    if ($ur)  { try { db_query("DELETE FROM " . tbl('user_roles') . " WHERE id=?", [$ur]); } catch (Throwable $e) {} }
    if ($uid) { try { db_query("DELETE FROM " . tbl('user') . " WHERE id=?", [$uid]); } catch (Throwable $e) {} }
    foreach ([$orgA, $orgB] as $o) { if ($o) { try { db_query("DELETE FROM " . tbl('organizations') . " WHERE id=?", [$o]); } catch (Throwable $e) {} } }
    unset($_SESSION['user_id']);
    $GLOBALS['_org_scope_cache'] = [];
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
