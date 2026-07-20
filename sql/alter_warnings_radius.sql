-- Add radius column to warnings (warn-locations / WarnZones) for the
-- circular-area variant of warn locations. The save endpoint at
-- api/config-admin.php:1202 INSERTs radius along with title/street/etc,
-- but no migration ships the column — fresh installs hit
-- "Unknown column 'radius' in 'INSERT INTO'" on the first Save click.
-- Beta tester a beta tester 2026-06-26.
--
-- Idempotent: SET @exist + IF() guard skips the ALTER if the column
-- already exists. Safe to re-run.

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'warnings'
                 AND COLUMN_NAME = 'radius');

SET @sql := IF(@exist = 0,
    'ALTER TABLE `warnings` ADD COLUMN `radius` INT DEFAULT 500 AFTER `lng`',
    'SELECT "warnings.radius column already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
