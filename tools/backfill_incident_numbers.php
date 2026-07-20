<?php
/**
 * Backfill incident_number for legacy tickets — Phase 99m followup
 * (Eric beta 2026-06-29).
 *
 * Walks every ticket where incident_number is NULL or empty, in
 * id-ASC order (oldest first), and assigns each one a case number
 * using incnum_allocate(). The timestamp passed is the ticket's
 * own creation date, so the year token in the template (e.g. {YY})
 * reflects when the incident actually happened rather than today's
 * year.
 *
 * Idempotent: re-running skips anything that already has a number.
 *
 * Usage:
 *   sudo -u www-data php tools/backfill_incident_numbers.php
 *
 * Dry run (no writes, just show what WOULD be assigned):
 *   sudo -u www-data php tools/backfill_incident_numbers.php --dry-run
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/incident-number.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$prefix = $GLOBALS['db_prefix'] ?? '';

$startSeq = incnum_get_next();
$template = incnum_get_template();

echo "Backfill incident numbers\n";
echo "  Template:        {$template}\n";
echo "  Next sequence:   {$startSeq}\n";
echo "  Dry run:         " . ($dryRun ? 'YES (no writes)' : 'NO (will write)') . "\n\n";

try {
    $tickets = db_fetch_all(
        "SELECT `id`, `date`, `scope`
           FROM `{$prefix}ticket`
          WHERE `incident_number` IS NULL OR `incident_number` = ''
          ORDER BY `id` ASC"
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR fetching tickets: " . $e->getMessage() . "\n");
    exit(1);
}

$total = count($tickets);
if ($total === 0) {
    echo "No tickets need backfilling. Done.\n";
    exit(0);
}

echo "Found {$total} ticket(s) without an incident_number.\n";
echo "Walking oldest-first:\n\n";

$assigned = 0;
$failed = 0;

foreach ($tickets as $t) {
    $id = (int) $t['id'];
    $dateStr = (string) ($t['date'] ?? '');
    $timestamp = $dateStr !== '' ? strtotime($dateStr) : null;
    if ($timestamp === false) $timestamp = null;

    try {
        if ($dryRun) {
            // Render WITHOUT advancing the sequence so the report
            // shows a coherent preview. Actual run will advance one
            // per ticket via incnum_allocate.
            $seq = $startSeq + $assigned;
            $rendered = incnum_render($template, $seq, $timestamp);
            $assigned++;
            printf("  [DRY] id=%-4d  date=%-19s  ->  %s\n", $id, $dateStr, $rendered);
        } else {
            // incnum_allocate() returns the rendered case number under
            // the key 'number' (not 'incident_number' — that's the
            // ticket COLUMN name, not the function's response key).
            $result = incnum_allocate($timestamp);
            $rendered = (string) ($result['number'] ?? '');
            if ($rendered === '') {
                throw new Exception('incnum_allocate returned no number: ' . json_encode($result));
            }
            db_query(
                "UPDATE `{$prefix}ticket` SET `incident_number` = ? WHERE `id` = ?",
                [$rendered, $id]
            );
            $assigned++;
            printf("  id=%-4d  date=%-19s  ->  %s\n", $id, $dateStr, $rendered);
        }
    } catch (Throwable $e) {
        $failed++;
        printf("  id=%-4d  FAILED: %s\n", $id, $e->getMessage());
    }
}

echo "\n";
echo "Summary: {$assigned} assigned, {$failed} failed";
echo $dryRun ? " (dry run — no writes performed).\n" : ".\n";
if (!$dryRun && $failed === 0) {
    echo "Sequence advanced to: " . incnum_get_next() . "\n";
}
