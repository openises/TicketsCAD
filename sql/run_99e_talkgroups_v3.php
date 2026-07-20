<?php
/**
 * Phase 99e talkgroups v3 (2026-06-28) — administrator opt-in posture.
 *
 * Eric: 'we by default leave these talk-groups disabled. An administrator
 * will enable those they need. I'd like to have you revise the sort order
 * values, lets have them alphabetical by name and count by 3, just so it's
 * easier to add values in the middle of a list without moving a bunch of
 * entries.'
 *
 * Three changes:
 *   1. Flip every existing row to enabled=0 — clean slate for the admin
 *      to opt-in to what they actually need. (Eric will re-enable his
 *      MN State + MN Metro 2 + 9911 manually after this runs.)
 *   2. Renumber sort_order alphabetically by name, starting at 3 and
 *      incrementing by 3 — gives gaps for inserting future rows in
 *      the middle without renumbering.
 *   3. Alter table DEFAULT to enabled=0 so future seeds + INSERT-with-
 *      missing-enabled-column also land disabled.
 *
 * Idempotent — safe to re-run. The renumbering produces the same result
 * each run regardless of starting state.
 *
 * Run: php sql/run_99e_talkgroups_v3.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Flip DEFAULT on `enabled` column ─────────────────────────
try {
    db_query(
        "ALTER TABLE `{$prefix}talkgroups`
            MODIFY COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 0"
    );
    echo "✓ ALTERed enabled DEFAULT to 0\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR altering enabled default: " . $e->getMessage() . "\n");
    exit(1);
}

// ── 2. Disable every existing row ───────────────────────────────
try {
    db_query("UPDATE `{$prefix}talkgroups` SET enabled = 0");
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}talkgroups`");
    echo "✓ disabled {$count} row(s)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR disabling rows: " . $e->getMessage() . "\n");
    exit(1);
}

// ── 3. Renumber sort_order alphabetically by name, step 3 ───────
try {
    $rows = db_fetch_all(
        "SELECT id, name FROM `{$prefix}talkgroups` ORDER BY name ASC, id ASC"
    );
    $sort = 3;
    foreach ($rows as $r) {
        db_query(
            "UPDATE `{$prefix}talkgroups` SET sort_order = ? WHERE id = ?",
            [$sort, (int) $r['id']]
        );
        $sort += 3;
    }
    echo "✓ renumbered " . count($rows) . " row(s) alphabetically (sort 3..{$sort})\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR renumbering: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Done. All talkgroups are now disabled — open Settings → DMR Talkgroups\n";
echo "and toggle the ones you want active.\n";
