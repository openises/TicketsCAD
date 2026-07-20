<?php
/**
 * Phase 41 — Make tile_mode='proxy' the default on existing installs.
 *
 * Existing rows that explicitly set tile_mode=direct stay as-is (admin
 * chose that). But installs that have NO tile_mode row, or have an empty
 * value, switch to 'proxy' so they get caching + key hiding for free.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 41 — tile_mode='proxy' as default\n";
echo "=======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // If no row exists at all, insert proxy.
    $exists = db_fetch_value("SELECT name FROM `{$prefix}settings` WHERE name = ?", ['tile_mode']);
    if (!$exists) {
        db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", ['tile_mode', 'proxy']);
        echo "[OK] inserted tile_mode=proxy (no prior row).\n";
    } else {
        // If the value is empty/null, upgrade to proxy. Do NOT clobber
        // an explicit 'direct' choice — admin set that deliberately.
        $val = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = ?", ['tile_mode']);
        if ($val === '' || $val === null) {
            db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?", ['proxy', 'tile_mode']);
            echo "[OK] upgraded empty tile_mode → 'proxy'.\n";
        } else {
            echo "[skip] tile_mode already set to '{$val}'.\n";
        }
    }
} catch (Exception $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
