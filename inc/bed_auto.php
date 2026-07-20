<?php
/**
 * NewUI v4.0 — Facility bed-count automation (Phase 103, a beta tester GH #20).
 *
 * `facilities.bed_auto_mode` controls whether a facility's simple
 * `beds_a` / `beds_o` counts change automatically on unit status
 * transitions:
 *
 *   'manual' (default) — nothing fires automatically; the two
 *                        counters only change when a facility admin
 *                        edits them via facility-edit.php.
 *   'auto'             — when a unit whose transport DESTINATION is this
 *                        facility transitions into a status considered a
 *                        "delivered/arrived" event, decrement beds_a by 1
 *                        and increment beds_o by 1. Fires once per assign_id
 *                        (deduplicated via the `facility_bed_auto_log` table).
 *
 * WHERE THE DESTINATION FACILITY COMES FROM (read this before "fixing" it):
 *   Resolution is COALESCE(assigns.rec_facility_id, ticket.rec_facility) —
 *   PER-UNIT first, incident-level fallback. `assigns.rec_facility_id` is the
 *   destination for a SPECIFIC unit's transport; it is the authoritative source
 *   because a mass-casualty incident sends different units to different hospitals.
 *   `ticket.rec_facility` is the incident-level default (the incident form's
 *   "Receiving Facility"), used when a unit has no per-unit destination of its own.
 *
 *   Provenance / history: `assigns.rec_facility_id` is a LEGACY column, actively
 *   written per-unit in the 30-year-old TicketsCAD. The NewUI rewrite inherited the
 *   column but its assignment writer stopped writing it, so early Phase 103 read a
 *   column nothing populated and never fired on real installs (GH #20, three rounds).
 *   Phase 116 restored the per-unit write path (assign_set_rec_facility() +
 *   un_status.extra_data_target='assignment'); see
 *   specs/phase-116-per-unit-receiving-facility. Do NOT collapse this back to a
 *   single incident-level read — that silently regresses the MCI capability.
 *
 * Deliberately narrow first cut:
 *   - No auto-release (bed goes back into `beds_a`) on incident close.
 *     Agencies handle patient discharge/turnover manually because the
 *     dispatch system doesn't know when the patient is transferred out.
 *   - Delta is fixed at 1 bed per delivery. Multi-patient runs still
 *     work — the facility admin bumps beds_o manually for the extra
 *     patients. A per-facility `beds_per_delivery` (or per-incident
 *     `patient_count`) is the obvious v2.
 *   - Uses the legacy `facilities.beds_a`/`beds_o` counters, NOT the
 *     categorized `facility_capacity` table. The two systems parallel
 *     each other today; unifying them is a bigger change.
 *
 * Doc surface: help.php ("How bed counts update") and the info banner
 * at the top of facility-detail.php's Bed Capacity card.
 */

if (!defined('BED_AUTO_STATUS_PATTERNS')) {
    /**
     * Case-insensitive substring patterns on `un_status.status_val`
     * that count as a "delivery" event. Kept as a small list rather
     * than a config table for MVP — agencies with non-standard status
     * names can add rows to their `un_status` table using one of these
     * substrings, or we can pull this into a proper `un_status.
     * facility_bed_delta` INT column in v2 once the pattern of real
     * agency configs is clearer.
     */
    define('BED_AUTO_STATUS_PATTERNS', [
        'at facility',
        'at hospital',
        'delivered',
        'patient delivered',
        'arrived',
        'transfer of care',
    ]);
}

/**
 * Ensure the audit table exists. Idempotent — safe to call on every
 * request. Kept close to the automation call site so a fresh install
 * doesn't need a migration for MVP; a later phase can promote this
 * to a proper migration script if the schema stabilizes.
 */
function _bed_auto_ensure_log_table()
{
    static $ensured = false;
    if ($ensured) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_bed_auto_log` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `assign_id`     INT NOT NULL,
            `facility_id`   INT NOT NULL,
            `responder_id`  INT NOT NULL,
            `ticket_id`     INT NOT NULL,
            `delta_a`       INT NOT NULL DEFAULT 0,
            `delta_o`       INT NOT NULL DEFAULT 0,
            `status_id`     INT NOT NULL DEFAULT 0,
            `status_val`    VARCHAR(64) DEFAULT '',
            `applied_by`    INT NOT NULL DEFAULT 0,
            `applied_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_assign_facility` (`assign_id`, `facility_id`),
            KEY `idx_facility_time` (`facility_id`, `applied_at`),
            KEY `idx_responder`     (`responder_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ensured = true;
    } catch (Exception $e) {
        error_log('[bed_auto] log table ensure failed: ' . $e->getMessage());
    }
}

/**
 * Test whether a status name qualifies as a delivery event
 * (legacy name-pattern fallback).
 */
function _bed_auto_is_delivery_status($statusName)
{
    $normalized = strtolower(trim((string) $statusName));
    if ($normalized === '') return false;
    foreach (BED_AUTO_STATUS_PATTERNS as $pat) {
        if (strpos($normalized, $pat) !== false) return true;
    }
    return false;
}

/**
 * GH #20 round 2 (a beta tester 2026-07-07) — per-status configuration.
 *
 * Agencies name their statuses whatever they like ("At Destination",
 * "AD", ...), so English substring matching can never be right: it
 * missed a beta tester's at-facility status AND would have fired on his
 * "Arrived" (which means on-scene, not at-facility). The un_status
 * table now carries a `bed_delivery` flag (Settings > Unit Statuses >
 * "Counts as facility delivery").
 *
 * Semantics: when ANY status on the install has the flag set, flags
 * are authoritative and the name patterns are ignored. When no flags
 * are set anywhere (pre-migration installs, or agencies that never
 * opened the setting), fall back to the legacy pattern list so
 * existing auto-mode deployments keep working unchanged.
 */
function bed_auto_status_qualifies($statusId, $statusName)
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    static $flagsInUse = null;
    if ($flagsInUse === null) {
        try {
            $flagsInUse = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}un_status` WHERE `bed_delivery` = 1"
            ) > 0;
        } catch (Exception $e) {
            $flagsInUse = false; // column missing — pre-migration install
        }
    }
    if ($flagsInUse) {
        try {
            return (int) db_fetch_value(
                "SELECT `bed_delivery` FROM `{$prefix}un_status` WHERE `id` = ?",
                [(int) $statusId]
            ) === 1;
        } catch (Exception $e) {
            return false;
        }
    }
    return _bed_auto_is_delivery_status($statusName);
}

/**
 * Called from responder_set_status_internal() after a status change
 * has committed. Walks the responder's open assigns for any with a
 * `rec_facility_id` whose facility has bed_auto_mode='auto', and
 * applies a one-time decrement per (assign_id, facility_id) pair.
 *
 * Fails soft: any error is logged and swallowed. Automation is a
 * convenience — it must never block a legitimate status change.
 *
 * @param int    $responderId
 * @param int    $statusId
 * @param string $statusName raw status_val from un_status
 * @param int    $userId     acting user (for audit trail)
 * @return array {applied: int, skipped: int, reasons: string[]}
 */
function bed_auto_apply_on_status_change($responderId, $statusId, $statusName, $userId)
{
    $result = ['applied' => 0, 'skipped' => 0, 'reasons' => []];
    if (!bed_auto_status_qualifies($statusId, $statusName)) {
        $result['reasons'][] = 'status_not_a_delivery_event';
        return $result;
    }
    _bed_auto_ensure_log_table();
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        // Detect whether the facilities table actually has the
        // bed_auto_mode column yet — installs that haven't run the
        // Phase 103 migration should skip silently, not crash.
        static $hasCol = null;
        if ($hasCol === null) {
            try {
                $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}facilities` LIKE 'bed_auto_mode'");
                $hasCol = !empty($cols);
            } catch (Exception $e) { $hasCol = false; }
        }
        if (!$hasCol) {
            $result['reasons'][] = 'schema_bed_auto_mode_missing';
            return $result;
        }

        // GH #20 round 3 (a beta tester 2026-07-15) — THE root cause behind
        // "still not working". The receiving facility a dispatcher
        // actually sets lives on the INCIDENT (`ticket.rec_facility`, the
        // "Receiving Facility" select on the incident form / incident
        // detail), NOT on the assignment row. `assigns.rec_facility_id`
        // exists in the schema but NO dispatch UI ever writes it, so
        // reading only that column made this automation impossible to
        // trigger on any real install — every open assign had
        // rec_facility_id = NULL, which is exactly why a beta tester's
        // bed_auto_diagnose Stage 4 reported
        // 'no_open_assignment_with_receiving_facility' with the facility
        // plainly set on his incident. (The 32 unit tests all hand-inserted
        // rec_facility_id into the assign, so they passed against a state
        // the real writer never produces — the classic
        // test-passes-but-real-usage-fails trap.)
        //
        // Resolve the effective receiving facility from the incident,
        // keeping the per-assignment column as an OPTIONAL override for a
        // future "this unit diverts to a different hospital" feature.
        $openAssigns = db_fetch_all(
            "SELECT a.id AS assign_id, a.ticket_id,
                    COALESCE(NULLIF(a.rec_facility_id, 0), NULLIF(t.rec_facility, 0)) AS rec_facility_id
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
             WHERE a.responder_id = ?
               AND (a.clear IS NULL OR a.clear = '' OR a.clear = '0000-00-00 00:00:00')
               AND COALESCE(NULLIF(a.rec_facility_id, 0), NULLIF(t.rec_facility, 0)) > 0",
            [$responderId]
        );
        if (empty($openAssigns)) {
            // GH #20 diagnosis aid: this was the one no-fire path that
            // recorded NO reason at all — a delivery status with no open
            // assignment whose incident carries a receiving facility
            // looked identical to success in the logs.
            $result['reasons'][] = 'no_open_assignment_with_receiving_facility';
        }

        foreach ($openAssigns as $oa) {
            $facilityId = (int) $oa['rec_facility_id'];
            $assignId   = (int) $oa['assign_id'];
            $ticketId   = (int) $oa['ticket_id'];

            $facility = db_fetch_one(
                "SELECT id, name, bed_auto_mode, beds_a, beds_o
                 FROM `{$prefix}facilities`
                 WHERE id = ?",
                [$facilityId]
            );
            if (!$facility) {
                $result['skipped']++;
                $result['reasons'][] = 'facility_' . $facilityId . '_not_found';
                continue;
            }
            if (($facility['bed_auto_mode'] ?? 'manual') !== 'auto') {
                $result['skipped']++;
                $result['reasons'][] = 'facility_' . $facilityId . '_mode_manual';
                continue;
            }

            $already = db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}facility_bed_auto_log`
                 WHERE assign_id = ? AND facility_id = ?",
                [$assignId, $facilityId]
            );
            if ((int) $already > 0) {
                $result['skipped']++;
                $result['reasons'][] = 'assign_' . $assignId . '_already_applied';
                continue;
            }

            // beds_a / beds_o are VARCHAR(6) legacy columns storing
            // stringified integers; coerce and floor at 0 for beds_a
            // so we don't dip negative on a mis-config.
            $bedsA = max(0, ((int) ($facility['beds_a'] ?? 0)) - 1);
            $bedsO = ((int) ($facility['beds_o'] ?? 0)) + 1;

            db_query(
                "UPDATE `{$prefix}facilities`
                 SET beds_a = ?, beds_o = ?, updated = NOW()
                 WHERE id = ?",
                [(string) $bedsA, (string) $bedsO, $facilityId]
            );

            db_query(
                "INSERT INTO `{$prefix}facility_bed_auto_log`
                 (assign_id, facility_id, responder_id, ticket_id,
                  delta_a, delta_o, status_id, status_val, applied_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$assignId, $facilityId, $responderId, $ticketId,
                 -1, +1, $statusId, substr((string) $statusName, 0, 64), $userId]
            );

            if (function_exists('audit_log')) {
                audit_log(
                    'asset', 'update', 'facility', $facilityId,
                    'Auto bed adjust: unit ' . $responderId
                        . ' -> ' . $facility['name']
                        . ' (' . $statusName . '), beds_a ' . ($bedsA + 1) . '->' . $bedsA
                        . ', beds_o ' . ($bedsO - 1) . '->' . $bedsO
                );
            }
            $result['applied']++;
        }
    } catch (Exception $e) {
        error_log('[bed_auto] apply failed: ' . $e->getMessage());
        $result['reasons'][] = 'exception:' . $e->getMessage();
    }

    // GH #20 dry-run trace (a beta tester, 2026-07-07): when a delivery-shaped
    // status fires NO bed change, say exactly why in the PHP error log —
    // callers discard the reasons array, so this is the only place the
    // decline becomes visible to an admin.
    if ($result['applied'] === 0) {
        error_log('[bed_auto] delivery status "' . $statusName . '" (id ' . $statusId
            . ') made no bed change for responder ' . $responderId . ': '
            . ($result['reasons'] ? implode(', ', $result['reasons']) : 'no reason recorded'));
    }
    return $result;
}
