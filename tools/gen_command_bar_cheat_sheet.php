<?php
/**
 * gen_command_bar_cheat_sheet.php — GH#136 (rjonesbsink).
 *
 * docs/COMMAND-BAR-CHEAT-SHEET.pdf and docs/command-bar-cheat-sheet-print.html
 * both carry a footer/comment claiming to be "generated from
 * assets/js/command-bar.js" -- but no generator ever existed anywhere under
 * tools/, so both were hand-maintained and drifted the moment a command was
 * renamed or reassigned (Phase 139 moved /log from "focus the Activity Log
 * widget" to Quick Notes capture; neither doc mentioned it). Investigating
 * this also found the drift is worse than the one command Ron reported: an
 * entire category (Settings deep links: /users /audit /types /organizations
 * /password /training /zones) and three Phase 86b/B10-B11 commands
 * (/major /road /radio) were missing from both docs entirely.
 *
 * This generator makes the name/alias/description columns -- the part that
 * actually goes stale when a command changes -- read directly from the live
 * COMMANDS registry, so a future rename can't silently drift again. The
 * hand-written syntax/example prose for the four argument-taking commands
 * (/s, /z, /net, /log) stays curated in $SPECIAL_SYNTAX below: a
 * meaningful worked example isn't something worth inventing programmatically,
 * and that prose changes far less often than a name or description does.
 *
 * Which simple (non-argument-taking) command belongs in which section is
 * also curated, in $CATEGORIES -- COMMANDS has no category field of its own.
 * A command found in the registry but not listed in ANY category (a new
 * command someone forgot to categorize here) is NOT silently dropped: it
 * lands in a loud "Uncategorized" section instead, which is the visible
 * failure mode this generator is meant to replace the silent one with.
 *
 * Usage:
 *   php tools/gen_command_bar_cheat_sheet.php            regenerate both docs
 *   php tools/gen_command_bar_cheat_sheet.php --check     exit 1 if either
 *                                                         doc is stale (CI/
 *                                                         pre-commit gate)
 *   --root=<dir>   operate against <dir> instead of the real repo root --
 *                  for tests/test_command_bar_cheat_sheet_gen.php to safely
 *                  exercise --check's failure path against a fixture copy
 *                  without ever touching the real committed docs.
 *
 * Does NOT regenerate the PDF -- that step needs a headless Chrome binary,
 * documented in the print HTML's own header comment. Run that manually
 * after regenerating the HTML.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
foreach ($argv as $arg) {
    if (strpos($arg, '--root=') === 0) { $root = rtrim(substr($arg, 7), '/\\'); }
}
$jsPath = $root . '/assets/js/command-bar.js';
$src = @file_get_contents($jsPath);
if ($src === false) {
    fwrite(STDERR, "Could not read $jsPath\n");
    exit(1);
}

// ── Extract the COMMANDS array ──────────────────────────────────────────
$start = strpos($src, 'var COMMANDS = [');
$end   = strpos($src, "\n    ];", $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "Could not locate the COMMANDS array in command-bar.js -- has its shape changed?\n");
    exit(1);
}
$commandsBlock = substr($src, $start, $end - $start);

// Each real entry is a single-line object literal: { name: 'x', aliases:
// [...], description: '...', handler: ..., takesArgs: true }. Matches a
// possibly-escaped single-quoted string for name/description (so "unit\'s"
// survives), a bracketed alias list, and an optional trailing takesArgs.
preg_match_all(
    "/\\{\\s*name:\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*aliases:\\s*\\[([^\\]]*)\\],\\s*description:\\s*'((?:[^'\\\\]|\\\\.)*)'.*?(?:,\\s*takesArgs:\\s*(true))?\\s*\\}/",
    $commandsBlock,
    $matches,
    PREG_SET_ORDER
);

$commands = [];
foreach ($matches as $m) {
    $name = stripslashes($m[1]);
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[2], $am);
    $aliases = array_map('stripslashes', $am[1] ?? []);
    $description = stripslashes($m[3]);
    $takesArgs = !empty($m[4]);
    $commands[$name] = ['aliases' => $aliases, 'description' => $description, 'takesArgs' => $takesArgs];
}
if (count($commands) < 20) {
    fwrite(STDERR, "Only parsed " . count($commands) . " commands out of command-bar.js -- "
        . "the regex likely no longer matches the file's real shape. Refusing to generate "
        . "a doc from a partial parse.\n");
    exit(1);
}

// ── Extract STATUS_ALIASES (short code => canonical status) ────────────
$statusStart = strpos($src, 'var STATUS_ALIASES = {');
$statusEnd   = strpos($src, "\n    };", $statusStart);
$statusBlock = ($statusStart !== false && $statusEnd !== false)
    ? substr($src, $statusStart, $statusEnd - $statusStart) : '';
// Keys use letters/digits/underscore/hyphen ('on-scene', 'on_scene'). Values
// are either a plain string ('Dispatched') or, since GH#44's multi-site-
// synonym fix, an array of candidates tried in order (['On Scene', 'At
// Scene']) -- captured separately since an array value has no closing
// quote where a plain string's does, and grouped under its FIRST/primary
// candidate for display purposes.
preg_match_all(
    "/'([a-z0-9_-]+)':\\s*(?:'([A-Za-z ]+)'|\\[\\s*'([A-Za-z ]+)')/",
    $statusBlock, $sm, PREG_SET_ORDER
);
$statusByCanonical = [];
foreach ($sm as $s) {
    $canonical = $s[2] !== '' ? $s[2] : $s[3];
    $statusByCanonical[$canonical][] = $s[1];
}
if (count($statusByCanonical) < 5) {
    fwrite(STDERR, "Only parsed " . count($statusByCanonical) . " status names out of STATUS_ALIASES -- "
        . "refusing to generate a doc from a partial parse.\n");
    exit(1);
}

// ── Which simple command goes in which cheat-sheet section ─────────────
// Keys are command `name`s (not aliases). A command present in COMMANDS
// but absent from every list below surfaces in "Uncategorized" instead
// of vanishing silently.
$CATEGORIES = [
    'Dispatch & workflow' => [
        'new', 'incidents', 'responders', 'units', 'facilities', 'activity',
        'detail', 'zello', 'road', 'radio',
    ],
    'Navigation — jump to a page' => [
        'dashboard', 'bigscreen', 'search', 'reports', 'settings', 'sop',
        'help', 'roster', 'teams', 'schedule', 'vehicles', 'equipment',
        'roles', 'profile', 'contacts', 'messages', 'links', 'ics', 'major',
    ],
    'Settings deep links' => [
        'users', 'audit', 'types', 'organizations', 'password', 'training', 'zones',
    ],
];

// Hand-curated syntax/examples for the four argument-taking commands.
// Kept out of COMMANDS entirely -- a worked example is prose, not data
// the registry has any business carrying.
$SPECIAL_SYNTAX = [
    'status' => [
        'heading'  => 'Unit status — change a unit without opening a modal',
        'syntax'   => ['/s <handle> <status>', '/status <handle> <status>      (same thing — /s and /st also work)'],
        'examples' => [
            ['/s M21 av', 'Medic 21 → Available'],
            ['/status E2 disp', 'Engine 2 → Dispatched'],
            ['/s Engine 2 dispatched', 'Multi-word unit names work too'],
            ['/s M4 out of service', 'Three-word statuses work too'],
        ],
        'note' => 'The status keyword is read from the END of what you type, so everything before '
            . 'it is treated as the unit handle. Case doesn\'t matter. Statuses needing extra info '
            . '(destination, reason) route you to the unit\'s S-key modal instead. An unrecognized '
            . 'word is still tried against your install\'s own configured statuses.',
    ],
    'zone' => [
        'heading'  => 'Event Net-Control zone move',
        'syntax'   => ['/z <team> <zone>'],
        'examples' => [
            ['/z alpha 3', 'Team Alpha → the zone with code or name "3"'],
            ['/z echo clear', 'Echo\'s zone assignment is cleared (clear, none, off all work)'],
        ],
        'note' => 'Requires an active event selected on the Net Control board first.',
    ],
    'net' => [
        'heading'  => 'Net-control check-ins — capture a whole round in one line',
        'syntax'   => ['/net <id> <note> / <id> <note> / <id> <note> ...'],
        'examples' => [
            ['/net 1234 tornado / 3344 hail', 'Two check-ins captured in one keystroke'],
        ],
        'note' => 'First word of each entry is the identifier, the rest is the note. Separate '
            . 'entries with /. Opens the situational screen with the check-ins loaded.',
    ],
    'log' => [
        'heading'  => 'Quick Notes — capture a note from anywhere',
        'syntax'   => ['/log <text>   → capture a timestamped note in one keystroke', '/log          → open the notes list to review/file it'],
        'examples' => [
            ['/log KOB reported at 4th and Main', 'Note captured instantly, no navigation'],
        ],
        'note' => 'Renamed from the Activity Log widget-focus command (that\'s /activity now) so this '
            . 'shorter name could go to quick capture instead.',
    ],
];

// ── Render docs/COMMAND-BAR-CHEAT-SHEET.md ──────────────────────────────
function md_table_row(string $cmd, array $aliases, string $desc): string {
    $aliasCell = $aliases ? implode(', ', array_map(function ($a) { return '`/' . $a . '`'; }, $aliases)) : '—';
    return '| `/' . $cmd . '` | ' . $aliasCell . ' | ' . $desc . ' |';
}

$md = "# TicketsCAD Command Bar — Cheat Sheet\n\n";
$md .= "Press **`/`** anywhere in the app (as long as you're not typing in a text field) to\n";
$md .= "open the command bar. Type a command name or a short alias, then **Enter** to run\n";
$md .= "it. If what you typed matches more than one command, a dropdown appears — use\n";
$md .= "**↑ / ↓** then **Enter**, click, or **Tab** to complete to the highlighted one.\n";
$md .= "**Esc** closes the bar without doing anything.\n\n";
$md .= "You don't have to type the whole word. `/in` is enough for `/incidents` as\n";
$md .= "long as nothing else starts with `in`.\n\n";

$categorized = [];
foreach ($CATEGORIES as $section => $names) {
    $md .= "## $section\n\n| Command | Aliases | What it does |\n|---|---|---|\n";
    foreach ($names as $name) {
        if (!isset($commands[$name])) continue; // renamed/removed since curated — silently skip the stale entry
        $categorized[$name] = true;
        $md .= md_table_row($name, $commands[$name]['aliases'], $commands[$name]['description']) . "\n";
    }
    $md .= "\n";
}

$uncategorized = [];
foreach ($commands as $name => $c) {
    if ($c['takesArgs']) continue;
    if (isset($categorized[$name])) continue;
    $uncategorized[$name] = $c;
}
if ($uncategorized) {
    $md .= "## Uncategorized (new since this doc was last generated — needs a section in "
         . "tools/gen_command_bar_cheat_sheet.php's \$CATEGORIES)\n\n| Command | Aliases | What it does |\n|---|---|---|\n";
    foreach ($uncategorized as $name => $c) {
        $md .= md_table_row($name, $c['aliases'], $c['description']) . "\n";
    }
    $md .= "\n";
}

foreach ($SPECIAL_SYNTAX as $name => $s) {
    if (!isset($commands[$name])) continue;
    $md .= "## {$s['heading']}\n\n```\n" . implode("\n", $s['syntax']) . "\n```\n\n**Examples**\n\n";
    $md .= "| You type | What happens |\n|---|---|\n";
    foreach ($s['examples'] as [$in, $out]) {
        $md .= "| `$in` | $out |\n";
    }
    $md .= "\n" . $s['note'] . "\n\n";
}

$md .= "## Unit status shortcuts (case-insensitive)\n\n| Status | Type any of |\n|---|---|\n";
foreach ($statusByCanonical as $canonical => $codes) {
    $md .= "| $canonical | " . implode(', ', array_map(function ($c) { return "`$c`"; }, $codes)) . " |\n";
}
$md .= "\n";

$md .= "## Keys once the bar is open\n\n| Key | Does |\n|---|---|\n";
$md .= "| **Enter** | Run the highlighted / typed command |\n";
$md .= "| **Tab** | Complete to the highlighted (or first) suggestion |\n";
$md .= "| **↑ / ↓** | Move the highlight in the dropdown |\n";
$md .= "| **Esc** | Close the bar, do nothing |\n\n";
$md .= "---\n\n";
$md .= "*Generated by `php tools/gen_command_bar_cheat_sheet.php` from the live command "
     . "registry in `assets/js/command-bar.js` — if a command here doesn't match what your "
     . "install actually does, re-run the generator; if it still doesn't match, the code is "
     . "the source of truth, please open an issue.*\n";

// ── Render docs/command-bar-cheat-sheet-print.html ──────────────────────
function html_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function html_rows(array $names, array $commands): string {
    $out = '';
    foreach ($names as $name) {
        if (!isset($commands[$name])) continue;
        $aliasStr = $commands[$name]['aliases']
            ? implode(' ', array_map(function ($a) { return '/' . $a; }, $commands[$name]['aliases']))
            : '—'; // html_esc() below leaves a literal em-dash alone; the &mdash; entity would double-escape to &amp;mdash;
        $out .= '      <tr><td class="cmd">/' . html_esc($name) . '</td><td class="alias">'
              . html_esc($aliasStr) . '</td><td class="what">' . html_esc($commands[$name]['description']) . "</td></tr>\n";
    }
    return $out;
}

$htmlSections = '';
foreach ($CATEGORIES as $section => $names) {
    $rows = html_rows($names, $commands);
    if ($rows === '') continue;
    $htmlSections .= "  <section>\n    <h2>" . html_esc($section) . "</h2>\n    <table>\n$rows    </table>\n  </section>\n\n";
}
if ($uncategorized) {
    $rows = html_rows(array_keys($uncategorized), $commands);
    $htmlSections .= "  <section>\n    <h2>Uncategorized (needs a section in the generator)</h2>\n    <table>\n$rows    </table>\n  </section>\n\n";
}
foreach ($SPECIAL_SYNTAX as $name => $s) {
    if (!isset($commands[$name])) continue;
    $htmlSections .= "  <section>\n    <h2>" . html_esc($s['heading']) . "</h2>\n";
    $htmlSections .= '    <div class="syntax">' . html_esc(implode("\n", $s['syntax'])) . "</div>\n";
    $htmlSections .= "    <div class=\"ex\">\n";
    foreach ($s['examples'] as [$in, $out]) {
        $htmlSections .= '      <div class="row"><span class="in">' . html_esc($in)
            . '</span><span class="arrow">&rarr;</span><span class="out">' . html_esc($out) . "</span></div>\n";
    }
    $htmlSections .= "    </div>\n    <p class=\"note\">" . html_esc($s['note']) . "</p>\n  </section>\n\n";
}
$statusRows = '';
foreach ($statusByCanonical as $canonical => $codes) {
    $statusRows .= '      <tr><td class="status-name">' . html_esc($canonical) . '</td><td class="alias">'
        . html_esc(implode(' ', $codes)) . "</td></tr>\n";
}
$htmlSections .= "  <section>\n    <h2>Unit status shortcuts</h2>\n    <div class=\"status-grid\">\n      <table>\n$statusRows      </table>\n    </div>\n  </section>\n\n";
$htmlSections .= <<<'HTML'
  <section>
    <h2>While the bar is open</h2>
    <table>
      <tr><td class="cmd"><kbd>Enter</kbd></td><td class="what">Run the command</td></tr>
      <tr><td class="cmd"><kbd>Tab</kbd></td><td class="what">Complete to highlighted suggestion</td></tr>
      <tr><td class="cmd"><kbd>&uarr; / &darr;</kbd></td><td class="what">Move the highlight</td></tr>
      <tr><td class="cmd"><kbd>Esc</kbd></td><td class="what">Close, do nothing</td></tr>
    </table>
  </section>

HTML;

$htmlTemplate = (string) file_get_contents($root . '/docs/command-bar-cheat-sheet-print.html');
// Preserve everything outside the <div class="cols">...</div> body (the
// <head>/<style>/<header>/<footer> shell hand-tuned for print layout);
// only the per-command content is regenerated.
$colsStart = strpos($htmlTemplate, '<div class="cols">');
$colsEnd   = strpos($htmlTemplate, '</div>', strrpos($htmlTemplate, '</section>'));
if ($colsStart === false || $colsEnd === false) {
    fwrite(STDERR, "Could not locate <div class=\"cols\">...</div> in the print HTML shell -- "
        . "has the template's structure changed? Refusing to overwrite blindly.\n");
    exit(1);
}
$newHtml = substr($htmlTemplate, 0, $colsStart)
    . "<div class=\"cols\">\n\n" . $htmlSections . "</div>"
    . substr($htmlTemplate, $colsEnd + strlen('</div>'));
// Re-point the footer's own claim so it names this generator, not itself.
$newHtml = str_replace(
    'Generated from assets/js/command-bar.js — the code is the source of truth',
    'Generated by tools/gen_command_bar_cheat_sheet.php from assets/js/command-bar.js',
    $newHtml
);
$newHtml = preg_replace(
    '/<!--\s*\n\s*Print source for.*?-->/s',
    "<!--\n  Print source for docs/COMMAND-BAR-CHEAT-SHEET.pdf. Regenerated from the live\n"
    . "  COMMANDS registry by tools/gen_command_bar_cheat_sheet.php (run it, then regenerate\n"
    . "  the PDF -- see that script's own header for the exact command):\n\n"
    . "    php tools/gen_command_bar_cheat_sheet.php\n"
    . "    \"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe\" --headless --disable-gpu ^\n"
    . "      --no-pdf-header-footer --print-to-pdf-no-header ^\n"
    . "      --print-to-pdf=\"docs\\COMMAND-BAR-CHEAT-SHEET.pdf\" ^\n"
    . "      \"file:///<repo>/docs/command-bar-cheat-sheet-print.html\"\n"
    . "-->",
    $newHtml,
    1
);

// ── Write, or just report drift ────────────────────────────────────────
$mdPath = $root . '/docs/COMMAND-BAR-CHEAT-SHEET.md';
$htmlPath = $root . '/docs/command-bar-cheat-sheet-print.html';
$checkOnly = in_array('--check', $argv, true);

$mdStale = (string) @file_get_contents($mdPath) !== $md;
$htmlStale = (string) @file_get_contents($htmlPath) !== $newHtml;

if ($checkOnly) {
    if ($mdStale || $htmlStale) {
        fwrite(STDERR, "Command bar cheat sheet is stale relative to assets/js/command-bar.js.\n"
            . "Run: php tools/gen_command_bar_cheat_sheet.php\n"
            . ($mdStale ? "  - docs/COMMAND-BAR-CHEAT-SHEET.md needs regenerating\n" : '')
            . ($htmlStale ? "  - docs/command-bar-cheat-sheet-print.html needs regenerating (then re-export the PDF)\n" : ''));
        exit(1);
    }
    echo "[OK] Command bar cheat sheet is current (" . count($commands) . " commands, "
        . count($statusByCanonical) . " statuses).\n";
    exit(0);
}

file_put_contents($mdPath, $md);
file_put_contents($htmlPath, $newHtml);
echo "[OK] Regenerated docs/COMMAND-BAR-CHEAT-SHEET.md and docs/command-bar-cheat-sheet-print.html\n";
echo "     (" . count($commands) . " commands, " . count($statusByCanonical) . " statuses)\n";
if ($uncategorized) {
    echo "[WARN] " . count($uncategorized) . " command(s) had no category in \$CATEGORIES and "
        . "landed in \"Uncategorized\": " . implode(', ', array_keys($uncategorized)) . "\n";
}
echo "     Remember to re-export the PDF -- see this file's own header comment for the command.\n";
