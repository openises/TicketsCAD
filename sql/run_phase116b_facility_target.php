<?php
/**
 * Phase 116b (GH #20 round 4) — normalize delivery/facility status routing.
 *
 * A `facility` collected on a UNIT status is always that unit's destination, so
 * it must route to the assignment (assigns.rec_facility_id), never the shared
 * incident (ticket.rec_facility). Phase 116 widened the extra_data_target ENUM to
 * allow 'assignment' but never SET any status to use it, so field-created delivery
 * statuses defaulted to target='incident' — two ambulances to two hospitals then
 * both decremented ONE facility's beds.
 *
 * The routing is now forced per-assignment in code (inc/responder-write.php), so
 * this migration is data hygiene: it makes the stored target agree with reality so
 * the Settings status editor shows the right value. Idempotent.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$prefix = $GLOBALS['db_prefix'] ?? '';
echo "Phase 116b — un_status facility routing normalized to 'assignment'\n";
try {
    // Only relevant if the extra_data_target column exists AND its ENUM already
    // includes 'assignment' (run_phase116 widened it; run first if pending).
    $type = db_fetch_value(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'extra_data_target'",
        [$prefix . 'un_status']
    );
    if ($type === null) { echo "  extra_data_target column absent — nothing to do.\n"; return; }
    if (stripos((string) $type, "'assignment'") === false) {
        echo "  ENUM lacks 'assignment' (run run_phase116 first) — skipping.\n";
        return;
    }
    $affected = db_query(
        "UPDATE `{$prefix}un_status`
            SET `extra_data_target` = 'assignment'
          WHERE `extra_data_type` = 'facility'
            AND `extra_data_target` <> 'assignment'"
    );
    echo "  Normalized facility statuses to target='assignment'.\n";
} catch (Exception $e) {
    echo "  WARN: " . $e->getMessage() . "\n";
}
