# NewUI SQL Scripts

All scripts in this directory manage database schema for TicketsCAD NewUI v4.0.

## How to Run

All PHP scripts are run from the command line:

```bash
/c/xampp/8.2.4/php/php.exe sql/script_name.php
```

All scripts require `config.php` for database credentials. Run from the `newui/` directory.

## Script Reference

### Schema Migration Runners (run\_\*.php)

These scripts create tables, add columns, and seed default data. All are idempotent.

| Script | Purpose | Idempotent | Notes |
|--------|---------|:----------:|-------|
| `run_00_rbac.php` | RBAC tables (roles, permissions, role\_permissions, user\_roles) + 6 default roles, 60+ permissions | Yes | Assigns Super Admin to user #1 |
| `run_member_columns.php` | Add named columns to legacy member table (field1 -> first\_name, etc.) | Yes | Guards each ALTER with column check |
| `run_02_organizations.php` | Organizations + comm identifiers tables, seeds default org | Yes | Runs organizations.sql + comm\_identifiers.sql |
| `run_org_scope.php` | Add org\_id to member\_types, member\_status, teams, certifications | Yes | Multi-org support |
| `run_login_security.php` | login\_attempts + active\_sessions tables, lockout settings | Yes | Brute-force prevention |
| `run_01_tfa.php` | Two-factor auth tables (user\_tfa, tfa\_remember\_tokens) + settings | Yes | Reads tfa.sql |
| `run_equipment.php` | Equipment tracking tables + default types | Yes | Reads equipment.sql |
| `run_equipment_personal.php` | Add personal/volunteer equipment columns to newui\_equipment | Yes | Requires run\_equipment.php first |
| `run_vehicles.php` | Vehicle management tables + default types | Yes | Reads vehicles.sql |
| `run_scheduling.php` | Shift and event scheduling tables | Yes | Reads scheduling.sql |
| `run_service_events.php` | Service health monitoring tables | Yes | Reads service\_events.sql |
| `run_captions.php` | i18n captions table + default English strings | Yes | Internationalization support |
| `run_03_location_providers.php` | Location tracking tables + 7 default providers | Yes | Reads location\_providers.sql |
| `run_geofences.php` | Geofencing tables (geofences, geofence\_events) | Yes | Links to map\_markups |
| `run_major_incidents.php` | Major incident linking + command structure tables | Yes | Reads major\_incidents.sql |
| `run_map_markups.php` | Map markup tables + default categories | Yes | Drawing/annotation system |
| `run_webhooks.php` | Webhook management + delivery tracking tables | Yes | Outbound event notifications |
| `run_can_login.php` | Add can\_login column to user table | Yes | CAD user account toggle |

### Data Seeding

| Script | Purpose | Idempotent | Notes |
|--------|---------|:----------:|-------|
| `seed_demo_data.php` | Seed sample members, vehicles, equipment, training, certifications | Yes | Checks for existing data before insert |
| `seed_scheduling_data.php` | Seed shift assignments, event participants, team members | Partially | Run after seed\_demo\_data.php and run\_scheduling.php |
| `cleanup_reseed.php` | Delete vehicles, equipment, training, certs for re-seeding | **No** | DESTRUCTIVE -- deletes data |

### Schema Check / Diagnostic Scripts

These are read-only scripts for inspecting database state. All safe to run anytime.

| Script | Purpose | Idempotent | Notes |
|--------|---------|:----------:|-------|
| `check_tables.php` | Show member data + search for constituent table | Yes | Read-only diagnostic |
| `check_constituent.php` | Locate constituent table across databases | Yes | Read-only diagnostic |
| `check_constituent2.php` | Search newui tables for constituent/contact matches | Yes | Read-only diagnostic |
| `check_constituent3.php` | Inspect constituents + contacts table schemas | Yes | Read-only diagnostic |
| `check_log_table.php` | Inspect legacy log + newui\_audit\_log tables | Yes | Read-only diagnostic |
| `check_member_cols.php` | Dump all member table columns + sample row | Yes | Read-only diagnostic |
| `check_member_aliases.php` | Verify key member columns + member\_status data | Yes | Read-only diagnostic |
| `check_member_view.php` | Detect legacy vs NewUI columns, create member\_view | Yes | Creates view if legacy |
| `check_personnel_tables.php` | Inspect certs, types, status, teams, member tables | Yes | Read-only diagnostic |
| `check_phase7_prereqs.php` | Verify Phase 7 prerequisite tables exist | Yes | Read-only diagnostic |
| `check_service_state.php` | Show service health monitoring data | Yes | Read-only diagnostic |
| `check_teams_cols.php` | Dump teams table schema + sample row | Yes | Read-only diagnostic |

### Migration Scripts

| Script | Purpose | Idempotent | Notes |
|--------|---------|:----------:|-------|
| `member_aliases.php` | Add virtual alias columns to legacy member table | Yes | Maps field1->last\_name, field2->first\_name, etc. |
| `migrate_member_orgs.php` | Link all members to organization #1 | Yes | Uses INSERT IGNORE |
| `setup_audit_log.php` | Create newui\_audit\_log + write test entry | Yes | Adds one test entry per run |

### Raw SQL Files

These `.sql` files are executed by their corresponding `run_*.php` scripts. Do not run directly.

| File | Used By |
|------|---------|
| `alter_match_pattern.sql` | Manual ALTER for match pattern |
| `alter_org_scope.sql` | `run_org_scope.php` |
| `audit_log.sql` | `setup_audit_log.php` |
| `captions.sql` | `run_captions.php` |
| `comm_identifiers.sql` | `run_02_organizations.php` |
| `constituents.sql` | Direct execution or import |
| `dashboard_tables.sql` | Initial setup |
| `equipment.sql` | `run_equipment.php` |
| `equipment_personal.sql` | `run_equipment_personal.php` |
| `facility_beds.sql` | Direct execution |
| `fcc_licenses.sql` | FCC lookup table |
| `geofences.sql` | `run_geofences.php` |
| `location_providers.sql` | `run_03_location_providers.php` |
| `login_security.sql` | `run_login_security.php` |
| `major_incidents.sql` | `run_major_incidents.php` |
| `member_callsigns.sql` | Callsign management |
| `membership.sql` | Membership schema |
| `organizations.sql` | `run_02_organizations.php` |
| `rbac.sql` | `run_00_rbac.php` |
| `scheduling.sql` | `run_scheduling.php` |
| `seed_demo_data.sql` | Legacy SQL seed (use .php version instead) |
| `service_events.sql` | `run_service_events.php` |
| `sessions.sql` | Session management |
| `sop_wiki.sql` | SOP/Wiki tables |
| `teams_nims.sql` | NIMS resource typing |
| `tfa.sql` | `run_01_tfa.php` |
| `training_nims.sql` | Training/NIMS tables |
| `upgrade_from_tickets.sql` | Legacy upgrade path |
| `vehicles.sql` | `run_vehicles.php` |
| `webhooks.sql` | `run_webhooks.php` |
| `zello_tables.sql` | Zello integration |
| `zipcodes.sql` | Zip code lookup |

## Recommended Run Order (Fresh Install)

1. `run_00_rbac.php` -- roles and permissions
2. `run_member_columns.php` -- named member columns
3. `run_02_organizations.php` -- orgs + comm identifiers
4. `run_org_scope.php` -- org scoping on existing tables
5. `run_login_security.php` -- login tracking
6. `run_01_tfa.php` -- two-factor auth
7. `run_equipment.php` then `run_equipment_personal.php`
8. `run_vehicles.php`
9. `run_scheduling.php`
10. `run_service_events.php`
11. `run_captions.php`
12. `run_03_location_providers.php`
13. `run_geofences.php`
14. `run_major_incidents.php`
15. `run_map_markups.php`
16. `run_webhooks.php`
17. `run_can_login.php`
18. `seed_demo_data.php` -- sample data
19. `seed_scheduling_data.php` -- sample schedules
