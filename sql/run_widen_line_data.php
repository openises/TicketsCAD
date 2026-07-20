<?php
/**
 * Issue #26 (a beta tester, 2026-07-02) — widen mmarkup.line_data.
 *
 * Import KML with routes failed with:
 *   SQLSTATE[22001]: String data, right truncated:
 *   1406 Data too long for column 'line_data' at row 1
 *
 * Root cause: mmarkup.line_data is varchar(4096) in the legacy
 * v3.44 schema. A KML LineString with 200+ coordinates plus decimal
 * lat/lng values easily produces a JSON encoding well past 4096
 * bytes. Real routes for a rest-stop tour or parade route are
 * typically 20-100 KB.
 *
 * Widen to LONGTEXT (4 GB) — plenty of headroom, no realistic upper
 * bound on legitimate route sizes. LONGTEXT is stored off-row so
 * it doesn't inflate the mmarkup row itself.
 *
 * Idempotent — skips the ALTER if the column is already LONGTEXT.
 *
 * Safe to re-run.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Issue #26 — widen mmarkup.line_data\n";
echo "===================================\n\n";

try {
    $row = db_fetch_one(
        "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'line_data'",
        [$prefix . 'mmarkup']
    );

    if (!$row) {
        echo "[--] mmarkup.line_data column not present (mmarkup table missing?) — skipping.\n";
    } else {
        $type = strtolower((string) $row['DATA_TYPE']);
        if ($type === 'longtext') {
            echo "[--] mmarkup.line_data already LONGTEXT — nothing to do.\n";
        } else {
            db_query("ALTER TABLE `{$prefix}mmarkup` MODIFY COLUMN `line_data` LONGTEXT NOT NULL");
            echo "[OK] mmarkup.line_data widened from {$type}"
                . (isset($row['CHARACTER_MAXIMUM_LENGTH']) ? "({$row['CHARACTER_MAXIMUM_LENGTH']})" : '')
                . " to LONGTEXT.\n";
        }
    }
} catch (Throwable $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}

echo "\nDone.\n";
