-- NewUI v4.0 - Phase 3: Teams + NIMS ICS Position Tracking
-- Adds ICS position reference, member qualifications, and team_members junction.
-- Run AFTER membership.sql (depends on member, teams, certifications tables).

-- ── ICS Positions Reference ──
-- Common NIMS/ICS position codes for qualification tracking.
CREATE TABLE IF NOT EXISTS `ics_positions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(16) NOT NULL COMMENT 'ICS position code (COML, IC, PSC, etc.)',
  `title` varchar(128) NOT NULL COMMENT 'Full position title',
  `category` varchar(32) DEFAULT NULL COMMENT 'Command, Operations, Planning, Logistics, Finance',
  `description` text DEFAULT NULL,
  `nims_typing_level` tinyint DEFAULT NULL COMMENT 'NIMS resource typing level 1-4',
  `required_certs` text DEFAULT NULL COMMENT 'JSON array of certification IDs',
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Member ICS Qualifications ──
-- Tracks which ICS positions a member is qualified for, with PTB status.
CREATE TABLE IF NOT EXISTS `member_ics_qualifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `ics_position_id` int(11) NOT NULL,
  `qualification_level` enum('Trainee','Qualified','Expert') DEFAULT 'Trainee',
  `ptb_status` enum('Not Started','In Progress','Completed') DEFAULT 'Not Started',
  `ptb_start_date` date DEFAULT NULL,
  `ptb_completion_date` date DEFAULT NULL,
  `evaluator` varchar(128) DEFAULT NULL COMMENT 'Name of evaluator/mentor',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_position` (`member_id`, `ics_position_id`),
  KEY `member_id` (`member_id`),
  KEY `ics_position_id` (`ics_position_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Team Members Junction ──
-- Many-to-many: members can belong to multiple teams with specific roles.
CREATE TABLE IF NOT EXISTS `team_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `role` varchar(64) DEFAULT 'Member' COMMENT 'Leader, Deputy, Member, Observer, etc.',
  `position_code` varchar(16) DEFAULT NULL COMMENT 'ICS position code for this team role',
  `assigned_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_member` (`team_id`, `member_id`),
  KEY `team_id` (`team_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ALTER teams table for NIMS fields ──
-- Safe ALTER: uses stored procedure to check column existence first.
DELIMITER //
DROP PROCEDURE IF EXISTS `add_nims_team_columns`//
CREATE PROCEDURE `add_nims_team_columns`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teams' AND COLUMN_NAME = 'nims_resource_type'
    ) THEN
        ALTER TABLE `teams`
            ADD COLUMN `nims_resource_type` varchar(64) DEFAULT NULL COMMENT 'NIMS resource type category',
            ADD COLUMN `nims_typing_level` tinyint DEFAULT NULL COMMENT 'NIMS typing level 1-4',
            ADD COLUMN `rtlt_code` varchar(32) DEFAULT NULL COMMENT 'FEMA RTLT code for API integration',
            ADD COLUMN `created_at` datetime DEFAULT NULL,
            ADD COLUMN `updated_at` datetime DEFAULT NULL;
    END IF;
END//
DELIMITER ;
CALL `add_nims_team_columns`();
DROP PROCEDURE IF EXISTS `add_nims_team_columns`;

-- ── Seed ICS Positions ──
-- ~15 common positions across ICS functional areas.
INSERT INTO `ics_positions` (`code`, `title`, `category`, `description`, `nims_typing_level`, `sort_order`) VALUES
('IC',     'Incident Commander',               'Command',    'Overall incident management authority. Responsible for all aspects of the response.', NULL, 1),
('IC1',    'Incident Commander Type 1',         'Command',    'Complex incident commander for Type 1 (most complex) incidents.', 1, 2),
('IC2',    'Incident Commander Type 2',         'Command',    'Incident commander for Type 2 incidents.', 2, 3),
('PSC',    'Public Information Officer',        'Command',    'Responsible for interfacing with the public and media.', NULL, 4),
('SOF1',   'Safety Officer',                    'Command',    'Monitors safety conditions and develops measures to assure personnel safety.', NULL, 5),
('LOFR',   'Liaison Officer',                   'Command',    'Point of contact for assisting and cooperating agencies.', NULL, 6),
('OSC',    'Operations Section Chief',          'Operations', 'Manages all tactical operations at the incident.', NULL, 10),
('DIVS',   'Division/Group Supervisor',         'Operations', 'Responsible for the implementation of the assigned portion of the IAP.', NULL, 11),
('PSC2',   'Planning Section Chief',            'Planning',   'Responsible for the collection, evaluation, and dissemination of incident information.', NULL, 20),
('RESL',   'Resources Unit Leader',             'Planning',   'Maintains status of all assigned resources at an incident.', NULL, 21),
('DMOB',   'Demobilization Unit Leader',        'Planning',   'Develops the Incident Demobilization Plan.', NULL, 22),
('LSC',    'Logistics Section Chief',           'Logistics',  'Provides facilities, services, and material support for the incident.', NULL, 30),
('COML',   'Communications Unit Leader',        'Logistics',  'Develops and implements the Incident Communications Plan (ICS 205).', NULL, 31),
('COMT',   'Communications Technician',         'Logistics',  'Provides technical radio/communications support under COML direction.', NULL, 32),
('AUXCOMM','Auxiliary Communicator',             'Logistics',  'Amateur radio operator providing supplemental communications support. ARES/RACES.', NULL, 33),
('THSP',   'Technical Specialist',              'Planning',   'Provides specialized knowledge or expertise (weather, HazMat, GIS, etc.).', NULL, 25),
('ORDM',   'Ordering Manager',                  'Finance',    'Places orders for personnel, equipment, and supplies.', NULL, 40),
('INCM',   'Incident Communications Manager',   'Logistics',  'Oversees all incident communications including interoperability.', NULL, 34)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- ── Migrate existing team_id data to team_members ──
-- Members with team_id set get a row in team_members for backward compatibility.
INSERT IGNORE INTO `team_members` (`team_id`, `member_id`, `role`, `assigned_date`)
SELECT `team_id`, `id`, 'Member', CURDATE()
FROM `member`
WHERE `team_id` IS NOT NULL AND `team_id` > 0;
