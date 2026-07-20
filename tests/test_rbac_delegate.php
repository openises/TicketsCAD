<?php
/**
 * Delegate-scope grant regression test.
 * Covers the fix for the broken Grant Role "delegate" scope (2026-06-23):
 *   - a delegate grant with delegated_by + scope_id succeeds and stores them
 *   - the missing-delegated_by guard still fires
 *   - the delegation_max_depth bound is enforced (default max = 1)
 *
 * Uses the default delegation_max_depth (1); does not mutate settings (the
 * _rbac_setting cache is per-process, so a mid-run change wouldn't take).
 * Calls rbac_grant_role() directly with granter id 0 (the documented
 * CLI/test path that bypasses the privilege guard). Cleans up its rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/rbac_grant.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== RBAC delegate-scope grants ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $w = ''): void { global $fail; echo "[FAIL] $n" . ($w ? " — $w" : '') . "\n"; $fail++; }

$users = db_fetch_all("SELECT id FROM `{$prefix}user` WHERE level > 0 ORDER BY id LIMIT 2");
$role  = (int) (db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE is_super = 0 ORDER BY sort_order, id LIMIT 1") ?: 0);
if (count($users) < 2 || !$role) {
    echo "[SKIP] need two non-admin users + a non-super role; not available.\n";
    exit(0);
}
$sam = (int) $users[0]['id'];   // grantee (receives delegated authority)
$pat = (int) $users[1]['id'];   // delegating user
$maxDepth = (int) (rbac_setting('rbac.delegation_max_depth', '1') ?? '1');
echo "grantee=#$sam delegating=#$pat role=#$role max_depth=$maxDepth\n\n";
if ($maxDepth < 1) { echo "[SKIP] delegation disabled on this install (max_depth=$maxDepth).\n"; exit(0); }

$created = [];
register_shutdown_function(function () use (&$created, $prefix) {
    foreach ($created as $gid) { try { db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$gid]); } catch (Throwable $e) {} }
});

// ── 1. Successful delegate grant (depth 1, within default max 1) ──
try {
    $gid = rbac_grant_role($sam, $role, 'delegate', $pat, null, 'delegate test', 0, $pat, 1);
    $created[] = $gid;
    $row = db_fetch_one("SELECT scope_kind, scope_id, delegated_by, delegation_depth FROM `{$prefix}user_roles` WHERE id = ?", [$gid]);
    ($row && $row['scope_kind'] === 'delegate') ? ok('delegate grant succeeds') : bad('delegate grant row missing/wrong');
    ((int) ($row['scope_id'] ?? 0) === $pat)        ? ok('scope_id = delegating user')      : bad('scope_id wrong', (string) ($row['scope_id'] ?? 'null'));
    ((int) ($row['delegated_by'] ?? 0) === $pat)    ? ok('delegated_by = delegating user')  : bad('delegated_by wrong', (string) ($row['delegated_by'] ?? 'null'));
    ((int) ($row['delegation_depth'] ?? -1) === 1)  ? ok('delegation_depth = 1')            : bad('delegation_depth wrong', (string) ($row['delegation_depth'] ?? 'null'));
} catch (Throwable $e) {
    bad('delegate grant succeeds', $e->getMessage());
}

// ── 2. Missing delegated_by still rejected ──
try {
    rbac_grant_role($sam, $role, 'delegate', $pat, null, 'no delegby', 0, null, 1);
    bad('missing delegated_by rejected', 'no exception thrown');
} catch (RuntimeException $e) {
    (strpos($e->getMessage(), 'delegated_by is required') !== false) ? ok('missing delegated_by rejected') : bad('wrong error for missing delegated_by', $e->getMessage());
}

// ── 3. Missing scope_id rejected (delegate needs the delegating user id) ──
try {
    rbac_grant_role($sam, $role, 'delegate', null, null, 'no scope', 0, $pat, 1);
    bad('missing scope_id rejected', 'no exception thrown');
} catch (RuntimeException $e) {
    (strpos($e->getMessage(), 'requires a scope_id') !== false) ? ok('missing scope_id rejected') : bad('wrong error for missing scope_id', $e->getMessage());
}

// ── 4. Depth bound enforced: depth max+1 exceeds the cap ──
try {
    rbac_grant_role($sam, $role, 'delegate', $pat, null, 'too deep', 0, $pat, $maxDepth + 1);
    bad('depth bound enforced', 'depth ' . ($maxDepth + 1) . ' accepted with max ' . $maxDepth);
} catch (RuntimeException $e) {
    (strpos($e->getMessage(), 'exceeds cap') !== false) ? ok('depth ' . ($maxDepth + 1) . ' rejected (max ' . $maxDepth . ')') : bad('wrong error for depth bound', $e->getMessage());
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
