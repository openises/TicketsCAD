<?php
/**
 * Phase 143 (2026-08-17) — Admin UI: api/org-relationships.php +
 * org-relationships-admin.php.
 *
 * This project's established precedent for admin-CRUD-UI testing (see
 * tests/test_org_sharing_admin_api.php's own docblock) is to drive the pure
 * decision functions directly rather than a full HTTP round trip — this
 * codebase has no session-login HTTP test harness anywhere, and
 * api/org-relationships.php itself requires auth.php (session + RBAC
 * fail-closed at the API edge, `json_error('Not authenticated', 401)` on a
 * bare CLI run). Unlike Phase 141's org-routing admin API, this endpoint
 * needed no separate resolver/formatter extraction: every decision
 * (two-party consent, per-row authorization, activation eligibility) is
 * already a pure, testable function in inc/org-relationships.php, called
 * here with $canActGlobal computed the SAME way the endpoint computes it —
 * rbac_can('action.manage_org_relationships'), live, against real
 * hand-granted role fixtures, never a hardcoded boolean.
 *
 * Covers:
 *   1. Structural: api/org-relationships.php and org-relationships-admin.php
 *      exist, gate on all three permission codes, and NEVER OR is_admin()
 *      into any gate (tokenizer-based, matching both prior phases'
 *      technique — "tokenize, do not grep": both files' docblocks
 *      legitimately DISCUSS the no-`|| is_admin()` rule in prose).
 *   2. Structural: api/org-relationships.php's reject_member action never
 *      reads a client-asserted confirmation field (the admin UI's
 *      named-confirmation typed-org-name step is a UX guard only, per
 *      plan.md's Admin UI section — this endpoint must not accept any such
 *      field as an authority).
 *   3. LIVE, end-to-end: a hand-granted role holding ONLY
 *      action.activate_org_relationship (the Dispatcher shape) genuinely
 *      activates a relationship its own org is an approved member of, via
 *      rbac_can()-derived flags exactly as the endpoint would compute them
 *      — and is genuinely REFUSED when its org is NOT an approved member
 *      (never silently bypassed by holding the narrower code alone).
 *   4. LIVE, end-to-end: the activation control actually works against a
 *      live fixture — activate with a short window, confirm visibility,
 *      backdate to simulate elapsed time with NO cleanup job invoked,
 *      confirm visibility is gone (the admin-API-facing companion to Task
 *      5's own dedicated file, distinguished by driving rbac_can()-derived
 *      flags rather than hardcoded booleans).
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_admin_api.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-relationships.php';
require_once __DIR__ . '/../inc/rbac.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Admin UI: api/org-relationships.php + org-relationships-admin.php ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

if (!org_relationships_schema_ready()) {
    echo "SKIP: org_relationships not present -- run sql/run_phase143_cross_org_standing_relationships.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}
t("org_relationships_schema_ready() is true against the live migrated database", org_relationships_schema_ready());

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — structural: files exist, gate on the right codes, never
// `|| is_admin()`.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Structural: RBAC gating, no is_admin() leak ---\n\n";

$adminApiSrc  = @file_get_contents($base . '/api/org-relationships.php');
$adminPageSrc = @file_get_contents($base . '/org-relationships-admin.php');

t("api/org-relationships.php exists", $adminApiSrc !== false);
t("org-relationships-admin.php exists", $adminPageSrc !== false);

/**
 * Strips comments/docstrings via the real PHP tokenizer before a substring
 * check for is_admin( — both admin files' docblocks legitimately DISCUSS
 * "never `rbac_can() || is_admin()`" in prose, and a plain grep/substring
 * scan cannot tell that explanation from an actual call (this project's
 * own documented "tokenize, do not grep" lesson, CLAUDE.md's proc_open
 * pipe deadlock entry).
 */
function _p143admin_code_only(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

if ($adminApiSrc !== false) {
    $adminApiCode = _p143admin_code_only($adminApiSrc);
    t("api/org-relationships.php: gates on action.manage_org_relationships", strpos($adminApiSrc, "rbac_can('action.manage_org_relationships')") !== false);
    t("api/org-relationships.php: gates on action.manage_org_relationships_org", strpos($adminApiSrc, "rbac_can('action.manage_org_relationships_org')") !== false);
    t("api/org-relationships.php: gates on action.activate_org_relationship", strpos($adminApiSrc, "rbac_can('action.activate_org_relationship')") !== false);
    t("api/org-relationships.php: no is_admin() call in actual CODE (comments excluded via tokenizer)", strpos($adminApiCode, 'is_admin(') === false);
}
if ($adminPageSrc !== false) {
    $adminPageCode = _p143admin_code_only($adminPageSrc);
    t("org-relationships-admin.php: gates on action.manage_org_relationships", strpos($adminPageSrc, "rbac_can('action.manage_org_relationships')") !== false);
    t("org-relationships-admin.php: gates on action.manage_org_relationships_org", strpos($adminPageSrc, "rbac_can('action.manage_org_relationships_org')") !== false);
    t("org-relationships-admin.php: gates on action.activate_org_relationship", strpos($adminPageSrc, "rbac_can('action.activate_org_relationship')") !== false);
    t("org-relationships-admin.php: no is_admin() call in actual CODE (comments excluded via tokenizer)", strpos($adminPageCode, 'is_admin(') === false);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — structural: reject_member never reads a client-asserted
// confirmation field as an authority. The admin UI's named-confirmation
// (type the org's name) is a client-side UX guard ONLY.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Structural: no client-asserted confirmation bypass on reject_member ---\n\n";

if ($adminApiSrc !== false) {
    $adminApiCode = _p143admin_code_only($adminApiSrc);
    // Isolate the reject_member branch body between its own `if` and the
    // next `if ($action === ...)` so this check doesn't accidentally match
    // unrelated code elsewhere in the file.
    $rejectStart = strpos($adminApiCode, "\$action === 'reject_member'");
    t("api/org-relationships.php: has a reject_member action branch", $rejectStart !== false);
    if ($rejectStart !== false) {
        $nextAction = strpos($adminApiCode, "\$action === '", $rejectStart + 20);
        $rejectBody = $nextAction !== false
            ? substr($adminApiCode, $rejectStart, $nextAction - $rejectStart)
            : substr($adminApiCode, $rejectStart);
        t("reject_member branch never reads \$input['confirmed']", strpos($rejectBody, "'confirmed'") === false);
        t("reject_member branch never reads \$input['confirm_name']", strpos($rejectBody, "'confirm_name'") === false);
        t("reject_member branch's only authorization is inside org_relationship_member_reject() itself (no local org_relationship_can_act_for_org() re-implementation to accidentally get wrong)", strpos($rejectBody, 'org_relationship_can_act_for_org') === false);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Fixture orgs/users for Parts 3-4 (live proofs)
// ═══════════════════════════════════════════════════════════════════════

$ownOrgId    = 900005400;
$otherOrgId  = 900005401;
$thirdOrgId  = 900005402; // an org the activate-only user has NO connection to
$createdOrgIds = [$ownOrgId, $otherOrgId, $thirdOrgId];

$testRoleActivateOnlyName = 'ZZ143AdminApi ActivateOnly Test Role';
$testRoleId = 0;
$permActivateId = 0;

$uidActivateOnly = 900005410; // holds ONLY action.activate_org_relationship, scoped to $ownOrgId
$uidActivateOnlyOther = 900005411; // same role, scoped to $thirdOrgId (unrelated to the relationship)
$ownerUserId = 900005412; // global-caller fixture owner (creates the relationship)

$createdRelIds = [];

$fallbackUserForOrgId = 900005420; // bound ad hoc in _p143admin_pick_user_for_org()

$cleanup = function () use (
    $prefix, &$createdOrgIds, &$createdRelIds, $testRoleActivateOnlyName,
    $uidActivateOnly, $uidActivateOnlyOther, $ownerUserId, $fallbackUserForOrgId
) {
    foreach ($createdRelIds as $id) {
        try { db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?, ?)", [$uidActivateOnly, $uidActivateOnlyOther, $ownerUserId, $fallbackUserForOrgId]); } catch (Throwable $e) {}
    try {
        $rid = db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = ?", [$testRoleActivateOnlyName]);
        if ($rid) {
            db_query("DELETE FROM {$prefix}role_permissions WHERE role_id = ?", [$rid]);
            db_query("DELETE FROM {$prefix}roles WHERE id = ?", [$rid]);
        }
    } catch (Throwable $e) {}
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
};
$cleanup();

try {
    foreach ($createdOrgIds as $id) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, 'ZZ143AdminApi Org ' . $id]);
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 1, NULL, 'global', NULL)", [$ownerUserId]);

    $permActivateId = (int) (db_fetch_value("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.activate_org_relationship']) ?: 0);
    t("action.activate_org_relationship permission row exists", $permActivateId > 0);

    if ($permActivateId > 0) {
        db_query("INSERT INTO {$prefix}roles (name, description) VALUES (?, 'throwaway Phase 143 admin-api test fixture -- activate-only')", [$testRoleActivateOnlyName]);
        $testRoleId = (int) db_insert_id();
        db_query("INSERT INTO {$prefix}role_permissions (role_id, permission_id) VALUES (?, ?)", [$testRoleId, $permActivateId]);

        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, ?, 'org', ?)", [$uidActivateOnly, $testRoleId, $ownOrgId, $ownOrgId]);
        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, ?, 'org', ?)", [$uidActivateOnlyOther, $testRoleId, $thirdOrgId, $thirdOrgId]);
    }

    // ══════════════════════════════════════════════════════════════════
    // Fixture: a real, approved, active, requires_activation=1 relationship
    // between $ownOrgId and $otherOrgId, created via the real writer with a
    // GLOBAL caller (this test's job is the admin-API's RBAC/activation
    // wiring, not two-party consent itself -- that's test_org_relationships_consent.php's job).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Fixture: relationship between ownOrgId and otherOrgId ---\n\n";

    $create = org_relationship_create_or_propose(
        [
            'name' => 'ZZ143AdminApi Relationship',
            'member_org_ids' => [$ownOrgId, $otherOrgId],
            'access_tier' => 'view',
            'redaction_profile' => 'view',
            'requires_activation' => 1,
            'max_activation_minutes' => 60,
        ],
        true, $ownerUserId, 'ZZ143AdminApi Owner'
    );
    t('fixture relationship created', $create['success'] === true);
    $relId = (int) ($create['id'] ?? 0);
    if ($relId > 0) $createdRelIds[] = $relId;
    t("fixture relationship status is 'active'", $create['status'] === 'active');

    // ══════════════════════════════════════════════════════════════════
    // Part 3 — LIVE: rbac_can()-derived flags, exactly as the endpoint
    // computes them, for a Dispatcher-shaped (activate-only) caller.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 3: LIVE rbac_can()-derived activation gating (Dispatcher-shaped caller) ---\n\n";

    if ($testRoleId > 0) {
        $oldSessionUser = $_SESSION['user_id'] ?? null;
        $oldActiveOrg   = $_SESSION['active_org_id'] ?? null;

        // The caller whose own org (ownOrgId) IS an approved member. An
        // org-scoped grant's scope is checked against $_SESSION['active_org_id']
        // when no explicit ['org_id'=>...] context is passed to rbac_can() --
        // exactly the bare-call shape api/org-relationships.php itself uses
        // for its own reachability gate ($canActivateCode = rbac_can('action.activate_org_relationship')).
        // active_org_id is set at LOGIN for every real session (CLAUDE.md's
        // own documented convention); a CLI fixture has to set it explicitly
        // to reproduce what a real logged-in Dispatcher session looks like.
        $_SESSION['user_id'] = $uidActivateOnly;
        $_SESSION['active_org_id'] = $ownOrgId;
        rbac_clear_cache();

        $canActGlobalLive = rbac_can('action.manage_org_relationships');
        $canActivateLive  = rbac_can('action.activate_org_relationship');
        t('LIVE: activate-only user does NOT hold action.manage_org_relationships', $canActGlobalLive === false);
        t('LIVE: activate-only user DOES hold action.activate_org_relationship (bare rbac_can() call, active_org_id set to their own org, matching a real session)', $canActivateLive === true);
        t('LIVE: the SAME bare rbac_can() call WITHOUT active_org_id set resolves to false (proves the reachability gate genuinely depends on session context, not a wrapper shortcut)', (function () {
            $saved = $_SESSION['active_org_id'] ?? null;
            unset($_SESSION['active_org_id']);
            rbac_clear_cache();
            $result = rbac_can('action.activate_org_relationship');
            $_SESSION['active_org_id'] = $saved;
            rbac_clear_cache();
            return $result === false;
        })());

        // Exactly the call api/org-relationships.php's action=activate makes,
        // with $canActGlobal computed the SAME way the endpoint computes it
        // -- a precise, unOR'd flag, never widened by the narrower code.
        $activateResult = org_relationship_activate($relId, $canActGlobalLive, $uidActivateOnly, 'ZZ Activate-Only User', 'admin-api live drill', 5);
        t('LIVE: activate-only user, whose OWN org is an approved member, CAN activate', $activateResult['success'] === true);
        $liveActivationId = (int) ($activateResult['id'] ?? 0);

        $deactivateResult = org_relationship_deactivate($relId, $canActGlobalLive, $uidActivateOnly, 'ZZ Activate-Only User', 'admin-api live drill cleanup', false);
        t('LIVE: the same user can deactivate their own activation', $deactivateResult['success'] === true);

        // The MIRROR case: a user holding the identical role/permission but
        // scoped to an UNRELATED org (thirdOrgId, not a member of this
        // relationship at all) must be REFUSED -- proving
        // action.activate_org_relationship alone is never a bypass of the
        // per-relationship membership gate.
        $_SESSION['user_id'] = $uidActivateOnlyOther;
        $_SESSION['active_org_id'] = $thirdOrgId;
        rbac_clear_cache();
        $canActGlobalOtherLive = rbac_can('action.manage_org_relationships');
        $canActivateOtherLive  = rbac_can('action.activate_org_relationship');
        t('LIVE: the unrelated-org activate-only user also does NOT hold action.manage_org_relationships', $canActGlobalOtherLive === false);
        t('LIVE: the unrelated-org activate-only user DOES hold the bare code (their own org, thirdOrgId, satisfies the reachability gate -- the refusal below must come from the per-relationship membership check, not the reachability gate)', $canActivateOtherLive === true);

        $refused = org_relationship_activate($relId, $canActGlobalOtherLive, $uidActivateOnlyOther, 'ZZ Unrelated Activate-Only User', 'should be refused', 5);
        t('LIVE: activate-only user whose org is NOT a member of this relationship is REFUSED (never bypassed by holding the narrower code alone)', $refused['success'] === false);

        if ($oldSessionUser !== null) { $_SESSION['user_id'] = $oldSessionUser; } else { unset($_SESSION['user_id']); }
        if ($oldActiveOrg !== null) { $_SESSION['active_org_id'] = $oldActiveOrg; } else { unset($_SESSION['active_org_id']); }
        rbac_clear_cache();
    } else {
        echo "SKIP: could not create the activate-only test role fixture.\n";
    }

    // ══════════════════════════════════════════════════════════════════
    // Part 4 — LIVE: the activation control actually working end-to-end
    // against a live fixture -- short window, visibility before, backdate
    // to simulate elapsed time, NO cleanup job invoked, visibility gone.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 4: LIVE activation control, short window, no cleanup job invoked ---\n\n";

    require_once __DIR__ . '/../inc/incident-write.php';
    $_SESSION['user_id'] = $ownerUserId;
    $_SESSION['active_org_id'] = $ownOrgId;
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES ('ZZ143AdminApi Type', 'zz143adminapi fixture type', NULL)");
    $inTypeId = (int) db_insert_id();
    $ticketResult = incident_create_internal(['in_types_id' => $inTypeId, 'scope' => 'zz143adminapi fixture ticket'], $ownerUserId);
    unset($_SESSION['active_org_id']);
    $ticketId = (int) ($ticketResult['id'] ?? 0);
    t('fixture ticket created under ownOrgId via the real writer', $ticketId > 0);

    // Activate with a short window via the SAME rbac_can()-derived flag
    // shape as Part 3, this time as a fresh live_key slot (Part 3's
    // activation was already deactivated above).
    $_SESSION['user_id'] = $ownerUserId;
    rbac_clear_cache();
    $canActGlobalForOwner = rbac_can('action.manage_org_relationships');
    $activate2 = org_relationship_activate($relId, $canActGlobalForOwner, $ownerUserId, 'ZZ143AdminApi Owner', 'admin-api control-loop drill', 1);
    t('activation for the control-loop proof succeeds', $activate2['success'] === true);
    $activationId2 = (int) ($activate2['id'] ?? 0);

    t('BEFORE expiry: otherOrgId can see ownOrgId\'s ticket via the activated relationship', org_can_see_ticket($ticketId, _p143admin_pick_user_for_org($otherOrgId)));

    db_query("UPDATE {$prefix}org_relationships_activations SET activated_at = NOW() - INTERVAL 2 MINUTE WHERE id = ?", [$activationId2]);
    $stillNull = db_fetch_value("SELECT deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId2]);
    t('deactivated_at is still NULL after backdating (no write has closed this row)', $stillNull === null);

    // Deliberately: NO call to tools/org_relationship_cleanup_tick.php, NO
    // call to org_relationship_deactivate(), NOTHING that writes
    // deactivated_at, anywhere below this line for activationId2.
    t('AFTER simulated elapsed time, cleanup job NEVER invoked: otherOrgId can NO LONGER see the ticket', !org_can_see_ticket($ticketId, _p143admin_pick_user_for_org($otherOrgId)));

    $stillNullAfter = db_fetch_value("SELECT deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId2]);
    t('deactivated_at is STILL NULL after the post-expiry assertion (access was already gone via the read-time predicate, not a write)', $stillNullAfter === null);

    // Cleanup this activation explicitly so it doesn't linger.
    org_relationship_deactivate($relId, true, $ownerUserId, 'ZZ143AdminApi Owner', 'test cleanup', false);
    try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}in_types WHERE type = 'ZZ143AdminApi Type'"); } catch (Throwable $e) {}

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
    rbac_clear_cache();
}

/**
 * A user id we can pass to org_can_see_ticket() representing "someone at
 * this org" -- reuses the activate-only fixture user when its org matches,
 * otherwise falls back to a throwaway user_roles row bound to the target
 * org for the duration of this single check. Kept local to this file; not
 * part of the public API.
 */
function _p143admin_pick_user_for_org(int $orgId): int {
    global $prefix, $uidActivateOnly, $ownOrgId;
    static $fallbackUserId = 900005420;
    static $bound = false;
    if ($orgId === $ownOrgId) return $uidActivateOnly; // bound to ownOrgId above -- Part 3's fixture, still present until cleanup()
    if (!$bound) {
        try {
            db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$fallbackUserId]);
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$fallbackUserId, $orgId, $orgId]);
        } catch (Throwable $e) {}
        $bound = true;
    }
    return $fallbackUserId;
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
