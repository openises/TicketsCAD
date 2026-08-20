<?php
/**
 * Phase 143 (2026-08-17) — Cross-org STANDING relationships: schema +
 * migration.
 *
 * Covers:
 *   1. Table/column existence for org_relationships, org_relationships_members,
 *      org_relationships_activations.
 *   2. uk_org_rel_member (relationship_id, org_id) -- verified NOT to need
 *      the NULL-safe technique (both columns NOT NULL) -- actually rejects
 *      a duplicate insert.
 *   3. live_key -- the corrected (NULL-for-closed, not id-referencing)
 *      generated-column technique -- actually binds
 *      uk_org_rel_activation_live: (a) a second LIVE activation for the
 *      same relationship is rejected; (b) two CLOSED activations for the
 *      same relationship never collide with each other; (c) a closed
 *      activation never collides with a subsequent new live one. Ask the
 *      database directly (INSERT + catch the duplicate-key exception), per
 *      this project's Phase 129/134/141 discipline -- never inferred from
 *      the DDL.
 *   4. Idempotent re-run of the migration script.
 *
 * @requires-db
 * Usage: php tests/test_org_relationships_schema.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Cross-Org Standing Relationships: Schema ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — table/column existence
// ═══════════════════════════════════════════════════════════════════════

echo "--- Structural ---\n\n";

$hasRelTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_relationships']
);
if (!$hasRelTable) {
    echo "\nSKIP: org_relationships table not present -- run sql/run_phase143_cross_org_standing_relationships.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}
t("org_relationships table exists", $hasRelTable);

$relCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_relationships']
), 'COLUMN_NAME');
foreach ([
    'id', 'name', 'relationship_type', 'access_tier', 'redaction_profile',
    'requires_activation', 'max_activation_minutes', 'status',
    'created_by', 'created_by_name', 'created_at', 'updated_at',
] as $c) {
    t("org_relationships.$c exists", in_array($c, $relCols, true));
}

$hasMembersTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_relationships_members']
);
t("org_relationships_members table exists", $hasMembersTable);

$memberCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_relationships_members']
), 'COLUMN_NAME');
foreach ([
    'id', 'relationship_id', 'org_id', 'status', 'proposed_by', 'proposed_by_name',
    'proposed_at', 'approved_by', 'approved_by_name', 'approved_at',
    'rejected_by', 'rejected_by_name', 'rejected_at', 'rejection_reason',
] as $c) {
    t("org_relationships_members.$c exists", in_array($c, $memberCols, true));
}

$hasActivationsTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_relationships_activations']
);
t("org_relationships_activations table exists", $hasActivationsTable);

$activationCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'org_relationships_activations']
), 'COLUMN_NAME');
foreach ([
    'id', 'relationship_id', 'activated_at', 'activated_by', 'activated_by_name',
    'activation_reason', 'max_activation_minutes', 'deactivated_at', 'deactivated_by',
    'deactivated_by_name', 'deactivated_reason', 'live_key',
] as $c) {
    t("org_relationships_activations.$c exists", in_array($c, $activationCols, true));
}

// Verify both columns of uk_org_rel_member are actually NOT NULL, per the
// migration script's own docblock claim (don't just trust the comment).
$relationshipIdNullable = db_fetch_value(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'relationship_id'",
    [$prefix . 'org_relationships_members']
);
$memberOrgIdNullable = db_fetch_value(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
    [$prefix . 'org_relationships_members']
);
t("org_relationships_members.relationship_id is NOT NULL (verified live, not assumed)", $relationshipIdNullable === 'NO');
t("org_relationships_members.org_id is NOT NULL (verified live, not assumed)", $memberOrgIdNullable === 'NO');

$ukMember = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX",
    [$prefix . 'org_relationships_members', 'uk_org_rel_member']
), 'COLUMN_NAME');
t("uk_org_rel_member covers (relationship_id, org_id) in that order", $ukMember === ['relationship_id', 'org_id']);

$ukActivation = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX",
    [$prefix . 'org_relationships_activations', 'uk_org_rel_activation_live']
), 'COLUMN_NAME');
t("uk_org_rel_activation_live covers (live_key)", $ukActivation === ['live_key']);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — uk_org_rel_member actually rejects a duplicate
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- org_relationships_members uniqueness (relationship_id, org_id) ---\n\n";

// Fake org ids well outside any real range, per this project's convention.
$fakeRelId = null;
$fakeOrgA  = 900005143;

db_query("DELETE FROM {$prefix}org_relationships WHERE name LIKE 'ZZ143Schema%'");
db_query(
    "INSERT INTO {$prefix}org_relationships (name, access_tier, redaction_profile) VALUES ('ZZ143Schema Test', 'view', 'view')"
);
$fakeRelId = (int) db_insert_id();

db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$fakeRelId]);

db_query(
    "INSERT INTO {$prefix}org_relationships_members (relationship_id, org_id, status) VALUES (?, ?, 'approved')",
    [$fakeRelId, $fakeOrgA]
);
t("first membership row for (relationship, org) pair inserts fine", true);

$memberDupRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_relationships_members (relationship_id, org_id, status) VALUES (?, ?, 'pending')",
        [$fakeRelId, $fakeOrgA]
    );
} catch (Exception $e) {
    $memberDupRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a duplicate (relationship_id, org_id) membership row is rejected", $memberDupRejected);

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — live_key actually binds uk_org_rel_activation_live
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- org_relationships_activations uniqueness (live_key -- corrected technique) ---\n\n";

db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$fakeRelId]);

// (a) First LIVE activation inserts fine; a SECOND live activation for the
// SAME relationship is rejected -- the real DB-level "at most one live
// activation per relationship" guarantee.
db_query(
    "INSERT INTO {$prefix}org_relationships_activations (relationship_id, activated_by, activated_by_name) VALUES (?, 1, 'ZZ Tester')",
    [$fakeRelId]
);
$firstActivationId = (int) db_insert_id();
t("first LIVE activation for a relationship inserts fine", true);

$liveRow = db_fetch_one("SELECT live_key FROM {$prefix}org_relationships_activations WHERE id = ?", [$firstActivationId]);
t("a LIVE row's live_key is 'live:<relationship_id>'", $liveRow && $liveRow['live_key'] === 'live:' . $fakeRelId);

$secondLiveRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_relationships_activations (relationship_id, activated_by, activated_by_name) VALUES (?, 1, 'ZZ Tester 2')",
        [$fakeRelId]
    );
} catch (Exception $e) {
    $secondLiveRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a SECOND live activation for the SAME relationship is REJECTED (real DB constraint, not app-level only)", $secondLiveRejected);

// (b) Close the first activation, confirm its live_key goes NULL, then
// open + close a SECOND activation -- two CLOSED rows for the same
// relationship must never collide with each other.
db_query(
    "UPDATE {$prefix}org_relationships_activations SET deactivated_at = NOW(), deactivated_by = 1 WHERE id = ?",
    [$firstActivationId]
);
$closedRow = db_fetch_one("SELECT live_key FROM {$prefix}org_relationships_activations WHERE id = ?", [$firstActivationId]);
t("after deactivation, the row's live_key is NULL (never collides with anything -- the corrected technique)", $closedRow && $closedRow['live_key'] === null);

db_query(
    "INSERT INTO {$prefix}org_relationships_activations (relationship_id, activated_by, activated_by_name) VALUES (?, 1, 'ZZ Tester 3')",
    [$fakeRelId]
);
$secondActivationId = (int) db_insert_id();
t("a NEW live activation for the same relationship is allowed once the first is closed", $secondActivationId > 0);

db_query(
    "UPDATE {$prefix}org_relationships_activations SET deactivated_at = NOW(), deactivated_by = 1 WHERE id = ?",
    [$secondActivationId]
);
$closedCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM {$prefix}org_relationships_activations WHERE relationship_id = ? AND deactivated_at IS NOT NULL",
    [$fakeRelId]
);
t("TWO closed activations for the same relationship coexist without collision", $closedCount === 2);

// (c) A closed activation never collides with a SUBSEQUENT new live one.
$thirdLiveOk = false;
try {
    db_query(
        "INSERT INTO {$prefix}org_relationships_activations (relationship_id, activated_by, activated_by_name) VALUES (?, 1, 'ZZ Tester 4')",
        [$fakeRelId]
    );
    $thirdLiveOk = true;
} catch (Exception $e) { /* assertion below reports the failure */ }
t("a closed activation never collides with a SUBSEQUENT new live one for the same relationship", $thirdLiveOk);

db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$fakeRelId]);
db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$fakeRelId]);
db_query("DELETE FROM {$prefix}org_relationships WHERE id = ?", [$fakeRelId]);

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — idempotent re-run of the migration script
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Idempotent re-run ---\n\n";

$migScript = $base . '/sql/run_phase143_cross_org_standing_relationships.php';
t("migration script file exists", file_exists($migScript));

if (file_exists($migScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migScript) . ' 2>&1');
    t("re-running the migration script exits without a fatal error", strpos((string) $out, 'Fatal error') === false);
    t("re-running the migration script reports all three tables already exist",
        strpos((string) $out, 'org_relationships already exists') !== false
        && strpos((string) $out, 'org_relationships_members already exists') !== false
        && strpos((string) $out, 'org_relationships_activations already exists') !== false);
}

$rowCountAfter = (int) db_fetch_value("SELECT COUNT(*) AS c FROM {$prefix}org_relationships WHERE name LIKE 'ZZ143Schema%'");
t("re-run does not resurrect deleted test fixture rows (idempotent CREATE, not a reseed)", $rowCountAfter === 0);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
