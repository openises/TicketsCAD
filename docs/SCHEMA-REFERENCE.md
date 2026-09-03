# TicketsCAD NewUI — Schema Reference

**Generated:** 2026-08-19 02:07 by `tools/gen_schema_reference.php` — do not hand-edit, regenerate instead.

Purely informational — every column on every table in this live database, for an agent to `Read` or `Grep` instead of running a one-off `SHOW COLUMNS`/`SHOW INDEX` query. NOT load-bearing: nothing checks this file against the live schema (that job belongs to `sql/schema_manifest.json` + `inc/schema-verify.php`, which cover columns the code actually writes to). A stale copy here is misleading, not breaking — regenerate when the schema changes meaningfully.

## Known gotchas

Facts a schema dump alone cannot show. Read this section before writing any new query against an unfamiliar table.

### `member_comm_identifiers`

`sort_order` is NOT in the base schema. It is added lazily, at runtime, the first time `api/comm-identifiers.php`'s `_ensure_sort_order_column()` runs. A fresh CI install (which never hits that admin endpoint) will not have it — naming it in a raw INSERT 1054-errors there even though it works on any dev DB that has. Check `inc/comm_resolve.php`'s `_comm_resolve_has_sort_order()` for the existing guard pattern before referencing this column anywhere new.

### `facilities` / `responder` / `teams` / `newui_equipment` / `newui_vehicles`

`org_id` is NOT in the base schema for any of these five (Phase 99j-6, "org-scope filter for units, facilities, teams, vehicles, equipment") — same shape as `member_comm_identifiers.sort_order` above. It is added lazily by `inc/org-scope.php`'s `ensure_org_id_column($table)`, called at the top of every writer that needs it (`facility_upsert_internal()`, `responder_upsert_internal()`, `team_upsert_internal()`, `api/equipment.php`'s save action, `api/vehicles.php`'s save action) before the INSERT that references it. A fresh CI install has none of the five until the FIRST create call for that table runs. `tools/schema_audit.php`'s own parser doesn't use `tools/sql_extract.php` (a pre-existing gap, not fixed here) so it only ever sees the two of these five built as a single literal `"INSERT INTO \`{$prefix}table\` (...)"` string (`facilities`, `responder`) — those two are in `tools/schema_audit_baseline.txt`; `teams` (built via `. db_table('teams') .` concatenation) and `newui_equipment`/`newui_vehicles` (built from a fully dynamic `$fields` array) are invisible to that parser regardless and need no baseline entry, but are exactly as lazily-self-healed as the two that do.

### `settings / config`

TWO separate stores, easy to cross. `settings` (name/value, ~255 rows) is what the Settings UI actually writes and what `get_variable($name)` reads — this is where a new feature toggle belongs. `config` (key/value, ~8 rows) is a small bootstrap-ish store read by `get_setting($key, $default)` that the Settings UI does NOT write to. Reading a UI-saved toggle with `get_setting()` silently returns the default forever, with no error anywhere (GH #79).

### `message_routes`

`source_channel = '*'` is a real wildcard `_router_get_routes()` matches (`WHERE source_channel = ? OR source_channel = '*'`) — one row can cover every present and future channel rather than needing a row per channel.

### `ticket_disposition`

Deliberately has NO database-level UNIQUE key on (code, org_id), because `org_id` is NULLable and MySQL/MariaDB treat every NULL as distinct in a unique index — a naive unique constraint would enforce nothing for global (org_id IS NULL) rows. Uniqueness is enforced at the application level instead (`disposition_code_exists()`).

### `assigns`

`clear` is a DATETIME, not a boolean. An assignment is OPEN when `clear IS NULL`, cleared when it holds a timestamp. Never add an `is_clear`/`active` column for this — the whole codebase already keys off `clear IS NULL`.

### `responder`

Has NO `member_id` column — a query written against a remembered `responder.member_id` will 1054 or (worse) silently return nothing. The two real member<->responder linkages are (1) `unit_personnel_assignments` (responder_id, member_id, status, released_at — many-people-one-unit model) and (2) `responder.personal_for_member_id` (one responder tied to exactly one member — the "personal unit" model). See `inc/comm_resolve.php`'s `comm_resolve_responder_member_id()` for the canonical resolution order (checks #1 then #2).

### `user`

`level` is a legacy v3 column. RBAC (`role_id` + the `roles`/`permissions`/`role_permissions` tables) is the ONLY permission system as of Phase 128 — `user.level` must never gate anything, not even as an OR-fallback. Also: the admin account is NOT necessarily user id 1 — `base_schema.sql` pins `AUTO_INCREMENT=3` on this table (a legacy-dump artefact), so the first account a fresh install creates is id 3. Use `tests/_test_admin.php`'s `test_admin_user_id()` in tests, never a hardcoded `1`.

### `ticket`

`status`: 1 = CLOSED, 2 = ACTIVE/open, 3 = SCHEDULED (booked_date in the future; auto-activates 3->2 lazily when the date passes). A query filtering "open" incidents wants `status = 2`, not `status != 1`.

### `comm_modes`

Fully data-driven: `fields_json` on each row defines the per-mode form field shape, and the existing Roster -> member -> Comm/Location IDs UI renders any row generically. Adding a new identifier type (a new messaging channel, a new device type) is a migration seeding one row plus a reverse-map entry in `inc/comm_resolve.php` -- it is NOT new UI code.

## Tables (261)

| Table | Jump |
|---|---|
| `access_requests` | [#`access_requests`](#access_requests) |
| `action` | [#`action`](#action) |
| `active_sessions` | [#`active_sessions`](#active_sessions) |
| `ai_conversations` | [#`ai_conversations`](#ai_conversations) |
| `ai_conversation_messages` | [#`ai_conversation_messages`](#ai_conversation_messages) |
| `ai_pending_responses` | [#`ai_pending_responses`](#ai_pending_responses) |
| `ajax_log` | [#`ajax_log`](#ajax_log) |
| `allocates` | [#`allocates`](#allocates) |
| `allocations` | [#`allocations`](#allocations) |
| `aprs_watchlist` | [#`aprs_watchlist`](#aprs_watchlist) |
| `assigns` | [#`assigns`](#assigns) |
| `atak_unbound_uids` | [#`atak_unbound_uids`](#atak_unbound_uids) |
| `audit_log_purges` | [#`audit_log_purges`](#audit_log_purges) |
| `auto_disp_status` | [#`auto_disp_status`](#auto_disp_status) |
| `auto_status` | [#`auto_status`](#auto_status) |
| `bridge_tokens` | [#`bridge_tokens`](#bridge_tokens) |
| `capability_types` | [#`capability_types`](#capability_types) |
| `capacity_categories` | [#`capacity_categories`](#capacity_categories) |
| `captions` | [#`captions`](#captions) |
| `captions_i18n` | [#`captions_i18n`](#captions_i18n) |
| `certifications` | [#`certifications`](#certifications) |
| `certs` | [#`certs`](#certs) |
| `certs_x_user` | [#`certs_x_user`](#certs_x_user) |
| `chat_invites` | [#`chat_invites`](#chat_invites) |
| `chat_messages` | [#`chat_messages`](#chat_messages) |
| `chat_rooms` | [#`chat_rooms`](#chat_rooms) |
| `cities` | [#`cities`](#cities) |
| `clones` | [#`clones`](#clones) |
| `clothing_types` | [#`clothing_types`](#clothing_types) |
| `codes` | [#`codes`](#codes) |
| `comm_channels` | [#`comm_channels`](#comm_channels) |
| `comm_channel_state` | [#`comm_channel_state`](#comm_channel_state) |
| `comm_modes` | [#`comm_modes`](#comm_modes) |
| `comm_routes` | [#`comm_routes`](#comm_routes) |
| `conditions` | [#`conditions`](#conditions) |
| `config` | [#`config`](#config) |
| `console_views` | [#`console_views`](#console_views) |
| `console_view_strips` | [#`console_view_strips`](#console_view_strips) |
| `constituents` | [#`constituents`](#constituents) |
| `contacts` | [#`contacts`](#contacts) |
| `courses` | [#`courses`](#courses) |
| `courses_x_user` | [#`courses_x_user`](#courses_x_user) |
| `css_day` | [#`css_day`](#css_day) |
| `css_night` | [#`css_night`](#css_night) |
| `dashboard_layouts` | [#`dashboard_layouts`](#dashboard_layouts) |
| `defined_fields` | [#`defined_fields`](#defined_fields) |
| `dmr_channels` | [#`dmr_channels`](#dmr_channels) |
| `dmr_messages` | [#`dmr_messages`](#dmr_messages) |
| `dmr_ws_tokens` | [#`dmr_ws_tokens`](#dmr_ws_tokens) |
| `documents` | [#`documents`](#documents) |
| `documents_log` | [#`documents_log`](#documents_log) |
| `email_blacklist` | [#`email_blacklist`](#email_blacklist) |
| `email_lists` | [#`email_lists`](#email_lists) |
| `email_list_members` | [#`email_list_members`](#email_list_members) |
| `equipment_assignments` | [#`equipment_assignments`](#equipment_assignments) |
| `equipment_types` | [#`equipment_types`](#equipment_types) |
| `events` | [#`events`](#events) |
| `event_types` | [#`event_types`](#event_types) |
| `event_zones` | [#`event_zones`](#event_zones) |
| `event_zone_templates` | [#`event_zone_templates`](#event_zone_templates) |
| `external_api_rate_limits` | [#`external_api_rate_limits`](#external_api_rate_limits) |
| `external_api_tokens` | [#`external_api_tokens`](#external_api_tokens) |
| `external_links` | [#`external_links`](#external_links) |
| `facilities` | [#`facilities`](#facilities) |
| `facility_bed_auto_log` | [#`facility_bed_auto_log`](#facility_bed_auto_log) |
| `facility_capacity` | [#`facility_capacity`](#facility_capacity) |
| `facility_notes` | [#`facility_notes`](#facility_notes) |
| `facnotes` | [#`facnotes`](#facnotes) |
| `fac_case_cat` | [#`fac_case_cat`](#fac_case_cat) |
| `fac_status` | [#`fac_status`](#fac_status) |
| `fac_types` | [#`fac_types`](#fac_types) |
| `fcc_amateur` | [#`fcc_amateur`](#fcc_amateur) |
| `fcc_gmrs` | [#`fcc_gmrs`](#fcc_gmrs) |
| `fieldsets` | [#`fieldsets`](#fieldsets) |
| `files` | [#`files`](#files) |
| `files_x` | [#`files_x`](#files_x) |
| `file_uploads` | [#`file_uploads`](#file_uploads) |
| `geofences` | [#`geofences`](#geofences) |
| `geofence_unit_state` | [#`geofence_unit_state`](#geofence_unit_state) |
| `hints` | [#`hints`](#hints) |
| `ics` | [#`ics`](#ics) |
| `ics_forms` | [#`ics_forms`](#ics_forms) |
| `ics_form_types` | [#`ics_form_types`](#ics_form_types) |
| `ics_positions` | [#`ics_positions`](#ics_positions) |
| `inbound_message_dedupe` | [#`inbound_message_dedupe`](#inbound_message_dedupe) |
| `incident_shares` | [#`incident_shares`](#incident_shares) |
| `insurance` | [#`insurance`](#insurance) |
| `internal_messages` | [#`internal_messages`](#internal_messages) |
| `in_types` | [#`in_types`](#in_types) |
| `known_sources` | [#`known_sources`](#known_sources) |
| `languages` | [#`languages`](#languages) |
| `location_ingest_tokens` | [#`location_ingest_tokens`](#location_ingest_tokens) |
| `location_providers` | [#`location_providers`](#location_providers) |
| `location_reports` | [#`location_reports`](#location_reports) |
| `log` | [#`log`](#log) |
| `logins` | [#`logins`](#logins) |
| `login_attempts` | [#`login_attempts`](#login_attempts) |
| `mailgroup` | [#`mailgroup`](#mailgroup) |
| `mailgroup_x` | [#`mailgroup_x`](#mailgroup_x) |
| `major_incidents` | [#`major_incidents`](#major_incidents) |
| `map_image_overlays` | [#`map_image_overlays`](#map_image_overlays) |
| `map_markups` | [#`map_markups`](#map_markups) |
| `markup_categories` | [#`markup_categories`](#markup_categories) |
| `mdb_files` | [#`mdb_files`](#mdb_files) |
| `mdb_settings` | [#`mdb_settings`](#mdb_settings) |
| `member` | [#`member`](#member) |
| `member_callsigns` | [#`member_callsigns`](#member_callsigns) |
| `member_certifications` | [#`member_certifications`](#member_certifications) |
| `member_comm_identifiers` | [#`member_comm_identifiers`](#member_comm_identifiers) |
| `member_ics_qualifications` | [#`member_ics_qualifications`](#member_ics_qualifications) |
| `member_organizations` | [#`member_organizations`](#member_organizations) |
| `member_status` | [#`member_status`](#member_status) |
| `member_time_entries` | [#`member_time_entries`](#member_time_entries) |
| `member_tracking_tokens` | [#`member_tracking_tokens`](#member_tracking_tokens) |
| `member_types` | [#`member_types`](#member_types) |
| `mesh_bridges` | [#`mesh_bridges`](#mesh_bridges) |
| `mesh_bridge_channels` | [#`mesh_bridge_channels`](#mesh_bridge_channels) |
| `mesh_channels` | [#`mesh_channels`](#mesh_channels) |
| `mesh_nodes` | [#`mesh_nodes`](#mesh_nodes) |
| `mesh_outbox` | [#`mesh_outbox`](#mesh_outbox) |
| `mesh_packet_log` | [#`mesh_packet_log`](#mesh_packet_log) |
| `messages` | [#`messages`](#messages) |
| `messages_bin` | [#`messages_bin`](#messages_bin) |
| `message_recipients` | [#`message_recipients`](#message_recipients) |
| `message_routes` | [#`message_routes`](#message_routes) |
| `mileage_log` | [#`mileage_log`](#mileage_log) |
| `mi_types` | [#`mi_types`](#mi_types) |
| `mi_x` | [#`mi_x`](#mi_x) |
| `mmarkup` | [#`mmarkup`](#mmarkup) |
| `mmarkup_cats` | [#`mmarkup_cats`](#mmarkup_cats) |
| `modules` | [#`modules`](#modules) |
| `msg_settings` | [#`msg_settings`](#msg_settings) |
| `net_checkins` | [#`net_checkins`](#net_checkins) |
| `newui_audit_log` | [#`newui_audit_log`](#newui_audit_log) |
| `newui_equipment` | [#`newui_equipment`](#newui_equipment) |
| `newui_equipment_log` | [#`newui_equipment_log`](#newui_equipment_log) |
| `newui_equipment_types` | [#`newui_equipment_types`](#newui_equipment_types) |
| `newui_events` | [#`newui_events`](#newui_events) |
| `newui_event_participants` | [#`newui_event_participants`](#newui_event_participants) |
| `newui_major_incidents` | [#`newui_major_incidents`](#newui_major_incidents) |
| `newui_major_incident_links` | [#`newui_major_incident_links`](#newui_major_incident_links) |
| `newui_service_events` | [#`newui_service_events`](#newui_service_events) |
| `newui_service_state` | [#`newui_service_state`](#newui_service_state) |
| `newui_shift_assignments` | [#`newui_shift_assignments`](#newui_shift_assignments) |
| `newui_shift_roles` | [#`newui_shift_roles`](#newui_shift_roles) |
| `newui_shift_slots` | [#`newui_shift_slots`](#newui_shift_slots) |
| `newui_shift_templates` | [#`newui_shift_templates`](#newui_shift_templates) |
| `newui_vehicles` | [#`newui_vehicles`](#newui_vehicles) |
| `newui_vehicle_types` | [#`newui_vehicle_types`](#newui_vehicle_types) |
| `notification_log` | [#`notification_log`](#notification_log) |
| `notification_preferences` | [#`notification_preferences`](#notification_preferences) |
| `notification_rules` | [#`notification_rules`](#notification_rules) |
| `notify` | [#`notify`](#notify) |
| `organisations` | [#`organisations`](#organisations) |
| `organizations` | [#`organizations`](#organizations) |
| `org_relationships` | [#`org_relationships`](#org_relationships) |
| `org_relationships_activations` | [#`org_relationships_activations`](#org_relationships_activations) |
| `org_relationships_members` | [#`org_relationships_members`](#org_relationships_members) |
| `org_type_routing` | [#`org_type_routing`](#org_type_routing) |
| `owntracks_outbox` | [#`owntracks_outbox`](#owntracks_outbox) |
| `par_config` | [#`par_config`](#par_config) |
| `par_cycles` | [#`par_cycles`](#par_cycles) |
| `par_unit_acks` | [#`par_unit_acks`](#par_unit_acks) |
| `patient` | [#`patient`](#patient) |
| `patient_x` | [#`patient_x`](#patient_x) |
| `pending_routed_messages` | [#`pending_routed_messages`](#pending_routed_messages) |
| `permissions` | [#`permissions`](#permissions) |
| `permission_review_dismissals` | [#`permission_review_dismissals`](#permission_review_dismissals) |
| `personnel` | [#`personnel`](#personnel) |
| `photos` | [#`photos`](#photos) |
| `pin_ctrl` | [#`pin_ctrl`](#pin_ctrl) |
| `places` | [#`places`](#places) |
| `push_subscriptions` | [#`push_subscriptions`](#push_subscriptions) |
| `quick_notes` | [#`quick_notes`](#quick_notes) |
| `radioid_users` | [#`radioid_users`](#radioid_users) |
| `region` | [#`region`](#region) |
| `region_type` | [#`region_type`](#region_type) |
| `remote_devices` | [#`remote_devices`](#remote_devices) |
| `replacetext` | [#`replacetext`](#replacetext) |
| `replacetext_order` | [#`replacetext_order`](#replacetext_order) |
| `responder` | [#`responder`](#responder) |
| `responder_notes` | [#`responder_notes`](#responder_notes) |
| `responder_rota` | [#`responder_rota`](#responder_rota) |
| `responder_x_member` | [#`responder_x_member`](#responder_x_member) |
| `roadinfo` | [#`roadinfo`](#roadinfo) |
| `roles` | [#`roles`](#roles) |
| `role_permissions` | [#`role_permissions`](#role_permissions) |
| `routing_log` | [#`routing_log`](#routing_log) |
| `scheduled_job_runs` | [#`scheduled_job_runs`](#scheduled_job_runs) |
| `scheduling_permission_assignments` | [#`scheduling_permission_assignments`](#scheduling_permission_assignments) |
| `scheduling_permission_profiles` | [#`scheduling_permission_profiles`](#scheduling_permission_profiles) |
| `security_labels` | [#`security_labels`](#security_labels) |
| `settings` | [#`settings`](#settings) |
| `severity_levels` | [#`severity_levels`](#severity_levels) |
| `showin_contactlist` | [#`showin_contactlist`](#showin_contactlist) |
| `signals` | [#`signals`](#signals) |
| `skills` | [#`skills`](#skills) |
| `skills_x_user` | [#`skills_x_user`](#skills_x_user) |
| `sop_pages` | [#`sop_pages`](#sop_pages) |
| `sop_revisions` | [#`sop_revisions`](#sop_revisions) |
| `sound_settings` | [#`sound_settings`](#sound_settings) |
| `sse_events` | [#`sse_events`](#sse_events) |
| `states_translator` | [#`states_translator`](#states_translator) |
| `stats_settings` | [#`stats_settings`](#stats_settings) |
| `stats_type` | [#`stats_type`](#stats_type) |
| `status_transitions` | [#`status_transitions`](#status_transitions) |
| `status_workflow_layout` | [#`status_workflow_layout`](#status_workflow_layout) |
| `std_msgs` | [#`std_msgs`](#std_msgs) |
| `talkgroups` | [#`talkgroups`](#talkgroups) |
| `team` | [#`team`](#team) |
| `teams` | [#`teams`](#teams) |
| `teams_x_user` | [#`teams_x_user`](#teams_x_user) |
| `team_members` | [#`team_members`](#team_members) |
| `team_types` | [#`team_types`](#team_types) |
| `tfa_remember_tokens` | [#`tfa_remember_tokens`](#tfa_remember_tokens) |
| `ticket` | [#`ticket`](#ticket) |
| `ticket_disposition` | [#`ticket_disposition`](#ticket_disposition) |
| `time_activity_types` | [#`time_activity_types`](#time_activity_types) |
| `tips` | [#`tips`](#tips) |
| `titles` | [#`titles`](#titles) |
| `tracks` | [#`tracks`](#tracks) |
| `tracks_hh` | [#`tracks_hh`](#tracks_hh) |
| `training_packages` | [#`training_packages`](#training_packages) |
| `training_records` | [#`training_records`](#training_records) |
| `tts_applications` | [#`tts_applications`](#tts_applications) |
| `tts_engines` | [#`tts_engines`](#tts_engines) |
| `unit_assignment_roles` | [#`unit_assignment_roles`](#unit_assignment_roles) |
| `unit_location_bindings` | [#`unit_location_bindings`](#unit_location_bindings) |
| `unit_personnel_assignments` | [#`unit_personnel_assignments`](#unit_personnel_assignments) |
| `unit_types` | [#`unit_types`](#unit_types) |
| `un_status` | [#`un_status`](#un_status) |
| `user` | [#`user`](#user) |
| `user_password_history` | [#`user_password_history`](#user_password_history) |
| `user_roles` | [#`user_roles`](#user_roles) |
| `user_roles_pre_v2_backup` | [#`user_roles_pre_v2_backup`](#user_roles_pre_v2_backup) |
| `user_screen_prefs` | [#`user_screen_prefs`](#user_screen_prefs) |
| `user_tfa` | [#`user_tfa`](#user_tfa) |
| `vehicles` | [#`vehicles`](#vehicles) |
| `vehicle_types` | [#`vehicle_types`](#vehicle_types) |
| `warnings` | [#`warnings`](#warnings) |
| `waste_basket_f` | [#`waste_basket_f`](#waste_basket_f) |
| `waste_basket_m` | [#`waste_basket_m`](#waste_basket_m) |
| `weather_alerts` | [#`weather_alerts`](#weather_alerts) |
| `weather_alert_areas` | [#`weather_alert_areas`](#weather_alert_areas) |
| `weather_alert_dispatch` | [#`weather_alert_dispatch`](#weather_alert_dispatch) |
| `weather_alert_rules` | [#`weather_alert_rules`](#weather_alert_rules) |
| `webhook_deliveries` | [#`webhook_deliveries`](#webhook_deliveries) |
| `webhook_subscriptions` | [#`webhook_subscriptions`](#webhook_subscriptions) |
| `wizard_settings` | [#`wizard_settings`](#wizard_settings) |
| `zello_messages` | [#`zello_messages`](#zello_messages) |
| `zello_outbox` | [#`zello_outbox`](#zello_outbox) |
| `zello_user_config` | [#`zello_user_config`](#zello_user_config) |
| `zello_ws_tokens` | [#`zello_ws_tokens`](#zello_ws_tokens) |
| `zipcodes` | [#`zipcodes`](#zipcodes) |
| `_migrations` | [#`_migrations`](#_migrations) |
| `{}zello_messages` | [#`{}zello_messages`](#{}zello_messages) |
| `{}zello_user_config` | [#`{}zello_user_config`](#{}zello_user_config) |
| `{}zello_ws_tokens` | [#`{}zello_ws_tokens`](#{}zello_ws_tokens) |

### `access_requests`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(6) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `email` | varchar(128) | NO |  |  |  |
| `phone` | varchar(24) | NO |  |  |  |
| `reason` | longtext | NO |  |  |  |
| `sec_code` | varchar(24) | NO |  |  |  |
| `date` | datetime | NO |  |  |  |

### `action`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `ticket_id` | int(8) | NO |  | 0 |  |
| `date` | datetime | YES |  | NULL |  |
| `description` | text | NO |  |  |  |
| `user` | int(8) | YES |  | NULL |  |
| `action_type` | int(8) | YES |  | NULL |  |
| `responder` | text | YES |  | NULL |  |
| `updated` | datetime | YES |  | NULL |  |
| `source_channel` | varchar(32) | YES |  | NULL |  |
| `source_message_id` | int(11) | YES |  | NULL |  |
| `author_member_id` | int(11) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `active_sessions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `session_id` | varchar(128) | NO | UNI |  |  |
| `ip_address` | varchar(45) | YES |  | NULL |  |
| `user_agent` | varchar(512) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `last_active` | datetime | NO |  | current_timestamp() |  |
| `expires_at` | datetime | NO | MUL |  |  |

Indexes:
- `KEY idx_expires_at` (expires_at)
- `KEY idx_user_id` (user_id)
- `UNIQUE KEY uk_session_id` (session_id)

### `ai_conversations`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `callsign` | varchar(16) | NO | PRI |  |  |
| `first_seen_at` | datetime | NO |  |  |  |
| `last_seen_at` | datetime | NO |  |  |  |
| `exchange_count` | int(11) | NO |  | 0 |  |

### `ai_conversation_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `callsign` | varchar(16) | NO | MUL |  |  |
| `role` | enum('caller','assistant') | NO |  |  |  |
| `content` | text | NO |  |  |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_callsign_created` (callsign, created_at)

### `ai_pending_responses`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `channel_id` | int(11) | NO |  |  |  |
| `caller_src_id` | int(11) | NO |  |  |  |
| `caller_callsign` | varchar(16) | YES | MUL | NULL |  |
| `inbound_call_id` | varchar(16) | NO |  |  |  |
| `transcript` | text | NO |  |  |  |
| `draft_response` | text | YES |  | NULL |  |
| `final_response` | text | YES |  | NULL |  |
| `status` | enum('pending_generation','pending_approval','sent','discarded','filtered','auto_discarded','error') | NO | MUL | 'pending_generation' |  |
| `error_msg` | varchar(512) | YES |  | NULL |  |
| `tx_stream_id` | varchar(16) | YES |  | NULL |  |
| `api_tokens_in` | int(11) | YES |  | NULL |  |
| `api_tokens_out` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `decided_at` | datetime | YES |  | NULL |  |
| `decided_by` | int(11) | YES |  | NULL |  |
| `target_kind` | varchar(8) | NO |  | 'dmr' |  |
| `target_ref` | varchar(100) | YES |  | NULL |  |

Indexes:
- `KEY idx_caller` (caller_callsign, created_at)
- `KEY idx_status_created` (status, created_at)

### `ajax_log`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(6) | NO | PRI |  | auto_increment |
| `info` | text | NO |  |  |  |
| `_when` | datetime | NO |  |  |  |

### `allocates`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `group` | int(4) | NO |  | 1 |  |
| `type` | tinyint(1) | NO |  | 1 |  |
| `al_as_of` | datetime | YES |  | NULL |  |
| `al_status` | int(4) | YES |  | NULL |  |
| `resource_id` | int(4) | YES |  | NULL |  |
| `sys_comments` | varchar(64) | YES |  | NULL |  |
| `user_id` | int(4) | NO |  | 0 |  |

### `allocations`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `member_id` | int(2) | NO |  | 0 |  |
| `skill_type` | int(2) | NO |  | 0 |  |
| `skill_id` | int(2) | NO |  | 0 |  |
| `completed` | date | YES |  | NULL |  |
| `refresh_due` | date | YES |  | NULL |  |
| `frequency` | enum('Daily','Weekly','Permanent') | YES |  | NULL |  |
| `start` | datetime | YES |  | NULL |  |
| `end` | datetime | YES |  | NULL |  |
| `days` | varchar(256) | YES |  | NULL |  |
| `_on` | date | NO |  |  |  |

### `aprs_watchlist`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `callsign` | varchar(16) | NO | UNI |  |  |
| `note` | varchar(255) | YES |  | NULL |  |
| `added_by` | int(11) | YES |  | NULL |  |
| `added_by_name` | varchar(64) | YES |  | NULL |  |
| `added_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_added_at` (added_at)
- `UNIQUE KEY uk_callsign` (callsign)

### `assigns`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `as_of` | datetime | YES |  | NULL |  |
| `status_id` | int(4) | YES |  | 1 |  |
| `ticket_id` | int(4) | YES |  | NULL |  |
| `responder_id` | int(4) | YES |  | NULL |  |
| `comments` | varchar(64) | YES |  | NULL |  |
| `start_miles` | int(8) | YES |  | NULL |  |
| `on_scene_miles` | int(8) | YES |  | NULL |  |
| `end_miles` | int(8) | YES |  | NULL |  |
| `miles` | int(8) | YES |  | NULL |  |
| `user_id` | int(4) | NO |  |  |  |
| `dispatched` | datetime | YES |  | NULL |  |
| `responding` | datetime | YES |  | NULL |  |
| `clear` | datetime | YES |  | NULL |  |
| `on_scene` | datetime | YES |  | NULL |  |
| `facility_id` | int(8) | YES |  | NULL |  |
| `rec_facility_id` | int(8) | YES |  | NULL |  |
| `u2fenr` | datetime | YES |  | NULL |  |
| `u2farr` | datetime | YES |  | NULL |  |
| `current_zone_id` | int(11) | YES | MUL | NULL |  |
| `zone_updated_at` | datetime | YES |  | NULL |  |
| `last_checkin_at` | datetime | YES |  | NULL |  |
| `signed_out_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY current_zone_idx` (current_zone_id)
- `UNIQUE KEY ID` (id)

### `atak_unbound_uids`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `atak_uid` | varchar(120) | NO | UNI |  |  |
| `callsign_seen` | varchar(64) | YES |  | NULL |  |
| `transport` | enum('meshtastic','tak_server') | NO |  |  |  |
| `channel_ref` | varchar(120) | NO |  |  |  |
| `first_seen` | datetime | NO |  | current_timestamp() |  |
| `last_seen` | datetime | NO | MUL | current_timestamp() | on update current_timestamp() |
| `position_count` | int(11) | NO |  | 1 |  |
| `last_lat` | decimal(10,7) | YES |  | NULL |  |
| `last_lng` | decimal(10,7) | YES |  | NULL |  |
| `bound_to` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_atak_unbound_last` (last_seen)
- `UNIQUE KEY uk_atak_uid` (atak_uid)

### `audit_log_purges`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `ran_at` | datetime | NO | MUL | current_timestamp() |  |
| `cutoff_date` | datetime | NO |  |  |  |
| `rows_purged` | int(11) | NO |  | 0 |  |
| `archive_filename` | varchar(255) | NO |  | '' |  |
| `archive_sha256` | char(64) | NO |  | '' |  |
| `triggered_by` | enum('scheduled','manual') | NO |  | 'scheduled' |  |
| `triggered_by_user_id` | int(11) | YES |  | NULL |  |
| `status` | varchar(16) | NO |  | 'ok' |  |
| `detail` | varchar(512) | NO |  | '' |  |

Indexes:
- `KEY idx_ran_at` (ran_at)

### `auto_disp_status`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(3) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO |  |  |  |
| `status_val` | int(3) | NO |  |  |  |

### `auto_status`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(3) | NO | PRI |  | auto_increment |
| `text` | varchar(24) | NO |  |  |  |
| `status_val` | int(3) | NO |  |  |  |

### `bridge_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `bridge_id` | int(10) unsigned | NO | MUL |  |  |
| `token_hash` | char(64) | NO | UNI |  |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `last_used_at` | datetime | YES |  | NULL |  |
| `revoked_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_bridge` (bridge_id)
- `UNIQUE KEY uniq_token_hash` (token_hash)

### `capability_types`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | YES |  | NULL |  |
| `description` | longtext | YES |  | NULL |  |

### `capacity_categories`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO | UNI |  |  |
| `icon` | varchar(64) | YES |  | 'bi-hospital' |  |
| `unit_label` | varchar(32) | YES |  | 'beds' |  |
| `sort_order` | int(11) | NO |  | 0 |  |

Indexes:
- `UNIQUE KEY uk_cap_name` (name)

### `captions`

Engine: MyISAM · Collation: utf8mb3_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `capt` | varchar(64) | NO |  |  |  |
| `repl` | varchar(64) | NO |  |  |  |
| `_by` | int(7) | NO |  | 0 |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

### `captions_i18n`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `caption_key` | varchar(128) | NO | MUL |  |  |
| `lang` | varchar(8) | NO | MUL | 'en' |  |
| `value` | text | NO |  |  |  |
| `category` | varchar(64) | NO | MUL | 'general' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_category` (category)
- `KEY idx_lang` (lang)
- `UNIQUE KEY uk_key_lang` (caption_key, lang)

### `certifications`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO | UNI |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `category` | varchar(64) | YES |  | NULL |  |
| `fema_course_code` | varchar(32) | YES |  | NULL |  |
| `nims_credential_type` | varchar(64) | YES |  | NULL |  |
| `required` | tinyint(1) | YES |  | 0 |  |
| `refresh_months` | int(11) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY uniq_name` (name)

### `certs`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `certificate` | varchar(48) | NO |  |  |  |
| `source` | varchar(48) | NO |  |  |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `certs_x_user`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `certificate_id` | int(3) | NO |  |  |  |
| `user_id` | int(4) | NO |  |  |  |
| `date` | date | YES |  | NULL |  |
| `comment` | varchar(48) | YES |  | NULL |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | YES |  | NULL |  |
| `on` | datetime | YES |  | NULL |  |

### `chat_invites`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `to` | varchar(64) | NO |  |  |  |
| `_by` | int(7) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

### `chat_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO |  | 0 |  |
| `user_name` | varchar(64) | NO |  | 'system' |  |
| `channel` | varchar(64) | NO | MUL | 'general' |  |
| `recipient` | varchar(64) | NO |  | 'all' |  |
| `body` | text | NO |  |  |  |
| `msg_type` | varchar(32) | NO |  | 'text' |  |
| `priority` | varchar(16) | NO |  | 'normal' |  |
| `ticket_id` | int(11) | YES | MUL | NULL |  |
| `signal_id` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_channel` (channel)
- `KEY idx_created` (created_at)
- `KEY idx_ticket` (ticket_id)

### `chat_rooms`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(7) | NO | PRI |  | auto_increment |
| `room` | varchar(16) | NO |  |  |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `cities`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `city_zip` | int(5) unsigned zerofill | NO |  |  |  |
| `city_name` | varchar(50) | NO |  |  |  |
| `city_state` | char(2) | NO |  |  |  |
| `city_lat` | double | NO |  |  |  |
| `city_lng` | double | NO |  |  |  |
| `city_county` | varchar(50) | NO |  |  |  |

### `clones`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(16) | YES |  | NULL |  |
| `prefix` | varchar(8) | YES |  | NULL |  |
| `date` | datetime | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `clothing_types`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `clothing_item` | varchar(48) | YES |  | NULL |  |
| `description` | longtext | YES |  | NULL |  |
| `size` | varchar(48) | YES |  | NULL |  |

### `codes`

Engine: MyISAM · Collation: utf8mb3_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `code` | varchar(20) | NO |  |  |  |
| `text` | varchar(64) | NO |  |  |  |
| `sort` | int(3) | NO |  | 999 |  |
| `_by` | int(7) | NO |  | 0 |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

### `comm_channels`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `channel_key` | varchar(160) | NO | UNI |  |  |
| `adapter` | varchar(32) | NO | MUL |  |  |
| `label` | varchar(120) | NO |  |  |  |
| `short_label` | varchar(24) | YES |  | NULL |  |
| `color` | varchar(16) | YES |  | NULL |  |
| `config_json` | text | YES |  | NULL |  |
| `capabilities_json` | text | YES |  | NULL |  |
| `regulatory_class` | enum('amateur','commercial','pstn','internal') | NO |  | 'internal' |  |
| `tts_app` | varchar(64) | YES |  | NULL |  |
| `enabled` | tinyint(1) | NO | MUL | 0 |  |
| `managed` | tinyint(1) | NO |  | 0 |  |
| `sort_order` | int(11) | NO |  | 100 |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_adapter` (adapter)
- `KEY idx_enabled_sort` (enabled, sort_order)
- `UNIQUE KEY uniq_channel_key` (channel_key)

### `comm_channel_state`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `channel_id` | int(11) | NO | PRI |  |  |
| `state` | enum('connected','degraded','down','unknown') | NO |  | 'unknown' |  |
| `last_rx_at` | datetime | YES |  | NULL |  |
| `last_tx_at` | datetime | YES |  | NULL |  |
| `last_caller` | varchar(120) | YES |  | NULL |  |
| `last_error` | text | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

### `comm_modes`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(32) | NO | UNI |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `icon` | varchar(32) | YES |  | NULL |  |
| `color` | varchar(7) | NO |  | '#6c757d' |  |
| `fields_json` | text | NO |  |  |  |
| `capabilities` | varchar(64) | YES |  | NULL |  |
| `lookup_url` | varchar(255) | YES |  | NULL |  |
| `enabled` | tinyint(1) | NO |  | 1 |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `notes` | text | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY code` (code)

### `comm_routes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `src_channel_id` | int(11) | NO | MUL |  |  |
| `dst_channel_id` | int(11) | NO |  |  |  |
| `gain_db` | decimal(5,1) | NO |  | 0.0 |  |
| `priority` | int(11) | NO |  | 0 |  |
| `ducking` | tinyint(1) | NO |  | 1 |  |
| `enabled` | tinyint(1) | NO | MUL | 1 |  |
| `allow_cross_class` | tinyint(1) | NO |  | 0 |  |
| `note` | varchar(255) | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_enabled` (enabled)
- `UNIQUE KEY uniq_src_dst` (src_channel_id, dst_channel_id)

### `conditions`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(2) | NO | PRI |  | auto_increment |
| `title` | varchar(128) | NO |  |  |  |
| `description` | longtext | YES |  | NULL |  |
| `icon` | varchar(128) | NO |  |  |  |
| `_by` | int(6) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `config`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `key` | varchar(64) | NO | PRI |  |  |
| `value` | text | YES |  | NULL |  |

### `console_views`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(80) | NO |  |  |  |
| `icon` | varchar(48) | YES |  | NULL |  |
| `owner_user_id` | int(11) | YES | MUL | NULL |  |
| `based_on_view_id` | int(11) | YES |  | NULL |  |
| `rbac_json` | text | YES |  | NULL |  |
| `is_default_for_json` | text | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 100 |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_owner_sort` (owner_user_id, sort_order)

### `console_view_strips`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `view_id` | int(11) | NO | MUL |  |  |
| `channel_id` | int(11) | NO | MUL |  |  |
| `position` | int(11) | NO |  | 0 |  |
| `width` | tinyint(4) | NO |  | 1 |  |
| `layout_json` | text | YES |  | NULL |  |
| `overrides_json` | text | YES |  | NULL |  |
| `controls_json` | text | YES |  | NULL |  |

Indexes:
- `KEY idx_channel` (channel_id)
- `KEY idx_view_pos` (view_id, position)

### `constituents`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(7) | NO | PRI |  | auto_increment |
| `contact` | varchar(96) | NO |  |  |  |
| `street` | varchar(48) | YES |  | NULL |  |
| `apartment` | varchar(48) | YES |  | NULL |  |
| `community` | varchar(48) | YES |  | NULL |  |
| `city` | varchar(48) | YES |  | NULL |  |
| `post_code` | varchar(48) | YES |  | NULL |  |
| `state` | varchar(48) | YES |  | NULL |  |
| `miscellaneous` | varchar(255) | YES |  | NULL |  |
| `phone` | varchar(32) | NO |  |  |  |
| `phone_type` | varchar(24) | YES |  | NULL |  |
| `phone_2` | varchar(32) | YES |  | NULL |  |
| `phone_2_type` | varchar(24) | YES |  | NULL |  |
| `phone_3` | varchar(32) | YES |  | NULL |  |
| `phone_3_type` | varchar(24) | YES |  | NULL |  |
| `phone_4` | varchar(32) | YES |  | NULL |  |
| `phone_4_type` | varchar(24) | YES |  | NULL |  |
| `email` | varchar(48) | YES |  | NULL |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `reference` | varchar(48) | YES |  | NULL |  |
| `updated` | varchar(32) | YES |  | NULL |  |
| `_by` | int(7) | NO |  | 0 |  |

### `contacts`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(7) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | NO |  |  |  |
| `organization` | varchar(48) | YES |  | NULL |  |
| `phone` | varchar(24) | YES |  | NULL |  |
| `mobile` | varchar(24) | YES |  | NULL |  |
| `email` | varchar(48) | NO |  |  |  |
| `other` | varchar(24) | YES |  | NULL |  |
| `as-of` | datetime | NO |  |  |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `courses`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `course` | varchar(48) | NO |  |  |  |
| `source` | varchar(48) | NO |  |  |  |
| `location` | varchar(48) | NO |  |  |  |
| `duration` | varchar(48) | NO |  |  |  |
| `basis` | varchar(48) | NO |  |  |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `courses_x_user`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `courses_id` | int(4) | NO |  |  |  |
| `user_id` | int(4) | NO |  |  |  |
| `date` | date | YES |  | NULL |  |
| `comment` | varchar(48) | YES |  | NULL |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | YES |  | NULL |  |
| `on` | datetime | YES |  | NULL |  |

### `css_day`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `name` | tinytext | YES |  | NULL |  |
| `value` | tinytext | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `css_night`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `name` | tinytext | YES |  | NULL |  |
| `value` | tinytext | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `dashboard_layouts`

Engine: InnoDB · Collation: utf8mb4_general_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `layout_name` | varchar(50) | NO |  | 'default' |  |
| `layout_json` | text | NO |  |  |  |
| `hidden_widgets` | text | YES |  | '[]' |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `UNIQUE KEY idx_user_layout` (user_id, layout_name)

### `defined_fields`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | tinyint(2) | NO | PRI |  | auto_increment |
| `field_id` | int(4) | NO |  |  |  |
| `label` | varchar(32) | NO |  |  |  |
| `size` | int(4) | NO |  | 48 |  |
| `fieldset` | int(2) | YES |  | NULL |  |
| `_noedit` | int(2) | NO |  | 0 |  |
| `_by` | int(4) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `browser` | varchar(40) | NO |  |  |  |

### `dmr_channels`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `label` | varchar(96) | NO | UNI |  |  |
| `talkgroup` | varchar(32) | NO |  |  |  |
| `network` | varchar(64) | NO |  | 'BrandMeister' |  |
| `bridge_host` | varchar(128) | NO |  |  |  |
| `bridge_port` | int(11) | NO |  | 5000 |  |
| `bridge_token` | char(64) | NO |  |  |  |
| `bridge_token_format` | varchar(16) | NO |  | 'plain' |  |
| `usrp_listen_port` | int(11) | NO | UNI |  |  |
| `usrp_send_port` | int(11) | NO |  |  |  |
| `link_mode` | enum('rx_only','tx_only','bidirectional') | NO |  | 'rx_only' |  |
| `chat_channel` | varchar(64) | NO |  | 'dispatch' |  |
| `tts_engine` | varchar(32) | YES |  | NULL |  |
| `tts_voice` | varchar(96) | YES |  | NULL |  |
| `stt_engine` | varchar(32) | YES |  | NULL |  |
| `stt_partials` | tinyint(1) | NO |  | 0 |  |
| `route_to_broker` | tinyint(1) | NO |  | 1 |  |
| `enabled` | tinyint(1) | NO | MUL | 0 |  |
| `last_seen_at` | datetime | YES |  | NULL |  |
| `last_error` | text | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_enabled_tg` (enabled, talkgroup)
- `UNIQUE KEY uniq_label` (label)
- `UNIQUE KEY uniq_usrp_listen` (usrp_listen_port)

### `dmr_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) | NO | PRI |  | auto_increment |
| `channel_id` | int(11) | NO | MUL |  |  |
| `direction` | enum('rx','tx') | NO |  |  |  |
| `call_started_at` | datetime | NO |  |  |  |
| `call_ended_at` | datetime | YES |  | NULL |  |
| `duration_ms` | int(11) | YES |  | NULL |  |
| `talkgroup` | varchar(32) | YES |  | NULL |  |
| `radio_id` | varchar(32) | YES | MUL | NULL |  |
| `radio_callsign` | varchar(32) | YES |  | NULL |  |
| `member_id` | int(11) | YES | MUL | NULL |  |
| `transcript` | text | YES |  | NULL |  |
| `transcript_engine` | varchar(32) | YES |  | NULL |  |
| `transcript_partials` | text | YES |  | NULL |  |
| `audio_path` | varchar(255) | YES |  | NULL |  |
| `audio_format` | varchar(16) | YES |  | NULL |  |
| `routed_to` | varchar(128) | YES |  | NULL |  |
| `ticket_id` | int(11) | YES | MUL | NULL |  |
| `error` | text | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_channel_time` (channel_id, call_started_at)
- `KEY idx_member` (member_id)
- `KEY idx_radio_id` (radio_id)
- `KEY idx_ticket` (ticket_id)

### `dmr_ws_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `token` | varchar(64) | NO | PRI |  |  |
| `user_id` | int(11) | NO | MUL |  |  |
| `user` | varchar(64) | NO |  |  |  |
| `user_level` | int(11) | NO |  | 99 |  |
| `channel_id` | int(11) | YES |  | NULL |  |
| `created` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_created` (created)
- `KEY idx_user_id` (user_id)

### `documents`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `status` | enum('locked','unlocked','na') | NO |  | 'na' |  |
| `locked_by` | int(7) | NO |  |  |  |
| `locked_on` | datetime | YES |  | NULL |  |
| `info` | tinytext | YES |  | NULL |  |
| `keyword` | varchar(64) | YES |  | NULL |  |
| `type` | varchar(64) | YES |  | NULL |  |
| `size` | int(10) unsigned | NO |  |  |  |
| `author` | int(10) unsigned | YES |  | NULL |  |
| `source` | int(10) unsigned | YES |  | NULL |  |
| `maintainer` | int(10) unsigned | YES |  | NULL |  |
| `revision` | varchar(64) | YES |  | NULL |  |
| `created` | datetime | YES |  | NULL |  |
| `modified` | datetime | YES |  | NULL |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `documents_log`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `user_id` | int(10) unsigned | NO |  |  |  |
| `document_id` | int(10) unsigned | NO |  |  |  |
| `revision` | int(10) unsigned | NO |  |  |  |
| `date` | timestamp | NO |  | current_timestamp() | on update current_timestamp() |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `email_blacklist`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  |  |
| `email` | varchar(64) | NO |  |  |  |
| `_by` | int(7) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `email_lists`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `slug` | varchar(64) | NO | UNI |  |  |
| `description` | text | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `archived_at` | datetime | YES | MUL | NULL |  |

Indexes:
- `KEY idx_archived` (archived_at)
- `UNIQUE KEY uk_slug` (slug)

### `email_list_members`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `list_id` | int(11) | NO | MUL |  |  |
| `member_type` | enum('member','constituent','inline','list') | NO | MUL |  |  |
| `ref_id` | int(11) | YES |  | NULL |  |
| `inline_email` | varchar(255) | YES |  | NULL |  |
| `display_name` | varchar(128) | YES |  | NULL |  |
| `added_by` | int(11) | YES |  | NULL |  |
| `added_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_list` (list_id)
- `KEY idx_type_ref` (member_type, ref_id)

### `equipment_assignments`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `equipment_id` | int(11) | NO | MUL |  |  |
| `member_id` | int(11) | NO | MUL |  |  |
| `issued_by` | int(11) | YES |  | NULL |  |
| `issued_at` | datetime | NO |  |  |  |
| `returned_at` | datetime | YES | MUL | NULL |  |
| `returned_by` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY equipment_idx` (equipment_id)
- `KEY member_idx` (member_id)
- `KEY open_idx` (returned_at)

### `equipment_types`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `equipment_name` | varchar(48) | YES |  | NULL |  |
| `description` | longtext | YES |  | NULL |  |
| `spec` | varchar(48) | YES |  | NULL |  |
| `serial` | varchar(48) | YES |  | NULL |  |
| `condition` | enum('New','Good','Serviceable','Unusable','For Destruction') | NO |  | 'Good' |  |

### `events`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `event_name` | varchar(128) | YES |  | NULL |  |
| `description` | longtext | YES |  | NULL |  |
| `event_type` | int(11) | NO |  | 0 |  |

### `event_types`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |

### `event_zones`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `ticket_id` | int(11) | NO | MUL |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `code` | varchar(16) | NO |  |  |  |
| `color` | varchar(16) | YES |  | NULL |  |
| `geo_json` | text | YES |  | NULL |  |
| `sort_order` | int(11) | YES |  | 0 |  |
| `hide` | tinyint(1) | YES |  | 0 |  |

Indexes:
- `KEY ticket_idx` (ticket_id)

### `event_zone_templates`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `incident_type_id` | int(11) | YES | MUL | NULL |  |
| `zones_json` | mediumtext | NO |  |  |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO |  |  |  |

Indexes:
- `KEY type_idx` (incident_type_id)

### `external_api_rate_limits`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `token_id` | int(11) | NO | PRI |  |  |
| `bucket_min` | datetime | NO | PRI |  |  |
| `count` | int(11) | NO |  | 0 |  |

Indexes:
- `KEY idx_earl_bucket` (bucket_min)

### `external_api_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(120) | NO |  |  |  |
| `description` | varchar(512) | YES |  | NULL |  |
| `token_prefix` | varchar(16) | NO |  |  |  |
| `token_hash` | char(64) | NO | UNI |  |  |
| `scopes_json` | text | NO |  |  |  |
| `ip_allowlist_json` | text | YES |  | NULL |  |
| `user_id` | int(11) | NO | MUL |  |  |
| `rate_limit_per_hour` | int(11) | NO |  | 1000 |  |
| `created_by` | int(11) | NO |  |  |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `expires_at` | datetime | YES | MUL | NULL |  |
| `last_used_at` | datetime | YES |  | NULL |  |
| `last_used_ip` | varchar(45) | YES |  | NULL |  |
| `revoked_at` | datetime | YES | MUL | NULL |  |
| `revoked_by` | int(11) | YES |  | NULL |  |
| `revoked_reason` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_eat_expires` (expires_at)
- `KEY idx_eat_revoked` (revoked_at)
- `KEY idx_eat_user` (user_id)
- `UNIQUE KEY uk_eat_token_hash` (token_hash)

### `external_links`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `title` | varchar(128) | NO |  |  |  |
| `url` | varchar(512) | NO |  |  |  |
| `description` | varchar(255) | NO |  | '' |  |
| `icon` | varchar(64) | NO |  | 'bi-link-45deg' |  |
| `category` | varchar(64) | NO | MUL | 'General' |  |
| `sort_order` | int(11) | NO | MUL | 0 |  |
| `active` | tinyint(1) | NO |  | 1 |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_category` (category)
- `KEY idx_sort` (sort_order)

### `facilities`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `name` | text | YES |  | NULL |  |
| `street` | varchar(128) | YES |  | NULL |  |
| `city` | varchar(128) | YES |  | NULL |  |
| `state` | varchar(32) | YES |  | NULL |  |
| `direcs` | tinyint(2) | NO |  | 1 |  |
| `description` | text | NO |  |  |  |
| `beds_a` | varchar(6) | YES |  | NULL |  |
| `beds_o` | varchar(6) | YES |  | NULL |  |
| `beds_info` | varchar(2048) | YES |  | NULL |  |
| `capab` | varchar(255) | YES |  | NULL |  |
| `status_id` | int(4) | NO |  | 0 |  |
| `status_about` | varchar(512) | YES |  | NULL |  |
| `other` | varchar(96) | YES |  | NULL |  |
| `handle` | varchar(24) | YES |  | NULL |  |
| `icon_str` | char(3) | YES |  | NULL |  |
| `boundary` | int(3) | NO |  | 0 |  |
| `contact_name` | varchar(64) | YES |  | NULL |  |
| `contact_email` | varchar(64) | YES |  | NULL |  |
| `contact_phone` | varchar(15) | YES |  | NULL |  |
| `security_contact` | varchar(64) | YES |  | NULL |  |
| `security_email` | varchar(64) | YES |  | NULL |  |
| `security_phone` | varchar(15) | YES |  | NULL |  |
| `opening_hours` | mediumtext | YES |  | NULL |  |
| `access_rules` | mediumtext | YES |  | NULL |  |
| `security_reqs` | mediumtext | YES |  | NULL |  |
| `pager_p` | varchar(64) | YES |  | NULL |  |
| `pager_s` | varchar(64) | YES |  | NULL |  |
| `send_no` | varchar(64) | YES |  | NULL |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `type` | smallint(5) | NO |  | 0 |  |
| `updated` | datetime | YES |  | NULL |  |
| `user_id` | int(4) | YES |  | NULL |  |
| `callsign` | varchar(24) | YES |  | NULL |  |
| `notify_mailgroup` | int(4) | NO |  | 0 |  |
| `notify_email` | varchar(256) | YES |  | NULL |  |
| `notify_when` | int(1) | NO |  | 1 |  |
| `_by` | int(7) | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | datetime | YES |  | NULL |  |
| `org_id` | int(11) | YES |  | NULL |  |
| `bed_auto_mode` | varchar(16) | NO |  | 'manual' |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)
- `KEY idx_deleted_at` (deleted_at)

### `facility_bed_auto_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `assign_id` | int(11) | NO | MUL |  |  |
| `facility_id` | int(11) | NO | MUL |  |  |
| `responder_id` | int(11) | NO | MUL |  |  |
| `ticket_id` | int(11) | NO |  |  |  |
| `delta_a` | int(11) | NO |  | 0 |  |
| `delta_o` | int(11) | NO |  | 0 |  |
| `status_id` | int(11) | NO |  | 0 |  |
| `status_val` | varchar(64) | YES |  | '' |  |
| `applied_by` | int(11) | NO |  | 0 |  |
| `applied_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_facility_time` (facility_id, applied_at)
- `KEY idx_responder` (responder_id)
- `UNIQUE KEY uk_assign_facility` (assign_id, facility_id)

### `facility_capacity`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `facility_id` | int(11) | NO | MUL |  |  |
| `category_id` | int(11) | NO |  |  |  |
| `total` | int(11) | NO |  | 0 |  |
| `available` | int(11) | NO |  | 0 |  |
| `notes` | varchar(255) | YES |  | '' |  |
| `updated_by` | int(11) | NO |  | 0 |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_facility` (facility_id)
- `UNIQUE KEY uk_fac_cat` (facility_id, category_id)

### `facility_notes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `facility_id` | int(11) | NO | MUL |  |  |
| `category` | enum('note','status','beds') | NO |  | 'note' |  |
| `note` | varchar(1000) | NO |  | '' |  |
| `detail` | varchar(255) | YES |  | NULL |  |
| `user_id` | int(11) | NO |  | 0 |  |
| `username` | varchar(64) | NO |  | '' |  |
| `source_ip` | varchar(45) | YES |  | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_created` (created_at)
- `KEY idx_facility` (facility_id)

### `facnotes`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) | NO | PRI |  | auto_increment |
| `ticket_id` | int(10) | NO |  |  |  |
| `origin` | varchar(64) | YES |  | NULL |  |
| `destination` | varchar(64) | YES |  | NULL |  |
| `type` | int(7) | NO |  |  |  |
| `patient` | varchar(64) | NO |  |  |  |
| `ETA` | varchar(16) | NO |  |  |  |
| `notes` | longtext | YES |  | NULL |  |
| `_by` | int(7) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `fac_case_cat`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(6) | NO | PRI |  | auto_increment |
| `category` | varchar(64) | NO |  |  |  |
| `description` | longtext | YES |  | NULL |  |
| `color` | varchar(7) | YES |  | NULL |  |
| `bgcolor` | varchar(7) | YES |  | NULL |  |
| `facility` | int(7) | NO |  |  |  |

### `fac_status`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `status_val` | varchar(20) | NO |  |  |  |
| `description` | varchar(60) | NO |  |  |  |
| `group` | varchar(20) | YES |  | NULL |  |
| `status_available` | int(2) | NO |  | 0 |  |
| `status_unavailable` | int(2) | NO |  | 0 |  |
| `sort` | int(11) | NO |  | 0 |  |
| `bg_color` | varchar(16) | NO |  | 'transparent' |  |
| `text_color` | varchar(16) | NO |  | '#000000' |  |
| `_by` | int(7) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | datetime | NO |  |  |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `fac_types`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | NO |  |  |  |
| `description` | varchar(96) | NO |  |  |  |
| `icon` | int(3) | NO |  | 0 |  |
| `_by` | int(7) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | datetime | NO |  |  |  |

### `fcc_amateur`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `callsign` | varchar(16) | NO | UNI |  |  |
| `oper_class` | varchar(4) | YES |  | NULL |  |
| `first_name` | varchar(64) | YES |  | NULL |  |
| `last_name` | varchar(64) | YES | MUL | NULL |  |
| `middle_initial` | varchar(4) | YES |  | NULL |  |
| `suffix` | varchar(4) | YES |  | NULL |  |
| `entity_name` | varchar(200) | YES |  | NULL |  |
| `entity_type` | char(2) | YES |  | NULL |  |
| `street` | varchar(128) | YES |  | NULL |  |
| `city` | varchar(64) | YES |  | NULL |  |
| `state` | varchar(4) | YES | MUL | NULL |  |
| `zip` | varchar(16) | YES |  | NULL |  |
| `frn` | varchar(16) | YES | MUL | NULL |  |
| `grant_date` | date | YES |  | NULL |  |
| `expiry_date` | date | YES | MUL | NULL |  |
| `last_action` | date | YES |  | NULL |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `grid_square` | varchar(8) | YES |  | NULL |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_expiry` (expiry_date)
- `KEY idx_frn` (frn)
- `KEY idx_last_name_zip` (last_name, zip)
- `KEY idx_state` (state)
- `UNIQUE KEY uk_callsign` (callsign)

### `fcc_gmrs`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `callsign` | varchar(16) | YES | MUL | NULL |  |
| `first_name` | varchar(64) | YES |  | NULL |  |
| `last_name` | varchar(64) | YES | MUL | NULL |  |
| `middle_initial` | varchar(4) | YES |  | NULL |  |
| `suffix` | varchar(4) | YES |  | NULL |  |
| `entity_name` | varchar(200) | YES |  | NULL |  |
| `entity_type` | char(2) | YES |  | NULL |  |
| `street` | varchar(128) | YES |  | NULL |  |
| `city` | varchar(64) | YES |  | NULL |  |
| `state` | varchar(4) | YES | MUL | NULL |  |
| `zip` | varchar(16) | YES |  | NULL |  |
| `frn` | varchar(16) | YES | MUL | NULL |  |
| `grant_date` | date | YES |  | NULL |  |
| `expiry_date` | date | YES |  | NULL |  |
| `last_action` | date | YES |  | NULL |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_callsign` (callsign)
- `KEY idx_frn` (frn)
- `KEY idx_last_name_zip` (last_name, zip)
- `KEY idx_name_search` (last_name, first_name, zip)
- `KEY idx_state` (state)

### `fieldsets`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | NO |  |  |  |
| `label` | varchar(48) | YES |  | NULL |  |

### `files`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | mediumint(5) | NO | PRI |  | auto_increment |
| `title` | varchar(128) | NO |  |  |  |
| `filename` | varchar(512) | NO |  |  |  |
| `orig_filename` | varchar(512) | NO |  |  |  |
| `ticket_id` | mediumint(6) | NO |  | 0 |  |
| `responder_id` | mediumint(6) | NO |  | 0 |  |
| `facility_id` | mediumint(6) | NO |  | 0 |  |
| `mi_id` | mediumint(6) | NO |  | 0 |  |
| `type` | int(2) | YES |  | 0 |  |
| `filetype` | varchar(128) | NO |  |  |  |
| `_by` | int(7) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `files_x`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | mediumint(6) | NO | PRI |  | auto_increment |
| `file_id` | mediumint(6) | NO |  |  |  |
| `user_id` | int(4) | NO |  |  |  |

### `file_uploads`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `entity_type` | varchar(32) | NO | MUL |  |  |
| `entity_id` | int(11) | NO |  |  |  |
| `filename` | varchar(255) | NO |  |  |  |
| `orig_name` | varchar(255) | NO |  |  |  |
| `mime_type` | varchar(128) | NO |  | 'application/octet-stream' |  |
| `file_size` | bigint(20) | NO |  | 0 |  |
| `file_path` | varchar(512) | NO |  |  |  |
| `uploaded_by` | int(11) | NO |  | 0 |  |
| `uploaded_by_name` | varchar(64) | NO |  | '' |  |
| `description` | varchar(255) | YES |  | '' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_entity` (entity_type, entity_id)

### `geofences`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `markup_id` | int(11) | NO | MUL |  |  |
| `name` | varchar(128) | NO |  |  |  |
| `active` | tinyint(1) | NO | MUL | 1 |  |
| `alert_on_enter` | tinyint(1) | NO |  | 1 |  |
| `alert_on_exit` | tinyint(1) | NO |  | 1 |  |
| `alert_channels_json` | text | YES |  | NULL |  |
| `notify_users_json` | text | YES |  | NULL |  |
| `created_by` | int(11) | NO |  | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_active` (active)
- `KEY idx_markup_id` (markup_id)

### `geofence_unit_state`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `geofence_id` | int(11) | NO | MUL |  |  |
| `unit_identifier` | varchar(128) | NO |  |  |  |
| `state` | enum('inside','outside') | NO | MUL | 'outside' |  |
| `entered_at` | datetime | YES |  | NULL |  |
| `exited_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_state` (state)
- `UNIQUE KEY uk_fence_unit` (geofence_id, unit_identifier)

### `hints`

Engine: MyISAM · Collation: utf8mb3_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `tag` | varchar(8) | NO |  |  |  |
| `hint` | varchar(200) | NO |  |  |  |
| `_by` | int(7) | NO |  | 0 |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

### `ics`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `to` | varchar(256) | YES |  | NULL |  |
| `name` | varchar(256) | NO |  |  |  |
| `type` | varchar(64) | NO |  |  |  |
| `script` | varchar(24) | NO |  |  |  |
| `payload` | varchar(10000) | YES |  | NULL |  |
| `count` | int(3) | NO |  | 0 |  |
| `_by` | int(7) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_as-of` | timestamp | NO |  | current_timestamp() |  |
| `_sent` | timestamp | YES |  | NULL |  |
| `archived` | timestamp | YES |  | NULL |  |

### `ics_forms`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `form_type` | varchar(10) | NO | MUL | '213' |  |
| `incident_id` | int(11) | YES | MUL | NULL |  |
| `title` | varchar(255) | NO |  | '' |  |
| `form_data_json` | mediumtext | NO |  |  |  |
| `created_by` | int(11) | NO |  | 0 |  |
| `created_by_name` | varchar(128) | NO |  | '' |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `status` | varchar(16) | NO | MUL | 'draft' |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |
| `custom_type_id` | int(11) | YES | MUL | NULL |  |

Indexes:
- `KEY idx_deleted_at` (deleted_at)
- `KEY idx_ics_created_at` (created_at)
- `KEY idx_ics_custom_type_id` (custom_type_id)
- `KEY idx_ics_form_type` (form_type)
- `KEY idx_ics_incident_id` (incident_id)
- `KEY idx_ics_status` (status)

### `ics_form_types`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `slug` | varchar(60) | NO |  |  |  |
| `form_number` | varchar(40) | NO |  | '' |  |
| `form_title` | varchar(255) | NO |  | '' |  |
| `description` | varchar(500) | NO |  | '' |  |
| `fields_json` | mediumtext | NO |  |  |  |
| `badge_color` | varchar(20) | NO |  | 'secondary' |  |
| `icon` | varchar(40) | NO |  | 'bi-file-earmark-text' |  |
| `org_id` | int(11) | YES | MUL | NULL |  |
| `org_key` | int(11) | YES | MUL | NULL | STORED GENERATED |
| `status` | varchar(16) | NO | MUL | 'active' |  |
| `restrict_to_permission` | varchar(64) | YES |  | NULL |  |
| `created_by` | int(11) | NO |  | 0 |  |
| `created_by_name` | varchar(128) | NO |  | '' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_ics_form_type_org` (org_id)
- `KEY idx_ics_form_type_status` (status)
- `UNIQUE KEY uk_ics_form_type_slug_org` (org_key, slug)

### `ics_positions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(16) | NO | UNI |  |  |
| `title` | varchar(128) | NO |  |  |  |
| `category` | varchar(32) | YES |  | NULL |  |
| `description` | text | YES |  | NULL |  |
| `nims_typing_level` | tinyint(4) | YES |  | NULL |  |
| `required_certs` | text | YES |  | NULL |  |
| `sort_order` | int(11) | YES |  | 0 |  |
| `active` | tinyint(1) | YES |  | 1 |  |

Indexes:
- `UNIQUE KEY code` (code)

### `inbound_message_dedupe`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `channel` | varchar(64) | NO | MUL |  |  |
| `external_id` | varchar(255) | NO |  |  |  |
| `seen_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_seen_at` (seen_at)
- `UNIQUE KEY uk_channel_external_id` (channel, external_id)

### `incident_shares`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `ticket_id` | bigint(8) | NO | MUL |  |  |
| `shared_with_org_id` | int(11) | NO | MUL |  |  |
| `owning_org_id` | int(11) | NO |  |  |  |
| `routing_rule_id` | int(11) | YES | MUL | NULL |  |
| `access_tier` | enum('view','assist') | NO |  | 'view' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_by_name` | varchar(128) | NO |  | '' |  |
| `share_reason` | varchar(255) | YES |  | NULL |  |
| `revoked_at` | datetime | YES |  | NULL |  |
| `revoked_reason` | varchar(255) | YES |  | NULL |  |
| `revoked_by` | int(11) | YES |  | NULL |  |
| `revoked_by_name` | varchar(128) | NO |  | '' |  |

Indexes:
- `KEY idx_incident_share_org` (shared_with_org_id, revoked_at)
- `KEY idx_incident_share_rule` (routing_rule_id)
- `KEY idx_incident_share_ticket` (ticket_id)
- `UNIQUE KEY uk_incident_share` (ticket_id, shared_with_org_id)

### `insurance`

Engine: MyISAM · Collation: utf8mb3_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `ins_value` | varchar(64) | NO |  |  |  |
| `sort_order` | int(3) | NO |  | 0 |  |
| `_by` | int(7) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() | on update current_timestamp() |

### `internal_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `from_user_id` | int(11) | NO | MUL |  |  |
| `subject` | varchar(255) | NO |  | '' |  |
| `body` | text | NO |  |  |  |
| `priority` | enum('normal','high','urgent') | NO |  | 'normal' |  |
| `incident_id` | int(11) | YES | MUL | NULL |  |
| `is_broadcast` | tinyint(1) | NO | MUL | 0 |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |
| `deleted_by_sender_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_im_broadcast` (is_broadcast)
- `KEY idx_im_created` (created_at)
- `KEY idx_im_deleted_by_sender` (from_user_id, deleted_by_sender_at)
- `KEY idx_im_from_user` (from_user_id)
- `KEY idx_im_incident` (incident_id)

### `in_types`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `type` | varchar(40) | NO |  |  |  |
| `description` | varchar(255) | NO |  |  |  |
| `protocol` | text | YES |  | NULL |  |
| `set_severity` | int(1) | NO |  | 0 |  |
| `watch` | int(2) | NO |  | 0 |  |
| `group` | varchar(20) | YES |  | NULL |  |
| `sort` | int(11) | YES |  | NULL |  |
| `radius` | int(4) | YES |  | NULL |  |
| `color` | varchar(8) | YES |  | NULL |  |
| `opacity` | int(3) | YES |  | NULL |  |
| `notify_mailgroup` | int(4) | YES |  | NULL |  |
| `notify_email` | varchar(256) | YES |  | NULL |  |
| `notify_when` | int(1) | NO |  | 1 |  |
| `match_pattern` | text | YES |  | NULL |  |
| `default_security_label_id` | int(10) unsigned | YES |  | NULL |  |
| `public_board_never_publish` | tinyint(1) | NO |  | 1 |  |
| `public_board_publish_delay_secs` | int(10) unsigned | YES |  | NULL |  |
| `public_board_visibility` | enum('full','presence_only') | NO |  | 'full' |  |
| `public_board_stub_label` | varchar(64) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `known_sources`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `contact` | varchar(64) | NO |  |  |  |
| `email` | varchar(64) | NO |  |  |  |
| `allow` | int(2) | NO |  | 0 |  |
| `_by` | int(7) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `languages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `code` | varchar(8) | NO | PRI |  |  |
| `display_name` | varchar(64) | NO |  |  |  |
| `native_name` | varchar(64) | NO |  | '' |  |
| `enabled` | tinyint(1) | NO | MUL | 1 |  |
| `is_default` | tinyint(1) | NO | MUL | 0 |  |
| `sort_order` | int(11) | NO | MUL | 100 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_default` (is_default)
- `KEY idx_enabled` (enabled)
- `KEY idx_sort` (sort_order)

### `location_ingest_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `label` | varchar(120) | NO |  |  |  |
| `secret_hash` | char(64) | NO | UNI |  |  |
| `provider_id` | int(11) | YES | MUL | NULL |  |
| `device_unique_id` | varchar(120) | YES | MUL | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `last_used_at` | datetime | YES |  | NULL |  |
| `last_used_ip` | varchar(45) | YES |  | NULL |  |
| `revoked_at` | datetime | YES | MUL | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_lit_device` (device_unique_id)
- `KEY idx_lit_provider` (provider_id)
- `KEY idx_lit_revoked` (revoked_at)
- `UNIQUE KEY uk_secret_hash` (secret_hash)

### `location_providers`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(32) | NO | UNI |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `enabled` | tinyint(1) | NO | MUL | 0 |  |
| `priority` | int(11) | NO | MUL | 50 |  |
| `config_json` | text | YES |  | NULL |  |
| `icon` | varchar(64) | YES |  | NULL |  |
| `color` | varchar(16) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `max_age_seconds` | int(11) | NO |  | 300 |  |

Indexes:
- `UNIQUE KEY code` (code)
- `KEY idx_lp_enabled` (enabled)
- `KEY idx_lp_priority` (priority)

### `location_reports`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) | NO | PRI |  | auto_increment |
| `provider_id` | int(11) | NO | MUL |  |  |
| `unit_identifier` | varchar(64) | NO | MUL |  |  |
| `lat` | decimal(10,7) | NO |  |  |  |
| `lng` | decimal(10,7) | NO |  |  |  |
| `altitude` | decimal(7,1) | YES |  | NULL |  |
| `speed` | decimal(6,1) | YES |  | NULL |  |
| `heading` | decimal(5,1) | YES |  | NULL |  |
| `accuracy` | decimal(6,1) | YES |  | NULL |  |
| `battery` | tinyint(3) unsigned | YES |  | NULL |  |
| `raw_data` | text | YES |  | NULL |  |
| `reported_at` | datetime | NO | MUL |  |  |
| `received_at` | datetime | YES |  | current_timestamp() |  |
| `auth_token_id` | int(11) | YES | MUL | NULL |  |

Indexes:
- `KEY idx_auth_token` (auth_token_id)
- `KEY idx_lr_provider` (provider_id)
- `KEY idx_lr_reported` (reported_at)
- `KEY idx_lr_unit` (unit_identifier)
- `KEY idx_lr_unit_time` (unit_identifier, reported_at)

### `log`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `who` | tinyint(7) | YES |  | NULL |  |
| `from` | varchar(20) | YES |  | NULL |  |
| `when` | datetime | YES |  | NULL |  |
| `code` | smallint(7) | NO |  | 0 |  |
| `ticket_id` | int(7) | YES |  | NULL |  |
| `responder_id` | int(7) | YES |  | NULL |  |
| `member_id` | int(7) | YES |  | NULL |  |
| `info` | varchar(2048) | YES |  | NULL |  |
| `facility` | int(7) | YES |  | NULL |  |
| `rec_facility` | int(7) | YES |  | NULL |  |
| `mileage` | int(8) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `logins`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `ip` | varchar(15) | NO |  |  |  |
| `salt` | varchar(36) | NO |  |  |  |
| `intime` | timestamp | NO |  | current_timestamp() | on update current_timestamp() |

### `login_attempts`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) | NO | PRI |  | auto_increment |
| `username` | varchar(255) | NO | MUL |  |  |
| `ip_address` | varchar(45) | YES | MUL | NULL |  |
| `user_agent` | varchar(512) | YES |  | NULL |  |
| `success` | tinyint(1) | NO |  | 0 |  |
| `failure_reason` | varchar(64) | YES |  | NULL |  |
| `cleared_at` | datetime | YES |  | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_created_at` (created_at)
- `KEY idx_ip_created` (ip_address, created_at)
- `KEY idx_username_created` (username, created_at)

### `mailgroup`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO |  |  |  |
| `notes` | text | YES |  | NULL |  |

### `mailgroup_x`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `mailgroup` | int(4) | NO |  |  |  |
| `contacts` | int(4) | YES |  | 0 |  |
| `responder` | int(4) | YES |  | 0 |  |

### `major_incidents`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `description` | longtext | NO |  |  |  |
| `type` | int(4) | NO |  |  |  |
| `mi_status` | enum('Open','Closed') | NO |  | 'Open' |  |
| `gold` | int(4) | NO |  |  |  |
| `silver` | int(4) | NO |  |  |  |
| `bronze` | int(4) | NO |  |  |  |
| `level4` | int(4) | NO |  |  |  |
| `level5` | int(4) | NO |  |  |  |
| `level6` | int(4) | NO |  |  |  |
| `gold_loc` | int(6) | NO |  | 0 |  |
| `gold_street` | varchar(64) | YES |  | NULL |  |
| `gold_city` | varchar(48) | YES |  | NULL |  |
| `gold_state` | varchar(4) | YES |  | NULL |  |
| `gold_lat` | varchar(16) | YES |  | NULL |  |
| `gold_lng` | varchar(16) | YES |  | NULL |  |
| `silver_loc` | int(6) | NO |  | 0 |  |
| `silver_street` | varchar(64) | YES |  | NULL |  |
| `silver_city` | varchar(48) | YES |  | NULL |  |
| `silver_state` | varchar(4) | YES |  | NULL |  |
| `silver_lat` | varchar(16) | YES |  | NULL |  |
| `silver_lng` | varchar(16) | YES |  | NULL |  |
| `bronze_loc` | int(6) | NO |  | 0 |  |
| `bronze_street` | varchar(64) | YES |  | NULL |  |
| `bronze_city` | varchar(48) | YES |  | NULL |  |
| `bronze_state` | varchar(4) | YES |  | NULL |  |
| `bronze_lat` | varchar(16) | YES |  | NULL |  |
| `bronze_lng` | varchar(16) | YES |  | NULL |  |
| `level4_loc` | int(6) | NO |  | 0 |  |
| `level4_street` | varchar(64) | YES |  | NULL |  |
| `level4_city` | varchar(48) | YES |  | NULL |  |
| `level4_state` | varchar(4) | YES |  | NULL |  |
| `level4_lat` | varchar(16) | YES |  | NULL |  |
| `level4_lng` | varchar(16) | YES |  | NULL |  |
| `level5_loc` | int(6) | NO |  | 0 |  |
| `level5_street` | varchar(64) | YES |  | NULL |  |
| `level5_city` | varchar(48) | YES |  | NULL |  |
| `level5_state` | varchar(4) | YES |  | NULL |  |
| `level5_lat` | varchar(16) | YES |  | NULL |  |
| `level5_lng` | varchar(16) | YES |  | NULL |  |
| `level6_loc` | int(6) | NO |  | 0 |  |
| `level6_street` | varchar(64) | YES |  | NULL |  |
| `level6_city` | varchar(48) | YES |  | NULL |  |
| `level6_state` | varchar(4) | YES |  | NULL |  |
| `level6_lat` | varchar(16) | YES |  | NULL |  |
| `level6_lng` | varchar(16) | YES |  | NULL |  |
| `boundary` | int(4) | NO |  |  |  |
| `inc_startime` | datetime | NO |  |  |  |
| `inc_endtime` | datetime | NO |  |  |  |
| `incident_notes` | longtext | YES |  | NULL |  |
| `_by` | int(11) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `map_image_overlays`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO |  |  |  |
| `file_path` | varchar(255) | NO |  |  |  |
| `mime` | varchar(64) | NO |  |  |  |
| `anchor_json` | text | YES |  | NULL |  |
| `opacity` | decimal(3,2) | YES |  | 0.70 |  |
| `enabled` | tinyint(1) | YES | MUL | 1 |  |
| `sort_order` | int(11) | YES | MUL | 0 |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_enabled` (enabled)
- `KEY idx_sort` (sort_order)

### `map_markups`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `category_id` | int(11) | YES | MUL | NULL |  |
| `name` | varchar(128) | NO |  |  |  |
| `description` | text | YES |  | NULL |  |
| `markup_type` | varchar(32) | NO |  | 'polygon' |  |
| `geojson` | text | NO |  |  |  |
| `style` | text | YES |  | NULL |  |
| `visible` | tinyint(1) | NO | MUL | 1 |  |
| `ident` | varchar(64) | YES |  | '' |  |
| `notes` | text | YES |  | NULL |  |
| `apply_to` | varchar(64) | YES | MUL | 'base_map' |  |
| `created_by` | int(11) | NO |  | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_apply_to` (apply_to)
- `KEY idx_category` (category_id)
- `KEY idx_visible` (visible)

### `markup_categories`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO | UNI |  |  |
| `icon` | varchar(64) | YES |  | 'bi-geo-alt' |  |
| `color` | varchar(8) | YES |  | '#FF0000' |  |
| `description` | varchar(255) | YES |  | '' |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `UNIQUE KEY uk_cat_name` (name)

### `mdb_files`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | smallint(3) | NO | PRI |  | auto_increment |
| `member_id` | smallint(3) | NO |  |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `shortname` | varchar(32) | NO |  |  |  |
| `description` | varchar(24) | NO |  |  |  |
| `filesize` | varchar(12) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |

### `mdb_settings`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `name` | tinytext | YES |  | NULL |  |
| `value` | varchar(512) | YES |  | NULL |  |

### `member`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(8) | NO | PRI |  | auto_increment |
| `field1` | varchar(28) | YES |  | NULL |  |
| `field2` | varchar(28) | YES |  | NULL |  |
| `field3` | int(4) | YES |  | 0 |  |
| `field4` | varchar(16) | YES |  | NULL |  |
| `field5` | varchar(64) | YES |  | NULL |  |
| `field6` | varchar(48) | YES |  | NULL |  |
| `field7` | varchar(20) | YES |  | NULL |  |
| `field8` | enum('Yes','No') | NO |  | 'Yes' |  |
| `field9` | varchar(28) | YES |  | NULL |  |
| `field10` | varchar(28) | YES |  | NULL |  |
| `field11` | varchar(12) | YES |  | NULL |  |
| `field12` | double | YES |  | NULL |  |
| `field13` | double | YES |  | NULL |  |
| `field14` | longtext | YES |  | NULL |  |
| `field15` | enum('Yes','No') | NO |  | 'No' |  |
| `field16` | datetime | YES |  | NULL |  |
| `field17` | datetime | YES |  | NULL |  |
| `field18` | datetime | YES |  | NULL |  |
| `field19` | enum('Yes','No') | NO |  | 'No' |  |
| `field20` | longtext | YES |  | NULL |  |
| `field21` | int(4) | NO |  | 0 |  |
| `field22` | varchar(1024) | YES |  | NULL |  |
| `field23` | varchar(1024) | YES |  | NULL |  |
| `field24` | varchar(1024) | YES |  | NULL |  |
| `field25` | varchar(1024) | YES |  | NULL |  |
| `field26` | varchar(1024) | YES |  | NULL |  |
| `field27` | varchar(1024) | YES |  | NULL |  |
| `field28` | varchar(1024) | YES |  | NULL |  |
| `field29` | varchar(1024) | YES |  | NULL |  |
| `field30` | varchar(1024) | YES |  | NULL |  |
| `field31` | varchar(1024) | YES |  | NULL |  |
| `field32` | varchar(1024) | YES |  | NULL |  |
| `field33` | varchar(1024) | YES |  | NULL |  |
| `field34` | varchar(1024) | YES |  | NULL |  |
| `field35` | varchar(1024) | YES |  | NULL |  |
| `field36` | varchar(1024) | YES |  | NULL |  |
| `field37` | varchar(1024) | YES |  | NULL |  |
| `field38` | varchar(1024) | YES |  | NULL |  |
| `field39` | varchar(1024) | YES |  | NULL |  |
| `field40` | varchar(1024) | YES |  | NULL |  |
| `field41` | varchar(1024) | YES |  | NULL |  |
| `field42` | varchar(1024) | YES |  | NULL |  |
| `field43` | varchar(1024) | YES |  | NULL |  |
| `field44` | varchar(1024) | YES |  | NULL |  |
| `field45` | varchar(1024) | YES |  | NULL |  |
| `field46` | enum('Yes','No') | YES |  | 'No' |  |
| `field47` | enum('Yes','No') | YES |  | 'No' |  |
| `field48` | enum('Yes','No') | YES |  | 'No' |  |
| `field49` | enum('Yes','No') | YES |  | 'No' |  |
| `field50` | enum('Yes','No') | YES |  | NULL |  |
| `field51` | enum('Yes','No') | YES |  | 'No' |  |
| `field52` | enum('Yes','No') | YES |  | 'No' |  |
| `field53` | enum('Yes','No') | YES |  | 'No' |  |
| `field54` | enum('Yes','No') | YES |  | 'No' |  |
| `field55` | enum('Yes','No') | YES |  | 'No' |  |
| `field56` | datetime | YES |  | NULL |  |
| `field57` | datetime | YES |  | NULL |  |
| `field58` | datetime | YES |  | NULL |  |
| `field59` | datetime | YES |  | NULL |  |
| `field60` | datetime | YES |  | NULL |  |
| `field61` | datetime | YES |  | NULL |  |
| `field62` | datetime | YES |  | NULL |  |
| `field63` | datetime | YES |  | NULL |  |
| `field64` | datetime | YES |  | NULL |  |
| `field65` | datetime | YES |  | NULL |  |
| `_by` | int(7) | NO |  | 1 |  |
| `_on` | datetime | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `first_name` | varchar(28) | YES |  | NULL | VIRTUAL GENERATED |
| `last_name` | varchar(28) | YES | MUL | NULL | VIRTUAL GENERATED |
| `middle_name` | varchar(64) | YES |  | NULL |  |
| `callsign` | varchar(16) | YES | MUL | NULL | VIRTUAL GENERATED |
| `email` | varchar(48) | YES |  | NULL | VIRTUAL GENERATED |
| `title` | varchar(64) | YES |  | NULL |  |
| `dob` | date | YES |  | NULL |  |
| `member_type_id` | int(11) | YES | MUL | NULL |  |
| `member_status_id` | int(11) | YES | MUL | NULL |  |
| `team_id` | int(11) | YES | MUL | NULL |  |
| `available` | enum('Yes','No') | YES |  | 'Yes' |  |
| `join_date` | date | YES |  | NULL |  |
| `membership_due` | date | YES |  | NULL |  |
| `phone_home` | varchar(24) | YES |  | NULL |  |
| `phone_work` | varchar(24) | YES |  | NULL |  |
| `street` | varchar(128) | YES |  | NULL |  |
| `city` | varchar(64) | YES |  | NULL |  |
| `county` | varchar(64) | YES |  | NULL |  |
| `state` | varchar(4) | YES |  | NULL |  |
| `zip` | varchar(16) | YES |  | NULL |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `emergency_contact` | varchar(128) | YES |  | NULL |  |
| `emergency_phone` | varchar(24) | YES |  | NULL |  |
| `emergency_relation` | varchar(64) | YES |  | NULL |  |
| `medical_info` | text | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `responder_id` | int(11) | YES | MUL | NULL |  |
| `user_id` | int(11) | YES | MUL | NULL |  |
| `photo_url` | varchar(255) | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |
| `phone` | varchar(20) | YES |  | NULL | VIRTUAL GENERATED |
| `phone_cell` | varchar(20) | YES |  | NULL | VIRTUAL GENERATED |
| `photo_file_id` | int(11) | YES |  | NULL |  |
| `owntracks_overrides` | text | YES |  | NULL |  |

Indexes:
- `KEY idx_callsign` (callsign)
- `KEY idx_deleted_at` (deleted_at)
- `KEY idx_last_name` (last_name)
- `KEY idx_member_deleted` (deleted_at)
- `KEY idx_member_status` (member_status_id)
- `KEY idx_member_type` (member_type_id)
- `KEY idx_responder_id` (responder_id)
- `KEY idx_team` (team_id)
- `KEY idx_user_id` (user_id)

### `member_callsigns`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `callsign` | varchar(16) | NO |  |  |  |
| `license_type` | varchar(32) | NO |  | 'amateur' |  |
| `oper_class` | varchar(16) | YES |  | NULL |  |
| `frn` | varchar(16) | YES |  | NULL |  |
| `grant_date` | date | YES |  | NULL |  |
| `expiry_date` | date | YES |  | NULL |  |
| `grid_square` | varchar(8) | YES |  | NULL |  |
| `is_primary` | tinyint(1) | NO |  | 0 |  |
| `source` | varchar(32) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_member` (member_id)
- `UNIQUE KEY uq_member_call` (member_id, callsign)

### `member_certifications`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `certification_id` | int(11) | NO | MUL |  |  |
| `earned_date` | date | YES |  | NULL |  |
| `expiry_date` | date | YES |  | NULL |  |
| `certificate_number` | varchar(64) | YES |  | NULL |  |
| `issuing_authority` | varchar(128) | YES |  | NULL |  |
| `verification_url` | varchar(512) | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY certification_id` (certification_id)
- `KEY member_id` (member_id)

### `member_comm_identifiers`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `comm_mode_id` | int(11) | NO | MUL |  |  |
| `label` | varchar(64) | YES |  | NULL |  |
| `values_json` | text | NO |  |  |  |
| `is_primary` | tinyint(1) | NO |  | 0 |  |
| `notes` | varchar(255) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |

Indexes:
- `KEY idx_comm_mode_id` (comm_mode_id)
- `KEY idx_member_id` (member_id)
- `KEY idx_member_mode` (member_id, comm_mode_id)

### `member_ics_qualifications`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `ics_position_id` | int(11) | NO | MUL |  |  |
| `qualification_level` | enum('Trainee','Qualified','Expert') | YES |  | 'Trainee' |  |
| `ptb_status` | enum('Not Started','In Progress','Completed') | YES |  | 'Not Started' |  |
| `ptb_start_date` | date | YES |  | NULL |  |
| `ptb_completion_date` | date | YES |  | NULL |  |
| `evaluator` | varchar(128) | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY ics_position_id` (ics_position_id)
- `KEY member_id` (member_id)
- `UNIQUE KEY member_position` (member_id, ics_position_id)

### `member_organizations`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `org_id` | int(11) | NO | MUL |  |  |
| `member_type_id` | int(11) | YES |  | NULL |  |
| `role_id` | int(11) | YES |  | NULL |  |
| `role` | varchar(32) | YES |  | NULL |  |
| `join_date` | date | YES |  | NULL |  |
| `status` | enum('active','inactive','pending') | NO |  | 'active' |  |
| `notes` | varchar(255) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_member_id` (member_id)
- `KEY idx_org_id` (org_id)
- `UNIQUE KEY uq_member_org` (member_id, org_id)

### `member_status`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `status_val` | varchar(48) | NO |  |  |  |
| `description` | mediumtext | NO |  |  |  |
| `color` | varchar(8) | NO |  | '#000000' |  |
| `text_color` | varchar(8) | NO |  | '#FFFFFF' |  |
| `background` | varchar(8) | NO |  | '#FFFFFF' |  |
| `sort_order` | int(11) | NO |  | 0 |  |

### `member_time_entries`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `org_id` | int(11) | YES | MUL | NULL |  |
| `started_at` | datetime | NO | MUL |  |  |
| `ended_at` | datetime | NO |  |  |  |
| `activity_type` | varchar(48) | NO |  |  |  |
| `category` | varchar(32) | YES | MUL | NULL |  |
| `incident_id` | int(11) | YES | MUL | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `status` | enum('self_reported','approved','rejected') | NO | MUL | 'self_reported' |  |
| `submitted_by` | int(11) | YES |  | NULL |  |
| `approved_by` | int(11) | YES |  | NULL |  |
| `approved_at` | datetime | YES |  | NULL |  |
| `rejection_reason` | varchar(255) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `hours` | decimal(10,2) | YES |  | NULL | VIRTUAL GENERATED |

Indexes:
- `KEY idx_category` (category)
- `KEY idx_incident` (incident_id)
- `KEY idx_member` (member_id, started_at)
- `KEY idx_org` (org_id, started_at)
- `KEY idx_started` (started_at)
- `KEY idx_status` (status)

### `member_tracking_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `token_label` | varchar(64) | YES |  | NULL |  |
| `token_hash` | char(64) | YES | MUL | NULL |  |
| `secret_hash` | char(64) | NO | MUL |  |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `valid_from` | datetime | YES | MUL | current_timestamp() |  |
| `valid_until` | datetime | YES |  | NULL |  |
| `last_used_at` | datetime | YES | MUL | NULL |  |
| `revoked_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_last_used` (last_used_at)
- `KEY idx_member` (member_id)
- `KEY idx_secret_hash` (secret_hash)
- `KEY idx_token_hash` (token_hash)
- `KEY idx_validity` (valid_from, valid_until, revoked_at)

### `member_types`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(16) | NO |  |  |  |
| `description` | longtext | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_by` | int(7) | NO |  |  |  |
| `color` | varchar(8) | NO |  | '#000000' |  |
| `text_color` | varchar(8) | NO |  | '#FFFFFF' |  |
| `background` | varchar(8) | NO |  | '#FFFFFF' |  |
| `org_id` | int(11) | YES | MUL | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |

Indexes:
- `KEY idx_org_id` (org_id)

### `mesh_bridges`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `label` | varchar(64) | NO |  |  |  |
| `host_hint` | varchar(128) | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |
| `last_seen_at` | datetime | YES | MUL | NULL |  |
| `last_packet_at` | datetime | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `revoked_at` | datetime | YES |  | NULL |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_deleted_at` (deleted_at)
- `KEY idx_last_seen` (last_seen_at)

### `mesh_bridge_channels`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `bridge_id` | int(11) | NO | MUL |  |  |
| `channel_id` | int(11) | NO | MUL |  |  |
| `slot` | int(11) | NO |  | 0 |  |
| `assigned_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_channel` (channel_id)
- `UNIQUE KEY uk_bridge_slot` (bridge_id, slot)

### `mesh_channels`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(32) | NO | UNI |  |  |
| `psk_b64` | varchar(64) | NO |  |  |  |
| `region` | varchar(16) | YES |  | 'US' |  |
| `modem_preset` | varchar(32) | YES |  | 'LONG_FAST' |  |
| `downlink_enabled` | tinyint(1) | NO |  | 1 |  |
| `uplink_enabled` | tinyint(1) | NO |  | 1 |  |
| `is_primary` | tinyint(1) | NO | MUL | 0 |  |
| `notes` | text | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `archived_at` | datetime | YES |  | NULL |  |
| `atak_enabled` | tinyint(1) | NO |  | 0 |  |
| `atak_sensitive_flag` | tinyint(1) | NO |  | 1 |  |
| `atak_push_incidents` | tinyint(1) | NO |  | 1 |  |
| `atak_push_units` | tinyint(1) | NO |  | 1 |  |
| `atak_push_facilities` | tinyint(1) | NO |  | 0 |  |
| `atak_push_chat` | tinyint(1) | NO |  | 1 |  |
| `atak_marker_action` | enum('new_incident','note_nearest') | NO |  | 'new_incident' |  |
| `atak_position_min_secs` | int(11) | NO |  | 60 |  |
| `atak_position_min_m` | int(11) | NO |  | 25 |  |

Indexes:
- `KEY idx_primary` (is_primary)
- `UNIQUE KEY uk_name` (name)

### `mesh_nodes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `node_id` | varchar(32) | NO | PRI |  |  |
| `protocol` | varchar(16) | YES |  | NULL |  |
| `bridge_id` | int(11) | YES | MUL | NULL |  |
| `short_name` | varchar(32) | YES |  | NULL |  |
| `long_name` | varchar(128) | YES |  | NULL |  |
| `hw_model` | varchar(64) | YES |  | NULL |  |
| `role` | varchar(32) | YES |  | NULL |  |
| `last_lat` | double | YES |  | NULL |  |
| `last_lng` | double | YES |  | NULL |  |
| `last_alt_m` | int(11) | YES |  | NULL |  |
| `last_snr` | float | YES |  | NULL |  |
| `last_rssi` | int(11) | YES |  | NULL |  |
| `last_hops` | int(11) | YES |  | NULL |  |
| `last_seen_at` | datetime(3) | YES | MUL | NULL |  |
| `first_seen_at` | datetime | NO |  | current_timestamp() |  |
| `notes` | text | YES |  | NULL |  |
| `public_key` | varchar(128) | YES | MUL | NULL |  |
| `firmware_ver` | varchar(64) | YES |  | NULL |  |
| `manuf_name` | varchar(64) | YES |  | NULL |  |
| `adv_type` | tinyint(3) unsigned | YES |  | NULL |  |
| `radio_freq` | decimal(8,3) | YES |  | NULL |  |
| `radio_bw` | decimal(6,1) | YES |  | NULL |  |
| `radio_sf` | tinyint(3) unsigned | YES |  | NULL |  |
| `radio_cr` | tinyint(3) unsigned | YES |  | NULL |  |
| `tx_power` | smallint(6) | YES |  | NULL |  |
| `max_tx_power` | smallint(6) | YES |  | NULL |  |
| `adv_lat` | decimal(10,6) | YES |  | NULL |  |
| `adv_lon` | decimal(10,6) | YES |  | NULL |  |
| `is_self` | tinyint(1) | NO |  | 0 |  |
| `self_info_at` | datetime(3) | YES |  | NULL |  |

Indexes:
- `KEY idx_bridge` (bridge_id)
- `KEY idx_last_seen` (last_seen_at)
- `KEY idx_public_key` (public_key)

### `mesh_outbox`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO | PRI |  | auto_increment |
| `queued_at` | datetime | NO |  | current_timestamp() |  |
| `queued_by` | int(11) | YES |  | NULL |  |
| `target_bridge_id` | int(10) unsigned | YES | MUL | NULL |  |
| `target_protocol` | enum('meshtastic','meshcore','any') | NO |  | 'any' |  |
| `kind` | varchar(32) | NO |  |  |  |
| `payload_json` | text | NO |  |  |  |
| `status` | enum('queued','claimed','sent','failed') | NO | MUL | 'queued' |  |
| `claimed_at` | datetime | YES |  | NULL |  |
| `claimed_by_bridge_id` | int(10) unsigned | YES |  | NULL |  |
| `completed_at` | datetime | YES |  | NULL |  |
| `result_json` | text | YES |  | NULL |  |
| `error` | varchar(255) | YES |  | NULL |  |
| `in_reply_to_packet_id` | bigint(20) unsigned | YES | MUL | NULL |  |
| `thread_key` | varchar(96) | YES | MUL | NULL |  |
| `ack_ms` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_in_reply_to` (in_reply_to_packet_id)
- `KEY idx_status` (status)
- `KEY idx_target` (target_bridge_id, status)
- `KEY idx_thread_key` (thread_key)

### `mesh_packet_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO | PRI |  | auto_increment |
| `received_at` | datetime(3) | NO | MUL | current_timestamp(3) |  |
| `bridge_id` | int(10) unsigned | NO | MUL |  |  |
| `protocol` | enum('meshtastic','meshcore') | NO |  |  |  |
| `packet_id` | bigint(20) unsigned | YES | MUL | NULL |  |
| `src_node` | varchar(48) | YES | MUL | NULL |  |
| `display_name` | varchar(128) | YES |  | NULL |  |
| `dst_node` | varchar(48) | YES |  | NULL |  |
| `port_kind` | varchar(32) | YES |  | NULL |  |
| `snr` | decimal(5,2) | YES |  | NULL |  |
| `rssi` | int(11) | YES |  | NULL |  |
| `hops` | tinyint(4) | YES |  | NULL |  |
| `payload_text` | varchar(512) | YES |  | NULL |  |
| `payload_json` | text | YES |  | NULL |  |
| `lat` | decimal(10,6) | YES |  | NULL |  |
| `lng` | decimal(10,6) | YES |  | NULL |  |
| `channel_idx` | tinyint(4) | YES |  | NULL |  |

Indexes:
- `KEY idx_bridge_received` (bridge_id, received_at)
- `KEY idx_packet_id` (packet_id)
- `KEY idx_received` (received_at)
- `KEY idx_src_node` (src_node, received_at)

### `messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `channel` | varchar(64) | NO | MUL |  |  |
| `direction` | enum('inbound','outbound') | NO |  | 'outbound' |  |
| `msg_type` | varchar(32) | NO |  | 'general' |  |
| `sender` | varchar(128) | NO |  | 'system' |  |
| `recipient` | varchar(256) | NO |  | '' |  |
| `subject` | varchar(256) | YES |  | '' |  |
| `body` | text | NO |  |  |  |
| `priority` | varchar(16) | NO |  | 'normal' |  |
| `status` | varchar(32) | NO | MUL | 'pending' |  |
| `error` | text | YES |  | NULL |  |
| `payload` | text | YES |  | NULL |  |
| `delivered_at` | datetime | YES |  | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_channel` (channel)
- `KEY idx_created` (created_at)
- `KEY idx_status` (status)

### `messages_bin`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) | NO | PRI |  | auto_increment |
| `msg_type` | int(2) | NO |  |  |  |
| `message_id` | varchar(24) | YES |  | NULL |  |
| `ticket_id` | int(8) | YES |  | NULL |  |
| `resp_id` | varchar(128) | YES |  | NULL |  |
| `recipients` | varchar(1024) | YES |  | NULL |  |
| `from_address` | varchar(128) | NO |  |  |  |
| `fromname` | varchar(128) | YES |  | NULL |  |
| `subject` | varchar(128) | NO |  | 'No Subject' |  |
| `message` | longtext | YES |  | NULL |  |
| `status` | varchar(24) | YES |  | NULL |  |
| `date` | datetime | NO |  |  |  |
| `read_status` | int(11) | NO |  | 0 |  |
| `readby` | varchar(512) | YES |  | NULL |  |
| `delivered` | varchar(512) | YES |  | NULL |  |
| `delivery_status` | tinyint(2) | NO |  | 0 |  |
| `_by` | int(7) | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | datetime | YES |  | NULL |  |

### `message_recipients`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `message_id` | int(11) | NO | MUL |  |  |
| `to_user_id` | int(11) | NO | MUL |  |  |
| `read_at` | datetime | YES |  | NULL |  |
| `deleted_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_mr_deleted` (to_user_id, deleted_at)
- `KEY idx_mr_message` (message_id)
- `KEY idx_mr_to_user` (to_user_id)
- `KEY idx_mr_unread` (to_user_id, read_at, deleted_at)

### `message_routes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `name` | varchar(100) | NO |  |  |  |
| `description` | varchar(255) | NO |  | '' |  |
| `enabled` | tinyint(4) | NO | MUL | 1 |  |
| `priority` | int(11) | NO | MUL | 100 |  |
| `source_channel` | varchar(64) | NO | MUL |  |  |
| `dest_channel` | varchar(64) | NO |  |  |  |
| `recipient_predicate_json` | text | YES |  | NULL |  |
| `direction` | enum('inbound','outbound','both') | NO |  | 'both' |  |
| `filters_json` | text | YES |  | NULL |  |
| `transform_json` | text | YES |  | NULL |  |
| `created_by` | int(10) unsigned | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |
| `dest_subaddress_json` | text | YES |  | NULL |  |
| `attach_action` | varchar(16) | YES |  | NULL |  |
| `attach_ticket_id` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_enabled` (enabled)
- `KEY idx_priority` (priority)
- `KEY idx_source` (source_channel)

### `mileage_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `responder_id` | int(11) | NO | MUL |  |  |
| `user_id` | int(11) | NO | MUL |  |  |
| `ticket_id` | int(11) | YES | MUL | NULL |  |
| `start_odo` | decimal(10,1) | YES |  | NULL |  |
| `end_odo` | decimal(10,1) | YES |  | NULL |  |
| `miles` | decimal(10,1) | YES |  | NULL |  |
| `started_at` | datetime | NO |  |  |  |
| `ended_at` | datetime | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `org_id` | int(11) | YES | MUL | NULL |  |

Indexes:
- `KEY idx_mileage_org` (org_id)
- `KEY idx_mileage_responder` (responder_id)
- `KEY idx_mileage_ticket` (ticket_id)
- `KEY idx_mileage_user` (user_id)

### `mi_types`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `bg_color` | varchar(12) | NO |  | 'transparent' |  |
| `color` | varchar(12) | NO |  | '#000000' |  |
| `_by` | int(11) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `mi_x`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(6) | NO | PRI |  | auto_increment |
| `mi_id` | int(6) | NO |  |  |  |
| `ticket_id` | int(6) | NO |  |  |  |

### `mmarkup`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `line_name` | varchar(32) | NO |  |  |  |
| `line_status` | int(2) | NO |  | 0 |  |
| `line_type` | varchar(1) | YES |  | NULL |  |
| `line_ident` | varchar(10) | YES |  | NULL |  |
| `line_cat_id` | int(3) | NO |  | 0 |  |
| `line_data` | longtext | NO |  |  |  |
| `use_with_bm` | tinyint(1) | NO |  | 0 |  |
| `use_with_r` | tinyint(1) | NO |  | 0 |  |
| `use_with_f` | tinyint(1) | NO |  | 0 |  |
| `use_with_u_ex` | tinyint(1) | NO |  | 0 |  |
| `use_with_u_rf` | tinyint(1) | NO |  | 0 |  |
| `line_color` | varchar(8) | YES |  | NULL |  |
| `line_opacity` | float | YES |  | NULL |  |
| `line_width` | int(2) | YES |  | NULL |  |
| `fill_color` | varchar(8) | YES |  | NULL |  |
| `fill_opacity` | float | YES |  | NULL |  |
| `filled` | int(1) | YES |  | 0 |  |
| `_by` | int(7) | NO |  | 0 |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |
| `category_id` | bigint(4) | YES | MUL | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)
- `KEY idx_category` (category_id)

### `mmarkup_cats`

Engine: MyISAM · Collation: utf8mb3_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `category` | varchar(24) | NO |  |  |  |
| `_by` | int(7) | NO |  | 0 |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |
| `color` | varchar(16) | YES |  | '#1976d2' |  |
| `icon` | varchar(32) | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `default_visible` | tinyint(1) | NO |  | 1 |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `archived_at` | datetime | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `modules`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `mod_name` | varchar(26) | NO |  |  |  |
| `mod_status` | varchar(1) | NO |  |  |  |

### `msg_settings`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | tinytext | YES |  | NULL |  |
| `value` | varchar(512) | YES |  | NULL |  |

### `net_checkins`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `identifier` | varchar(64) | NO |  |  |  |
| `note` | varchar(255) | NO |  | '' |  |
| `status` | varchar(16) | NO |  | 'pending' |  |
| `seq` | int(11) | NO |  | 0 |  |
| `priority` | int(11) | NO |  | 0 |  |
| `ticket_id` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | NO |  |  |  |
| `worked_at` | datetime | YES |  | NULL |  |
| `deleted_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | NO |  |  |  |

Indexes:
- `KEY idx_net_user_created` (user_id, created_at)
- `KEY idx_net_user_status` (user_id, status, id)

### `newui_audit_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) | NO | PRI |  | auto_increment |
| `event_time` | datetime | NO | MUL | current_timestamp() |  |
| `user_id` | int(11) | YES | MUL | NULL |  |
| `user_name` | varchar(64) | YES |  | NULL |  |
| `ip_address` | varchar(45) | YES |  | NULL |  |
| `category` | varchar(32) | NO | MUL |  |  |
| `activity` | varchar(32) | NO |  |  |  |
| `severity` | tinyint(4) | NO | MUL | 1 |  |
| `target_type` | varchar(48) | YES | MUL | NULL |  |
| `target_id` | varchar(64) | YES |  | NULL |  |
| `summary` | varchar(512) | NO |  |  |  |
| `details` | longtext | YES |  | NULL |  |

Indexes:
- `KEY idx_category` (category)
- `KEY idx_event_time` (event_time)
- `KEY idx_severity` (severity)
- `KEY idx_target` (target_type, target_id)
- `KEY idx_user_id` (user_id)

### `newui_equipment`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `equipment_type_id` | int(11) | YES | MUL | NULL |  |
| `ownership` | enum('organization','personal') | YES | MUL | 'organization' |  |
| `owner_member_id` | int(11) | YES | MUL | NULL |  |
| `available_for_events` | tinyint(1) | YES |  | 0 |  |
| `name` | varchar(128) | NO |  |  |  |
| `serial_number` | varchar(64) | YES |  | NULL |  |
| `asset_tag` | varchar(32) | YES | MUL | NULL |  |
| `make` | varchar(64) | YES |  | NULL |  |
| `model` | varchar(64) | YES |  | NULL |  |
| `size` | varchar(8) | YES |  | NULL |  |
| `purchase_date` | date | YES |  | NULL |  |
| `purchase_cost` | decimal(10,2) | YES |  | NULL |  |
| `warranty_exp` | date | YES |  | NULL |  |
| `condition` | enum('New','Good','Fair','Poor','Out of Service','Disposed') | YES |  | 'Good' |  |
| `assigned_member_id` | int(11) | YES | MUL | NULL |  |
| `assigned_team_id` | int(11) | YES | MUL | NULL |  |
| `location` | varchar(128) | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `status` | enum('Available','Checked Out','In Repair','Lost','Disposed') | YES | MUL | 'Available' |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |
| `org_id` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY asset_tag` (asset_tag)
- `KEY assigned_member_id` (assigned_member_id)
- `KEY assigned_team_id` (assigned_team_id)
- `KEY equipment_type_id` (equipment_type_id)
- `KEY ownership` (ownership)
- `KEY owner_member_id` (owner_member_id)
- `KEY status` (status)

### `newui_equipment_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `equipment_id` | int(11) | NO | MUL |  |  |
| `action` | enum('checkout','checkin','transfer','condition_change','repair','note') | NO |  |  |  |
| `member_id` | int(11) | YES | MUL | NULL |  |
| `team_id` | int(11) | YES |  | NULL |  |
| `performed_by` | int(11) | YES |  | NULL |  |
| `previous_condition` | varchar(32) | YES |  | NULL |  |
| `new_condition` | varchar(32) | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY created_at` (created_at)
- `KEY equipment_id` (equipment_id)
- `KEY idx_deleted_at` (deleted_at)
- `KEY member_id` (member_id)

### `newui_equipment_types`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO | UNI |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `icon` | varchar(32) | YES |  | 'bi-box' |  |
| `requires_checkout` | tinyint(1) | YES |  | 1 |  |
| `sort_order` | int(11) | YES |  | 0 |  |
| `active` | tinyint(1) | YES |  | 1 |  |

Indexes:
- `UNIQUE KEY uniq_name` (name)

### `newui_events`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(255) | NO |  |  |  |
| `event_type` | enum('drill','exercise','deployment','meeting','training','other') | YES | MUL | 'other' |  |
| `description` | text | YES |  | NULL |  |
| `start_date` | datetime | NO | MUL |  |  |
| `end_date` | datetime | YES |  | NULL |  |
| `location` | varchar(255) | YES |  | NULL |  |
| `max_participants` | int(11) | YES |  | NULL |  |
| `required_cert_ids` | text | YES |  | NULL |  |
| `status` | enum('planned','active','completed','cancelled') | YES | MUL | 'planned' |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_start` (start_date)
- `KEY idx_status` (status)
- `KEY idx_type` (event_type)

### `newui_event_participants`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `event_id` | int(11) | NO | MUL |  |  |
| `member_id` | int(11) | NO | MUL |  |  |
| `status` | enum('registered','confirmed','attended','no-show','cancelled') | YES |  | 'registered' |  |
| `self_signup` | tinyint(1) | YES |  | 0 |  |
| `role` | varchar(64) | YES |  | NULL |  |
| `check_in_time` | datetime | YES |  | NULL |  |
| `check_out_time` | datetime | YES |  | NULL |  |
| `hours_worked` | decimal(5,2) | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_member` (member_id)
- `UNIQUE KEY uq_event_member` (event_id, member_id)

### `newui_major_incidents`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(255) | NO |  |  |  |
| `description` | text | YES |  | NULL |  |
| `commander` | int(11) | YES | MUL | NULL |  |
| `severity` | tinyint(4) | NO |  | 0 |  |
| `status` | enum('open','closed') | NO | MUL | 'open' |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `closed_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_commander` (commander)
- `KEY idx_created_at` (created_at)
- `KEY idx_status` (status)

### `newui_major_incident_links`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `major_id` | int(11) | NO | MUL |  |  |
| `ticket_id` | int(11) | NO | MUL |  |  |
| `linked_by` | int(11) | YES |  | NULL |  |
| `linked_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_major_id` (major_id)
- `KEY idx_ticket_id` (ticket_id)
- `UNIQUE KEY uq_major_ticket` (major_id, ticket_id)

### `newui_service_events`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `service` | varchar(64) | NO | MUL |  |  |
| `event_type` | varchar(32) | NO |  |  |  |
| `detected_at` | datetime | NO | MUL |  |  |
| `uptime_seconds` | int(11) | YES |  | NULL |  |
| `details` | text | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |

Indexes:
- `KEY idx_detected_at` (detected_at)
- `KEY idx_service` (service)
- `KEY idx_svc_date` (service, detected_at)

### `newui_service_state`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `service` | varchar(64) | NO | PRI |  |  |
| `last_status` | varchar(16) | NO |  | 'unknown' |  |
| `last_checked` | datetime | NO |  |  |  |
| `last_uptime_sec` | int(11) | YES |  | NULL |  |
| `consecutive_failures` | int(11) | YES |  | 0 |  |

### `newui_shift_assignments`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `slot_id` | int(11) | NO | MUL |  |  |
| `role_id` | int(11) | NO | MUL |  |  |
| `member_id` | int(11) | NO | MUL |  |  |
| `assignment_date` | date | NO | MUL |  |  |
| `status` | enum('assigned','confirmed','completed','no-show','swapped','cancelled') | YES |  | 'assigned' |  |
| `self_signup` | tinyint(1) | YES |  | 0 |  |
| `notes` | text | YES |  | NULL |  |
| `assigned_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_date` (assignment_date)
- `KEY idx_member` (member_id)
- `KEY idx_role` (role_id)
- `KEY idx_slot` (slot_id)
- `UNIQUE KEY uq_slot_role_member_date` (slot_id, role_id, member_id, assignment_date)

### `newui_shift_roles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `template_id` | int(11) | NO | MUL |  |  |
| `role_name` | varchar(64) | NO |  |  |  |
| `description` | text | YES |  | NULL |  |
| `min_slots` | int(11) | YES |  | 1 |  |
| `max_slots` | int(11) | YES |  | 1 |  |
| `required_cert_ids` | text | YES |  | NULL |  |
| `required_ics_position_id` | int(11) | YES |  | NULL |  |
| `sort_order` | int(11) | YES |  | 0 |  |

Indexes:
- `KEY idx_template` (template_id)

### `newui_shift_slots`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `template_id` | int(11) | NO | MUL |  |  |
| `day_of_week` | tinyint(4) | NO |  |  |  |
| `start_time` | time | NO |  |  |  |
| `end_time` | time | NO |  |  |  |
| `week_number` | int(11) | YES |  | 1 |  |
| `label` | varchar(64) | YES |  | NULL |  |

Indexes:
- `KEY idx_template` (template_id)

### `newui_shift_templates`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO |  |  |  |
| `description` | text | YES |  | NULL |  |
| `rotation_weeks` | int(11) | NO |  | 1 |  |
| `timezone` | varchar(64) | YES |  | 'America/Chicago' |  |
| `active` | tinyint(1) | YES | MUL | 1 |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_active` (active)

### `newui_vehicles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | YES | MUL | NULL |  |
| `owner_org_id` | int(11) | YES | MUL | NULL |  |
| `vehicle_type_id` | int(11) | YES | MUL | NULL |  |
| `callsign` | varchar(24) | YES | MUL | NULL |  |
| `year` | smallint(6) | YES |  | NULL |  |
| `make` | varchar(64) | YES |  | NULL |  |
| `model` | varchar(64) | YES |  | NULL |  |
| `color` | varchar(32) | YES |  | NULL |  |
| `plate_number` | varchar(16) | YES |  | NULL |  |
| `plate_state` | varchar(4) | YES |  | NULL |  |
| `vin` | varchar(20) | YES |  | NULL |  |
| `registration_exp` | date | YES |  | NULL |  |
| `insurance_carrier` | varchar(128) | YES |  | NULL |  |
| `insurance_policy` | varchar(64) | YES |  | NULL |  |
| `insurance_exp` | date | YES |  | NULL |  |
| `is_agency_vehicle` | tinyint(1) | YES |  | 0 |  |
| `is_private` | tinyint(1) | YES |  | 1 |  |
| `status` | enum('Active','Out of Service','Disposed') | YES |  | 'Active' |  |
| `notes` | text | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |
| `org_id` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY callsign` (callsign)
- `KEY idx_vehicle_owner_org` (owner_org_id)
- `KEY member_id` (member_id)
- `KEY vehicle_type_id` (vehicle_type_id)

### `newui_vehicle_types`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO | UNI |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `icon` | varchar(32) | YES |  | 'bi-truck' |  |
| `sort_order` | int(11) | YES |  | 0 |  |
| `active` | tinyint(1) | YES |  | 1 |  |

Indexes:
- `UNIQUE KEY uk_vt_name` (name)

### `notification_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `rule_id` | int(10) unsigned | YES | MUL | NULL |  |
| `event_type` | varchar(50) | NO |  |  |  |
| `ticket_id` | int(10) unsigned | YES | MUL | NULL |  |
| `channel` | varchar(20) | NO |  |  |  |
| `recipient` | varchar(255) | NO |  |  |  |
| `subject` | varchar(255) | YES |  | '' |  |
| `body` | text | YES |  | NULL |  |
| `status` | enum('sent','failed','skipped') | NO |  | 'sent' |  |
| `error` | text | YES |  | NULL |  |
| `sent_at` | datetime | YES | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_rule` (rule_id)
- `KEY idx_sent` (sent_at)
- `KEY idx_ticket` (ticket_id)

### `notification_preferences`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `user_id` | int(10) unsigned | NO | UNI |  |  |
| `channel_email` | tinyint(4) | NO |  | 1 |  |
| `channel_sms` | tinyint(4) | NO |  | 0 |  |
| `channel_chat` | tinyint(4) | NO |  | 1 |  |
| `quiet_start` | time | YES |  | NULL |  |
| `quiet_end` | time | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `UNIQUE KEY idx_user` (user_id)

### `notification_rules`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `name` | varchar(100) | NO |  | '' |  |
| `event_type` | enum('incident_create','incident_close','incident_status','unit_assign','unit_clear','severity_high','has_broadcast') | NO | MUL |  |  |
| `severity_filter` | tinyint(4) | YES |  | NULL |  |
| `incident_type_filter` | int(10) unsigned | YES |  | NULL |  |
| `channel` | varchar(20) | NO |  | 'email' |  |
| `recipients` | text | YES |  | NULL |  |
| `email_list_id` | int(10) unsigned | YES |  | NULL |  |
| `subject_template` | varchar(255) | YES |  | '' |  |
| `body_template` | text | YES |  | NULL |  |
| `active` | tinyint(4) | NO | MUL | 1 |  |
| `created_by` | int(10) unsigned | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `updated_at` | datetime | YES |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_active` (active)
- `KEY idx_event_type` (event_type)

### `notify`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `ticket_id` | int(8) | NO |  | 0 |  |
| `user` | int(8) | NO |  | 0 |  |
| `execute_path` | tinytext | YES |  | NULL |  |
| `severities` | int(1) | NO |  | 0 |  |
| `on_action` | tinyint(1) | YES |  | 0 |  |
| `on_ticket` | tinyint(1) | YES |  | 0 |  |
| `on_patient` | tinyint(1) | YES |  | 0 |  |
| `email_address` | varchar(255) | YES |  | NULL |  |
| `mailgroup` | int(4) | NO |  | 0 |  |
| `pager` | varchar(255) | YES |  | NULL |  |
| `pager_cb` | varchar(96) | YES |  | NULL |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `organisations`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO |  |  |  |
| `street` | varchar(256) | NO |  |  |  |
| `city` | varchar(64) | NO |  |  |  |
| `state` | varchar(4) | NO |  |  |  |
| `tel` | varchar(16) | NO |  |  |  |
| `email` | varchar(256) | NO |  |  |  |

### `organizations`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `parent_org_id` | int(11) | YES | MUL | NULL |  |
| `name` | varchar(128) | NO |  |  |  |
| `short_name` | varchar(32) | YES |  | NULL |  |
| `org_type` | varchar(64) | YES |  | NULL |  |
| `description` | text | YES |  | NULL |  |
| `contact_name` | varchar(128) | YES |  | NULL |  |
| `contact_email` | varchar(128) | YES |  | NULL |  |
| `contact_phone` | varchar(24) | YES |  | NULL |  |
| `address` | varchar(255) | YES |  | NULL |  |
| `city` | varchar(64) | YES |  | NULL |  |
| `state` | varchar(4) | YES |  | NULL |  |
| `zip` | varchar(16) | YES |  | NULL |  |
| `active` | tinyint(1) | NO | MUL | 1 |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |
| `public_board_enabled` | tinyint(1) | NO |  | 0 |  |
| `public_board_slug` | varchar(64) | YES | UNI | NULL |  |

Indexes:
- `KEY idx_active` (active)
- `KEY idx_org_parent` (parent_org_id)
- `UNIQUE KEY uk_public_board_slug` (public_board_slug)

### `org_relationships`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO |  |  |  |
| `relationship_type` | varchar(40) | NO |  | 'mutual_aid' |  |
| `access_tier` | enum('view','assist') | NO |  | 'view' |  |
| `redaction_profile` | enum('view','assist') | NO |  | 'view' |  |
| `requires_activation` | tinyint(1) | NO |  | 1 |  |
| `max_activation_minutes` | int(11) | YES |  | NULL |  |
| `status` | enum('pending','active','rejected') | NO | MUL | 'pending' |  |
| `created_by` | int(11) | NO |  | 0 |  |
| `created_by_name` | varchar(128) | NO |  | '' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_org_rel_status` (status)

### `org_relationships_activations`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `relationship_id` | int(11) | NO | MUL |  |  |
| `activated_at` | datetime | NO |  | current_timestamp() |  |
| `activated_by` | int(11) | NO |  |  |  |
| `activated_by_name` | varchar(128) | NO |  | '' |  |
| `activation_reason` | varchar(255) | YES |  | NULL |  |
| `max_activation_minutes` | int(11) | YES |  | NULL |  |
| `deactivated_at` | datetime | YES |  | NULL |  |
| `deactivated_by` | int(11) | YES |  | NULL |  |
| `deactivated_by_name` | varchar(128) | NO |  | '' |  |
| `deactivated_reason` | varchar(255) | YES |  | NULL |  |
| `live_key` | varchar(24) | YES | UNI | NULL | STORED GENERATED |

Indexes:
- `KEY idx_org_rel_activation_rel` (relationship_id, deactivated_at)
- `UNIQUE KEY uk_org_rel_activation_live` (live_key)

### `org_relationships_members`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `relationship_id` | int(11) | NO | MUL |  |  |
| `org_id` | int(11) | NO | MUL |  |  |
| `status` | enum('pending','approved','rejected') | NO |  | 'pending' |  |
| `proposed_by` | int(11) | NO |  | 0 |  |
| `proposed_by_name` | varchar(128) | NO |  | '' |  |
| `proposed_at` | datetime | NO |  | current_timestamp() |  |
| `approved_by` | int(11) | YES |  | NULL |  |
| `approved_by_name` | varchar(128) | NO |  | '' |  |
| `approved_at` | datetime | YES |  | NULL |  |
| `rejected_by` | int(11) | YES |  | NULL |  |
| `rejected_by_name` | varchar(128) | NO |  | '' |  |
| `rejected_at` | datetime | YES |  | NULL |  |
| `rejection_reason` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_org_rel_member_org` (org_id, status)
- `UNIQUE KEY uk_org_rel_member` (relationship_id, org_id)

### `org_type_routing`

Engine: InnoDB · Collation: utf8mb4_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `owning_org_id` | int(11) | NO | MUL |  |  |
| `shared_with_org_id` | int(11) | NO | MUL |  |  |
| `match_scope` | enum('group','type') | NO |  | 'group' |  |
| `match_group` | varchar(20) | YES | MUL | NULL |  |
| `match_in_type_id` | int(11) | YES | MUL | NULL |  |
| `match_key` | varchar(24) | YES |  | NULL | STORED GENERATED |
| `access_tier` | enum('view','assist') | NO |  | 'view' |  |
| `active` | tinyint(1) | NO |  | 1 |  |
| `created_by` | int(11) | NO |  | 0 |  |
| `created_by_name` | varchar(128) | NO |  | '' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `deactivated_at` | datetime | YES |  | NULL |  |
| `deactivated_by` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_org_routing_group` (match_group)
- `KEY idx_org_routing_owner` (owning_org_id, active)
- `KEY idx_org_routing_shared` (shared_with_org_id, active)
- `KEY idx_org_routing_type` (match_in_type_id)
- `UNIQUE KEY uk_org_routing_rule` (owning_org_id, shared_with_org_id, match_key)

### `owntracks_outbox`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `payload_json` | text | NO |  |  |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `consumed_at` | datetime | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_pending` (member_id, consumed_at)

### `par_config`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `scope` | enum('agency_default','incident_type') | NO | MUL |  |  |
| `in_types_id` | int(10) unsigned | YES |  | NULL |  |
| `cadence_minutes` | int(10) unsigned | NO |  | 0 |  |
| `first_cycle_window_s` | int(10) unsigned | NO |  | 60 |  |
| `retry_cycle_window_s` | int(10) unsigned | NO |  | 120 |  |
| `escalate_after_misses` | tinyint(3) unsigned | NO |  | 1 |  |
| `chat_channel` | varchar(64) | YES |  | NULL |  |
| `audio_alert` | varchar(32) | YES |  | NULL |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `is_disabled` | tinyint(1) | NO |  | 0 |  |

Indexes:
- `UNIQUE KEY uniq_scope` (scope, in_types_id)

### `par_cycles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `ticket_id` | bigint(20) unsigned | NO | MUL |  |  |
| `initiated_at` | datetime | NO | MUL |  |  |
| `initiated_by` | int(10) unsigned | YES |  | NULL |  |
| `initiated_kind` | enum('scheduled','manual','mayday','benchmark') | NO |  |  |  |
| `cycle_window_s` | int(10) unsigned | NO |  | 60 |  |
| `status` | enum('pending','complete','aborted','expired') | NO | MUL | 'pending' |  |
| `completed_at` | datetime | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_initiated_at` (initiated_at)
- `KEY idx_status` (status)
- `KEY idx_ticket` (ticket_id)

### `par_unit_acks`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `par_cycle_id` | int(10) unsigned | NO | MUL |  |  |
| `responder_id` | int(10) unsigned | NO | MUL |  |  |
| `expected` | tinyint(1) | NO |  | 1 |  |
| `state` | enum('pending','acked','missed','aborted','expired') | NO | MUL | 'pending' |  |
| `acked_at` | datetime | YES |  | NULL |  |
| `acked_by` | int(10) unsigned | YES |  | NULL |  |
| `acked_via` | enum('mobile','dispatcher_manual','sse','voice_radio') | YES |  | NULL |  |
| `member_count` | tinyint(3) unsigned | YES |  | NULL |  |
| `comments` | varchar(1024) | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_responder` (responder_id)
- `KEY idx_state` (state)
- `UNIQUE KEY uniq_cycle_unit` (par_cycle_id, responder_id)

### `patient`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `ticket_id` | int(8) | NO |  | 0 |  |
| `name` | varchar(32) | YES |  | NULL |  |
| `fullname` | varchar(64) | YES |  | NULL |  |
| `dob` | varchar(32) | YES |  | NULL |  |
| `gender` | int(1) | NO |  | 0 |  |
| `insurance_id` | int(3) | YES |  | NULL |  |
| `facility_contact` | varchar(64) | YES |  | NULL |  |
| `facility_id` | int(3) | NO |  | 0 |  |
| `date` | datetime | YES |  | NULL |  |
| `description` | text | NO |  |  |  |
| `user` | int(8) | YES |  | NULL |  |
| `action_type` | int(8) | YES |  | NULL |  |
| `updated` | datetime | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `patient_x`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `patient_id` | int(7) | NO |  |  |  |
| `assign_id` | int(7) | NO |  |  |  |
| `_by` | int(7) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `pending_routed_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `ticket_id` | bigint(20) unsigned | YES | MUL | NULL |  |
| `route_id` | int(10) unsigned | YES |  | NULL |  |
| `channel` | varchar(64) | NO |  |  |  |
| `target` | varchar(255) | NO |  |  |  |
| `subject` | varchar(255) | YES |  | NULL |  |
| `body` | text | NO |  |  |  |
| `priority` | varchar(16) | YES |  | NULL |  |
| `scheduled_send_at` | datetime | NO | MUL |  |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `created_by` | int(10) unsigned | YES |  | NULL |  |
| `status` | enum('pending','sent','killed','failed','expired') | NO | MUL | 'pending' |  |
| `sent_at` | datetime | YES |  | NULL |  |
| `killed_at` | datetime | YES |  | NULL |  |
| `killed_by` | int(10) unsigned | YES |  | NULL |  |
| `killed_reason` | varchar(255) | YES |  | NULL |  |
| `send_error` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_scheduled` (scheduled_send_at, status)
- `KEY idx_status` (status)
- `KEY idx_ticket` (ticket_id)

### `permissions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(64) | NO | UNI |  |  |
| `name` | varchar(128) | NO |  |  |  |
| `category` | varchar(32) | NO | MUL |  |  |
| `resource` | varchar(48) | YES | MUL | NULL |  |
| `verb` | varchar(16) | YES |  | NULL |  |
| `deprecated_alias_of` | varchar(64) | YES |  | NULL |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `admin_only` | tinyint(3) unsigned | NO |  | 0 |  |

Indexes:
- `KEY idx_category` (category)
- `KEY idx_resource_verb` (resource, verb)
- `UNIQUE KEY uniq_permission_code` (code)

### `permission_review_dismissals`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `permission_id` | int(11) | NO | UNI |  |  |
| `dismissed_by` | int(11) | NO | MUL |  |  |
| `dismissed_at` | datetime | NO |  |  |  |

Indexes:
- `KEY idx_dismissed_by` (dismissed_by)
- `UNIQUE KEY uniq_perm` (permission_id)

### `personnel`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `surname` | varchar(48) | YES |  | NULL |  |
| `forenames` | varchar(48) | YES |  | NULL |  |
| `address` | varchar(128) | YES |  | NULL |  |
| `state` | varchar(24) | YES |  | NULL |  |
| `latitude` | double | YES |  | NULL |  |
| `longitude` | double | YES |  | NULL |  |
| `map_grid` | varchar(10) | YES |  | NULL |  |
| `date_of_birth` | date | YES |  | NULL |  |
| `gender` | varchar(48) | YES |  | NULL |  |
| `person_identifier` | varchar(48) | YES |  | NULL |  |
| `email` | varchar(48) | YES |  | NULL |  |
| `cellphone` | varchar(48) | YES |  | NULL |  |
| `homephone` | varchar(48) | YES |  | NULL |  |
| `workphone` | varchar(48) | YES |  | NULL |  |
| `next_of_kin_name` | varchar(48) | YES |  | NULL |  |
| `next_of_kin_address` | varchar(128) | YES |  | NULL |  |
| `next_of_kin_homephone` | varchar(48) | YES |  | NULL |  |
| `next_of_kin_workphone` | varchar(48) | YES |  | NULL |  |
| `next_of_kin_cellphone` | varchar(48) | YES |  | NULL |  |
| `amateur_radio_callsign` | varchar(48) | YES |  | NULL |  |
| `person_status` | varchar(48) | YES |  | NULL |  |
| `team_name` | varchar(48) | YES |  | NULL |  |
| `person_notes` | longtext | YES |  | NULL |  |
| `person_capabilities` | longtext | YES |  | NULL |  |
| `vehicle_identifier` | varchar(48) | YES |  | NULL |  |
| `vehicle_callsign` | varchar(48) | YES |  | NULL |  |
| `vehicle_owner` | varchar(48) | YES |  | NULL |  |
| `vehicle_make` | varchar(48) | YES |  | NULL |  |
| `vehicle_model` | varchar(48) | YES |  | NULL |  |
| `vehicle_year` | varchar(48) | YES |  | NULL |  |
| `vehicle_color` | varchar(48) | YES |  | NULL |  |
| `vehicle_seats` | varchar(48) | YES |  | NULL |  |
| `vehicle_notes` | longtext | YES |  | NULL |  |
| `vehicle_capabilities` | longtext | YES |  | NULL |  |
| `valid_training` | longtext | YES |  | NULL |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_by` | int(7) | YES |  | NULL |  |

### `photos`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `description` | varchar(256) | NO |  |  |  |
| `ticket_id` | int(7) | NO |  |  |  |
| `taken_by` | varchar(48) | YES |  | NULL |  |
| `taken_on` | varchar(24) | YES |  | NULL |  |
| `by` | int(7) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |

### `pin_ctrl`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `responder_id` | int(7) | NO |  | 0 |  |
| `pin` | varchar(4) | NO |  |  |  |
| `_by` | int(7) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

### `places`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | YES |  | NULL |  |
| `apply_to` | enum('city','bldg') | NO |  | 'city' |  |
| `street` | varchar(96) | YES |  | NULL |  |
| `city` | varchar(32) | YES |  | NULL |  |
| `state` | varchar(4) | YES |  | NULL |  |
| `information` | varchar(1024) | YES |  | NULL |  |
| `lat` | float | YES |  | 0 |  |
| `lon` | float | YES |  | 0 |  |
| `zoom` | int(2) | YES |  | 7 |  |

### `push_subscriptions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `channel` | enum('web','apns','fcm') | NO | MUL | 'web' |  |
| `endpoint` | text | NO |  |  |  |
| `p256dh` | varchar(255) | NO |  |  |  |
| `auth` | varchar(64) | NO |  |  |  |
| `device_label` | varchar(128) | YES |  | NULL |  |
| `user_agent` | varchar(512) | YES |  | NULL |  |
| `filters_json` | text | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |
| `last_used_at` | datetime | YES |  | NULL |  |
| `last_error` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_channel` (channel)
- `KEY idx_user` (user_id)
- `UNIQUE KEY uk_user_endpoint` (user_id, endpoint)

### `quick_notes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `note_text` | mediumtext | NO |  |  |  |
| `captured_at` | datetime | NO |  |  |  |
| `done` | tinyint(1) | NO |  | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_qn_user` (user_id)
- `KEY idx_qn_user_done` (user_id, done)

### `radioid_users`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `dmr_id` | bigint(20) | NO | PRI |  |  |
| `callsign` | varchar(16) | NO | MUL | '' |  |
| `fname` | varchar(64) | NO |  | '' |  |
| `surname` | varchar(64) | NO |  | '' |  |
| `country` | varchar(64) | NO |  | '' |  |
| `state` | varchar(64) | NO |  | '' |  |
| `city` | varchar(64) | NO |  | '' |  |
| `fetched_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_callsign` (callsign)
- `KEY idx_fetched` (fetched_at)

### `region`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `group_name` | varchar(60) | NO |  |  |  |
| `category` | int(2) | YES |  | NULL |  |
| `description` | varchar(60) | YES |  | NULL |  |
| `owner` | int(2) | NO |  | 1 |  |
| `def_area_code` | varchar(4) | YES |  | NULL |  |
| `def_city` | varchar(20) | YES |  | NULL |  |
| `def_lat` | double | YES |  | NULL |  |
| `def_lng` | double | YES |  | NULL |  |
| `def_st` | varchar(20) | YES |  | NULL |  |
| `def_zoom` | int(2) | NO |  | 10 |  |
| `boundary` | int(4) | YES |  | NULL |  |

### `region_type`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(16) | NO |  |  |  |
| `description` | varchar(48) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_by` | int(7) | NO |  |  |  |

### `remote_devices`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(64) | NO | PRI |  | auto_increment |
| `lat` | double | YES |  | 0 |  |
| `lng` | double | YES |  | 0 |  |
| `time` | datetime | NO |  |  |  |
| `speed` | int(4) | NO |  | 0 |  |
| `altitude` | int(6) | NO |  | 0 |  |
| `direction` | double | NO |  | 0 |  |
| `user` | varchar(64) | YES |  | NULL |  |

### `replacetext`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(3) | NO | PRI |  | auto_increment |
| `in_text` | varchar(128) | NO |  |  |  |
| `out_text` | varchar(128) | NO |  |  |  |
| `add_ticket` | enum('Yes','No') | NO |  | 'No' |  |
| `add_user` | enum('Yes','No') | NO |  | 'No' |  |
| `add_user_unit` | enum('Yes','No') | NO |  | 'No' |  |
| `add_time` | enum('Yes','No') | NO |  | 'No' |  |
| `add_date` | enum('Yes','No') | NO |  | 'No' |  |
| `app_summ` | enum('Yes','No') | NO |  | 'No' |  |
| `app_shortsumm` | enum('Yes','No') | NO |  | 'No' |  |
| `app_desc` | enum('Yes','No') | NO |  | 'No' |  |
| `app_phone` | enum('Yes','No') | NO |  | 'No' |  |
| `app_street` | enum('Yes','No') | NO |  | 'No' |  |
| `app_city` | enum('Yes','No') | NO |  | 'No' |  |
| `app_toaddress` | enum('Yes','No') | NO |  | 'No' |  |
| `app_dispnotes` | enum('Yes','No') | NO |  | 'No' |  |

### `replacetext_order`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `displayorder` | int(2) | NO |  |  |  |
| `info_name` | varchar(24) | NO |  |  |  |

### `responder`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `roster_user` | int(7) | NO |  | 0 |  |
| `name` | text | YES |  | NULL |  |
| `street` | varchar(128) | YES |  | NULL |  |
| `city` | varchar(128) | YES |  | NULL |  |
| `state` | varchar(32) | YES |  | NULL |  |
| `phone` | varchar(16) | YES |  | NULL |  |
| `mobile` | tinyint(2) | YES |  | 0 |  |
| `direcs` | tinyint(2) | NO |  | 1 |  |
| `multi` | int(1) | NO |  | 0 |  |
| `aprs` | tinyint(2) | NO |  | 0 |  |
| `instam` | tinyint(2) | NO |  | 0 |  |
| `ogts` | tinyint(2) | NO |  | 0 |  |
| `t_tracker` | tinyint(2) | NO |  | 0 |  |
| `mob_tracker` | tinyint(2) | NO |  | 0 |  |
| `xastir_tracker` | tinyint(2) | NO |  | 0 |  |
| `traccar` | tinyint(2) | NO |  | 0 |  |
| `javaprssrvr` | tinyint(2) | NO |  | 0 |  |
| `ring_fence` | int(3) | NO |  | 0 |  |
| `excl_zone` | int(3) | NO |  | 0 |  |
| `locatea` | tinyint(2) | NO |  | 0 |  |
| `gtrack` | tinyint(2) | NO |  | 0 |  |
| `glat` | tinyint(2) | NO |  | 0 |  |
| `description` | text | NO |  |  |  |
| `capab` | varchar(255) | YES |  | NULL |  |
| `un_status_id` | int(4) | NO |  | 0 |  |
| `status_about` | varchar(512) | YES |  | NULL |  |
| `other` | varchar(96) | YES |  | NULL |  |
| `callsign` | varchar(24) | YES |  | NULL |  |
| `handle` | varchar(24) | YES |  | NULL |  |
| `icon_str` | char(3) | YES |  | NULL |  |
| `contact_name` | varchar(64) | YES |  | NULL |  |
| `contact_via` | varchar(64) | YES |  | NULL |  |
| `smsg_id` | varchar(16) | YES |  | NULL |  |
| `cellphone` | varchar(128) | YES |  | NULL |  |
| `pager_p` | varchar(64) | YES |  | NULL |  |
| `pager_s` | varchar(64) | YES |  | NULL |  |
| `send_no` | varchar(64) | YES |  | NULL |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `type` | tinyint(1) | YES |  | NULL |  |
| `at_facility` | int(6) | NO |  | 0 |  |
| `updated` | datetime | YES |  | NULL |  |
| `status_updated` | datetime | YES |  | NULL |  |
| `user_id` | int(4) | YES |  | NULL |  |
| `followmee_tracker` | tinyint(2) | NO |  | 0 |  |
| `zello_username` | varchar(64) | YES |  | NULL |  |
| `personal_for_member_id` | int(11) | YES |  | NULL |  |
| `org_id` | int(11) | YES |  | NULL |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)
- `KEY idx_deleted_at` (deleted_at)

### `responder_notes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `responder_id` | int(11) | NO | MUL |  |  |
| `category` | varchar(32) | NO | MUL | 'general' |  |
| `note` | text | NO |  |  |  |
| `by_user` | int(11) | NO |  | 0 |  |
| `by_username` | varchar(64) | NO |  | '' |  |
| `corrects_id` | int(11) | YES | MUL | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `deleted_at` | datetime | YES |  | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_category` (category)
- `KEY idx_corrects` (corrects_id)
- `KEY idx_responder_time` (responder_id, created_at)

### `responder_rota`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(8) | NO | PRI |  | auto_increment |
| `person_id` | int(4) | YES |  | NULL |  |
| `resp_id` | int(4) | NO |  |  |  |
| `starttime` | datetime | YES |  | NULL |  |
| `endtime` | datetime | YES |  | NULL |  |
| `rota_status` | int(2) | YES |  | NULL |  |
| `recurring` | int(2) | YES |  | NULL |  |

### `responder_x_member`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `responder_id` | int(6) | NO |  |  |  |
| `member_id` | int(6) | NO |  |  |  |
| `use_email` | int(11) | NO |  | 0 |  |
| `use_cellphone` | int(11) | NO |  | 0 |  |
| `use_homephone` | int(11) | NO |  | 0 |  |
| `use_workphone` | int(11) | NO |  | 0 |  |
| `use_smsg_id` | int(11) | NO |  | 0 |  |

### `roadinfo`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(6) | NO | PRI |  | auto_increment |
| `title` | varchar(128) | NO |  |  |  |
| `description` | longtext | NO |  |  |  |
| `address` | varchar(512) | YES |  | NULL |  |
| `conditions` | int(2) | NO |  |  |  |
| `lat` | varchar(16) | NO |  |  |  |
| `lng` | varchar(16) | NO |  |  |  |
| `username` | varchar(24) | NO |  |  |  |
| `_by` | int(4) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `roles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO | MUL |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `session_timeout_minutes` | int(11) | YES |  | NULL |  |
| `org_id` | int(11) | YES | MUL | NULL |  |
| `is_default` | tinyint(1) | NO |  | 0 |  |
| `is_super` | tinyint(1) | NO |  | 0 |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `legacy_level` | int(11) | YES |  | NULL |  |
| `is_system` | tinyint(1) | NO |  | 0 |  |
| `mobile_first` | tinyint(1) | NO |  | 0 |  |

Indexes:
- `KEY idx_org_id` (org_id)
- `UNIQUE KEY uk_role_name_org` (name, org_id)

### `role_permissions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `role_id` | int(11) | NO | PRI |  |  |
| `permission_id` | int(11) | NO | PRI |  |  |

Indexes:
- `KEY idx_perm_id` (permission_id)

### `routing_log`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `route_id` | int(10) unsigned | NO | MUL |  |  |
| `source_channel` | varchar(64) | NO |  |  |  |
| `dest_channel` | varchar(64) | NO |  |  |  |
| `source_message_id` | int(10) unsigned | YES | MUL | NULL |  |
| `dest_message_id` | int(10) unsigned | YES |  | NULL |  |
| `status` | enum('forwarded','failed','skipped','loop_blocked') | NO |  | 'forwarded' |  |
| `error` | text | YES |  | NULL |  |
| `payload_summary` | varchar(500) | YES |  | '' |  |
| `routed_at` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_route` (route_id)
- `KEY idx_routed` (routed_at)
- `KEY idx_source_msg` (source_message_id)

### `scheduled_job_runs`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `job_key` | varchar(64) | NO | PRI |  |  |
| `last_run_at` | datetime | YES |  | NULL |  |
| `last_ok_at` | datetime | YES | MUL | NULL |  |
| `last_status` | enum('ok','error') | NO |  | 'ok' |  |
| `last_detail` | varchar(255) | YES |  | NULL |  |
| `last_duration_ms` | int(10) unsigned | YES |  | NULL |  |
| `run_count` | bigint(20) unsigned | NO |  | 0 |  |
| `error_count` | bigint(20) unsigned | NO |  | 0 |  |

Indexes:
- `KEY idx_last_ok` (last_ok_at)

### `scheduling_permission_assignments`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `profile_id` | int(11) | NO | MUL |  |  |
| `scope_type` | enum('global','template','event','role') | NO | MUL | 'global' |  |
| `scope_id` | int(11) | YES |  | NULL |  |
| `target_type` | enum('all','member','team','member_type') | NO | MUL | 'all' |  |
| `target_id` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_profile` (profile_id)
- `KEY idx_scope` (scope_type, scope_id)
- `KEY idx_target` (target_type, target_id)

### `scheduling_permission_profiles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(32) | NO | UNI |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `can_view_schedule` | tinyint(1) | NO |  | 1 |  |
| `can_view_own` | tinyint(1) | NO |  | 1 |  |
| `can_view_others` | tinyint(1) | NO |  | 0 |  |
| `can_view_available` | tinyint(1) | NO |  | 0 |  |
| `can_self_assign` | tinyint(1) | NO |  | 0 |  |
| `can_self_remove` | tinyint(1) | NO |  | 0 |  |
| `can_mark_unavailable` | tinyint(1) | NO |  | 0 |  |
| `can_swap` | tinyint(1) | NO |  | 0 |  |
| `can_request_cover` | tinyint(1) | NO |  | 0 |  |
| `can_assign_others` | tinyint(1) | NO |  | 0 |  |
| `can_remove_others` | tinyint(1) | NO |  | 0 |  |
| `can_change_status` | tinyint(1) | NO |  | 0 |  |
| `can_manage_slots` | tinyint(1) | NO |  | 0 |  |
| `sort_order` | int(11) | NO |  | 50 |  |
| `active` | tinyint(1) | NO |  | 1 |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `UNIQUE KEY code` (code)

### `security_labels`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `code` | varchar(32) | NO | UNI |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `sort_order` | int(10) unsigned | NO | MUL | 100 |  |
| `is_default` | tinyint(1) | NO |  | 0 |  |
| `badge_bg_color` | varchar(16) | YES |  | NULL |  |
| `badge_text_color` | varchar(16) | YES |  | NULL |  |
| `eoc_show_scope` | tinyint(1) | NO |  | 1 |  |
| `eoc_show_address` | tinyint(1) | NO |  | 1 |  |
| `eoc_show_map_marker` | enum('full','dim','hide') | NO |  | 'full' |  |
| `eoc_placeholder_text` | varchar(64) | YES |  | NULL |  |
| `routing_allow_broadcast` | tinyint(1) | NO |  | 1 |  |
| `routing_allow_direct` | tinyint(1) | NO |  | 1 |  |
| `routing_send_delay_secs` | int(10) unsigned | NO |  | 0 |  |
| `routing_recall_window_s` | int(10) unsigned | NO |  | 0 |  |
| `ics_export_show_full` | tinyint(1) | NO |  | 1 |  |
| `ics_watermark_text` | varchar(64) | YES |  | NULL |  |
| `audit_required_reason` | tinyint(1) | NO |  | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_sort` (sort_order)
- `UNIQUE KEY uniq_code` (code)

### `settings`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `name` | varchar(128) | NO | UNI |  |  |
| `value` | text | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)
- `UNIQUE KEY uq_name` (name)

### `severity_levels`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `value` | int(11) | NO | UNI |  |  |
| `label` | varchar(30) | NO |  |  |  |
| `color` | varchar(7) | NO |  | '#6c757d' |  |
| `sort_order` | int(11) | NO | MUL | 0 |  |
| `is_default` | tinyint(1) | NO |  | 0 |  |
| `is_high_alert` | tinyint(1) | NO |  | 0 |  |
| `_by` | int(11) | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_sort_order` (sort_order)
- `UNIQUE KEY uq_severity_value` (value)

### `showin_contactlist`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `fieldid` | varchar(48) | NO |  |  |  |
| `show_contact` | int(2) | NO |  | 0 |  |

### `signals`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(16) | NO | UNI |  |  |
| `description` | varchar(255) | NO |  | '' |  |
| `sort_order` | int(11) | NO | MUL | 0 |  |
| `hide` | enum('n','y') | NO |  | 'n' |  |
| `_by` | int(11) | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_sort` (sort_order)
- `UNIQUE KEY uq_code` (code)

### `skills`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `skill` | varchar(48) | NO |  |  |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `skills_x_user`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `skills_id` | int(3) | NO |  |  |  |
| `user_id` | int(4) | NO |  |  |  |
| `level` | enum('b','m','h','x','na') | NO |  | 'na' |  |
| `comment` | varchar(48) | YES |  | NULL |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | YES |  | NULL |  |
| `on` | datetime | YES |  | NULL |  |

### `sop_pages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `slug` | varchar(128) | NO | UNI |  |  |
| `title` | varchar(255) | NO |  |  |  |
| `content` | mediumtext | NO |  |  |  |
| `parent_id` | int(11) | YES | MUL | NULL |  |
| `owner_user_id` | int(11) | YES | MUL | NULL |  |
| `sort_order` | int(11) | YES |  | 0 |  |
| `created_by` | int(11) | NO |  |  |  |
| `created_at` | datetime | NO |  |  |  |
| `updated_by` | int(11) | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_sop_owner` (owner_user_id)
- `KEY parent_id` (parent_id)
- `UNIQUE KEY slug` (slug)

### `sop_revisions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `page_id` | int(11) | NO | MUL |  |  |
| `content` | mediumtext | NO |  |  |  |
| `title` | varchar(255) | NO |  |  |  |
| `edited_by` | int(11) | NO |  |  |  |
| `edited_at` | datetime | NO |  |  |  |
| `summary` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY page_id` (page_id)

### `sound_settings`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(2) | NO | PRI |  |  |
| `name` | varchar(48) | NO |  |  |  |
| `filename` | varchar(128) | NO |  |  |  |
| `mp3_filename` | varchar(128) | NO |  |  |  |
| `ison` | int(1) | NO |  | 0 |  |

### `sse_events`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(20) | NO | PRI |  | auto_increment |
| `event_type` | varchar(64) | NO | MUL |  |  |
| `payload` | text | NO |  | '{}' |  |
| `user_id` | int(11) | YES |  | NULL |  |
| `visibility_scope` | varchar(16) | NO | MUL | 'public' |  |
| `visibility_ids` | varchar(255) | YES |  | NULL |  |
| `created_at` | datetime(3) | NO | MUL | current_timestamp(3) |  |

Indexes:
- `KEY idx_created` (created_at)
- `KEY idx_type` (event_type)
- `KEY idx_visibility` (visibility_scope)

### `states_translator`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `code` | varchar(4) | NO |  |  |  |

### `stats_settings`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(3) | NO | PRI |  | auto_increment |
| `user_id` | int(3) | NO |  |  |  |
| `refresh_rate` | int(3) | NO |  | 10 |  |
| `f1` | int(3) | NO |  | 1 |  |
| `f2` | int(3) | NO |  | 2 |  |
| `f3` | int(3) | NO |  | 3 |  |
| `f4` | int(3) | NO |  | 4 |  |
| `f5` | int(3) | NO |  | 5 |  |
| `f6` | int(3) | NO |  | 6 |  |
| `f7` | int(3) | NO |  | 7 |  |
| `f8` | int(3) | NO |  | 8 |  |
| `threshold_1` | varchar(12) | NO |  | '0' |  |
| `threshold_2` | varchar(12) | NO |  | '0' |  |
| `threshold_3` | varchar(12) | NO |  | '0' |  |
| `threshold_4` | varchar(12) | NO |  | '0' |  |
| `threshold_5` | varchar(12) | NO |  | '0' |  |
| `threshold_6` | varchar(12) | NO |  | '0' |  |
| `threshold_7` | varchar(12) | NO |  | '0' |  |
| `threshold_8` | varchar(12) | NO |  | '0' |  |
| `thresholdw_1` | varchar(12) | NO |  | '0' |  |
| `thresholdw_2` | varchar(12) | NO |  | '0' |  |
| `thresholdw_3` | varchar(12) | NO |  | '0' |  |
| `thresholdw_4` | varchar(12) | NO |  | '0' |  |
| `thresholdw_5` | varchar(12) | NO |  | '0' |  |
| `thresholdw_6` | varchar(12) | NO |  | '0' |  |
| `thresholdw_7` | varchar(12) | NO |  | '0' |  |
| `thresholdw_8` | varchar(12) | NO |  | '0' |  |
| `thresholdf_1` | varchar(12) | NO |  | '0' |  |
| `thresholdf_2` | varchar(12) | NO |  | '0' |  |
| `thresholdf_3` | varchar(12) | NO |  | '0' |  |
| `thresholdf_4` | varchar(12) | NO |  | '0' |  |
| `thresholdf_5` | varchar(12) | NO |  | '0' |  |
| `thresholdf_6` | varchar(12) | NO |  | '0' |  |
| `thresholdf_7` | varchar(12) | NO |  | '0' |  |
| `thresholdf_8` | varchar(12) | NO |  | '0' |  |
| `t_type1` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type2` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type3` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type4` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type5` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type6` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type7` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |
| `t_type8` | enum('Less','Less or Equal','Equal','More or Equal','More') | NO |  | 'More' |  |

### `stats_type`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `st_id` | int(2) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `stat_type` | varchar(3) | NO |  | 'int' |  |

### `status_transitions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `from_status_id` | int(11) | NO | MUL |  |  |
| `to_status_id` | int(11) | NO |  |  |  |
| `conditions_json` | text | YES |  | NULL |  |
| `created_by` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `UNIQUE KEY uniq_edge` (from_status_id, to_status_id)

### `status_workflow_layout`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `status_id` | int(11) | NO | PRI |  |  |
| `pos_x` | int(11) | NO |  | 0 |  |
| `pos_y` | int(11) | NO |  | 0 |  |

### `std_msgs`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | NO |  |  |  |
| `message` | varchar(248) | NO |  |  |  |
| `groupby` | varchar(64) | YES |  | 'Messages' |  |
| `email` | int(2) | NO |  | 1 |  |
| `smsresponder` | int(2) | NO |  | 0 |  |
| `txtlocal` | int(2) | NO |  | 0 |  |
| `mototrbo` | int(2) | NO |  | 0 |  |
| `smsbroadcast` | int(2) | NO |  | 0 |  |

### `talkgroups`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(64) | NO |  |  |  |
| `dmr_id` | int(10) unsigned | NO | UNI |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `call_type` | enum('group','private') | NO | MUL | 'group' |  |
| `sort_order` | int(11) | NO | MUL | 0 |  |
| `enabled` | tinyint(1) | NO | MUL | 0 |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

Indexes:
- `KEY idx_call_type` (call_type)
- `KEY idx_enabled` (enabled)
- `KEY idx_sort` (sort_order, name)
- `UNIQUE KEY uniq_dmr_id` (dmr_id)

### `team`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | NO |  |  |  |
| `description` | varchar(512) | NO |  |  |  |
| `manager` | int(4) | NO |  |  |  |

### `teams`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `team` | varchar(48) | NO | UNI | '' |  |
| `sub-group` | varchar(48) | NO |  | '' |  |
| `ttypes_id` | int(7) | NO |  | 0 |  |
| `mission` | varchar(48) | NO |  | '' |  |
| `leader` | int(4) | NO |  | 0 |  |
| `leader_dpty` | int(4) | NO |  | 0 |  |
| `formed` | date | YES |  | NULL |  |
| `by` | int(7) | NO |  | 0 |  |
| `from` | varchar(16) | NO |  | '' |  |
| `on` | datetime | YES |  | NULL |  |
| `nims_resource_type` | varchar(64) | YES |  | NULL |  |
| `nims_typing_level` | tinyint(4) | YES |  | NULL |  |
| `rtlt_code` | varchar(32) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |
| `active` | tinyint(1) | YES |  | 1 |  |
| `name` | varchar(48) | YES |  | NULL | VIRTUAL GENERATED |
| `org_id` | int(11) | YES |  | NULL |  |
| `description` | text | YES |  | NULL |  |
| `team_type` | varchar(64) | YES |  | NULL |  |
| `leader_id` | int(11) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY uk_teams_team_name` (team)

### `teams_x_user`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `teams_id` | int(4) | NO |  |  |  |
| `member_id` | int(7) | NO |  |  |  |
| `status` | int(2) | YES |  | NULL |  |
| `date_a` | date | YES |  | NULL |  |
| `date_e` | date | YES |  | NULL |  |
| `comment` | varchar(48) | YES |  | NULL |  |
| `by` | int(7) | YES |  | NULL |  |
| `from` | varchar(16) | YES |  | NULL |  |
| `on` | datetime | YES |  | NULL |  |

### `team_members`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `team_id` | int(11) | NO | MUL |  |  |
| `member_id` | int(11) | NO | MUL |  |  |
| `role` | varchar(64) | YES |  | 'Member' |  |
| `position_code` | varchar(16) | YES |  | NULL |  |
| `assigned_date` | date | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |
| `source` | varchar(20) | YES |  | NULL |  |

Indexes:
- `KEY member_id` (member_id)
- `KEY team_id` (team_id)
- `UNIQUE KEY team_member` (team_id, member_id)

### `team_types`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `type` | varchar(48) | NO |  |  |  |
| `comment` | varchar(48) | NO |  |  |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `tfa_remember_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `token_hash` | varchar(64) | NO | MUL |  |  |
| `device_fingerprint` | varchar(64) | NO |  |  |  |
| `ip_address` | varchar(45) | NO |  |  |  |
| `user_agent` | varchar(512) | NO |  | '' |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `expires_at` | datetime | NO | MUL |  |  |

Indexes:
- `KEY idx_tfa_remember_expires` (expires_at)
- `KEY idx_tfa_remember_token` (token_hash)
- `KEY idx_tfa_remember_user` (user_id)

### `ticket`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `in_types_id` | int(4) | NO |  |  |  |
| `org` | int(3) | NO |  | 0 |  |
| `portal_user` | int(4) | YES |  | NULL |  |
| `contact` | varchar(48) | NO |  | '' |  |
| `street` | varchar(96) | YES |  | NULL |  |
| `address_about` | varchar(512) | YES |  | NULL |  |
| `city` | varchar(32) | YES |  | NULL |  |
| `state` | char(4) | YES |  | NULL |  |
| `phone` | varchar(16) | YES |  | NULL |  |
| `to_address` | varchar(1024) | YES |  | NULL |  |
| `facility` | int(4) | YES |  | 0 |  |
| `rec_facility` | int(4) | YES |  | 0 |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `date` | datetime | YES |  | NULL |  |
| `problemstart` | datetime | YES |  | NULL |  |
| `problemend` | datetime | YES |  | NULL |  |
| `scope` | text | NO |  |  |  |
| `affected` | text | YES |  | NULL |  |
| `description` | text | NO |  |  |  |
| `comments` | text | YES |  | NULL |  |
| `nine_one_one` | varchar(96) | YES |  | NULL |  |
| `signal` | varchar(8) | YES |  | NULL |  |
| `status` | tinyint(1) | NO |  | 0 |  |
| `owner` | tinyint(4) | NO |  | 0 |  |
| `severity` | int(2) | NO |  | 0 |  |
| `updated` | datetime | YES |  | NULL |  |
| `booked_date` | datetime | YES |  | NULL |  |
| `_by` | int(7) | YES |  | NULL |  |
| `org_id` | int(11) | YES | MUL | NULL |  |
| `incident_number` | varchar(64) | YES | UNI | NULL |  |
| `par_cadence_override_min` | int(10) unsigned | YES |  | NULL |  |
| `par_last_cycle_at` | datetime | YES | MUL | NULL |  |
| `security_label_override_id` | int(10) unsigned | YES |  | NULL |  |
| `security_set_by` | int(10) unsigned | YES |  | NULL |  |
| `security_set_at` | datetime | YES |  | NULL |  |
| `security_reason` | varchar(255) | YES |  | NULL |  |
| `par_last_overdue_broadcast_at` | datetime | YES |  | NULL |  |
| `auto_close_scheduled_at` | datetime | YES | MUL | NULL |  |
| `deleted_at` | datetime | YES | MUL | NULL |  |
| `deleted_by` | int(11) | YES |  | NULL |  |
| `disposition_id` | int(11) | YES | MUL | NULL |  |
| `primary_responder_id` | int(11) | YES | MUL | NULL |  |
| `primary_set_at` | datetime | YES |  | NULL |  |
| `primary_set_by` | int(11) | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY ID` (id)
- `KEY idx_auto_close_sched` (auto_close_scheduled_at)
- `KEY idx_deleted_at` (deleted_at)
- `KEY idx_par_last_cycle` (par_last_cycle_at)
- `KEY idx_ticket_disposition` (disposition_id)
- `KEY idx_ticket_org` (org_id)
- `KEY idx_ticket_primary_responder` (primary_responder_id) — Phase 151 (GH#138)
- `UNIQUE KEY uniq_incident_number` (incident_number)

### `ticket_disposition`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `status_val` | varchar(64) | NO |  |  |  |
| `description` | text | NO |  | '' |  |
| `code` | varchar(64) | NO | MUL |  |  |
| `discipline` | varchar(32) | NO | MUL | '' |  |
| `org_id` | int(11) | YES | MUL | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `requires_comment` | tinyint(1) | NO |  | 0 |  |
| `active` | tinyint(1) | NO | MUL | 1 |  |

Indexes:
- `KEY idx_active` (active)
- `KEY idx_code` (code)
- `KEY idx_discipline` (discipline)
- `KEY idx_org_id` (org_id)

### `time_activity_types`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | NO | UNI |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |
| `active` | tinyint(1) | NO |  | 1 |  |
| `auto_approve` | tinyint(1) | NO |  | 0 |  |

Indexes:
- `UNIQUE KEY name` (name)

### `tips`

Engine: MyISAM · Collation: utf8mb3_unicode_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `title` | varchar(24) | NO |  |  |  |
| `tip` | text | NO |  |  |  |
| `_by` | int(7) | NO |  | 0 |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | timestamp | NO |  | current_timestamp() |  |

### `titles`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `title` | varchar(24) | NO |  |  |  |
| `by` | int(7) | NO |  |  |  |
| `from` | varchar(16) | NO |  |  |  |
| `on` | datetime | NO |  |  |  |

### `tracks`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(7) | NO | PRI |  | auto_increment |
| `packet_id` | varchar(48) | YES | UNI | NULL |  |
| `source` | varchar(96) | YES |  | NULL |  |
| `latitude` | double | YES |  | NULL |  |
| `longitude` | double | YES |  | NULL |  |
| `speed` | int(8) | YES |  | NULL |  |
| `course` | int(8) | YES |  | NULL |  |
| `altitude` | int(8) | YES |  | NULL |  |
| `symbol_table` | varchar(96) | YES |  | NULL |  |
| `symbol_code` | varchar(96) | YES |  | NULL |  |
| `status` | varchar(96) | YES |  | NULL |  |
| `closest_city` | varchar(200) | YES |  | NULL |  |
| `mapserver_url_street` | varchar(200) | YES |  | NULL |  |
| `mapserver_url_regional` | varchar(200) | YES |  | NULL |  |
| `packet_date` | datetime | YES |  | NULL |  |
| `updated` | datetime | NO |  |  |  |

Indexes:
- `UNIQUE KEY packet_id` (packet_id)

### `tracks_hh`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(7) | NO | PRI |  | auto_increment |
| `source` | varchar(96) | YES |  | NULL |  |
| `latitude` | double | YES |  | NULL |  |
| `longitude` | double | YES |  | NULL |  |
| `speed` | int(8) | YES |  | NULL |  |
| `course` | int(8) | YES |  | NULL |  |
| `altitude` | int(8) | YES |  | NULL |  |
| `utc_stamp` | bigint(12) | YES |  | NULL |  |
| `status` | varchar(96) | YES |  | NULL |  |
| `closest_city` | varchar(200) | YES |  | NULL |  |
| `updated` | datetime | NO |  |  |  |
| `from` | varchar(16) | YES |  | NULL |  |

### `training_packages`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `package_name` | varchar(48) | YES |  | NULL |  |
| `description` | longtext | YES |  | NULL |  |
| `available` | enum('Yes','No') | NO |  | 'No' |  |
| `provider` | varchar(48) | YES |  | NULL |  |
| `address` | varchar(128) | YES |  | NULL |  |
| `name` | varchar(48) | YES |  | NULL |  |
| `email` | varchar(64) | YES |  | NULL |  |
| `phone` | varchar(48) | YES |  | NULL |  |
| `cost` | int(16) | YES |  | NULL |  |

### `training_records`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `member_id` | int(11) | NO | MUL |  |  |
| `training_name` | varchar(255) | NO |  |  |  |
| `training_type` | enum('Course','Drill','Exercise','Workshop','OJT','Webinar','Self-Study') | YES |  | 'Course' |  |
| `training_date` | date | YES | MUL | NULL |  |
| `hours` | decimal(5,1) | YES |  | NULL |  |
| `location` | varchar(255) | YES |  | NULL |  |
| `instructor` | varchar(128) | YES |  | NULL |  |
| `result` | enum('Completed','Incomplete','Failed','In Progress') | YES |  | 'Completed' |  |
| `fema_course_code` | varchar(32) | YES | MUL | NULL |  |
| `certificate_number` | varchar(64) | YES |  | NULL |  |
| `notes` | text | YES |  | NULL |  |
| `created_at` | datetime | YES |  | NULL |  |
| `updated_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY fema_course_code` (fema_course_code)
- `KEY member_id` (member_id)
- `KEY training_date` (training_date)

### `tts_applications`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `app_key` | varchar(32) | NO | UNI |  |  |
| `label` | varchar(80) | NO |  | '' |  |
| `engine_id` | int(11) | YES |  | NULL |  |
| `voice` | varchar(120) | YES |  | NULL |  |
| `rate` | int(11) | NO |  | 8000 |  |
| `fallback_engine_id` | int(11) | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |

Indexes:
- `UNIQUE KEY uk_app_key` (app_key)

### `tts_engines`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `engine_key` | varchar(48) | NO | UNI |  |  |
| `driver` | varchar(24) | NO |  | 'piper' |  |
| `label` | varchar(80) | NO |  | '' |  |
| `config_json` | text | YES |  | NULL |  |
| `enabled` | tinyint(1) | NO |  | 1 |  |
| `last_ok_at` | datetime | YES |  | NULL |  |
| `last_error` | varchar(255) | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 0 |  |

Indexes:
- `UNIQUE KEY uk_engine_key` (engine_key)

### `unit_assignment_roles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `code` | varchar(32) | NO | UNI |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `description` | varchar(255) | YES |  | NULL |  |
| `sort_order` | int(11) | NO |  | 50 |  |
| `active` | tinyint(1) | NO |  | 1 |  |

Indexes:
- `UNIQUE KEY code` (code)

### `unit_location_bindings`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `responder_id` | int(11) | NO | MUL |  |  |
| `provider_id` | int(11) | NO | MUL |  |  |
| `unit_identifier` | varchar(64) | NO | MUL |  |  |
| `priority` | int(11) | NO |  | 50 |  |
| `active` | tinyint(1) | NO | MUL | 1 |  |
| `source` | enum('manual','personnel') | NO |  | 'manual' |  |
| `assignment_id` | int(11) | YES |  | NULL |  |
| `created_at` | datetime | YES |  | current_timestamp() |  |

Indexes:
- `KEY idx_ulb_active` (active)
- `KEY idx_ulb_provider` (provider_id)
- `KEY idx_ulb_responder` (responder_id)
- `KEY idx_ulb_unit` (unit_identifier)

### `unit_personnel_assignments`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `responder_id` | int(11) | NO | MUL |  |  |
| `member_id` | int(11) | NO | MUL |  |  |
| `role` | varchar(32) | NO |  | 'operator' |  |
| `status` | enum('active','standby','released') | NO | MUL | 'active' |  |
| `assigned_at` | datetime | NO |  | current_timestamp() |  |
| `released_at` | datetime | YES |  | NULL |  |
| `assigned_by` | int(11) | YES |  | NULL |  |
| `notes` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_upa_active` (responder_id, status, released_at)
- `KEY idx_upa_member` (member_id)
- `KEY idx_upa_responder` (responder_id)
- `KEY idx_upa_status` (status)

### `unit_types`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(32) | NO |  |  |  |
| `description` | varchar(48) | NO |  |  |  |
| `icon` | int(3) | NO |  | 0 |  |
| `_on` | datetime | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_by` | int(7) | NO |  | 0 |  |

### `un_status`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `status_val` | varchar(20) | NO |  |  |  |
| `description` | varchar(60) | NO |  |  |  |
| `dispatch` | int(1) | NO |  | 0 |  |
| `watch` | int(1) | NO |  | 0 |  |
| `hide` | enum('n','y') | NO |  | 'n' |  |
| `excl_from_reset` | enum('n','y') | NO |  | 'n' |  |
| `group` | varchar(20) | YES |  | NULL |  |
| `sort` | int(11) | NO |  | 0 |  |
| `bg_color` | varchar(16) | NO |  | 'transparent' |  |
| `text_color` | varchar(16) | NO |  | '#000000' |  |
| `incident_action` | enum('','dispatched','responding','on_scene','facility_enroute','facility_arrived','clear') | NO |  | '' |  |
| `resets_par` | tinyint(1) | NO |  | 0 |  |
| `extra_data_type` | enum('none','facility','mileage','location','note','numeric') | NO |  | 'none' |  |
| `extra_data_required` | tinyint(1) | NO |  | 0 |  |
| `extra_data_label` | varchar(64) | YES |  | NULL |  |
| `extra_data_target` | enum('incident','unit','action_log','assignment') | NO |  | 'action_log' |  |
| `bed_delivery` | tinyint(1) | NO |  | 0 |  |
| `hide_from_board` | tinyint(1) | NO |  | 0 |  |
| `units_filter` | enum('available','in_service','unavailable') | YES |  | NULL |  |
| `extra_data_type_2` | enum('none','facility','mileage','location','note','numeric') | NO |  | 'none' |  |
| `extra_data_required_2` | tinyint(1) | NO |  | 0 |  |
| `extra_data_label_2` | varchar(64) | YES |  | NULL |  |
| `extra_data_target_2` | enum('incident','unit','action_log','assignment') | NO |  | 'action_log' |  |

Indexes:
- `UNIQUE KEY ID` (id)

### `user`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `user` | text | NO |  |  |  |
| `passwd` | tinytext | NO |  |  |  |
| `name_l` | text | YES |  | NULL |  |
| `name_f` | text | YES |  | NULL |  |
| `name_mi` | text | YES |  | NULL |  |
| `member` | int(11) | YES |  | NULL |  |
| `dob` | text | YES |  | NULL |  |
| `title_id` | tinyint(2) | YES |  | NULL |  |
| `addr_street` | text | YES |  | NULL |  |
| `addr_city` | text | YES |  | NULL |  |
| `addr_st` | text | YES |  | NULL |  |
| `disp` | tinyint(1) | YES |  | 1 |  |
| `files` | tinyint(1) | YES |  | 0 |  |
| `pers` | tinyint(1) | YES |  | 0 |  |
| `org` | int(3) | NO |  | 0 |  |
| `home_org_id` | int(11) | YES |  | NULL |  |
| `teams` | tinyint(1) | YES |  | 0 |  |
| `status` | enum('approved','pending','na') | NO |  | 'approved' |  |
| `open_at` | enum('d','f','p','t') | NO |  | 'd' |  |
| `ident` | text | YES |  | NULL |  |
| `info` | text | YES |  | NULL |  |
| `phone_p` | text | YES |  | NULL |  |
| `phone_s` | text | YES |  | NULL |  |
| `phone_m` | text | YES |  | NULL |  |
| `level` | tinyint(1) | NO |  | 0 |  |
| `responder_id` | int(7) | NO |  | 0 |  |
| `facility_id` | int(7) | NO |  | 0 |  |
| `email` | text | YES |  | NULL |  |
| `email_s` | text | YES |  | NULL |  |
| `ticket_per_page` | tinyint(1) | YES |  | NULL |  |
| `sort_desc` | tinyint(1) | YES |  | 0 |  |
| `sortorder` | tinytext | YES |  | NULL |  |
| `reporting` | tinyint(1) | YES |  | 1 |  |
| `callsign` | varchar(12) | YES |  | NULL |  |
| `db_prefix` | text | YES |  | NULL |  |
| `expires` | timestamp | YES |  | NULL |  |
| `sid` | varchar(40) | YES |  | NULL |  |
| `login` | timestamp | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `browser` | varchar(40) | YES |  | NULL |  |
| `can_login` | tinyint(1) | NO |  | 1 |  |
| `preferred_lang` | varchar(8) | YES |  | NULL |  |
| `must_change_password` | tinyint(1) | NO | MUL | 0 |  |
| `password_changed_at` | datetime | YES |  | NULL |  |
| `password_reminder_snoozed_until` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_must_change_password` (must_change_password)

### `user_password_history`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `hash` | varchar(255) | NO |  |  |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |

Indexes:
- `KEY idx_user_created` (user_id, created_at)

### `user_roles`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | MUL |  |  |
| `role_id` | int(11) | NO | MUL |  |  |
| `org_id` | int(11) | YES |  | NULL |  |
| `scope_kind` | enum('global','org','team','self','delegate') | NO | MUL | 'global' |  |
| `scope_id` | int(11) | YES |  | NULL |  |
| `expires_at` | datetime | YES | MUL | NULL |  |
| `granted_by` | int(11) | YES |  | NULL |  |
| `granted_at` | datetime | NO |  | current_timestamp() |  |
| `reason` | varchar(255) | YES |  | NULL |  |
| `delegated_by` | int(11) | YES |  | NULL |  |
| `delegation_depth` | tinyint(4) | NO |  | 0 |  |
| `scope_key` | int(11) | YES |  | NULL | STORED GENERATED, INVISIBLE |

Indexes:
- `KEY idx_expires` (expires_at)
- `KEY idx_role_id` (role_id)
- `KEY idx_scope` (scope_kind, scope_id)
- `KEY idx_user_id` (user_id)
- `UNIQUE KEY uk_user_role_scope` (user_id, role_id, scope_kind, scope_key)

### `user_roles_pre_v2_backup`

Engine: InnoDB · Collation: utf8mb4_general_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO |  | 0 |  |
| `user_id` | int(11) | NO |  |  |  |
| `role_id` | int(11) | NO |  |  |  |
| `org_id` | int(11) | YES |  | NULL |  |
| `scope_kind` | enum('global','org','team','self','delegate') | NO |  | 'global' |  |
| `scope_id` | int(11) | YES |  | NULL |  |
| `expires_at` | datetime | YES |  | NULL |  |
| `granted_by` | int(11) | YES |  | NULL |  |
| `granted_at` | datetime | NO |  | current_timestamp() |  |
| `reason` | varchar(255) | YES |  | NULL |  |

### `user_screen_prefs`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `user_id` | int(10) unsigned | NO | PRI |  |  |
| `screen` | varchar(48) | NO | PRI |  |  |
| `prefs_json` | text | NO |  |  |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

### `user_tfa`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `user_id` | int(11) | NO | UNI |  |  |
| `secret_encrypted` | blob | NO |  |  |  |
| `backup_codes_json` | text | NO |  |  |  |
| `confirmed` | tinyint(1) | NO |  | 0 |  |
| `enrolled_at` | datetime | NO | MUL | current_timestamp() |  |
| `last_used_at` | datetime | YES |  | NULL |  |
| `last_used_counter` | bigint(20) | YES |  | NULL |  |

Indexes:
- `KEY idx_user_tfa_enrolled` (enrolled_at)
- `UNIQUE KEY uk_user_tfa_user` (user_id)

### `vehicles`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(8) | NO | PRI |  | auto_increment |
| `owner` | int(4) | YES |  | NULL |  |
| `make` | varchar(16) | NO |  |  |  |
| `model` | varchar(28) | NO |  |  |  |
| `year` | varchar(4) | YES |  | NULL |  |
| `color` | varchar(4) | YES |  | NULL |  |
| `regno` | varchar(12) | NO |  |  |  |
| `type` | tinyint(2) | NO |  | 0 |  |
| `seats` | tinyint(2) | NO |  |  |  |
| `fueltype` | enum('Petrol','Diesel','LPG','EV','HEV','PHEV') | NO |  |  |  |
| `roofrack` | enum('Yes','No') | NO |  | 'No' |  |
| `towbar` | enum('None','Ball','Ball and Pin','NATO') | NO |  | 'None' |  |
| `winch` | enum('None','Electric','Hydraulic','PTO','Hand') | NO |  | 'None' |  |
| `trailer` | enum('Yes','No') | NO |  | 'No' |  |
| `vin` | varchar(48) | YES |  | NULL |  |
| `excise` | date | YES |  | NULL |  |
| `test` | date | YES |  | NULL |  |
| `insurance` | date | YES |  | NULL |  |
| `notes` | longtext | YES |  | NULL |  |
| `_by` | int(11) | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `_on` | datetime | NO |  |  |  |

### `vehicle_types`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | bigint(4) | NO | PRI |  | auto_increment |
| `name` | varchar(48) | YES | UNI | NULL |  |
| `description` | longtext | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY uk_vt_name` (name)

### `warnings`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(7) | NO | PRI |  | auto_increment |
| `title` | text | NO |  |  |  |
| `street` | varchar(96) | NO |  |  |  |
| `city` | varchar(32) | NO |  |  |  |
| `state` | char(4) | NO |  |  |  |
| `lat` | double | NO |  |  |  |
| `lng` | double | NO |  |  |  |
| `radius` | int(11) | NO |  | 500 |  |
| `loc_type` | smallint(4) | NO |  | 4 |  |
| `description` | text | NO |  |  |  |
| `_by` | int(7) | YES |  | NULL |  |
| `_on` | datetime | YES |  | NULL |  |
| `_from` | varchar(45) | YES |  | NULL |  |

### `waste_basket_f`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | smallint(3) | NO | PRI |  | auto_increment |
| `member_id` | smallint(3) | NO |  |  |  |
| `name` | varchar(64) | NO |  |  |  |
| `shortname` | varchar(32) | NO |  |  |  |
| `description` | varchar(24) | NO |  |  |  |
| `_on` | datetime | NO |  |  |  |

### `waste_basket_m`

Engine: MyISAM · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(8) | NO | PRI |  | auto_increment |
| `field1` | varchar(28) | YES |  | NULL |  |
| `field2` | varchar(28) | YES |  | NULL |  |
| `field3` | int(4) | NO |  | 0 |  |
| `field4` | varchar(16) | YES |  | NULL |  |
| `field5` | varchar(64) | YES |  | NULL |  |
| `field6` | varchar(48) | YES |  | NULL |  |
| `field7` | bigint(4) | NO |  |  |  |
| `field8` | enum('Yes','No') | NO |  | 'Yes' |  |
| `field9` | varchar(28) | YES |  | NULL |  |
| `field10` | varchar(28) | YES |  | NULL |  |
| `field11` | varchar(12) | YES |  | NULL |  |
| `field12` | double | YES |  | NULL |  |
| `field13` | double | YES |  | NULL |  |
| `field14` | longtext | YES |  | NULL |  |
| `field15` | enum('Yes','No') | NO |  | 'No' |  |
| `field16` | datetime | YES |  | NULL |  |
| `field17` | datetime | YES |  | NULL |  |
| `field18` | datetime | YES |  | NULL |  |
| `field19` | enum('Yes','No') | NO |  | 'No' |  |
| `field20` | longtext | YES |  | NULL |  |
| `field21` | int(4) | NO |  | 0 |  |
| `field22` | varchar(1024) | YES |  | NULL |  |
| `field23` | varchar(1024) | YES |  | NULL |  |
| `field24` | varchar(1024) | YES |  | NULL |  |
| `field25` | varchar(1024) | YES |  | NULL |  |
| `field26` | varchar(1024) | YES |  | NULL |  |
| `field27` | varchar(1024) | YES |  | NULL |  |
| `field28` | varchar(1024) | YES |  | NULL |  |
| `field29` | varchar(1024) | YES |  | NULL |  |
| `field30` | varchar(1024) | YES |  | NULL |  |
| `field31` | varchar(1024) | YES |  | NULL |  |
| `field32` | varchar(1024) | YES |  | NULL |  |
| `field33` | varchar(1024) | YES |  | NULL |  |
| `field34` | varchar(1024) | YES |  | NULL |  |
| `field35` | varchar(1024) | YES |  | NULL |  |
| `field36` | varchar(1024) | YES |  | NULL |  |
| `field37` | varchar(1024) | YES |  | NULL |  |
| `field38` | varchar(1024) | YES |  | NULL |  |
| `field39` | varchar(1024) | YES |  | NULL |  |
| `field40` | varchar(1024) | YES |  | NULL |  |
| `field41` | varchar(1024) | YES |  | NULL |  |
| `field42` | varchar(1024) | YES |  | NULL |  |
| `field43` | varchar(1024) | YES |  | NULL |  |
| `field44` | varchar(1024) | YES |  | NULL |  |
| `field45` | varchar(1024) | YES |  | NULL |  |
| `field46` | enum('Yes','No') | YES |  | NULL |  |
| `field47` | enum('Yes','No') | YES |  | NULL |  |
| `field48` | enum('Yes','No') | YES |  | NULL |  |
| `field49` | enum('Yes','No') | YES |  | NULL |  |
| `field50` | enum('Yes','No') | YES |  | NULL |  |
| `field51` | enum('Yes','No') | YES |  | NULL |  |
| `field52` | enum('Yes','No') | YES |  | NULL |  |
| `field53` | enum('Yes','No') | YES |  | NULL |  |
| `field54` | enum('Yes','No') | YES |  | NULL |  |
| `field55` | enum('Yes','No') | YES |  | NULL |  |
| `field56` | datetime | YES |  | NULL |  |
| `field57` | datetime | YES |  | NULL |  |
| `field58` | datetime | YES |  | NULL |  |
| `field59` | datetime | YES |  | NULL |  |
| `field60` | datetime | YES |  | NULL |  |
| `field61` | datetime | YES |  | NULL |  |
| `field62` | datetime | YES |  | NULL |  |
| `field63` | datetime | YES |  | NULL |  |
| `field64` | datetime | YES |  | NULL |  |
| `field65` | datetime | YES |  | NULL |  |
| `_by` | int(7) | NO |  | 1 |  |
| `_on` | datetime | NO |  |  |  |
| `_from` | varchar(45) | YES |  | NULL |  |
| `training` | varchar(128) | YES |  | NULL |  |
| `capabilities` | varchar(128) | YES |  | NULL |  |
| `equipment` | varchar(128) | YES |  | NULL |  |
| `vehicles` | varchar(128) | YES |  | NULL |  |
| `clothing` | varchar(128) | YES |  | NULL |  |
| `files` | varchar(128) | YES |  | NULL |  |
| `old_id` | int(8) | NO |  |  |  |

### `weather_alerts`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `nws_id` | varchar(255) | NO | UNI |  |  |
| `event` | varchar(120) | YES |  | NULL |  |
| `severity` | varchar(20) | YES |  | NULL |  |
| `urgency` | varchar(20) | YES |  | NULL |  |
| `certainty` | varchar(20) | YES |  | NULL |  |
| `message_type` | varchar(20) | YES |  | NULL |  |
| `area_desc` | text | YES |  | NULL |  |
| `headline` | text | YES |  | NULL |  |
| `description` | mediumtext | YES |  | NULL |  |
| `instruction` | mediumtext | YES |  | NULL |  |
| `onset` | datetime | YES |  | NULL |  |
| `expires` | datetime | YES |  | NULL |  |
| `ends` | datetime | YES |  | NULL |  |
| `geocode_ugc` | text | YES |  | NULL |  |
| `polygon` | mediumtext | YES |  | NULL |  |
| `centroid_lat` | decimal(9,6) | YES |  | NULL |  |
| `centroid_lng` | decimal(9,6) | YES |  | NULL |  |
| `status` | enum('active','cancelled','expired') | NO | MUL | 'active' |  |
| `first_seen` | datetime | NO |  |  |  |
| `last_seen` | datetime | NO |  |  |  |

Indexes:
- `KEY idx_status` (status)
- `UNIQUE KEY uk_nws` (nws_id)

### `weather_alert_areas`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `label` | varchar(120) | NO |  |  |  |
| `kind` | enum('state','zones','point_radius') | NO |  |  |  |
| `state_code` | char(2) | YES |  | NULL |  |
| `zones` | text | YES |  | NULL |  |
| `lat` | decimal(9,6) | YES |  | NULL |  |
| `lng` | decimal(9,6) | YES |  | NULL |  |
| `radius_miles` | int(11) | YES |  | NULL |  |
| `active` | tinyint(1) | NO |  | 1 |  |
| `sort_order` | int(11) | NO |  | 0 |  |

### `weather_alert_dispatch`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `alert_id` | int(11) | NO | MUL |  |  |
| `rule_id` | int(11) | NO |  |  |  |
| `nws_message_type` | varchar(20) | YES |  | NULL |  |
| `status` | enum('sent','queued','skipped','failed') | NO |  |  |  |
| `detail` | varchar(255) | YES |  | NULL |  |
| `created_at` | datetime | NO |  |  |  |

Indexes:
- `KEY idx_alert` (alert_id)
- `UNIQUE KEY uk_once` (alert_id, rule_id, nws_message_type)

### `weather_alert_rules`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `label` | varchar(120) | NO |  |  |  |
| `area_id` | int(11) | NO | MUL |  |  |
| `target` | enum('tray','chat','sms','email','zello','dmr') | NO |  |  |  |
| `target_ref` | varchar(120) | YES |  | NULL |  |
| `min_severity` | enum('Minor','Moderate','Severe','Extreme') | NO |  | 'Severe' |  |
| `min_urgency` | enum('Past','Future','Expected','Immediate') | NO |  | 'Expected' |  |
| `event_allow` | text | YES |  | NULL |  |
| `event_deny` | text | YES |  | NULL |  |
| `message_types` | varchar(60) | NO |  | 'Alert,Update' |  |
| `action_mode` | enum('notify','auto_fire','operator_approve') | NO |  | 'notify' |  |
| `repeat_on_update` | tinyint(1) | NO |  | 1 |  |
| `active` | tinyint(1) | NO |  | 1 |  |
| `sort_order` | int(11) | NO |  | 0 |  |

Indexes:
- `KEY idx_area` (area_id)

### `webhook_deliveries`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `subscription_id` | int(11) | NO | MUL | 0 |  |
| `webhook_id` | int(11) | YES | MUL | NULL |  |
| `event_type` | varchar(64) | NO | MUL | '' |  |
| `payload` | text | NO |  |  |  |
| `http_status` | int(11) | YES |  | NULL |  |
| `response_body` | text | YES |  | NULL |  |
| `duration_ms` | int(11) | YES |  | NULL |  |
| `attempt` | tinyint(4) | NO |  | 1 |  |
| `status` | varchar(16) | NO | MUL | 'pending' |  |
| `error` | varchar(512) | YES |  | NULL |  |
| `delivery_uid` | varchar(36) | YES | MUL | NULL |  |
| `created_at` | datetime | NO | MUL | current_timestamp() |  |
| `dead_lettered_at` | datetime | YES | MUL | NULL |  |
| `replayed_from_id` | int(11) | YES |  | NULL |  |

Indexes:
- `KEY idx_wd_created_at` (created_at)
- `KEY idx_wd_dead_letter` (dead_lettered_at)
- `KEY idx_wd_delivery_uid` (delivery_uid)
- `KEY idx_wd_event_type` (event_type)
- `KEY idx_wd_status` (status)
- `KEY idx_wd_subscription` (subscription_id)
- `KEY idx_wd_webhook_id` (webhook_id)

### `webhook_subscriptions`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `name` | varchar(120) | NO |  |  |  |
| `description` | varchar(512) | YES |  | NULL |  |
| `target_url` | varchar(512) | NO |  |  |  |
| `hmac_secret` | varchar(128) | NO |  |  |  |
| `event_filters_json` | text | NO |  |  |  |
| `retry_policy_json` | text | YES |  | NULL |  |
| `active` | tinyint(1) | NO | MUL | 1 |  |
| `ip_allowlist_json` | text | YES |  | NULL |  |
| `created_by` | int(11) | YES | MUL | NULL |  |
| `created_at` | datetime | NO |  | current_timestamp() |  |
| `updated_at` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `last_success_at` | datetime | YES |  | NULL |  |
| `last_failure_at` | datetime | YES |  | NULL |  |
| `dead_letter_count` | int(11) | NO |  | 0 |  |

Indexes:
- `KEY idx_ws_active` (active)
- `KEY idx_ws_created_by` (created_by)

### `wizard_settings`

Engine: InnoDB · Collation: latin1_swedish_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(4) | NO | PRI |  | auto_increment |
| `screen` | int(11) | NO |  |  |  |
| `fieldname` | varchar(512) | YES |  | NULL |  |
| `label` | varchar(64) | NO |  |  |  |
| `default_text` | varchar(64) | NO |  |  |  |
| `helptext` | varchar(1024) | NO |  |  |  |
| `display_order` | int(2) | NO |  |  |  |
| `fieldtype` | varchar(64) | NO |  |  |  |
| `size` | int(4) | NO |  |  |  |
| `maxlength` | int(4) | NO |  |  |  |

### `zello_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `channel` | varchar(100) | NO | MUL | '' |  |
| `recipient` | varchar(100) | NO | MUL | '' |  |
| `direction` | enum('incoming','outgoing') | NO |  | 'incoming' |  |
| `message_type` | varchar(20) | NO |  | 'text' |  |
| `sender_username` | varchar(100) | NO |  | '' |  |
| `sender_display` | varchar(100) | NO |  | '' |  |
| `content` | text | YES |  | NULL |  |
| `incident_id` | int(10) unsigned | YES | MUL | NULL |  |
| `created` | datetime | NO | MUL | current_timestamp() |  |
| `duration_ms` | int(10) unsigned | YES |  | NULL |  |
| `media_url` | varchar(255) | YES |  | NULL |  |

Indexes:
- `KEY idx_channel` (channel)
- `KEY idx_created` (created)
- `KEY idx_incident` (incident_id)
- `KEY idx_recipient` (recipient)

### `zello_outbox`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `kind` | varchar(16) | NO |  | 'text' |  |
| `channel` | varchar(100) | NO |  | '' |  |
| `recipient` | varchar(100) | NO |  | '' |  |
| `body` | text | NO |  |  |  |
| `status` | enum('queued','claimed','sent','failed') | NO | MUL | 'queued' |  |
| `error` | varchar(255) | YES |  | NULL |  |
| `queued_by` | int(10) unsigned | YES |  | NULL |  |
| `source` | varchar(32) | NO |  | 'router' |  |
| `queued_at` | datetime | NO | MUL | current_timestamp() |  |
| `claimed_at` | datetime | YES |  | NULL |  |
| `completed_at` | datetime | YES |  | NULL |  |

Indexes:
- `KEY idx_queued` (queued_at)
- `KEY idx_status` (status)

### `zello_user_config`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `user` | varchar(64) | NO | PRI |  |  |
| `ptt_key` | varchar(20) | NO |  | 'Space' |  |
| `auto_connect` | tinyint(1) | NO |  | 0 |  |
| `play_sounds` | tinyint(1) | NO |  | 1 |  |
| `updated` | datetime | NO |  | current_timestamp() | on update current_timestamp() |
| `user_id` | int(11) | YES | UNI | NULL |  |
| `zello_username` | varchar(64) | YES |  | NULL |  |
| `zello_password` | varchar(190) | YES |  | NULL |  |
| `enabled` | tinyint(1) | NO |  | 1 |  |

Indexes:
- `UNIQUE KEY uniq_user_id` (user_id)

### `zello_ws_tokens`

Engine: InnoDB · Collation: utf8mb4_general_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `token` | varchar(64) | NO | PRI |  |  |
| `user` | varchar(64) | NO |  |  |  |
| `user_level` | int(11) | NO |  | 99 |  |
| `created` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_created` (created)

### `zipcodes`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `zip` | varchar(10) | NO | PRI |  |  |
| `city` | varchar(64) | NO | MUL |  |  |
| `state` | varchar(4) | NO | MUL |  |  |
| `county` | varchar(64) | YES |  | NULL |  |
| `lat` | double | YES |  | NULL |  |
| `lng` | double | YES |  | NULL |  |
| `timezone` | varchar(48) | YES |  | NULL |  |

Indexes:
- `KEY idx_city_state` (city, state)
- `KEY idx_state` (state)

### `_migrations`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(11) | NO | PRI |  | auto_increment |
| `script_name` | varchar(128) | NO | MUL |  |  |
| `script_hash` | char(64) | NO |  |  |  |
| `applied_at` | datetime | NO |  | current_timestamp() |  |
| `applied_by` | varchar(64) | YES |  | NULL |  |
| `duration_ms` | int(11) | YES |  | NULL |  |
| `status` | enum('ok','failed') | NO |  | 'ok' |  |
| `notes` | text | YES |  | NULL |  |

Indexes:
- `UNIQUE KEY uk_script_hash` (script_name, script_hash)

### `{}zello_messages`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `id` | int(10) unsigned | NO | PRI |  | auto_increment |
| `channel` | varchar(100) | NO | MUL | '' |  |
| `direction` | enum('incoming','outgoing') | NO |  | 'incoming' |  |
| `message_type` | varchar(20) | NO |  | 'text' |  |
| `sender_username` | varchar(100) | NO |  | '' |  |
| `sender_display` | varchar(100) | NO |  | '' |  |
| `content` | text | YES |  | NULL |  |
| `incident_id` | int(10) unsigned | YES | MUL | NULL |  |
| `created` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_channel` (channel)
- `KEY idx_created` (created)
- `KEY idx_incident` (incident_id)

### `{}zello_user_config`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `user` | varchar(64) | NO | PRI |  |  |
| `ptt_key` | varchar(20) | NO |  | 'Space' |  |
| `auto_connect` | tinyint(1) | NO |  | 0 |  |
| `play_sounds` | tinyint(1) | NO |  | 1 |  |
| `updated` | datetime | NO |  | current_timestamp() | on update current_timestamp() |

### `{}zello_ws_tokens`

Engine: InnoDB · Collation: utf8mb4_uca1400_ai_ci

| Column | Type | Null | Key | Default | Extra |
|---|---|---|---|---|---|
| `token` | varchar(64) | NO | PRI |  |  |
| `user` | varchar(64) | NO |  |  |  |
| `user_level` | int(11) | NO |  | 99 |  |
| `created` | datetime | NO | MUL | current_timestamp() |  |

Indexes:
- `KEY idx_created` (created)

