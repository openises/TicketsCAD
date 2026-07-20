-- ═══════════════════════════════════════════════════════════════
-- Two-Factor Authentication (TOTP) Schema
-- Stores encrypted secrets, backup codes, and device remember tokens.
-- ═══════════════════════════════════════════════════════════════

-- User 2FA enrollment — one row per enrolled user
CREATE TABLE IF NOT EXISTS `user_tfa` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT NOT NULL,
    `secret_encrypted`    BLOB NOT NULL COMMENT 'AES-256-CBC encrypted Base32 TOTP secret',
    `backup_codes_json`   TEXT NOT NULL COMMENT 'AES-256-CBC encrypted JSON array of 8-digit backup codes',
    `confirmed`           TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=verified with TOTP code',
    `enrolled_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`        DATETIME DEFAULT NULL,
    UNIQUE KEY `uk_user_tfa_user` (`user_id`),
    KEY `idx_user_tfa_enrolled` (`enrolled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Device "remember me" tokens — multiple devices per user
CREATE TABLE IF NOT EXISTS `tfa_remember_tokens` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT NOT NULL,
    `token_hash`          VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of the cookie token value',
    `device_fingerprint`  VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of User-Agent + Accept-Language',
    `ip_address`          VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6 address',
    `user_agent`          VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'Raw User-Agent string for device display',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`          DATETIME NOT NULL,
    KEY `idx_tfa_remember_user` (`user_id`),
    KEY `idx_tfa_remember_token` (`token_hash`),
    KEY `idx_tfa_remember_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Config table for key-value settings (created if not exists)
CREATE TABLE IF NOT EXISTS `config` (
    `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2FA settings stored in the config table (INSERT IGNORE for idempotency)
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('tfa_enabled', '0');
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('tfa_required_roles', '[]');
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('tfa_trusted_cidrs', '["127.0.0.0/8","10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]');
INSERT IGNORE INTO `config` (`key`, `value`) VALUES ('tfa_remember_days', '30');

-- Note: user_agent column migration is handled in run_tfa.php (PHP-based)
-- because DELIMITER/PROCEDURE syntax doesn't work via PDO exec().
