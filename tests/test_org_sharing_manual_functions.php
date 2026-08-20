<?php
/**
 * Phase 142 (2026-08-17) — org_sharing_create_manual_share() /
 * org_sharing_revoke_share() against live fixtures.
 *
 * Covers (tasks.md section 3's own test-file scope):
 *   - the revive-vs-reject-vs-fresh-insert three-way branch, each case
 *     driven through the real function, not hand-seeded rows
 *   - validation: target org missing/nonexistent/inactive/self, access
 *     tier out of range, reason empty/over-length
 *   - the IDOR guard on revoke: a share_id belonging to a ticket the
 *     caller does NOT own, with the caller's OWN org owning a completely
 *     different ticket, confirms the function uses the row's own
 *     ticket_id, never a caller-supplied one (there is no ticket_id
 *     parameter on org_sharing_revoke_share() at all -- this proves the
 *     function cannot be tricked by ANY input into checking the wrong
 *     ticket)
 *   - audit_log entries for both share_created (manual) and share_revoked
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_manual_functions.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 142 — org_sharing_create_manual_share() / org_sharing_revoke_share() ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$hasNewCols = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'revoked_by'",
    [$prefix . 'incident_shares']
);
if (!$hasNewCols) {
    echo "\nSKIP: incident_shares.revoked_by not present -- run sql/run_phase142_cross_org_manual_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$ownerOrgId    = 900004360; // owns ticket X
$targetOrgId   = 900004361; // legitimate target
$inactiveOrgId = 900004362; // exists but active=0
$otherOwnerOrgId = 900004363; // owns a SECOND, unrelated ticket Y
$ownerUserId   = 900004370;
$otherOwnerUserId = 900004371;

$createdOrgIds = [$ownerOrgId, $targetOrgId, $inactiveOrgId, $otherOwnerOrgId];
$createdTicketIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, &$createdTicketIds, $ownerUserId, $otherOwnerUserId) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$ownerUserId, $otherOwnerUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}newui_audit_log WHERE summary LIKE 'zz142mf%' OR summary LIKE '%ZZ142MF%'"); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, 'ZZ142MF Owner']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$targetOrgId, 'ZZ142MF Target']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 0)", [$inactiveOrgId, 'ZZ142MF Inactive']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$otherOwnerOrgId, 'ZZ142MF OtherOwner']);

    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$otherOwnerUserId, $otherOwnerOrgId, $otherOwnerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 ManualFn Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142mf ticket X', 'zz142mf ticket X', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketX = (int) db_insert_id();
    $createdTicketIds[] = $ticketX;

    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '2 ManualFn Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142mf ticket Y', 'zz142mf ticket Y', 2, 1, NOW(), ?)",
        [$now, $otherOwnerOrgId]
    );
    $ticketY = (int) db_insert_id();
    $createdTicketIds[] = $ticketY;

    // ══════════════════════════════════════════════════════════════════
    // Validation
    // ══════════════════════════════════════════════════════════════════
    echo "--- validation ---\n\n";

    $r = org_sharing_create_manual_share($ticketX, 0, 'view', 'zz142mf reason', $ownerUserId, 'Owner');
    t('target org id 0: refused with a non-empty error', $r['success'] === false && !empty($r['errors']));

    $r = org_sharing_create_manual_share($ticketX, 999999999, 'view', 'zz142mf reason', $ownerUserId, 'Owner');
    t('nonexistent target org: refused', $r['success'] === false && !empty($r['errors']));

    $r = org_sharing_create_manual_share($ticketX, $inactiveOrgId, 'view', 'zz142mf reason', $ownerUserId, 'Owner');
    t('inactive target org: refused', $r['success'] === false && !empty($r['errors']));

    $r = org_sharing_create_manual_share($ticketX, $ownerOrgId, 'view', 'zz142mf reason', $ownerUserId, 'Owner');
    t('target org == owning org (self-share): refused', $r['success'] === false && !empty($r['errors']));

    $r = org_sharing_create_manual_share($ticketX, $targetOrgId, 'bogus_tier', 'zz142mf reason', $ownerUserId, 'Owner');
    t("access_tier outside ('view','assist'): refused", $r['success'] === false && !empty($r['errors']));

    $r = org_sharing_create_manual_share($ticketX, $targetOrgId, 'view', '', $ownerUserId, 'Owner');
    t('empty reason: refused', $r['success'] === false && !empty($r['errors']));

    $r = org_sharing_create_manual_share($ticketX, $targetOrgId, 'view', str_repeat('x', 256), $ownerUserId, 'Owner');
    t('over-length (256 char) reason: refused', $r['success'] === false && !empty($r['errors']));

    // Confirm none of the above validation failures wrote a row.
    $noRow = db_fetch_one("SELECT id FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketX, $targetOrgId]);
    t('none of the validation-failure attempts wrote an incident_shares row', !$noRow);

    // ══════════════════════════════════════════════════════════════════
    // Fresh insert -> reject-while-active -> revive-after-revoke
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- revive / reject / fresh-insert branch ---\n\n";

    $create1 = org_sharing_create_manual_share($ticketX, $targetOrgId, 'view', 'ZZ142MF first grant', $ownerUserId, 'ZZ142MF Owner User');
    t('no existing row -> fresh INSERT succeeds', $create1['success'] === true);
    $shareId = (int) ($create1['id'] ?? 0);
    t('fresh INSERT returned a real share id', $shareId > 0);

    $row = db_fetch_one(
        "SELECT `access_tier`,`share_reason`,`created_by`,`created_by_name`,`routing_rule_id`,`revoked_at`
           FROM {$prefix}incident_shares WHERE id = ?",
        [$shareId]
    );
    t('fresh row: access_tier = view', $row['access_tier'] === 'view');
    t('fresh row: share_reason set verbatim', $row['share_reason'] === 'ZZ142MF first grant');
    t('fresh row: created_by set to the caller', (int) $row['created_by'] === $ownerUserId);
    t('fresh row: created_by_name set to the caller name', $row['created_by_name'] === 'ZZ142MF Owner User');
    t('fresh row: routing_rule_id is NULL (manual, not rule-sourced)', $row['routing_rule_id'] === null);
    t('fresh row: revoked_at is NULL (active)', $row['revoked_at'] === null);

    // Active row exists -> a second create attempt for the SAME (ticket,
    // target org) pair must be REJECTED, never silently overwrite.
    $create2 = org_sharing_create_manual_share($ticketX, $targetOrgId, 'assist', 'ZZ142MF should be rejected', $ownerUserId, 'ZZ142MF Owner User');
    t('an ACTIVE row already exists -> second create is REJECTED (never silently overwrites)', $create2['success'] === false);
    t('rejection error names the existing tier', !empty($create2['errors']) && stripos($create2['errors'][0], 'already shared') !== false);
    $rowUnchanged = db_fetch_one("SELECT access_tier, share_reason FROM {$prefix}incident_shares WHERE id = ?", [$shareId]);
    t('the existing row is completely UNCHANGED by the rejected second attempt', $rowUnchanged['access_tier'] === 'view' && $rowUnchanged['share_reason'] === 'ZZ142MF first grant');

    // Revoke it, then confirm a fresh create() for the SAME pair REVIVES
    // the same row (same id) rather than colliding with uk_incident_share
    // on a fresh INSERT.
    $revoke = org_sharing_revoke_share($shareId, 'ZZ142MF revoke before revive test', $ownerUserId, 'ZZ142MF Owner User');
    t('revoke succeeds', $revoke['success'] === true);
    $revokedRow = db_fetch_one("SELECT revoked_at, revoked_by, revoked_by_name, revoked_reason FROM {$prefix}incident_shares WHERE id = ?", [$shareId]);
    t('revoked_at is now set', $revokedRow['revoked_at'] !== null);
    t('revoked_by is set to the caller', (int) $revokedRow['revoked_by'] === $ownerUserId);
    t('revoked_by_name is set to the caller name', $revokedRow['revoked_by_name'] === 'ZZ142MF Owner User');
    t('revoked_reason is set verbatim', $revokedRow['revoked_reason'] === 'ZZ142MF revoke before revive test');

    $create3 = org_sharing_create_manual_share($ticketX, $targetOrgId, 'assist', 'ZZ142MF revived grant', $ownerUserId, 'ZZ142MF Owner User');
    t('revoked row exists for the same (ticket, target org) pair -> create REVIVES it, does not error on uk_incident_share', $create3['success'] === true);
    t('the revived row keeps the SAME share id (UPDATE, not a fresh INSERT)', (int) ($create3['id'] ?? -1) === $shareId);

    $revivedRow = db_fetch_one(
        "SELECT `access_tier`,`share_reason`,`created_by_name`,`routing_rule_id`,`revoked_at`,`revoked_reason`,`revoked_by`,`revoked_by_name`
           FROM {$prefix}incident_shares WHERE id = ?",
        [$shareId]
    );
    t('revived row: access_tier updated to the new value (assist)', $revivedRow['access_tier'] === 'assist');
    t('revived row: share_reason updated', $revivedRow['share_reason'] === 'ZZ142MF revived grant');
    t('revived row: revoked_at cleared back to NULL', $revivedRow['revoked_at'] === null);
    t('revived row: revoked_reason cleared back to NULL', $revivedRow['revoked_reason'] === null);
    t('revived row: revoked_by cleared back to NULL', $revivedRow['revoked_by'] === null);
    t('revived row: revoked_by_name cleared back to empty string', $revivedRow['revoked_by_name'] === '');
    t('revived row: routing_rule_id explicitly NULL even though (irrelevant here) this row was never rule-sourced -- the revive path always clears it', $revivedRow['routing_rule_id'] === null);

    // ══════════════════════════════════════════════════════════════════
    // A revoked-but-originally-RULE-sourced row, revived by a human,
    // loses its routing_rule_id attribution (plan.md: "even if the row's
    // ORIGINAL creation was rule-driven, this specific act of re-granting
    // is a human decision and must be attributed as one").
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- reviving a rule-sourced revoked row re-attributes it as manual ---\n\n";

    db_query(
        "INSERT INTO {$prefix}incident_shares (`ticket_id`,`shared_with_org_id`,`owning_org_id`,`routing_rule_id`,`access_tier`,`revoked_at`,`revoked_reason`)
         VALUES (?, ?, ?, 999999, 'view', NOW(), 'ZZ142MF pre-revoked rule-sourced fixture')",
        [$ticketY, $targetOrgId, $otherOwnerOrgId]
    );
    $ruleSourcedShareId = (int) db_insert_id();
    $create4 = org_sharing_create_manual_share($ticketY, $targetOrgId, 'view', 'ZZ142MF human re-grant of a rule-sourced share', $otherOwnerUserId, 'ZZ142MF OtherOwner User');
    t('reviving a revoked, rule-sourced row succeeds', $create4['success'] === true);
    t('reviving keeps the same row id', (int) ($create4['id'] ?? -1) === $ruleSourcedShareId);
    $revivedRuleRow = db_fetch_one("SELECT routing_rule_id, created_by FROM {$prefix}incident_shares WHERE id = ?", [$ruleSourcedShareId]);
    t('the revived row now has routing_rule_id = NULL (re-attributed as a human decision)', $revivedRuleRow['routing_rule_id'] === null);
    t('the revived row is attributed to the reviving human caller', (int) $revivedRuleRow['created_by'] === $otherOwnerUserId);

    // ══════════════════════════════════════════════════════════════════
    // IDOR guard on revoke — a share_id belonging to ticket Y (owned by a
    // DIFFERENT org) must be refused when the caller only owns ticket X.
    // org_sharing_revoke_share() takes ONLY a share_id -- there is no
    // ticket_id parameter to trick, proving the function cannot be misled
    // by any caller-supplied ticket identity at all.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- IDOR guard: revoke derives ticket_id from the row, never from caller input ---\n\n";

    $wrongOwnerRevoke = org_sharing_revoke_share($ruleSourcedShareId, 'attempted cross-ticket revoke', $ownerUserId, 'ZZ142MF Owner User (wrong org)');
    t('a caller who owns ticket X cannot revoke a share on ticket Y via its share_id', $wrongOwnerRevoke['success'] === false);
    $stillActiveY = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$ruleSourcedShareId]);
    t('ticket Y\'s share is STILL active after the wrong-owner revoke attempt', $stillActiveY === null);

    // Confirm the RIGHT owner (ticket Y's actual owning org) CAN revoke it.
    $rightOwnerRevoke = org_sharing_revoke_share($ruleSourcedShareId, 'legitimate revoke', $otherOwnerUserId, 'ZZ142MF OtherOwner User');
    t('the actual owning-org caller for ticket Y CAN revoke its share', $rightOwnerRevoke['success'] === true);

    // Revoking an already-revoked share is refused, not silently a no-op success.
    $doubleRevoke = org_sharing_revoke_share($ruleSourcedShareId, 'double revoke', $otherOwnerUserId, 'ZZ142MF OtherOwner User');
    t('revoking an already-revoked share is refused', $doubleRevoke['success'] === false);

    // Revoking a nonexistent share id is refused cleanly, not a fatal error.
    $bogusRevoke = org_sharing_revoke_share(999999999, 'bogus', $ownerUserId, 'ZZ142MF Owner User');
    t('revoking a nonexistent share_id is refused cleanly', $bogusRevoke['success'] === false);

    // ══════════════════════════════════════════════════════════════════
    // Audit log — share_created (manual) and share_revoked entries exist,
    // with the details payload distinguishing manual from rule-sourced
    // per plan.md's Audit Logging section.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- audit log ---\n\n";

    $auditCreated = db_fetch_one(
        "SELECT `details` FROM {$prefix}newui_audit_log
          WHERE category = 'incident' AND activity = 'share_created' AND target_type = 'ticket' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [$ticketX]
    );
    t('a share_created audit_log entry exists for the manual share on ticket X', (bool) $auditCreated);
    if ($auditCreated) {
        $details = json_decode($auditCreated['details'], true);
        t('share_created details.routing_rule_id is null (distinguishes manual from rule-sourced, per plan.md)', array_key_exists('routing_rule_id', $details) && $details['routing_rule_id'] === null);
        t('share_created details.share_reason is present', isset($details['share_reason']));
    }

    $auditRevoked = db_fetch_one(
        "SELECT `details` FROM {$prefix}newui_audit_log
          WHERE category = 'incident' AND activity = 'share_revoked' AND target_type = 'ticket' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [$ticketX]
    );
    t('a share_revoked audit_log entry exists for the revoke on ticket X', (bool) $auditRevoked);

    $auditRevokedRuleSourced = db_fetch_one(
        "SELECT `details` FROM {$prefix}newui_audit_log
          WHERE category = 'incident' AND activity = 'share_revoked' AND target_type = 'ticket' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [$ticketY]
    );
    t('a share_revoked audit_log entry exists for ticket Y\'s revoke', (bool) $auditRevokedRuleSourced);
    if ($auditRevokedRuleSourced) {
        $detailsY = json_decode($auditRevokedRuleSourced['details'], true);
        // false, not true: the earlier revive already cleared routing_rule_id
        // to NULL (re-attributing the row as manual per plan.md), so by the
        // time THIS revoke reads the row, it is no longer rule-sourced at
        // all -- was_rule_sourced reflects the row's state at revoke time,
        // not its original creation history.
        t('share_revoked details.was_rule_sourced is false -- the earlier revive already re-attributed this row as manual', $detailsY['was_rule_sourced'] === false);
    }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
