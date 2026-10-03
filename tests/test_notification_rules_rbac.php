<?php
/**
 * Phase 155 (GH#144) - RBAC for Notification Rules: action.manage_notification_rules.
 *
 * WHY SUPER ADMIN ONLY, AND WHY THIS FILE EXISTS. A notification rule makes the
 * server send mail and texts. A rule author who can pick any recipient, any
 * channel and any message text controls an outbound channel that looks like it
 * came from the agency - and a Slack or Telegram rule posts to a destination that
 * is shared across every organization. That is install-wide blast radius, so the
 * permission is tier 2 (permissions.admin_only = 2): held by Super Admin and
 * grantable to NO other role through any code path.
 *
 * The shipped RBAC seeding leaks admin-only codes onto lower roles in four known
 * ways (CLAUDE.md, the "RBAC EXCLUSION-LIST" entries): a broad NOT IN grant that
 * forgot the code, a canonical alias the list cannot name, a grant that predates
 * the exclusion, and a migration that grants it directly. This file proves the
 * code survives each, using the REAL seed scripts - not a copy of their SQL.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/rbac_admin_only.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
p155_install_cleanup();
$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');
$CODE = 'action.manage_notification_rules';

echo "=== Phase 155 / GH#144 - RBAC: action.manage_notification_rules ===\n\n";

$perm = db_fetch_one("SELECT * FROM `{$prefix}permissions` WHERE `code` = ?", [$CODE]);
p155_t('the permission row exists', (bool) $perm);
if (!$perm) { echo "SKIP the rest: run php sql/run_00_rbac.php\n"; p155_done(); }
$pid = (int) $perm['id'];
p155_t('...in the `action` category', $perm['category'] === 'action');
p155_t('...and is tier 2 (admin_only = 2): grantable to no role below Super Admin', (int) $perm['admin_only'] === 2);

// Every role id, with its name, so a custom role added later is covered too.
$roles = db_fetch_all("SELECT `id`, `name`, `is_super` FROM `{$prefix}roles` ORDER BY `id`");
$hasAliasCol = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'",
    [$prefix . 'permissions']);

/** Does the role hold the code - directly, or through its canonical alias? */
function nr_role_holds(int $roleId, string $code): array
{
    global $prefix, $hasAliasCol;
    $direct = (bool) db_fetch_value(
        "SELECT 1 FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE rp.role_id = ? AND p.code = ?", [$roleId, $code]);
    $alias = false;
    if ($hasAliasCol) {
        $alias = (bool) db_fetch_value(
            "SELECT 1 FROM `{$prefix}role_permissions` rp
               JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
               JOIN `{$prefix}permissions` oldp ON oldp.deprecated_alias_of = canon.code
              WHERE rp.role_id = ? AND oldp.code = ?", [$roleId, $code]);
    }
    return ['direct' => $direct, 'alias' => $alias];
}
function nr_leaks(): array
{
    global $roles, $CODE;
    $out = [];
    foreach ($roles as $r) {
        if ((int) $r['is_super'] === 1) continue;
        $h = nr_role_holds((int) $r['id'], $CODE);
        if ($h['direct'] || $h['alias']) $out[] = $r['name'] . ($h['direct'] ? ' (direct)' : ' (alias)');
    }
    return $out;
}

/**
 * What rbac_can() ACTUALLY answers for a throwaway account holding the given roles -
 * the real resolver, not a reading of the grant tables.
 */
function nr_can_as(array $roleIds): bool
{
    global $CODE;
    static $n = 0;
    $uid = 900155400 + (++$n);
    p155_make_user($uid, 'p155rbac' . $n, null, null, null, $roleIds);
    $_SESSION['user_id'] = $uid;
    _rbac_load_grants(true);
    $can = rbac_can($CODE);
    unset($_SESSION['user_id']);
    _rbac_load_grants(true);
    return $can;
}

echo "\n--- who holds it today ---\n";
$super = null;
foreach ($roles as $r) if ((int) $r['is_super'] === 1) $super = $r;
p155_t('a Super Admin role exists', $super !== null);
p155_t('rbac_can() answers YES for an account with the Super Admin role', nr_can_as([(int) $super['id']]) === true);
p155_t('rbac_can() answers NO for Org Admin', nr_can_as([2]) === false);
p155_t('rbac_can() answers NO for Dispatcher', nr_can_as([3]) === false);
p155_t('rbac_can() answers NO for an account holding BOTH Org Admin and Dispatcher (roles do not add up to a tier-2 code)', nr_can_as([2, 3]) === false);
foreach ([2 => 'Org Admin', 3 => 'Dispatcher', 4 => 'Operator', 5 => 'Read-Only', 6 => 'Field Unit'] as $rid => $label) {
    $h = nr_role_holds($rid, $CODE);
    p155_t("{$label} (role {$rid}) holds it neither directly nor through its canonical alias", !$h['direct'] && !$h['alias']);
}
p155_t('NO non-super role (including any custom role) holds it in any form', nr_leaks() === []);

echo "\n--- the write-site guard (inc/rbac_admin_only.php) ---\n";
p155_t('granting it to Org Admin is refused', rbac_grant_permission_allowed(2, $pid) === false);
p155_t('granting it to Dispatcher is refused', rbac_grant_permission_allowed(3, $pid) === false);
p155_t('granting it to Operator is refused', rbac_grant_permission_allowed(4, $pid) === false);
p155_t('granting it to Super Admin is allowed', $super !== null && rbac_grant_permission_allowed((int) $super['id'], $pid) === true);
$refused = false;
try { rbac_assert_grant_permission_allowed(2, $pid); } catch (Throwable $e) { $refused = true; }
p155_t('...and the asserting form throws, so a writer cannot ignore the answer', $refused);

echo "\n--- the endpoint gate carries no is_admin() fallback ---\n";
$src = (string) file_get_contents($base . '/api/notification-rules.php');
$code = '';
foreach (token_get_all($src) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;   // comments may name it
    $code .= is_array($tok) ? $tok[1] : $tok;
}
p155_t("the gate is rbac_can('action.manage_notification_rules')", strpos($code, "rbac_can('action.manage_notification_rules')") !== false);
p155_t('...with NO `|| is_admin()` fallback (is_admin() is also true for anyone holding action.manage_config, which would hand an Org Admin a tier-2 control)',
    strpos($code, 'is_admin(') === false);
p155_t('...and no legacy level / role-name test', !preg_match('/\$_SESSION\[\s*[\'"]level[\'"]\s*\]|user_level|role_name/', $code));

echo "\n--- the four leak mechanisms, against the REAL seed scripts ---\n";
p155_on_cleanup("DELETE rp FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` p ON p.id = rp.permission_id WHERE p.code = '{$CODE}' AND rp.role_id IN (2,3,4,5,6)");
if ($hasAliasCol) {
    p155_on_cleanup("UPDATE `{$prefix}permissions` SET `deprecated_alias_of` = NULL WHERE `code` = '{$CODE}' AND `deprecated_alias_of` = 'notification_rules.zztest_canon'");
    p155_on_cleanup("DELETE rp FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` p ON p.id = rp.permission_id WHERE p.code = 'notification_rules.zztest_canon'");
    p155_on_cleanup("DELETE FROM `{$prefix}permissions` WHERE `code` = 'notification_rules.zztest_canon'");
}
$run = static function (string $script) use ($base): array {
    return p155_run_php([$base . '/sql/' . $script]);
};

// 1. A DIRECT grant that predates the exclusion list.
foreach ([2, 3] as $rid) db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)", [$rid, $pid]);
p155_t('fixture: Org Admin and Dispatcher have been handed the code directly (the pre-exclusion shape)', count(nr_leaks()) >= 2);
$audit = p155_run_php([$base . '/tools/rbac_exclusion_leak_audit.php']);
p155_t('the exclusion-leak audit CATCHES it (non-zero exit, names the code)', $audit['code'] !== 0 && strpos($audit['out'], $CODE) !== false);
$r = $run('run_00_rbac.php');
p155_t('re-running sql/run_00_rbac.php exits 0', $r['code'] === 0);
p155_t('...and its repair DELETE revokes both leaked grants', nr_leaks() === []);
p155_t('...and the audit is clean again', p155_run_php([$base . '/tools/rbac_exclusion_leak_audit.php'])['code'] === 0);

// 2. A CANONICAL ALIAS the NOT IN list cannot name.
if ($hasAliasCol) {
    db_query("INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`) VALUES ('notification_rules.zztest_canon', 'ZZ canonical fixture', 'action', 'throwaway fixture')");
    $canonId = (int) db_insert_id();
    db_query("UPDATE `{$prefix}permissions` SET `deprecated_alias_of` = 'notification_rules.zztest_canon' WHERE `code` = ?", [$CODE]);
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (2, ?)", [$canonId]);
    p155_t('fixture: Org Admin holds the code\'s CANONICAL ALIAS (rbac_can() treats a code and its alias as interchangeable)', nr_role_holds(2, $CODE)['alias'] === true);
    $r = $run('run_00_rbac.php');
    p155_t('re-running run_00_rbac.php revokes the alias grant as well', $r['code'] === 0 && nr_role_holds(2, $CODE)['alias'] === false);
    db_query("UPDATE `{$prefix}permissions` SET `deprecated_alias_of` = NULL WHERE `code` = ?", [$CODE]);
    db_query("DELETE FROM `{$prefix}role_permissions` WHERE `permission_id` = ?", [$canonId]);
    db_query("DELETE FROM `{$prefix}permissions` WHERE `id` = ?", [$canonId]);
}

// 3. The fresh-install ordering gap: sql/rbac.sql runs BEFORE the migration that
//    could create a permission row, so its classification UPDATE can match zero
//    rows and the code is left at the column default (0 = no restriction at all).
//    The reconcile script sorts LAST and re-applies it.
db_query("UPDATE `{$prefix}permissions` SET `admin_only` = 0 WHERE `code` = ?", [$CODE]);
p155_on_cleanup("UPDATE `{$prefix}permissions` SET `admin_only` = 2 WHERE `code` = '{$CODE}'");
p155_t('fixture: the code has been left at the column default (0) - the state a fresh install can end up in', (int) db_fetch_value("SELECT `admin_only` FROM `{$prefix}permissions` WHERE `code` = ?", [$CODE]) === 0);
p155_t('...and while it is 0 the write-site guard would ALLOW a grant to Org Admin (the gap this closes)', rbac_grant_permission_allowed(2, $pid) === true);
$r = $run('run_zzz_admin_only_reconcile.php');
p155_t('the admin-only reconcile script exits 0', $r['code'] === 0);
p155_t('...and restores the code to tier 2', (int) db_fetch_value("SELECT `admin_only` FROM `{$prefix}permissions` WHERE `code` = ?", [$CODE]) === 2);
p155_t('...so the write-site guard refuses Org Admin again', rbac_grant_permission_allowed(2, $pid) === false);
p155_t('the migration that creates the permission also verified it (sql/run_phase155_notification_rules.php fails non-zero if admin_only != 2)',
    strpos((string) file_get_contents($base . '/sql/run_phase155_notification_rules.php'), "admin_only=2") !== false);

// 4. Nothing here withdrew the legitimate grant.
p155_t('after every repair rbac_can() still answers YES for Super Admin and NO for Org Admin / Dispatcher',
    nr_can_as([(int) $super['id']]) === true && nr_can_as([2]) === false && nr_can_as([3]) === false);
p155_t('...and the permission row is still tier 2', (int) db_fetch_value("SELECT `admin_only` FROM `{$prefix}permissions` WHERE `code` = ?", [$CODE]) === 2);

echo "\n--- the seed files agree (structural) ---\n";
$rbacSql = (string) file_get_contents($base . '/sql/rbac.sql');
$run00   = (string) file_get_contents($base . '/sql/run_00_rbac.php');
$recon   = (string) file_get_contents($base . '/sql/run_zzz_admin_only_reconcile.php');
p155_t('sql/rbac.sql seeds the permission row', preg_match("/\('" . preg_quote($CODE, '/') . "',\s*'[^']+',\s*'action'/", $rbacSql) === 1);
p155_t('sql/run_00_rbac.php seeds the permission row', strpos($run00, "'{$CODE}', 'Manage Notification Rules (install-wide)'") !== false);
p155_t('sql/rbac.sql names it in the broad-grant exclusion lists AND the repair DELETEs (>= 6 occurrences)', substr_count($rbacSql, $CODE) >= 6);
p155_t('sql/run_00_rbac.php names it in the exclusion lists AND the repair DELETEs (>= 5 occurrences)', substr_count($run00, $CODE) >= 5);
p155_t('sql/run_zzz_admin_only_reconcile.php lists it as a tier-2 code', strpos($recon, $CODE) !== false);

p155_cleanup();
p155_t('final state: no non-super role holds it', nr_leaks() === []);
p155_done();
