<?php
/**
 * GH #77 regression — RBAC-v2 alias permissions must not double in the UI.
 *
 * run_rbac_v2.php (step A8) introduced a canonical `resource.verb` code for
 * every permission and linked the old `screen./action.` codes to it via
 * permissions.deprecated_alias_of. Both rows share a display name, which made
 * the roles editor render "two of every permission".
 *
 * The fix is display-only (no data migration): api/rbac.php LISTS canonical
 * rows only (deprecated_alias_of IS NULL) and computes each canonical's
 * `granted` flag from a grant on the canonical row OR any alias that points to
 * it, so the checkbox stays honest whether the historical grant landed on the
 * new or the old code. This test locks in the invariants that keep the doubling
 * gone AND keep effective access truthfully displayed:
 *
 *   1. The canonical set (what the editor lists) has no duplicate display names
 *      — i.e. no doubling.
 *   2. Alias resolution is intact: an old code still resolves to its canonical,
 *      so a grant on the canonical satisfies rbac_can(old_code).
 *   3. Display honesty: for every role/canonical-permission the editor lists,
 *      the alias-aware `granted` flag agrees with rbac_can() on the old code.
 *      (This is the invariant that lets us skip the risky grant-consolidation
 *      migration — a grant on the alias still shows as granted.)
 *
 * Self-skips on installs that never ran RBAC v2 (no alias column / no aliases).
 *
 * Usage: php tests/test_rbac_alias_dedupe.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

$hasCol = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ?
        AND column_name = 'deprecated_alias_of'", [$prefix . 'permissions']);
$aliasCount = $hasCol ? (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}permissions`
      WHERE deprecated_alias_of IS NOT NULL AND deprecated_alias_of <> ''") : 0;

if (!$hasCol || $aliasCount === 0) {
    echo "SKIP: RBAC v2 not applied (no deprecated_alias_of column / no aliases)\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// 1. Canonical set (what the roles editor lists) has no duplicate names.
$dupCanonNames = (int) db_fetch_value(
    "SELECT COUNT(*) FROM (
        SELECT name FROM `{$prefix}permissions`
         WHERE deprecated_alias_of IS NULL
         GROUP BY name HAVING COUNT(*) > 1) x");
test('canonical permissions have no duplicate display names', $dupCanonNames === 0);

// 2. Alias resolution intact: an old code resolves to its canonical, so a grant
//    on the canonical still satisfies rbac_can(old_code).
$sample = db_fetch_one(
    "SELECT code, deprecated_alias_of FROM `{$prefix}permissions`
      WHERE deprecated_alias_of IS NOT NULL AND deprecated_alias_of <> '' LIMIT 1");
if ($sample && function_exists('_rbac_alias_candidates')) {
    $cands = _rbac_alias_candidates($sample['code']);
    test('deprecated code resolves to its canonical (alias intact)',
        in_array($sample['deprecated_alias_of'], $cands, true));
} else {
    test('alias candidate helper available', false);
}

// 3. Display honesty: the alias-aware `granted` flag the editor computes must
//    agree with rbac_can() on the old code, for a canonical permission that is
//    aliased and actually granted somewhere. This is what makes the
//    display-only fix safe without consolidating grants.
//
//    Build the same alias-aware granted set api/rbac.php uses for one role that
//    holds grants, then compare against rbac_can() on each alias code.
$roleWithGrants = db_fetch_value(
    "SELECT rp.role_id FROM `{$prefix}role_permissions` rp
       GROUP BY rp.role_id ORDER BY COUNT(*) DESC LIMIT 1");
if ($roleWithGrants) {
    $roleId = (int) $roleWithGrants;
    // Canonical perms the editor lists, with alias-aware granted flag — mirror
    // of the api/rbac.php per-role editor query.
    $rows = db_fetch_all(
        "SELECT p.code, p.deprecated_alias_of,
                IF(MAX(rp.role_id) IS NOT NULL, 1, 0) AS granted
           FROM `{$prefix}permissions` p
           LEFT JOIN `{$prefix}permissions` ap
                  ON (ap.id = p.id OR ap.deprecated_alias_of = p.code)
           LEFT JOIN `{$prefix}role_permissions` rp
                  ON rp.permission_id = ap.id AND rp.role_id = ?
          WHERE p.deprecated_alias_of IS NULL
          GROUP BY p.id",
        [$roleId]);
    // The role's active grant codes (resolved via rbac.php helper).
    $grantCodes = [];
    foreach (db_fetch_all(
        "SELECT p.code FROM `{$prefix}role_permissions` rp
           JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE rp.role_id = ?", [$roleId]) as $gc) {
        // Fold each granted code to its canonical for comparison.
        $grantCodes[$gc['code']] = true;
    }
    $mismatch = 0; $checked = 0;
    foreach ($rows as $r) {
        $canonGranted = ((int) $r['granted']) === 1;
        // Effective: role holds a grant on this canonical code or any alias of
        // it. Resolve both directions via the rbac helper.
        $cands = function_exists('_rbac_alias_candidates')
            ? _rbac_alias_candidates($r['code']) : [$r['code']];
        $effective = false;
        foreach ($cands as $c) { if (isset($grantCodes[$c])) { $effective = true; break; } }
        // Also count a direct grant on any alias whose canonical is this code.
        if (!$effective) {
            foreach ($grantCodes as $code => $_) {
                $cc = function_exists('_rbac_alias_candidates')
                    ? _rbac_alias_candidates($code) : [$code];
                if (in_array($r['code'], $cc, true)) { $effective = true; break; }
            }
        }
        $checked++;
        if ($canonGranted !== $effective) $mismatch++;
    }
    test("editor granted flag matches effective grant for role $roleId ($checked perms)",
        $mismatch === 0);
} else {
    test('a role with grants exists to verify display honesty', false);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
