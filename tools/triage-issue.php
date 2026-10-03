<?php
/**
 * triage-issue.php — acknowledge and label a newly opened issue.
 *
 * Run by .github/workflows/triage.yml on `issues: opened`. It does two
 * small things, both of which a bot can do honestly:
 *
 *   1. Post ONE comment, from the Actions bot, saying the report arrived and
 *      what happens next. The wording lives in
 *      .github/triage/acknowledgement.md (not in this file) so the maintainer
 *      edits prose, not code.
 *   2. Add labels the issue forms cannot: `needs triage` always; a type label
 *      inferred from the title prefix when an API-created issue has none;
 *      the Windows label when the install-method answer says Windows; the
 *      live-operations label when the new form question is answered "Yes".
 *
 * ── WHY IT EXISTS ────────────────────────────────────────────────────
 *
 * The response record (specs/phase-155-community-backlog/
 * 000-community-responsiveness.md, section 2) is two regimes: a median first
 * reply of 8.9 hours while somebody was at the keyboard, and 9.2 DAYS while
 * nobody was. Acknowledgement is the one part of responsiveness that must not
 * depend on a person being present, so it is the one part handed to a bot —
 * and the bot says it is a bot, says when the maintainer is away, and promises
 * nothing the maintainer has not already promised in public.
 *
 * ── WHAT IT WILL NEVER DO ────────────────────────────────────────────
 *
 *   - Speak as the maintainer, promise a fix, or promise a date.
 *   - Close, lock, assign, or re-label anything beyond the four labels above.
 *   - Echo the issue's own text into the comment or into any command. The
 *     title and body are UNTRUSTED data: they are read from the event file the
 *     runner wrote, matched against fixed strings, and never interpolated.
 *   - Fail a run because a label is missing or the API hiccupped. The only
 *     failure that matters is "the acknowledgement could not be posted", and
 *     even that is backstopped by the state-based detector.
 *
 * ── SETTINGS (repository variables; each has a default and a reader here) ─
 *
 *   TRIAGE_ENABLED            true | false     kill switch              (true)
 *   AWAY_UNTIL                YYYY-MM-DD       maintainer away, inclusive (unset)
 *   TRIAGE_TARGET_DAYS        1..30            days named in the comment  (3)
 *   TRIAGE_TARGET_STYLE       number|qualitative  "within N days" or
 *                             "usually within a few days"               (number)
 *   TRIAGE_SKIP_ASSOCIATIONS  comma list of author_association values that are
 *                             NOT acknowledged (the maintainers' own issues)
 *                                                                (OWNER,MEMBER)
 *   TRIAGE_WINDOWS_LABEL      label for Windows installs         (windows/iis)
 *
 * ── USAGE ────────────────────────────────────────────────────────────
 *
 *   php tools/triage-issue.php [--event=PATH] [--repo=owner/name]
 *                              [--template=PATH] [--today=YYYY-MM-DD]
 *                              [--dry-run] [--json]
 *
 *   --event     the Actions event payload (default: $GITHUB_EVENT_PATH)
 *   --repo      default: $GITHUB_REPOSITORY
 *   --dry-run   print the plan and do nothing
 *
 * Exit codes: 0 done / skipped / disabled; 1 the acknowledgement could not be
 * posted; 2 bad invocation.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/lib/community.php';

const TRIAGE_MARKER = 'triage-bot:ack';
/** Type labels the issue forms already apply; inference only runs when none is present. */
const TRIAGE_TYPE_LABELS = ['bug', 'enhancement', 'question'];

// ─────────────────────────────────────────────────────────────────────
// Pure logic (tests drive these directly)
// ─────────────────────────────────────────────────────────────────────

/**
 * Parse the markdown an issue FORM produces:
 *
 *     ### Which version are you running?
 *
 *     4.2.27
 *
 * into [normalised-question => answer]. `_No response_` (GitHub's placeholder
 * for an empty optional field) becomes ''. The FIRST occurrence of a question
 * wins, so a later heading typed into a free-text answer cannot overwrite the
 * real answer above it.
 *
 * @return array<string,string>
 */
function tr_parse_form(string $body): array
{
    $out = [];
    $current = null;
    $buf = [];
    $flush = static function () use (&$out, &$current, &$buf): void {
        if ($current === null) return;
        $value = trim(implode("\n", $buf));
        if ($value === '_No response_') $value = '';
        if (!array_key_exists($current, $out)) $out[$current] = $value;
    };
    foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
        if (preg_match('/^###\s+(.+?)\s*$/u', $line, $m)) {
            $flush();
            $current = tr_norm($m[1]);
            $buf = [];
        } elseif ($current !== null) {
            $buf[] = $line;
        }
    }
    $flush();
    return $out;
}

function tr_norm(string $s): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
}

/**
 * The type label a title prefix implies ("[Bug]: …", "Bug: …", "Feature
 * request - …"), or null. Only the PREFIX counts; a title that merely contains
 * the word "bug" says nothing about what kind of report it is.
 */
function tr_infer_type(string $title): ?string
{
    $re = '/^\s*(?:\[\s*(bug|feature(?:\s+request)?|enhancement|question|help)\s*\]|(bug|feature(?:\s+request)?|enhancement|question|help)\s*[:\-–—])/iu';
    if (!preg_match($re, $title, $m)) return null;
    $word = strtolower($m[1] !== '' ? $m[1] : $m[2]);
    if ($word === 'bug') return 'bug';
    if ($word === 'question' || $word === 'help') return 'question';
    return 'enhancement';
}

/**
 * Today/away decision for AWAY_UNTIL. A malformed value is reported and
 * IGNORED: the comment must never say "away until banana".
 *
 * @return array{away:bool,date:?string,warning:?string}
 */
function tr_away_state(?string $awayUntil, string $today): array
{
    if ($awayUntil === null || trim($awayUntil) === '') {
        return ['away' => false, 'date' => null, 'warning' => null];
    }
    $date = cm_valid_date($awayUntil);
    if ($date === null) {
        return ['away' => false, 'date' => null,
                'warning' => "AWAY_UNTIL={$awayUntil} is not a YYYY-MM-DD date; ignored"];
    }
    return ['away' => $date >= $today, 'date' => $date, 'warning' => null];
}

/** "within 3 days" / "within 1 day" / "usually within a few days". */
function tr_target_phrase(int $days, string $style): string
{
    if ($style === 'qualitative') return 'usually within a few days';
    return 'within ' . $days . ' ' . ($days === 1 ? 'day' : 'days');
}

/**
 * Fill the acknowledgement template.
 *
 *   {{target}}                    → tr_target_phrase()
 *   {{#away}} … {{/away}}         → kept only while the maintainer is away
 *   {{date}} (inside that block)  → the validated AWAY_UNTIL date
 *
 * Nothing from the issue itself is ever substituted: the only inputs are the
 * maintainer's own settings, and the date has already passed cm_valid_date().
 */
function tr_render(string $tpl, bool $away, ?string $date, string $targetPhrase): string
{
    $tpl = preg_replace_callback(
        '/\{\{#away\}\}(.*?)\{\{\/away\}\}[ \t]*\r?\n?/s',
        static function (array $m) use ($away, $date): string {
            return $away ? str_replace('{{date}}', (string) $date, $m[1]) : '';
        },
        $tpl
    ) ?? $tpl;
    $tpl = str_replace('{{target}}', $targetPhrase, $tpl);
    return rtrim($tpl) . "\n\n" . cm_marker(TRIAGE_MARKER) . "\n";
}

/**
 * Decide everything this run will do, from the event and the settings, with no
 * side effects.
 *
 * @param array<string,mixed> $event the Actions `issues` event payload
 * @param array<string,mixed> $cfg   see tr_config()
 * @return array{skip:?string,labels:string[],comment:?string,warnings:string[],issue:int}
 */
function tr_plan(array $event, array $cfg, string $template, string $today): array
{
    $warnings = $cfg['warnings'] ?? [];
    $issue = is_array($event['issue'] ?? null) ? $event['issue'] : [];
    $number = (int) ($issue['number'] ?? 0);
    $plan = ['skip' => null, 'labels' => [], 'comment' => null, 'warnings' => $warnings, 'issue' => $number];

    if (!$cfg['enabled']) { $plan['skip'] = 'disabled (TRIAGE_ENABLED is off)'; return $plan; }
    if (($event['action'] ?? 'opened') !== 'opened') { $plan['skip'] = 'not an opened event'; return $plan; }
    if ($number < 1) { $plan['skip'] = 'event has no issue number'; return $plan; }
    if (isset($issue['pull_request'])) { $plan['skip'] = 'a pull request, not an issue'; return $plan; }

    $user = is_array($issue['user'] ?? null) ? $issue['user'] : [];
    if (strcasecmp((string) ($user['type'] ?? ''), 'Bot') === 0) {
        $plan['skip'] = 'opened by a bot';
        return $plan;
    }
    $assoc = strtoupper((string) ($issue['author_association'] ?? ''));
    if ($assoc !== '' && in_array($assoc, $cfg['skip_associations'], true)) {
        $plan['skip'] = "opened by a maintainer ({$assoc})";
        return $plan;
    }

    // ── labels ────────────────────────────────────────────────────────
    $existing = [];
    foreach ((array) ($issue['labels'] ?? []) as $l) {
        $name = is_array($l) ? (string) ($l['name'] ?? '') : (string) $l;
        if ($name !== '') $existing[] = strtolower($name);
    }
    $labels = ['needs triage'];
    if (count(array_intersect($existing, TRIAGE_TYPE_LABELS)) === 0) {
        $type = tr_infer_type((string) ($issue['title'] ?? ''));
        if ($type !== null) $labels[] = $type;
    }
    $form = tr_parse_form((string) ($issue['body'] ?? ''));
    $install = $form['how is it installed?'] ?? '';
    if ($install !== '' && stripos($install, 'windows') !== false) {
        $labels[] = $cfg['windows_label'];
    }
    $live = $form['is this affecting live operations right now?'] ?? '';
    if ($live !== '' && preg_match('/^yes\b/i', $live)) {
        $labels[] = 'live operations';
    }
    $plan['labels'] = array_values(array_unique($labels));

    // ── comment ───────────────────────────────────────────────────────
    $away = tr_away_state($cfg['away_until'], $today);
    if ($away['warning'] !== null) $plan['warnings'][] = $away['warning'];
    $plan['comment'] = tr_render(
        $template,
        $away['away'],
        $away['date'],
        tr_target_phrase($cfg['target_days'], $cfg['target_style'])
    );
    return $plan;
}

/**
 * Read the settings from the environment (repository variables), applying the
 * documented defaults. A malformed value yields a warning and the default.
 *
 * @return array<string,mixed>
 */
function tr_config(): array
{
    $warnings = [];
    $w = null;
    $days = cm_env_int('TRIAGE_TARGET_DAYS', 3, 1, 30, $w);
    if ($w !== null) $warnings[] = $w;

    $style = strtolower((string) (cm_env('TRIAGE_TARGET_STYLE') ?? 'number'));
    if (!in_array($style, ['number', 'qualitative'], true)) {
        $warnings[] = "TRIAGE_TARGET_STYLE={$style} is not number|qualitative; using number";
        $style = 'number';
    }

    $skip = cm_env('TRIAGE_SKIP_ASSOCIATIONS') ?? 'OWNER,MEMBER';
    $skipList = [];
    foreach (explode(',', $skip) as $s) {
        $s = strtoupper(trim($s));
        if ($s !== '') $skipList[] = $s;
    }

    $win = cm_env('TRIAGE_WINDOWS_LABEL') ?? 'windows/iis';

    return [
        'enabled'           => cm_env_bool('TRIAGE_ENABLED', true),
        'away_until'        => cm_env('AWAY_UNTIL'),
        'target_days'       => $days,
        'target_style'      => $style,
        'skip_associations' => $skipList,
        'windows_label'     => $win,
        'warnings'          => $warnings,
    ];
}

// ─────────────────────────────────────────────────────────────────────
// Side effects
// ─────────────────────────────────────────────────────────────────────

/**
 * Carry out a plan through the GitHub CLI.
 *
 * @param array{skip:?string,labels:string[],comment:?string,warnings:string[],issue:int} $plan
 * @return array{commented:bool,already:bool,labels_added:string[],labels_missing:string[],ok:bool,notes:string[]}
 */
function tr_execute(array $plan, string $repo): array
{
    $res = ['commented' => false, 'already' => false, 'labels_added' => [],
            'labels_missing' => [], 'ok' => true, 'notes' => []];
    $n = (string) $plan['issue'];

    // 1. The acknowledgement. Check for our own marker first so a re-run (a
    //    manual re-run, a redelivered event) never posts it twice. If the check
    //    itself fails we post anyway: a duplicate costs a line of scrolling, a
    //    missing acknowledgement is the failure this tool exists to prevent.
    if ($plan['comment'] !== null) {
        $why = null;
        $comments = cm_gh_api_list("repos/{$repo}/issues/{$n}/comments", ['--paginate'], $why);
        $dup = false;
        if ($comments === null) {
            $res['notes'][] = "could not list existing comments ({$why}); posting anyway";
        } else {
            foreach ($comments as $c) {
                if (is_array($c) && cm_comment_is_ours_with($c, cm_marker(TRIAGE_MARKER))) {
                    $dup = true;
                    break;
                }
            }
        }
        if ($dup) {
            $res['already'] = true;
        } else {
            $r = cm_gh(['issue', 'comment', $n, '--repo', $repo, '--body-file', '-'], $plan['comment']);
            if ($r['code'] === 0) {
                $res['commented'] = true;
            } else {
                $res['ok'] = false;
                $res['notes'][] = 'could not post the acknowledgement: ' . trim($r['err']);
            }
        }
    }

    // 2. Labels. Only labels that exist in the repository are applied: adding
    //    an unknown label would fail the whole call, and creating labels is
    //    the job of tools/sync-labels.php, not of a bot reacting to a stranger.
    if ($plan['labels'] !== []) {
        $why = null;
        $have = cm_gh_api_list("repos/{$repo}/labels?per_page=100", ['--paginate'], $why);
        $known = [];
        if ($have === null) {
            $res['notes'][] = "could not list repository labels ({$why}); labels not applied";
        } else {
            foreach ($have as $l) {
                if (is_array($l) && isset($l['name'])) $known[strtolower((string) $l['name'])] = (string) $l['name'];
            }
            $apply = [];
            foreach ($plan['labels'] as $want) {
                if (isset($known[strtolower($want)])) $apply[] = $known[strtolower($want)];
                else $res['labels_missing'][] = $want;
            }
            if ($apply !== []) {
                $cmd = ['issue', 'edit', $n, '--repo', $repo];
                foreach ($apply as $name) { $cmd[] = '--add-label'; $cmd[] = $name; }
                $r = cm_gh($cmd);
                if ($r['code'] === 0) {
                    $res['labels_added'] = $apply;
                } else {
                    $res['notes'][] = 'could not add labels: ' . trim($r['err']);
                }
            }
            if ($res['labels_missing'] !== []) {
                $res['notes'][] = 'labels not defined in this repository (run tools/sync-labels.php): '
                    . implode(', ', $res['labels_missing']);
            }
        }
    }
    return $res;
}

// ─────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────

if (!defined('TRIAGE_LIBRARY_ONLY')) {
    $opt = cm_parse_args($argv, [
        'event' => null, 'repo' => null, 'template' => null, 'today' => null,
        'dry-run' => false, 'json' => false,
    ], 'triage-issue');

    $eventPath = is_string($opt['event']) ? $opt['event'] : (string) getenv('GITHUB_EVENT_PATH');
    if ($eventPath === '' || !is_file($eventPath)) {
        fwrite(STDERR, "triage-issue: no event payload (pass --event=PATH or run under GitHub Actions)\n");
        exit(2);
    }
    $event = json_decode((string) file_get_contents($eventPath), true);
    if (!is_array($event)) {
        fwrite(STDERR, "triage-issue: {$eventPath} is not valid JSON\n");
        exit(2);
    }

    $repo = cm_valid_slug(is_string($opt['repo']) ? $opt['repo'] : (string) getenv('GITHUB_REPOSITORY'));
    if ($repo === null) {
        fwrite(STDERR, "triage-issue: no valid owner/name (pass --repo= or set GITHUB_REPOSITORY)\n");
        exit(2);
    }

    $tplPath = is_string($opt['template']) ? $opt['template'] : dirname(__DIR__) . '/.github/triage/acknowledgement.md';
    $template = is_file($tplPath) ? (string) file_get_contents($tplPath) : '';
    if (trim($template) === '') {
        fwrite(STDERR, "triage-issue: acknowledgement template missing or empty: {$tplPath}\n");
        exit(2);
    }

    $today = is_string($opt['today']) ? $opt['today'] : cm_utc_date(time());
    if (cm_valid_date($today) === null) {
        fwrite(STDERR, "triage-issue: --today must be YYYY-MM-DD\n");
        exit(2);
    }

    $cfg  = tr_config();
    $plan = tr_plan($event, $cfg, $template, $today);
    foreach ($plan['warnings'] as $w) cm_note('warning', $w);

    $out = ['repo' => $repo, 'issue' => $plan['issue'], 'skip' => $plan['skip'],
            'labels' => $plan['labels'], 'comment_posted' => false, 'labels_added' => [], 'notes' => []];

    if ($plan['skip'] !== null) {
        $out['notes'][] = 'skipped: ' . $plan['skip'];
    } elseif (cm_flag($opt['dry-run'])) {
        $out['notes'][] = 'dry run: nothing was posted';
        $out['comment'] = $plan['comment'];
    } else {
        $res = tr_execute($plan, $repo);
        $out['comment_posted'] = $res['commented'];
        $out['already_acknowledged'] = $res['already'];
        $out['labels_added'] = $res['labels_added'];
        $out['notes'] = $res['notes'];
        foreach ($res['notes'] as $note) cm_note($res['ok'] ? 'warning' : 'error', $note);
        $out['ok'] = $res['ok'];
    }

    if (cm_flag($opt['json'])) {
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } else {
        echo 'triage-issue: ', $repo, '#', $plan['issue'], ' — ',
            ($plan['skip'] ?? (isset($out['already_acknowledged']) && $out['already_acknowledged']
                ? 'already acknowledged'
                : (cm_flag($opt['dry-run']) ? 'dry run' : ($out['comment_posted'] ? 'acknowledged' : 'not acknowledged')))),
            $plan['labels'] !== [] && $plan['skip'] === null ? ' [labels: ' . implode(', ', $plan['labels']) . ']' : '',
            "\n";
    }
    exit(($out['ok'] ?? true) === false ? 1 : 0);
}
