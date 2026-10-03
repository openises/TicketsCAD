<?php
/**
 * GH#141 -- a booking must not be collateral damage of ordinary dispatch work.
 *
 * Reading the code against the issue found three more real defects that all come
 * from one root cause: a pre-booking stored as an uncleared `assigns` row is
 * indistinguishable from live work to every writer that reads "any open
 * assigns row":
 *   (a) responder_set_status_internal() with a clear-mapped status (the
 *       External API's responder-status.php never passes an assign id) closes
 *       EVERY open assignment of the unit -- including the pre-booking. An RMS
 *       that sets a unit Available after call A silently cancels its booking
 *       for call B.
 *   (b) incident_clear_stragglers() refuses to reset a unit to Available after
 *       its LIVE call closes when it has any other open assignment -- including
 *       a booking hours away -- so the unit stays "On Scene" on a closed call.
 *   (c) the dispatch gate's implied double-booking warning fires when a unit
 *       holding a booking is sent to a real emergency: the exact false alarm the
 *       reporter described.
 * In reserve mode the booking is a reservation row, which none of those readers
 * or writers ever sees -- so all three are fixed by construction. Each case is
 * run twice, in immediate mode (documenting the mechanism: the defect is real
 * for a pre-booking stored as assigns) and in reserve mode (fixed).
 *
 * @requires-db
 * Usage: php tests/test_gh141_pre_booking_not_collateral_damage.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- a pre-booking is not collateral damage ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh141-test-user';
p155_remember_settings(['scheduled_assign_mode']);

$availableId = _assign_available_status_id();     // maps to incident_action 'clear' on a stock install
$stBusy = p155_make_status('p155_cd_busy', 0);

$unitStatus = function (int $rid) use ($prefix) { return (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]); };
$openAssigns = function (int $tid, int $rid) use ($prefix) {
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ? AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')", [$tid, $rid]);
};
$book = function (int $tid, int $rid, string $mode) use ($adminId) {
    return p155_worker('assign', ["id=$tid", "unit=$rid", "user=$adminId", 'set:scheduled_assign_mode=' . $mode]);
};
$mkUnit = function (string $h) use ($prefix, $availableId) {
    $rid = p155_make_unit($h);
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$availableId, $rid]);
    return $rid;
};
// A unit on a live call, On Scene.
$putOnLiveCall = function (int $rid, string $label) use ($adminId, $prefix) {
    $tid = p155_make_ticket(2, null, 'GH141 live ' . $label);
    // force=true: in immediate mode the unit already holds its booking as an
    // assigns row, so this dispatch would otherwise stop at the double-booking
    // confirmation (which is itself defect (c)); the dispatcher has said yes.
    $a = assign_create_internal($tid, $rid, '', $adminId, true);
    assign_update_status_internal((int) $a['id'], 'on_scene', $adminId);
    return [$tid, (int) $a['id']];
};

foreach (['immediate' => false, 'reserve' => true] as $mode => $fixed) {
    echo "--- booking stored in mode '$mode' " . ($fixed ? '(a reservation row)' : '(an assigns row -- today\'s behaviour)') . " ---\n";

    // ── (a) the unit is set Available by a status change ──
    $uA = $mkUnit("CD-A-$mode");
    $tBook = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', "GH141 booking a/$mode");
    $b = $book($tBook, $uA, $mode);                       // the booking is made FIRST (the unit is free)
    [$tLive, $aLive] = $putOnLiveCall($uA, "a/$mode");
    t("[$mode/a] setup: the unit is on a live call AND booked for a later one",
        $openAssigns($tLive, $uA) === 1 && (!empty($b['reserved']) === $fixed), json_encode($b));
    $setAvail = responder_set_status_internal($uA, $availableId, $adminId);
    $bookingAlive = $fixed
        ? ((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assign_reservations` WHERE ticket_id = ? AND responder_id = ? AND state = 'pending' AND active_key = 1", [$tBook, $uA]) === 1)
        : ($openAssigns($tBook, $uA) === 1);
    if ($fixed) {
        t("[$mode/a] setting the unit Available (clear-mapped) does NOT touch its booking", $bookingAlive);
    } else {
        t("[$mode/a] CONTROL -- with the booking stored as assigns, the same status change SILENTLY CANCELS it (the defect this design avoids)", !$bookingAlive);
    }
    t("[$mode/a] either way the unit's LIVE call is cleared (that part is correct)", $openAssigns($tLive, $uA) === 0);

    // ── (b) the live call closes ──
    $uB = $mkUnit("CD-B-$mode");
    $tBook2 = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', "GH141 booking b/$mode");
    $book($tBook2, $uB, $mode);                           // booking first
    [$tLive2, ] = $putOnLiveCall($uB, "b/$mode");
    $cl = incident_update_status_internal($tLive2, 1, $adminId, ['skip_disposition_check' => true, 'source' => 'ui']);
    t("[$mode/b] setup: the live call closed", $cl['status_changed'] === true);
    if ($fixed) {
        t("[$mode/b] the unit is reset to AVAILABLE when its live call closes (the booking no longer blocks the reset)", $unitStatus($uB) === $availableId, (string) $unitStatus($uB));
    } else {
        t("[$mode/b] CONTROL -- with the booking stored as assigns, the unit is NOT reset (stuck on a closed call's status)", $unitStatus($uB) !== $availableId, (string) $unitStatus($uB));
    }

    // ── (c) dispatch to a second, real emergency ──
    $uC = $mkUnit("CD-C-$mode");
    $tBook3 = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', "GH141 booking c/$mode");
    $book($tBook3, $uC, $mode);
    $tEmerg = p155_make_ticket(2, null, "GH141 emergency c/$mode");
    $e = assign_create_internal($tEmerg, $uC, '', $adminId);
    if ($fixed) {
        t("[$mode/c] dispatching the booked unit to a real emergency raises NO double-booking warning",
            empty($e['needs_confirmation']) && !empty($e['id']) && empty($e['errors']), json_encode($e));
        t("[$mode/c] and the unit really is dispatched", $openAssigns($tEmerg, $uC) === 1);
    } else {
        t("[$mode/c] CONTROL -- with the booking stored as assigns, the SAME dispatch is held for a false 'already has another active assignment' warning",
            !empty($e['needs_confirmation']), json_encode($e));
    }
    echo "\n";
}

p155_set_setting('scheduled_assign_mode', 'immediate');
p155_cleanup();
echo "=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
