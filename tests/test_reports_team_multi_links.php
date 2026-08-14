<?php
/**
 * Team drill-down links, multi-team cells (Eric, 2026-08-13 follow-up
 * decision): roster_snapshot's Teams column joins ALL of a member's teams
 * into one comma-separated string ("Alpha, Bravo") — a single {col,ids}
 * link descriptor can't express "link each name to its own team", so this
 * uses a separate 'team_multi' kind: {col, kind:'team_multi', items} where
 * items[$r] is an array of {id,name} for row $r. assets/js/reports.js
 * renders one <a> per item instead of wrapping the whole joined string.
 *
 * Driven through the REAL endpoint (api/reports.php via the same CLI
 * subprocess probe as the other drill-down tests) with a member belonging
 * to TWO real teams, so the multi-item case is genuinely exercised, not
 * just a single-team happy path that would also pass with a naive
 * single-id design.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/team-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Reports drill-down links — team_multi (roster_snapshot Teams column) ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function tml_probe(string $report, string $period = 'this_year'): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_reports_links_probe.php')
         . ' ' . escapeshellarg($report) . ' ' . escapeshellarg($period);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

$marker = 'TML' . bin2hex(random_bytes(4));
$lastName = 'MultiTeam_' . $marker;
$firstName = 'Persona';
$memberId = 0;
$teamAId = 0;
$teamBId = 0;

$cleanup = function () use (&$memberId, &$teamAId, &$teamBId, $prefix) {
    try {
        if ($memberId) {
            db_query("DELETE FROM `{$prefix}team_members` WHERE member_id = ?", [$memberId]);
            db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]);
        }
        if ($teamAId) db_query("DELETE FROM `{$prefix}teams` WHERE id = ?", [$teamAId]);
        if ($teamBId) db_query("DELETE FROM `{$prefix}teams` WHERE id = ?", [$teamBId]);
    } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
};
register_shutdown_function($cleanup);

try {
    db_query("INSERT INTO `{$prefix}member` (field2, field1) VALUES (?, ?)", [$firstName, $lastName]);
    $memberId = (int) db_insert_id();

    // `teams` carries several legacy NOT-NULL-no-default columns
    // (`sub-group`, `ttypes_id`, `mission`, `leader`, `leader_dpty`, `by`,
    // `from`, `on`) that a bare `(team)` insert skips — that only "worked"
    // locally because of pre-existing schema drift, not on a truly fresh
    // install (CI). Go through the real writer instead of hand-rolling the
    // column list a second time.
    $adminId = test_admin_user_id();
    $teamA = team_upsert_internal(['name' => 'TML Team A ' . $marker], $adminId);
    if (!empty($teamA['errors'])) { throw new Exception('team A: ' . implode('; ', $teamA['errors'])); }
    $teamAId = (int) $teamA['id'];
    $teamB = team_upsert_internal(['name' => 'TML Team B ' . $marker], $adminId);
    if (!empty($teamB['errors'])) { throw new Exception('team B: ' . implode('; ', $teamB['errors'])); }
    $teamBId = (int) $teamB['id'];

    db_query("INSERT INTO `{$prefix}team_members` (member_id, team_id) VALUES (?, ?)", [$memberId, $teamAId]);
    db_query("INSERT INTO `{$prefix}team_members` (member_id, team_id) VALUES (?, ?)", [$memberId, $teamBId]);
    ok('created a member belonging to two teams');
} catch (Exception $e) {
    bad('fixture setup failed', $e->getMessage());
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$payload = tml_probe('roster_snapshot');
if (!is_array($payload) || empty($payload['rows'])) {
    bad('roster_snapshot probe returned no rows', substr(json_encode($payload), 0, 300));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$idx = null;
foreach ($payload['rows'] as $i => $r) {
    if (($r[0] ?? '') === $lastName && ($r[1] ?? '') === $firstName) { $idx = $i; break; }
}
if ($idx === null) {
    bad('fixture member not found in roster_snapshot rows');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok("fixture member row found at index {$idx}");

// Visible cell text is still the joined string (CSV/sort compatibility).
$visibleTeams = $payload['rows'][$idx][5] ?? null;
if (is_string($visibleTeams) && strpos($visibleTeams, 'TML Team A ' . $marker) !== false
    && strpos($visibleTeams, 'TML Team B ' . $marker) !== false) {
    ok('visible Teams cell still shows both team names as plain text (CSV/legacy compatibility preserved)');
} else {
    bad('visible Teams cell missing one or both team names', json_encode($visibleTeams));
}

$teamMultiDesc = null;
foreach (($payload['links'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'team_multi' && (int) ($d['col'] ?? -1) === 5) { $teamMultiDesc = $d; break; }
}
if (!$teamMultiDesc) {
    bad('no team_multi descriptor for column 5 in links[]', json_encode($payload['links'] ?? []));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok('links[] carries a team_multi descriptor for column 5 (Teams)');

$items = $teamMultiDesc['items'][$idx] ?? null;
if (!is_array($items) || count($items) !== 2) {
    bad('team_multi items at the fixture row are not a 2-element array', json_encode($items));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok('team_multi items at the fixture row has exactly 2 entries');

$gotIds = array_map(fn($it) => (int) ($it['id'] ?? 0), $items);
$gotNames = array_map(fn($it) => (string) ($it['name'] ?? ''), $items);
sort($gotIds);
$expectedIds = [$teamAId, $teamBId];
sort($expectedIds);

if ($gotIds === $expectedIds) {
    ok('both team ids in the items array match the fixture teams');
} else {
    bad('team ids mismatch', json_encode($gotIds) . ' expected ' . json_encode($expectedIds));
}

if (in_array('TML Team A ' . $marker, $gotNames, true) && in_array('TML Team B ' . $marker, $gotNames, true)) {
    ok('both team names in the items array match the fixture teams');
} else {
    bad('team names mismatch', json_encode($gotNames));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
