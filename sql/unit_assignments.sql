-- ============================================================
-- Unit Personnel Assignments — Schema
-- Tracks which personnel are assigned to which units,
-- with time-based assignments and role designations.
--
-- Key concepts:
--   - A member can be assigned to multiple units
--   - A unit can have multiple members
--   - Assignments have start/end times (shift-based or standing)
--   - When a member is assigned to a unit, the unit's location
--     becomes the member's location and vice versa
-- ============================================================

-- ── Unit-Personnel Assignments ──────────────────────────────

CREATE TABLE IF NOT EXISTS `unit_personnel_assignments` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `responder_id`  INT NOT NULL COMMENT 'FK to responder (the unit)',
    `member_id`     INT NOT NULL COMMENT 'FK to member (the person)',
    `role`          VARCHAR(32) NOT NULL DEFAULT 'operator' COMMENT 'operator, driver, observer, commander, medic',
    `status`        ENUM('active','standby','released') NOT NULL DEFAULT 'active',
    `assigned_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `released_at`   DATETIME DEFAULT NULL COMMENT 'NULL = still assigned',
    `assigned_by`   INT DEFAULT NULL COMMENT 'User ID who made the assignment',
    `notes`         VARCHAR(255) DEFAULT NULL,
    KEY `idx_upa_responder` (`responder_id`),
    KEY `idx_upa_member`    (`member_id`),
    KEY `idx_upa_status`    (`status`),
    KEY `idx_upa_active`    (`responder_id`, `status`, `released_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Unit Assignment Roles (configurable) ────────────────────

CREATE TABLE IF NOT EXISTS `unit_assignment_roles` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(32) NOT NULL UNIQUE,
    `name`        VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `sort_order`  INT NOT NULL DEFAULT 50,
    `active`      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `unit_assignment_roles` (`code`, `name`, `description`, `sort_order`) VALUES
('commander',  'Commander/Officer', 'Unit commander or officer in charge',  10),
('operator',   'Operator',          'Primary unit operator',                20),
('driver',     'Driver',            'Vehicle driver',                       30),
('medic',      'Medic/EMT',         'Medical personnel',                   40),
('observer',   'Observer',          'Spotter or observer role',             50),
('trainee',    'Trainee',           'Personnel in training',               60),
('support',    'Support',           'General support role',                 70);


-- ============================================================
-- Location Provider Staleness Thresholds
-- Adds per-provider max_age_seconds to location_providers table.
-- If a report is older than max_age_seconds, it is considered stale
-- and the system falls through to the next priority provider.
-- ============================================================

-- Add staleness threshold column if not exists
-- Default: 300 seconds (5 minutes) — customizable per provider
ALTER TABLE `location_providers`
    ADD COLUMN IF NOT EXISTS `max_age_seconds` INT NOT NULL DEFAULT 300
    COMMENT 'Reports older than this are considered stale; system falls through to next provider';

-- Set sensible defaults per provider type
UPDATE `location_providers` SET `max_age_seconds` = 600  WHERE `code` = 'aprs'       AND `max_age_seconds` = 300;
UPDATE `location_providers` SET `max_age_seconds` = 300  WHERE `code` = 'meshtastic'  AND `max_age_seconds` = 300;
UPDATE `location_providers` SET `max_age_seconds` = 5400 WHERE `code` = 'owntracks'   AND `max_age_seconds` = 300;
UPDATE `location_providers` SET `max_age_seconds` = 600  WHERE `code` = 'opengts'     AND `max_age_seconds` = 300;
UPDATE `location_providers` SET `max_age_seconds` = 900  WHERE `code` = 'dmr'         AND `max_age_seconds` = 300;
UPDATE `location_providers` SET `max_age_seconds` = 60   WHERE `code` = 'internal'    AND `max_age_seconds` = 300;
UPDATE `location_providers` SET `max_age_seconds` = 3600 WHERE `code` = 'google_lat'  AND `max_age_seconds` = 300;
