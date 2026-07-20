<?php
/**
 * Phase 109 Slice C — equipment cache checkout on the Net-Control Board.
 *
 * Gear is issued to a PERSON via the equipment_assignments ledger (issue →
 * return, with history), shown per-member on the board (📻 chips), and flagged
 * for return on a signed-out unit. Static wiring guards + a DB round-trip over
 * the cache-availability + per-member queries the API/board rely on.
 *
 * Usage: php tests/test_net_control_equipment.php
 */
require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 109 Slice C — equipment checkout ===\n\n";

// ── Schema + RBAC ─────────────────────────────────────────────────────────────
$hasTbl = (bool) db_fetch_one(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='returned_at'",
    [$prefix . 'equipment_assignments']);
t('equipment_assignments ledger exists (issue/return history)', $hasTbl);
$permCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE code='action.issue_equipment'");
t('RBAC action.issue_equipment seeded', $permCount === 1);

// ── API ───────────────────────────────────────────────────────────────────────
$api = rd($base . '/api/equipment-assign.php');
t('equipment-assign API has cache / for_member / issue / return',
    $api !== false &&
    strpos($api, "'cache'") !== false && strpos($api, "'for_member'") !== false &&
    strpos($api, "'issue'") !== false && strpos($api, "'return'") !== false);
t('issue/return gate on action.issue_equipment + CSRF + audit; reject double-issue',
    $api !== false &&
    strpos($api, "rbac_can('action.issue_equipment')") !== false &&
    strpos($api, 'csrf_verify') !== false &&
    strpos($api, "audit_log('equipment', 'issue'") !== false &&
    strpos($api, 'already issued to') !== false);

// ── Board payload ─────────────────────────────────────────────────────────────
$nc = rd($base . '/api/net-control.php');
t('net-control payload attaches per-member equipment + can_issue_equipment cap',
    $nc !== false &&
    strpos($nc, "equipment_assignments`") !== false &&
    strpos($nc, "\$mm['equipment'] = \$eqByMember") !== false &&
    strpos($nc, "'can_issue_equipment'") !== false);

// ── Board UI ──────────────────────────────────────────────────────────────────
$js = rd($base . '/assets/js/net-control.js');
t('board renders gear chips + issue button + return wiring',
    $js !== false &&
    strpos($js, 'function gearHtml(m)') !== false &&
    strpos($js, 'function wireGearReturns(container)') !== false &&
    strpos($js, "openIssueModal(") !== false &&
    strpos($js, "action: 'return'") !== false && strpos($js, "action: 'issue'") !== false);
t('signed-out tray flags outstanding equipment for return',
    $js !== false &&
    strpos($js, 'function unitOutstandingGear(u)') !== false &&
    strpos($js, 'wireGearReturns(els.trayList)') !== false);
$ph = rd($base . '/net-control.php');
t('net-control.php has the issue modal + canIssueEquipment config',
    $ph !== false &&
    strpos($ph, 'id="ncIssueModal"') !== false &&
    strpos($ph, 'canIssueEquipment:') !== false);

// ── DB round-trip: cache availability + per-member queries ────────────────────
if (!$hasTbl) { t('SKIP integration — ledger table absent', true);
    echo "\n=== $passed passed, $failed failed ===\n"; exit($failed === 0 ? 0 : 1); }

$marker = 'ZZTEST_GEAR_C';
$member = 990109;
try {
    db_query("DELETE a FROM `{$prefix}equipment_assignments` a
              JOIN `{$prefix}newui_equipment` e ON e.id=a.equipment_id WHERE e.name=?", [$marker]);
    db_query("DELETE FROM `{$prefix}newui_equipment` WHERE name=?", [$marker]);

    db_query("INSERT INTO `{$prefix}newui_equipment` (name, available_for_events) VALUES (?, 1)", [$marker]);
    $eqId = (int) db_insert_id();

    $availSql = "SELECT id FROM `{$prefix}newui_equipment`
                  WHERE available_for_events = 1 AND name = ?
                    AND id NOT IN (SELECT equipment_id FROM `{$prefix}equipment_assignments` WHERE returned_at IS NULL)";
    t('an available item appears in the cache before issue', (bool) db_fetch_one($availSql, [$marker]));

    // Issue it (ledger row, open).
    db_query("INSERT INTO `{$prefix}equipment_assignments` (equipment_id, member_id, issued_at) VALUES (?, ?, NOW())", [$eqId, $member]);
    t('issued item DROPS OUT of the available cache', !db_fetch_one($availSql, [$marker]));

    $forMember = db_fetch_one(
        "SELECT e.name FROM `{$prefix}equipment_assignments` a
           JOIN `{$prefix}newui_equipment` e ON e.id=a.equipment_id
          WHERE a.member_id=? AND a.returned_at IS NULL AND e.name=?", [$member, $marker]);
    t('issued item shows as carried by the member', $forMember && $forMember['name'] === $marker);

    // Return it.
    db_query("UPDATE `{$prefix}equipment_assignments` SET returned_at=NOW() WHERE equipment_id=? AND returned_at IS NULL", [$eqId]);
    t('returned item RE-ENTERS the available cache', (bool) db_fetch_one($availSql, [$marker]));

    db_query("DELETE FROM `{$prefix}equipment_assignments` WHERE equipment_id=?", [$eqId]);
    db_query("DELETE FROM `{$prefix}newui_equipment` WHERE id=?", [$eqId]);
} catch (Throwable $e) {
    t('DB round-trip (unexpected error: ' . $e->getMessage() . ')', false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
