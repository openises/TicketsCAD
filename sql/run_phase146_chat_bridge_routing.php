<?php
/**
 * Phase 146 — Chat Bridge routing columns (GH#89, public repo).
 *
 * Adds `managed` and `managed_key` to `message_routes` so the four
 * "Bridge -> X" checkboxes in Settings > Chat Settings
 * (inc/chat-bridge.php's chat_bridge_sync()) can identify and own the
 * routing rules they create, without ever touching a rule an admin
 * created by hand via the Message Routing UI. Same pattern
 * inc/channel_registry.php already uses for `comm_channels`
 * (managed TINYINT + a stable key column).
 *
 * `managed_key` is nullable and carries a UNIQUE key — every existing
 * hand-created route has managed_key = NULL, and NULL is distinct in a
 * MySQL/MariaDB unique index (CLAUDE.md's Phase 129 lesson), so this adds
 * zero constraint on any pre-existing row.
 *
 * Usage: php sql/run_phase146_chat_bridge_routing.php
 * Prerequisites: config.php with valid database credentials.
 * Safety: idempotent -- checks information_schema before each ALTER.
 * Safe to re-run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/broker.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 146 — Chat Bridge routing columns (GH#89) ===\n\n";

// message_routes may not exist yet on a very fresh install; the router
// creates it lazily. Ensure it first so the ALTERs below have a table.
if (function_exists('_router_ensure_tables')) {
    _router_ensure_tables();
}

function _p146_has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

function _p146_has_key(string $table, string $keyName): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
        [$prefix . $table, $keyName]
    );
}

// ── message_routes.managed ──────────────────────────────────
if (_p146_has_col('message_routes', 'managed')) {
    echo "[OK] message_routes.managed already present\n";
} else {
    try {
        db_query(
            "ALTER TABLE `{$prefix}message_routes`
             ADD COLUMN `managed` TINYINT NOT NULL DEFAULT 0
             COMMENT 'system-managed row (1, e.g. a chat-bridge checkbox) vs hand-created by an admin (0)'"
        );
        echo "[OK] added message_routes.managed\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            echo "[OK] message_routes.managed already present (race)\n";
        } else {
            echo "[FAIL] message_routes.managed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// ── message_routes.managed_key ──────────────────────────────
if (_p146_has_col('message_routes', 'managed_key')) {
    echo "[OK] message_routes.managed_key already present\n";
} else {
    try {
        db_query(
            "ALTER TABLE `{$prefix}message_routes`
             ADD COLUMN `managed_key` VARCHAR(64) DEFAULT NULL
             COMMENT 'stable identity for a managed row, e.g. chat_bridge:slack -- never set on a hand-created row'"
        );
        echo "[OK] added message_routes.managed_key\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            echo "[OK] message_routes.managed_key already present (race)\n";
        } else {
            echo "[FAIL] message_routes.managed_key: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// ── uk_managed_key unique index ─────────────────────────────
if (_p146_has_key('message_routes', 'uk_managed_key')) {
    echo "[OK] message_routes.uk_managed_key already present\n";
} else {
    try {
        db_query("ALTER TABLE `{$prefix}message_routes` ADD UNIQUE KEY `uk_managed_key` (`managed_key`)");
        echo "[OK] added message_routes.uk_managed_key\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate key') !== false || stripos($e->getMessage(), 'already exists') !== false) {
            echo "[OK] message_routes.uk_managed_key already present (race)\n";
        } else {
            echo "[FAIL] message_routes.uk_managed_key: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// ── Sync the four chat-bridge routing rules to current settings ────
// Safe to run on every migration pass -- chat_bridge_sync() is
// idempotent and reads live settings values (see inc/chat-bridge.php).
// On a fresh install all four chat_bridge_* settings default unset
// (=> "off"), so this is a no-op there; on an upgrade it picks up
// whatever an admin already had checked, which had been inert until now.
require_once __DIR__ . '/../inc/chat-bridge.php';
try {
    $sync = chat_bridge_sync();
    echo "[OK] chat_bridge_sync: created={$sync['created']} enabled={$sync['enabled']} disabled={$sync['disabled']}\n";
} catch (Exception $e) {
    echo "[WARN] chat_bridge_sync failed: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
