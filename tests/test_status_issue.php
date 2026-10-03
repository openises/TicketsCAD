<?php
/**
 * Phase 155 — the "Project status and known issues" issue
 * (tools/status-issue.php + .github/status-issue-template.md).
 *
 * WHAT IS BEING PROVEN
 *
 *   The status page is GENERATED from the repository's own data and the same
 *   settings the triage bot reads, so it cannot go stale or disagree with the
 *   acknowledgement; everything on it is declarative; a stranger's issue title
 *   cannot inject markup or a mention into it; and publishing is deliberate
 *   (print by default; --apply refuses an issue that is not the managed one).
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('STATUS_ISSUE_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
$tool = $root . '/tools/status-issue.php';
if (!is_file($tool)) ct_skip('tools/status-issue.php not present');
require_once $tool;

echo "=== Status issue ===\n\n";

$tplFile = $root . '/.github/status-issue-template.md';
$tpl = (string) @file_get_contents($tplFile);
ct('the template exists and carries the managed marker', $tpl !== '' && strpos($tpl, SI_MARKER) !== false);

$now = (int) strtotime('2026-10-02T12:00:00Z');
$cfg = ['away_until' => null, 'target_days' => 3, 'target_style' => 'number', 'aging_days' => 14];
$data = [
    'release' => ['tag' => 'v4.2.27', 'published_at' => '2026-09-02T00:00:00Z'],
    'main_date' => '2026-10-02T06:00:00Z',
    'open_bugs' => [
        ['number' => 143, 'title' => 'SOP table overflows', 'labels' => ['bug', 'confirmed']],
        ['number' => 144, 'title' => 'Notification rules placeholder', 'labels' => ['bug', 'on hold']],
        ['number' => 147, 'title' => 'Plain', 'labels' => ['bug']],
    ],
];

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. Rendering --\n";

$out = si_render($tpl, $data, $cfg, $now);
ct('no placeholder is left behind', strpos($out, '{{') === false, $out);
ct('the release and how long ago', strpos($out, '**Latest release:** v4.2.27 (2026-09-02, 30.5d ago)') !== false, $out);
ct('when main last moved', strpos($out, '**Main branch last updated:** 2026-10-02 (6h ago)') !== false, $out);
ct('the open-bug count', strpos($out, '**Open bug reports:** 3') !== false);
ct('a row per bug with a status a reader can understand',
    strpos($out, '| #143 | SOP table overflows | confirmed |') !== false && strpos($out, '| #144 | Notification rules placeholder | on hold |') !== false
    && strpos($out, '| #147 | Plain | open |') !== false, $out);
ct('when it was refreshed', strpos($out, '2026-10-02 12:00 UTC') !== false);
ct('the target comes from the SAME setting as the bot', strpos($out, 'within 3 days') !== false);
ct('it says a target is not a guarantee and is no promise of a fix date', stripos($out, 'not a guarantee') !== false && stripos($out, 'not a promise of a fix date') !== false);
ct('the aging commitment is stated with its number', strpos($out, 'at least every 14 days') !== false);
ct('it points at the Google Group, the private security route and SUPPORT.md', strpos($out, 'groups.google.com/g/open-source-cad') !== false && strpos($out, 'SECURITY.md') !== false && strpos($out, 'SUPPORT.md') !== false);
ct('there is no away banner when AWAY_UNTIL is unset', stripos($out, 'away') === false);
ct('it never mentions the private/public split', stripos($out, 'snapshot') === false && stripos($out, 'private repo') === false);
ct('it makes no offer and speaks as no person', !preg_match('/\b(happy to|let me|I will|we will)\b/i', $out));
ct('it ends with a single newline', substr($out, -1) === "\n" && substr($out, -2) !== "\n\n");

$cfgAway = $cfg; $cfgAway['away_until'] = '2026-10-20';
$away = si_render($tpl, $data, $cfgAway, $now);
ct('AWAY_UNTIL in the future adds the banner with the date', strpos($away, 'The maintainer is away until 2026-10-20.') !== false && strpos($away, 'SECURITY.md') !== false);
$awayPast = si_render($tpl, $data, ['away_until' => '2026-09-01'] + $cfg, $now);
ct('a past AWAY_UNTIL does not', stripos($awayPast, 'away') === false);
$awayBad = si_render($tpl, $data, ['away_until' => 'later'] + $cfg, $now);
ct('a malformed AWAY_UNTIL is ignored, not printed', stripos($awayBad, 'later') === false && stripos($awayBad, 'away until') === false);
ct('TRIAGE_TARGET_DAYS changes the stated target', strpos(si_render($tpl, $data, ['target_days' => 5] + $cfg, $now), 'within 5 days') !== false);
ct('the qualitative style states no number', strpos(si_render($tpl, $data, ['target_style' => 'qualitative'] + $cfg, $now), 'usually within a few days') !== false);
ct('COMMUNITY_AGING_DAYS changes the stated cadence', strpos(si_render($tpl, $data, ['aging_days' => 21] + $cfg, $now), 'at least every 21 days') !== false);

$none = si_render($tpl, ['release' => null, 'main_date' => null, 'open_bugs' => []], $cfg, $now);
ct('no release, no commit date, no bugs: honest placeholders, still renders', strpos($none, 'none published yet') !== false && strpos($none, 'unknown') !== false && strpos($none, 'No open bug reports.') !== false);

$hostile = [['number' => 9, 'title' => "Pwn | @everyone `x` <script>alert(1)</script> [click](http://evil.example) \n# heading", 'labels' => ['bug']]];
$h = si_render($tpl, ['open_bugs' => $hostile] + $data, $cfg, $now);
ct('a hostile title cannot inject a mention, markup, a link or a heading',
    strpos($h, '@everyone') === false && strpos($h, '<script>') === false && preg_match('/(?<!\\\\)\]\(/', $h) === 0 && strpos($h, "\n# heading") === false, $h);
ct('...and cannot break out of its table row (the pipe is escaped)', preg_match('/^\| #9 \| Pwn \\\\\|/m', $h) === 1, $h);

$spam = [['number' => 11, 'title' => 'Great deal at https://spam.example/buy and www.spam.example and ftp://x.example and a@b.example', 'labels' => ['bug']]];
$sp = si_render($tpl, ['open_bugs' => $spam] + $data, $cfg, $now);
ct('a link or address in a stranger\'s title does NOT become a live link on the public status page (autolinks are defanged)',
    !preg_match('~https?://spam|ftp://x|www\.spam~i', $sp) && strpos($sp, 'spam.example') !== false && strpos($sp, 'a@b') === false, $sp);

$many = [];
for ($i = 1; $i <= 40; $i++) $many[] = ['number' => $i, 'title' => "bug {$i}", 'labels' => ['bug']];
$capped = si_render($tpl, ['open_bugs' => $many] + $data, $cfg, $now, 30);
ct('the table is capped, and the rest is counted', substr_count($capped, "\n| #") === 30 && strpos($capped, '…and 10 more') !== false);

ct('si_status_of: the most specific label wins', si_status_of(['bug', 'live operations', 'planned']) === 'live operations' && si_status_of(['on hold', 'confirmed']) === 'on hold'
    && si_status_of(['needs reporter']) === 'waiting for the reporter' && si_status_of(['bug']) === 'open' && si_status_of(['needs triage']) === 'new');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The settings reader --\n";

foreach (['AWAY_UNTIL', 'TRIAGE_TARGET_DAYS', 'TRIAGE_TARGET_STYLE', 'COMMUNITY_AGING_DAYS'] as $v) putenv($v);
$d = si_config();
ct('defaults', $d['away_until'] === null && $d['target_days'] === 3 && $d['target_style'] === 'number' && $d['aging_days'] === 14);
putenv('COMMUNITY_AGING_DAYS=30'); putenv('TRIAGE_TARGET_DAYS=5'); putenv('AWAY_UNTIL=2026-12-24'); putenv('TRIAGE_TARGET_STYLE=qualitative');
$d = si_config();
ct('each setting is read', $d['aging_days'] === 30 && $d['target_days'] === 5 && $d['away_until'] === '2026-12-24' && $d['target_style'] === 'qualitative');
putenv('COMMUNITY_AGING_DAYS=0'); ct('an out-of-range value falls back to the default', si_config()['aging_days'] === 14);
foreach (['AWAY_UNTIL', 'TRIAGE_TARGET_DAYS', 'TRIAGE_TARGET_STYLE', 'COMMUNITY_AGING_DAYS'] as $v) putenv($v);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. The real tool through the real command runner --\n";

$inputFile = ct_json_file('status-data.json', $data);
$run = static function (array $args, array $rules = [], array $env = []) use ($tool, $inputFile): array {
    $g = ct_fake_gh($rules, $env);
    $r = ct_php($tool, array_merge(['--repo=o/r', '--now=2026-10-02T12:00:00Z'], $args), $g['env']);
    return $r + ['log' => $g['log']];
};

$r = $run(['--input=' . $inputFile]);
ct('print mode (the default) writes the body to stdout and calls gh NEVER', $r['code'] === 0 && ct_calls($r['log']) === [] && strpos($r['out'], '**Latest release:** v4.2.27') !== false);
$r = $run(['--input=' . $inputFile], [], ['AWAY_UNTIL' => '2026-10-20', 'TRIAGE_TARGET_DAYS' => '7']);
ct('settings in the environment reach the real tool', strpos($r['out'], 'away until 2026-10-20') !== false && strpos($r['out'], 'within 7 days') !== false);

// gathering through gh
$rules = [
    ['match' => 'releases/latest', 'out' => json_encode(['tag_name' => 'v4.2.28', 'published_at' => '2026-10-01T00:00:00Z'])],
    ['match' => 'commits?per_page=1', 'out' => json_encode([['commit' => ['committer' => ['date' => '2026-10-02T11:00:00Z']]]])],
    ['match' => 'issues?state=open&labels=bug', 'out' => json_encode([
        ['number' => 20, 'title' => 'B', 'labels' => [['name' => 'bug'], ['name' => 'planned']]],
        ['number' => 10, 'title' => 'A', 'labels' => [['name' => 'bug']]],
        ['number' => 30, 'title' => 'A pull request, not a bug', 'labels' => [], 'pull_request' => ['url' => 'x']],
    ])],
];
$r = $run([], $rules);
ct('gathered from GitHub: release, newest commit, open bugs (sorted, pull requests excluded)',
    $r['code'] === 0 && strpos($r['out'], 'v4.2.28') !== false && strpos($r['out'], '| #10 | A | open |') !== false
    && strpos($r['out'], '| #20 | B | planned |') !== false && strpos($r['out'], '#30') === false && strpos($r['out'], '(6h ago)') === false
    && strpos($r['out'], '2026-10-02 (1h ago)') !== false, $r['out'] . $r['err']);
ct('...only by READS (no write call was made)', ct_calls_matching($r['log'], 'issue edit') === [] && ct_calls_matching($r['log'], 'issue create') === []);
$failing = [['match' => 'releases/latest', 'out' => '', 'err' => 'HTTP 404', 'code' => 1], $rules[1],
            ['match' => 'issues?state=open&labels=bug', 'out' => '', 'err' => 'HTTP 502', 'code' => 1]];
$r = $run([], $failing);
ct('if a read fails the page STILL renders, saying "unknown" (a status page must not vanish when something is wrong)',
    $r['code'] === 0 && strpos($r['out'], 'none published yet') !== false && strpos($r['err'], 'open bugs') !== false, $r['out'] . $r['err']);

// publishing is deliberate
$managed = ['number' => 5, 'body' => "x\n" . SI_MARKER . "\ny"];
$applyRules = [['match' => 'repos/o/r/issues/5', 'out' => json_encode($managed)], ['match' => 'issue edit 5', 'out' => '']];
$r = $run(['--input=' . $inputFile, '--apply=5'], $applyRules);
$edit = ct_calls_matching($r['log'], 'issue edit 5');
ct('--apply edits the managed issue, body on stdin', $r['code'] === 0 && count($edit) === 1 && in_array('--body-file', $edit[0]['args'], true) && strpos($edit[0]['stdin'], SI_MARKER) !== false);
$human = [['match' => 'repos/o/r/issues/6', 'out' => json_encode(['number' => 6, 'body' => 'A human wrote this, no marker.'])], ['match' => 'issue edit', 'out' => '']];
$r = $run(['--input=' . $inputFile, '--apply=6'], $human);
ct('--apply REFUSES an issue that is not the managed one (a mistyped number cannot overwrite a human\'s issue)',
    $r['code'] === 1 && ct_calls_matching($r['log'], 'issue edit') === [] && stripos($r['err'], 'managed marker') !== false, $r['err']);
$pr = [['match' => 'repos/o/r/issues/7', 'out' => json_encode(['number' => 7, 'body' => SI_MARKER, 'pull_request' => ['url' => 'x']])], ['match' => 'issue edit', 'out' => '']];
ct('--apply refuses a pull request', $run(['--input=' . $inputFile, '--apply=7'], $pr)['code'] === 1);
ct('--apply on an issue that cannot be read: exit 1, no edit', ($rr = $run(['--input=' . $inputFile, '--apply=8'], [])) && $rr['code'] === 1 && ct_calls_matching($rr['log'], 'issue edit') === []);
$r = $run(['--input=' . $inputFile, '--create'], [['match' => 'issue create', 'out' => "https://github.com/o/r/issues/99\n"]]);
$create = ct_calls_matching($r['log'], 'issue create');
ct('--create opens the issue with the fixed title and the body on stdin',
    $r['code'] === 0 && count($create) === 1 && in_array('Project status and known issues', $create[0]['args'], true) && strpos($create[0]['stdin'], SI_MARKER) !== false);
ct('--apply and --create together are refused', $run(['--input=' . $inputFile, '--apply=5', '--create'], $applyRules)['code'] === 2);
ct('a non-numeric --apply is refused', $run(['--input=' . $inputFile, '--apply=abc'])['code'] === 2);

$bareTpl = ct_tmp() . '/no-marker.md';
file_put_contents($bareTpl, "# no marker\n{{release_line}}\n");
ct('a template without the managed marker is refused (the marker is what makes --apply safe)', $run(['--input=' . $inputFile, '--template=' . $bareTpl])['code'] === 2);
ct('a bad --repo is refused', ct_php($tool, ['--repo=nope', '--input=' . $inputFile], ct_env())['code'] === 2);
ct('unreadable --input is refused', $run(['--input=' . ct_tmp() . '/missing.json'])['code'] === 2);

ct_finish();
