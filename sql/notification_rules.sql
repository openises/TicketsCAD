-- Notification Rules & Preferences
-- Defines which events trigger notifications and who gets them.
--
-- Phase 155 (GH#144, 2026-10-02): the SAME final shape as
-- inc/notification-schema.php (the engine's lazily-created tables) and
-- sql/run_phase155_notification_rules.php (which brings an existing install's
-- tables to it). tests/test_notification_rules_migration.php compares them.
--   event_type    was an ENUM of seven values. Now VARCHAR(50): the API validates
--                 against inc/notification-events.php, and a new event needs no ALTER.
--   once_per_incident  new. 1 = this rule notifies only for the FIRST matching
--                 event on an incident (for example one page per incident, not one per unit).
--   notification_log.status  was an ENUM('sent','failed','skipped'). Now
--                 VARCHAR(20) and gains 'queued' (deliveries go through the
--                 notification queue and are sent by the scheduled sweep).
--   notification_log.queue_id  new. Links a log row to its pending_routed_messages
--                 row so the delivery log can show what really became of it.

CREATE TABLE IF NOT EXISTS `notification_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `event_type` VARCHAR(50) NOT NULL COMMENT 'one of inc/notification-events.php notification_event_ids()',
    `severity_filter` TINYINT DEFAULT NULL COMMENT 'NULL = all, otherwise exactly this severity value',
    `incident_type_filter` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = all, or in_types.id',
    -- GH #84 (2026-08-18): was ENUM('email','sms','local_chat','all'), which
    -- meant a rule could never target Slack/Telegram/push/APRS/DMR/Meshtastic/
    -- MeshCore/SMTP even though inc/broker.php's dispatch (broker_send()) is
    -- fully generic against every channel registered in inc/channels/*.php.
    -- Widened to a validated free-form code - the same shape sql/routing.sql
    -- already uses for message_routes.dest_channel ("Channel code or * for
    -- any") - rather than another rigid ENUM migration every time an adapter
    -- is added. 'all' is a reserved value meaning "every currently registered
    -- broker channel" (inc/notification-engine.php resolves it dynamically).
    -- api/notification-rules.php validates it against the registered channels.
    -- VARCHAR(20) matches notification_log.channel (below) and
    -- inc/notification-schema.php, comfortably covering every current channel
    -- code (longest today: 'local_chat' / 'meshtastic', 10 chars).
    `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
    `recipients` TEXT COMMENT 'JSON array of user:ID, email:ADDRESS, tel:NUMBER (legacy bare values still parse)',
    `email_list_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK to email distribution list',
    `subject_template` VARCHAR(255) DEFAULT '' COMMENT 'Subject line with {placeholders}',
    `body_template` TEXT COMMENT 'Body text with {placeholders}',
    `once_per_incident` TINYINT NOT NULL DEFAULT 0,
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
    `status` VARCHAR(20) NOT NULL DEFAULT 'sent' COMMENT 'queued, sent, failed or skipped',
    `error` TEXT DEFAULT NULL,
    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `queue_id` INT UNSIGNED DEFAULT NULL COMMENT 'pending_routed_messages.id for a queued delivery',
    PRIMARY KEY (`id`),
    KEY `idx_ticket` (`ticket_id`),
    KEY `idx_rule` (`rule_id`),
    KEY `idx_sent` (`sent_at`),
    KEY `idx_queue` (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
