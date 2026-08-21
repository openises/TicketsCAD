<?php
/**
 * B19 (SPEC-STATUS.md, 2026-08-21) — drop the legacy `newui_facility_capacity`
 * table.
 *
 * `sql/facility_beds.sql` used to CREATE + seed this table on every fresh
 * install (tools/install_fresh.php's foundational-imports list). No API
 * endpoint or page ever read or wrote its data — the live capacity model is
 * a different pair, `capacity_categories` + `facility_capacity`
 * (sql/run_facility_capacity_tables.php, api/facility-capacity.php). It was
 * absent from sql/schema_manifest.json, confirming it was genuinely outside
 * the tracked-writer set.
 *
 * The ONLY thing in the whole tree that ever touched this table's actual
 * ROW DATA was `sql/facility_beds.sql`'s own seed INSERT:
 *
 *     INSERT IGNORE INTO newui_facility_capacity (facility_id, category,
 *         total, occupied, status)
 *     SELECT f.id, 'General', 0, 0, 'open' FROM facilities f WHERE NOT
 *         EXISTS (...)
 *
 * facility-board.php read the table's EXISTENCE (an information_schema
 * COUNT), never a row's contents, as a (wrong) proxy for "is capacity
 * tracking available" -- fixed in the same change to check
 * `facility_capacity`, the table its own capacity fetch actually reads.
 * Nothing else in api/, inc/, or any *.js file names this table at all.
 *
 * WHY THIS IS NOT A STRICT "row count must be exactly zero" CHECK, unlike
 * sql/run_gh96_drop_requests_table.php's precedent for the (always empty)
 * legacy `requests` table: the seed INSERT above ran on every fresh install
 * that had any facilities at all, so a real install can have N rows -- one
 * per facility -- and STILL be data nothing ever used. Refusing to drop
 * whenever N > 0 would mean this migration could never succeed anywhere.
 * Instead: every existing row is verified to still match the EXACT
 * auto-seeded placeholder shape above (category='General', total=0,
 * occupied=0, status='open') before dropping. Since nothing besides that
 * one seed INSERT has ever written to this table, a row that does NOT match
 * that shape is genuine unexplained data -- and the script refuses and
 * reports it rather than guessing.
 *
 * Verified before this script was written against three independent
 * installs -- this dev database (10 rows), your-server.example.com
 * (10 rows), your-server (0 rows) -- every row on every install
 * matched the placeholder shape exactly.
 *
 * Idempotent: DROP TABLE IF EXISTS, guarded by an information_schema check
 * first so a re-run against an install that has already dropped it (or a
 * fresh install that never created it, per sql/facility_beds.sql no longer
 * creating it) is a clean no-op.
 *
 * Usage: php sql/run_facility_capacity_legacy_table_drop.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'newui_facility_capacity';

try {
    $exists = db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    if (!$exists) {
        echo "[SKIP] {$table} does not exist — nothing to drop "
            . "(already dropped, or a fresh install that never created it)\n";
    } else {
        $rows = db_fetch_all(
            "SELECT id, facility_id, category, total, occupied, status
               FROM `{$table}`"
        );

        $unexpected = [];
        foreach ($rows as $r) {
            $isPlaceholder = ($r['category'] === 'General')
                && ((int) $r['total'] === 0)
                && ((int) $r['occupied'] === 0)
                && ($r['status'] === 'open');
            if (!$isPlaceholder) {
                $unexpected[] = $r;
            }
        }

        if (!empty($unexpected)) {
            // This has never been observed on any install checked (this dev
            // database, training, your deployment) -- every row there matched the
            // auto-seeded placeholder shape exactly. If a row here does NOT
            // match, something wrote real data through a path this
            // investigation didn't find -- refuse rather than silently
            // discard it, and surface enough detail for a human to look.
            fwrite(STDERR,
                "ERROR: {$table} has " . count($unexpected) . " row(s) that do NOT match "
                . "the known auto-seeded placeholder shape (category='General', total=0, "
                . "occupied=0, status='open') -- refusing to drop.\n"
                . "This table was believed used ONLY for that placeholder seed on every "
                . "install checked; investigate before proceeding. First unexpected row:\n"
                . json_encode($unexpected[0]) . "\n"
                . "(If this is confirmed safe to discard, drop it manually and re-run this "
                . "script, which will then find the table already gone and exit cleanly.)\n"
            );
            exit(1);
        }

        $rowCount = count($rows);
        db_query("DROP TABLE `{$table}`");
        echo "[OK] {$table} dropped (confirmed {$rowCount} row(s), all matching the "
            . "auto-seeded placeholder shape, before drop)\n";
    }
    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
