<?php
/**
 * Phase 142 (2026-08-17) — SSE 'org' scope end-to-end proof.
 *
 * api/stream.php is a long-running polling script, not a set of callable
 * functions, so it cannot be invoked directly from a CLI test the way
 * inc/sse.php's functions can — matching this codebase's established
 * "no session-login HTTP test harness" convention (Phase 141's own Task 6
 * precedent, and tools/test_sse.php's own approach: publish via the real
 * sse_publish()/sse_publish_for_incident() functions, then query the REAL
 * `sse_events` table directly rather than driving the HTTP stream).
 *
 * The one piece that genuinely can't be exercised that way is
 * api/stream.php's own per-connection $visibilityWhere/$visibilityParams
 * construction (the code that decides WHICH published rows a given
 * session's poll loop would actually receive). That construction is
 * mirrored here as _p142_stream_visibility_where(), built by copying the
 * 'org'-scope block verbatim from the live file — and Part 0 below proves
 * the mirror hasn't drifted by asserting the exact same clause text is
 * present in api/stream.php's own source, so a future edit to the real
 * file that isn't mirrored here fails LOUDLY instead of silently making
 * this test meaningless.
 *
 * Covers (tasks.md section 6's own test-file scope):
 *   0. The mirror function's clause shapes are structurally verified
 *      against api/stream.php's own source (not merely trusted).
 *   1. A published scope='org' event with org X in visibility_ids is
 *      present in a $visibilityWhere-shaped query for a simulated session
 *      whose $userOrgIds includes X, and absent for one whose $userOrgIds
 *      does not.
 *   2. The propagation-delay claim, proven directly (not just argued in
 *      prose): revoke a share, publish a subsequent ORDINARY ticket event
 *      via sse_publish_for_incident('incident:update', ...), and assert
 *      the just-revoked org's id is absent from that publish's resulting
 *      row set entirely (no scope='org' row is even written for it,
 *      because _sse_share_orgs_for_ticket() already excludes the revoked
 *      org by the time this second publish runs).
 *   3. _org_sharing_notify_share_change()'s TARGETED notification reaches
 *      a simulated shared-with-org session for BOTH incident:shared (on
 *      create) and incident:unshared (on revoke) — including the revoke
 *      case specifically, where the target org is by definition no
 *      longer in the "currently active" set _sse_share_orgs_for_ticket()
 *      would compute, which is exactly why plan.md calls for a SEPARATE,
 *      targeted publish rather than relying on the broad resolver.
 *   4. A simulated session belonging to a DIFFERENT org (not the
 *      share's target) does NOT receive the targeted incident:shared /
 *      incident:unshared notification — no information leak about a
 *      ticket's sharing relationships to an uninvolved third org.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_sse.php
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

echo "=== Phase 142 — SSE 'org' scope end-to-end ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');
_sse_ensure_schema();

/**
 * Mirrors api/stream.php's own $visibilityClauses/$visibilityParams
 * construction, non-admin branch, 'org' scope only (the group/entitled/
 * user clauses are Phase 141-and-earlier surface, unchanged by this
 * phase, and are exercised by that surface's own existing tests). Part 0
 * below verifies this mirror's text is genuinely present in the live
 * file, not merely asserted to match by comment.
 *
 * @return array{0: string, 1: array} [$whereSql, $params] — a fragment
 *   safe to AND onto `WHERE id > ? AND (...)`.
 */
function _p142_stream_visibility_where(array $userOrgIds): array {
    $visibilityClauses = ["`visibility_scope` = 'public'"];
    $visibilityParams  = [];
    if (!empty($userOrgIds)) {
        $orgOrs = [];
        foreach ($userOrgIds as $oid) {
            $orgOrs[] = "FIND_IN_SET(?, `visibility_ids`) > 0";
            $visibilityParams[] = (string) $oid;
        }
        $visibilityClauses[] = "(`visibility_scope` = 'org' AND (" . implode(' OR ', $orgOrs) . "))";
    }
    return ['(' . implode(' OR ', $visibilityClauses) . ')', $visibilityParams];
}

// ═══════════════════════════════════════════════════════════════════════
// Part 0 — the mirror is not drifting from api/stream.php's real source.
// ═══════════════════════════════════════════════════════════════════════

echo "--- Part 0: mirror verified against api/stream.php's own source ---\n\n";

$streamSrc = @file_get_contents($base . '/api/stream.php');
t('api/stream.php exists', $streamSrc !== false);
if ($streamSrc !== false) {
    t("stream.php requires inc/org-scope.php", strpos($streamSrc, "require_once __DIR__ . '/../inc/org-scope.php'") !== false);
    t("stream.php computes \$userOrgIds via org_visible_ids()", strpos($streamSrc, '$userOrgIds = org_visible_ids($userId)') !== false);
    t("stream.php's admin branch includes 'org' in visibility_scope IN (...)", strpos($streamSrc, "visibility_scope` IN ('admin','group','entitled','org')") !== false);
    t("stream.php's non-admin 'org' clause matches this test's mirror exactly (FIND_IN_SET shape)", strpos($streamSrc, "(`visibility_scope` = 'org' AND (\" . implode(' OR ', \$orgOrs) . \"))") !== false);
    t("stream.php's non-admin 'org' clause is gated on \$userOrgIds !== null && !empty(\$userOrgIds)", strpos($streamSrc, '$userOrgIds !== null && !empty($userOrgIds)') !== false);
}

// ═══════════════════════════════════════════════════════════════════════
// Fixtures
// ═══════════════════════════════════════════════════════════════════════

$ownerOrgId  = 900004900;
$targetOrgId = 900004901; // the org a ticket gets shared WITH
$otherOrgId  = 900004902; // an uninvolved third org — must never receive the targeted notify
$ownerUserId = 900004910;

$createdOrgIds = [$ownerOrgId, $targetOrgId, $otherOrgId];
$createdTicketIds = [];
$createdEventIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, &$createdEventIds, $ownerUserId) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$ownerUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}sse_events WHERE payload LIKE '%zz142sse%'"); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([$ownerOrgId, $targetOrgId, $otherOrgId] as $oid) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$oid, 'ZZ142SSE Org ' . $oid]);
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 Sse142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142sse ticket', 'zz142sse ticket', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;

    // ══════════════════════════════════════════════════════════════════
    // Part 1 — a directly-published scope='org' event, inclusion/exclusion.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 1: directly-published scope='org' event -- inclusion/exclusion ---\n\n";

    $published = sse_publish('incident:update', ['zz142sse' => true, 'note' => 'direct org-scope publish'], $ownerUserId, 'org', [$targetOrgId]);
    t('sse_publish() with scope=org succeeds', $published === true);

    $eventRow = db_fetch_one(
        "SELECT id, visibility_scope, visibility_ids FROM {$prefix}sse_events WHERE payload LIKE '%direct org-scope publish%' ORDER BY id DESC LIMIT 1"
    );
    t('the published row exists with visibility_scope=org', $eventRow && $eventRow['visibility_scope'] === 'org');
    t('the published row visibility_ids contains the target org id', $eventRow && (string) $eventRow['visibility_ids'] === (string) $targetOrgId);
    if ($eventRow) $createdEventIds[] = (int) $eventRow['id'];

    [$whereIncluded, $paramsIncluded] = _p142_stream_visibility_where([$targetOrgId]);
    $rowsIncluded = db_fetch_all(
        "SELECT id FROM {$prefix}sse_events WHERE id = ? AND {$whereIncluded}",
        array_merge([(int) $eventRow['id']], $paramsIncluded)
    );
    t('a simulated session whose userOrgIds includes the target org SEES the event via the mirrored visibility WHERE', count($rowsIncluded) === 1);

    [$whereExcluded, $paramsExcluded] = _p142_stream_visibility_where([$otherOrgId]);
    $rowsExcluded = db_fetch_all(
        "SELECT id FROM {$prefix}sse_events WHERE id = ? AND {$whereExcluded}",
        array_merge([(int) $eventRow['id']], $paramsExcluded)
    );
    t('a simulated session whose userOrgIds does NOT include the target org does NOT see the event', count($rowsExcluded) === 0);

    // ══════════════════════════════════════════════════════════════════
    // Part 2 — the propagation-delay claim, proven directly.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 2: propagation-delay claim -- revoke takes effect on the VERY NEXT publish ---\n\n";

    $share = org_sharing_create_manual_share($ticketId, $targetOrgId, 'view', 'zz142sse propagation fixture', $ownerUserId, 'ZZ142SSE Owner');
    t('fixture share created', $share['success'] === true);
    $shareId = (int) ($share['id'] ?? 0);

    // sse_publish_for_incident() is the ~15-call-site function -- publish
    // an ORDINARY ticket event (not the targeted share-change notify) and
    // confirm it reaches the target org's scope='org' clause WHILE the
    // share is still active.
    sse_publish_for_incident('incident:update', ['zz142sse' => true, 'phase' => 'pre-revoke'], $ticketId, $ownerUserId);
    $preRevokeRow = db_fetch_one(
        "SELECT id, visibility_ids FROM {$prefix}sse_events
          WHERE event_type = 'incident:update' AND payload LIKE '%pre-revoke%' AND visibility_scope = 'org'
          ORDER BY id DESC LIMIT 1"
    );
    t('WHILE the share is active: an ordinary incident:update publish DOES carry a scope=org row for the target org', $preRevokeRow && (string) $preRevokeRow['visibility_ids'] === (string) $targetOrgId);
    if ($preRevokeRow) $createdEventIds[] = (int) $preRevokeRow['id'];

    $revoke = org_sharing_revoke_share($shareId, 'zz142sse propagation revoke', $ownerUserId, 'ZZ142SSE Owner');
    t('fixture share revoked', $revoke['success'] === true);

    // The VERY NEXT publish for this ticket -- no delay, no sleep, no
    // reconnect simulation. If there were any propagation-delay window on
    // the WRITE side, this publish (which happens immediately after
    // revoke, in the same process) would still carry the org. It must not.
    sse_publish_for_incident('incident:update', ['zz142sse' => true, 'phase' => 'post-revoke'], $ticketId, $ownerUserId);
    $postRevokeRow = db_fetch_one(
        "SELECT id FROM {$prefix}sse_events
          WHERE event_type = 'incident:update' AND payload LIKE '%post-revoke%' AND visibility_scope = 'org'
          ORDER BY id DESC LIMIT 1"
    );
    t('IMMEDIATELY after revoke: the very next incident:update publish carries NO scope=org row at all for this ticket (zero propagation delay)', !$postRevokeRow);
    if ($postRevokeRow) $createdEventIds[] = (int) $postRevokeRow['id'];

    // ══════════════════════════════════════════════════════════════════
    // Part 3 — _org_sharing_notify_share_change()'s targeted notification,
    // both directions (create -> incident:shared, revoke -> incident:unshared),
    // reaching a simulated shared-with-org session and NOT an uninvolved third org.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 3: targeted incident:shared / incident:unshared notification ---\n\n";

    $share2 = org_sharing_create_manual_share($ticketId, $targetOrgId, 'view', 'zz142sse notify fixture', $ownerUserId, 'ZZ142SSE Owner');
    t('second fixture share created (for the notify test)', $share2['success'] === true);
    $share2Id = (int) ($share2['id'] ?? 0);

    $sharedEventRow = db_fetch_one(
        "SELECT id, visibility_ids FROM {$prefix}sse_events
          WHERE event_type = 'incident:shared' AND visibility_scope = 'org'
            AND payload LIKE CONCAT('%\"ticket_id\":', ?, '%')
          ORDER BY id DESC LIMIT 1",
        [$ticketId]
    );
    t('an incident:shared event, scope=org, was published for the create', $sharedEventRow && (string) $sharedEventRow['visibility_ids'] === (string) $targetOrgId);
    if ($sharedEventRow) $createdEventIds[] = (int) $sharedEventRow['id'];

    [$whereTarget, $paramsTarget] = _p142_stream_visibility_where([$targetOrgId]);
    $targetSees = $sharedEventRow ? db_fetch_all("SELECT id FROM {$prefix}sse_events WHERE id = ? AND {$whereTarget}", array_merge([(int) $sharedEventRow['id']], $paramsTarget)) : [];
    t('a simulated session for the TARGET org sees the incident:shared notification', count($targetSees) === 1);

    [$whereOther, $paramsOther] = _p142_stream_visibility_where([$otherOrgId]);
    $otherSees = $sharedEventRow ? db_fetch_all("SELECT id FROM {$prefix}sse_events WHERE id = ? AND {$whereOther}", array_merge([(int) $sharedEventRow['id']], $paramsOther)) : [];
    t('a simulated session for an UNINVOLVED third org does NOT see the incident:shared notification', count($otherSees) === 0);

    $revoke2 = org_sharing_revoke_share($share2Id, 'zz142sse notify revoke', $ownerUserId, 'ZZ142SSE Owner');
    t('second fixture share revoked (for the notify test)', $revoke2['success'] === true);

    $unsharedEventRow = db_fetch_one(
        "SELECT id, visibility_ids FROM {$prefix}sse_events
          WHERE event_type = 'incident:unshared' AND visibility_scope = 'org'
            AND payload LIKE CONCAT('%\"ticket_id\":', ?, '%')
          ORDER BY id DESC LIMIT 1",
        [$ticketId]
    );
    // This is the case plan.md specifically calls out: by the time this
    // event is published, _sse_share_orgs_for_ticket() ALREADY excludes
    // $targetOrgId (the share is revoked) -- so this row can only exist
    // because _org_sharing_notify_share_change() targets it EXPLICITLY,
    // never via the broad sse_publish_for_incident() resolver.
    t('an incident:unshared event, scope=org, was published for the revoke -- targeting the org that JUST LOST access', $unsharedEventRow && (string) $unsharedEventRow['visibility_ids'] === (string) $targetOrgId);
    if ($unsharedEventRow) $createdEventIds[] = (int) $unsharedEventRow['id'];

    $targetSeesUnshare = $unsharedEventRow ? db_fetch_all("SELECT id FROM {$prefix}sse_events WHERE id = ? AND {$whereTarget}", array_merge([(int) $unsharedEventRow['id']], $paramsTarget)) : [];
    t('the simulated TARGET-org session sees the incident:unshared notification (it must hear about losing access)', count($targetSeesUnshare) === 1);

    $otherSeesUnshare = $unsharedEventRow ? db_fetch_all("SELECT id FROM {$prefix}sse_events WHERE id = ? AND {$whereOther}", array_merge([(int) $unsharedEventRow['id']], $paramsOther)) : [];
    t('an uninvolved third org does NOT see the incident:unshared notification either', count($otherSeesUnshare) === 0);

    // Confirm _sse_share_orgs_for_ticket() itself now genuinely excludes
    // the revoked org, directly -- the mechanism Part 2/3 both rest on.
    $sharesNow = _sse_share_orgs_for_ticket($ticketId);
    t('_sse_share_orgs_for_ticket() no longer includes the revoked target org', !in_array($targetOrgId, $sharesNow, true));

} finally {
    foreach ($createdEventIds as $eid) { try { db_query("DELETE FROM {$prefix}sse_events WHERE id = ?", [$eid]); } catch (Throwable $e) {} }
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
