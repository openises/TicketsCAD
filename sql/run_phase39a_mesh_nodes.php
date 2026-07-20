<?php
/**
 * Phase 39A — Mesh node identity tracking.
 *
 *   mesh_nodes — one row per known node (long_name, short_name, hw, last pos).
 *
 * The mesh_packet_log table already has src_node (string like !abcdef12) which
 * matches mesh_nodes.node_id. Adding a column for friendly display name on the
 * log too so we can show it without a JOIN on the busy live-feed path.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 39A — mesh_nodes + display_name column\n";
echo "============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

// 1. mesh_nodes table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}mesh_nodes` (
        `node_id`     VARCHAR(32)   NOT NULL,
        `protocol`    VARCHAR(16)   NULL,
        `bridge_id`   INT           NULL COMMENT 'Most recent bridge that heard this node',
        `short_name`  VARCHAR(32)   NULL,
        `long_name`   VARCHAR(128)  NULL,
        `hw_model`    VARCHAR(64)   NULL,
        `role`        VARCHAR(32)   NULL COMMENT 'Meshtastic role: CLIENT/ROUTER/REPEATER/...',
        `last_lat`    DOUBLE        NULL,
        `last_lng`    DOUBLE        NULL,
        `last_alt_m`  INT           NULL,
        `last_snr`    FLOAT         NULL,
        `last_rssi`   INT           NULL,
        `last_hops`   INT           NULL,
        `last_seen_at` DATETIME(3)  NULL,
        `first_seen_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `notes`       TEXT          NULL,
        PRIMARY KEY (`node_id`),
        KEY `idx_last_seen` (`last_seen_at`),
        KEY `idx_bridge` (`bridge_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] mesh_nodes table ready.\n";
} catch (Exception $e) {
    echo "[ERR] mesh_nodes: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Display-name column on the log table (denormalized for fast feed render)
if (!_has_col('mesh_packet_log', 'display_name')) {
    try {
        db_query("ALTER TABLE `{$prefix}mesh_packet_log`
                  ADD COLUMN `display_name` VARCHAR(128) NULL AFTER `src_node`");
        echo "[OK] Added mesh_packet_log.display_name.\n";
    } catch (Exception $e) {
        echo "[WARN] display_name column: " . $e->getMessage() . "\n";
    }
} else {
    echo "[skip] mesh_packet_log.display_name already exists.\n";
}

echo "\nDone.\n";
