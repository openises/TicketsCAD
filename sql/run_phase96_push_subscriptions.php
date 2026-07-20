<?php
/**
 * Phase 96 — Browser Web Push schema.
 *
 * Creates push_subscriptions table + seeds 4 settings:
 *   push_vapid_public_key   — empty; admin pastes after generating
 *   push_vapid_private_key  — empty; admin pastes after generating
 *   push_vapid_subject      — empty; admin sets mailto:owner@site
 *   push_enabled            — '0' by default
 *
 * Idempotent; safe to re-run. Existing settings rows are preserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 96 — Browser Web Push schema\n";
echo "===================================\n\n";

echo "  [1/2] push_subscriptions table... ";
try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}push_subscriptions` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`      INT NOT NULL,
            `channel`      ENUM('web', 'apns', 'fcm') NOT NULL DEFAULT 'web',
            `endpoint`     TEXT NOT NULL,
            `p256dh`       VARCHAR(255) NOT NULL,
            `auth`         VARCHAR(64)  NOT NULL,
            `device_label` VARCHAR(128) NULL,
            `user_agent`   VARCHAR(512) NULL,
            `filters_json` TEXT NULL COMMENT 'reserved for v2 per-user event filters',
            `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL,
            `last_error`   VARCHAR(255) NULL,
            UNIQUE KEY `uk_user_endpoint` (`user_id`, `endpoint`(255)),
            KEY `idx_user` (`user_id`),
            KEY `idx_channel` (`channel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "  [2/2] settings defaults... ";
$seeded = 0;
$defaults = [
    'push_vapid_public_key'  => '',
    'push_vapid_private_key' => '',
    'push_vapid_subject'     => '',
    'push_enabled'           => '0',
];
foreach ($defaults as $name => $val) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            [$name, $val]
        );
        if (db_insert_id() > 0) $seeded++;
    } catch (Exception $e) { /* table missing fallback skipped */ }
}
echo "OK ({$seeded} new)\n";

echo "\nPhase 96 schema complete.\n";
echo "Next: run tools/generate_vapid_keys.php to mint VAPID keys.\n";
echo "Paste them into Settings → Push Notifications, then flip\n";
echo "push_enabled to 1.\n";
