<?php
/**
 * Incident Summary -> incident type -> filtered Incidents list (Eric,
 * 2026-08-13): "When running an incident summary report, I'd like to see
 * an ability to click on an incident type and view a list of the
 * incidents of that type."
 *
 * Unlike every other drill-down kind (which opens ONE record), clicking a
 * type in api/reports.php's 'incident_summary' report re-runs the
 * 'incident_report' report filtered to that type's in_types_id. This test
 * covers both halves through the REAL endpoint:
 *   1. incident_summary's row for our fixture type carries an
 *      'incident_type' link descriptor whose id is the real in_types_id.
 *   2. incident_report, given that id as in_types_id, returns exactly the
 *      incidents of that type -- and only those, proving the filter
 *      actually narrows rather than being ignored.
 *
 * Driven via the real writer (incident_create_internal) and a freshly
 * created, uniquely-named incident type, so the fixture can't collide with
 * real data on a shared dev database.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Incident Summary -> type -> filtered Incidents list ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function tdd_probe(string $report, string $period = 'this_year', array $extra = []): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_reports_links_probe.php')
         . ' ' . escapeshellarg($report) . ' ' . escapeshellarg($period);
    // _reports_links_probe.php's 3rd argv is incident_id; type filtering
    // needs its own $_GET key, so drive it via a small env-var side
    // channel the probe doesn't need to know about — simplest is a 4th arg.
    foreach ($extra as $v) { $cmd .= ' ' . escapeshellarg((string) $v); }
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

$marker = 'TDD' . bin2hex(random_bytes(4));
$typeName = 'TDD Test Type ' . $marker;
$typeId = 0;
$ticketIds = [];

$cleanup = function () use (&$typeId, &$ticketIds, $prefix) {
    try {
        foreach ($ticketIds as $tid) {
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?",        [$tid]);
        }
        if ($typeId) db_query("DELETE FROM `{$prefix}in_types` WHERE id = ?", [$typeId]);
    } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
};
register_shutdown_function($cleanup);

try {
    db_query(
        "INSERT INTO `{$prefix}in_types` (`type`, `description`, `set_severity`) VALUES (?, ?, 0)",
        [$typeName, 'TDD test fixture type']
    );
    $typeId = (int) db_insert_id();
} catch (Exception $e) {
    bad('creating fixture incident type', $e->getMessage());
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok("created fixture incident type #{$typeId}");

$adminId = test_admin_user_id();
for ($i = 0; $i < 2; $i++) {
    $res = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => $marker . " fixture {$i}",
        'street'      => '221B Baker Street',
        'city'        => 'Cleveland',
        'state'       => 'OH',
        'description' => 'TDD type-drilldown fixture',
    ], $adminId);
    $tid = (int) ($res['id'] ?? 0);
    if ($tid > 0) { $ticketIds[] = $tid; }
}
if (count($ticketIds) !== 2) {
    bad('did not create exactly 2 fixture incidents of the test type', 'got ' . count($ticketIds));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok('created 2 fixture incidents of the test type through the real writer');

// ── Half 1: incident_summary carries the right link ─────────────────────
$summary = tdd_probe('incident_summary');
if (!is_array($summary) || empty($summary['rows'])) {
    bad('incident_summary probe returned no rows', substr(json_encode($summary), 0, 300));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
$idx = null;
foreach ($summary['rows'] as $i => $r) {
    if (($r[0] ?? '') === $typeName) { $idx = $i; break; }
}
if ($idx === null) {
    bad('fixture type not found in incident_summary rows', 'looked for ' . $typeName);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok("fixture type row found in incident_summary at index {$idx}");

if ((int) ($summary['rows'][$idx][1] ?? -1) === 2) {
    ok('incident_summary Total column for the fixture type is exactly 2');
} else {
    bad('incident_summary Total column mismatch', json_encode($summary['rows'][$idx][1] ?? null));
}

$typeDesc = null;
foreach (($summary['links'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'incident_type' && (int) ($d['col'] ?? -1) === 0) { $typeDesc = $d; break; }
}
if (!$typeDesc) {
    bad('no incident_type descriptor for column 0 in incident_summary links[]');
} else {
    ok('incident_summary links[] carries an incident_type descriptor for column 0');
    if ((int) ($typeDesc['ids'][$idx] ?? 0) === $typeId) {
        ok('incident_type link id at the fixture row matches the fixture type id');
    } else {
        bad('incident_type link id mismatch', 'got ' . ($typeDesc['ids'][$idx] ?? 'null') . " expected {$typeId}");
    }
}

// ── Half 2: incident_report, filtered by that id, returns ONLY those 2 ──
$filtered = tdd_probe('incident_report', 'this_year', [0, $typeId]);
// _reports_links_probe.php's argv(3) is incident_id (0 = don't filter by
// it); this test needs a probe variant that also forwards in_types_id.
if (!is_array($filtered)) {
    bad('incident_report (type-filtered) probe returned no usable payload');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
$rows = $filtered['rows'] ?? [];
$typesSeen = array_unique(array_column($rows, 2));
if (count($rows) === 2) {
    ok('incident_report, filtered by in_types_id, returns exactly 2 rows');
} else {
    bad('incident_report row count mismatch after type filter', 'got ' . count($rows) . ' expected 2');
}
if ($typesSeen === [$typeName] || $typesSeen === [0 => $typeName]) {
    ok('every returned row is the fixture type — no other type leaked through the filter');
} else {
    bad('a row of a different type leaked through the in_types_id filter', json_encode($typesSeen));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
