<?php
/**
 * GH#141 -- assigning a unit to a Scheduled call marks it Dispatched
 * immediately, not at the scheduled time.
 *
 * THE REGRESSION TEST FOR THE REPORTED SYMPTOM, through the REAL writer
 * (assign_create_internal()) with the setting in each of its values. Every
 * mode-sensitive call runs in a FRESH subprocess worker (get_variable() caches
 * the whole `settings` table for the life of a process -- Phase 151 pitfall),
 * which applies the setting BEFORE the process has read any.
 *
 * Proves:
 *   - mode = immediate (the default, today's behaviour): an assigns row exists
 *     and the unit is Dispatched -- UNCHANGED. Nothing about upgrading changes
 *     how dispatch works.
 *   - mode = reserve, Scheduled incident with a future booked time: a
 *     reservation row exists, there is NO assigns row, the unit's status is
 *     unchanged (still Available), and it does not read as busy to the
 *     "does this unit already have live work" test the rest of the app uses.
 *   - the cases that must still dispatch at once: booked time in the past, no
 *     booked time, an Open incident, a booked time inside the lead window, and an
 *     explicit dispatch_now
 *   - each control CHANGES behaviour (mode flips the outcome; the lead flips it
 *     for the same incident; dispatch_now flips it)
 *   - a second live reservation for the same (incident, unit) is refused -- by
 *     the DATABASE's unique key, asserted as a real SQL error, not just by the
 *     PHP pre-check; finished reservations coexist
 *   - the reservation writes its action-log line and an audit row that is NOT
 *     webhook-mapped (nothing was dispatched); no assign.created is announced
 *   - a missing migration (no table) never blocks dispatch
 *
 * @requires-db
 * Usage: php tests/test_gh141_reserve_mode_assign.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/../inc/webhooks.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- reserve mode: assigning to a Scheduled incident ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes']);

$availableId  = _assign_available_status_id();
$dispatchedId = _assign_dispatched_status_id();
t('fixture sanity: the install distinguishes Available from Dispatched', $availableId > 0 && $dispatchedId > 0 && $availableId !== $dispatchedId);

$unitStatus = function (int $rid) use ($prefix) { return (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]); };
$assignRows = function (int $tid, int $rid) use ($prefix) {
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ?", [$tid, $rid]);
};
$resRows = function (int $tid, int $rid, ?string $state = null) use ($prefix) {
    $sql = "SELECT COUNT(*) FROM `{$prefix}assign_reservations` WHERE ticket_id = ? AND responder_id = ?";
    $p = [$tid, $rid];
    if ($state !== null) { $sql .= ' AND state = ?'; $p[] = $state; }
    return (int) db_fetch_value($sql, $p);
};
$setAvailable = function (int $rid) use ($prefix, $availableId) {
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$availableId, $rid]);
};
$assign = function (int $tid, int $rid, string $mode, array $extra = []) use ($adminId) {
    return p155_worker('assign', array_merge(["id=$tid", "unit=$rid", "user=$adminId", 'set:scheduled_assign_mode=' . $mode], $extra));
};

// ── 1. mode = immediate: today's behaviour, unchanged ─────────────────────
echo "--- mode = immediate (the default) ---\n";
$u1 = p155_make_unit('RES-IMM');
$setAvailable($u1);
$tFuture = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 booked 3h ahead');
$r = $assign($tFuture, $u1, 'immediate');
t('immediate: the assignment succeeds with a real id', !empty($r['id']) && empty($r['errors']) && empty($r['reserved']), json_encode($r));
t('immediate: an assigns row exists', $assignRows($tFuture, $u1) === 1);
t("immediate: the unit IS Dispatched at once (the reporter's symptom -- unchanged by default)", $unitStatus($u1) === $dispatchedId);
t('immediate: no reservation was created', $resRows($tFuture, $u1) === 0);

// ── 2. mode = reserve: the fix ────────────────────────────────────────────
echo "\n--- mode = reserve, future booked time ---\n";
$u2 = p155_make_unit('RES-A');
$setAvailable($u2);
$r = $assign($tFuture, $u2, 'reserve', ['role=Medic']);
t('reserve: the answer says RESERVED, with id 0 (never a fake assignment id)',
    !empty($r['reserved']) && (int) ($r['id'] ?? -1) === 0 && !empty($r['reservation_id']) && empty($r['errors']), json_encode($r));
t('reserve: NO assigns row exists', $assignRows($tFuture, $u2) === 0);
t('reserve: the unit is still AVAILABLE (not flipped to Dispatched)', $unitStatus($u2) === $availableId);
t('reserve: a live reservation row exists', $resRows($tFuture, $u2, 'pending') === 1);
$row = db_fetch_one("SELECT * FROM `{$prefix}assign_reservations` WHERE ticket_id = ? AND responder_id = ?", [$tFuture, $u2]);
t('reserve: row carries the reserver, role, active_key=1', $row && (int) $row['reserved_by'] === $adminId && $row['role'] === 'Medic' && (int) $row['active_key'] === 1);
t("reserve: promotes_at is the booked time (lead 0)", !empty($r['promotes_at']));
t('reserve: the unit does NOT read as busy to the app-wide "has other live work" test',
    _assign_has_other_active($u2, 0) === false);
t('reserve: an action-log line was written on the incident (type 27)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ? AND action_type = 27 AND description LIKE 'Reserved %'", [$tFuture]) >= 1);
// The reservation's audit rows hang off the RESERVATION (target_type 'assigns',
// target_id = the reservation id) and carry the incident in their details.
test_fixture_guard_track_where('newui_audit_log', "category = 'incident' AND activity IN ('reserve','reserve_release','reserve_promote','reserve_blocked') AND JSON_EXTRACT(details, '$.ticket_id') IN (" . (int) $tFuture . ")", []);
$reserveAudit = db_fetch_all(
    "SELECT id, summary, details FROM `{$prefix}newui_audit_log`
      WHERE category = 'incident' AND activity = 'reserve' AND target_type = 'assigns'
        AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tFuture]);
t('reserve: an audit row `reserve` was written (target = the reservation)', count($reserveAudit) === 1 && (string) db_fetch_value("SELECT id FROM `{$prefix}assign_reservations` WHERE ticket_id = ? AND responder_id = ?", [$tFuture, $u2]) === (string) db_fetch_value("SELECT target_id FROM `{$prefix}newui_audit_log` WHERE id = ?", [$reserveAudit[0]['id'] ?? 0]));
t('reserve: that audit event is NOT webhook-mapped (nothing was dispatched)', _audit_to_webhook_event('incident', 'reserve', 'assigns') === null);
t('reserve: NO `assign` audit row exists for the incident (no assign.created webhook for a dispatch that has not happened)',
    count(db_fetch_all("SELECT id FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tFuture])) === 0);

// ── 3. The cases that must still dispatch at once, in reserve mode ────────
echo "\n--- reserve mode still dispatches at once when it should ---\n";
$cases = [
    ['booked in the PAST (the time has come)', 3, 'DATE_SUB(NOW(), INTERVAL 5 MINUTE)', []],
    ['no booked time at all',                   3, null, []],
    ['an OPEN incident',                         2, null, []],
];
foreach ($cases as [$label, $status, $booked, $extra]) {
    $u = p155_make_unit('RES-X' . substr(md5($label), 0, 4));
    $setAvailable($u);
    $tx = p155_make_ticket($status, $booked, 'GH141 ' . $label);
    $r = $assign($tx, $u, 'reserve');
    t("reserve + $label: dispatched immediately", !empty($r['id']) && empty($r['reserved']) && $assignRows($tx, $u) === 1 && $unitStatus($u) === $dispatchedId, json_encode($r));
}

$uDn = p155_make_unit('RES-DN');
$setAvailable($uDn);
$tDn = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'GH141 dispatch_now');
$r = $assign($tDn, $uDn, 'reserve', ['dispatch_now=1']);
t('reserve + dispatch_now: dispatches at once even though the booked time is hours away',
    !empty($r['id']) && empty($r['reserved']) && $assignRows($tDn, $uDn) === 1 && $unitStatus($uDn) === $dispatchedId, json_encode($r));
$uNoDn = p155_make_unit('RES-NODN');
$setAvailable($uNoDn);
$r = $assign($tDn, $uNoDn, 'reserve');
t('control: the same incident WITHOUT dispatch_now reserves (so dispatch_now is what changed the outcome)', !empty($r['reserved']), json_encode($r));

// ── 4. The lead time changes the outcome for the SAME incident ────────────
echo "\n--- lead time ---\n";
$tSoon = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 20 MINUTE)', 'GH141 booked 20 minutes ahead');
$uL0 = p155_make_unit('RES-L0'); $setAvailable($uL0);
$uL30 = p155_make_unit('RES-L30'); $setAvailable($uL30);
$r0 = $assign($tSoon, $uL0, 'reserve', ['set:scheduled_assign_lead_minutes=0']);
t('lead 0: booked 20 minutes ahead -> RESERVED', !empty($r0['reserved']), json_encode($r0));
$r30 = $assign($tSoon, $uL30, 'reserve', ['set:scheduled_assign_lead_minutes=30']);
t('lead 30: the SAME booking is inside the lead window -> dispatched at once', !empty($r30['id']) && empty($r30['reserved']) && $assignRows($tSoon, $uL30) === 1, json_encode($r30));
t("the lead window dispatches the unit EARLY (status Dispatched before the booked time)", $unitStatus($uL30) === $dispatchedId);

// ── 5. The setting is read strictly ──────────────────────────────────────
echo "\n--- the setting's reader ---\n";
$probe = function (string $value, string $leadValue = '0'): array {
    $code = 'require ' . var_export(__DIR__ . '/../config.php', true) . ';'
          . 'require ' . var_export(__DIR__ . '/../inc/assign-reservations.php', true) . ';'
          . 'echo json_encode(["mode" => assign_reservation_mode(), "lead" => assign_reservation_lead_minutes()]);';
    foreach (['scheduled_assign_mode' => $value, 'scheduled_assign_lead_minutes' => $leadValue] as $k => $v) {
        p155_set_setting($k, $v);
    }
    $res = p155_run_process(['-r', $code]);
    $j = json_decode(trim($res['stdout']), true);
    return is_array($j) ? $j : ['mode' => '?', 'lead' => -1, 'raw' => $res['stdout'] . $res['stderr']];
};
foreach (['immediate' => 'immediate', 'reserve' => 'reserve', 'RESERVE' => 'immediate', 'banana' => 'immediate', '' => 'immediate', '1' => 'immediate'] as $in => $want) {
    $got = $probe($in);
    t("scheduled_assign_mode='" . $in . "' reads as '" . $want . "'", $got['mode'] === $want, json_encode($got));
}
foreach (['0' => 0, '30' => 30, '1440' => 1440, '99999' => 1440, '-5' => 0, 'abc' => 0, '' => 0] as $in => $want) {
    $got = $probe('reserve', (string) $in);
    t("scheduled_assign_lead_minutes='" . $in . "' reads as " . $want, $got['lead'] === $want, json_encode($got));
}

// ── 6. Duplicates: the DATABASE refuses, not just the PHP pre-check ───────
echo "\n--- duplicates ---\n";
$rDup = $assign($tFuture, $u2, 'reserve');
t('a second reservation for the same (incident, unit) is refused with a clear message',
    !empty($rDup['errors']) && stripos((string) $rDup['errors'][0], 'already reserved') !== false, json_encode($rDup));
t('and did not create a second row', $resRows($tFuture, $u2) === 1);
$sqlErr = '';
try {
    db_query("INSERT INTO `{$prefix}assign_reservations` (ticket_id, responder_id, role, reserved_by, state, active_key) VALUES (?, ?, '', ?, 'pending', 1)",
        [$tFuture, $u2, $adminId]);
} catch (Throwable $e) { $sqlErr = $e->getMessage(); }
t('the DATABASE itself rejects a second live row for the pair (uk_active is a real constraint -- a SQL error, not a PHP check)',
    strpos($sqlErr, '1062') !== false, $sqlErr);
$finishedOk = true;
try {
    db_query("INSERT INTO `{$prefix}assign_reservations` (ticket_id, responder_id, role, reserved_by, state, active_key) VALUES (?, ?, '', ?, 'cancelled', NULL)", [$tFuture, $u2, $adminId]);
    db_query("INSERT INTO `{$prefix}assign_reservations` (ticket_id, responder_id, role, reserved_by, state, active_key) VALUES (?, ?, '', ?, 'promoted', NULL)", [$tFuture, $u2, $adminId]);
} catch (Throwable $e) { $finishedOk = false; }
t('any number of FINISHED rows (active_key NULL) coexist with the live one', $finishedOk);
$already = $assign($tFuture, $u1, 'reserve');
t('a unit ALREADY dispatched to the incident cannot also be reserved for it', !empty($already['errors']) && stripos((string) $already['errors'][0], 'already assigned') !== false, json_encode($already));

// ── 7. A missing migration never blocks dispatch ─────────────────────────
echo "\n--- table missing: dispatch is never blocked ---\n";
$uM = p155_make_unit('RES-NOTABLE'); $setAvailable($uM);
$tM = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', 'GH141 no table');
db_query("RENAME TABLE `{$prefix}assign_reservations` TO `{$prefix}assign_reservations_p155bak`");
test_fixture_guard_track_cleanup(function () use ($prefix) {
    $t = db_fetch_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'assign_reservations_p155bak']);
    $o = db_fetch_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'assign_reservations']);
    if ($t && !$o) db_query("RENAME TABLE `{$prefix}assign_reservations_p155bak` TO `{$prefix}assign_reservations`");
}, 'restore assign_reservations table');
try {
    $rM = $assign($tM, $uM, 'reserve');
    t("reserve mode with NO table: the unit is dispatched immediately (a missing migration never blocks dispatch)",
        !empty($rM['id']) && empty($rM['reserved']) && empty($rM['errors']) && $assignRows($tM, $uM) === 1, json_encode($rM));
} finally {
    db_query("RENAME TABLE `{$prefix}assign_reservations_p155bak` TO `{$prefix}assign_reservations`");
}
t('(the table is back)', (bool) db_fetch_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'assign_reservations']));

// ── 8. incident_create_internal() with units on a Scheduled incident ─────
echo "\n--- units given to incident_create_internal() ---\n";
require_once __DIR__ . '/../inc/incident-write.php';
$uC = p155_make_unit('RES-CREATE'); $setAvailable($uC);
$booked = date('Y-m-d H:i:s', time() + 6 * 3600);
$inTypeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
if ($inTypeId <= 0) {
    db_query("INSERT INTO `{$prefix}in_types` (`type`, `description`) VALUES ('GH141 test type', 'gh141')");
    $inTypeId = (int) db_insert_id();
    test_fixture_guard_track('in_types', $inTypeId);
}
$code = 'require ' . var_export(__DIR__ . '/../config.php', true) . ';'
      . 'require ' . var_export(__DIR__ . '/../inc/incident-write.php', true) . ';'
      . '$_SESSION["user_id"] = ' . (int) $adminId . ';'
      . '$r = incident_create_internal(["in_types_id" => ' . $inTypeId . ', "scope" => "GH141 created scheduled", "status" => 3, "booked_date" => ' . var_export($booked, true) . ', "assign_responders" => [' . (int) $uC . ']], ' . (int) $adminId . ');'
      . 'echo json_encode($r);';
p155_set_setting('scheduled_assign_mode', 'reserve');
$res = p155_run_process(['-r', $code]);
$cr = json_decode(trim($res['stdout']), true);
$newTid = is_array($cr) ? (int) ($cr['id'] ?? 0) : 0;
if ($newTid > 0) {
    p155_track_ticket($newTid);
}
t('creating a SCHEDULED incident with a unit succeeds in reserve mode', $newTid > 0 && empty($cr['errors']), $res['stdout'] . $res['stderr']);
t('the unit given at creation was RESERVED, not dispatched', $newTid > 0 && $resRows($newTid, $uC, 'pending') === 1 && $assignRows($newTid, $uC) === 0 && $unitStatus($uC) === $availableId);

p155_set_setting('scheduled_assign_mode', 'immediate');
p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
