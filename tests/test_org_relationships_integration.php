<?php
/**
 * Phase 143 (2026-08-17) — Integration with existing tier/redaction/audit
 * machinery: the three named edits actually behave as specified.
 *
 * Covers:
 *   1. The four access_tier/redaction_profile combinations
 *      (view/view, view/assist, assist/view, assist/assist), each
 *      asserting BOTH the write-capability outcome (org_can_mutate_ticket())
 *      AND the field-set outcome (org_share_context_for_ticket()'s
 *      redaction_tier, fed through org_share_redact_ticket_fields())
 *      independently -- proving the two axes are genuinely decoupled, not
 *      silently collapsed back into one.
 *   2. Precedence: when both an incident_shares grant and a relationship
 *      grant apply to the same (ticket, org) pair, the incident_shares
 *      tier governs redaction/attribution, unchanged from pre-Phase-143
 *      behavior.
 *   3. org_sharing_apply_list_redaction() applies the relationship
 *      redaction_profile (not access_tier) to a relationship-sourced row.
 *   4. All five audit_log() lifecycle entries fire with the right
 *      category/activity/severity and a details payload naming the right
 *      fields -- including auto_expired distinguishing a manual
 *      deactivation from an auto-expired one.
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_integration.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/org-relationships.php';
require_once __DIR__ . '/../inc/audit.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Integration: tier/redaction/audit machinery ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$ownerOrgId = 900005700;
$viewViewOrgId   = 900005701; // access_tier=view,   redaction_profile=view
$viewAssistOrgId = 900005702; // access_tier=view,   redaction_profile=assist
$assistViewOrgId = 900005703; // access_tier=assist, redaction_profile=view
$assistAssistOrgId = 900005704; // access_tier=assist, redaction_profile=assist
$precedenceOrgId = 900005705; // has BOTH an incident_shares row and a relationship

$ownerUserId = 900005710;
$vvUserId = 900005711;
$vaUserId = 900005712;
$avUserId = 900005713;
$aaUserId = 900005714;
$precUserId = 900005715;

$createdOrgIds = [$ownerOrgId, $viewViewOrgId, $viewAssistOrgId, $assistViewOrgId, $assistAssistOrgId, $precedenceOrgId];
$createdUserIds = [$ownerUserId, $vvUserId, $vaUserId, $avUserId, $aaUserId, $precUserId];
$createdTicketIds = [];
$createdRelIds = [];

$cleanup = function () use ($prefix, &$createdOrgIds, &$createdUserIds, &$createdTicketIds, &$createdRelIds) {
    foreach ($createdRelIds as $id) {
        try { db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTicketIds as $id) {
        try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdUserIds as $id) { try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$id]); } catch (Throwable $e) {} }
};
$cleanup();

try {
    foreach ($createdOrgIds as $id) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, "ZZ143Int Org {$id}"]);
    }
    foreach ([
        $ownerUserId => $ownerOrgId, $vvUserId => $viewViewOrgId, $vaUserId => $viewAssistOrgId,
        $avUserId => $assistViewOrgId, $aaUserId => $assistAssistOrgId, $precUserId => $precedenceOrgId,
    ] as $uid => $oid) {
        db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$uid, $oid, $oid]);
    }

    $now = date('Y-m-d H:i:s');
    $mkTicket = function ($tag) use ($prefix, $ownerOrgId, $now, &$createdTicketIds) {
        db_query(
            "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
             VALUES (0, 'zz143int contact', '1 Int143 Way', 'Testville', 'MN', 44.8, -93.3, ?, ?, 'zz143int description', 2, 1, NOW(), ?)",
            [$now, "zz143int {$tag}", $ownerOrgId]
        );
        $id = (int) db_insert_id();
        $createdTicketIds[] = $id;
        return $id;
    };

    $ticketVV = $mkTicket('view-view');
    $ticketVA = $mkTicket('view-assist');
    $ticketAV = $mkTicket('assist-view');
    $ticketAA = $mkTicket('assist-assist');
    $ticketPrec = $mkTicket('precedence');

    $mkRel = function ($name, $memberOrgId, $accessTier, $redactionProfile) use ($prefix, $ownerOrgId, $ownerUserId, &$createdRelIds) {
        $r = org_relationship_create_or_propose(
            ['name' => $name, 'member_org_ids' => [$ownerOrgId, $memberOrgId], 'access_tier' => $accessTier,
             'redaction_profile' => $redactionProfile, 'requires_activation' => 0],
            true, $ownerUserId, 'ZZ Owner User'
        );
        if (!empty($r['id'])) $createdRelIds[] = (int) $r['id'];
        return $r;
    };

    // ══════════════════════════════════════════════════════════════════
    // Item 1 — four independent access_tier/redaction_profile combinations
    // ══════════════════════════════════════════════════════════════════
    echo "--- Item 1: the four access_tier/redaction_profile combinations, independently asserted ---\n\n";

    $rVV = $mkRel('ZZ143Int VV', $viewViewOrgId, 'view', 'view');
    t('view/view relationship created and active', $rVV['success'] && $rVV['status'] === 'active');
    t('view/view: write capability DENIED (access_tier=view)', !org_can_mutate_ticket($ticketVV, $vvUserId));
    $ctxVV = org_share_context_for_ticket($ticketVV, $vvUserId);
    t('view/view: context resolved', $ctxVV !== null);
    t('view/view: redaction_tier is view (fields redacted)', $ctxVV && $ctxVV['redaction_tier'] === 'view');
    $redactedVV = org_share_redact_ticket_fields(['id' => $ticketVV, 'contact' => 'SECRET', 'description' => 'SECRET DESC', 'scope' => 'zz143int view-view'], $ctxVV['redaction_tier']);
    t('view/view: PII field (contact) is REDACTED OUT', !array_key_exists('contact', $redactedVV));

    $rVA = $mkRel('ZZ143Int VA', $viewAssistOrgId, 'view', 'assist');
    t('view/assist relationship created and active', $rVA['success'] && $rVA['status'] === 'active');
    t('view/assist: write capability DENIED (access_tier=view, even though redaction_profile=assist)', !org_can_mutate_ticket($ticketVA, $vaUserId));
    $ctxVA = org_share_context_for_ticket($ticketVA, $vaUserId);
    t('view/assist: redaction_tier is assist (full field set, despite NO write capability)', $ctxVA && $ctxVA['redaction_tier'] === 'assist');
    $redactedVA = org_share_redact_ticket_fields(['id' => $ticketVA, 'contact' => 'SECRET', 'description' => 'SECRET DESC', 'scope' => 'zz143int view-assist'], $ctxVA['redaction_tier']);
    t('view/assist: PII field (contact) is RETAINED (redaction_profile=assist governs the field set, independent of access_tier)', array_key_exists('contact', $redactedVA));

    $rAV = $mkRel('ZZ143Int AV', $assistViewOrgId, 'assist', 'view');
    t('assist/view relationship created and active', $rAV['success'] && $rAV['status'] === 'active');
    t('assist/view: write capability GRANTED (access_tier=assist)', org_can_mutate_ticket($ticketAV, $avUserId));
    $ctxAV = org_share_context_for_ticket($ticketAV, $avUserId);
    t('assist/view: redaction_tier is view (fields STILL redacted, despite full write capability -- the whole point of decoupling the axes)', $ctxAV && $ctxAV['redaction_tier'] === 'view');
    $redactedAV = org_share_redact_ticket_fields(['id' => $ticketAV, 'contact' => 'SECRET', 'description' => 'SECRET DESC', 'scope' => 'zz143int assist-view'], $ctxAV['redaction_tier']);
    t('assist/view: PII field (contact) is REDACTED OUT even though the caller can fully mutate the ticket', !array_key_exists('contact', $redactedAV));

    $rAA = $mkRel('ZZ143Int AA', $assistAssistOrgId, 'assist', 'assist');
    t('assist/assist relationship created and active', $rAA['success'] && $rAA['status'] === 'active');
    t('assist/assist: write capability GRANTED', org_can_mutate_ticket($ticketAA, $aaUserId));
    $ctxAA = org_share_context_for_ticket($ticketAA, $aaUserId);
    t('assist/assist: redaction_tier is assist (full field set)', $ctxAA && $ctxAA['redaction_tier'] === 'assist');

    // ══════════════════════════════════════════════════════════════════
    // Item 2 — precedence: incident_shares wins unconditionally
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 2: precedence -- incident_shares wins over a relationship for the SAME (ticket, org) pair ---\n\n";

    $rPrec = $mkRel('ZZ143Int Precedence', $precedenceOrgId, 'assist', 'assist'); // relationship would grant assist/assist
    t('precedence relationship created and active', $rPrec['success'] && $rPrec['status'] === 'active');

    // A view-tier MANUAL share for the SAME (ticket, org) pair -- per
    // plan.md, this must govern redaction UNCONDITIONALLY, even though the
    // relationship (if it were the only source) would grant assist/assist.
    db_query(
        "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`access_tier`,`created_by`,`created_by_name`)
         VALUES (?, ?, ?, 'view', ?, 'ZZ Owner User')",
        [$ticketPrec, $precedenceOrgId, $ownerOrgId, $ownerUserId]
    );

    $ctxPrec = org_share_context_for_ticket($ticketPrec, $precUserId);
    t('precedence: context resolved', $ctxPrec !== null);
    t('precedence: routing_rule_id/relationship_id shape confirms incident_shares source (relationship_id null)', $ctxPrec && $ctxPrec['relationship_id'] === null);
    t('precedence: access_tier is view (from incident_shares), NOT assist (which the relationship alone would have granted)', $ctxPrec && $ctxPrec['access_tier'] === 'view');
    t('precedence: redaction_tier is view (incident_shares governs, unconditionally)', $ctxPrec && $ctxPrec['redaction_tier'] === 'view');
    t('precedence: write capability still GRANTED via the relationship (existence-of-write-capability is OR\'d across sources per plan.md, unlike tier-selection)', org_can_mutate_ticket($ticketPrec, $precUserId));

    // ══════════════════════════════════════════════════════════════════
    // Item 3 — org_sharing_apply_list_redaction() applies redaction_profile
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 3: org_sharing_apply_list_redaction() applies redaction_profile for a relationship-sourced row ---\n\n";

    $listRows = [
        ['id' => $ticketAV, 'org_id' => $ownerOrgId, 'contact' => 'SECRET LIST CONTACT', 'description' => 'SECRET LIST DESC', 'scope' => 'zz143int list row'],
    ];
    $listRowsRedacted = org_sharing_apply_list_redaction($listRows, $avUserId);
    t('list redaction: assist-tier WRITE org with view-tier REDACTION still has its PII field redacted out in list results', !array_key_exists('contact', $listRowsRedacted[0]));
    t('list redaction: shared_from_org_id annotation is present', array_key_exists('shared_from_org_id', $listRowsRedacted[0]) && $listRowsRedacted[0]['shared_from_org_id'] === $ownerOrgId);

    // ══════════════════════════════════════════════════════════════════
    // Item 4 — all five audit_log() lifecycle entries
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 4: all five audit_log() lifecycle entries fire correctly ---\n\n";

    // $rVV was already created back in Item 1 -- its 'relationship_proposed'
    // audit entry fired then, via the real writer. Inspect it directly,
    // never delete-then-look (that would just erase the very row this
    // assertion needs).
    $auditRelId = (int) $rVV['id'];
    $proposedRow = db_fetch_one(
        "SELECT category, activity, severity FROM {$prefix}newui_audit_log WHERE target_type = 'org_relationship' AND target_id = ? AND activity = 'relationship_proposed' ORDER BY id DESC LIMIT 1",
        [$auditRelId]
    );
    t('relationship_proposed audit entry fired', (bool) $proposedRow);
    t('relationship_proposed: category=config, severity=AUDIT_MEDIUM(3)', $proposedRow && $proposedRow['category'] === 'config' && (int) $proposedRow['severity'] === 3);

    // Approve/reject entries via a fresh org-scoped propose + approve/reject cycle.
    $auditOrgX = 900005720;
    $auditOrgY = 900005721;
    $auditUserX = 900005730;
    $auditUserY = 900005731;
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ143Int Audit X', 1)", [$auditOrgX]);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ143Int Audit Y', 1)", [$auditOrgY]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$auditUserX, $auditOrgX, $auditOrgX]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$auditUserY, $auditOrgY, $auditOrgY]);
    $createdOrgIds[] = $auditOrgX; $createdOrgIds[] = $auditOrgY;
    $createdUserIds[] = $auditUserX; $createdUserIds[] = $auditUserY;

    $auditRel = org_relationship_create_or_propose(
        ['name' => 'ZZ143Int Audit Rel', 'member_org_ids' => [$auditOrgX, $auditOrgY], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        false, $auditUserX, 'ZZ Audit User X'
    );
    $auditRelId2 = (int) ($auditRel['id'] ?? 0);
    if ($auditRelId2 > 0) $createdRelIds[] = $auditRelId2;
    $auditMemberY = db_fetch_one("SELECT id FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$auditRelId2, $auditOrgY]);

    $approveResult = org_relationship_member_approve((int) $auditMemberY['id'], false, $auditUserY, 'ZZ Audit User Y');
    t('approve succeeds', $approveResult['success'] === true);
    $approvedRow = db_fetch_one(
        "SELECT category, activity, severity, details FROM {$prefix}newui_audit_log WHERE target_type = 'org_relationship' AND target_id = ? AND activity = 'relationship_member_approved' ORDER BY id DESC LIMIT 1",
        [$auditRelId2]
    );
    t('relationship_member_approved audit entry fired', (bool) $approvedRow);
    if ($approvedRow) {
        $details = json_decode($approvedRow['details'], true) ?: [];
        t('relationship_member_approved details carries org_id', (int) ($details['org_id'] ?? 0) === $auditOrgY);
    }

    // Now activate + deactivate to cover the remaining two.
    $activateResult = org_relationship_activate($auditRelId2, false, $auditUserX, 'ZZ Audit User X', 'audit drill activation', 5);
    t('activate succeeds', $activateResult['success'] === true);
    $activatedRow = db_fetch_one(
        "SELECT category, activity, severity, details FROM {$prefix}newui_audit_log WHERE target_type = 'org_relationship' AND target_id = ? AND activity = 'relationship_activated' ORDER BY id DESC LIMIT 1",
        [$auditRelId2]
    );
    t('relationship_activated audit entry fired', (bool) $activatedRow);
    if ($activatedRow) {
        $details = json_decode($activatedRow['details'], true) ?: [];
        t('relationship_activated details carries activation_id + max_activation_minutes', !empty($details['activation_id']) && (int) $details['max_activation_minutes'] === 5);
    }

    $deactivateResult = org_relationship_deactivate($auditRelId2, false, $auditUserX, 'ZZ Audit User X', 'audit drill stand-down', false);
    t('manual deactivate succeeds', $deactivateResult['success'] === true);
    $deactivatedRow = db_fetch_one(
        "SELECT category, activity, severity, details FROM {$prefix}newui_audit_log WHERE target_type = 'org_relationship' AND target_id = ? AND activity = 'relationship_deactivated' ORDER BY id DESC LIMIT 1",
        [$auditRelId2]
    );
    t('relationship_deactivated audit entry fired', (bool) $deactivatedRow);
    if ($deactivatedRow) {
        $details = json_decode($deactivatedRow['details'], true) ?: [];
        t('relationship_deactivated (MANUAL): auto_expired is false, distinguishing it from a cleanup-job closure', ($details['auto_expired'] ?? null) === false);
    }

    // relationship_member_rejected -- via a fresh third org.
    $auditOrgZ = 900005722;
    $auditUserZ = 900005732;
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ143Int Audit Z', 1)", [$auditOrgZ]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$auditUserZ, $auditOrgZ, $auditOrgZ]);
    $createdOrgIds[] = $auditOrgZ;
    $createdUserIds[] = $auditUserZ;
    $addZ = org_relationship_member_add($auditRelId2, $auditOrgZ, false, $auditUserX, 'ZZ Audit User X');
    t('member_add for Org Z succeeds', $addZ['success'] === true);
    $rejectZ = org_relationship_member_reject((int) $addZ['id'], false, $auditUserZ, 'ZZ Audit User Z', 'declining');
    t('reject succeeds', $rejectZ['success'] === true);
    $rejectedRow = db_fetch_one(
        "SELECT category, activity, severity, details FROM {$prefix}newui_audit_log WHERE target_type = 'org_relationship' AND target_id = ? AND activity = 'relationship_member_rejected' ORDER BY id DESC LIMIT 1",
        [$auditRelId2]
    );
    t('relationship_member_rejected audit entry fired', (bool) $rejectedRow);
    if ($rejectedRow) {
        $details = json_decode($rejectedRow['details'], true) ?: [];
        t('relationship_member_rejected details carries the reason', ($details['reason'] ?? null) === 'declining');
    }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
