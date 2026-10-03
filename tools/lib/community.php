<?php
/**
 * Shared plumbing for the community-responsiveness tools (Phase 155).
 *
 * Used by tools/triage-issue.php, tools/sync-labels.php,
 * tools/release-notify.php, tools/status-issue.php and (development tree only)
 * tools/community-watch.php and tools/sync-lag-check.php.
 *
 * Everything here exists to make ONE property true: **no issue, comment or
 * commit text is ever handed to a shell.** Every external program is started
 * from an argument list (proc_open with an array, never a command string), and
 * everything a stranger can type (an issue title, a comment body, a commit
 * subject) travels only as data: a JSON file the Actions runner wrote, a
 * stdin stream, or one discrete argv element. See
 * tests/test_no_shell_command_execution.php for the lexical gate that holds
 * this file to it.
 *
 * Child processes write to temporary FILES, not pipes. A pipe fills (about
 * 64 KB on Linux, less on Windows) and then the child blocks until someone
 * reads it, while a parent reading the other stream blocks too: the deadlock
 * documented in tests/test_proc_open_pipe_deadlock.php. A file cannot fill up
 * that way, and it makes the timeout below reachable.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─────────────────────────────────────────────────────────────────────
// Process plumbing
// ─────────────────────────────────────────────────────────────────────

/**
 * Run a program from an argument list and wait for it.
 *
 * $argv is typed `array` so the argv form is provable at the proc_open site
 * (tests/test_no_shell_command_execution.php, rule A).
 *
 * @param string[]|array $argv
 * @return array{code:int,out:string,err:string}
 */
function cm_run(array $argv, ?string $cwd = null, ?string $stdin = null, ?array $env = null, int $timeoutSec = 120): array
{
    $inPath  = tempnam(sys_get_temp_dir(), 'cmi');
    $outPath = tempnam(sys_get_temp_dir(), 'cmo');
    $errPath = tempnam(sys_get_temp_dir(), 'cme');
    if ($inPath === false || $outPath === false || $errPath === false) {
        return ['code' => 127, 'out' => '', 'err' => 'could not create temporary files'];
    }
    file_put_contents($inPath, $stdin ?? '');

    $desc = [
        0 => ['file', $inPath, 'r'],
        1 => ['file', $outPath, 'w'],
        2 => ['file', $errPath, 'w'],
    ];
    $pipes = [];
    $proc  = @proc_open($argv, $desc, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        @unlink($inPath); @unlink($outPath); @unlink($errPath);
        return ['code' => 127, 'out' => '', 'err' => 'could not start ' . (string) ($argv[0] ?? '?')];
    }

    $exit     = null;
    $deadline = time() + $timeoutSec;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) { $exit = (int) $st['exitcode']; break; }
        if (time() >= $deadline) {
            proc_terminate($proc);
            $exit = 124;                       // the conventional timeout status
            break;
        }
        usleep(15000);
    }
    $closed = proc_close($proc);
    if ($exit === null || $exit === -1) $exit = $closed;

    $out = (string) @file_get_contents($outPath);
    $err = (string) @file_get_contents($errPath);
    @unlink($inPath); @unlink($outPath); @unlink($errPath);
    return ['code' => $exit, 'out' => $out, 'err' => $err];
}

/**
 * The command prefix used to reach the GitHub CLI: normally just ["gh"].
 *
 * COMMUNITY_GH_CMD holds a JSON array of strings and exists for two honest
 * reasons: a runner or workstation where `gh` is not on PATH under that name,
 * and the test suite, which points it at a recording fake so the REAL runner
 * below is what gets exercised. It is an argument list, never a shell string.
 *
 * @return string[]
 */
function cm_gh_prefix(): array
{
    $raw = getenv('COMMUNITY_GH_CMD');
    if ($raw === false || trim($raw) === '') return ['gh'];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        fwrite(STDERR, "COMMUNITY_GH_CMD must be a JSON array of strings, e.g. [\"gh\"]\n");
        exit(2);
    }
    foreach ($decoded as $part) {
        if (!is_string($part) || $part === '') {
            fwrite(STDERR, "COMMUNITY_GH_CMD must be a JSON array of non-empty strings\n");
            exit(2);
        }
    }
    return array_values($decoded);
}

/**
 * Run `gh <args>`. $stdin carries any body text (never an argument, so it
 * cannot be mistaken for an option and cannot hit an argv length limit).
 *
 * @param string[] $args
 * @return array{code:int,out:string,err:string}
 */
function cm_gh(array $args, ?string $stdin = null): array
{
    $cmd = array_merge(cm_gh_prefix(), $args);
    return cm_run($cmd, null, $stdin);
}

/**
 * Run `git -C <repo> <args>`.
 *
 * @param string[] $args
 * @return array{code:int,out:string,err:string}
 */
function cm_git(string $repo, array $args): array
{
    $cmd = array_merge(['git', '-C', $repo], $args);
    return cm_run($cmd, null, null, null, 300);
}

// ─────────────────────────────────────────────────────────────────────
// JSON
// ─────────────────────────────────────────────────────────────────────

/**
 * Decode every top-level JSON value in $text.
 *
 * `gh api --paginate` prints each page's JSON back to back (`[...][...]`),
 * which is not one valid JSON document; older gh versions do this and newer
 * ones do it unless --slurp is given. Decoding the pages one by one makes the
 * caller independent of which gh it meets.
 *
 * @return array<int,mixed>|null null when the text is not a clean run of
 *         objects/arrays (including empty output)
 */
function cm_json_decode_all(string $text): ?array
{
    $len = strlen($text);
    $i = 0;
    $values = [];
    while (true) {
        while ($i < $len && ctype_space($text[$i])) $i++;
        if ($i >= $len) break;
        $open = $text[$i];
        if ($open !== '[' && $open !== '{') return null;
        $start = $i;
        $depth = 0;
        $inStr = false;
        $esc = false;
        for (; $i < $len; $i++) {
            $c = $text[$i];
            if ($inStr) {
                if ($esc) { $esc = false; }
                elseif ($c === '\\') { $esc = true; }
                elseif ($c === '"') { $inStr = false; }
                continue;
            }
            if ($c === '"') { $inStr = true; continue; }
            if ($c === '[' || $c === '{') { $depth++; continue; }
            if ($c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) { $i++; break; }
            }
        }
        if ($depth !== 0) return null;
        $one = json_decode(substr($text, $start, $i - $start), true);
        if (!is_array($one)) return null;
        $values[] = $one;
    }
    return $values === [] ? null : $values;
}

/**
 * One list out of a possibly-paginated gh response: concatenates page arrays
 * when they are lists, or returns the single object wrapped in a one-item
 * list. Null when the text is not usable JSON.
 *
 * @return array<int,mixed>|null
 */
function cm_json_list(string $text): ?array
{
    $all = cm_json_decode_all($text);
    if ($all === null) return null;
    $out = [];
    foreach ($all as $page) {
        $isList = $page === [] || array_keys($page) === range(0, count($page) - 1);
        if ($isList) {
            foreach ($page as $row) $out[] = $row;
        } else {
            $out[] = $page;
        }
    }
    return $out;
}

/**
 * gh api <path> → decoded list, or null with the failure reason in $why.
 *
 * @param string[] $extra additional gh arguments (e.g. ['--paginate'])
 * @return array<int,mixed>|null
 */
function cm_gh_api_list(string $path, array $extra, ?string &$why = null): ?array
{
    $args = array_merge(['api'], $extra, [$path]);
    $r = cm_gh($args);
    if ($r['code'] !== 0) {
        $why = trim($r['err']) !== '' ? trim($r['err']) : ('gh exited ' . $r['code']);
        return null;
    }
    $list = cm_json_list($r['out']);
    if ($list === null) {
        $why = 'gh api ' . $path . ' returned something that is not JSON';
        return null;
    }
    return $list;
}

// ─────────────────────────────────────────────────────────────────────
// Configuration (repository variables arrive as environment variables)
// ─────────────────────────────────────────────────────────────────────

/** An env var as a trimmed string, or null when unset/empty. */
function cm_env(string $name): ?string
{
    $v = getenv($name);
    if ($v === false) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}

/**
 * An integer setting bounded to [$min,$max]. A value that is not an integer,
 * or is out of range, falls back to the default and is reported through
 * $warn, so a typo in a repository variable degrades to the documented default
 * instead of silently becoming 0 or 10000.
 */
function cm_env_int(string $name, int $default, int $min, int $max, ?string &$warn = null): int
{
    $v = cm_env($name);
    if ($v === null) return $default;
    if (!preg_match('/^\d{1,6}$/', $v) || (int) $v < $min || (int) $v > $max) {
        $warn = "{$name}={$v} is not an integer between {$min} and {$max}; using {$default}";
        return $default;
    }
    return (int) $v;
}

/** A boolean setting: 0/false/no/off disable, anything else (or unset) = $default. */
function cm_env_bool(string $name, bool $default): bool
{
    $v = cm_env($name);
    if ($v === null) return $default;
    return !in_array(strtolower($v), ['0', 'false', 'no', 'off', 'disabled'], true);
}

/** A strict YYYY-MM-DD calendar date, or null. */
function cm_valid_date(?string $s): ?string
{
    if ($s === null) return null;
    $s = trim($s);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : null;
}

// ─────────────────────────────────────────────────────────────────────
// Time
// ─────────────────────────────────────────────────────────────────────

/** ISO-8601 → unix time, or null. */
function cm_ts(?string $iso): ?int
{
    if ($iso === null || trim($iso) === '') return null;
    $t = strtotime($iso);
    return $t === false ? null : $t;
}

/**
 * Calendar date (Y-m-d), minute stamp (Y-m-d H:i) and ISO weekday of a unix time,
 * in UTC. Built from DateTimeImmutable('@ts'), which is always UTC, rather than
 * the gm-date function: these are GitHub's clock (UTC on the wire), never a
 * MySQL DATETIME, and tools/timezone_audit.php rightly flags a bare UTC date
 * format anywhere it could be confused with one. Saying "this is UTC" in a
 * function name beats baselining a false positive.
 */
function cm_utc_date(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->format('Y-m-d');
}

function cm_utc_minute(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->format('Y-m-d H:i');
}

/** ISO weekday, 1 (Monday) to 7 (Sunday). */
function cm_utc_weekday(int $ts): int
{
    return (int) (new DateTimeImmutable('@' . $ts))->format('N');
}

function cm_hours_between(int $from, int $to): float
{
    return ($to - $from) / 3600.0;
}

/** Compact "3h", "2.5d" style age. */
function cm_age_label(float $hours): string
{
    if ($hours < 1) return max(1, (int) round($hours * 60)) . 'm';
    if ($hours < 48) return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . 'h';
    return rtrim(rtrim(number_format($hours / 24, 1, '.', ''), '0'), '.') . 'd';
}

// ─────────────────────────────────────────────────────────────────────
// Text safety
// ─────────────────────────────────────────────────────────────────────

/**
 * Make untrusted one-line text safe to place inside a markdown table cell or
 * a heading: no newlines, no table/HTML/markdown control characters, no live
 * @mention, capped length.
 */
function cm_md_inline(string $s, int $max = 80): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    if (function_exists('mb_strlen') && mb_strlen($s) > $max) {
        $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    } elseif (!function_exists('mb_strlen') && strlen($s) > $max) {
        $s = rtrim(substr($s, 0, $max - 1)) . '...';
    }
    // Defang autolinks: GitHub turns a bare http(s)/ftp URL or a www. host into a
    // live link. A stranger's issue title must not become a clickable link on a
    // page the project owns (the public status issue lists open bug titles, and
    // the private inbox is read by a person in a hurry). A zero-width space after
    // the scheme breaks the autolink and leaves the text readable.
    $s = preg_replace('~\b(https?|ftp)://~i', '$1:&#8203;//', $s) ?? $s;
    $s = preg_replace('~\bwww\.~i', 'www&#8203;.', $s) ?? $s;
    // Neutralise: table pipe, HTML, emphasis/code/link characters, and the
    // @ that would otherwise notify whoever the text happens to name.
    $map = [
        '\\' => '\\\\', '|' => '\\|', '<' => '&lt;', '>' => '&gt;', '`' => "'",
        '*' => '\\*', '_' => '\\_', '[' => '\\[', ']' => '\\]', '@' => '@&#8203;',
    ];
    return strtr($s, $map);
}

/** A hidden HTML comment marker, used to recognise our own comments later. */
function cm_marker(string $name): string
{
    return '<!-- ' . $name . ' -->';
}

/**
 * Is $comment one of OUR comments carrying $marker?
 *
 * Anyone can type `<!-- triage-bot:ack -->` into a comment. If that were enough,
 * a stranger could silence the bot (or a release notice) on an issue by posting
 * the marker first. So the marker only counts from an author who could actually
 * have been us: a bot (the Actions identity), or a maintainer-class account (a
 * manual run posts under the maintainer's own name). The authorship fields are
 * GitHub's, not the commenter's.
 *
 * @param array<string,mixed> $comment a row from the issue-comments API
 */
function cm_comment_is_ours_with(array $comment, string $marker): bool
{
    if (strpos((string) ($comment['body'] ?? ''), $marker) === false) return false;
    $user = is_array($comment['user'] ?? null) ? $comment['user'] : [];
    if (strcasecmp((string) ($user['type'] ?? ''), 'Bot') === 0) return true;
    return in_array(strtoupper((string) ($comment['author_association'] ?? '')), ['OWNER', 'MEMBER', 'COLLABORATOR'], true);
}

/**
 * Report a problem in the form GitHub Actions turns into an annotation, or as
 * a plain line on a workstation.
 */
function cm_note(string $level, string $msg): void
{
    $msg = trim($msg);
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $enc = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $msg);
        echo "::{$level}::{$enc}\n";
    } else {
        fwrite(STDERR, "{$level}: {$msg}\n");
    }
}

/** owner/name validated, or null. */
function cm_valid_slug(?string $slug): ?string
{
    if ($slug === null) return null;
    $slug = trim($slug);
    return preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $slug) ? $slug : null;
}

/** Minimal option parser: --key=value and --flag; unknown keys are fatal. */
function cm_parse_args(array $argvIn, array $spec, string $who): array
{
    $opt = $spec;
    foreach (array_slice($argvIn, 1) as $arg) {
        if (substr($arg, 0, 2) !== '--') {
            fwrite(STDERR, "{$who}: unexpected argument `{$arg}`\n");
            exit(2);
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        $key = $eq === false ? $body : substr($body, 0, $eq);
        $val = $eq === false ? true : substr($body, $eq + 1);
        if (!array_key_exists($key, $opt)) {
            fwrite(STDERR, "{$who}: unknown option `--{$key}`\n");
            exit(2);
        }
        if (is_array($opt[$key])) {
            $opt[$key][] = $val;               // repeatable option
        } else {
            $opt[$key] = $val;
        }
    }
    return $opt;
}

/**
 * A boolean flag the way an operator would spell it: --json and --json=1 are
 * true, --json=0 / =false / =no are false. (The release tooling learned this
 * the hard way: a flag whose plausible spelling silently meant the opposite.)
 */
function cm_flag($v): bool
{
    if ($v === true) return true;
    if ($v === false || $v === null) return false;
    return !in_array(strtolower((string) $v), ['0', 'false', 'no', ''], true);
}
