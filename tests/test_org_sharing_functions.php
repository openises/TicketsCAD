<?php
/**
 * Phase 141 (2026-08-17) — Core functions against live fixtures.
 *
 * Drives every Phase-141 core function through REAL database fixtures
 * (real organizations, a real user_roles grant, a real ticket, real
 * incident_shares rows, and a real incident_create_internal() call) --
 * never hand-simulated state -- per this project's standing
 * "reproduce_via_real_writer" discipline.
 *
 * Covers:
 *   - org_can_see_ticket() Phase 141 extension (same-org, genuinely
 *     invisible, share-visible at both tiers, revoked share excluded)
 *   - org_ticket_query_filter() (Super Admin unrestricted, org-scoped
 *     caller sees own-org + shared tickets, excludes unrelated tickets)
 *   - org_can_mutate_ticket() (same-org always; assist-tier share allows;
 *     view-tier share refuses; no share refuses)
 *   - org_ticket_is_owned_by_caller() (true ONLY for genuine ownership,
 *     false even under an assist-tier share -- the DELETE-carve-out gate)
 *   - org_share_context_for_ticket() (null for same-org/Super Admin, the
 *     winning share row for a share-derived caller)
 *   - org_share_redact_ticket_fields() (assist unchanged; view allowlist)
 *   - org_sharing_apply_list_redaction() (batched, same-org rows
 *     untouched, shared rows redacted + annotated)
 *   - org_sharing_apply_routing_on_create() wired into
 *     incident_create_internal(): a real incident creation through an
 *     active routing rule produces a real incident_shares row and a
 *     share_created audit_log entry
 *   - org_routing_rule_validate() (self-route rejected, missing org
 *     rejected, out-of-visible-scope owning org rejected for a
 *     non-Super-Admin caller, mismatched match_scope/match_group/
 *     match_in_type_id combinations rejected, unknown group rejected)
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_functions.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/incident-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Core functions against live fixtures ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$hasSharesTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'incident_shares']
);
if (!$hasSharesTable) {
    echo "\nSKIP: incident_shares table not present -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// ── Fixture setup ──────────────────────────────────────────────────────
$ownerOrgId  = 900002160;
$targetOrgId = 900002161;
$strangerOrgId = 900002162; // an org with NO share -- genuinely invisible
$testUserId  = 900002163;   // fake user id, scoped to targetOrgId only
$superUserId = 900002164;   // fake user id with a global role

$createdOrgIds = [];
$createdTicketIds = [];
$createdShareIds = [];
$createdRuleIds = [];
$createdTypeIds = [];

$cleanup = function () use (
    $prefix, &$createdOrgIds, &$createdTicketIds, &$createdShareIds, &$createdRuleIds, &$createdTypeIds,
    $testUserId, $superUserId
) {
    foreach ($createdShareIds as $id)  { try { db_query("DELETE FROM {$prefix}incident_shares WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdRuleIds as $id)   { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id)   { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id)    { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$testUserId, $superUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}incident_shares WHERE owning_org_id = 900002160"); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}org_type_routing WHERE owning_org_id = 900002160"); } catch (Throwable $e) {}
};
$cleanup();

try {
    // Real organizations (explicit ids so they don't collide with fixtures
    // used by other test files' own arbitrary-id conventions).
    foreach ([$ownerOrgId => 'ZZ141 Owner Org', $targetOrgId => 'ZZ141 Target Org', $strangerOrgId => 'ZZ141 Stranger Org'] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
        $createdOrgIds[] = $id;
    }

    // A real user_roles grant scoping $testUserId to ONLY targetOrgId
    // (role_id=3 Dispatcher, an org-scoped grant -- exercises the exact
    // shape org_visible_ids() reads).
    db_query(
        "INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)",
        [$testUserId, $targetOrgId, $targetOrgId]
    );
    // A real global-scope grant for $superUserId (Super Admin, role_id=1).
    db_query(
        "INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 1, NULL, 'global', NULL)",
        [$superUserId]
    );

    // A real ticket owned by ownerOrgId.
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket
            (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 ZZ141 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz141 core fn test', 'zz141 core fn test', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $sharedTicketId = (int) db_insert_id();
    $createdTicketIds[] = $sharedTicketId;

    // A second ticket owned by the SAME owner org, but with NO share row
    // to targetOrgId at all -- must stay genuinely invisible to testUserId.
    db_query(
        "INSERT INTO {$prefix}ticket
            (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '2 ZZ141 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz141 unshared test', 'zz141 unshared test', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $unsharedTicketId = (int) db_insert_id();
    $createdTicketIds[] = $unsharedTicketId;

    // A third ticket owned by targetOrgId itself (same-org for testUserId).
    db_query(
        "INSERT INTO {$prefix}ticket
            (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '3 ZZ141 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz141 same-org test', 'zz141 same-org test', 2, 1, NOW(), ?)",
        [$now, $targetOrgId]
    );
    $sameOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $sameOrgTicketId;

    // A VIEW-tier share of $sharedTicketId to targetOrgId.
    db_query(
        "INSERT INTO {$prefix}incident_shares (ticket_id, shared_with_org_id, owning_org_id, access_tier) VALUES (?, ?, ?, 'view')",
        [$sharedTicketId, $targetOrgId, $ownerOrgId]
    );
    $viewShareId = (int) db_insert_id();
    $createdShareIds[] = $viewShareId;

    // ══════════════════════════════════════════════════════════════════
    // org_can_see_ticket() extension
    // ══════════════════════════════════════════════════════════════════
    echo "--- org_can_see_ticket() Phase 141 extension ---\n\n";

    t("same-org ticket is visible", org_can_see_ticket($sameOrgTicketId, $testUserId));
    t("cross-org ticket with NO share is genuinely invisible", !org_can_see_ticket($unsharedTicketId, $testUserId));
    t("cross-org ticket WITH an active view-tier share is visible", org_can_see_ticket($sharedTicketId, $testUserId));
    t("Super Admin sees everything regardless of share state", org_can_see_ticket($unsharedTicketId, $superUserId));

    // Revoke the share -- must stop being visible.
    db_query("UPDATE {$prefix}incident_shares SET revoked_at = NOW() WHERE id = ?", [$viewShareId]);
    t("a REVOKED share no longer grants visibility", !org_can_see_ticket($sharedTicketId, $testUserId));
    db_query("UPDATE {$prefix}incident_shares SET revoked_at = NULL WHERE id = ?", [$viewShareId]);
    t("un-revoking restores visibility (sanity check on the fixture toggle itself)", org_can_see_ticket($sharedTicketId, $testUserId));

    // ══════════════════════════════════════════════════════════════════
    // org_ticket_query_filter()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_ticket_query_filter() ---\n\n";

    [$fragSuper, $varsSuper] = org_ticket_query_filter($superUserId);
    t("Super Admin gets an unrestricted filter (empty fragment, empty vars)", $fragSuper === '' && $varsSuper === []);

    [$frag, $vars] = org_ticket_query_filter($testUserId);
    t("org-scoped caller's filter fragment starts with ' AND ('", strpos($frag, ' AND (') === 0);
    $rows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$frag}", $vars);
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    t("filtered query includes the same-org ticket", in_array($sameOrgTicketId, $ids, true));
    t("filtered query includes the SHARED cross-org ticket", in_array($sharedTicketId, $ids, true));
    t("filtered query EXCLUDES the unshared cross-org ticket", !in_array($unsharedTicketId, $ids, true));

    // Revoke again -- the query result must lose the ticket too, not just org_can_see_ticket().
    db_query("UPDATE {$prefix}incident_shares SET revoked_at = NOW() WHERE id = ?", [$viewShareId]);
    $rowsRevoked = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$frag}", $vars);
    $idsRevoked = array_map(fn($r) => (int) $r['id'], $rowsRevoked);
    t("after revocation, the query filter also excludes the (now-revoked) shared ticket", !in_array($sharedTicketId, $idsRevoked, true));
    db_query("UPDATE {$prefix}incident_shares SET revoked_at = NULL WHERE id = ?", [$viewShareId]);

    // ══════════════════════════════════════════════════════════════════
    // org_can_mutate_ticket()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_can_mutate_ticket() ---\n\n";

    t("same-org ticket is always mutable", org_can_mutate_ticket($sameOrgTicketId, $testUserId));
    t("cross-org ticket with a VIEW-tier share is NOT mutable", !org_can_mutate_ticket($sharedTicketId, $testUserId));
    t("cross-org ticket with NO share is NOT mutable", !org_can_mutate_ticket($unsharedTicketId, $testUserId));

    db_query("UPDATE {$prefix}incident_shares SET access_tier = 'assist' WHERE id = ?", [$viewShareId]);
    t("cross-org ticket with an ASSIST-tier share IS mutable", org_can_mutate_ticket($sharedTicketId, $testUserId));
    db_query("UPDATE {$prefix}incident_shares SET access_tier = 'view' WHERE id = ?", [$viewShareId]);
    t("reverting to view tier makes it non-mutable again (sanity check)", !org_can_mutate_ticket($sharedTicketId, $testUserId));

    t("Super Admin can always mutate", org_can_mutate_ticket($unsharedTicketId, $superUserId));

    // ══════════════════════════════════════════════════════════════════
    // org_ticket_is_owned_by_caller() — the DELETE carve-out gate
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_ticket_is_owned_by_caller() ---\n\n";

    t("same-org ticket is owned by caller", org_ticket_is_owned_by_caller($sameOrgTicketId, $testUserId));
    t("cross-org ticket with a VIEW-tier share is NOT owned by caller", !org_ticket_is_owned_by_caller($sharedTicketId, $testUserId));

    db_query("UPDATE {$prefix}incident_shares SET access_tier = 'assist' WHERE id = ?", [$viewShareId]);
    t("cross-org ticket with an ASSIST-tier share is STILL NOT owned by caller (the whole point of this gate)", !org_ticket_is_owned_by_caller($sharedTicketId, $testUserId));
    db_query("UPDATE {$prefix}incident_shares SET access_tier = 'view' WHERE id = ?", [$viewShareId]);

    t("Super Admin is always treated as owning", org_ticket_is_owned_by_caller($unsharedTicketId, $superUserId));

    // ══════════════════════════════════════════════════════════════════
    // org_share_context_for_ticket()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_share_context_for_ticket() ---\n\n";

    t("same-org access returns null (not share-derived)", org_share_context_for_ticket($sameOrgTicketId, $testUserId) === null);
    t("Super Admin always returns null", org_share_context_for_ticket($unsharedTicketId, $superUserId) === null);

    $ctx = org_share_context_for_ticket($sharedTicketId, $testUserId);
    t("share-derived access returns a non-null context", $ctx !== null);
    t("context reports the correct shared_with_org_id", ($ctx['shared_with_org_id'] ?? null) === $targetOrgId);
    t("context reports the correct access_tier", ($ctx['access_tier'] ?? null) === 'view');

    // ══════════════════════════════════════════════════════════════════
    // org_share_redact_ticket_fields()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_share_redact_ticket_fields() ---\n\n";

    $fullRow = [
        'id' => $sharedTicketId, 'scope' => 'zz141 core fn test', 'street' => '1 ZZ141 Way',
        'contact' => 'Jane Caller', 'phone' => '555-1212', 'nine_one_one' => 'callback ok',
        'description' => 'free text narrative', 'comments' => 'more narrative',
        'org_id' => $ownerOrgId, 'severity' => 1, 'status' => 2,
    ];
    $assistResult = org_share_redact_ticket_fields($fullRow, 'assist');
    t("assist tier returns the row BYTE-IDENTICAL to the input", $assistResult === $fullRow);

    $viewResult = org_share_redact_ticket_fields($fullRow, 'view');
    t("view tier includes 'id'", array_key_exists('id', $viewResult));
    t("view tier includes 'scope'", array_key_exists('scope', $viewResult));
    t("view tier includes 'street'", array_key_exists('street', $viewResult));
    t("view tier includes 'org_id'", array_key_exists('org_id', $viewResult));
    t("view tier includes 'severity'", array_key_exists('severity', $viewResult));
    t("view tier includes 'status'", array_key_exists('status', $viewResult));
    t("view tier EXCLUDES 'contact' (caller PII)", !array_key_exists('contact', $viewResult));
    t("view tier EXCLUDES 'phone' (caller PII)", !array_key_exists('phone', $viewResult));
    t("view tier EXCLUDES 'nine_one_one' (caller PII)", !array_key_exists('nine_one_one', $viewResult));
    t("view tier EXCLUDES 'description' (free-text narrative)", !array_key_exists('description', $viewResult));
    t("view tier EXCLUDES 'comments' (free-text narrative)", !array_key_exists('comments', $viewResult));
    t("view tier's excluded values are genuinely ABSENT, not just null-valued", !in_array('Jane Caller', $viewResult, true));

    // ══════════════════════════════════════════════════════════════════
    // org_sharing_apply_list_redaction()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_sharing_apply_list_redaction() ---\n\n";

    $rowsIn = [
        ['id' => $sameOrgTicketId, 'org_id' => $targetOrgId, 'scope' => 'same-org scope', 'contact' => 'Same-Org Caller'],
        ['id' => $sharedTicketId,  'org_id' => $ownerOrgId,  'scope' => 'shared scope',    'contact' => 'Shared Caller'],
    ];
    $rowsOut = org_sharing_apply_list_redaction($rowsIn, $testUserId);
    $bySameOrgId = null; $bySharedId = null;
    foreach ($rowsOut as $r) {
        if ($r['id'] === $sameOrgTicketId) $bySameOrgId = $r;
        if ($r['id'] === $sharedTicketId)  $bySharedId  = $r;
    }
    t("same-org row is unchanged (no shared_from_* keys)", $bySameOrgId !== null && !array_key_exists('shared_from_org_id', $bySameOrgId));
    t("same-org row keeps 'contact' unredacted (it's the caller's own org)", ($bySameOrgId['contact'] ?? null) === 'Same-Org Caller');
    t("shared row gets shared_from_org_id = owning org", $bySharedId !== null && ($bySharedId['shared_from_org_id'] ?? null) === $ownerOrgId);
    t("shared row gets shared_from_org_name resolved", ($bySharedId['shared_from_org_name'] ?? null) === 'ZZ141 Owner Org');
    t("shared row's 'contact' is redacted away (view tier)", !array_key_exists('contact', $bySharedId ?? []));
    t("shared row keeps 'scope' (dispatch-relevant, view tier)", array_key_exists('scope', $bySharedId ?? []));

    $superRowsOut = org_sharing_apply_list_redaction($rowsIn, $superUserId);
    t("Super Admin's rows pass through completely unchanged", $superRowsOut === $rowsIn);

    // ══════════════════════════════════════════════════════════════════
    // org_sharing_apply_routing_on_create() wired into a REAL incident
    // creation via incident_create_internal() -- not a hand-inserted share
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_sharing_apply_routing_on_create() via real incident_create_internal() ---\n\n";

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 routed type', 'ZZ141RouteGroup')", ['zz141route-' . uniqid()]);
    $routedTypeId = (int) db_insert_id();
    $createdTypeIds[] = $routedTypeId;

    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141RouteGroup', 'view', 1)",
        [$ownerOrgId, $targetOrgId]
    );
    $liveRuleId = (int) db_insert_id();
    $createdRuleIds[] = $liveRuleId;

    $priorAuditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}newui_audit_log WHERE category='incident' AND activity='share_created'"
    );

    $_SESSION['user_id'] = $testUserId; // incident_create_internal doesn't require this, but keep the writer's environment realistic
    $_SESSION['active_org_id'] = $ownerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $routedTypeId,
        'scope' => 'zz141 real incident creation routing test',
    ], $testUserId);
    unset($_SESSION['active_org_id']);

    t("incident_create_internal() succeeded (no errors)", empty($createResult['errors']));
    $newTicketId = (int) ($createResult['id'] ?? 0);
    if ($newTicketId > 0) $createdTicketIds[] = $newTicketId;
    t("a real ticket id was returned", $newTicketId > 0);

    $realShareRow = db_fetch_one(
        "SELECT * FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?",
        [$newTicketId, $targetOrgId]
    );
    if ($realShareRow) $createdShareIds[] = (int) $realShareRow['id'];
    t("a REAL incident_shares row was created by the actual writer (not hand-inserted)", (bool) $realShareRow);
    t("the real share row's routing_rule_id matches the live rule", $realShareRow && (int) $realShareRow['routing_rule_id'] === $liveRuleId);
    t("the real share row's access_tier matches the rule's tier", $realShareRow && $realShareRow['access_tier'] === 'view');
    t("the real share row's owning_org_id snapshot matches the ticket's actual org", $realShareRow && (int) $realShareRow['owning_org_id'] === $ownerOrgId);

    $newAuditCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}newui_audit_log WHERE category='incident' AND activity='share_created'"
    );
    t("a share_created audit_log entry was fired by the real creation path", $newAuditCount > $priorAuditCount);

    // Now prove org_can_see_ticket() picks up this REAL share for real:
    t("the new routed ticket is now visible to testUserId via org_can_see_ticket()", org_can_see_ticket($newTicketId, $testUserId));

    // ══════════════════════════════════════════════════════════════════
    // org_routing_rule_validate()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- org_routing_rule_validate() ---\n\n";

    $vSelfRoute = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $ownerOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141RouteGroup', 'access_tier' => 'view',
    ], $superUserId);
    t("a rule routing an org to ITSELF is rejected", !$vSelfRoute['valid']);

    $vMissingOwner = org_routing_rule_validate([
        'owning_org_id' => 0, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141RouteGroup', 'access_tier' => 'view',
    ], $superUserId);
    t("a rule with no owning_org_id is rejected", !$vMissingOwner['valid']);

    $vGoodSuper = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141RouteGroup', 'access_tier' => 'view',
    ], $superUserId);
    t("a well-formed rule validates for a Super Admin caller", $vGoodSuper['valid'] && $vGoodSuper['errors'] === []);

    // The design-synthesis guardrail: testUserId's org_visible_ids() is
    // ONLY targetOrgId -- naming ownerOrgId (which they don't belong to)
    // as the OWNING org must be rejected.
    $vOutOfScope = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $strangerOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141RouteGroup', 'access_tier' => 'view',
    ], $testUserId);
    t("a non-Super-Admin caller cannot name an owning_org_id outside their own org_visible_ids() (design-synthesis guardrail)", !$vOutOfScope['valid']);

    $vInScope = org_routing_rule_validate([
        'owning_org_id' => $targetOrgId, 'shared_with_org_id' => $strangerOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141RouteGroup', 'access_tier' => 'view',
    ], $testUserId);
    t("the SAME caller CAN name their own org as owning_org_id", $vInScope['valid']);

    $vBadScope = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'bogus', 'access_tier' => 'view',
    ], $superUserId);
    t("an invalid match_scope value is rejected", !$vBadScope['valid']);

    $vTypeWithGroup = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'type', 'match_in_type_id' => $routedTypeId, 'match_group' => 'ZZ141RouteGroup',
        'access_tier' => 'view',
    ], $superUserId);
    t("match_scope=type with match_group ALSO set is rejected (mutually exclusive)", !$vTypeWithGroup['valid']);

    $vGroupUnknown = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141NoSuchGroupExists', 'access_tier' => 'view',
    ], $superUserId);
    t("a match_group that matches NO existing in_types.group is rejected (not a silently-inert rule)", !$vGroupUnknown['valid']);

    $vBadTier = org_routing_rule_validate([
        'owning_org_id' => $ownerOrgId, 'shared_with_org_id' => $targetOrgId,
        'match_scope' => 'group', 'match_group' => 'ZZ141RouteGroup', 'access_tier' => 'full',
    ], $superUserId);
    t("access_tier='full' is rejected ('full' does not exist in Phase 1)", !$vBadTier['valid']);

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
