<?php
/**
 * GH#148 (Phase 155) -- the two permissions and the six-site tier-1 checklist.
 *
 *   action.dispatch_vendor  tier 0 (unrestricted): Super Admin, Org Admin, Dispatcher
 *   action.manage_vendors   tier 1 (Org Admin or above): Super Admin, Org Admin ONLY
 *
 * A tier-1 code has leaked onto Dispatcher five times in this project's history, every time because ONE of the places that
 * must name it did not. This test checks each place, checks the LIVE grants for every role (direct AND through the
 * canonical alias, which is how action.manage_calls leaked), drives rbac_can() for a real user in each role, runs the
 * project's own leak audit, and checks that neither endpoint nor the page falls back to is_admin().
 *
 * @requires-db
 * Usage: php tests/test_vendor_rbac.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';
require_once __DIR__ . '/../inc/rbac.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$root = dirname(__DIR__);
register_shutdown_function('vf_cleanup');

echo "=== GH#148 -- RBAC ===\n\n";

// ── seeded, classified ───────────────────────────────────────
echo "--- rows and tiers ---\n";
$disp = db_fetch_one("SELECT * FROM `{$prefix}permissions` WHERE `code` = 'action.dispatch_vendor'");
$mgr  = db_fetch_one("SELECT * FROM `{$prefix}permissions` WHERE `code` = 'action.manage_vendors'");
t('action.dispatch_vendor is seeded', $disp !== null && $disp['category'] === 'action');
t('action.manage_vendors is seeded', $mgr !== null && $mgr['category'] === 'action');
t('action.dispatch_vendor is tier 0 (unrestricted: an install can revoke it in the Roles UI)', $disp !== null && (int) $disp['admin_only'] === 0);
t('action.manage_vendors is tier 1 (Org Admin or above)', $mgr !== null && (int) $mgr['admin_only'] === 1);

// ── live grants per role ─────────────────────────────────────
echo "\n--- live grants, direct and through the canonical alias ---\n";
$holds = function (int $roleId, string $code) use ($prefix) {
    $row = db_fetch_one("SELECT `id`, `deprecated_alias_of` FROM `{$prefix}permissions` WHERE `code` = ?", [$code]);
    if (!$row) return ['direct' => false, 'alias' => false];
    $direct = (bool) db_fetch_value("SELECT 1 FROM `{$prefix}role_permissions` WHERE `role_id` = ? AND `permission_id` = ?", [$roleId, (int) $row['id']]);
    // canonical alias partner(s): this code's canonical code, and any code that names this one as its canonical
    $alias = (bool) db_fetch_value(
        "SELECT 1 FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
          JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
         WHERE rp.role_id = ? AND old_p.code = ?", [$roleId, $code]);
    return ['direct' => $direct, 'alias' => $alias];
};
$roleNames = [1 => 'Super Admin', 2 => 'Org Admin', 3 => 'Dispatcher', 4 => 'Operator', 5 => 'Read-Only', 6 => 'Field Unit', 7 => 'Facility'];
foreach ($roleNames as $rid => $rname) {
    $d = $holds($rid, 'action.dispatch_vendor');
    $wantDispatch = in_array($rid, [1, 2, 3], true);
    t("$rname " . ($wantDispatch ? 'holds' : 'does NOT hold') . ' action.dispatch_vendor', $d['direct'] === $wantDispatch);
    $m = $holds($rid, 'action.manage_vendors');
    $wantManage = in_array($rid, [1, 2], true);
    t("$rname " . ($wantManage ? 'holds' : 'does NOT hold') . ' action.manage_vendors', $m['direct'] === $wantManage);
    if (!$wantManage) t("$rname does not hold the canonical ALIAS of action.manage_vendors either", $m['alias'] === false);
}

// ── rbac_can() for real users in each role ───────────────────
echo "\n--- rbac_can() for a real user in each role ---\n";
$expect = [1 => [true, true], 2 => [true, true], 3 => [true, false], 4 => [false, false], 6 => [false, false]];
foreach ($expect as $rid => [$wantD, $wantM]) {
    $uid = vf_user('vtr-role-' . $rid, $rid);
    vf_session_as($uid, null, 'vtr-role-' . $rid);
    rbac_clear_cache();   // _rbac_load_grants() caches for the process; this is a different user now
    t($roleNames[$rid] . ': rbac_can(dispatch) = ' . ($wantD ? 'yes' : 'no'), rbac_can('action.dispatch_vendor') === $wantD);
    t($roleNames[$rid] . ': rbac_can(manage) = ' . ($wantM ? 'yes' : 'no'), rbac_can('action.manage_vendors') === $wantM);
}

// ── the six sites name the new code ──────────────────────────
echo "\n--- the six-site checklist ---\n";
$sqlRbac = file_get_contents($root . '/sql/rbac.sql');
$run00 = file_get_contents($root . '/sql/run_00_rbac.php');
$zzz = file_get_contents($root . '/sql/run_zzz_admin_only_reconcile.php');
$mig = file_get_contents($root . '/sql/run_gh148_vendor_dispatch.php');
$leak = file_get_contents($root . '/tests/test_rbac_canonical_alias_leak.php');
t('site 1a: sql/rbac.sql seeds both permission rows', preg_match("/\\('action\\.dispatch_vendor'/", $sqlRbac) === 1 && preg_match("/\\('action\\.manage_vendors'/", $sqlRbac) === 1);
t('site 1b: sql/rbac.sql lists manage_vendors in the tier-1 UPDATE', (bool) preg_match('/admin_only` = 1 WHERE `code` IN \((?:(?!\n\);)[\s\S])*?action\.manage_vendors/', $sqlRbac));
t('site 1c: sql/rbac.sql excludes manage_vendors from Dispatcher\'s broad grant', (bool) preg_match('/SELECT 3, `id` FROM `permissions`\s+WHERE `code` NOT IN \((?:(?!\n    \)\n)[\s\S])*?action\.manage_vendors/', $sqlRbac));
t('site 1d: BOTH Dispatcher repair DELETEs in sql/rbac.sql name it (direct and canonical alias)',
    preg_match_all('/role_id` = 3\s+AND p\.`code` IN \([^;]*?action\.manage_vendors/s', $sqlRbac) === 1
    && preg_match_all('/rp\.role_id = 3\s+AND old_p\.code IN \([^;]*?action\.manage_vendors/s', $sqlRbac) === 1);
t('site 1e: dispatch_vendor is NOT in any exclusion list (tier 0, Dispatcher-default)', !preg_match('/NOT IN \([^;]*?action\.dispatch_vendor/s', $sqlRbac));
t('site 2a: sql/run_00_rbac.php seeds both rows', strpos($run00, "'action.dispatch_vendor'") !== false && strpos($run00, "'action.manage_vendors'") !== false);
t('site 2b: sql/run_00_rbac.php lists manage_vendors in its tier-1 UPDATE', (bool) preg_match('/admin_only = 1 WHERE code IN \((?:(?!\n    \)\"\);)[\s\S])*?action\.manage_vendors/', $run00));
t('site 2c: the Dispatcher ALLOW-list in sql/run_00_rbac.php NAMES dispatch_vendor (an allow-list withholds whatever it does not name)', (bool) preg_match('/SELECT 3, `id` FROM[^;]*?\'action\.dispatch_vendor\'/s', $run00));
t('site 2d: both Dispatcher repair DELETEs in sql/run_00_rbac.php name manage_vendors (direct and canonical alias)',
    preg_match_all('/AND p\.`code` IN \([^;]*?action\.manage_vendors/s', $run00) === 1
    && preg_match_all('/AND old_p\.code IN \([^;]*?action\.manage_vendors/s', $run00) === 1);
t('site 3: sql/run_zzz_admin_only_reconcile.php classifies manage_vendors tier 1 (so a FRESH install does too, after the migration created the row)', (bool) preg_match('/admin_only = 1 WHERE code IN \([^)]*?action\.manage_vendors/s', $zzz));
t('site 4: the migration inserts, classifies, grants and leak-checks', strpos($mig, "'action.manage_vendors'") !== false && strpos($mig, 'Dispatcher (role 3) holds') !== false
    && strpos($mig, 'exit(1)') !== false);
t('site 5: tests/test_rbac_canonical_alias_leak.php carries it, BEFORE action.manage_org_relationships (that entry must stay last)',
    (bool) preg_match("/'action\\.manage_vendors',[^\\]]*'action\\.manage_org_relationships'\\]/", $leak));
t('the migration sorts before sql/run_zzz_admin_only_reconcile.php (so the tier is classified on a fresh install)', 'run_gh148_vendor_dispatch.php' < 'run_zzz_admin_only_reconcile.php');

// ── site 6: the project's own audit, and the admin_only test ─
echo "\n--- the project's own leak audit ---\n";
$run = function (array $argv) use ($root) {
    $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return [-1, ''];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out . $err];
};
[$code, $out] = $run([PHP_BINARY ?: 'php', 'tools/rbac_exclusion_leak_audit.php']);
t('tools/rbac_exclusion_leak_audit.php is clean (exit 0, 0 new findings)', $code === 0 && strpos($out, '0 total finding(s)') !== false);
if ($code !== 0) echo "    " . str_replace("\n", "\n    ", trim($out)) . "\n";
[$code, $out] = $run([PHP_BINARY ?: 'php', 'tools/rbac_permission_audit.php']);
t('tools/rbac_permission_audit.php finds no reference to a missing permission code', $code === 0);

// ── no is_admin() fallback on either gate (tokenized, not grepped) ──
echo "\n--- no is_admin() fallback ---\n";
function idents(string $file): array
{
    $out = [];
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok) && $tok[0] === T_STRING) $out[] = $tok[1];
    }
    return $out;
}
foreach (['api/vendor-dispatch.php', 'api/vendor-admin.php', 'service-providers-admin.php', 'inc/vendor-dispatch.php', 'inc/vendor-admin-write.php', 'inc/vendor-dispatch-modal.php'] as $f) {
    t("$f never calls is_admin() (comments excluded)", !in_array('is_admin', idents($root . '/' . $f), true));
}
$idText = file_get_contents($root . '/incident-detail.php');
preg_match('/\$vendorDispatchOn = [^;]*;/', $idText, $m);
t('incident-detail.php gates the feature on rbac_can(action.dispatch_vendor) && vendor_enabled(), with no is_admin()', isset($m[0])
    && strpos($m[0], "rbac_can('action.dispatch_vendor')") !== false && strpos($m[0], 'vendor_enabled()') !== false && strpos($m[0], 'is_admin') === false);

// ── page gate and API gate name the SAME permission ──────────
echo "\n--- page gate == API gate ---\n";
$page = file_get_contents($root . '/service-providers-admin.php');
$adminApi = file_get_contents($root . '/api/vendor-admin.php');
$dispApi = file_get_contents($root . '/api/vendor-dispatch.php');
t('the admin page and the admin API both gate on rbac_can(action.manage_vendors)', preg_match("/if \\(!rbac_can\\('action\\.manage_vendors'\\)\\)/", $page) === 1
    && preg_match("/if \\(!rbac_can\\('action\\.manage_vendors'\\)\\) json_error\\('Forbidden', 403\\)/", $adminApi) === 1);
t('the incident page and the dispatch API both gate on rbac_can(action.dispatch_vendor)', strpos($idText, "rbac_can('action.dispatch_vendor')") !== false
    && preg_match("/if \\(!rbac_can\\('action\\.dispatch_vendor'\\)\\) json_error\\('Forbidden', 403\\)/", $dispApi) === 1);
$sidebar = file_get_contents($root . '/inc/config-sidebar.php');
t('the Settings sidebar link is gated on the page\'s own permission', (bool) preg_match("/rbac_can\\('action\\.manage_vendors'\\)\\) \\{\\s*_cfg_link\\('service-providers-admin'/s", $sidebar));
t('the dispatch API consults manage_vendors only to let a manager void any entry (not as an alternative gate)', substr_count($dispatchApi = $dispApi, "rbac_can('action.manage_vendors')") === 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
