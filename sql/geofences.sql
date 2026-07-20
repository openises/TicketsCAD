-- ============================================================
-- Geofencing tables for NewUI v4.0
-- Run via: php sql/run_geofences.php
-- ============================================================

CREATE TABLE IF NOT EXISTS `geofences` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `markup_id`           INT          NOT NULL COMMENT 'FK to map_markups.id',
    `name`                VARCHAR(128) NOT NULL,
    `active`              TINYINT(1)   NOT NULL DEFAULT 1,
    `alert_on_enter`      TINYINT(1)   NOT NULL DEFAULT 1,
    `alert_on_exit`       TINYINT(1)   NOT NULL DEFAULT 1,
    `alert_channels_json` TEXT         DEFAULT NULL COMMENT 'JSON array of broker channel codes',
    `notify_users_json`   TEXT         DEFAULT NULL COMMENT 'JSON array of user IDs to notify',
    `created_by`          INT          NOT NULL DEFAULT 0,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_markup_id` (`markup_id`),
    KEY `idx_active`    (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `geofence_unit_state` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `geofence_id`     INT          NOT NULL,
    `unit_identifier` VARCHAR(128) NOT NULL,
    `state`           ENUM('inside','outside') NOT NULL DEFAULT 'outside',
    `entered_at`      DATETIME     DEFAULT NULL,
    `exited_at`       DATETIME     DEFAULT NULL,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_fence_unit` (`geofence_id`, `unit_identifier`),
    KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
