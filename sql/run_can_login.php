<?php
/**
 * Run Can Login — Add can_login column to the user table.
 *
 * Purpose:  Adds a `can_login` TINYINT(1) column to the user table so
 *           administrators can disable individual CAD user accounts.
 * Usage:    php sql/run_can_login.php
 * Prerequisites: config.php with valid database credentials; user table.
 * Safety:   Idempotent. Checks information_schema before ALTER. Safe to re-run.
 * Output:   [OK] if column added, [SKIP] if already exists, [WARN] on error.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}user' AND COLUMN_NAME = 'can_login'"
    );
    if (empty($cols)) {
        db_query("ALTER TABLE `{$prefix}user` ADD COLUMN `can_login` TINYINT(1) NOT NULL DEFAULT 1");
        echo "[OK] Added can_login column to user table\n";
    } else {
        echo "[SKIP] user.can_login already exists\n";
    }
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

echo "Done.\n";
