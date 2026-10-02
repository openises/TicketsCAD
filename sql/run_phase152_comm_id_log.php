<?php
/**
 * Phase 152 (Communications Console v2) — prerequisite #2, station-ID
 * audit sink for comm_channels-scoped (non-DMR) autonomous transmissions.
 *
 * NOT a reuse of dmr_id_log/dmr_ptt_state (sql/run_phase148_fcc_station_id.php).
 * Those tables key `channel_id` as a plain INT with no FK, always populated
 * from dmr_channels.id. dmr_channels.id and comm_channels.id are
 * independent, uncoordinated AUTO_INCREMENT sequences -- writing
 * comm_channels.id values into the same column would let an unrelated DMR
 * channel and comm channel collide on the same numeric id and blend each
 * other's compliance history. Found during implementation, before any code
 * shipped that would have done this.
 *
 * Same shape as dmr_id_log/dmr_ptt_state deliberately -- this is a sibling
 * table, not a redesign. inc/fcc_station_id.php's two pure, table-agnostic
 * timing functions (fcc_may_transmit_without_id(), fcc_id_zone()) are
 * reused verbatim against this table's data via inc/comm_fcc_gate.php;
 * only the DB read/write wrappers are new.
 *
 * Scope: this is the audit sink for AUTONOMOUS transmissions only (a
 * coupling/patch relaying one operator's speech onto another channel with
 * no direct human PTT press on that leg) -- per specs/phase-152-comms-
 * console-v2/spec.md's prerequisite #2. A direct human PTT on a console
 * strip stays on the existing client-side gate model and does not write
 * here.
 *
 * Idempotent -- safe to run repeatedly; picked up by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- comm_id_log / comm_ptt_state\n";
echo "==========================================\n\n";

$stmts = [
    'comm_id_log' => "CREATE TABLE IF NOT EXISTS `{$prefix}comm_id_log` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `channel_id`   INT NOT NULL COMMENT 'comm_channels.id -- NOT dmr_channels.id',
        `user_id`      INT DEFAULT NULL COMMENT 'NULL for a system-initiated
                        (autonomous relay) ID event with no single operator
                        of record for this specific leg',
        `callsign`     VARCHAR(16) NOT NULL,
        `id_at`        DATETIME NOT NULL,
        `source`       ENUM('confirmed_tx','monitoring_id','end_of_conversation','autonomous_relay') NOT NULL
                       COMMENT 'autonomous_relay = a coupling/patch relay leg
                                 the matrix service itself keyed, with no
                                 direct human PTT press on this leg.',
        `notes`        VARCHAR(255) DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel_user_at` (`channel_id`, `user_id`, `id_at`),
        KEY `idx_callsign_at` (`callsign`, `id_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- station-ID
        event log for comm_channels-scoped autonomous transmissions. Sibling
        of dmr_id_log, deliberately NOT shared with it -- see the migration
        docblock. last_id_at is ALWAYS derived live as MAX(id_at), never
        cached. See inc/comm_fcc_gate.php.'",

    'comm_ptt_state' => "CREATE TABLE IF NOT EXISTS `{$prefix}comm_ptt_state` (
        `channel_id`               INT NOT NULL COMMENT 'comm_channels.id',
        `user_id`                  INT NOT NULL DEFAULT 0 COMMENT '0 for the
                                    autonomous-relay pseudo-operator row (no
                                    single human operator for this leg)',
        `last_tx_at`               DATETIME DEFAULT NULL
            COMMENT 'Informational only -- does NOT feed the compliance
                      check (that reads comm_id_log exclusively).',
        `conversation_started_at`  DATETIME DEFAULT NULL
            COMMENT 'Informational only. NULL = no open conversation.',
        `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`channel_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- per
        (comm_channel,operator) PTT bookkeeping, sibling of dmr_ptt_state.
        Deliberately does NOT store last_id_at -- see comm_id_log.'",
];

$ok = true;
foreach ($stmts as $name => $sql) {
    try {
        db_query($sql);
        echo "[OK] {$name} table ready\n";
    } catch (Exception $e) {
        fwrite(STDERR, "[FAIL] {$name}: " . $e->getMessage() . "\n");
        $ok = false;
    }
}

// Verify (Phase 128 A9b lesson).
foreach (array_keys($stmts) as $name) {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $name]
    );
    if ((int) $exists !== 1) {
        fwrite(STDERR, "[FAIL] verification: {$name} not found after migration\n");
        $ok = false;
    }
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
