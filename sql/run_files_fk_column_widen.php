<?php
/**
 * files/files_x column-width fix — MEDIUMINT foreign-key columns silently
 * overflow against the bigint(8) parent tables they reference.
 *
 * Found 2026-08-31 while another agent was verifying the GH#124 wastebasket/
 * ticket-purge cascade fix and needed a file attached to a ticket for the
 * test: `sql/base_schema.sql` declares `files.id`/`ticket_id`/
 * `responder_id`/`facility_id`/`mi_id` (and `files_x.id`/`file_id`) as
 * `MEDIUMINT` — a real 3-byte MySQL/MariaDB storage type with a hard signed
 * ceiling of 8,388,607, NOT a cosmetic display-width parenthetical the way
 * `int(11)` etc. are used elsewhere in this schema. `ticket.id`,
 * `facilities.id`, `responder.id`, and `user.id` are all `bigint(8)` and, on
 * this shared dev database, already carry values north of 900 million (a
 * side effect of the "seed fixture ids around 900,000,000+" convention
 * several unrelated test suites in this codebase use to dodge collisions —
 * see the AUTO_INCREMENT check in this script's own regression test).
 * Confirmed directly reproducible via a real INSERT: MySQL error 1264 "Out
 * of range value for column 'ticket_id'" the moment `ticket_id` exceeds
 * 8,388,607 — which the live `ticket` AUTO_INCREMENT counter already has,
 * so EVERY new file attach on this database fails until this runs, not a
 * hypothetical someday-at-scale problem.
 *
 * Full sweep (per this project's root-cause-troubleshooting discipline —
 * don't patch just the one column a bug report names): `files`/`files_x`
 * are the ONLY two tables in `sql/base_schema.sql` that declare a
 * MEDIUMINT column shaped like a foreign key (`grep -in mediumint
 * sql/base_schema.sql` returns exactly 7 lines, all in these two tables).
 * Confirmed on the live dev database at fix time:
 *
 *   files.ticket_id    -> ticket.id            bigint(8), MAX(id) ~900.0M  OVERFLOWING NOW (87 rows > ceiling)
 *   files.facility_id  -> facilities.id        bigint(8), 1 row already   > ceiling
 *   files.responder_id -> responder.id         bigint(8), 1 row already   > ceiling
 *   files.mi_id        -> major_incidents.id   int(11),  table empty       not yet at risk, but same defect shape
 *   files_x.file_id    -> files.id             mediumint(5) (this table's own PK, widened below too)
 *
 * `files_x` itself has zero references anywhere in the PHP tree outside
 * its own CREATE TABLE (confirmed via `grep -rln files_x --include=*.php .`
 * returning nothing) -- it appears to be dead legacy schema, like the
 * `requests` table GH#96 dropped. Dropping it is a separate decision (it
 * would need the same kind of explicit review GH#96's table drop got); this
 * fix only widens it so it does not reintroduce the identical mismatch the
 * moment `files.id` is no longer MEDIUMINT.
 *
 * Widening decisions:
 *   - files.ticket_id / .responder_id / .facility_id  -> BIGINT(8), matching
 *     the exact type of the parent tables they reference (ticket.id,
 *     facilities.id, responder.id are all bigint(8)). This is the only
 *     choice that can never need a second migration if those parent tables
 *     keep growing via the same large-fixture-id convention that already
 *     caused this bug once.
 *   - files.mi_id -> BIGINT(8) as well, for consistency with the other
 *     three columns in the same "exactly one of these four is set"
 *     polymorphic FK group on the same row -- even though its current
 *     parent (major_incidents.id) is only int(11) and nowhere near
 *     overflow. The storage cost of BIGINT vs INT on this column is a few
 *     bytes per file-attachment row; the benefit is one fewer inconsistent
 *     column to explain, and no future migration if major_incidents.id is
 *     ever widened too.
 *   - files.id / files_x.id (each table's own AUTO_INCREMENT primary key)
 *     -> INT(11). These are NOT foreign keys -- they grow only as fast as
 *     real file uploads happen (confirmed 0 rows / AUTO_INCREMENT=1 on both
 *     tables on this dev database, i.e. neither has been touched by the
 *     "big fixture id" convention that hit the business-entity tables).
 *     INT's ~2.1 billion ceiling is ample headroom without following the
 *     other columns to BIGINT for no reason.
 *   - files_x.file_id -> INT(11) to match the new files.id type exactly
 *     (an FK must match what it references).
 *
 * `ticket.id` itself is already `bigint(8)` -- confirmed on the live
 * database before writing this migration -- so it is NOT part of this fix;
 * nothing here touches it.
 *
 * Idempotent: every column is checked via information_schema.COLUMNS
 * before touching it -- MODIFY COLUMN only runs when the live DATA_TYPE is
 * still 'mediumint'. Safe to re-run on an install that has already been
 * fixed, or one where these tables don't exist yet for some reason.
 *
 * Usage: php sql/run_files_fk_column_widen.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * Widen one column from MEDIUMINT to the given target type, only if it is
 * still MEDIUMINT on this install. Returns nothing; echoes [OK]/[SKIP].
 */
function fw_widen_column(string $table, string $column, string $alterClause, string $label): void
{
    $dataType = db_fetch_value(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );

    if ($dataType === null || $dataType === false) {
        echo "[SKIP] {$table}.{$column} not found (table/column missing?) — nothing to widen\n";
        return;
    }

    if (strtolower((string) $dataType) !== 'mediumint') {
        echo "[SKIP] {$table}.{$column} is already {$dataType} — nothing to widen\n";
        return;
    }

    db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$alterClause}");
    echo "[OK] {$table}.{$column} widened to {$label} (was mediumint)\n";
}

try {
    $filesTbl = $prefix . 'files';
    $filesXTbl = $prefix . 'files_x';

    // ── files table ─────────────────────────────────────────────────────
    fw_widen_column($filesTbl, 'id', 'INT(11) NOT NULL AUTO_INCREMENT', 'INT(11)');
    fw_widen_column($filesTbl, 'ticket_id', 'BIGINT(8) NOT NULL DEFAULT 0', 'BIGINT(8)');
    fw_widen_column($filesTbl, 'responder_id', 'BIGINT(8) NOT NULL DEFAULT 0', 'BIGINT(8)');
    fw_widen_column($filesTbl, 'facility_id', 'BIGINT(8) NOT NULL DEFAULT 0', 'BIGINT(8)');
    fw_widen_column($filesTbl, 'mi_id', 'BIGINT(8) NOT NULL DEFAULT 0', 'BIGINT(8)');

    // ── files_x table (dead code — see docblock — widened for schema
    //    consistency with the new files.id type, not because anything
    //    writes to it today) ──────────────────────────────────────────────
    fw_widen_column($filesXTbl, 'id', 'INT(11) NOT NULL AUTO_INCREMENT', 'INT(11)');
    fw_widen_column($filesXTbl, 'file_id', 'INT(11) NOT NULL', 'INT(11)');

    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
