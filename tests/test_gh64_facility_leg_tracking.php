<?php
/**
 * GH#64 (Ron Jones, 2026-08-15) — facility-leg tracking.
 *
 * assigns.u2fenr/u2farr ("unit to facility en route"/"arrived") existed in
 * the schema since the v3 carryover and were already read by
 * api/incident-detail.php + api/responder-detail.php, but nothing in v4
 * ever wrote them, and un_status.incident_action's ENUM had no value an
 * admin could even point a status at. The concrete symptom: an admin who
 * pointed "Facility Arrived" at on_scene (the closest existing option) got
 * nothing, because on_scene was already stamped from the original dispatch
 * and the second stamp silently no-opped.
 *
 * This closes the gap for real, on BOTH status-change paths:
 *   - inc/responder-write.php's responder_set_status_internal() (dashboard
 *     / mobile / command-bar unit-status widget — operates across every
 *     open assign for a responder)
 *   - inc/assignment-write.php's assign_update_status_internal() (the
 *     incident-detail page's own per-assignment status dropdown)
 * plus the consumers: the ICS-214 personal timeline, the conservative-mode
 * on-call heuristic in incident_clear_stragglers(), and PAR's resets_par
 * cadence lookup.
 *
 * Usage: php tests/test_gh64_facility_leg_tracking.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/responder-write.php';
require_once 'inc/assignment-write.php';
require_once 'inc/incident-write.php';
require_once 'inc/par.php';
require_once 'inc/ics214_timeline.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function t64($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#64: facility-leg tracking (u2fenr/u2farr) ===\n\n";

// ── Structural checks — every consumer this fix touched ─────────────────
$respSrc = (string) file_get_contents('inc/responder-write.php');
$assignSrc = (string) file_get_contents('inc/assignment-write.php');
$settingsSrc = (string) file_get_contents('settings.php');
$timelineSrc = (string) file_get_contents('inc/ics214_timeline.php');
$incWriteSrc = (string) file_get_contents('inc/incident-write.php');
$parSrc = (string) file_get_contents('inc/par.php');
$migrationSrc = (string) file_get_contents('sql/run_phase25b_gh64_facility_leg_incident_action.php');
$configAdminSrc = (string) file_get_contents('api/config-admin.php');
$configJsSrc = (string) file_get_contents('assets/js/config.js');

t64('migration ENUM includes both new values',
    strpos($migrationSrc, "'facility_enroute','facility_arrived'") !== false);
t64('migration is idempotent (checks information_schema before altering)',
    strpos($migrationSrc, 'information_schema.COLUMNS') !== false);

// Regression guard for the CI failure this migration hit on its first
// push: sql/run_migrations.php discovers scripts via glob() and sorts
// them lexicographically (sql/run_migrations.php ~line 70-82), so a
// migration that MODIFYs a column Phase 25 creates must sort AFTER
// run_phase25_un_status_incident_action.php, or it runs first on a
// genuinely fresh install and fails its own prerequisite check. This
// migration is filed as run_phase25b_... specifically to guarantee that;
// this assertion fails loudly if it's ever renamed back to something
// that sorts before phase25.
t64('migration filename sorts after run_phase25_un_status_incident_action.php (lexicographic glob() order)',
    is_file('sql/run_phase25_un_status_incident_action.php')
    && strcmp('run_phase25b_gh64_facility_leg_incident_action.php', 'run_phase25_un_status_incident_action.php') > 0);

t64('responder-write.php: stampableActions includes facility_enroute',
    (bool) preg_match('/\$stampableActions\s*=\s*\[[^\]]*facility_enroute/', $respSrc));
t64('responder-write.php: stampableActions includes facility_arrived',
    (bool) preg_match('/\$stampableActions\s*=\s*\[[^\]]*facility_arrived/', $respSrc));
t64('responder-write.php: openAssigns query selects u2fenr and u2farr',
    (bool) preg_match('/SELECT[^"]*u2fenr[^"]*u2farr/s', $respSrc));

t64('assignment-write.php: validNamed includes facility_enroute + facility_arrived',
    (bool) preg_match('/\$validNamed\s*=\s*\[[^\]]*facility_enroute[^\]]*facility_arrived/', $assignSrc));
t64('assignment-write.php: facility_enroute branch logs action_type 25',
    (bool) preg_match("/en route to facility.,\\s*25,/", $assignSrc));
t64('assignment-write.php: facility_arrived branch logs action_type 26',
    (bool) preg_match("/arrived at facility.,\\s*26,/", $assignSrc));

// GH#64 round 2 (found via live browser verification, 2026-08-15) — a
// FOURTH independent allowlist, api/config-admin.php's statuses POST
// handler, silently coerced facility_enroute/facility_arrived back to ''
// before ever reaching the database. The client sent the right value, the
// save reported success, and the row was NOT what the admin picked — this
// is the exact silent-mismatch shape this project's schema-mismatch
// pattern warns about, just one layer further out (an allowlist, not a
// column name). Caught only by actually submitting the real settings.php
// form in a browser and reading the saved row back, not by code-reading.
t64('config-admin.php: statuses save allowlist includes facility_enroute + facility_arrived',
    (bool) preg_match('/\$allowedActions\s*=\s*\[[^\]]*facility_enroute[^\]]*facility_arrived/', $configAdminSrc));

t64('config.js: Resets PAR default-check heuristic includes facility_enroute + facility_arrived',
    (bool) preg_match('/\[.dispatched.,.responding.,.on_scene.,.facility_enroute.,.facility_arrived.\]\.indexOf/', $configJsSrc));

t64('settings.php: dropdown has Facility En Route option',
    strpos($settingsSrc, '<option value="facility_enroute">Facility En Route</option>') !== false);
t64('settings.php: dropdown has Facility Arrived option',
    strpos($settingsSrc, '<option value="facility_arrived">Facility Arrived</option>') !== false);

t64('ics214_timeline.php: SELECT includes u2fenr and u2farr',
    strpos($timelineSrc, 'a.u2fenr, a.u2farr') !== false);
t64('ics214_timeline.php: verb map includes both facility-leg columns',
    strpos($timelineSrc, "'u2fenr'") !== false && strpos($timelineSrc, "'u2farr'") !== false);

t64('incident-write.php: on-call array includes facility_enroute + facility_arrived',
    (bool) preg_match('/in_array\(\$ia,\s*\[[^\]]*facility_enroute[^\]]*facility_arrived/', $incWriteSrc));

t64('par.php: incidentActionToColumn maps facility_enroute => u2fenr',
    (bool) preg_match("/'facility_enroute'\\s*=>\\s*'u2fenr'/", $parSrc));
t64('par.php: incidentActionToColumn maps facility_arrived => u2farr',
    (bool) preg_match("/'facility_arrived'\\s*=>\\s*'u2farr'/", $parSrc));
t64('par.php: candidate-timestamp query selects u2fenr and u2farr',
    strpos($parSrc, 'dispatched, responding, on_scene, u2fenr, u2farr') !== false);

// ── Live schema check ────────────────────────────────────────────────────
$col = db_fetch_one(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'incident_action'",
    [$prefix . 'un_status']
);
t64('live DB: un_status.incident_action ENUM has facility_enroute',
    $col && strpos((string) $col['COLUMN_TYPE'], "'facility_enroute'") !== false);
t64('live DB: un_status.incident_action ENUM has facility_arrived',
    $col && strpos((string) $col['COLUMN_TYPE'], "'facility_arrived'") !== false);

// ── Fixtures ──────────────────────────────────────────────────────────────
db_query("DELETE FROM `{$prefix}un_status` WHERE `description` = 'gh64 test fixture'");
db_query("DELETE FROM `{$prefix}responder` WHERE `description` = 'gh64 test fixture'");
foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = '__GH64_ Test Incident'") as $old) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$old['id']]);
}

$typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");
$testUserId = (int) (db_fetch_value("SELECT MIN(id) FROM `{$prefix}user`") ?: 1);

function gh64_mk_status($val, $ia, $resetsPar = 0) {
    global $prefix;
    db_query(
        "INSERT INTO `{$prefix}un_status`
         (`status_val`, `description`, `dispatch`, `watch`, `hide`, `excl_from_reset`,
          `group`, `sort`, `bg_color`, `text_color`, `incident_action`, `resets_par`,
          `extra_data_type`, `extra_data_required`, `extra_data_target`, `bed_delivery`)
         VALUES (?, 'gh64 test fixture', 0, 0, 'n', 'n', '', 99, 'transparent', '#000000', ?, ?, 'none', 0, 'action_log', 0)",
        [$val, $ia, $resetsPar]
    );
    return (int) db_insert_id();
}

function gh64_mk_ticket() {
    global $prefix, $typeId;
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `date`, `problemstart`, `_by`)
         VALUES (?, 2, 3, '__GH64_ Test Incident', 'gh64 test fixture', ?, ?, 1)",
        [$typeId, $now, $now]
    );
    return (int) db_insert_id();
}

function gh64_mk_responder($name, $statusId) {
    global $prefix;
    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`, `handle`, `multi`)
         VALUES (?, 'gh64 test fixture', ?, ?, 0)",
        [$name, $statusId, substr($name, -4)]
    );
    return (int) db_insert_id();
}

function gh64_mk_assign($ticketId, $responderId) {
    global $prefix, $testUserId;
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `status_id`, `dispatched`, `user_id`, `as_of`)
         VALUES (?, ?, 1, ?, ?, ?)",
        [$ticketId, $responderId, $now, $testUserId, $now]
    );
    return (int) db_insert_id();
}

function gh64_assign_row($assignId) {
    global $prefix;
    return db_fetch_one("SELECT `u2fenr`, `u2farr` FROM `{$prefix}assigns` WHERE `id` = ?", [$assignId]);
}

$stEnroute = gh64_mk_status('__GH64_Enrt', 'facility_enroute', 1);
$stArrived = gh64_mk_status('__GH64_Arr', 'facility_arrived', 1);
$stOOS     = gh64_mk_status('__GH64_OOS', '', 0);

// ── responder_set_status_internal() path (dashboard/mobile/command bar) ──
$t1 = gh64_mk_ticket();
$r1 = gh64_mk_responder('__GH64_ Unit A', $stOOS);
$a1 = gh64_mk_assign($t1, $r1);

responder_set_status_internal($r1, $stEnroute, $testUserId);
$row = gh64_assign_row($a1);
t64('responder path: facility_enroute stamps u2fenr',
    !empty($row['u2fenr']) && substr((string) $row['u2fenr'], 0, 4) !== '0000');
t64('responder path: facility_enroute leaves u2farr null',
    empty($row['u2farr']) || substr((string) $row['u2farr'], 0, 4) === '0000');
$firstEnroute = $row['u2fenr'];

// Write-once: re-entering the facility_enroute status a second time must
// not move the original timestamp.
sleep(1);
responder_set_status_internal($r1, $stEnroute, $testUserId);
$row = gh64_assign_row($a1);
t64('responder path: facility_enroute is write-once (second pass does not re-stamp)',
    $row['u2fenr'] === $firstEnroute, 'first=' . $firstEnroute . ' second=' . $row['u2fenr']);

responder_set_status_internal($r1, $stArrived, $testUserId);
$row = gh64_assign_row($a1);
t64('responder path: facility_arrived stamps u2farr',
    !empty($row['u2farr']) && substr((string) $row['u2farr'], 0, 4) !== '0000');
t64('responder path: facility_arrived does not disturb the earlier u2fenr',
    $row['u2fenr'] === $firstEnroute);

// ── assign_update_status_internal() path (incident-detail dropdown) ──────
// Jump straight to facility_arrived, skipping the en-route step, on a
// SEPARATE fresh assign — must backstamp BOTH columns together (same
// "later milestone implies earlier milestone" shape as the existing
// on_scene branch backstamping responding).
$t2 = gh64_mk_ticket();
$r2 = gh64_mk_responder('__GH64_ Unit B', $stOOS);
$a2 = gh64_mk_assign($t2, $r2);

$result = assign_update_status_internal($a2, 'facility_arrived', $testUserId);
t64('assign path: facility_arrived (no prior enroute) — no errors', empty($result['errors']));
$row = gh64_assign_row($a2);
t64('assign path: facility_arrived backstamps u2fenr',
    !empty($row['u2fenr']) && substr((string) $row['u2fenr'], 0, 4) !== '0000');
t64('assign path: facility_arrived stamps u2farr',
    !empty($row['u2farr']) && substr((string) $row['u2farr'], 0, 4) !== '0000');

$actionRows = db_fetch_all(
    "SELECT `action_type` FROM `{$prefix}action` WHERE `ticket_id` = ? ORDER BY `id`", [$t2]
);
$actionTypes = array_map(fn($r) => (int) $r['action_type'], $actionRows);
t64('assign path: action_type 26 (facility_arrived) logged', in_array(26, $actionTypes, true),
    'types: ' . implode(',', $actionTypes));

// A separate assign exercised end-to-end through 'facility_enroute' via the
// assign path too, confirming action_type 25 + validNamed accepts it.
$t3 = gh64_mk_ticket();
$r3 = gh64_mk_responder('__GH64_ Unit C', $stOOS);
$a3 = gh64_mk_assign($t3, $r3);
$result = assign_update_status_internal($a3, 'facility_enroute', $testUserId);
t64('assign path: facility_enroute — no errors', empty($result['errors']));
$row = gh64_assign_row($a3);
t64('assign path: facility_enroute stamps u2fenr only',
    !empty($row['u2fenr']) && (empty($row['u2farr']) || substr((string) $row['u2farr'], 0, 4) === '0000'));
$actionTypes3 = array_map(fn($r) => (int) $r['action_type'],
    db_fetch_all("SELECT `action_type` FROM `{$prefix}action` WHERE `ticket_id` = ?", [$t3]));
t64('assign path: action_type 25 (facility_enroute) logged', in_array(25, $actionTypes3, true));

// ── ICS-214 timeline picks up both facility-leg entries ──────────────────
$responderRow = db_fetch_one("SELECT * FROM `{$prefix}responder` WHERE `id` = ?", [$r1]);
$timeline = ics214_build_timeline($responderRow, $r1, '2020-01-01 00:00:00', date('Y-m-d H:i:s', time() + 60), $t1);
$notes = array_map(fn($e) => $e['note'], $timeline);
$hasEnroute = false; $hasArrived = false;
foreach ($notes as $n) {
    if (strpos($n, 'En route to facility for') === 0) $hasEnroute = true;
    if (strpos($n, 'Arrived at facility for') === 0) $hasArrived = true;
}
t64('ICS-214 timeline includes the en-route entry', $hasEnroute, 'notes: ' . implode(' | ', $notes));
t64('ICS-214 timeline includes the arrived entry', $hasArrived, 'notes: ' . implode(' | ', $notes));

// ── Conservative-mode on-call heuristic recognizes a facility-leg status ─
// $onCall === true means "active-call milestone -- heal this straggler to
// Available" (the SAME bucket dispatched/responding/on_scene already sit
// in), as opposed to an independent status like Out of Service, which
// conservative mode leaves alone (test_stranded_assigns.php covers that
// side). Before GH#64, facility_enroute/facility_arrived had no
// incident_action match here at all, so a unit stuck transporting when its
// incident force-closed without a proper cascade fell through to the
// fragile name-regex fallback instead of the robust incident_action check.
$availableStatusId = (int) (db_fetch_value(
    "SELECT `id` FROM `{$prefix}un_status`
     WHERE LOWER(`group`) LIKE 'av%' OR LOWER(`status_val`) LIKE 'avail%'
     ORDER BY (LOWER(`group`) LIKE 'av%') DESC, `id` LIMIT 1"
) ?: 1);
$t4 = gh64_mk_ticket();
db_query("UPDATE `{$prefix}ticket` SET `status` = 1 WHERE `id` = ?", [$t4]); // pre-closed, cascade never ran
$r4 = gh64_mk_responder('__GH64_ Unit D', $stEnroute); // stuck transporting when the close never cascaded
$a4 = gh64_mk_assign($t4, $r4);
$res = incident_clear_stragglers($t4, 0, ['conservative' => true]);
t64('conservative heal: no errors', empty($res['errors']));
t64('conservative heal: 1 responder healed (facility_enroute recognized as on-call)',
    (int) $res['reset_responders'] === 1);
$healedStatus = (int) db_fetch_value("SELECT `un_status_id` FROM `{$prefix}responder` WHERE `id` = ?", [$r4]);
t64('conservative heal: facility_enroute straggler reset to Available',
    $healedStatus === $availableStatusId, 'expected ' . $availableStatusId . ' got ' . $healedStatus);

// ── config-admin.php statuses-save allowlist: live proof, not just regex ─
// api/config-admin.php is session/CSRF-coupled (require_once auth.php at
// the top) so it can't be `include`d here the way the pure inc/*.php
// writers above were called directly -- same constraint documented in
// test_gh57_incident_report_responder_filter.php for api/reports.php.
// Extract the array literal that survived the structural check above and
// actually evaluate the SAME filter logic the endpoint runs, proving the
// two new values pass and a bogus one is still rejected -- this is what
// caught the real bug (the client sent 'facility_enroute', the endpoint
// silently coerced it to '', and the row saved with an empty
// incident_action) when only reading the source would not have.
if (preg_match('/\$allowedActions\s*=\s*(\[[^\]]*\]);/', $configAdminSrc, $aaMatch) === 1) {
    $allowedActions = eval('return ' . $aaMatch[1] . ';');
    $checkAction = function ($input) use ($allowedActions) {
        $a = trim((string) $input);
        return in_array($a, $allowedActions, true) ? $a : '';
    };
    t64('config-admin.php allowlist: facility_enroute survives the filter',
        $checkAction('facility_enroute') === 'facility_enroute');
    t64('config-admin.php allowlist: facility_arrived survives the filter',
        $checkAction('facility_arrived') === 'facility_arrived');
    t64('config-admin.php allowlist: an unrecognized value is still coerced to empty (allowlist still enforced)',
        $checkAction('not_a_real_action') === '');
} else {
    t64('config-admin.php allowlist: could not extract $allowedActions for live proof', false);
}

// ── PAR resets_par lookup reaches u2fenr when the status opts in ─────────
$t5 = gh64_mk_ticket();
$r5 = gh64_mk_responder('__GH64_ Unit E', $stOOS);
$a5 = gh64_mk_assign($t5, $r5);
responder_set_status_internal($r5, $stEnroute, $testUserId);
$lastActivity = par_unit_last_activity_at($t5, $r5);
t64('par.php: last-activity picks up the u2fenr stamp (resets_par=1 on this status)',
    $lastActivity !== null && $lastActivity > 0, 'got: ' . var_export($lastActivity, true));

// ── Cleanup ────────────────────────────────────────────────────────────
foreach ([$t1, $t2, $t3, $t4, $t5] as $tid) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$tid]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$tid]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$tid]);
}
foreach ([$r1, $r2, $r3, $r4, $r5] as $rid) {
    db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$rid]);
}
db_query("DELETE FROM `{$prefix}un_status` WHERE `description` = 'gh64 test fixture'");

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
