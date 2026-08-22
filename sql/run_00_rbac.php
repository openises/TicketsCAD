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

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

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

// Phase 145 (2026-08-19, GH#90) — external facility account. Deliberately
// NO explicit id, unlike roles 1-6 above (seeded once at the very start
// of an install's life, before any custom role can exist). roles.id is a
// plain AUTO_INCREMENT; a real, months-old install can already have
// custom roles occupying low ids by the time this migration first runs —
// confirmed live on a dev database, where a pre-existing custom role
// already held id 7 and a literal `(7, 'Facility', ...)` INSERT IGNORE
// silently no-op'd, leaving the intended row never created.
// `uk_role_name_org` (name, org_id) is the real uniqueness guard; the
// role is resolved by NAME everywhere else (api/config-admin.php,
// assets/js/config.js, this file's own grant block below) — the same
// lesson run_phase11d_mobile_first.php already learned for Field Unit's
// id ("Magic id=6 doesn't survive... rename").
try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}roles` (`name`, `description`, `is_default`, `sort_order`) VALUES (?, ?, ?, ?)",
        ['Facility', 'External facility account — confined to the facility portal', 0, 7]
    );
    echo "[OK] Facility role seeded\n";
} catch (Exception $e) {
    echo "[WARN] Facility role: " . $e->getMessage() . "\n";
}

// 6. Seed permissions
$perms = [
    // Screens
    ['screen.dashboard',       'Dashboard',            'screen'],
    ['screen.incidents',       'Incident List',        'screen'],
    ['screen.incident_detail', 'Incident Detail',      'screen'],
    ['screen.search',          'Search',               'screen'],
    ['screen.new_incident',    'New Incident',         'screen'],
    // SPEC-STATUS.md B12 (2026-08-21) — see sql/rbac.sql's matching entry
    // + sql/run_units_screen_perm.php for the companion migration.
    ['screen.units',           'Units List',           'screen'],
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
    // Phase 133 (2026-08-03) — audit-log retention/purge. Scoped like
    // action.manage_config: Super Admin only (see the Org Admin `NOT IN`
    // exclusion below; Dispatcher/Operator/Read-Only/Field Unit mappings in
    // this file are allow-lists that do not name it, so they correctly do
    // not receive it either).
    ['action.manage_audit_retention', 'Manage Audit Log Retention', 'action'],
    // Phase 132 (2026-08-03) — manage the incident-disposition list
    // (add/rename/reorder/retire) and the disposition-required-at-close
    // setting. Scoped like action.manage_config: Super Admin only (see the
    // Org Admin `NOT IN` exclusion below; Dispatcher/Operator/Read-Only/
    // Field Unit mappings in this file are allow-lists that do not name it,
    // so they correctly do not receive it either). Selecting a disposition
    // when closing/editing an incident needs no permission.
    ['action.manage_dispositions', 'Manage Incident Dispositions', 'action'],
    // 2026-07-29 — org-wide aggregate + personnel reports (api/reports.php).
    // Granted below to Super Admin (broad SELECT) and Org Admin (broad NOT IN);
    // Dispatcher/Operator/Read-Only/Field Unit grants below are allow-lists that
    // do NOT include 'action' rows, so they correctly do not receive it.
    ['action.view_reports',    'Run Aggregate Reports', 'action'],
    ['action.export_data',     'Export Data',           'action'],
    ['action.import_data',     'Import Data',           'action'],
    ['action.manage_sop',      'Manage SOPs',          'action'],
    ['action.upload_files',    'Upload Files',          'action'],
    ['action.manage_map',      'Manage Map Markups',   'action'],
    // Phase 73r — ICS forms management (save / export PDF / export XML).
    // The CRUD surface auto-grants to action.create_incident too, so a
    // dispatcher with no ICS-specific role still works.
    ['action.manage_ics_forms','Manage ICS Forms',     'action'],
    // 2026-08-02 (Chris Byrd) — deleting a saved ICS form. ADMINISTRATIVE:
    // a finalized ICS-214 is the operational record of a real incident, so
    // removing one is records retention, not dispatch. Super Admin (broad
    // SELECT) and Org Admin (broad NOT IN) receive it here; the Dispatcher/
    // Operator/Read-Only/Field Unit mappings below are allow-lists that name
    // `action.` codes explicitly, so none of them pick it up — and the
    // matching exclusion is spelled out in sql/rbac.sql, whose Dispatcher
    // mapping IS a broad NOT IN. Deleting a draft you created yourself needs
    // no permission at all (see inc/ics-forms-write.php).
    ['action.delete_ics_form', 'Delete ICS Forms',     'action'],
    // 2026-08-07 (Chris Byrd, GH#38) — deleting an equipment activity-log entry.
    // Same tier and reasoning as action.delete_ics_form directly above, except
    // there is no ownership exception at all (Eric: admin-only, full stop).
    ['action.delete_equipment_log', 'Delete Equipment Log Entries', 'action'],
    // Phase 131 — net-control check-ins (/net). OPERATIONAL, not administrative,
    // so it is deliberately NOT added to the Org Admin `NOT IN (...)` exclusion
    // list below: Super Admin (broad SELECT) and Org Admin (broad NOT IN) should
    // both receive it. Dispatcher/Operator/Read-Only/Field Unit are allow-lists
    // here, so Dispatcher is granted it explicitly a few lines down; Operator and
    // Read-Only get it per-install via the Roles UI if an org wants them running nets.
    // An `action.` code, not `screen.`, is load-bearing — the Dispatcher/Operator/
    // Read-Only grants below sweep `category IN ('screen','widget')` wholesale, so a
    // screen.* code would have been handed silently to Read-Only.
    ['action.net_checkin',     'Use Net-Control Check-Ins', 'action'],
    // Phase 138 (2026-08-13) — public incident board. TWO permissions split
    // by blast radius (security review finding #1): action.manage_public_
    // board is install-wide (master switch, precision ceiling, excluded
    // groups, default delay, rate limits, shared in_types publish rules)
    // and reaches Super Admin ONLY (see the Org Admin `NOT IN` exclusion
    // below). action.manage_public_board_org is org-scoped self-service
    // (enable/slug for the caller's OWN org only, enforced server-side by
    // forcing the org id from the session) and reaches Super Admin + Org
    // Admin via the broad Org Admin NOT IN grant below (deliberately NOT
    // added to that exclusion list). Dispatcher/Operator/Read-Only/Field
    // Unit mappings in THIS file are allow-lists that name neither code, so
    // both are correctly withheld there with no edit needed — asymmetric
    // with sql/rbac.sql, whose Dispatcher mapping IS a broad NOT IN and so
    // must name both explicitly (done there).
    ['action.manage_public_board', 'Manage Public Incident Board (install-wide)', 'action'],
    ['action.manage_public_board_org', "Manage Own Org's Public Board URL", 'action'],
    // Phase 140 (2026-08-16) — custom (data-driven) ICS form types, GH#69.
    // Same two-permission split as Phase 138 directly above, same reasoning:
    // action.manage_ics_form_types is install-wide type authoring (Super
    // Admin ONLY — see the Org Admin `NOT IN` exclusion below).
    // action.manage_ics_form_types_org is org-scoped self-service authoring
    // (Super Admin + Org Admin via the broad Org Admin NOT IN grant below,
    // deliberately NOT added to that exclusion list). Dispatcher/Operator/
    // Read-Only/Field Unit mappings in THIS file are allow-lists that name
    // neither code, so both are correctly withheld there with no edit
    // needed — asymmetric with sql/rbac.sql, whose Dispatcher mapping IS a
    // broad NOT IN and so must name both explicitly (done there). Neither
    // gate may fall back to `is_admin()` — see plan.md's RBAC section.
    ['action.manage_ics_form_types', 'Manage Custom ICS Form Types (install-wide)', 'action'],
    ['action.manage_ics_form_types_org', "Manage Own Org's Custom ICS Form Types", 'action'],
    // Phase 141 (2026-08-17) — cross-org ticket sharing / auto-routing, GH#70.
    // TWO permissions, but UNLIKE Phase 138/140, BOTH are Super-Admin-only in
    // Phase 1 (plan.md open-question-1): a routing rule grants a DIFFERENT
    // org visibility into the CREATING org's ticket data, and Phase 1 has no
    // two-party-awareness mechanism for the receiving org to consent or veto.
    // action.manage_org_routing is install-wide authoring (any owning org);
    // action.manage_org_routing_org is org-scoped self-service (only the
    // caller's own org as owning_org_id) -- created now so Phase 2/3 (or a
    // Super Admin, per-install, via the Roles & Permissions UI) can widen
    // access later by REMOVING an exclusion-list entry, no new migration
    // needed. Both are added to the Org Admin `NOT IN` exclusion below.
    // Dispatcher/Operator/Read-Only/Field Unit mappings in THIS file are
    // allow-lists that name neither code, so both are correctly withheld
    // there with no edit needed -- asymmetric with sql/rbac.sql, whose
    // Dispatcher mapping IS a broad NOT IN and so must name both explicitly
    // (done there). Neither gate may fall back to `is_admin()` -- see
    // plan.md's RBAC section.
    ['action.manage_org_routing', 'Manage Cross-Org Ticket Routing Rules (install-wide)', 'action'],
    ['action.manage_org_routing_org', "Manage Own Org's Cross-Org Ticket Routing Rules", 'action'],
    // Phase 142 (2026-08-17) — manual cross-org ticket sharing, GH#70 Phase 2.
    // TWO permissions, but UNLIKE Phase 141's routing-rule codes directly
    // above, BOTH are granted to Dispatcher AND Org Admin by default
    // (plan.md's RBAC section) -- a manual share is bounded to one ticket,
    // one decision, made once, by a person already actively working that
    // specific call, not a standing rule with unbounded future scope. The
    // real protection is org_ticket_is_owned_by_caller(), re-checked on
    // every request -- not RBAC. Org Admin's NOT IN exclusion below does
    // NOT name either code (absence grants by construction). Dispatcher's
    // mapping in THIS file is an ALLOW-list (the opposite mechanism from
    // sql/rbac.sql's broad NOT IN), so both codes ARE explicitly added to
    // the Dispatcher allow-list below -- the one file/role combination in
    // this phase that needs an edit for Dispatcher (plan.md verified this
    // asymmetry rather than assuming it). Neither gate may fall back to
    // `is_admin()` -- see plan.md's RBAC section.
    ['action.share_incident', 'Share Incident With Another Organization', 'action'],
    ['action.revoke_incident_share', 'Revoke a Cross-Org Incident Share', 'action'],
    // Phase 143 (2026-08-17) — cross-org STANDING relationships + time-boxed
    // activation windows, GH#70 Phase 3. THREE codes, two default postures:
    // action.manage_org_relationships (install-wide) is Super-Admin-only,
    // added to the Org Admin `NOT IN` exclusion below. The other two --
    // action.manage_org_relationships_org (org-scoped propose/admin, a
    // deliberate departure from Phase 141's own `_org` precedent: proposing
    // grants zero visibility by itself, the real boundary is the
    // counterpart's own per-row consent) and action.activate_org_relationship
    // (bounded, per-instance activation, gated per-relationship by
    // org_relationship_can_act_for_org() against the caller's own
    // membership) -- are BOTH granted to Org Admin AND Dispatcher by
    // default. Org Admin's mapping in THIS file is a broad NOT-IN exclusion
    // that already grants by omission (both codes absent from that list --
    // no edit needed there). Dispatcher's mapping in THIS file is a named
    // ALLOW-list (the opposite mechanism), so both codes ARE explicitly
    // added to the Dispatcher allow-list below -- the same asymmetry Phase
    // 142's plan.md already verified for its own two broadly-granted codes.
    // Neither gate may fall back to `is_admin()` -- see plan.md's RBAC section.
    ['action.manage_org_relationships', 'Manage Cross-Org Standing Relationships (install-wide)', 'action'],
    ['action.manage_org_relationships_org', "Manage Own Org's Cross-Org Standing Relationships", 'action'],
    ['action.activate_org_relationship', 'Activate/Deactivate a Cross-Org Standing Relationship', 'action'],
    // Data Visibility
    ['field.view_patient',     'View Patient Info',    'field'],
    ['field.view_contact',     'View Contact Info',    'field'],
    ['field.view_address',     'View Full Address',    'field'],
    ['field.view_notes',       'View Notes',           'field'],
    ['field.view_medical',     'View Medical Info',    'field'],
    // Phase 145 (2026-08-19, GH#90) — facility-account portal. Category
    // 'facility_account' (not 'screen'/'action') deliberately sidesteps
    // Operator's/Read-Only's category-based broad grants below; only the
    // Org Admin NOT-IN exclusion (below) needs both codes named, since
    // that grant has no category restriction. Dispatcher/Operator/
    // Read-Only/Field Unit mappings in THIS file are allow-lists that
    // name neither code, so all three are correctly withheld with no
    // edit needed there — asymmetric with sql/rbac.sql, whose Dispatcher
    // mapping IS a broad NOT IN and so must name both explicitly (done
    // there).
    ['screen.facility_portal',      'Facility Portal',             'facility_account'],
    ['action.facility_self_report', 'Facility Self-Report Status', 'facility_account'],
    // Phase 149 (2026-08-22, inbound SIP/PBX call integration). Category
    // 'call_queue' (not 'screen'/'action'/'field') deliberately sidesteps
    // Operator's/Read-Only's category-based broad grants below, the same
    // technique the facility_account codes just above already use --
    // Operator holds field.caller_history but NOT field.patient_history
    // (plan.md §5's one intentional narrowing), so a literal 'field'
    // category would have handed Operator both. Org Admin gets all five
    // via the broad NOT-IN exclusion below (deliberately NOT added to
    // that exclusion list). Dispatcher's mapping in THIS file is a named
    // ALLOW-list (unlike sql/rbac.sql's broad NOT-IN exclusion), so all
    // four Dispatcher-held codes are explicitly added to the Dispatcher
    // allow-list below; action.manage_calls is the one code withheld from
    // Dispatcher, achieved simply by never naming it there.
    ['screen.call_queue',      'Inbound Call Queue',            'call_queue'],
    ['action.claim_call',      'Claim / Release Inbound Call',  'call_queue'],
    ['action.manage_calls',    'Manage Inbound Calls (Admin)',  'call_queue'],
    ['field.caller_history',   'View Caller History',           'call_queue'],
    ['field.patient_history',  'View Caller Patient History',   'call_queue'],
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
              WHERE `code` NOT IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
                                    'action.manage_audit_retention', 'action.manage_dispositions',
                                    'action.manage_public_board', 'action.manage_ics_form_types',
                                    'action.manage_org_routing', 'action.manage_org_routing_org',
                                    'screen.facility_portal', 'action.facility_self_report',
                                    'action.manage_org_relationships')");
    echo "[OK] Org Admin permissions mapped\n";
} catch (Exception $e) {}

// Repair (2026-08-16, RBAC canonical-alias privilege-leak fix — identical
// mechanism and rationale as the matching repair in sql/rbac.sql; see that
// file's comment for the full explanation). The broad grant above matches
// this file's exclusion list by LITERAL STRING and is purely ADDITIVE
// (INSERT IGNORE) -- two distinct mechanisms leak an excluded permission
// back onto Org Admin: (1) DIRECT -- a role can hold the OLD code itself
// from before it was added to this exclusion list, and nothing ever
// retroactively revokes that when the string is later added (confirmed
// live on your-server 2026-08-16, Org Admin held all seven
// excluded codes directly); (2) ALIAS -- sql/run_rbac_v2.php's A8 step
// independently creates a CANONICAL `<resource>.<verb>` code for every
// permission and links the old code to it via `deprecated_alias_of`, and
// rbac_can() treats old code and canonical alias as interchangeable, so
// any re-run of this script after A8 has canonicalized an excluded
// permission re-grants it under its new name (confirmed live on the dev
// database and your-server.example.com, including action.manage_config
// and action.manage_roles). Both DELETEs below are self-healing on every
// run.
try {
    db_query("DELETE `{$prefix}role_permissions` FROM `{$prefix}role_permissions`
              JOIN `{$prefix}permissions` p ON p.id = `{$prefix}role_permissions`.`permission_id`
              WHERE `{$prefix}role_permissions`.`role_id` = 2
                AND p.`code` IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
                                  'action.manage_audit_retention', 'action.manage_dispositions',
                                  'action.manage_public_board', 'action.manage_ics_form_types',
                                  'action.manage_org_routing', 'action.manage_org_routing_org',
                                  'action.manage_org_relationships',
                                  'screen.facility_portal', 'action.facility_self_report')");
    db_query("DELETE rp FROM `{$prefix}role_permissions` rp
              JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
              JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
              WHERE rp.role_id = 2
                AND old_p.code IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
                                    'action.manage_audit_retention', 'action.manage_dispositions',
                                    'action.manage_public_board', 'action.manage_ics_form_types',
                                    'action.manage_org_routing', 'action.manage_org_routing_org',
                                    'action.manage_org_relationships',
                                    'screen.facility_portal', 'action.facility_self_report')");
    echo "[OK] Org Admin canonical-alias privilege leak repaired (if any)\n";
} catch (Exception $e) {}

// Dispatcher gets screens + widgets + operational actions
// Phase 142 (2026-08-17): action.share_incident / action.revoke_incident_share
// added here -- this mapping is a named-code ALLOW-list, so (unlike
// sql/rbac.sql's broad NOT-IN exclusion) a code absent from this list is
// withheld regardless of what the exclusion list in rbac.sql does. Both
// codes belong to Dispatcher by default per plan.md's RBAC section.
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 3, `id` FROM `{$prefix}permissions`
              WHERE `category` IN ('screen', 'widget')
                 OR `code` IN ('action.create_incident', 'action.edit_incident', 'action.close_incident',
                               'action.assign_unit', 'action.add_note', 'action.set_own_zone',
                               'action.net_checkin', 'action.share_incident', 'action.revoke_incident_share',
                               'action.manage_org_relationships_org', 'action.activate_org_relationship',
                               -- Phase 149 (2026-08-22): action.manage_calls deliberately absent --
                               -- withheld from Dispatcher (plan.md §5).
                               'screen.call_queue', 'action.claim_call',
                               'field.caller_history', 'field.patient_history')");
    echo "[OK] Dispatcher permissions mapped\n";
} catch (Exception $e) {}

// Repair (2026-08-22, Phase 149 discovery) — confirmed LIVE on the dev
// database that Dispatcher held the CANONICAL ALIAS (calls.manage) of
// action.manage_calls even though this ALLOW-list above never names it
// and never has. This is a THIRD variant of the canonical-alias leak
// documented in CLAUDE.md: the first two (direct grant predating an
// exclusion; alias leak through an EXCLUSION list) both assumed the leak
// path runs through a role's own grant mechanism. This one shows the
// alias-mirror step in sql/run_rbac_v2.php's A8 can hand a role a grant
// that NEITHER an allow-list NOR an exclusion-list ever authorized,
// because A8 mirrors role_permissions from whichever roles hold the OLD
// code at the moment A8 itself runs -- which is not guaranteed to be
// AFTER this file's own grant statements have reached their final,
// intended state (e.g. a tools/install_fresh.php bootstrap pass may
// interleave a fresh sql/rbac.sql import with the RBAC v2 canonicalization
// pipeline in an order this file's own top-to-bottom statements cannot
// control). Never assume an allow-list is immune to this leak just
// because it never named the code -- verify the LIVE grant, not the
// mechanism. Self-healing on every run, mirroring the Org Admin repair
// above.
try {
    db_query("DELETE `{$prefix}role_permissions` FROM `{$prefix}role_permissions`
              JOIN `{$prefix}permissions` p ON p.id = `{$prefix}role_permissions`.`permission_id`
              WHERE `{$prefix}role_permissions`.`role_id` = 3
                AND p.`code` IN ('action.manage_calls')");
    db_query("DELETE rp FROM `{$prefix}role_permissions` rp
              JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
              JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
              WHERE rp.role_id = 3
                AND old_p.code IN ('action.manage_calls')");
    echo "[OK] Dispatcher canonical-alias privilege leak repaired (if any)\n";
} catch (Exception $e) {}

// Operator gets view + notes
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT 4, `id` FROM `{$prefix}permissions`
              WHERE `category` IN ('screen', 'widget')
                 OR `code` IN ('action.add_note', 'action.set_own_zone',
                               -- Phase 149 (2026-08-22): field.patient_history deliberately
                               -- absent -- the one intentional narrowing in plan.md §5.
                               'screen.call_queue', 'action.claim_call', 'field.caller_history')");
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

// Facility gets exactly the two facility-portal permissions (Phase 145,
// 2026-08-19, GH#90). See sql/rbac.sql's comment on the Facility role
// INSERT above for why this role's narrow grant is not itself the
// security boundary, and why it is resolved by NAME (never a hardcoded
// id — roles.id is a plain AUTO_INCREMENT that a custom role may have
// already claimed by the time this migration runs).
try {
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
              SELECT r.`id`, p.`id`
                FROM `{$prefix}roles` r
                CROSS JOIN `{$prefix}permissions` p
               WHERE r.`name` = 'Facility'
                 AND p.`code` IN ('screen.facility_portal', 'action.facility_self_report')");
    echo "[OK] Facility permissions mapped\n";
} catch (Exception $e) {}

// 8. Auto-assign Super Admin role to user #1 (only if user #1 exists)
//
// Phase 129 (2026-07-29). This was:
//
//     INSERT IGNORE INTO user_roles (user_id, role_id, org_id)
//     VALUES (1, 1, NULL);
//
// and the comment already said "(if exists)" — describing an intent the
// code never implemented. Two defects, both live for months:
//
//  1. INSERT IGNORE deduped nothing. It relies on a unique key raising a
//     duplicate-key error, and `uk_user_role_scope` / `uk_user_role_org`
//     both end in a NULLable column (scope_id / org_id). MySQL and
//     MariaDB treat every NULL in a UNIQUE index as distinct, so a global
//     grant — org_id NULL, scope_id NULL — collides with nothing. Each
//     run of the migration pipeline appended another identical row:
//     13 copies on your-server, 23 on training, one per run.
//     Fixed properly at the schema level by
//     sql/run_rbac_v3_grant_uniqueness.php; guarded here as well, because
//     a writer should not depend on a constraint to be correct.
//
//  2. It never checked that user 1 exists. On an install whose
//     administrator is not id 1 (training: user 1 has never existed) this
//     wrote Super Admin grants to a phantom, and the first account later
//     created with id 1 would have inherited them. That — not the row
//     count — is why these had to go.
//
// The natural key is matched with <=> so NULL matches NULL, which is
// exactly the comparison the index could not make.
try {
    $adminExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` WHERE `id` = 1");
    if ($adminExists === 0) {
        echo "[--] No user #1 on this install — Super Admin grant skipped\n";
        echo "     (assign a role in Settings -> Roles & Permissions;\n";
        echo "      sql/run_rbac_v2.php step A9 also maps legacy accounts)\n";
    } else {
        $already = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_roles`
              WHERE `user_id` = 1 AND `role_id` = 1 AND `org_id` <=> NULL");
        if ($already > 0) {
            echo "[OK] User #1 already holds Super Admin ({$already} grant)\n";
        } else {
            db_query("INSERT INTO `{$prefix}user_roles`
                      (`user_id`, `role_id`, `org_id`) VALUES (1, 1, NULL)");
            echo "[OK] User #1 assigned Super Admin role\n";
        }
    }
} catch (Exception $e) {
    echo "[WARN] Super Admin grant for user #1: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
