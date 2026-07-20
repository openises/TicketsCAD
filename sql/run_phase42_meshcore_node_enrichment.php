<?php
/**
 * Phase 42 — Enrich mesh_nodes with MeshCore-specific node-info fields.
 *
 * Today mesh_nodes captures the lowest common denominator (node id, short/long
 * names, hw model, role, last position). MeshCore exposes a lot more in its
 * self_info payload, and we want to record it so the Mesh Console can show
 * what each radio is doing — which channel it's on, what radio params, what
 * firmware, etc.
 *
 * Additive columns only — no destructive changes. Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 42 — MeshCore node enrichment\n";
echo "===================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

// MeshCore-specific identity fields.
$additions = [
    'public_key'    => "VARCHAR(128) NULL COMMENT 'MeshCore: 64-char hex public key (full identity)'",
    'firmware_ver'  => "VARCHAR(64) NULL COMMENT 'Firmware version string reported by the radio'",
    'manuf_name'    => "VARCHAR(64) NULL COMMENT 'MeshCore manuf_name field'",
    'adv_type'      => "TINYINT UNSIGNED NULL COMMENT 'MeshCore advertisement type (1=companion)'",
    'radio_freq'    => "DECIMAL(8,3) NULL COMMENT 'MHz, e.g. 910.525'",
    'radio_bw'      => "DECIMAL(6,1) NULL COMMENT 'kHz, e.g. 250.0'",
    'radio_sf'      => "TINYINT UNSIGNED NULL COMMENT 'Spreading factor 7..12'",
    'radio_cr'      => "TINYINT UNSIGNED NULL COMMENT 'Coding rate (4/5..4/8 → 5..8)'",
    'tx_power'      => "SMALLINT NULL COMMENT 'Configured TX power, dBm'",
    'max_tx_power'  => "SMALLINT NULL COMMENT 'Hardware max TX power, dBm'",
    'adv_lat'       => "DECIMAL(10,6) NULL COMMENT 'MeshCore: advertised lat from beacon (0,0 = unset)'",
    'adv_lon'       => "DECIMAL(10,6) NULL COMMENT 'MeshCore: advertised lon from beacon (0,0 = unset)'",
    'is_self'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'This row is the bridge-attached radio'",
    'self_info_at'  => "DATETIME(3) NULL COMMENT 'Last time self_info was refreshed'",
];

foreach ($additions as $col => $def) {
    if (!_has_col('mesh_nodes', $col)) {
        try {
            db_query("ALTER TABLE `{$prefix}mesh_nodes` ADD COLUMN `{$col}` {$def}");
            echo "[OK] mesh_nodes.{$col} added\n";
        } catch (Exception $e) {
            echo "[WARN] mesh_nodes.{$col}: " . $e->getMessage() . "\n";
        }
    }
}

// Index on public_key — MeshCore identifies nodes by it, not by short hex prefix.
$hasIdx = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_public_key'",
    [$prefix . 'mesh_nodes']
);
if (!$hasIdx) {
    try {
        db_query("ALTER TABLE `{$prefix}mesh_nodes` ADD INDEX `idx_public_key` (`public_key`(32))");
        echo "[OK] mesh_nodes.idx_public_key added\n";
    } catch (Exception $e) {
        echo "[WARN] idx_public_key: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
