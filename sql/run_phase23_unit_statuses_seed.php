<?php
/**
 * Phase 23 (2026-06-11) — Seed default unit statuses.
 *
 * Eric on 2026-06-11 clicked a quick status-change button on
 * unit-detail.php?id=48 and got "Status not found". Root cause:
 * the un_status table was empty. The JS fallback renders hardcoded
 * Available/Busy/Unavailable buttons with status IDs 1/2/3 — those
 * IDs didn't exist in the un_status table on training (or anywhere).
 *
 * This migration seeds a standard set of fire/EMS unit statuses
 * matching the legacy fallback IDs + the common operational flow
 * (dispatched → responding → on scene → clear).
 *
 * Idempotent: only inserts when the table is empty. If admin has
 * already customized the statuses, this migration is a no-op.
 *
 * Usage: php sql/run_phase23_unit_statuses_seed.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 23 — Seed default unit statuses\n";
echo "=====================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $existing = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}un_status`");
    if ($existing > 0) {
        echo "[OK] un_status already has {$existing} rows — skipping seed.\n";
        echo "     If you want to RESET to defaults, manually TRUNCATE first.\n";
        exit(0);
    }
} catch (Exception $e) {
    echo "[FAIL] couldn't read un_status: " . $e->getMessage() . "\n";
    exit(1);
}

// Standard fire/EMS/RACES unit status set. Column `group` is reserved
// in MariaDB so it has to be quoted in the INSERT. Colors picked to
// match the legacy badge convention (green = available, yellow = busy,
// red = unavailable, blue = in-service operations).
$rows = [
    // id, status_val,      description,                         dispatch, watch, hide, group,         sort, bg,        fg
    [ 1,  'Available',      'Unit is in-service and ready',      1,        1,     'n',  'available',   10,  '#198754', '#ffffff'],
    [ 2,  'Busy',           'Unit on assignment but reachable',  1,        1,     'n',  'busy',        20,  '#ffc107', '#000000'],
    [ 3,  'Unavailable',    'Unit out of service',               0,        0,     'n',  'unavailable', 30,  '#dc3545', '#ffffff'],
    [ 4,  'Dispatched',     'Tones out, en route',               1,        1,     'n',  'busy',        40,  '#ffaa00', '#000000'],
    [ 5,  'Responding',     'En route to scene',                 1,        1,     'n',  'busy',        50,  '#fd7e14', '#ffffff'],
    [ 6,  'On Scene',       'Arrived at incident',               1,        1,     'n',  'busy',        60,  '#0dcaf0', '#000000'],
    [ 7,  'Transporting',   'Patient transport in progress',     1,        1,     'n',  'busy',        70,  '#6610f2', '#ffffff'],
    [ 8,  'At Facility',    'Arrived at hospital/destination',   1,        1,     'n',  'busy',        80,  '#6f42c1', '#ffffff'],
    [ 9,  'In Quarters',    'Returned to station',               1,        1,     'n',  'available',   90,  '#20c997', '#000000'],
    [10,  'Out of Service', 'Maintenance / personnel / fuel',    0,        0,     'n',  'unavailable', 100, '#6c757d', '#ffffff'],
];

$inserted = 0;
foreach ($rows as $r) {
    try {
        db_query(
            "INSERT INTO `{$prefix}un_status`
                (id, status_val, description, dispatch, watch, hide, `group`, sort, bg_color, text_color)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $r
        );
        $inserted++;
        echo "  [{$r[0]}] {$r[1]}\n";
    } catch (Exception $e) {
        echo "  [SKIP {$r[0]}] " . $e->getMessage() . "\n";
    }
}

echo "\n[OK] Seeded {$inserted} unit status rows.\n";
echo "Admins can edit/add/remove via Settings → Unit Statuses.\n";
