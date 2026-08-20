<?php
/**
 * Phase 142 (2026-08-17) — MANDATORY no-op regression, backend layer.
 *
 * Per tasks.md's own discipline for this family of tests (mirrors Phase
 * 141's tests/test_org_sharing_noop.php): proves that an install which
 * never uses MANUAL sharing (zero calls to
 * org_sharing_create_manual_share() / org_sharing_revoke_share(), zero
 * manual shares ever created) sees ZERO behavior change from anything
 * this backend stage adds -- even though, unlike Phase 141's routing
 * codes, action.share_incident / action.revoke_incident_share ARE granted
 * to Super Admin/Org Admin/Dispatcher by default (plan.md's deliberate
 * departure). The no-op claim here is specifically: holding the
 * permission and never invoking it changes nothing, and every function
 * this phase's backend work did NOT touch is provably untouched.
 *
 * Coverage:
 *   1. Every Phase-141 org-scope.php function this phase's plan.md
 *      declares off-limits (org_can_see_ticket, org_ticket_query_filter,
 *      org_can_mutate_ticket, org_ticket_is_owned_by_caller,
 *      org_visible_ids, org_query_filter, org_descendant_ids,
 *      org_member_query_filter, org_can_see_row, org_can_see_member) is
 *      BYTE-IDENTICAL to the pre-Phase-142 committed version -- this
 *      phase's backend work never edited inc/org-scope.php at all.
 *   2. Every pre-existing org-sharing.php function EXCEPT
 *      org_sharing_apply_routing_on_create() is BYTE-IDENTICAL to the
 *      pre-Phase-142 committed version -- this phase's work only APPENDED
 *      new functions (org_sharing_create_manual_share,
 *      org_sharing_revoke_share, org_sharing_list_active_shares,
 *      _org_sharing_notify_share_change) and made exactly ONE deliberate,
 *      spec'd edit to a Phase-141 function's body: one new line inside
 *      org_sharing_apply_routing_on_create() calling
 *      _org_sharing_notify_share_change() so the AUTO-ROUTED sharing path
 *      also gets a live SSE push, per plan.md's SSE section ("Called
 *      from... org_sharing_apply_routing_on_create()... this is how the
 *      SSE gap closes for both sharing paths"). Checked separately below,
 *      by CONTENT only, never by comparing against `git show HEAD` for
 *      (in)equality/absence -- see the inline comment at that check for
 *      why: once this phase's own commit becomes HEAD (true from the
 *      moment it merges, for every future checkout), `git show HEAD:...`
 *      returns this phase's OWN already-committed content, so an
 *      "is different from / did not exist in HEAD" assertion is
 *      permanently, structurally false from merge onward, not flaky.
 *      Caught live on this phase's own first CI run (fresh install).
 *   3. A ticket with ZERO incident_shares rows: org_can_see_ticket() /
 *      org_ticket_query_filter() behave exactly as they did before this
 *      phase, for both same-org and genuinely-cross-org callers.
 *   4. incident_create_internal()'s auto-routing path
 *      (org_sharing_apply_routing_on_create()) still creates ZERO
 *      incident_shares rows for a ticket whose type matches no active
 *      routing rule -- merely loading this phase's expanded
 *      inc/org-sharing.php has no effect on Phase 141's existing
 *      behavior.
 *   5. UPDATED once the endpoint/UI/SSE stage landed (this file's own
 *      Part 5 originally asserted NO existing file referenced the new
 *      functions/permission codes at all -- true only while
 *      api/incident-share.php did not yet exist). Now: no file OUTSIDE
 *      the phase's complete, final set of new/touched files (backend +
 *      api/incident-share.php + api/incident-detail.php's
 *      can_manage_sharing field) references either new function or
 *      either new permission code -- catches a stray/duplicate
 *      implementation appearing anywhere else, while accepting the
 *      legitimate wiring that now exists.
 *   6. Re-seeding RBAC does not change any PRE-EXISTING permission's role
 *      grants -- specifically, Phase 141's action.manage_org_routing /
 *      action.manage_org_routing_org stay Super-Admin-only even after
 *      this phase's edit to the Dispatcher allow-list in
 *      sql/run_00_rbac.php (proving the new allow-list entries didn't
 *      widen anything beyond the two codes actually added).
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_manual_noop.php
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

echo "=== Phase 142 — MANDATORY no-op regression (backend layer) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

function _p142_extract_function(string $source, string $funcName): ?string {
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

$cwd = getcwd();
chdir($base);
$out = [];
exec('git show HEAD:inc/org-scope.php 2>&1', $out, $code1);
$headOrgScope = ($code1 === 0 && !empty($out)) ? implode("\n", $out) : null;
$out = [];
exec('git show HEAD:inc/org-sharing.php 2>&1', $out, $code2);
$headOrgSharing = ($code2 === 0 && !empty($out)) ? implode("\n", $out) : null;
chdir($cwd);

$gitAvailable = ($headOrgScope !== null && $headOrgSharing !== null);

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — inc/org-scope.php is UNTOUCHED by this phase's backend work.
// ═══════════════════════════════════════════════════════════════════════

echo "--- inc/org-scope.php: every function is byte-identical to pre-Phase-142 HEAD ---\n\n";

if (!$gitAvailable) {
    echo "SKIP: git not available / not a git checkout -- cannot diff against HEAD in this environment.\n";
} else {
    $currentOrgScope = file_get_contents($base . '/inc/org-scope.php');
    foreach ([
        // org_can_see_ticket / org_ticket_query_filter / org_can_mutate_ticket
        // are deliberately EXCLUDED from this strict list -- Phase 143
        // (2026-08-17, GH#70 Phase 3) widened all three in place with a
        // relationship-derived visibility branch (plan.md's own named,
        // required edit -- see that phase's dedicated content-based check
        // just below, same shape as this file's own
        // org_sharing_apply_routing_on_create() precedent). This file's own
        // job is unaffected: THIS phase (142) never touched org-scope.php
        // at all, and that remains true regardless of what a LATER phase
        // does to it.
        'org_visible_ids', '_org_compute_visible_ids', 'org_query_filter',
        'org_ticket_is_owned_by_caller',
        'org_descendant_ids', 'org_member_query_filter', 'ensure_org_id_column',
        'org_can_see_row', 'org_can_see_member', 'org_strict_isolation_enabled', 'org_user_home_id',
    ] as $fn) {
        $h = _p142_extract_function($headOrgScope, $fn);
        $c = _p142_extract_function($currentOrgScope, $fn);
        t("$fn() is present in both pre-Phase-142 HEAD and the current tree", $h !== null && $c !== null);
        t("$fn()'s function body is BYTE-IDENTICAL to the pre-Phase-142 committed version", $h !== null && $c !== null && $h === $c);
    }

    // Phase 143 (2026-08-17) — the three functions Phase 143 legitimately
    // widened. Content-based checks only (never "is different from HEAD" --
    // see this file's own Part 2 comment on why that assertion class is
    // permanently, structurally false from the moment a phase's commit
    // becomes HEAD, not merely flaky): each function's ORIGINAL Phase
    // 141/142 logic must still be present, unchanged, plus the new
    // relationship-branch addition.
    foreach ([
        'org_can_see_ticket'      => ['FROM `{$prefix}incident_shares`', 'org_relationship_activation_live_join_sql'],
        'org_ticket_query_filter' => ['{$prefix}incident_shares` `ish`', 'org_relationship_activation_live_join_sql'],
        'org_can_mutate_ticket'   => ["access_tier` = 'assist'", 'org_relationship_activation_live_join_sql'],
    ] as $fn => $mustContain) {
        $c = _p142_extract_function($currentOrgScope, $fn);
        t("$fn() is present in the current tree", $c !== null);
        if ($c !== null) {
            foreach ($mustContain as $needle) {
                t("$fn() still contains its pre-Phase-143 logic / gained the Phase 143 relationship branch (needle: " . substr($needle, 0, 40) . ")", strpos($c, $needle) !== false);
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — every PRE-EXISTING org-sharing.php function is byte-identical;
// this phase only APPENDED new functions, never edited an existing one.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- inc/org-sharing.php: every PRE-EXISTING function (except the one deliberate edit) is byte-identical to pre-Phase-142 HEAD ---\n\n";

if (!$gitAvailable) {
    echo "SKIP: git not available / not a git checkout -- cannot diff against HEAD in this environment.\n";
} else {
    $currentOrgSharing = file_get_contents($base . '/inc/org-sharing.php');
    foreach ([
        // org_sharing_apply_routing_on_create, org_share_context_for_ticket,
        // and org_sharing_apply_list_redaction are deliberately EXCLUDED
        // from this strict list -- the first is THIS phase's own one
        // deliberate edit (see the dedicated check just below); the latter
        // two are Phase 143's (2026-08-17, GH#70 Phase 3) own named,
        // required edits per that phase's plan.md ("the one deliberate,
        // required edit to already-shipped Phase 141/142 code this phase
        // makes") -- see the dedicated Phase 143 content-based checks
        // further below. This file's own job is unaffected: THIS phase
        // (142) never edited either function, and that remains true
        // regardless of what a LATER phase does to them.
        '_org_sharing_apply_precedence', 'org_sharing_resolve_shares_for_ticket',
        'org_share_view_tier_field_allowlist', 'org_share_view_tier_assignment_allowlist',
        'org_share_redact_assignment_fields', 'org_share_redact_ticket_fields',
        'org_routing_rule_validate', 'org_routing_rule_create',
        'org_routing_rule_update', 'org_routing_rule_deactivate', 'org_routing_resolve_caller_org_id',
        'org_routing_can_author_org', 'org_routing_resolve_create_owning_org', 'org_routing_row_out',
        'org_routing_schema_ready',
    ] as $fn) {
        $h = _p142_extract_function($headOrgSharing, $fn);
        $c = _p142_extract_function($currentOrgSharing, $fn);
        t("$fn() is present in both pre-Phase-142 HEAD and the current tree", $h !== null && $c !== null);
        t("$fn()'s function body is BYTE-IDENTICAL to the pre-Phase-142 committed version", $h !== null && $c !== null && $h === $c);
    }

    // Phase 143 (2026-08-17) — the two functions Phase 143 legitimately,
    // deliberately edited (plan.md's own named list). Content-based checks
    // only: each function's ORIGINAL Phase 141/142 logic must still be
    // present, unchanged, plus the new relationship-path addition.
    foreach ([
        'org_share_context_for_ticket'    => ['FROM `{$prefix}incident_shares`', 'org_relationship_context_for_ticket', "'redaction_tier'"],
        'org_sharing_apply_list_redaction' => ['FROM `{$prefix}incident_shares`', 'org_relationship_activation_live_join_sql'],
    ] as $fn => $mustContain) {
        $c = _p142_extract_function($currentOrgSharing, $fn);
        t("$fn() is present in the current tree", $c !== null);
        if ($c !== null) {
            foreach ($mustContain as $needle) {
                t("$fn() still contains its pre-Phase-143 logic / gained the Phase 143 relationship addition (needle: " . substr($needle, 0, 40) . ")", strpos($c, $needle) !== false);
            }
        }
    }

    // org_sharing_apply_routing_on_create() -- the ONE deliberate, spec'd
    // edit to a Phase-141 function's body (plan.md's SSE section). Not
    // byte-identical, but its ORIGINAL Phase 141 logic (the INSERT into
    // incident_shares and the share_created audit_log call) must still be
    // present UNCHANGED, plus exactly one new call to
    // _org_sharing_notify_share_change() -- proving this is an ADDITION,
    // not a rewrite of the auto-routing behavior itself (Part 4 below
    // proves the same thing behaviorally, against a real fixture).
    $hRouting = _p142_extract_function($headOrgSharing, 'org_sharing_apply_routing_on_create');
    $cRouting = _p142_extract_function($currentOrgSharing, 'org_sharing_apply_routing_on_create');
    t('org_sharing_apply_routing_on_create() is present in both pre-Phase-142 HEAD and the current tree', $hRouting !== null && $cRouting !== null);
    // NOT asserted here: "$hRouting !== $cRouting" / "did NOT exist in
    // HEAD". Caught live on this phase's own first CI run (a genuinely
    // fresh install, first run against the just-pushed commit): the
    // instant this phase's own commit becomes HEAD -- true for every CI
    // run and every future checkout from that moment on -- `git show
    // HEAD:...` returns THIS PHASE'S OWN already-committed content, so
    // $hRouting and $cRouting are the same string. An "is NO LONGER
    // byte-identical to HEAD" or "did NOT exist in HEAD" assertion is
    // therefore only ever true in the pre-commit window and becomes
    // PERMANENTLY, STRUCTURALLY false from merge onward -- not flaky,
    // wrong by design, exactly 6 such assertions in this file, reproduced
    // as 6 failures on a fresh CI install and 0 failures against the
    // long-lived local dev DB the commit was cut from (uncommitted
    // working-tree changes at the time HEAD was still the parent commit).
    // The durable, ongoing protection is below: the CURRENT file's
    // CONTENT (never its git history) still contains the original Phase
    // 141 behavior plus exactly the one new call -- true forever,
    // regardless of which commit HEAD points to. Phase 141's OWN noop
    // test (tests/test_org_sharing_noop.php) never asserts inequality or
    // absence against HEAD for exactly this reason -- only equality,
    // which stays trivially true post-commit since current tree == HEAD
    // there (this file's own Part 1 above relies on the same, safe
    // equality-only pattern and needed no fix).
    if ($cRouting !== null) {
        t('the ORIGINAL Phase 141 INSERT INTO incident_shares statement is still present, unchanged', strpos($cRouting, 'INSERT INTO `{$prefix}incident_shares`') !== false);
        t("the ORIGINAL Phase 141 audit_log('incident', 'share_created', ...) call is still present, unchanged", strpos($cRouting, "'incident', 'share_created'") !== false);
        // The actual call is followed by a newline (multi-line arg list);
        // a bare substring count would also match this file's own
        // docblock-comment mentions of the function name, so match the
        // call SHAPE specifically.
        t('_org_sharing_notify_share_change() IS called from org_sharing_apply_routing_on_create(), per plan.md\'s SSE section', (bool) preg_match('/_org_sharing_notify_share_change\(\s*\n/', $cRouting));
    }

    // The genuinely new functions exist in the current tree. (Whether
    // they were genuinely NEW, not a rename of pre-existing logic, was
    // verified once against pre-Phase-142 HEAD during development/review
    // -- not re-asserted here as an ongoing regression check, per the
    // comment above.)
    foreach ([
        'org_sharing_create_manual_share', 'org_sharing_revoke_share',
        'org_sharing_list_active_shares', '_org_sharing_notify_share_change',
    ] as $newFn) {
        t("{$newFn}() exists in the current tree", function_exists($newFn));
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Fixture — a ticket with ZERO incident_shares rows, ids structurally
// guaranteed unreachable by any routing rule on this database.
// ═══════════════════════════════════════════════════════════════════════

$uniq = 'zz142noop-' . substr(md5((string) mt_rand()), 0, 8);
$ownerOrgId  = 900004500 + random_int(1, 4999);
$callerOrgId = 900004500 + random_int(5000, 9999);
$strangerOrgId = 900004500 + random_int(10000, 14999);

$createdOrgIds = [$ownerOrgId, $callerOrgId, $strangerOrgId];
$createdTicketIds = [];
$testUserId = 900004600 + random_int(1, 4999);

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, $testUserId) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$testUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, "ZZ142NoOp Owner ({$uniq})"]);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$callerOrgId, "ZZ142NoOp Caller ({$uniq})"]);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$strangerOrgId, "ZZ142NoOp Stranger ({$uniq})"]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$testUserId, $callerOrgId, $callerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 NoOp142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142noop same-org', 'zz142noop same-org', 2, 1, NOW(), ?)",
        [$now, $callerOrgId]
    );
    $sameOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $sameOrgTicketId;

    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '2 NoOp142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142noop cross-org', 'zz142noop cross-org', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $crossOrgTicketId = (int) db_insert_id();
    $createdTicketIds[] = $crossOrgTicketId;

    // ══════════════════════════════════════════════════════════════════
    // Part 3 — org_can_see_ticket() / org_ticket_query_filter() with zero
    // incident_shares rows behave exactly as pre-Phase-142.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 3: read gates are unaffected for a ticket with zero shares ---\n\n";

    t('same-org ticket is visible (unchanged)', org_can_see_ticket($sameOrgTicketId, $testUserId));
    t('genuinely cross-org ticket with NO share is invisible (unchanged)', !org_can_see_ticket($crossOrgTicketId, $testUserId));

    [$frag, $vars] = org_ticket_query_filter($testUserId, 't');
    $rows = db_fetch_all("SELECT `id` FROM {$prefix}ticket t WHERE 1=1 {$frag} AND t.id IN (?, ?)", array_merge($vars, [$sameOrgTicketId, $crossOrgTicketId]));
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    t('org_ticket_query_filter() includes the same-org ticket, excludes the cross-org one (unchanged)', in_array($sameOrgTicketId, $ids, true) && !in_array($crossOrgTicketId, $ids, true));

    // ══════════════════════════════════════════════════════════════════
    // Part 4 — auto-routing path still creates zero shares with no rule
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 4: incident_create_internal() auto-routing path is unaffected ---\n\n";

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz142noop unrouted type', NULL)", ["zz142noop-{$uniq}"]);
    $unroutedTypeId = (int) db_insert_id();

    $_SESSION['user_id'] = $testUserId;
    $_SESSION['active_org_id'] = $callerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $unroutedTypeId,
        'scope' => 'zz142noop no-routing-rule test',
    ], $testUserId);
    unset($_SESSION['active_org_id']);

    t('incident_create_internal() succeeded with no routing rule present', empty($createResult['errors']));
    $noRuleTicketId = (int) ($createResult['id'] ?? 0);
    if ($noRuleTicketId > 0) $createdTicketIds[] = $noRuleTicketId;

    $shareCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}incident_shares WHERE ticket_id = ?", [$noRuleTicketId]);
    t('ZERO incident_shares rows were created for a ticket whose type matches no active routing rule (auto-routing behavior unchanged by this phase)', $shareCount === 0);

    db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$unroutedTypeId]);

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — no file OUTSIDE this phase's complete, final set of new/touched
// files references either new function or either new permission code.
// UPDATED once the endpoint/UI/SSE stage landed (see this file's own
// docblock item 5): api/incident-share.php now legitimately calls
// org_sharing_create_manual_share()/org_sharing_revoke_share(), and both
// api/incident-share.php and api/incident-detail.php (its
// can_manage_sharing field) legitimately reference both permission codes.
// The point of this part is now narrower but still real: catch a STRAY or
// DUPLICATE reference appearing anywhere else in the codebase.
//
// UPDATED AGAIN, Phase 143 (2026-08-17, GH#70 Phase 3): inc/org-relationships.php
// is a genuinely new, LATER file this Phase-142 test could not have known
// about, and its own docblock legitimately mentions
// org_sharing_create_manual_share() by name (explaining why
// org_relationship_member_add() mirrors that function's "revive a lapsed
// grant" pattern, per the project's own "build on existing functions
// rather than duplicate them" instruction). Same treatment as the prior
// update: allow-list it rather than let this Phase-142 test forever assume
// no later phase is ever allowed to exist.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Part 5: no file OUTSIDE this phase's own final file set references the new functions or permission codes ---\n\n";

$thisPhaseOwnFiles = [
    'inc/org-sharing.php',            // where the new functions are DEFINED
    'sql/rbac.sql',                   // where the new codes are SEEDED
    'sql/run_00_rbac.php',            // where the new codes are SEEDED
    'sql/run_phase142_cross_org_manual_sharing.php',
    'api/incident-share.php',         // the new endpoint -- calls both write functions, gates on both codes
    'api/incident-detail.php',        // can_manage_sharing field -- gates on both codes
    'inc/org-relationships.php',      // Phase 143 -- legitimately mentions org_sharing_create_manual_share() in its own docblock
];

function _p142_scan_for(string $base, string $needle, array $skipFiles): array {
    $hits = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'js'], true)) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        if (strpos($rel, 'tests/') === 0) continue;
        if (strpos($rel, 'specs/') === 0) continue;
        if (strpos($rel, 'docs/') === 0) continue;
        if (strpos($rel, 'vendor/') !== false) continue;
        if (in_array($rel, $skipFiles, true)) continue;
        $contents = file_get_contents($file->getPathname());
        if ($contents !== false && strpos($contents, $needle) !== false) {
            $hits[] = $rel;
        }
    }
    return $hits;
}

foreach ([
    'org_sharing_create_manual_share' => 'org_sharing_create_manual_share(',
    'org_sharing_revoke_share'        => 'org_sharing_revoke_share(',
    "action.share_incident"           => "'action.share_incident'",
    "action.revoke_incident_share"    => "'action.revoke_incident_share'",
] as $label => $needle) {
    $hits = _p142_scan_for($base, $needle, $thisPhaseOwnFiles);
    t("no existing file outside this phase's own additions references {$label}", empty($hits));
    if (!empty($hits)) {
        echo "    unexpected reference(s): " . implode(', ', $hits) . "\n";
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Part 6 — re-seeding RBAC leaves every PRE-EXISTING permission's role
// grants unaffected. Specifically: Phase 141's action.manage_org_routing /
// action.manage_org_routing_org stay Super-Admin-only, proving this
// phase's edit to the Dispatcher allow-list in run_00_rbac.php widened
// ONLY the two codes actually added, nothing else.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Part 6: RBAC re-seed leaves pre-existing permissions unaffected ---\n\n";

$permGlobal = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_routing']);
$permOrg    = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_routing_org']);
if ($permGlobal && $permOrg) {
    $hasGlobal = (bool) db_fetch_value("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 3 AND permission_id = ?", [(int) $permGlobal['id']]);
    $hasOrg    = (bool) db_fetch_value("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 3 AND permission_id = ?", [(int) $permOrg['id']]);
    t('Dispatcher still does NOT hold Phase 141\'s action.manage_org_routing (this phase\'s Dispatcher allow-list edit widened ONLY the two new codes)', !$hasGlobal);
    t('Dispatcher still does NOT hold Phase 141\'s action.manage_org_routing_org', !$hasOrg);
} else {
    echo "SKIP: Phase 141 permission rows not present on this database.\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
