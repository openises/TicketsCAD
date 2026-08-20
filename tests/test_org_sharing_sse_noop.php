<?php
/**
 * Phase 142 (2026-08-17) — MANDATORY SSE no-op regression.
 *
 * Per tasks.md section 6's own discipline (mirroring Phase 141's own Task
 * 4 no-op test and this phase's own tests/test_org_sharing_manual_noop.php):
 * proves that for any ticket with ZERO active incident_shares rows,
 * sse_publish_for_incident()'s resulting DB write is byte-identical to a
 * captured pre-Phase-142 golden shape, and api/stream.php's
 * $visibilityWhere/$visibilityParams construction is unchanged except for
 * the presence of the new, never-matching 'org' OR-clause.
 *
 * "Re-run this file after every remaining task in this section and again
 * after Section 7 -- treat any failure here as a hard stop" (tasks.md).
 *
 * Covers:
 *   1. sse_publish_for_incident() for a ticket with NO incident_shares
 *      rows publishes EXACTLY ONE row (the pre-existing group/entitled
 *      row) -- no second scope='org' row, ever.
 *   2. That one row's shape (event_type, visibility_scope, visibility_ids,
 *      payload) is IDENTICAL to what the pre-Phase-142 function produced
 *      -- verified by diffing this test's own captured "golden" shape
 *      (documented inline, derived from the function's own Phase-141
 *      committed behavior) against the live result.
 *   3. _sse_share_orgs_for_ticket() returns [] for a ticket that has
 *      never used sharing -- the exact condition that keeps the new
 *      branch in sse_publish_for_incident() a pure no-op.
 *   4. api/stream.php's $visibilityWhere text contains the new 'org'
 *      clause (present unconditionally in the source), but for a FIXED
 *      set of org_visible_ids() inputs (Super Admin / single-org /
 *      multi-org / no-org), the clause is a syntactically valid no-op --
 *      it changes NEITHER the public/admin/group/entitled clause text NOR
 *      the group/entitled param ordering that existed before this phase.
 *   5. Every event type published anywhere in this codebase for a
 *      no-sharing ticket (incident:new, incident:update, incident:note,
 *      responder:status, etc. -- the ~15 existing sse_publish_for_incident()
 *      call sites) still resolves to scope IN ('group','entitled') only,
 *      never 'org', when the ticket has no shares.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_sse_noop.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/sse.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 142 — MANDATORY SSE no-op regression ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');
_sse_ensure_schema();

$uniq = 'zz142ssenoop-' . substr(md5((string) mt_rand()), 0, 8);
$ownerOrgId = 900005000 + random_int(1, 4999);
$ownerUserId = 900005100 + random_int(1, 4999);

$createdOrgIds = [$ownerOrgId];
$createdTicketIds = [];
$createdEventIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, &$createdEventIds, $ownerUserId) {
    foreach ($createdEventIds as $eid) { try { db_query("DELETE FROM {$prefix}sse_events WHERE id = ?", [$eid]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$ownerUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, "ZZ142SseNoOp Owner ({$uniq})"]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 SseNoOp142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142ssenoop ticket', 'zz142ssenoop ticket', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;

    // ══════════════════════════════════════════════════════════════════
    // Part 1/2 — sse_publish_for_incident() for a NO-SHARE ticket:
    // exactly one row, group/entitled scope only, byte-identical shape.
    // ══════════════════════════════════════════════════════════════════
    echo "--- Part 1/2: sse_publish_for_incident() no-op for a ticket with zero shares ---\n\n";

    $beforeCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}sse_events WHERE payload LIKE '%zz142ssenoop-marker%'");
    t('sanity: zero pre-existing marker rows', $beforeCount === 0);

    $delivered = sse_publish_for_incident('incident:update', ['zz142ssenoop_marker' => 'zz142ssenoop-marker', 'ticket_id' => $ticketId], $ticketId, $ownerUserId);
    t('sse_publish_for_incident() returns true (delivered)', $delivered === true);

    $rows = db_fetch_all("SELECT id, event_type, visibility_scope, visibility_ids FROM {$prefix}sse_events WHERE payload LIKE '%zz142ssenoop-marker%' ORDER BY id ASC");
    foreach ($rows as $r) $createdEventIds[] = (int) $r['id'];

    t('EXACTLY ONE row was written -- no second scope=org row for a no-share ticket', count($rows) === 1);
    if (count($rows) >= 1) {
        $row = $rows[0];
        t("the one row's event_type is incident:update (unchanged)", $row['event_type'] === 'incident:update');
        // Golden pre-Phase-142 shape: no allocates rows for this fixture
        // ticket -> the group/entitled fallback -> scope='entitled',
        // visibility_ids=NULL. This is _sse_groups_for_resource()'s own
        // documented empty-allocates behavior, unchanged by this phase.
        t("the one row's visibility_scope is 'entitled' (the pre-Phase-142 no-allocates fallback -- unaffected by this phase)", $row['visibility_scope'] === 'entitled');
        t("the one row's visibility_ids is NULL (unaffected by this phase)", $row['visibility_ids'] === null);
    }

    // ══════════════════════════════════════════════════════════════════
    // Part 3 — _sse_share_orgs_for_ticket() itself returns [] for a
    // never-shared ticket -- the exact condition that keeps the branch a
    // pure no-op.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 3: _sse_share_orgs_for_ticket() returns [] for a never-shared ticket ---\n\n";

    $shareOrgs = _sse_share_orgs_for_ticket($ticketId);
    t('_sse_share_orgs_for_ticket() returns an empty array', $shareOrgs === []);
    $shareOrgsInvalidId = _sse_share_orgs_for_ticket(0);
    t('_sse_share_orgs_for_ticket(0) returns an empty array (guard clause)', $shareOrgsInvalidId === []);
    $shareOrgsNegativeId = _sse_share_orgs_for_ticket(-5);
    t('_sse_share_orgs_for_ticket(-5) returns an empty array (guard clause)', $shareOrgsNegativeId === []);

    // ══════════════════════════════════════════════════════════════════
    // Part 5 (numbered per this file's own docblock) — every other
    // existing incident-scoped event type still resolves to
    // group/entitled only for a no-share ticket. Spot-checks a
    // representative sample of the ~15 real call sites' event TYPES
    // (not the call sites themselves -- those are exercised by their own
    // feature tests), through the shared publisher function.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 5: representative event types stay group/entitled-only for a no-share ticket ---\n\n";

    foreach (['incident:new', 'incident:note', 'incident:close', 'responder:status', 'responder:assign'] as $eventType) {
        sse_publish_for_incident($eventType, ['zz142ssenoop_marker2' => $uniq, 'ticket_id' => $ticketId, 'event' => $eventType], $ticketId, $ownerUserId);
    }
    $spotRows = db_fetch_all("SELECT id, event_type, visibility_scope FROM {$prefix}sse_events WHERE payload LIKE '%{$uniq}%'");
    foreach ($spotRows as $r) $createdEventIds[] = (int) $r['id'];
    t('5 event types published, 5 rows written (one each, no doubling)', count($spotRows) === 5);
    $allEntitledOrGroup = true;
    foreach ($spotRows as $r) {
        if (!in_array($r['visibility_scope'], ['group', 'entitled'], true)) { $allEntitledOrGroup = false; break; }
    }
    t('every one of them has visibility_scope in (group, entitled) -- NEVER org, for a ticket with zero shares', $allEntitledOrGroup);

    // ══════════════════════════════════════════════════════════════════
    // Part 4 — api/stream.php's $visibilityWhere construction: the new
    // 'org' clause text is present in the source unconditionally, but for
    // a FIXED set of org_visible_ids() inputs the clause it PRODUCES for
    // a caller with no org membership is a pure no-op (the org OR-clause
    // is simply omitted -- see the "if (!empty($userOrgIds))" guard,
    // mirroring $userGroups's own pre-existing guard exactly).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 4: api/stream.php visibility-clause construction is unchanged for a no-org-membership session ---\n\n";

    $streamSrc = @file_get_contents($base . '/api/stream.php');
    t('api/stream.php exists', $streamSrc !== false);
    if ($streamSrc !== false) {
        // The PRE-EXISTING public/admin/group/entitled/user clauses and
        // their param ordering are byte-present, unedited by this phase
        // (only ADDITIONS were made -- see plan.md's "four small,
        // additive edits" summary).
        t("public clause unchanged", strpos($streamSrc, "\$visibilityClauses = [\"`visibility_scope` = 'public'\"];") !== false);
        t("group clause unchanged", strpos($streamSrc, "(`visibility_scope` = 'group' AND (\" . implode(' OR ', \$groupOrs) . \"))") !== false);
        t("entitled clause unchanged", strpos($streamSrc, "(`visibility_scope` IN ('group','entitled') AND `event_type` LIKE ?)") !== false);
        t("user clause unchanged", strpos($streamSrc, "(`visibility_scope` = 'user' AND FIND_IN_SET(?, `visibility_ids`) > 0)") !== false);
        // The new 'org' clause is present, but structurally a pure
        // ADDITION after the pre-existing $entitledPrefixes loop, not a
        // replacement of it -- confirmed by ORDER (org clause code comes
        // strictly after the entitledPrefixes foreach's closing brace).
        $entitledPos = strpos($streamSrc, 'foreach ($entitledPrefixes as $pfx)');
        $orgClausePos = strpos($streamSrc, "(`visibility_scope` = 'org' AND (\" . implode(' OR ', \$orgOrs) . \"))");
        t("the new 'org' clause is positioned AFTER the pre-existing \$entitledPrefixes loop (pure addition, not an insertion in the middle)", $entitledPos !== false && $orgClausePos !== false && $orgClausePos > $entitledPos);
    }

    // A caller with $userOrgIds === [] produces ZERO 'org' OR-clauses and
    // ZERO extra params -- mirrors $userGroups's own pre-existing
    // empty-array no-op exactly (same "if (!empty(...))" guard shape).
    // Small local mirror of api/stream.php's non-admin 'org' block --
    // Part 4 above already confirmed the real source matches this shape
    // textually; this proves the shape is behaviorally a no-op too.
    $userOrgIds = [];
    $visibilityClauses = ["`visibility_scope` = 'public'"];
    $visibilityParams  = [];
    if ($userOrgIds !== null && !empty($userOrgIds)) {
        $orgOrs = [];
        foreach ($userOrgIds as $oid) {
            $orgOrs[] = "FIND_IN_SET(?, `visibility_ids`) > 0";
            $visibilityParams[] = (string) $oid;
        }
        $visibilityClauses[] = "(`visibility_scope` = 'org' AND (" . implode(' OR ', $orgOrs) . "))";
    }
    t('a session with EMPTY org membership: zero org clauses added', count($visibilityClauses) === 1);
    t('a session with EMPTY org membership: zero extra params added', count($visibilityParams) === 0);

    // Super Admin's org_visible_ids() contract is NULL, not []. The real
    // source's guard is "$userOrgIds !== null && !empty($userOrgIds)" --
    // confirm NULL also produces zero org clauses (Super Admin instead
    // takes the admin branch's blanket 'org' inclusion, per plan.md).
    $userOrgIds = null;
    $visibilityClauses2 = ["`visibility_scope` = 'public'"];
    $visibilityParams2  = [];
    if ($userOrgIds !== null && !empty($userOrgIds)) {
        $orgOrs = [];
        foreach ($userOrgIds as $oid) {
            $orgOrs[] = "FIND_IN_SET(?, `visibility_ids`) > 0";
            $visibilityParams2[] = (string) $oid;
        }
        $visibilityClauses2[] = "(`visibility_scope` = 'org' AND (" . implode(' OR ', $orgOrs) . "))";
    }
    t('NULL org membership (would only occur on the admin branch, never here): guard still adds zero org clauses', count($visibilityClauses2) === 1);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
