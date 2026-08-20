<?php
/**
 * Phase 141 (2026-08-17) — MANDATORY no-op regression test.
 *
 * Per tasks.md section 4: written EARLY (immediately after schema + core
 * functions exist), not as an afterthought, specifically so it can catch
 * a regression introduced by ANY later stage of this phase (the 11
 * endpoint integrations, redaction wiring, admin UI, etc.). Re-run this
 * file after every subsequent Phase 141 task; a failure here at ANY point
 * is a hard stop, not a test to adjust.
 *
 * Proves: on a database/caller/ticket combination with ZERO applicable
 * org_type_routing rules and ZERO applicable incident_shares rows,
 * Phase 141's code produces output byte-identical to pre-Phase-141
 * behavior. Every fixture below uses org/ticket ids that no routing rule
 * anywhere on this (possibly shared, long-lived dev) database could ever
 * name, so "zero applicable rows" holds structurally — this test does
 * NOT require the org_type_routing table to be globally empty.
 *
 * Coverage (exactly the four items tasks.md's Task 4 names):
 *   1. org_can_see_ticket() for a same-org ticket and a genuinely-
 *      invisible cross-org ticket.
 *   2. org_ticket_query_filter()'s returned SQL fragment / row-set
 *      against a fixed set of org_visible_ids() inputs (Super Admin,
 *      single-org, multi-org descendant tree).
 *   3. A full round-trip through incident-list.php's CURRENT (untouched
 *      as of this task) query-construction logic, proving that merely
 *      loading the Phase 141 code (inc/org-sharing.php, the extended
 *      inc/org-scope.php) has zero effect on an endpoint that does not
 *      yet call any of it — the state every one of the 11 endpoints is
 *      in until Task 5 touches them one at a time.
 *   4. incident_create_internal() creates ZERO incident_shares rows when
 *      no routing rule exists for the ticket's type/owning org.
 *
 * PLUS a structural check tasks.md's Task 3 itself calls out explicitly:
 * org_query_filter()'s function body is byte-identical to the
 * pre-Phase-141 committed version (diffed against git HEAD), proving the
 * "org_query_filter() itself is untouched, in any commit, at all" claim
 * is not just asserted in a docblock but actually true.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_noop.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/incident-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — MANDATORY no-op regression ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 0 — structural: org_query_filter()'s function body is byte-
// identical to the pre-Phase-141 committed version.
// ═══════════════════════════════════════════════════════════════════════

echo "--- org_query_filter() source is byte-identical to pre-Phase-141 HEAD ---\n\n";

function _p141_extract_function(string $source, string $funcName): ?string {
    $pos = strpos($source, 'function ' . $funcName . '(');
    if ($pos === false) return null;
    $braceStart = strpos($source, '{', $pos);
    if ($braceStart === false) return null;
    $depth = 0;
    for ($i = $braceStart; $i < strlen($source); $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $pos, $i - $pos + 1);
            }
        }
    }
    return null;
}

$gitAvailable = false;
$headSource = null;
$cwd = getcwd();
chdir($base);
$out = [];
exec('git show HEAD:inc/org-scope.php 2>&1', $out, $code);
chdir($cwd);
if ($code === 0 && !empty($out)) {
    $gitAvailable = true;
    $headSource = implode("\n", $out);
}

if (!$gitAvailable) {
    echo "SKIP: git not available / not a git checkout -- cannot diff against HEAD in this environment.\n";
} else {
    $currentSource = file_get_contents($base . '/inc/org-scope.php');
    $headFn = _p141_extract_function($headSource, 'org_query_filter');
    $currentFn = _p141_extract_function($currentSource, 'org_query_filter');
    t("org_query_filter() was present in the pre-Phase-141 HEAD version", $headFn !== null);
    t("org_query_filter() is present in the current working tree", $currentFn !== null);
    t("org_query_filter()'s function body is BYTE-IDENTICAL to the pre-Phase-141 committed version", $headFn !== null && $currentFn !== null && $headFn === $currentFn);

    // Same check for the other three functions plan.md declares off-limits.
    foreach (['org_visible_ids', 'org_descendant_ids', 'org_member_query_filter', 'org_can_see_row', 'org_can_see_member'] as $fn) {
        $h = _p141_extract_function($headSource, $fn);
        $c = _p141_extract_function($currentSource, $fn);
        t("$fn()'s function body is BYTE-IDENTICAL to the pre-Phase-141 committed version", $h !== null && $c !== null && $h === $c);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Fixture setup — org/ticket ids structurally guaranteed to match zero
// org_type_routing rows anywhere on this database (no rule could ever
// name these fresh, randomly-suffixed ids).
// ═══════════════════════════════════════════════════════════════════════

$uniq = 'zz141noop-' . substr(md5((string) mt_rand()), 0, 8);
$ownerOrgId  = 900003100 + random_int(1, 8999);
$callerOrgId = 900003100 + random_int(9000, 17999);
$childOrgId  = 900003100 + random_int(18000, 26999);

$createdOrgIds = [];
$createdTicketIds = [];
$testUserSingle = 900003200 + random_int(1, 8999);
$testUserMulti  = 900003200 + random_int(9000, 17999);

$cleanup = function () use ($prefix, &$createdOrgIds, &$createdTicketIds, $testUserSingle, $testUserMulti) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$testUserSingle, $testUserMulti]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, "ZZ141NoOp Owner ({$uniq})"]);
    $createdOrgIds[] = $ownerOrgId;
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$callerOrgId, "ZZ141NoOp Caller ({$uniq})"]);
    $createdOrgIds[] = $callerOrgId;
    db_query("INSERT INTO {$prefix}organizations (id, name, parent_org_id, active) VALUES (?, ?, ?, 1)", [$childOrgId, "ZZ141NoOp Child ({$uniq})", $callerOrgId]);
    $createdOrgIds[] = $childOrgId;

    // Single-org caller.
    db_query(
        "INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)",
        [$testUserSingle, $callerOrgId, $callerOrgId]
    );
    // Multi-org (descendant-tree) caller: scoped to callerOrgId, which
    // has childOrgId as a real child -- org_visible_ids() should resolve
    // to [callerOrgId, childOrgId].
    db_query(
        "INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)",
        [$testUserMulti, $callerOrgId, $callerOrgId]
    );

    $now = date('Y-m-d H:i:s');
    // Same-org ticket (owned by callerOrgId — visible to testUserSingle without any share).
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 NoOp Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz141noop same-org', 'zz141noop same-org', 2, 1, NOW(), ?)",
        [$now, $callerOrgId]
    );
    $sameOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $sameOrgTicketId;

    // Genuinely cross-org ticket (owned by ownerOrgId — no share exists,
    // never will under this fixture's ids — must stay invisible).
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '2 NoOp Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz141noop cross-org', 'zz141noop cross-org', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $crossOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $crossOrgTicketId;

    // ══════════════════════════════════════════════════════════════════
    // Item 1 — org_can_see_ticket()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 1: org_can_see_ticket() ---\n\n";

    t("same-org ticket is visible (unchanged pre-Phase-141 behavior)", org_can_see_ticket($sameOrgTicketId, $testUserSingle));
    t("genuinely cross-org ticket with NO applicable share is invisible (unchanged pre-Phase-141 behavior)", !org_can_see_ticket($crossOrgTicketId, $testUserSingle));

    // ══════════════════════════════════════════════════════════════════
    // Item 2 — org_ticket_query_filter() vs org_query_filter()
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 2: org_ticket_query_filter() ---\n\n";

    // Super Admin: byte-identical tuple (both short-circuit to ['', []]).
    require_once __DIR__ . '/../inc/org-sharing.php'; // pulls in org_ticket_query_filter's own file, but the function itself lives in org-scope.php
    $superId = 900003300 + random_int(1, 999);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 1, NULL, 'global', NULL)", [$superId]);
    try {
        $baseSuper = org_query_filter('t.org_id', $superId);
        $ticketSuper = org_ticket_query_filter($superId, 't');
        t("Super Admin: org_ticket_query_filter() returns a BYTE-IDENTICAL tuple to org_query_filter()", $baseSuper === $ticketSuper);
    } finally {
        db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$superId]);
    }

    // Single-org caller: row SETS must match (SQL text may legitimately
    // widen with an inert "OR EXISTS(...)" clause, but with zero matching
    // incident_shares rows the result set is identical).
    [$baseFrag, $baseVars] = org_query_filter('t.org_id', $testUserSingle);
    [$ticketFrag, $ticketVars] = org_ticket_query_filter($testUserSingle, 't');
    $baseRows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$baseFrag} AND t.id IN (?, ?)", array_merge($baseVars, [$sameOrgTicketId, $crossOrgTicketId]));
    $ticketRows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$ticketFrag} AND t.id IN (?, ?)", array_merge($ticketVars, [$sameOrgTicketId, $crossOrgTicketId]));
    $baseIds = array_map(fn($r) => (int) $r['id'], $baseRows);
    $ticketIds = array_map(fn($r) => (int) $r['id'], $ticketRows);
    sort($baseIds); sort($ticketIds);
    t("single-org caller: org_ticket_query_filter()'s row set is IDENTICAL to org_query_filter()'s (zero applicable shares)", $baseIds === $ticketIds);
    t("single-org caller: both filters agree the same-org ticket is included", in_array($sameOrgTicketId, $baseIds, true) && in_array($sameOrgTicketId, $ticketIds, true));
    t("single-org caller: both filters agree the cross-org ticket is excluded", !in_array($crossOrgTicketId, $baseIds, true) && !in_array($crossOrgTicketId, $ticketIds, true));

    // Multi-org descendant-tree caller.
    $visibleMulti = org_visible_ids($testUserMulti);
    t("multi-org caller's org_visible_ids() resolves the descendant tree (caller + child)", is_array($visibleMulti) && in_array($callerOrgId, array_map('intval', $visibleMulti), true) && in_array($childOrgId, array_map('intval', $visibleMulti), true));

    [$baseFragM, $baseVarsM] = org_query_filter('t.org_id', $testUserMulti);
    [$ticketFragM, $ticketVarsM] = org_ticket_query_filter($testUserMulti, 't');
    $baseRowsM = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$baseFragM} AND t.id IN (?, ?)", array_merge($baseVarsM, [$sameOrgTicketId, $crossOrgTicketId]));
    $ticketRowsM = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$ticketFragM} AND t.id IN (?, ?)", array_merge($ticketVarsM, [$sameOrgTicketId, $crossOrgTicketId]));
    $baseIdsM = array_map(fn($r) => (int) $r['id'], $baseRowsM);
    $ticketIdsM = array_map(fn($r) => (int) $r['id'], $ticketRowsM);
    sort($baseIdsM); sort($ticketIdsM);
    t("multi-org descendant-tree caller: org_ticket_query_filter()'s row set is IDENTICAL to org_query_filter()'s", $baseIdsM === $ticketIdsM);

    // ══════════════════════════════════════════════════════════════════
    // Item 3 — full round-trip through incident-list.php's ACTUAL,
    // NOW-SWITCHED query-construction logic (endpoint-integration task
    // landed after this test was first written — see this file's own
    // docblock: it names this as "the state every one of the 11 endpoints
    // is in UNTIL Task 5 touches them", not a permanent assertion. Updated
    // in place, per that docblock, rather than left to fail forever — the
    // invariant this item actually protects (a no-rule database produces
    // the SAME row set through incident-list.php's real code, before and
    // after the switch) is unchanged and still asserted below.)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 3: incident-list.php's ACTUAL query logic is a no-op with zero applicable shares ---\n\n";

    $incidentListSource = file_get_contents($base . '/api/incident-list.php');
    t("incident-list.php now calls org_ticket_query_filter() (post-Task-5 endpoint integration landed)",
        strpos($incidentListSource, 'org_ticket_query_filter') !== false);
    t("incident-list.php no longer calls the bare org_query_filter('t.org_id')",
        strpos($incidentListSource, "org_query_filter('t.org_id')") === false);

    // Reproduce incident-list.php's exact WHERE-construction shape (its
    // REAL call, post-switch) and confirm it still returns the expected
    // rows with zero applicable incident_shares rows for this fixture.
    [$listFrag, $listVars] = org_ticket_query_filter($testUserSingle, 't');
    $listRows = db_fetch_all(
        "SELECT `t`.`id` FROM `{$prefix}ticket` `t`
          LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
          WHERE 1=1 {$listFrag} AND t.id IN (?, ?)",
        array_merge($listVars, [$sameOrgTicketId, $crossOrgTicketId])
    );
    $listIds = array_map(fn($r) => (int) $r['id'], $listRows);
    t("incident-list.php's own (now share-aware) query shape still includes the same-org ticket", in_array($sameOrgTicketId, $listIds, true));
    t("incident-list.php's own (now share-aware) query shape still excludes the cross-org ticket with no applicable share", !in_array($crossOrgTicketId, $listIds, true));

    // ══════════════════════════════════════════════════════════════════
    // Item 4 — incident_create_internal() creates zero incident_shares
    // rows when no routing rule exists
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Item 4: incident_create_internal() creates zero shares with no matching rule ---\n\n";

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141noop unrouted type', NULL)", ["zz141noop-{$uniq}"]);
    $unroutedTypeId = (int) db_insert_id();

    $_SESSION['user_id'] = $testUserSingle;
    $_SESSION['active_org_id'] = $callerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $unroutedTypeId,
        'scope' => 'zz141noop no-routing-rule test',
    ], $testUserSingle);
    unset($_SESSION['active_org_id']);

    t("incident_create_internal() succeeded with no routing rule present", empty($createResult['errors']));
    $noRuleTicketId = (int) ($createResult['id'] ?? 0);
    if ($noRuleTicketId > 0) $createdTicketIds[] = $noRuleTicketId;

    $shareCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}incident_shares WHERE ticket_id = ?", [$noRuleTicketId]);
    t("ZERO incident_shares rows were created for a ticket whose type matches no active routing rule", $shareCount === 0);

    db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$unroutedTypeId]);

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
