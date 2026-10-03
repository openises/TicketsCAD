<?php
/**
 * status-issue.php — render (and optionally publish) the "Project status and
 * known issues" issue body.
 *
 * ── WHY AN ISSUE, AND WHY GENERATED ──────────────────────────────────
 *
 * A project that goes quiet reads as a project that stopped. The cure is one
 * stable URL that always says what is true: the current release, when main last
 * moved, which bug reports are open, what response to expect and, when the
 * maintainer is away, until when. A pinned ISSUE is that URL: it survives the
 * full-tree-replace snapshots that would wipe a STATUS.md file, it notifies
 * nobody when edited, and it is findable from the issue list people already
 * use. It is generated, never hand-maintained, so it cannot go stale in the
 * very way it exists to cure (specs/phase-155-community-backlog/
 * 000-community-responsiveness.md §5.2 G).
 *
 * Everything on it is DECLARATIVE: facts and targets, no offers, no promises,
 * no decisions. The shape lives in .github/status-issue-template.md; the
 * numbers come from the same settings the triage bot reads, so the two cannot
 * disagree.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────
 *
 *   - Print-only by default. Publishing is a public act: the first version is
 *     created with --create after the maintainer has read what it prints.
 *   - --apply=N refuses an issue whose current body lacks the managed marker,
 *     so a mistyped number can never overwrite a human-written issue.
 *   - Issue titles are untrusted text: escaped, capped and de-fanged
 *     (cm_md_inline) before they enter the body.
 *
 * ── SETTINGS (environment; the repository variables the triage bot uses) ─
 *
 *   AWAY_UNTIL             YYYY-MM-DD    shows the away banner while in the future
 *   TRIAGE_TARGET_DAYS     1..30         the stated first-reply target     (3)
 *   TRIAGE_TARGET_STYLE    number|qualitative                         (number)
 *   COMMUNITY_AGING_DAYS   1..365        how often an open report gets a status
 *                                        line                              (14)
 *
 * ── USAGE ────────────────────────────────────────────────────────────
 *
 *   php tools/status-issue.php [--repo=owner/name] [--template=PATH]
 *                              [--input=FILE] [--now=ISO8601] [--limit=30]
 *                              [--apply=N | --create] [--json]
 *
 *   --input    read the gathered data from this JSON instead of GitHub
 *   --apply    edit issue N in --repo (must carry the managed marker)
 *   --create   open a NEW issue titled "Project status and known issues"
 *
 * Exit codes: 0 ok; 1 a GitHub call failed; 2 bad invocation.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/lib/community.php';
// tr_away_state() and tr_target_phrase(): ONE definition of "away" and of the
// stated target, shared with the bot, so the status page and the acknowledgement
// can never disagree. The constant stops triage-issue.php running its own main.
if (!defined('TRIAGE_LIBRARY_ONLY')) define('TRIAGE_LIBRARY_ONLY', true);
require_once __DIR__ . '/triage-issue.php';

const SI_MARKER = '<!-- status-issue:managed -->';
const SI_TITLE  = 'Project status and known issues';

// ─────────────────────────────────────────────────────────────────────
// Pure rendering
// ─────────────────────────────────────────────────────────────────────

/**
 * A status label for an open bug, from the labels a reader can see.
 *
 * @param string[] $labels
 */
function si_status_of(array $labels): string
{
    $have = array_map('strtolower', $labels);
    foreach (['live operations' => 'live operations', 'on hold' => 'on hold', 'planned' => 'planned',
              'confirmed' => 'confirmed', 'needs reporter' => 'waiting for the reporter',
              'needs triage' => 'new'] as $label => $text) {
        if (in_array($label, $have, true)) return $text;
    }
    return 'open';
}

/**
 * @param array<string,mixed> $data   release, main_date, open_bugs (see si_gather)
 * @param array<string,mixed> $cfg    away_until, target_days, target_style, aging_days
 */
function si_render(string $tpl, array $data, array $cfg, int $now, int $limit = 30): string
{
    $away = tr_away_state($cfg['away_until'], cm_utc_date($now));
    $banner = '';
    if ($away['away']) {
        $banner = "\n> **The maintainer is away until {$away['date']}.** A personal reply may take longer than usual. "
            . "Security reports have a separate private route (SECURITY.md) and are handled first.\n";
    }

    $rel = $data['release'] ?? null;
    if (is_array($rel) && !empty($rel['tag'])) {
        $when = cm_ts((string) ($rel['published_at'] ?? ''));
        $releaseLine = cm_md_inline((string) $rel['tag'], 40)
            . ($when !== null ? ' (' . cm_utc_date($when) . ', ' . cm_age_label(cm_hours_between($when, $now)) . ' ago)' : '');
    } else {
        $releaseLine = 'none published yet';
    }
    $mainTs = cm_ts((string) ($data['main_date'] ?? ''));
    $mainDate = $mainTs !== null ? cm_utc_date($mainTs) . ' (' . cm_age_label(cm_hours_between($mainTs, $now)) . ' ago)' : 'unknown';

    $bugs = is_array($data['open_bugs'] ?? null) ? $data['open_bugs'] : [];
    $rows = [];
    foreach (array_slice($bugs, 0, $limit) as $b) {
        $rows[] = '| #' . (int) $b['number'] . ' | ' . cm_md_inline((string) $b['title'], 90) . ' | '
            . si_status_of((array) ($b['labels'] ?? [])) . ' |';
    }
    if ($bugs === []) {
        $known = 'No open bug reports.';
    } else {
        $known = "| Report | Summary | Status |\n|---|---|---|\n" . implode("\n", $rows);
        if (count($bugs) > $limit) $known .= "\n\n…and " . (count($bugs) - $limit) . ' more in the issue list.';
    }

    $target = tr_target_phrase((int) $cfg['target_days'], (string) $cfg['target_style']);
    $expect = "The maintainer aims to reply to a new report {$target}. That is a target, not a guarantee, "
        . "and a reply is not a promise of a fix date. A report that has been acknowledged and is still open "
        . "gets a short status line at least every {$cfg['aging_days']} days. Fixes are announced on the "
        . "issue once they are available in main, and again when a release includes them.";

    $out = strtr($tpl, [
        '{{away_banner}}'    => $banner,
        '{{release_line}}'   => $releaseLine,
        '{{main_date}}'      => $mainDate,
        '{{open_bug_count}}' => (string) count($bugs),
        '{{refreshed}}'      => cm_utc_minute($now) . ' UTC',
        '{{expectations}}'   => $expect,
        '{{known_issues}}'   => $known,
    ]);
    return rtrim($out) . "\n";
}

/** @return array<string,mixed> */
function si_config(): array
{
    $w = null;
    $days = cm_env_int('TRIAGE_TARGET_DAYS', 3, 1, 30, $w);
    $aging = cm_env_int('COMMUNITY_AGING_DAYS', 14, 1, 365, $w);
    $style = strtolower((string) (cm_env('TRIAGE_TARGET_STYLE') ?? 'number'));
    if (!in_array($style, ['number', 'qualitative'], true)) $style = 'number';
    return ['away_until' => cm_env('AWAY_UNTIL'), 'target_days' => $days, 'target_style' => $style, 'aging_days' => $aging];
}

// ─────────────────────────────────────────────────────────────────────
// Gathering
// ─────────────────────────────────────────────────────────────────────

/**
 * Read the facts the page needs from the repository. A fact that cannot be
 * read is left null and the page says "unknown": a status page that fails to
 * render because one call failed would be a status page that goes missing
 * exactly when something is wrong.
 *
 * @return array{release:?array<string,string>,main_date:?string,open_bugs:array<int,array<string,mixed>>,errors:string[]}
 */
function si_gather(string $repo): array
{
    $out = ['release' => null, 'main_date' => null, 'open_bugs' => [], 'errors' => []];

    $r = cm_gh(['api', "repos/{$repo}/releases/latest"]);
    if ($r['code'] === 0) {
        $j = json_decode($r['out'], true);
        if (is_array($j) && !empty($j['tag_name'])) {
            $out['release'] = ['tag' => (string) $j['tag_name'], 'published_at' => (string) ($j['published_at'] ?? '')];
        }
    }

    $why = null;
    $c = cm_gh_api_list("repos/{$repo}/commits?per_page=1", [], $why);
    if ($c === null) $out['errors'][] = "latest commit: {$why}";
    elseif (isset($c[0]['commit']['committer']['date'])) $out['main_date'] = (string) $c[0]['commit']['committer']['date'];

    $why = null;
    $issues = cm_gh_api_list("repos/{$repo}/issues?state=open&labels=bug&per_page=100", ['--paginate'], $why);
    if ($issues === null) {
        $out['errors'][] = "open bugs: {$why}";
    } else {
        foreach ($issues as $i) {
            if (!is_array($i) || isset($i['pull_request'])) continue;
            $labels = [];
            foreach ((array) ($i['labels'] ?? []) as $l) $labels[] = is_array($l) ? (string) ($l['name'] ?? '') : (string) $l;
            $out['open_bugs'][] = ['number' => (int) $i['number'], 'title' => (string) ($i['title'] ?? ''), 'labels' => $labels];
        }
        usort($out['open_bugs'], static fn(array $a, array $b): int => $a['number'] <=> $b['number']);
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────

if (!defined('STATUS_ISSUE_LIBRARY_ONLY')) {
    $opt = cm_parse_args($argv, ['repo' => null, 'template' => null, 'input' => null, 'now' => null,
                                 'limit' => null, 'apply' => null, 'create' => false, 'json' => false], 'status-issue');

    $repo = cm_valid_slug(is_string($opt['repo']) ? $opt['repo'] : (string) getenv('GITHUB_REPOSITORY'));
    if ($repo === null) { fwrite(STDERR, "status-issue: no valid owner/name (pass --repo=)\n"); exit(2); }

    $tplPath = is_string($opt['template']) ? $opt['template'] : dirname(__DIR__) . '/.github/status-issue-template.md';
    $tpl = is_file($tplPath) ? (string) file_get_contents($tplPath) : '';
    if (trim($tpl) === '' || strpos($tpl, SI_MARKER) === false) {
        fwrite(STDERR, "status-issue: template missing, empty, or lacking the managed marker: {$tplPath}\n");
        exit(2);
    }
    $now = is_string($opt['now']) ? cm_ts($opt['now']) : time();
    if ($now === null) { fwrite(STDERR, "status-issue: --now is not a date\n"); exit(2); }
    $limit = is_string($opt['limit']) && preg_match('/^\d{1,3}$/', $opt['limit']) && (int) $opt['limit'] > 0 ? (int) $opt['limit'] : 30;

    if (is_string($opt['input'])) {
        $data = is_file($opt['input']) ? json_decode((string) file_get_contents($opt['input']), true) : null;
        if (!is_array($data)) { fwrite(STDERR, "status-issue: cannot read JSON from {$opt['input']}\n"); exit(2); }
    } else {
        $data = si_gather($repo);
        foreach ($data['errors'] as $e) cm_note('warning', $e);
    }
    $body = si_render($tpl, $data, si_config(), $now, $limit);

    $apply = is_string($opt['apply']) && preg_match('/^\d+$/', $opt['apply']) ? (int) $opt['apply'] : null;
    if (is_string($opt['apply']) && $apply === null) { fwrite(STDERR, "status-issue: --apply needs an issue number\n"); exit(2); }
    if ($apply !== null && cm_flag($opt['create'])) { fwrite(STDERR, "status-issue: --apply and --create are exclusive\n"); exit(2); }

    $result = ['repo' => $repo, 'action' => 'print'];
    $code = 0;
    if ($apply !== null) {
        $cur = cm_gh(['api', "repos/{$repo}/issues/{$apply}"]);
        $row = $cur['code'] === 0 ? json_decode($cur['out'], true) : null;
        if (!is_array($row) || isset($row['pull_request'])) {
            fwrite(STDERR, "status-issue: issue #{$apply} could not be read in {$repo}\n");
            exit(1);
        }
        if (strpos((string) ($row['body'] ?? ''), SI_MARKER) === false) {
            fwrite(STDERR, "status-issue: issue #{$apply} does not carry the managed marker; refusing to overwrite a "
                . "human-written issue. Create the status issue with --create, or add the marker line by hand.\n");
            exit(1);
        }
        $r = cm_gh(['issue', 'edit', (string) $apply, '--repo', $repo, '--body-file', '-'], $body);
        $result = ['repo' => $repo, 'action' => 'edited', 'issue' => $apply, 'ok' => $r['code'] === 0];
        if ($r['code'] !== 0) { fwrite(STDERR, 'status-issue: ' . trim($r['err']) . "\n"); $code = 1; }
    } elseif (cm_flag($opt['create'])) {
        $r = cm_gh(['issue', 'create', '--repo', $repo, '--title', SI_TITLE, '--body-file', '-'], $body);
        $result = ['repo' => $repo, 'action' => 'created', 'ok' => $r['code'] === 0, 'url' => trim($r['out'])];
        if ($r['code'] !== 0) { fwrite(STDERR, 'status-issue: ' . trim($r['err']) . "\n"); $code = 1; }
    }

    if (cm_flag($opt['json'])) {
        echo json_encode($result + ['body' => $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } elseif ($result['action'] === 'print') {
        echo $body;
    } else {
        echo 'status-issue: ', $result['action'], ($result['ok'] ?? false) ? ' ok' : ' FAILED', "\n";
    }
    exit($code);
}
