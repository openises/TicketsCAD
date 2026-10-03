<?php
/**
 * GH#141 -- the "committed for later" data behind the unit chip
 * (unit_future_commitments(), and the `future` arrays api/responders.php and
 * api/responder-detail.php return).
 *
 * The chip has to be useful in BOTH modes of scheduled_assign_mode:
 *   reserve    -> a live reservation               (kind 'reserved')
 *   immediate  -> an open assignment on a Scheduled incident whose booked time is
 *                 still ahead (kind 'scheduled_dispatched'): today's behaviour,
 *                 but the chip at least says WHICH call and WHEN
 *
 * Proves: both kinds; ordering by booked time; DUE and HELD flags; closed and
 * soft-deleted incidents excluded; a past-booked Scheduled incident is not
 * "future"; units with nothing are absent; and it is ONE batched read -- the
 * SELECT count does not grow with the number of units (asserted from the
 * database's own Com_select counter, so an N+1 regression fails here).
 *
 * @requires-db
 * Usage: php tests/test_gh141_unit_future_commitments.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- unit future commitments ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh141-test-user';
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes']);
p155_set_setting('scheduled_assign_mode', 'immediate');
p155_set_setting('scheduled_assign_lead_minutes', '0');

$stAvail = p155_make_status('p155_fc_avail', 0);
$mkUnit = function (string $h) use ($prefix, $stAvail) {
    $rid = p155_make_unit($h);
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $rid]);
    return $rid;
};
$reserve = function (int $tid, int $rid) use ($adminId) {
    $r = p155_worker('assign', ["id=$tid", "unit=$rid", "user=$adminId", 'set:scheduled_assign_mode=reserve']);
    return (int) ($r['reservation_id'] ?? 0);
};

// ── Fixtures ─────────────────────────────────────────────────────────────
$uR = $mkUnit('FC-RES');          $tR = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 fc reserved');
$uD = $mkUnit('FC-DISP');         $tD = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'GH141 fc dispatched');
$uDue = $mkUnit('FC-DUE');        $tDue = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 fc due');
$uDel = $mkUnit('FC-DEL');        $tDel = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 fc deleted');
$uCl = $mkUnit('FC-CLOSED');      $tCl = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 fc closed');
$uPast = $mkUnit('FC-PAST');      $tPast = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 fc past-booked');
$uOpen = $mkUnit('FC-OPEN');      $tOpen = p155_make_ticket(2, null, 'GH141 fc open incident');
$uTwo = $mkUnit('FC-TWO');        $tTwoA = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 9 HOUR)', 'GH141 fc two (later)');
                                  $tTwoB = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)', 'GH141 fc two (sooner)');
$uNone = $mkUnit('FC-NONE');

$resR = $reserve($tR, $uR);
$a = p155_worker('assign', ["id=$tD", "unit=$uD", "user=$adminId", 'set:scheduled_assign_mode=immediate']);
$resDue = $reserve($tDue, $uDue);
db_query("UPDATE `{$prefix}ticket` SET booked_date = DATE_SUB(NOW(), INTERVAL 3 MINUTE) WHERE id = ?", [$tDue]);
$resDel = $reserve($tDel, $uDel);
db_query("UPDATE `{$prefix}ticket` SET deleted_at = NOW() WHERE id = ?", [$tDel]);
$resCl = $reserve($tCl, $uCl);
incident_update_status_internal($tCl, 1, $adminId, ['skip_disposition_check' => true, 'source' => 'ui']);
// A unit dispatched (immediate) to a Scheduled incident whose booked time has since passed is NOT "future".
p155_worker('assign', ["id=$tPast", "unit=$uPast", "user=$adminId", 'set:scheduled_assign_mode=immediate']);
db_query("UPDATE `{$prefix}ticket` SET booked_date = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [$tPast]);
// An assignment to an OPEN incident is live work, never "future".
assign_create_internal($tOpen, $uOpen, '', $adminId);
$reserve($tTwoA, $uTwo);
$reserve($tTwoB, $uTwo);
t('setup: all fixtures built', $resR > 0 && !empty($a['id']) && $resDue > 0 && $resDel > 0 && $resCl > 0);

// A HELD reservation: a busy unit at its booked time.
$uHeld = $mkUnit('FC-HELD');
$tLive = p155_make_ticket(2, null, 'GH141 fc live call');
$aLive = assign_create_internal($tLive, $uHeld, '', $adminId);
assign_update_status_internal((int) $aLive['id'], 'on_scene', $adminId);
$tHeld = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 fc held');
$resHeld = $reserve($tHeld, $uHeld);
db_query("UPDATE `{$prefix}ticket` SET booked_date = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?", [$tHeld]);
p155_worker('promote_one', ["res=$resHeld"]);

$all = [$uR, $uD, $uDue, $uDel, $uCl, $uPast, $uOpen, $uTwo, $uNone, $uHeld];
$f = unit_future_commitments($all);

// ── The kinds ────────────────────────────────────────────────────────────
echo "--- the two kinds ---\n";
t("reserve mode: a live reservation is kind 'reserved', not due, not held, with the incident and booked time",
    isset($f[$uR][0]) && $f[$uR][0]['kind'] === 'reserved' && $f[$uR][0]['ticket_id'] === $tR
    && $f[$uR][0]['due'] === false && $f[$uR][0]['held'] === false && !empty($f[$uR][0]['booked_date']), json_encode($f[$uR] ?? null));
t("immediate mode: a unit dispatched to a Scheduled incident booked ahead is kind 'scheduled_dispatched'",
    isset($f[$uD][0]) && $f[$uD][0]['kind'] === 'scheduled_dispatched' && $f[$uD][0]['ticket_id'] === $tD, json_encode($f[$uD] ?? null));
t("a reservation whose time has come is flagged DUE", isset($f[$uDue][0]) && $f[$uDue][0]['due'] === true && $f[$uDue][0]['held'] === false, json_encode($f[$uDue] ?? null));
t("a reservation held for a dispatcher is flagged HELD", isset($f[$uHeld][0]) && $f[$uHeld][0]['kind'] === 'reserved' && $f[$uHeld][0]['held'] === true, json_encode($f[$uHeld] ?? null));
t('every commitment carries a case number (or the #id fallback)', isset($f[$uR][0]['incident_number']) && $f[$uR][0]['incident_number'] !== '');

// ── What must NOT appear ─────────────────────────────────────────────────
echo "\n--- what must not appear ---\n";
t('a unit with nothing committed is absent', !array_key_exists($uNone, $f));
t('a reservation on a SOFT-DELETED incident is excluded', !array_key_exists($uDel, $f));
t('a reservation cancelled by its incident CLOSING is excluded', !array_key_exists($uCl, $f));
t("a dispatch to a Scheduled incident whose booked time has PASSED is not 'future'", !array_key_exists($uPast, $f));
t('an assignment to an OPEN incident is live work, not a future commitment', !array_key_exists($uOpen, $f));
t('the empty id list is a clean empty answer', unit_future_commitments([]) === [] && unit_future_commitments([0, -3]) === []);

// ── Ordering ─────────────────────────────────────────────────────────────
echo "\n--- ordering ---\n";
t('a unit with two commitments gets both, SOONEST booked time first',
    isset($f[$uTwo]) && count($f[$uTwo]) === 2 && $f[$uTwo][0]['ticket_id'] === $tTwoB && $f[$uTwo][1]['ticket_id'] === $tTwoA, json_encode($f[$uTwo] ?? null));

// ── One batched read ─────────────────────────────────────────────────────
echo "\n--- batched, not N+1 ---\n";
unit_future_commitments([$uR]);   // warm the per-process table-exists cache so it is not counted
$selects = function (array $ids): int {
    $before = (int) db_fetch_one("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
    unit_future_commitments($ids);
    $after = (int) db_fetch_one("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
    // minus the two SHOW statements' own bookkeeping: the counter counts SELECTs only
    return $after - $before;
};
$one = $selects([$uR]);
$many = $selects($all);
$bulk = array_merge($all, range(2147470000, 2147470030));   // 41 units, most do not exist
$lots = $selects($bulk);
t("the SELECT count is the same for 1 unit and for " . count($all) . " units ($one vs $many)", $one === $many);
t("and for " . count($bulk) . " units ($lots) -- it does not grow with the list", $lots === $one);
t('and it is small (two reads: reservations + scheduled dispatches)', $one <= 3, (string) $one);

// ── The APIs carry it ────────────────────────────────────────────────────
echo "\n--- the APIs ---\n";
$list = p155_probe('session', 'api/responders.php', $adminId, [], 'GET');
$row = null;
foreach (($list['json']['responders'] ?? []) as $r) { if ((int) $r['id'] === $uR) { $row = $r; break; } }
t('api/responders.php carries a `future` array on each unit', $row !== null && isset($row['future']) && is_array($row['future']), $list['raw']);
t('...and it holds the reservation for that unit', $row && count($row['future']) === 1 && $row['future'][0]['kind'] === 'reserved' && (int) $row['future'][0]['ticket_id'] === $tR);
$rowNone = null;
foreach (($list['json']['responders'] ?? []) as $r) { if ((int) $r['id'] === $uNone) { $rowNone = $r; break; } }
t('a unit with nothing committed has an EMPTY `future` array (the key is always present)', $rowNone !== null && $rowNone['future'] === []);
$det = p155_probe('session', 'api/responder-detail.php', $adminId, [], 'GET', 'id=' . $uTwo);
t('api/responder-detail.php carries the unit\'s `future` list too', $det['http'] === 200 && isset($det['json']['future']) && count($det['json']['future']) === 2, $det['raw']);

// ── The chip helper (node) ───────────────────────────────────────────────
echo "\n--- assets/js/future-chip.js (node) ---\n";
require_once __DIR__ . '/_test_node_probe.php';
$node = test_probe_cli(['node', 'node.exe']);
if ($node === null) {
    echo "SKIP: node is not available on this host -- the chip helper was not run\n";
} else {
    $driver = <<<'JS'
var fs = require('fs');
var window = {};
eval(fs.readFileSync(process.argv[2], 'utf8'));
var C = window.TCADFutureChip;
var out = {};
out.none = C.html([]) + '|' + C.html(undefined) + '|' + C.html(null);
out.reserved = C.html([{kind:'reserved', ticket_id:7, incident_number:'26-0071', booked_date:'2026-10-04 18:00:00', due:false, held:false}]);
out.due = C.html([{kind:'reserved', ticket_id:7, incident_number:'26-0071', booked_date:'2026-10-04 18:00:00', due:true, held:false}]);
out.held = C.html([{kind:'reserved', ticket_id:7, incident_number:'26-0071', booked_date:'2026-10-04 18:00:00', due:true, held:true}]);
out.sched = C.html([{kind:'scheduled_dispatched', ticket_id:7, incident_number:'26-0071', booked_date:'2026-10-04 18:00:00', due:false, held:false}]);
out.two = C.html([{kind:'reserved', ticket_id:7, incident_number:'A', booked_date:'2026-10-04 18:00:00'}, {kind:'reserved', ticket_id:8, incident_number:'B', booked_date:'2026-10-05 09:00:00'}]);
out.xss = C.html([{kind:'reserved', ticket_id:7, incident_number:'<img src=x onerror=alert(1)>', booked_date:'2026-10-04 18:00:00'}]);
console.log(JSON.stringify(out));
JS;
    $f2 = tempnam(sys_get_temp_dir(), 'p155chip') . '.js';
    file_put_contents($f2, $driver);
    $outRaw = test_run_cli([$node, $f2, __DIR__ . '/../assets/js/future-chip.js']);
    @unlink($f2);
    $lines = preg_split('/\r?\n/', trim((string) $outRaw));
    $o = json_decode((string) end($lines), true);
    t('the chip helper ran under node', is_array($o), (string) $outRaw);
    if (is_array($o)) {
        t('nothing committed -> no markup at all', $o['none'] === '||');
        t('reserved -> an info badge with the case number and the booked day/time',
            strpos($o['reserved'], 'text-bg-info') !== false && strpos($o['reserved'], '26-0071 10-04 18:00') !== false);
        t('DUE -> a warning badge reading DUE', strpos($o['due'], 'text-bg-warning') !== false && strpos($o['due'], 'DUE 26-0071') !== false);
        t('HELD -> a danger badge reading HELD', strpos($o['held'], 'text-bg-danger') !== false && strpos($o['held'], 'HELD 26-0071') !== false);
        t('a scheduled dispatch says so in its hover text', strpos($o['sched'], 'Already dispatched to Scheduled incident') !== false);
        t('it links to the incident', strpos($o['reserved'], 'href="incident-detail.php?id=7"') !== false);
        t('it is accessible: title AND aria-label carry the full sentence', strpos($o['reserved'], 'aria-label="Reserved for incident 26-0071') !== false && strpos($o['reserved'], 'title="Reserved for incident 26-0071') !== false);
        t('two commitments: the first is shown with "+1" and both appear in the hover text',
            strpos($o['two'], 'A 10-04 18:00 +1') !== false && strpos($o['two'], 'incident B') !== false);
        t('markup is escaped (a hostile case number cannot inject HTML)', strpos($o['xss'], '<img') === false && strpos($o['xss'], '&lt;img') !== false);
    }
}

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
