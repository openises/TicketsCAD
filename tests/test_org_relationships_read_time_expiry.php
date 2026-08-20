<?php
/**
 * Phase 143 (2026-08-17) — THE read-time expiry proof, no sweep job ever
 * invoked.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THIS IS THE SINGLE MOST IMPORTANT TEST IN THIS ENTIRE PHASE. It is the
 * mechanism spec.md names as its own success criterion, and it is the
 * THIRD test of whether this project's PAR-scheduler / pending-message-
 * sweep lesson (CLAUDE.md, 2026-07-29 -- "an on/off switch gates behaviour;
 * cleanup that closes out work nobody can answer runs either way") actually
 * stuck. A failure here is a hard stop, not a test to adjust.
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Drives the exact scenario spec.md/plan.md describe, end to end, through
 * REAL writes (never hand-inserted rows for the relationship/membership
 * side):
 *   1. Create an approved, 'active', requires_activation=1 relationship
 *      between two test orgs via org_relationship_create_or_propose() +
 *      org_relationship_member_approve() -- the real two-party consent
 *      writers.
 *   2. Create a ticket under one org (via incident_create_internal()).
 *   3. Activate the relationship via org_relationship_activate() with a
 *      SHORT max_activation_minutes, then BACKDATE activated_at directly
 *      to simulate elapsed time (avoids sleeping the test runner).
 *   4. BEFORE expiry: confirm org_can_see_ticket(), org_ticket_query_filter()
 *      (against a real fixture query), and org_can_mutate_ticket() (for an
 *      assist-tier relationship) all report the OTHER org has
 *      visibility/access.
 *   5. Do NOT call tools/org_relationship_cleanup_tick.php. Do NOT call any
 *      function that writes deactivated_at. Do NOT invoke the scheduled
 *      job in any form.
 *   6. Re-check the exact same three functions. Assert visibility and
 *      mutation access are BOTH gone -- while the activation row's own
 *      deactivated_at is STILL NULL in the database at this point (asserted
 *      explicitly, so the test cannot pass by accidentally triggering the
 *      very cleanup path it exists to prove is unnecessary).
 *   7. Deactivate explicitly (org_relationship_deactivate()) and re-confirm
 *      the same three functions report no access -- the explicit-
 *      deactivation path and the pure-elapsed-time path are both tested,
 *      separately, neither standing in for the other.
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_read_time_expiry.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-relationships.php';
require_once __DIR__ . '/../inc/incident-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — THE read-time expiry proof (no sweep job ever invoked) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$ownerOrgId = 900005300; // owns the ticket
$otherOrgId = 900005301; // gains visibility via the relationship

$ownerUserId = 900005310;
$otherUserId = 900005311; // Org otherOrgId's user -- the one whose access we're proving

$createdOrgIds = [$ownerOrgId, $otherOrgId];
$createdTicketIds = [];
$createdRelIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, &$createdRelIds, $ownerUserId, $otherUserId) {
    foreach ($createdRelIds as $id) {
        try { db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$ownerUserId, $otherUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, 'ZZ143RTE Owner Org']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$otherOrgId, 'ZZ143RTE Other Org']);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$otherUserId, $otherOrgId, $otherOrgId]);

    // ══════════════════════════════════════════════════════════════════
    // Step 1 — approved, active, requires_activation=1, assist-tier
    // relationship (assist tier so org_can_mutate_ticket() has something
    // real to prove too), via the REAL two-party consent writers.
    // ══════════════════════════════════════════════════════════════════
    echo "--- Step 1: relationship created + approved via real writers ---\n\n";

    $create = org_relationship_create_or_propose(
        [
            'name' => 'ZZ143RTE Standing Relationship',
            'member_org_ids' => [$ownerOrgId, $otherOrgId],
            'access_tier' => 'assist',
            'redaction_profile' => 'view',
            'requires_activation' => 1,
            'max_activation_minutes' => 60, // relationship-level ceiling; per-activation duration set at activation time
        ],
        true, $ownerUserId, 'ZZ Owner User' // global caller -- auto-approves both, so we assert the two-party mechanism at the RIGHT layer (this test's job is expiry, not consent -- that's test_org_relationships_consent.php's job)
    );
    t('relationship created', $create['success'] === true);
    $relId = (int) ($create['id'] ?? 0);
    if ($relId > 0) $createdRelIds[] = $relId;
    t("relationship status is 'active' (both members auto-approved)", $create['status'] === 'active');

    // ══════════════════════════════════════════════════════════════════
    // Step 2 — a real ticket, owned by $ownerOrgId, via the real writer.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Step 2: ticket created under the owning org ---\n\n";

    $_SESSION['user_id'] = $ownerUserId;
    $_SESSION['active_org_id'] = $ownerOrgId;
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES ('ZZ143RTE Type', 'zz143rte fixture type', NULL)");
    $inTypeId = (int) db_insert_id();
    $createResult = incident_create_internal([
        'in_types_id' => $inTypeId,
        'scope' => 'zz143rte fixture ticket',
    ], $ownerUserId);
    unset($_SESSION['active_org_id']);
    $ticketId = (int) ($createResult['id'] ?? 0);
    if ($ticketId > 0) $createdTicketIds[] = $ticketId;
    t('ticket created, owned by the owning org', $ticketId > 0);

    $ticketOrgId = db_fetch_value("SELECT org_id FROM {$prefix}ticket WHERE id = ?", [$ticketId]);
    t("ticket's own org_id is the owning org", (int) $ticketOrgId === $ownerOrgId);

    // ══════════════════════════════════════════════════════════════════
    // Before ANY activation exists: requires_activation=1 means standing
    // consent alone is NOT visibility (spec.md's own explicit distinction).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Before activation: standing consent alone grants NOTHING (requires_activation=1) ---\n\n";

    t('BEFORE activation: the other org CANNOT see the ticket', !org_can_see_ticket($ticketId, $otherUserId));

    // ══════════════════════════════════════════════════════════════════
    // Step 3 — activate with a SHORT duration, then BACKDATE activated_at
    // to simulate elapsed time (no sleeping the test runner).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Step 3: activate with a short duration, then simulate elapsed time ---\n\n";

    $activate = org_relationship_activate($relId, true, $otherUserId, 'ZZ Other User', 'ZZ143RTE drill', 1);
    t('activation succeeds', $activate['success'] === true);
    $activationId = (int) ($activate['id'] ?? 0);
    t('activation returns an id', $activationId > 0);

    $activationRow = db_fetch_one("SELECT activated_at, max_activation_minutes, deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('activation max_activation_minutes is 1 (the requested duration, within the relationship ceiling)', (int) $activationRow['max_activation_minutes'] === 1);
    t('activation deactivated_at is NULL immediately after activating', $activationRow['deactivated_at'] === null);

    // ══════════════════════════════════════════════════════════════════
    // Step 4 — BEFORE expiry: all three functions report access.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Step 4: BEFORE expiry -- visibility and mutation access are GRANTED ---\n\n";

    t('BEFORE expiry: org_can_see_ticket() reports the other org CAN see the ticket', org_can_see_ticket($ticketId, $otherUserId));

    [$frag, $vars] = org_ticket_query_filter($otherUserId, 't');
    $rowsBefore = db_fetch_all("SELECT id FROM {$prefix}ticket t WHERE 1=1 {$frag} AND t.id = ?", array_merge($vars, [$ticketId]));
    t('BEFORE expiry: org_ticket_query_filter() includes the ticket in the other org\'s query results', count($rowsBefore) === 1);

    t('BEFORE expiry: org_can_mutate_ticket() reports the other org CAN mutate the ticket (assist tier)', org_can_mutate_ticket($ticketId, $otherUserId));

    // ══════════════════════════════════════════════════════════════════
    // Simulate elapsed time: backdate activated_at to 2 minutes ago against
    // a 1-minute window -- the window has genuinely elapsed by wall clock.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Simulating elapsed time (backdating activated_at -- NOT sleeping the runner) ---\n\n";

    db_query(
        "UPDATE {$prefix}org_relationships_activations SET activated_at = NOW() - INTERVAL 2 MINUTE WHERE id = ?",
        [$activationId]
    );
    $backdated = db_fetch_one("SELECT activated_at, deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('activated_at is now backdated to the past', strtotime($backdated['activated_at']) < time() - 60);
    t('deactivated_at is STILL NULL after backdating (nothing has closed this row yet)', $backdated['deactivated_at'] === null);

    // ══════════════════════════════════════════════════════════════════
    // Step 5 — THE critical non-action. Do NOT call the cleanup tick. Do
    // NOT call org_relationship_deactivate(). Do NOT touch deactivated_at
    // in any way. Nothing below this comment writes to
    // org_relationships_activations until the EXPLICIT-deactivation part
    // further down, which is deliberately AFTER this section's assertions.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Step 5/6: AFTER elapsed time, with the cleanup job NEVER invoked -- access is GONE ---\n\n";

    $preCheckDeactivatedAt = db_fetch_value("SELECT deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('deactivated_at is STILL NULL right before the post-expiry assertions (the read-time predicate, not a write, is what will revoke access)', $preCheckDeactivatedAt === null);

    t('AFTER elapsed time (no sweep run): org_can_see_ticket() reports the other org can NO LONGER see the ticket', !org_can_see_ticket($ticketId, $otherUserId));

    [$fragAfter, $varsAfter] = org_ticket_query_filter($otherUserId, 't');
    $rowsAfter = db_fetch_all("SELECT id FROM {$prefix}ticket t WHERE 1=1 {$fragAfter} AND t.id = ?", array_merge($varsAfter, [$ticketId]));
    t('AFTER elapsed time (no sweep run): org_ticket_query_filter() no longer includes the ticket', count($rowsAfter) === 0);

    t('AFTER elapsed time (no sweep run): org_can_mutate_ticket() reports the other org can NO LONGER mutate the ticket', !org_can_mutate_ticket($ticketId, $otherUserId));

    $postCheckDeactivatedAt = db_fetch_value("SELECT deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('deactivated_at is STILL NULL after the post-expiry assertions -- access was already gone via the READ-TIME predicate, not a write anyone made', $postCheckDeactivatedAt === null);

    // ══════════════════════════════════════════════════════════════════
    // Step 7 — the EXPLICIT-deactivation path, tested separately. Create a
    // FRESH activation (short-lived, not yet expired) specifically to
    // prove the manual-deactivate path also works -- this must not be the
    // same activation the elapsed-time assertions above already used, so
    // that neither path's proof depends on the other having already run.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Step 7: the EXPLICIT-deactivation path, tested separately ---\n\n";

    // Close out the expired-but-not-yet-closed activation manually so a
    // fresh one can be opened (uk_org_rel_activation_live allows only one
    // LIVE row per relationship at a time) -- this call itself is what
    // Step 7 is testing, applied here to the SAME (already access-revoked)
    // activation first, then a second fresh one below for the clean case.
    $deactivateExpired = org_relationship_deactivate($relId, true, $ownerUserId, 'ZZ Owner User', 'closing the backdated drill activation', false);
    t('explicit deactivation of the already-expired activation succeeds', $deactivateExpired['success'] === true);
    $closedRow = db_fetch_one("SELECT deactivated_at, deactivated_reason FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('deactivated_at is now set by the explicit call', $closedRow && $closedRow['deactivated_at'] !== null);
    t('deactivated_reason carries the human-supplied reason (not the auto-expired sentinel -- this was an EXPLICIT deactivation)', $closedRow && $closedRow['deactivated_reason'] === 'closing the backdated drill activation');

    // Fresh, NOT backdated, activation -- confirm access before, then
    // deactivate explicitly, then confirm access is gone.
    $activate2 = org_relationship_activate($relId, true, $otherUserId, 'ZZ Other User', 'second drill', 30);
    t('a second, fresh activation succeeds', $activate2['success'] === true);
    t('BEFORE explicit deactivation (fresh activation, well within its window): the other org CAN see the ticket', org_can_see_ticket($ticketId, $otherUserId));

    $deactivate2 = org_relationship_deactivate($relId, true, $otherUserId, 'ZZ Other User', 'manual stand-down', false);
    t('explicit deactivation of the fresh (not-yet-expired) activation succeeds', $deactivate2['success'] === true);

    t('AFTER explicit deactivation: org_can_see_ticket() reports the other org can NO LONGER see the ticket', !org_can_see_ticket($ticketId, $otherUserId));
    [$fragFinal, $varsFinal] = org_ticket_query_filter($otherUserId, 't');
    $rowsFinal = db_fetch_all("SELECT id FROM {$prefix}ticket t WHERE 1=1 {$fragFinal} AND t.id = ?", array_merge($varsFinal, [$ticketId]));
    t('AFTER explicit deactivation: org_ticket_query_filter() no longer includes the ticket', count($rowsFinal) === 0);
    t('AFTER explicit deactivation: org_can_mutate_ticket() reports the other org can NO LONGER mutate the ticket', !org_can_mutate_ticket($ticketId, $otherUserId));

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
    try { db_query("DELETE FROM {$prefix}in_types WHERE type = 'ZZ143RTE Type'"); } catch (Throwable $e) {}
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
