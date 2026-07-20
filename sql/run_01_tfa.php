<?php
/**
 * Run TFA — Create two-factor authentication schema and seed settings.
 *
 * Purpose:  Creates user_tfa and tfa_remember_tokens tables, and seeds
 *           default 2FA settings (enforcement mode, remember duration,
 *           trusted networks) into the config table.
 * Usage:    php sql/run_tfa.php
 * Prerequisites: config.php with valid database credentials; tfa.sql in
 *                same directory.
 * Safety:   Idempotent. All statements use IF NOT EXISTS / INSERT IGNORE.
 *           Safe to run multiple times.
 * Output:   [OK]/[SKIP]/[ERR] per SQL statement.
 */

require_once __DIR__ . '/../config.php';

echo "=== Two-Factor Authentication Schema Migration ===\n\n";

$sqlFile = __DIR__ . '/tfa.sql';

if (!file_exists($sqlFile)) {
    echo "ERROR: tfa.sql not found at {$sqlFile}\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);

// Split into individual statements (skip empty lines and comments)
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function ($s) {
        // Keep only non-empty, non-comment-only statements
        $s = trim($s);
        if ($s === '') return false;
        // Remove comment-only lines
        $lines = explode("\n", $s);
        $hasCode = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, '--') !== 0) {
                $hasCode = true;
                break;
            }
        }
        return $hasCode;
    }
);

$success = 0;
$errors = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;

    // Extract a short description for output
    if (preg_match('/CREATE TABLE IF NOT EXISTS `(\w+)`/i', $stmt, $m)) {
        $desc = "CREATE TABLE {$m[1]}";
    } elseif (preg_match('/INSERT IGNORE INTO `(\w+)`.*?VALUES\s*\(\s*\'([^\']+)\'/i', $stmt, $m)) {
        $desc = "INSERT config: {$m[2]}";
    } else {
        $desc = substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . '...';
    }

    try {
        db()->exec($stmt);
        echo "  OK  {$desc}\n";
        $success++;
    } catch (PDOException $e) {
        // Ignore "already exists" errors for idempotency
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  SKIP {$desc} (already exists)\n";
        } elseif (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            echo "  SKIP {$desc} (already set)\n";
        } else {
            echo "  FAIL {$desc}: {$e->getMessage()}\n";
            $errors++;
        }
    }
}

// Add user_agent column if it doesn't exist (PHP-based alternative to stored procedure)
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `tfa_remember_tokens` WHERE `Field` = 'user_agent'");
    if (empty($cols)) {
        db()->exec("ALTER TABLE `tfa_remember_tokens` ADD COLUMN `user_agent` VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'Raw User-Agent string for device display' AFTER `ip_address`");
        echo "  OK  ALTER TABLE tfa_remember_tokens ADD user_agent\n";
        $success++;
    } else {
        echo "  SKIP user_agent column already exists\n";
    }
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
        echo "  SKIP user_agent column (already exists)\n";
    } else {
        echo "  FAIL user_agent column: {$e->getMessage()}\n";
        $errors++;
    }
}

echo "\nDone. {$success} executed, {$errors} errors.\n";

if ($errors > 0) {
    exit(1);
}
