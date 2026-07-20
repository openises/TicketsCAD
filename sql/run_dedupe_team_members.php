<?php
/**
 * Remove orphaned team_members rows and align member_count with reality.
 *
 * Why this exists (issue #45, 2026-07-03):
 *   On Bloomington, teams.php showed a badge like "4 members" on the
 *   Skywarn team but the members list underneath said "No members
 *   assigned to this team yet." Two things were wrong:
 *
 *   1. team_members had 6 rows referencing member_ids 6, 11, 15, 23,
 *      25 — none of which existed in the `member` table (which held
 *      ids 58-87). Those references were left behind when the site's
 *      member roster was re-imported during Phase 87b (AUXCOMM
 *      user provisioning), which changed the id space.
 *
 *   2. The teams-list COUNT subquery joined nothing to `member`, so
 *      the badge counted the raw team_members rows and included the
 *      orphans; the per-team members list used an INNER JOIN and
 *      dropped them. Badge=4 / list=0 is the tell.
 *
 *   The COUNT is fixed at query-source in api/teams.php (INNER JOIN
 *   member so the two agree). This migration cleans up the physical
 *   orphans so they don't slowly rot as more rows accumulate over
 *   the app's lifetime.
 *
 * Behaviour:
 *   - Deletes team_members rows whose member_id doesn't exist in
 *     member.
 *   - Reports counts before + after and lists the orphaned
 *     (team_id, member_id) pairs before deletion for auditability.
 *   - Idempotent: after the first run, subsequent runs find nothing
 *     to do and exit clean.
 *
 * Usage:
 *   php sql/run_dedupe_team_members.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix   = $GLOBALS['db_prefix'] ?? '';
$tmTbl    = $prefix . 'team_members';
$mTbl     = $prefix . 'member';

echo "Dedupe orphaned team_members\n";
echo "============================\n\n";

try {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$tmTbl]
    );
    if (!$exists) {
        echo "[--] {$tmTbl} does not exist — nothing to dedupe.\n";
        exit(0);
    }

    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$tmTbl}`");
    echo "Before: {$tmTbl} has {$before} row(s).\n";

    // Find orphans BEFORE deletion so we can report specifics.
    $orphans = db_fetch_all(
        "SELECT tm.id, tm.team_id, tm.member_id
           FROM `{$tmTbl}` tm
           LEFT JOIN `{$mTbl}` m ON tm.member_id = m.id
          WHERE m.id IS NULL"
    );

    if (empty($orphans)) {
        echo "[--] No orphaned rows found. Nothing to do.\n";
        exit(0);
    }

    echo "\nOrphans to remove (member_id has no matching member row):\n";
    foreach ($orphans as $o) {
        printf("  tm.id=%d  team_id=%d  member_id=%d\n",
            (int) $o['id'], (int) $o['team_id'], (int) $o['member_id']);
    }
    echo "\n";

    $stmt = db_query(
        "DELETE tm FROM `{$tmTbl}` tm
           LEFT JOIN `{$mTbl}` m ON tm.member_id = m.id
          WHERE m.id IS NULL"
    );
    $deleted = $stmt->rowCount();

    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$tmTbl}`");
    echo "[OK] Deleted {$deleted} orphan row(s).\n";
    echo "After: {$tmTbl} has {$after} row(s).\n";
    echo "\nDone.\n";
} catch (Throwable $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}
