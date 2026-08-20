<?php
/**
 * Phase 145 (GH #84, 2026-08-19) — widen notification_rules.channel from
 * ENUM('email','sms','local_chat','all') to VARCHAR(20).
 *
 * A notification rule could never target Slack, Telegram, push, APRS, DMR,
 * Meshtastic, MeshCore, or SMTP — not because inc/broker.php's dispatch
 * (broker_send()) can't reach them (it's fully generic against every
 * channel registered in inc/channels/*.php, 11 today), but because the
 * ENUM on notification_rules.channel physically could not store any value
 * outside its four literals. On a strict-mode connection (NewUI's default —
 * see inc/db.php, no sql_mode override) an INSERT of 'slack' fails outright
 * with "Data truncated for column 'channel'"; the column can never silently
 * accept it either way.
 *
 * sql/notification_rules.sql now defines the column as VARCHAR(20) NOT NULL
 * DEFAULT 'email' for fresh installs — this script brings an EXISTING
 * install's table to the same shape. Widening an ENUM to VARCHAR preserves
 * every existing row's value as a plain string; no data is lost or
 * rewritten.
 *
 * Idempotent — skips the ALTER if the column is already something other
 * than ENUM (i.e. already migrated). Safe to re-run.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 145 (GH #84) — widen notification_rules.channel\n";
echo "=======================================================\n\n";

try {
    $row = db_fetch_one(
        "SELECT DATA_TYPE, COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'channel'",
        [$prefix . 'notification_rules']
    );

    if (!$row) {
        echo "[--] {$prefix}notification_rules.channel column not present"
            . " (table missing — inc/notification-engine.php creates it"
            . " lazily on first use) — nothing to widen yet.\n";
    } else {
        $type = strtolower((string) $row['DATA_TYPE']);
        if ($type !== 'enum') {
            echo "[--] {$prefix}notification_rules.channel is already"
                . " {$row['COLUMN_TYPE']} — nothing to do.\n";
        } else {
            db_query(
                "ALTER TABLE `{$prefix}notification_rules`"
                . " MODIFY COLUMN `channel` VARCHAR(20) NOT NULL DEFAULT 'email'"
            );
            echo "[OK] {$prefix}notification_rules.channel widened from"
                . " {$row['COLUMN_TYPE']} to VARCHAR(20). Existing values"
                . " (email/sms/local_chat/all) are preserved as plain"
                . " strings — rules can now also target slack, telegram,"
                . " push, aprs, dmr, meshtastic, meshcore, and smtp.\n";
        }
    }
} catch (Throwable $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}

echo "\nDone.\n";
