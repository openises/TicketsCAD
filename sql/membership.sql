-- NewUI v4.0 - Membership / Roster Tables
-- Personnel tracking with certifications, teams, and contact info.
-- Simplified from legacy 65+ field member table to a clean schema.

CREATE TABLE IF NOT EXISTS `member_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6c757d' COMMENT 'Badge color hex',
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6c757d',
  `bg_color` varchar(7) DEFAULT '#ffffff',
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(64) NOT NULL,
  `last_name` varchar(64) NOT NULL,
  `middle_name` varchar(64) DEFAULT NULL,
  `member_type_id` int(11) DEFAULT NULL,
  `member_status_id` int(11) DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL,
  `callsign` varchar(24) DEFAULT NULL,
  `title` varchar(64) DEFAULT NULL COMMENT 'Position/title',
  `email` varchar(128) DEFAULT NULL,
  `phone_home` varchar(24) DEFAULT NULL,
  `phone_work` varchar(24) DEFAULT NULL,
  `phone_cell` varchar(24) DEFAULT NULL,
  `street` varchar(128) DEFAULT NULL,
  `city` varchar(64) DEFAULT NULL,
  `state` varchar(4) DEFAULT NULL,
  `zip` varchar(16) DEFAULT NULL,
  `lat` double DEFAULT NULL,
  `lng` double DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `membership_due` date DEFAULT NULL,
  `available` enum('Yes','No') DEFAULT 'Yes',
  `responder_id` int(11) DEFAULT NULL COMMENT 'Link to responder table',
  `user_id` int(11) DEFAULT NULL COMMENT 'Link to user account',
  `photo_url` varchar(255) DEFAULT NULL,
  `emergency_contact` varchar(128) DEFAULT NULL,
  `emergency_phone` varchar(24) DEFAULT NULL,
  `emergency_relation` varchar(64) DEFAULT NULL,
  `medical_info` text DEFAULT NULL COMMENT 'Allergies, medications, conditions',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_type_id` (`member_type_id`),
  KEY `member_status_id` (`member_status_id`),
  KEY `team_id` (`team_id`),
  KEY `last_name` (`last_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `team_type` varchar(64) DEFAULT NULL COMMENT 'RACES, CERT, Medical, Fire, etc.',
  `leader_id` int(11) DEFAULT NULL COMMENT 'Member ID of team leader',
  `deputy_id` int(11) DEFAULT NULL COMMENT 'Member ID of deputy',
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `certifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `required` tinyint(1) DEFAULT 0,
  `refresh_months` int(11) DEFAULT NULL COMMENT 'Months between refreshes, NULL=permanent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member_certifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `certification_id` int(11) NOT NULL,
  `earned_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `certification_id` (`certification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data.
-- Every seed below uses INSERT IGNORE so a re-run of membership.sql
-- (deliberately or as part of foundational-SQL re-import) leaves
-- existing rows untouched and adds no duplicates. This relies on the
-- UNIQUE KEY on `name` set on each target table's CREATE TABLE above
-- (member_types.name and member_status.name were added earlier in this
-- file; certifications.name was added 2026-07-03 alongside the equipment
-- fix; teams.name is in seed_demo_data / installer-managed elsewhere so
-- INSERT IGNORE here is defensive.)
INSERT IGNORE INTO `member_types` (`name`, `description`, `color`, `sort_order`) VALUES
('Full Member', 'Active full member', '#198754', 1),
('Associate', 'Associate/auxiliary member', '#0d6efd', 2),
('Trainee', 'Member in training', '#fd7e14', 3),
('Inactive', 'Inactive member', '#6c757d', 4),
('Alumni', 'Former member', '#adb5bd', 5);

INSERT IGNORE INTO `member_status` (`name`, `description`, `color`, `bg_color`, `sort_order`) VALUES
('Active', 'Active and available', '#198754', '#d1e7dd', 1),
('On Leave', 'Temporarily unavailable', '#fd7e14', '#fff3cd', 2),
('Suspended', 'Membership suspended', '#dc3545', '#f8d7da', 3),
('Retired', 'Retired from service', '#6c757d', '#e2e3e5', 4);

INSERT IGNORE INTO `teams` (`name`, `description`, `team_type`, `active`) VALUES
('Alpha Team', 'Primary response team', 'General', 1),
('Bravo Team', 'Secondary response team', 'General', 1),
('Medical Unit', 'Medical response specialists', 'Medical', 1),
('Communications', 'Radio and communications operators', 'RACES', 1);

INSERT IGNORE INTO `certifications` (`name`, `description`, `required`, `refresh_months`) VALUES
('CPR/First Aid', 'Basic CPR and First Aid certification', 1, 24),
('ICS-100', 'Introduction to Incident Command System', 1, NULL),
('ICS-200', 'ICS for Single Resources', 0, NULL),
('ICS-700', 'NIMS Introduction', 1, NULL),
('ICS-800', 'National Response Framework', 0, NULL),
('HAM Radio License', 'FCC Amateur Radio Technician or higher', 0, NULL),
('CERT Basic', 'Community Emergency Response Team basic training', 0, NULL),
('Hazmat Awareness', 'Hazardous Materials Awareness level', 0, 36),
('Defensive Driving', 'Emergency vehicle operations', 0, 24);
