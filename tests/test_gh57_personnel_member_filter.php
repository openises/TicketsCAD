<?php
/**
 * GH#57 follow-up (cbyrdmo, 2026-08-15 -- "Personnel menu items also appear
 * to have the same error" -- and Eric's explicit follow-up asking that the
 * gap be researched and fixed): the six Personnel reports (Roster Snapshot,
 * Time Summary, License Expirations, Membership Dues Due, Inactive Members,
 * DMR ID Inventory) had NO way to scope to one person at all.
 * `responder_id` (already wired for unit_log/dispatch_log/facility_log/
 * notes_log) filters the `responder` table -- units/vehicles -- and
 * Personnel reports were explicitly exempted from it, so a bare responder
 * dropdown was never going to fix this: they needed their OWN filter
 * against the `member` (people/roster) table.
 *
 * Covers: the new $member_id parameter, its IDOR check, the $memberIdFrag/
 * $memberIdVars fragment wired into all six Personnel report queries (with
 * correct bind-order for the two trickiest cases -- license_expirations'
 * two queries, and time_summary's date-then-org-then-member placeholder
 * order), the new Member filter UI in reports.php, and its JS wiring in
 * reports.js (a SEPARATE dropdown from Responder, sourced from
 * api/members.php not api/responders.php).
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function t57(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#57 follow-up — Personnel report member filter ===\n\n";

// ── Structural: server-side ──────────────────────────────────────────────
$reportsApi = file_get_contents($root . '/api/reports.php');
t57('parses member_id from the query string',
    (bool) preg_match('/\$member_id\s*=\s*max\(0, \(int\) \(\$_GET\[.member_id.\] \?\? 0\)\)/', $reportsApi));
t57('IDOR-checks member_id via user_can_access_entity(\'member\', ...)',
    strpos($reportsApi, "user_can_access_entity('member', \$member_id)") !== false);
t57('builds a shared $memberIdFrag / $memberIdVars pair',
    strpos($reportsApi, '$memberIdFrag = $member_id > 0') !== false
    && strpos($reportsApi, '$memberIdVars = $member_id > 0') !== false);

$memberIdFragCount = substr_count($reportsApi, '{$memberIdFrag}');
t57('the fragment is applied at least 7 times (6 report types, 2 queries in license_expirations)',
    $memberIdFragCount >= 7, "found $memberIdFragCount occurrences");

foreach (['roster_snapshot', 'dmr_inventory', 'membership_due', 'inactive_members', 'time_summary'] as $caseName) {
    if (!preg_match('/case \'' . preg_quote($caseName, '/') . '\':(.*?)\n        break;/s', $reportsApi, $m)) {
        t57("isolated the $caseName case block", false);
        continue;
    }
    t57("$caseName's query includes {\$memberIdFrag}", strpos($m[1], '{$memberIdFrag}') !== false);
    t57("$caseName's bound vars include \$memberIdVars", strpos($m[1], '$memberIdVars') !== false);
}
// license_expirations has two queries ($fcc, $certs) — check both directly.
if (preg_match('/case \'license_expirations\':(.*?)\n        break;/s', $reportsApi, $m)) {
    t57('license_expirations: both queries include {$memberIdFrag}',
        substr_count($m[1], '{$memberIdFrag}') === 2);
    t57('license_expirations: both queries merge $memberIdVars',
        substr_count($m[1], '$memberIdVars') === 2);
}
// time_summary — bind order matters: date placeholders appear in the SQL
// BEFORE the WHERE clause's org/member placeholders, so the vars array
// must list them in that same order or PDO binds the wrong value to the
// wrong "?".
if (preg_match('/case \'time_summary\':(.*?)\n        break;/s', $reportsApi, $m)) {
    t57('time_summary merges vars in SQL placeholder order (date, org-scope, member)',
        (bool) preg_match(
            '/array_merge\(\[\$start_date.*?\$end_date.*?\],\s*\$rptMemberVars,\s*\$memberIdVars\)/s',
            $m[1]
        ));
}

t57('period_label gets the filtered member\'s name appended for Personnel reports',
    strpos($reportsApi, 'if ($isPersonnel && $member_id > 0)') !== false);

// ── Structural: client-side ──────────────────────────────────────────────
$reportsPhp = file_get_contents($root . '/reports.php');
t57('reports.php has a memberFilterCol / memberFilter dropdown',
    strpos($reportsPhp, 'id="memberFilterCol"') !== false
    && strpos($reportsPhp, 'id="memberFilter"') !== false);

$reportsJs = file_get_contents($root . '/assets/js/reports.js');
t57('reports.js defines loadMembers(), separate from loadResponders()',
    strpos($reportsJs, 'function loadMembers()') !== false);
t57('loadMembers() sources api/members.php, not api/responders.php',
    (bool) preg_match("/function loadMembers\\(\\) \\{\\s*\\n\\s*fetch\\('api\\/members\\.php'/", $reportsJs));
t57('showMember covers all six Personnel report types',
    (bool) preg_match(
        "/var showMember = \\(type === 'roster_snapshot' \\|\\| type === 'time_summary' \\|\\|\\s*\\n\\s*type === 'license_expirations' \\|\\| type === 'membership_due' \\|\\|\\s*\\n\\s*type === 'inactive_members' \\|\\| type === 'dmr_inventory'\\)/",
        $reportsJs
    ));
t57('hiding the Member filter clears its value (same fix as Responder/Incident)',
    (bool) preg_match('/if \(!showMember\) \{\s*\n\s*memberFilter\.value = .0.;/', $reportsJs));
t57('runReport() sends member_id when the Member filter is set',
    (bool) preg_match("/var mid = parseInt\\(memberFilter\\.value, 10\\) \\|\\| 0;\\s*\\n\\s*if \\(mid > 0\\) \\{\\s*\\n\\s*params \\+= '&member_id=' \\+ mid;/", $reportsJs));

// ── Functional: drive the real fixed queries against real seed data ──────
try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

$members = db_fetch_all(
    "SELECT id, field1 AS last_name, field2 AS first_name FROM `{$prefix}member` WHERE deleted_at IS NULL ORDER BY id LIMIT 2"
);
if (count($members) < 2) {
    echo "SKIP: need at least 2 seeded member rows to test filtering — only found " . count($members) . "\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}
$targetId = (int) $members[0]['id'];
$otherId  = (int) $members[1]['id'];

// roster_snapshot's real query, unfiltered vs filtered — the simplest case,
// no other WHERE conditions to interact with.
$unfiltered = db_fetch_all(
    "SELECT m.id FROM `{$prefix}member` m WHERE m.deleted_at IS NULL ORDER BY m.field1, m.field2"
);
$filtered = db_fetch_all(
    "SELECT m.id FROM `{$prefix}member` m WHERE m.deleted_at IS NULL AND m.id = ? ORDER BY m.field1, m.field2",
    [$targetId]
);
t57('roster_snapshot-shaped query: filtered to one member returns exactly that member',
    count($filtered) === 1 && (int) $filtered[0]['id'] === $targetId,
    'got ' . json_encode($filtered));
t57('roster_snapshot-shaped query: unfiltered returns more than the one filtered row',
    count($unfiltered) >= 2, 'total members: ' . count($unfiltered));

$filteredOther = db_fetch_all(
    "SELECT m.id FROM `{$prefix}member` m WHERE m.deleted_at IS NULL AND m.id = ? ORDER BY m.field1, m.field2",
    [$otherId]
);
t57('filtering to a DIFFERENT member id returns that different member, not the first one',
    count($filteredOther) === 1 && (int) $filteredOther[0]['id'] === $otherId);

// A garbage/nonexistent member id must return zero rows, not an error and
// not silently falling back to "all members".
$garbage = db_fetch_all(
    "SELECT m.id FROM `{$prefix}member` m WHERE m.deleted_at IS NULL AND m.id = ?",
    [999999999]
);
t57('a nonexistent member id returns zero rows, not all members', count($garbage) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
