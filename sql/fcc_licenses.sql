-- ═══════════════════════════════════════════════════════════════
-- FCC License Database (Amateur Radio + GMRS)
-- Sources:
--   Amateur: https://data.fcc.gov/download/pub/uls/complete/l_amat.zip
--   GMRS:    https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip
-- Import via: php tools/import-fcc.php <type> <zip-or-dir>
--   type = "amateur" or "gmrs"
-- ═══════════════════════════════════════════════════════════════

-- Amateur radio licenses (callsign-indexed)
CREATE TABLE IF NOT EXISTS `fcc_amateur` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `callsign`        VARCHAR(16)   NOT NULL,
    `oper_class`      VARCHAR(4)    DEFAULT NULL COMMENT 'T=Technician, G=General, E=Extra',
    `first_name`      VARCHAR(64)   DEFAULT NULL,
    `last_name`       VARCHAR(64)   DEFAULT NULL,
    `middle_initial`  VARCHAR(4)    DEFAULT NULL,
    `suffix`          VARCHAR(4)    DEFAULT NULL,
    `entity_name`     VARCHAR(200)  DEFAULT NULL COMMENT 'Club or entity name if not individual',
    `entity_type`     CHAR(2)       DEFAULT NULL COMMENT 'I=Individual, C=Club, etc.',
    `street`          VARCHAR(128)  DEFAULT NULL,
    `city`            VARCHAR(64)   DEFAULT NULL,
    `state`           VARCHAR(4)    DEFAULT NULL,
    `zip`             VARCHAR(16)   DEFAULT NULL,
    `frn`             VARCHAR(16)   DEFAULT NULL,
    `grant_date`      DATE          DEFAULT NULL,
    `expiry_date`     DATE          DEFAULT NULL,
    `last_action`     DATE          DEFAULT NULL,
    `lat`             DOUBLE        DEFAULT NULL,
    `lng`             DOUBLE        DEFAULT NULL,
    `grid_square`     VARCHAR(8)    DEFAULT NULL,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_callsign` (`callsign`),
    KEY `idx_last_name_zip` (`last_name`, `zip`),
    KEY `idx_frn` (`frn`),
    KEY `idx_state` (`state`),
    KEY `idx_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GMRS licenses (name-indexed, no unique callsigns per person)
CREATE TABLE IF NOT EXISTS `fcc_gmrs` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `callsign`        VARCHAR(16)   DEFAULT NULL,
    `first_name`      VARCHAR(64)   DEFAULT NULL,
    `last_name`       VARCHAR(64)   DEFAULT NULL,
    `middle_initial`  VARCHAR(4)    DEFAULT NULL,
    `suffix`          VARCHAR(4)    DEFAULT NULL,
    `entity_name`     VARCHAR(200)  DEFAULT NULL,
    `entity_type`     CHAR(2)       DEFAULT NULL,
    `street`          VARCHAR(128)  DEFAULT NULL,
    `city`            VARCHAR(64)   DEFAULT NULL,
    `state`           VARCHAR(4)    DEFAULT NULL,
    `zip`             VARCHAR(16)   DEFAULT NULL,
    `frn`             VARCHAR(16)   DEFAULT NULL,
    `grant_date`      DATE          DEFAULT NULL,
    `expiry_date`     DATE          DEFAULT NULL,
    `last_action`     DATE          DEFAULT NULL,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_callsign` (`callsign`),
    KEY `idx_last_name_zip` (`last_name`, `zip`),
    KEY `idx_name_search` (`last_name`, `first_name`, `zip`),
    KEY `idx_frn` (`frn`),
    KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
