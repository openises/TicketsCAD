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
                'last_name'  => ['label' => 'Last Name',   'type' => 'string', 'required' => true,  'import' => true,  'export' => true,  'legacy' => 'field1'],
                'first_name' => ['label' => 'First Name',  'type' => 'string', 'required' => true,  'import' => true,  'export' => true,  'legacy' => 'field2'],
                'callsign'   => ['label' => 'Callsign',    'type' => 'string', 'required' => false, 'import' => true,  'export' => true,  'legacy' => 'field4'],
                'email'      => ['label' => 'Email',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true,  'legacy' => 'field6'],
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
                'street'      => ['label' => 'Street',       'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'city'        => ['label' => 'City',         'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'state'       => ['label' => 'State',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'zip'         => ['label' => 'Zip',          'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'lat'         => ['label' => 'Latitude',     'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'lng'         => ['label' => 'Longitude',    'type' => 'float',  'required' => false, 'import' => true,  'export' => true],
                'phone'       => ['label' => 'Phone',        'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'contact'     => ['label' => 'Contact',      'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'capacity'    => ['label' => 'Capacity',     'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
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
            'audit_cols'   => [],
            'columns' => [
                'id'          => ['label' => 'ID',            'type' => 'int',    'required' => false, 'import' => false, 'export' => true],
                'name'        => ['label' => 'Team Name',     'type' => 'string', 'required' => true,  'import' => true,  'export' => true],
                'description' => ['label' => 'Description',   'type' => 'string', 'required' => false, 'import' => true,  'export' => true],
                'team_type_id'=> ['label' => 'Team Type ID',  'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
                'active'      => ['label' => 'Active',        'type' => 'int',    'required' => false, 'import' => true,  'export' => true],
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
                'name'      => ['label' => 'Full Name', 'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
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
            'columns' => [
                'id'           => ['label' => 'ID',            'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'scope'        => ['label' => 'Scope/Summary', 'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'in_types_id'  => ['label' => 'Type ID',       'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'status'       => ['label' => 'Status',        'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'severity'     => ['label' => 'Severity',      'type' => 'int',     'required' => false, 'import' => false, 'export' => true],
                'address'      => ['label' => 'Address',       'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'city'         => ['label' => 'City',          'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'state'        => ['label' => 'State',         'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'lat'          => ['label' => 'Latitude',      'type' => 'float',   'required' => false, 'import' => false, 'export' => true],
                'lng'          => ['label' => 'Longitude',     'type' => 'float',   'required' => false, 'import' => false, 'export' => true],
                'caller_name'  => ['label' => 'Caller Name',   'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'caller_phone' => ['label' => 'Caller Phone',  'type' => 'string',  'required' => false, 'import' => false, 'export' => true],
                'call_received'=> ['label' => 'Call Received',  'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
                'dispatched'   => ['label' => 'Dispatched',     'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
                'closed'       => ['label' => 'Closed',         'type' => 'string', 'required' => false, 'import' => false, 'export' => true],
            ],
        ],
    ];

    if (!isset($configs[$target])) {
        return [];
    }
    return $configs[$target];
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
        $insertRow = [];
        $columns = $config['columns'];
        foreach ($row as $dbCol => $val) {
            // Use legacy column name if applicable
            if (isset($columns[$dbCol]['legacy'])) {
                $insertRow[$columns[$dbCol]['legacy']] = $val;
            } else {
                $insertRow[$dbCol] = $val;
            }
        }
        $insertRow = array_merge($insertRow, $audit);

        // Remove null values for optional columns
        $insertRow = array_filter($insertRow, function ($v) { return $v !== null; });

        // Fill in `default` values from config for any importable column
        // that's missing from the row. Lets table configs declare a safe
        // default for NOT NULL columns that the source CSV might not
        // include (e.g. in_types.description is NOT NULL with no DB
        // default — old CSVs exported before description was added to
        // the export config simply omit the column). Without this, the
        // INSERT errors with "Field 'X' doesn't have a default value".
        foreach ($columns as $dbCol => $def) {
            if (!array_key_exists('default', $def)) continue;
            if (!$def['import']) continue;
            $actualCol = isset($def['legacy']) ? $def['legacy'] : $dbCol;
            if (!array_key_exists($actualCol, $insertRow)) {
                $insertRow[$actualCol] = $def['default'];
            }
        }

        if (empty($insertRow)) {
            $skipped++;
            continue;
        }

        try {
            if ($mode === 'upsert') {
                // Try to find existing record by match columns
                $matchFound = false;
                foreach ($config['match_columns'] as $matchExpr) {
                    $matchCols = explode('+', $matchExpr);
                    $where = [];
                    $params = [];
                    $allPresent = true;
                    foreach ($matchCols as $mc) {
                        $actualCol = isset($columns[$mc]['legacy']) ? $columns[$mc]['legacy'] : $mc;
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
                        // Update
                        $existingId = $existing[0][$idCol];
                        $setParts = [];
                        $updateParams = [];
                        foreach ($insertRow as $col => $val) {
                            $setParts[] = "`{$col}` = ?";
                            $updateParams[] = $val;
                        }
                        $updateParams[] = $existingId;
                        db_query(
                            "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE `{$idCol}` = ?",
                            $updateParams
                        );
                        $updated++;
                        $matchFound = true;
                        break;
                    }
                }
                if ($matchFound) continue;
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

    // Build SELECT with export columns
    $selectCols = [];
    $headerLabels = [];
    foreach ($columns as $dbCol => $def) {
        if (!$def['export']) continue;
        // Use legacy column with alias for export
        if (isset($def['legacy'])) {
            $selectCols[] = "`{$def['legacy']}` AS `{$dbCol}`";
        } else {
            $selectCols[] = "`{$dbCol}`";
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
                $actual = isset($def['legacy']) ? $def['legacy'] : $dbCol;
                $searchCols[] = "`{$actual}` LIKE ?";
                $params[] = $term;
            }
        }
        if (!empty($searchCols)) {
            $where[] = '(' . implode(' OR ', $searchCols) . ')';
        }
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT " . implode(', ', $selectCols) . " FROM {$table} {$whereSQL} ORDER BY `{$config['id_column']}` LIMIT 10000";

    try {
        $rows = db_fetch_all($sql, $params);
    } catch (Exception $e) {
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
