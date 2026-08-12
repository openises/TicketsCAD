<?php
/**
 * GH#51 follow-up (Eric, 2026-08-12) — Reports page column sort silently
 * broke for Incident #, Dispatched, and Clear on the Dispatch Log report
 * (and any other column whose values share a leading numeric prefix).
 *
 * assets/js/reports.js's sortByColumn() decided "is this column numeric"
 * with parseFloat(), which only reads a LEADING number: "26-0091" parses
 * to 26, "2026-06-15 14:22:09" parses to 2026. Since most rows in a
 * report share that leading year/prefix, almost every comparison tied at
 * the same number and the visible row order barely moved in either sort
 * direction -- clicking the header a second time didn't visibly reverse
 * anything.
 *
 * This project has no JS test runner (docs/CI-ENVIRONMENT.md), so this
 * is a hand-maintained PHP mirror of the fixed comparator -- same
 * convention as test_gh44_command_bar_status_synonyms.php's norm() port.
 * Keep it in sync with assets/js/reports.js's isWhollyNumeric() +
 * sortByColumn() comparator if that logic changes.
 */

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ── PHP mirror of the JS fix ────────────────────────────────────────────

function gh51_is_wholly_numeric($v): bool
{
    if ($v === null) return false;
    return (bool) preg_match('/^-?\d+(\.\d+)?$/', trim((string) $v));
}

function gh51_compare(array $rows, int $colIdx, bool $asc): array
{
    usort($rows, function ($a, $b) use ($colIdx, $asc) {
        $va = $a[$colIdx];
        $vb = $b[$colIdx];

        if (gh51_is_wholly_numeric($va) && gh51_is_wholly_numeric($vb)) {
            $na = (float) $va;
            $nb = (float) $vb;
            $cmp = $na <=> $nb;
            return $asc ? $cmp : -$cmp;
        }

        $sva = ($va !== null) ? strtolower((string) $va) : '';
        $svb = ($vb !== null) ? strtolower((string) $vb) : '';
        $cmp = strcmp($sva, $svb);
        if ($cmp === 0) return 0;
        return $asc ? ($cmp < 0 ? -1 : 1) : ($cmp < 0 ? 1 : -1);
    });
    return $rows;
}

// ── Old (broken) comparator, for a demonstrated-regression check ───────

function gh51_old_is_numeric($v): bool
{
    // parseFloat()-style: NaN only if the string doesn't START with a
    // number at all. PHP's (float) cast mirrors parseFloat's
    // leading-number behavior exactly ((float)'26-0091' === 26.0), so
    // the "is this numeric" test just needs to confirm there IS a
    // leading digit for the cast below to have parsed something real.
    return $v !== null && $v !== '' && preg_match('/^\s*-?\d/', (string) $v) === 1;
}

// ── Fixtures: the real Dispatch Log column shapes ───────────────────────

$incidentRows = [
    ['id' => 'A', 0 => '26-0091'],
    ['id' => 'B', 0 => '26-0074'],
    ['id' => 'C', 0 => '26-0084'],
];
// Reindex without the 'id' helper key for the comparator (which only
// looks at colIdx) -- kept as parallel arrays instead.
$incidentRowsPlain = [['26-0091'], ['26-0074'], ['26-0084']];

$dateRows = [
    ['2026-06-15 14:22:09'],
    ['2026-06-15 09:05:41'],
    ['2026-06-15 22:47:03'],
];

$mixedEmptyRows = [
    ['2026-06-15 14:22:09'],
    [''],
    ['2026-06-15 09:05:41'],
];

// 1. Incident # ("26-0091" style) — ascending sort must actually reorder.
$sortedAsc  = gh51_compare($incidentRowsPlain, 0, true);
$sortedDesc = gh51_compare($incidentRowsPlain, 0, false);
test('Incident # sorts ascending correctly',
    array_column($sortedAsc, 0) === ['26-0074', '26-0084', '26-0091'],
    json_encode(array_column($sortedAsc, 0)));
test('Incident # sorts descending correctly (and differs from ascending)',
    array_column($sortedDesc, 0) === ['26-0091', '26-0084', '26-0074']);
test('Incident # ascending and descending produce DIFFERENT orders (the bug: they used to be identical)',
    array_column($sortedAsc, 0) !== array_column($sortedDesc, 0));

// 2. Dispatched/Clear-style full datetime strings — chronological sort.
$dateAsc  = gh51_compare($dateRows, 0, true);
$dateDesc = gh51_compare($dateRows, 0, false);
test('datetime column sorts chronologically ascending',
    array_column($dateAsc, 0) === ['2026-06-15 09:05:41', '2026-06-15 14:22:09', '2026-06-15 22:47:03']);
test('datetime column sorts chronologically descending',
    array_column($dateDesc, 0) === ['2026-06-15 22:47:03', '2026-06-15 14:22:09', '2026-06-15 09:05:41']);

// 3. Empty cells (unit hasn't reached that step yet) don't crash and sort consistently.
$emptyAsc = gh51_compare($mixedEmptyRows, 0, true);
test('a report with empty-string cells (unreached dispatch step) sorts without error',
    count($emptyAsc) === 3);
test('empty string sorts first ascending (empty string < any date string)',
    $emptyAsc[0][0] === '');

// 4. Demonstrate the OLD comparator actually was broken for this exact
//    shape — a regression guard proving the fix targets a real defect,
//    not a hypothetical one.
$oldTies = 0;
for ($i = 0; $i < count($incidentRowsPlain); $i++) {
    for ($j = $i + 1; $j < count($incidentRowsPlain); $j++) {
        $va = $incidentRowsPlain[$i][0];
        $vb = $incidentRowsPlain[$j][0];
        if (gh51_old_is_numeric($va) && gh51_old_is_numeric($vb)) {
            $na = (float) $va; // PHP (float) cast mirrors JS parseFloat()'s leading-number behavior
            $nb = (float) $vb;
            if ($na === $nb) $oldTies++;
        }
    }
}
test('regression guard: the OLD parseFloat-style comparator really did tie every Incident # pair (proves the bug existed)',
    $oldTies === 3, "expected all 3 pairs to tie under the old logic, got {$oldTies}");

// 5. Non-numeric text columns (Type, Scope, Unit) are unaffected — string sort as before.
$textRows = [['Wildland Fire'], ['Medical'], ['Auto Accident']];
$textAsc = gh51_compare($textRows, 0, true);
test('plain text columns still sort alphabetically (no regression for working columns)',
    array_column($textAsc, 0) === ['Auto Accident', 'Medical', 'Wildland Fire']);

// ── Static check: the JS actually ships the fix ─────────────────────────
$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/js/reports.js');
test('reports.js requires the WHOLE value to be numeric before comparing numerically',
    strpos($js, 'isWhollyNumeric') !== false);
test('reports.js no longer trusts a bare parseFloat()/isNaN() pair to decide numeric-ness',
    !preg_match('/if\s*\(\s*!isNaN\(na\)\s*&&\s*!isNaN\(nb\)\s*\)/', $js));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
