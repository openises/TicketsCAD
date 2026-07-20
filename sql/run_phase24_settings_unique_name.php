<?php
/**
 * Phase 24 (2026-06-11) — settings.name must be UNIQUE.
 *
 * Eric on 2026-06-11: "I now see the feedback when I click save,
 * but it's still not saving the value."
 *
 * Root cause: the `settings` table has no UNIQUE constraint on
 * `name`. The codebase pervasively uses
 *   INSERT INTO settings (name, value) VALUES (?, ?)
 *   ON DUPLICATE KEY UPDATE value = VALUES(value)
 *
 * That clause only fires on a UNIQUE/PRIMARY KEY collision. With
 * no constraint, every "save" just inserts a NEW row. Reads then
 * use LIMIT 1 and get whichever row the storage engine returns
 * first — typically the OLDEST. Net effect: every settings save
 * since the table was created has silently failed for any setting
 * that already had a row.
 *
 * Audit found 20 setting names with 2-8 duplicate rows each on
 * training, including par_enabled (3 rows: 0, 1, 1) — explaining
 * why Eric saw the toast "PAR enabled" but PAR stayed off.
 *
 * == What this migration does ==
 *
 *   1. For each duplicated `name`, keep the row with the highest
 *      id (most recent save) and DELETE the older copies. This
 *      preserves the user's most-recent intent.
 *
 *   2. Add a UNIQUE KEY on `settings(name)` so future INSERT...
 *      ON DUPLICATE KEY UPDATE actually updates.
 *
 *   3. The pre-existing `__nut_test_setting` rows from earlier
 *      diagnostics are cleaned up as a side-effect; harmless.
 *
 * Idempotent: re-running is a no-op once the UNIQUE key is in
 * place (dedup query returns zero rows).
 *
 * Usage: php sql/run_phase24_settings_unique_name.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 24 — settings.name UNIQUE constraint + dedup\n";
echo "==================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Check if UNIQUE key already exists ──────────────────────────────
$alreadyUnique = false;
try {
    $rows = db_fetch_all(
        "SELECT INDEX_NAME, NON_UNIQUE
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'name'",
        [$prefix . 'settings']
    );
    foreach ($rows as $r) {
        if ((int) $r['NON_UNIQUE'] === 0) {
            $alreadyUnique = true;
            break;
        }
    }
} catch (Exception $e) {}

if ($alreadyUnique) {
    echo "[OK] settings.name already has a UNIQUE constraint.\n";
    echo "     Skipping dedup; INSERT ON DUPLICATE works correctly.\n";
    exit(0);
}

// ── Dedup: for each name with multiple rows, keep MAX(id) ──────────
echo "[STEP 1/2] Deduplicating settings rows...\n";

try {
    $dupes = db_fetch_all(
        "SELECT name, COUNT(*) AS n
           FROM `{$prefix}settings`
          WHERE name IS NOT NULL
          GROUP BY name
         HAVING n > 1"
    );
    $deletedTotal = 0;
    foreach ($dupes as $d) {
        // Find the highest id for this name; delete the rest.
        $maxId = (int) db_fetch_value(
            "SELECT MAX(id) FROM `{$prefix}settings` WHERE name = ?",
            [$d['name']]
        );
        if ($maxId <= 0) continue;
        $stmt = db_query(
            "DELETE FROM `{$prefix}settings` WHERE name = ? AND id <> ?",
            [$d['name'], $maxId]
        );
        $deleted = $stmt ? $stmt->rowCount() : 0;
        $deletedTotal += (int) $deleted;
        echo "  - {$d['name']}: kept id={$maxId}, deleted {$deleted} older row(s)\n";
    }
    echo "[OK] Deleted {$deletedTotal} stale rows total.\n";
} catch (Exception $e) {
    echo "[FAIL] dedup: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Also drop NULL-name rows; they're junk and break UNIQUE add ────
try {
    $stmt = db_query("DELETE FROM `{$prefix}settings` WHERE name IS NULL OR name = ''");
    $nulls = $stmt ? $stmt->rowCount() : 0;
    if ($nulls > 0) echo "[OK] Removed {$nulls} row(s) with NULL/empty name.\n";
} catch (Exception $e) {}

// ── Add UNIQUE KEY ──────────────────────────────────────────────────
echo "[STEP 2/2] Adding UNIQUE KEY on settings(name)...\n";
try {
    // tinytext can't be UNIQUE in MariaDB without an explicit length
    // prefix. Convert column to VARCHAR(191) first (191 = 4-byte
    // utf8 safe size). Existing rows fit easily; no truncation.
    db_query("ALTER TABLE `{$prefix}settings` MODIFY COLUMN `name` VARCHAR(191) NOT NULL");
    db_query("ALTER TABLE `{$prefix}settings` ADD UNIQUE KEY `uniq_name` (`name`)");
    echo "[OK] settings.name is now VARCHAR(191) UNIQUE NOT NULL.\n";
} catch (Exception $e) {
    echo "[FAIL] ALTER: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone. Every INSERT ... ON DUPLICATE KEY UPDATE across the\n";
echo "codebase now functions correctly.\n";
