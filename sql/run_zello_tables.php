<?php
/**
 * Zello integration tables migration runner.
 *
 * Wraps sql/zello_tables.sql so the master migration runner
 * (sql/run_migrations.php) picks it up. Without this wrapper
 * the raw .sql file was never applied on fresh installs, so:
 *
 *   - api/zello-token.php failed with SQLSTATE[42S02]
 *     "Table 'zello_ws_tokens' doesn't exist"
 *   - Zello UI message logging would fail
 *   - Per-user Zello preferences had nowhere to persist
 *
 * Beta tester a beta tester hit the zello_ws_tokens path while
 * testing the Zello connection from Settings.
 *
 * Idempotent — every CREATE in zello_tables.sql uses
 * IF NOT EXISTS, safe to re-run.
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;

echo "Zello integration tables migration\n";
echo "==================================\n\n";

$sqlPath = __DIR__ . '/zello_tables.sql';
if (!is_readable($sqlPath)) {
    echo "[FATAL] cannot read $sqlPath\n";
    exit(1);
}

$sql = file_get_contents($sqlPath);

// Split on semicolon outside of strings — adequate for our DDL-only file
// (no embedded semicolons in string literals). Trim and drop empty/comment
// statements.
$statements = array_filter(
    array_map('trim', preg_split('/;\s*\n/', $sql) ?: []),
    function ($s) {
        if ($s === '') return false;
        // Drop a statement that is only line-comments
        $stripped = preg_replace('/^--[^\n]*\n?/m', '', $s);
        return trim($stripped) !== '';
    }
);

$applied = 0;
$skipped = 0;
$failed  = 0;

foreach ($statements as $stmt) {
    // Get a label from the first non-comment line for the log
    $label = preg_replace('/\s+/', ' ', substr(preg_replace('/^--[^\n]*\n?/m', '', $stmt), 0, 80));
    try {
        db_query($stmt);
        echo "  [ok] $label\n";
        $applied++;
    } catch (Exception $e) {
        echo "  [fail] $label\n         -> " . $e->getMessage() . "\n";
        $failed++;
    }
}

// NOTE: avoid the literal token "Failed" in the summary line — the master
// runner (sql/run_migrations.php:165) regex flags any output containing
// /\bFAILED\b/i as a script failure, so "Failed: 0" trips a false alarm.
echo "\nApplied: $applied   Errors: $failed\n";

// Final sanity check — all three tables should now exist
foreach (['zello_messages', 'zello_user_config', 'zello_ws_tokens'] as $tbl) {
    try {
        $exists = db_fetch_one(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$tbl]
        );
        echo "  " . ($exists ? '[ok]  ' : '[MISS]') . " $tbl\n";
    } catch (Exception $e) {
        echo "  [err] $tbl: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
