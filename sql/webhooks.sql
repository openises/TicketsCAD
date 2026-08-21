-- ============================================================
-- Outbound Webhooks Schema
-- ============================================================
-- Fires HTTP POST callbacks to external systems on CAD events.
-- Run via: php sql/run_webhooks.php
--
-- B20 (SPEC-STATUS.md, 2026-08-21): this file used to ALSO CREATE a bare
-- `webhooks` table. It had zero readers/writers anywhere in the app --
-- every live code path uses `webhook_subscriptions`
-- (sql/run_phase94_external_api.php) instead. Removed here so a fresh
-- install (tools/install_fresh.php's foundational-imports list) no longer
-- creates it; sql/run_webhooks_legacy_table_drop.php drops it on installs
-- that already have it, after confirming it is empty. Do not re-add the
-- CREATE TABLE below without re-reading the B20 writeup in
-- specs/SPEC-STATUS.md first.
-- ============================================================

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
    -- Stable idempotency key, shared across every retry and admin replay
    -- of one logical delivery (2026-08-02 replay-protection fix).
    `delivery_uid` VARCHAR(36)  DEFAULT NULL,
    `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wd_webhook_id` (`webhook_id`),
    KEY `idx_wd_event_type` (`event_type`),
    KEY `idx_wd_status` (`status`),
    KEY `idx_wd_delivery_uid` (`delivery_uid`),
    KEY `idx_wd_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
