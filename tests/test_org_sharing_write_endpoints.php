<?php
/**
 * Phase 141 (2026-08-17) — Write-endpoint access-tier boundary.
 *
 * Reproduces the EXACT gate logic each write-shaped endpoint now runs
 * (api/incident-update.php, api/incident-assign.php, external API's
 * PATCH and DELETE sites) against a REAL ticket created via
 * incident_create_internal() with REAL org_type_routing rules —
 * confirming, per tasks.md section 10's hard-stop assertion:
 *
 *   - a view-tier shared-with-org caller is REFUSED at every write gate
 *     (403, the exact plan.md denial message / ext_api 'forbidden')
 *   - an assist-tier shared-with-org caller IS allowed through the
 *     tier-aware gate (matches what a same-org dispatcher gets)
 *   - same-org access is completely unaffected
 *   - NO tier, at ANY access level — including assist — can pass the
 *     DELETE-only gate (org_ticket_is_owned_by_caller()) unless the
 *     caller's own org actually owns the ticket
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_write_endpoints.php
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

echo "=== Phase 141 — Write-endpoint access-tier boundary (real fixtures) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$ownerOrgId  = 900002360;
$viewOrgId   = 900002361;
$assistOrgId = 900002362;
$viewUserId  = 900002364;
$assistUserId = 900002365;
$sameOrgUserId = 900002366; // scoped to ownerOrgId itself

$createdOrgIds = [];
$createdTicketIds = [];
$createdRuleIds = [];
$createdTypeIds = [];

$cleanup = function () use (
    $prefix, &$createdOrgIds, &$createdTicketIds, &$createdRuleIds, &$createdTypeIds,
    $viewUserId, $assistUserId, $sameOrgUserId
) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdRuleIds as $id)   { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id)   { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id)    { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?)", [$viewUserId, $assistUserId, $sameOrgUserId]); } catch (Throwable $e) {}
};
$cleanup();

/**
 * Reproduces api/incident-update.php's and api/incident-assign.php's
 * IDENTICAL post-Phase-141 gate:
 *   if (!org_can_mutate_ticket($id)) {
 *       if (org_can_see_ticket($id)) -> 403 "...does not permit..."
 *       else                          -> 404 "Ticket not found"
 *   }
 * Returns the HTTP status the real endpoint would send, or 200 (proceed)
 * when the gate passes.
 */
function _reproduce_mutate_gate(int $ticketId, int $userId): int {
    if (!org_can_mutate_ticket($ticketId, $userId)) {
        if (org_can_see_ticket($ticketId, $userId)) return 403;
        return 404;
    }
    return 200;
}

/**
 * Reproduces api/external/v1/incidents.php's PATCH site (Site 3):
 * same shape, ext_api_error('forbidden', 403) / ext_api_error('not_found', 404).
 */
function _reproduce_ext_patch_gate(int $ticketId, int $userId): int {
    return _reproduce_mutate_gate($ticketId, $userId); // identical logic, same 403/404 mapping
}

/**
 * Reproduces api/external/v1/incidents.php's DELETE site (Site 4):
 *   if (!org_ticket_is_owned_by_caller($id)) -> 404 'not_found'
 * NO tier ever satisfies this — deliberately not org_can_mutate_ticket().
 */
function _reproduce_ext_delete_gate(int $ticketId, int $userId): int {
    return org_ticket_is_owned_by_caller($ticketId, $userId) ? 200 : 404;
}

try {
    foreach ([$ownerOrgId => 'ZZ141WE Owner', $viewOrgId => 'ZZ141WE ViewOrg', $assistOrgId => 'ZZ141WE AssistOrg'] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
        $createdOrgIds[] = $id;
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$viewUserId, $viewOrgId, $viewOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$assistUserId, $assistOrgId, $assistOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$sameOrgUserId, $ownerOrgId, $ownerOrgId]);

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 write-endpoints type', 'ZZ141WEGroup')", ['zz141we-' . uniqid()]);
    $typeId = (int) db_insert_id();
    $createdTypeIds[] = $typeId;

    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141WEGroup', 'view', 1)",
        [$ownerOrgId, $viewOrgId]
    );
    $createdRuleIds[] = (int) db_insert_id();
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141WEGroup', 'assist', 1)",
        [$ownerOrgId, $assistOrgId]
    );
    $createdRuleIds[] = (int) db_insert_id();

    $_SESSION['user_id'] = $sameOrgUserId;
    $_SESSION['active_org_id'] = $ownerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => 'zz141 write-endpoints routed incident',
    ], $sameOrgUserId);
    unset($_SESSION['active_org_id'], $_SESSION['user_id']);

    t("fixture ticket created via the real writer", empty($createResult['errors']));
    $ticketId = (int) ($createResult['id'] ?? 0);
    $createdTicketIds[] = $ticketId;
    t("fixture ticket has a real id", $ticketId > 0);

    $shareCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}incident_shares WHERE ticket_id = ?", [$ticketId]);
    t("the real writer produced exactly 2 real incident_shares rows", $shareCount === 2);

    // ══════════════════════════════════════════════════════════════════
    // incident-update.php / incident-assign.php (identical gate shape)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- incident-update.php / incident-assign.php gate ---\n\n";

    t("same-org caller: gate passes (200)", _reproduce_mutate_gate($ticketId, $sameOrgUserId) === 200);
    t("view-tier shared-with-org caller: REFUSED with 403 (not 404 — they already have confirmed read visibility)", _reproduce_mutate_gate($ticketId, $viewUserId) === 403);
    t("assist-tier shared-with-org caller: gate passes (200) — same actions a same-org dispatcher gets", _reproduce_mutate_gate($ticketId, $assistUserId) === 200);

    // ══════════════════════════════════════════════════════════════════
    // External API PATCH (Site 3)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- external API PATCH (Site 3) gate ---\n\n";

    t("same-org caller: gate passes (200)", _reproduce_ext_patch_gate($ticketId, $sameOrgUserId) === 200);
    t("view-tier caller: 403 forbidden", _reproduce_ext_patch_gate($ticketId, $viewUserId) === 403);
    t("assist-tier caller: gate passes (200)", _reproduce_ext_patch_gate($ticketId, $assistUserId) === 200);

    // ══════════════════════════════════════════════════════════════════
    // External API DELETE (Site 4) — the ONE hard-stop assertion
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- external API DELETE (Site 4) gate — the hard-stop assertion ---\n\n";

    t("same-org (owning) caller: DELETE gate passes (200) — they actually own the ticket", _reproduce_ext_delete_gate($ticketId, $sameOrgUserId) === 200);
    t("view-tier caller: DELETE refused (404) — no share, at any tier, satisfies ownership", _reproduce_ext_delete_gate($ticketId, $viewUserId) === 404);
    t("assist-tier caller: DELETE STILL refused (404) — assist tier can mutate fields/notes but NEVER delete", _reproduce_ext_delete_gate($ticketId, $assistUserId) === 404);

    // Prove this is not merely a coincidence of which function was called
    // by hand-verifying the assist-tier caller genuinely CAN mutate but
    // genuinely CANNOT own:
    t("assist-tier caller: org_can_mutate_ticket() is true", org_can_mutate_ticket($ticketId, $assistUserId));
    t("assist-tier caller: org_ticket_is_owned_by_caller() is STILL false (the whole point of the DELETE carve-out)", !org_ticket_is_owned_by_caller($ticketId, $assistUserId));

    // Escalate the view-tier org's share to assist and re-confirm DELETE
    // is STILL refused — proves the carve-out is tier-independent, not
    // merely "view tier hasn't been granted yet".
    db_query("UPDATE {$prefix}incident_shares SET access_tier = 'assist' WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $viewOrgId]);
    t("even AFTER hand-escalating the view-tier org's share to assist, DELETE is STILL refused (404) — tier-independent by construction", _reproduce_ext_delete_gate($ticketId, $viewUserId) === 404);
    db_query("UPDATE {$prefix}incident_shares SET access_tier = 'view' WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $viewOrgId]);

    // ══════════════════════════════════════════════════════════════════
    // dispositions-picker.php — read-only, view tier IS sufficient
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- dispositions-picker.php — view tier is sufficient (read-only) ---\n\n";

    t("view-tier caller: org_can_see_ticket() (the ONLY gate this endpoint uses) passes", org_can_see_ticket($ticketId, $viewUserId));

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
