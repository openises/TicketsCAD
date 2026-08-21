<?php
/**
 * Phase 148 (2026-08-20) — FCC 97.119 amateur station-ID enforcement for
 * the live DMR/BrandMeister radio widget.
 *
 * Closes specs/SPEC-STATUS.md section B3: `assets/js/console.js` renders an
 * "AMATEUR -- station ID required" badge and the system-generated TX paths
 * (inc/weather_alerts.php's weather_tts_callsign, inc/radio_ai_client.php's
 * radio_ai_filter_response()) correctly suffix an ID, but nothing tracked or
 * enforced identification for a live human dispatcher keying up the radio
 * widget. specs/phase-85e-fcc-station-id/spec.md is the original design for
 * this exact gap; this migration + inc/fcc_station_id.php + api/dmr-station-id.php
 * + the assets/js/radio-widget.js wiring implement it end to end.
 *
 * Timing model follows the fcc-amateur-station-id skill precisely: the
 * regulated unit is the CONVERSATION, not the transmission; the 10-minute
 * clock is anchored to last_id_at (never last TX, never conversation
 * start); the clock creates an obligation on the operator's NEXT
 * transmission, not a background alarm -- silence past the 10-minute mark
 * is legal. See inc/fcc_station_id.php's own docblock for the full model
 * and tests/test_fcc_station_id_timing.php for the worked examples proven
 * against it.
 *
 * Three schema changes, all idempotent (safe to re-run):
 *
 *   1. dmr_channels.id_interval_seconds -- the actual regulatory ID
 *      interval in seconds for this channel (default 600 = the FCC's
 *      10-minute maximum; an admin MAY tighten it for a safety margin,
 *      never widen it past 600).
 *   2. dmr_channels.id_enforce -- 'off' (no ID UI/tracking -- reserved for
 *      a future non-RF-linked DMR channel; every dmr_channels row today is
 *      BrandMeister-linked amateur RF per inc/channel_registry.php's
 *      'dmr_bm' adapter, which is unconditionally regulatory_class=amateur),
 *      'soft' (default -- countdown + banner, never blocks a transmission),
 *      'hard' (requires an explicit operator acknowledgment before a
 *      transmission proceeds when the timer has expired -- NOT an
 *      unconditional server-side block, deliberately, so the software can
 *      never suppress legitimate emergency traffic; see
 *      inc/fcc_station_id.php's docblock and the phase-85e spec's own risk
 *      register, "Soft enforcement initially so we don't block legitimate
 *      emergency traffic").
 *   3. dmr_id_log -- append-only log of every station-ID event (operator
 *      self-confirmed transmission, Monitoring ID, End-conversation
 *      closing ID). This is the ONLY source of last_id_at -- read live via
 *      MAX(id_at), never cached in a separate column, matching this
 *      project's own established "ask the database, don't trust a
 *      tracker" discipline (Phase 129/143's read-time-derivation lesson).
 *   4. dmr_ptt_state -- one row per (channel, operator): last_tx_at
 *      (informational) and conversation_started_at (informational, for
 *      the widget's "Conversation: MM:SS elapsed" display). Neither column
 *      feeds the compliance check -- that reads dmr_id_log exclusively.
 *
 * Usage: php sql/run_phase148_fcc_station_id.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
$dbInc = file_exists(__DIR__ . '/../inc/db.inc.php') ? __DIR__ . '/../inc/db.inc.php' : __DIR__ . '/../inc/db.php';
require_once $dbInc;

echo "Phase 148 -- FCC 97.119 station-ID enforcement (schema)\n";
echo "=========================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p148_col_exists(string $t, string $c): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $t, $c]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

function _p148_table_exists(string $t): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $t]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

// ── 1/2. dmr_channels columns ───────────────────────────────────────────
if (!_p148_table_exists('dmr_channels')) {
    echo "[WARN] dmr_channels table does not exist yet -- run sql/run_phase73i_dvswitch_schema.php first.\n";
} else {
    $columns = [
        'id_interval_seconds' => "ADD COLUMN `id_interval_seconds` INT NOT NULL DEFAULT 600
            COMMENT 'Phase 148 -- FCC 97.119 max seconds between station IDs
                      during a continuous conversation (600 = the regulatory
                      10-minute maximum). Also used as the conversation-gap
                      threshold for the informational conversation_started_at
                      marker.' AFTER `enabled`",
        'id_enforce' => "ADD COLUMN `id_enforce` ENUM('off','soft','hard') NOT NULL DEFAULT 'soft'
            COMMENT 'Phase 148 -- off=no ID UI/tracking, soft=countdown+banner
                      only (default, never blocks a TX), hard=require an
                      explicit operator acknowledgment before a TX proceeds
                      when the timer has expired. Never an unconditional
                      server-side block -- see inc/fcc_station_id.php.'
            AFTER `id_interval_seconds`",
    ];
    foreach ($columns as $col => $ddl) {
        if (_p148_col_exists('dmr_channels', $col)) {
            echo "[OK] dmr_channels.{$col} already exists\n";
            continue;
        }
        try {
            db_query("ALTER TABLE `{$prefix}dmr_channels` {$ddl}");
            echo "[OK] Added dmr_channels.{$col}\n";
        } catch (Exception $e) {
            echo "[WARN] dmr_channels.{$col}: " . $e->getMessage() . "\n";
        }
    }
}

// ── 3. dmr_id_log ────────────────────────────────────────────────────────
try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}dmr_id_log` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `channel_id`   INT NOT NULL,
            `user_id`      INT NOT NULL,
            `callsign`     VARCHAR(16) NOT NULL,
            `id_at`        DATETIME NOT NULL,
            `source`       ENUM('confirmed_tx','monitoring_id','end_of_conversation') NOT NULL
                           COMMENT 'confirmed_tx = operator self-confirmed their
                                     live TX included the callsign (no STT in
                                     this phase -- Phase 85e-5/Whisper is
                                     deferred); monitoring_id/end_of_conversation
                                     = system-fired standalone TTS ID the
                                     bridge actually transmitted.',
            `notes`        VARCHAR(255) DEFAULT NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_channel_user_at` (`channel_id`, `user_id`, `id_at`),
            KEY `idx_callsign_at` (`callsign`, `id_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 148 -- FCC 97.119
            station-ID event log. last_id_at is ALWAYS derived live as
            MAX(id_at) per (channel_id,user_id) -- never cached in a separate
            column. See inc/fcc_station_id.php:fcc_last_id_at().'"
    );
    echo "[OK] dmr_id_log table ready\n";
} catch (Exception $e) {
    echo "[WARN] dmr_id_log: " . $e->getMessage() . "\n";
}

// ── 4. dmr_ptt_state ─────────────────────────────────────────────────────
try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}dmr_ptt_state` (
            `channel_id`               INT NOT NULL,
            `user_id`                  INT NOT NULL,
            `last_tx_at`               DATETIME DEFAULT NULL
                COMMENT 'Informational only -- does NOT feed the compliance
                          check (that reads dmr_id_log exclusively). Used for
                          the End-conversation button''s \"did the most recent
                          TX already carry an ID\" test and the widget''s
                          conversation-elapsed display.',
            `conversation_started_at`  DATETIME DEFAULT NULL
                COMMENT 'Informational only, per the fcc-amateur-station-id
                          skill: \"Conversation state -- informational, NOT
                          controlling compliance.\" NULL = no open
                          conversation.',
            `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`channel_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 148 -- per
            (channel,operator) PTT bookkeeping for the FCC station-ID widget.
            Deliberately does NOT store last_id_at -- see dmr_id_log.'"
    );
    echo "[OK] dmr_ptt_state table ready\n";
} catch (Exception $e) {
    echo "[WARN] dmr_ptt_state: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
