<?php
/**
 * Phase 95 — Configurable status extra-data.
 *
 * a beta tester (beta tester) 2026-06-28 feedback: status changes
 * should optionally collect additional context (e.g. "Transporting"
 * prompts for destination facility, "Maintenance" prompts for an
 * explanation note, "Responding" prompts for start mileage).
 *
 * Eric's design call: keep it generic and configurable per-status
 * rather than hard-coding "Transporting needs facility" semantics.
 * Any admin can wire any status to collect any of a handful of
 * extra-data types, mark it required or optional, and pick where the
 * data lands (incident, unit, or audit-log only).
 *
 * Schema additions to un_status:
 *   extra_data_type     — one of none/facility/mileage/location/note/numeric
 *   extra_data_required — 0 = optional, 1 = required to save the status change
 *   extra_data_label    — admin-customizable prompt text (e.g. "Destination facility")
 *   extra_data_target   — incident | unit | action_log (where the value lands)
 *
 * Default for every existing row: type='none' / required=0 / label=NULL /
 * target='action_log'. Zero behavior change for current installs.
 * Admins opt in per-status by setting type to something other than 'none'.
 *
 * Idempotent — safe to re-run. Uses IF NOT EXISTS guards.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 95 schema migration — configurable status extra-data\n";
echo "==========================================================\n\n";

function _phase95_column_exists(string $prefix, string $table, string $column): bool {
    try {
        $v = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$prefix . $table, $column]
        );
        return (int) $v > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ── extra_data_type ────────────────────────────────────────────
echo "  [1/4] un_status.extra_data_type... ";
if (_phase95_column_exists($prefix, 'un_status', 'extra_data_type')) {
    echo "exists (skip)\n";
} else {
    try {
        db_query(
            "ALTER TABLE `{$prefix}un_status`
             ADD COLUMN `extra_data_type`
             ENUM('none','facility','mileage','location','note','numeric')
             NOT NULL DEFAULT 'none' AFTER `resets_par`"
        );
        echo "ADDED\n";
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

// ── extra_data_required ────────────────────────────────────────
echo "  [2/4] un_status.extra_data_required... ";
if (_phase95_column_exists($prefix, 'un_status', 'extra_data_required')) {
    echo "exists (skip)\n";
} else {
    try {
        db_query(
            "ALTER TABLE `{$prefix}un_status`
             ADD COLUMN `extra_data_required` TINYINT(1) NOT NULL DEFAULT 0
             AFTER `extra_data_type`"
        );
        echo "ADDED\n";
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

// ── extra_data_label ───────────────────────────────────────────
echo "  [3/4] un_status.extra_data_label... ";
if (_phase95_column_exists($prefix, 'un_status', 'extra_data_label')) {
    echo "exists (skip)\n";
} else {
    try {
        db_query(
            "ALTER TABLE `{$prefix}un_status`
             ADD COLUMN `extra_data_label` VARCHAR(64) NULL DEFAULT NULL
             AFTER `extra_data_required`"
        );
        echo "ADDED\n";
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

// ── extra_data_target ──────────────────────────────────────────
echo "  [4/4] un_status.extra_data_target... ";
if (_phase95_column_exists($prefix, 'un_status', 'extra_data_target')) {
    echo "exists (skip)\n";
} else {
    try {
        db_query(
            // 'assignment' (Phase 116) routes a picked facility to the unit's
            // assignment row (assigns.rec_facility_id) — the per-unit receiving
            // facility. run_phase116 widens this on installs created before 116.
            "ALTER TABLE `{$prefix}un_status`
             ADD COLUMN `extra_data_target` ENUM('incident','unit','action_log','assignment')
             NOT NULL DEFAULT 'action_log'
             AFTER `extra_data_label`"
        );
        echo "ADDED\n";
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

echo "\nPhase 95 schema complete.\n";
echo "All existing un_status rows default to extra_data_type='none' —\n";
echo "no behavior change until admin opts a status in via Settings →\n";
echo "App Preferences → Unit Statuses.\n";
