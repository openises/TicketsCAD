<?php
/**
 * Phase 141 (2026-08-17) — Cross-org ticket sharing: schema + migration.
 *
 * Covers:
 *   1. Table/column existence for org_type_routing and incident_shares.
 *   2. org_type_routing.match_key -- the discriminated-union-collapse
 *      generated column -- actually binds the uniqueness constraint for
 *      BOTH match_scope values (ask the database, not the DDL, per this
 *      project's Phase 129/134 discipline).
 *   3. incident_shares.uk_incident_share (ticket_id, shared_with_org_id)
 *      -- verified NOT to need the NULL-safe technique (both columns are
 *      NOT NULL) -- actually rejects a duplicate insert.
 *   4. Idempotent re-run of the migration script.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_schema.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Cross-Org Ticket Sharing: Schema ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — table/column existence
// ═══════════════════════════════════════════════════════════════════════

echo "--- Structural ---\n\n";

$hasRoutingTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_type_routing']
);
if (!$hasRoutingTable) {
    echo "\nSKIP: org_type_routing table not present -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}
t("org_type_routing table exists", $hasRoutingTable);

$routingCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_type_routing']
), 'COLUMN_NAME');
foreach ([
    'id', 'owning_org_id', 'shared_with_org_id', 'match_scope', 'match_group',
    'match_in_type_id', 'match_key', 'access_tier', 'active', 'created_by',
    'created_by_name', 'created_at', 'updated_at', 'deactivated_at', 'deactivated_by',
] as $c) {
    t("org_type_routing.$c exists", in_array($c, $routingCols, true));
}

$hasSharesTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'incident_shares']
);
t("incident_shares table exists", $hasSharesTable);

$sharesCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'incident_shares']
), 'COLUMN_NAME');
foreach ([
    'id', 'ticket_id', 'shared_with_org_id', 'owning_org_id', 'routing_rule_id',
    'access_tier', 'created_at', 'revoked_at', 'revoked_reason',
] as $c) {
    t("incident_shares.$c exists", in_array($c, $sharesCols, true));
}

// Verify both columns of uk_incident_share are actually NOT NULL, per the
// migration script's own docblock claim (don't just trust the comment).
$ticketIdNullable = db_fetch_value(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'ticket_id'",
    [$prefix . 'incident_shares']
);
$sharedOrgNullable = db_fetch_value(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'shared_with_org_id'",
    [$prefix . 'incident_shares']
);
t("incident_shares.ticket_id is NOT NULL (verified live, not assumed)", $ticketIdNullable === 'NO');
t("incident_shares.shared_with_org_id is NOT NULL (verified live, not assumed)", $sharedOrgNullable === 'NO');

$ukShares = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX",
    [$prefix . 'incident_shares', 'uk_incident_share']
), 'COLUMN_NAME');
t("uk_incident_share covers (ticket_id, shared_with_org_id) in that order", $ukShares === ['ticket_id', 'shared_with_org_id']);

$ukRouting = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX",
    [$prefix . 'org_type_routing', 'uk_org_routing_rule']
), 'COLUMN_NAME');
t("uk_org_routing_rule covers (owning_org_id, shared_with_org_id, match_key) in that order",
    $ukRouting === ['owning_org_id', 'shared_with_org_id', 'match_key']);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — org_type_routing.match_key actually binds the unique key
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- org_type_routing uniqueness (match_key discriminated-union collapse) ---\n\n";

// Fake org ids well outside any real range, per this project's convention.
$owner = 900002141;
$targetA = 900002142;
$targetB = 900002143;

db_query("DELETE FROM {$prefix}org_type_routing WHERE owning_org_id = ? OR shared_with_org_id IN (?, ?)", [$owner, $targetA, $targetB]);

// -- group scope --
db_query(
    "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier) VALUES (?, ?, 'group', 'ZZTestGroup', 'view')",
    [$owner, $targetA]
);
t("first group-scoped rule inserts fine", true);

$groupDupRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier) VALUES (?, ?, 'group', 'ZZTestGroup', 'assist')",
        [$owner, $targetA]
    );
} catch (Exception $e) {
    $groupDupRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a SECOND group-scoped rule with the SAME (owner, target, group) is rejected", $groupDupRejected);

// A DIFFERENT group value for the same owner/target is fine (different match_key).
$groupDiffOk = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier) VALUES (?, ?, 'group', 'ZZTestGroupTwo', 'view')",
        [$owner, $targetA]
    );
    $groupDiffOk = true;
} catch (Exception $e) { /* assertion below reports the failure */ }
t("a DIFFERENT match_group for the same (owner, target) pair is allowed (different match_key)", $groupDiffOk);

// -- type scope --
db_query(
    "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_in_type_id, access_tier) VALUES (?, ?, 'type', 999888777, 'view')",
    [$owner, $targetB]
);
t("first type-scoped rule inserts fine", true);

$typeDupRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_in_type_id, access_tier) VALUES (?, ?, 'type', 999888777, 'assist')",
        [$owner, $targetB]
    );
} catch (Exception $e) {
    $typeDupRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a SECOND type-scoped rule with the SAME (owner, target, in_type_id) is rejected", $typeDupRejected);

// A group-scoped rule and a type-scoped rule for the SAME (owner, target)
// pair are DIFFERENT match_key values ('g:...' vs 't:...') and must both
// be allowed to coexist -- this is exactly what makes the type-beats-group
// precedence resolution meaningful in inc/org-sharing.php.
$coexistOk = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier) VALUES (?, ?, 'group', 'ZZCoexistGroup', 'view')",
        [$owner, $targetB]
    );
    $coexistOk = true;
} catch (Exception $e) { /* assertion below reports the failure */ }
t("a group-scoped rule and a type-scoped rule may coexist for the SAME (owner, target) pair (different match_key discriminant)", $coexistOk);

// Verify match_key's actual computed values for a spot check.
$mkRows = db_fetch_all(
    "SELECT match_scope, match_group, match_in_type_id, match_key FROM {$prefix}org_type_routing WHERE owning_org_id = ? ORDER BY id",
    [$owner]
);
$mkByScope = [];
foreach ($mkRows as $r) {
    if ($r['match_scope'] === 'type') { $mkByScope['type'] = $r['match_key']; }
}
t("match_key for a type-scoped row is 't:<in_type_id>'", ($mkByScope['type'] ?? null) === 't:999888777');

db_query("DELETE FROM {$prefix}org_type_routing WHERE owning_org_id = ? OR shared_with_org_id IN (?, ?)", [$owner, $targetA, $targetB]);

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — incident_shares.uk_incident_share actually rejects a duplicate
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- incident_shares uniqueness (ticket_id, shared_with_org_id) ---\n\n";

$fakeTicketId = 900002144;
$fakeSharedOrg = 900002145;

db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$fakeTicketId]);

db_query(
    "INSERT INTO {$prefix}incident_shares (ticket_id, shared_with_org_id, owning_org_id, access_tier) VALUES (?, ?, 900002146, 'view')",
    [$fakeTicketId, $fakeSharedOrg]
);
t("first incident_shares row for (ticket, org) pair inserts fine", true);

$shareDupRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}incident_shares (ticket_id, shared_with_org_id, owning_org_id, access_tier) VALUES (?, ?, 900002146, 'assist')",
        [$fakeTicketId, $fakeSharedOrg]
    );
} catch (Exception $e) {
    $shareDupRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a duplicate (ticket_id, shared_with_org_id) incident_shares row is rejected", $shareDupRejected);

// A different target org for the SAME ticket is a separate, valid grant.
$secondOrgOk = false;
try {
    db_query(
        "INSERT INTO {$prefix}incident_shares (ticket_id, shared_with_org_id, owning_org_id, access_tier) VALUES (?, 900002147, 900002146, 'view')",
        [$fakeTicketId]
    );
    $secondOrgOk = true;
} catch (Exception $e) { /* assertion below reports the failure */ }
t("a SECOND, different shared_with_org_id for the same ticket is allowed (independent grant)", $secondOrgOk);

db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$fakeTicketId]);

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — idempotent re-run of the migration script
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Idempotent re-run ---\n\n";

$migScript = $base . '/sql/run_phase141_cross_org_ticket_sharing.php';
t("migration script file exists", file_exists($migScript));

if (file_exists($migScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migScript) . ' 2>&1');
    t("re-running the migration script exits without a fatal error", strpos((string) $out, 'Fatal error') === false);
    t("re-running the migration script reports both tables already exist",
        strpos((string) $out, 'org_type_routing already exists') !== false
        && strpos((string) $out, 'incident_shares already exists') !== false);
}

$rowCountAfter = (int) db_fetch_value("SELECT COUNT(*) AS c FROM {$prefix}org_type_routing WHERE owning_org_id = ?", [$owner]);
t("re-run does not resurrect deleted test fixture rows (idempotent CREATE, not a reseed)", $rowCountAfter === 0);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
