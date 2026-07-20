-- ============================================================
-- NewUI v4.0 — Active Sessions Schema
-- Tracks logged-in sessions for management and forced logout.
-- Safe to run multiple times (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `active_sessions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
