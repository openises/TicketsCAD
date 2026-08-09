<?php
/**
 * GH#44 (Chris Byrd, 2026-08-08): "/s 7152 os" failed with "not configured
 * on this install", on an install whose un_status table used "At Scene"
 * for this state instead of assets/js/command-bar.js's hardcoded "On
 * Scene". Adding "On Scene" as a new status worked around it -- the
 * underlying matcher had no way to know "At Scene" meant the same thing.
 *
 * Same class of problem as issue #18's 'en'/'enroute' gap, documented at
 * length in command-bar.js's own comments: a real, differently-worded
 * synonym some installs use for a concept the alias table only knows one
 * spelling of. The fix lets an alias resolve to an ARRAY of canonical
 * candidates, each tried through every tier of the matcher before falling
 * through to the next tier (so an exact match on candidate #2 still beats
 * a substring hit on candidate #1).
 *
 * This test is a literal PHP port of the matcher in
 * assets/js/command-bar.js's doStatusCommand() (kept in sync by hand --
 * same convention as this project's other JS-logic regression tests, see
 * tools/test_gh39_places_edit.php's placesUpdateSql() comment) rather than
 * a live browser/Node run, since this project's CI has no JS runtime in
 * its pipeline (see docs/CI-ENVIRONMENT.md).
 *
 * Usage: php tools/test_gh44_command_bar_status_synonyms.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#44 — command bar /s status synonyms (multi-candidate matcher) ===\n\n";

// Literal port of the STATUS_ALIASES table entries relevant here.
$STATUS_ALIASES = [
    'os'       => ['On Scene', 'At Scene'],
    'onscene'  => ['On Scene', 'At Scene'],
    'tx'       => 'Transporting',
    'en'       => 'Enroute',
];
$TWO_TOKEN_MAP = [
    'on scene' => ['On Scene', 'At Scene'],
    'at scene' => ['On Scene', 'At Scene'],
];

// Literal port of doStatusCommand()'s four-tier matcher, generalized to a
// candidate LIST (command-bar.js's canonCandidates). Returns the matched
// status_val, or null.
function matchStatus(array $canonCandidates, string $rawStatus, array $statuses): ?string {
    $canonLowers = array_map('strtolower', $canonCandidates);
    $rawLower = strtolower($rawStatus);

    foreach ($canonLowers as $c) {
        foreach ($statuses as $s) { if (strtolower($s) === $c) return $s; }
    }
    foreach ($canonLowers as $c) {
        foreach ($statuses as $s) { if (strtolower(trim($s)) === $c) return $s; }
    }
    if ($rawLower !== '') {
        foreach ($statuses as $s) { if (strtolower(trim($s)) === $rawLower) return $s; }
        foreach ($statuses as $s) { if (strpos(strtolower($s), $rawLower) !== false) return $s; }
    }
    foreach ($canonLowers as $c) {
        foreach ($statuses as $s) {
            $v = strtolower($s);
            if (strpos($v, $c) !== false || strpos($c, $v) !== false) return $s;
        }
    }
    return null;
}

// ── 1. Chris's exact repro: alias 'os', install configured "At Scene" only.
$candidates = is_array($STATUS_ALIASES['os']) ? $STATUS_ALIASES['os'] : [$STATUS_ALIASES['os']];
$result = matchStatus($candidates, 'os', ['Available', 'Dispatched', 'At Scene', 'Transporting']);
($result === 'At Scene')
    ? ok('"os" resolves against an install configured with "At Scene" (Chris\'s exact repro)')
    : bad('os -> At Scene', var_export($result, true));

// ── 2. The two-token "at scene" form (typed out instead of the shorthand).
$result2 = matchStatus($TWO_TOKEN_MAP['at scene'], 'at scene', ['Available', 'At Scene']);
($result2 === 'At Scene')
    ? ok('"at scene" (typed out) resolves the same way as the "os" shorthand')
    : bad('at scene -> At Scene', var_export($result2, true));

// ── 3. Un-regressed: an install using the ORIGINAL "On Scene" spelling
//      still matches — the fix must not favor the new candidate over the
//      old one when both exist as real config possibilities.
$result3 = matchStatus($candidates, 'os', ['Available', 'On Scene']);
($result3 === 'On Scene')
    ? ok('"os" still resolves against an install configured with the original "On Scene"')
    : bad('os -> On Scene (regression check)', var_export($result3, true));

// ── 4. Exact match must still beat substring, even across candidates —
//      an install with BOTH a real "On Scene" row and an unrelated row
//      that merely CONTAINS "at scene" as a substring must not let the
//      substring tier fire before the exact tier has tried every candidate.
$result4 = matchStatus($candidates, 'os', ['Combat Scene Support', 'On Scene']);
($result4 === 'On Scene')
    ? ok('exact match on candidate #1 beats a substring hit belonging to unrelated config text')
    : bad('exact-beats-substring across candidates', var_export($result4, true));

// ── 5. Un-regressed: single-candidate aliases (not touched by this fix)
//      still resolve exactly as before — 'tx' against a short-form "TX"
//      config (this is literally issue #18's original repro).
$candidatesTx = is_array($STATUS_ALIASES['tx']) ? $STATUS_ALIASES['tx'] : [$STATUS_ALIASES['tx']];
$result5 = matchStatus($candidatesTx, 'tx', ['Available', 'TX']);
($result5 === 'TX')
    ? ok('single-candidate aliases (e.g. "tx") are unaffected by the multi-candidate change')
    : bad('tx -> TX (issue #18 non-regression)', var_export($result5, true));

// ── 6. No match at all — the "not configured" error path (both messages
//      list ALL candidates tried, so the error is actually actionable).
$result6 = matchStatus($candidates, 'os', ['Available', 'Dispatched']);
($result6 === null)
    ? ok('no match when neither "On Scene" nor "At Scene" is configured — falls through to the error path')
    : bad('expected null (no match)', var_export($result6, true));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
