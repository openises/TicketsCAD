<?php
/**
 * Phase 15c (2026-06-11) — Incident-number uniqueness defense.
 *
 * Phase 15 + 15b guarantee that the in-flight allocator generates
 * sequential, period-correct numbers. They do NOT defend against:
 *
 *   - Admin manually setting Next sequence to a value already used
 *     (e.g., set=42 but ticket "26-0042" already exists from earlier
 *      this year)
 *   - Restoring from a backup and forgetting to bump the counter
 *   - Template changes that suddenly make the rendered shape match
 *     an old shape (e.g., switching from "{YY}-{NNNN}" to "{NNNN}"
 *     with old "26-0042" and new "0042" colliding)
 *   - Importing legacy data with pre-existing numbers
 *
 * This migration adds the schema-level uniqueness guarantee that
 * the allocator can't lie about. Combined with the application-layer
 * retry-on-collision in incnum_allocate(), even when the admin asks
 * for a number that's already taken, the system advances to the next
 * truly-available slot and audit-logs the skip.
 *
 * What this script does:
 *
 *   1. Detects whether ticket.incident_number already has duplicates.
 *      If yes, REPORTS them but does NOT alter the index — the admin
 *      needs to manually resolve them first (otherwise the ALTER
 *      would fail).
 *   2. Drops the non-unique idx_incident_number index if present.
 *   3. Adds UNIQUE KEY uniq_incident_number (incident_number).
 *      NULL values are allowed (MySQL/MariaDB UNIQUE doesn't dedupe
 *      NULLs), so existing rows with NULL incident_number are fine.
 *
 * Idempotent: re-runs are a no-op once UNIQUE is in place.
 *
 * Usage: php sql/run_phase15c_incident_number_uniqueness.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 15c — Incident-number uniqueness\n";
echo "======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Sanity-check for existing duplicates ──────────────────────────────
try {
    $dupes = db_fetch_all(
        "SELECT incident_number, COUNT(*) AS n, GROUP_CONCAT(id LIMIT 5) AS sample_ids
         FROM `{$prefix}ticket`
         WHERE incident_number IS NOT NULL AND incident_number <> ''
         GROUP BY incident_number
         HAVING n > 1
         LIMIT 10"
    );
    if (count($dupes) > 0) {
        echo "[ABORT] Duplicates already exist in ticket.incident_number:\n";
        foreach ($dupes as $d) {
            echo "  '{$d['incident_number']}' — {$d['n']} rows (ids: {$d['sample_ids']})\n";
        }
        echo "\nResolve these manually before re-running this migration.\n";
        echo "(Either NULL the duplicates or assign new unique values.)\n";
        exit(1);
    }
    echo "[OK] No existing duplicates detected\n";
} catch (Exception $e) {
    echo "[INFO] dup-check: " . $e->getMessage() . " (probably means ticket table or column missing)\n";
}

// ── Check current index state ─────────────────────────────────────────
try {
    $indexes = db_fetch_all(
        "SELECT INDEX_NAME, NON_UNIQUE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'incident_number'",
        [$prefix . 'ticket']
    );

    $hasUnique  = false;
    $hasNonUnique = false;
    foreach ($indexes as $idx) {
        if ((int) $idx['NON_UNIQUE'] === 0) $hasUnique = true;
        if ((int) $idx['NON_UNIQUE'] === 1) $hasNonUnique = true;
    }

    if ($hasUnique) {
        echo "[OK] UNIQUE index already in place on ticket.incident_number\n";
    } else {
        // Drop the non-unique idx_incident_number from Phase 15 first.
        if ($hasNonUnique) {
            try {
                db_query("ALTER TABLE `{$prefix}ticket` DROP KEY `idx_incident_number`");
                echo "[OK] Dropped non-unique idx_incident_number\n";
            } catch (Exception $e) {
                echo "[WARN] could not drop non-unique index: " . $e->getMessage() . "\n";
            }
        }
        db_query(
            "ALTER TABLE `{$prefix}ticket`
             ADD UNIQUE KEY `uniq_incident_number` (`incident_number`)"
        );
        echo "[OK] Added UNIQUE key uniq_incident_number on ticket.incident_number\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
