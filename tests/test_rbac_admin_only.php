<?php
/**
 * RBAC admin_only structural guard regression (2026-08-22).
 *
 * This project has hit the SAME bug class five separate times: an
 * admin-only permission code silently ends up granted to a role that
 * should never hold it (see CLAUDE.md's several "RBAC EXCLUSION-LIST
 * MECHANISM LEAKS" entries). Most recently, Phase 149's action.manage_calls
 * leaked onto Dispatcher via sql/run_rbac_v2.php's A8 canonical-alias
 * mirror step -- IN THE SAME COMMIT that created the permission and its
 * own exclusion-list entry, meaning even maximal care in the moment wasn't
 * enough. Root cause: "admin-only" was never a real, checkable database
 * property -- only a hand-maintained `WHERE code NOT IN (...)` string list
 * that a new canonical alias, a new migration, or a forgotten edit could
 * all bypass invisibly.
 *
 * THE FIX: `permissions.admin_only` (0=unrestricted, 1=Org Admin or above,
 * 2=Super Admin only -- see inc/rbac_admin_only.php for the full model and
 * why a single boolean can't represent both real tiers this project's
 * incidents span) is now a real schema column, consulted by:
 *   - api/rbac.php's set_permissions / set_role_permission (the live,
 *     human-driven grant path)
 *   - sql/run_rbac_v2.php's A8 mirror step (the automated path that
 *     caused the Phase 149 incident specifically)
 *   - sql/rbac.sql's and sql/run_00_rbac.php's broad "everything except"
 *     grant statements (structural correctness independent of the
 *     hand-maintained exclusion-list text)
 *
 * This file proves:
 *   Part 1 -- the column exists with the right shape.
 *   Part 2 -- every currently-known admin-only code is classified
 *             correctly, under BOTH its old code and canonical alias name.
 *   Part 3 -- the guard functions (inc/rbac_admin_only.php) give the
 *             correct answer for the real historical incident pairings.
 *   Part 4 -- THE Phase 149 mechanism, reproduced directly: the OLD
 *             (unguarded) A8 mirror SQL WOULD leak a tier-1 fixture
 *             permission onto a tier-0 role; the NEW (guarded) mirror SQL,
 *             run against the identical fixture state, does not.
 *   Part 5 -- no role's actual, live effective permissions changed: every
 *             role's holding of an admin_only>0 code matches its intended
 *             tier, checked as an invariant (not a byte-diff against a
 *             remembered snapshot) so it is robust to this shared dev
 *             database's ordinary concurrent-session churn.
 *
 * @requires-db
 * Usage: php tests/test_rbac_admin_only.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac_admin_only.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== RBAC admin_only structural guard regression ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — schema
// ═══════════════════════════════════════════════════════════════════════
echo "--- Part 1: schema ---\n\n";

t('permissions.admin_only column exists', rbac_admin_only_column_exists());

$colRow = db_fetch_one(
    "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
    [$prefix . 'permissions']
);
t('admin_only is NOT NULL', $colRow && strtoupper($colRow['IS_NULLABLE']) === 'NO');
t('admin_only defaults to 0', $colRow && (int) $colRow['COLUMN_DEFAULT'] === 0);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — classification of every currently-known admin-only code,
// cross-referenced against sql/rbac.sql's own exclusion lists (the
// authoritative source), verified under BOTH the old code and its
// canonical alias (if one currently exists on this database).
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 2: classification ---\n\n";

$tier2 = [
    'action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
    'action.manage_audit_retention', 'action.manage_dispositions',
    'action.manage_public_board', 'action.manage_ics_form_types',
    'action.manage_org_routing', 'action.manage_org_routing_org',
    'action.manage_org_relationships',
];
$tier1 = [
    'action.manage_users', 'action.delete_incident', 'action.import_data',
    'console.design', 'action.intercom_unlock', 'action.view_reports',
    'action.delete_ics_form', 'action.delete_equipment_log',
    'action.manage_public_board_org', 'action.manage_ics_form_types_org',
    'action.manage_matrix', 'action.manage_calls',
];
$tier0Reserved = ['screen.facility_portal', 'action.facility_self_report'];

function admin_only_check_code(string $code, int $expected, string $prefix): void {
    $row = db_fetch_one(
        "SELECT id, admin_only, deprecated_alias_of FROM `{$prefix}permissions` WHERE code = ?",
        [$code]
    );
    if (!$row) { t("'$code' not seeded on this database -- skipped", true); return; }
    t("'$code' has admin_only = $expected", (int) $row['admin_only'] === $expected);
    if (!empty($row['deprecated_alias_of'])) {
        $canon = db_fetch_one("SELECT admin_only FROM `{$prefix}permissions` WHERE code = ?", [$row['deprecated_alias_of']]);
        if ($canon) {
            t("'$code''s canonical alias ({$row['deprecated_alias_of']}) also has admin_only = $expected",
                (int) $canon['admin_only'] === $expected);
        }
    }
}

foreach ($tier2 as $code) admin_only_check_code($code, RBAC_ADMIN_ONLY_SUPER_ADMIN, $prefix);
foreach ($tier1 as $code) admin_only_check_code($code, RBAC_ADMIN_ONLY_ORG_ADMIN_UP, $prefix);
foreach ($tier0Reserved as $code) {
    admin_only_check_code($code, RBAC_ADMIN_ONLY_NONE, $prefix);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — guard function correctness against the REAL historical
// incident pairings (not synthetic fixtures) — this proves the guard
// gives the right answer for the exact codes that actually leaked.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 3: guard function correctness ---\n\n";

$superAdminRoleId = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE is_super = 1 LIMIT 1");
$orgAdminRoleId = 2; // seeded with an explicit id, like every other original role — see inc/rbac_admin_only.php
$dispatcherRoleId = 3;

t('a Super Admin role id was found on this database', $superAdminRoleId > 0);

function perm_id(string $code, string $prefix): ?int {
    $id = db_fetch_value("SELECT id FROM `{$prefix}permissions` WHERE code = ?", [$code]);
    return $id ? (int) $id : null;
}

if ($superAdminRoleId > 0) {
    $manageConfigId = perm_id('action.manage_config', $prefix);
    $manageCallsId = perm_id('action.manage_calls', $prefix);
    $netCheckinId = perm_id('action.net_checkin', $prefix); // tier 0, ordinary

    if ($manageConfigId) {
        t('Super Admin MAY hold action.manage_config (tier 2)',
            rbac_grant_permission_allowed($superAdminRoleId, $manageConfigId));
        t('Org Admin may NOT hold action.manage_config (tier 2) — the "full defeat of the boundary" case',
            !rbac_grant_permission_allowed($orgAdminRoleId, $manageConfigId));
        t('Dispatcher may NOT hold action.manage_config (tier 2)',
            !rbac_grant_permission_allowed($dispatcherRoleId, $manageConfigId));

        $threw = false;
        try { rbac_assert_grant_permission_allowed($dispatcherRoleId, $manageConfigId); }
        catch (RuntimeException $e) { $threw = true; }
        t('rbac_assert_grant_permission_allowed() throws for Dispatcher + action.manage_config', $threw);
    } else {
        t('action.manage_config not seeded -- Part 3a skipped', true);
    }

    if ($manageCallsId) {
        // THE Phase 149 pairing specifically.
        t('Org Admin MAY hold action.manage_calls (tier 1) — this must stay true, Org Admin held it correctly all along',
            rbac_grant_permission_allowed($orgAdminRoleId, $manageCallsId));
        t('Dispatcher may NOT hold action.manage_calls (tier 1) — the exact Phase 149 incident pairing',
            !rbac_grant_permission_allowed($dispatcherRoleId, $manageCallsId));
        t('Super Admin MAY hold action.manage_calls (tier 1)',
            rbac_grant_permission_allowed($superAdminRoleId, $manageCallsId));
    } else {
        t('action.manage_calls not seeded -- Part 3b skipped', true);
    }

    if ($netCheckinId) {
        t('an ordinary tier-0 permission (action.net_checkin) may be held by Dispatcher',
            rbac_grant_permission_allowed($dispatcherRoleId, $netCheckinId));
    } else {
        t('action.net_checkin not seeded -- Part 3c skipped', true);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — THE Phase 149 mechanism, reproduced directly with throwaway
// fixtures: the OLD (unguarded) A8 mirror SQL WOULD leak; the NEW
// (guarded) mirror SQL, run against the identical starting state, does
// not. This is not a description of the bug — it is the bug, executed.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 4: Phase 149 mechanism reproduced directly ---\n\n";

$oldFixtureId = null;
$canonFixtureIdOld = null;
$canonFixtureIdNew = null;
try {
    // A throwaway tier-1 ("Org Admin or above") old-style code, exactly
    // the shape action.manage_calls was before its own canonicalization.
    db_query(
        "INSERT INTO `{$prefix}permissions` (code, name, category, description, admin_only)
         VALUES ('action.zz_test_admin_only_old', 'ZZ admin_only Test (old)', 'action', 'throwaway fixture', 1)"
    );
    $oldFixtureId = (int) db_insert_id();

    // Simulate the real incident's precondition: Dispatcher (tier 0)
    // transiently/incorrectly holds the OLD code at the moment the mirror
    // runs — exactly what CLAUDE.md documents happened for
    // action.manage_calls at some point in this project's history.
    db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)",
        [$dispatcherRoleId, $oldFixtureId]
    );
    $preconditionHeld = db_fetch_one(
        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
        [$dispatcherRoleId, $oldFixtureId]
    );
    t('fixture setup: Dispatcher holds the throwaway OLD tier-1 code (the incident precondition)',
        (bool) $preconditionHeld);

    // -- OLD (pre-fix) mirror SQL: exactly what sql/run_rbac_v2.php's A8
    //    step did before this fix — blind, unconditional copy. --
    db_query(
        "INSERT INTO `{$prefix}permissions` (code, name, category, description)
         VALUES ('zz_test_admin_only_old.manage', 'ZZ admin_only Test (canonical, OLD mirror)', 'action', 'throwaway fixture')"
    );
    $canonFixtureIdOld = (int) db_insert_id();
    db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
         SELECT role_id, ? FROM `{$prefix}role_permissions` WHERE permission_id = ?",
        [$canonFixtureIdOld, $oldFixtureId]
    );
    $oldMirrorLeaked = db_fetch_one(
        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
        [$dispatcherRoleId, $canonFixtureIdOld]
    );
    t('THE BUG, reproduced: the OLD unguarded mirror SQL leaks the tier-1 code onto Dispatcher',
        (bool) $oldMirrorLeaked);

    // -- NEW (fixed) mirror SQL: exactly what sql/run_rbac_v2.php's A8
    //    step does now — the tier-aware guarded copy. --
    db_query(
        "INSERT INTO `{$prefix}permissions` (code, name, category, description, admin_only)
         VALUES ('zz_test_admin_only_new.manage', 'ZZ admin_only Test (canonical, NEW mirror)', 'action', 'throwaway fixture', 1)"
    );
    $canonFixtureIdNew = (int) db_insert_id();
    db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
         SELECT rp.role_id, ? FROM `{$prefix}role_permissions` rp
         JOIN `{$prefix}roles` rr ON rr.id = rp.role_id
         WHERE rp.permission_id = ?
           AND (CASE WHEN rr.is_super = 1 THEN 2
                     WHEN rr.id = 2 OR rr.name = 'Org Admin' THEN 1
                     ELSE 0 END) >= ?",
        [$canonFixtureIdNew, $oldFixtureId, 1]
    );
    $newMirrorLeaked = db_fetch_one(
        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
        [$dispatcherRoleId, $canonFixtureIdNew]
    );
    t('THE FIX, proven: the NEW guarded mirror SQL (as shipped in run_rbac_v2.php) does NOT leak it onto Dispatcher',
        !$newMirrorLeaked);

    // And Org Admin, whose tier (1) is sufficient, still gets it via the
    // same guarded query — the fix narrows correctly, it doesn't just
    // block everything.
    db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)",
        [$orgAdminRoleId, $oldFixtureId]
    );
    db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
         SELECT rp.role_id, ? FROM `{$prefix}role_permissions` rp
         JOIN `{$prefix}roles` rr ON rr.id = rp.role_id
         WHERE rp.permission_id = ?
           AND (CASE WHEN rr.is_super = 1 THEN 2
                     WHEN rr.id = 2 OR rr.name = 'Org Admin' THEN 1
                     ELSE 0 END) >= ?",
        [$canonFixtureIdNew, $oldFixtureId, 1]
    );
    $orgAdminGotIt = db_fetch_one(
        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
        [$orgAdminRoleId, $canonFixtureIdNew]
    );
    t('the guarded mirror still correctly grants a tier-1 code to Org Admin (tier 1) — the fix narrows, it does not block everything',
        (bool) $orgAdminGotIt);
    db_query("DELETE FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id IN (?, ?)",
        [$orgAdminRoleId, $oldFixtureId, $canonFixtureIdNew]);
} catch (Throwable $e) {
    t('Part 4 fixture setup/exec without error: ' . $e->getMessage(), false);
} finally {
    foreach ([$oldFixtureId, $canonFixtureIdOld, $canonFixtureIdNew] as $id) {
        if (!empty($id)) {
            try { db_query("DELETE FROM `{$prefix}role_permissions` WHERE permission_id = ?", [$id]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM `{$prefix}permissions` WHERE id = ?", [$id]); } catch (Throwable $e) {}
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — no role's actual, live effective permissions changed: checked
// as an INVARIANT (every role's tier vs. every admin_only>0 code it
// holds), not a byte-diff against a remembered snapshot — this dev
// database has other concurrent sessions actively creating/granting roles
// at any given moment (see CLAUDE.md's "concurrent sessions share one
// working tree" entries), so a snapshot-diff would be fragile; the
// invariant is not.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 5: no role holds an admin_only permission above its own tier ---\n\n";

$violations = db_fetch_all(
    "SELECT rp.role_id, r.name AS role_name, p.code, p.admin_only,
            (CASE WHEN r.is_super = 1 THEN 2
                  WHEN r.id = 2 OR r.name = 'Org Admin' THEN 1
                  ELSE 0 END) AS role_tier
       FROM `{$prefix}role_permissions` rp
       JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
       JOIN `{$prefix}roles` r ON r.id = rp.role_id
      WHERE p.admin_only > 0"
);
$bad = array_filter($violations, fn($r) => (int) $r['role_tier'] < (int) $r['admin_only']);
foreach ($bad as $b) {
    echo "  VIOLATION: role {$b['role_id']} ({$b['role_name']}, tier {$b['role_tier']}) holds "
       . "'{$b['code']}' (admin_only={$b['admin_only']})\n";
}
t('no role on this database holds an admin_only permission above its own tier', count($bad) === 0);

// Every real Super Admin, Org Admin and Dispatcher row (the six original
// roles) — assert against the KNOWN, intended tier membership directly,
// not just "no violation found" (a table that GRANTED NOTHING would also
// show zero violations, which is a different and equally wrong failure
// mode — Super Admin losing action.manage_config outright would not trip
// Part 5's violation scan at all).
foreach ($tier2 as $code) {
    $row = db_fetch_one("SELECT id FROM `{$prefix}permissions` WHERE code = ?", [$code]);
    if (!$row) continue;
    $held = db_fetch_one(
        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
        [$superAdminRoleId, (int) $row['id']]
    );
    t("Super Admin still actually holds tier-2 code '$code' (not just \"nobody else does\")", (bool) $held);
}
foreach ($tier1 as $code) {
    $row = db_fetch_one("SELECT id FROM `{$prefix}permissions` WHERE code = ?", [$code]);
    if (!$row) continue;
    $held = db_fetch_one(
        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
        [$orgAdminRoleId, (int) $row['id']]
    );
    t("Org Admin still actually holds tier-1 code '$code' (its own long-standing correct grant, unchanged by this fix)",
        (bool) $held);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 6 — structural check: the live grant-writing code paths actually
// CALL the guard. A future refactor of api/rbac.php or sql/run_rbac_v2.php
// could silently drop the wiring while leaving inc/rbac_admin_only.php
// itself untouched and every test above still green (they exercise the
// guard function directly, not the call sites) — this closes that gap.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 6: the guard is actually wired into every grant-writing call site ---\n\n";

$apiRbacSrc = file_get_contents(__DIR__ . '/../api/rbac.php');
t('api/rbac.php requires inc/rbac_admin_only.php',
    strpos($apiRbacSrc, "rbac_admin_only.php") !== false);
t('api/rbac.php\'s set_permissions path calls rbac_grant_permission_allowed()',
    (bool) preg_match('/set_permissions.*?rbac_grant_permission_allowed/s', $apiRbacSrc));
t('api/rbac.php\'s set_role_permission (toggle) path calls rbac_grant_permission_allowed()',
    (bool) preg_match('/set_role_permission.*?rbac_grant_permission_allowed/s', $apiRbacSrc));

$rbacV2Src = file_get_contents(__DIR__ . '/../sql/run_rbac_v2.php');
t('sql/run_rbac_v2.php requires inc/rbac_admin_only.php',
    strpos($rbacV2Src, "rbac_admin_only.php") !== false);
t('sql/run_rbac_v2.php\'s A8 mirror step filters by role tier vs. admin_only',
    strpos($rbacV2Src, "WHEN rr.is_super = 1 THEN 2") !== false
    && strpos($rbacV2Src, "role_permissions` (role_id, permission_id)") !== false);

$rbacSqlSrc = file_get_contents(__DIR__ . '/../sql/rbac.sql');
$run00Src = file_get_contents(__DIR__ . '/../sql/run_00_rbac.php');
t('sql/rbac.sql\'s Org Admin broad grant filters by admin_only',
    (bool) preg_match('/SELECT 2, `id` FROM `permissions`.*?admin_only.*?<=\s*1/s', $rbacSqlSrc));
t('sql/rbac.sql\'s Dispatcher broad grant filters by admin_only',
    (bool) preg_match('/SELECT 3, `id` FROM `permissions`.*?admin_only.*?=\s*0/s', $rbacSqlSrc));
t('sql/run_00_rbac.php\'s Org Admin broad grant filters by admin_only',
    (bool) preg_match('/SELECT 2, `id` FROM `\{\$prefix\}permissions`.*?admin_only.*?<=\s*1/s', $run00Src));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
