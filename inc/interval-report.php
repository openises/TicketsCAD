<?php
/**
 * GH#64 (Ron Jones) — interval reporting over the assigns milestones.
 *
 * Pure, DB-free interval-math helpers shared by api/reports.php's
 * 'interval_report' case and its test suite (tests/test_interval_report_math.php
 * drives these directly with synthetic edge-case inputs; the DB-integration
 * test drives them against rows populated by the REAL writers —
 * inc/assignment-write.php's assign_create_internal()/assign_update_status_internal()
 * and inc/responder-write.php's responder_set_status_internal() — since those
 * are what actually stamp `assigns`.dispatched/responding/on_scene/u2fenr/
 * u2farr/clear in production).
 *
 * Six milestones, per docs/SCHEMA-REFERENCE.md's `assigns` table:
 *   dispatched, responding, on_scene, u2fenr (facility en route),
 *   u2farr (facility arrived), clear — all nullable datetime, write-once
 *   with backstamp-the-earlier-milestone semantics (see the responder-write.php
 *   / assignment-write.php docblocks). Most incidents never populate
 *   u2fenr/u2farr at all (no transport leg) — every function here treats a
 *   missing milestone as "no data for that leg," never an error, per GH#64's
 *   explicit requirement that a partial milestone set compute from whichever
 *   CONSECUTIVE pair exists rather than erroring or showing garbage.
 */

if (!function_exists('interval_report_ts')) {
    /**
     * Normalize a raw assigns.* datetime value to a Unix timestamp, or
     * null if the milestone was never stamped. Treats both a true SQL
     * NULL and the legacy '0000-00-00 00:00:00' sentinel (the real
     * writers never emit it — see inc/responder-write.php's own
     * substr(...,0,4)==='0000' guard — but older migrated data can still
     * carry it) as "not set."
     *
     * @param mixed $raw
     */
    function interval_report_ts($raw): ?int {
        if ($raw === null) return null;
        $s = trim((string) $raw);
        if ($s === '' || substr($s, 0, 4) === '0000') return null;
        $ts = strtotime($s);
        return $ts !== false ? $ts : null;
    }

    /**
     * Seconds between two assigns.* milestone columns, or null when
     * either endpoint is unset OR the result would be negative (a clock
     * anomaly on the underlying data — never surfaced as a "garbage"
     * duration; the raw timestamps themselves are still shown in their
     * own columns for whoever needs to see the anomaly).
     *
     * @param mixed $startRaw
     * @param mixed $endRaw
     */
    function interval_report_diff($startRaw, $endRaw): ?int {
        $start = interval_report_ts($startRaw);
        $end   = interval_report_ts($endRaw);
        if ($start === null || $end === null) {
            return null;
        }
        $diff = $end - $start;
        return $diff >= 0 ? $diff : null;
    }

    /**
     * Format a seconds value as M:SS, matching the existing
     * dispatch_log/unit_log convention already in api/reports.php
     * (sprintf('%d:%02d', floor(secs/60), secs%60)). Empty string for
     * null/negative so a partial row renders a blank cell, not "0:00"
     * (which would misleadingly claim an instantaneous interval).
     */
    function interval_report_fmt(?int $secs): string {
        if ($secs === null || $secs < 0) return '';
        return sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
    }

    /**
     * Compute every interval leg for one assigns row. $row must carry
     * (at minimum) the keys dispatched/responding/on_scene/u2fenr/u2farr/
     * clear — raw DB values, each either null or a datetime string.
     *
     * Returns an array of seconds (int|null) keyed:
     *   turnout_secs   — dispatched -> responding (crew acknowledged the call)
     *   travel_secs    — responding -> on_scene (drive time)
     *   response_secs  — dispatched -> on_scene (the overall/EMS-standard
     *                     "response time," computed directly so it's still
     *                     available even when `responding` was never
     *                     stamped — e.g. a status config that jumps
     *                     straight to On Scene)
     *   scene_secs     — on_scene -> the EARLIER of u2fenr (if a transport
     *                     leg happened) or clear (if it didn't) — "scene
     *                     time" ends the moment the unit starts moving
     *                     again, whichever leg that turns out to be
     *   transport_secs — u2fenr -> u2farr (time actually moving the patient)
     *   total_secs     — dispatched -> clear (the whole call, door to door)
     *
     * Any leg missing one or both of its endpoints comes back null —
     * never 0, never an exception — so a report row with only
     * dispatched/on_scene/clear (the common no-transport case) renders
     * response_secs + total_secs and leaves turnout/travel/scene/transport
     * blank, exactly as GH#64 asked for.
     */
    function interval_report_compute(array $row): array {
        $dispatched = $row['dispatched'] ?? null;
        $responding = $row['responding'] ?? null;
        $onScene    = $row['on_scene']   ?? null;
        $u2fenr     = $row['u2fenr']     ?? null;
        $u2farr     = $row['u2farr']     ?? null;
        $clear      = $row['clear']      ?? null;

        // Prefer u2fenr as the scene-time boundary whenever it's actually
        // set (a transport happened); otherwise fall back to clear. Using
        // interval_report_ts() (not a raw truthiness check) so the
        // '0000-00-00' sentinel is treated identically to NULL here too.
        $sceneEndRaw = (interval_report_ts($u2fenr) !== null) ? $u2fenr : $clear;

        return [
            'turnout_secs'   => interval_report_diff($dispatched, $responding),
            'travel_secs'    => interval_report_diff($responding, $onScene),
            'response_secs'  => interval_report_diff($dispatched, $onScene),
            'scene_secs'     => interval_report_diff($onScene, $sceneEndRaw),
            'transport_secs' => interval_report_diff($u2fenr, $u2farr),
            'total_secs'     => interval_report_diff($dispatched, $clear),
        ];
    }
}
