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
 * GH#44 round 2 (rjonesbsink, 2026-08-09): a DIFFERENT install split the
 * short code and the human label across two columns -- status_val='ONS',
 * description='On Scene'. The substring tiers compared raw strings, so the
 * space in 'on scene' sat exactly where it would need to align with 'ONS'
 * to match, and neither string contained the other. Fixed two ways: (a)
 * strip everything but letters before the substring comparisons (norm()),
 * mirroring classifyAvailability()'s .replace(/[^a-z]/g, '') in units.js
 * (same bug shape as GH#48); (b) also try `description`, not just
 * `status_val`, at every tier.
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

function norm(string $s): string { return preg_replace('/[^a-z]/', '', strtolower($s)); }

/**
 * Literal port of doStatusCommand()'s four-tier matcher.
 * $statuses: list of ['status_val' => string, 'description' => string].
 * Returns the matched status_val, or null.
 */
function matchStatus(array $canonCandidates, string $rawStatus, array $statuses): ?string {
    $canonLowers = array_map('strtolower', $canonCandidates);
    $canonNorms  = array_map('norm', $canonCandidates);
    $rawLower    = strtolower($rawStatus);
    $rawNorm     = norm($rawStatus);

    foreach ($canonLowers as $c) {
        foreach ($statuses as $s) {
            if (strtolower($s['status_val']) === $c || strtolower($s['description']) === $c) return $s['status_val'];
        }
    }
    foreach ($canonLowers as $c) {
        foreach ($statuses as $s) {
            if (strtolower(trim($s['status_val'])) === $c || strtolower(trim($s['description'])) === $c) return $s['status_val'];
        }
    }
    if ($rawLower !== '') {
        foreach ($statuses as $s) {
            if (strtolower(trim($s['status_val'])) === $rawLower || strtolower(trim($s['description'])) === $rawLower) return $s['status_val'];
        }
        if ($rawNorm !== '') {
            foreach ($statuses as $s) {
                $v = norm($s['status_val']);
                $d = norm($s['description']);
                if (($v !== '' && strpos($v, $rawNorm) !== false) || ($d !== '' && strpos($d, $rawNorm) !== false)) {
                    return $s['status_val'];
                }
            }
        }
    }
    foreach ($canonNorms as $c) {
        if ($c === '') continue;
        foreach ($statuses as $s) {
            $v = norm($s['status_val']);
            $d = norm($s['description']);
            if (($v !== '' && (strpos($v, $c) !== false || strpos($c, $v) !== false))
                || ($d !== '' && (strpos($d, $c) !== false || strpos($c, $d) !== false))) {
                return $s['status_val'];
            }
        }
    }
    return null;
}

/** Build a statuses list from status_val-only strings (description defaults to same value, matching most real installs). */
function svOnly(array $vals): array {
    return array_map(function ($v) { return ['status_val' => $v, 'description' => $v]; }, $vals);
}

// ── 1. Chris's exact repro: alias 'os', install configured "At Scene" only.
$candidates = is_array($STATUS_ALIASES['os']) ? $STATUS_ALIASES['os'] : [$STATUS_ALIASES['os']];
$result = matchStatus($candidates, 'os', svOnly(['Available', 'Dispatched', 'At Scene', 'Transporting']));
($result === 'At Scene')
    ? ok('"os" resolves against an install configured with "At Scene" (Chris\'s exact repro)')
    : bad('os -> At Scene', var_export($result, true));

// ── 2. The two-token "at scene" form (typed out instead of the shorthand).
$result2 = matchStatus($TWO_TOKEN_MAP['at scene'], 'at scene', svOnly(['Available', 'At Scene']));
($result2 === 'At Scene')
    ? ok('"at scene" (typed out) resolves the same way as the "os" shorthand')
    : bad('at scene -> At Scene', var_export($result2, true));

// ── 3. Un-regressed: an install using the ORIGINAL "On Scene" spelling
//      still matches — the fix must not favor the new candidate over the
//      old one when both exist as real config possibilities.
$result3 = matchStatus($candidates, 'os', svOnly(['Available', 'On Scene']));
($result3 === 'On Scene')
    ? ok('"os" still resolves against an install configured with the original "On Scene"')
    : bad('os -> On Scene (regression check)', var_export($result3, true));

// ── 4. Exact match must still beat substring, even across candidates —
//      an install with BOTH a real "On Scene" row and an unrelated row
//      that merely CONTAINS "at scene" as a substring must not let the
//      substring tier fire before the exact tier has tried every candidate.
$result4 = matchStatus($candidates, 'os', svOnly(['Combat Scene Support', 'On Scene']));
($result4 === 'On Scene')
    ? ok('exact match on candidate #1 beats a substring hit belonging to unrelated config text')
    : bad('exact-beats-substring across candidates', var_export($result4, true));

// ── 5. Un-regressed: single-candidate aliases (not touched by this fix)
//      still resolve exactly as before — 'tx' against a short-form "TX"
//      config (this is literally issue #18's original repro).
$candidatesTx = is_array($STATUS_ALIASES['tx']) ? $STATUS_ALIASES['tx'] : [$STATUS_ALIASES['tx']];
$result5 = matchStatus($candidatesTx, 'tx', svOnly(['Available', 'TX']));
($result5 === 'TX')
    ? ok('single-candidate aliases (e.g. "tx") are unaffected by the multi-candidate change')
    : bad('tx -> TX (issue #18 non-regression)', var_export($result5, true));

// ── 6. No match at all — the "not configured" error path (both messages
//      list ALL candidates tried, so the error is actually actionable).
$result6 = matchStatus($candidates, 'os', svOnly(['Available', 'Dispatched']));
($result6 === null)
    ? ok('no match when neither "On Scene" nor "At Scene" is configured — falls through to the error path')
    : bad('expected null (no match)', var_export($result6, true));

// ── 7. GH#44 round 2 — rjonesbsink's exact repro: status_val='ONS',
//      description='On Scene'. Neither raw string contains the other
//      (the space in "on scene" defeats a match against "ons"); the
//      normalized substring tier must bridge it.
$result7 = matchStatus($candidates, 'os', [
    ['status_val' => 'ONS', 'description' => 'On Scene'],
    ['status_val' => 'ENR', 'description' => 'En Route'],
]);
($result7 === 'ONS')
    ? ok('GH#44 round 2: "os" resolves against status_val=ONS/description="On Scene" via normalized substring')
    : bad('os -> ONS (rjonesbsink repro)', var_export($result7, true));

// ── 8. description-only match — an alias whose canonical form matches the
//      DESCRIPTION exactly but has nothing in common with status_val at all
//      (a fully opaque short code) must still resolve.
$result8 = matchStatus(['Available'], 'av', [
    ['status_val' => 'A1', 'description' => 'Available'],
    ['status_val' => 'U1', 'description' => 'Unavailable'],
]);
($result8 === 'A1')
    ? ok('an alias matches via description exact-match when status_val shares nothing with it')
    : bad('av -> A1 via description', var_export($result8, true));

// ── 9. Un-regressed: normalization must not create a FALSE match — an
//      empty status_val/description never matches via the empty-substring
//      trap (norm('') is '', and '' would be "found" in anything).
$result9 = matchStatus($candidates, 'os', [
    ['status_val' => '', 'description' => ''],
    ['status_val' => 'On Scene', 'description' => ''],
]);
($result9 === 'On Scene')
    ? ok('empty status_val/description rows are never spuriously matched by the normalized substring tier')
    : bad('empty-row guard', var_export($result9, true));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
