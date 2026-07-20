-- ═══════════════════════════════════════════════════════════
--  Phase 7: Scheduling & Shift Management
-- ═══════════════════════════════════════════════════════════

-- Shift templates: named rotation patterns (e.g., "Skywarn 4-Week Rotation")
CREATE TABLE IF NOT EXISTS `newui_shift_templates` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(128)  NOT NULL,
  `description`     TEXT          DEFAULT NULL,
  `rotation_weeks`  INT           NOT NULL DEFAULT 1 COMMENT 'Cycle length in weeks',
  `timezone`        VARCHAR(64)   DEFAULT 'America/Chicago',
  `active`          TINYINT(1)    DEFAULT 1,
  `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shift roles within a template (e.g., "Manager", "Support", "Observer")
CREATE TABLE IF NOT EXISTS `newui_shift_roles` (
  `id`                      INT AUTO_INCREMENT PRIMARY KEY,
  `template_id`             INT           NOT NULL,
  `role_name`               VARCHAR(64)   NOT NULL,
  `description`             TEXT          DEFAULT NULL,
  `min_slots`               INT           DEFAULT 1,
  `max_slots`               INT           DEFAULT 1,
  `required_cert_ids`       TEXT          DEFAULT NULL COMMENT 'JSON array of certification IDs',
  `required_ics_position_id` INT          DEFAULT NULL COMMENT 'Require ICS qualification',
  `sort_order`              INT           DEFAULT 0,
  KEY `idx_template` (`template_id`),
  CONSTRAINT `fk_shift_role_template` FOREIGN KEY (`template_id`)
    REFERENCES `newui_shift_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shift time slots (when shifts occur within the rotation)
CREATE TABLE IF NOT EXISTS `newui_shift_slots` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `template_id`     INT           NOT NULL,
  `day_of_week`     TINYINT       NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat',
  `start_time`      TIME          NOT NULL,
  `end_time`        TIME          NOT NULL,
  `week_number`     INT           DEFAULT 1 COMMENT 'Which week in the rotation (1-based)',
  `label`           VARCHAR(64)   DEFAULT NULL COMMENT 'Optional label like Morning, Evening',
  KEY `idx_template` (`template_id`),
  CONSTRAINT `fk_shift_slot_template` FOREIGN KEY (`template_id`)
    REFERENCES `newui_shift_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shift assignments: who fills each slot on a specific date
CREATE TABLE IF NOT EXISTS `newui_shift_assignments` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `slot_id`         INT           NOT NULL,
  `role_id`         INT           NOT NULL,
  `member_id`       INT           NOT NULL,
  `assignment_date` DATE          NOT NULL COMMENT 'The actual calendar date',
  `status`          ENUM('assigned','confirmed','completed','no-show','swapped','cancelled')
                                  DEFAULT 'assigned',
  `self_signup`     TINYINT(1)    DEFAULT 0 COMMENT '1 = volunteer signed up themselves',
  `notes`           TEXT          DEFAULT NULL,
  `assigned_by`     INT           DEFAULT NULL COMMENT 'Who made the assignment',
  `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_slot`    (`slot_id`),
  KEY `idx_role`    (`role_id`),
  KEY `idx_member`  (`member_id`),
  KEY `idx_date`    (`assignment_date`),
  UNIQUE KEY `uq_slot_role_member_date` (`slot_id`, `role_id`, `member_id`, `assignment_date`),
  CONSTRAINT `fk_assign_slot` FOREIGN KEY (`slot_id`)
    REFERENCES `newui_shift_slots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_role` FOREIGN KEY (`role_id`)
    REFERENCES `newui_shift_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Events: drills, exercises, deployments, meetings
CREATE TABLE IF NOT EXISTS `newui_events` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `name`              VARCHAR(255)  NOT NULL,
  `event_type`        ENUM('drill','exercise','deployment','meeting','training','other')
                                    DEFAULT 'other',
  `description`       TEXT          DEFAULT NULL,
  `start_date`        DATETIME      NOT NULL,
  `end_date`          DATETIME      DEFAULT NULL,
  `location`          VARCHAR(255)  DEFAULT NULL,
  `max_participants`  INT           DEFAULT NULL,
  `required_cert_ids` TEXT          DEFAULT NULL COMMENT 'JSON array of certification IDs',
  `status`            ENUM('planned','active','completed','cancelled')
                                    DEFAULT 'planned',
  `created_by`        INT           DEFAULT NULL,
  `created_at`        DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status`    (`status`),
  KEY `idx_start`     (`start_date`),
  KEY `idx_type`      (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Event participants: signup and attendance tracking
CREATE TABLE IF NOT EXISTS `newui_event_participants` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `event_id`        INT           NOT NULL,
  `member_id`       INT           NOT NULL,
  `status`          ENUM('registered','confirmed','attended','no-show','cancelled')
                                  DEFAULT 'registered',
  `self_signup`     TINYINT(1)    DEFAULT 0,
  `role`            VARCHAR(64)   DEFAULT NULL COMMENT 'Role during event (IC, Safety, etc.)',
  `check_in_time`   DATETIME      DEFAULT NULL,
  `check_out_time`  DATETIME      DEFAULT NULL,
  `hours_worked`    DECIMAL(5,2)  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_event_member` (`event_id`, `member_id`),
  KEY `idx_member` (`member_id`),
  CONSTRAINT `fk_participant_event` FOREIGN KEY (`event_id`)
    REFERENCES `newui_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══ Seed: Demo shift template ═══════════════════════════════

INSERT INTO `newui_shift_templates` (`name`, `description`, `rotation_weeks`, `timezone`)
VALUES ('Skywarn 4-Week Rotation', 'Primary Skywarn net control rotation. 24/7 coverage with Manager + 2 Support positions. 4-week cycle.', 4, 'America/Chicago');

-- Roles for the Skywarn template (ID will be 1)
INSERT INTO `newui_shift_roles` (`template_id`, `role_name`, `description`, `min_slots`, `max_slots`, `sort_order`)
VALUES
  (1, 'Net Manager', 'Primary net control. Must hold AUXCOMM certification.', 1, 1, 1),
  (1, 'Support', 'Backup net control and weather relay.', 1, 2, 2),
  (1, 'Observer', 'Weather spotter reporting into the net.', 0, 4, 3);

-- Shift slots: 3 shifts per day (Morning/Afternoon/Night), Mon-Sun, week 1 only
-- In a real 4-week rotation you'd define all 4 weeks. Starting with week 1.
INSERT INTO `newui_shift_slots` (`template_id`, `day_of_week`, `start_time`, `end_time`, `week_number`, `label`)
VALUES
  (1, 0, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 0, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 0, '22:00:00', '06:00:00', 1, 'Night'),
  (1, 1, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 1, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 1, '22:00:00', '06:00:00', 1, 'Night'),
  (1, 2, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 2, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 2, '22:00:00', '06:00:00', 1, 'Night'),
  (1, 3, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 3, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 3, '22:00:00', '06:00:00', 1, 'Night'),
  (1, 4, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 4, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 4, '22:00:00', '06:00:00', 1, 'Night'),
  (1, 5, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 5, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 5, '22:00:00', '06:00:00', 1, 'Night'),
  (1, 6, '06:00:00', '14:00:00', 1, 'Morning'),
  (1, 6, '14:00:00', '22:00:00', 1, 'Afternoon'),
  (1, 6, '22:00:00', '06:00:00', 1, 'Night');

-- Seed: Demo event
INSERT INTO `newui_events` (`name`, `event_type`, `description`, `start_date`, `end_date`, `location`, `max_participants`, `status`, `created_by`)
VALUES
  ('Spring Severe Weather Drill', 'drill', 'Annual spring severe weather communications drill. All Skywarn volunteers encouraged to participate.', '2026-04-15 09:00:00', '2026-04-15 17:00:00', 'Bloomington EOC', 30, 'planned', 1),
  ('AUXCOMM Training Exercise', 'exercise', 'Multi-agency auxiliary communications exercise with ARES/RACES and CERT teams.', '2026-05-10 08:00:00', '2026-05-10 16:00:00', 'Hennepin County EOC', 20, 'planned', 1),
  ('Monthly ARES Net', 'meeting', 'Regularly scheduled monthly ARES net check-in on 146.820 repeater.', '2026-04-01 19:00:00', '2026-04-01 20:00:00', 'On-Air (146.820-)', NULL, 'planned', 1);
