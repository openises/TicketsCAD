-- NewUI v4.0 - Equipment Clothing/Uniform Extension
-- Adds size column to equipment and Clothing/Uniform equipment type.

-- Add size column (for clothing items)
-- Idempotent: check if column exists first
SET @col_exists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'newui_equipment'
      AND COLUMN_NAME = 'size');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `newui_equipment` ADD COLUMN `size` varchar(8) DEFAULT NULL COMMENT ''Clothing size (XS, S, M, L, XL, 2XL, 3XL)'' AFTER `model`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add Clothing/Uniform equipment type (idempotent via INSERT IGNORE-style)
INSERT INTO `newui_equipment_types` (`name`, `description`, `icon`, `requires_checkout`, `sort_order`)
SELECT 'Clothing/Uniform', 'Uniforms, vests, jackets, boots', 'bi-person-badge', 1, 10
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `newui_equipment_types` WHERE `name` = 'Clothing/Uniform'
);
