-- ═══════════════════════════════════════════════════════════════
-- US Zip Code Database
-- Source: GeoNames / SimpleMaps / zip-codes.com (free tier)
-- Import via: php tools/import-zipcodes.php <csv-file>
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `zipcodes` (
    `zip`       VARCHAR(10)   NOT NULL PRIMARY KEY,
    `city`      VARCHAR(64)   NOT NULL,
    `state`     VARCHAR(4)    NOT NULL COMMENT 'Two-letter state code',
    `county`    VARCHAR(64)   DEFAULT NULL,
    `lat`       DOUBLE        DEFAULT NULL,
    `lng`       DOUBLE        DEFAULT NULL,
    `timezone`  VARCHAR(48)   DEFAULT NULL,
    KEY `idx_city_state` (`city`, `state`),
    KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
