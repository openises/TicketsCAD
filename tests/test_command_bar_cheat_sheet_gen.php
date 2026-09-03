<?php
/**
 * test_command_bar_cheat_sheet_gen.php — GH#136 (rjonesbsink).
 *
 * docs/COMMAND-BAR-CHEAT-SHEET.pdf and docs/command-bar-cheat-sheet-print.html
 * both carried a footer claiming to be "generated from
 * assets/js/command-bar.js" with no generator anywhere under tools/ to back
 * that claim -- so both drifted silently the moment a command was renamed
 * (Phase 139's /log reassignment) or added (Settings deep links, /major,
 * /road, /radio were all missing entirely). tools/gen_command_bar_cheat_sheet.php
 * makes the claim true; this test proves the generator itself is correct
 * and that its --check mode actually gates on real drift, not just on
 * whether the script runs without a fatal error.
 */

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/gen_command_bar_cheat_sheet.php';
$php  = PHP_BINARY ?: 'php';

echo "=== Command bar cheat sheet generator ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

function cbc_run(string $php, string $tool, array $args): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $outLines, $code);
    return [$code, implode("\n", $outLines)];
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The committed docs are current relative to the live registry --\n";
// ─────────────────────────────────────────────────────────────────────────
// THE regression this whole tool exists to catch: run --check against the
// real, committed docs. This is the assertion that fails the suite the
// next time a command is renamed/added/removed without regenerating.
[$code, $out] = cbc_run($php, $tool, ['--check']);
is_true($code === 0, 'the real, committed cheat sheet docs are current', $out);
is_true(strpos($out, '[OK]') !== false, '--check reports [OK] on a current tree', $out);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. --check genuinely detects drift (not just \"the script ran\") --\n";
// ─────────────────────────────────────────────────────────────────────────
// Build an isolated fixture: a copy of the real repo's js/docs subset, with
// the JS file's COMMANDS array mutated (a description changed, matching
// EXACTLY the historical bug -- a real command whose text changed with
// nothing regenerating the docs). Never touches the real committed files.
$tmp = sys_get_temp_dir() . '/tcad_cbc_test_' . getmypid();
@mkdir($tmp . '/assets/js', 0777, true);
@mkdir($tmp . '/docs', 0777, true);

$realJs = (string) file_get_contents($base . '/assets/js/command-bar.js');
$realMd = (string) file_get_contents($base . '/docs/COMMAND-BAR-CHEAT-SHEET.md');
$realHtml = (string) file_get_contents($base . '/docs/command-bar-cheat-sheet-print.html');

// Fixture A: docs match a PRE-mutation copy of the JS -- --check must pass.
file_put_contents($tmp . '/assets/js/command-bar.js', $realJs);
file_put_contents($tmp . '/docs/COMMAND-BAR-CHEAT-SHEET.md', $realMd);
file_put_contents($tmp . '/docs/command-bar-cheat-sheet-print.html', $realHtml);
[$codeA, $outA] = cbc_run($php, $tool, ['--check', '--root=' . $tmp]);
is_true($codeA === 0, 'a fixture whose docs match its own JS passes --check', $outA);

// Fixture B: same docs, but the JS's /zello description is now different
// (Phase 139-shaped mutation -- a real command's text changed underneath
// stale docs). --check must now fail, and must say WHICH file is stale.
$mutatedJs = str_replace(
    "description: 'Toggle the Zello radio panel'",
    "description: 'Open the Zello radio drawer (renamed for this test)'",
    $realJs
);
is_true($mutatedJs !== $realJs, 'the fixture mutation actually changed the JS source (sanity check on the test itself)');
file_put_contents($tmp . '/assets/js/command-bar.js', $mutatedJs);
[$codeB, $outB] = cbc_run($php, $tool, ['--check', '--root=' . $tmp]);
is_true($codeB === 1, 'a mutated command description is detected as drift by --check', $outB);
is_true(strpos($outB, 'stale') !== false, '--check names the docs as stale, not a generic error', $outB);

// Fixture C: regenerating against the mutated JS, then re-checking, must
// pass again -- proves the write path and the check path agree with each
// other, not just that each one individually runs.
[$codeC1, $outC1] = cbc_run($php, $tool, ['--root=' . $tmp]);
is_true($codeC1 === 0, 'regenerating against the mutated JS succeeds', $outC1);
[$codeC2, $outC2] = cbc_run($php, $tool, ['--check', '--root=' . $tmp]);
is_true($codeC2 === 0, 'the freshly-regenerated docs pass --check against the SAME mutated JS', $outC2);
$regenMd = (string) file_get_contents($tmp . '/docs/COMMAND-BAR-CHEAT-SHEET.md');
is_true(strpos($regenMd, 'Open the Zello radio drawer (renamed for this test)') !== false,
    'the regenerated doc actually carries the mutated description text');

@unlink($tmp . '/assets/js/command-bar.js');
@rmdir($tmp . '/assets/js');
@rmdir($tmp . '/assets');
@unlink($tmp . '/docs/COMMAND-BAR-CHEAT-SHEET.md');
@unlink($tmp . '/docs/command-bar-cheat-sheet-print.html');
@rmdir($tmp . '/docs');
@rmdir($tmp);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Regression coverage for the two parsing bugs found while building this --\n";
// ─────────────────────────────────────────────────────────────────────────
// (a) A first draft double-escaped a literal backtick as \` inside a PHP
//     double-quoted string, which PHP has no such escape for -- it renders
//     as a literal backslash followed by a backtick. Every command row in
//     the real .md must render a clean markdown code span.
is_true(strpos($realMd, '\\`') === false,
    'the generated markdown never contains a literal backslash-backtick artifact');
is_true((bool) preg_match('/\| `\/new` \|/', $realMd),
    'a command cell renders as a clean markdown code span (`/new`), not \\`/new\\`');

// (b) The STATUS_ALIASES parser originally used a key charclass of
//     [a-z ]+ (missing hyphen/underscore) and only matched a plain-string
//     value -- so every hyphenated/underscored alias AND the entire
//     "On Scene"/"At Scene" entry (whose value is an array, from GH#44's
//     multi-site-synonym fix) silently vanished. All of these must survive.
foreach (['on-scene', 'on_scene', 'at-facility', 'in-quarters'] as $needle) {
    is_true(strpos($realMd, '`' . $needle . '`') !== false,
        "hyphen/underscore alias \"$needle\" is present in the generated doc");
}
is_true(strpos($realMd, '| On Scene |') !== false,
    'the "On Scene" status (an array-valued STATUS_ALIASES entry) is not silently dropped');

// (c) A second bug in the HTML renderer double-escaped the literal
//     "&mdash;" placeholder through htmlspecialchars(), producing
//     "&amp;mdash;" on screen instead of an em dash.
$realHtmlNow = (string) file_get_contents($base . '/docs/command-bar-cheat-sheet-print.html');
is_true(strpos($realHtmlNow, 'amp;mdash') === false,
    'the generated HTML never double-escapes the no-alias placeholder into &amp;mdash;');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Every command the app actually ships appears somewhere in the doc --\n";
// ─────────────────────────────────────────────────────────────────────────
// A command absent from every $CATEGORIES list AND every $SPECIAL_SYNTAX
// entry would previously vanish with no trace. Confirm none of today's
// real commands hit that gap by checking the tool's own stated count
// against a direct extraction of the real file.
preg_match_all("/name:\\s*'([a-z]+)'/", $realJs, $nameMatches);
$realNames = array_unique($nameMatches[1] ?? []);
is_true(count($realNames) >= 30, 'sanity: the real command-bar.js has a healthy number of named commands',
    (string) count($realNames));
$missingFromDoc = [];
foreach ($realNames as $n) {
    if (strpos($realMd, '/' . $n . '`') === false && strpos($realMd, '/' . $n . ' ') === false
        && strpos($realMd, '## ' . $n) === false) {
        // A handful of names double as section anchors only (none currently) --
        // the real check is: does "/name" appear ANYWHERE as a command token.
        if (strpos($realMd, '/' . $n) === false) { $missingFromDoc[] = $n; }
    }
}
is_true($missingFromDoc === [], 'no real command is silently absent from the generated doc',
    implode(', ', $missingFromDoc));

echo "\n";
echo "==========================================================\n";
echo "Command bar cheat sheet generator tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
