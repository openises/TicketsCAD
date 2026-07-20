<?php
/**
 * Phase 32 (2026-06-12) — par_config.is_disabled
 *
 * Eric: "On the incident types page, the PAR Cadence Override (min)
 * needs to have another way to indicate an override. I might want
 * to disable PAR checks on an incident type."
 *
 * The current single-number field conflates two states:
 *   * 0 / blank      → "use the agency / system default"
 *   * positive int N → "override with N minutes"
 *
 * Missing: an explicit way to say "PAR doesn't apply to incidents
 * of this type at all." With Phase 30A's gates, the only way to
 * silence PAR for a given type was to set the agency default to 0
 * which kills PAR globally — wrong granularity.
 *
 * Adds a boolean is_disabled column to par_config. The resolver
 * treats an incident-type row with is_disabled=1 as a hard NO:
 * cadence_minutes is forced to 0 and source stays 'incident_type'
 * so par_due_at correctly returns null with a clean reason.
 *
 * UI gains a tri-state radio group on the Incident Types form:
 *   ○ Use system default
 *   ○ Override with [N] minutes
 *   ○ Disable PAR for this type
 *
 * Idempotent. Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 32 — par_config.is_disabled\n";
echo "==================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'is_disabled'",
        [$prefix . 'par_config']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}par_config`
             ADD COLUMN `is_disabled` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Phase 32: 1=PAR disabled for this scope. cadence_minutes ignored when 1. Lets admin explicitly opt out of PAR for an incident type even when agency default is enabled.'"
        );
        echo "[OK] Added par_config.is_disabled\n";
    } else {
        echo "[OK] par_config.is_disabled already exists\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
