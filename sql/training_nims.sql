-- NewUI v4.0 - Phase 4: Training + Certifications Enhancement
-- Adds FEMA IS course tracking, training records, enhanced certification fields.
-- Run AFTER membership.sql (depends on certifications, member_certifications tables).

-- ── ALTER certifications for FEMA/NIMS fields ──
-- Safe ALTER: uses stored procedure to check column existence first.
DELIMITER //
DROP PROCEDURE IF EXISTS `add_cert_nims_columns`//
CREATE PROCEDURE `add_cert_nims_columns`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certifications' AND COLUMN_NAME = 'category'
    ) THEN
        ALTER TABLE `certifications`
            ADD COLUMN `category` varchar(64) DEFAULT NULL COMMENT 'FEMA IS, CPR/Medical, Radio, HAZMAT, etc.' AFTER `description`,
            ADD COLUMN `fema_course_code` varchar(32) DEFAULT NULL COMMENT 'IS-100, IS-200, etc.' AFTER `category`,
            ADD COLUMN `nims_credential_type` varchar(64) DEFAULT NULL COMMENT 'NIMS credential category' AFTER `fema_course_code`;
    END IF;
END//
DELIMITER ;
CALL `add_cert_nims_columns`();
DROP PROCEDURE IF EXISTS `add_cert_nims_columns`;

-- ── ALTER member_certifications for certificate tracking ──
DELIMITER //
DROP PROCEDURE IF EXISTS `add_member_cert_columns`//
CREATE PROCEDURE `add_member_cert_columns`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_certifications' AND COLUMN_NAME = 'certificate_number'
    ) THEN
        ALTER TABLE `member_certifications`
            ADD COLUMN `certificate_number` varchar(64) DEFAULT NULL COMMENT 'Certificate/license number' AFTER `expiry_date`,
            ADD COLUMN `issuing_authority` varchar(128) DEFAULT NULL COMMENT 'Organization that issued the cert' AFTER `certificate_number`,
            ADD COLUMN `verification_url` varchar(512) DEFAULT NULL COMMENT 'URL to verify credential online' AFTER `issuing_authority`;
    END IF;
END//
DELIMITER ;
CALL `add_member_cert_columns`();
DROP PROCEDURE IF EXISTS `add_member_cert_columns`;

-- ── Training Records ──
-- Tracks individual training events (drills, exercises, courses, workshops, OJT).
CREATE TABLE IF NOT EXISTS `training_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `training_name` varchar(255) NOT NULL COMMENT 'Name/title of the training',
  `training_type` enum('Course','Drill','Exercise','Workshop','OJT','Webinar','Self-Study') DEFAULT 'Course',
  `training_date` date DEFAULT NULL,
  `hours` decimal(5,1) DEFAULT NULL COMMENT 'Training hours',
  `location` varchar(255) DEFAULT NULL,
  `instructor` varchar(128) DEFAULT NULL,
  `result` enum('Completed','Incomplete','Failed','In Progress') DEFAULT 'Completed',
  `fema_course_code` varchar(32) DEFAULT NULL COMMENT 'FEMA IS course code if applicable',
  `certificate_number` varchar(64) DEFAULT NULL COMMENT 'Certificate or completion number',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `training_date` (`training_date`),
  KEY `fema_course_code` (`fema_course_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Update existing certification seed data with FEMA fields ──
-- Add category and fema_course_code to existing certifications where applicable.
UPDATE `certifications` SET `category` = 'CPR/Medical', `fema_course_code` = NULL
WHERE `name` = 'CPR/First Aid' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'FEMA IS', `fema_course_code` = 'IS-100.c'
WHERE `name` = 'ICS-100' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'FEMA IS', `fema_course_code` = 'IS-200.c'
WHERE `name` = 'ICS-200' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'FEMA IS', `fema_course_code` = 'IS-700.b'
WHERE `name` = 'ICS-700' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'FEMA IS', `fema_course_code` = 'IS-800.d'
WHERE `name` = 'ICS-800' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'Radio', `fema_course_code` = NULL
WHERE `name` = 'HAM Radio License' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'Emergency Mgmt', `fema_course_code` = NULL
WHERE `name` = 'CERT Basic' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'HAZMAT', `fema_course_code` = NULL
WHERE `name` = 'Hazmat Awareness' AND (`category` IS NULL OR `category` = '');

UPDATE `certifications` SET `category` = 'Driving', `fema_course_code` = NULL
WHERE `name` = 'Defensive Driving' AND (`category` IS NULL OR `category` = '');

-- ── Seed additional FEMA IS courses ──
-- INSERT IGNORE relies on the UNIQUE KEY certifications(name) added in
-- membership.sql on 2026-07-03. Prior to that fix, this INSERT used
-- ON DUPLICATE KEY UPDATE against a table that had no matching UNIQUE
-- constraint, so every re-run silently duplicated the entire seed —
-- your-server.example.com had 84 rows from just this block (7x dupes)
-- before the fix. See run_dedupe_certifications.php for cleanup.
--
-- The four foundational IS-100.c / IS-200.c / IS-700.b / IS-800.d
-- courses ARE added here as first-class rows (2026-07-03) rather than
-- being applied as fema_course_code UPDATE labels on legacy "ICS-100"
-- rows above — installs that seeded from a legacy base_schema import
-- (which had no ICS-100/200/700/800 rows at all) were missing these
-- modern-named entries entirely on the FEMA Courses reference panel.
INSERT IGNORE INTO `certifications` (`name`, `description`, `required`, `refresh_months`, `category`, `fema_course_code`, `nims_credential_type`) VALUES
('IS-100.c Introduction to the Incident Command System', 'FEMA ICS-100 (modern revision c)', 1, NULL, 'FEMA IS', 'IS-100.c', NULL),
('IS-200.c Basic Incident Command System for Initial Response', 'FEMA ICS-200 (modern revision c)', 0, NULL, 'FEMA IS', 'IS-200.c', NULL),
('IS-700.b An Introduction to the National Incident Management System', 'FEMA NIMS Introduction (modern revision b)', 1, NULL, 'FEMA IS', 'IS-700.b', NULL),
('IS-800.d National Response Framework, An Introduction', 'FEMA NRF Introduction (modern revision d)', 0, NULL, 'FEMA IS', 'IS-800.d', NULL),
('IS-2200 AUXCOMM', 'Basic Auxiliary Communications course — ARES/RACES operators', 0, NULL, 'FEMA IS', 'IS-2200', 'Auxiliary Communicator'),
('IS-120.c An Introduction to Exercises', 'Introduction to emergency management exercises', 0, NULL, 'FEMA IS', 'IS-120.c', NULL),
('IS-230.e Fundamentals of Emergency Management', 'Fundamentals of emergency management', 0, NULL, 'FEMA IS', 'IS-230.e', NULL),
('IS-235.c Emergency Planning', 'Emergency planning principles', 0, NULL, 'FEMA IS', 'IS-235.c', NULL),
('IS-240.c Leadership and Influence', 'Leadership and influence in emergency management', 0, NULL, 'FEMA IS', 'IS-240.c', NULL),
('IS-241.c Decision Making and Problem Solving', 'Decision-making and problem-solving skills', 0, NULL, 'FEMA IS', 'IS-241.c', NULL),
('IS-242.c Effective Communication', 'Effective communication skills for EM', 0, NULL, 'FEMA IS', 'IS-242.c', NULL),
('IS-244.b Developing and Managing Volunteers', 'Developing and managing volunteers', 0, NULL, 'FEMA IS', 'IS-244.b', NULL),
('IS-300 Intermediate ICS', 'Intermediate ICS for expanding incidents', 0, NULL, 'FEMA IS', 'IS-300', 'ICS Intermediate'),
('IS-400 Advanced ICS', 'Advanced ICS for complex incidents', 0, NULL, 'FEMA IS', 'IS-400', 'ICS Advanced'),
('SKYWARN Spotter Training', 'NWS SKYWARN storm spotter basic training', 0, 24, 'Weather', NULL, NULL),
('ARES/RACES Basic', 'ARRL ARES/RACES basic qualification', 0, NULL, 'Radio', NULL, NULL);
