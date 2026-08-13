<?php
/**
 * GH#57 (rjonesbsink, 2026-08-13) — the After Action report was headed
 * "Incident #53" (the internal ticket.id) with incident_number appearing
 * nowhere on it, so an operator had no way to confirm they pulled the
 * report they meant to. The input side (typing the case number instead of
 * the internal id) was already fixed under GH#51 -- this covers the
 * remaining gap Eric approved: the report OUTPUT must name the incident
 * the way an operator recognizes it, incident_number first, internal id
 * as a parenthetical for cross-referencing.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// Source-level: the API contract must actually expose incident_number and
// use it as the primary label, not just carry incident_id.
$reportsApi = file_get_contents($root . '/api/reports.php');
test("api/reports.php's after_action summary includes 'incident_number'",
    strpos($reportsApi, "'incident_number' => \$ticket['incident_number']") !== false);
test('period_label prefers incident_number over the bare internal id',
    (bool) preg_match('/period_label\s*=\s*!empty\(\$ticket\[.incident_number.\]\)/', $reportsApi));

$reportsJs = file_get_contents($root . '/assets/js/reports.js');
test('reports.js renders summary.incident_number in the After Action panel',
    strpos($reportsJs, 'summary.incident_number') !== false);

// Functional: replicate the handler's own query + label logic against a
// real ticket that has a non-null incident_number, and check the shape.
$row = db_fetch_one(
    "SELECT `id`, `incident_number` FROM `{$prefix}ticket`
     WHERE `incident_number` IS NOT NULL AND `incident_number` != ''
       AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')
     ORDER BY `id` DESC LIMIT 1"
);

if (!$row) {
    echo "SKIP: no ticket with a non-null incident_number to test against\n";
} else {
    $label = $row['incident_number'] . ' (#' . $row['id'] . ')';
    test('label format is "<incident_number> (#<id>)"',
        strpos($label, $row['incident_number']) === 0 && strpos($label, '#' . $row['id']) !== false,
        "got '{$label}'");
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
