<?php
/**
 * Phase 155 — the sync-lag gate (tools/sync-lag-check.php).
 *
 * THE FAILURE BEING PREVENTED, reproduced as it happened
 *
 *   A fix is verified on the hosted betas, the reporter is told "fixed", and
 *   public main does not contain it. Issues #140 and #143 waited 21.5 and 20.4
 *   days. Nothing measured the distance, so a standing rule was the only
 *   control, and a rule is a streak, not a gate.
 *
 *   This test builds real git repositories (a dev tree and a public repo), makes
 *   a fix commit that has been deployed but not published, and asserts that:
 *     - the lag is reported, with the right exit code at each threshold;
 *     - `--issue N` (the gate a "fixed" comment must pass) says NOT PUBLISHED;
 *     - once a public commit carries the Dev-Commit trailer for that fix, the
 *       same gate says published.
 *   Nothing is hand-assembled: ages come from real commit dates and --now.
 *
 * Settings proven to take effect: SYNC_WARN_HOURS, SYNC_FAIL_HOURS and their
 * flags. Gates must fail CLOSED: every "cannot determine" case exits 4, never 0.
 *
 * Needs git on PATH; skips (with the canonical summary) without it. The
 * EXCLUDES comparison against bash needs bash and skips only that part.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('SYNC_LAG_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
$tool = $root . '/tools/sync-lag-check.php';
if (!is_file($tool)) ct_skip('tools/sync-lag-check.php not present (it is development-tree only)');
if (!ct_have('git')) ct_skip('git is not on PATH; this gate builds real repositories');
require_once $tool;

echo "=== Sync-lag gate ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. Which paths are published (EXCLUDES read from the snapshot script) --\n";

$fixtureScript = "#!/usr/bin/env bash\nset -e\nshopt -s nullglob\nEXCLUDES=(\n  specs coordination\n  services/*/bench\n"
    . "  # a comment line inside the array\n  docs/AUTONOMOUS-SESSION-*.md\n  tools/deploy.sh   # trailing comment\n  tools/a.php tools/b.php\n)\nfor p in \"\${EXCLUDES[@]}\"; do rm -rf \$p; done\n";
$ex = sync_parse_excludes($fixtureScript);
ct('the array is parsed, comments and all', $ex === ['specs', 'coordination', 'services/*/bench', 'docs/AUTONOMOUS-SESSION-*.md', 'tools/deploy.sh', 'tools/a.php', 'tools/b.php'], json_encode($ex));
ct('a one-line array parses too', sync_parse_excludes("EXCLUDES=(a b/c)\n") === ['a', 'b/c']);
ct('a script with no EXCLUDES array parses to nothing (callers refuse to guess)', sync_parse_excludes("echo hi\n") === []);
ct('a directory entry excludes everything under it', !sync_path_published('specs/phase-1/spec.md', $ex) && !sync_path_published('specs/x', $ex));
ct('...but not a sibling that merely starts with the same letters', sync_path_published('specsheet.md', $ex) && sync_path_published('docs/specs-guide.md', $ex));
ct('a glob entry excludes what it matches', !sync_path_published('services/audio/bench/run.py', $ex) && !sync_path_published('docs/AUTONOMOUS-SESSION-7.md', $ex));
ct('a file entry excludes exactly that file', !sync_path_published('tools/deploy.sh', $ex) && sync_path_published('tools/deploy.php', $ex));
ct('ordinary code is published', sync_path_published('api/incidents.php', $ex) && sync_path_published('README.md', $ex));
ct('Windows separators and a leading slash are normalised', !sync_path_published('specs\\x.md', $ex) && !sync_path_published('/specs/x.md', $ex));

$real = file_get_contents($root . '/tools/release-snapshot.sh');
$realEx = sync_parse_excludes((string) $real);
ct('the REAL snapshot script yields a non-trivial EXCLUDES list', count($realEx) > 10 && in_array('specs', $realEx, true), 'got ' . count($realEx));
$bash = ct_bash();
if ($bash === null) {
    echo "NOTE: no bash on this machine; skipping the comparison against bash's own expansion\n";
} else {
    // `set -f` (no pathname expansion): in the real script the array assignment
    // expands globs such as services/*/bench against whatever exists in the staged
    // tree. The SET of removed paths is the same, but the pattern text is what a
    // parser can compare, so ask bash for the patterns themselves.
    $r = cm_run([$bash, '-c', 'set -f; eval "$(sed -n "/^EXCLUDES=(/,/^)/p" "$1")"; printf "%s\n" "${EXCLUDES[@]}"', '_', str_replace('\\', '/', $root) . '/tools/release-snapshot.sh'], null, null, ct_env(), 60);
    $bashList = array_values(array_filter(array_map('trim', explode("\n", $r['out'])), 'strlen'));
    ct('the PHP parse of the real script equals what BASH expands the array to (no second source of truth)',
        $r['code'] === 0 && $bashList === $realEx, 'bash=' . count($bashList) . ' php=' . count($realEx));
}
foreach (['tools/sync-lag-check.php', 'tools/publish-sync.sh', 'tools/sync-lag-baseline.txt', 'tools/community-watch.php', '.github/workflows/community-watch.yml'] as $devOnly) {
    ct("`{$devOnly}` is development-only (excluded from the public snapshot)", !sync_path_published($devOnly, $realEx));
}
foreach (['tools/triage-issue.php', 'tools/sync-labels.php', 'tools/release-notify.php', 'tools/status-issue.php', 'tools/lib/community.php',
          '.github/workflows/triage.yml', '.github/workflows/labels.yml', '.github/workflows/release-notify.yml', '.github/labels.json',
          '.github/triage/acknowledgement.md', '.github/ISSUE_TEMPLATE/bug_report.yml', 'SUPPORT.md', 'tests/test_triage_issue.php'] as $shipped) {
    ct("`{$shipped}` ships to the public repository", sync_path_published($shipped, $realEx));
}
ct('specs/ and tools/deploy.sh are not published (a commit touching only them is not "unpublished work")',
    !sync_path_published('specs/phase-155-community-backlog/README.md', $realEx) && !sync_path_published('tools/deploy.sh', $realEx));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. Reading commits --\n";

ct('issue tokens: several, de-duplicated, ascending', sync_issue_tokens("Fix GH#146 and GH#143, also gh#143 again") === [143, 146]);
ct('issue tokens: a longer number is not mistaken for a shorter one', sync_issue_tokens('GH#1234') === [1234] && !in_array(123, sync_issue_tokens('GH#1234'), true));
ct('issue tokens: a bare #N is not a GH#N', sync_issue_tokens('see #99 and issue 12') === []);
$raw = "\x1eaaa\x1f2026-10-01T00:00:00+00:00\x1fA\x1fFix a (GH#1)\x1fbody text\x1f\n\napi/a.php\nREADME.md\n"
     . "\x1ebbb\x1f2026-10-01T01:00:00+00:00\x1fB\x1fNo files\x1f\x1f\n";
$parsed = sync_parse_dev_log($raw);
ct('the git-log parser reads sha, date, subject, body and files', count($parsed) === 2 && $parsed[0]['sha'] === 'aaa' && $parsed[0]['subject'] === 'Fix a (GH#1)'
    && $parsed[0]['body'] === 'body text' && $parsed[0]['files'] === ['api/a.php', 'README.md'] && $parsed[1]['files'] === []);
$msgGood = "Sync\n\nDev-Commit: " . str_repeat('a', 40) . "\nFixes-Included: GH#1\n";
$tr = sync_trailers_from_commits([
    ['sha' => 'p3', 'date' => 'd3', 'message' => "prose: the Dev-Commit: trailer is described in docs\n"],
    ['sha' => 'p2', 'date' => 'd2', 'message' => "short\n\nDev-Commit: abc1234\n"],
    ['sha' => 'p1', 'date' => 'd1', 'message' => $msgGood],
]);
ct('only a FULL 40-hex Dev-Commit trailer counts (prose and abbreviations do not)', count($tr) === 1 && $tr[0]['public'] === 'p1' && $tr[0]['dev'] === str_repeat('a', 40));
ct('a trailer in the middle of a message body does not need to be last', count(sync_trailers_from_commits([['sha' => 'x', 'date' => 'd', 'message' => "Subject\n\nDev-Commit: " . str_repeat('b', 40) . "\n\nSigned-off-by: A <a@b>\n"]])) === 1);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. The computation and its thresholds --\n";

$mk = static function (string $sha, string $date, string $subject, array $files): array {
    return ['sha' => $sha, 'date' => $date, 'author' => 'a', 'subject' => $subject, 'body' => '', 'files' => $files];
};
$now = (int) strtotime('2026-10-02T00:00:00Z');
$c = sync_compute([$mk('s1', '2026-10-01T00:00:00Z', 'Fix (GH#5)', ['api/x.php'])], $ex, $now, 24, 72);
ct('24h exactly is a WARN (the threshold is inclusive)', $c['status'] === 'warn' && $c['exit'] === 1 && $c['oldest_age_hours'] === 24.0);
$c = sync_compute([$mk('s1', '2026-10-01T00:00:01Z', 'Fix (GH#5)', ['api/x.php'])], $ex, $now, 24, 72);
ct('one second under is OK', $c['status'] === 'ok' && $c['exit'] === 0);
$c = sync_compute([$mk('s1', '2026-09-29T00:00:00Z', 'Fix (GH#5)', ['api/x.php'])], $ex, $now, 24, 72);
ct('72h exactly is a FAIL', $c['status'] === 'fail' && $c['exit'] === 2);
$c = sync_compute([$mk('s1', '2026-09-01T00:00:00Z', 'Specs only', ['specs/a.md']), $mk('s2', '2026-09-01T00:00:00Z', 'Deploy script', ['tools/deploy.sh'])], $ex, $now, 24, 72);
ct('a month-old commit touching ONLY unpublished paths is not lag at all', $c['status'] === 'ok' && $c['publishable_count'] === 0 && $c['ahead_commits'] === 2);
$c = sync_compute([$mk('s1', '2026-09-01T00:00:00Z', 'Mixed', ['specs/a.md', 'api/y.php'])], $ex, $now, 24, 72);
ct('a commit touching ONE published path among unpublished ones counts', $c['publishable_count'] === 1 && $c['status'] === 'fail');
$c = sync_compute([$mk('new', '2026-10-01T23:00:00Z', 'New (GH#9)', ['a.php']), $mk('old', '2026-09-30T00:00:00Z', 'Old (GH#8)', ['b.php'])], $ex, $now, 24, 72);
ct('the AGE is the OLDEST unpublished commit, and every issue is listed', $c['oldest_sha'] === 'old' && $c['issues'] === [8, 9] && $c['status'] === 'warn');
$c = sync_compute([], $ex, $now, 24, 72);
ct('nothing ahead is OK', $c['status'] === 'ok' && $c['ahead_commits'] === 0);
$bullets = sync_bullets([$mk('n', 'x', 'Fix the thing (GH#12)', ['a.php']), $mk('m', 'x', 'Tidy comments', ['a.php']), $mk('o', 'x', 'Another fix. GH#3', ['b.php'])]);
ct('a changelog bullet only for a commit whose SUBJECT names an issue, newest first', $bullets === ['- Fix the thing (GH#12).', '- Another fix. GH#3.'], json_encode($bullets));
$msg = sync_commit_message(str_repeat('c', 40), [143, 146]);
ct('the commit message carries both trailers, the full sha, and a short readable subject',
    strpos($msg, "TicketsCAD sync ccccccc: GH#143 GH#146\n") === 0 && strpos($msg, 'Dev-Commit: ' . str_repeat('c', 40) . "\n") !== false
    && strpos($msg, "Fixes-Included: GH#143 GH#146\n") !== false);
ct('with no issues there is no Fixes-Included line', strpos(sync_commit_message(str_repeat('c', 40), []), 'Fixes-Included') === false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. THE FAILURE AS IT HAPPENED (real repositories) --\n";

$tmp = ct_tmp();
$snapScript = $tmp . '/snapshot.sh';
file_put_contents($snapScript, "EXCLUDES=(\n  specs\n  tools/deploy.sh\n)\n");
$dev = $tmp . '/dev';
$pub = $tmp . '/public';
ct('fixture repositories can be created', ct_repo_init($dev) && ct_repo_init($pub));

$c0 = ct_commit($dev, ['app.php' => "<?php // 1\n", 'specs/notes.md' => "n\n"], 'Base: the tree the public repo carries', '2026-09-01T10:00:00+00:00');
$p0 = ct_commit($pub, ['app.php' => "<?php // 1\n"], "TicketsCAD sync " . substr($c0, 0, 7) . "\n\nDev-Commit: {$c0}\n", '2026-09-01T11:00:00+00:00');
// The fix, deployed to the hosts but NOT published:
$c1 = ct_commit($dev, ['app.php' => "<?php // fixed\n"], 'Fix SOP table overflow (GH#143)', '2026-10-01T00:00:00+00:00');
$c2 = ct_commit($dev, ['specs/plan.md' => "plan\n"], 'Plan notes for GH#144', '2026-10-01T06:00:00+00:00');
$c3 = ct_commit($dev, ['README.md' => "docs\n"], 'Docs touch-up, no issue', '2026-10-01T08:00:00+00:00');

$base = ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-repo=' . $pub, '--public-ref=main', '--no-fetch'];
$sl = static function (array $args, array $env = []) use ($tool, $base): array {
    return ct_php($tool, array_merge($base, $args), ct_env($env));
};

$r = $sl(['--now=2026-10-01T10:00:00Z']);
ct('10h after the fix: reported, but still under the warn threshold (exit 0)', $r['code'] === 0, 'exit ' . $r['code'] . ' ' . trim($r['err'] . $r['out']));
ct('the report names the fix\'s issue', strpos($r['out'], 'GH#143') !== false, trim($r['out']));
ct('...but NOT an issue that only a specs-only commit mentions (that is not unpublished work)', strpos($r['out'], 'GH#144') === false, trim($r['out']));
ct('it counts only the publishable commits (the specs-only one is ahead but not lag)', strpos($r['out'], '3 dev commit(s), 2 touching published files') !== false, trim($r['out']));

$r = $sl(['--now=2026-10-02T02:00:00Z']);
ct('26h after: WARN (exit 1)', $r['code'] === 1, 'exit ' . $r['code']);
$r = $sl(['--now=2026-10-04T02:00:00Z']);
ct('74h after: FAIL (exit 2)', $r['code'] === 2, 'exit ' . $r['code']);
$j = json_decode($sl(['--now=2026-10-04T02:00:00Z', '--json'])['out'], true);
ct('--json carries the same verdict and the numbers behind it',
    is_array($j) && $j['status'] === 'fail' && $j['exit'] === 2 && (float) $j['oldest_age_hours'] === 74.0 && $j['publishable_commits'] === 2
    && $j['last_synced']['dev_commit'] === substr($c0, 0, 7) && $j['last_synced']['source'] === 'Dev-Commit trailer' && $j['issues'] === [143],
    json_encode($j));

// settings reach the verdict
$r = $sl(['--now=2026-10-02T02:00:00Z', '--warn-hours=48', '--fail-hours=100']);
ct('--warn-hours moves the threshold (26h is OK under 48)', $r['code'] === 0);
$r = $sl(['--now=2026-10-02T02:00:00Z'], ['SYNC_WARN_HOURS' => '30']);
ct('SYNC_WARN_HOURS (environment) moves it too', $r['code'] === 0);
$r = $sl(['--now=2026-10-02T02:00:00Z'], ['SYNC_WARN_HOURS' => '10', 'SYNC_FAIL_HOURS' => '20']);
ct('SYNC_FAIL_HOURS (environment) moves the fail line (26h is a FAIL under 20)', $r['code'] === 2);
$r = $sl(['--now=2026-10-02T02:00:00Z'], ['SYNC_WARN_HOURS' => 'banana']);
ct('a malformed SYNC_WARN_HOURS falls back to the default with a warning, not a crash', $r['code'] === 1 && stripos($r['err'], 'SYNC_WARN_HOURS') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$r = $sl(['--now=2026-10-02T02:00:00Z', '--warn-hours=50', '--fail-hours=10']);
ct('a fail threshold below the warn threshold is refused (exit 2)', $r['code'] === 2);

// THE GATE for "may I tell the reporter it is fixed?"
$r = $sl(['--issue=143', '--now=2026-10-01T10:00:00Z']);
ct('GATE: the fix is deployed but NOT in public main: --issue=143 exits 1 (do not say "fixed")', $r['code'] === 1, 'exit ' . $r['code'] . ' ' . trim($r['out']));
ct('GATE: and it names the unpublished commit', strpos($r['out'], substr($c1, 0, 7)) !== false && strpos($r['out'], 'not published') !== false, trim($r['out']));
$r = $sl(['--issue=144']);
ct('GATE: an issue whose only commit touches unpublished paths: exit 3 (nothing can be claimed)', $r['code'] === 3, 'exit ' . $r['code']);
$r = $sl(['--issue=999']);
ct('GATE: an issue no commit names: exit 3', $r['code'] === 3);
ct('GATE: a non-numeric issue is exit 2', $sl(['--issue=abc'])['code'] === 2);

// the public repo publishes it: a sync commit with the trailer
$p1 = ct_commit($pub, ['app.php' => "<?php // fixed\n"], "TicketsCAD sync " . substr($c1, 0, 7) . ": GH#143\n\nDev-Commit: {$c1}\nFixes-Included: GH#143\n", '2026-10-01T09:00:00+00:00');
$r = $sl(['--issue=143']);
ct('GATE: once public main carries the trailer for that commit, --issue=143 exits 0', $r['code'] === 0, 'exit ' . $r['code'] . ' ' . trim($r['out']));
$r = $sl(['--now=2026-10-01T10:00:00Z', '--json']);
$j = json_decode($r['out'], true);
ct('the newest trailer is the new reference point (c1), and only later commits are lag',
    $j['last_synced']['dev_commit'] === substr($c1, 0, 7) && $j['publishable_commits'] === 1 && $j['issues'] === []);

// a second commit for the same issue, not yet published
$c4 = ct_commit($dev, ['app.php' => "<?php // fixed properly\n"], 'Follow-up fix for GH#143 (a second commit)', '2026-10-01T12:00:00+00:00');
$r = $sl(['--issue=143']);
ct('GATE: a SECOND commit for the same issue, unpublished, flips it back to exit 1', $r['code'] === 1 && strpos($r['out'], substr($c4, 0, 7)) !== false, trim($r['out']));
ct('...and names ONLY the unpublished one', strpos($r['out'], substr($c1, 0, 7)) === false, trim($r['out']));
// matches in the BODY count (not just the subject)
$c5 = ct_commit($dev, ['api/z.php' => "<?php\n"], "Refactor\n\nFixes GH#150 as well.", '2026-10-01T13:00:00+00:00');
ct('GATE: an issue named only in a commit BODY is found', $sl(['--issue=150'])['code'] === 1);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. Failing closed: every \"cannot determine\" case is exit 4 --\n";

$pubBare = $tmp . '/public-notrailer';
ct_repo_init($pubBare);
ct_commit($pubBare, ['app.php' => "<?php\n"], "TicketsCAD release\n\nThe Dev-Commit: trailer is described in the docs.\n", '2026-09-01T11:00:00+00:00');
$baseNoTrailer = ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-repo=' . $pubBare, '--public-ref=main', '--no-fetch'];
$run4 = static function (array $args, array $env = []) use ($tool, $baseNoTrailer): array {
    return ct_php($tool, array_merge($baseNoTrailer, $args), ct_env($env));
};
$r = $run4([]);
ct('no trailer yet and no seed baseline: exit 4 (never "clean")', $r['code'] === 4 && stripos($r['err'], 'baseline') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
ct('the --issue gate fails closed too (exit 4, not 0)', $run4(['--issue=143'])['code'] === 4);
$r = $run4(['--baseline=' . substr($c0, 0, 10), '--now=2026-10-02T02:00:00Z', '--json']);
$j = json_decode($r['out'], true);
ct('--baseline=<sha> seeds it, and the report says the source is a seed', is_array($j) && $j['last_synced']['source'] === 'seed baseline' && $j['last_synced']['dev_commit'] === substr($c0, 0, 7), trim($r['out'] . $r['err']));
ct('--baseline with a sha that is not in the dev repository: exit 4', $run4(['--baseline=deadbeefdeadbeef'])['code'] === 4);
file_put_contents($dev . '/tools-baseline.tmp', '');
mkdir($dev . '/tools', 0777, true);
file_put_contents($dev . '/tools/sync-lag-baseline.txt', "# seed\n\n" . substr($c0, 0, 9) . "\n");
$r = $run4(['--now=2026-10-02T02:00:00Z', '--json']);
$j = json_decode($r['out'], true);
ct('tools/sync-lag-baseline.txt seeds it when there is no flag', is_array($j) && ($j['last_synced']['dev_commit'] ?? '') === substr($c0, 0, 7), trim($r['out'] . $r['err']));
file_put_contents($dev . '/tools/sync-lag-baseline.txt', "not-a-sha\n");
ct('a malformed seed file is not guessed at: exit 4', $run4([])['code'] === 4);
@unlink($dev . '/tools/sync-lag-baseline.txt');

$pubTrailerUnknown = $tmp . '/public-unknown';
ct_repo_init($pubTrailerUnknown);
ct_commit($pubTrailerUnknown, ['a' => "1\n"], "sync\n\nDev-Commit: " . str_repeat('d', 40) . "\n", '2026-09-01T11:00:00+00:00');
$r = ct_php($tool, ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-repo=' . $pubTrailerUnknown, '--public-ref=main', '--no-fetch'], ct_env());
ct('a trailer naming a dev commit this repository has never heard of: exit 4', $r['code'] === 4 && stripos($r['err'], 'not in this repository') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$r = ct_php($tool, array_merge($base, ['--snapshot-script=' . $tmp . '/does-not-exist.sh']), ct_env());
ct('no snapshot script, so no way to know what is published: exit 4', $r['code'] === 4);
$emptyScript = $tmp . '/empty.sh';
file_put_contents($emptyScript, "echo nothing\n");
ct('a snapshot script with no EXCLUDES array: exit 4', ct_php($tool, array_merge($base, ['--snapshot-script=' . $emptyScript]), ct_env())['code'] === 4);
ct('a dev ref that does not exist: exit 4', ct_php($tool, array_merge($base, ['--dev-ref=no-such-branch']), ct_env())['code'] === 4);
$notRepo = $tmp . '/not-a-repo';
mkdir($notRepo);
$r = ct_php($tool, ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-repo=' . $notRepo, '--no-fetch'], ct_env());
ct('a public path that is not a git repository: exit 4', $r['code'] === 4);
$r = ct_php($tool, array_merge($base, ['--json', '--snapshot-script=' . $tmp . '/does-not-exist.sh']), ct_env());
$j = json_decode($r['out'], true);
ct('in --json mode a refusal is still JSON, status unknown, exit 4', $r['code'] === 4 && is_array($j) && $j['status'] === 'unknown' && $j['exit'] === 4);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. Other sources of the public history --\n";

$apiRows = [
    ['sha' => 'pp2', 'commit' => ['message' => 'Unrelated public edit', 'committer' => ['date' => '2026-10-01T10:00:00Z']]],
    ['sha' => 'pp1', 'commit' => ['message' => "sync\n\nDev-Commit: {$c1}\n", 'committer' => ['date' => '2026-10-01T09:00:00Z']]],
    ['sha' => 'pp0', 'commit' => ['message' => "sync\n\nDev-Commit: {$c0}\n", 'committer' => ['date' => '2026-09-01T11:00:00Z']]],
];
$apiFile = ct_json_file('public-commits.json', $apiRows);
$r = ct_php($tool, ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-json=' . $apiFile, '--json', '--now=2026-10-02T00:00:00Z'], ct_env());
$j = json_decode($r['out'], true);
ct('--public-json (the GitHub commits API shape): newest trailer wins even with an unrelated newer public commit above it',
    is_array($j) && $j['last_synced']['dev_commit'] === substr($c1, 0, 7) && $j['last_synced']['public_commit'] === 'pp1', trim($r['out'] . $r['err']));
$g = ct_fake_gh([['match' => 'repos/o/r/commits', 'out' => json_encode($apiRows)]]);
$r = ct_php($tool, ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-github=o/r', '--json', '--now=2026-10-02T00:00:00Z'], $g['env']);
$j = json_decode($r['out'], true);
ct('--public-github reads the history through the real gh runner', is_array($j) && ($j['last_synced']['dev_commit'] ?? '') === substr($c1, 0, 7), trim($r['out'] . $r['err']));
ct('...with exactly one API call', count(ct_calls($g['log'])) === 1 && strpos(implode(' ', ct_calls($g['log'])[0]['args']), 'repos/o/r/commits') !== false);
$g = ct_fake_gh([['match' => 'repos/o/r/commits', 'out' => '', 'err' => 'HTTP 403 rate limit', 'code' => 1]]);
$r = ct_php($tool, ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-github=o/r'], $g['env']);
ct('GitHub unreachable: exit 4 with the reason, never a green', $r['code'] === 4 && strpos($r['err'], 'rate limit') !== false);
ct('a bad --public-github name: exit 4', ct_php($tool, ['--dev-repo=' . $dev, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-github=nope'], ct_env())['code'] === 4);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 7. The plan, and the changelog --\n";

$plan = static function (array $extra = []) use ($sl): array { return $sl(array_merge(['--plan', '--now=2026-10-02T00:00:00Z'], $extra)); };
$msgOut = $plan(['--emit=message'])['out'];
ct('--plan --emit=message: short subject naming the issues', preg_match('/^TicketsCAD sync [0-9a-f]{7}: GH#143 GH#150\n/', $msgOut) === 1, $msgOut);
ct('...Dev-Commit is the FULL sha of the dev ref being published', strpos($msgOut, 'Dev-Commit: ' . $c5 . "\n") !== false, $msgOut);
ct('...Fixes-Included lists every issue since the previous trailer', strpos($msgOut, "Fixes-Included: GH#143 GH#150\n") !== false);
ct('--emit=dev-commit prints that sha', trim($plan(['--emit=dev-commit'])['out']) === $c5);
ct('--emit=fixes', trim($plan(['--emit=fixes'])['out']) === 'GH#143 GH#150');
ct('--emit=bullets: one per issue-naming SUBJECT, newest first', trim($plan(['--emit=bullets'])['out']) === '- Follow-up fix for GH#143 (a second commit).', trim($plan(['--emit=bullets'])['out']));
ct('--headline overrides the subject but keeps the trailers',
    strpos($plan(['--emit=message', '--headline=TicketsCAD v9.9.9 — big one'])['out'], "TicketsCAD v9.9.9 — big one\n\nDev-Commit: ") === 0);
$planJson = json_decode($plan([])['out'], true);
ct('--plan with no --emit is JSON: message, bullets, issues, the commits', is_array($planJson) && $planJson['dev_commit'] === $c5 && $planJson['issues'] === [143, 150] && count($planJson['publishable_commits']) === 3, json_encode($planJson));

$clText = "# Changelog\n\n## [Unreleased]\n\n## [1.0.0] — 2026-08-01\n\n### Added\n\n- first\n";
$clFile = $tmp . '/CHANGELOG.md';
file_put_contents($clFile, $clText);
$r = $sl(['--plan', '--apply-changelog=' . $clFile, '--now=2026-10-02T00:00:00Z']);
$after = (string) file_get_contents($clFile);
ct('--apply-changelog creates "### Fixed" under [Unreleased] with the bullet', $r['code'] === 0
    && strpos($after, "## [Unreleased]\n\n### Fixed\n\n- Follow-up fix for GH#143 (a second commit).\n\n## [1.0.0]") !== false, $after);
$r = $sl(['--plan', '--apply-changelog=' . $clFile, '--now=2026-10-02T00:00:00Z']);
ct('running it again changes NOTHING (idempotent, no duplicate bullet)', (string) file_get_contents($clFile) === $after && substr_count($after, 'Follow-up fix') === 1);
file_put_contents($clFile, "# Changelog\r\n\r\n## [Unreleased]\r\n\r\n### Fixed\r\n\r\n- an older entry\r\n\r\n## [1.0.0]\r\n");
$sl(['--plan', '--apply-changelog=' . $clFile, '--now=2026-10-02T00:00:00Z']);
$after = (string) file_get_contents($clFile);
ct('with an existing "### Fixed", the new bullet goes on TOP of it, above the older entry',
    strpos($after, "### Fixed\n\n- Follow-up fix for GH#143 (a second commit).\n- an older entry") !== false, $after);
ct('CRLF input is normalised to LF (the public repository is LF)', strpos($after, "\r") === false);
file_put_contents($clFile, "# Changelog\n\n## [1.0.0] — 2026-08-01\n\n- first\n");
$sl(['--plan', '--apply-changelog=' . $clFile, '--now=2026-10-02T00:00:00Z']);
$after = (string) file_get_contents($clFile);
ct('with no [Unreleased] at all, one is created before the first release heading',
    strpos($after, "## [Unreleased]\n\n### Fixed") !== false && strpos($after, '## [Unreleased]') < strpos($after, '## [1.0.0]'), $after);
file_put_contents($clFile, $clText);
$r = $sl(['--plan', '--apply-changelog=' . $clFile, '--release=v1.2.3', '--date=2026-10-05', '--now=2026-10-02T00:00:00Z']);
$after = (string) file_get_contents($clFile);
ct('--release dates the section and leaves an EMPTY [Unreleased] above it',
    $r['code'] === 0 && strpos($after, "## [Unreleased]\n\n## [1.2.3] — 2026-10-05\n\n### Fixed\n\n- Follow-up fix") !== false, $after);
ct('a malformed --release is exit 2 and the file is untouched',
    ($r = $sl(['--plan', '--apply-changelog=' . $clFile, '--release=4.2', '--now=2026-10-02T00:00:00Z'])) && $r['code'] === 2 && (string) file_get_contents($clFile) === $after);
ct('a missing changelog file is exit 2', $sl(['--plan', '--apply-changelog=' . $tmp . '/nope.md'])['code'] === 2);

ct('sync_changelog_insert with no bullets returns the text unchanged (modulo LF)', sync_changelog_insert("a\r\nb\r\n", []) === "a\nb\n");
ct('sync_changelog_release with no [Unreleased] heading changes nothing', sync_changelog_release("# C\n## [1.0.0]\n", 'v2.0.0', '2026-01-01') === "# C\n## [1.0.0]\n");
$once = sync_changelog_release("# C\n\n## [Unreleased]\n\n### Fixed\n\n- x\n\n## [1.0.0]\n", '2.0.0', '2026-01-01');
ct('sync_changelog_release dates the section once', substr_count($once, '## [2.0.0] — 2026-01-01') === 1);
ct('...and running it again changes nothing (the "push the commit already waiting" run re-applies the plan)', sync_changelog_release($once, '2.0.0', '2026-02-02') === $once);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 8. A merge brings commits in individually --\n";

$dev2 = $tmp . '/dev2';
ct_repo_init($dev2);
$m0 = ct_commit($dev2, ['a.php' => "1\n"], 'base', '2026-09-01T00:00:00+00:00');
$pubM = $tmp . '/public-merge';
ct_repo_init($pubM);
ct_commit($pubM, ['a.php' => "1\n"], "sync\n\nDev-Commit: {$m0}\n", '2026-09-01T01:00:00+00:00');
ct_git($dev2, ['checkout', '-q', '-b', 'feature']);
ct_commit($dev2, ['f.php' => "feature\n"], 'Feature work (GH#77)', '2026-09-20T00:00:00+00:00');
ct_git($dev2, ['checkout', '-q', 'main']);
ct_commit($dev2, ['b.php' => "main\n"], 'Main work', '2026-09-21T00:00:00+00:00');
ct_git($dev2, ['merge', '--no-ff', '-q', '-m', 'Merge feature into main', 'feature'], ['GIT_AUTHOR_DATE' => '2026-09-22T00:00:00+00:00', 'GIT_COMMITTER_DATE' => '2026-09-22T00:00:00+00:00']);
$r = ct_php($tool, ['--dev-repo=' . $dev2, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-repo=' . $pubM, '--public-ref=main', '--no-fetch', '--json', '--now=2026-10-01T00:00:00Z'], ct_env());
$j = json_decode($r['out'], true);
ct('the merged branch\'s commit is counted individually; the merge itself lists no files and adds nothing',
    is_array($j) && $j['publishable_commits'] === 2 && $j['issues'] === [77], trim($r['out'] . $r['err']));
ct('--issue sees a fix that arrived through a merge', ct_php($tool, ['--dev-repo=' . $dev2, '--dev-ref=main', '--snapshot-script=' . $snapScript, '--public-repo=' . $pubM, '--public-ref=main', '--no-fetch', '--issue=77'], ct_env())['code'] === 1);

ct_finish();
