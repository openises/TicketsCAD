<?php
/**
 * Phase 63 migration — OwnTracks max_age_seconds alignment
 *
 * Phase 55 set the battery-friendly baseline to pubInterval=60 minutes,
 * but the seed-time max_age_seconds for OwnTracks was still 120 seconds
 * (from unit_assignments.sql line 69). Result: every off-duty heartbeat
 * landed ~60 min after the last one and was immediately flagged stale.
 * Combined with Phase 63's switch to received_at, the right ceiling is
 * one heartbeat interval + 50% grace = 90 min.
 *
 * Only bumps if the value is still at one of our prior seed-time
 * defaults (120 or 300). An admin who intentionally tightened or
 * loosened past those values is left alone.
 *
 * Idempotent — safe to re-run.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;

$prefix = $GLOBALS['db_prefix'] ?? '';

// Per the project's defensive-database conventions (CLAUDE.md "Schema
// resilience"), never assume a column exists. On fresh installs the
// master migration runner discovers scripts in lex order, so this
// phase63 file runs BEFORE run_unit_assignments.php — which is where
// max_age_seconds actually gets ALTERed onto location_providers. If
// the column isn't here yet, no-op cleanly; run_unit_assignments will
// add the column AND seed owntracks=5400 directly, making this whole
// migration a no-op for fresh installs. It exists for the
// upgrade-from-Phase-55-or-earlier case where the column already
// exists with a stale value of 120 or 300.
$colExists = db_fetch_one(
    "SELECT 1
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = ?
        AND COLUMN_NAME  = 'max_age_seconds'",
    ["{$prefix}location_providers"]
);
if (!$colExists) {
    echo "location_providers.max_age_seconds column not present yet — "
       . "run_unit_assignments.php (later in this migration set) will add it "
       . "and seed owntracks=5400 directly. Nothing to do here.\n";
    exit(0);
}

$before = db_fetch_one(
    "SELECT `id`, `max_age_seconds` FROM `{$prefix}location_providers` WHERE `code` = ?",
    ['owntracks']
);

if (!$before) {
    echo "OwnTracks provider not registered — nothing to do.\n";
    exit(0);
}

if (!in_array((int) $before['max_age_seconds'], [120, 300], true)) {
    echo "OwnTracks max_age_seconds is {$before['max_age_seconds']} (admin-customized) — leaving alone.\n";
    exit(0);
}

db_query(
    "UPDATE `{$prefix}location_providers`
     SET `max_age_seconds` = 5400
     WHERE `code` = ? AND `max_age_seconds` IN (120, 300)",
    ['owntracks']
);

$after = db_fetch_one(
    "SELECT `max_age_seconds` FROM `{$prefix}location_providers` WHERE `code` = ?",
    ['owntracks']
);

echo "OwnTracks max_age_seconds: {$before['max_age_seconds']}s -> {$after['max_age_seconds']}s\n";
