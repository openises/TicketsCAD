<?php
/**
 * Phase 116 — per-unit receiving facility.
 *
 * Adds 'assignment' to the `un_status.extra_data_target` ENUM so a delivery
 * status can route its picked facility to the UNIT'S assignment row
 * (`assigns.rec_facility_id`) — the per-unit destination hospital — instead of
 * only the incident-level `ticket.rec_facility`. This restores the per-unit
 * receiving-facility capability the legacy TicketsCAD had and the NewUI rewrite
 * dropped, which matters most in a mass-casualty incident where each transporting
 * unit goes to a DIFFERENT hospital.
 * See specs/phase-116-per-unit-receiving-facility.
 *
 * Idempotent: widens the ENUM only if 'assignment' is not already a member, and
 * skips cleanly if the column doesn't exist yet (fresh installs get it directly
 * from run_phase95_status_extra_data.php, which now includes 'assignment').
 * Exits non-zero ONLY on a real failure, so the migration runner halts correctly.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 116 — un_status.extra_data_target += 'assignment'\n";
echo "======================================================\n";

try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'extra_data_target'",
        [$prefix . 'un_status']
    );

    if (!$col) {
        echo "  extra_data_target column absent — run_phase95 creates it (already\n";
        echo "  including 'assignment'). Nothing to widen.\n";
        exit(0);
    }

    $type = (string) ($col['COLUMN_TYPE'] ?? '');
    if (stripos($type, "'assignment'") !== false) {
        echo "  'assignment' already present. Nothing to do.\n";
        exit(0);
    }

    db_query(
        "ALTER TABLE `{$prefix}un_status`
         MODIFY COLUMN `extra_data_target`
         ENUM('incident','unit','action_log','assignment')
         NOT NULL DEFAULT 'action_log'"
    );
    echo "  WIDENED extra_data_target to include 'assignment'.\n";
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
