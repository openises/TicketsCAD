<?php
/**
 * NewUI v4.0 — Reusable Import/Export Library
 *
 * Provides CSV parsing, column mapping, validation, preview, and batch operations.
 * Used by api/import-export.php for both member and constituent tables (and any future table).
 *
 * USAGE:
 *   require_once 'inc/import-export.php';
 *   $config = get_table_config('member');
 *   $parsed = parse_csv_upload('file_field', $config);
 *   $preview = preview_import($parsed, $config);
 *   $result  = execute_import($parsed, $config, $column_map);
 */

/**
 * Return the import/export configuration for a supported table.
 *
 * Each config defines:
 *   table        — actual DB table name
 *   label        — human-readable label
 *   columns      — array of column definitions:
 *                   [db_col => [label, type, required, importable, exportable]]
 *   id_column    — primary key column
 *   match_column — column used for duplicate detection on import (e.g. callsign, contact name)
 *   audit_cols   — columns to auto-fill on insert (e.g. _by, _on, _from)
 */
function get_table_config(string $target): array
{
    $configs = [

        'member' => [
            'table'        => 'member',
            'label'        => 'Personnel (Members)',
            'id_column'    => 'id',
            'match_columns' => ['callsign', 'last_name+first_name'],
            // Phase 99q (Billy beta 2026-06-29) — removed the `field7 => 0`
            // audit_col. field7 is varchar(20) and is the underlying
            // storage for phone (phone_cell / phone are VIRTUAL
            // columns derived from field7). Forcing it to `0` via
            // array_merge() AFTER the row data was assembled meant
            // every imported phone got overwritten with the literal
            // string "0". field7 is nullable with default NULL, so
            // there's no need to force a value.
            'audit_cols'   => [
                '_by'    => '__USER_ID__',
                '_on'    => '__NOW__',
                '_from'  => '__IP__',
            ],
            'columns' => [
                'id'         => ['label' => 'ID',          'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
            // GH#103 (rjonesbsink/cbyrdmo, 2026-08-22) — these four columns
            // carry 'legacy_remap' => true, which the reader/writer below
            // treat very differently from every OTHER 'legacy' alias in
            // this file (facility.phone/contact, in_types.severity,
            // team.name/description/team_type_id, and this same target's
            // own 'phone' a few lines down): on those, `legacy` names the
            // ONE real, always-existing column the rest of the app
            // actually reads and writes — the named key is purely a
            // friendlier export label, never independently writable
            // (confirmed by audit: none of those named columns exist as
            // real columns on any install checked, or — for team.name/
            // description — exist but are either a GENERATED mirror of
            // the legacy column or a plain column nothing internal ever
            // reads, since api/teams.php always reads `mission AS
            // description`). For last_name/first_name/callsign/email,
            // GH#95's own audit established the opposite: on ANY given
            // install, exactly one of the named/legacy pair is the live
            // column real writes land in — these four are EITHER a
            // GENERATED VIRTUAL mirror of field1/field2/field4/field6
            // (older/legacy-shaped installs), OR independently plain
            // columns the roster UI writes directly while the field*
            // columns sit untouched (confirmed live on both GH#95
            // reporters' installs, and directly read by api/members.php's
            // roster queries with no fallback of their own). Exporting/
            // importing via the bare 'legacy' column only, as every other
            // target correctly does, silently exports/imports NULL names
            // on the second shape — that was this bug. 'legacy_remap' =>
            // true tells execute_import()/export_csv() to resolve, per
            // install, via information_schema
            // (db_generated_column_map() in inc/functions.php) rather
            // than assume 'legacy' is always the real column.
                'last_name'  => ['label' => 'Last Name',   'type' => 'string', 'required' => true,  'import' => true,  'export' => true,  'legacy' => 'field1', 'legacy_remap' => true],
                'first_name' => ['label' => 'First Name',  'type' => 'string', 'required' => true,  'import' => true,  'export' => true,  'legacy' => 'field2', 'legacy_remap' => true],
                'callsign'   => ['label' => 'Callsign',    'type' => 'string', 'required' => false, 'import' => true,  'export' => true,  'legacy' => 'field4', 'legacy_remap' => true],
                'email'      => ['label' => 'Email',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true,  'legacy' => 'field6', 'legacy_remap' => true],
                // 'phone' deliberately does NOT get 'legacy_remap' (checked,
                // not assumed): the roster (api/members.php, three SELECTs)
                // and the external API read `phone_cell`, never bare
                // `phone` — a THIRD column this pair doesn't reach either
                // way. Nothing internal reads or writes `member`.`phone`
                // at all (confirmed by grep); it exists only as a
                // GENERATED VIRTUAL mirror of field7 on installs where
                // tools/install_fresh.php's alias step created it, or not
                // at all on some upgraded installs. Adding legacy_remap
                // here would resolve cleanly between `phone`/`field7` but
                // would NOT make an imported phone number visible in the
                // roster either way, since that reads `phone_cell` — a
                // column this config has no key for. That is a real,
                // separate, pre-existing gap (the CSV's "Phone" column
                // was never wired to the field the roster displays, in
                // EITHER schema shape), left open rather than silently
                // "fixed" without actually closing it — see GH#103.
                'phone'      => ['label' => 'Phone',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true,  'legacy' => 'field7'],
                'field3'     => ['label' => 'Member Type',  'type' => 'int',   'required' => false, 'import' => true,  'export' => true],
                'field8'     => ['label' => 'Available',    'type' => 'enum',  'required' => false, 'import' => true,  'export' => true,  'values' => ['Yes', 'No']],
                'field9'     => ['label' => 'Address',      'type' => 'string','required' => false, 'import' => true,  'export' => true],
                'field10'    => ['label' => 'City',         'type' => 'string','required' => false, 'import' => true,  'export' => true],
                'field11'    => ['label' => 'State',        'type' => 'string','required' => false, 'import' => true,  'export' => true],
                'field12'    => ['label' => 'Latitude',     'type' => 'float', 'required' => false, 'import' => true,  'export' => true],
                'field13'    => ['label' => 'Longitude',    'type' => 'float', 'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        'constituent' => [
            'table'        => 'constituents',
            'label'        => 'Constituents (Community Contacts)',
            'id_column'    => 'id',
            'match_columns' => ['contact', 'phone'],
            'audit_cols'   => [
                '_by'     => '__USER_ID__',
                'updated' => '__NOW__',
            ],
            'columns' => [
                'id'           => ['label' => 'ID',            'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'contact'      => ['label' => 'Name',          'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'street'       => ['label' => 'Street',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'apartment'    => ['label' => 'Apartment',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'community'    => ['label' => 'Community',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'city'         => ['label' => 'City',          'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'post_code'    => ['label' => 'Zip/Post Code', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'state'        => ['label' => 'State',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'miscellaneous'=> ['label' => 'Notes',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone'        => ['label' => 'Phone',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_type'   => ['label' => 'Phone Type',    'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_2'      => ['label' => 'Phone 2',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_2_type' => ['label' => 'Phone 2 Type',  'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_3'      => ['label' => 'Phone 3',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_3_type' => ['label' => 'Phone 3 Type',  'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_4'      => ['label' => 'Phone 4',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'phone_4_type' => ['label' => 'Phone 4 Type',  'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'email'        => ['label' => 'Email',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'lat'          => ['label' => 'Latitude',      'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'lng'          => ['label' => 'Longitude',     'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'reference'    => ['label' => 'Reference',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        // GH #36 follow-up (a beta tester + Eric 2026-07-08) — Places moved here
        // from the Settings → Places panel header so ALL bulk data entry
        // lives on the one import-export page.
        'place' => [
            'table'        => 'places',
            'label'        => 'Places (Known Locations)',
            'id_column'    => 'id',
            'match_columns' => ['name'],
            'audit_cols'   => [],
            'columns' => [
                'id'          => ['label' => 'ID',          'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'name'        => ['label' => 'Name',        'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'apply_to'    => ['label' => 'Apply To',    'type' => 'enum',   'required' => false, 'import' => true,  'export' => true, 'values' => ['city', 'bldg']],
                'street'      => ['label' => 'Street',      'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'city'        => ['label' => 'City',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'state'       => ['label' => 'State',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'information' => ['label' => 'Information', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'lat'         => ['label' => 'Latitude',    'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'lon'         => ['label' => 'Longitude',   'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'zoom'        => ['label' => 'Zoom',        'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        'vehicle' => [
            'table'        => 'newui_vehicles',
            'label'        => 'Vehicles',
            'id_column'    => 'id',
            'match_columns' => ['plate_number', 'vin'],
            'audit_cols'   => [],
            'columns' => [
                'id'               => ['label' => 'ID',              'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'member_id'        => ['label' => 'Member ID',       'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'owner_org_id'     => ['label' => 'Owner Agency ID', 'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'vehicle_type_id'  => ['label' => 'Vehicle Type ID', 'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'year'             => ['label' => 'Year',            'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'make'             => ['label' => 'Make',            'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'model'            => ['label' => 'Model',           'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'color'            => ['label' => 'Color',           'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'plate_number'     => ['label' => 'Plate Number',    'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'plate_state'      => ['label' => 'Plate State',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'callsign'         => ['label' => 'Unit Number',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'is_agency_vehicle'=> ['label' => 'Agency Vehicle',  'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'is_private'       => ['label' => 'Private',         'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        'equipment' => [
            'table'        => 'newui_equipment',
            'label'        => 'Equipment',
            'id_column'    => 'id',
            'match_columns' => ['asset_tag', 'serial_number'],
            'audit_cols'   => [],
            'columns' => [
                'id'               => ['label' => 'ID',            'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'name'             => ['label' => 'Name',          'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'equipment_type_id'=> ['label' => 'Type ID',       'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'ownership'        => ['label' => 'Ownership',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'serial_number'    => ['label' => 'Serial Number', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'asset_tag'        => ['label' => 'Asset Tag',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'make'             => ['label' => 'Make',          'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'model'            => ['label' => 'Model',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'condition'        => ['label' => 'Condition',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'location'         => ['label' => 'Location',      'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'status'           => ['label' => 'Status',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'notes'            => ['label' => 'Notes',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'purchase_date'    => ['label' => 'Purchase Date', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'purchase_cost'    => ['label' => 'Purchase Cost', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        'responder' => [
            'table'        => 'responder',
            'label'        => 'Units / Responders',
            'id_column'    => 'id',
            'match_columns' => ['name', 'handle'],
            'audit_cols'   => [],
            'columns' => [
                'id'          => ['label' => 'ID',           'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'name'        => ['label' => 'Unit Name',    'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'handle'      => ['label' => 'Handle/ID',    'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'callsign'    => ['label' => 'Callsign',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'description' => ['label' => 'Description',  'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'type'        => ['label' => 'Type ID',      'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'un_status_id'=> ['label' => 'Status ID',    'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'street'      => ['label' => 'Street',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'city'        => ['label' => 'City',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'state'       => ['label' => 'State',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'lat'         => ['label' => 'Latitude',     'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'lng'         => ['label' => 'Longitude',    'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'phone'       => ['label' => 'Phone',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'cellphone'   => ['label' => 'Cell Phone',   'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'contact_name'=> ['label' => 'Contact Name', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'capab'       => ['label' => 'Capabilities', 'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'mobile'      => ['label' => 'Is Mobile',    'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        'facility' => [
            'table'        => 'facilities',
            'label'        => 'Facilities',
            'id_column'    => 'id',
            'match_columns' => ['name'],
            'audit_cols'   => [],
            'columns' => [
                'id'          => ['label' => 'ID',           'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'name'        => ['label' => 'Name',         'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'type'        => ['label' => 'Type',         'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'description' => ['label' => 'Description',  'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                // History:
                //   2026-07-31 (Ron Jones, GH TicketsCAD#14): four of these
                //   named columns `facilities` has never had, so exporting
                //   Facilities failed outright. `zip` and `capacity` were
                //   dropped — no facility screen offers a postcode, and
                //   capacity is tracked as beds_a/beds_o, so mapping it to
                //   either one would export a misleading single figure.
                //   `phone` and `contact` DO have exact equivalents and are
                //   aliased rather than dropped, so no data leaves the export.
                'street'      => ['label' => 'Street',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'city'        => ['label' => 'City',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'state'       => ['label' => 'State',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'lat'         => ['label' => 'Latitude',     'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'lng'         => ['label' => 'Longitude',    'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'phone'       => ['label' => 'Phone',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true, 'legacy' => 'contact_phone'],
                'contact'     => ['label' => 'Contact',      'type' => 'string', 'required' => false, 'import' => true,  'export' => true, 'legacy' => 'contact_name'],
            ],
        ],

        'in_types' => [
            'table'        => 'in_types',
            'label'        => 'Incident Types',
            'id_column'    => 'id',
            'match_columns' => ['type'],
            'audit_cols'   => [],
            'columns' => [
                // History:
                //   2026-06-26 (a beta tester): export was failing with "no data
                //   or table error" because the export SELECT referenced
                //   `severity` but the legacy column is `set_severity`.
                //   Added the `legacy` alias.
                //   2026-06-26 (a beta tester, second pass): export now works
                //   but import-of-the-exported-CSV fails with "Field
                //   'description' doesn't have a default value" — the
                //   `description` column on in_types is NOT NULL with no
                //   default, and the import builder didn't include it.
                //   Added description as importable+exportable + supplied
                //   the new `default` key so execute_import can auto-fill
                //   it when missing from the CSV (e.g. CSVs exported
                //   before this fix landed).
                'id'          => ['label' => 'ID',           'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'type'        => ['label' => 'Type Name',    'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'description' => ['label' => 'Description',  'type' => 'string', 'required' => false, 'import' => true,  'export' => true, 'default' => ''],
                'severity'    => ['label' => 'Severity',     'type' => 'int',    'required' => false, 'import' => true,  'export' => true, 'legacy' => 'set_severity'],
                'group'       => ['label' => 'Group',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'color'       => ['label' => 'Color',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'protocol'    => ['label' => 'Protocol',     'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
            ],
        ],

        'team' => [
            'table'        => 'teams',
            'label'        => 'Teams',
            'id_column'    => 'id',
            'match_columns' => ['name'],
            // GH#103 follow-up (found by this fix's own new test against
            // CI's genuinely fresh install, 2026-08-22): sql/base_schema.sql's
            // `teams` table carries three MORE legacy NOT NULL-no-default
            // columns beyond `team`/`mission`/`ttypes_id` — `by`, `from`,
            // `on` — that inc/team-write.php's real team_upsert_internal()
            // already supplies on every INSERT ("`sub-group`, `by`, `from`,
            // `on` columns are legacy NOT NULL with no default; supply
            // empty/zero placeholders" — see that file). execute_import()
            // never supplied them at all, so importing a team via CSV threw
            // MySQL 1364 on any install that never got an ad-hoc `ALTER ...
            // SET DEFAULT` — every genuinely fresh install, including CI's
            // — for every row, regardless of which columns the CSV
            // included. This dev database's teams.by/from/on all show a
            // real DEFAULT because SOME earlier untracked fix set one
            // directly on this long-lived DB; base_schema.sql itself never
            // gained one. Matching the real writer's own values exactly
            // ($userId for `by`, '' for `from`, NOW() for `on`) so an
            // imported team is indistinguishable from a UI-created one.
            'audit_cols'   => [
                'by'   => '__USER_ID__',
                'from' => '',
                'on'   => '__NOW__',
            ],
            'columns' => [
                // History:
                //   2026-07-31 (Ron Jones, GH TicketsCAD#14): `description` and
                //   `team_type_id` are not columns of `teams`; the real ones
                //   are `mission` and `ttypes_id`. `name` is worse than it
                //   looks — it exists ONLY where sql/seed_scheduling_data.php
                //   has run, and there only as a GENERATED VIRTUAL alias of
                //   `team`, which cannot be INSERTed into. So export failed on
                //   installs without it and import failed on installs with it.
                //   Aliasing to the base column works on both.
                'id'          => ['label' => 'ID',            'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'name'        => ['label' => 'Team Name',     'type' => 'string', 'required' => true,  'import' => true,  'export' => true, 'legacy' => 'team'],
                'description' => ['label' => 'Description',   'type' => 'string', 'required' => false, 'import' => true,  'export' => true, 'legacy' => 'mission'],
                'team_type_id'=> ['label' => 'Team Type ID',  'type' => 'int',    'required' => false, 'import' => true,  'export' => true, 'legacy' => 'ttypes_id', 'default' => 0],
                'active'      => ['label' => 'Active',        'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                // GH#103 follow-up — not user-facing (import/export both
                // false, so never appears in the CSV column-mapping UI):
                // `sub-group`/`leader`/`leader_dpty` are legacy NOT NULL
                // columns with no DB default and no named/UI counterpart
                // this config otherwise exposes. Values match
                // team_upsert_internal()'s own placeholders exactly (empty
                // string / no leader chosen) so a CSV-imported team is
                // indistinguishable from one created via the Teams UI with
                // no leader/deputy set.
                'sub-group'   => ['label' => 'Sub-Group (legacy)',  'type' => 'string', 'required' => false, 'import' => false, 'export' => false, 'default' => ''],
                'leader'      => ['label' => 'Leader (legacy)',     'type' => 'int',    'required' => false, 'import' => false, 'export' => false, 'default' => 0],
                'leader_dpty' => ['label' => 'Deputy (legacy)',     'type' => 'int',    'required' => false, 'import' => false, 'export' => false, 'default' => 0],
            ],
        ],

        'user' => [
            'table'        => 'user',
            'label'        => 'User Accounts (export only)',
            'id_column'    => 'id',
            'match_columns' => ['user'],
            'audit_cols'   => [],
            'columns' => [
                'id'        => ['label' => 'ID',        'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'user'      => ['label' => 'Username',  'type' => 'string', 'required' => true,  'import' => false, 'export' => true],
                'level'     => ['label' => 'Level',     'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                // `user`.`name` does not exist — names are stored split as
                // name_l / name_f. A synthesised full name is not expressible
                // through the one-to-one `legacy` alias, and the split fields
                // round-trip better anyway (GH TicketsCAD#14).
                'name_l'    => ['label' => 'Last Name',  'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
                'name_f'    => ['label' => 'First Name', 'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
                'email'     => ['label' => 'Email',     'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
                'can_login' => ['label' => 'Can Login',  'type' => 'int',   'required' => false, 'import' => false, 'export' => true],
            ],
        ],

        'incident' => [
            'table'        => 'ticket',
            'label'        => 'Incidents (export only)',
            'id_column'    => 'id',
            'match_columns' => [],
            'audit_cols'   => [],
            // Phase 132 Step 5 (GH #16) — disposition_code below needs a
            // value from a JOINed table (`ticket_disposition`.`code`), not
            // just another column of `ticket`, so the existing `legacy`
            // alias mechanism (same-table rename only) can't express it.
            // `joins` is a structured list export_csv() turns into real
            // LEFT JOIN clauses at query-build time — see that function
            // for how `table`/`alias`/`on_local`/`on_foreign` are used.
            // Kept as plain data (no db_table() calls here) so this
            // function stays a pure config builder, matching every other
            // target above.
            'joins' => [
                ['table' => 'ticket_disposition', 'alias' => 'td',
                 'on_local' => 'disposition_id', 'on_foreign' => 'id'],
            ],
            'columns' => [
                'id'           => ['label' => 'ID',            'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'scope'        => ['label' => 'Scope/Summary', 'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'in_types_id'  => ['label' => 'Type ID',       'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'status'       => ['label' => 'Status',        'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'severity'     => ['label' => 'Severity',      'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                // History:
                //   2026-07-31 (Ron Jones, GH TicketsCAD#14): six of these named
                //   columns `ticket` does not have. Each alias below is
                //   grounded in how the app itself uses the column, not
                //   inferred from the name:
                //     address       -> street       (api/incidents.php treats
                //                                    street as the address)
                //     caller_name   -> contact      \ new-incident.php's caller
                //     caller_phone  -> phone        / inputs post to these
                //     call_received -> date         (api/feed.php aliases
                //                                    `date` AS `opened`)
                //     closed        -> problemend   (api/incidents.php:159,
                //                                    api/reports.php:391)
                //   `dispatched` is dropped: dispatch time is a property of an
                //   assignment (`assigns`.`dispatched`), not of the incident,
                //   so there is nothing on `ticket` to map it to. `problemstart`
                //   does exist and was simply missing.
                'address'      => ['label' => 'Address',       'type' => 'string',  'required' => false, 'import' => false, 'export' => true, 'legacy' => 'street'],
                'city'         => ['label' => 'City',          'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'state'        => ['label' => 'State',         'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'lat'          => ['label' => 'Latitude',      'type' => 'float',   'required' => false, 'import' => false, 'export' => true],
                'lng'          => ['label' => 'Longitude',     'type' => 'float',   'required' => false, 'import' => false, 'export' => true],
                'caller_name'  => ['label' => 'Caller Name',   'type' => 'string',  'required' => false, 'import' => false, 'export' => true, 'legacy' => 'contact'],
                'caller_phone' => ['label' => 'Caller Phone',  'type' => 'string',  'required' => false, 'import' => false, 'export' => true, 'legacy' => 'phone'],
                'call_received'=> ['label' => 'Call Received',  'type' => 'string', 'required' => false, 'import' => false, 'export' => true, 'legacy' => 'date'],
                'problemstart' => ['label' => 'Problem Start',  'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
                'closed'       => ['label' => 'Closed',         'type' => 'string', 'required' => false, 'import' => false, 'export' => true, 'legacy' => 'problemend'],
                // Phase 132 Step 5 (GH #16) — the disposition's stable
                // `code`, never the renameable `status_val` label (plan.md
                // §6: "export `code`, not the label" — the whole reason
                // `code` exists is to survive local relabeling). `sql` is a
                // raw, already-qualified SELECT expression consumed by
                // export_csv() instead of the plain-column/`legacy` paths;
                // `joined_table`/`joined_column` let
                // tests/test_import_export_schema.php verify it against the
                // JOINed table's real schema rather than `ticket`'s (which
                // has no such column). Export-only, like every other column
                // in this target: a NULL disposition_id (the normal state
                // for most incidents) exports as an empty string, not an
                // error — see export_csv()'s CSV-row builder.
                'disposition_code' => ['label' => 'Disposition Code', 'type' => 'string', 'required' => false,
                    'import' => false, 'export' => true, 'sql' => '`td`.`code`',
                    'joined_table' => 'ticket_disposition', 'joined_column' => 'code'],
            ],
        ],
    ];

    if (!isset($configs[$target])) {
        return [];
    }
    return $configs[$target];
}

/**
 * GH#103 — resolve which physical column a config column definition
 * should be READ FROM / WRITTEN TO for a given table.
 *
 * A column with no 'legacy' key always resolves to itself, unchanged.
 * A column with 'legacy' but no 'legacy_remap' (every legacy alias in
 * this file except member's five) always resolves to the legacy column
 * — exactly the pre-GH#103 behavior, since for those targets `legacy`
 * IS the one real, always-existing column (see the long comment on
 * member's column definitions above for why that distinction matters
 * and can't be made generic from schema alone).
 *
 * A column with 'legacy_remap' => true resolves via
 * db_generated_column_map(): if the NAMED column is a GENERATED mirror
 * of the legacy one on this install, the legacy column is the real,
 * writable one; otherwise the named column itself is (either because
 * it's a plain, independently-writable column with no generated
 * relationship to 'legacy' at all, or — belt and suspenders — because
 * it doesn't exist on this install and db_generated_column_map()
 * quietly returned no entry for it, in which case writing the named
 * column would fail loudly rather than silently landing on the wrong
 * one; this shape does not currently occur for any legacy_remap column,
 * since member's five named columns are added unconditionally by both
 * tools/install_fresh.php's virtual-alias step and
 * sql/run_member_columns.php's addCol(), so they always exist as either
 * generated or plain).
 */
function _ie_resolve_write_column(string $dbCol, array $def, string $table): string
{
    if (!isset($def['legacy'])) {
        return $dbCol;
    }
    if (empty($def['legacy_remap'])) {
        return $def['legacy'];
    }
    $genMap = db_generated_column_map($table);
    return isset($genMap[$dbCol]) ? $def['legacy'] : $dbCol;
}

/**
 * Get list of supported table targets for import/export.
 */
function get_supported_targets(): array
{
    return [
        'member'      => 'Personnel (Members)',
        'responder'   => 'Units / Responders',
        'facility'    => 'Facilities',
        'in_types'    => 'Incident Types',
        'team'        => 'Teams',
        'constituent' => 'Constituents (Community Contacts)',
        'vehicle'     => 'Vehicles',
        'equipment'   => 'Equipment',
        'user'        => 'User Accounts (export only)',
        'incident'    => 'Incidents (export only)',
        // GH #36 follow-up — Places joined the unified page 2026-07-08.
        'place'       => 'Places (Known Locations)',
    ];
}

/**
 * Parse a CSV string into an array of associative rows.
 * Returns: [headers => [...], rows => [[...], ...], row_count => N]
 */
function parse_csv_string(string $csvData): array
{
    $lines = str_getcsv_lines($csvData);
    if (empty($lines)) {
        return ['headers' => [], 'rows' => [], 'row_count' => 0];
    }

    $headers = array_shift($lines);
    // Trim BOM and whitespace from headers
    $headers = array_map(function ($h) {
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', $h));
    }, $headers);

    $rows = [];
    foreach ($lines as $line) {
        if (empty(array_filter($line, function ($v) { return trim($v) !== ''; }))) continue;
        $row = [];
        for ($i = 0; $i < count($headers); $i++) {
            $row[$headers[$i]] = isset($line[$i]) ? trim($line[$i]) : '';
        }
        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows, 'row_count' => count($rows)];
}

/**
 * Parse CSV handling multi-line fields properly.
 */
function str_getcsv_lines(string $data): array
{
    $lines = [];
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $data);
    rewind($stream);
    // Explicit $escape for PHP 8.4+ (deprecation 2026-06)
    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        $lines[] = $row;
    }
    fclose($stream);
    return $lines;
}

/**
 * Auto-map CSV headers to table columns by matching labels or column names.
 * Returns: [csv_header => db_column, ...]
 */
function auto_map_columns(array $csvHeaders, array $config): array
{
    $map = [];
    $columns = $config['columns'];

    foreach ($csvHeaders as $csvHeader) {
        $normalized = strtolower(trim($csvHeader));
        $bestMatch = null;

        foreach ($columns as $dbCol => $def) {
            if (!$def['import']) continue;

            // Exact match on db column name
            if ($normalized === strtolower($dbCol)) {
                $bestMatch = $dbCol;
                break;
            }
            // Exact match on label
            if ($normalized === strtolower($def['label'])) {
                $bestMatch = $dbCol;
                break;
            }
            // Match legacy column name
            if (isset($def['legacy']) && $normalized === strtolower($def['legacy'])) {
                $bestMatch = $dbCol;
                break;
            }
            // Fuzzy: check if header contains the label or vice versa
            if (strpos($normalized, strtolower($def['label'])) !== false ||
                strpos(strtolower($def['label']), $normalized) !== false) {
                $bestMatch = $dbCol;
            }
        }

        if ($bestMatch) {
            $map[$csvHeader] = $bestMatch;
        }
    }

    return $map;
}

/**
 * Validate a set of rows against a config.
 * Returns: [valid => [...], errors => [...], warnings => [...]]
 */
function validate_import(array $rows, array $columnMap, array $config): array
{
    $valid = [];
    $errors = [];
    $warnings = [];
    $errorRows = [];   // Full row data for failed rows (enables inline editing)
    $columns = $config['columns'];

    foreach ($rows as $idx => $row) {
        $rowNum = $idx + 2; // +2 for 1-indexed + header row
        $rowErrors = [];
        $mapped = [];

        // Map CSV columns to DB columns
        foreach ($columnMap as $csvCol => $dbCol) {
            if (!isset($columns[$dbCol])) continue;
            $val = isset($row[$csvCol]) ? trim($row[$csvCol]) : '';
            $def = $columns[$dbCol];

            // Required check
            if ($def['required'] && $val === '') {
                $rowErrors[] = "{$def['label']} is required";
                continue;
            }

            // Type validation
            if ($val !== '') {
                switch ($def['type']) {
                    case 'int':
                        if (!is_numeric($val)) {
                            $rowErrors[] = "{$def['label']} must be a number (got '{$val}')";
                        } else {
                            $val = (int) $val;
                        }
                        break;
                    case 'float':
                        if (!is_numeric($val)) {
                            $rowErrors[] = "{$def['label']} must be a number (got '{$val}')";
                        } else {
                            $val = (float) $val;
                        }
                        break;
                    case 'enum':
                        if (isset($def['values']) && !in_array($val, $def['values'])) {
                            $warnings[] = "Row {$rowNum}: {$def['label']} value '{$val}' not in allowed values";
                        }
                        break;
                }
            }

            $mapped[$dbCol] = $val === '' ? null : $val;
        }

        if (!empty($rowErrors)) {
            // Build "Row N: error" strings for backward compat
            foreach ($rowErrors as $re) {
                $errors[] = "Row {$rowNum}: {$re}";
            }
            // Also store the full row data + mapped data so the UI can offer inline editing
            $errorRows[] = [
                'row_num'     => $rowNum,
                'csv_index'   => $idx,
                'errors'      => $rowErrors,
                'original'    => $row,     // raw CSV row (keyed by CSV header)
                'mapped'      => $mapped,  // partially mapped DB columns
            ];
        } else {
            $valid[] = $mapped;
        }
    }

    return ['valid' => $valid, 'errors' => $errors, 'warnings' => $warnings, 'error_rows' => $errorRows];
}

/**
 * Execute an import: insert or update rows into the target table.
 * Returns: [inserted => N, updated => N, skipped => N, errors => [...]]
 */
function execute_import(array $validRows, array $config, int $userId, string $mode = 'insert'): array
{
    $pdo = db();
    $table = db_table($config['table']);
    $idCol = $config['id_column'];
    $auditCols = $config['audit_cols'] ?? [];

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($validRows as $idx => $row) {
        // Resolve audit columns. Phase 99k fix (Billy beta 2026-06-29):
        // __IP__ now uses the proxy-aware client_ip() helper so
        // X-Forwarded-For-fronted deploys see real client IPs rather
        // than the reverse-proxy's loopback. Also defensively truncated
        // to 45 chars (IPv6 max) so legacy _from columns that haven't
        // yet been widened by run_99k_widen_from_cols.php don't trip
        // SQLSTATE[22001] "Data too long" on the insert.
        if (!function_exists('client_ip')) {
            $clientIpFile = __DIR__ . '/client-ip.php';
            if (is_file($clientIpFile)) require_once $clientIpFile;
        }
        $resolvedIp = function_exists('client_ip')
            ? client_ip()
            : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $resolvedIp = substr((string) $resolvedIp, 0, 45);

        $audit = [];
        foreach ($auditCols as $col => $val) {
            if ($val === '__USER_ID__') $audit[$col] = $userId;
            elseif ($val === '__NOW__') $audit[$col] = date('Y-m-d H:i:s');
            elseif ($val === '__IP__') $audit[$col] = $resolvedIp;
            else $audit[$col] = $val;
        }

        // For legacy member table, use field names not aliases
        //
        // GH#103 fix: this used to unconditionally redirect every
        // 'legacy'-aliased column to its legacy name, which is correct
        // for facility/in_types/team (see the long comment on member's
        // column definitions in get_table_config()) but wrong for
        // member's five 'legacy_remap' columns on any install where the
        // named columns are plain and independently writable — the
        // import silently wrote data to field1/field2/field4/field6/
        // field7 while the roster (api/members.php) reads
        // first_name/last_name/callsign/email/phone_cell with no
        // fallback, so the imported member existed but showed up
        // completely nameless. _ie_resolve_write_column() now decides
        // per column, per install (via information_schema), which
        // physical column is actually writable.
        $insertRow = [];
        $columns = $config['columns'];
        foreach ($row as $dbCol => $val) {
            $actualCol = isset($columns[$dbCol])
                ? _ie_resolve_write_column($dbCol, $columns[$dbCol], $table)
                : $dbCol;
            $insertRow[$actualCol] = $val;
        }
        $insertRow = array_merge($insertRow, $audit);

        // Remove null values for optional columns
        $insertRow = array_filter($insertRow, function ($v) { return $v !== null; });

        // Fill in `default` values from config for any column missing
        // from the row. Lets table configs declare a safe default for
        // NOT NULL columns that the source CSV might not include (e.g.
        // in_types.description is NOT NULL with no DB default — old
        // CSVs exported before description was added to the export
        // config simply omit the column). Without this, the INSERT
        // errors with "Field 'X' doesn't have a default value".
        //
        // GH#103 follow-up: this used to additionally require
        // $def['import'] === true, which is right for a column a CSV
        // might legitimately omit (in_types.description) but wrong for
        // a column that ISN'T importable at all and therefore NEVER
        // appears in $row — team.sub-group/leader/leader_dpty are
        // exactly that: legacy NOT NULL columns with no UI/CSV
        // counterpart, so the 'import' === true gate made this fallback
        // permanently unreachable for them and every fresh-install team
        // import failed with the same MySQL 1364. A column opts into
        // this fallback simply by declaring 'default' at all, whether
        // or not it's importable — no existing importable-with-default
        // column (only in_types.description before this fix) changes
        // behavior, since it's already importable.
        foreach ($columns as $dbCol => $def) {
            if (!array_key_exists('default', $def)) continue;
            $actualCol = _ie_resolve_write_column($dbCol, $def, $table);
            if (!array_key_exists($actualCol, $insertRow)) {
                $insertRow[$actualCol] = $def['default'];
            }
        }

        if (empty($insertRow)) {
            $skipped++;
            continue;
        }

        try {
            // GH#54 (cbyrdmo, 2026-08-12): 'insert' mode's own UI label
            // reads "skip rows that match existing records", but this
            // block used to run the match lookup ONLY for 'upsert' — an
            // 'insert' row fell straight through to the unconditional
            // INSERT below regardless of whether a match existed. Every
            // facility already present on the target (e.g. re-importing
            // a CSV exported from a test copy into the live install)
            // silently duplicated. Both modes now share one match
            // lookup; they differ only in what happens on a match.
            $matchedId = null;
            foreach ($config['match_columns'] as $matchExpr) {
                $matchCols = explode('+', $matchExpr);
                $where = [];
                $params = [];
                $allPresent = true;
                foreach ($matchCols as $mc) {
                    // GH#103: matches _ie_resolve_write_column()'s decision
                    // above — a duplicate-detection lookup must check the
                    // SAME physical column the row was (or will be)
                    // written to, or re-importing a CSV into a
                    // plain-column install would never find its own
                    // previously-imported rows (comparing against an
                    // always-empty legacy column) and duplicate them
                    // every time, the GH#54 bug this match lookup exists
                    // to prevent, reappearing for a different reason.
                    $actualCol = isset($columns[$mc])
                        ? _ie_resolve_write_column($mc, $columns[$mc], $table)
                        : $mc;
                    if (!isset($row[$mc]) || $row[$mc] === null || $row[$mc] === '') {
                        $allPresent = false;
                        break;
                    }
                    $where[] = "`{$actualCol}` = ?";
                    $params[] = $row[$mc];
                }
                if (!$allPresent) continue;

                $existing = db_fetch_all(
                    "SELECT {$idCol} FROM {$table} WHERE " . implode(' AND ', $where) . " LIMIT 1",
                    $params
                );
                if (!empty($existing)) {
                    $matchedId = $existing[0][$idCol];
                    break;
                }
            }

            if ($matchedId !== null) {
                if ($mode === 'upsert') {
                    $setParts = [];
                    $updateParams = [];
                    foreach ($insertRow as $col => $val) {
                        $setParts[] = "`{$col}` = ?";
                        $updateParams[] = $val;
                    }
                    $updateParams[] = $matchedId;
                    db_query(
                        "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE `{$idCol}` = ?",
                        $updateParams
                    );
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            // Insert new record
            $cols = array_keys($insertRow);
            $placeholders = array_fill(0, count($cols), '?');
            db_query(
                "INSERT INTO {$table} (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                array_values($insertRow)
            );
            $inserted++;

        } catch (Exception $e) {
            $errors[] = "Row " . ($idx + 2) . ": " . $e->getMessage();
        }
    }

    return [
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ];
}

/**
 * Export table data as CSV string.
 */
function export_csv(array $config, array $filters = []): string
{
    $pdo = db();
    $table = db_table($config['table']);
    $columns = $config['columns'];
    $hasJoins = !empty($config['joins']);

    // Phase 132 Step 5 (GH #16) — turn the config's structured `joins`
    // list into real LEFT JOIN clauses. Kept out of get_table_config() so
    // that function stays a pure data builder; db_table() is only ever
    // called from executable code in this file, same as everywhere else
    // here.
    $joinSQL = '';
    foreach (($config['joins'] ?? []) as $j) {
        $joinTable = db_table($j['table']);
        $joinSQL .= " LEFT JOIN {$joinTable} `{$j['alias']}` ON {$table}.`{$j['on_local']}` = `{$j['alias']}`.`{$j['on_foreign']}`";
    }

    // Build SELECT with export columns
    $selectCols = [];
    $headerLabels = [];
    foreach ($columns as $dbCol => $def) {
        if (!$def['export']) continue;
        if (isset($def['sql'])) {
            // A value from a JOINed table — the `legacy` mechanism only
            // re-points to another column of the SAME table, so a raw,
            // already-qualified SQL expression is the only way to reach
            // one from here.
            $selectCols[] = "{$def['sql']} AS `{$dbCol}`";
        } elseif (isset($def['legacy']) && !empty($def['legacy_remap'])) {
            // GH#103 (rjonesbsink/cbyrdmo) — reading the bare legacy
            // column exported all-NULL names on any install where the
            // named column is the one actually written (member's plain-
            // column shape). Both columns are guaranteed to exist for
            // every 'legacy_remap' column (see the comment on member's
            // definitions in get_table_config()), so COALESCE is always
            // safe here — on a generated-column install the named column
            // already mirrors the legacy one, making this a no-op.
            $col = ($hasJoins ? "{$table}." : '');
            $selectCols[] = "COALESCE(NULLIF({$col}`{$dbCol}`, ''), {$col}`{$def['legacy']}`) AS `{$dbCol}`";
        } elseif (isset($def['legacy'])) {
            // A JOIN puts the joined table's columns in scope too, so an
            // unqualified `legacy` reference that used to be unambiguous
            // (e.g. `id`, which `ticket_disposition` also has) can stop
            // being one. Qualify with the base table whenever a join is
            // present; every other target has no joins, so this leaves
            // their SQL text unchanged.
            $selectCols[] = ($hasJoins ? "{$table}." : '') . "`{$def['legacy']}` AS `{$dbCol}`";
        } else {
            $selectCols[] = ($hasJoins ? "{$table}." : '') . "`{$dbCol}`";
        }
        $headerLabels[] = $def['label'];
    }

    $where = [];
    $params = [];
    if (!empty($filters['search'])) {
        $term = '%' . trim($filters['search']) . '%';
        // Search across all string columns
        $searchCols = [];
        foreach ($columns as $dbCol => $def) {
            if ($def['type'] === 'string' && $def['export']) {
                if (isset($def['sql'])) {
                    $searchCols[] = "{$def['sql']} LIKE ?";
                } elseif (isset($def['legacy']) && !empty($def['legacy_remap'])) {
                    // GH#103 — same reasoning as the SELECT list above: a
                    // search that only checks the legacy column misses
                    // rows whose data lives in the named column.
                    $col = ($hasJoins ? "{$table}." : '');
                    $searchCols[] = "COALESCE(NULLIF({$col}`{$dbCol}`, ''), {$col}`{$def['legacy']}`) LIKE ?";
                } else {
                    $actual = isset($def['legacy']) ? $def['legacy'] : $dbCol;
                    $searchCols[] = ($hasJoins ? "{$table}." : '') . "`{$actual}` LIKE ?";
                }
                $params[] = $term;
            }
        }
        if (!empty($searchCols)) {
            $where[] = '(' . implode(' OR ', $searchCols) . ')';
        }
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    // Same ambiguity reasoning as the SELECT list above: qualify the
    // ORDER BY column with the base table whenever a join is present.
    $orderCol = ($hasJoins ? "{$table}." : '') . "`{$config['id_column']}`";
    $sql = "SELECT " . implode(', ', $selectCols) . " FROM {$table}{$joinSQL} {$whereSQL} ORDER BY {$orderCol} LIMIT 10000";

    try {
        $rows = db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // The '' return is the caller's contract ("Export failed — no data or
        // table error") and stays. But swallowing the REASON is what made GH
        // TicketsCAD#14 take a manual reconstruction of the SELECT to
        // diagnose: a genuinely empty table still returns a header row, so
        // reaching this branch always means a real error, never "no rows".
        error_log('export_csv(' . $config['table'] . ') failed: ' . $e->getMessage()
                . ' — SQL: ' . $sql);
        return '';
    }

    // Build CSV — explicit $escape for PHP 8.4+ (deprecation 2026-06)
    $output = fopen('php://temp', 'r+');
    fputcsv($output, $headerLabels, ',', '"', '\\');

    $colKeys = [];
    foreach ($columns as $dbCol => $def) {
        if ($def['export']) $colKeys[] = $dbCol;
    }

    foreach ($rows as $row) {
        $csvRow = [];
        foreach ($colKeys as $key) {
            $csvRow[] = $row[$key] ?? '';
        }
        fputcsv($output, $csvRow, ',', '"', '\\');
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return $csv;
}
