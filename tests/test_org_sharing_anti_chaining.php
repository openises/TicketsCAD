<?php
/**
 * Phase 142 (2026-08-17) — Anti-chaining regression, EARLY per tasks.md
 * section 4: written immediately after the functions it exercises, well
 * before the endpoint/UI/SSE work. This is the single hard security line
 * this phase names (plan.md's "one hard security line"):
 *
 *   share-creation (and revoke) must be rejected whenever the caller's own
 *   access is itself share-derived, not owner-derived.
 *
 * Drives org_sharing_create_manual_share() / org_sharing_revoke_share()
 * directly -- the REAL writers, not hand-seeded rows -- through the exact
 * cases plan.md's "one hard security line" section names:
 *
 *   1. A view-tier shared-in user at Org B attempts to share ticket X
 *      (owned by Org A) onward to Org C. Refused, 403-shape (non-empty
 *      errors), and NO row is written to incident_shares for (X, Org C).
 *   2. An assist-tier shared-in user at Org B (full same-org-equivalent
 *      WRITE access to ticket X via org_can_mutate_ticket()) attempts the
 *      same. STILL refused -- the specific case Phase 141's
 *      design-synthesis flagged, and the reason
 *      org_ticket_is_owned_by_caller() deliberately never delegates to
 *      org_can_mutate_ticket().
 *   3. A genuine Org A (owning-org) user succeeds sharing ticket X to
 *      Org C. Control case -- proves the gate isn't failing closed for
 *      everyone, which would make cases 1/2's "refused" assertions
 *      meaningless.
 *   4. The same three shapes against org_sharing_revoke_share(): a
 *      shared-in user (either tier) at Org B cannot revoke Org A's share
 *      of ticket X to a DIFFERENT org (the IDOR-guard companion case), or
 *      even Org B's OWN share of ticket X -- only Org A (the owning org)
 *      can manage sharing on a ticket it owns.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_anti_chaining.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 142 — Anti-chaining regression (the one hard security line) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$ownerOrgId   = 900004260; // Org A -- actually owns ticket X
$viewOrgId    = 900004261; // Org B (view tier)
$assistOrgId  = 900004262; // Org B2 (assist tier)
$targetOrgId  = 900004263; // Org C -- the onward-share target
$revokeTargetOrgId = 900004264; // Org D -- a second onward share, for revoke cases

$ownerUserId  = 900004270;
$viewUserId   = 900004271;
$assistUserId = 900004272;
$targetUserId = 900004273; // member of Org C -- used to prove immediate visibility loss on revoke

$createdOrgIds = [$ownerOrgId, $viewOrgId, $assistOrgId, $targetOrgId, $revokeTargetOrgId];
$createdTicketIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, $ownerUserId, $viewUserId, $assistUserId, $targetUserId) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?, ?)", [$ownerUserId, $viewUserId, $assistUserId, $targetUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([
        $ownerOrgId  => 'ZZ142AC Owner',
        $viewOrgId   => 'ZZ142AC ViewOrg',
        $assistOrgId => 'ZZ142AC AssistOrg',
        $targetOrgId => 'ZZ142AC TargetOrg',
        $revokeTargetOrgId => 'ZZ142AC RevokeTargetOrg',
    ] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
    }

    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$viewUserId, $viewOrgId, $viewOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$assistUserId, $assistOrgId, $assistOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$targetUserId, $targetOrgId, $targetOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 AntiChain Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142ac ticket', 'zz142ac ticket', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;
    t('fixture ticket created, owned by Org A', $ticketId > 0);

    // Org B holds an ACTIVE view-tier share; Org B2 holds an ACTIVE
    // assist-tier share -- both direct incident_shares inserts (simulating
    // a rule- or manually-granted share already in place), not routed
    // through org_sharing_create_manual_share() itself, so this test does
    // not depend on the function under test to set up its own fixtures.
    db_query(
        "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`access_tier`,`created_by`,`created_by_name`)
         VALUES (?, ?, ?, 'view', ?, 'ZZ142AC Owner User')",
        [$ticketId, $viewOrgId, $ownerOrgId, $ownerUserId]
    );
    $viewShareId = (int) db_insert_id();
    db_query(
        "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`access_tier`,`created_by`,`created_by_name`)
         VALUES (?, ?, ?, 'assist', ?, 'ZZ142AC Owner User')",
        [$ticketId, $assistOrgId, $ownerOrgId, $ownerUserId]
    );
    $assistShareId = (int) db_insert_id();

    t('Org B (view tier) can see the ticket', org_can_see_ticket($ticketId, $viewUserId));
    t('Org B2 (assist tier) can see the ticket', org_can_see_ticket($ticketId, $assistUserId));
    t('Org B2 (assist tier) CAN mutate the ticket (full same-org-equivalent write access -- the control fact this whole test exists to defeat for SHARING specifically)', org_can_mutate_ticket($ticketId, $assistUserId));

    // ══════════════════════════════════════════════════════════════════
    // CREATE — cases 1, 2, 3
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_sharing_create_manual_share() ---\n\n";

    // Case 1 — view-tier shared-in user at Org B attempts to share onward.
    $result1 = org_sharing_create_manual_share($ticketId, $targetOrgId, 'view', 'onward share attempt (view tier)', $viewUserId, 'ZZ View User');
    t('Case 1: view-tier shared-in user is REFUSED when sharing onward', $result1['success'] === false);
    t('Case 1: refusal carries a non-empty errors array', !empty($result1['errors']));
    $rowAfter1 = db_fetch_one("SELECT id FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $targetOrgId]);
    t('Case 1: NO row was written to incident_shares for (ticket, Org C)', !$rowAfter1);

    // Case 2 — assist-tier shared-in user at Org B2 attempts the same.
    // This is the specific case design-synthesis.md flagged: assist tier
    // already grants full same-org-equivalent WRITE access via
    // org_can_mutate_ticket() (asserted above), yet sharing must still be
    // refused because org_ticket_is_owned_by_caller() never delegates to
    // org_can_mutate_ticket().
    $result2 = org_sharing_create_manual_share($ticketId, $targetOrgId, 'view', 'onward share attempt (assist tier)', $assistUserId, 'ZZ Assist User');
    t('Case 2: assist-tier shared-in user is STILL REFUSED when sharing onward, despite holding full write access to the ticket', $result2['success'] === false);
    t('Case 2: refusal carries a non-empty errors array', !empty($result2['errors']));
    $rowAfter2 = db_fetch_one("SELECT id FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $targetOrgId]);
    t('Case 2: NO row was written to incident_shares for (ticket, Org C) after the assist-tier attempt either', !$rowAfter2);

    // Case 3 — control: a genuine owning-org (Org A) user succeeds.
    $result3 = org_sharing_create_manual_share($ticketId, $targetOrgId, 'view', 'legitimate owning-org share', $ownerUserId, 'ZZ Owner User');
    t('Case 3 (control): the genuine owning-org user SUCCEEDS sharing the ticket to Org C -- proves the gate is not failing closed for everyone', $result3['success'] === true);
    t('Case 3 (control): a real share id was returned', (int) ($result3['id'] ?? 0) > 0);
    $rowAfter3 = db_fetch_one("SELECT id, created_by FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $targetOrgId]);
    t('Case 3 (control): the row now exists in incident_shares, attributed to the owning-org caller', $rowAfter3 && (int) $rowAfter3['created_by'] === $ownerUserId);
    $createdShareToC = $rowAfter3 ? (int) $rowAfter3['id'] : 0;

    // ══════════════════════════════════════════════════════════════════
    // REVOKE — same three shapes, plus "cannot revoke your OWN share"
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_sharing_revoke_share() ---\n\n";

    // Set up a second owner-created share (ticket -> Org D) to attempt
    // revoking from the wrong caller.
    db_query(
        "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`access_tier`,`created_by`,`created_by_name`)
         VALUES (?, ?, ?, 'view', ?, 'ZZ142AC Owner User')",
        [$ticketId, $revokeTargetOrgId, $ownerOrgId, $ownerUserId]
    );
    $revokeTargetShareId = (int) db_insert_id();

    // Case 1 — view-tier shared-in user at Org B cannot revoke Org A's
    // share of the ticket to a DIFFERENT org (Org D). The IDOR-guard
    // companion case: the caller supplies only a share_id, never a
    // ticket_id, and the function must derive ticket_id from the row
    // itself before gating.
    $revokeResult1 = org_sharing_revoke_share($revokeTargetShareId, 'unauthorized revoke attempt (view tier)', $viewUserId, 'ZZ View User');
    t('Revoke Case 1: view-tier shared-in user is REFUSED revoking a DIFFERENT org\'s share on a ticket they do not own', $revokeResult1['success'] === false);
    $stillActive1 = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$revokeTargetShareId]);
    t('Revoke Case 1: the target share is STILL active (revoked_at IS NULL) after the refused attempt', $stillActive1 === null);

    // Case 2 — assist-tier shared-in user at Org B2, same DIFFERENT-org
    // share, same refusal despite full write access to the ticket itself.
    $revokeResult2 = org_sharing_revoke_share($revokeTargetShareId, 'unauthorized revoke attempt (assist tier)', $assistUserId, 'ZZ Assist User');
    t('Revoke Case 2: assist-tier shared-in user is STILL REFUSED revoking a DIFFERENT org\'s share, despite holding full write access to the ticket', $revokeResult2['success'] === false);
    $stillActive2 = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$revokeTargetShareId]);
    t('Revoke Case 2: the target share is STILL active after the refused attempt', $stillActive2 === null);

    // Companion invariant: a shared-in org cannot even revoke its OWN
    // share -- only the OWNING org (Org A) can manage sharing on a ticket
    // it doesn't own is the wrong framing here; the correct framing is
    // "only the owning org can manage sharing on a ticket IT owns" -- Org
    // B does not own ticket X, so Org B cannot un-share itself either.
    $revokeOwnResult = org_sharing_revoke_share($viewShareId, 'attempt to self-revoke', $viewUserId, 'ZZ View User');
    t('Revoke companion: Org B cannot revoke its OWN share either -- only the owning org (Org A) manages sharing on a ticket it owns', $revokeOwnResult['success'] === false);
    $viewShareStillActive = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$viewShareId]);
    t('Revoke companion: Org B\'s own share is STILL active after its own refused self-revoke attempt', $viewShareStillActive === null);

    // Before revoking: confirm Org C's user actually gained visibility from
    // Case 3's control share (org_can_see_ticket() and
    // org_ticket_query_filter() both live-query incident_shares, per Phase
    // 141) -- otherwise the "loses visibility on revoke" assertion below
    // would be trivially true for the wrong reason.
    t('Org C\'s user CAN see the ticket via org_can_see_ticket() while the share is still active', org_can_see_ticket($ticketId, $targetUserId));
    [$preRevokeFrag, $preRevokeVars] = org_ticket_query_filter($targetUserId, 't');
    $preRevokeRows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$preRevokeFrag} AND t.id = ?", array_merge($preRevokeVars, [$ticketId]));
    t('Org C\'s user sees the ticket via org_ticket_query_filter() while the share is still active', count($preRevokeRows) === 1);

    // Case 3 — control: the genuine owning-org (Org A) user succeeds
    // revoking the share it just created to Org C.
    $revokeResult3 = org_sharing_revoke_share($createdShareToC, 'legitimate revoke by owning org', $ownerUserId, 'ZZ Owner User');
    t('Revoke Case 3 (control): the genuine owning-org user SUCCEEDS revoking the share it created -- proves the gate is not failing closed for everyone', $revokeResult3['success'] === true);
    $revokedNow = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$createdShareToC]);
    t('Revoke Case 3 (control): revoked_at is now set', $revokedNow !== null);

    // ══════════════════════════════════════════════════════════════════
    // Immediate visibility loss on revoke — org_can_see_ticket() /
    // org_ticket_query_filter() (Phase 141's existing filters, which
    // already filter on revoked_at IS NULL) must reflect the revoke with
    // zero propagation delay, since both re-query live on every call.
    // Per the task's explicit instruction: verify this rather than assume
    // it "should be automatic".
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- revoke immediately removes visibility (Phase 141's own revoked_at IS NULL filters) ---\n\n";

    t('Org C\'s user no longer sees the ticket via org_can_see_ticket() immediately after revoke', !org_can_see_ticket($ticketId, $targetUserId));
    [$postRevokeFrag, $postRevokeVars] = org_ticket_query_filter($targetUserId, 't');
    $postRevokeRows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$postRevokeFrag} AND t.id = ?", array_merge($postRevokeVars, [$ticketId]));
    t('Org C\'s user no longer sees the ticket via org_ticket_query_filter() immediately after revoke', count($postRevokeRows) === 0);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
