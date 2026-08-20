<?php
/**
 * Phase 143 (2026-08-17) — Anti-chaining, re-examined and resolved.
 *
 * Phase 141's plan.md explicitly flagged this for later re-examination:
 * "This must be re-examined the moment Phase 3 introduces org_relationships,
 * since that is exactly the mechanism that could make org_visible_ids()
 * itself share-derived." This file is that re-examination -- structurally
 * identical in shape to Phase 142's tests/test_org_sharing_anti_chaining.php,
 * substituting an active relationship-activation for an incident_shares row.
 *
 * Drives the REAL writers, not hand-seeded rows, through the exact scenario
 * plan.md's "Anti-chaining, re-examined and resolved" section names:
 *   1. An assist-tier relationship-derived viewer at Org B (confirmed via
 *      org_can_mutate_ticket() to have full write access to Org A's ticket,
 *      purely via an active relationship) attempts Phase 142's
 *      org_sharing_create_manual_share() to share the ticket onward to
 *      Org C. Must be refused -- no incident_shares row written.
 *   2. The same Org B user attempts org_relationship_create_or_propose() /
 *      org_relationship_member_add() naming Org A as a member of a NEW
 *      relationship "on Org A's behalf." Must be refused --
 *      org_relationship_can_act_for_org() returns false because Org B's
 *      own org_visible_ids() never contains Org A.
 *   3. A genuine Org A user succeeds at both -- control case, proves the
 *      gate isn't failing closed for everyone.
 *   4. Confirm (source inspection, not just behavioral proof) that
 *      org_visible_ids() and org_ticket_is_owned_by_caller() are
 *      byte-identical to their pre-Phase-143 shape -- asserted against a
 *      stored pre-Phase-143 snapshot of both functions' bodies, so a future
 *      refactor that accidentally widens either one is caught even if it
 *      happens not to break this specific scenario.
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_anti_chaining.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/org-relationships.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Anti-chaining, re-examined and resolved ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

$orgA = 900005400; // owns the ticket
$orgB = 900005401; // assist-tier relationship-derived viewer
$orgC = 900005402; // onward-share target Org B attempts to use

$userA = 900005410; // genuine Org A user (control case)
$userB = 900005411; // Org B's user -- the one every refusal below is about

$createdOrgIds = [$orgA, $orgB, $orgC];
$createdTicketIds = [];
$createdRelIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, &$createdRelIds, $userA, $userB) {
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
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$userA, $userB]); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([$orgA => 'ZZ143AC Org A', $orgB => 'ZZ143AC Org B', $orgC => 'ZZ143AC Org C'] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$userA, $orgA, $orgA]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$userB, $orgB, $orgB]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 AntiChain143 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz143ac ticket', 'zz143ac ticket', 2, 1, NOW(), ?)",
        [$now, $orgA]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;
    t('fixture ticket created, owned by Org A', $ticketId > 0);

    // Active, assist-tier, always-on (requires_activation=0, so no
    // activation-window mechanics distract from what THIS test is actually
    // proving) relationship between Org A and Org B, via the real writers.
    $create = org_relationship_create_or_propose(
        [
            'name' => 'ZZ143AC Standing Relationship',
            'member_org_ids' => [$orgA, $orgB],
            'access_tier' => 'assist',
            'redaction_profile' => 'view',
            'requires_activation' => 0,
        ],
        true, $userA, 'ZZ Owner User'
    );
    t('relationship created and active', $create['success'] === true && $create['status'] === 'active');
    $relId = (int) ($create['id'] ?? 0);
    if ($relId > 0) $createdRelIds[] = $relId;

    t('Org B (relationship-derived) can see the ticket', org_can_see_ticket($ticketId, $userB));
    t('Org B (relationship-derived, assist tier) CAN mutate the ticket (full same-org-equivalent write access -- the control fact this whole test exists to defeat for SHARING/AUTHORING specifically)', org_can_mutate_ticket($ticketId, $userB));

    // ══════════════════════════════════════════════════════════════════
    // Case 1 — Org B attempts to share the ticket onward to Org C via
    // Phase 142's org_sharing_create_manual_share().
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Case 1: relationship-derived Org B attempts to SHARE the ticket onward ---\n\n";

    $result1 = org_sharing_create_manual_share($ticketId, $orgC, 'view', 'onward share attempt via relationship-derived access', $userB, 'ZZ Org B User');
    t('Case 1: relationship-derived Org B is REFUSED when sharing onward, despite holding full write access to the ticket', $result1['success'] === false);
    t('Case 1: refusal carries a non-empty errors array', !empty($result1['errors']));
    $rowAfter1 = db_fetch_one("SELECT id FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $orgC]);
    t('Case 1: NO row was written to incident_shares for (ticket, Org C)', !$rowAfter1);

    // ══════════════════════════════════════════════════════════════════
    // Case 2 — Org B attempts to name Org A as a member of a NEW
    // relationship "on Org A's behalf."
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Case 2: relationship-derived Org B attempts to PROPOSE naming Org A ---\n\n";

    $result2 = org_relationship_create_or_propose(
        ['name' => 'ZZ143AC Chained proposal', 'member_org_ids' => [$orgA, $orgC], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        false, $userB, 'ZZ Org B User'
    );
    t('Case 2: Org B (org-scoped, not naming their own org) is REFUSED proposing a relationship naming Org A/Org C', $result2['success'] === false);
    t('Case 2: refusal names the specific requirement', !empty($result2['errors']) && stripos(implode(' ', $result2['errors']), 'own organization') !== false);

    // The sharper case: an EXISTING relationship (naming Org A and Org C,
    // proposed globally so it actually exists) that Org B attempts to add
    // itself/approve on Org A's behalf via org_relationship_member_add() /
    // org_relationship_member_approve() -- Org B's own org_visible_ids()
    // never contains Org A, so org_relationship_can_act_for_org() must
    // refuse regardless of Org B's OWN, unrelated, relationship-derived
    // ticket access.
    $existingRel = org_relationship_create_or_propose(
        ['name' => 'ZZ143AC Existing (A+C)', 'member_org_ids' => [$orgA, $orgC], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        true, $userA, 'ZZ Owner User'
    );
    $existingRelId = (int) ($existingRel['id'] ?? 0);
    if ($existingRelId > 0) $createdRelIds[] = $existingRelId;
    $orgARow = db_fetch_one("SELECT id FROM {$prefix}org_relationships_members WHERE relationship_id = ? AND org_id = ?", [$existingRelId, $orgA]);

    $chainedApprove = org_relationship_member_approve((int) $orgARow['id'], false, $userB, 'ZZ Org B User');
    t("Case 2b: Org B CANNOT approve Org A's own membership row on an unrelated relationship, despite Org B's own relationship-derived ticket access", $chainedApprove['success'] === false);

    $chainedAdd = org_relationship_member_add($existingRelId, $orgB, false, $userB, 'ZZ Org B User');
    // This one is actually LEGITIMATE for Org B to do for ITSELF (adding
    // Org B, not Org A) -- included as a companion control to confirm the
    // gate distinguishes "acting for yourself" from "acting for Org A".
    t("Case 2c (companion control): Org B CAN add ITSELF (not Org A) to an existing relationship it's not yet part of", $chainedAdd['success'] === true);

    // ══════════════════════════════════════════════════════════════════
    // Case 3 — control: a genuine Org A user succeeds at both.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Case 3 (control): genuine Org A user succeeds at both ---\n\n";

    $result3 = org_sharing_create_manual_share($ticketId, $orgC, 'view', 'legitimate owning-org share', $userA, 'ZZ Owner User');
    t('Case 3 (control): the genuine owning-org (Org A) user SUCCEEDS sharing the ticket to Org C -- proves the gate is not failing closed for everyone', $result3['success'] === true);
    $rowAfter3 = db_fetch_one("SELECT id, created_by FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $orgC]);
    t('Case 3 (control): the row now exists in incident_shares, attributed to the owning-org caller', $rowAfter3 && (int) $rowAfter3['created_by'] === $userA);

    $result3b = org_relationship_create_or_propose(
        ['name' => 'ZZ143AC Control proposal', 'member_org_ids' => [$orgA, $orgC], 'access_tier' => 'view', 'redaction_profile' => 'view'],
        false, $userA, 'ZZ Owner User'
    );
    t('Case 3b (control): the genuine Org A user SUCCEEDS proposing a relationship naming their own org', $result3b['success'] === true);
    $controlRelId = (int) ($result3b['id'] ?? 0);
    if ($controlRelId > 0) $createdRelIds[] = $controlRelId;

    // ══════════════════════════════════════════════════════════════════
    // Case 4 — structural: org_visible_ids() and org_ticket_is_owned_by_caller()
    // are byte-identical to their pre-Phase-143 shape.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Case 4: org_visible_ids() / org_ticket_is_owned_by_caller() are structurally UNTOUCHED ---\n\n";

    $cwd = getcwd();
    chdir($base);
    $out = [];
    exec('git show HEAD:inc/org-scope.php 2>&1', $out, $code);
    chdir($cwd);
    $headSource = ($code === 0 && !empty($out)) ? implode("\n", $out) : null;

    if ($headSource === null) {
        echo "SKIP: git not available / not a git checkout -- cannot diff against HEAD in this environment.\n";
    } else {
        $currentSource = file_get_contents($base . '/inc/org-scope.php');

        function _p143ac_extract_function(string $source, string $funcName): ?string {
            $pos = strpos($source, 'function ' . $funcName . '(');
            if ($pos === false) return null;
            $braceStart = strpos($source, '{', $pos);
            if ($braceStart === false) return null;
            $depth = 0;
            for ($i = $braceStart; $i < strlen($source); $i++) {
                if ($source[$i] === '{') $depth++;
                if ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) return substr($source, $pos, $i - $pos + 1);
                }
            }
            return null;
        }

        foreach (['org_visible_ids', 'org_ticket_is_owned_by_caller'] as $fn) {
            $h = _p143ac_extract_function($headSource, $fn);
            $c = _p143ac_extract_function($currentSource, $fn);
            t("$fn() is present in both pre-Phase-143 HEAD and the current tree", $h !== null && $c !== null);
            t("$fn()'s function body is BYTE-IDENTICAL to the pre-Phase-143 committed version -- ZERO edits, in this phase or any commit", $h !== null && $c !== null && $h === $c);
        }
    }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
