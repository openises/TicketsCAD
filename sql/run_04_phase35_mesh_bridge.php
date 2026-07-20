<?php
/**
 * Phase 35 (2026-06-12) — mesh bridge schema.
 *
 * Tables to support remote LoRa-mesh bridges (Meshtastic / MeshCore)
 * connecting to TicketsCAD with:
 *   * mesh_bridges          — registered bridge endpoints (one per remote
 *                             workstation / Pi that has a radio attached)
 *   * bridge_tokens         — long-lived bearer tokens (SHA-256 hashed)
 *   * mesh_packet_log       — every packet a bridge has reported, for
 *                             the admin console + coverage / latency view
 *   * mesh_outbox           — outbound work queued by CAD for a bridge
 *                             (send-text, set-config). Bridge polls.
 *
 * Idempotent. Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 35 — mesh bridge schema\n";
echo "==============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function ensure_table(string $sql, string $name): void {
    try {
        db_query($sql);
        echo "[OK] $name\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'already exists') !== false) {
            echo "[OK] $name already exists\n";
        } else {
            echo "[FAIL] $name: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

ensure_table(
    "CREATE TABLE IF NOT EXISTS `{$prefix}mesh_bridges` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `label` VARCHAR(64) NOT NULL,
        `host_hint` VARCHAR(128) DEFAULT NULL,
        `notes` VARCHAR(255) DEFAULT NULL,
        `last_seen_at` DATETIME DEFAULT NULL,
        `last_packet_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_by` INT DEFAULT NULL,
        `revoked_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_last_seen` (`last_seen_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "mesh_bridges"
);

ensure_table(
    "CREATE TABLE IF NOT EXISTS `{$prefix}bridge_tokens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bridge_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_by` INT DEFAULT NULL,
        `last_used_at` DATETIME DEFAULT NULL,
        `revoked_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_token_hash` (`token_hash`),
        KEY `idx_bridge` (`bridge_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "bridge_tokens"
);

ensure_table(
    "CREATE TABLE IF NOT EXISTS `{$prefix}mesh_packet_log` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `bridge_id` INT UNSIGNED NOT NULL,
        `protocol` ENUM('meshtastic','meshcore') NOT NULL,
        `packet_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'protocol packet id for dedup across bridges',
        `src_node` VARCHAR(48) DEFAULT NULL,
        `dst_node` VARCHAR(48) DEFAULT NULL,
        `port_kind` VARCHAR(32) DEFAULT NULL COMMENT 'TEXT, POSITION, NODEINFO, TELEMETRY, etc.',
        `snr` DECIMAL(5,2) DEFAULT NULL,
        `rssi` INT DEFAULT NULL,
        `hops` TINYINT DEFAULT NULL,
        `payload_text` VARCHAR(512) DEFAULT NULL,
        `payload_json` TEXT DEFAULT NULL,
        `lat` DECIMAL(10,6) DEFAULT NULL,
        `lng` DECIMAL(10,6) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_received` (`received_at`),
        KEY `idx_bridge_received` (`bridge_id`, `received_at`),
        KEY `idx_packet_id` (`packet_id`),
        KEY `idx_src_node` (`src_node`, `received_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "mesh_packet_log"
);

ensure_table(
    "CREATE TABLE IF NOT EXISTS `{$prefix}mesh_outbox` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `queued_by` INT DEFAULT NULL,
        `target_bridge_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = any bridge that picks it up first',
        `target_protocol` ENUM('meshtastic','meshcore','any') NOT NULL DEFAULT 'any',
        `kind` VARCHAR(32) NOT NULL COMMENT 'send_text, set_owner, set_channel, set_region, reboot',
        `payload_json` TEXT NOT NULL,
        `status` ENUM('queued','claimed','sent','failed') NOT NULL DEFAULT 'queued',
        `claimed_at` DATETIME DEFAULT NULL,
        `claimed_by_bridge_id` INT UNSIGNED DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `result_json` TEXT DEFAULT NULL,
        `error` VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`),
        KEY `idx_target` (`target_bridge_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "mesh_outbox"
);

// Seed default RBAC perm for mesh admin.
try {
    $exists = db_fetch_one(
        "SELECT id FROM `{$prefix}permissions` WHERE code = 'action.manage_mesh_bridges' LIMIT 1"
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}permissions` (code, name, category, resource, verb, description)
             VALUES ('action.manage_mesh_bridges',
                     'Manage mesh bridges',
                     'action', 'mesh_bridge', 'manage',
                     'Mint bridge tokens, view all bridges, send to mesh, configure remote devices.')"
        );
        echo "[OK] Added permission action.manage_mesh_bridges\n";

        // Grant to super-admin roles automatically.
        db_query(
            "INSERT INTO `{$prefix}role_permissions` (role_id, permission_id)
             SELECT r.id, p.id
               FROM `{$prefix}roles` r
               JOIN `{$prefix}permissions` p
                 ON p.code = 'action.manage_mesh_bridges'
              WHERE r.is_super = 1
                AND NOT EXISTS (
                    SELECT 1 FROM `{$prefix}role_permissions` rp
                     WHERE rp.role_id = r.id AND rp.permission_id = p.id
                )"
        );
        echo "[OK] Granted action.manage_mesh_bridges to super-admin roles\n";
    } else {
        echo "[OK] action.manage_mesh_bridges already exists\n";
    }
} catch (Exception $e) {
    echo "[WARN] RBAC seed: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
