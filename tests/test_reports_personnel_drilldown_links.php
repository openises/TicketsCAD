<?php
/**
 * Personnel report drill-down links (Eric, 2026-08-13): "Each of the
 * personnel reports should allow you to click on either the first or last
 * name and it needs to drill down into this persons personnel record."
 *
 * Covers all 6 personnel report types in api/reports.php — license_expirations,
 * roster_snapshot, dmr_inventory, membership_due, inactive_members,
 * time_summary — each of which uses the associative-row + structured-column
 * shape (not the positional-array shape the incident-style reports use), so
 * member_id has to survive a completely different code path: extracted from
 * $rows AFTER all of a case's sorting/filtering/annotation, not built
 * alongside the initial fetch loop (see the comment above the extraction
 * block in api/reports.php — license_expirations's usort() specifically
 * would desync a parallel array built earlier).
 *
 * Driven through the REAL endpoint (api/reports.php via a CLI subprocess
 * probe) and one real member fixture wired into member_callsigns (license
 * expiry) and member_time_entries (time logged), so every report type has
 * a genuine matching row rather than a hand-shaped one.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Personnel report drill-down links (member first/last name) ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function pdl_probe(string $report, string $period = 'this_year'): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_reports_links_probe.php')
         . ' ' . escapeshellarg($report) . ' ' . escapeshellarg($period);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

/** All {col,kind,ids} descriptors for a given kind. */
function pdl_links(array $payload, string $kind): array {
    $out = [];
    foreach (($payload['links'] ?? []) as $d) {
        if (($d['kind'] ?? '') === $kind) $out[] = $d;
    }
    return $out;
}

/** Index of the row matching our fixture's first/last name (cols 0/1), or null. */
function pdl_find_row(array $payload, string $last, string $first): ?int {
    foreach (($payload['rows'] ?? []) as $i => $r) {
        if (($r[0] ?? '') === $last && ($r[1] ?? '') === $first) return $i;
    }
    return null;
}

$marker  = 'PDL' . bin2hex(random_bytes(4));
$lastName = 'RLPTest_' . $marker;
$firstName = 'Persona';
$memberId = 0;

$cleanup = function () use (&$memberId, $prefix) {
    if (!$memberId) return;
    try {
        db_query("DELETE FROM `{$prefix}member_callsigns`    WHERE member_id = ?", [$memberId]);
        db_query("DELETE FROM `{$prefix}member_time_entries` WHERE member_id = ?", [$memberId]);
        db_query("DELETE FROM `{$prefix}member`              WHERE id = ?",        [$memberId]);
    } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
};
register_shutdown_function($cleanup);

$expiry = date('Y-m-d', strtotime('+20 days'));
$dueDate = date('Y-m-d', strtotime('+15 days'));

try {
    db_query(
        "INSERT INTO `{$prefix}member`
            (field2, field1, field4, field8, membership_due, notes)
         VALUES (?, ?, ?, 'No', ?, ?)",
        [$firstName, $lastName, 'TEST' . $marker, $dueDate, 'DMR ID: 54321 (TEST' . $marker . ')']
    );
    $memberId = (int) db_insert_id();
} catch (Exception $e) {
    bad('creating fixture member', $e->getMessage());
}

if ($memberId <= 0) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok("created fixture member #{$memberId}");

try {
    db_query(
        "INSERT INTO `{$prefix}member_callsigns` (member_id, callsign, expiry_date) VALUES (?, ?, ?)",
        [$memberId, 'TEST' . $marker, $expiry]
    );
    ok('added a member_callsigns row (license_expirations fixture)');
} catch (Exception $e) {
    bad('member_callsigns insert failed', $e->getMessage());
}

try {
    db_query(
        "INSERT INTO `{$prefix}member_time_entries`
            (member_id, started_at, ended_at, activity_type, status)
         VALUES (?, ?, ?, 'Drill', 'self_reported')",
        [$memberId, date('Y-m-d H:i:s', strtotime('-2 hours')), date('Y-m-d H:i:s', strtotime('-1 hour'))]
    );
    ok('added a member_time_entries row (time_summary fixture)');
} catch (Exception $e) {
    bad('member_time_entries insert failed', $e->getMessage());
}

/**
 * @param string $report the report=... value
 * @param bool   $expectRow whether our fixture must appear (some reports
 *               could theoretically miss it on an odd install state, but
 *               all 6 are engineered to include it here)
 */
function pdl_check_report(string $report, string $lastName, string $firstName): void {
    $payload = pdl_probe($report);
    if (!is_array($payload) || empty($payload['rows'])) {
        bad("{$report}: probe returned no rows", is_array($payload) ? 'rows empty' : 'no payload');
        return;
    }
    $idx = pdl_find_row($payload, $lastName, $firstName);
    if ($idx === null) {
        bad("{$report}: fixture member not found in rendered rows",
            'looked for last=' . $lastName . ' first=' . $firstName);
        return;
    }
    ok("{$report}: fixture member row found at index {$idx}");

    $descs = pdl_links($payload, 'member');
    $colsSeen = array_map(fn($d) => (int) $d['col'], $descs);
    $has0 = in_array(0, $colsSeen, true);
    $has1 = in_array(1, $colsSeen, true);
    if ($has0 && $has1) {
        ok("{$report}: links[] carries member descriptors for both last_name (col 0) and first_name (col 1)");
    } else {
        bad("{$report}: missing a member link column", 'cols seen: ' . implode(',', $colsSeen));
    }

    global $memberId;
    $allMatch = true;
    foreach ($descs as $d) {
        if ((int) ($d['ids'][$idx] ?? -1) !== $memberId) $allMatch = false;
    }
    if ($allMatch && !empty($descs)) {
        ok("{$report}: member link id at the fixture row matches the fixture member id");
    } else {
        bad("{$report}: member link id mismatch at the fixture row",
            json_encode(array_map(fn($d) => $d['ids'][$idx] ?? null, $descs)) . " expected {$memberId}");
    }
}

pdl_check_report('roster_snapshot', $lastName, $firstName);
pdl_check_report('dmr_inventory', $lastName, $firstName);
pdl_check_report('membership_due', $lastName, $firstName);
pdl_check_report('inactive_members', $lastName, $firstName);
pdl_check_report('time_summary', $lastName, $firstName);
pdl_check_report('license_expirations', $lastName, $firstName);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
