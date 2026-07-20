-- NewUI v4.0 - Phase 6: Equipment Management
-- Equipment types, individual items, and checkout/checkin tracking.

-- Equipment Types (Radio, Medical, PPE, Tools, etc.)
CREATE TABLE IF NOT EXISTS `newui_equipment_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(32) DEFAULT 'bi-box' COMMENT 'Bootstrap Icon class',
  `requires_checkout` tinyint(1) DEFAULT 1 COMMENT '1=tracked checkout/checkin',
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Individual equipment items
CREATE TABLE IF NOT EXISTS `newui_equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_type_id` int(11) DEFAULT NULL,
  `name` varchar(128) NOT NULL COMMENT 'Item name/description',
  `serial_number` varchar(64) DEFAULT NULL,
  `asset_tag` varchar(32) DEFAULT NULL COMMENT 'Organization asset tag',
  `make` varchar(64) DEFAULT NULL,
  `model` varchar(64) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(10,2) DEFAULT NULL,
  `warranty_exp` date DEFAULT NULL,
  `condition` enum('New','Good','Fair','Poor','Out of Service','Disposed') DEFAULT 'Good',
  `assigned_member_id` int(11) DEFAULT NULL COMMENT 'Currently assigned to member',
  `assigned_team_id` int(11) DEFAULT NULL COMMENT 'Currently assigned to team',
  `location` varchar(128) DEFAULT NULL COMMENT 'Current storage location',
  `notes` text DEFAULT NULL,
  `status` enum('Available','Checked Out','In Repair','Lost','Disposed') DEFAULT 'Available',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_type_id` (`equipment_type_id`),
  KEY `assigned_member_id` (`assigned_member_id`),
  KEY `assigned_team_id` (`assigned_team_id`),
  KEY `asset_tag` (`asset_tag`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Equipment activity log (checkout, checkin, transfer, condition change)
CREATE TABLE IF NOT EXISTS `newui_equipment_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `equipment_id` int(11) NOT NULL,
  `action` enum('checkout','checkin','transfer','condition_change','repair','note') NOT NULL,
  `member_id` int(11) DEFAULT NULL COMMENT 'Member involved in action',
  `team_id` int(11) DEFAULT NULL COMMENT 'Team involved in action',
  `performed_by` int(11) DEFAULT NULL COMMENT 'User who performed the action',
  `previous_condition` varchar(32) DEFAULT NULL,
  `new_condition` varchar(32) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Equipment Types.
-- INSERT IGNORE relies on the UNIQUE KEY on `name` above so a re-run of
-- this file (via `php sql/run_equipment.php`) leaves existing rows
-- untouched and adds nothing new. If you WANT to update the icon or
-- sort_order on an existing type, edit the row in the UI (Config ->
-- Equipment Types) or issue an explicit UPDATE — do not repurpose the
-- seed for that.
INSERT IGNORE INTO `newui_equipment_types` (`name`, `description`, `icon`, `requires_checkout`, `sort_order`) VALUES
('Radio', 'Handheld and mobile radios', 'bi-broadcast', 1, 1),
('Medical', 'Medical equipment and supplies', 'bi-heart-pulse', 0, 2),
('PPE', 'Personal protective equipment', 'bi-shield-check', 1, 3),
('Tools', 'General tools and equipment', 'bi-tools', 1, 4),
('Communications', 'Antennas, repeaters, cables', 'bi-wifi', 1, 5),
('Electronics', 'Laptops, tablets, chargers', 'bi-laptop', 1, 6),
('Shelter', 'Tents, tarps, cots', 'bi-house', 0, 7),
('Signage', 'Signs, cones, barriers', 'bi-sign-stop', 0, 8),
('Generator', 'Portable generators and power', 'bi-lightning', 1, 9),
('Other', 'Other equipment', 'bi-question-circle', 0, 99);
