<?php
/**
 * Phase 39B — Mesh channels (Meshtastic-compatible).
 *
 *   mesh_channels — per-org channel definitions with PSK + region.
 *   mesh_bridge_channels — which channels live on which bridge slot.
 *
 *   Meshtastic uses up to 8 channel slots (index 0..7). Slot 0 is "primary"
 *   and carries the channel-set sharing URL. PSK is a 1-, 16- or 32-byte
 *   key (32-byte = AES-256). We store as base64.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 39B — mesh_channels + mesh_bridge_channels\n";
echo "================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}mesh_channels` (
        `id`            INT NOT NULL AUTO_INCREMENT,
        `name`          VARCHAR(32)  NOT NULL,
        `psk_b64`       VARCHAR(64)  NOT NULL COMMENT 'base64-encoded PSK (1/16/32 bytes)',
        `region`        VARCHAR(16)  NULL DEFAULT 'US' COMMENT 'LoRa region preset',
        `modem_preset`  VARCHAR(32)  NULL DEFAULT 'LONG_FAST',
        `downlink_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `uplink_enabled`   TINYINT(1) NOT NULL DEFAULT 1,
        `is_primary`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Mark THE primary channel for the org',
        `notes`         TEXT NULL,
        `created_by`    INT NULL,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `archived_at`   DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_name` (`name`),
        KEY `idx_primary` (`is_primary`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] mesh_channels ready.\n";
} catch (Exception $e) {
    echo "[ERR] mesh_channels: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}mesh_bridge_channels` (
        `id`         INT NOT NULL AUTO_INCREMENT,
        `bridge_id`  INT NOT NULL,
        `channel_id` INT NOT NULL,
        `slot`       INT NOT NULL DEFAULT 0 COMMENT 'Meshtastic slot 0..7',
        `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_bridge_slot` (`bridge_id`, `slot`),
        KEY `idx_channel` (`channel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] mesh_bridge_channels ready.\n";
} catch (Exception $e) {
    echo "[ERR] mesh_bridge_channels: " . $e->getMessage() . "\n";
    exit(1);
}

// Seed a default "LongFast" channel matching Meshtastic default,
// so the install starts in a known-compatible state.
try {
    $existing = db_fetch_value("SELECT id FROM `{$prefix}mesh_channels` WHERE name = ? LIMIT 1", ['LongFast']);
    if (!$existing) {
        // Meshtastic default PSK (well-known, NOT a secret) — single byte 0x01 = "default"
        db_query("INSERT INTO `{$prefix}mesh_channels`
                    (name, psk_b64, region, modem_preset, is_primary, notes)
                  VALUES (?, ?, ?, ?, ?, ?)",
            ['LongFast', 'AQ==', 'US', 'LONG_FAST', 1,
             'Meshtastic public default — anyone can read. Replace with a private channel for org traffic.']);
        echo "[OK] Seeded 'LongFast' default channel.\n";
    } else {
        echo "[skip] LongFast channel already present.\n";
    }
} catch (Exception $e) {
    echo "[WARN] seed: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
