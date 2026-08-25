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
-- 2026-08-15 (tools/rbac_permission_audit.php investigation): resource,
-- verb, and deprecated_alias_of used to be added later by run_rbac_v2.php
-- (an ADD COLUMN, idempotency-guarded). run_rbac_v2.php sorts AFTER
-- several phase-numbered migrations that seed a permission row USING
-- those columns (run_04_phase35_mesh_bridge.php,
-- run_phase16a_par_schema.php, run_phase18a_security_labels.php,
-- run_phase80d_time_entries.php, run_phase138_public_board.php --
-- lexicographic order runs "run_04"/"run_phase*" before "run_rbac_v2"),
-- so on a genuinely fresh install (verified in CI, which is the only
-- environment that ever exercises the full migration sequence from an
-- empty database) those five scripts' INSERTs threw "Unknown column"
-- against a `permissions` table that didn't have the columns yet -- an
-- exception each one's own outer try/catch printed as a [WARN] and
-- swallowed, so the migration "succeeded" while the permission row was
-- never created. A long-lived dev database that had already run
-- run_rbac_v2.php by the time those scripts were added never showed the
-- symptom. Fixed at the root: the columns are part of the table from
-- its first creation, so no migration's insert order can ever race them
-- again. run_rbac_v2.php's own ADD COLUMN step still runs on an existing
-- install upgrading from before this fix -- it is guarded by an
-- information-schema existence check either way.
-- `admin_only` (2026-08-22): the structural fix for a bug class this
-- project hit FIVE times (see CLAUDE.md's "RBAC EXCLUSION-LIST MECHANISM
-- LEAKS" entries) -- "admin-only" used to exist ONLY as a hand-maintained
-- `WHERE code NOT IN (...)` string list further down this file, which a
-- new canonical alias (sql/run_rbac_v2.php's A8 step), a new migration, or
-- a forgotten exclusion-list edit could all bypass invisibly. Added to
-- this CREATE TABLE directly (not left to a later ALTER) for the SAME
-- reason resource/verb/deprecated_alias_of are here -- see the comment
-- above the `permissions` table. See inc/rbac_admin_only.php for the full
-- tier model (0=unrestricted, 1=Org Admin or above, 2=Super Admin only)
-- and the guard functions every grant-writing code path now consults.
CREATE TABLE IF NOT EXISTS `permissions` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Machine-readable key, e.g. screen.search, widget.map',
    `name`        VARCHAR(128) NOT NULL COMMENT 'Human-readable label',
    `category`    VARCHAR(32)  NOT NULL COMMENT 'screen, widget, action, field',
    `resource`    VARCHAR(48)  DEFAULT NULL,
    `verb`        VARCHAR(16)  DEFAULT NULL,
    `deprecated_alias_of` VARCHAR(64) DEFAULT NULL COMMENT 'When set, points at the canonical new code; both work.',
    `description` VARCHAR(255) DEFAULT NULL,
    `admin_only`  TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0=unrestricted, 1=Org Admin or above, 2=Super Admin only. See inc/rbac_admin_only.php.',
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
    -- SPEC-STATUS.md B12 (2026-08-21) — units.php gates on this now. The
    -- code was already referenced (via a loop variable, never a literal
    -- rbac_can() call) in inc/access.php + api/stream.php's OR-chains but
    -- had never actually been seeded — harmless there only because it rode
    -- alongside real codes in the same chain. See
    -- sql/run_units_screen_perm.php for the companion migration that
    -- reaches installs that already ran this file before today.
    ('screen.units',           'Units List',           'screen', 'View the units/responders list'),
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
    -- Phase 133 (2026-08-03) — audit-log retention/purge setting + manual
    -- trigger. Scoped like action.manage_config: Super Admin only (see the
    -- Org Admin and Dispatcher `NOT IN (...)` exclusions below).
    ('action.manage_audit_retention', 'Manage Audit Log Retention', 'action', 'Change the audit-log retention/purge setting and trigger a manual purge'),
    -- Phase 132 (2026-08-03) — manage the incident-disposition list (add/
    -- rename/reorder/retire) and the disposition-required-at-close setting.
    -- Scoped like action.manage_config: admin-only (see the Org Admin and
    -- Dispatcher `NOT IN (...)` exclusions below). Selecting a disposition
    -- when closing/editing an incident needs NO permission — only managing
    -- the list does.
    ('action.manage_dispositions', 'Manage Incident Dispositions', 'action', 'Add/rename/reorder/retire incident dispositions and the require-at-close setting'),
    ('action.view_reports',    'Run Aggregate Reports', 'action', 'Run cross-incident / cross-responder and personnel reports (screen.reports alone only allows single-resource reports)'),
    ('action.export_data',     'Export Data',           'action', 'Export data and reports'),
    ('action.import_data',     'Import Data',           'action', 'Import data from external sources'),
    ('action.manage_sop',      'Manage SOPs',          'action', 'Create/edit standard operating procedures'),
    ('action.upload_files',    'Upload Files',          'action', 'Upload file attachments'),
    ('action.manage_map',      'Manage Map Markups',   'action', 'Create/edit/delete map markups and road conditions'),
    -- 2026-08-02 (Chris Byrd) — deleting a saved ICS form. ADMINISTRATIVE, not
    -- operational: a finalized ICS-214 is the operational record of a real
    -- incident, so removing one is a records-retention decision. It is
    -- therefore listed in the Dispatcher `NOT IN (...)` exclusion below, and
    -- reaches only Super Admin (broad SELECT) and Org Admin (broad NOT IN).
    -- Without it a user may STILL delete a draft they created themselves —
    -- that path is ownership, not permission (inc/ics-forms-write.php).
    ('action.delete_ics_form', 'Delete ICS Forms',     'action', 'Delete any saved ICS form to the wastebasket, including finalized forms'),
    -- 2026-08-07 (Chris Byrd, GH#38) — deleting an equipment check-out/check-in
    -- log entry. ADMINISTRATIVE, same tier as action.delete_ics_form and for the
    -- same reason: removing an entry is a records-retention decision. Unlike ICS
    -- forms there is no creator-may-delete-their-own exception — admin-only, full
    -- stop (Eric's explicit call). Listed in the Dispatcher `NOT IN (...)` exclusion below.
    ('action.delete_equipment_log', 'Delete Equipment Log Entries', 'action', 'Delete a checked-out/checked-in activity log entry for a piece of equipment'),
    -- Phase 131 — net-control check-ins (/net). OPERATIONAL, not administrative:
    -- running a net is a dispatcher's job, so this is deliberately absent from the
    -- Org Admin and Dispatcher `NOT IN (...)` exclusion lists below and is meant to
    -- reach both through their broad grants. Operator/Read-Only do not get it (their
    -- grants name action.* codes explicitly); grant it per-install via the Roles UI.
    ('action.net_checkin',     'Use Net-Control Check-Ins', 'action', 'Capture and work a personal net-control check-in list via the /net command'),
    -- Phase 138 (2026-08-13) — public incident board. TWO permissions,
    -- split by blast radius (security review finding #1: a single flat
    -- permission let any Org Admin control cross-org, install-wide
    -- settings with no server-side check). action.manage_public_board is
    -- install-wide (master switch, precision ceiling, excluded groups,
    -- default delay, rate limits, and the shared in_types publish rules -
    -- never-publish/delay/visibility/stub-label - since in_types is one
    -- shared table, not per-org) and reaches Super Admin ONLY (see the
    -- Org Admin `NOT IN (...)` exclusion below - deliberately excluded, not
    -- an oversight; do not "fix" it back to broad). action.manage_public_
    -- board_org is org-scoped self-service (enable/slug for the caller's
    -- OWN org only, enforced server-side in api/public-board-admin.php by
    -- forcing the org id from the session, never the request) and reaches
    -- Org Admin via this file's broad Org Admin NOT IN grant below (i.e.
    -- by deliberately NOT being added to that exclusion list). Both are
    -- added to the Dispatcher `NOT IN (...)` exclusion below, since this
    -- file's Dispatcher mapping is a broad exclusion list (unlike
    -- sql/run_00_rbac.php's Dispatcher mapping, which is an ALLOW-list of
    -- specific codes - neither permission is named there, so absence is
    -- already correct on that side; see the note on that file's Dispatcher
    -- block for the asymmetry).
    ('action.manage_public_board',     'Manage Public Incident Board (install-wide)', 'action', 'Install-wide public-board settings and the shared in_types publish rules. Super Admin only.'),
    ('action.manage_public_board_org', "Manage Own Org's Public Board URL",           'action', "Org-scoped self-service enable/slug for the caller's own organization's public board URL only."),
    -- Phase 140 (2026-08-16) — custom (data-driven) ICS form types, GH#69.
    -- Same two-permission split as Phase 138 directly above, same reasoning:
    -- action.manage_ics_form_types is install-wide type authoring (Super
    -- Admin ONLY — see the Org Admin `NOT IN (...)` exclusion below, and the
    -- Dispatcher `NOT IN (...)` exclusion further down, since THIS file's
    -- Dispatcher mapping is a broad exclusion list). action.manage_ics_form_
    -- types_org is org-scoped self-service authoring (Super Admin + Org
    -- Admin via this file's broad Org Admin NOT IN grant below — deliberately
    -- NOT added to that exclusion list) but IS added to the Dispatcher
    -- exclusion below, same as action.manage_public_board_org was — type
    -- authoring (even org-scoped) is an administrative action, not dispatch.
    -- sql/run_00_rbac.php's Dispatcher mapping is an ALLOW-list that names
    -- neither code, so it's already correct there with no edit needed.
    ('action.manage_ics_form_types',     'Manage Custom ICS Form Types (install-wide)', 'action', 'Author, edit, and archive agency-custom ICS form type definitions install-wide. Super Admin only.'),
    ('action.manage_ics_form_types_org', "Manage Own Org's Custom ICS Form Types",      'action', "Author, edit, and archive custom ICS form type definitions scoped to the caller's own organization."),
    -- Phase 141 (2026-08-17) — cross-org ticket sharing / auto-routing, GH#70.
    -- TWO permissions, but UNLIKE Phase 138/140's split, BOTH are Super-Admin-
    -- only in Phase 1 (plan.md's resolved open question 1): a routing rule
    -- grants a DIFFERENT org visibility into the CREATING org's ticket data,
    -- and Phase 1 has no two-party-awareness mechanism for the receiving org
    -- to consent or veto. action.manage_org_routing is install-wide authoring
    -- (any owning org); action.manage_org_routing_org is org-scoped
    -- self-service (only the caller's own org as owning_org_id) -- created so
    -- Phase 2/3 (or a Super Admin, per-install, via the Roles & Permissions
    -- UI) can widen access later by REMOVING an exclusion-list entry, no new
    -- migration needed. Both are therefore added to the Org Admin `NOT IN`
    -- exclusion below (deliberately including the `_org` code -- the
    -- departure from Phase 138/140 precedent) AND to the Dispatcher `NOT IN`
    -- exclusion further down (this file's Dispatcher mapping is a broad
    -- exclusion list, unlike sql/run_00_rbac.php's allow-list Dispatcher
    -- mapping, which withholds both by construction with no edit needed).
    -- Neither gate may fall back to `is_admin()` -- see plan.md's RBAC section.
    ('action.manage_org_routing',     'Manage Cross-Org Ticket Routing Rules (install-wide)', 'action', 'Create/edit/deactivate cross-org ticket auto-routing rules naming any owning org. Super Admin only in Phase 1.'),
    ('action.manage_org_routing_org', "Manage Own Org's Cross-Org Ticket Routing Rules",      'action', "Create/edit/deactivate cross-org ticket auto-routing rules scoped to the caller's own organization as the owning org. Excluded from Org Admin's default grant in Phase 1 -- see plan.md open-question-1."),
    -- Phase 142 (2026-08-17) — manual cross-org ticket sharing, GH#70 Phase 2.
    -- TWO permissions, but UNLIKE Phase 141's routing-rule codes, BOTH are
    -- granted to Dispatcher AND Org Admin by default (plan.md's RBAC
    -- section): a manual share is bounded to one ticket, one decision, made
    -- once, by a person already actively working that specific call --
    -- closer in kind to action.assign_unit than to authoring a standing
    -- routing rule with unbounded future scope. The real protection is not
    -- RBAC here -- it is org_ticket_is_owned_by_caller(), re-checked on
    -- every request (plan.md's "one hard security line"). So NEITHER code
    -- is added to the Org Admin `NOT IN` exclusion below (absence grants by
    -- construction) NOR to the Dispatcher `NOT IN` exclusion further down
    -- (this file's Dispatcher mapping is ALSO a broad exclusion list, so
    -- absence grants there too) -- the opposite treatment from Phase 141's
    -- codes directly above. No repair-DELETE entries exist for these two
    -- codes: they were never excluded, so there is no leak path to repair.
    -- Neither gate may fall back to `is_admin()` -- see plan.md's RBAC section.
    ('action.share_incident',         'Share Incident With Another Organization', 'action', 'Manually share a single incident this org owns with another organization, at a chosen access tier, with a reason. Granted to Dispatcher and Org Admin by default.'),
    ('action.revoke_incident_share',  'Revoke a Cross-Org Incident Share',        'action', "Revoke an active manual or rule-sourced share on an incident the caller's org owns. Granted to Dispatcher and Org Admin by default."),
    -- Phase 143 (2026-08-17) — cross-org STANDING relationships + time-boxed
    -- activation windows, GH#70 Phase 3 (the final phase of the Option D
    -- build). THREE permissions, two different default postures:
    --   action.manage_org_relationships (install-wide) is Super-Admin-only
    --   -- full CRUD over any relationship naming any orgs, approve/reject
    --   any pending row on behalf of any org, edit ceiling settings.
    --   action.manage_org_relationships_org (org-scoped proposal/admin) IS
    --   granted to Org Admin by default -- a DELIBERATE departure from
    --   Phase 141's own precedent for its `_org` code (kept Super-Admin-only
    --   there because a routing rule takes effect unilaterally with no
    --   counterparty check). Proposing a relationship grants ZERO visibility
    --   by itself here -- the security boundary is the per-row
    --   org_relationship_can_act_for_org() gate re-run on every approval
    --   (same separation-of-concerns Phase 142 already established for
    --   manual sharing), so withholding this from Org Admin would make
    --   spec.md's own Org-Admin-persona user story unreachable for no
    --   security gain.
    --   action.activate_org_relationship is a THIRD, separate code (bounded,
    --   per-instance, human-in-the-loop -- closer in kind to Phase 142's
    --   manual share than to standing rule authorship), granted to
    --   Dispatcher AND Org Admin by default, gated per-relationship by
    --   org_relationship_can_act_for_org() against the caller's own
    --   membership -- a dispatcher can only activate a relationship their
    --   OWN org is an approved member of.
    -- Only action.manage_org_relationships is added to the Org Admin AND
    -- Dispatcher `NOT IN` exclusions below (mirrors Phase 141's own
    -- Super-Admin-only code exactly); the other two codes are deliberately
    -- ABSENT from every exclusion list (absence grants by construction, same
    -- mechanism Phase 142 already used for its own two broadly-granted
    -- codes). Neither gate may fall back to `is_admin()` -- see plan.md's
    -- RBAC section.
    ('action.manage_org_relationships',     'Manage Cross-Org Standing Relationships (install-wide)', 'action', 'Full CRUD over any standing cross-org relationship naming any orgs; approve/reject any pending membership row on behalf of any org; edit ceiling settings. Super Admin only.'),
    ('action.manage_org_relationships_org', "Manage Own Org's Cross-Org Standing Relationships",      'action', "Propose/administer standing cross-org relationships naming the caller's own organization as one of the initial members. Granted to Org Admin by default -- proposing grants zero visibility by itself; the security boundary is the counterpart org's own per-row consent."),
    ('action.activate_org_relationship',    'Activate/Deactivate a Cross-Org Standing Relationship',   'action', "Activate or deactivate a requires_activation relationship the caller's own organization is an approved member of. Granted to Dispatcher and Org Admin by default."),
    -- Data Visibility (field-level)
    ('field.view_patient',     'View Patient Info',    'field', 'See patient name, DOB, medical details'),
    ('field.view_contact',     'View Contact Info',    'field', 'See caller name and phone number'),
    ('field.view_address',     'View Full Address',    'field', 'See complete street address (vs. city-only)'),
    ('field.view_notes',       'View Notes',           'field', 'See incident narrative/notes'),
    ('field.view_medical',     'View Medical Info',    'field', 'See member medical information');

-- Phase 149 (2026-08-22) — inbound SIP/PBX call integration. FIVE
-- permissions, deliberately NOT a reuse of `screen.constituents` (gates
-- only the standalone Constituents page) or `action.manage_members`
-- (gates only writes) -- neither means what this feature's checks need
-- (plan.md §5). Category 'call_queue' (not 'screen'/'action'/'field')
-- deliberately sidesteps Operator's `category IN ('screen','widget',
-- 'field')` broad grant below and Read-Only's `category IN ('screen',
-- 'widget')` broad grant -- the SAME bespoke-category technique Phase 145
-- used for screen.facility_portal/action.facility_self_report, because
-- the per-role grant matrix here is NOT uniform across categories: e.g.
-- Operator holds field.caller_history but NOT field.patient_history, so a
-- literal 'field' category would hand Operator both. Every one of these
-- five is seeded via an ORDINARY, POSITIVE role-inclusive grant (a named
-- INSERT ... SELECT ... WHERE code IN (...) per role, below), never via
-- the `WHERE code NOT IN (...)` broad-exclusion mechanism this file also
-- uses elsewhere -- that mechanism is exactly what produced this
-- project's four separate documented privilege-leak incidents (direct
-- grant predating an exclusion, canonical-alias leak, cross-file drift, a
-- dedicated migration re-granting an excluded code); none of it applies
-- here since every code below is a subtraction from nothing, not from
-- "all of them". The ONE exception is action.manage_calls, which IS
-- added to Dispatcher's existing broad NOT-IN exclusion list further
-- down (Dispatcher's grant in THIS file is itself a broad exclusion, so
-- withholding one code from it uses that file's own existing mechanism)
-- -- see that list's own comment for why no repair-DELETE is needed
-- (this is a brand-new code, so no prior grant can predate its
-- exclusion).
INSERT IGNORE INTO `permissions` (`code`, `name`, `category`, `description`) VALUES
    ('screen.call_queue',      'Inbound Call Queue',            'call_queue', 'See the live ringing/claimed inbound-call banner and queue'),
    ('action.claim_call',      'Claim / Release Inbound Call',  'call_queue', 'Claim a ringing call, release a claim, quick-reassign a mis-claim within the grace window, or force-reclaim a STALE claim'),
    ('action.manage_calls',    'Manage Inbound Calls (Admin)',  'call_queue', 'Force-reclaim an ACTIVE (non-stale) claim with a reason; configure SIP/PBX trunks'),
    ('field.caller_history',   'View Caller History',           'call_queue', "See a claimed call's matched constituent identity and prior-incident summary"),
    ('field.patient_history',  'View Caller Patient History',   'call_queue', 'See clinical/patient detail nested inside a caller''s prior-incident history');

-- Phase 145 (2026-08-19, GH#90) — facility-account portal. TWO permissions,
-- deliberately given category 'facility_account' rather than 'screen'/
-- 'action' — Operator's grant below sweeps `category IN ('screen','widget',
-- 'field')` wholesale and Read-Only sweeps `category IN ('screen','widget')`,
-- so a `screen.` code with a `screen` category would have been handed to
-- both of those roles silently. A bespoke category sidesteps that
-- mechanism entirely; only role 7 (Facility, below) is ever granted these
-- two codes. Org Admin and Dispatcher below use a broad NOT-IN exclusion
-- with no category restriction at all, so both codes ARE added to both of
-- those exclusion lists (and their repair DELETEs) to withhold them there.
INSERT IGNORE INTO `permissions` (`code`, `name`, `category`, `description`) VALUES
    ('screen.facility_portal',       'Facility Portal',              'facility_account', 'Access the facility self-service portal (own facility only)'),
    ('action.facility_self_report',  'Facility Self-Report Status',  'facility_account', "Update the caller's own linked facility's status/diversion and bed capacity");

-- ── admin_only classification (2026-08-22) ──
-- MUST stay in sync with the matching block in sql/run_00_rbac.php --
-- same convention this file already follows for the exclusion lists
-- themselves. Cross-referenced against THIS file's own Org Admin and
-- Dispatcher `WHERE code NOT IN (...)` exclusion lists below (the
-- authoritative source of "what's admin-only today") and verified against
-- a live database's role_permissions state before writing this.
--
-- Tier 2 (Super Admin ONLY -- excluded from BOTH Org Admin's and
-- Dispatcher's broad grants below): a full defeat of the Org-Admin/
-- Super-Admin boundary if granted elsewhere (action.manage_config and
-- action.manage_roles are the textbook case -- either alone makes
-- is_admin() true for every holder of the role).
--
-- Tier 1 (Org Admin or above -- excluded from Dispatcher's broad grant
-- ONLY; Org Admin legitimately holds these by design): this is exactly
-- the Phase 149 incident's tier -- action.manage_calls's canonical alias
-- leaked onto Dispatcher, which never should have held it, while Org
-- Admin's own hold of it was always correct and must not be disturbed.
--
-- screen.facility_portal / action.facility_self_report are DELIBERATELY
-- left at tier 0 -- see inc/rbac_admin_only.php's docblock for why a tier
-- restriction would be wrong for a permission reserved for a specific
-- bespoke role (hundreds of live non-super Facility rows hold these).
UPDATE `permissions` SET `admin_only` = 2 WHERE `code` IN (
    'action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
    'action.manage_audit_retention', 'action.manage_dispositions',
    'action.manage_public_board', 'action.manage_ics_form_types',
    'action.manage_org_routing', 'action.manage_org_routing_org',
    'action.manage_org_relationships'
);
UPDATE `permissions` SET `admin_only` = 1 WHERE `code` IN (
    'action.manage_users', 'action.delete_incident', 'action.import_data',
    'console.design', 'action.intercom_unlock', 'action.view_reports',
    'action.delete_ics_form', 'action.delete_equipment_log',
    'action.manage_public_board_org', 'action.manage_ics_form_types_org',
    'action.manage_matrix', 'action.manage_calls'
);
-- Propagate onto each code's canonical alias partner in BOTH directions
-- (sql/run_rbac_v2.php's A8 step may already have created the canonical
-- <resource>.<verb> row on an install that's run RBAC v2 migrations
-- before today) so a lookup by EITHER name agrees -- this is the actual
-- data-level fix for the Phase 149 mechanism, not just the runtime
-- symmetric lookup inc/rbac_admin_only.php also does as a second line of
-- defense.
UPDATE `permissions` canon
  JOIN `permissions` old_p ON old_p.deprecated_alias_of = canon.code
   SET canon.admin_only = old_p.admin_only
 WHERE old_p.admin_only > canon.admin_only;
UPDATE `permissions` old_p
  JOIN `permissions` canon ON canon.code = old_p.deprecated_alias_of
   SET old_p.admin_only = canon.admin_only
 WHERE canon.admin_only > old_p.admin_only;

-- ── Default Role → Permission Mappings ──

-- Super Admin gets EVERYTHING
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 1, `id` FROM `permissions`;

-- Org Admin gets everything except system-level config and role management.
-- Bulk member deletion is deliberately withheld from Org Admin (Eric, 2026-07-04):
-- it's a bigger hammer than single-member management and should be granted
-- explicitly per-role via the Roles UI, not handed to every administrator.
-- 2026-08-22 (admin_only structural fix): `AND admin_only <= 1` is added
-- AFTER the exclusion list below (not instead of it, and deliberately kept
-- as a trailing `AND` rather than leading -- tools/rbac_exclusion_leak_audit.php's
-- parser looks for the literal shape `WHERE code NOT IN (...)` immediately
-- after WHERE, so the admin_only check must not sit between them) -- Org
-- Admin's own tier is 1 (org_admin_or_above), so this structurally
-- excludes any tier-2 (Super Admin only) permission even if a FUTURE
-- tier-2 code is added to `permissions` without also being added to the
-- NOT IN list. The exclusion list stays for documentation/history and as
-- a secondary check (tools/rbac_exclusion_leak_audit.php cross-references
-- both).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 2, `id` FROM `permissions`
    WHERE `code` NOT IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
                          'action.manage_audit_retention', 'action.manage_dispositions',
                          -- Phase 138 (2026-08-13) — install-wide public-board settings are
                          -- Super-Admin-only; Org Admin gets ONLY action.manage_public_board_org
                          -- (deliberately absent from this list, so it IS granted below).
                          'action.manage_public_board',
                          -- Phase 140 (2026-08-16) — install-wide ICS form type authoring is
                          -- Super-Admin-only; Org Admin gets ONLY action.manage_ics_form_types_org
                          -- (deliberately absent from this list, so it IS granted below).
                          'action.manage_ics_form_types',
                          -- Phase 141 (2026-08-17) — cross-org ticket routing. UNLIKE Phase
                          -- 138/140, BOTH codes are excluded here (plan.md open-question-1):
                          -- Phase 1 has no two-party-awareness mechanism for the receiving org,
                          -- so even the org-scoped variant stays Super-Admin-only by default.
                          'action.manage_org_routing', 'action.manage_org_routing_org',
                          -- Phase 145 (2026-08-19, GH#90) — the facility-portal permissions are
                          -- for the dedicated Facility role (resolved by name, not id) ONLY.
                          -- Category alone already keeps them out of Operator/Read-Only's
                          -- category-based grants, but THIS grant has no category restriction at
                          -- all (it is "everything except X"), so both codes must be named here
                          -- explicitly. Placed BEFORE the Phase 143 entry below, not after --
                          -- tests/test_org_relationships_rbac.php's own structural check expects
                          -- 'action.manage_org_relationships' to be the LAST entry in this list.
                          'screen.facility_portal', 'action.facility_self_report',
                          -- Phase 143 (2026-08-17) — cross-org STANDING relationships. ONLY the
                          -- install-wide code is excluded -- action.manage_org_relationships_org
                          -- and action.activate_org_relationship are deliberately absent from
                          -- this list so they ARE granted below (plan.md's departure from Phase
                          -- 141's precedent; see the permission INSERT block's own comment).
                          'action.manage_org_relationships')
      AND `admin_only` <= 1;

-- Repair (2026-08-16, RBAC canonical-alias privilege-leak fix): the broad
-- grant above matches this file's exclusion list by LITERAL STRING and is
-- purely ADDITIVE (`INSERT IGNORE`) — it can never revoke a grant that
-- predates the string being added to this list. Two distinct mechanisms
-- both leak an excluded permission back onto Org Admin:
--
--  (1) DIRECT: a role can hold the OLD code itself from before it was
--      added to this exclusion list (this file's own history documents
--      exactly this for action.bulk_delete_members — "Before 2026-07-07
--      this INSERT only excluded action.manage_config" — but NOTHING
--      retroactively revoked the pre-existing grant when the string was
--      later added; confirmed live on your-server 2026-08-16, Org
--      Admin held all seven excluded codes above directly).
--  (2) ALIAS: sql/run_rbac_v2.php's A8 step (2026-08-15 RBAC
--      permission-code audit, tools/rbac_permission_audit.php)
--      independently creates a CANONICAL `<resource>.<verb>` code for
--      every permission and links the old code to it via
--      `deprecated_alias_of` — and inc/rbac.php's rbac_can() treats the
--      old code and its canonical alias as fully interchangeable for
--      grant lookups (_rbac_alias_candidates()). A literal exclusion list
--      can never name a canonical code that didn't exist yet when it was
--      written, so any re-import of this file after A8 has canonicalized
--      an excluded code re-grants Org Admin the alias under its new name
--      (confirmed live on the dev database and on
--      your-server.example.com — action.manage_config and
--      action.manage_roles among them).
--
-- Both are a full defeat of the Org-Admin/Super-Admin boundary. Both
-- DELETEs below run on every re-import (self-healing, like A8b's repair
-- step) and revoke exactly the leaked grants — nothing else. Neither
-- depends on import order relative to run_rbac_v2.php.
DELETE `role_permissions` FROM `role_permissions`
    JOIN `permissions` p ON p.id = `role_permissions`.`permission_id`
    WHERE `role_permissions`.`role_id` = 2
      AND p.`code` IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
                        'action.manage_audit_retention', 'action.manage_dispositions',
                        'action.manage_public_board', 'action.manage_ics_form_types',
                        'action.manage_org_routing', 'action.manage_org_routing_org',
                        'action.manage_org_relationships',
                        'screen.facility_portal', 'action.facility_self_report');

DELETE rp FROM `role_permissions` rp
    JOIN `permissions` canon ON canon.id = rp.permission_id
    JOIN `permissions` old_p ON old_p.deprecated_alias_of = canon.code
    WHERE rp.role_id = 2
      AND old_p.code IN ('action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
                          'action.manage_audit_retention', 'action.manage_dispositions',
                          'action.manage_public_board', 'action.manage_ics_form_types',
                          'action.manage_org_routing', 'action.manage_org_routing_org',
                          'action.manage_org_relationships',
                          'screen.facility_portal', 'action.facility_self_report');

-- Dispatcher gets EVERYTHING except system admin tasks (60 of 65 permissions)
-- A dispatcher answering phones needs full operational capability
-- NOTE (2026-07-07): this file is re-imported by tools/install_fresh.php on
-- upgrades, AFTER later-phase migrations have added their own permissions.
-- Any permission NOT in this exclusion list therefore gets granted to
-- Dispatcher on re-import — keep the list in sync with every phase that
-- introduces an admin-only permission (see the Phase 114 console entries).
-- 2026-08-22 (admin_only structural fix): `AND admin_only = 0` is added
-- AFTER the exclusion list below (not instead of it, and deliberately kept
-- as a trailing `AND` rather than leading -- tools/rbac_exclusion_leak_audit.php's
-- parser looks for the literal shape `WHERE code NOT IN (...)` immediately
-- after WHERE) -- Dispatcher's own tier is 0, so this structurally
-- excludes any tier-1 or tier-2 permission even if a FUTURE restricted
-- code is added to `permissions` without also being added to the NOT IN
-- list below (exactly how action.manage_calls's canonical alias leaked
-- onto Dispatcher via sql/run_rbac_v2.php's A8 mirror step, in the SAME
-- COMMIT that created the permission and its own exclusion-list entry
-- here -- see inc/rbac_admin_only.php).
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
        'action.intercom_unlock',      -- intercom door actuator is admin-only (Phase 114, roles 1-2)
        'action.view_reports',         -- org-wide aggregate reports are admin-only (roles 1-2, 2026-07-29);
                                       -- grant per-role via the Roles UI if your dispatchers need them
        'action.delete_ics_form',      -- deleting an ICS form is records retention, not dispatch
                                       -- (roles 1-2, 2026-08-02); a dispatcher can still delete
                                       -- a draft they created themselves
        'action.delete_equipment_log', -- deleting an equipment log entry is records retention,
                                       -- admin-only with no ownership exception (roles 1-2, 2026-08-07)
        'action.manage_audit_retention', -- audit-log retention/purge is admin-only (roles 1 only,
                                       -- 2026-08-03) — same tier as action.manage_config
        'action.manage_dispositions',  -- managing the incident-disposition list is admin-only
                                       -- (role 1 only, 2026-08-03) — same tier as action.manage_config;
                                       -- selecting a disposition on a call needs no permission
        'action.manage_public_board',     -- Phase 138 (2026-08-13) — install-wide public-board
                                       -- settings are admin-only (roles 1-2, same tier as
                                       -- action.manage_config)
        'action.manage_public_board_org', -- Phase 138 — even the org-scoped self-service variant
                                       -- is withheld from Dispatcher; only Super Admin + Org
                                       -- Admin self-service their own org's public board URL
        'action.manage_ics_form_types',     -- Phase 140 (2026-08-16) — install-wide custom ICS
                                       -- form type authoring is admin-only (roles 1-2, same
                                       -- tier as action.manage_config)
        'action.manage_ics_form_types_org', -- Phase 140 — even the org-scoped self-service variant
                                       -- is withheld from Dispatcher; type authoring (even scoped
                                       -- to one's own org) is administrative, not dispatch
        'action.manage_org_routing',        -- Phase 141 (2026-08-17) — install-wide cross-org
                                       -- ticket routing authoring is admin-only (role 1 only in
                                       -- Phase 1, same tier as action.manage_config)
        'action.manage_org_routing_org',    -- Phase 141 — the org-scoped variant is ALSO
                                       -- Super-Admin-only in Phase 1 (plan.md open-question-1)
                                       -- and withheld from Dispatcher for the same reason it is
                                       -- withheld from Org Admin above
        'action.manage_org_relationships',  -- Phase 143 (2026-08-17) — install-wide standing-
                                       -- relationship authoring is admin-only (role 1 only, same
                                       -- tier as action.manage_config). action.manage_org_relationships_org
                                       -- and action.activate_org_relationship are deliberately
                                       -- absent from this list -- both ARE granted to Dispatcher
                                       -- by default (plan.md's RBAC section)
        'screen.facility_portal',      -- Phase 145 (2026-08-19, GH#90) — the facility portal is
                                       -- for the dedicated Facility role (7) only, never Dispatcher.
                                       -- This grant has no category restriction, so both new codes
                                       -- must be named here explicitly.
        'action.facility_self_report',
        'action.manage_matrix',        -- Phase 114c (sql/run_phase114c_comm_routes.php) — audio-
                                       -- matrix patch management is admin-only (roles 1-2, same tier
                                       -- as console.design/action.intercom_unlock just above). Found
                                       -- missing from this list 2026-08-20 while building the
                                       -- matrix-admin.php UI: confirmed LIVE on the dev database that
                                       -- Dispatcher (role 3) had already been swept up by this file's
                                       -- broad NOT-IN grant on a prior re-import, exactly the pattern
                                       -- this file's own repair-DELETE history documents.
        'action.manage_calls'          -- Phase 149 (2026-08-22) — force-reclaiming an ACTIVE
                                       -- (non-stale) claim, and trunk configuration, are admin-only
                                       -- (roles 1-2, same tier as action.manage_config); a Dispatcher
                                       -- still gets screen.call_queue/action.claim_call/
                                       -- field.caller_history/field.patient_history via the explicit
                                       -- positive grants above -- only this one code is withheld.
                                       -- CORRECTION (same day): "brand new code, so no repair-DELETE
                                       -- needed" turned out to be false even for a code created in
                                       -- THIS SAME COMMIT -- confirmed live on the dev database that
                                       -- Dispatcher held the CANONICAL ALIAS (calls.manage) of this
                                       -- exact code via sql/run_rbac_v2.php's A8 canonicalization step,
                                       -- because that step can run (re-deriving resource/verb and
                                       -- mirroring role_permissions) at a moment that does not
                                       -- coincide with this file's own grant statements having already
                                       -- reached their final, correctly-excluded state -- e.g. a
                                       -- tools/install_fresh.php bootstrap pass that re-imports this
                                       -- file and re-runs the RBAC v2 canonicalization pipeline is not
                                       -- guaranteed to interleave in the order a single script's own
                                       -- top-to-bottom statement order would suggest. The safe
                                       -- assumption going forward: EVERY exclusion-list addition needs
                                       -- both repair-DELETEs below, even for a permission created in
                                       -- the same commit as its own exclusion.
    )
      AND `admin_only` = 0;

-- Repair (2026-08-16, RBAC canonical-alias privilege-leak fix — same two
-- mechanisms and rationale as the Org Admin repair DELETEs above; see that
-- comment block for the full explanation). Confirmed live: Dispatcher held
-- the canonical alias of action.manage_config and action.manage_roles
-- among others via the alias path; your-server additionally held
-- several of these codes DIRECTLY (the pre-exclusion-list-edit path).
DELETE `role_permissions` FROM `role_permissions`
    JOIN `permissions` p ON p.id = `role_permissions`.`permission_id`
    WHERE `role_permissions`.`role_id` = 3
      AND p.`code` IN (
        'action.manage_config', 'action.manage_roles', 'action.manage_users',
        'action.delete_incident', 'action.import_data', 'action.bulk_delete_members',
        'console.design', 'action.intercom_unlock', 'action.view_reports',
        'action.delete_ics_form', 'action.delete_equipment_log',
        'action.manage_audit_retention', 'action.manage_dispositions',
        'action.manage_public_board', 'action.manage_public_board_org',
        'action.manage_ics_form_types', 'action.manage_ics_form_types_org',
        'action.manage_org_routing', 'action.manage_org_routing_org',
        'action.manage_org_relationships',
        'screen.facility_portal', 'action.facility_self_report',
        'action.manage_matrix', 'action.manage_calls'
      );

DELETE rp FROM `role_permissions` rp
    JOIN `permissions` canon ON canon.id = rp.permission_id
    JOIN `permissions` old_p ON old_p.deprecated_alias_of = canon.code
    WHERE rp.role_id = 3
      AND old_p.code IN (
        'action.manage_config', 'action.manage_roles', 'action.manage_users',
        'action.delete_incident', 'action.import_data', 'action.bulk_delete_members',
        'console.design', 'action.intercom_unlock', 'action.view_reports',
        'action.delete_ics_form', 'action.delete_equipment_log',
        'action.manage_audit_retention', 'action.manage_dispositions',
        'action.manage_public_board', 'action.manage_public_board_org',
        'action.manage_ics_form_types', 'action.manage_ics_form_types_org',
        'action.manage_org_routing', 'action.manage_org_routing_org',
        'action.manage_org_relationships',
        'screen.facility_portal', 'action.facility_self_report',
        'action.manage_matrix', 'action.manage_calls'
      );

-- Operator gets all screens/widgets/fields + key operational actions (45 permissions)
-- 2026-08-22 (admin_only structural fix): `AND admin_only = 0` --
-- defense-in-depth, not required (none of the named/category-swept codes
-- are currently restricted) -- makes the invariant uniform across every
-- grant statement in this file.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 4, `id` FROM `permissions`
    WHERE (`category` IN ('screen', 'widget', 'field')
       OR `code` IN (
           'action.add_note', 'action.change_unit_status', 'action.self_signup',
           'action.send_chat', 'action.upload_files', 'action.dispatch_unit',
           'action.link_major', 'action.export_data', 'action.update_capacity',
           'action.set_own_zone',  -- Phase 115 (#64): report own unit's zone
           -- Phase 149 (2026-08-22) -- Operator can see/claim inbound calls
           -- and their caller history, but NOT clinical/patient detail
           -- (field.patient_history is deliberately withheld -- plan.md
           -- §5's one intentional narrowing relative to every other
           -- permission in this table, flagged explicitly for Eric rather
           -- than silently shipped). Category 'call_queue' is not in the
           -- `screen,widget,field` sweep above, so these need naming here.
           'screen.call_queue', 'action.claim_call', 'field.caller_history'
       ))
       AND `admin_only` = 0;

-- Read-Only gets view screens + widgets + basic field visibility (31 permissions)
-- 2026-08-22 (admin_only structural fix): `AND admin_only = 0` -- same
-- defense-in-depth reasoning as the Operator grant above.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 5, `id` FROM `permissions`
    WHERE ((`category` IN ('screen', 'widget')
       AND `code` NOT IN ('screen.settings', 'screen.new_incident', 'screen.import_export'))
       OR `code` IN ('field.view_contact', 'field.view_address', 'field.view_notes'))
       AND `admin_only` = 0;

-- ── Field Unit role (mobile responders) — 18 permissions ──
INSERT IGNORE INTO `roles` (`id`, `name`, `description`, `is_default`, `sort_order`) VALUES
    (6, 'Field Unit', 'Mobile responder — status updates, notes, photo upload, location sharing', 0, 6);

-- 2026-08-22 (admin_only structural fix): `AND admin_only = 0` -- same
-- defense-in-depth reasoning as the grants above.
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
    )
      AND `admin_only` = 0;

-- ── Facility role (external facility accounts) — 2 permissions, GH#90 ──
-- Phase 145 (2026-08-19). Real confinement v3's LEVEL_FACILITY never had:
-- the confinement is not "this role holds only 2 permissions" alone (a role
-- with the right two permission names but a role_id someone forgot to
-- exclude from a broad grant elsewhere would be just as leaky as v3's bare
-- redirect) -- it is inc/facility-scope.php's three independent layers
-- (inc/rbac.php's _rbac_load_grants(), api/auth.php's script allowlist,
-- inc/force-pw-change.php's page allowlist), all keyed off
-- $_SESSION['facility_id'] (from user.facility_id, repurposed -- see
-- sql/run_phase145_facility_accounts.php), not off role_id at all. This
-- role exists so the account has SOMETHING to hold in the roles-matrix UI
-- and so is_admin()/rbac_can() resolve sensibly for the two portal actions;
-- it is not itself the security boundary.
--
-- Deliberately NO explicit id here (unlike roles 1-6, seeded once at the
-- very start of an install's life before any custom role could exist).
-- roles.id is a plain AUTO_INCREMENT, and a real, months-old install can
-- easily have already created custom roles that consumed low ids by the
-- time this migration first runs -- confirmed live on a dev database,
-- where a pre-existing custom role already held id 7 and a literal
-- `(7, 'Facility', ...)` INSERT IGNORE would have silently no-op'd,
-- leaving the intended row never created. `uk_role_name_org` (name, org_id)
-- is the real uniqueness guard here -- letting the id auto-assign and
-- resolving the row by NAME everywhere else (api/config-admin.php,
-- assets/js/config.js, sql/run_00_rbac.php's own grant below) is the same
-- lesson run_phase11d_mobile_first.php already learned for Field Unit's
-- id (that migration's docblock: "Magic id=6 doesn't survive... rename").
INSERT IGNORE INTO `roles` (`name`, `description`, `is_default`, `sort_order`) VALUES
    ('Facility', 'External facility account — self-service view of incidents at/inbound to its own facility, and self-service status/capacity reporting. Confined to the facility portal; see inc/facility-scope.php.', 0, 7);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.`id`, p.`id`
      FROM `roles` r
      CROSS JOIN `permissions` p
     WHERE r.`name` = 'Facility'
       AND p.`code` IN ('screen.facility_portal', 'action.facility_self_report');
