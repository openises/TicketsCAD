-- ============================================================
-- Outbound Webhooks Schema
-- ============================================================
-- Fires HTTP POST callbacks to external systems on CAD events.
-- Run via: php sql/run_webhooks.php
-- ============================================================

CREATE TABLE IF NOT EXISTS `webhooks` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `name`         VARCHAR(128)  NOT NULL DEFAULT '',
    `url`          VARCHAR(512)  NOT NULL,
    `secret`       VARCHAR(128)  NOT NULL DEFAULT '',
    `events_json`  TEXT          NOT NULL,
    `active`       TINYINT(1)   NOT NULL DEFAULT 1,
    `retry_max`    TINYINT      NOT NULL DEFAULT 3,
    `created_by`   INT          NOT NULL DEFAULT 0,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_webhooks_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `webhook_id`   INT          NOT NULL,
    `event_type`   VARCHAR(64)  NOT NULL DEFAULT '',
    `payload`      TEXT         NOT NULL,
    `http_status`  INT          DEFAULT NULL,
    `response_body` TEXT        DEFAULT NULL,
    `duration_ms`  INT          DEFAULT NULL,
    `attempt`      TINYINT     NOT NULL DEFAULT 1,
    `status`       VARCHAR(16) NOT NULL DEFAULT 'pending',
    `error`        VARCHAR(512) DEFAULT NULL,
    `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wd_webhook_id` (`webhook_id`),
    KEY `idx_wd_event_type` (`event_type`),
    KEY `idx_wd_status` (`status`),
    KEY `idx_wd_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
