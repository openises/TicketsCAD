<?php
/**
 * NewUI v4.0 — Per-user screen column preferences (Phase 17, 2026-06-11).
 *
 * Each "screen" (e.g., 'units', 'roster', 'incidents') has a default
 * column catalog defined in code. Users can override visibility, order,
 * and default sort via the column-editor modal.
 *
 *   prefs_get(int $userId, string $screen, array $defaults): array
 *     Merges saved overrides with the screen's defaults so missing
 *     entries inherit. Always returns {columns: [...], sort: {...}}.
 *
 *   prefs_set(int $userId, string $screen, array $prefs): bool
 *     Persists the JSON blob.
 *
 *   prefs_reset(int $userId, string $screen): bool
 *     Drops the row so the screen reverts to defaults.
 *
 *   prefs_screen_defaults(): array
 *     Returns the canonical catalog for every supported screen.
 *     Other pages just import this constant and ship the screen
 *     name to the JS component.
 */

function prefs_screen_defaults(): array {
    return [
        // Units (responders) page — Eric's primary target for Phase 17.
        // PAR columns ('par_last_checkin', 'par_next_due') are off by
        // default so existing users don't suddenly see new columns
        // until they opt in.
        // Dashboard — Phase 17 (2026-06-11). Per-user recent-closed
        // window for the Incidents widget. Separate from 'situation'
        // so users can keep different windows on each surface.
        'dashboard' => [
            'columns' => [],
            'sort' => ['col' => '', 'dir' => 'asc'],
            'options' => [
                'recent_close_mins' => 30,

                // Phase 131 (owner review 2026-08-01) — where the net-control
                // check-in panel sits, and whether it is open. It floats over
                // the grid rather than living in it, so GridStack's
                // dashboard_layouts row (grid CELL coordinates for grid items,
                // plus a hidden list) has nowhere to put pixel geometry, and
                // localStorage — what the chat and Zello panels use — is per
                // browser, not per user. This options block already exists for
                // exactly this: arbitrary per-user, per-screen scalars. The
                // panel also renders on situation.php, which never loads
                // GridStack or api/layout.php at all; keeping all of its state
                // here is what lets the two screens agree.
                //
                // -1 means "never placed" → centre of the viewport.
                //  0 means "no explicit size" → the stylesheet's size.
                'net_panel_left' => -1,
                'net_panel_top'  => -1,
                'net_panel_w'    => 0,
                'net_panel_h'    => 0,
                'net_panel_open' => 1,
            ],
        ],

        // Situation screen — Phase 17 (2026-06-11). Only stores the
        // user's recent-closed window in minutes. No column list
        // (the situation widget has a fixed layout).
        'situation' => [
            'columns' => [],
            'sort' => ['col' => '', 'dir' => 'asc'],
            'options' => [
                // Minutes that recently-closed incidents linger in the
                // "Current" view. Default 30 matches the prior server
                // setting; users can extend to several hours if they
                // want closed incidents to stay clickable longer.
                'recent_close_mins' => 30,
            ],
        ],

        // Roster (personnel) — Phase 17 follow-on (2026-06-11).
        'roster' => [
            'columns' => [
                ['id' => 'name',     'label' => 'Name',     'visible' => true, 'pos' => 0],
                ['id' => 'callsign', 'label' => 'Callsign', 'visible' => true, 'pos' => 1],
                ['id' => 'type',     'label' => 'Type',     'visible' => true, 'pos' => 2],
                ['id' => 'status',   'label' => 'Status',   'visible' => true, 'pos' => 3],
                ['id' => 'team',     'label' => 'Team',     'visible' => true, 'pos' => 4],
                ['id' => 'phone',    'label' => 'Phone',    'visible' => true, 'pos' => 5],
                ['id' => 'avail',    'label' => 'Avail',    'visible' => true, 'pos' => 6],
            ],
            'sort' => ['col' => 'name', 'dir' => 'asc'],
        ],

        // Incidents list — Phase 17 follow-on (2026-06-11).
        'incidents' => [
            'columns' => [
                ['id' => 'id',       'label' => '#',        'visible' => true, 'pos' => 0],
                ['id' => 'severity', 'label' => 'Sev',      'visible' => true, 'pos' => 1],
                ['id' => 'date',     'label' => 'Date',     'visible' => true, 'pos' => 2],
                ['id' => 'scope',    'label' => 'Scope',    'visible' => true, 'pos' => 3],
                ['id' => 'type',     'label' => 'Type',     'visible' => true, 'pos' => 4],
                ['id' => 'location', 'label' => 'Location', 'visible' => true, 'pos' => 5],
                ['id' => 'status',   'label' => 'Status',   'visible' => true, 'pos' => 6],
                ['id' => 'units',    'label' => 'Units',    'visible' => true, 'pos' => 7],
                ['id' => 'updated',  'label' => 'Updated',  'visible' => true, 'pos' => 8],
            ],
            'sort' => ['col' => 'date', 'dir' => 'desc'],
        ],

        'units' => [
            'columns' => [
                ['id' => 'name',             'label' => 'Name',                  'visible' => true,  'pos' => 0],
                ['id' => 'handle',           'label' => 'Handle',                'visible' => true,  'pos' => 1],
                ['id' => 'type',             'label' => 'Type',                  'visible' => true,  'pos' => 2],
                ['id' => 'status',           'label' => 'Status',                'visible' => true,  'pos' => 3],
                ['id' => 'active',           'label' => 'Active',                'visible' => true,  'pos' => 4],
                ['id' => 'updated',          'label' => 'Last Updated',          'visible' => true,  'pos' => 5],
                ['id' => 'par_last_checkin', 'label' => 'Time since last activity', 'visible' => false, 'pos' => 6],
                ['id' => 'par_next_due',     'label' => 'Next PAR due',          'visible' => false, 'pos' => 7],
            ],
            'sort' => ['col' => 'name', 'dir' => 'asc'],
        ],

        // Phase 26B (2026-06-11) — facilities / equipment / vehicles
        // get the same Phase 17 column-prefs treatment.
        'facilities' => [
            'columns' => [
                ['id' => 'name',    'label' => 'Name',    'visible' => true, 'pos' => 0],
                ['id' => 'type',    'label' => 'Type',    'visible' => true, 'pos' => 1],
                ['id' => 'status',  'label' => 'Status',  'visible' => true, 'pos' => 2],
                ['id' => 'beds',    'label' => 'Beds A/O','visible' => true, 'pos' => 3],
                ['id' => 'city',    'label' => 'City',    'visible' => true, 'pos' => 4],
                ['id' => 'updated', 'label' => 'Updated', 'visible' => true, 'pos' => 5],
            ],
            'sort' => ['col' => 'name', 'dir' => 'asc'],
        ],

        'equipment' => [
            'columns' => [
                ['id' => 'item',      'label' => 'Item',         'visible' => true, 'pos' => 0],
                ['id' => 'type',      'label' => 'Type',         'visible' => true, 'pos' => 1],
                ['id' => 'asset_tag', 'label' => 'Asset Tag',    'visible' => true, 'pos' => 2],
                ['id' => 'assigned',  'label' => 'Assigned To',  'visible' => true, 'pos' => 3],
                ['id' => 'status',    'label' => 'Status',       'visible' => true, 'pos' => 4],
                ['id' => 'condition', 'label' => 'Condition',    'visible' => true, 'pos' => 5],
            ],
            'sort' => ['col' => 'item', 'dir' => 'asc'],
        ],

        'vehicles' => [
            'columns' => [
                ['id' => 'unit',    'label' => 'Unit',    'visible' => true, 'pos' => 0],
                ['id' => 'vehicle', 'label' => 'Vehicle', 'visible' => true, 'pos' => 1],
                ['id' => 'type',    'label' => 'Type',    'visible' => true, 'pos' => 2],
                ['id' => 'owner',   'label' => 'Owner',   'visible' => true, 'pos' => 3],
                ['id' => 'status',  'label' => 'Status',  'visible' => true, 'pos' => 4],
                ['id' => 'privacy', 'label' => 'Privacy', 'visible' => true, 'pos' => 5],
            ],
            'sort' => ['col' => 'unit', 'dir' => 'asc'],
        ],

        // GH #63 (a beta tester, 2026-07-07) — extend the units.php column
        // picker to the situation screen's Incidents/Units tabs and
        // the dashboard Incidents/Responders widgets. Each catalog
        // mirrors exactly what its table renders today, everything
        // visible by default, so nothing changes until a user
        // customizes. prefs_get() merges saved overrides against
        // THIS list — a screen missing here silently loses its saved
        // prefs on the next load, so keep ids in sync with the
        // data-col-id attributes in situation.php / index.php.
        'situation-incidents' => [
            'columns' => [
                ['id' => 'sev',     'label' => 'Sev',     'visible' => true, 'pos' => 0],
                ['id' => 'case',    'label' => 'Case #',  'visible' => true, 'pos' => 1],
                ['id' => 'scope',   'label' => 'Scope',   'visible' => true, 'pos' => 2],
                ['id' => 'type',    'label' => 'Type',    'visible' => true, 'pos' => 3],
                ['id' => 'address', 'label' => 'Address', 'visible' => true, 'pos' => 4],
                ['id' => 'units',   'label' => 'Units',   'visible' => true, 'pos' => 5],
                ['id' => 'updated', 'label' => 'Updated', 'visible' => true, 'pos' => 6],
            ],
            'sort' => ['col' => '', 'dir' => 'asc'],
        ],

        'situation-units' => [
            'columns' => [
                ['id' => 'dot',       'label' => 'Status Dot', 'visible' => true, 'pos' => 0],
                ['id' => 'unit',      'label' => 'Unit',       'visible' => true, 'pos' => 1],
                ['id' => 'callsign',  'label' => 'Callsign',   'visible' => true, 'pos' => 2],
                ['id' => 'principal', 'label' => 'Principal',  'visible' => true, 'pos' => 3],
                ['id' => 'status',    'label' => 'Status',     'visible' => true, 'pos' => 4],
                ['id' => 'location',  'label' => 'Location',   'visible' => true, 'pos' => 5],
                ['id' => 'updated',   'label' => 'Updated',    'visible' => true, 'pos' => 6],
            ],
            'sort' => ['col' => '', 'dir' => 'asc'],
        ],

        'widget-incidents' => [
            'columns' => [
                ['id' => 'case',     'label' => 'Case #',   'visible' => true, 'pos' => 0],
                ['id' => 'scope',    'label' => 'Scope',    'visible' => true, 'pos' => 1],
                ['id' => 'type',     'label' => 'Type',     'visible' => true, 'pos' => 2],
                ['id' => 'location', 'label' => 'Location', 'visible' => true, 'pos' => 3],
                ['id' => 'sev',      'label' => 'Severity', 'visible' => true, 'pos' => 4],
                ['id' => 'units',    'label' => 'Units',    'visible' => true, 'pos' => 5],
                ['id' => 'pts',      'label' => 'Patients', 'visible' => true, 'pos' => 6],
                ['id' => 'act',      'label' => 'Actions',  'visible' => true, 'pos' => 7],
                ['id' => 'updated',  'label' => 'Updated',  'visible' => true, 'pos' => 8],
            ],
            'sort' => ['col' => '', 'dir' => 'asc'],
        ],

        'widget-responders' => [
            'columns' => [
                ['id' => 'name',     'label' => 'Name',     'visible' => true, 'pos' => 0],
                ['id' => 'handle',   'label' => 'Handle',   'visible' => true, 'pos' => 1],
                ['id' => 'type',     'label' => 'Type',     'visible' => true, 'pos' => 2],
                ['id' => 'status',   'label' => 'Status',   'visible' => true, 'pos' => 3],
                ['id' => 'assigned', 'label' => 'Assigned', 'visible' => true, 'pos' => 4],
            ],
            'sort' => ['col' => '', 'dir' => 'asc'],
        ],

        // GH #63 (a beta tester) — Facilities dashboard widget. Column ids MUST match
        // the data-col-id attributes in index.php's tpl-facilities thead, in
        // the SAME order as the <td> cells in renderFacilities() (the picker
        // hides by nth-child, so a mismatch would hide the wrong cell). 'beds'
        // is the combined Beds (Available / Occupied) cell a beta tester asked for;
        // shown by default so it's discoverable without hunting the picker.
        'widget-facilities' => [
            'columns' => [
                ['id' => 'name',   'label' => 'Name',              'visible' => true, 'pos' => 0],
                ['id' => 'type',   'label' => 'Type',              'visible' => true, 'pos' => 1],
                ['id' => 'status', 'label' => 'Status',            'visible' => true, 'pos' => 2],
                ['id' => 'beds',   'label' => 'Beds (Avail / Occ)', 'visible' => true, 'pos' => 3],
                ['id' => 'hours',  'label' => 'Hours',             'visible' => true, 'pos' => 4],
            ],
            'sort' => ['col' => '', 'dir' => 'asc'],
        ],
    ];
}

/**
 * Raw per-user JSON blob under an arbitrary $screen key — bypasses the
 * columns/sort/options envelope prefs_get() enforces (which is a poor fit
 * for a free-form structure like per-channel console-audio state: its
 * merge step only carries SCALAR 'options' values forward, so a nested
 * object silently vanishes on the next read through prefs_get()). Reuses
 * the same user_screen_prefs table/row (one JSON blob per user+screen) —
 * just without the catalog-based defaults-merge. Callers own their own
 * shape and validation; this is pure storage.
 *
 * Phase 114b3 (console per-channel audio prefs) is the first consumer —
 * see api/console-audio-prefs.php.
 */
function prefs_get_raw(int $userId, string $screen): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT prefs_json FROM `{$prefix}user_screen_prefs`
              WHERE user_id = ? AND screen = ? LIMIT 1",
            [$userId, $screen]
        );
        if ($v) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) { return $decoded; }
        }
    } catch (Exception $e) {}
    return [];
}

function prefs_get(int $userId, string $screen, ?array $defaults = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $catalog = prefs_screen_defaults();
    $screenDefaults = $defaults ?? ($catalog[$screen] ?? ['columns' => [], 'sort' => ['col' => '', 'dir' => 'asc']]);

    $saved = null;
    try {
        $v = db_fetch_value(
            "SELECT prefs_json FROM `{$prefix}user_screen_prefs`
              WHERE user_id = ? AND screen = ? LIMIT 1",
            [$userId, $screen]
        );
        if ($v) $saved = json_decode($v, true);
    } catch (Exception $e) {}

    // Build a quick lookup of saved column entries by id.
    $savedById = [];
    if (is_array($saved) && isset($saved['columns']) && is_array($saved['columns'])) {
        foreach ($saved['columns'] as $c) {
            if (is_array($c) && isset($c['id'])) $savedById[$c['id']] = $c;
        }
    }

    // Merge: for each default column, apply saved overrides (visibility,
    // pos). Columns the user has hidden completely or moved stay where
    // they're saved. Columns added since the user last saved fall back
    // to default (visible if default says so).
    $merged = [];
    foreach ($screenDefaults['columns'] as $c) {
        $entry = $c;
        if (isset($savedById[$c['id']])) {
            $sv = $savedById[$c['id']];
            if (isset($sv['visible'])) $entry['visible'] = (bool) $sv['visible'];
            if (isset($sv['pos']))     $entry['pos']     = (int)  $sv['pos'];
        }
        $merged[] = $entry;
    }
    // Sort by pos so the JS doesn't have to.
    usort($merged, function ($a, $b) { return ($a['pos'] ?? 0) - ($b['pos'] ?? 0); });

    $sort = $screenDefaults['sort'];
    if (is_array($saved) && isset($saved['sort']) && is_array($saved['sort'])) {
        if (isset($saved['sort']['col'])) $sort['col'] = (string) $saved['sort']['col'];
        if (isset($saved['sort']['dir']) && in_array($saved['sort']['dir'], ['asc','desc'], true)) {
            $sort['dir'] = $saved['sort']['dir'];
        }
    }

    // 2026-06-11 — generic 'options' block lets screens store
    // arbitrary scalar prefs (numbers, strings, booleans). Merge
    // saved overrides on top of defaults.
    $options = $screenDefaults['options'] ?? [];
    if (is_array($saved) && isset($saved['options']) && is_array($saved['options'])) {
        foreach ($saved['options'] as $k => $v) {
            if (is_scalar($v)) $options[$k] = $v;
        }
    }

    return [
        'columns' => $merged,
        'sort'    => $sort,
        'options' => $options,
    ];
}

function prefs_set(int $userId, string $screen, array $prefs): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}user_screen_prefs` (user_id, screen, prefs_json)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json)",
            [$userId, $screen, json_encode($prefs)]
        );
        return true;
    } catch (Exception $e) { return false; }
}

function prefs_reset(int $userId, string $screen): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "DELETE FROM `{$prefix}user_screen_prefs`
              WHERE user_id = ? AND screen = ?",
            [$userId, $screen]
        );
        return true;
    } catch (Exception $e) { return false; }
}
