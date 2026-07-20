-- ============================================================
-- NewUI v4.0 — Login Security Schema
-- Login attempt tracking and account lockout support.
-- Safe to run multiple times (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
    `username`        VARCHAR(255)  NOT NULL,
    `ip_address`      VARCHAR(45)   DEFAULT NULL,
    `user_agent`      VARCHAR(512)  DEFAULT NULL,
    `success`         TINYINT(1)    NOT NULL DEFAULT 0,
    `failure_reason`  VARCHAR(64)   DEFAULT NULL COMMENT 'wrong_password, account_locked, 2fa_failed, account_disabled',
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Fast lockout check: recent failures by username
    KEY `idx_username_created` (`username`, `created_at`),

    -- IP-based rate limiting
    KEY `idx_ip_created` (`ip_address`, `created_at`),

    -- Cleanup old records
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default lockout settings (inserted only if they don't already exist)
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('lockout_max_attempts',      '5');
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('lockout_window_minutes',    '15');
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('lockout_duration_minutes',  '30');
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('session_timeout_minutes',   '480');
