<?php
/**
 * NewUI v4.0 — Broker + Chat schema migration
 *
 * Brings `messages` and `chat_messages` to the modern broker schema
 * that inc/broker.php and inc/channels/local_chat.php actually write.
 *
 * Idempotent: safe to run multiple times. Skips tables already on the
 * modern schema.
 *
 * Safety belt: before DROP, snapshots non-empty legacy tables to
 *   `messages_legacy_YYYYMMDD` / `chat_messages_legacy_YYYYMMDD`
 * so an operator can hand-restore rows if needed.
 *
 * Why this exists: the v3.44 → v4.0 column reshape was never written
 * before the broker code was, so broker.php and local_chat.php have
 * been writing into tables whose columns don't exist. Every INSERT
 * threw PDOException and was swallowed by a try/catch with no log
 * line. The v4.0 broker has never persisted a single message until
 * this migration runs.
 *
 * v3.44 legacy data migration is intentionally NOT done here. A
 * separate tool (tools/migrate_legacy_messages.php) maps v3.44's
 * 20-column messages table into the modern schema when an operator
 * upgrades a real v3.44 install. See that tool's docblock.
 *
 * Usage:
 *   php tools/fix_chat_tables.php
 */

require __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$today  = date('Ymd');

echo "=== Broker/Chat schema migration ===\n";

// ──────────────────────────────────────────────────────────────────
// chat_messages
// ──────────────────────────────────────────────────────────────────

$chatHasModernCols = false;
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}chat_messages`");
    $names = array_column($cols, 'Field');
    $chatHasModernCols = in_array('body', $names)
                      && in_array('user_name', $names)
                      && in_array('channel', $names);
} catch (Exception $e) {
    echo "  chat_messages does not exist yet\n";
}

if ($chatHasModernCols) {
    echo "[skip] chat_messages already on modern schema\n";
} else {
    // Safety-belt snapshot if the table exists and has rows
    try {
        $rows = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}chat_messages`");
    } catch (Exception $e) {
        $rows = 0;
    }
    if ($rows > 0) {
        $snap = "{$prefix}chat_messages_legacy_{$today}";
        echo "  chat_messages has {$rows} row(s) — snapshotting to {$snap}\n";
        try { db_query("DROP TABLE IF EXISTS `{$snap}`"); } catch (Exception $e) {}
        db_query("CREATE TABLE `{$snap}` LIKE `{$prefix}chat_messages`");
        db_query("INSERT INTO `{$snap}` SELECT * FROM `{$prefix}chat_messages`");
        echo "  [OK] snapshot complete\n";
    }

    echo "  recreating chat_messages with modern broker schema...\n";
    try { db_query("DROP TABLE IF EXISTS `{$prefix}chat_messages`"); } catch (Exception $e) {}
    db_query("CREATE TABLE `{$prefix}chat_messages` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`    INT NOT NULL DEFAULT 0,
        `user_name`  VARCHAR(64) NOT NULL DEFAULT 'system',
        `channel`    VARCHAR(64) NOT NULL DEFAULT 'general',
        `recipient`  VARCHAR(64) NOT NULL DEFAULT 'all',
        `body`       TEXT NOT NULL,
        `msg_type`   VARCHAR(32) NOT NULL DEFAULT 'text',
        `priority`   VARCHAR(16) NOT NULL DEFAULT 'normal',
        `ticket_id`  INT DEFAULT NULL,
        `signal_id`  INT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel`    (`channel`),
        KEY `idx_created`    (`created_at`),
        KEY `idx_ticket`     (`ticket_id`),
        KEY `idx_recipient`  (`recipient`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [OK] chat_messages recreated\n";
}

// ──────────────────────────────────────────────────────────────────
// messages (broker log)
// ──────────────────────────────────────────────────────────────────

$messagesHasModernCols = false;
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}messages`");
    $names = array_column($cols, 'Field');
    $messagesHasModernCols = in_array('channel', $names)
                          && in_array('direction', $names)
                          && in_array('body', $names)
                          && in_array('sender', $names);
} catch (Exception $e) {
    echo "  messages does not exist yet\n";
}

if ($messagesHasModernCols) {
    echo "[skip] messages already on modern broker schema\n";
} else {
    // Safety-belt snapshot — v3.44 installs may have years of message
    // history in the legacy 20-column shape that we must not lose.
    try {
        $rows = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}messages`");
    } catch (Exception $e) {
        $rows = 0;
    }
    if ($rows > 0) {
        $snap = "{$prefix}messages_legacy_{$today}";
        echo "  messages has {$rows} row(s) — snapshotting to {$snap}\n";
        echo "  Run tools/migrate_legacy_messages.php after this to import legacy rows into the new schema.\n";
        try { db_query("DROP TABLE IF EXISTS `{$snap}`"); } catch (Exception $e) {}
        db_query("CREATE TABLE `{$snap}` LIKE `{$prefix}messages`");
        db_query("INSERT INTO `{$snap}` SELECT * FROM `{$prefix}messages`");
        echo "  [OK] snapshot complete\n";
    }

    echo "  recreating messages with modern broker schema...\n";
    try { db_query("DROP TABLE IF EXISTS `{$prefix}messages`"); } catch (Exception $e) {}
    db_query("CREATE TABLE `{$prefix}messages` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `channel`      VARCHAR(64) NOT NULL,
        `direction`    ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
        `msg_type`     VARCHAR(32) NOT NULL DEFAULT 'general',
        `sender`       VARCHAR(128) NOT NULL DEFAULT 'system',
        `recipient`    VARCHAR(256) NOT NULL DEFAULT '',
        `subject`      VARCHAR(256) DEFAULT '',
        `body`         TEXT NOT NULL,
        `priority`     VARCHAR(16) NOT NULL DEFAULT 'normal',
        `status`       VARCHAR(32) NOT NULL DEFAULT 'pending',
        `error`        TEXT DEFAULT NULL,
        `payload`      TEXT DEFAULT NULL,
        `delivered_at` DATETIME DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel`   (`channel`),
        KEY `idx_status`    (`status`),
        KEY `idx_direction` (`direction`),
        KEY `idx_created`   (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [OK] messages recreated\n";
}

echo "=== migration complete ===\n";
