<?php
/**
 * Run RBAC — Create role-based access control schema and seed defaults.
 *
 * Purpose:  Creates roles, permissions, role_permissions, and user_roles
 *           tables. Seeds 6 default roles (Super Admin through Field Unit)
 *           and 60+ permissions across screen, widget, action, and field
 *           categories. Assigns Super Admin to user #1.
 * Usage:    php sql/run_rbac.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   [OK]/[WARN] per table and seed operation.
 */
require_once __DIR__ . '/../config.php';

echo "Phase D: RBAC Schema Setup\n";
echo "==========================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// 1. Create roles table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}roles` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(64)  NOT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        `org_id`      INT          DEFAULT NULL,
        `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
        `sort_order`  INT          NOT NULL DEFAULT 0,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_role_name_org` (`name`, `org_id`),
        KEY `idx_org_id` (`org_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] roles table ready\n";
} catch (Exception $e) {
    echo "[WARN] roles: " . $e->getMessage() . "\n";
}

// 2. Create permissions table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}permissions` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `code`        VARCHAR(64)  NOT NULL UNIQUE,
        `name`        VARCHAR(128) NOT NULL,
        `category`    VARCHAR(32)  NOT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        KEY `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] permissions table ready\n";
} catch (Exception $e) {
    echo "[WARN] permissions: " . $e->getMessage() . "\n";
}

// 3. Create role_permissions table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}role_permissions` (
        `role_id`       INT NOT NULL,
        `permission_id` INT NOT NULL,
        PRIMARY KEY (`role_id`, `permission_id`),
        KEY `idx_perm_id` (`permission_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] role_permissions table ready\n";
} catch (Exception $e) {
    echo "[WARN] role_permissions: " . $e->getMessage() . "\n";
}

// 4. Create user_roles table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}user_roles` (
        `id`      INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `role_id` INT NOT NULL,
        `org_id`  INT DEFAULT NULL,
        UNIQUE KEY `uk_user_role_org` (`user_id`, `role_id`, `org_id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_role_id` (`role_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] user_roles table ready\n";
} catch (Exception $e) {
    echo "[WARN] user_roles: " . $e->getMessage() . "\n";
}

// 5. Seed default roles
$roles = [
    [1, 'Super Admin',  'Full system access — config, users, all data', 0, 1],
    [2, 'Org Admin',    'Manages org-level settings, members, and incident types', 0, 2],
    [3, 'Dispatcher',   'Full operational access — create/manage incidents, assign units', 0, 3],
    [4, 'Operator',     'View incidents and update assigned tasks', 0, 4],
    [5, 'Read-Only',    'View-only access to incidents and dashboard', 1, 5],
    [6, 'Field Unit',   'Mobile responder — status updates, notes, photo upload', 0, 6],
];

$inserted = 0;
foreach ($roles as $r) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}roles` (`id`, `name`, `description`, `is_default`, `sort_order`) VALUES (?, ?, ?, ?, ?)",
            $r
        );
        $inserted++;
    } catch (Exception $e) {}
}
echo "[OK] $inserted default roles seeded\n";

// 6. Seed permissions
$perms = [
    // Screens
    ['screen.dashboard',       'Dashboard',            'screen'],
    ['screen.incidents',       'Incident List',        'screen'],
    ['screen.incident_detail', 'Incident Detail',      'screen'],
    ['screen.search',          'Search',               'screen'],
    ['screen.new_incident',    'New Incident',         'screen'],
    ['screen.unit_detail',     'Unit Detail',          'screen'],
    ['screen.unit_edit',       'Unit Edit',            'screen'],
    ['screen.settings',        'Settings / Config',    'screen'],
    ['screen.roster',          'Roster',               'screen'],
    ['screen.teams',           'Teams',                'screen'],
    ['screen.facilities',      'Facilities',           'screen'],
    ['screen.facility_detail', 'Facility Detail',      'screen'],
    ['screen.scheduling',      'Scheduling',           'screen'],
    ['screen.reports',         'Reports',              'screen'],
    ['screen.sop',             'SOP / Procedures',     'screen'],
    ['screen.equipment',       'Equipment',            'screen'],
    ['screen.vehicles',        'Vehicles',             'screen'],
    ['screen.constituents',    'Constituents',         'screen'],
    ['screen.situation',       'Full-Screen Situation', 'screen'],
    ['screen.import_export',   'Import / Export',      'screen'],
    // Widgets
    ['widget.map',             'Map Widget',           'widget'],
    ['widget.incidents',       'Incidents Widget',     'widget'],
    ['widget.responders',      'Responders Widget',    'widget'],
    ['widget.facilities',      'Facilities Widget',    'widget'],
    ['widget.stats',           'Statistics Widget',    'widget'],
    ['widget.log',             'Recent Events Widget', 'widget'],
    ['widget.controls',        'Controls Widget',      'widget'],
    ['widget.comms',           'Comms Widget',         'widget'],
    // Incident Actions
    ['action.create_incident', 'Create Incident',      'action'],
    ['action.edit_incident',   'Edit Incident',        'action'],
    ['action.close_incident',  'Close / Reopen',       'action'],
    ['action.delete_incident', 'Delete Incident',      'action'],
    ['action.assign_unit',     'Assign Unit',          'action'],
    ['action.add_note',        'Add Notes',            'action'],
    ['action.link_major',      'Link Major Incident',  'action'],
    // Personnel Actions
    ['action.manage_members',  'Manage Members',       'action'],
    ['action.manage_teams',    'Manage Teams',         'action'],
    ['action.manage_schedule', 'Manage Schedule',      'action'],
    ['action.self_signup',     'Self-Signup Shifts',   'action'],
    ['action.self_clock_in',   'Self Clock-In as Personal Resource', 'action'],
    // Unit Actions
    ['action.change_unit_status', 'Change Unit Status', 'action'],
    ['action.dispatch_unit',   'Dispatch Unit',        'action'],
    // Facility Actions
    ['action.manage_facilities','Manage Facilities',   'action'],
    ['action.update_capacity', 'Update Capacity',      'action'],
    // Communication Actions
    ['action.send_chat',       'Send Chat Messages',   'action'],
    ['action.send_sms',        'Send SMS',             'action'],
    ['action.send_email',      'Send Email',           'action'],
    // Administration
    ['action.manage_users',    'Manage Users',         'action'],
    ['action.manage_roles',    'Manage Roles',         'action'],
    ['action.manage_config',   'Manage Config',        'action'],
    ['action.manage_orgs',     'Manage Organizations', 'action'],
    ['action.manage_types',    'Manage Incident Types', 'action'],
    ['action.view_audit',      'View Audit Log',       'action'],
    ['action.export_data',     'Export Data',           'action'],
    ['action.import_data',     'Import Data',           'action'],
    ['action.manage_sop',      'Manage SOPs',          'action'],
    ['action.upload_files',    'Upload Files',          'action'],
    ['action.manage_map',      'Manage Map Markups',   'action'],
    // Phase 73r — ICS forms management (save / export PDF / export XML).
    // The CRUD surface auto-grants to action.create_incident too, so a
    // dispatcher with no ICS-specific role still works.
    ['action.manage_ics_forms','Manage ICS Forms',     'action'],
    // Data Visibility
    ['field.view_patient',     'View Patient Info',    'field'],
    ['field.view_contact',     'View Contact Info',    'field'],
    ['field.view_address',     'View Full Address',    'field'],
    ['field.view_notes',       'View Notes',           'field'],
    ['field.view_medical',     'View Medical Info',    'field'],
];

$pInserted = 0;
foreach ($perms as $p) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`) VALUES (?, ?, ?)",
            $p
        );
        $pInserted++;
    } catch (Exception $e) {}
}
echo "[OK] $pInserted permissions seeded\n";

// 7. Map roles → permissions
// Super Admin gets everything
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 1, `id` FROM `{$prefix}permissions`");
    echo "[OK] Super Admin permissions mapped\n";
} catch (Exception $e) {
    echo "[WARN] Super Admin perms: " . $e->getMessage() . "\n";
}

// Org Admin gets most.
// Exclusions MUST stay in sync with sql/rbac.sql's Org Admin mapping:
// system config + role management are Super-Admin-only, and bulk member
// deletion is deliberately withheld (Eric, 2026-07-04 — grant it per-role
// via the Roles UI, never by default). Before 2026-07-07 this INSERT only
// excluded action.manage_config, so on fresh installs (where rbac.sql had
// already seeded all permission rows) it silently re-granted the withheld
// codes to Org Admin. run_bulk_delete_member_perm.php heals the
// bulk-delete grant on affected installs.
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 2, `id` FROM `{$prefix}permissions`
              WHERE `code` NOT IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members')");
    echo "[OK] Org Admin permissions mapped\n";
} catch (Exception $e) {}

// Dispatcher gets screens + widgets + operational actions
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 3, `id` FROM `{$prefix}permissions`
              WHERE `category` IN ('screen', 'widget')
                 OR `code` IN ('action.create_incident', 'action.edit_incident', 'action.close_incident',
                               'action.assign_unit', 'action.add_note', 'action.set_own_zone')");
    echo "[OK] Dispatcher permissions mapped\n";
} catch (Exception $e) {}

// Operator gets view + notes
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 4, `id` FROM `{$prefix}permissions`
              WHERE `category` IN ('screen', 'widget')
                 OR `code` IN ('action.add_note', 'action.set_own_zone')");
    echo "[OK] Operator permissions mapped\n";
} catch (Exception $e) {}

// Read-Only gets view screens + widgets (no settings, no new incident)
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 5, `id` FROM `{$prefix}permissions`
              WHERE (`category` IN ('screen', 'widget'))
                AND `code` NOT IN ('screen.settings', 'screen.new_incident')");
    echo "[OK] Read-Only permissions mapped\n";
} catch (Exception $e) {}

// Field Unit gets mobile-appropriate permissions
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 6, `id` FROM `{$prefix}permissions`
              WHERE `code` IN (
                  'screen.dashboard', 'screen.incident_detail', 'screen.situation',
                  'widget.map', 'widget.incidents',
                  'action.add_note', 'action.change_unit_status', 'action.self_signup',
                  'action.self_clock_in',
                  'action.send_chat', 'action.upload_files',
                  'field.view_contact', 'field.view_address', 'field.view_notes'
              )");
    echo "[OK] Field Unit permissions mapped\n";
} catch (Exception $e) {}

// 8. Auto-assign Super Admin role to user #1 (if exists)
try {
    db_query("INSERT IGNORE INTO `{$prefix}user_roles` (`user_id`, `role_id`, `org_id`) VALUES (1, 1, NULL)");
    echo "[OK] User #1 assigned Super Admin role\n";
} catch (Exception $e) {}

echo "\nDone.\n";
