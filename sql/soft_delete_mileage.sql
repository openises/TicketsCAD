-- ============================================================
-- NewUI v4.0 — Soft Delete + Mileage Log Schema
-- Adds deleted_at columns for recoverable deletes and
-- a mileage_log table for mobile unit mileage tracking.
--
-- Safe to run multiple times (all statements are guarded).
-- ============================================================

-- ── Soft-delete columns ─────────────────────────────────────

-- member table
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member' AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `member` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member' AND COLUMN_NAME = 'deleted_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `member` ADD COLUMN `deleted_by` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- responder table
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'responder' AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `responder` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'responder' AND COLUMN_NAME = 'deleted_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `responder` ADD COLUMN `deleted_by` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ticket table
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket' AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `ticket` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket' AND COLUMN_NAME = 'deleted_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `ticket` ADD COLUMN `deleted_by` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- facilities table
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facilities' AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `facilities` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facilities' AND COLUMN_NAME = 'deleted_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `facilities` ADD COLUMN `deleted_by` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Indexes for soft-delete queries ─────────────────────────

CREATE INDEX IF NOT EXISTS idx_member_deleted ON `member` (`deleted_at`);
CREATE INDEX IF NOT EXISTS idx_responder_deleted ON `responder` (`deleted_at`);
CREATE INDEX IF NOT EXISTS idx_ticket_deleted ON `ticket` (`deleted_at`);
CREATE INDEX IF NOT EXISTS idx_facilities_deleted ON `facilities` (`deleted_at`);

-- ── Mileage log table ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS `mileage_log` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `responder_id`  INT NOT NULL,
    `user_id`       INT NOT NULL,
    `ticket_id`     INT DEFAULT NULL,
    `start_odo`     DECIMAL(10,1) DEFAULT NULL COMMENT 'Odometer at trip start',
    `end_odo`       DECIMAL(10,1) DEFAULT NULL COMMENT 'Odometer at trip end',
    `miles`         DECIMAL(10,1) GENERATED ALWAYS AS (CASE WHEN end_odo IS NOT NULL AND start_odo IS NOT NULL THEN end_odo - start_odo ELSE NULL END) STORED,
    `started_at`    DATETIME NOT NULL,
    `ended_at`      DATETIME DEFAULT NULL,
    `notes`         VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mileage_responder (`responder_id`),
    INDEX idx_mileage_user (`user_id`),
    INDEX idx_mileage_ticket (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
