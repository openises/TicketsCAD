-- ============================================================================
-- Tickets NewUI v4.0 — Internal Messaging & HAS Broadcast Schema
--
-- Tables:
--   internal_messages    — Message headers (from, subject, body, priority)
--   message_recipients   — Per-recipient delivery tracking (read, deleted)
--
-- Safety:  Uses CREATE TABLE IF NOT EXISTS — idempotent, safe to re-run.
-- Engine:  InnoDB for transactional safety on inserts + FK support.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `internal_messages` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `message_recipients` (
    `id`         INT      AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT      NOT NULL,
    `to_user_id` INT      NOT NULL,
    `read_at`    DATETIME DEFAULT NULL,
    `deleted_at` DATETIME DEFAULT NULL,
    KEY `idx_mr_message`     (`message_id`),
    KEY `idx_mr_to_user`     (`to_user_id`),
    KEY `idx_mr_unread`      (`to_user_id`, `read_at`, `deleted_at`),
    KEY `idx_mr_deleted`     (`to_user_id`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
