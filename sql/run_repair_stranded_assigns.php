<?php
/**
 * Repair: stranded unit assignments on closed incidents (Eric 2026-07-07)
 *
 * The close cascade (auto-clear assigns + reset responders to Available)
 * was only added on 2026-06-28 (Phase 94 Stage 4j). Any incident closed
 * BEFORE that date kept its assigns rows open forever, pinning the
 * assigned units to a closed incident. Observed on training: ticket 131
 * (closed 2026-06-24) held unit M1 in "Responding" for two weeks.
 *
 * This runs incident_clear_stragglers() in conservative mode over every
 * closed, non-deleted ticket that still has open assigns rows:
 *   - assigns.clear is stamped with the ticket's original close time
 *     (problemend, falling back to updated) so time-on-task reports
 *     stay truthful
 *   - responders are reset to Available ONLY if their current status
 *     still looks on-call (Responding / Dispatched / On Scene / busy
 *     group) — a status a dispatcher set since the close is preserved
 *
 * Idempotent — picked up automatically by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
require_once 'inc/incident-write.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Repair: stranded assignments on closed incidents\n";
echo "================================================\n\n";

try {
    $stranded = db_fetch_all(
        "SELECT t.`id`, t.`problemend`, t.`updated`, COUNT(a.`id`) AS open_assigns
         FROM `{$prefix}ticket` t
         JOIN `{$prefix}assigns` a ON a.`ticket_id` = t.`id`
              AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`,'%y') = '00')
         WHERE t.`status` = 1
           AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
         GROUP BY t.`id`, t.`problemend`, t.`updated`"
    );
} catch (Exception $e) {
    // Pre-wastebasket installs lack deleted_at — retry without it.
    $stranded = db_fetch_all(
        "SELECT t.`id`, t.`problemend`, t.`updated`, COUNT(a.`id`) AS open_assigns
         FROM `{$prefix}ticket` t
         JOIN `{$prefix}assigns` a ON a.`ticket_id` = t.`id`
              AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`,'%y') = '00')
         WHERE t.`status` = 1
         GROUP BY t.`id`, t.`problemend`, t.`updated`"
    );
}

if (empty($stranded)) {
    echo "skip: no closed tickets with open assignments\n";
    exit(0);
}

$totalCleared = 0;
$totalReset = 0;
foreach ($stranded as $t) {
    $tid = (int) $t['id'];
    $closeTime = (string) ($t['problemend'] ?? '');
    if ($closeTime === '' || strpos($closeTime, '0000') === 0) {
        $closeTime = (string) ($t['updated'] ?? '');
    }
    if ($closeTime === '' || strpos($closeTime, '0000') === 0) {
        $closeTime = date('Y-m-d H:i:s');
    }

    $r = incident_clear_stragglers($tid, 0, [
        'conservative' => true,
        'clear_time'   => $closeTime,
        'action_note'  => 'Stranded assignments repaired (close predates auto-clear cascade)',
    ]);
    if (!empty($r['errors'])) {
        echo "ticket $tid: ERROR " . implode('; ', $r['errors']) . "\n";
        continue;
    }
    echo "ticket $tid: cleared {$r['cleared_assigns']} assignment(s), "
       . "reset {$r['reset_responders']} responder(s) (clear stamped $closeTime)\n";
    $totalCleared += (int) $r['cleared_assigns'];
    $totalReset   += (int) $r['reset_responders'];
}

echo "\ndone: {$totalCleared} assignment(s) cleared, {$totalReset} responder(s) reset across "
   . count($stranded) . " ticket(s)\n";
