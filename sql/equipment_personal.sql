-- NewUI v4.0 - Equipment: Add personal equipment support
-- Adds ownership tracking so volunteers can list their own equipment.
-- Organization equipment = tracked checkout/checkin, asset tags
-- Personal equipment = volunteer-owned, listed for availability/lost+found

-- Add ownership and owner columns
DROP PROCEDURE IF EXISTS alter_equip_ownership;
DELIMITER //
CREATE PROCEDURE alter_equip_ownership()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'newui_equipment'
        AND COLUMN_NAME = 'ownership') THEN
        ALTER TABLE `newui_equipment`
            ADD COLUMN `ownership` ENUM('organization','personal') DEFAULT 'organization'
                COMMENT 'organization=agency owned, personal=volunteer owned' AFTER `equipment_type_id`,
            ADD COLUMN `owner_member_id` INT(11) DEFAULT NULL
                COMMENT 'Volunteer who owns this (for personal equipment)' AFTER `ownership`,
            ADD COLUMN `available_for_events` TINYINT(1) DEFAULT 0
                COMMENT '1=owner has marked this available for org use' AFTER `owner_member_id`,
            ADD KEY `owner_member_id` (`owner_member_id`),
            ADD KEY `ownership` (`ownership`);
    END IF;
END //
DELIMITER ;
CALL alter_equip_ownership();
DROP PROCEDURE IF EXISTS alter_equip_ownership;
