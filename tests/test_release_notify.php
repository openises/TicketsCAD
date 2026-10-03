<?php
/**
 * Phase 155 — telling reporters their fix is in a release
 * (tools/release-notify.php + .github/workflows/release-notify.yml).
 *
 * WHAT IS BEING PROVEN
 *
 *   When a release is published, each issue its notes or its changelog section
 *   name gets ONE short comment, once per release, capped; a pull request, a
 *   draft, a pre-release, a typo'd number and a repeat run all do nothing; and
 *   no text from the release notes is ever copied into a comment or an
 *   argument. The settings (RELEASE_NOTIFY_*) demonstrably change behaviour.
 *
 * The tool runs as a real subprocess through the real command runner against a
 * recording fake gh.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('RELEASE_NOTIFY_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
$tool = $root . '/tools/release-notify.php';
if (!is_file($tool)) ct_skip('tools/release-notify.php not present');
require_once $tool;

echo "=== Release notification ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. Reading the issue numbers --\n";

ct('GH#N and "GH #N" are issue references; order and duplicates are normalised',
    rn_issue_numbers("Fixes GH#146, gh #143 and GH#146 again") === [143, 146]);
ct('a bare #N is NOT an issue reference (it may be anything)', rn_issue_numbers('see #99, PR #12, version 4.2.27') === []);
ct('GH#0 is not an issue', rn_issue_numbers('GH#0') === []);
ct('a longer number is not truncated to a shorter one', rn_issue_numbers('GH#1234') === [1234]);

$changelog = "# Changelog\n\n## [Unreleased]\n\n- Not yet released (GH#901).\n\n## [4.2.28] — 2026-10-05\n\n### Fixed\n\n- A (GH#146).\n- B (GH#149).\n\n## [4.2.27] — 2026-09-02\n\n- Old (GH#10).\n";
$sec = rn_changelog_section($changelog, 'v4.2.28');
ct('the section for a version is exactly that version\'s', strpos($sec, 'GH#146') !== false && strpos($sec, 'GH#149') !== false);
ct('...not [Unreleased] and not the previous release', strpos($sec, 'GH#901') === false && strpos($sec, 'GH#10)') === false);
ct('a tag with or without the leading v finds the same section', rn_changelog_section($changelog, '4.2.28') === $sec);
ct('a version with no section yields nothing', rn_changelog_section($changelog, 'v9.9.9') === '');
ct('a version is matched literally (4.2.2 is not 4.2.28)', rn_changelog_section($changelog, 'v4.2.2') === '');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The plan --\n";

$ev = static function (array $rel = [], string $action = 'published'): array {
    return ['action' => $action, 'release' => $rel + ['tag_name' => 'v4.2.28', 'body' => 'Notes: GH#146 and GH#149.', 'draft' => false, 'prerelease' => false]];
};
$p = rn_plan($ev(), null, true, 25, false);
ct('a normal release names its issues', $p['skip'] === null && $p['numbers'] === [146, 149] && $p['tag'] === 'v4.2.28');
$p = rn_plan($ev(['body' => 'no issues here']), $changelog, true, 25, false);
ct('the changelog section is read too, and merged with the notes', $p['numbers'] === [146, 149]);
$p = rn_plan($ev(['body' => 'GH#7']), $changelog, true, 25, false);
ct('notes and changelog are UNIONED', $p['numbers'] === [7, 146, 149]);
ct('disabled: skips', rn_plan($ev(), null, false, 25, false)['skip'] !== null);
ct('a draft release: skips', rn_plan($ev(['draft' => true]), null, true, 25, false)['skip'] !== null);
ct('a pre-release: skips by default', rn_plan($ev(['prerelease' => true]), null, true, 25, false)['skip'] !== null);
ct('...unless RELEASE_NOTIFY_PRERELEASES is on', rn_plan($ev(['prerelease' => true]), null, true, 25, true)['skip'] === null);
ct('an action other than "published": skips', rn_plan($ev([], 'created'), null, true, 25, false)['skip'] !== null);
ct('a release that names no issue: skips (nothing to tell anyone)', rn_plan($ev(['body' => 'misc']), null, true, 25, false)['skip'] !== null);
foreach (['', 'latest', 'v4.2', 'v1.2.3; rm -rf /', "v1.2.3\nGH#5", 'v1.2.3/../x', '$(id)'] as $badTag) {
    ct('a tag that is not a plain version is refused: ' . json_encode($badTag), rn_plan($ev(['tag_name' => $badTag]), null, true, 25, false)['skip'] !== null);
}
foreach (['v4.2.28', '4.2.28', 'v4.2.28-rc.1'] as $okTag) {
    ct("the tag {$okTag} is accepted", rn_plan($ev(['tag_name' => $okTag]), null, true, 25, true)['skip'] === null);
}
$many = rn_plan($ev(['body' => implode(' ', array_map(static fn(int $n): string => "GH#{$n}", range(1, 40))), 'tag_name' => 'v4.2.28']), null, true, 25, false);
ct('the cap: 40 named issues, 25 notified, 15 reported as not', count($many['numbers']) === 25 && $many['truncated'] === 15 && $many['numbers'][0] === 1);
$few = rn_plan($ev(['body' => 'GH#1 GH#2 GH#3']), null, true, 2, false);
ct('RELEASE_NOTIFY_MAX is the cap that is applied', count($few['numbers']) === 2 && $few['truncated'] === 1);
$c = rn_comment('o/r', 'v4.2.28');
ct('the comment is declarative and links the release', strpos($c, 'Included in release v4.2.28 (https://github.com/o/r/releases/tag/v4.2.28).') === 0);
ct('...with the hidden once-per-release marker', strpos($c, '<!-- release-notify:v4.2.28 -->') !== false);
ct('...and promises nothing, offers nothing', !preg_match('/\b(will|happy to|let us know|please)\b/i', $c));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. The real tool, through the real command runner (fake gh) --\n";

$hostile = "Release notes. GH#146 GH#149 GH#150 GH#151 GH#152\n\n@everyone ZZNOTESZZ `rm -rf /` <script>x</script>";
$eventFile = ct_json_file('release-event.json', $ev(['body' => $hostile]));
$rules = [
    ['match' => 'repos/o/r/issues/146/comments', 'out' => '[]'],
    ['match' => 'repos/o/r/issues/149/comments', 'out' => '[]'],
    ['match' => 'repos/o/r/issues/150/comments', 'out' => json_encode([['body' => "Included in release v4.2.28 (x).\n\n<!-- release-notify:v4.2.28 -->\n", 'user' => ['login' => 'github-actions[bot]', 'type' => 'Bot']]])],
    ['match' => 'repos/o/r/issues/151/comments', 'out' => '[]'],
    ['match' => 'repos/o/r/issues/146', 'out' => json_encode(['number' => 146, 'state' => 'closed'])],
    ['match' => 'repos/o/r/issues/149', 'out' => json_encode(['number' => 149, 'state' => 'open'])],
    ['match' => 'repos/o/r/issues/150', 'out' => json_encode(['number' => 150])],
    ['match' => 'repos/o/r/issues/151', 'out' => json_encode(['number' => 151, 'pull_request' => ['url' => 'x']])],
    ['match' => 'repos/o/r/issues/152', 'out' => '', 'err' => 'HTTP 404', 'code' => 1],
    ['match' => 'issue comment', 'out' => ''],
    ['match' => 'contents/CHANGELOG.md', 'out' => $changelog],
];
$run = static function (array $rules, array $env = [], array $args = [], ?string $event = null) use ($tool, $eventFile): array {
    $g = ct_fake_gh($rules, $env);
    $r = ct_php($tool, array_merge(['--event=' . ($event ?? $eventFile), '--repo=o/r', '--json'], $args), $g['env']);
    return $r + ['log' => $g['log'], 'j' => json_decode($r['out'], true)];
};

$r = $run($rules);
ct('a normal run exits 0', $r['code'] === 0, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$posts = ct_calls_matching($r['log'], 'issue comment');
$postedTo = array_map(static fn(array $c): string => $c['args'][2], $posts);
sort($postedTo);
ct('it comments on the closed issue and the open one (a closed issue still needs to hear)', $postedTo === ['146', '149'], json_encode($postedTo));
ct('it SKIPS the issue already told for this release', in_array(150, $r['j']['already'] ?? [], true) && !in_array('150', $postedTo, true));
ct('it SKIPS a pull request that the notes happen to name', ($r['j']['skipped'][151] ?? '') === 'a pull request' && !in_array('151', $postedTo, true));
ct('it SKIPS a number that does not exist (a typo), without failing the run', ($r['j']['skipped'][152] ?? '') === 'not found' && $r['code'] === 0);
ct('the body goes on stdin (--body-file -), and carries the marker',
    count($posts) === 2 && in_array('--body-file', $posts[0]['args'], true) && strpos($posts[0]['stdin'], '<!-- release-notify:v4.2.28 -->') !== false);
$all = '';
foreach (ct_calls($r['log']) as $c) $all .= implode("\n", $c['args']) . "\n" . $c['stdin'] . "\n";
ct('NOTHING from the release notes (mention, backticks, markup) reaches any argument or any comment',
    strpos($all, 'ZZNOTESZZ') === false && strpos($all, '@everyone') === false && strpos($all, 'rm -rf') === false && strpos($all, '<script>') === false);
ct('the changelog at the tag was fetched through the API', count(ct_calls_matching($r['log'], 'contents/CHANGELOG.md?ref=v4.2.28')) === 1);

// A second run for the same release posts nothing more.
$dupRules = $rules;
$dupRules[0] = ['match' => 'repos/o/r/issues/146/comments', 'out' => json_encode([['body' => "<!-- release-notify:v4.2.28 -->", 'user' => ['login' => 'github-actions[bot]', 'type' => 'Bot']]])];
$dupRules[1] = ['match' => 'repos/o/r/issues/149/comments', 'out' => json_encode([['body' => "<!-- release-notify:v4.2.28 -->", 'user' => ['login' => 'github-actions[bot]', 'type' => 'Bot']]])];
$r2 = $run($dupRules);
ct('re-running for the same release posts NOTHING more', ct_calls_matching($r2['log'], 'issue comment') === [] && $r2['code'] === 0);
$dupOther = $rules;
$dupOther[0] = ['match' => 'repos/o/r/issues/146/comments', 'out' => json_encode([['body' => "Included in release v4.2.27.\n<!-- release-notify:v4.2.27 -->"]])];
$r3 = $run($dupOther);
ct('...but a comment for an EARLIER release does not stop THIS release\'s (the marker is per tag)',
    in_array('146', array_map(static fn(array $c): string => $c['args'][2], ct_calls_matching($r3['log'], 'issue comment')), true));

$spoof = $rules;
$spoof[0] = ['match' => 'repos/o/r/issues/146/comments', 'out' => json_encode([['body' => "<!-- release-notify:v4.2.28 -->", 'user' => ['login' => 'troll', 'type' => 'User'], 'author_association' => 'NONE']])];
$r10 = $run($spoof);
ct('a STRANGER\'s comment containing the marker does not stop the real notice', in_array('146', array_map(static fn(array $c): string => $c['args'][2], ct_calls_matching($r10['log'], 'issue comment')), true));

// settings
$r4 = $run($rules, ['RELEASE_NOTIFY_MAX' => '1']);
ct('RELEASE_NOTIFY_MAX reaches the real tool (1 comment, the rest reported)', count(ct_calls_matching($r4['log'], 'issue comment')) === 1);
$r5 = $run($rules, ['RELEASE_NOTIFY_ENABLED' => 'false']);
ct('RELEASE_NOTIFY_ENABLED=false: no gh call at all', ct_calls($r5['log']) === [] && $r5['code'] === 0);
$preEvent = ct_json_file('release-pre.json', $ev(['prerelease' => true]));
ct('a pre-release makes no gh call by default', ct_calls($run($rules, [], [], $preEvent)['log']) === []);
$r6 = $run($rules, ['RELEASE_NOTIFY_PRERELEASES' => 'true'], [], $preEvent);
ct('RELEASE_NOTIFY_PRERELEASES=true notifies for it', count(ct_calls_matching($r6['log'], 'issue comment')) === 2);

// dry run, failures
$r7 = $run($rules, [], ['--dry-run']);
ct('--dry-run posts nothing and shows the comment', ct_calls_matching($r7['log'], 'issue comment') === [] && strpos((string) ($r7['j']['comment'] ?? ''), 'Included in release') === 0);
$failRules = $rules;
array_unshift($failRules, ['match' => 'issue comment 146', 'out' => '', 'err' => 'HTTP 403', 'code' => 1]);
$r8 = $run($failRules);
ct('a comment that fails makes the run exit 1, and the OTHER issue is still told', $r8['code'] === 1 && count(ct_calls_matching($r8['log'], 'issue comment 149')) === 1);
$noChangelog = $rules;
array_unshift($noChangelog, ['match' => 'contents/CHANGELOG.md', 'out' => '', 'err' => 'HTTP 404', 'code' => 1]);
$r9 = $run($noChangelog);
ct('if the changelog cannot be read, the release notes alone are used (and the run still succeeds)', $r9['code'] === 0 && count(ct_calls_matching($r9['log'], 'issue comment')) === 2);

// bad invocation
$g = ct_fake_gh($rules);
ct('no event: exit 2', ct_php($tool, ['--repo=o/r', '--event=' . ct_tmp() . '/none.json'], $g['env'])['code'] === 2);
ct('bad repo: exit 2', ct_php($tool, ['--repo=nope', '--event=' . $eventFile], $g['env'])['code'] === 2);
ct('unknown option: exit 2', ct_php($tool, ['--x', '--repo=o/r', '--event=' . $eventFile], $g['env'])['code'] === 2);
ct('none of those called gh', ct_calls($g['log']) === []);

ct_finish();
