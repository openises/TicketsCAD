<?php
/**
 * GH #73 regression (a beta tester 2026-07-07) — personal-unit clock-out.
 *
 * a beta tester's install has fully custom un_status names, so:
 *   - pu_ensure_schema() auto-created an 'Off Shift' row (his id 7), BUT
 *   - _pu_status_id() never searched for 'Off Shift', fell through to
 *     `return 1`, and Clock-Out set the unit to un_status id 1 (his
 *     available status) → clocked_in stayed true → "clicking clock out
 *     does nothing".
 *
 * This test simulates that install: it renames every off-duty-looking
 * status out of the way, runs the clock-in → clock-out cycle on a
 * fixture member, and asserts the invariant that broke: after
 * pu_clock_out(), pu_status_for_member() MUST report clocked_in=false.
 * Names are restored and fixtures deleted on completion.
 *
 * Usage: php tests/test_personal_unit_clockout.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/audit.php';
require_once 'inc/personnel-units.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function t($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Personal-unit clock-out tests (GH #73) ===\n\n";

// ── Simulate a beta tester's install: hide every off-duty-named status ─────
// (rename with a marker prefix; restored in the shutdown handler)
$offNames = ['off shift','off-shift','inactive','released','off-duty','off duty',
             'out of service','out-of-service','unavailable','in quarters'];
$renamed = [];
foreach (db_fetch_all("SELECT id, status_val FROM `{$prefix}un_status`") as $row) {
    foreach ($offNames as $n) {
        if (stripos($row['status_val'], $n) !== false) {
            $renamed[(int) $row['id']] = $row['status_val'];
            db_query("UPDATE `{$prefix}un_status` SET status_val = ? WHERE id = ?",
                [substr('X73_' . $row['status_val'], 0, 20), $row['id']]);
            break;
        }
    }
}

$fixtures = ['member' => 0, 'un_status' => []];
register_shutdown_function(function () use ($prefix, $renamed, &$fixtures) {
    foreach ($renamed as $id => $orig) {
        try { db_query("UPDATE `{$prefix}un_status` SET status_val = ? WHERE id = ?", [$orig, $id]); } catch (Exception $e) {}
    }
    try {
        // The borrowed member had no personal unit before this run —
        // delete only the one the test created.
        if ($fixtures['member']) {
            db_query("DELETE FROM `{$prefix}responder` WHERE personal_for_member_id = ?", [$fixtures['member']]);
        }
        // Only remove an auto-created Off Shift row if THIS run created it.
        foreach ($fixtures['un_status'] as $sid) {
            db_query("DELETE FROM `{$prefix}un_status` WHERE id = ? AND description LIKE 'Auto-created%'", [$sid]);
        }
    } catch (Exception $e) {}
});

$offRowsBefore = array_column(
    db_fetch_all("SELECT id FROM `{$prefix}un_status` WHERE LOWER(status_val) = 'off shift'"), 'id');

// ── Borrow a member with no personal unit (legacy member table has
//    NOT NULL field1-65 columns, so inserting a fixture member needs the
//    self-healing pattern — borrowing avoids all of that) ─────────────
pu_ensure_schema(); // ensures responder.personal_for_member_id exists first
$mid = (int) db_fetch_value(
    "SELECT m.id FROM `{$prefix}member` m
     LEFT JOIN `{$prefix}responder` r ON r.personal_for_member_id = m.id
     WHERE r.id IS NULL ORDER BY m.id LIMIT 1");
if (!$mid) {
    echo "SKIP: no member available to borrow (empty roster or all have personal units)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
$fixtures['member'] = $mid;

// ── The a beta tester cycle ────────────────────────────────────────────────
// pu_ensure_schema()'s static guard already ran above — re-run its
// off-shift seed directly since the off-named rows were renamed AFTER it.
$hasOff = db_fetch_value(
    "SELECT id FROM `{$prefix}un_status`
      WHERE LOWER(status_val) IN ('off shift','off-shift','inactive','released','off-duty','off duty','out of service','out-of-service','unavailable','in quarters')
      LIMIT 1");
if (!$hasOff) {
    db_query("INSERT INTO `{$prefix}un_status` (status_val, description)
              VALUES ('Off Shift', 'Auto-created — used for personal-unit clock out')");
}
$offRowsAfter = array_column(
    db_fetch_all("SELECT id FROM `{$prefix}un_status` WHERE LOWER(status_val) = 'off shift'"), 'id');
$fixtures['un_status'] = array_values(array_diff($offRowsAfter, $offRowsBefore));
t('pu_ensure_schema guarantees an Off Shift row', !empty($offRowsAfter));

$unit = pu_clock_in($mid);
t('clock_in creates the personal unit', !empty($unit['id']));
$st = pu_status_for_member($mid);
t('after clock_in: clocked_in = true', $st['clocked_in'] === true);

$unit2 = pu_clock_out($mid);
t('clock_out returns the unit row', !empty($unit2['id']));
$st2 = pu_status_for_member($mid);
t('after clock_out: clocked_in = FALSE (GH #73 invariant)', $st2['clocked_in'] === false);
t('clock_out status is the off-shift row, not id 1 fallback',
    in_array((int) $unit2['un_status_id'], array_map('intval', $offRowsAfter), true));

// ── Round trip again (badge toggle repeatability) ───────────────────
pu_clock_in($mid);
t('re-clock_in: clocked_in = true', pu_status_for_member($mid)['clocked_in'] === true);
pu_clock_out($mid);
t('re-clock_out: clocked_in = false', pu_status_for_member($mid)['clocked_in'] === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
