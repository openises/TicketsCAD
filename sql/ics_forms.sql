-- ICS Forms Schema
-- Creates the ics_forms table for storing ICS form data (213, 214, 202, 205, 205a, 213rr)
-- Engine: InnoDB (supports transactions, foreign keys)
-- Safety: Idempotent — uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `ics_forms` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `form_type`      VARCHAR(10)   NOT NULL DEFAULT '213' COMMENT '213, 214, 202, 205, 205a, 213rr',
    `incident_id`    INT           DEFAULT NULL COMMENT 'FK to ticket.id (nullable for standalone forms)',
    `title`          VARCHAR(255)  NOT NULL DEFAULT '',
    `form_data_json` MEDIUMTEXT    NOT NULL COMMENT 'JSON blob storing all form field values',
    `created_by`     INT           NOT NULL DEFAULT 0 COMMENT 'FK to user.id',
    `created_by_name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Denormalized user display name',
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `status`         VARCHAR(16)   NOT NULL DEFAULT 'draft' COMMENT 'draft, final, sent',
    KEY `idx_ics_form_type`    (`form_type`),
    KEY `idx_ics_incident_id`  (`incident_id`),
    KEY `idx_ics_created_at`   (`created_at`),
    KEY `idx_ics_status`       (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
