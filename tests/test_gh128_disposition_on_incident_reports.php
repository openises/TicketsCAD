<?php
/**
 * test_gh128_disposition_on_incident_reports.php — GH#128 (rjonesbsink,
 * 2026-08-31): the Incident Summary report has had a disposition
 * BREAKDOWN (aggregate count per disposition) since Phase 132 Step 5, but
 * the two reports that show what happened to an INDIVIDUAL incident never
 * surfaced its own disposition:
 *   - Incident Report ('incident_report' case) — the per-incident row
 *     listing (ID, Scope, Type, Severity, Status, Location, Created,
 *     Closed, Units Assigned, Actions) had no disposition column at all.
 *   - After Action ('after_action' case) — already SELECTs `t.*` (so
 *     $ticket['disposition_id'] was sitting in hand with no extra query
 *     needed) but never read it into $summary.
 *
 * THE FIX: both cases now resolve disposition_id against
 * ticket_disposition using the SAME COALESCE(status_val,'No Disposition')
 * pattern the existing Incident Summary breakdown already uses —
 * incident_report as a new trailing 'Disposition' column (flows through
 * the generic columns/rows table AND the generic CSV export with zero JS
 * changes), after_action as a new 'disposition' summary key rendered by
 * assets/js/reports.js's renderAfterActionPanel().
 *
 * This file drives the REAL api/reports.php endpoint via the shared CLI
 * probe (tests/_gh96_mileage_report_probe.php — generic over any `report`
 * value despite its historical name), never hand-building the expected
 * output, for both a dispositioned and an undispositioned incident.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_fixture_guard.php';
require_once __DIR__ . '/_test_node_probe.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#128 — disposition on Incident Report + After Action ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

function gh128_probe(string $qs): ?array {
    $php = PHP_BINARY ?: 'php';
    $out = test_run_cli([$php, __DIR__ . '/_gh96_mileage_report_probe.php', $qs]);
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

$userId = test_admin_user_id();
$ticketIds = [];

try {
    $dispRow = db_fetch_one("SELECT id, status_val FROM `{$prefix}ticket_disposition` ORDER BY id LIMIT 1");
    is_true($dispRow !== null && (int) ($dispRow['id'] ?? 0) > 0,
        'fixture prerequisite: this install has at least one ticket_disposition row', json_encode($dispRow));
    $dispId    = (int) ($dispRow['id'] ?? 0);
    $dispLabel = (string) ($dispRow['status_val'] ?? '');

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");

    // Incident A: dispositioned.
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, problemend, disposition_id, _by)
              VALUES (?, 1, 0, 'gh128_call_A', 'GH128 fixture', NOW(), NOW(), NOW(), ?, 1)", [$typeId, $dispId]);
    $tidA = (int) db_insert_id(); $ticketIds[] = $tidA;
    test_fixture_guard_track('ticket', $tidA);

    // Incident B: never dispositioned (disposition_id stays NULL) — the
    // normal historical state, per this project's own established
    // "undispositioned is the NORMAL state, not an error" discipline.
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, problemend, _by)
              VALUES (?, 1, 0, 'gh128_call_B', 'GH128 fixture', NOW(), NOW(), NOW(), 1)", [$typeId]);
    $tidB = (int) db_insert_id(); $ticketIds[] = $tidB;
    test_fixture_guard_track('ticket', $tidB);

    $dateStart = date('Y-m-d', strtotime('-1 day'));
    $dateEnd   = date('Y-m-d', strtotime('+1 day'));

    // ── incident_report: dispositioned incident ──
    $qsA = "report=incident_report&start_date={$dateStart}&end_date={$dateEnd}&incident_id={$tidA}";
    $payloadA = gh128_probe($qsA);
    is_true($payloadA !== null, 'incident_report probe (A, dispositioned) returned decodable JSON', json_encode($payloadA));

    if ($payloadA !== null) {
        $cols = $payloadA['columns'] ?? [];
        $dispCol = array_search('Disposition', $cols, true);
        is_true($dispCol !== false, 'incident_report: columns include a Disposition column', json_encode($cols));

        $rows = $payloadA['rows'] ?? [];
        is_true(count($rows) === 1, 'incident_report: exactly one row for incident A', json_encode($rows));
        if ($dispCol !== false && count($rows) === 1) {
            is_true(($rows[0][$dispCol] ?? null) === $dispLabel,
                'incident_report: row shows the REAL disposition label for A',
                'expected ' . $dispLabel . ', got ' . json_encode($rows[0][$dispCol] ?? null));
        }
    }

    // ── incident_report: undispositioned incident ──
    $qsB = "report=incident_report&start_date={$dateStart}&end_date={$dateEnd}&incident_id={$tidB}";
    $payloadB = gh128_probe($qsB);
    is_true($payloadB !== null, 'incident_report probe (B, undispositioned) returned decodable JSON', json_encode($payloadB));
    if ($payloadB !== null) {
        $colsB = $payloadB['columns'] ?? [];
        $dispColB = array_search('Disposition', $colsB, true);
        $rowsB = $payloadB['rows'] ?? [];
        if ($dispColB !== false && count($rowsB) === 1) {
            is_true(($rowsB[0][$dispColB] ?? null) === 'No Disposition',
                'incident_report: an undispositioned incident shows "No Disposition", never blank/null',
                json_encode($rowsB[0][$dispColB] ?? null));
        } else {
            bad('incident_report: B row/column shape unexpected', json_encode($payloadB));
        }
    }

    // ── after_action: dispositioned incident ──
    $qsAA = "report=after_action&incident_id={$tidA}";
    $payloadAA = gh128_probe($qsAA);
    is_true($payloadAA !== null, 'after_action probe (A, dispositioned) returned decodable JSON', json_encode($payloadAA));
    if ($payloadAA !== null) {
        $summary = $payloadAA['summary'] ?? [];
        is_true(array_key_exists('disposition', $summary), 'after_action: summary carries a disposition key');
        is_true(($summary['disposition'] ?? null) === $dispLabel,
            'after_action: summary.disposition is the REAL label for A',
            'expected ' . $dispLabel . ', got ' . json_encode($summary['disposition'] ?? null));
    }

    // ── after_action: undispositioned incident ──
    $qsBB = "report=after_action&incident_id={$tidB}";
    $payloadBB = gh128_probe($qsBB);
    is_true($payloadBB !== null, 'after_action probe (B, undispositioned) returned decodable JSON', json_encode($payloadBB));
    if ($payloadBB !== null) {
        $summaryB = $payloadBB['summary'] ?? [];
        is_true(($summaryB['disposition'] ?? null) === 'No Disposition',
            'after_action: an undispositioned incident shows "No Disposition"',
            json_encode($summaryB['disposition'] ?? null));
    }

} catch (Throwable $e) {
    bad('fixture/probe path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ── Static: reports.js actually renders the new field ──
$reportsJsSrc = (string) file_get_contents($base . '/assets/js/reports.js');
is_true(strpos($reportsJsSrc, "summary.disposition") !== false,
    'assets/js/reports.js reads summary.disposition (After Action panel)');

// ── Static: the CSV export path is fully generic (columns/rows-driven) —
// confirm it has no hardcoded column list that would silently drop the
// new Disposition column, matching the codebase's own established
// dead-control lesson about "plumbing exists, nobody wired the last mile".
is_true(strpos($reportsJsSrc, 'reportData.columns.map(csvEscape)') !== false,
    'assets/js/reports.js\'s CSV export is columns-driven, not a hardcoded list — the new column reaches CSV automatically');

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown ──
try {
    foreach ($ticketIds as $tid) { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]); }
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

exit($fail === 0 ? 0 : 1);
