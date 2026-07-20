<?php
/**
 * Add Traccar (and OpenGTS) to comm_modes so personnel can bind
 * a device's uniqueId to their record via the existing
 * comm-identifiers UI.
 *
 * Without these rows, the Roster's "add comm identifier" dropdown
 * would show APRS / DMR / Meshtastic / Zello / OwnTracks but not
 * Traccar — the operator would have no way to tell TicketsCAD
 * "device IMEI 863719010012345 belongs to Unit 7".
 *
 * Mirrors the OwnTracks comm_mode (id=155 on training) — one
 * required field for the binding key, plus optional notes.
 *
 * Idempotent: INSERT IGNORE keyed on `code`, safe to re-run.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 88 — seed traccar + opengts comm_modes\n";
echo "===========================================\n";

$modes = [
    [
        'code'        => 'traccar',
        'name'        => 'Traccar',
        'icon'        => 'geo-fill',
        'color'       => '#FF8800',
        'capabilities'=> 'L',
        'sort_order'  => 55,
        'notes'       => 'Traccar Server / Traccar Client devices. Binding key is device.uniqueId (IMEI on most hardware, app-generated on phone clients).',
        'fields_json' => json_encode([
            [
                'key'         => 'device_unique_id',
                'label'       => 'Device unique ID',
                'type'        => 'text',
                'placeholder' => '863719010012345',
                'maxlength'   => 64,
                'required'    => true,
                'hint'        => 'IMEI for hardware trackers; the per-install ID Traccar Client generates for phones. Find it in Traccar Server: Devices panel → device name → Unique Identifier.',
            ],
            [
                'key'         => 'device_name',
                'label'       => 'Device name',
                'type'        => 'text',
                'placeholder' => 'Truck 7',
                'maxlength'   => 64,
                'required'    => false,
                'hint'        => 'Optional display label so the dispatcher knows which physical device this is. Matches what you typed in Traccar Server.',
            ],
        ]),
    ],
    [
        'code'        => 'opengts',
        'name'        => 'OpenGTS / GPRMC',
        'icon'        => 'geo-alt',
        'color'       => '#993399',
        'capabilities'=> 'L',
        'sort_order'  => 56,
        'notes'       => 'Hardware GPS modems speaking the legacy OpenGTS GPRMC-over-HTTP protocol. Binding key is the device IMEI (or whatever the modem sends in its `id=` query param).',
        'fields_json' => json_encode([
            [
                'key'         => 'device_id',
                'label'       => 'Device ID (IMEI)',
                'type'        => 'text',
                'placeholder' => '861234567890123',
                'maxlength'   => 32,
                'required'    => true,
                'hint'        => 'Whatever the modem sends in its `id` (or `deviceid`/`imei`) query string. Usually the 15-digit IMEI.',
            ],
        ]),
    ],
];

foreach ($modes as $m) {
    try {
        // INSERT IGNORE keyed on unique `code` so re-runs don't error.
        $stmt = db_query(
            "INSERT IGNORE INTO `{$prefix}comm_modes`
                (code, name, icon, color, fields_json, capabilities, lookup_url, enabled, sort_order, notes)
             VALUES (?, ?, ?, ?, ?, ?, '', 1, ?, ?)",
            [$m['code'], $m['name'], $m['icon'], $m['color'], $m['fields_json'],
             $m['capabilities'], $m['sort_order'], $m['notes']]
        );
        if ($stmt->rowCount() > 0) {
            echo "  [ok] inserted comm_mode '{$m['code']}'\n";
        } else {
            // Existing row — refresh fields_json + notes in case the
            // operator has edited but we shipped an improved field
            // definition. Don't touch enabled or sort_order (admin's
            // choice).
            db_query(
                "UPDATE `{$prefix}comm_modes` SET fields_json = ?, notes = ?, icon = ?, color = ? WHERE code = ?",
                [$m['fields_json'], $m['notes'], $m['icon'], $m['color'], $m['code']]
            );
            echo "  [ok] refreshed comm_mode '{$m['code']}' (fields_json + notes)\n";
        }
    } catch (Exception $e) {
        echo "  [err] {$m['code']}: " . $e->getMessage() . "\n";
        throw $e;
    }
}

echo "\nDone.\n";
