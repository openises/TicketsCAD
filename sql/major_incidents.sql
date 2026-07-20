-- ============================================================
-- Major Incidents — schema for linking multiple incidents
-- under a single coordinating "major incident" umbrella.
--
-- Uses newui_ prefix to avoid conflict with the legacy
-- major_incidents table from the tickets installation.
-- ============================================================

-- Main table: one row per major incident
CREATE TABLE IF NOT EXISTS `newui_major_incidents` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(255) NOT NULL,
    `description` TEXT         DEFAULT NULL,
    `commander`   INT          DEFAULT NULL COMMENT 'user_id of incident commander',
    `severity`    TINYINT      NOT NULL DEFAULT 0,
    `status`      ENUM('open','closed') NOT NULL DEFAULT 'open',
    `lat`         DOUBLE       DEFAULT NULL,
    `lng`         DOUBLE       DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `closed_at`   DATETIME     DEFAULT NULL,
    KEY `idx_status`     (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_commander`  (`commander`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link table: ties a ticket to a major incident
CREATE TABLE IF NOT EXISTS `newui_major_incident_links` (
    `id`        INT AUTO_INCREMENT PRIMARY KEY,
    `major_id`  INT      NOT NULL,
    `ticket_id` INT      NOT NULL,
    `linked_by` INT      DEFAULT NULL COMMENT 'user_id who created the link',
    `linked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_major_ticket` (`major_id`, `ticket_id`),
    KEY `idx_ticket_id` (`ticket_id`),
    KEY `idx_major_id`  (`major_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
