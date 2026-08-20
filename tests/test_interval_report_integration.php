<?php
/**
 * GH#64 — interval-report DB-integration test.
 *
 * Root-cause-troubleshooting discipline (per this project's CLAUDE.md):
 * this project has been burned before by tests that hand-seed the assigns
 * table with "ideal" timestamps a real writer never actually produces (see
 * the GH#20 bed_auto pitfall — a hand-inserted assigns.rec_facility_id
 * passed tests for weeks while the real dispatch flow never wrote that
 * column at all). This test does NOT INSERT/UPDATE assigns.dispatched/
 * responding/on_scene/u2fenr/u2farr/clear directly to fabricate a fixture —
 * every one of those six columns is stamped by calling the REAL production
 * writers: inc/assignment-write.php's assign_create_internal() (dispatched)
 * and assign_update_status_internal() (every other milestone, exactly the
 * function incident-detail.php's own per-assignment dropdown calls — the
 * same path GH#64's reporter used for their live verification "run from
 * the incident page, not the unit page").
 *
 * The one place this test DOES write to those columns directly is to
 * BACKDATE a milestone AFTER the real writer has already stamped it, so the
 * six milestones land at deterministic, realistic offsets instead of all
 * clustering within the same wall-clock second a fast test loop produces.
 * This is the same "backdate to simulate elapsed time, don't sleep the
 * runner" technique this codebase's own Phase 143 test suite already
 * established as the correct pattern for testing time-elapsed behavior
 * without a real-time sleep. Each write-once/backstamp BRANCH the real
 * writer takes is asserted BEFORE its backdate (see Sections 2-4 below) —
 * that is the part proving the real writer's branching logic, not the
 * backdating.
 *
 * Two fixtures:
 *   1. The full six-milestone transport case (mirrors GH#64's own real
 *      example: I26-0068 / MEDIC20).
 *   2. The far more common case — a call that never transports, so
 *      u2fenr/u2farr are never stamped at all. Proves the "not every
 *      incident has all 6 milestones" requirement end-to-end, against a
 *      row the real writer actually produces (a fresh assigns row really
 *      does have u2fenr/u2farr NULL when a transport never happens — this
 *      isn't simulated, it's just... not calling those two writer paths).
 *
 * @requires-db
 * Usage: php tests/test_interval_report_integration.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/interval-report.php';
require_once __DIR__ . '/_test_admin.php';

$_SESSION = ['user_id' => test_admin_user_id(), 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';
$userId = test_admin_user_id();

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#64 — interval-report DB integration (real writers) ===\n\n";

/** Backdate one assigns column by $offsetSecs relative to a base time. */
function _gh64_backdate(string $prefix, int $assignId, string $col, string $baseIso, int $offsetSecs): string {
    $ts = date('Y-m-d H:i:s', strtotime($baseIso) + $offsetSecs);
    db_query("UPDATE `{$prefix}assigns` SET `{$col}` = ? WHERE `id` = ?", [$ts, $assignId]);
    return $ts;
}

$ridA = 0; $tidA = 0; $aidA = 0;
$ridB = 0; $tidB = 0; $aidB = 0;

try {
    echo "--- Fixture 1: full six-milestone transport ---\n\n";

    $typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh64_unit_A', 'GH64A', 'test', 1, NOW(), NOW())");
    $ridA = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh64_ticket_A', 'interval report fixture (transport)', NOW(), NOW(), ?)",
              [$typeId ?: null, $userId]);
    $tidA = (int) db_insert_id();

    // 1. Real writer: dispatched.
    $ra = assign_create_internal($tidA, $ridA, '', $userId);
    t('assignment created via real writer (assign_create_internal)', (int) ($ra['id'] ?? 0) > 0);
    $aidA = (int) ($ra['id'] ?? 0);

    $fresh = db_fetch_one("SELECT `dispatched`,`responding`,`on_scene`,`u2fenr`,`u2farr`,`clear` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidA]);
    t('dispatched stamped by the writer', !empty($fresh['dispatched']));
    t('responding/on_scene/u2fenr/u2farr/clear all still unset on a fresh assignment', empty($fresh['responding']) && empty($fresh['on_scene']) && empty($fresh['u2fenr']) && empty($fresh['u2farr']) && empty($fresh['clear']));

    $D = $fresh['dispatched'];
    // Backdate dispatched 30 minutes into the past so every subsequent
    // real-writer NOW() stamp naturally lands "later" in wall-clock terms
    // too, matching how a real call actually unfolds.
    $D = _gh64_backdate($prefix, $aidA, 'dispatched', $D, -1800);

    // 2. Real writer: responding (first call — assign.responding is NULL).
    assign_update_status_internal($aidA, 'responding', $userId);
    $afterResp = db_fetch_one("SELECT `responding` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidA]);
    t('responding stamped on first call (was NULL)', !empty($afterResp['responding']));
    $R = _gh64_backdate($prefix, $aidA, 'responding', $D, 90); // 1:30 turnout

    // 3. Real writer: on_scene. responding is ALREADY set — the writer's
    // own branch (inc/assignment-write.php ~line 540) must take the ELSE
    // path (UPDATE on_scene only) and leave responding untouched. This is
    // exactly the write-once semantics GH#64's own thread is about.
    assign_update_status_internal($aidA, 'on_scene', $userId);
    $afterScene = db_fetch_one("SELECT `responding`,`on_scene` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidA]);
    t('responding was NOT re-stamped by the on_scene call (write-once held)', $afterScene['responding'] === $R);
    t('on_scene was stamped', !empty($afterScene['on_scene']));
    $OS = _gh64_backdate($prefix, $aidA, 'on_scene', $R, 237); // 3:57 travel

    // 4. Real writer: facility_enroute (u2fenr is NULL — first call).
    assign_update_status_internal($aidA, 'facility_enroute', $userId);
    $afterEnr = db_fetch_one("SELECT `u2fenr` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidA]);
    t('u2fenr stamped on first call', !empty($afterEnr['u2fenr']));
    $FE = _gh64_backdate($prefix, $aidA, 'u2fenr', $OS, 900); // 15:00 scene time

    // 5. Real writer: facility_arrived. u2fenr is ALREADY set — must take
    // the ELSE path (UPDATE u2farr only), same write-once shape as step 3.
    assign_update_status_internal($aidA, 'facility_arrived', $userId);
    $afterArr = db_fetch_one("SELECT `u2fenr`,`u2farr` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidA]);
    t('u2fenr was NOT re-stamped by the facility_arrived call (write-once held)', $afterArr['u2fenr'] === $FE);
    t('u2farr was stamped', !empty($afterArr['u2farr']));
    $FA = _gh64_backdate($prefix, $aidA, 'u2farr', $FE, 180); // 3:00 transport

    // 6. Real writer: clear.
    assign_update_status_internal($aidA, 'clear', $userId);
    $afterClear = db_fetch_one("SELECT `clear` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidA]);
    t('clear stamped', !empty($afterClear['clear']));
    $CLR = _gh64_backdate($prefix, $aidA, 'clear', $FA, 300); // 5:00 at-facility before clear

    // Now read the row back exactly the way api/reports.php's
    // 'interval_report' case does, and feed it through the same pure
    // interval-math function.
    $row = db_fetch_one(
        "SELECT `dispatched`,`responding`,`on_scene`,`u2fenr`,`u2farr`,`clear` FROM `{$prefix}assigns` WHERE `id` = ?",
        [$aidA]
    );
    $legs = interval_report_compute($row);
    t('turnout = 90s (dispatched->responding)', $legs['turnout_secs'] === 90);
    t('travel = 237s (responding->on_scene)', $legs['travel_secs'] === 237);
    t('response = 327s (dispatched->on_scene)', $legs['response_secs'] === 327);
    t('scene = 900s (on_scene->u2fenr, preferring the facility leg over clear)', $legs['scene_secs'] === 900);
    t('transport = 180s (u2fenr->u2farr)', $legs['transport_secs'] === 180);
    t('total = 1707s (dispatched->clear)', $legs['total_secs'] === 1707);

    echo "\n--- Fixture 2: no-transport call (the common case — u2fenr/u2farr never stamped) ---\n\n";

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh64_unit_B', 'GH64B', 'test', 1, NOW(), NOW())");
    $ridB = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh64_ticket_B', 'interval report fixture (no transport)', NOW(), NOW(), ?)",
              [$typeId ?: null, $userId]);
    $tidB = (int) db_insert_id();

    $rb = assign_create_internal($tidB, $ridB, '', $userId);
    $aidB = (int) ($rb['id'] ?? 0);
    t('second assignment created via real writer', $aidB > 0);

    $D2 = db_fetch_value("SELECT `dispatched` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidB]);
    $D2 = _gh64_backdate($prefix, $aidB, 'dispatched', $D2, -600);

    assign_update_status_internal($aidB, 'responding', $userId);
    $R2 = db_fetch_value("SELECT `responding` FROM `{$prefix}assigns` WHERE `id` = ?", [$aidB]);
    $R2 = _gh64_backdate($prefix, $aidB, 'responding', $D2, 60);

    assign_update_status_internal($aidB, 'on_scene', $userId);
    $OS2 = _gh64_backdate($prefix, $aidB, 'on_scene', $R2, 300);

    // Skip facility_enroute/facility_arrived entirely — go straight to clear,
    // exactly the shape every non-transport call in production actually has.
    assign_update_status_internal($aidB, 'clear', $userId);
    $CLR2 = _gh64_backdate($prefix, $aidB, 'clear', $OS2, 420);

    $row2 = db_fetch_one(
        "SELECT `dispatched`,`responding`,`on_scene`,`u2fenr`,`u2farr`,`clear` FROM `{$prefix}assigns` WHERE `id` = ?",
        [$aidB]
    );
    t('u2fenr genuinely NULL on this row (never called)', $row2['u2fenr'] === null);
    t('u2farr genuinely NULL on this row (never called)', $row2['u2farr'] === null);

    $legs2 = interval_report_compute($row2);
    t('turnout computes (60s)', $legs2['turnout_secs'] === 60);
    t('travel computes (300s)', $legs2['travel_secs'] === 300);
    t('response computes (360s)', $legs2['response_secs'] === 360);
    t('scene falls back to clear (420s, on_scene->clear) since u2fenr was never stamped', $legs2['scene_secs'] === 420);
    t('transport is null — never errors on a row missing the facility leg entirely', $legs2['transport_secs'] === null);
    t('total computes (780s, dispatched->clear)', $legs2['total_secs'] === 780);

} catch (Throwable $e) {
    echo "  [FAIL] fixture threw: " . $e->getMessage() . "\n";
    $fail++;
}

// Teardown.
try {
    if ($tidA > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$tidA]);
    if ($tidA > 0) db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$tidA]);
    if ($tidA > 0) db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$tidA]);
    if ($ridA > 0) db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$ridA]);
    if ($tidB > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$tidB]);
    if ($tidB > 0) db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$tidB]);
    if ($tidB > 0) db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$tidB]);
    if ($ridB > 0) db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$ridB]);
    t('teardown complete', true);
} catch (Throwable $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
