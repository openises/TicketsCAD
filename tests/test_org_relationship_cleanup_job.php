<?php
/**
 * Phase 143 (2026-08-17) — the cleanup job's own non-authoritative property.
 *
 * The specific, named proof that tools/org_relationship_cleanup_tick.php
 * is non-authoritative: it closes an audit record for an activation the
 * read-time predicate has ALREADY excluded, and running it changes nothing
 * about who can see what.
 *
 *   (a) create an already-expired-but-not-yet-deactivated_at-stamped
 *       activation (backdated activated_at, same technique as
 *       tests/test_org_relationships_read_time_expiry.php).
 *   (b) confirm org_can_see_ticket() ALREADY reports no access BEFORE the
 *       tick script runs (re-confirming the read-time-expiry test's result,
 *       cheaply, in this file too).
 *   (c) run the tick script (the real script, via shell_exec, exactly as
 *       systemd would invoke it).
 *   (d) confirm deactivated_at is now stamped and the
 *       relationship_deactivated / auto_expired=true audit entry exists.
 *   (e) confirm org_can_see_ticket()'s answer is UNCHANGED before and after
 *       the tick ran -- the access state was already correct; the job only
 *       closed the record.
 *   (f) sched_job_required('org_relationship_activation_cleanup') reports
 *       false on a fresh install (no expired-but-open activation) and true
 *       once one exists -- the "shipped default is not evidence of use"
 *       discipline.
 *
 * @requires-db
 * Usage: php tests/test_org_relationship_cleanup_job.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-relationships.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 143 — Cleanup job: non-authoritative by construction ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

$ownerOrgId = 900005800;
$otherOrgId = 900005801;
$ownerUserId = 900005810;
$otherUserId = 900005811;

$createdOrgIds = [$ownerOrgId, $otherOrgId];
$createdTicketIds = [];
$createdRelIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, &$createdRelIds, $ownerUserId, $otherUserId) {
    foreach ($createdRelIds as $id) {
        try { db_query("DELETE FROM {$prefix}org_relationships_activations WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships_members WHERE relationship_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}org_relationships WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$ownerUserId, $otherUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, 'ZZ143CJ Owner']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$otherOrgId, 'ZZ143CJ Other']);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$otherUserId, $otherOrgId, $otherOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 CleanupJob143 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz143cj ticket', 'zz143cj ticket', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;

    // (a) already-expired-but-open activation, via real writers + backdating.
    $create = org_relationship_create_or_propose(
        ['name' => 'ZZ143CJ Standing Relationship', 'member_org_ids' => [$ownerOrgId, $otherOrgId],
         'access_tier' => 'view', 'redaction_profile' => 'view', 'requires_activation' => 1],
        true, $ownerUserId, 'ZZ Owner User'
    );
    t('relationship created and active', $create['success'] && $create['status'] === 'active');
    $relId = (int) ($create['id'] ?? 0);
    if ($relId > 0) $createdRelIds[] = $relId;

    $activate = org_relationship_activate($relId, true, $otherUserId, 'ZZ Other User', 'zz143cj drill', 1);
    t('activation succeeds', $activate['success'] === true);
    $activationId = (int) ($activate['id'] ?? 0);

    db_query("UPDATE {$prefix}org_relationships_activations SET activated_at = NOW() - INTERVAL 5 MINUTE WHERE id = ?", [$activationId]);

    // (b) access already gone BEFORE the tick runs.
    echo "--- (b) access is already gone BEFORE the tick script runs ---\n\n";
    $beforeAccess = org_can_see_ticket($ticketId, $otherUserId);
    t('org_can_see_ticket() already reports NO access before the tick script has ever run', !$beforeAccess);
    $beforeDeactivatedAt = db_fetch_value("SELECT deactivated_at FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('deactivated_at is STILL NULL before the tick script runs', $beforeDeactivatedAt === null);

    // (f) sched_job_required() reports true now that an expired-but-open
    // activation exists.
    $reqBefore = sched_job_required('org_relationship_activation_cleanup');
    t("sched_job_required('org_relationship_activation_cleanup') reports required=true once an expired-but-open activation exists", $reqBefore['required'] === true);

    // (c) run the REAL tick script.
    echo "\n--- (c) running the real cleanup tick script ---\n\n";
    $tickScript = $base . '/tools/org_relationship_cleanup_tick.php';
    t('cleanup tick script file exists', file_exists($tickScript));
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($tickScript) . ' 2>&1');
    t('cleanup tick script exits without a fatal error', strpos((string) $out, 'Fatal error') === false);
    t('cleanup tick script reports at least one closed activation', (bool) preg_match('/closed=[1-9]/', (string) $out));

    // (d) deactivated_at is now stamped, audit entry exists.
    echo "\n--- (d) deactivated_at is now stamped, audit entry exists ---\n\n";
    $afterRow = db_fetch_one("SELECT deactivated_at, deactivated_by, deactivated_reason FROM {$prefix}org_relationships_activations WHERE id = ?", [$activationId]);
    t('deactivated_at is now set by the cleanup job', $afterRow && $afterRow['deactivated_at'] !== null);
    t("deactivated_by is 0 ('the system, not a human, did this')", $afterRow && (int) $afterRow['deactivated_by'] === 0);
    t("deactivated_reason is the auto-expired sentinel", $afterRow && $afterRow['deactivated_reason'] === 'auto-expired (cleanup sweep)');

    $auditRow = db_fetch_one(
        "SELECT details FROM {$prefix}newui_audit_log WHERE target_type = 'org_relationship' AND target_id = ? AND activity = 'relationship_deactivated' ORDER BY id DESC LIMIT 1",
        [$relId]
    );
    t('relationship_deactivated audit entry exists for the cleanup job\'s own closure', (bool) $auditRow);
    if ($auditRow) {
        $details = json_decode($auditRow['details'], true) ?: [];
        t("audit entry's auto_expired flag is true (distinguishing this from a manual deactivation)", ($details['auto_expired'] ?? null) === true);
    }

    // (e) access state is UNCHANGED before vs. after.
    echo "\n--- (e) access state is UNCHANGED before vs. after the cleanup job ran ---\n\n";
    $afterAccess = org_can_see_ticket($ticketId, $otherUserId);
    t('org_can_see_ticket()\'s answer is IDENTICAL before and after the cleanup job ran (both false) -- the job only closed the record, it did not revoke anything', $beforeAccess === $afterAccess && $afterAccess === false);

    // (f continued) sched_job_required() now reports false again (the
    // only expired-but-open row has been closed).
    $reqAfter = sched_job_required('org_relationship_activation_cleanup');
    t("sched_job_required(...) reports required=false again once the only expired-but-open row has been closed", $reqAfter['required'] === false);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
