<?php
/**
 * Reports page KPI reconciliation (Eric, 2026-08-13) — he read "32 Closed
 * (Period)" next to "1 Total (Period)" on the live Reports page and
 * (reasonably) expected the Incident Summary table below to list 32+1
 * incidents. It listed 1. Root cause: api/statistics.php's mode=reports
 * block computed closed_in_period by filtering `problemend` (when an
 * incident was CLOSED) while total_in_period — and the incident_summary
 * report in api/reports.php — both filter `date` (when it was OPENED).
 * An incident opened last month and closed this month counted toward
 * "closed (period)" but not "total (period)", so the two numbers on the
 * same "(Period)"-labeled panel weren't answering the same question. No
 * data was deleted; the two cards just disagreed about what "period"
 * means. Fixed by aligning closed_in_period to the same `date` basis.
 *
 * This test reproduces exactly that scenario through the real endpoint
 * (api/statistics.php?mode=reports, driven via a CLI subprocess probe —
 * same discipline as tests/test_soft_delete_sweep.php, since the endpoint
 * finishes via json_response() and exits) and proves the fix: an incident
 * opened outside the period but closed inside it must NOT inflate
 * closed_in_period, and closed_in_period must never exceed total_in_period.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Reports page KPI reconciliation ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function rsp_probe(string $period): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_reports_stats_probe.php')
         . ' ' . escapeshellarg($period);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

$typeId = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
} catch (Exception $e) { /* handled below */ }

if ($typeId <= 0) {
    echo "  SKIP  no incident types configured — cannot create fixture incidents\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$adminId = test_admin_user_id();
$marker  = 'RSP reconcile ' . bin2hex(random_bytes(5));
$ids     = [];

$cleanup = function () use (&$ids, $prefix) {
    foreach ($ids as $tid) {
        if (!$tid) continue;
        try {
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?",        [$tid]);
        } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
    }
};
register_shutdown_function($cleanup);

function rsp_create(int $typeId, int $adminId, string $marker, string $suffix): int {
    global $ids;
    $res = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => $marker . ' ' . $suffix,
        'street'      => '221B Baker Street',
        'city'        => 'Cleveland',
        'state'       => 'OH',
        'description' => 'RSP reconcile fixture — ' . $suffix,
    ], $adminId);
    $id = (int) ($res['id'] ?? 0);
    if ($id > 0) $ids[] = $id;
    return $id;
}

// A — opened LAST YEAR, closed just now. This is Eric's exact scenario:
// closed inside "this_month" but opened well outside it.
$idA = rsp_create($typeId, $adminId, $marker, 'opened-long-ago closed-now');
// B — opened AND closed today (inside "this_month" either way).
$idB = rsp_create($typeId, $adminId, $marker, 'opened-and-closed-today');
// C — opened today, still OPEN (counts toward total, not toward closed).
$idC = rsp_create($typeId, $adminId, $marker, 'opened-today-still-open');

if ($idA <= 0 || $idB <= 0 || $idC <= 0) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok('created 3 fixture incidents through the real writer (incident_create_internal)');

db_query("UPDATE `{$prefix}ticket` SET `date` = ?, `status` = 1, `problemend` = NOW() WHERE `id` = ?",
    ['2020-01-15 10:00:00', $idA]);
db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = NOW() WHERE `id` = ?", [$idB]);
// C stays open (status=2 from the writer) with today's `date`.

$baseline = rsp_probe('this_month');
if (!is_array($baseline) || !array_key_exists('total_in_period', $baseline) || !array_key_exists('closed_in_period', $baseline)) {
    bad('probe did not return a usable reports-mode payload',
        substr(json_encode($baseline), 0, 300));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok('api/statistics.php?mode=reports returned total_in_period + closed_in_period');

// Fixture A already exists by the time we captured $baseline (it has to,
// to be updated above) — so re-probe is needed for a true before/after
// delta. Capture a SECOND baseline immediately, then null out A's
// problemend to get a genuine "before A closes" snapshot, then restore it.
db_query("UPDATE `{$prefix}ticket` SET `status` = 2, `problemend` = NULL WHERE `id` = ?", [$idA]);
db_query("UPDATE `{$prefix}ticket` SET `status` = 2, `problemend` = NULL WHERE `id` = ?", [$idB]);
$before = rsp_probe('this_month');
db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = NOW() WHERE `id` = ?", [$idA]);
db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = NOW() WHERE `id` = ?", [$idB]);
$after = rsp_probe('this_month');

if (!is_array($before) || !is_array($after)) {
    bad('before/after probe pair did not both return usable payloads');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$deltaTotal  = (int) $after['total_in_period']  - (int) $before['total_in_period'];
$deltaClosed = (int) $after['closed_in_period'] - (int) $before['closed_in_period'];

// total_in_period is unaffected by status/problemend changes (it only
// filters on `date`, which never changed) — A and B were already counted
// in $before since their `date` was already in-period. Delta should be 0.
if ($deltaTotal === 0) {
    ok('closing A and B does not change total_in_period (it only tracks open-date)');
} else {
    bad('total_in_period moved when only status/problemend changed', "delta={$deltaTotal}");
}

// closed_in_period should gain exactly 1 — B (opened AND closed in-period).
// A must NOT count: its `date` is 2020-01-15, outside "this_month", even
// though its problemend is NOW(). This is the exact bug Eric hit.
if ($deltaClosed === 1) {
    ok('closed_in_period gained exactly 1 (B) — A (closed now, opened in 2020) correctly excluded');
} else {
    bad('closed_in_period did not move by exactly 1 as expected',
        "delta={$deltaClosed} — if this is >1, incident A (opened 2020-01-15, " .
        "closed just now) is being counted by open-date-outside-period, which " .
        "is the original bug reproduced live");
}

// Global invariant: since closed_in_period's query is now a strict subset
// of total_in_period's (same date range + soft-delete filter, plus
// status=1), it can never exceed it. This is the structural guarantee
// that prevents "32 closed, 1 total" from ever rendering again.
if ((int) $after['closed_in_period'] <= (int) $after['total_in_period']) {
    ok('closed_in_period <= total_in_period holds (the cards can never contradict the table again)');
} else {
    bad('closed_in_period exceeds total_in_period — the KPI cards will look broken again',
        "closed={$after['closed_in_period']} total={$after['total_in_period']}");
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
