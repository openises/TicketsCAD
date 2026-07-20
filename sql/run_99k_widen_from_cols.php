<?php
/**
 * Phase 99k (2026-06-29) — widen all narrow legacy `_from` columns
 * to varchar(45) so the import path can store proxy-aware client IPs
 * without truncation.
 *
 * Bug origin: Billy Irwin's beta email 2026-06-29 — importing 59
 * personnel records each returned
 *
 *   Row 2: SQLSTATE[22001]: String data, right truncated:
 *          1406 Data too long for column '_from' at row 1
 *
 * Root cause: `member._from` was `varchar(10)`, but inc/import-export.php
 * writes `$_SERVER['REMOTE_ADDR']` into it (e.g. an LAN IP like
 * "192.168.1.100" = 13 chars). The legacy convention was a short
 * source-tag like "seed" (4 chars), which fit in 10. Modern import
 * code introduced a unit mismatch.
 *
 * Fix: widen every `_from` column under 45 chars (IPv6 + IPv4-mapped
 * max length) to varchar(45). varchar widening in MariaDB is a fast
 * metadata operation in most cases; safe for live tables. The
 * accompanying code patch (inc/import-export.php) also adds a
 * defensive substr() and switches to the proxy-aware client_ip().
 *
 * Idempotent. Run with: php sql/run_99k_widen_from_cols.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$TARGET_WIDTH = 45;   // IPv6 mapped-IPv4 max — covers every realistic IP

try {
    $narrow = db_fetch_all(
        "SELECT TABLE_NAME, CHARACTER_MAXIMUM_LENGTH AS width
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND COLUMN_NAME = '_from'
            AND DATA_TYPE = 'varchar'
            AND CHARACTER_MAXIMUM_LENGTH < ?",
        [$TARGET_WIDTH]
    );

    if (empty($narrow)) {
        echo "✓ all _from columns already >= varchar({$TARGET_WIDTH}) — nothing to widen\n";
        echo "\nDone.\n";
        exit(0);
    }

    echo "Widening " . count($narrow) . " narrow _from column(s) to varchar({$TARGET_WIDTH}):\n";
    $widened = 0;
    $failed = 0;
    foreach ($narrow as $row) {
        $table = $row['TABLE_NAME'];
        $oldW  = (int) $row['width'];
        try {
            // We only widen narrowed varchars. Preserve NULL/DEFAULT
            // metadata by reading the current full column type and
            // doing a MODIFY. Belt-and-suspenders: explicit NULL so
            // we don't accidentally re-flip NOT NULL behavior on
            // tables that used the column nullable.
            db_query("ALTER TABLE `{$table}` MODIFY COLUMN `_from` VARCHAR({$TARGET_WIDTH}) NULL");
            printf("  ✓ %-22s varchar(%d) -> varchar(%d)\n", $table, $oldW, $TARGET_WIDTH);
            $widened++;
        } catch (Throwable $e) {
            printf("  ✗ %-22s FAILED: %s\n", $table, $e->getMessage());
            $failed++;
        }
    }

    echo "\nDone. Widened {$widened}, failed {$failed}.\n";
    if ($failed > 0) exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
