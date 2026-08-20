<?php
/**
 * Phase 141 (2026-08-17) — Admin UI: api/org-routing.php's business logic.
 *
 * This project's established precedent for admin-CRUD-UI testing (see
 * tests/test_public_board_rbac.php's own docblock) is to drive the pure
 * decision functions directly rather than a full HTTP round trip — this
 * codebase has no session-login HTTP test harness anywhere. api/org-routing.php
 * itself requires auth.php (session + RBAC fail-closed at the API edge), so
 * its resolver/formatter logic was deliberately extracted into
 * inc/org-sharing.php (org_routing_resolve_caller_org_id(),
 * org_routing_can_author_org(), org_routing_resolve_create_owning_org(),
 * org_routing_row_out(), org_routing_schema_ready()) specifically so it can
 * be driven directly here, the same way pb_resolve_admin_write_org() /
 * pb_resolve_caller_org_id() (Phase 138) and
 * ics_form_types_resolve_caller_org_id() / ics_form_types_resolve_create_org()
 * (Phase 140) already are.
 *
 * Covers:
 *   1. org_routing_resolve_create_owning_org() — the critical covering case
 *      (an org-scoped-only caller can never write another org's rule),
 *      mirroring pb_resolve_admin_write_org()'s test shape exactly.
 *   2. org_routing_can_author_org() — pure-input cases, plus a LIVE
 *      hand-granted-role case proving the rbac_can(..., ['org_id'=>...])
 *      call inside it actually honours a real scope_kind='org' grant (not
 *      just its own if/else branching).
 *   3. org_routing_resolve_caller_org_id() against REAL user_roles/
 *      role_permissions rows (mirrors test_public_board_rbac.php Part 3):
 *      no grant -> 0; single org-scoped grant -> that org; two distinct
 *      org-scoped grants (ambiguous) -> 0; a GLOBAL-scoped grant of the
 *      SAME permission -> 0 (this resolver only ever reads scope_kind='org'
 *      rows by design — a global-scoped org-routing_org grant is a
 *      configuration oddity this function deliberately treats as
 *      "no self-service org," not silently as org #1 or similar).
 *   4. org_routing_row_out() — match_description formatting (group vs.
 *      type, including the unresolved-type-name fallback), org-name
 *      fallback, boolean cast of `active`.
 *   5. Full CRUD immutability, extended beyond test_org_sharing_audit.php's
 *      owning_org_id-only case to EVERY identity field: shared_with_org_id,
 *      match_scope, match_group, match_in_type_id.
 *   6. org_routing_schema_ready() against the live (migrated) database.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_admin_api.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/rbac.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Admin UI: api/org-routing.php business logic ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

if (!org_routing_schema_ready()) {
    echo "SKIP: org_type_routing not present -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}
t("org_routing_schema_ready() is true against the live migrated database", org_routing_schema_ready());

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — org_routing_resolve_create_owning_org() (pure)
// ═══════════════════════════════════════════════════════════════════════
echo "--- org_routing_resolve_create_owning_org() ---\n\n";

$d = org_routing_resolve_create_owning_org(true, /* callerOrgId */ 7, /* requested */ 42);
t('global author: writes the REQUESTED owning org (any org)', $d['ok'] === true && $d['org_id'] === 42);

$d = org_routing_resolve_create_owning_org(true, 7, null);
t('global author: no owning_org_id in request -> rejected 400 (never silently applied)', $d['ok'] === false && $d['status'] === 400);

$d = org_routing_resolve_create_owning_org(false, /* callerOrgId */ 11, /* requested */ 11);
t('org-scoped author: writing own org id -> allowed, forced from the resolved caller org', $d['ok'] === true && $d['org_id'] === 11);

$d = org_routing_resolve_create_owning_org(false, 11, null);
t('org-scoped author: no owning_org_id sent at all -> allowed, defaults to own org', $d['ok'] === true && $d['org_id'] === 11);

// THE critical case: an org-scoped-only caller attempting to name a
// DIFFERENT org's id than the one their own grant is scoped to.
$d = org_routing_resolve_create_owning_org(false, /* callerOrgId (own) */ 11, /* requested (a DIFFERENT org) */ 999);
t('org-scoped author: DIFFERENT owning org id in request -> REJECTED, never applied', $d['ok'] === false);
t('org-scoped author: DIFFERENT owning org id -> 403, not silently ignored', $d['status'] === 403);
t('org-scoped author: DIFFERENT owning org id -> org_id in the decision is null, never 999', $d['org_id'] === null);

$d = org_routing_resolve_create_owning_org(false, /* callerOrgId */ 0, null);
t('org-scoped author: no organization scoped to the account -> rejected 403', $d['ok'] === false && $d['status'] === 403);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — org_routing_can_author_org() — pure-input cases
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- org_routing_can_author_org() -- pure-input cases ---\n\n";

t('global author: authorized over ANY owning org id', org_routing_can_author_org(true, 555));
t('global author: authorized even when owningOrgId is 0/unset', org_routing_can_author_org(true, 0));
t('org-scoped-only, owningOrgId <= 0: never authorized (no enumeration signal to chase)', !org_routing_can_author_org(false, 0));

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — org_routing_resolve_caller_org_id() / org_routing_can_author_org()
// against REAL user_roles + role_permissions rows (mirrors
// test_public_board_rbac.php Part 3's technique). Also satisfies tasks.md
// section 8's "confirm a Super-Admin-hand-granted
// action.manage_org_routing_org on a specific test role actually works
// end to end" requirement for the FUNCTION this admin UI actually calls.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- org_routing_resolve_caller_org_id() / org_routing_can_author_org() against real grants ---\n\n";

$permOrgId = (int) (db_fetch_value(
    "SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_routing_org']
) ?: 0);

if ($permOrgId <= 0) {
    echo "SKIP: action.manage_org_routing_org permission row missing -- run sql/run_00_rbac.php first.\n";
} else {
    // A dedicated throwaway ROLE (never an existing production role like
    // Org Admin/Dispatcher) so this fixture cannot perturb any OTHER
    // test's assumptions about a real role's permission set.
    $testRoleName = 'ZZ141 Admin API Test Role';
    db_query("DELETE FROM {$prefix}roles WHERE name = ?", [$testRoleName]);
    db_query("INSERT INTO {$prefix}roles (name, description) VALUES (?, 'throwaway Phase 141 admin-api test fixture')", [$testRoleName]);
    $testRoleId = (int) db_insert_id();
    db_query(
        "INSERT INTO {$prefix}role_permissions (role_id, permission_id) VALUES (?, ?)",
        [$testRoleId, $permOrgId]
    );

    $ownOrgId   = 900002560;
    $otherOrgId = 900002561;
    $createdOrgIds = [$ownOrgId, $otherOrgId];
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ141 AdminApi OwnOrg', 1)", [$ownOrgId]);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ141 AdminApi OtherOrg', 1)", [$otherOrgId]);

    $uidNone    = 900002562; // no grant at all
    $uidSingle  = 900002563; // one org-scoped grant -> $ownOrgId
    $uidTwoOrgs = 900002564; // two distinct org-scoped grants -> ambiguous
    $uidGlobal  = 900002565; // a GLOBAL-scoped grant of the SAME permission

    $insertedRoleIds = [];
    $cleanupFixture = function () use (
        $prefix, $testRoleId, $testRoleName, $permOrgId, &$createdOrgIds,
        $uidNone, $uidSingle, $uidTwoOrgs, $uidGlobal
    ) {
        try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?, ?)", [$uidNone, $uidSingle, $uidTwoOrgs, $uidGlobal]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}role_permissions WHERE role_id = ?", [$testRoleId]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}roles WHERE id = ?", [$testRoleId]); } catch (Throwable $e) {}
        foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    };

    try {
        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, ?, 'org', ?)", [$uidSingle, $testRoleId, $ownOrgId, $ownOrgId]);
        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, ?, 'org', ?)", [$uidTwoOrgs, $testRoleId, $ownOrgId, $ownOrgId]);
        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, ?, 'org', ?)", [$uidTwoOrgs, $testRoleId, $otherOrgId, $otherOrgId]);
        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, NULL, 'global', NULL)", [$uidGlobal, $testRoleId]);

        t('user with NO grant at all -> org_routing_resolve_caller_org_id() resolves to 0',
            org_routing_resolve_caller_org_id($uidNone) === 0);
        t('user with a SINGLE org-scoped grant -> resolves to that exact org, not a guess',
            org_routing_resolve_caller_org_id($uidSingle) === $ownOrgId);
        t('user with TWO DISTINCT org-scoped grants -> resolves to 0 (ambiguous), never the first/lowest',
            org_routing_resolve_caller_org_id($uidTwoOrgs) === 0);
        t('user with a GLOBAL-scoped grant of the SAME permission -> resolves to 0 (this resolver only reads org-scoped rows by design)',
            org_routing_resolve_caller_org_id($uidGlobal) === 0);
        t('userId <= 0 -> resolves to 0 without querying', org_routing_resolve_caller_org_id(0) === 0);

        // ── The live end-to-end proof that a Super-Admin-hand-granted
        // action.manage_org_routing_org on a specific test role actually
        // authorizes the RIGHT org and refuses every other one — driving
        // rbac_can() for real via org_routing_can_author_org(), not just
        // its own if/else branching (Part 2 above already proved that half).
        $oldSessionUser = $_SESSION['user_id'] ?? null;
        $_SESSION['user_id'] = $uidSingle;
        rbac_clear_cache();

        t('LIVE: hand-granted org-scoped caller CAN author their own resolved org (org_routing_can_author_org, canAuthorGlobal=false)',
            org_routing_can_author_org(false, $ownOrgId));
        t('LIVE: hand-granted org-scoped caller CANNOT author a different org — rbac_can() scope check genuinely enforced, not just the wrapper\'s own branching',
            !org_routing_can_author_org(false, $otherOrgId));

        $_SESSION['user_id'] = $uidNone;
        rbac_clear_cache();
        t('LIVE: a user with NO grant at all cannot author any org',
            !org_routing_can_author_org(false, $ownOrgId));

        if ($oldSessionUser !== null) { $_SESSION['user_id'] = $oldSessionUser; } else { unset($_SESSION['user_id']); }
        rbac_clear_cache();
    } finally {
        $cleanupFixture();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — org_routing_row_out() formatting
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- org_routing_row_out() ---\n\n";

$groupRow = [
    'id' => 1, 'owning_org_id' => 10, 'owning_org_name' => 'Owner Org',
    'shared_with_org_id' => 20, 'shared_with_org_name' => 'Target Org',
    'match_scope' => 'group', 'match_group' => 'Fire', 'match_in_type_id' => null, 'match_type_name' => null,
    'access_tier' => 'view', 'active' => 1, 'created_by_name' => 'zztest', 'created_at' => '2026-08-17 00:00:00',
    'updated_at' => '2026-08-17 00:00:00', 'deactivated_at' => null,
];
$out = org_routing_row_out($groupRow);
t("group rule: match_description reads \"'Fire' incidents (group)\"", $out['match_description'] === "'Fire' incidents (group)");
t('group rule: active cast to real boolean true', $out['active'] === true);

$typeRowResolved = $groupRow;
$typeRowResolved['match_scope'] = 'type'; $typeRowResolved['match_group'] = null;
$typeRowResolved['match_in_type_id'] = 5; $typeRowResolved['match_type_name'] = 'Structure Fire';
$out = org_routing_row_out($typeRowResolved);
t('type rule (name resolved): match_description reads "Structure Fire (specific type)"', $out['match_description'] === 'Structure Fire (specific type)');

$typeRowUnresolved = $typeRowResolved;
$typeRowUnresolved['match_type_name'] = null; // the in_types row was deleted/renamed since the rule was created
$out = org_routing_row_out($typeRowUnresolved);
t('type rule (name UNRESOLVED — never silently blank): falls back to "Incident type #5 (specific type)"', $out['match_description'] === 'Incident type #5 (specific type)');

$noNameRow = $groupRow;
$noNameRow['owning_org_name'] = null; $noNameRow['shared_with_org_name'] = null; $noNameRow['active'] = 0;
$out = org_routing_row_out($noNameRow);
t('owning org with no resolved name falls back to "Org #10"', $out['owning_org_name'] === 'Org #10');
t('target org with no resolved name falls back to "Org #20"', $out['shared_with_org_name'] === 'Org #20');
t('inactive rule: active cast to real boolean false', $out['active'] === false);

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — CRUD immutability, EVERY identity field (owning_org_id was
// already covered in test_org_sharing_audit.php; this extends to the rest)
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- org_routing_rule_update() immutability -- every identity field ---\n\n";

$superUserId = 900002566;
$ownerOrgId2  = 900002567;
$targetOrgId2 = 900002568;
$otherOrgId2  = 900002569;
$createdOrgIds2 = [$ownerOrgId2, $targetOrgId2, $otherOrgId2];
$createdRuleIds2 = [];
$createdTypeIds2 = [];

$cleanup2 = function () use ($prefix, &$createdOrgIds2, &$createdRuleIds2, &$createdTypeIds2, $superUserId) {
    foreach ($createdRuleIds2 as $id) { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds2 as $id) { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds2 as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$superUserId]); } catch (Throwable $e) {}
};
$cleanup2();

try {
    foreach ($createdOrgIds2 as $id) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, 'ZZ141 AdminApi Immut ' . $id]);
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 1, NULL, 'global', NULL)", [$superUserId]);

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 admin-api immut type A', 'ZZ141AdminApiGroupA')", ['zz141aa-a-' . uniqid()]);
    $typeAId = (int) db_insert_id(); $createdTypeIds2[] = $typeAId;
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 admin-api immut type B', 'ZZ141AdminApiGroupB')", ['zz141aa-b-' . uniqid()]);
    $typeBId = (int) db_insert_id(); $createdTypeIds2[] = $typeBId;

    $_SESSION['user_id'] = $superUserId;
    rbac_clear_cache();

    $createResult = org_routing_rule_create([
        'owning_org_id' => $ownerOrgId2, 'shared_with_org_id' => $targetOrgId2,
        'match_scope' => 'group', 'match_group' => 'ZZ141AdminApiGroupA', 'access_tier' => 'view',
    ], $superUserId, 'zz141-admin-api-super');
    t('fixture rule created via the real writer', $createResult['success'] === true);
    $ruleId = (int) ($createResult['id'] ?? 0);
    if ($ruleId > 0) $createdRuleIds2[] = $ruleId;

    $attemptSharedWith = org_routing_rule_update($ruleId, ['shared_with_org_id' => $otherOrgId2, 'access_tier' => 'view'], $superUserId);
    t('changing shared_with_org_id on update is REJECTED', $attemptSharedWith['success'] === false);

    $attemptScope = org_routing_rule_update($ruleId, ['match_scope' => 'type', 'match_in_type_id' => $typeAId, 'access_tier' => 'view'], $superUserId);
    t('changing match_scope (group -> type) on update is REJECTED', $attemptScope['success'] === false);

    $attemptGroup = org_routing_rule_update($ruleId, ['match_group' => 'ZZ141AdminApiGroupB', 'access_tier' => 'view'], $superUserId);
    t('changing match_group on update is REJECTED', $attemptGroup['success'] === false);

    // A second, TYPE-scoped rule to prove match_in_type_id immutability
    // (the group-scoped rule above has match_in_type_id = NULL and can't
    // exercise this branch).
    $createTypeResult = org_routing_rule_create([
        'owning_org_id' => $ownerOrgId2, 'shared_with_org_id' => $otherOrgId2,
        'match_scope' => 'type', 'match_in_type_id' => $typeAId, 'access_tier' => 'view',
    ], $superUserId, 'zz141-admin-api-super');
    t('fixture TYPE-scoped rule created', $createTypeResult['success'] === true);
    $typeRuleId = (int) ($createTypeResult['id'] ?? 0);
    if ($typeRuleId > 0) $createdRuleIds2[] = $typeRuleId;

    $attemptTypeId = org_routing_rule_update($typeRuleId, ['match_in_type_id' => $typeBId, 'access_tier' => 'view'], $superUserId);
    t('changing match_in_type_id on update is REJECTED', $attemptTypeId['success'] === false);

    // The ONE field that IS mutable: access_tier alone, on either rule.
    $legitTierChange = org_routing_rule_update($ruleId, ['access_tier' => 'assist'], $superUserId);
    t('a tier-ONLY update (no identity fields touched) succeeds', $legitTierChange['success'] === true);
    $reloaded = db_fetch_one("SELECT `access_tier` FROM {$prefix}org_type_routing WHERE id = ?", [$ruleId]);
    t('the tier actually changed in the database', $reloaded && $reloaded['access_tier'] === 'assist');
} finally {
    $cleanup2();
    unset($_SESSION['user_id']);
    rbac_clear_cache();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
