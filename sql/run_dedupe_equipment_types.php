<?php
/**
 * Dedupe newui_equipment_types and lock the schema against re-dupe.
 *
 * Why this exists (2026-07-03):
 *   sql/equipment.sql seeds 10 canonical types with `ON DUPLICATE KEY
 *   UPDATE name = VALUES(name)`, but the table has no UNIQUE constraint
 *   on `name` — only PRIMARY KEY (id) auto-increment. That means the
 *   "on duplicate key" clause NEVER fires and each re-run of
 *   run_equipment.php silently inserts a fresh 10 rows with new IDs.
 *   Bloomington accumulated 341 rows (34x dupes); training accumulated
 *   51 (5x dupes); each canonical name (Radio, Medical, PPE, Tools,
 *   Communications, Electronics, Shelter, Signage, Generator, Other)
 *   repeated exactly as many times as the seed had been re-run.
 *
 * What this migration does:
 *   1. Repoints any newui_equipment rows that reference a duplicate
 *      type ID onto the canonical lowest-id row for that name.
 *   2. Deletes the duplicate rows (keeps MIN(id) per name).
 *   3. Adds a UNIQUE KEY (name) on newui_equipment_types so any future
 *      re-run of the seed either succeeds silently (INSERT IGNORE) or
 *      updates the existing row (ON DUPLICATE KEY UPDATE). Either way,
 *      no more phantom rows.
 *
 * Safety:
 *   - Idempotent: safe to run any number of times. First run cleans up
 *     the mess; subsequent runs find nothing to do and exit clean.
 *   - Non-destructive to real data: equipment_type_id references are
 *     repointed BEFORE the delete, so no newui_equipment row is
 *     orphaned.
 *   - Uses a transaction so a mid-run failure leaves the table
 *     unchanged.
 *   - The UNIQUE-index add is guarded (won't error if already present).
 *
 * Usage: php sql/run_dedupe_equipment_types.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$typesTbl = $prefix . 'newui_equipment_types';
$eqTbl    = $prefix . 'newui_equipment';

echo "Dedupe newui_equipment_types\n";
echo "============================\n\n";

$pdo = db();

try {
    // 1. Confirm the table exists.
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$typesTbl]
    );
    if (!$exists) {
        echo "[--] {$typesTbl} does not exist yet — nothing to dedupe.\n";
        exit(0);
    }

    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$typesTbl}`");
    echo "Before: {$typesTbl} has {$before} rows.\n\n";

    // 2. Build the canonical map: for each name, the lowest id.
    $canonical = [];
    foreach (db_fetch_all(
        "SELECT `name`, MIN(id) AS keep_id
           FROM `{$typesTbl}`
          GROUP BY `name`"
    ) as $r) {
        $canonical[$r['name']] = (int) $r['keep_id'];
    }
    $uniqueNames = count($canonical);
    echo "Distinct names: {$uniqueNames}\n";
    foreach ($canonical as $name => $keepId) {
        echo "  keep id={$keepId} for {$name}\n";
    }
    echo "\n";

    if ($uniqueNames === $before) {
        echo "[--] No duplicates. Skipping cleanup phase.\n";
    } else {
        $pdo->beginTransaction();
        try {
            // 3. Repoint any newui_equipment row that references a
            //    duplicate type ID onto the canonical id for its name.
            //    Only run this if newui_equipment table exists.
            $eqExists = db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$eqTbl]
            );
            $repointed = 0;
            if ($eqExists) {
                foreach ($canonical as $name => $keepId) {
                    // Find every id that maps to this name EXCEPT the one we keep.
                    $dupeIds = [];
                    foreach (db_fetch_all(
                        "SELECT id FROM `{$typesTbl}` WHERE `name` = ? AND id <> ?",
                        [$name, $keepId]
                    ) as $r) {
                        $dupeIds[] = (int) $r['id'];
                    }
                    if (empty($dupeIds)) continue;
                    $placeholders = implode(',', array_fill(0, count($dupeIds), '?'));
                    $params = array_merge([$keepId], $dupeIds);
                    $stmt = db_query(
                        "UPDATE `{$eqTbl}`
                            SET equipment_type_id = ?
                          WHERE equipment_type_id IN ({$placeholders})",
                        $params
                    );
                    $affected = $stmt->rowCount();
                    if ($affected > 0) {
                        echo "  repointed {$affected} equipment row(s) '{$name}' -> id {$keepId}\n";
                        $repointed += $affected;
                    }
                }
                if ($repointed === 0) {
                    echo "  no equipment rows needed repointing (equipment table empty or already canonical).\n";
                }
            } else {
                echo "  {$eqTbl} does not exist; skipping equipment repoint (no risk).\n";
            }

            // 4. Delete the duplicate rows.
            $keepIds = array_values($canonical);
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $stmt = db_query(
                "DELETE FROM `{$typesTbl}` WHERE id NOT IN ({$placeholders})",
                $keepIds
            );
            $deleted = $stmt->rowCount();
            echo "  deleted {$deleted} duplicate type row(s).\n";

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // 5. Add UNIQUE KEY on `name` so future seed re-runs cannot dupe.
    $hasUnique = db_fetch_value(
        "SELECT COUNT(*)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'name'
            AND NON_UNIQUE   = 0",
        [$typesTbl]
    );
    if ($hasUnique) {
        echo "\n[--] UNIQUE index on {$typesTbl}(name) already present.\n";
    } else {
        db_query("ALTER TABLE `{$typesTbl}` ADD UNIQUE KEY `uniq_name` (`name`)");
        echo "\n[OK] Added UNIQUE KEY uniq_name(name) on {$typesTbl}.\n";
    }

    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$typesTbl}`");
    echo "\nAfter: {$typesTbl} has {$after} rows.\n";
    echo "Removed: " . ($before - $after) . " duplicate row(s).\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}
