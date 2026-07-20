<?php
/**
 * Dedupe the `certifications` table and lock the schema against re-dupe.
 *
 * Why this exists (2026-07-03):
 *   Same class of bug as newui_equipment_types (see
 *   run_dedupe_equipment_types.php). Two separate seed files touched
 *   this table without proper idempotency:
 *
 *   1. sql/membership.sql seeded 9 legacy names (CPR/First Aid,
 *      ICS-100, ICS-200, ICS-700, ICS-800, HAM Radio License, CERT
 *      Basic, Hazmat Awareness, Defensive Driving) with a plain
 *      INSERT INTO — no idempotency guard AT ALL. Every re-run added
 *      9 more rows.
 *
 *   2. sql/training_nims.sql seeded 12 modern FEMA IS-XXX names with
 *      `ON DUPLICATE KEY UPDATE name = VALUES(name)`. But the
 *      certifications table had only `PRIMARY KEY (id)` — no UNIQUE
 *      constraint on `name` — so the on-duplicate-key never fired.
 *      Every re-run added 12 more rows.
 *
 *   your-server.example.com accumulated 138 rows (6x from seed 1, 7x
 *   from seed 2) before we caught it. Bloomington didn't have seed 1's
 *   base names at all (installed from a legacy base_schema import that
 *   never ran membership.sql's certification seed), so it was missing
 *   the modern IS-100.c / IS-200.c / IS-700.b / IS-800.d entries
 *   entirely — those were being applied as UPDATE labels on the
 *   legacy ICS-100/200/700/800 rows that never existed there.
 *
 * What this migration does:
 *   1. Repoint any member_certifications rows that reference a
 *      duplicate certification ID onto the canonical (lowest-id) row
 *      for that name.
 *   2. Delete the duplicate rows (keeps MIN(id) per name).
 *   3. Add UNIQUE KEY certifications(name) so future re-runs of
 *      either seed file leave the table untouched (INSERT IGNORE).
 *
 * Safety:
 *   - Idempotent: safe to run repeatedly. First run cleans up the
 *     mess; subsequent runs find nothing to do and exit clean.
 *   - Non-destructive to real data: member_certifications references
 *     are repointed BEFORE the delete, so no member's earned cert is
 *     orphaned.
 *   - Uses a transaction so a mid-run failure leaves the table
 *     unchanged.
 *   - The UNIQUE-index add is guarded (won't error if already present).
 *
 * Usage: php sql/run_dedupe_certifications.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix   = $GLOBALS['db_prefix'] ?? '';
$certTbl  = $prefix . 'certifications';
$mcertTbl = $prefix . 'member_certifications';

echo "Dedupe certifications\n";
echo "=====================\n\n";

$pdo = db();

try {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$certTbl]
    );
    if (!$exists) {
        echo "[--] {$certTbl} does not exist yet — nothing to dedupe.\n";
        exit(0);
    }

    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$certTbl}`");
    echo "Before: {$certTbl} has {$before} rows.\n\n";

    // 1. Build canonical map: for each name, the lowest surviving id.
    $canonical = [];
    foreach (db_fetch_all(
        "SELECT `name`, MIN(id) AS keep_id
           FROM `{$certTbl}`
          GROUP BY `name`"
    ) as $r) {
        $canonical[$r['name']] = (int) $r['keep_id'];
    }
    $uniqueNames = count($canonical);
    echo "Distinct names: {$uniqueNames}\n\n";

    if ($uniqueNames === $before) {
        echo "[--] No duplicates. Skipping cleanup phase.\n";
    } else {
        $pdo->beginTransaction();
        try {
            // 2. Repoint any member_certifications rows that reference a
            //    duplicate certification_id onto the canonical id.
            $mcertExists = db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$mcertTbl]
            );
            $repointed = 0;
            if ($mcertExists) {
                foreach ($canonical as $name => $keepId) {
                    $dupeIds = [];
                    foreach (db_fetch_all(
                        "SELECT id FROM `{$certTbl}` WHERE `name` = ? AND id <> ?",
                        [$name, $keepId]
                    ) as $r) {
                        $dupeIds[] = (int) $r['id'];
                    }
                    if (empty($dupeIds)) continue;
                    $placeholders = implode(',', array_fill(0, count($dupeIds), '?'));
                    $params = array_merge([$keepId], $dupeIds);
                    $stmt = db_query(
                        "UPDATE `{$mcertTbl}`
                            SET certification_id = ?
                          WHERE certification_id IN ({$placeholders})",
                        $params
                    );
                    $affected = $stmt->rowCount();
                    if ($affected > 0) {
                        echo "  repointed {$affected} member_certifications row(s) '{$name}' -> id {$keepId}\n";
                        $repointed += $affected;
                    }
                }
                if ($repointed === 0) {
                    echo "  no member_certifications rows needed repointing.\n";
                }
            } else {
                echo "  {$mcertTbl} does not exist; skipping member-cert repoint.\n";
            }

            // 3. Delete duplicate rows.
            $keepIds = array_values($canonical);
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $stmt = db_query(
                "DELETE FROM `{$certTbl}` WHERE id NOT IN ({$placeholders})",
                $keepIds
            );
            $deleted = $stmt->rowCount();
            echo "  deleted {$deleted} duplicate certification row(s).\n";

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // 4. Add UNIQUE KEY on `name` so future seed re-runs cannot dupe.
    $hasUnique = db_fetch_value(
        "SELECT COUNT(*)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'name'
            AND NON_UNIQUE   = 0",
        [$certTbl]
    );
    if ($hasUnique) {
        echo "\n[--] UNIQUE index on {$certTbl}(name) already present.\n";
    } else {
        db_query("ALTER TABLE `{$certTbl}` ADD UNIQUE KEY `uniq_name` (`name`)");
        echo "\n[OK] Added UNIQUE KEY uniq_name(name) on {$certTbl}.\n";
    }

    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$certTbl}`");
    echo "\nAfter: {$certTbl} has {$after} rows.\n";
    echo "Removed: " . ($before - $after) . " duplicate row(s).\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}
