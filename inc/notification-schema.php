<?php
/**
 * Phase 155 (GH#144) - the ONE definition of the three notification tables'
 * lazily-created shape.
 *
 * The same tables used to be defined twice with different column types: the
 * installer imported sql/notification_rules.sql (ENUMs for notification_rules.
 * event_type and notification_log.status), while the engine's
 * _notification_ensure_tables() created them with VARCHARs when it found them
 * missing. What an install ACCEPTED therefore depended on how its table had come
 * to exist - an ENUM refuses an event name added later; a VARCHAR does not.
 *
 * Both the engine and sql/run_phase155_notification_rules.php now take their DDL
 * from here, and sql/notification_rules.sql carries the same final shape for
 * fresh installs (a test compares them), so the three can no longer drift.
 *
 * `{PREFIX}` is substituted by the caller.
 */

/** @return string[] CREATE TABLE IF NOT EXISTS statements */
function notification_schema_ddl(string $prefix = ''): array
{
    $sqls = [
        "CREATE TABLE IF NOT EXISTS `{PREFIX}notification_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL DEFAULT '',
            `event_type` VARCHAR(50) NOT NULL,
            `severity_filter` TINYINT DEFAULT NULL,
            `incident_type_filter` INT UNSIGNED DEFAULT NULL,
            `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
            `recipients` TEXT,
            `email_list_id` INT UNSIGNED DEFAULT NULL,
            `subject_template` VARCHAR(255) DEFAULT '',
            `body_template` TEXT,
            `once_per_incident` TINYINT NOT NULL DEFAULT 0,
            `active` TINYINT NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{PREFIX}notification_preferences` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `channel_email` TINYINT NOT NULL DEFAULT 1,
            `channel_sms` TINYINT NOT NULL DEFAULT 0,
            `channel_chat` TINYINT NOT NULL DEFAULT 1,
            `quiet_start` TIME DEFAULT NULL,
            `quiet_end` TIME DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{PREFIX}notification_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rule_id` INT UNSIGNED DEFAULT NULL,
            `event_type` VARCHAR(50) NOT NULL,
            `ticket_id` INT UNSIGNED DEFAULT NULL,
            `channel` VARCHAR(20) NOT NULL,
            `recipient` VARCHAR(255) NOT NULL,
            `subject` VARCHAR(255) DEFAULT '',
            `body` TEXT,
            `status` VARCHAR(20) NOT NULL DEFAULT 'sent',
            `error` TEXT DEFAULT NULL,
            `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `queue_id` INT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ticket` (`ticket_id`),
            KEY `idx_rule` (`rule_id`),
            KEY `idx_sent` (`sent_at`),
            KEY `idx_queue` (`queue_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    return array_map(static function ($s) use ($prefix) { return str_replace('{PREFIX}', $prefix, $s); }, $sqls);
}
