<?php
/**
 * Phase 143 (2026-08-17) — Cross-org STANDING relationships: RBAC.
 *
 * Run BEFORE any Phase 143 endpoint code exists -- this proves the RBAC
 * seeding is correct in isolation, same discipline Phase 141/142 used for
 * their own RBAC tests (tests/test_org_sharing_rbac.php).
 *
 * Covers:
 *   1. All three permission codes exist.
 *   2. Exact role-grant boundaries: Super Admin holds all three;
 *      Org Admin and Dispatcher hold action.manage_org_relationships_org
 *      and action.activate_org_relationship but NOT
 *      action.manage_org_relationships; Operator/Read-Only/Field Unit hold
 *      none of the three.
 *   3. Re-running sql/run_00_rbac.php's broad sweep does not leak
 *      action.manage_org_relationships, and does not withdraw the other two.
 *   4. Reproduce + repair both leak mechanisms (direct grant + canonical
 *      alias) for action.manage_org_relationships specifically, mirroring
 *      tests/test_rbac_canonical_alias_leak.php's own approach.
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_rbac.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Cross-Org Standing Relationships: RBAC ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — permission rows exist
// ═══════════════════════════════════════════════════════════════════════

echo "--- Permission rows ---\n\n";

$permGlobal   = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_relationships']);
$permOrg      = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_relationships_org']);
$permActivate = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.activate_org_relationship']);
t("action.manage_org_relationships permission row exists", (bool) $permGlobal);
t("action.manage_org_relationships_org permission row exists", (bool) $permOrg);
t("action.activate_org_relationship permission row exists", (bool) $permActivate);

if (!$permGlobal || !$permOrg || !$permActivate) {
    echo "\nSKIP: permission rows missing -- run sql/run_00_rbac.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

function _p143_role_has(int $roleId, int $permId): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = ? AND permission_id = ?",
        [$roleId, $permId]
    );
}

$gId = (int) $permGlobal['id'];
$oId = (int) $permOrg['id'];
$aId = (int) $permActivate['id'];

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — exact role-grant boundaries
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Role grants ---\n\n";

t("Super Admin (role 1) holds action.manage_org_relationships", _p143_role_has(1, $gId));
t("Super Admin (role 1) holds action.manage_org_relationships_org", _p143_role_has(1, $oId));
t("Super Admin (role 1) holds action.activate_org_relationship", _p143_role_has(1, $aId));

t("Org Admin (role 2) does NOT hold action.manage_org_relationships (install-wide, Super-Admin-only)", !_p143_role_has(2, $gId));
t("Org Admin (role 2) DOES hold action.manage_org_relationships_org (deliberate departure from Phase 141's own `_org` precedent)", _p143_role_has(2, $oId));
t("Org Admin (role 2) DOES hold action.activate_org_relationship", _p143_role_has(2, $aId));

t("Dispatcher (role 3) does NOT hold action.manage_org_relationships", !_p143_role_has(3, $gId));
t("Dispatcher (role 3) DOES hold action.manage_org_relationships_org (spec.md's own Org-Admin-persona user story would otherwise be unreachable for Dispatcher too)", _p143_role_has(3, $oId));
t("Dispatcher (role 3) DOES hold action.activate_org_relationship (spec.md's own dispatcher-persona activation user story)", _p143_role_has(3, $aId));

t("Operator (role 4) does NOT hold action.manage_org_relationships", !_p143_role_has(4, $gId));
t("Operator (role 4) does NOT hold action.manage_org_relationships_org", !_p143_role_has(4, $oId));
t("Operator (role 4) does NOT hold action.activate_org_relationship", !_p143_role_has(4, $aId));

t("Read-Only (role 5) does NOT hold action.manage_org_relationships", !_p143_role_has(5, $gId));
t("Read-Only (role 5) does NOT hold action.manage_org_relationships_org", !_p143_role_has(5, $oId));
t("Read-Only (role 5) does NOT hold action.activate_org_relationship", !_p143_role_has(5, $aId));

t("Field Unit (role 6) does NOT hold action.manage_org_relationships", !_p143_role_has(6, $gId));
t("Field Unit (role 6) does NOT hold action.manage_org_relationships_org", !_p143_role_has(6, $oId));
t("Field Unit (role 6) does NOT hold action.activate_org_relationship", !_p143_role_has(6, $aId));

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — re-running run_00_rbac.php's broad sweep leaves the correct
// grants intact (does not leak the install-wide code, does not withdraw
// the other two).
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Re-run of run_00_rbac.php broad sweep ---\n\n";

$rbacScript = $base . '/sql/run_00_rbac.php';
if (file_exists($rbacScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($rbacScript) . ' 2>&1');
    t("re-running sql/run_00_rbac.php exits without a fatal error", strpos((string) $out, 'Fatal error') === false);

    t("AFTER re-run: Org Admin still does NOT hold action.manage_org_relationships", !_p143_role_has(2, $gId));
    t("AFTER re-run: Org Admin still HOLDS action.manage_org_relationships_org", _p143_role_has(2, $oId));
    t("AFTER re-run: Org Admin still HOLDS action.activate_org_relationship", _p143_role_has(2, $aId));
    t("AFTER re-run: Dispatcher still does NOT hold action.manage_org_relationships", !_p143_role_has(3, $gId));
    t("AFTER re-run: Dispatcher still HOLDS action.manage_org_relationships_org", _p143_role_has(3, $oId));
    t("AFTER re-run: Dispatcher still HOLDS action.activate_org_relationship", _p143_role_has(3, $aId));
} else {
    echo "SKIP: sql/run_00_rbac.php not found.\n";
}

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — reproduce + repair both leak mechanisms for
// action.manage_org_relationships, mirroring
// tests/test_rbac_canonical_alias_leak.php's own approach.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Reproduce + repair both leak mechanisms (Org Admin, role 2, action.manage_org_relationships only) ---\n\n";

$hasAlias = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'",
    [$prefix . 'permissions']
);

// -- Mechanism 1: DIRECT grant predating the exclusion list. --
db_query("INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?)", [$gId]);
$leaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$gId]);
t("fixture setup: simulated direct-grant leak of action.manage_org_relationships onto Org Admin exists before repair", (bool) $leaked);

db_query(
    "DELETE {$prefix}role_permissions FROM {$prefix}role_permissions
     JOIN {$prefix}permissions p ON p.id = {$prefix}role_permissions.permission_id
     WHERE {$prefix}role_permissions.role_id = 2
       AND p.code = 'action.manage_org_relationships'"
);
$stillLeaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$gId]);
t("the direct-grant repair DELETE (as shipped) revokes a pre-exclusion-list grant of action.manage_org_relationships", !$stillLeaked);

if ($hasAlias) {
    // -- Mechanism 2: ALIAS grant of the canonical form. --
    $canonCode = 'org_relationships.zztest_canon';
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description) VALUES (?, ?, 'action', 'throwaway fixture')",
        [$canonCode, 'ZZ canonical fixture for action.manage_org_relationships']
    );
    $canonId = (int) db_insert_id();
    db_query("UPDATE {$prefix}permissions SET deprecated_alias_of = ? WHERE code = ?", [$canonCode, 'action.manage_org_relationships']);

    db_query("INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?)", [$canonId]);
    $aliasLeaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId]);
    t("fixture setup: simulated alias-grant leak of action.manage_org_relationships's canonical form onto Org Admin exists before repair", (bool) $aliasLeaked);

    db_query(
        "DELETE rp FROM {$prefix}role_permissions rp
         JOIN {$prefix}permissions canon ON canon.id = rp.permission_id
         JOIN {$prefix}permissions old_p ON old_p.deprecated_alias_of = canon.code
         WHERE rp.role_id = 2
           AND old_p.code = 'action.manage_org_relationships'"
    );
    $aliasStillLeaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId]);
    t("the alias repair DELETE (as shipped) revokes the leaked canonical grant for action.manage_org_relationships", !$aliasStillLeaked);

    // Cleanup fixture.
    db_query("UPDATE {$prefix}permissions SET deprecated_alias_of = NULL WHERE code = 'action.manage_org_relationships'");
    db_query("DELETE FROM {$prefix}role_permissions WHERE permission_id = ?", [$canonId]);
    db_query("DELETE FROM {$prefix}permissions WHERE id = ?", [$canonId]);
}

// Restore true state via the shipped broad sweep (re-grants Super Admin,
// leaves Org Admin without the install-wide code, holding the other two).
if (file_exists($rbacScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($rbacScript) . ' 2>&1');
}
t("final state: Org Admin does NOT hold action.manage_org_relationships after all repro/repair cycles", !_p143_role_has(2, $gId));
t("final state: Org Admin still holds action.manage_org_relationships_org and action.activate_org_relationship", _p143_role_has(2, $oId) && _p143_role_has(2, $aId));
t("final state: Super Admin holds all three codes", _p143_role_has(1, $gId) && _p143_role_has(1, $oId) && _p143_role_has(1, $aId));

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — structural: repair statements + inclusion/exclusion lists are
// present in the shipped seed files, scoped correctly.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Shipped seed files carry the correct lists (structural) ---\n\n";

$rbacSql = file_get_contents($base . '/sql/rbac.sql');
$run00   = file_get_contents($base . '/sql/run_00_rbac.php');

t("sql/rbac.sql: permissions INSERT list includes all three codes",
    strpos($rbacSql, "'action.manage_org_relationships'") !== false
    && strpos($rbacSql, "'action.manage_org_relationships_org'") !== false
    && strpos($rbacSql, "'action.activate_org_relationship'") !== false);
t("sql/rbac.sql: Org Admin exclusion list includes ONLY action.manage_org_relationships (not the other two)",
    (bool) preg_match("/NOT IN \\(.*?action\\.manage_org_relationships'\\)/s", $rbacSql)
    && substr_count($rbacSql, "'action.manage_org_relationships_org'") === 1  // only the permission INSERT itself
    && substr_count($rbacSql, "'action.activate_org_relationship'") === 1);
t("sql/rbac.sql: Dispatcher exclusion list includes action.manage_org_relationships",
    substr_count($rbacSql, "'action.manage_org_relationships'") >= 2);
t("sql/run_00_rbac.php: \$perms seed array includes all three codes",
    strpos($run00, "'action.manage_org_relationships'") !== false
    && strpos($run00, "'action.manage_org_relationships_org'") !== false
    && strpos($run00, "'action.activate_org_relationship'") !== false);
t("sql/run_00_rbac.php: Org Admin NOT IN exclusion includes action.manage_org_relationships",
    (bool) preg_match("/NOT IN \\('action\\.manage_config'.*?action\\.manage_org_relationships'\\)/s", $run00));
t("sql/run_00_rbac.php: Dispatcher ALLOW-list DOES explicitly name both broadly-granted codes",
    (bool) preg_match("/role_id`, `permission_id`\\)\\s*\\n\\s*SELECT 3,[\\s\\S]*?'action\\.manage_org_relationships_org'[\\s\\S]*?'action\\.activate_org_relationship'/", $run00));

$excludedByRoleTest = file_get_contents($base . '/tests/test_rbac_canonical_alias_leak.php');
t("tests/test_rbac_canonical_alias_leak.php \$excludedByRole[2] includes action.manage_org_relationships ONLY",
    (bool) preg_match("/2 => \\[.*?action\\.manage_org_relationships'\\]/s", $excludedByRoleTest)
    && !preg_match("/2 => \\[[^\\]]*?action\\.manage_org_relationships_org/s", $excludedByRoleTest));
t("tests/test_rbac_canonical_alias_leak.php \$excludedByRole[3] includes action.manage_org_relationships ONLY",
    (bool) preg_match("/3 => \\[.*?action\\.manage_org_relationships'\\]/s", $excludedByRoleTest)
    && !preg_match("/3 => \\[[^\\]]*?action\\.manage_org_relationships_org/s", $excludedByRoleTest));

// ═══════════════════════════════════════════════════════════════════════
// Part 6 — tools/rbac_permission_audit.php resolves all three codes cleanly.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- tools/rbac_permission_audit.php ---\n\n";

$auditScript = $base . '/tools/rbac_permission_audit.php';
if (file_exists($auditScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $auditOut = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($auditScript) . ' 2>&1');
    t("tools/rbac_permission_audit.php exits without a fatal error", strpos((string) $auditOut, 'Fatal error') === false);
} else {
    echo "SKIP: tools/rbac_permission_audit.php not found.\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
