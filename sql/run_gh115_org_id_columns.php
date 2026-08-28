<?php
/**
 * GH#115 (d3xter/Andreas Sinz, 2026-08-26) — a genuinely fresh install
 * (confirmed on Docker) reports SCHEMA IS BEHIND THE CODE for
 * `teams`: org_id, and `php tools/check-schema.php --repair` cannot fix
 * it: there was no migration to re-run.
 *
 * Phase 99j-6 added `org_id` to facilities/responder/teams/
 * newui_equipment/newui_vehicles LAZILY -- ensure_org_id_column()
 * (inc/org-scope.php) is called from each table's own write path
 * (inc/team-write.php, inc/facility-write.php, inc/responder-write.php,
 * api/equipment.php, api/vehicles.php) and ALTERs the table the first
 * time something is actually saved. That's fine for the org-scoping
 * feature itself (existing rows stay org_id=NULL and remain visible via
 * org_query_filter()'s legacy fallback either way), but
 * sql/schema_manifest.json records the column as required because the
 * writer code references it -- so a fresh install that hasn't yet
 * created a single team/facility/responder/vehicle/equipment row
 * reports a false CRITICAL schema-drift error with no migration able to
 * clear it, exactly the "SBOM version 43" class of gap this project's
 * schema-verify system exists to prevent (see Phase 125's CLAUDE.md
 * pitfall entry: "an install could not check itself").
 *
 * This migration simply calls ensure_org_id_column() for all five
 * tables directly, during the normal migration pass, so the schema is
 * deterministically correct the moment an install finishes migrating --
 * never dependent on which entity types a user happens to have touched
 * first. Idempotent: ensure_org_id_column() itself checks
 * information_schema before ALTERing, so a table that already has the
 * column (because it WAS touched first) is a silent no-op.
 *
 * ORDERING CAVEAT, found in CI, not in the original report: two of the
 * five tables (newui_equipment, newui_vehicles) aren't in base_schema.sql
 * -- they're created by their own sql/run_equipment.php /
 * sql/run_vehicles.php migrations, discovered by sql/run_migrations.php's
 * run_*.php glob and run in ksort() (alphabetical filename) order. This
 * script's own filename can sort BEFORE one or both of those (it does,
 * for newui_vehicles: "run_gh115..." < "run_vehicles..."), so on a
 * genuinely fresh install this script can legitimately run before the
 * table it's trying to alter has been created at all -- a missing
 * TABLE, not a missing column, and not an error: whichever of these two
 * tables isn't ready yet gets its org_id column the same way it always
 * has, lazily, the first time something is actually saved through
 * api/equipment.php or api/vehicles.php. A hard failure here for that
 * case previously made THIS script the one thing that could break a
 * fresh install outright (confirmed live: it aborted the migration
 * pass with a non-zero exit before this fix), which is a strictly worse
 * outcome than the false-positive schema-drift warning it exists to
 * fix. Renaming this file to guarantee it always sorts last is not a
 * real fix either -- some THIRD table could be added after this one
 * ships -- so the tolerant check below is the actual fix, not a
 * workaround for today's specific two tables.
 *
 * Usage: php sql/run_gh115_org_id_columns.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/org-scope.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$tables = ['facilities', 'responder', 'teams', 'newui_equipment', 'newui_vehicles'];

try {
    foreach ($tables as $table) {
        $fullTable = $prefix . $table;
        $tableExists = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$fullTable]
        );
        if (!$tableExists) {
            echo "[SKIP] {$fullTable} does not exist yet -- its own migration hasn't run yet on this install; org_id will be added lazily the first time a row is saved through the app\n";
            continue;
        }
        $before = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
            [$fullTable]
        );
        ensure_org_id_column($table);
        $after = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
            [$fullTable]
        );
        if (!$after) {
            // The table exists but the column still doesn't -- a real,
            // unexpected failure (not the ordering case above, which is
            // handled before this point), so this one genuinely fails.
            fwrite(STDERR, "ERROR: {$fullTable}.org_id still missing after ensure_org_id_column() despite the table existing\n");
            exit(1);
        }
        echo $before
            ? "[SKIP] {$fullTable}.org_id already present\n"
            : "[OK] {$fullTable}.org_id added\n";
    }
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
