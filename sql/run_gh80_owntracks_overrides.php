<?php
/**
 * GH #80 (a beta tester) — add member.owntracks_overrides column
 *
 * owntracks-diagnostics.php (and other readers) query
 * `member.owntracks_overrides`, but the column was only ever created LAZILY,
 * by a self-heal inside api/owntracks-config.php that fires only when that one
 * endpoint is hit. On an install where owntracks-config.php hasn't run, the
 * diagnostics page throws:
 *     SQLSTATE[42S22]: Unknown column 'm.owntracks_overrides' in 'SELECT'
 *
 * Per the project rule "every feature schema must be wired into the install
 * pipeline", add the column via an idempotent migration so it exists on every
 * install regardless of endpoint order. Idempotent — safe to re-run; picked
 * up by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #80 — member.owntracks_overrides column\n";
echo "==========================================\n\n";

$have = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'member']);
if (!$have) {
    echo "member table absent — nothing to do.\n";
    return;
}

$hasCol = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'owntracks_overrides'",
    [$prefix . 'member']);

if ($hasCol) {
    echo "owntracks_overrides column already present — nothing to do.\n";
} else {
    try {
        db_query("ALTER TABLE `{$prefix}member` ADD COLUMN `owntracks_overrides` TEXT NULL");
        echo "Added member.owntracks_overrides TEXT NULL.\n";
    } catch (Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
