<?php
/**
 * Phase 142 (2026-08-17) — Manual cross-org sharing: schema + migration.
 *
 * Covers (tasks.md section 1's own test-file scope):
 *   1. All five new incident_shares columns exist, with the right
 *      nullability/defaults.
 *   2. Idempotent re-run of sql/run_phase142_cross_org_manual_sharing.php.
 *   3. The lapsed-grant-revives failure mode this phase's revive-vs-reject-
 *      vs-insert logic (org_sharing_create_manual_share()) exists to route
 *      around: insert an active share, revoke it (revoked_at set), then
 *      attempt a SECOND plain INSERT on the same
 *      (ticket_id, shared_with_org_id) key and confirm it collides with
 *      uk_incident_share -- ask the database, not the DDL, per this
 *      project's Phase 129/134 discipline.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_manual_schema.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 142 — Manual Cross-Org Sharing: Schema ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

$hasSharesTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'incident_shares']
);
if (!$hasSharesTable) {
    echo "\nSKIP: incident_shares table not present -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — column existence + shape
// ═══════════════════════════════════════════════════════════════════════

echo "--- Structural ---\n\n";

$cols = [];
foreach (db_fetch_all(
    "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'incident_shares']
) as $r) {
    $cols[$r['COLUMN_NAME']] = $r;
}

foreach (['created_by', 'created_by_name', 'share_reason', 'revoked_by', 'revoked_by_name'] as $c) {
    t("incident_shares.$c exists", isset($cols[$c]));
}

if (isset($cols['created_by'])) t('created_by is nullable (NULL for auto-routed shares)', $cols['created_by']['IS_NULLABLE'] === 'YES');
if (isset($cols['created_by_name'])) {
    t('created_by_name is NOT NULL', $cols['created_by_name']['IS_NULLABLE'] === 'NO');
    t("created_by_name defaults to '' (MariaDB reports the literal expression \"''\")", $cols['created_by_name']['COLUMN_DEFAULT'] === "''");
}
if (isset($cols['share_reason'])) t('share_reason is nullable', $cols['share_reason']['IS_NULLABLE'] === 'YES');
if (isset($cols['revoked_by'])) t('revoked_by is nullable (NULL while active)', $cols['revoked_by']['IS_NULLABLE'] === 'YES');
if (isset($cols['revoked_by_name'])) {
    t('revoked_by_name is NOT NULL', $cols['revoked_by_name']['IS_NULLABLE'] === 'NO');
    t("revoked_by_name defaults to '' (MariaDB reports the literal expression \"''\")", $cols['revoked_by_name']['COLUMN_DEFAULT'] === "''");
}

// None of the five new columns participate in uk_incident_share.
$keyCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_incident_share'",
    [$prefix . 'incident_shares']
), 'COLUMN_NAME');
sort($keyCols);
t('uk_incident_share is still exactly (ticket_id, shared_with_org_id) -- unaffected by this ALTER', $keyCols === ['shared_with_org_id', 'ticket_id']);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — idempotent re-run
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Idempotent re-run ---\n\n";

$migrationScript = $base . '/sql/run_phase142_cross_org_manual_sharing.php';
t('migration script exists', file_exists($migrationScript));
if (file_exists($migrationScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migrationScript) . ' 2>&1');
    t('re-running the migration script exits without a fatal error', strpos((string) $out, 'Fatal error') === false);
    t('re-running the migration script reports every column as already existing (idempotent)', substr_count((string) $out, 'already exists') === 5);
    t('re-running the migration script does NOT report a new WARN', strpos((string) $out, '[WARN]') === false);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — the lapsed-grant-revives failure mode, proven live: a plain
// second INSERT on (ticket_id, shared_with_org_id) after the first row was
// revoked collides with uk_incident_share. This is the EXACT failure mode
// org_sharing_create_manual_share()'s revive-vs-reject-vs-insert logic
// exists to route around (plan.md's Schema section) -- prove the
// collision is real BEFORE trusting the workaround.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Lapsed-grant-revives failure mode is real (ask the database) ---\n\n";

$ownerOrgId = 900004160;
$targetOrgId = 900004161;
$createdOrgIds = [$ownerOrgId, $targetOrgId];
$createdTicketIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, 'ZZ142SC Owner']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$targetOrgId, 'ZZ142SC Target']);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 SchemaCollision Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142sc ticket', 'zz142sc ticket', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;

    db_query(
        "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`access_tier`) VALUES (?, ?, ?, 'view')",
        [$ticketId, $targetOrgId, $ownerOrgId]
    );
    $firstShareId = (int) db_insert_id();
    t('active share inserted', $firstShareId > 0);

    db_query("UPDATE {$prefix}incident_shares SET revoked_at = NOW(), revoked_reason = 'zz142sc test revoke' WHERE id = ?", [$firstShareId]);
    $revokedAt = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$firstShareId]);
    t('share is now revoked', $revokedAt !== null);

    $collided = false;
    try {
        db_query(
            "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`access_tier`) VALUES (?, ?, ?, 'assist')",
            [$ticketId, $targetOrgId, $ownerOrgId]
        );
    } catch (Throwable $e) {
        $collided = (stripos($e->getMessage(), 'Duplicate') !== false) || (stripos($e->getMessage(), 'uk_incident_share') !== false);
    }
    t('a PLAIN second INSERT on the same (ticket_id, shared_with_org_id) pair after revoke COLLIDES with uk_incident_share -- proving the revive-vs-insert workaround is necessary, not defensive-only', $collided);

    $rowCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $targetOrgId]);
    t('exactly one row exists for this (ticket, org) pair -- the failed second INSERT left no partial row', $rowCount === 1);
} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
