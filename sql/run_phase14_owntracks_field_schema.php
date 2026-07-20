<?php
/**
 * Phase 14 (2026-06-11) — OwnTracks comm_mode field schema fix.
 *
 * Eric reported on 2026-06-11 that he couldn't save an OwnTracks
 * identifier for a roster member. Root cause: the OwnTracks comm_mode
 * row's fields_json marks BOTH mqtt_topic AND tracker_id as required.
 *
 * OwnTracks runs in two modes:
 *   - HTTP-Direct (recommended for small deployments) — phones POST
 *     positions to /api/location.php?provider=owntracks. Only needs
 *     the 2-char Tracker ID (TID).
 *   - MQTT — phones publish to a Mosquitto broker on a topic like
 *     `owntracks/user/device`. Needs both topic AND TID.
 *
 * Marking mqtt_topic required blocks every HTTP-Direct admin from
 * saving an OwnTracks identifier.
 *
 * This migration:
 *   - Sets mqtt_topic to NOT required.
 *   - Adds a hint on each field explaining when it's needed.
 *   - Keeps tracker_id required (you need a TID either way).
 *
 * Idempotent: the UPDATE writes the new value verbatim. Re-running
 * is a no-op once the field_json has been replaced.
 *
 * Usage: php sql/run_phase14_owntracks_field_schema.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 14 — OwnTracks comm_mode field schema fix\n";
echo "===============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$newFieldsJson = json_encode([
    [
        'key'         => 'tracker_id',
        'label'       => 'Tracker ID (TID)',
        'type'        => 'text',
        'placeholder' => 'EO',
        'maxlength'   => 2,
        'required'    => true,
        'hint'        => '2-character code (e.g., EO for Eric Osterberg) that matches the TrackerID set in the OwnTracks app. Required for both HTTP-Direct and MQTT modes.',
    ],
    [
        'key'         => 'mqtt_topic',
        'label'       => 'MQTT Topic',
        'type'        => 'text',
        'placeholder' => 'owntracks/user/device',
        'maxlength'   => 128,
        'required'    => false,
        'hint'        => 'Only needed for OwnTracks MQTT mode (Mosquitto broker + OwnTracks Recorder). Leave blank for the simpler HTTP-Direct mode where phones POST to /api/location.php?provider=owntracks.',
    ],
], JSON_UNESCAPED_SLASHES);

try {
    $row = db_fetch_one(
        "SELECT id, fields_json FROM `{$prefix}comm_modes` WHERE code = 'owntracks' LIMIT 1"
    );
    if (!$row) {
        echo "[--] owntracks comm_mode row not present; the run_member_columns.php / comm_identifiers seed has not yet run.\n";
        echo "    Run sql/run_migrations.php first.\n";
        exit(1);
    }
    if ($row['fields_json'] === $newFieldsJson) {
        echo "[OK] owntracks fields_json already current — nothing to do.\n";
        exit(0);
    }

    db_query(
        "UPDATE `{$prefix}comm_modes` SET fields_json = ? WHERE id = ?",
        [$newFieldsJson, (int) $row['id']]
    );
    echo "[OK] Updated owntracks comm_mode field schema (id={$row['id']})\n";
    echo "     mqtt_topic: required=false, with hint about MQTT vs HTTP-Direct\n";
    echo "     tracker_id: required=true, with hint\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
