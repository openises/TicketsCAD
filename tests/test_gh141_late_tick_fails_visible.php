<?php
/**
 * GH#141 -- a reservation whose time has come, but which nothing has promoted,
 * FAILS VISIBLE rather than silently wrong.
 *
 * The Phase 143 lesson, proven rather than argued: "is this reservation due" is
 * never a stored flag a background job flips -- it is a SQL predicate evaluated
 * against the database clock on every read. So if the 60-second timer is late,
 * dead, or was never installed, a dispatcher sees "DUE -- not yet dispatched",
 * the unit is NOT falsely marked Dispatched (the old behaviour was a permanent,
 * silent "Dispatched" lie from the moment of assignment), and the Status page
 * says the job is required.
 *
 * Deliberately NEVER invokes the tick, the lazy hook, or any promoter. Time
 * "passes" by backdating booked_date.
 *
 * @requires-db
 * Usage: php tests/test_gh141_late_tick_fails_visible.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- a late tick fails visible ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes']);
p155_set_setting('scheduled_assign_mode', 'reserve');

$stAvail = p155_make_status('p155_lt_avail', 0);
$unit = p155_make_unit('LATE-A');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $unit]);
$tid = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)', 'GH141 late tick');
$r = p155_worker('assign', ["id=$tid", "unit=$unit", "user=$adminId", 'set:scheduled_assign_mode=reserve']);
$resId = (int) ($r['reservation_id'] ?? 0);
t('setup: the unit is reserved for an incident 2 hours out', $resId > 0);

// ── Before the time comes ────────────────────────────────────────────────
$f = unit_future_commitments([$unit]);
t('before the booked time: the chip data says reserved, NOT due',
    isset($f[$unit][0]) && $f[$unit][0]['kind'] === 'reserved' && $f[$unit][0]['due'] === false, json_encode($f));
$detail = p155_probe('session', 'api/incident-detail.php', $adminId, [], 'GET', 'id=' . $tid);
$res0 = $detail['json']['reservations'][0] ?? null;
t('the incident page API lists it, live and not due', $detail['http'] === 200 && $res0 && $res0['live'] === true && $res0['due'] === false && $res0['state'] === 'pending', $detail['raw']);

// ── Time passes. NOTHING promotes. ───────────────────────────────────────
db_query("UPDATE `{$prefix}ticket` SET booked_date = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?", [$tid]);

$f = unit_future_commitments([$unit]);
t('after the booked time, with NO promoter ever having run: the chip data reports the reservation as DUE',
    isset($f[$unit][0]) && $f[$unit][0]['kind'] === 'reserved' && $f[$unit][0]['due'] === true, json_encode($f));
$detail = p155_probe('session', 'api/incident-detail.php', $adminId, [], 'GET', 'id=' . $tid);
$res1 = $detail['json']['reservations'][0] ?? null;
t('the incident page API says DUE too -- derived by the database clock on this very read, not a flag a job flipped',
    $res1 && $res1['due'] === true && $res1['state'] === 'pending' && $res1['live'] === true, $detail['raw']);
t("the reservation's STATE is still 'pending' (state records outcomes of actions, never 'time has passed')",
    db_fetch_value("SELECT state FROM `{$prefix}assign_reservations` WHERE id = ?", [$resId]) === 'pending');
t('the unit is NOT falsely marked Dispatched', (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$unit]) === $stAvail);
t('and no assigns row was invented', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]) === 0);

$req = sched_job_required('scheduled_incidents_tick');
t('the Status page rule says the job is REQUIRED (something is waiting on it)', $req['required'] === true, json_encode($req));
$why = (string) $req['why'];
t('and says why', strpos($why, 'reservation') !== false || strpos($why, 'Scheduled incident') !== false, $why);

// ── A reservation pending on a far-future incident makes the job required too ──
// (a long lead time dispatches the unit early, so the timer matters long before
// the incident itself is within 24 hours).
$unit2 = p155_make_unit('LATE-B');
$tFar = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 60 DAY)', 'GH141 far future');
$r2 = p155_worker('assign', ["id=$tFar", "unit=$unit2", "user=$adminId", 'set:scheduled_assign_mode=reserve']);
t('setup: a unit reserved for an incident 60 days out', !empty($r2['reserved']));
db_query("UPDATE `{$prefix}assign_reservations` SET state = 'cancelled', active_key = NULL WHERE ticket_id = ?", [$tid]);
// Move the FIRST incident out of the 24-hour window too, so the only thing left
// that could make the timer required is the pending reservation itself.
db_query("UPDATE `{$prefix}ticket` SET booked_date = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?", [$tid]);
$reqFar = sched_job_required('scheduled_incidents_tick');
t('a pending reservation alone (incident 60 days out) makes the timer required', $reqFar['required'] === true && strpos((string) $reqFar['why'], 'reservation') !== false, json_encode($reqFar));

p155_set_setting('scheduled_assign_mode', 'immediate');
p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
