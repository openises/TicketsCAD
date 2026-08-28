<?php
/**
 * GH#122 — Facility role must not duplicate on re-run of run_00_rbac.php.
 *
 * INSERT IGNORE on (name, org_id) cannot suppress duplicates for global
 * roles because MySQL treats each NULL in a UNIQUE index as distinct.
 * The seed now checks for an existing global Facility row before inserting.
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }

$prefix = $GLOBALS['db_prefix'] ?? '';
$root   = dirname(__DIR__);

echo "\n=== GH#122 — Facility role seed idempotency ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    skip('No database available — these tests need one');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$haveTable = false;
try {
    $haveTable = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'roles']) > 0;
} catch (Throwable $e) {}
if (!$haveTable) {
    skip('roles table missing — run php sql/run_00_rbac.php');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$seed = $root . '/sql/run_00_rbac.php';
if (!is_file($seed)) {
    skip('sql/run_00_rbac.php not found');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$countFacility = function () use ($prefix): int {
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}roles` WHERE `name` = ? AND `org_id` IS NULL",
        ['Facility']
    );
};

echo "\n-- 1. Re-running the RBAC seed does not create a second Facility role --\n";

$before = $countFacility();
$php    = PHP_BINARY ?: 'php';
$out    = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($seed) . ' 2>&1');
$mid    = $countFacility();
$out2   = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($seed) . ' 2>&1');
$after  = $countFacility();

is_ok($mid === $before,
      "first re-run adds no Facility row ({$before} -> {$mid})");
is_ok($after === $mid,
      "second re-run adds no Facility row ({$mid} -> {$after})");
is_ok(stripos($out, 'fatal') === false && stripos($out2, 'fatal') === false,
      'the seed runs clean on a database that already has its rows');
is_ok(
    strpos($out2, 'already present') !== false || $before === 0,
    'the seed reports the Facility row as already present when one exists'
);

echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
exit($fail > 0 ? 1 : 0);
