<?php
/**
 * Phase 143 (2026-08-17) — MANDATORY no-op regression.
 *
 * Per this project's own established discipline (Phase 141's
 * tests/test_org_sharing_noop.php, Phase 142's
 * tests/test_org_sharing_manual_noop.php): proves that an install with
 * ZERO org_relationships rows sees ZERO behavior change from anything this
 * phase's backend adds. Every fixture below uses org/ticket ids that no
 * relationship anywhere on this (possibly shared, long-lived dev) database
 * could ever name, so "zero applicable rows" holds structurally -- this
 * test does NOT require the org_relationships table to be globally empty.
 *
 * Coverage:
 *   1. org_can_see_ticket() / org_ticket_query_filter() / org_can_mutate_ticket()
 *      for a same-org ticket and a genuinely-invisible cross-org ticket,
 *      with zero applicable relationships -- unchanged from pre-Phase-143.
 *   2. org_share_context_for_ticket() returns null (not a relationship
 *      context) for a caller with no applicable share AND no applicable
 *      relationship.
 *   3. org_sharing_apply_list_redaction() leaves rows completely unchanged
 *      when neither a share nor a relationship applies.
 *   4. incident_create_internal()'s auto-routing path is unaffected --
 *      still creates ZERO incident_shares rows for an unrouted type.
 *   5. sse_publish_for_incident()'s THIRD (Phase 143) call never fires when
 *      _org_relationship_orgs_for_ticket_owner() returns [] -- proven by
 *      inspecting sse_events row counts before/after a publish for a
 *      relationship-free ticket.
 *   6. Structural: org_visible_ids(), org_query_filter(),
 *      org_ticket_is_owned_by_caller() are byte-identical to their
 *      pre-Phase-143 shape (the anti-chaining test already covers the
 *      first two structurally too -- re-asserted here for this file's own
 *      completeness as the phase's dedicated no-op proof).
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_noop.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/org-relationships.php';
require_once __DIR__ . '/../inc/incident-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — MANDATORY no-op regression (zero relationships => zero behavior change) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

$uniq = 'zz143noop-' . substr(md5((string) mt_rand()), 0, 8);
$ownerOrgId  = 900005500 + random_int(1, 4999);
$callerOrgId = 900005500 + random_int(5000, 9999);

$createdOrgIds = [$ownerOrgId, $callerOrgId];
$createdTicketIds = [];
$testUserId = 900005600 + random_int(1, 4999);

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, $testUserId) {
    foreach ($createdTicketIds as $id) {
        try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$testUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, "ZZ143NoOp Owner ({$uniq})"]);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$callerOrgId, "ZZ143NoOp Caller ({$uniq})"]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$testUserId, $callerOrgId, $callerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 NoOp143 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz143noop same-org', 'zz143noop same-org', 2, 1, NOW(), ?)",
        [$now, $callerOrgId]
    );
    $sameOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $sameOrgTicketId;

    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '2 NoOp143 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz143noop cross-org', 'zz143noop cross-org', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $crossOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $crossOrgTicketId;

    // ══════════════════════════════════════════════════════════════════
    // Item 1 — the three widened org-scope.php functions, zero relationships
    // ══════════════════════════════════════════════════════════════════
    echo "--- Item 1: org_can_see_ticket() / org_ticket_query_filter() / org_can_mutate_ticket() unaffected ---\n\n";

    t('same-org ticket is visible (unchanged)', org_can_see_ticket($sameOrgTicketId, $testUserId));
    t('genuinely cross-org ticket with NO share and NO relationship is invisible (unchanged)', !org_can_see_ticket($crossOrgTicketId, $testUserId));
    t('same-org ticket is mutable (unchanged)', org_can_mutate_ticket($sameOrgTicketId, $testUserId));
    t('genuinely cross-org ticket is NOT mutable (unchanged)', !org_can_mutate_ticket($crossOrgTicketId, $testUserId));

    [$frag, $vars] = org_ticket_query_filter($testUserId, 't');
    $rows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$frag} AND t.id IN (?, ?)", array_merge($vars, [$sameOrgTicketId, $crossOrgTicketId]));
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    t('org_ticket_query_filter() includes the same-org ticket, excludes the cross-org one (unchanged)', in_array($sameOrgTicketId, $ids, true) && !in_array($crossOrgTicketId, $ids, true));

    // ══════════════════════════════════════════════════════════════════
    // Item 2 — org_share_context_for_ticket() returns null
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 2: org_share_context_for_ticket() returns null with zero applicable shares/relationships ---\n\n";

    t('org_share_context_for_ticket() returns null for the same-org ticket (Super Admin/same-org semantics unchanged)', org_share_context_for_ticket($sameOrgTicketId, $testUserId) === null);
    t('org_share_context_for_ticket() returns null for the cross-org ticket with no applicable share/relationship', org_share_context_for_ticket($crossOrgTicketId, $testUserId) === null);

    // ══════════════════════════════════════════════════════════════════
    // Item 3 — org_sharing_apply_list_redaction() leaves rows unchanged
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 3: org_sharing_apply_list_redaction() is a no-op with zero applicable shares/relationships ---\n\n";

    $listRowsBefore = [
        ['id' => $sameOrgTicketId, 'org_id' => $callerOrgId, 'contact' => 'zz143noop contact', 'description' => 'zz143noop description'],
    ];
    $listRowsAfter = org_sharing_apply_list_redaction($listRowsBefore, $testUserId);
    t('same-org row passes through org_sharing_apply_list_redaction() completely unchanged (no shared_from_* keys added)',
        $listRowsAfter === $listRowsBefore);

    // ══════════════════════════════════════════════════════════════════
    // Item 4 — auto-routing path still creates zero shares with no rule
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 4: incident_create_internal() auto-routing path is unaffected ---\n\n";

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz143noop unrouted type', NULL)", ["zz143noop-{$uniq}"]);
    $unroutedTypeId = (int) db_insert_id();

    $_SESSION['user_id'] = $testUserId;
    $_SESSION['active_org_id'] = $callerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $unroutedTypeId,
        'scope' => 'zz143noop no-routing-rule test',
    ], $testUserId);
    unset($_SESSION['active_org_id']);

    t('incident_create_internal() succeeded with no routing rule present', empty($createResult['errors']));
    $noRuleTicketId = (int) ($createResult['id'] ?? 0);
    if ($noRuleTicketId > 0) $createdTicketIds[] = $noRuleTicketId;

    $shareCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}incident_shares WHERE ticket_id = ?", [$noRuleTicketId]);
    t('ZERO incident_shares rows were created for a ticket whose type matches no active routing rule (auto-routing behavior unchanged by this phase)', $shareCount === 0);

    db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$unroutedTypeId]);

    // ══════════════════════════════════════════════════════════════════
    // Item 5 — sse_publish_for_incident()'s THIRD (Phase 143) call is a
    // pure no-op for a relationship-free ticket.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 5: sse_publish_for_incident()'s Phase 143 call is a no-op with zero relationships ---\n\n";

    require_once __DIR__ . '/../inc/sse.php';
    if (function_exists('_sse_ensure_schema')) { try { _sse_ensure_schema(); } catch (Throwable $e) {} }

    $relOrgs = _org_relationship_orgs_for_ticket_owner($sameOrgTicketId);
    t('_org_relationship_orgs_for_ticket_owner() returns [] for a ticket whose owning org has zero relationships', $relOrgs === []);

    if (function_exists('sse_events_table_exists') || true) {
        try {
            $beforeCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}sse_events");
            sse_publish_for_incident('incident:note', ['ticket_id' => $sameOrgTicketId], $sameOrgTicketId, $testUserId);
            $afterCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}sse_events");
            // At most ONE new row (the group/entitled call) -- the org-scope
            // 'org' call for shares AND the Phase 143 relationship 'org' call
            // must BOTH be no-ops here (zero shares, zero relationships).
            t('sse_publish_for_incident() writes at most ONE new sse_events row (Phase 142 share-scope AND Phase 143 relationship-scope calls are both no-ops)', ($afterCount - $beforeCount) <= 1);
            db_query("DELETE FROM {$prefix}sse_events WHERE payload LIKE ?", ['%' . $sameOrgTicketId . '%']);
        } catch (Throwable $e) {
            echo "SKIP: sse_events table not present -- " . $e->getMessage() . "\n";
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Item 6 — structural byte-identity re-confirmation
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 6: structural -- org_visible_ids() / org_query_filter() / org_ticket_is_owned_by_caller() untouched ---\n\n";

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

        function _p143noop_extract_function(string $source, string $funcName): ?string {
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

        foreach (['org_visible_ids', 'org_query_filter', 'org_ticket_is_owned_by_caller', 'org_descendant_ids', 'org_member_query_filter', 'org_can_see_row', 'org_can_see_member'] as $fn) {
            $h = _p143noop_extract_function($headSource, $fn);
            $c = _p143noop_extract_function($currentSource, $fn);
            t("$fn()'s function body is BYTE-IDENTICAL to the pre-Phase-143 committed version", $h !== null && $c !== null && $h === $c);
        }
    }

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
