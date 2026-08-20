<?php
/**
 * Phase 143 (2026-08-17) — Cross-org STANDING relationships: two-party
 * consent state machine.
 *
 * Drives inc/org-relationships.php's real writers, not hand-seeded rows:
 *   (a) Super-Admin creation auto-approves all named members immediately.
 *   (b) org-scoped creation auto-approves only the caller's own org, leaves
 *       the named counterpart 'pending'.
 *   (c) the counterpart's OWN authorized approver (a distinct test user
 *       whose org_visible_ids() contains only the counterpart org) can
 *       approve their own row, and the ORIGINAL proposer CANNOT approve it
 *       on the counterpart's behalf -- a lone actor cannot both propose
 *       and approve.
 *   (d) rejection by any one named member recomputes the relationship's
 *       own status to 'rejected', even when every other member already
 *       approved.
 *   (e) adding a third org to an already-'active' two-org relationship
 *       later follows the identical branch logic.
 *   (f) _org_relationship_recompute_status() reaches 'active' only at
 *       >= 2 approved members, never at 1.
 *   (g) an UNRELATED org cannot approve a pending row that isn't theirs.
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_consent.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-relationships.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Two-party consent state machine ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$orgA = 900005200; // proposer org (org-scoped caller's own org)
$orgB = 900005201; // counterpart org
$orgC = 900005202; // third org, added later
$orgU = 900005203; // unrelated org — must never be able to approve anything

$userSuper   = 900005210; // "Super Admin"-equivalent for this test (canActGlobal=true passed directly)
$userA       = 900005211; // Org A's own authorized approver
$userB       = 900005212; // Org B's own authorized approver
$userC       = 900005213; // Org C's own authorized approver
$userU       = 900005214; // Org U's user — unrelated to the relationship entirely

$createdOrgIds = [$orgA, $orgB, $orgC, $orgU];
$createdRelIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdRelIds, $userSuper, $userA, $userB, $userC, $userU) {
    foreach ($createdRelIds as $id) {
        try { db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?, ?, ?)", [$userSuper, $userA, $userB, $userC, $userU]); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([$orgA => 'ZZ143 Org A', $orgB => 'ZZ143 Org B', $orgC => 'ZZ143 Org C', $orgU => 'ZZ143 Org U'] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$userA, $orgA, $orgA]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$userB, $orgB, $orgB]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$userC, $orgC, $orgC]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$userU, $orgU, $orgU]);

    // ══════════════════════════════════════════════════════════════════
    // (a) Super-Admin (global) creation auto-approves ALL named members.
    // ══════════════════════════════════════════════════════════════════
    echo "--- (a) Global-capability creation auto-approves every named org ---\n\n";

    $r1 = org_relationship_create_or_propose(
        ['name' => 'ZZ143 Global-created', 'member_org_ids' => [$orgA, $orgB], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        true, $userSuper, 'ZZ Super'
    );
    t('global creation succeeds', $r1['success'] === true);
    $rel1 = (int) ($r1['id'] ?? 0);
    if ($rel1 > 0) $createdRelIds[] = $rel1;
    t('global creation returns an id', $rel1 > 0);
    t("global creation's relationship status is 'active' immediately (>= 2 auto-approved members)", $r1['status'] === 'active');

    $members1 = db_fetch_all("SELECT org_id, status FROM {$prefix}org_relationships_members WHERE relationship_id = ? ORDER BY org_id", [$rel1]);
    t('both named members are approved', count($members1) === 2 && $members1[0]['status'] === 'approved' && $members1[1]['status'] === 'approved');

    // ══════════════════════════════════════════════════════════════════
    // (b) Org-scoped (Org A) caller creation auto-approves ONLY Org A;
    // Org B starts pending.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- (b) Org-scoped creation auto-approves only the caller's own org ---\n\n";

    $r2 = org_relationship_create_or_propose(
        ['name' => 'ZZ143 Org-scoped proposal', 'member_org_ids' => [$orgA, $orgB], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        false, $userA, 'ZZ Org A User'
    );
    t('org-scoped creation succeeds (caller named their own org)', $r2['success'] === true);
    $rel2 = (int) ($r2['id'] ?? 0);
    if ($rel2 > 0) $createdRelIds[] = $rel2;
    t("org-scoped creation's relationship status is 'pending' (only 1 of 2 approved)", $r2['status'] === 'pending');

    $memberA2 = db_fetch_one("SELECT status FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$rel2, $orgA]);
    $memberB2 = db_fetch_one("SELECT status FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$rel2, $orgB]);
    t("Org A's own row is auto-approved (the act of proposing IS their consent)", $memberA2 && $memberA2['status'] === 'approved');
    t("Org B's row starts 'pending' (awaiting Org B's own approval)", $memberB2 && $memberB2['status'] === 'pending');

    // Org-scoped creation WITHOUT naming the caller's own org must be refused.
    $r2b = org_relationship_create_or_propose(
        ['name' => 'ZZ143 Should fail', 'member_org_ids' => [$orgB, $orgC], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        false, $userA, 'ZZ Org A User'
    );
    t('org-scoped creation is REFUSED when the caller does not name their own org', $r2b['success'] === false);
    t('refusal names the specific requirement', !empty($r2b['errors']) && stripos(implode(' ', $r2b['errors']), 'own organization') !== false);

    // ══════════════════════════════════════════════════════════════════
    // (c) The counterpart's OWN authorized approver can approve their own
    // row; the ORIGINAL proposer CANNOT approve it on the counterpart's
    // behalf. Also: an UNRELATED org's user cannot approve it either.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- (c) Only the counterpart's own approver can approve the counterpart's row (lone actor cannot propose AND approve) ---\n\n";

    $memberBRow = db_fetch_one("SELECT id FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$rel2, $orgB]);
    $memberBId = (int) $memberBRow['id'];

    // The ORIGINAL proposer (userA, Org A) attempts to approve Org B's own
    // row -- must be refused: userA's org_visible_ids() never contains
    // Org B.
    $badApprove = org_relationship_member_approve($memberBId, false, $userA, 'ZZ Org A User');
    t("the ORIGINAL proposer (Org A) CANNOT approve Org B's own row on Org B's behalf", $badApprove['success'] === false);
    $stillPending = db_fetch_one("SELECT status FROM {$prefix}org_relationships_members WHERE id = ?", [$memberBId]);
    t("Org B's row is STILL pending after the refused cross-org approval attempt", $stillPending && $stillPending['status'] === 'pending');

    // An UNRELATED org (Org U)'s user also cannot approve Org B's row.
    $unrelatedApprove = org_relationship_member_approve($memberBId, false, $userU, 'ZZ Org U User');
    t('an UNRELATED org (Org U) cannot approve a pending row that is not theirs', $unrelatedApprove['success'] === false);
    $stillPending2 = db_fetch_one("SELECT status FROM {$prefix}org_relationships_members WHERE id = ?", [$memberBId]);
    t('Org B\'s row is STILL pending after the refused unrelated-org attempt', $stillPending2 && $stillPending2['status'] === 'pending');

    // Org B's OWN authorized approver succeeds.
    $goodApprove = org_relationship_member_approve($memberBId, false, $userB, 'ZZ Org B User');
    t("Org B's OWN authorized approver CAN approve Org B's own row", $goodApprove['success'] === true);
    $nowApproved = db_fetch_one("SELECT status FROM {$prefix}org_relationships_members WHERE id = ?", [$memberBId]);
    t('Org B\'s row is now approved', $nowApproved && $nowApproved['status'] === 'approved');

    $rel2StatusAfter = db_fetch_value("SELECT status FROM {$prefix}org_relationships WHERE id = ?", [$rel2]);
    t("the relationship's own status recomputed to 'active' now that both members are approved", $rel2StatusAfter === 'active');

    // ══════════════════════════════════════════════════════════════════
    // (d) Rejection by any one named member recomputes the relationship's
    // own status to 'rejected', even when every other member already
    // approved.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- (d) A single holdout's rejection recomputes the WHOLE relationship to 'rejected' ---\n\n";

    $r3 = org_relationship_create_or_propose(
        ['name' => 'ZZ143 Rejection test', 'member_org_ids' => [$orgA, $orgC], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        false, $userA, 'ZZ Org A User'
    );
    $rel3 = (int) ($r3['id'] ?? 0);
    if ($rel3 > 0) $createdRelIds[] = $rel3;
    t('rejection-test relationship created, pending', $r3['status'] === 'pending');

    $memberC3 = db_fetch_one("SELECT id FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$rel3, $orgC]);
    $reject3 = org_relationship_member_reject((int) $memberC3['id'], false, $userC, 'ZZ Org C User', 'not interested');
    t('Org C rejects', $reject3['success'] === true);

    $rel3StatusAfter = db_fetch_value("SELECT status FROM {$prefix}org_relationships WHERE id = ?", [$rel3]);
    t("the relationship's status recomputes to 'rejected' even though Org A (the only other member) already approved", $rel3StatusAfter === 'rejected');

    // ══════════════════════════════════════════════════════════════════
    // (e) Adding a third org to an already-'active' relationship follows
    // the identical branch logic.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- (e) Adding a member to an already-active relationship follows the same branch logic ---\n\n";

    $addResult = org_relationship_member_add($rel2, $orgC, false, $userA, 'ZZ Org A User');
    t('Org A (org-scoped, not itself Org C) adding Org C succeeds (creates a pending row, does not auto-approve Org C)', $addResult['success'] === true);
    $memberC2 = db_fetch_one("SELECT id, status FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$rel2, $orgC]);
    t("Org C's newly-added row starts 'pending' (Org A cannot auto-approve on Org C's behalf)", $memberC2 && $memberC2['status'] === 'pending');

    $relStatusAfterAdd = db_fetch_value("SELECT status FROM {$prefix}org_relationships WHERE id = ?", [$rel2]);
    t("the relationship's status recomputes back to 'pending' the instant a third, unapproved member is added", $relStatusAfterAdd === 'pending');

    $addApprove = org_relationship_member_approve((int) $memberC2['id'], false, $userC, 'ZZ Org C User');
    t("Org C's own approver approves", $addApprove['success'] === true);
    $relStatusAfterApprove = db_fetch_value("SELECT status FROM {$prefix}org_relationships WHERE id = ?", [$rel2]);
    t("the relationship recomputes back to 'active' once all THREE members are approved", $relStatusAfterApprove === 'active');

    // ══════════════════════════════════════════════════════════════════
    // (f) _org_relationship_recompute_status() reaches 'active' only at
    // >= 2 approved members, never at 1.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- (f) A relationship with only 1 approved member never reaches 'active' ---\n\n";

    // Direct-insert fixture (bypassing the create function's own >= 2
    // validation) to exercise _org_relationship_recompute_status() at
    // exactly 1 approved member.
    db_query(
        "INSERT INTO {$prefix}org_relationships (name, access_tier, redaction_profile, status) VALUES ('ZZ143 Single-member', 'view', 'view', 'pending')"
    );
    $rel4 = (int) db_insert_id();
    $createdRelIds[] = $rel4;
    db_query(
        "INSERT INTO {$prefix}org_relationships_members (relationship_id, org_id, status, proposed_by, proposed_by_name, approved_by, approved_by_name, approved_at)
         VALUES (?, ?, 'approved', ?, 'ZZ', ?, 'ZZ', NOW())",
        [$rel4, $orgA, $userA, $userA]
    );
    _org_relationship_recompute_status($rel4);
    $rel4Status = db_fetch_value("SELECT status FROM {$prefix}org_relationships WHERE id = ?", [$rel4]);
    t("recompute_status: a relationship with exactly 1 approved member (and no other rows) stays 'pending', never 'active'", $rel4Status === 'pending');

    // ══════════════════════════════════════════════════════════════════
    // (g) org_relationship_can_act_for_org() itself, in isolation — the
    // one reusable per-row primitive everything above rests on.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- (g) org_relationship_can_act_for_org() in isolation ---\n\n";

    t('global caller can act for ANY org', org_relationship_can_act_for_org(true, $orgA, $userU));
    t("org-scoped Org A user CAN act for Org A", org_relationship_can_act_for_org(false, $orgA, $userA));
    t("org-scoped Org A user CANNOT act for Org B", !org_relationship_can_act_for_org(false, $orgB, $userA));
    t("org-scoped Org U user CANNOT act for Org A", !org_relationship_can_act_for_org(false, $orgA, $userU));

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
