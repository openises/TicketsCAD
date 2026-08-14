<?php
/**
 * Reports drill-down links vs. client-side column sort (2026-08-13,
 * self-caught while building the GH#51 follow-up drill-down feature).
 *
 * assets/js/reports.js's renderRows() turns a linked cell into an
 * <a href> using reportData.links[].ids, which is ROW-PARALLEL — ids[r] is
 * the drill-down id for reportData.rows[r]. sortByColumn() used to sort a
 * COPY of reportData.rows in place and never touch reportData.links, so
 * after a user clicked any column header to sort, every link on the page
 * would point at the row that USED TO be at that position, not the row now
 * shown there. This would have shipped invisibly -- the automated backend
 * tests never sort, and the one live browser check that verified rendering
 * never clicked a header either.
 *
 * Fixed: sortByColumn() now sorts a permutation of INDICES and applies that
 * SAME permutation to reportData.rows AND to every links[].ids array.
 *
 * This test extracts the REAL sortByColumn() function body from the shipped
 * file (regex, same technique as tests/test_gh52_incident_status_ui.php)
 * and executes it under node with a fake reportData shaped exactly like a
 * real drill-down response, so what's under test is the actual sort logic
 * — not a hand-written re-implementation of what it's supposed to do.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$jsPath = $root . '/assets/js/reports.js';
$jsSrc = (string) file_get_contents($jsPath);

if (preg_match('/function sortByColumn\(colIdx\)\s*\{(.*?)\r?\n    \}\r?\n/s', $jsSrc, $m) !== 1) {
    echo "[FAIL] could not isolate sortByColumn() — file structure changed?\n";
    echo "\n0 passed, 1 failed\n";
    exit(1);
}
$fnBody = $m[1];
$pass++; echo "[PASS] isolated sortByColumn()'s function body\n";

test('reads reportData.rows without first snapshotting a disconnected copy',
    strpos($fnBody, 'reportData.rows.slice()') === false,
    'a .slice() copy at the top is the old (buggy) shape this test guards against');
test('builds an index permutation ("order") rather than sorting rows directly',
    (bool) preg_match('/order\.sort\(/', $fnBody));
test('permutes reportData.links[].ids using the same order the rows were permuted by',
    (bool) preg_match('/links\[li\]\.ids\s*=\s*order\.map/', $fnBody) || (bool) preg_match('/\.ids\s*=\s*order\.map/', $fnBody));
test('the render call at the end uses reportData.rows (freshly permuted), not a stale local copy',
    (bool) preg_match('/renderRows\(\s*reportData\.rows\s*\)\s*;\s*\}\s*$/', "function x(){" . $fnBody . "}"));

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

// Fake response shaped like a real 'incident_report' drill-down payload:
// 3 rows, an 'incident' link on column 0. Rows are seeded OUT of sorted
// order so sortByColumn() actually has to move something.
$harness = sys_get_temp_dir() . '/tcad_reports_sort_harness_' . getmypid() . '.js';
$fnBodyJs = $fnBody; // embed verbatim — it's real production JS, not user input
$js = <<<JS
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var reportData = {
    columns: ['ID', 'Scope'],
    rows: [
        ['26-0030', 'Charlie incident'],
        ['26-0010', 'Alpha incident'],
        ['26-0020', 'Bravo incident']
    ],
    links: [
        { col: 0, kind: 'incident', ids: [300, 100, 200] },
        { col: 1, kind: 'team_multi', items: [
            [{id: 30, name: 'Charlie-team'}],
            [{id: 10, name: 'Alpha-team'}],
            [{id: 20, name: 'Bravo-team'}]
        ] }
    ]
};
var sortColumn = -1;
var sortAsc = true;
var renderRowsCalledWith = null;
function renderRows(rows) { renderRowsCalledWith = rows; }
var reportTableHead = { querySelectorAll: function () { return []; } };

function sortByColumn(colIdx) {
$fnBodyJs
}

// Sort by column 0 (the incident-number text column) ascending.
sortByColumn(0);

check('rows sorted ascending by column 0',
    JSON.stringify(reportData.rows) === JSON.stringify([
        ['26-0010', 'Alpha incident'],
        ['26-0020', 'Bravo incident'],
        ['26-0030', 'Charlie incident']
    ]),
    JSON.stringify(reportData.rows));

// THE regression, executed: id 100 belongs to '26-0010' (now row 0), 200 to
// '26-0020' (now row 1), 300 to '26-0030' (now row 2). Before the fix, ids
// stayed [300, 100, 200] — row 0 ('26-0010') would have linked to ticket 300
// (which is actually '26-0030'), pointing every sorted row at the WRONG incident.
check('links[0].ids permuted to match the new row order',
    JSON.stringify(reportData.links[0].ids) === JSON.stringify([100, 200, 300]),
    JSON.stringify(reportData.links[0].ids));

// team_multi carries 'items' (array-of-arrays) instead of 'ids' — same
// row-parallel permutation, must move in lockstep with the sort too.
check('links[1].items (team_multi) permuted to match the new row order',
    JSON.stringify(reportData.links[1].items) === JSON.stringify([
        [{id: 10, name: 'Alpha-team'}],
        [{id: 20, name: 'Bravo-team'}],
        [{id: 30, name: 'Charlie-team'}]
    ]),
    JSON.stringify(reportData.links[1].items));

check('renderRows() was called with the freshly sorted rows, not a stale copy',
    renderRowsCalledWith === reportData.rows);

// Sort the SAME column again -> descending toggle. Confirms the permutation
// logic is stable across repeated sorts, not just a one-shot fixture match.
sortByColumn(0);
check('second click on the same column reverses to descending',
    JSON.stringify(reportData.rows) === JSON.stringify([
        ['26-0030', 'Charlie incident'],
        ['26-0020', 'Bravo incident'],
        ['26-0010', 'Alpha incident']
    ]),
    JSON.stringify(reportData.rows));
check('links[0].ids re-permuted correctly on the second sort too',
    JSON.stringify(reportData.links[0].ids) === JSON.stringify([300, 200, 100]),
    JSON.stringify(reportData.links[0].ids));

console.log(out.join('\\n'));
JS;
file_put_contents($harness, $js);
$raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);

if (!is_string($raw) || strpos($raw, '|') === false) {
    test('node harness ran sortByColumn()', false, trim((string) $raw));
} else {
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 2) { continue; }
        test('[js] ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
    }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
