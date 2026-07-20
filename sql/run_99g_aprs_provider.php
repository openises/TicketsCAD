<?php
/**
 * Phase 99g (2026-06-28) — register APRS-IS as a location provider so
 * the future APRS-IS listener daemon has somewhere to write rows.
 *
 * Idempotent — INSERT IGNORE on `code` unique key.
 *
 * Run: php sql/run_99g_aprs_provider.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // GH #72 follow-on (2026-07-07): max_age_seconds is ALTERed onto
    // location_providers by run_unit_assignments.php, which sorts AFTER
    // this script — on a fresh install the column doesn't exist yet and
    // this INSERT hard-failed every fresh install (silently, until the
    // runner started honoring exit codes). Include the column only when
    // present; run_unit_assignments backfills aprs to its default later.
    $hasMaxAge = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'max_age_seconds'",
        [$prefix . 'location_providers']
    );
    $cols = "code, name, enabled, priority, config_json, icon, color, created_at";
    $vals = "'aprs', 'APRS-IS (amateur radio)', 1, 50,
             '{\"server\":\"rotate.aprs2.net\",\"port\":14580,\"filter_radius_km\":200}',
             'bi-broadcast', '#0d6efd', NOW()";
    if ($hasMaxAge) {
        $cols = str_replace('color,', 'color, max_age_seconds,', $cols);
        $vals = str_replace("'#0d6efd',", "'#0d6efd', 3600,", $vals);
    }
    db_query("INSERT IGNORE INTO `{$prefix}location_providers` ($cols) VALUES ($vals)");
    $id = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}location_providers` WHERE code = 'aprs' LIMIT 1"
    );
    echo "✓ APRS-IS location provider row id=" . $id . " (existing rows untouched)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Done.\n";
