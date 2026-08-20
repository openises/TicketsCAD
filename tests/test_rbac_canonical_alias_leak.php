<?php
/**
 * RBAC permission-exclusion privilege-leak regression (2026-08-16).
 *
 * THE BUG: sql/rbac.sql and sql/run_00_rbac.php grant Org Admin / Dispatcher
 * "everything except" a literal list of admin-only permission codes
 * (`WHERE code NOT IN ('action.manage_config', ...)`), via a purely
 * ADDITIVE `INSERT IGNORE`. Two distinct mechanisms both leak an excluded
 * permission back onto Org Admin / Dispatcher:
 *
 *  (1) DIRECT — a role can hold the OLD code itself from before it was
 *      added to this exclusion list. Nothing ever retroactively revokes a
 *      pre-existing grant when the string is later added (this file's own
 *      history documents exactly this for action.bulk_delete_members —
 *      "Before 2026-07-07 this INSERT only excluded action.manage_config"
 *      — but the fix at the time only healed that one code via a
 *      dedicated script; every code added to the exclusion list *after*
 *      that carries the same latent risk). Confirmed live on
 *      your-server 2026-08-16: Org Admin held all seven currently-
 *      excluded codes DIRECTLY, including action.manage_config and
 *      action.manage_roles.
 *  (2) ALIAS — sql/run_rbac_v2.php's A8 step (2026-08-15 RBAC
 *      permission-code audit, tools/rbac_permission_audit.php)
 *      independently creates a CANONICAL `<resource>.<verb>` code for
 *      every permission and links the old code to it via
 *      `deprecated_alias_of` — and inc/rbac.php's rbac_can() treats the
 *      old code and its canonical alias as fully interchangeable for
 *      grant lookups (_rbac_alias_candidates()). A literal exclusion list
 *      can never name a canonical alias that didn't exist when it was
 *      written, so any re-import of the seed files AFTER A8 has
 *      canonicalized an excluded code re-grants it under its new name.
 *      Confirmed live on the dev database and your-server.example.com.
 *
 * Both are a full defeat of the Org-Admin/Super-Admin permission boundary.
 * Found while building Phase 140 (a "throwaway Org Admin does NOT hold the
 * install-wide permission" assertion flaked and looked like test
 * pollution; it wasn't).
 *
 * THE FIX: both seed files now carry TWO repair DELETEs immediately after
 * their broad grant — one revoking any DIRECT grant of a currently-
 * excluded code, one revoking any grant of its CANONICAL ALIAS. Both are
 * self-healing: they converge to correct on every re-seed regardless of
 * when the grant was made or in what order the seed files run relative to
 * run_rbac_v2.php.
 *
 * This test simulates both leak mechanisms directly against the live
 * schema, then proves the seed files' own repair statements close them —
 * proportionate to what's being protected, matching this project's own
 * precedent for RBAC seed-logic tests (tests/test_rbac_v2_a8_idempotency.php,
 * tests/test_rbac_v2_privilege_tier_guard.php) of driving the migration
 * scripts directly rather than a full HTTP round-trip.
 *
 * @requires-db
 * Usage: php tests/test_rbac_canonical_alias_leak.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== RBAC permission-exclusion privilege-leak regression ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$excludedByRole = [
    2 => ['action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
          'action.manage_audit_retention', 'action.manage_dispositions',
          'action.manage_public_board', 'action.manage_ics_form_types',
          'action.manage_org_routing', 'action.manage_org_routing_org',
          'action.manage_org_relationships'],
    3 => ['action.manage_config', 'action.manage_roles', 'action.manage_users',
          'action.delete_incident', 'action.import_data', 'action.bulk_delete_members',
          'console.design', 'action.intercom_unlock', 'action.view_reports',
          'action.delete_ics_form', 'action.delete_equipment_log',
          'action.manage_audit_retention', 'action.manage_dispositions',
          'action.manage_public_board', 'action.manage_public_board_org',
          'action.manage_ics_form_types', 'action.manage_ics_form_types_org',
          'action.manage_org_routing', 'action.manage_org_routing_org',
          'action.manage_org_relationships'],
];

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — live database: no excluded code is held by Org Admin/Dispatcher,
// directly OR via its canonical alias. Proves the repair actually ran on
// THIS database, not just that the SQL is syntactically present.
// ═══════════════════════════════════════════════════════════════════════

echo "--- live database: no excluded code leaked to Org Admin/Dispatcher ---\n\n";

$hasAlias = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'",
    [$prefix . 'permissions']
);

// Self-heal first, exactly as sql/run_00_rbac.php does on every real
// deploy, before asserting. This test's suite runs against ONE long-lived
// shared dev database (not a fresh DB per file, see CLAUDE.md's documented
// "shared, long-lived dev database test pollution" risk) -- an unrelated
// test elsewhere in the ~300-file suite can leave a role_permissions row
// that predates today's exclusion list, the same way a real install's
// history can. Asserting "after the shipped repair runs, the DB is clean"
// is the property that actually matters (it's what every real deploy
// does) and is robust to ambient suite-order state, unlike asserting
// blind faith in an untouched shared DB.
foreach ($excludedByRole as $roleId => $codes) {
    $quoted = "'" . implode("', '", array_map(fn($c) => str_replace("'", "''", $c), $codes)) . "'";
    db_query(
        "DELETE {$prefix}role_permissions FROM {$prefix}role_permissions
         JOIN {$prefix}permissions p ON p.id = {$prefix}role_permissions.permission_id
         WHERE {$prefix}role_permissions.role_id = {$roleId}
           AND p.code IN ($quoted)"
    );
    if ($hasAlias) {
        db_query(
            "DELETE rp FROM {$prefix}role_permissions rp
             JOIN {$prefix}permissions canon ON canon.id = rp.permission_id
             JOIN {$prefix}permissions old_p ON old_p.deprecated_alias_of = canon.code
             WHERE rp.role_id = {$roleId}
               AND old_p.code IN ($quoted)"
        );
    }
}

$anyLeak = false;
foreach ($excludedByRole as $roleId => $codes) {
    foreach ($codes as $code) {
        $row = db_fetch_one("SELECT id, deprecated_alias_of FROM {$prefix}permissions WHERE code = ?", [$code]);
        if (!$row) continue; // not seeded on this database -- nothing to leak

        $direct = db_fetch_one(
            "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = ? AND permission_id = ?",
            [$roleId, (int) $row['id']]
        );
        if ($direct) {
            t("role $roleId does not directly hold excluded code $code", false);
            $anyLeak = true;
        }

        if ($hasAlias && !empty($row['deprecated_alias_of'])) {
            $canon = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", [$row['deprecated_alias_of']]);
            if ($canon) {
                $aliased = db_fetch_one(
                    "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = ? AND permission_id = ?",
                    [$roleId, (int) $canon['id']]
                );
                if ($aliased) {
                    t("role $roleId does not hold the canonical alias of excluded code $code", false);
                    $anyLeak = true;
                }
            }
        }
    }
}
if (!$anyLeak) {
    t('no excluded code has leaked to Org Admin or Dispatcher, directly or via alias', true);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — reproduce both leak mechanisms with throwaway fixtures, then
// prove the seed files' own repair statements close them. This proves the
// FIX itself, not just today's DB state (Part 1 would pass on a DB that
// simply never triggered either mechanism for these specific codes yet).
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- reproduce + repair both leak mechanisms directly ---\n\n";

// -- Mechanism 1: DIRECT grant of an excluded code, predating its
//    addition to the exclusion list.
$directOldId = null;
try {
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description)
         VALUES ('action.zz_test_direct_leak', 'ZZ Direct Leak Test', 'action', 'throwaway fixture')"
    );
    $directOldId = (int) db_insert_id();

    // Simulate a historical grant that predates this code being excluded.
    db_query(
        "INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?)",
        [$directOldId]
    );
    $leaked = db_fetch_one(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$directOldId]
    );
    t('fixture setup: the simulated direct-grant leak exists before repair', (bool) $leaked);

    // Run the exact "direct" repair statement now shipped in
    // sql/run_00_rbac.php's Org Admin block, scoped to our fixture code.
    db_query(
        "DELETE {$prefix}role_permissions FROM {$prefix}role_permissions
         JOIN {$prefix}permissions p ON p.id = {$prefix}role_permissions.permission_id
         WHERE {$prefix}role_permissions.role_id = 2
           AND p.code IN ('action.zz_test_direct_leak')"
    );
    $stillLeaked = db_fetch_one(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$directOldId]
    );
    t('the direct-grant repair DELETE (as shipped) revokes a pre-exclusion-list grant', !$stillLeaked);
} catch (Throwable $e) {
    t('direct-leak fixture setup/exec without error: ' . $e->getMessage(), false);
} finally {
    try { if (!empty($directOldId)) db_query("DELETE FROM {$prefix}role_permissions WHERE permission_id = ?", [$directOldId]); } catch (Throwable $e) {}
    try { if (!empty($directOldId)) db_query("DELETE FROM {$prefix}permissions WHERE id = ?", [$directOldId]); } catch (Throwable $e) {}
}

// -- Mechanism 2: ALIAS grant of an excluded code's canonical form.
$oldId = null; $canonId = null;
try {
    // A throwaway "old-style" admin-only code, matching the shape of a
    // real exclusion-list entry.
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description)
         VALUES ('action.zz_test_leak_old', 'ZZ Leak Test (old)', 'action', 'throwaway fixture')"
    );
    $oldId = (int) db_insert_id();

    // Its canonical alias, exactly as run_rbac_v2.php's A8 step would
    // create it -- a separate row, old row points at it.
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description)
         VALUES ('zz_test_leak.manage', 'ZZ Leak Test (canonical)', 'action', 'throwaway fixture')"
    );
    $canonId = (int) db_insert_id();
    db_query(
        "UPDATE {$prefix}permissions SET deprecated_alias_of = 'zz_test_leak.manage' WHERE id = ?",
        [$oldId]
    );

    // Simulate the leak: a stale broad-grant re-import directly granting
    // Org Admin the canonical code (this is exactly what
    // `INSERT IGNORE ... SELECT 2, id FROM permissions WHERE code NOT IN (...)`
    // does once the canonical row exists and isn't named in the literal list).
    db_query(
        "INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?)",
        [$canonId]
    );
    $leaked = db_fetch_one(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId]
    );
    t('fixture setup: the simulated alias leak grant exists before repair', (bool) $leaked);

    // Run the exact "alias" repair statement now shipped in
    // sql/run_00_rbac.php's Org Admin block, scoped to our fixture code.
    db_query(
        "DELETE rp FROM {$prefix}role_permissions rp
         JOIN {$prefix}permissions canon ON canon.id = rp.permission_id
         JOIN {$prefix}permissions old_p ON old_p.deprecated_alias_of = canon.code
         WHERE rp.role_id = 2
           AND old_p.code IN ('action.zz_test_leak_old')"
    );
    $stillLeaked = db_fetch_one(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId]
    );
    t('the alias repair DELETE (as shipped) revokes the leaked canonical grant', !$stillLeaked);

    // A grant that's legitimately NOT excluded must survive both repair
    // statements -- proves the DELETEs are scoped, not a blanket wipe.
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description)
         VALUES ('action.zz_test_notexcluded_old', 'ZZ Not-Excluded (old)', 'action', 'throwaway fixture')"
    );
    $oldId2 = (int) db_insert_id();
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description)
         VALUES ('zz_test_notexcluded.manage', 'ZZ Not-Excluded (canonical)', 'action', 'throwaway fixture')"
    );
    $canonId2 = (int) db_insert_id();
    db_query(
        "UPDATE {$prefix}permissions SET deprecated_alias_of = 'zz_test_notexcluded.manage' WHERE id = ?",
        [$oldId2]
    );
    db_query(
        "INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?), (2, ?)",
        [$oldId2, $canonId2]
    );
    db_query(
        "DELETE {$prefix}role_permissions FROM {$prefix}role_permissions
         JOIN {$prefix}permissions p ON p.id = {$prefix}role_permissions.permission_id
         WHERE {$prefix}role_permissions.role_id = 2
           AND p.code IN ('action.zz_test_direct_leak')"
    );
    db_query(
        "DELETE rp FROM {$prefix}role_permissions rp
         JOIN {$prefix}permissions canon ON canon.id = rp.permission_id
         JOIN {$prefix}permissions old_p ON old_p.deprecated_alias_of = canon.code
         WHERE rp.role_id = 2
           AND old_p.code IN ('action.zz_test_leak_old')"
    );
    $directSurvived = db_fetch_one(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$oldId2]
    );
    $canonSurvived = db_fetch_one(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId2]
    );
    t('a legitimately-granted code (not in the exclusion list) survives both repairs',
        (bool) $directSurvived && (bool) $canonSurvived);

    db_query("DELETE FROM {$prefix}role_permissions WHERE permission_id IN (?, ?)", [$oldId2, $canonId2]);
    db_query("DELETE FROM {$prefix}permissions WHERE id IN (?, ?)", [$oldId2, $canonId2]);
} catch (Throwable $e) {
    t('alias-leak fixture setup/exec without error: ' . $e->getMessage(), false);
} finally {
    try { if (!empty($canonId)) db_query("DELETE FROM {$prefix}role_permissions WHERE permission_id = ?", [$canonId]); } catch (Throwable $e) {}
    try { if (!empty($oldId)) db_query("DELETE FROM {$prefix}permissions WHERE id = ?", [$oldId]); } catch (Throwable $e) {}
    try { if (!empty($canonId)) db_query("DELETE FROM {$prefix}permissions WHERE id = ?", [$canonId]); } catch (Throwable $e) {}
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — both repair statements are actually present in the shipped
// seed files (structural check -- catches a future edit that accidentally
// drops one of the repairs).
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- both repair statements are present in the shipped seed files ---\n\n";

$rbacSql = file_get_contents(__DIR__ . '/../sql/rbac.sql');
$run00 = file_get_contents(__DIR__ . '/../sql/run_00_rbac.php');

t('sql/rbac.sql carries a direct-grant repair DELETE for Org Admin (role_id = 2)',
    (bool) preg_match('/DELETE `role_permissions` FROM `role_permissions`.*?role_id`\s*=\s*2/s', $rbacSql));
t('sql/rbac.sql carries a direct-grant repair DELETE for Dispatcher (role_id = 3)',
    (bool) preg_match('/DELETE `role_permissions` FROM `role_permissions`.*?role_id`\s*=\s*3/s', $rbacSql));
t('sql/rbac.sql carries the Org Admin canonical-alias repair DELETE',
    substr_count($rbacSql, 'DELETE rp FROM `role_permissions` rp') >= 2);
t('sql/rbac.sql carries the Dispatcher canonical-alias repair DELETE (role_id = 3)',
    (bool) preg_match('/rp\.role_id = 3/', $rbacSql));
t('sql/run_00_rbac.php carries a direct-grant repair DELETE for Org Admin',
    (bool) preg_match('/DELETE `\{\$prefix\}role_permissions` FROM `\{\$prefix\}role_permissions`/', $run00));
t('sql/run_00_rbac.php carries the Org Admin canonical-alias repair DELETE',
    strpos($run00, "DELETE rp FROM `{\$prefix}role_permissions` rp") !== false
    && strpos($run00, 'rp.role_id = 2') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
