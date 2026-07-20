-- ============================================================
-- member_callsigns — Multi-callsign support (Google Contacts style)
-- Supports amateur, GMRS, and any future license types
-- ============================================================

CREATE TABLE IF NOT EXISTS `member_callsigns` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`     INT NOT NULL,
    `callsign`      VARCHAR(16) NOT NULL,
    `license_type`  VARCHAR(32) NOT NULL DEFAULT 'amateur',
    `oper_class`    VARCHAR(16) DEFAULT NULL,
    `frn`           VARCHAR(16) DEFAULT NULL,
    `grant_date`    DATE DEFAULT NULL,
    `expiry_date`   DATE DEFAULT NULL,
    `grid_square`   VARCHAR(8) DEFAULT NULL,
    `is_primary`    TINYINT(1) NOT NULL DEFAULT 0,
    `source`        VARCHAR(32) DEFAULT NULL,
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member` (`member_id`),
    UNIQUE KEY `uq_member_call` (`member_id`, `callsign`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
