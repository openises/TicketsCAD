-- ═══════════════════════════════════════════════════════════════
-- RBAC (Role-Based Access Control) Schema
-- Phase D: Replaces simple user.level with granular roles
-- ═══════════════════════════════════════════════════════════════

-- Roles define named permission sets
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(64)  NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `org_id`      INT          DEFAULT NULL COMMENT 'NULL = global role, otherwise org-specific',
    `is_default`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Auto-assign to new users',
    `sort_order`  INT          NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_role_name_org` (`name`, `org_id`),
    KEY `idx_org_id` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissions define specific actions/screens/widgets
CREATE TABLE IF NOT EXISTS `permissions` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Machine-readable key, e.g. screen.search, widget.map',
    `name`        VARCHAR(128) NOT NULL COMMENT 'Human-readable label',
    `category`    VARCHAR(32)  NOT NULL COMMENT 'screen, widget, action, field',
    `description` VARCHAR(255) DEFAULT NULL,
    KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Junction: which permissions each role grants
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       INT NOT NULL,
    `permission_id` INT NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `idx_perm_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Junction: which roles each user has (global or per-org)
CREATE TABLE IF NOT EXISTS `user_roles` (
    `id`      INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `role_id` INT NOT NULL,
    `org_id`  INT DEFAULT NULL COMMENT 'NULL = role applies globally',
    UNIQUE KEY `uk_user_role_org` (`user_id`, `role_id`, `org_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Default Roles ──
INSERT IGNORE INTO `roles` (`id`, `name`, `description`, `is_default`, `sort_order`) VALUES
    (1, 'Super Admin',  'Full system access, can manage all settings and users', 0, 1),
    (2, 'Org Admin',    'Manages org-level settings, members, and incident types', 0, 2),
    (3, 'Dispatcher',   'Can create/manage incidents, assign responders, full operational access', 0, 3),
    (4, 'Operator',     'Can view incidents and update assigned tasks', 0, 4),
    (5, 'Read-Only',    'View-only access to incidents and dashboard', 1, 5);

-- ── Default Permissions ──
INSERT IGNORE INTO `permissions` (`code`, `name`, `category`, `description`) VALUES
    -- Screens
    ('screen.dashboard',       'Dashboard',            'screen', 'Access the main dashboard'),
    ('screen.incidents',       'Incident List',        'screen', 'View the incident list'),
    ('screen.incident_detail', 'Incident Detail',      'screen', 'View incident details'),
    ('screen.search',          'Search',               'screen', 'Search past incidents'),
    ('screen.new_incident',    'New Incident',         'screen', 'Access the new incident form'),
    ('screen.unit_detail',     'Unit Detail',          'screen', 'View responder/unit details'),
    ('screen.unit_edit',       'Unit Edit',            'screen', 'Edit responder/unit records'),
    ('screen.settings',        'Settings / Config',    'screen', 'Access settings/config panel'),
    ('screen.roster',          'Roster',               'screen', 'View personnel roster'),
    ('screen.teams',           'Teams',                'screen', 'View team management'),
    ('screen.facilities',      'Facilities',           'screen', 'View facilities list'),
    ('screen.facility_detail', 'Facility Detail',      'screen', 'View facility details'),
    ('screen.scheduling',      'Scheduling',           'screen', 'Access scheduling (shifts & events)'),
    ('screen.reports',         'Reports',              'screen', 'View reports'),
    ('screen.sop',             'SOP / Procedures',     'screen', 'View standard operating procedures'),
    ('screen.equipment',       'Equipment',            'screen', 'View equipment tracking'),
    ('screen.vehicles',        'Vehicles',             'screen', 'View vehicle management'),
    ('screen.constituents',    'Constituents',         'screen', 'View contacts/constituents'),
    ('screen.situation',       'Full-Screen Situation', 'screen', 'Open the full-screen situation view'),
    ('screen.import_export',   'Import / Export',      'screen', 'Access import/export tools'),
    -- Widgets
    ('widget.map',             'Map Widget',           'widget', 'Dashboard map widget'),
    ('widget.incidents',       'Incidents Widget',     'widget', 'Dashboard incidents table'),
    ('widget.responders',      'Responders Widget',    'widget', 'Dashboard responders table'),
    ('widget.facilities',      'Facilities Widget',    'widget', 'Dashboard facilities table'),
    ('widget.stats',           'Statistics Widget',    'widget', 'Dashboard statistics cards'),
    ('widget.log',             'Recent Events Widget', 'widget', 'Dashboard activity log'),
    ('widget.controls',        'Controls Widget',      'widget', 'Dashboard quick-action buttons'),
    ('widget.comms',           'Comms Widget',         'widget', 'Dashboard communications panel'),
    -- Incident Actions
    ('action.create_incident', 'Create Incident',      'action', 'Create new incidents'),
    ('action.edit_incident',   'Edit Incident',        'action', 'Edit incident fields (type, location, etc.)'),
    ('action.close_incident',  'Close / Reopen',       'action', 'Close or reopen incidents'),
    ('action.delete_incident', 'Delete Incident',      'action', 'Permanently delete incidents'),
    ('action.assign_unit',     'Assign Unit',          'action', 'Assign/unassign responders to incidents'),
    ('action.add_note',        'Add Notes',            'action', 'Add activity notes to incidents'),
    ('action.link_major',      'Link Major Incident',  'action', 'Link incidents to major incidents'),
    -- Personnel Actions
    ('action.manage_members',  'Manage Members',       'action', 'Create/edit/delete member records'),
    ('action.bulk_delete_members', 'Bulk Delete Members', 'action', 'Remove multiple member records at once (roster bulk actions)'),
    ('action.manage_teams',    'Manage Teams',         'action', 'Create/edit/delete teams'),
    ('action.manage_schedule', 'Manage Schedule',      'action', 'Create/edit shift templates and assignments'),
    ('action.self_signup',     'Self-Signup Shifts',   'action', 'Sign up for open shift slots'),
    -- Unit Actions
    ('action.change_unit_status', 'Change Unit Status', 'action', 'Update responder/unit status'),
    ('action.dispatch_unit',   'Dispatch Unit',        'action', 'Dispatch units to incidents'),
    -- Facility Actions
    ('action.manage_facilities','Manage Facilities',   'action', 'Create/edit/delete facilities'),
    ('action.update_capacity', 'Update Capacity',      'action', 'Update facility bed/capacity counts'),
    -- Communication Actions
    ('action.send_chat',       'Send Chat Messages',   'action', 'Send messages in the chat system'),
    ('action.send_sms',        'Send SMS',             'action', 'Send SMS notifications'),
    ('action.send_email',      'Send Email',           'action', 'Send email notifications'),
    ('action.manage_routing',  'Manage Routing',       'action', 'Create/edit/delete cross-protocol message routing rules'),
    -- Administration
    ('action.manage_users',    'Manage Users',         'action', 'Create/edit/delete user accounts'),
    ('action.manage_roles',    'Manage Roles',         'action', 'Create/edit roles and assign permissions'),
    ('action.manage_config',   'Manage Config',        'action', 'Edit system configuration settings'),
    ('action.manage_orgs',     'Manage Organizations', 'action', 'Create/edit organizations'),
    ('action.manage_types',    'Manage Incident Types', 'action', 'Create/edit incident type definitions'),
    ('action.view_audit',      'View Audit Log',       'action', 'View the system audit log'),
    ('action.export_data',     'Export Data',           'action', 'Export data and reports'),
    ('action.import_data',     'Import Data',           'action', 'Import data from external sources'),
    ('action.manage_sop',      'Manage SOPs',          'action', 'Create/edit standard operating procedures'),
    ('action.upload_files',    'Upload Files',          'action', 'Upload file attachments'),
    ('action.manage_map',      'Manage Map Markups',   'action', 'Create/edit/delete map markups and road conditions'),
    -- Data Visibility (field-level)
    ('field.view_patient',     'View Patient Info',    'field', 'See patient name, DOB, medical details'),
    ('field.view_contact',     'View Contact Info',    'field', 'See caller name and phone number'),
    ('field.view_address',     'View Full Address',    'field', 'See complete street address (vs. city-only)'),
    ('field.view_notes',       'View Notes',           'field', 'See incident narrative/notes'),
    ('field.view_medical',     'View Medical Info',    'field', 'See member medical information');

-- ── Default Role → Permission Mappings ──

-- Super Admin gets EVERYTHING
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 1, `id` FROM `permissions`;

-- Org Admin gets everything except system-level config and role management.
-- Bulk member deletion is deliberately withheld from Org Admin (Eric, 2026-07-04):
-- it's a bigger hammer than single-member management and should be granted
-- explicitly per-role via the Roles UI, not handed to every administrator.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 2, `id` FROM `permissions`
    WHERE `code` NOT IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members');

-- Dispatcher gets EVERYTHING except system admin tasks (60 of 65 permissions)
-- A dispatcher answering phones needs full operational capability
-- NOTE (2026-07-07): this file is re-imported by tools/install_fresh.php on
-- upgrades, AFTER later-phase migrations have added their own permissions.
-- Any permission NOT in this exclusion list therefore gets granted to
-- Dispatcher on re-import — keep the list in sync with every phase that
-- introduces an admin-only permission (see the Phase 114 console entries).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 3, `id` FROM `permissions`
    WHERE `code` NOT IN (
        'action.manage_config',        -- system configuration is admin-only
        'action.manage_roles',         -- role/permission management is admin-only
        'action.manage_users',         -- user account CRUD is admin-only
        'action.delete_incident',      -- permanent deletion is too destructive for dispatch
        'action.import_data',          -- bulk import is an admin task
        'action.bulk_delete_members',  -- bulk roster removal is Super-Admin-only by default (Eric, 2026-07-04)
        'console.design',              -- shared console-view designer is admin-only (Phase 114, roles 1-2)
        'action.intercom_unlock'       -- intercom door actuator is admin-only (Phase 114, roles 1-2)
    );

-- Operator gets all screens/widgets/fields + key operational actions (45 permissions)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 4, `id` FROM `permissions`
    WHERE `category` IN ('screen', 'widget', 'field')
       OR `code` IN (
           'action.add_note', 'action.change_unit_status', 'action.self_signup',
           'action.send_chat', 'action.upload_files', 'action.dispatch_unit',
           'action.link_major', 'action.export_data', 'action.update_capacity',
           'action.set_own_zone'   -- Phase 115 (#64): report own unit's zone
       );

-- Read-Only gets view screens + widgets + basic field visibility (31 permissions)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 5, `id` FROM `permissions`
    WHERE (`category` IN ('screen', 'widget')
       AND `code` NOT IN ('screen.settings', 'screen.new_incident', 'screen.import_export'))
       OR `code` IN ('field.view_contact', 'field.view_address', 'field.view_notes');

-- ── Field Unit role (mobile responders) — 18 permissions ──
INSERT IGNORE INTO `roles` (`id`, `name`, `description`, `is_default`, `sort_order`) VALUES
    (6, 'Field Unit', 'Mobile responder — status updates, notes, photo upload, location sharing', 0, 6);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 6, `id` FROM `permissions`
    WHERE `code` IN (
        'screen.dashboard', 'screen.incidents', 'screen.incident_detail', 'screen.situation',
        'screen.scheduling', 'screen.facilities',
        'widget.map', 'widget.incidents', 'widget.stats', 'widget.comms',
        'action.add_note', 'action.change_unit_status', 'action.self_signup',
        'action.send_chat', 'action.upload_files',
        'field.view_contact', 'field.view_address', 'field.view_notes',
        'screen.zone_coverage', 'action.set_own_zone'  -- Phase 115 (#64): see zone counts + report own zone
    );
