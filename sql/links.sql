-- ============================================================
-- External Links — Configurable link panel for NewUI
-- ============================================================

CREATE TABLE IF NOT EXISTS `external_links` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `title`       VARCHAR(128) NOT NULL,
    `url`         VARCHAR(512) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `icon`        VARCHAR(64)  NOT NULL DEFAULT 'bi-link-45deg',
    `category`    VARCHAR(64)  NOT NULL DEFAULT 'General',
    `sort_order`  INT          NOT NULL DEFAULT 0,
    `active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_by`  INT          DEFAULT NULL,
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_category` (`category`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
