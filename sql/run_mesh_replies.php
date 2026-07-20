<?php
/**
 * Phase C (messaging-send-gaps-2026-06) — mesh replies + threading + ACK.
 *
 * Adds the minimal columns to make an inbound mesh text reply-able and to
 * thread an inbound row → its reply → the reply's delivery status:
 *
 *   mesh_packet_log.channel_idx   — channel slot an inbound channel message
 *                                   arrived on, so a CHANNEL reply can target
 *                                   the same slot. NULL for direct messages.
 *
 *   mesh_outbox.in_reply_to_packet_id — links a queued reply back to the
 *                                   mesh_packet_log row it answers (the thread
 *                                   anchor). NULL for a non-reply send.
 *   mesh_outbox.thread_key        — a stable conversation key, e.g.
 *                                   "meshcore:dm:a1b2c3d4e5f6" or
 *                                   "meshtastic:chan:1", so a future
 *                                   conversation view can group a back-and-forth.
 *   mesh_outbox.ack_ms            — end-to-end round-trip ms reported by the
 *                                   bridge when the transport supplies a real
 *                                   delivery ACK (MeshCore). NULL when the
 *                                   transport gives no ACK or it hasn't arrived.
 *
 * All guarded — checks information_schema before each ADD COLUMN. Safe to
 * re-run. No data migration; new columns default NULL.
 */
require_once __DIR__ . '/../config.php';

echo "Phase C — mesh replies + threading + ACK columns\n";
echo "================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _mc_has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

function _mc_add_col(string $table, string $col, string $ddl): void {
    global $prefix;
    if (_mc_has_col($table, $col)) {
        echo "[OK] {$table}.{$col} already present\n";
        return;
    }
    try {
        db_query("ALTER TABLE `{$prefix}{$table}` ADD COLUMN {$ddl}");
        echo "[OK] added {$table}.{$col}\n";
    } catch (Exception $e) {
        // Tolerate a concurrent add ("Duplicate column name") — idempotent intent.
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            echo "[OK] {$table}.{$col} already present (race)\n";
        } else {
            echo "[FAIL] {$table}.{$col}: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// ── mesh_packet_log: channel_idx (origin slot for channel replies) ──
_mc_add_col(
    'mesh_packet_log',
    'channel_idx',
    "`channel_idx` TINYINT DEFAULT NULL COMMENT 'channel slot an inbound channel msg arrived on (NULL=DM)'"
);

// ── mesh_outbox: reply threading + ACK round-trip ──
_mc_add_col(
    'mesh_outbox',
    'in_reply_to_packet_id',
    "`in_reply_to_packet_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'mesh_packet_log.id this reply answers'"
);
_mc_add_col(
    'mesh_outbox',
    'thread_key',
    "`thread_key` VARCHAR(96) DEFAULT NULL COMMENT 'conversation key, e.g. meshcore:dm:<prefix> or meshtastic:chan:<slot>'"
);
_mc_add_col(
    'mesh_outbox',
    'ack_ms',
    "`ack_ms` INT DEFAULT NULL COMMENT 'end-to-end ACK round-trip ms (MeshCore); NULL = no ACK / not yet'"
);

// Helpful indexes for the thread/reply lookups (guarded).
function _mc_has_index(string $table, string $index): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
        [$prefix . $table, $index]
    );
}
if (!_mc_has_index('mesh_outbox', 'idx_in_reply_to')) {
    try {
        db_query("ALTER TABLE `{$prefix}mesh_outbox` ADD KEY `idx_in_reply_to` (`in_reply_to_packet_id`)");
        echo "[OK] added index mesh_outbox.idx_in_reply_to\n";
    } catch (Exception $e) {
        echo "[WARN] index idx_in_reply_to: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] index mesh_outbox.idx_in_reply_to already present\n";
}
if (!_mc_has_index('mesh_outbox', 'idx_thread_key')) {
    try {
        db_query("ALTER TABLE `{$prefix}mesh_outbox` ADD KEY `idx_thread_key` (`thread_key`)");
        echo "[OK] added index mesh_outbox.idx_thread_key\n";
    } catch (Exception $e) {
        echo "[WARN] index idx_thread_key: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
