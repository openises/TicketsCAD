<?php
/**
 * Fix the seed-demo data sown by tools/seed_training_demo_part2.php
 * before the 2026-06-11 status-code + action_type fix.
 *
 * Two bugs were fixed in the seeder; this script patches existing
 * data that pre-dates the fix:
 *
 *   1. Status code mapping was inverted. Historical/closed
 *      incidents were stamped with status=2 (which is OPEN in
 *      api/incident-detail.php's $status_labels). Walk every
 *      seed-demo ticket: if its action log contains an
 *      "Incident closed" entry, set status=1 (Closed).
 *
 *   2. action_type was 1 (generic note) on every row. The
 *      detail-page renderActions() color-codes by type:
 *        100   creation (green + plus)
 *        10-19 status   (yellow + repeat)
 *        20-29 assignment (info + people)
 *      Walk every seed-demo action row and re-stamp:
 *        "Call received"  → 100
 *        "Units dispatched" → 21
 *        "First unit on scene" → 22
 *        "Scene secured"   → 13
 *        "Incident closed" → 11
 *
 * Idempotent — re-running is a no-op once values are correct.
 *
 * Usage: php tools/fix_seed_demo_data.php
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Fix seed-demo data (status + action_type)\n";
echo "=========================================\n\n";

// ── 1. Status correction ────────────────────────────────────────────
// Tickets where description starts 'seed-demo:' AND have an
// "Incident closed" action row → should be status=1 (Closed).
try {
    $closedTickets = db_fetch_all(
        "SELECT DISTINCT t.id
           FROM `{$prefix}ticket` t
           JOIN `{$prefix}action` a ON a.ticket_id = t.id
          WHERE t.description LIKE 'seed-demo:%'
            AND t.status <> 1
            AND a.description LIKE '%ncident closed%'"
    );
    $fixed = 0;
    foreach ($closedTickets as $row) {
        $tid = (int) $row['id'];
        db_query(
            "UPDATE `{$prefix}ticket` SET status = 1 WHERE id = ?",
            [$tid]
        );
        $fixed++;
    }
    echo "[OK] Status corrected: {$fixed} seed-demo tickets transitioned to Closed\n";
} catch (Exception $e) {
    echo "[WARN] status fix: " . $e->getMessage() . "\n";
}

// ── 2. action_type re-stamping ─────────────────────────────────────
// Only touch rows on seed-demo tickets. Match by description prefix.
try {
    // Only rows currently at type=1 (the legacy generic-note default)
    // and on a seed-demo ticket. We don't want to clobber actions
    // that were already correctly typed.
    $remaps = [
        100 => 'Call received%',
        21  => 'Units dispatched%',
        22  => 'First unit on scene%',
        13  => 'Scene secured%',
        11  => 'Incident closed%',
    ];
    $totalFixed = 0;
    foreach ($remaps as $newType => $likePattern) {
        $n = db_query(
            "UPDATE `{$prefix}action` a
               JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
                SET a.action_type = ?
              WHERE t.description LIKE 'seed-demo:%'
                AND a.description LIKE ?
                AND a.action_type = 1",
            [$newType, $likePattern]
        );
        $rows = $n ? $n->rowCount() : 0;
        $totalFixed += (int) $rows;
        echo "  - type {$newType} (matching '{$likePattern}'): {$rows} rows updated\n";
    }
    echo "[OK] action_type re-stamped: {$totalFixed} rows total\n";
} catch (Exception $e) {
    echo "[WARN] action_type fix: " . $e->getMessage() . "\n";
}

// ── 3. Sanity counts ─────────────────────────────────────────────────
try {
    $statusCounts = db_fetch_all(
        "SELECT status, COUNT(*) AS n
           FROM `{$prefix}ticket`
          WHERE description LIKE 'seed-demo:%'
          GROUP BY status
          ORDER BY status"
    );
    echo "\nSeed-demo ticket status distribution after fix:\n";
    foreach ($statusCounts as $r) {
        $label = ['0' => 'unset', '1' => 'Closed', '2' => 'Open', '3' => 'Scheduled'][$r['status']] ?? '?';
        echo sprintf("  status=%-2d (%s): %d\n", $r['status'], $label, $r['n']);
    }

    $typeCounts = db_fetch_all(
        "SELECT a.action_type, COUNT(*) AS n
           FROM `{$prefix}action` a
           JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
          WHERE t.description LIKE 'seed-demo:%'
          GROUP BY a.action_type
          ORDER BY a.action_type"
    );
    echo "\nSeed-demo action_type distribution after fix:\n";
    foreach ($typeCounts as $r) {
        echo sprintf("  type=%-4d: %d\n", $r['action_type'], $r['n']);
    }
} catch (Exception $e) {}

echo "\nDone.\n";
