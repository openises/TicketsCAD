-- NewUI v4.0 - Phase 5: Vehicles + Privacy Model
-- Fleet management with privacy controls on personal vehicle data.
-- Run AFTER membership.sql (depends on member table).

-- ── Vehicle Types ──
CREATE TABLE IF NOT EXISTS `newui_vehicle_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(32) DEFAULT 'bi-truck' COMMENT 'Bootstrap Icon class',
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vt_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Vehicles ──
CREATE TABLE IF NOT EXISTS `newui_vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL COMMENT 'Owner member ID',
  `vehicle_type_id` int(11) DEFAULT NULL,
  `callsign` varchar(24) DEFAULT NULL COMMENT 'Vehicle unit number/callsign',
  `year` smallint DEFAULT NULL,
  `make` varchar(64) DEFAULT NULL,
  `model` varchar(64) DEFAULT NULL,
  `color` varchar(32) DEFAULT NULL,
  `plate_number` varchar(16) DEFAULT NULL,
  `plate_state` varchar(4) DEFAULT NULL,
  `vin` varchar(20) DEFAULT NULL,
  `registration_exp` date DEFAULT NULL,
  `insurance_carrier` varchar(128) DEFAULT NULL,
  `insurance_policy` varchar(64) DEFAULT NULL,
  `insurance_exp` date DEFAULT NULL,
  `is_agency_vehicle` tinyint(1) DEFAULT 0 COMMENT '1=owned by org, 0=personal',
  `is_private` tinyint(1) DEFAULT 1 COMMENT '1=redact plate/VIN/insurance from non-owners',
  `status` enum('Active','Out of Service','Disposed') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `vehicle_type_id` (`vehicle_type_id`),
  KEY `callsign` (`callsign`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed Vehicle Types ──
-- INSERT IGNORE relies on the uk_vt_name UNIQUE key above so re-runs
-- are a true no-op instead of silently accumulating duplicates.
-- See GH issue #38 for the parallel equipment-types fix.
INSERT IGNORE INTO `newui_vehicle_types` (`name`, `description`, `icon`, `sort_order`) VALUES
('Personal Vehicle', 'Member-owned personal vehicle', 'bi-car-front', 1),
('Agency Vehicle', 'Organization-owned vehicle', 'bi-truck', 2),
('Emergency Vehicle', 'Emergency response vehicle (ambulance, fire truck)', 'bi-truck', 3),
('ATV/UTV', 'All-terrain or utility vehicle', 'bi-truck-front', 4),
('Trailer', 'Trailer or mobile command unit', 'bi-box-seam', 5),
('Boat', 'Watercraft', 'bi-water', 6),
('Bicycle', 'Bicycle or e-bike', 'bi-bicycle', 7),
('Other', 'Other vehicle type', 'bi-question-circle', 99);
