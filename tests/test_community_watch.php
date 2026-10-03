<?php
/**
 * Phase 155 — the state-based detector (tools/community-watch.php).
 *
 * THE FAILURES BEING PREVENTED, reproduced from the 2026-10-02 record
 *
 *   - an issue nobody ever answered (#147) invisible for 17 days because the
 *     old sweep listed "what changed", not "what was never answered";
 *   - a reporter's "still broken" on a CLOSED issue (#139) unread for 21 days,
 *     because a query for open issues cannot see a comment on a closed one;
 *   - the legacy repository (two issues, two pull requests, 34 days) never
 *     looked at at all;
 *   - an inbox that emails on every run until nobody reads it.
 *
 * HOW
 *
 *   Part 1 feeds the pure classifier items shaped like real GitHub REST rows
 *   (through the real normalisers), so a "reporter waiting" case is built the
 *   way GitHub reports it, not hand-assembled. Part 2 proves the notification
 *   rule (quiet unless the SET changed, rate-limited, a deferred change is not
 *   lost). Part 3 proves untrusted titles cannot inject into the inbox. Part 4
 *   runs the real tool through the real command runner against a recording fake
 *   gh, including paginated output and the inbox create/edit/comment cycle.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('COMMUNITY_WATCH_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
$tool = $root . '/tools/community-watch.php';
if (!is_file($tool)) ct_skip('tools/community-watch.php not present (it is development-tree only)');
require_once $tool;

echo "=== Community watch ===\n\n";

foreach (array_keys(getenv()) as $k) {
    if (preg_match('/^(COMMUNITY_|AWAY_UNTIL|SYNC_)/', (string) $k)) putenv((string) $k);
}

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. Does the comment ask something, or say it is still broken? --\n";

$yes = [
    'Just did a git pull and the issue still exists',
    'Is this fixed in 4.2.28?', 'doesn\'t work for me', 'It fails again after the update', 'still seeing the overflow',
    'Verified the first fix, but still seeing the second problem', 'same error as before', 'This is broken on Windows',
    'worse than before', "Quoted above:\n> works now\n\nBut what about the other screen?",
];
$no = [
    'Verified, closing. Thanks!', 'Confirmed fixed. Works now.', 'thanks', 'Looks good on my install.', 'Verified: it no longer fails, closing.',
    "> still broken?\n\nLooks good now, thanks.", "```\nerror: is this a question?\n```\nresolved, thanks",
];
foreach ($yes as $t) ct('asks/persists: ' . json_encode(substr($t, 0, 50)), cw_unresolved($t) === true);
foreach ($no as $t) ct('a confirmation, skipped: ' . json_encode(substr($t, 0, 50)), cw_unresolved($t) === false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. Classification (rows shaped like GitHub's REST responses) --\n";

$now = (int) strtotime('2026-10-02T21:00:00Z');
$row = static function (int $n, string $title, string $user, string $created, array $o = []): array {
    $r = [
        'number' => $n, 'title' => $title, 'state' => $o['state'] ?? 'open',
        'user' => ['login' => $user, 'type' => $o['type'] ?? 'User'],
        'labels' => array_map(static fn(string $l): array => ['name' => $l], $o['labels'] ?? []),
        'created_at' => $created, 'closed_at' => $o['closed'] ?? null, 'updated_at' => $o['updated'] ?? $created,
        'html_url' => "https://github.com/o/r/issues/{$n}", 'comments' => count($o['comments'] ?? []),
    ];
    if (!empty($o['pr'])) $r['pull_request'] = ['url' => 'x'];
    return $r;
};
$com = static function (string $user, string $created, string $body, string $type = 'User'): array {
    return ['user' => ['login' => $user, 'type' => $type], 'created_at' => $created, 'body' => $body];
};
$item = static function (string $slug, array $issueRow, array $comments = []) use ($com): array {
    $it = cw_norm_item($issueRow, $slug);
    foreach ($comments as $c) $it['comments'][] = cw_norm_comment($c);
    return $it;
};

$items = [
    // The record's cases:
    $item('o/r', $row(147, 'Status webhook', 'reporter-a', '2026-09-15T08:00:00Z')),                                   // never answered
    $item('o/r', $row(148, 'Tow dispatch', 'reporter-a', '2026-09-18T08:00:00Z'), [$com('EJosterberg', '2026-10-02T05:42:00Z', 'Acknowledged.')]),
    $item('o/r', $row(141, 'Design question', 'reporter-a', '2026-09-09T08:00:00Z'), [$com('ejosterberg', '2026-09-09T20:00:00Z', 'Answer given.')]),   // 23d silent
    $item('o/r', $row(139, 'Overlays twice', 'reporter-b', '2026-09-08T08:00:00Z',
        ['state' => 'closed', 'closed' => '2026-09-10T08:00:00Z', 'updated' => '2026-09-12T02:56:00Z']),
        [$com('ejosterberg', '2026-09-09T09:00:00Z', 'Fixed.'), $com('reporter-b', '2026-09-12T02:56:00Z', 'Just did a git pull and the issue still exists')]),
    $item('o/r', $row(140, 'Closed and confirmed', 'reporter-a', '2026-09-08T08:00:00Z',
        ['state' => 'closed', 'closed' => '2026-09-10T08:00:00Z', 'updated' => '2026-09-10T09:00:00Z']),
        [$com('ejosterberg', '2026-09-09T09:00:00Z', 'Fixed.'), $com('reporter-a', '2026-09-10T09:00:00Z', 'Verified, closing. Thanks!')]),
    $item('o/r', $row(52, 'Old closed', 'reporter-c', '2026-06-01T08:00:00Z',
        ['state' => 'closed', 'closed' => '2026-06-02T08:00:00Z', 'updated' => '2026-07-04T08:00:00Z']),
        [$com('reporter-c', '2026-07-04T08:00:00Z', 'This is still not working')]),                                    // 90d ago: outside the window
    // Features and decisions:
    $item('o/r', $row(151, 'DVM host', 'kc3dvr', '2026-09-23T08:00:00Z', ['labels' => ['enhancement']]), [$com('ejosterberg', '2026-10-02T05:42:00Z', 'Noted.')]),
    $item('o/r', $row(152, 'Planned feature', 'kc3dvr', '2026-09-23T08:00:00Z', ['labels' => ['enhancement', 'planned']]), [$com('ejosterberg', '2026-10-02T05:42:00Z', 'Noted.')]),
    $item('o/r', $row(153, 'Brand new feature', 'kc3dvr', '2026-09-30T08:00:00Z', ['labels' => ['enhancement']]), [$com('ejosterberg', '2026-10-02T05:42:00Z', 'Noted.')]),
    // Reporter waiting on an OPEN issue; a bot acknowledgement arrived AFTER the question:
    $item('o/r', $row(60, 'Open with a question', 'reporter-d', '2026-10-01T00:00:00Z'),
        [$com('ejosterberg', '2026-10-01T01:00:00Z', 'Try X.'), $com('reporter-d', '2026-10-01T10:00:00Z', 'Tried X. Is there anything else?'),
         $com('github-actions[bot]', '2026-10-01T10:05:00Z', 'automatic acknowledgement', 'Bot')]),
    $item('o/r', $row(61, 'Maintainer spoke last', 'reporter-d', '2026-10-01T00:00:00Z'),
        [$com('reporter-d', '2026-10-01T10:00:00Z', 'Is this expected?'), $com('ejosterberg', '2026-10-01T11:00:00Z', 'Yes.')]),
    // The maintainer's own issues and a Dependabot PR are not "unanswered":
    $item('o/r', $row(62, 'Self-filed', 'ejosterberg', '2026-09-01T08:00:00Z')),
    $item('o/r', $row(64, 'Bump a dependency', 'dependabot[bot]', '2026-09-20T08:00:00Z', ['pr' => true, 'type' => 'Bot'])),
    $item('o/r', $row(65, 'Outside pull request', 'contrib', '2026-09-28T08:00:00Z', ['pr' => true])),
    $item('o/r', $row(66, 'Three hours old', 'reporter-e', '2026-10-02T18:00:00Z')),
    $item('o/r', $row(67, 'Five hours old', 'reporter-e', '2026-10-02T16:00:00Z')),
    // The legacy repository the old sweep never looked at:
    $item('o/legacy', $row(16, 'PHP 7 report fatals', 'reporter-a', '2026-08-29T08:00:00Z')),
    $item('o/legacy', $row(17, 'Fix for #16', 'reporter-a', '2026-08-29T08:01:00Z', ['pr' => true])),
];
$data = ['now' => $now, 'maintainers' => ['ejosterberg'], 'repos' => ['o/r' => ['items' => array_slice($items, 0, 16)], 'o/legacy' => ['items' => array_slice($items, 16)]]];
$cfg = cw_config();
$res = cw_compute($data, $cfg);
$nums = static fn(array $rows): array => array_map(static fn(array $r): string => $r['repo'] . '#' . $r['number'], $rows);

ct('UNANSWERED: the issue nobody ever answered (the #147 case) is found', in_array('o/r#147', $nums($res['unanswered']), true), json_encode($nums($res['unanswered'])));
ct('UNANSWERED: an outside pull request with no reply is found, and says it is a pull request',
    in_array('o/r#65', $nums($res['unanswered']), true) && array_values(array_filter($res['unanswered'], static fn($r) => $r['number'] === 65))[0]['kind'] === 'pr');
ct('UNANSWERED: the LEGACY repository is covered (issue and pull request)', in_array('o/legacy#16', $nums($res['unanswered']), true) && in_array('o/legacy#17', $nums($res['unanswered']), true));
ct('UNANSWERED: an item the maintainer answered is not', !in_array('o/r#148', $nums($res['unanswered']), true));
ct('UNANSWERED: the maintainer\'s own issue is not (and the login match is case-insensitive)', !in_array('o/r#62', $nums($res['unanswered']), true));
ct('UNANSWERED: a Dependabot pull request is not; it is listed separately', !in_array('o/r#64', $nums($res['unanswered']), true) && $nums($res['dependabot']) === ['o/r#64']);
ct('UNANSWERED: 3 hours old is under the 4h threshold; 5 hours old is over it',
    !in_array('o/r#66', $nums($res['unanswered']), true) && in_array('o/r#67', $nums($res['unanswered']), true));

ct('WAITING: a stranger\'s "still exists" on a CLOSED issue (the #139 case) is found', in_array('o/r#139', $nums($res['waiting']), true), json_encode($nums($res['waiting'])));
ct('WAITING: "Verified, closing. Thanks!" on a closed issue is not', !in_array('o/r#140', $nums($res['waiting']), true));
ct('WAITING: a closed issue whose last word was 90 days ago is outside the 60-day window', !in_array('o/r#52', $nums($res['waiting']), true));
ct('WAITING: an open issue whose newest HUMAN comment is the reporter\'s question is found even though a bot comment came after it', in_array('o/r#60', $nums($res['waiting']), true));
ct('WAITING: if the maintainer spoke last, nobody is waiting', !in_array('o/r#61', $nums($res['waiting']), true));
$w139 = array_values(array_filter($res['waiting'], static fn($r) => $r['number'] === 139))[0];
ct('WAITING: it reports how long (about 20 days)', $w139['waiting_hours'] > 19 * 24 && $w139['waiting_hours'] < 21 * 24, (string) $w139['waiting_hours']);

ct('AGING: an open item whose last maintainer comment is 23 days old is found', in_array('o/r#141', $nums($res['aging']), true));
ct('AGING: one the maintainer answered this morning is not', !in_array('o/r#148', $nums($res['aging']), true));
ct('DECISION: a feature with no disposition label after 9 days is found', in_array('o/r#151', $nums($res['decision']), true));
ct('DECISION: a `planned` feature is not', !in_array('o/r#152', $nums($res['decision']), true));
ct('DECISION: a feature only 3 days old is not', !in_array('o/r#153', $nums($res['decision']), true));

ct('open counts per repository', $res['open_counts']['o/r'] === ['issues' => 11, 'prs' => 2] && $res['open_counts']['o/legacy'] === ['issues' => 1, 'prs' => 1], json_encode($res['open_counts']));
ct('every flagged item has a stable key, and the key list is sorted and unique',
    in_array('unanswered:o/r#147', $res['keys'], true) && in_array('waiting:o/r#139', $res['keys'], true) && $res['keys'] === array_values(array_unique($res['keys'])) && $res['keys'] === (function () use ($res) { $k = $res['keys']; sort($k); return $k; })());

// settings reach the classifier
$tight = $cfg; $tight['unanswered_hours'] = 2;
ct('COMMUNITY_UNANSWERED_HOURS is the threshold applied (3h old is unanswered under 2h)', in_array('o/r#66', $nums(cw_compute($data, $tight)['unanswered']), true));
$loose = $cfg; $loose['aging_days'] = 30;
ct('COMMUNITY_AGING_DAYS is the threshold applied (23 days is not aging under 30)', !in_array('o/r#141', $nums(cw_compute($data, $loose)['aging']), true));
$wide = $cfg; $wide['closed_window_days'] = 120;
ct('COMMUNITY_CLOSED_WINDOW_DAYS is the window applied (the 90-day-old closed issue appears under 120)', in_array('o/r#52', $nums(cw_compute($data, $wide)['waiting']), true));
$slow = $cfg; $slow['waiting_hours'] = 24 * 40;
ct('COMMUNITY_WAITING_HOURS is the threshold applied', !in_array('o/r#139', $nums(cw_compute($data, $slow)['waiting']), true));
$decide = $cfg; $decide['decision_labels'] = ['enhancement'];
ct('COMMUNITY_DECISION_LABELS is the set applied', !in_array('o/r#151', $nums(cw_compute($data, $decide)['decision']), true));
$otherMaint = cw_compute($data + [], $cfg);
$data2 = $data; $data2['maintainers'] = ['someone-else'];
ct('COMMUNITY_MAINTAINERS decides who counts as the maintainer (change it and #148 is unanswered)', in_array('o/r#148', $nums(cw_compute($data2, $cfg)['unanswered']), true));

// publication, release and CI feed the keys
$pub = $data;
$pub['sync_lag'] = ['status' => 'fail', 'publishable_commits' => 63, 'oldest_age_hours' => 500, 'issues' => [140, 143]];
$pub['release'] = ['o/r' => ['tag' => 'v4.2.27', 'published_at' => '2026-09-02T00:00:00Z', 'commits_since' => 8, 'unreleased_empty' => true]];
$pub['ci'] = ['o/r' => ['sha' => str_repeat('a', 40), 'state' => 'red', 'detail' => 'failed: qa'], 'o/legacy' => ['sha' => str_repeat('b', 40), 'state' => 'green', 'detail' => '1 run(s) passed']];
$rp = cw_compute($pub, $cfg);
ct('a FAILING sync lag becomes an item that needs a person', in_array('sync-lag:fail', $rp['keys'], true));
ct('a release older than the floor with commits since becomes one', in_array('release-floor:o/r', $rp['keys'], true));
ct('red CI by exact SHA becomes one, keyed by the SHA (a new red commit is a new item)', in_array('ci:o/r:aaaaaaa', $rp['keys'], true) && !in_array('ci:o/legacy:bbbbbbb', $rp['keys'], true));
$ok = $pub; $ok['sync_lag']['status'] = 'ok'; $ok['ci']['o/r']['state'] = 'green';
$ok['release']['o/r']['commits_since'] = 0;
$rok = cw_compute($ok, $cfg);
ct('an OK lag, green CI and a release with nothing since add NO item',
    !in_array('sync-lag:ok', $rok['keys'], true) && count(array_filter($rok['keys'], static fn($k) => strpos($k, 'ci:') === 0 || strpos($k, 'release-floor') === 0 || strpos($k, 'sync-lag') === 0)) === 0);
$ar = $pub; $ar['ci']['o/r']['state'] = 'action_required';
ct('action_required is NOT success: it is an item (it looks like a failure in email and blocks the pipeline)', in_array('ci:o/r:aaaaaaa', cw_compute($ar, $cfg)['keys'], true));
$unk = $pub; $unk['sync_lag'] = ['status' => 'unknown', 'reason' => 'no baseline']; $unk['ci']['o/r']['state'] = 'unknown';
ct('"could not determine" is shown in the inbox but is not claimed to be a problem or a pass', !in_array('sync-lag:unknown', cw_compute($unk, $cfg)['keys'], true)
    && strpos(cw_render(cw_compute($unk, $cfg), $unk, $cfg), 'could not be determined') !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. CI state by exact SHA --\n";

$sha = str_repeat('c', 40);
$mkRun = static fn(string $status, string $concl, string $name = 'qa'): array => ['status' => $status, 'conclusion' => $concl, 'name' => $name];
ct('no runs at all: "none", and the text says why that matters', cw_ci_state($sha, [])['state'] === 'none' && strpos(cw_ci_state($sha, [])['detail'], 'outage') !== false);
ct('all completed successfully: green', cw_ci_state($sha, [$mkRun('completed', 'success'), $mkRun('completed', 'skipped', 'x')])['state'] === 'green');
ct('one failure among successes: red, naming it', ($s = cw_ci_state($sha, [$mkRun('completed', 'success'), $mkRun('completed', 'failure', 'sbom')])) && $s['state'] === 'red' && strpos($s['detail'], 'sbom') !== false);
ct('cancelled, timed_out and startup_failure are red too', cw_ci_state($sha, [$mkRun('completed', 'cancelled')])['state'] === 'red' && cw_ci_state($sha, [$mkRun('completed', 'timed_out')])['state'] === 'red' && cw_ci_state($sha, [$mkRun('completed', 'startup_failure')])['state'] === 'red');
ct('a run still going is pending, never green', cw_ci_state($sha, [$mkRun('completed', 'success'), $mkRun('in_progress', '')])['state'] === 'pending');
ct('action_required is its own state', cw_ci_state($sha, [$mkRun('completed', 'action_required')])['state'] === 'action_required');
ct('red outranks pending (a failure is not hidden by a slow sibling)', cw_ci_state($sha, [$mkRun('completed', 'failure'), $mkRun('in_progress', '')])['state'] === 'red');

ct('[Unreleased] empty: detected', cw_unreleased_empty("# C\n\n## [Unreleased]\n\n## [1.0.0]\n- x\n") === true);
ct('[Unreleased] with only sub-headings: still empty', cw_unreleased_empty("## [Unreleased]\n\n### Fixed\n\n## [1.0.0]\n") === true);
ct('[Unreleased] with a bullet: not empty', cw_unreleased_empty("## [Unreleased]\n\n### Fixed\n\n- a thing\n\n## [1.0.0]\n") === false);
ct('no [Unreleased] heading at all: unknown (null), not "empty"', cw_unreleased_empty("# C\n## [1.0.0]\n") === null);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. When to email (the set changed, not the run) --\n";

$t0 = (int) strtotime('2026-10-02T00:00:00Z');
$keys1 = ['unanswered:o/r#147', 'waiting:o/r#139'];
$first = cw_notify_decision(null, $keys1, $t0, 24);
ct('the first ever run with items notifies', $first['notify'] === true && count($first['added']) === 2);
ct('the first ever run with NOTHING does not (record the baseline, say nothing)', cw_notify_decision(null, [], $t0, 24)['notify'] === false);
$state = $first['state'];
$same = cw_notify_decision($state, array_reverse($keys1), $t0 + 3600 * 5, 24);
ct('the same set, in any order, hours later: SILENCE', $same['notify'] === false && $same['why'] === 'no change');
$more = array_merge($keys1, ['unanswered:o/r#150']);
$d = cw_notify_decision($state, $more, $t0 + 3600 * 5, 24);
ct('a changed set within the minimum gap is DEFERRED, not sent', $d['notify'] === false && strpos($d['why'], 'deferred') !== false);
ct('...and a deferred change is NOT LOST: the stored state is still the last NOTIFIED set', $d['state'] === $state);
$d2 = cw_notify_decision($d['state'], $more, $t0 + 3600 * 26, 24);
ct('the next run after the quiet period sees the difference and speaks', $d2['notify'] === true && $d2['added'] === ['unanswered:o/r#150'] && $d2['cleared'] === []);
ct('...and records the new set with the new time', $d2['state']['keys'] === ['unanswered:o/r#147', 'unanswered:o/r#150', 'waiting:o/r#139'] && $d2['state']['at'] === gmdate('c', $t0 + 3600 * 26));
$cleared = cw_notify_decision($d2['state'], ['unanswered:o/r#150'], $t0 + 3600 * 60, 24);
ct('items that cleared are reported as cleared', $cleared['notify'] === true && $cleared['cleared'] === ['unanswered:o/r#147', 'waiting:o/r#139'] && $cleared['added'] === []);
$allClear = cw_notify_decision($d2['state'], [], $t0 + 3600 * 60, 24);
ct('the set becoming EMPTY is worth one "all clear" notice', $allClear['notify'] === true && cw_notice_text($allClear, '') !== '' && strpos(cw_notice_text($allClear, ''), 'is clear') !== false);
$stillClear = cw_notify_decision($allClear['state'], [], $t0 + 3600 * 200, 24);
ct('...and an empty set that stays empty is silent', $stillClear['notify'] === false);
$min = cw_notify_decision($state, $more, $t0 + 3600 * 2, 1);
ct('COMMUNITY_NOTIFY_MIN_HOURS is the gap applied (a 1h gap lets a 2h-later change through)', $min['notify'] === true);
$rt = cw_parse_state('text ' . cw_state_marker($state) . ' more');
ct('the state marker round-trips through an issue body', $rt === $state);
ct('a body with no marker, or a corrupted one, is "no prior state" (never a crash)', cw_parse_state('nothing here') === null && cw_parse_state('<!-- community-watch:state:@@@@ -->') === null
    && cw_parse_state('<!-- community-watch:state:' . base64_encode('{"hash":1}') . ' -->') === null);
ct('the notice text lists what is new, as code, capped', strpos(cw_notice_text($d2, ''), '`unanswered:o/r#150`') !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. The inbox body: untrusted text, and what it shows --\n";

$evil = $row(300, "Pwn | @everyone `id` <script>alert(1)</script> [click](http://evil.example) \n# fake heading", "evil|user\n@here", '2026-09-01T00:00:00Z');
$dataEvil = ['now' => $now, 'maintainers' => ['ejosterberg'], 'repos' => ['o/r' => ['items' => [cw_norm_item($evil, 'o/r')]]]];
$resEvil = cw_compute($dataEvil, $cfg);
$body = cw_render($resEvil, $dataEvil, $cfg);
ct('the hostile item IS listed', in_array('unanswered:o/r#300', $resEvil['keys'], true));
ct('...but no raw @mention, markup, link or heading from its title or author survives in the body',
    strpos($body, '@everyone') === false && strpos($body, '@here') === false && strpos($body, '<script>') === false
    && preg_match('/(?<!\\\\)\]\(http:\/\/evil/', $body) === 0 && strpos($body, "\n# fake heading") === false);
ct('...and its table row is intact (pipes escaped, one line)', preg_match('/^\| \[o\/r#300\]\(https:\/\/github\.com\/o\/r\/issues\/300\) \| issue \| Pwn \\\\\| /m', $body) === 1, $body);
$long = $row(301, str_repeat('Z', 400), 'x', '2026-09-01T00:00:00Z');
$bl = cw_render(cw_compute(['now' => $now, 'maintainers' => [], 'repos' => ['o/r' => ['items' => [cw_norm_item($long, 'o/r')]]]], $cfg), ['now' => $now, 'repos' => []], $cfg);
ct('a 400-character title is capped', strpos($bl, str_repeat('Z', 80)) === false);

$bodyAll = cw_render($rp, $pub, $cfg);
ct('the body states how many need a person', strpos($bodyAll, '**Needs a person now: ' . count($rp['keys']) . '**') !== false);
ct('it has all six sections', preg_match_all('/^## [1-6]\. /m', $bodyAll) === 6);
ct('publication: sync lag with the numbers and the gate command', strpos($bodyAll, 'Sync lag: **FAIL** — 63 publishable dev commit(s) not in public main, oldest 20.8d (GH#140 GH#143)') !== false
    && strpos($bodyAll, 'sync-lag-check.php --issue=N') !== false, $bodyAll);
ct('publication: release age, commits since, the empty changelog, and the floor', strpos($bodyAll, 'v4.2.27, 30') !== false && strpos($bodyAll, '8 commit(s) on main since') !== false
    && strpos($bodyAll, 'the changelog\'s [Unreleased] section is empty') !== false && strpos($bodyAll, '28-day release floor') !== false);
ct('CI: by SHA, with the state', strpos($bodyAll, 'CI (o/r @ aaaaaaa): **red** — failed: qa') !== false);
ct('Dependabot pull requests are listed', strpos($bodyAll, 'Open Dependabot pull requests: 1') !== false);
$cfgAway = $cfg; $cfgAway['away_until'] = '2026-10-20';
ct('AWAY_UNTIL shows in the header while in the future', strpos(cw_render($rp, $pub, $cfgAway), 'Away until: **2026-10-20**') !== false);
ct('...and says "not set" otherwise', strpos($bodyAll, 'Away: not set') !== false);
$quiet = cw_render(cw_compute(['now' => $now, 'maintainers' => ['ejosterberg'], 'repos' => ['o/r' => ['items' => []]]], $cfg), ['now' => $now, 'repos' => [], 'warnings' => []], $cfg);
ct('an empty inbox says nothing is waiting', strpos($quiet, 'nothing is waiting') !== false);
ct('the body is replaced, not appended: it ends with no state marker (the caller adds exactly one)', strpos($bodyAll, 'community-watch:state') === false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. Metrics (the response-time tables, from a dataset) --\n";

ct('percentile: linear interpolation', cw_percentile([1.0, 2.0, 3.0, 4.0, 5.0], 50) === 3.0 && abs((float) cw_percentile([1.0, 2.0, 3.0, 4.0, 5.0], 90) - 4.6) < 1e-9 && cw_percentile([], 50) === null);
$m = static function (string $created, array $replies = []) use ($row, $com): array {
    static $n = 400;
    $n++;
    $it = cw_norm_item($row($n, 'x', 'someone', $created, ['state' => 'closed']), 'o/r');
    foreach ($replies as $c) $it['comments'][] = cw_norm_comment($c);
    return $it;
};
$mItems = [
    $m('2026-09-07T00:00:00Z', [$com('ejosterberg', '2026-09-07T02:00:00Z', 'a')]),                 // 2h
    $m('2026-09-08T00:00:00Z', [$com('ejosterberg', '2026-09-08T10:00:00Z', 'a')]),                 // 10h
    $m('2026-09-09T00:00:00Z', [$com('ejosterberg', '2026-09-10T06:00:00Z', 'a')]),                 // 30h
    $m('2026-09-10T00:00:00Z', []),                                                                  // never
    cw_norm_item($row(499, 'maintainer own', 'ejosterberg', '2026-09-10T00:00:00Z'), 'o/r'),         // excluded
    cw_norm_item($row(498, 'a pr', 'someone', '2026-09-10T00:00:00Z', ['pr' => true]), 'o/r'),       // not an issue
];
$mData = ['now' => $now, 'maintainers' => ['ejosterberg'], 'repos' => ['o/r' => ['items' => $mItems]]];
$md = cw_metrics($mData, $cfg);
ct('volume counts only outside-filed ISSUES (the maintainer\'s own and pull requests are excluded)', strpos($md, 'Outside-filed issues in this dataset: 4 (0 open, 4 closed); pull requests in the dataset: 1') !== false, $md);
ct('first reply: 4 issues, 3 replied, median 10h, worst 30h', strpos($md, '| All | 4 | 3 | 10h | 20h | 26h | 30h |') !== false, $md);
ct('replied-within shares count a never-answered issue as a miss (25% / 50% / 100% of... of 4)', strpos($md, '| All | 25% | 50% | 75% | 75% | 75% |') !== false, $md);
$split = cw_metrics($mData, $cfg, '2026-09-09');
ct('--split-date splits the groups', strpos($split, '| Before 2026-09-09 | 2 | 2 |') !== false && strpos($split, '| From 2026-09-09 | 2 | 1 |') !== false, $split);
ct('a weekly series is produced (week beginning Monday, UTC)', strpos($md, '| 2026-09-07 | 4 |') !== false, $md);
ct('"who spoke last" lists a closed issue whose last word is a stranger\'s unresolved comment',
    strpos(cw_metrics(['now' => $now, 'maintainers' => ['ejosterberg'], 'repos' => ['o/r' => ['items' => [$item('o/r', $row(139, 'x', 'rb', '2026-09-08T00:00:00Z', ['state' => 'closed']), [$com('rb', '2026-09-12T02:56:00Z', 'still exists')])]]]], $cfg), '| o/r#139 | closed | rb |') !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 7. The real tool through the real command runner --\n";

// Offline: a dataset file in, markdown and JSON out.
$dsFile = ct_json_file('dataset.json', $data);
$g = ct_fake_gh([]);
$r = ct_php($tool, ['--input=' . $dsFile, '--now=2026-10-02T21:00:00Z'], $g['env']);
ct('--input renders the inbox without calling gh', $r['code'] === 0 && ct_calls($g['log']) === [] && strpos($r['out'], '# Community inbox') !== false && strpos($r['out'], 'o/r#147') !== false, $r['err']);
$rj = ct_php($tool, ['--input=' . $dsFile, '--now=2026-10-02T21:00:00Z', '--json'], $g['env']);
$j = json_decode($rj['out'], true);
ct('--json: the count, the keys and the structured result', is_array($j) && $j['needs_a_person'] === count($j['keys']) && in_array('unanswered:o/r#147', $j['keys'], true) && isset($j['result']['waiting']));
$rm = ct_php($tool, ['--input=' . $dsFile, '--now=2026-10-02T21:00:00Z', '--metrics'], $g['env']);
ct('--metrics prints the response-time tables', $rm['code'] === 0 && strpos($rm['out'], '### First maintainer reply') !== false);
ct('a bad --now is exit 2', ct_php($tool, ['--input=' . $dsFile, '--now=banana'], $g['env'])['code'] === 2);
ct('an unreadable --input is exit 2', ct_php($tool, ['--input=' . ct_tmp() . '/nope.json'], $g['env'])['code'] === 2);
ct('a bad --repo is exit 2', ct_php($tool, ['--repo=nope', '--input=' . $dsFile], $g['env'])['code'] === 2);
ct('an unknown option is exit 2', ct_php($tool, ['--frobnicate'], $g['env'])['code'] === 2);
$g2 = ct_fake_gh([]);
$rEnv = ct_php($tool, ['--input=' . $dsFile, '--now=2026-10-02T21:00:00Z', '--json'], ct_fake_gh([], ['COMMUNITY_UNANSWERED_HOURS' => '2'])['env']);
ct('COMMUNITY_UNANSWERED_HOURS in the environment reaches the real tool (#66 appears)', in_array('unanswered:o/r#66', json_decode($rEnv['out'], true)['keys'] ?? [], true));
$rEnv2 = ct_php($tool, ['--input=' . $dsFile, '--now=2026-10-02T21:00:00Z', '--json'], ct_fake_gh([], ['COMMUNITY_MAINTAINERS' => 'someone-else'])['env']);
ct('COMMUNITY_MAINTAINERS reaches the real tool', in_array('unanswered:o/r#148', json_decode($rEnv2['out'], true)['keys'] ?? [], true));

// Gathering: paginated output, closed items from the comment feed, PR reviews.
$openA = json_encode([$row(147, 'Never answered', 'rep', '2026-09-15T08:00:00Z', ['comments' => []]), $row(60, 'Has comments', 'rep', '2026-10-01T00:00:00Z', ['comments' => [1, 2]])]);
$openB = json_encode([$row(65, 'Outside PR', 'contrib', '2026-09-28T08:00:00Z', ['pr' => true])]);
$closed = json_encode([$row(139, 'Closed with a stranger\'s last word', 'rb', '2026-09-08T08:00:00Z', ['state' => 'closed', 'closed' => '2026-09-10T08:00:00Z', 'updated' => '2026-09-12T02:56:00Z', 'comments' => [1, 2]])]);
$feed = json_encode([
    ['issue_url' => 'https://api.github.com/repos/o/r/issues/139', 'user' => ['login' => 'ejosterberg', 'type' => 'User'], 'created_at' => '2026-09-09T09:00:00Z', 'body' => 'Fixed.'],
    ['issue_url' => 'https://api.github.com/repos/o/r/issues/139', 'user' => ['login' => 'rb', 'type' => 'User'], 'created_at' => '2026-09-12T02:56:00Z', 'body' => 'Just did a git pull and the issue still exists'],
    ['issue_url' => 'https://api.github.com/repos/o/r/issues/60', 'user' => ['login' => 'rep', 'type' => 'User'], 'created_at' => '2026-10-01T10:00:00Z', 'body' => 'feed copy of an OPEN item: must be ignored'],
]);
$fetchRules = [
    ['match' => 'issues?state=open', 'out' => $openA . $openB],                     // two pages, back to back, as gh --paginate prints them
    ['match' => 'issues?state=closed', 'out' => $closed],
    ['match' => 'issues/comments?since', 'out' => $feed],
    ['match' => 'issues/60/comments', 'out' => json_encode([['user' => ['login' => 'ejosterberg', 'type' => 'User'], 'created_at' => '2026-10-01T01:00:00Z', 'body' => 'Try X.'], ['user' => ['login' => 'rep', 'type' => 'User'], 'created_at' => '2026-10-01T10:00:00Z', 'body' => 'Is there anything else?']])],
    ['match' => 'issues/65/comments', 'out' => '[]'],
    ['match' => 'pulls/65/reviews', 'out' => '[]'],
];
$g = ct_fake_gh($fetchRules);
$r = ct_php($tool, ['--repo=o/r', '--no-sync-lag', '--no-release', '--no-ci', '--now=2026-10-02T21:00:00Z', '--json'], $g['env']);
$j = json_decode($r['out'], true);
ct('gathering works end to end (exit 0)', $r['code'] === 0 && is_array($j), 'exit ' . $r['code'] . ' ' . trim($r['err']));
$keys = $j['keys'] ?? [];
ct('PAGINATED output (two JSON arrays back to back) is read as one list: items from BOTH pages are classified',
    in_array('unanswered:o/r#147', $keys, true) && in_array('unanswered:o/r#65', $keys, true), json_encode($keys));
ct('a CLOSED issue\'s comments come from the repository-wide feed: #139 is found', in_array('waiting:o/r#139', $keys, true), json_encode($keys));
ct('...and the feed\'s row for an OPEN item is ignored (its comments come from the per-item call)', count(array_filter(ct_calls($g['log']), static fn($c) => strpos(implode(' ', $c['args']), 'issues/60/comments') !== false)) === 1);
ct('an open item with comments gets a per-item comments call; one with none does not',
    ct_calls_matching($g['log'], 'issues/147/comments') === [] && count(ct_calls_matching($g['log'], 'issues/60/comments')) === 1);
ct('an open PULL REQUEST also gets its reviews read (a review is a reply)', count(ct_calls_matching($g['log'], 'pulls/65/reviews')) === 1);
ct('gathering made ONLY read calls (no write of any kind)', ct_calls_matching($g['log'], 'issue edit') === [] && ct_calls_matching($g['log'], 'issue comment') === [] && ct_calls_matching($g['log'], 'issue create') === []);
$saved = ct_tmp() . '/saved.json';
$g = ct_fake_gh($fetchRules);
ct_php($tool, ['--repo=o/r', '--no-sync-lag', '--no-release', '--no-ci', '--now=2026-10-02T21:00:00Z', '--save-input=' . $saved], $g['env']);
$replay = ct_php($tool, ['--input=' . $saved, '--now=2026-10-02T21:00:00Z', '--json'], ct_fake_gh([])['env']);
ct('--save-input captures the dataset so a report can be reproduced OFFLINE, with the same answer', json_decode($replay['out'], true)['keys'] === $keys);
$failRules = $fetchRules;
$failRules[0] = ['match' => 'issues?state=open', 'out' => '', 'err' => 'HTTP 502', 'code' => 1];
$r = ct_php($tool, ['--repo=o/r', '--no-sync-lag', '--no-release', '--no-ci', '--now=2026-10-02T21:00:00Z'], ct_fake_gh($failRules)['env']);
ct('if a repository cannot be read, the inbox SAYS SO instead of reporting it clean', strpos($r['out'], 'could not list open items') !== false, $r['out']);

// --post: create, edit, comment — and the quiet path.
$postData = ['now' => $now, 'maintainers' => ['ejosterberg'], 'repos' => ['o/r' => ['items' => [$item('o/r', $row(147, 'Never answered', 'rep', '2026-09-15T08:00:00Z'))]]]];
$postFile = ct_json_file('post-data.json', $postData);
$postRun = static function (array $rules, array $extra = [], array $env = []) use ($tool, $postFile): array {
    $g = ct_fake_gh($rules, $env);
    $r = ct_php($tool, array_merge(['--input=' . $postFile, '--now=2026-10-02T21:00:00Z', '--post', '--inbox-repo=o/inbox'], $extra), $g['env']);
    return $r + ['log' => $g['log']];
};
$list = static fn(array $issues): array => ['match' => 'repos/o/inbox/issues?state=open', 'out' => json_encode($issues)];

$r = $postRun([$list([]), ['match' => 'issue create', 'out' => "https://github.com/o/inbox/issues/12\n"], ['match' => 'issue comment 12', 'out' => '']]);
$create = ct_calls_matching($r['log'], 'issue create');
ct('no inbox yet: it CREATES one, assigned, body on stdin', $r['code'] === 0 && count($create) === 1 && in_array('--assignee', $create[0]['args'], true) && in_array('ejosterberg', $create[0]['args'], true)
    && strpos($create[0]['stdin'], '# Community inbox') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
ct('...the body carries the state marker (so the NEXT run can stay quiet)', strpos($create[0]['stdin'] ?? '', 'community-watch:state:') !== false);
ct('...and, since the first run has something to say, ONE notification comment on the new issue', count(ct_calls_matching($r['log'], 'issue comment 12')) === 1);

$stored = cw_parse_state((string) ($create[0]['stdin'] ?? ''));
ct('the stored state is the current set', $stored !== null && $stored['keys'] === ['unanswered:o/r#147']);
$existing = ['number' => 12, 'title' => 'Community inbox', 'body' => 'old body ' . cw_state_marker($stored)];
$r = $postRun([$list([$existing]), ['match' => 'issue edit 12', 'out' => ''], ['match' => 'issue comment 12', 'out' => '']]);
ct('the SAME set as last time: the body is replaced and NO comment is made (a quiet run sends nothing)',
    $r['code'] === 0 && count(ct_calls_matching($r['log'], 'issue edit 12')) === 1 && ct_calls_matching($r['log'], 'issue comment') === [], 'out ' . trim($r['out']));
ct('...and says why it was quiet', strpos($r['out'], 'no change') !== false, $r['out']);

$stale = ['hash' => cw_set_hash(['waiting:o/r#1']), 'keys' => ['waiting:o/r#1'], 'at' => gmdate('c', $now - 3600)];
$existing = ['number' => 12, 'title' => 'Community inbox', 'body' => cw_state_marker($stale)];
$r = $postRun([$list([$existing]), ['match' => 'issue edit 12', 'out' => ''], ['match' => 'issue comment 12', 'out' => '']]);
$edit = ct_calls_matching($r['log'], 'issue edit 12');
ct('a CHANGED set 1h after the last notice: body updated, comment DEFERRED', count($edit) === 1 && ct_calls_matching($r['log'], 'issue comment') === [] && strpos($r['out'], 'deferred') !== false, $r['out']);
ct('...and the marker still holds the OLD notified set (the change is not lost)', ($sp = cw_parse_state($edit[0]['stdin'])) !== null && $sp['keys'] === ['waiting:o/r#1']);

$old = ['hash' => cw_set_hash(['waiting:o/r#1']), 'keys' => ['waiting:o/r#1'], 'at' => gmdate('c', $now - 3600 * 30)];
$existing = ['number' => 12, 'title' => 'Community inbox', 'body' => cw_state_marker($old)];
$r = $postRun([$list([$existing]), ['match' => 'issue edit 12', 'out' => ''], ['match' => 'issue comment 12', 'out' => '']]);
$cmt = ct_calls_matching($r['log'], 'issue comment 12');
ct('a changed set 30h after the last notice: ONE comment', count($cmt) === 1 && strpos($cmt[0]['stdin'], '`unanswered:o/r#147`') !== false && strpos($cmt[0]['stdin'], 'Cleared') !== false, $r['out']);
$edit = ct_calls_matching($r['log'], 'issue edit 12');
ct('...and the stored state moves to the new set', ($sp = cw_parse_state($edit[0]['stdin'])) !== null && $sp['keys'] === ['unanswered:o/r#147']);
$r = $postRun([$list([$existing]), ['match' => 'issue edit 12', 'out' => ''], ['match' => 'issue comment 12', 'out' => '']], [], ['COMMUNITY_NOTIFY_MIN_HOURS' => '48']);
ct('COMMUNITY_NOTIFY_MIN_HOURS=48 defers that same 30h-old change (the setting reaches the real tool)', ct_calls_matching($r['log'], 'issue comment') === []);

$other = [['number' => 3, 'title' => 'Community inbox', 'body' => 'a PULL REQUEST with the same title', 'pull_request' => ['url' => 'x']],
          ['number' => 4, 'title' => 'Something else', 'body' => '']];
$r = $postRun(array_merge([$list($other)], [['match' => 'issue create', 'out' => "https://github.com/o/inbox/issues/20\n"], ['match' => 'issue comment 20', 'out' => '']]));
ct('a pull request with the same title, or an issue with another title, is NOT the inbox (a new one is created)', count(ct_calls_matching($r['log'], 'issue create')) === 1 && ct_calls_matching($r['log'], 'issue edit') === []);

$r = $postRun([$list([]), ['match' => 'assignee', 'out' => '', 'err' => 'could not assign', 'code' => 1], ['match' => 'issue create', 'out' => "https://github.com/o/inbox/issues/21\n"], ['match' => 'issue comment 21', 'out' => '']]);
ct('if the assignee cannot be set, the inbox is created WITHOUT it (the inbox existing matters more)', $r['code'] === 0 && count(ct_calls_matching($r['log'], 'issue create')) === 2 && strpos(implode(' ', ct_calls_matching($r['log'], 'issue create')[1]['args']), 'assignee') === false);
$r = $postRun([['match' => 'repos/o/inbox/issues?state=open', 'out' => '', 'err' => 'HTTP 500', 'code' => 1]]);
ct('if the inbox cannot even be looked up: exit 1 and NOTHING is written', $r['code'] === 1 && ct_calls_matching($r['log'], 'issue create') === [] && ct_calls_matching($r['log'], 'issue edit') === []);
$g = ct_fake_gh([]);
ct('--post without an inbox repository is exit 2', ct_php($tool, ['--input=' . $postFile, '--post'], $g['env'])['code'] === 2);
$r = $postRun([$list([]), ['match' => 'issue create', 'out' => "https://github.com/o/inbox/issues/22\n"], ['match' => 'issue comment 22', 'out' => '']], [], ['COMMUNITY_INBOX_TITLE' => 'Maintainer inbox', 'COMMUNITY_INBOX_ASSIGNEE' => 'someone']);
$cr = ct_calls_matching($r['log'], 'issue create');
ct('COMMUNITY_INBOX_TITLE and COMMUNITY_INBOX_ASSIGNEE reach the real tool', $cr && in_array('Maintainer inbox', $cr[0]['args'], true) && in_array('someone', $cr[0]['args'], true));

// Nothing is ever sent to a PUBLIC repository: every write targets the inbox repo.
$writes = [];
foreach (ct_calls($r['log']) as $c) {
    $j = implode(' ', $c['args']);
    if (preg_match('/^issue (create|edit|comment)/', $j)) $writes[] = $j;
}
ct('every write targets the inbox repository and nothing else', $writes !== [] && count(array_filter($writes, static fn(string $w): bool => strpos($w, '--repo o/inbox') === false)) === 0, json_encode($writes));

ct_finish();
