-- ============================================================
-- Facility Bed/Capacity Tracking
-- Extends the facilities table with capacity categories
-- ============================================================

-- Add capacity columns to facilities if they don't exist
-- (Use run script for idempotent ALTER)

CREATE TABLE IF NOT EXISTS `newui_facility_capacity` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `facility_id`   INT NOT NULL,
    `category`      VARCHAR(64) NOT NULL COMMENT 'e.g., ICU, ER, General, Shelter Cots, Kennels',
    `total`         INT NOT NULL DEFAULT 0,
    `occupied`      INT NOT NULL DEFAULT 0,
    `available`     INT GENERATED ALWAYS AS (total - occupied) STORED,
    `status`        ENUM('open','full','closed') NOT NULL DEFAULT 'open',
    `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT DEFAULT NULL,
    KEY `idx_facility` (`facility_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed some capacity categories
INSERT IGNORE INTO `newui_facility_capacity` (facility_id, category, total, occupied, status)
SELECT f.id, 'General', 0, 0, 'open'
FROM `facilities` f
WHERE NOT EXISTS (
    SELECT 1 FROM `newui_facility_capacity` fc WHERE fc.facility_id = f.id
);
