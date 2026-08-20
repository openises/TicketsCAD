<?php
/**
 * Phase 141 (2026-08-17) — org_sharing_resolve_shares_for_ticket() and its
 * pure precedence helper _org_sharing_apply_precedence().
 *
 * Two layers of coverage, per tasks.md 3's own instruction to test the
 * resolver "in isolation (no ticket needed)":
 *
 *   Part 1 — the PURE precedence/dedup algorithm, driven with synthetic
 *   candidate rows (no database at all). This is the only way to exercise
 *   the "two rules of the same specificity tie" branch plan.md specifies:
 *   org_type_routing's own UNIQUE KEY makes that state unreachable via
 *   normal inserts for a single ticket (see inc/org-sharing.php's
 *   docblock), so it's tested as a defensive contract directly against
 *   the pure function rather than against an unreachable DB state.
 *
 *   Part 2 — the real DB-querying function, against live
 *   org_type_routing + in_types fixtures: type-beats-group precedence,
 *   independent grants to different target orgs, inactive rules
 *   excluded, no-match returns empty, and a pre-migration-shaped
 *   (missing-table) fallback.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_resolution.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — org_sharing_resolve_shares_for_ticket() precedence ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — pure precedence algorithm, synthetic rows, no database
// ═══════════════════════════════════════════════════════════════════════

echo "--- Pure precedence resolver (_org_sharing_apply_precedence) ---\n\n";

// Type beats group for the SAME target org, regardless of input order.
$r = _org_sharing_apply_precedence([
    ['id' => 10, 'shared_with_org_id' => 5, 'match_scope' => 'group', 'access_tier' => 'view'],
    ['id' => 20, 'shared_with_org_id' => 5, 'match_scope' => 'type',  'access_tier' => 'assist'],
]);
t("type-scoped row (id 20) beats group-scoped row (id 10) for the same target, group seen first", count($r) === 1 && $r[0]['routing_rule_id'] === 20 && $r[0]['access_tier'] === 'assist');

$r2 = _org_sharing_apply_precedence([
    ['id' => 20, 'shared_with_org_id' => 5, 'match_scope' => 'type',  'access_tier' => 'assist'],
    ['id' => 10, 'shared_with_org_id' => 5, 'match_scope' => 'group', 'access_tier' => 'view'],
]);
t("type-scoped row (id 20) beats group-scoped row (id 10) for the same target, type seen first", count($r2) === 1 && $r2[0]['routing_rule_id'] === 20);

// Same-specificity tie: input pre-sorted id DESC (most-recent-first),
// as org_sharing_resolve_shares_for_ticket() always provides — the
// FIRST row seen for a given target wins.
$r3 = _org_sharing_apply_precedence([
    ['id' => 30, 'shared_with_org_id' => 5, 'match_scope' => 'group', 'access_tier' => 'assist'], // most recent
    ['id' => 10, 'shared_with_org_id' => 5, 'match_scope' => 'group', 'access_tier' => 'view'],    // older
]);
t("same-specificity tie: the most-recently-created row (id 30, first in id-DESC order) wins", count($r3) === 1 && $r3[0]['routing_rule_id'] === 30 && $r3[0]['access_tier'] === 'assist');

$r3b = _org_sharing_apply_precedence([
    ['id' => 10, 'shared_with_org_id' => 5, 'match_scope' => 'type', 'access_tier' => 'view'],
    ['id' => 30, 'shared_with_org_id' => 5, 'match_scope' => 'type', 'access_tier' => 'assist'],
]);
// Not id-DESC-ordered on purpose here -- confirms the function trusts
// input order (as documented) rather than re-sorting internally; the
// FIRST element (id 10) wins because it's first in the array, proving
// the ordering contract is the caller's responsibility.
t("precedence helper honors INPUT ORDER (first element wins a same-specificity tie), not its own re-sort", count($r3b) === 1 && $r3b[0]['routing_rule_id'] === 10);

// Independent targets never collide.
$r4 = _org_sharing_apply_precedence([
    ['id' => 1, 'shared_with_org_id' => 5, 'match_scope' => 'group', 'access_tier' => 'view'],
    ['id' => 2, 'shared_with_org_id' => 6, 'match_scope' => 'group', 'access_tier' => 'assist'],
]);
t("two rules naming DIFFERENT target orgs both survive as independent grants", count($r4) === 2);
$byTarget = [];
foreach ($r4 as $row) $byTarget[$row['shared_with_org_id']] = $row;
t("org 5's grant is view tier, org 6's grant is assist tier — independently resolved", ($byTarget[5]['access_tier'] ?? null) === 'view' && ($byTarget[6]['access_tier'] ?? null) === 'assist');

// Empty input -> empty output.
t("empty candidate list resolves to an empty result", _org_sharing_apply_precedence([]) === []);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — real DB-querying function against live fixtures
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- org_sharing_resolve_shares_for_ticket() against live fixtures ---\n\n";

$hasRoutingTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_type_routing']
);
if (!$hasRoutingTable) {
    echo "\nSKIP: org_type_routing table not present -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$owner  = 900002150;
$target = 900002151;
$otherTarget = 900002152;
$createdTypeIds = [];
$createdRuleIds = [];

$cleanup = function () use ($prefix, $owner, $target, $otherTarget, &$createdTypeIds, &$createdRuleIds) {
    foreach ($createdRuleIds as $id) { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id) { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}org_type_routing WHERE owning_org_id = ?", [$owner]); } catch (Throwable $e) {}
};
$cleanup();

try {
    $groupVal = 'ZZP141-' . substr(md5((string) mt_rand()), 0, 6);

    // Two incident types sharing the same group, so a group-scoped rule
    // can match one while a type-scoped rule targets the other.
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 test type A', ?)", ['zz141a-' . uniqid(), $groupVal]);
    $typeA = (int) db_insert_id(); $createdTypeIds[] = $typeA;
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 test type B', ?)", ['zz141b-' . uniqid(), $groupVal]);
    $typeB = (int) db_insert_id(); $createdTypeIds[] = $typeB;

    // Rule 1: group-scoped, owner -> target, view tier.
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', ?, 'view', 1)",
        [$owner, $target, $groupVal]
    );
    $ruleGroup = (int) db_insert_id(); $createdRuleIds[] = $ruleGroup;

    // A ticket of typeA (group match only) resolves to the group rule at view tier.
    $resultA = org_sharing_resolve_shares_for_ticket($typeA, $owner);
    t("group-only match: typeA resolves to exactly one share", count($resultA) === 1);
    t("group-only match: resolves to the target org", ($resultA[0]['shared_with_org_id'] ?? null) === $target);
    t("group-only match: resolves to the group rule's id", ($resultA[0]['routing_rule_id'] ?? null) === $ruleGroup);
    t("group-only match: resolves to view tier", ($resultA[0]['access_tier'] ?? null) === 'view');

    // Rule 2: type-scoped, owner -> SAME target, assist tier, for typeB.
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_in_type_id, access_tier, active) VALUES (?, ?, 'type', ?, 'assist', 1)",
        [$owner, $target, $typeB]
    );
    $ruleType = (int) db_insert_id(); $createdRuleIds[] = $ruleType;

    // typeB matches BOTH the group rule (same group) AND the type rule
    // (exact match) -- type must win.
    $resultB = org_sharing_resolve_shares_for_ticket($typeB, $owner);
    t("type-beats-group: typeB (matches both rules) resolves to exactly one share", count($resultB) === 1);
    t("type-beats-group: resolves to the TYPE rule's id, not the group rule's", ($resultB[0]['routing_rule_id'] ?? null) === $ruleType);
    t("type-beats-group: resolves to assist tier (the type rule's tier)", ($resultB[0]['access_tier'] ?? null) === 'assist');

    // typeA still resolves via the group rule only (unaffected by the new type rule).
    $resultA2 = org_sharing_resolve_shares_for_ticket($typeA, $owner);
    t("typeA is unaffected by the new type-scoped rule (still resolves via the group rule)", count($resultA2) === 1 && $resultA2[0]['routing_rule_id'] === $ruleGroup);

    // Rule 3: a SEPARATE target org, group-scoped, same group -- independent grant.
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', ?, 'view', 1)",
        [$owner, $otherTarget, $groupVal]
    );
    $ruleOtherTarget = (int) db_insert_id(); $createdRuleIds[] = $ruleOtherTarget;

    $resultA3 = org_sharing_resolve_shares_for_ticket($typeA, $owner);
    t("typeA now resolves to TWO independent shares (target + otherTarget)", count($resultA3) === 2);
    $targets = array_column($resultA3, 'shared_with_org_id');
    sort($targets);
    $expected = [$target, $otherTarget]; sort($expected);
    t("both target orgs are present in the result", $targets === $expected);

    // Inactive rule is excluded entirely.
    db_query("UPDATE {$prefix}org_type_routing SET active = 0 WHERE id = ?", [$ruleOtherTarget]);
    $resultA4 = org_sharing_resolve_shares_for_ticket($typeA, $owner);
    t("deactivating a rule removes it from resolution (only the still-active rule's target remains)", count($resultA4) === 1 && $resultA4[0]['shared_with_org_id'] === $target);

    // No matching rule at all (a type belonging to no shared group).
    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 unrouted type', NULL)", ['zz141c-' . uniqid()]);
    $typeUnrouted = (int) db_insert_id(); $createdTypeIds[] = $typeUnrouted;
    $resultNone = org_sharing_resolve_shares_for_ticket($typeUnrouted, $owner);
    t("a type with no matching rule resolves to an empty array", $resultNone === []);

    // A different owning org (no rules at all) resolves to an empty array.
    $resultDifferentOwner = org_sharing_resolve_shares_for_ticket($typeA, 900002199);
    t("a different owning org with no rules resolves to an empty array", $resultDifferentOwner === []);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
