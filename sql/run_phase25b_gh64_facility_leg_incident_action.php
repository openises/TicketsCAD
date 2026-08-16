<?php
/**
 * GH#64 (Ron Jones, 2026-08-15) — facility-leg tracking closes the gap the
 * issue's own thread found in three parts:
 *
 *   1. assigns.u2fenr/u2farr ("unit to facility en route"/"arrived") have
 *      existed in the schema since the v3 carryover, are already SELECTed
 *      and returned by api/incident-detail.php and api/responder-detail.php,
 *      but nothing in v4 has ever written them -- confirmed by grepping the
 *      whole tree. Read side complete, write side never built.
 *   2. un_status.incident_action's ENUM only ever had dispatched/responding/
 *      on_scene/clear (Phase 25) -- there was no value an admin COULD map a
 *      "Facility En Route" or "Facility Arrived" status to, even though the
 *      columns to stamp already existed.
 *   3. The concrete bug this produced: an admin who reasonably pointed a
 *      "Facility Arrived" status at on_scene (the closest existing option)
 *      got nothing -- on_scene is write-once and was already stamped when
 *      the unit reached the ORIGINAL scene, so the second stamp silently
 *      no-opped. Status changed, action-log entry written, but the
 *      incident timeline gained nothing (fixed cosmetically with a help-
 *      text warning in v4.2.20; this migration fixes it for real by giving
 *      the facility leg its own two slots instead of overloading on_scene).
 *
 * This migration adds 'facility_enroute' and 'facility_arrived' to the
 * ENUM. MySQL/MariaDB have no "ADD ENUM VALUE" -- a MODIFY with the full,
 * extended value list is the only way, and only safe because it is a
 * pure APPEND: every existing stored value ('', 'dispatched', 'responding',
 * 'on_scene', 'clear') keeps its same ordinal position, so no existing row
 * changes meaning.
 *
 * Idempotent: checks the live ENUM definition via information_schema
 * before altering, so a second run is a clean skip.
 *
 * Filename: this depends on Phase 25's un_status.incident_action column
 * already existing (it MODIFYs that column's ENUM, it doesn't create it).
 * sql/run_migrations.php discovers scripts via glob() and sorts them
 * lexicographically -- run_gh64_* sorts BEFORE run_phase25_* ('g' < 'p'),
 * so on a genuinely fresh install (CI's fresh-install job, an empty DB)
 * this ran before Phase 25 ever created the column and failed its own
 * prerequisite check, halting the whole migration run. Named
 * run_phase25b_... instead so it sorts immediately after Phase 25 and the
 * dependency is explicit in the filename, matching this codebase's
 * existing sub-migration convention (run_phase11b_*, run_phase15b_*,
 * run_phase116b_*, ...).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "GH#64 — un_status.incident_action: facility_enroute / facility_arrived\n";
echo "========================================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'incident_action'",
        [$prefix . 'un_status']
    );
    if (!$col) {
        echo "[FAIL] un_status.incident_action does not exist -- run sql/run_phase25_un_status_incident_action.php first.\n";
        exit(1);
    }
    $hasNewValues = strpos((string) $col['COLUMN_TYPE'], "'facility_enroute'") !== false
        && strpos((string) $col['COLUMN_TYPE'], "'facility_arrived'") !== false;
    if ($hasNewValues) {
        echo "[OK] facility_enroute/facility_arrived already present\n";
    } else {
        db_query(
            "ALTER TABLE `{$prefix}un_status`
             MODIFY COLUMN `incident_action`
                 ENUM('','dispatched','responding','on_scene','facility_enroute','facility_arrived','clear')
                 NOT NULL DEFAULT ''
             COMMENT 'Phase 25 + GH#64: assigns timestamp this status maps to'"
        );
        echo "[OK] Extended un_status.incident_action with facility_enroute/facility_arrived\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nNo default seeding: unlike Phase 25's initial rollout, there is no\n";
echo "existing status name to pattern-match onto the facility leg -- an\n";
echo "admin who wants it creates or repoints a status via Settings > Unit\n";
echo "Statuses (the Incident Action field's own help text explains the two\n";
echo "new options).\n";

echo "\nDone.\n";
