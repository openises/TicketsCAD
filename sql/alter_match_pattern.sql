-- Add regex match_pattern column to in_types for auto-type detection
-- Safe to run multiple times (checks IF NOT EXISTS via procedure)

-- MySQL doesn't have ALTER TABLE IF NOT EXISTS for columns, so check first
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'in_types'
               AND COLUMN_NAME = 'match_pattern');

SET @sql := IF(@exist = 0,
    'ALTER TABLE `in_types` ADD COLUMN `match_pattern` TEXT DEFAULT NULL',
    'SELECT "match_pattern column already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
