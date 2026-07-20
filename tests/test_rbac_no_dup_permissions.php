<?php
/**
 * GH #77 regression — the permissions table must not duplicate.
 *
 * a beta tester saw "two of every permission" on settings.php#roles-levels because
 * his install's permissions table was created (by an older schema) without
 * UNIQUE(code), so the INSERT IGNORE seed appended a fresh dup row per code
 * on every re-run. run_gh77_dedupe_permissions.php heals it. This test guards
 * the invariant on every build so it can never regress:
 *   - no duplicate permission codes
 *   - a UNIQUE key on permissions.code (so re-seeds stay idempotent)
 *   - role_permissions has no duplicate (role_id, permission_id) grants
 *
 * Usage: php tests/test_rbac_no_dup_permissions.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

$have = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'permissions']);
if (!$have) {
    echo "SKIP: permissions table absent (RBAC not installed) — 0/0\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$dupCodes = db_fetch_all(
    "SELECT code, COUNT(*) c FROM `{$prefix}permissions`
      GROUP BY code HAVING c > 1");
test('no duplicate permission codes', empty($dupCodes));
if (!empty($dupCodes)) {
    foreach ($dupCodes as $d) { echo "      dup: {$d['code']} x{$d['c']}\n"; }
}

$hasUnique = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ?
        AND column_name = 'code' AND non_unique = 0", [$prefix . 'permissions']);
test('permissions.code has a UNIQUE key', $hasUnique > 0);

$hasRolePerms = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'role_permissions']);
if ($hasRolePerms) {
    $dupGrants = db_fetch_all(
        "SELECT role_id, permission_id, COUNT(*) c
           FROM `{$prefix}role_permissions`
          GROUP BY role_id, permission_id HAVING c > 1");
    test('no duplicate role_permissions grants', empty($dupGrants));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
