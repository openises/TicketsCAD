<?php
/**
 * Phase 152 (Console rebuild) — acoustic proximity auto-discovery, an
 * addition to the manual "Nearby workstations" entry (plan.md section
 * 3.5's own sub-section, Eric's idea, 2026-09-06).
 *
 * `console_workstations.beacon_code` was reserved (DEFAULT NULL) by the
 * earlier workstation-identity migration this same session, sql/run_
 * phase152_workstations.php. This file does NOT assume that one has
 * already run first, though, despite both being introduced in the same
 * session -- run_migrations.php discovers per-feature scripts and applies
 * them by ksort() LEXICOGRAPHIC FILENAME ORDER (this project's own
 * documented gotcha), and "run_phase152_beacon_discovery.php" sorts
 * BEFORE "run_phase152_workstations.php" ('b' < 'w') on a genuinely fresh
 * install that has never run either yet. A first version of this file
 * assumed the column already existed and failed exactly that way in CI's
 * fresh-install job (this project's own dev database, worked on earlier
 * across THIS session in a different order, never caught it). Fixed by
 * making this script self-sufficient: it creates the column itself, the
 * same idempotent way run_phase152_workstations.php would, if it doesn't
 * already exist -- safe and correct regardless of which of the two
 * scripts a fresh install happens to run first.
 *
 * Also seeds the `console_beacon_discovery_enabled` setting (a PLAIN
 * settings-table row -- get_variable()/api/config-admin.php's already-
 * generic settings passthrough, no dedicated backend code needed for the
 * toggle itself) so a fresh install has an explicit, discoverable default
 * (on) rather than relying on get_variable()'s own fallback-to-empty-
 * string behavior for a boolean flag, which would read as "off" the first
 * time anything checks it.
 *
 * `beacon_code` itself is a small integer in [0, 27] — the number of
 * unique 2-of-8 near-ultrasonic frequency-pair codes this feature's tone
 * plan supports (C(8,2) = 28; see assets/js/console-beacon.js's own
 * docblock for the exact frequency table). It is NOT the workstation's
 * real id — encoding an arbitrary, unbounded auto-increment value
 * acoustically has no natural alphabet limit, where this feature's whole
 * tone plan is built around a small, fixed set of distinguishable tones.
 * Assigned deterministically as (id - 1) % 28 the first time a
 * workstation row is created (inc/console-workstations.php::
 * console_workstation_resolve()) — a disclosed, accepted limitation: if
 * more than 28 workstations have EVER been created (most stale/inactive
 * is the normal case), two currently-active desks could theoretically
 * collide on the same code. Acceptable because this whole feature is
 * already framed as "best-effort convenience, not a guaranteed result"
 * (plan.md's own words) for room sizes far below that in any realistic
 * dispatch center, and manual entry — unaffected by this column at all —
 * is always the reliable fallback.
 *
 * Idempotent — safe to run repeatedly, and safe regardless of run order
 * relative to sql/run_phase152_workstations.php; picked up by
 * run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- acoustic proximity auto-discovery\n";
echo "===============================================\n\n";

$ok = true;

// console_workstations itself might not exist yet either, on a fresh
// install where run_phase152_workstations.php hasn't run yet -- that is
// fine, this script simply has nothing to add the column to; the OTHER
// migration will create the table (with beacon_code already in its own
// CREATE TABLE) when it runs, in either order.
$tableExists = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'console_workstations']
);
if ((int) $tableExists === 1) {
    $colExists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'beacon_code'",
        [$prefix . 'console_workstations']
    );
    if ((int) $colExists === 1) {
        echo "[OK] console_workstations.beacon_code already exists\n";
    } else {
        try {
            db_query(
                "ALTER TABLE `{$prefix}console_workstations` ADD COLUMN `beacon_code` INT DEFAULT NULL AFTER `label`"
            );
            echo "[OK] console_workstations.beacon_code added\n";
        } catch (Exception $e) {
            fwrite(STDERR, "[FAIL] adding beacon_code: " . $e->getMessage() . "\n");
            $ok = false;
        }
    }
} else {
    echo "[OK] console_workstations table not created yet -- sql/run_phase152_workstations.php "
       . "will create it (with beacon_code already in its own CREATE TABLE) whenever it next runs\n";
}

try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}settings` (name, value) VALUES ('console_beacon_discovery_enabled', '1')"
    );
    echo "[OK] console_beacon_discovery_enabled seeded (default on)\n";
} catch (Exception $e) {
    fwrite(STDERR, "[FAIL] seeding console_beacon_discovery_enabled: " . $e->getMessage() . "\n");
    $ok = false;
}

// Verify (Phase 128 A9b lesson: a migration that swallows its own failure
// and exits 0 is a migration that never ran).
$settingExists = db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = 'console_beacon_discovery_enabled'"
);
if ((int) $settingExists !== 1) {
    fwrite(STDERR, "[FAIL] verification: console_beacon_discovery_enabled setting not found after migration\n");
    $ok = false;
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
