<?php
/**
 * Found while chasing the same class of bug this session already fixed
 * three times over (see sql/run_gh115_org_id_columns.php,
 * sql/run_00_rbac.php, sql/run_zzz_admin_only_reconcile.php) -- a
 * migration-ordering gap, this time in the FCC station-ID feature,
 * unrelated to any of those in subject matter but identical in shape.
 *
 * sql/run_migrations.php discovers run_*.php scripts and sorts them with
 * ksort() -- a plain STRING comparison, not numeric. "run_phase148_..."
 * sorts BEFORE "run_phase73i_..." because '1' < '7' as the first
 * differing character, even though 148 > 73 as a number. But it's
 * run_phase73i_dvswitch_schema.php that creates the dmr_channels table
 * in the first place -- run_phase148_fcc_station_id.php just ADDS two
 * columns to it (id_interval_seconds, id_enforce).
 *
 * run_phase148_fcc_station_id.php already defends against this with a
 * table-existence check (_p148_table_exists('dmr_channels')) -- but on
 * finding the table missing, it prints a [WARN] and moves on, never
 * failing. That's the right call for THIS script (a hard failure here
 * would abort sql/run_migrations.php's entire pass partway through,
 * exactly the worse-than-the-original-bug outcome already documented in
 * sql/run_gh115_org_id_columns.php's own docblock) -- but it means the
 * script is marked "applied" regardless, and sql/run_migrations.php
 * never revisits an already-applied script on its own. So on a
 * genuinely fresh install, dmr_channels.id_interval_seconds/id_enforce
 * are silently never added, permanently -- confirmed live: CI's
 * "Migrations are idempotent" step reports every migration applied, 0
 * pending, immediately before tests/test_fcc_station_id_integration.php
 * fails with "Unknown column 'id_interval_seconds'".
 *
 * This migration is deliberately named to sort LAST among all run_*.php
 * scripts (ksort() order) -- same fix shape as
 * sql/run_zzz_admin_only_reconcile.php -- so it always runs after
 * run_phase73i_dvswitch_schema.php has had its turn, regardless of
 * which of the two ran "first" under lexicographic sort. Idempotent:
 * re-applies the identical column-add logic run_phase148 already has.
 *
 * Usage: php sql/run_zzz_fcc_station_id_columns_reconcile.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
$dbInc = file_exists(__DIR__ . '/../inc/db.inc.php') ? __DIR__ . '/../inc/db.inc.php' : __DIR__ . '/../inc/db.php';
require_once $dbInc;

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p148r_col_exists(string $prefix, string $t, string $c): bool {
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $t, $c]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

try {
    $tableExists = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'dmr_channels']
    );
    if (!$tableExists) {
        echo "[SKIP] {$prefix}dmr_channels does not exist yet -- run_phase73i_dvswitch_schema.php hasn't run yet on this install\n";
        echo "Done.\n";
        exit(0);
    }

    // Same two columns, same DDL, as run_phase148_fcc_station_id.php --
    // kept in sync deliberately; this is a reconciliation of the SAME
    // schema change, not a different one.
    $columns = [
        'id_interval_seconds' => "ADD COLUMN `id_interval_seconds` INT NOT NULL DEFAULT 600
            COMMENT 'Phase 148 -- FCC 97.119 max seconds between station IDs
                      during a continuous conversation (600 = the regulatory
                      10-minute maximum).' AFTER `enabled`",
        'id_enforce' => "ADD COLUMN `id_enforce` ENUM('off','soft','hard') NOT NULL DEFAULT 'soft'
            COMMENT 'Phase 148 -- off=no ID UI/tracking, soft=countdown+banner
                      only (default), hard=require operator acknowledgment.'
            AFTER `id_interval_seconds`",
    ];
    foreach ($columns as $col => $ddl) {
        if (_p148r_col_exists($prefix, 'dmr_channels', $col)) {
            echo "[SKIP] {$prefix}dmr_channels.{$col} already exists\n";
            continue;
        }
        db_query("ALTER TABLE `{$prefix}dmr_channels` {$ddl}");
        echo "[OK] Added {$prefix}dmr_channels.{$col}\n";
    }
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
