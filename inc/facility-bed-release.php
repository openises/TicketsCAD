<?php
/**
 * GH#102 — Facility self-service bed RELEASE (the missing inverse of
 * inc/bed_auto.php's automatic decrement).
 *
 * `bed_auto_apply_on_status_change()` (inc/bed_auto.php) is a one-way
 * ratchet: it decrements `facilities.beds_a` and increments `beds_o` on
 * every delivery, but nothing ever moves a bed back the other way except
 * a dispatch-side admin hand-editing the facility record via
 * api/facility-action.php's `action=beds` — an endpoint a facility-portal
 * account can never reach (see inc/facility-scope.php's confinement
 * model). The facility itself, the only party that actually knows when a
 * patient is discharged, had no way to say so. Reported as GH#102
 * (openises/TicketsCAD issue #102, rjonesbsink) with an unusually
 * thorough analysis of the exact gap and the confinement boundary that
 * makes the obvious "just widen the allowlist" fix wrong.
 *
 * DESIGN DECISION (synthesized from a 3-perspective review — a receiving-
 * facility bed-manager, a working dispatcher, and a data-integrity/
 * security reviewer — run before writing any code):
 *
 *   - Targets `facilities.beds_a`/`beds_o` ONLY — the SAME columns
 *     bed_auto.php decrements, and the number facility-detail.php's
 *     dispatch-facing "Bed Capacity" card actually shows. A release that
 *     only touched the separate, already-facility-writable
 *     `facility_capacity` table (categorized, per capacity_categories)
 *     would not close the loop the bug is about — dispatch doesn't look
 *     at that number for automatic-mode routing decisions, and
 *     `bed_auto.php` never touches it either. Unifying the two tables is
 *     a materially bigger, riskier change (which number wins on an
 *     install where they've already diverged; every reader updated in
 *     lockstep) and is deliberately DEFERRED — this is a well-scoped
 *     partial step, not a rewrite. See bed_auto.php's own docblock,
 *     unchanged, for that boundary.
 *
 *   - Deliberately a COARSE "release N beds" action, not a release tied
 *     1:1 to a specific `facility_bed_auto_log` row. A real facility
 *     doesn't discharge patients in the same order deliveries arrived,
 *     often doesn't track which specific transport corresponds to which
 *     bed, and the field user has 10-30 seconds between patients, not
 *     time to pick a historical assignment from a list. The safety
 *     ceiling this still needs — a facility must never be able to
 *     inflate its own capacity — comes from a STRUCTURAL invariant
 *     instead of a per-row lookup: a release can never move more beds
 *     than are CURRENTLY marked occupied (`beds_o` floors at 0), and
 *     because every release is a symmetric swap (beds_a += N, beds_o -=
 *     N), `beds_a + beds_o` — the facility's own implicit "beds
 *     accounted for" total — never changes. A release can only ever
 *     shift already-counted beds from occupied to available; it can
 *     never create capacity that wasn't already on the books. This is a
 *     live, database-checked bound, not a historical one — it holds
 *     regardless of whether every prior occupancy came from automation,
 *     a dispatcher's manual edit, or a legacy import.
 *
 *   - Every release is logged to `facility_bed_release_log` (mirroring
 *     `facility_bed_auto_log`'s shape) with the acting user's REAL id and
 *     denormalized name — never 0, never a synthetic value — so a
 *     dispatcher reviewing the "Facility Bed Adjustments" report
 *     (api/reports.php `facility_bed_adjustments`) can tell an automatic
 *     decrement from a facility's own self-release from a dispatcher's
 *     manual correction, distinctly, per the dispatcher-perspective
 *     reviewer's explicit ask.
 *
 *   - `count` is clamped to a small sane range (1-50) purely as input
 *     hygiene; the real, meaningful ceiling is the beds_o floor above,
 *     which holds regardless of what `count` claims.
 *
 * Extracted into inc/ (not left inline in api/facility-portal.php) so it
 * is directly unit-testable against a real database without an HTTP
 * harness — matching this codebase's own established rule that reusable/
 * testable logic belongs in an inc/*.php include, never buried in an
 * api/*.php endpoint (see api/owntracks-config.php's OT_CONFIG_LIBRARY_ONLY
 * pitfall in CLAUDE.md for why).
 */

declare(strict_types=1);

if (!defined('FACILITY_BED_RELEASE_MIN_COUNT')) {
    define('FACILITY_BED_RELEASE_MIN_COUNT', 1);
}
if (!defined('FACILITY_BED_RELEASE_MAX_COUNT')) {
    define('FACILITY_BED_RELEASE_MAX_COUNT', 50);
}

/**
 * Ensure facility_bed_release_log exists. Idempotent, safe every request —
 * same self-healing shape api/facility-portal.php already uses for
 * capacity_categories/facility_capacity, and the same DDL as the
 * canonical migration (sql/run_gh102_facility_bed_release.php).
 */
function facility_bed_release_ensure_table(): void
{
    static $ensured = false;
    if ($ensured) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_bed_release_log` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `facility_id`      INT NOT NULL,
            `delta_a`          INT NOT NULL DEFAULT 0,
            `delta_o`          INT NOT NULL DEFAULT 0,
            `note`             VARCHAR(500) DEFAULT '',
            `released_by`      INT NOT NULL DEFAULT 0,
            `released_by_name` VARCHAR(191) DEFAULT '',
            `applied_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_facility_time` (`facility_id`, `applied_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ensured = true;
    } catch (Exception $e) {
        error_log('[facility_bed_release] log table ensure failed: ' . $e->getMessage());
    }
}

/**
 * Apply a facility self-release. Structurally cannot touch any facility
 * other than $facilityId — the caller (api/facility-portal.php) MUST pass
 * facility_session_facility_id(), never a client-supplied value, matching
 * every other write in that file.
 *
 * @param int    $facilityId  the caller's OWN facility (session-scoped)
 * @param int    $requested   how many beds to attempt to release (clamped)
 * @param string $note        optional free-text note (trimmed, capped)
 * @param int    $userId      acting user id (real, never 0/synthetic)
 * @param string $userName    denormalized acting user display name
 * @return array{
 *   success: bool, released: int, beds_a: int, beds_o: int,
 *   error: string|null
 * }
 */
function facility_bed_release_apply(int $facilityId, int $requested, string $note, int $userId, string $userName): array
{
    $result = ['success' => false, 'released' => 0, 'beds_a' => 0, 'beds_o' => 0, 'error' => null];
    if ($facilityId <= 0) {
        $result['error'] = 'No facility linked to this account';
        return $result;
    }

    $count = max(FACILITY_BED_RELEASE_MIN_COUNT, min(FACILITY_BED_RELEASE_MAX_COUNT, $requested));
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $facility = db_fetch_one(
        "SELECT `id`, `name`, `beds_a`, `beds_o` FROM `{$prefix}facilities` WHERE `id` = ?",
        [$facilityId]
    );
    if (!$facility) {
        $result['error'] = 'Facility not found';
        return $result;
    }

    $currentA = (int) ($facility['beds_a'] ?? 0);
    $currentO = (int) ($facility['beds_o'] ?? 0);

    // The structural ceiling: never release more than are CURRENTLY
    // marked occupied. This is what makes the action safe as a coarse,
    // non-log-tied operation — beds_a + beds_o is conserved by
    // construction, so a release can only ever move already-accounted
    // beds from occupied to available, never invent capacity.
    $actual = min($count, $currentO);
    if ($actual <= 0) {
        $result['error'] = 'No occupied beds to release';
        $result['beds_a'] = $currentA;
        $result['beds_o'] = $currentO;
        return $result;
    }

    $newA = $currentA + $actual;
    $newO = $currentO - $actual;

    db_query(
        "UPDATE `{$prefix}facilities` SET `beds_a` = ?, `beds_o` = ?, `updated` = NOW() WHERE `id` = ?",
        [(string) $newA, (string) $newO, $facilityId]
    );

    facility_bed_release_ensure_table();
    try {
        db_query(
            "INSERT INTO `{$prefix}facility_bed_release_log`
             (facility_id, delta_a, delta_o, note, released_by, released_by_name)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$facilityId, $actual, -$actual, mb_substr(trim($note), 0, 500), $userId, mb_substr(trim($userName), 0, 191)]
        );
    } catch (Exception $e) {
        // Never let a logging failure undo or block a real, already-
        // committed bed release — logged-and-swallowed, matching the
        // standing audit-write convention.
        error_log('[facility_bed_release] log insert failed: ' . $e->getMessage());
    }

    if (function_exists('audit_log')) {
        audit_log(
            'facility', 'bed_release', 'facility', $facilityId,
            'Facility self-released ' . $actual . ' bed' . ($actual === 1 ? '' : 's')
                . ' at ' . ($facility['name'] ?? ('facility #' . $facilityId))
                . ': beds_a ' . $currentA . '->' . $newA . ', beds_o ' . $currentO . '->' . $newO,
            ['facility_id' => $facilityId, 'released' => $actual, 'note' => $note,
             'source' => 'facility_portal_self_report']
        );
    }

    $result['success']  = true;
    $result['released'] = $actual;
    $result['beds_a']   = $newA;
    $result['beds_o']   = $newO;
    return $result;
}
