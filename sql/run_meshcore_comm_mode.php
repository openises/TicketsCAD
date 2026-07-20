<?php
/**
 * Phase B (messaging-send-gaps-2026-06) — seed a MeshCore comm_mode so
 * personnel can have a MeshCore pubkey-prefix bound to their roster
 * record via the existing Roster → Comm Identifiers UI.
 *
 * MeshCore is a sibling transport to Meshtastic in the unified mesh
 * stack (services/meshtastic/bridge_v2.py's MeshCoreAdapter). A MeshCore
 * DM is addressed to a *contact*, identified by its public key — the
 * 6-byte hex prefix (12 hex chars) is what the send frame and inbound
 * packets actually use. So the binding key here is `pubkey_prefix`
 * (≥12 hex chars), analogous to the Meshtastic `node_id`.
 *
 * Without this row, the Roster's "add comm identifier" dropdown shows
 * APRS / DMR / Meshtastic / Zello / OwnTracks / Traccar but NOT
 * MeshCore — the operator would have no way to tell TicketsCAD which
 * MeshCore contact belongs to which unit/person, so direct-to-unit
 * MeshCore messaging could never resolve an address.
 *
 * Idempotent + guarded: INSERT IGNORE keyed on the unique `code`, so
 * re-running never errors and never duplicates. On an existing row it
 * refreshes the field definition + notes (in case we ship an improved
 * shape) WITHOUT touching `enabled` or `sort_order` (admin's choice).
 * Safe to run repeatedly.
 *
 * Mirrors sql/run_traccar_comm_mode.php (Phase 88).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase B — seed meshcore comm_mode\n";
echo "=================================\n";

$mode = [
    'code'         => 'meshcore',
    'name'         => 'MeshCore',
    'icon'         => 'hdd-network-fill',
    'color'        => '#0d6efd',
    'capabilities' => 'L,2T',   // Location + 2-way text (same as Meshtastic)
    'sort_order'   => 35,        // sits right after Meshtastic (30)
    'notes'        => 'MeshCore LoRa mesh — route-remembering, end-to-end ACK. '
                    . 'Direct messages are addressed to a contact public-key prefix '
                    . '(hex, >=12 chars). Sibling transport to Meshtastic on the same '
                    . 'mesh bridge stack.',
    'fields_json'  => json_encode([
        [
            'key'         => 'pubkey_prefix',
            'label'       => 'Public-key prefix',
            'type'        => 'text',
            'placeholder' => 'a1b2c3d4e5f6',
            'maxlength'   => 64,
            'required'    => true,
            'hint'        => 'Hex prefix of the MeshCore contact public key (at least 12 hex '
                           . 'characters / 6 bytes). This is what a direct message is addressed '
                           . 'to. Find it in the MeshCore companion app contact list, or read it '
                           . 'off an inbound packet in the Mesh Console.',
        ],
        [
            'key'         => 'adv_name',
            'label'       => 'Advert name',
            'type'        => 'text',
            'placeholder' => 'Eric Base',
            'maxlength'   => 39,
            'required'    => false,
            'hint'        => 'Optional display name the node broadcasts in its advert. Helps '
                           . 'the dispatcher recognise the contact.',
        ],
    ]),
];

try {
    // INSERT IGNORE keyed on unique `code` so re-runs don't error.
    $stmt = db_query(
        "INSERT IGNORE INTO `{$prefix}comm_modes`
            (code, name, icon, color, fields_json, capabilities, lookup_url, enabled, sort_order, notes)
         VALUES (?, ?, ?, ?, ?, ?, '', 1, ?, ?)",
        [$mode['code'], $mode['name'], $mode['icon'], $mode['color'], $mode['fields_json'],
         $mode['capabilities'], $mode['sort_order'], $mode['notes']]
    );
    if ($stmt->rowCount() > 0) {
        echo "  [ok] inserted comm_mode 'meshcore'\n";
    } else {
        // Existing row — refresh field definition + notes (shipped improvement),
        // but leave enabled / sort_order alone (admin's choice).
        db_query(
            "UPDATE `{$prefix}comm_modes`
                SET fields_json = ?, notes = ?, icon = ?, color = ?, capabilities = ?
              WHERE code = ?",
            [$mode['fields_json'], $mode['notes'], $mode['icon'], $mode['color'],
             $mode['capabilities'], $mode['code']]
        );
        echo "  [ok] refreshed comm_mode 'meshcore' (fields_json + notes)\n";
    }
} catch (Exception $e) {
    echo "  [err] meshcore: " . $e->getMessage() . "\n";
    throw $e;
}

echo "\nDone.\n";
