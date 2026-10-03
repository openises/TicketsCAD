<?php
/**
 * GH#141 -- a reservation becomes a real dispatch at the booked time, through
 * the REAL assign_create_internal(), exactly once, and never double-books.
 *
 * Drives the real promoters (assign_reservations_promote_one / _for_ticket /
 * _due, the activation function, the status writer's hooks) against real
 * fixtures; "time passes" by backdating booked_date, never by sleeping. The
 * promoters run in worker subprocesses so the concurrency cases are genuinely
 * concurrent and the settings (primary unit mode, lead time) are fresh per call.
 *
 * Proves:
 *   - a due reservation promotes ONCE: one assigns row, unit Dispatched,
 *     assigns.dispatched stamped at PROMOTION time (not booking time -- the
 *     Intervals report stays truthful), one assign audit row naming the system,
 *     one SSE refresh, reservation 'promoted' with active_key NULL
 *   - three concurrent promoters => exactly one assigns row, exactly one
 *     winner, exactly ONE assign.created webhook delivery (a live receiver)
 *   - primary-unit "auto" mode populates the primary at promotion
 *   - a unit busy elsewhere (On Scene, not Multi-Assign) => the reservation is
 *     HELD, not double-booked; it is not auto-retried; a dispatcher's Dispatch
 *     now (force) promotes it. Multi-Assign => auto-promoted.
 *   - a hard block (dispatch level Unavailable) is held even via Dispatch now
 *     with force, and promotes once the unit's status allows it
 *   - closing the incident cancels its reservations
 *   - soft-deleting the incident stops promotion; RESTORING it makes the very
 *     same reservation promotable again (excluded by join, never mutated)
 *   - a Scheduled incident opened by hand, or activated by its booked time,
 *     promotes its reservations
 *   - a reservation not yet due stays pending; the lead time dispatches it early
 *   - release works and a released reservation cannot be promoted
 *
 * @requires-db
 * Usage: php tests/test_gh141_reservation_promotion.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/../inc/notify-fanout.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- promoting a reservation ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh141-test-user';
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes', 'primary_unit_mode', 'webhook_url_allowlist']);
p155_set_setting('webhook_url_allowlist', '127.0.0.1');
p155_set_setting('scheduled_assign_mode', 'reserve');
p155_track_fanout_queue();

$stAvail = p155_make_status('p155_avail', 0);
$stBusy  = p155_make_status('p155_busy', 0);
$stBlock = p155_make_status('p155_block', 2);

$mkUnit = function (string $h, int $status = 0, int $multi = 0) use ($prefix, $stAvail) {
    $rid = p155_make_unit($h, $multi);
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$status ?: $stAvail, $rid]);
    return $rid;
};
$unitStatus = function (int $rid) use ($prefix) { return (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]); };
$assignCount = function (int $tid, int $rid) use ($prefix) {
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ?", [$tid, $rid]);
};
$resRow = function (int $resId) use ($prefix) { return db_fetch_one("SELECT * FROM `{$prefix}assign_reservations` WHERE id = ?", [$resId]); };
// Reserve through the REAL writer in reserve mode; returns the reservation id.
$reserve = function (int $tid, int $rid) use ($adminId) {
    $r = p155_worker('assign', ["id=$tid", "unit=$rid", "user=$adminId", 'set:scheduled_assign_mode=reserve']);
    return (int) ($r['reservation_id'] ?? 0);
};
$makeDue = function (int $tid) use ($prefix) {
    db_query("UPDATE `{$prefix}ticket` SET booked_date = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id = ?", [$tid]);
};

$rcv = p155_start_receiver();
$subAssign = null;
if ($rcv) {
    $subAssign = p155_make_subscription('gh141_assign', ['assign.created'], 'http://127.0.0.1:' . $rcv['port'] . '/hook');
    notify_fanout_forget_channel_cache();
}

// ── A. The basic promotion ────────────────────────────────────────────────
echo "--- a due reservation promotes once ---\n";
$uA = $mkUnit('PRM-A');
$tA = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 promote A');
$resA = $reserve($tA, $uA);
t('setup: reserved', $resA > 0 && $assignCount($tA, $uA) === 0);
db_query("UPDATE `{$prefix}assign_reservations` SET reserved_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?", [$resA]);
$makeDue($tA);
$promoStart = (string) db_fetch_value('SELECT NOW()');
$r = p155_worker('promote_one', ["res=$resA"]);
t('promote_one reports promoted', !empty($r['promoted']) && empty($r['errors']) && (int) $r['assign_id'] > 0, json_encode($r));
t('exactly one assigns row exists', $assignCount($tA, $uA) === 1);
t('the unit is now Dispatched', $unitStatus($uA) === _assign_dispatched_status_id());
$ar = db_fetch_one("SELECT * FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ?", [$tA, $uA]);
t("assigns.dispatched is the PROMOTION time, not the booking time (the Intervals report's turnout leg stays truthful)",
    $ar && strtotime((string) $ar['dispatched']) >= strtotime($promoStart) - 2 && strtotime((string) $ar['dispatched']) > time() - 3600,
    (string) ($ar['dispatched'] ?? ''));
t('assigns.user_id is the person who reserved it', $ar && (int) $ar['user_id'] === $adminId);
$row = $resRow($resA);
t("the reservation is 'promoted' with active_key NULL, closed_at and promoted_assign_id set",
    $row['state'] === 'promoted' && $row['active_key'] === null && !empty($row['closed_at']) && (int) $row['promoted_assign_id'] === (int) $ar['id']);
$assignAudit = db_fetch_all("SELECT user_id, user_name, details FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND target_type = 'assigns' AND target_id = ?", [(string) $ar['id']]);
t('exactly one `assign` audit row (-> assign.created) for the promotion, naming the SYSTEM',
    count($assignAudit) === 1 && $assignAudit[0]['user_name'] === 'System' && $assignAudit[0]['user_id'] === null, json_encode($assignAudit));
t('and it records which reservation it came from', strpos((string) ($assignAudit[0]['details'] ?? ''), '"from_reservation":true') !== false);
t('exactly one SSE responder:assign refresh for the incident',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'responder:assign' AND JSON_EXTRACT(payload, '$.ticket_id') = ?", [$tA]) === 1);
t('a reserve_promote audit row was written',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'reserve_promote' AND JSON_EXTRACT(details, '$.reservation_id') = ?", [$resA]) === 1);
$again = p155_worker('promote_one', ["res=$resA"]);
t('promoting it again is a no-op (already promoted)', empty($again['promoted']) && ($again['skipped'] ?? '') === 'already_promoted' && $assignCount($tA, $uA) === 1, json_encode($again));

// ── B. Concurrency ────────────────────────────────────────────────────────
echo "\n--- three concurrent promoters ---\n";
$uB = $mkUnit('PRM-B');
$tB = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 promote B (race)');
$resB = $reserve($tB, $uB);
$makeDue($tB);
$jobs = [];
for ($i = 0; $i < 3; $i++) { $jobs[] = ['action' => 'promote_one', 'args' => ["res=$resB"]]; }
$results = p155_worker_race($jobs);
$winners = 0; $skipped = 0;
foreach ($results as $x) {
    if (!is_array($x)) continue;
    if (!empty($x['promoted'])) $winners++;
    elseif (in_array($x['skipped'] ?? '', ['already_promoted'], true)) $skipped++;
}
t('exactly ONE of the three promoters won', $winners === 1, json_encode($results));
t('the other two found it already promoted (they serialized on the row lock)', $skipped === 2, json_encode($results));
t('exactly ONE assigns row exists (no double dispatch)', $assignCount($tB, $uB) === 1);
t('exactly one assign audit row for the incident',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tB]) === 1);
if ($rcv) {
    p155_drain();
    $deliveries = (int) db_fetch_value("SELECT COUNT(DISTINCT delivery_uid) FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ? AND event_type = 'assign.created' AND status = 'success' AND JSON_EXTRACT(payload, '$.data.details.ticket_id') = ?", [$subAssign, $tB]);
    t('and EXACTLY ONE assign.created webhook delivery reached a live receiver for the promotion', $deliveries === 1, (string) $deliveries);
} else {
    echo "SKIP: no local webhook receiver -- the delivery-count assertion was not run\n";
}

// ── C. Primary-unit auto mode ─────────────────────────────────────────────
echo "\n--- primary-unit auto mode ---\n";
$uC = $mkUnit('PRM-C');
$tC = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 promote C (primary)');
$resC = $reserve($tC, $uC);
$makeDue($tC);
$r = p155_worker('promote_one', ["res=$resC", 'set:primary_unit_mode=auto']);
t('promoted', !empty($r['promoted']), json_encode($r));
t('primary_unit_mode=auto populated the incident\'s primary unit with the promoted unit (it saw it as the first active assignment)',
    (int) db_fetch_value("SELECT primary_responder_id FROM `{$prefix}ticket` WHERE id = ?", [$tC]) === $uC);
p155_set_setting('primary_unit_mode', 'off');

// ── D. A busy unit is HELD, never double-booked ───────────────────────────
echo "\n--- a busy unit at activation ---\n";
$uD = $mkUnit('PRM-D');
$tL = p155_make_ticket(2, null, 'GH141 live call D');
$aL = assign_create_internal($tL, $uD, '', $adminId);
assign_update_status_internal((int) $aL['id'], 'on_scene', $adminId);
t('setup: the unit is On Scene on a live call', $assignCount($tL, $uD) === 1);
$tD = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 promote D (busy)');
$resD = $reserve($tD, $uD);
t('setup: reserving a BUSY unit for later is allowed (the gate runs at promotion, not now)', $resD > 0);
$makeDue($tD);
$r = p155_worker('promote_one', ["res=$resD"]);
t('the promotion does NOT dispatch -- the reservation is HELD for a dispatcher', empty($r['promoted']) && !empty($r['blocked']), json_encode($r));
t('the unit is NOT double-booked (no assigns row on the reserved incident)', $assignCount($tD, $uD) === 0);
$row = $resRow($resD);
t("state is 'blocked' (still live: active_key stays 1) with the gate's reason recorded",
    $row['state'] === 'blocked' && (int) $row['active_key'] === 1 && stripos((string) $row['outcome_note'], 'already has another active assignment') !== false, json_encode($row));
t('a "needs a dispatcher" line was written to the incident log',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ? AND action_type = 27 AND description LIKE '%needs a dispatcher%'", [$tD]) === 1);
t('a reserve_blocked audit row was written',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'reserve_blocked' AND JSON_EXTRACT(details, '$.reservation_id') = ?", [$resD]) === 1);
$due = p155_worker('promote_due');
t('the timer does NOT retry a held reservation (an auto-retry would dispatch a unit hours late when it finally became free)',
    (int) ($due['promoted'] ?? 0) === 0 && $assignCount($tD, $uD) === 0, json_encode($due));
$dn = p155_worker('promote_one', ["res=$resD", 'force=1', "user=$adminId"]);
t("a dispatcher's Dispatch now (force) promotes the held reservation", !empty($dn['promoted']) && $assignCount($tD, $uD) === 1, json_encode($dn));
t('the unit now legitimately has two active assignments, the dispatcher having confirmed it',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE responder_id = ? AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')", [$uD]) === 2);

$uM = $mkUnit('PRM-MULTI', 0, 1);
$tLm = p155_make_ticket(2, null, 'GH141 live call multi');
$aLm = assign_create_internal($tLm, $uM, '', $adminId);
assign_update_status_internal((int) $aLm['id'], 'on_scene', $adminId);
$tMm = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 promote multi');
$resM = $reserve($tMm, $uM);
$makeDue($tMm);
$r = p155_worker('promote_one', ["res=$resM"]);
t('a Multi-Assign unit in the same situation is PROMOTED without a human (Multi-Assign means the same thing here as everywhere)',
    !empty($r['promoted']) && $assignCount($tMm, $uM) === 1, json_encode($r));

// ── E. A hard block ───────────────────────────────────────────────────────
echo "\n--- Dispatch level: Unavailable ---\n";
$uE = $mkUnit('PRM-E', $stBlock);
$tE = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 promote E (hard block)');
$resE = $reserve($tE, $uE);
$makeDue($tE);
$r = p155_worker('promote_one', ["res=$resE"]);
t('a unit whose status is Unavailable is held, flagged as a hard block', !empty($r['blocked']) && !empty($r['hard_block']) && $assignCount($tE, $uE) === 0, json_encode($r));
$r = p155_worker('promote_one', ["res=$resE", 'force=1', "user=$adminId"]);
t('Dispatch now with force CANNOT bypass a hard block', empty($r['promoted']) && !empty($r['hard_block']) && $assignCount($tE, $uE) === 0, json_encode($r));
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $uE]);
$r = p155_worker('promote_one', ["res=$resE", "user=$adminId"]);
t('once the unit\'s status allows it, the SAME held reservation promotes', !empty($r['promoted']) && $assignCount($tE, $uE) === 1, json_encode($r));

// ── F. Closing the incident cancels ───────────────────────────────────────
echo "\n--- closing the incident ---\n";
$uF = $mkUnit('PRM-F');
$tF = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 close F');
$resF = $reserve($tF, $uF);
$cl = incident_update_status_internal($tF, 1, $adminId, ['skip_disposition_check' => true, 'source' => 'ui']);
t('the incident closes', $cl['status_changed'] === true);
$row = $resRow($resF);
t("the reservation is cancelled ('incident closed'), active_key NULL", $row['state'] === 'cancelled' && $row['active_key'] === null && $row['outcome_note'] === 'incident closed', json_encode($row));
t('and it can no longer be promoted', (p155_worker('promote_one', ["res=$resF"])['skipped'] ?? '') === 'already_cancelled');
t('the unit stayed Available throughout', $unitStatus($uF) === $stAvail);

// ── G. Soft delete is excluded by join, restore revives ───────────────────
echo "\n--- soft-delete and restore ---\n";
$uG = $mkUnit('PRM-G');
$tG = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 soft delete G');
$resG = $reserve($tG, $uG);
$makeDue($tG);
db_query("UPDATE `{$prefix}ticket` SET deleted_at = NOW() WHERE id = ?", [$tG]);
$due = p155_worker('promote_due');
t('a due reservation on a SOFT-DELETED incident is not promoted by the timer', $assignCount($tG, $uG) === 0 && $resRow($resG)['state'] === 'pending', json_encode($due));
$r = p155_worker('promote_one', ["res=$resG"]);
t('nor by a direct promote (skipped: ticket_deleted) -- and the reservation is NOT mutated', ($r['skipped'] ?? '') === 'ticket_deleted' && $resRow($resG)['state'] === 'pending', json_encode($r));
t('the deleted incident\'s reservation is invisible to the unit chip data', unit_future_commitments([$uG]) === []);
db_query("UPDATE `{$prefix}ticket` SET deleted_at = NULL WHERE id = ?", [$tG]);
$due = p155_worker('promote_due');
t('RESTORED from the wastebasket: the very same reservation promotes (derived by join, never mutated)',
    $assignCount($tG, $uG) === 1 && $resRow($resG)['state'] === 'promoted', json_encode($due));

// ── H. Opening a Scheduled incident by hand; I. activation ────────────────
echo "\n--- opening / activating promotes ---\n";
$uH = $mkUnit('PRM-H');
$tH = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'GH141 manual open H');
$resH = $reserve($tH, $uH);
$r = p155_worker('status', ["id=$tH", 'to=2', "user=$adminId"]);
t('a dispatcher making the Scheduled incident Open (3 -> 2) by hand', !empty($r['status_changed']), json_encode($r));
t('promotes its reservation (the unit is dispatched)', $assignCount($tH, $uH) === 1 && $resRow($resH)['state'] === 'promoted');

$uI = $mkUnit('PRM-I');
$tI = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'GH141 activation I');
$resI = $reserve($tI, $uI);
$makeDue($tI);
$r = p155_worker('activate_one', ["id=$tI"]);
t('the booked time arriving (the activation function)', !empty($r['activated']) && (int) $r['promoted'] === 1, json_encode($r));
t('promotes the reservation in the same step', $assignCount($tI, $uI) === 1 && $resRow($resI)['state'] === 'promoted');
t('the incident is Open', (int) db_fetch_value("SELECT status FROM `{$prefix}ticket` WHERE id = ?", [$tI]) === 2);

// ── J. Not due stays pending; the lead dispatches early ───────────────────
echo "\n--- not due / lead time ---\n";
$uJ = $mkUnit('PRM-J');
$tJ = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'GH141 lead J');
$resJ = $reserve($tJ, $uJ);
$due = p155_worker('promote_due');
t('a reservation whose time has NOT come stays pending (lead 0)', $resRow($resJ)['state'] === 'pending' && $assignCount($tJ, $uJ) === 0, json_encode($due));
$due = p155_worker('promote_due', ['set:scheduled_assign_lead_minutes=600']);
t('with a 600-minute lead the SAME reservation is dispatched early (the incident is still Scheduled)',
    $resRow($resJ)['state'] === 'promoted' && $assignCount($tJ, $uJ) === 1 && (int) db_fetch_value("SELECT status FROM `{$prefix}ticket` WHERE id = ?", [$tJ]) === 3, json_encode($due));
p155_set_setting('scheduled_assign_lead_minutes', '0');

// ── K. Release ───────────────────────────────────────────────────────────
echo "\n--- release ---\n";
$uK = $mkUnit('PRM-K');
$tK = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'GH141 release K');
$resK = $reserve($tK, $uK);
$rel = assign_reservation_release($resK, $adminId, 'released by a dispatcher');
t('release succeeds', !empty($rel['released']) && (int) $rel['ticket_id'] === $tK, json_encode($rel));
$row = $resRow($resK);
t("cancelled, active_key NULL, the reason recorded", $row['state'] === 'cancelled' && $row['active_key'] === null && $row['outcome_note'] === 'released by a dispatcher');
$rel2 = assign_reservation_release($resK, $adminId);
t('releasing it again is refused', empty($rel2['released']) && !empty($rel2['errors']));
t('a released reservation cannot be promoted', (p155_worker('promote_one', ["res=$resK"])['skipped'] ?? '') === 'already_cancelled');
t('and the unit can be reserved for the same incident again (a finished row does not block it)', $reserve($tK, $uK) > 0);
$relMissing = assign_reservation_release(2147480000, $adminId);
t('releasing a reservation that does not exist is an error, not a crash', empty($relMissing['released']) && !empty($relMissing['errors']));

p155_set_setting('scheduled_assign_mode', 'immediate');
p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
