<?php
/**
 * Schema audit 2026-07-07 — zello_user_config column reconciliation
 *
 * api/zello-user.php (per-user Zello preferences) was written against
 * columns (user_id, zello_username, zello_password, enabled) that the
 * table's creating migration (run_zello_tables.php: user, ptt_key,
 * auto_connect, play_sounds, updated) never had — so per-user Zello
 * settings never saved on any install. Add the API's columns; the
 * legacy `user` column stays for anything that referenced it.
 *
 * Idempotent — picked up automatically by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "zello_user_config column reconciliation\n";
echo "=======================================\n\n";

$add = [
    'user_id'        => "INT NULL",
    'zello_username' => "VARCHAR(64) DEFAULT NULL",
    'zello_password' => "VARCHAR(190) DEFAULT NULL",
    'enabled'        => "TINYINT(1) NOT NULL DEFAULT 1",
];
foreach ($add as $col => $def) {
    try {
        $exists = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'zello_user_config', $col]
        );
        if ($exists) { echo "skip: $col present\n"; continue; }
        db_query("ALTER TABLE `{$prefix}zello_user_config` ADD COLUMN `$col` $def");
        echo "added: $col\n";
    } catch (Exception $e) {
        echo "ERR ($col): " . $e->getMessage() . "\n";
    }
}
// Unique per user so the API's existence-check UPDATE/INSERT pattern holds.
try {
    db_query("ALTER TABLE `{$prefix}zello_user_config` ADD UNIQUE KEY `uniq_user_id` (`user_id`)");
    echo "added: uniq_user_id index\n";
} catch (Exception $e) {
    echo "index: " . (stripos($e->getMessage(), 'Duplicate') !== false ? 'skip (exists)' : $e->getMessage()) . "\n";
}

echo "\nDone.\n";
