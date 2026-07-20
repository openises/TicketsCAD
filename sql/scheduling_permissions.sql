-- ============================================================
-- Scheduling Permissions — Flexible Self-Service Controls
--
-- Controls what personnel can do with their own scheduling:
--   - View schedules (none, own only, own + available, full)
--   - Self-assign to open shifts
--   - Mark themselves unavailable (and allow swaps)
--   - Full control (admin-like for specific templates/events)
--
-- Permission model:
--   1. Global default permission level (settings table)
--   2. Per-template override (via scheduling_permissions)
--   3. Per-event override (via scheduling_permissions)
--   4. Per-role override (via scheduling_permissions)
--   5. Per-member override (via scheduling_permissions)
--
-- Resolution order: member > role > event/template > global default
-- ============================================================

-- ── Scheduling Permission Profiles ──────────────────────────
-- Reusable named permission sets that can be assigned to
-- templates, events, roles, or individual members.

CREATE TABLE IF NOT EXISTS `scheduling_permission_profiles` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `code`              VARCHAR(32) NOT NULL UNIQUE,
    `name`              VARCHAR(64) NOT NULL,
    `description`       VARCHAR(255) DEFAULT NULL,

    -- View permissions
    `can_view_schedule` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can see the schedule at all',
    `can_view_own`      TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can see own assignments',
    `can_view_others`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can see other peoples assignments',
    `can_view_available` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can see open/unfilled slots',

    -- Self-service permissions
    `can_self_assign`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can sign up for open slots',
    `can_self_remove`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can remove self from a slot',
    `can_mark_unavailable` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can mark self unavailable',
    `can_swap`          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can request/accept swaps with others',
    `can_request_cover` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can post a shift for others to pick up',

    -- Admin-like permissions (for team leads, etc.)
    `can_assign_others` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can assign other members',
    `can_remove_others` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can remove other members assignments',
    `can_change_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can mark confirmed/completed/no-show',
    `can_manage_slots`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can create/edit/delete time slots',

    `sort_order`        INT NOT NULL DEFAULT 50,
    `active`            TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Permission Assignments ──────────────────────────────────
-- Links permission profiles to specific contexts.
-- scope_type + scope_id identifies what the permission applies to.
-- target_type + target_id identifies who it applies to.

CREATE TABLE IF NOT EXISTS `scheduling_permission_assignments` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `profile_id`    INT NOT NULL COMMENT 'FK to scheduling_permission_profiles',
    `scope_type`    ENUM('global','template','event','role') NOT NULL DEFAULT 'global'
                    COMMENT 'What context this applies to',
    `scope_id`      INT DEFAULT NULL COMMENT 'template_id, event_id, or role_id (NULL for global)',
    `target_type`   ENUM('all','member','team','member_type') NOT NULL DEFAULT 'all'
                    COMMENT 'Who this applies to',
    `target_id`     INT DEFAULT NULL COMMENT 'member_id, team_id, or member_type_id (NULL for all)',
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_scope` (`scope_type`, `scope_id`),
    KEY `idx_target` (`target_type`, `target_id`),
    KEY `idx_profile` (`profile_id`),
    CONSTRAINT `fk_sched_perm_profile` FOREIGN KEY (`profile_id`)
        REFERENCES `scheduling_permission_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══ Seed Default Permission Profiles ═══════════════════════

INSERT IGNORE INTO `scheduling_permission_profiles`
    (`code`, `name`, `description`,
     `can_view_schedule`, `can_view_own`, `can_view_others`, `can_view_available`,
     `can_self_assign`, `can_self_remove`, `can_mark_unavailable`, `can_swap`, `can_request_cover`,
     `can_assign_others`, `can_remove_others`, `can_change_status`, `can_manage_slots`,
     `sort_order`)
VALUES
-- No permissions: can't see anything
('none', 'No Access', 'Cannot view or interact with scheduling',
 0, 0, 0, 0,
 0, 0, 0, 0, 0,
 0, 0, 0, 0,
 10),

-- View only: can see full schedule but not interact
('view_only', 'View Only', 'Can view the full schedule but cannot make changes',
 1, 1, 1, 1,
 0, 0, 0, 0, 0,
 0, 0, 0, 0,
 20),

-- View own: can only see own assignments
('view_own', 'View Own Only', 'Can only see their own assignments',
 1, 1, 0, 0,
 0, 0, 0, 0, 0,
 0, 0, 0, 0,
 30),

-- View own + available: see own assignments and open slots
('view_own_available', 'View Own + Available', 'Can see own assignments and open slots that need filling',
 1, 1, 0, 1,
 0, 0, 0, 0, 0,
 0, 0, 0, 0,
 40),

-- Self-service: can sign up for open shifts, mark unavailable, swap
('self_service', 'Self-Service', 'Can view schedule, sign up for open shifts, mark unavailable, and swap with others',
 1, 1, 1, 1,
 1, 1, 1, 1, 1,
 0, 0, 0, 0,
 50),

-- Team lead: everything self-service + can assign/remove others and change status
('team_lead', 'Team Lead', 'Can manage assignments for their team members',
 1, 1, 1, 1,
 1, 1, 1, 1, 1,
 1, 1, 1, 0,
 60),

-- Full control: everything including slot management
('full_control', 'Full Control', 'Complete scheduling management including slot creation',
 1, 1, 1, 1,
 1, 1, 1, 1, 1,
 1, 1, 1, 1,
 70);

-- ═══ Default global permission: self-service for all members ═══
INSERT IGNORE INTO `scheduling_permission_assignments`
    (`profile_id`, `scope_type`, `scope_id`, `target_type`, `target_id`)
SELECT `id`, 'global', NULL, 'all', NULL
FROM `scheduling_permission_profiles`
WHERE `code` = 'self_service'
LIMIT 1;
