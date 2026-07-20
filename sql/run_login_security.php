<?php
/**
 * Run Login Security — Create login attempt tracking and session tables.
 *
 * Purpose:  Creates login_attempts table for brute-force prevention and
 *           active_sessions table for session management. Seeds default
 *           lockout settings into the config table.
 * Usage:    php sql/run_login_security.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   [OK]/[WARN] per table and setting created.
 */
require_once __DIR__ . '/../config.php';

echo "Login Security Schema Setup\n";
echo "===========================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// 1. Create login_attempts table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}login_attempts` (
        `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
        `username`        VARCHAR(255)  NOT NULL,
        `ip_address`      VARCHAR(45)   DEFAULT NULL,
        `user_agent`      VARCHAR(512)  DEFAULT NULL,
        `success`         TINYINT(1)    NOT NULL DEFAULT 0,
        `failure_reason`  VARCHAR(64)   DEFAULT NULL,
        `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_username_created` (`username`, `created_at`),
        KEY `idx_ip_created` (`ip_address`, `created_at`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] login_attempts table ready\n";
} catch (Exception $e) {
    echo "[WARN] login_attempts: " . $e->getMessage() . "\n";
}

// 2. Create active_sessions table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}active_sessions` (
        `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT          NOT NULL,
        `session_id`  VARCHAR(128) NOT NULL,
        `ip_address`  VARCHAR(45)  DEFAULT NULL,
        `user_agent`  VARCHAR(512) DEFAULT NULL,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_active` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at`  DATETIME     NOT NULL,
        UNIQUE KEY `uk_session_id` (`session_id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_expires_at` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] active_sessions table ready\n";
} catch (Exception $e) {
    echo "[WARN] active_sessions: " . $e->getMessage() . "\n";
}

// 3. Seed default lockout settings into config table
$defaults = [
    'lockout_max_attempts'     => '5',
    'lockout_window_minutes'   => '15',
    'lockout_duration_minutes' => '30',
    'session_timeout_minutes'  => '480',
];

foreach ($defaults as $key => $value) {
    try {
        // Only insert if not already set
        $existing = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}config` WHERE `key` = ?",
            [$key]
        );
        if ((int) $existing === 0) {
            db_query(
                "INSERT INTO `{$prefix}config` (`key`, `value`) VALUES (?, ?)",
                [$key, $value]
            );
            echo "[OK] Setting '{$key}' = '{$value}' inserted\n";
        } else {
            echo "[OK] Setting '{$key}' already exists, skipped\n";
        }
    } catch (Exception $e) {
        echo "[WARN] Setting '{$key}': " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
