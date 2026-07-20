-- Cross-Protocol Message Routing Engine
-- Routes messages between channels (Meshtastic, Zello, DMR, local chat, SMS, email)
-- based on configurable rules with filters and transformations.

CREATE TABLE IF NOT EXISTS `message_routes` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(100) NOT NULL,
    `description`      VARCHAR(255) NOT NULL DEFAULT '',
    `enabled`          TINYINT NOT NULL DEFAULT 1,
    `priority`         INT NOT NULL DEFAULT 100 COMMENT 'Lower = evaluated first',
    `source_channel`   VARCHAR(64) NOT NULL COMMENT 'Channel code or * for any',
    `dest_channel`     VARCHAR(64) NOT NULL COMMENT 'Target channel code',
    `direction`        ENUM('inbound','outbound','both') NOT NULL DEFAULT 'both',
    `filters_json`     TEXT DEFAULT NULL COMMENT 'JSON: incident_type_ids, severity_min, priority_in, sender_roles, keywords, exclude_keywords',
    `transform_json`   TEXT DEFAULT NULL COMMENT 'JSON: prefix, override_priority',
    `created_by`       INT UNSIGNED DEFAULT NULL,
    `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_enabled`  (`enabled`),
    KEY `idx_source`   (`source_channel`),
    KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `routing_log` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `route_id`          INT UNSIGNED NOT NULL,
    `source_channel`    VARCHAR(64) NOT NULL,
    `dest_channel`      VARCHAR(64) NOT NULL,
    `source_message_id` INT UNSIGNED DEFAULT NULL,
    `dest_message_id`   INT UNSIGNED DEFAULT NULL,
    `status`            ENUM('forwarded','failed','skipped','loop_blocked') NOT NULL DEFAULT 'forwarded',
    `error`             TEXT DEFAULT NULL,
    `payload_summary`   VARCHAR(500) DEFAULT '',
    `routed_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_route`     (`route_id`),
    KEY `idx_routed`    (`routed_at`),
    KEY `idx_source_msg` (`source_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
