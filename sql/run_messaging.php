<?php
/**
 * Run Messaging — Create internal messaging and HAS broadcast tables.
 *
 * Purpose:  Creates internal_messages and message_recipients tables for
 *           persistent internal messaging and HAS broadcast alerts.
 * Usage:    php sql/run_messaging.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   [OK]/[WARN] per table creation.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Internal Messaging Schema Setup ===\n\n";

// ── internal_messages table ────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}internal_messages` (
        `id`           INT          AUTO_INCREMENT PRIMARY KEY,
        `from_user_id` INT          NOT NULL,
        `subject`      VARCHAR(255) NOT NULL DEFAULT '',
        `body`         TEXT         NOT NULL,
        `priority`     ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
        `incident_id`  INT          DEFAULT NULL,
        `is_broadcast` TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_im_from_user` (`from_user_id`),
        KEY `idx_im_created`   (`created_at`),
        KEY `idx_im_incident`  (`incident_id`),
        KEY `idx_im_broadcast` (`is_broadcast`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] internal_messages table\n";
} catch (Exception $e) { echo "[WARN] " . $e->getMessage() . "\n"; }

// ── message_recipients table ───────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}message_recipients` (
        `id`         INT      AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT      NOT NULL,
        `to_user_id` INT      NOT NULL,
        `read_at`    DATETIME DEFAULT NULL,
        `deleted_at` DATETIME DEFAULT NULL,
        KEY `idx_mr_message`     (`message_id`),
        KEY `idx_mr_to_user`     (`to_user_id`),
        KEY `idx_mr_unread`      (`to_user_id`, `read_at`, `deleted_at`),
        KEY `idx_mr_deleted`     (`to_user_id`, `deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] message_recipients table\n";
} catch (Exception $e) { echo "[WARN] " . $e->getMessage() . "\n"; }

echo "\nDone.\n";
