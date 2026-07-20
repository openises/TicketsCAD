<?php
/**
 * Phase 41 — Deduplicate `newui_vehicle_types` rows and add a UNIQUE KEY.
 *
 * Eric reported that your-server.example.com had each vehicle type listed
 * multiple times. Bloomington beta showed 272 rows (34 copies × 8 names)
 * on 2026-07-04, same-day report — visible in /vehicles.php's Type
 * filter row rendering the same chip 34 times.
 *
 * Root cause: sql/vehicles.sql originally had no UNIQUE KEY on `name`
 * and used INSERT ... ON DUPLICATE KEY UPDATE. With no key to collide
 * against, every re-run of sql/run_vehicles.php silently inserted 8
 * fresh rows. Same shape as GH #38 for newui_equipment_types.
 *
 * ── History of this script ──
 * The original 2026-05 version used `$prefix . 'vehicle_types'` — but
 * the seed hardcodes the `newui_` prefix in the table name. So the
 * dedup ran DELETE against a non-existent table, threw "table doesn't
 * exist", printed `[ERR] ...`, and exited 1. run_migrations.php's
 * failure-detection regex looked for `ERR:` (colon) but the catch used
 * `[ERR]` (bracket) — silent failure marker recorded the script as
 * applied even though it did nothing.
 *
 * 2026-07-04 rewrite:
 *   - Use the correct table name (`$prefix . 'newui_vehicle_types'`),
 *     matching GH #38's dedupe pattern.
 *   - Re-point any newui_vehicles.vehicle_type_id references onto the
 *     canonical (lowest-id) row before deleting duplicates. Prevents
 *     orphaning existing fleet records.
 *   - Wrap the FK repoint + delete + ALTER in a transaction so a
 *     partial run doesn't leave the table half-migrated.
 *   - Because this file's hash changes, run_migrations.php will
 *     see it as a new (name, hash) pair and re-run automatically.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 41 — dedup newui_vehicle_types\n";
echo "====================================\n\n";

$prefix   = $GLOBALS['db_prefix'] ?? '';
$typesTbl = $prefix . 'newui_vehicle_types';
$vehTbl   = $prefix . 'newui_vehicles';

try {
    $pdo = db();

    // GH #72 (a beta tester 2026-07-07) — on a FRESH install this script runs
    // before run_vehicles.php (alphabetical migration order: 'phase41' <
    // 'vehicles'), so the table doesn't exist yet and the COUNT below
    // hard-failed the whole migration run. Nothing to dedup on a fresh
    // install, and sql/vehicles.sql now creates the table WITH the
    // uk_vt_name UNIQUE KEY — skipping here loses nothing.
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$typesTbl]
    );
    if (!$tableExists) {
        echo "[--] {$typesTbl} does not exist yet (fresh install — run_vehicles.php\n";
        echo "     creates it later with the UNIQUE KEY built in). Nothing to dedup.\n";
        echo "\nDone.\n";
        exit(0);
    }

    // 0. Snapshot state before we touch anything.
    $beforeCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$typesTbl}`");
    $distinct    = (int) db_fetch_value("SELECT COUNT(DISTINCT name) FROM `{$typesTbl}`");
    echo "Before: {$typesTbl} has {$beforeCount} rows, {$distinct} distinct names.\n\n";

    if ($beforeCount === $distinct) {
        echo "[--] No duplicates. Skipping cleanup phase.\n";
    } else {
        $pdo->beginTransaction();

        // 1. Build a name → canonical (lowest) id map. Any row with
        //    id > canonical for its name is a duplicate to remove.
        $canonicalRows = db_fetch_all(
            "SELECT name, MIN(id) AS canonical_id
               FROM `{$typesTbl}`
              GROUP BY name"
        );
        $canonicalByName = [];
        foreach ($canonicalRows as $r) {
            $canonicalByName[$r['name']] = (int) $r['canonical_id'];
        }

        // 2. Repoint any newui_vehicles FK from a duplicate id to the
        //    canonical id BEFORE the delete. Skip if the vehicles
        //    table doesn't exist (very fresh install).
        $hasVehiclesTbl = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$vehTbl]
        );
        $repointed = 0;
        if ($hasVehiclesTbl) {
            $stmt = $pdo->prepare(
                "UPDATE `{$vehTbl}` v
                    JOIN `{$typesTbl}` t ON t.id = v.vehicle_type_id
                    SET v.vehicle_type_id = :canonical
                  WHERE t.name = :name
                    AND v.vehicle_type_id != :canonical2"
            );
            foreach ($canonicalByName as $name => $canonicalId) {
                $stmt->execute([
                    ':canonical'  => $canonicalId,
                    ':name'       => $name,
                    ':canonical2' => $canonicalId,
                ]);
                $repointed += $stmt->rowCount();
            }
            echo "[OK] repointed {$repointed} newui_vehicles FK reference(s) onto canonical rows.\n";
        } else {
            echo "[--] newui_vehicles table missing — skipping FK repoint.\n";
        }

        // 3. Delete the duplicates.
        $deleted = $pdo->exec(
            "DELETE v1 FROM `{$typesTbl}` v1
             INNER JOIN `{$typesTbl}` v2
             WHERE v1.id > v2.id AND v1.name = v2.name"
        );
        echo "[OK] deleted {$deleted} duplicate type row(s).\n";

        $pdo->commit();
    }

    // 4. Add a UNIQUE KEY so future bad seeds fail loudly. Idempotent.
    $hasUk = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = 'uk_vt_name'",
        [$typesTbl]
    );
    if (!$hasUk) {
        $pdo->exec("ALTER TABLE `{$typesTbl}` ADD UNIQUE KEY uk_vt_name (name)");
        echo "[OK] added UNIQUE KEY uk_vt_name(name).\n";
    } else {
        echo "[--] uk_vt_name already present.\n";
    }

    $afterCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$typesTbl}`");
    echo "\nAfter: {$typesTbl} has {$afterCount} rows.\n";
    echo "Removed: " . ($beforeCount - $afterCount) . " duplicate row(s).\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Note the bracket-form here matches run_migrations.php's UPDATED
    // failure regex (2026-07-04 fix in same commit) so a real error
    // now surfaces as FAILED instead of silently applied.
    echo "[ERR] Phase 41 dedup failed: " . $e->getMessage() . "\n";
    exit(1);
}
