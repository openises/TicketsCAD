<?php
/**
 * Repair the OwnTracks provider's endpoint URL on existing installs.
 *
 * The original seed in location_providers.sql shipped this URL:
 *     /api/location.php?action=report&provider_code=owntracks
 *
 * The actual receiver in api/location.php:33 only dispatches on:
 *     /api/location.php?provider=owntracks
 *
 * So every existing install that displayed the Settings → Location
 * Providers panel showed operators the wrong URL to point their
 * OwnTracks app at. Devices configured per the displayed URL would
 * silently fail (HTTP 200 from Apache, nothing parsed, no row in
 * location_reports).
 *
 * This migration:
 *   1. Updates the owntracks row's config_json to the correct URL
 *   2. Inserts the new traccar + opengts rows if they're not present
 *      (mirroring the updated seed in location_providers.sql)
 *
 * Idempotent — safe to re-run. The orchestrator (run_migrations.php)
 * uses script-name + file-hash dedup so this auto-applies once on each
 * install and stays out of the way after.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 87 — repair OwnTracks endpoint URL + seed Traccar/OpenGTS\n";
echo "================================================================\n";

// ── Step 1: fix the OwnTracks endpoint URL ───────────────────────
try {
    // Re-write the entire config_json (keeps any secret the admin had
    // set; only rewrites the endpoint). Match by code, not row id,
    // because seed order varies between installs.
    $row = db_fetch_one(
        "SELECT id, config_json FROM `{$prefix}location_providers` WHERE code = 'owntracks'"
    );
    if (!$row) {
        echo "  [skip] no owntracks row to fix\n";
    } else {
        $config = json_decode((string) $row['config_json'], true);
        if (!is_array($config)) $config = [];
        $oldEndpoint = $config['endpoint'] ?? null;
        $correct     = '/api/location.php?provider=owntracks';
        if ($oldEndpoint === $correct) {
            echo "  [skip] owntracks endpoint already correct\n";
        } else {
            $config['endpoint'] = $correct;
            // Drop the bogus `provider_code` query param if it's still in
            // there from the old seed.
            db_query(
                "UPDATE `{$prefix}location_providers` SET config_json = ? WHERE id = ?",
                [json_encode($config), (int) $row['id']]
            );
            echo "  [ok] owntracks endpoint repaired: '" . ($oldEndpoint ?: '<empty>') .
                 "' -> '$correct'\n";
        }
    }
} catch (Exception $e) {
    echo "  [err] owntracks repair failed: " . $e->getMessage() . "\n";
    throw $e;
}

// ── Step 2: insert traccar + opengts provider rows ───────────────
$newProviders = [
    [
        'code'        => 'opengts',
        'name'        => 'OpenGTS',
        'priority'    => 40,
        'config_json' => '{"endpoint":"/api/location.php?provider=opengts","note":"GPRMC HTTP query-string format"}',
        'icon'        => 'bi-geo-alt',
        'color'       => '#993399',
    ],
    [
        'code'        => 'traccar',
        'name'        => 'Traccar',
        'priority'    => 45,
        'config_json' => '{"endpoint":"/api/location.php?provider=traccar","note":"Traccar Server JSON forwarder (osmand format)"}',
        'icon'        => 'bi-geo-fill',
        'color'       => '#FF8800',
    ],
];

foreach ($newProviders as $p) {
    try {
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}location_providers` WHERE code = ?",
            [$p['code']]
        );
        if ($exists) {
            // Update the config_json so an old install that had a stub
            // row gets the corrected endpoint, but DO NOT touch `enabled`
            // (admin choice) or `priority` (operator may have re-ordered).
            db_query(
                "UPDATE `{$prefix}location_providers` SET config_json = ?, icon = ?, color = ? WHERE code = ?",
                [$p['config_json'], $p['icon'], $p['color'], $p['code']]
            );
            echo "  [ok] {$p['code']} config_json refreshed\n";
        } else {
            db_query(
                "INSERT INTO `{$prefix}location_providers`
                    (code, name, enabled, priority, config_json, icon, color)
                 VALUES (?, ?, 0, ?, ?, ?, ?)",
                [$p['code'], $p['name'], $p['priority'], $p['config_json'], $p['icon'], $p['color']]
            );
            echo "  [ok] {$p['code']} row inserted (enabled=0)\n";
        }
    } catch (Exception $e) {
        echo "  [err] {$p['code']}: " . $e->getMessage() . "\n";
        throw $e;
    }
}

echo "\nDone.\n";
