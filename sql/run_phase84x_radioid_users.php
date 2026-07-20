<?php
/**
 * Phase 84x — radioid.net DMR user cache table.
 *
 * Purpose:  Local cache of (dmr_id → callsign, fname, surname, country)
 *           rows pulled from radioid.net. The radio widget's
 *           api/dmr-lookup.php falls through to this table when the
 *           local personnel roster (member_comm_identifiers) doesn't
 *           know a DMR ID. Aggressive caching is asked for by
 *           radioid.net's TOS — repeated lookups for the same operator
 *           must NOT re-hit their API.
 *
 * Population:  Two paths:
 *   - On-demand: api/dmr-lookup.php upserts a row each time it makes
 *     a live GET to https://database.radioid.net/api/dmr/user/?id=N
 *   - Bulk: tools/radioid_bulk_import.php pulls the full users.csv
 *     (~250k rows) and chunk-inserts. Admins run this once at install
 *     time and then maybe quarterly.
 *
 * Usage:        php sql/run_phase84x_radioid_users.php
 * Prereq:       config.php with valid database credentials.
 * Safety:       Idempotent. CREATE TABLE IF NOT EXISTS.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 84x — radioid.net DMR user cache\n";
echo "======================================\n\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}radioid_users` (
        `dmr_id`     BIGINT       NOT NULL,
        `callsign`   VARCHAR(16)  NOT NULL DEFAULT '',
        `fname`      VARCHAR(64)  NOT NULL DEFAULT '',
        `surname`    VARCHAR(64)  NOT NULL DEFAULT '',
        `country`    VARCHAR(64)  NOT NULL DEFAULT '',
        `state`      VARCHAR(64)  NOT NULL DEFAULT '',
        `city`       VARCHAR(64)  NOT NULL DEFAULT '',
        `fetched_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`dmr_id`),
        KEY `idx_callsign` (`callsign`),
        KEY `idx_fetched`  (`fetched_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='radioid.net DMR user cache (Phase 84x)'");
    echo "[OK] radioid_users table ready\n";
} catch (Exception $e) {
    echo "[ERR] radioid_users: " . $e->getMessage() . "\n";
    exit(1);
}

// Sanity check
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}radioid_users`");
    echo "[INFO] cache currently holds {$count} row(s)\n";
    if ($count === 0) {
        echo "[INFO] empty — admin may bulk-import via:\n";
        echo "       php tools/radioid_bulk_import.php\n";
        echo "       (otherwise the cache will populate opportunistically on\n";
        echo "        first lookup miss from api/dmr-lookup.php).\n";
    }
} catch (Exception $e) {
    // not fatal — table is there, just couldn't count
}

echo "\nDone.\n";
