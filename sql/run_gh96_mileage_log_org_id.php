<?php
/**
 * GH#96 (2026-08-20) — mileage_log.org_id column + best-effort backfill.
 *
 * Step 1 of the GH#96 Mileage Log report build (see the design synthesis
 * in the GH#96 issue thread and docs/CROSS-ORG-TICKET-SHARING.md's sibling
 * doc for the report itself). A direct, session-derived org_id column —
 * NOT a join through ticket.org_id at report time — because:
 *
 *   - ticket.org_id is only 53%-93% populated across the two live installs
 *     checked (your-server.example.com, your-server), so a
 *     join-based attribution would silently misattribute or drop rows
 *     with no way for an admin to notice.
 *   - A meaningful share of mileage_log rows have ticket_id IS NULL
 *     entirely (trips with no active assignment — see
 *     api/mobile-data.php's start_mileage, which has always supported
 *     this shape).
 *
 * Step 0 (the accompanying write-path fix in inc/responder-write.php +
 * api/mobile-data.php) makes every NEW mileage_log row carry org_id going
 * forward. This migration only handles EXISTING rows, best-effort:
 *
 *   1. Idempotent ALTER — org_id INT NULL + an index, guarded by an
 *      information_schema check (safe to re-run).
 *   2. A ONE-TIME backfill via ticket.org_id, ONLY for rows that already
 *      have a resolvable ticket link with a populated org_id. Rows with
 *      ticket_id IS NULL, or whose linked ticket has no org_id, are left
 *      org_id IS NULL ("Unattributed" in the Mileage Log report) rather
 *      than guessed at — matching this codebase's standing "an honest
 *      blank beats an estimated number dressed as data" convention (see
 *      the "No legacy route-distance/geocoding fallback" decision in the
 *      GH#96 design and the tile_mode / geocode-cache CLAUDE.md entries).
 *
 * The backfill UPDATE only ever touches rows where org_id IS NULL, so
 * re-running this script is a no-op once it has run — safe on every
 * subsequent `php sql/run_migrations.php`.
 *
 * Usage: php sql/run_gh96_mileage_log_org_id.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // ── 1. Idempotent ALTER ────────────────────────────────────────────
    $exists = db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
        [$prefix . 'mileage_log']
    );
    if ($exists) {
        echo "[SKIP] {$prefix}mileage_log.org_id already exists — nothing to add\n";
    } else {
        db_query(
            "ALTER TABLE `{$prefix}mileage_log`
                ADD COLUMN `org_id` INT NULL,
                ADD INDEX `idx_mileage_org` (`org_id`)"
        );
        echo "[OK] {$prefix}mileage_log.org_id added (+ idx_mileage_org index)\n";
    }

    // ── 2. Best-effort, one-time backfill via ticket.org_id ────────────
    // Only touches rows that already have a resolvable ticket link with a
    // populated org_id on that ticket. WHERE ml.org_id IS NULL makes this
    // safe to re-run: once backfilled (or once Step 0's writers have
    // stamped a row directly), a row is never revisited.
    $updated = db_query(
        "UPDATE `{$prefix}mileage_log` ml
         JOIN `{$prefix}ticket` t ON ml.ticket_id = t.id
            SET ml.org_id = t.org_id
          WHERE ml.org_id IS NULL
            AND ml.ticket_id IS NOT NULL
            AND t.org_id IS NOT NULL"
    );
    $backfilled = $updated->rowCount();
    echo "[OK] backfilled org_id on {$backfilled} pre-existing mileage_log row(s) via ticket.org_id\n";

    $remaining = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}mileage_log` WHERE org_id IS NULL"
    );
    echo "[INFO] {$remaining} mileage_log row(s) remain org_id IS NULL (no resolvable ticket link -- "
        . "shown as \"Unattributed\" in the Mileage Log report; not an error)\n";

    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
