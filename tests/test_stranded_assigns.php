<?php
/**
 * Stranded-assignment cascade + heal tests (Eric 2026-07-07).
 *
 * Covers the report "I closed an incident but it did not clear the
 * assigned units — M1 stayed Responding":
 *   1. the live close cascade (incident_update_status_internal → 1)
 *      still clears assigns + resets responders after the refactor
 *   2. incident_clear_stragglers() repairs a PRE-cascade close
 *      (ticket already status=1, assign still open)
 *   3. conservative mode preserves a status a dispatcher set since
 *      the close (Out of Service must NOT flip to Available)
 *   4. un_status.excl_from_reset = 'y' is honored (previously stored
 *      by the config UI but never enforced anywhere)
 *   5. the Available lookup no longer matches "Unavailable"
 *      (old LIKE '%avail%' bug)
 *   6. a responder holding another active assignment is not reset
 *   7. idempotency — second run clears nothing
 *   8. repair stamps assigns.clear with the supplied close time
 *
 * Usage: php tests/test_stranded_assigns.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/incident-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

$now = date('Y-m-d H:i:s');
$cleanup = ['ticket' => [], 'responder' => [], 'un_status' => []];

// ── Fixtures ────────────────────────────────────────────────────────
$type_id = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");

function mk_status($val, $group, $ia, $excl) {
    global $prefix, $cleanup;
    db_query(
        "INSERT INTO `{$prefix}un_status`
         (`status_val`, `description`, `dispatch`, `watch`, `hide`, `excl_from_reset`,
          `group`, `sort`, `bg_color`, `text_color`, `incident_action`, `resets_par`,
          `extra_data_type`, `extra_data_required`, `extra_data_target`, `bed_delivery`)
         VALUES (?, ?, 0, 0, 'n', ?, ?, 99, 'transparent', '#000000', ?, 0, 'none', 0, 'action_log', 0)",
        [$val, 'stranded-assigns test fixture', $excl, $group, $ia]
    );
    $id = (int) db_insert_id();
    $cleanup['un_status'][] = $id;
    return $id;
}

function mk_ticket($status) {
    global $prefix, $cleanup, $type_id, $now;
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `date`, `problemstart`, `_by`)
         VALUES (?, ?, 0, '__STR_ Test Incident', 'stranded-assigns test fixture', ?, ?, 1)",
        [$type_id, $status, $now, $now]
    );
    $id = (int) db_insert_id();
    $cleanup['ticket'][] = $id;
    return $id;
}

function mk_responder($name, $statusId) {
    global $prefix, $cleanup;
    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`, `handle`, `multi`)
         VALUES (?, 'stranded-assigns test fixture', ?, ?, 0)",
        [$name, $statusId, substr($name, -4)]
    );
    $id = (int) db_insert_id();
    $cleanup['responder'][] = $id;
    return $id;
}

function mk_assign($ticketId, $responderId) {
    global $prefix, $now;
    db_query(
        "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `status_id`, `dispatched`, `user_id`, `as_of`)
         VALUES (?, ?, 1, ?, 1, ?)",
        [$ticketId, $responderId, $now, $now]
    );
    return (int) db_insert_id();
}

function resp_status($responderId) {
    global $prefix;
    return (int) db_fetch_value("SELECT `un_status_id` FROM `{$prefix}responder` WHERE `id` = ?", [$responderId]);
}

function assign_open($assignId) {
    global $prefix;
    $row = db_fetch_one("SELECT `clear` FROM `{$prefix}assigns` WHERE `id` = ?", [$assignId]);
    $c = (string) ($row['clear'] ?? '');
    return $c === '' || strpos($c, '0000') === 0;
}

echo "=== Stranded-assignment cascade tests ===\n\n";

// Sweep leftovers from an earlier aborted run (status_val is varchar(20))
db_query("DELETE FROM `{$prefix}un_status` WHERE `description` = 'stranded-assigns test fixture'");
db_query("DELETE FROM `{$prefix}responder` WHERE `description` = 'stranded-assigns test fixture'");
foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = '__STR_ Test Incident'") as $old) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$old['id']]);
}

$stResponding = mk_status('__STR_Responding', 'busy', 'responding', 'n');
$stOOS        = mk_status('__STR_OOS', 'unavailable', '', 'n');
$stGuarded    = mk_status('__STR_Guarded', 'busy', 'responding', 'y');

// Available id the helper should resolve to (group av… / name avail…)
$availRow = db_fetch_one(
    "SELECT `id`, `status_val`, `group` FROM `{$prefix}un_status`
     WHERE LOWER(`group`) LIKE 'av%' OR LOWER(`status_val`) LIKE 'avail%'
     ORDER BY (LOWER(`group`) LIKE 'av%') DESC, `id` LIMIT 1"
);
$availId = $availRow ? (int) $availRow['id'] : 1;

// ── 5. Available lookup never resolves to "Unavailable" ───────────
test('Available lookup exists', $availRow !== null && $availRow !== false);
test('Available lookup is not Unavailable',
    stripos((string) $availRow['status_val'], 'unavail') === false);

// ── 1. Live close cascade (post-refactor) ─────────────────────────
$t1 = mk_ticket(2);
$r1 = mk_responder('__STR_ Unit A', $stResponding);
$a1 = mk_assign($t1, $r1);
$res = incident_update_status_internal($t1, 1, 1);
test('Live close: no errors', empty($res['errors']));
test('Live close: 1 assign cleared', (int) $res['cleared_assigns'] === 1);
test('Live close: 1 responder reset', (int) $res['reset_responders'] === 1);
test('Live close: assign clear stamped', !assign_open($a1));
test('Live close: responder now Available', resp_status($r1) === $availId);
test('Live close: ticket status = 1',
    (int) db_fetch_value("SELECT `status` FROM `{$prefix}ticket` WHERE `id` = ?", [$t1]) === 1);
$act = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}action` WHERE `ticket_id` = ? AND `action_type` = 23", [$t1]);
test('Live close: action_type 23 row logged', $act === 1);

// ── 2. Stranded repair (close predates the cascade) ───────────────
$t2 = mk_ticket(1);   // already closed, cascade never ran
$r2 = mk_responder('__STR_ Unit B', $stResponding);
$a2 = mk_assign($t2, $r2);
$res = incident_clear_stragglers($t2, 0, ['conservative' => true]);
test('Repair: no errors', empty($res['errors']));
test('Repair: 1 assign cleared', (int) $res['cleared_assigns'] === 1);
test('Repair: 1 responder reset', (int) $res['reset_responders'] === 1);
test('Repair: assign clear stamped', !assign_open($a2));
test('Repair: on-call responder now Available', resp_status($r2) === $availId);

// ── 7. Idempotency ─────────────────────────────────────────────────
$res = incident_clear_stragglers($t2, 0, ['conservative' => true]);
test('Idempotent: second run clears 0', (int) $res['cleared_assigns'] === 0);

// ── 3. Conservative mode preserves a deliberate status ─────────────
$t3 = mk_ticket(1);
$r3 = mk_responder('__STR_ Unit C', $stOOS);   // dispatcher set Out of Service since
$a3 = mk_assign($t3, $r3);
$res = incident_clear_stragglers($t3, 0, ['conservative' => true]);
test('Conservative: assign still cleared', (int) $res['cleared_assigns'] === 1 && !assign_open($a3));
test('Conservative: 0 responders reset', (int) $res['reset_responders'] === 0);
test('Conservative: Out of Service preserved', resp_status($r3) === $stOOS);

// ── 4. excl_from_reset honored in LIVE close ──────────────────────
$t4 = mk_ticket(2);
$r4 = mk_responder('__STR_ Unit D', $stGuarded);
$a4 = mk_assign($t4, $r4);
$res = incident_update_status_internal($t4, 1, 1);
test('excl_from_reset: assign cleared', (int) $res['cleared_assigns'] === 1 && !assign_open($a4));
test('excl_from_reset: responder NOT reset', resp_status($r4) === $stGuarded);

// ── 6. Other active assignment blocks the reset ───────────────────
$t5 = mk_ticket(2);
$t6 = mk_ticket(2);
$r5 = mk_responder('__STR_ Unit E', $stResponding);
$a5 = mk_assign($t5, $r5);
$a6 = mk_assign($t6, $r5);
$res = incident_update_status_internal($t5, 1, 1);
test('Other-assign: t5 assign cleared', !assign_open($a5));
test('Other-assign: t6 assign untouched', assign_open($a6));
test('Other-assign: responder NOT reset (still on t6)', resp_status($r5) === $stResponding);

// ── 8. Repair stamps the supplied close time ───────────────────────
$t7 = mk_ticket(1);
$r7 = mk_responder('__STR_ Unit F', $stResponding);
$a7 = mk_assign($t7, $r7);
$histClose = '2026-06-24 15:30:00';
$res = incident_clear_stragglers($t7, 0, ['conservative' => true, 'clear_time' => $histClose]);
$stamped = (string) db_fetch_value("SELECT `clear` FROM `{$prefix}assigns` WHERE `id` = ?", [$a7]);
test('clear_time honored on repair', $stamped === $histClose);

// ── Cleanup ─────────────────────────────────────────────────────────
foreach ($cleanup['ticket'] as $id) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$id]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$id]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$id]);
}
foreach ($cleanup['responder'] as $id) {
    db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$id]);
}
foreach ($cleanup['un_status'] as $id) {
    db_query("DELETE FROM `{$prefix}un_status` WHERE `id` = ?", [$id]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
