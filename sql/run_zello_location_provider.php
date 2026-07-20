<?php
/**
 * Seed the `zello` location provider so a Zello channel member's shared
 * location (the Zello `on_location` event the proxy already parses) can
 * flow onto the existing unit-tracking map.
 *
 * The unit-tracking map (api/location.php?all_units=1) joins
 *   location_reports → unit_location_bindings → location_providers
 * by provider_id + unit_identifier. Every other live provider
 * (owntracks, traccar, aprs, meshtastic, dmr) has a row in
 * `location_providers`; Zello did not. Without it the proxy has no
 * provider_id to write a fix against and nothing shows on the map.
 *
 * This migration inserts the `zello` provider row (enabled = 0 — the
 * operator turns it on in Settings → Location Providers, same as every
 * other provider). The Zello proxy writes location_reports with
 * unit_identifier = the sender's Zello username (the same value
 * member_comm_identifiers stores under the `username` key for comm
 * mode `zello`).
 *
 * Idempotent — matches by `code`, inserts only when absent, refreshes
 * cosmetic fields (icon/color/config_json) on re-run without touching
 * the operator's `enabled` / `priority` choices. Safe to re-run.
 *
 * The orchestrator (run_migrations.php) discovers `sql/run_*.php` and
 * dedups by script-name + file-hash, so this auto-applies once.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Seed Zello location provider\n";
echo "============================\n";

$code        = 'zello';
$name        = 'Zello Location';
$priority    = 55;
$configJson  = '{"note":"Populated by the Zello WebSocket proxy from on_location events. unit_identifier = the sender Zello username (member_comm_identifiers.values_json.username). The channel member must enable location sharing in their Zello app."}';
$icon        = 'bi-broadcast';
$color       = '#FFB300';

try {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}location_providers` WHERE code = ?",
        [$code]
    );
    if ($exists) {
        // Refresh cosmetic fields only — never override enabled/priority
        // (those are operator choices once the row exists).
        db_query(
            "UPDATE `{$prefix}location_providers`
                SET name = ?, config_json = ?, icon = ?, color = ?
              WHERE code = ?",
            [$name, $configJson, $icon, $color, $code]
        );
        echo "  [ok] zello provider already present — cosmetic fields refreshed\n";
    } else {
        db_query(
            "INSERT INTO `{$prefix}location_providers`
                (code, name, enabled, priority, config_json, icon, color)
             VALUES (?, ?, 0, ?, ?, ?, ?)",
            [$code, $name, $priority, $configJson, $icon, $color]
        );
        echo "  [ok] zello provider row inserted (enabled=0 — turn on in Settings)\n";
    }
} catch (Exception $e) {
    echo "  [err] zello provider seed failed: " . $e->getMessage() . "\n";
    throw $e;
}

echo "\nDone.\n";
