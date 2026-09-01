<?php
/**
 * GH#122 (reported 2026-08-28, rjonesbsink) — roles.uk_role_name_org
 * uniqueness fix, same defect class and same fix shape as Phase 129's
 * user_roles.scope_key (tests/test_rbac_grant_uniqueness.php).
 *
 * The defect: `roles` carries `UNIQUE KEY uk_role_name_org (name, org_id)`.
 * MySQL/MariaDB treat every NULL as distinct in a UNIQUE index, so the key
 * places no constraint on GLOBAL roles (org_id IS NULL) -- every seeded
 * role. Roles 1-6 are seeded with an explicit id (the PRIMARY KEY does the
 * real work there); the 'Facility' role is seeded WITHOUT one (deliberately,
 * so a real install's own custom role at id 7 isn't silently no-op'd), so
 * it is the only one relying on the broken index -- and every re-run of
 * sql/run_00_rbac.php created another one. Reporter found 6 duplicates on
 * their install; this dev database had accumulated 1301 by the time this
 * fix landed.
 *
 * These tests drive the REAL migration (sql/run_gh122_roles_org_key.php)
 * and the REAL seed (sql/run_00_rbac.php) as subprocesses -- exactly as
 * sql/run_migrations.php executes them -- rather than re-implementing what
 * they're believed to do. Uses tests/_test_node_probe.php's test_run_cli()
 * (proc_open-preferring) instead of a bare shell_exec() call, per GH#120's
 * own lesson about disabled-function fragility in test harnesses.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_node_probe.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }

$prefix = $GLOBALS['db_prefix'] ?? '';
$root   = dirname(__DIR__);
$php    = PHP_BINARY ?: 'php';

echo "\n=== GH#122 — roles.uk_role_name_org uniqueness ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    skip('No database available — these tests need one');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$haveTable = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'roles']) > 0;
if (!$haveTable) {
    skip('roles missing — run php sql/run_00_rbac.php');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$hasOrgKey = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_key'",
    [$prefix . 'roles']) > 0;

// A role name that will never collide with a real seeded or admin-created
// role, so these fixtures can never be confused with live data.
$TESTNAME = 'gh122_test_role_' . getmypid();

$cleanup = function () use ($prefix, $TESTNAME) {
    try {
        $ids = db_fetch_all("SELECT id FROM `{$prefix}roles` WHERE name = ?", [$TESTNAME]);
        foreach ($ids as $r) {
            db_query("DELETE FROM `{$prefix}role_permissions` WHERE role_id = ?", [$r['id']]);
            db_query("DELETE FROM `{$prefix}roles` WHERE id = ?", [$r['id']]);
        }
    } catch (Throwable $e) {}
};
$cleanup();

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The constraint refuses a duplicate global role --\n";
// This is the property, stated as the database sees it. Asked twice for
// the identical global role name, the second must be rejected. Before
// this fix both INSERTs succeeded, which is the whole bug.
// ─────────────────────────────────────────────────────────────────────────

if (!$hasOrgKey) {
    skip('org_key absent — run php sql/run_gh122_roles_org_key.php');
} else {
    db_query("INSERT INTO `{$prefix}roles` (name, description, is_default, sort_order)
              VALUES (?, 'gh122 fixture', 0, 999)", [$TESTNAME]);
    $rejected = false;
    try {
        db_query("INSERT INTO `{$prefix}roles` (name, description, is_default, sort_order)
                  VALUES (?, 'gh122 fixture duplicate', 0, 999)", [$TESTNAME]);
    } catch (Throwable $e) { $rejected = true; }
    is_ok($rejected, 'a second identical GLOBAL role name is rejected by uk_role_name_org');

    $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles` WHERE name = ?", [$TESTNAME]);
    is_ok($n === 1, "exactly one role row survives two attempts (got {$n})");

    // INSERT IGNORE is the form the real seed uses. It must now be a
    // genuine no-op rather than an append — this is THE bug, reproduced
    // directly against the exact statement shape sql/run_00_rbac.php uses.
    db_query("INSERT IGNORE INTO `{$prefix}roles` (name, description, is_default, sort_order)
              VALUES (?, 'gh122 fixture ignored', 0, 999)", [$TESTNAME]);
    $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles` WHERE name = ?", [$TESTNAME]);
    is_ok($n === 1, "INSERT IGNORE of the same global role name adds nothing (got {$n})");

    // A DIFFERENT org's role of the same name must still be allowed — the
    // constraint stops duplicates without preventing legitimate
    // per-org roles with a shared name.
    db_query("INSERT INTO `{$prefix}roles` (name, description, is_default, sort_order, org_id)
              VALUES (?, 'gh122 fixture org-scoped', 0, 999, 424242)", [$TESTNAME]);
    $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles` WHERE name = ?", [$TESTNAME]);
    is_ok($n === 2, "the same role name in a DIFFERENT org is still allowed (got {$n})");

    $cleanup();
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Re-running the RBAC seed does not create a second Facility role --\n";
// The headline regression, and the reporter's own suggested verification:
// "re-running sql/run_00_rbac.php twice and counting is the whole
// verification." Executed as a real subprocess, the way
// sql/run_migrations.php runs it.
// ─────────────────────────────────────────────────────────────────────────

$seed = $root . '/sql/run_00_rbac.php';
if (!is_file($seed)) {
    skip('sql/run_00_rbac.php not found');
} else {
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
    $out1 = test_run_cli([$php, $seed]);
    $mid = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
    $out2 = test_run_cli([$php, $seed]);
    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");

    is_ok($mid === $before, "first re-run of run_00_rbac.php adds no role ({$before} -> {$mid})");
    is_ok($after === $mid, "second re-run adds no role ({$mid} -> {$after})");
    is_ok($out1 !== null && stripos($out1, 'fatal') === false
       && $out2 !== null && stripos($out2, 'fatal') === false,
        'the seed runs clean on a database that already has its rows');

    $facilityCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}roles` WHERE name = 'Facility'");
    is_ok($facilityCount === 1, "exactly one 'Facility' role exists after two seed runs (got {$facilityCount})");
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The migration is idempotent and self-verifying --\n";
// ─────────────────────────────────────────────────────────────────────────

$mig = $root . '/sql/run_gh122_roles_org_key.php';
if (!is_file($mig)) {
    skip('sql/run_gh122_roles_org_key.php not found');
} else {
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
    $out = test_run_cli([$php, $mig]);
    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
    is_ok($before === $after, "re-running the migration changes no rows ({$before} -> {$after})");
    is_ok($out !== null && strpos($out, '[FAILED]') === false, 'the migration reports no failures');
    is_ok($out !== null && strpos($out, 'no duplicates') !== false,
        'the migration verifies its own outcome against the database');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Live data holds the invariant --\n";
// Whatever the history of this install, it must not currently be carrying
// duplicate roles. This is the direct check the reporter's own fresh eyes
// would run against a live install.
// ─────────────────────────────────────────────────────────────────────────

$dupeGroups = (int) db_fetch_value(
    "SELECT COUNT(*) FROM (
        SELECT COUNT(*) n FROM `{$prefix}roles`
         GROUP BY `name`, COALESCE(`org_id`, -1)
        HAVING n > 1) d");
is_ok($dupeGroups === 0, "no duplicate role groups in roles (found {$dupeGroups})");

$facilityCountFinal = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}roles` WHERE name = 'Facility'");
is_ok($facilityCountFinal === 1, "exactly one live 'Facility' role (found {$facilityCountFinal})");

$cleanup();

echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
exit($fail > 0 ? 1 : 0);
