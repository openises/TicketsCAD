<?php
/**
 * Phase 141 (2026-08-17) — Audit logging verification.
 *
 * Fires all three shapes from plan.md's Audit Logging section through
 * their REAL writer paths and asserts the resulting newui_audit_log
 * rows carry the exact category/activity/severity plan.md specifies:
 *
 *   1. 'share_created' — fired once per incident_shares row, from
 *      org_sharing_apply_routing_on_create() via a REAL
 *      incident_create_internal() call (already smoke-tested in
 *      test_org_sharing_functions.php; re-verified here with the exact
 *      category/activity/severity/details assertions).
 *   2. Routing-rule CRUD ('config' category) — create/update/deactivate,
 *      via the REAL org_routing_rule_create()/update()/deactivate()
 *      writers this endpoint-integration task added (not hand-inserted
 *      org_type_routing rows).
 *   3. 'view_shared' — reproduces incident-detail.php's exact
 *      post-Phase-141 audit call (verified present in the real file by
 *      tests/test_org_sharing_endpoint_wiring.php) against a real share,
 *      confirming it fires ONLY for a genuinely share-derived read and
 *      NEVER for same-org / Super Admin access.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_audit.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/audit.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Audit logging verification ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$ownerOrgId  = 900002460;
$targetOrgId = 900002461;
$otherOrgId  = 900002462;
$superUserId = 900002463;
$viewUserId  = 900002464;

$createdOrgIds = [];
$createdTicketIds = [];
$createdRuleIds = [];
$createdTypeIds = [];

$cleanup = function () use (
    $prefix, &$createdOrgIds, &$createdTicketIds, &$createdRuleIds, &$createdTypeIds,
    $superUserId, $viewUserId
) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    // Audit-log rows referencing a since-deleted org_type_routing id are
    // harmless to leave (unique-id fixture range, never re-read by another
    // test file) — not deleted here, deliberately, to avoid a fragile
    // cross-table subquery.
    foreach ($createdRuleIds as $id)   { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id)   { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id)    { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$superUserId, $viewUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([$ownerOrgId => 'ZZ141Audit Owner', $targetOrgId => 'ZZ141Audit Target', $otherOrgId => 'ZZ141Audit Other'] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
        $createdOrgIds[] = $id;
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 1, NULL, 'global', NULL)", [$superUserId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$viewUserId, $targetOrgId, $targetOrgId]);

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 audit type', 'ZZ141AuditGroup')", ['zz141audit-' . uniqid()]);
    $typeId = (int) db_insert_id();
    $createdTypeIds[] = $typeId;

    // A SECOND type/group for the share_created section below — deliberately
    // distinct from ZZ141AuditGroup, because uk_org_routing_rule keys on
    // (owning_org_id, shared_with_org_id, match_key) WITHOUT `active` in the
    // key: deactivating the CRUD section's rule does not free that identity
    // for reuse (this is correct schema behavior, not a bug — a deactivated
    // rule's identity stays reserved so its incident_shares attribution
    // stays unambiguous, per plan.md's immutability section).
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 audit type 2', 'ZZ141AuditGroup2')", ['zz141audit2-' . uniqid()]);
    $typeId2 = (int) db_insert_id();
    $createdTypeIds[] = $typeId2;

    // ══════════════════════════════════════════════════════════════════
    // 1. Routing-rule CRUD ('config' category) via the REAL writers
    // ══════════════════════════════════════════════════════════════════
    echo "--- Routing-rule CRUD audit ('config' category) ---\n\n";

    $_SESSION['user_id'] = $superUserId;
    $_SESSION['user'] = 'zz141-audit-super';

    $createResult = org_routing_rule_create([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141AuditGroup', 'access_tier' => 'view',
    ], $superUserId, 'zz141-audit-super');
    t("org_routing_rule_create() succeeds via the real writer", $createResult['success'] === true);
    $ruleId = (int) ($createResult['id'] ?? 0);
    if ($ruleId > 0) $createdRuleIds[] = $ruleId;
    t("a real rule id was returned", $ruleId > 0);

    $createAudit = db_fetch_one(
        "SELECT * FROM {$prefix}newui_audit_log
          WHERE category = 'config' AND activity = 'create' AND target_type = 'org_type_routing' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ruleId]
    );
    t("a 'config'/'create' audit row exists for the new rule", (bool) $createAudit);
    if ($createAudit) {
        $details = json_decode((string) $createAudit['details'], true) ?: [];
        t("audit details carry the correct owning_org_id", (int) ($details['owning_org_id'] ?? 0) === $ownerOrgId);
        t("audit details carry the correct shared_with_org_id", (int) ($details['shared_with_org_id'] ?? 0) === $targetOrgId);
        t("audit details carry the correct access_tier", ($details['access_tier'] ?? '') === 'view');
        t("audit severity is AUDIT_MEDIUM (3) per plan.md", (int) $createAudit['severity'] === 3);
    }

    $updateResult = org_routing_rule_update($ruleId, ['access_tier' => 'assist'], $superUserId);
    t("org_routing_rule_update() succeeds (tier-only edit)", $updateResult['success'] === true);
    $updateAudit = db_fetch_one(
        "SELECT * FROM {$prefix}newui_audit_log
          WHERE category = 'config' AND activity = 'update' AND target_type = 'org_type_routing' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ruleId]
    );
    t("a 'config'/'update' audit row exists for the tier change", (bool) $updateAudit);

    // Immutability: attempting to change owning_org_id must be REJECTED
    // and must NOT fire a second update audit row.
    $priorUpdateAuditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}newui_audit_log WHERE category='config' AND activity='update' AND target_type='org_type_routing' AND target_id = ?",
        [(string) $ruleId]
    );
    $immutableAttempt = org_routing_rule_update($ruleId, ['owning_org_id' => $otherOrgId], $superUserId);
    t("attempting to change owning_org_id on update is REJECTED", $immutableAttempt['success'] === false);
    $afterUpdateAuditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}newui_audit_log WHERE category='config' AND activity='update' AND target_type='org_type_routing' AND target_id = ?",
        [(string) $ruleId]
    );
    t("a REJECTED immutability-violating update does NOT fire a spurious audit row", $afterUpdateAuditCount === $priorUpdateAuditCount);

    $deactivateResult = org_routing_rule_deactivate($ruleId, $superUserId);
    t("org_routing_rule_deactivate() succeeds", $deactivateResult['success'] === true);
    $deactivateAudit = db_fetch_one(
        "SELECT * FROM {$prefix}newui_audit_log
          WHERE category = 'config' AND activity = 'deactivate' AND target_type = 'org_type_routing' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ruleId]
    );
    t("a 'config'/'deactivate' audit row exists", (bool) $deactivateAudit);

    $ruleRow = db_fetch_one("SELECT `active`, `deactivated_at`, `deactivated_by` FROM {$prefix}org_type_routing WHERE id = ?", [$ruleId]);
    t("the rule is actually marked inactive in the database", $ruleRow && (int) $ruleRow['active'] === 0);
    t("deactivated_at was stamped", $ruleRow && !empty($ruleRow['deactivated_at']));
    t("deactivated_by records the caller", $ruleRow && (int) $ruleRow['deactivated_by'] === $superUserId);

    unset($_SESSION['user_id'], $_SESSION['user']);

    // ══════════════════════════════════════════════════════════════════
    // 2. share_created — via a REAL incident_create_internal() call
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- 'share_created' audit ('incident' category) ---\n\n";

    // A fresh, distinctly-identified rule (the CRUD section's rule above is
    // now deactivated and its identity is reserved — see the comment above).
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141AuditGroup2', 'view', 1)",
        [$ownerOrgId, $targetOrgId]
    );
    $liveRuleId = (int) db_insert_id();
    $createdRuleIds[] = $liveRuleId;

    $_SESSION['user_id'] = $viewUserId;
    $_SESSION['active_org_id'] = $ownerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $typeId2,
        'scope'       => 'zz141 audit share_created test',
    ], $viewUserId);
    unset($_SESSION['active_org_id']);

    t("fixture ticket created via the real writer", empty($createResult['errors']));
    $ticketId = (int) ($createResult['id'] ?? 0);
    $createdTicketIds[] = $ticketId;

    $shareAudit = db_fetch_one(
        "SELECT * FROM {$prefix}newui_audit_log
          WHERE category = 'incident' AND activity = 'share_created' AND target_type = 'ticket' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ticketId]
    );
    t("a 'incident'/'share_created' audit row exists", (bool) $shareAudit);
    if ($shareAudit) {
        $details = json_decode((string) $shareAudit['details'], true) ?: [];
        t("audit details carry the correct shared_with_org_id", (int) ($details['shared_with_org_id'] ?? 0) === $targetOrgId);
        t("audit details carry the correct routing_rule_id", (int) ($details['routing_rule_id'] ?? 0) === $liveRuleId);
        t("audit details carry the correct access_tier", ($details['access_tier'] ?? '') === 'view');
        t("audit severity is AUDIT_MEDIUM (3) per plan.md", (int) $shareAudit['severity'] === 3);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. view_shared — reproduces incident-detail.php's exact audit call
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- 'view_shared' audit ('incident' category) ---\n\n";

    // Reproduce incident-detail.php's post-gate logic exactly (verified
    // present in the real file by test_org_sharing_endpoint_wiring.php):
    //   $shareCtx = org_share_context_for_ticket($id);
    //   if ($shareCtx !== null) { ... audit_log('incident','view_shared',...) }
    $priorViewAuditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}newui_audit_log WHERE category='incident' AND activity='view_shared' AND target_type='ticket' AND target_id = ?",
        [(string) $ticketId]
    );

    $shareCtx = org_share_context_for_ticket($ticketId, $viewUserId);
    t("share-derived caller resolves a non-null share context", $shareCtx !== null);
    if ($shareCtx !== null) {
        $tier = $shareCtx['access_tier'];
        $sharedWithOrgId = (int) $shareCtx['shared_with_org_id'];
        audit_log(
            'incident', 'view_shared', 'ticket', $ticketId,
            "Incident #{$ticketId} opened via cross-org share (org #{$sharedWithOrgId}, {$tier} tier)",
            ['shared_with_org_id' => $sharedWithOrgId, 'access_tier' => $tier],
            AUDIT_INFO
        );
    }
    $afterViewAuditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}newui_audit_log WHERE category='incident' AND activity='view_shared' AND target_type='ticket' AND target_id = ?",
        [(string) $ticketId]
    );
    t("exactly one 'view_shared' audit row was fired for the share-derived read", $afterViewAuditCount === $priorViewAuditCount + 1);

    $viewAudit = db_fetch_one(
        "SELECT * FROM {$prefix}newui_audit_log
          WHERE category = 'incident' AND activity = 'view_shared' AND target_type = 'ticket' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ticketId]
    );
    t("severity is AUDIT_INFO (1), NOT AUDIT_MEDIUM — a read is not a state change, per plan.md", (bool) $viewAudit && (int) $viewAudit['severity'] === 1);

    // NEVER fires for same-org access — check against a user scoped to the
    // ticket's own owning org.
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (900002465, 3, ?, 'org', ?)", [$ownerOrgId, $ownerOrgId]);
    $sameOrgUserCtx = org_share_context_for_ticket($ticketId, 900002465);
    t("same-org access NEVER resolves a share context (view_shared audit would never fire for it)", $sameOrgUserCtx === null);
    db_query("DELETE FROM {$prefix}user_roles WHERE user_id = 900002465");

    // NEVER fires for Super Admin.
    $superCtx = org_share_context_for_ticket($ticketId, $superUserId);
    t("Super Admin access NEVER resolves a share context (view_shared audit would never fire for it)", $superCtx === null);

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id'], $_SESSION['user']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
