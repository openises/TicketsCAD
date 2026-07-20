-- Notification Rules & Preferences
-- Defines which events trigger notifications and who gets them.

CREATE TABLE IF NOT EXISTS `notification_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `event_type` ENUM('incident_create','incident_close','incident_status','unit_assign','unit_clear','severity_high','has_broadcast') NOT NULL,
    `severity_filter` TINYINT DEFAULT NULL COMMENT 'NULL = all, 0-2 = specific severity',
    `incident_type_filter` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = all, or in_types.id',
    `channel` ENUM('email','sms','local_chat','all') NOT NULL DEFAULT 'email',
    `recipients` TEXT COMMENT 'JSON array of user IDs, email addresses, or phone numbers',
    `email_list_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK to email distribution list',
    `subject_template` VARCHAR(255) DEFAULT '' COMMENT 'Subject line with {placeholders}',
    `body_template` TEXT COMMENT 'Body text with {placeholders}',
    `active` TINYINT NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_type` (`event_type`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `channel_email` TINYINT NOT NULL DEFAULT 1,
    `channel_sms` TINYINT NOT NULL DEFAULT 0,
    `channel_chat` TINYINT NOT NULL DEFAULT 1,
    `quiet_start` TIME DEFAULT NULL COMMENT 'Quiet hours start (e.g. 22:00)',
    `quiet_end` TIME DEFAULT NULL COMMENT 'Quiet hours end (e.g. 07:00)',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_id` INT UNSIGNED DEFAULT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `ticket_id` INT UNSIGNED DEFAULT NULL,
    `channel` VARCHAR(20) NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) DEFAULT '',
    `body` TEXT,
    `status` ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
    `error` TEXT DEFAULT NULL,
    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ticket` (`ticket_id`),
    KEY `idx_rule` (`rule_id`),
    KEY `idx_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
