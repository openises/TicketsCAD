<?php
/**
 * Phase 73i migration — DVSwitch DMR proxy tables
 *
 * Schema for the DVSwitch DMR voice bridge (spec at
 * specs/dvswitch-proxy-2026-06/spec.md). Two new tables:
 *
 *   dmr_channels  — one row per linked-talkgroup configuration
 *                   (BrandMeister TG <-> TicketsCAD chat channel).
 *                   Each row stores the bridge endpoint, bearer token
 *                   for auth, USRP port pair, link mode (rx-only / tx-
 *                   only / bidirectional), and TTS/STT engine choice.
 *
 *   dmr_messages  — RX/TX audit log mirroring the broker pattern.
 *                   Stores transcripts (STT result for RX, TTS source
 *                   text for TX), audio-file paths (optional), the
 *                   linked-channel id, the route direction, and the
 *                   timestamp range of the call.
 *
 * Both tables are idempotent: CREATE IF NOT EXISTS plus column-add
 * guards so re-running this migration on an install where someone
 * has already added columns by hand doesn't fail.
 *
 * Suggested cadence: run once during initial DVSwitch deployment.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 73i — DVSwitch DMR proxy schema\n";
echo "=====================================\n\n";

$stmts = [
    "CREATE TABLE IF NOT EXISTS `{$prefix}dmr_channels` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `label`           VARCHAR(96) NOT NULL,
        `talkgroup`       VARCHAR(32) NOT NULL,
        `network`         VARCHAR(64) NOT NULL DEFAULT 'BrandMeister',
        `bridge_host`     VARCHAR(128) NOT NULL,
        `bridge_port`     INT NOT NULL DEFAULT 5000,
        `bridge_token`    CHAR(64) NOT NULL,
        `usrp_listen_port` INT NOT NULL,
        `usrp_send_port`   INT NOT NULL,
        `link_mode`       ENUM('rx_only','tx_only','bidirectional')
                          NOT NULL DEFAULT 'rx_only',
        `chat_channel`    VARCHAR(64) NOT NULL DEFAULT 'dispatch',
        `tts_engine`      VARCHAR(32) DEFAULT NULL,
        `tts_voice`       VARCHAR(96) DEFAULT NULL,
        `stt_engine`      VARCHAR(32) DEFAULT NULL,
        `stt_partials`    TINYINT(1) NOT NULL DEFAULT 0,
        `route_to_broker` TINYINT(1) NOT NULL DEFAULT 1,
        `enabled`         TINYINT(1) NOT NULL DEFAULT 0,
        `last_seen_at`    DATETIME DEFAULT NULL,
        `last_error`      TEXT DEFAULT NULL,
        `created_by`      INT DEFAULT NULL,
        `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_label` (`label`),
        UNIQUE KEY `uniq_usrp_listen` (`usrp_listen_port`),
        KEY `idx_enabled_tg` (`enabled`, `talkgroup`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `{$prefix}dmr_messages` (
        `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
        `channel_id`      INT NOT NULL,
        `direction`       ENUM('rx','tx') NOT NULL,
        `call_started_at` DATETIME NOT NULL,
        `call_ended_at`   DATETIME DEFAULT NULL,
        `duration_ms`     INT DEFAULT NULL,
        `talkgroup`       VARCHAR(32) DEFAULT NULL,
        `radio_id`        VARCHAR(32) DEFAULT NULL,
        `radio_callsign`  VARCHAR(32) DEFAULT NULL,
        `member_id`       INT DEFAULT NULL,
        `transcript`      TEXT DEFAULT NULL,
        `transcript_engine` VARCHAR(32) DEFAULT NULL,
        `transcript_partials` TEXT DEFAULT NULL,
        `audio_path`      VARCHAR(255) DEFAULT NULL,
        `audio_format`    VARCHAR(16) DEFAULT NULL,
        `routed_to`       VARCHAR(128) DEFAULT NULL,
        `ticket_id`       INT DEFAULT NULL,
        `error`           TEXT DEFAULT NULL,
        `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel_time` (`channel_id`, `call_started_at`),
        KEY `idx_radio_id` (`radio_id`),
        KEY `idx_member` (`member_id`),
        KEY `idx_ticket` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($stmts as $i => $sql) {
    echo "[" . ($i + 1) . "/" . count($stmts) . "] ";
    try {
        db_query($sql);
        $first = trim(strtok($sql, "\n"));
        echo "OK: " . substr($first, 0, 60) . "...\n";
    } catch (Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

// Backfill: add new columns idempotently in case someone created the
// tables via an earlier draft and is rerunning.
$additions = [
    ['dmr_channels', 'tts_voice',          "VARCHAR(96) DEFAULT NULL AFTER `tts_engine`"],
    ['dmr_channels', 'route_to_broker',    "TINYINT(1) NOT NULL DEFAULT 1 AFTER `stt_partials`"],
    ['dmr_messages', 'transcript_engine',  "VARCHAR(32) DEFAULT NULL AFTER `transcript`"],
    ['dmr_messages', 'transcript_partials',"TEXT DEFAULT NULL AFTER `transcript_engine`"],
];
echo "\n-- Column backfill\n";
foreach ($additions as [$tbl, $col, $def]) {
    try {
        $exists = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?",
            [$prefix . $tbl, $col]
        );
        if ($exists) {
            echo "  [skip] {$tbl}.{$col} already present\n";
        } else {
            db_query("ALTER TABLE `{$prefix}{$tbl}` ADD COLUMN `{$col}` {$def}");
            echo "  [add ] {$tbl}.{$col}\n";
        }
    } catch (Exception $e) {
        echo "  [err ] {$tbl}.{$col}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
