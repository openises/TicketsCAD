<?php
/**
 * Phase D (messaging-send-gaps-2026-06) — route sub-address + Zello outbox.
 *
 * Two schema additions, both idempotent + guarded, so the unified
 * broker/router can deliver a routing RULE to mesh + Zello for real
 * (instead of logging the old dead-stub `failed`):
 *
 *   1. message_routes.dest_subaddress_json
 *      A route's destination is still the flat `dest_channel` code, but
 *      mesh/Zello destinations carry a JSON sub-address alongside it:
 *
 *        mesh:meshtastic / mesh:meshcore →
 *            {"channel_slot":1}                          (channel broadcast)
 *            {"unit_id":42}  / {"member_id":7}           (resolved DM)
 *            {"to_node":"!a2a79f57"}                      (raw address DM)
 *        zello →
 *            {"channel":"Dispatch"}                       (channel text)
 *            {"user":"unit12"}                            (user DM — Phase E send)
 *
 *      A flat-channel route (local_chat, email, sms, slack, dmr) leaves
 *      this NULL and behaves exactly as before — backward compatible.
 *
 *   2. zello_outbox
 *      The Zello proxy is a long-running WebSocket daemon; the web
 *      process can't push into its event loop. So a routed Zello send is
 *      QUEUED here and the proxy drains it on a periodic loop timer
 *      (proxy/ZelloProxyApp::pollOutbox), then marks it sent/failed.
 *      This is the same poll-the-outbox contract the mesh bridges use —
 *      it does NOT fake success: status stays 'queued' until the proxy
 *      actually relays it (or 'failed' with an error if it can't).
 *
 * Safe to re-run. No data migration; new column defaults NULL, table is
 * CREATE TABLE IF NOT EXISTS.
 */
require_once __DIR__ . '/../config.php';

echo "Phase D — route sub-address + zello_outbox\n";
echo "==========================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _rsa_has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

function _rsa_add_col(string $table, string $col, string $ddl): void {
    global $prefix;
    if (_rsa_has_col($table, $col)) {
        echo "[OK] {$table}.{$col} already present\n";
        return;
    }
    try {
        db_query("ALTER TABLE `{$prefix}{$table}` ADD COLUMN {$ddl}");
        echo "[OK] added {$table}.{$col}\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            echo "[OK] {$table}.{$col} already present (race)\n";
        } else {
            echo "[FAIL] {$table}.{$col}: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// message_routes may not exist yet on a very fresh install; the router
// creates it lazily via _router_ensure_tables(). Ensure it first so the
// ALTER has a table to touch.
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/broker.php';
if (function_exists('_router_ensure_tables')) {
    _router_ensure_tables();
}

// ── 1. message_routes.dest_subaddress_json ──
_rsa_add_col(
    'message_routes',
    'dest_subaddress_json',
    "`dest_subaddress_json` TEXT DEFAULT NULL COMMENT 'JSON sub-address for mesh/zello destinations (channel_slot|unit_id|member_id|to_node|channel|user); NULL for flat channels'"
);

// ── 2. zello_outbox (proxy-drained outbound queue) ──
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}zello_outbox` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `kind`         VARCHAR(16) NOT NULL DEFAULT 'text',
        `channel`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Zello channel name (blank = default dispatch channel)',
        `recipient`    VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Zello user for a DM (blank = channel text)',
        `body`         TEXT NOT NULL,
        `status`       ENUM('queued','claimed','sent','failed') NOT NULL DEFAULT 'queued',
        `error`        VARCHAR(255) DEFAULT NULL,
        `queued_by`    INT UNSIGNED DEFAULT NULL,
        `source`       VARCHAR(32) NOT NULL DEFAULT 'router' COMMENT 'what queued it (router, api, ...)',
        `queued_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `claimed_at`   DATETIME DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`),
        KEY `idx_queued` (`queued_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] zello_outbox table ready\n";
} catch (Exception $e) {
    echo "[FAIL] zello_outbox: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
