<?php
/**
 * release-notify.php — tell the reporters when their fix is in a release.
 *
 * Run by .github/workflows/release-notify.yml when a release is PUBLISHED. It
 * reads the issue numbers the release notes and the changelog section name
 * (`GH#146`), and posts ONE short comment on each, from the Actions bot:
 *
 *     Included in release v4.2.28 (https://github.com/<repo>/releases/tag/v4.2.28).
 *
 * ── WHY ──────────────────────────────────────────────────────────────
 *
 * A fix can be published and the person who reported it never hear: nothing
 * connects "release published" to "the issue that asked for this". Reporters
 * who file occasionally (they run no confirm-and-close loop of their own) find
 * out weeks later, or never; and everyone who moves only on tagged releases
 * (Docker and ZIP installs) learns nothing from a silent `main`. This closes
 * that gap for tagged releases (specs/phase-155-community-backlog/
 * 000-community-responsiveness.md §3(d), §5.2 H).
 *
 * ── WHAT IT WILL NEVER DO ────────────────────────────────────────────
 *
 *   - Reopen, close, label or assign anything. It writes one comment.
 *   - Comment on a pull request, a draft or (by default) a pre-release.
 *   - Post twice for the same release (a hidden marker per tag).
 *   - Flood: at most RELEASE_NOTIFY_MAX comments per release (default 25).
 *   - Put any text of the release notes into the comment. The only inputs that
 *     reach it are issue NUMBERS (digits) and a tag name that has passed a
 *     strict pattern; the link is built from the repository name and that tag.
 *
 * ── SETTINGS ─────────────────────────────────────────────────────────
 *
 *   RELEASE_NOTIFY_ENABLED      true | false   kill switch                 (true)
 *   RELEASE_NOTIFY_MAX          1..200         comments per release          (25)
 *   RELEASE_NOTIFY_PRERELEASES  true | false   also notify for pre-releases (false)
 *
 * ── USAGE ────────────────────────────────────────────────────────────
 *
 *   php tools/release-notify.php [--event=PATH] [--repo=owner/name]
 *                                [--changelog=FILE] [--dry-run] [--json]
 *
 * --event defaults to $GITHUB_EVENT_PATH; --repo to $GITHUB_REPOSITORY.
 * Without --changelog, the changelog at the tag is read from the repository
 * through the GitHub API; if that fails, the release notes alone are used.
 *
 * Exit codes: 0 done / skipped; 1 a comment failed; 2 bad invocation.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/lib/community.php';

const RN_TAG_RE = '/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]{1,40})?$/';

// ─────────────────────────────────────────────────────────────────────
// Pure logic
// ─────────────────────────────────────────────────────────────────────

/** Distinct issue numbers named as GH#N (or "GH #N"), ascending. */
function rn_issue_numbers(string $text): array
{
    if (!preg_match_all('/\bGH\s?#(\d+)(?!\d)/i', $text, $m)) return [];
    $n = array_values(array_unique(array_map('intval', $m[1])));
    $n = array_values(array_filter($n, static fn(int $i): bool => $i >= 1));
    sort($n);
    return $n;
}

/**
 * The changelog section for one version: from its `## [X.Y.Z]` heading to the
 * next `## [` heading. Empty string when there is none.
 */
function rn_changelog_section(string $changelog, string $tag): string
{
    $ver = preg_quote(ltrim($tag, 'v'), '/');
    $lines = preg_split('/\r\n|\n|\r/', $changelog) ?: [];
    $out = [];
    $in = false;
    foreach ($lines as $line) {
        if (preg_match('/^##\s*\[/', $line)) {
            if ($in) break;
            if (preg_match('/^##\s*\[v?' . $ver . '\]/i', $line)) { $in = true; continue; }
        }
        if ($in) $out[] = $line;
    }
    return implode("\n", $out);
}

/**
 * Decide what to do for a `release` event.
 *
 * @param array<string,mixed> $event
 * @return array{skip:?string,tag:string,numbers:string[]|int[],truncated:int}
 */
function rn_plan(array $event, ?string $changelog, bool $enabled, int $max, bool $prereleases): array
{
    $plan = ['skip' => null, 'tag' => '', 'numbers' => [], 'truncated' => 0];
    if (!$enabled) { $plan['skip'] = 'disabled (RELEASE_NOTIFY_ENABLED is off)'; return $plan; }
    $rel = is_array($event['release'] ?? null) ? $event['release'] : [];
    $tag = (string) ($rel['tag_name'] ?? '');
    if (!preg_match(RN_TAG_RE, $tag)) { $plan['skip'] = 'the release tag is not a version number'; return $plan; }
    $plan['tag'] = $tag;
    if (($event['action'] ?? 'published') !== 'published') { $plan['skip'] = 'not a published event'; return $plan; }
    if (!empty($rel['draft'])) { $plan['skip'] = 'a draft release'; return $plan; }
    if (!empty($rel['prerelease']) && !$prereleases) { $plan['skip'] = 'a pre-release (RELEASE_NOTIFY_PRERELEASES is off)'; return $plan; }

    $text = (string) ($rel['body'] ?? '');
    if ($changelog !== null) $text .= "\n" . rn_changelog_section($changelog, $tag);
    $numbers = rn_issue_numbers($text);
    if (count($numbers) > $max) {
        $plan['truncated'] = count($numbers) - $max;
        $numbers = array_slice($numbers, 0, $max);
    }
    $plan['numbers'] = $numbers;
    if ($numbers === []) $plan['skip'] = 'the release names no issue (nothing to tell anyone)';
    return $plan;
}

/** The comment text. Inputs are a validated tag and a validated repo slug only. */
function rn_comment(string $repo, string $tag): string
{
    $url = "https://github.com/{$repo}/releases/tag/{$tag}";
    return "Included in release {$tag} ({$url}).\n\n" . rn_marker($tag) . "\n";
}

function rn_marker(string $tag): string
{
    return cm_marker('release-notify:' . $tag);
}

// ─────────────────────────────────────────────────────────────────────
// Side effects
// ─────────────────────────────────────────────────────────────────────

/**
 * @param int[] $numbers
 * @return array{posted:int[],already:int[],skipped:array<int,string>,failed:array<int,string>}
 */
function rn_execute(string $repo, string $tag, array $numbers): array
{
    $res = ['posted' => [], 'already' => [], 'skipped' => [], 'failed' => []];
    foreach ($numbers as $n) {
        $n = (int) $n;
        // Only a real ISSUE: a number in the notes might be a pull request or
        // not exist at all (a typo).
        $meta = cm_gh(['api', "repos/{$repo}/issues/{$n}"]);
        if ($meta['code'] !== 0) { $res['skipped'][$n] = 'not found'; continue; }
        $row = json_decode($meta['out'], true);
        if (!is_array($row)) { $res['skipped'][$n] = 'unreadable'; continue; }
        if (isset($row['pull_request'])) { $res['skipped'][$n] = 'a pull request'; continue; }

        $why = null;
        $comments = cm_gh_api_list("repos/{$repo}/issues/{$n}/comments", ['--paginate'], $why);
        if ($comments === null) { $res['failed'][$n] = "could not list comments: {$why}"; continue; }
        $dup = false;
        foreach ($comments as $c) {
            if (is_array($c) && cm_comment_is_ours_with($c, rn_marker($tag))) { $dup = true; break; }
        }
        if ($dup) { $res['already'][] = $n; continue; }

        $r = cm_gh(['issue', 'comment', (string) $n, '--repo', $repo, '--body-file', '-'], rn_comment($repo, $tag));
        if ($r['code'] === 0) $res['posted'][] = $n;
        else $res['failed'][$n] = trim($r['err']);
    }
    return $res;
}

// ─────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────

if (!defined('RELEASE_NOTIFY_LIBRARY_ONLY')) {
    $opt = cm_parse_args($argv, ['event' => null, 'repo' => null, 'changelog' => null,
                                 'dry-run' => false, 'json' => false], 'release-notify');

    $eventPath = is_string($opt['event']) ? $opt['event'] : (string) getenv('GITHUB_EVENT_PATH');
    if ($eventPath === '' || !is_file($eventPath)) {
        fwrite(STDERR, "release-notify: no event payload (pass --event=PATH or run under GitHub Actions)\n");
        exit(2);
    }
    $event = json_decode((string) file_get_contents($eventPath), true);
    if (!is_array($event)) { fwrite(STDERR, "release-notify: {$eventPath} is not valid JSON\n"); exit(2); }

    $repo = cm_valid_slug(is_string($opt['repo']) ? $opt['repo'] : (string) getenv('GITHUB_REPOSITORY'));
    if ($repo === null) { fwrite(STDERR, "release-notify: no valid owner/name (pass --repo=)\n"); exit(2); }

    $w = null;
    $max = cm_env_int('RELEASE_NOTIFY_MAX', 25, 1, 200, $w);
    if ($w !== null) cm_note('warning', $w);
    $enabled = cm_env_bool('RELEASE_NOTIFY_ENABLED', true);
    $pre = cm_env_bool('RELEASE_NOTIFY_PRERELEASES', false);

    // Decide from the event alone FIRST. A draft, a pre-release, a disabled bot or
    // an invalid tag must cost no API call at all; only a release that could
    // notify anyone goes on to read the changelog at its tag.
    $plan = rn_plan($event, null, $enabled, $max, $pre);
    $couldNotify = $plan['skip'] === null || strpos((string) $plan['skip'], 'names no issue') === 0;
    if ($couldNotify) {
        $changelog = null;
        if (is_string($opt['changelog'])) {
            $changelog = is_file($opt['changelog']) ? (string) file_get_contents($opt['changelog']) : null;
        } else {
            // The changelog at the tag, through the repository API. The tag has
            // already passed RN_TAG_RE inside rn_plan (it is part of a URL here).
            $r = cm_gh(['api', '-H', 'Accept: application/vnd.github.raw+json',
                        "repos/{$repo}/contents/CHANGELOG.md?ref={$plan['tag']}"]);
            if ($r['code'] === 0 && $r['out'] !== '') $changelog = $r['out'];
            else cm_note('warning', 'could not read CHANGELOG.md at the tag; using the release notes alone');
        }
        if ($changelog !== null) $plan = rn_plan($event, $changelog, $enabled, $max, $pre);
    }
    if ($plan['truncated'] > 0) {
        cm_note('warning', "the release names {$plan['truncated']} more issue(s) than RELEASE_NOTIFY_MAX={$max}; the rest were not notified");
    }

    $out = ['repo' => $repo, 'tag' => $plan['tag'], 'skip' => $plan['skip'], 'issues' => $plan['numbers'],
            'posted' => [], 'already' => [], 'skipped' => [], 'failed' => []];
    $failed = false;
    if ($plan['skip'] === null) {
        if (cm_flag($opt['dry-run'])) {
            $out['dry_run'] = true;
            $out['comment'] = rn_comment($repo, $plan['tag']);
        } else {
            $res = rn_execute($repo, $plan['tag'], $plan['numbers']);
            $out = array_merge($out, $res);
            foreach ($res['failed'] as $n => $why) cm_note('warning', "GH#{$n}: {$why}");
            $failed = $res['failed'] !== [];
        }
    }

    if (cm_flag($opt['json'])) {
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    } elseif ($plan['skip'] !== null) {
        echo "release-notify: skipped — {$plan['skip']}\n";
    } else {
        echo 'release-notify: ', $plan['tag'], ' — ', count($out['posted']), ' posted, ',
             count($out['already']), ' already told, ', count($out['skipped']), ' skipped, ',
             count($out['failed']), ' failed', cm_flag($opt['dry-run']) ? ' (dry run: ' . count($plan['numbers']) . ' would be told)' : '', "\n";
    }
    exit($failed ? 1 : 0);
}
