<?php
/**
 * Run TicketsCAD's scheduled background jobs — every one of them, each at its
 * own interval. See inc/scheduled-job-runner.php for why this exists (the
 * Docker image had no scheduler at all, 2026-10-17) and what it guarantees.
 *
 *   php tools/run-scheduled-jobs.php --loop     run forever (the Docker `scheduler` service)
 *   php tools/run-scheduled-jobs.php --once     run whatever is due, wait for it, exit
 *                                               (cron, a Coolify scheduled task, Windows
 *                                               Task Scheduler — every minute)
 *   php tools/run-scheduled-jobs.php --list     show every job, its interval and last start
 *   php tools/run-scheduled-jobs.php --health   exit 0 if a --loop runner was alive in
 *                                               the last 3 minutes (the compose healthcheck)
 *
 * Options:
 *   --force           with --once: run every (selected) job now, due or not
 *   --only=a,b        only these job keys (see --list)
 *   --with=a,b        also run these optional jobs (aprs_poller)
 *   --state-dir=DIR   where per-job lock/last-start files live (default
 *                     cache/job-state; see sched_runner_state_dir())
 *   --log-dir=DIR     also append each job's full output to DIR/<script>.log
 *   --verbose         print every job's output, not only failures and the
 *                     hourly-or-slower jobs
 *   --summary-every=S with --loop: print a one-line summary every S seconds
 *                     (default 900; 0 = never)
 *
 * Exit status (--once): 0 = every job that ran succeeded (or nothing was due,
 * or the database is not installed yet); 1 = a job failed or timed out, or the
 * database could not be reached.
 *
 * Use ONE scheduler per install: this runner, OR the per-job systemd timers in
 * docs/MAINTENANCE-RUNBOOK.md — not both. Two copies of this runner (say, the
 * Docker `scheduler` service plus a --once scheduled task) are safe: they share
 * per-job locks. The systemd timers call the jobs directly and do not.
 *
 * Run it as the web server user (www-data in the image), so anything a job
 * writes — a backup archive, a cache file — is owned by the user the web
 * server reads it as.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/scheduled-job-runner.php';

// ── Arguments ────────────────────────────────────────────────────────────
$args = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $eq = strpos($a, '=');
    if ($eq === false) { $args[substr($a, 2)] = true; }
    else               { $args[substr($a, 2, $eq - 2)] = substr($a, $eq + 1); }
}
$flag = static function (string $name) use ($args): bool {
    if (!array_key_exists($name, $args)) return false;
    $v = $args[$name];
    return $v === true || in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
};
$list = static function (string $name) use ($args): array {
    if (!isset($args[$name]) || $args[$name] === true) return [];
    return array_values(array_filter(array_map('trim', explode(',', (string) $args[$name])), 'strlen'));
};

$root     = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$verbose  = $flag('verbose');
$stateDir = sched_runner_state_dir($root, isset($args['state-dir']) && $args['state-dir'] !== true ? (string) $args['state-dir'] : null);
$logDir   = isset($args['log-dir']) && $args['log-dir'] !== true ? rtrim((string) $args['log-dir'], '/\\') : null;

function rsj_say(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    if (function_exists('fflush')) @fflush(STDOUT);
}

// ── --health ────────────────────────────────────────────────────────────
if ($flag('health')) {
    $age = sched_runner_alive_age($stateDir, time());
    if ($age !== null && $age <= 180) { echo "ok: runner alive {$age}s ago\n"; exit(0); }
    echo 'unhealthy: ' . ($age === null ? 'no --loop runner has reported in' : "last report {$age}s ago") . "\n";
    exit(1);
}

// ── Which jobs ──────────────────────────────────────────────────────────
$with = $list('with');
$unknownWith = array_diff($with, array_keys(sched_runner_optional_jobs()));
if ($unknownWith) {
    fwrite(STDERR, 'Unknown --with job(s): ' . implode(', ', $unknownWith)
        . '. Optional jobs: ' . implode(', ', array_keys(sched_runner_optional_jobs())) . "\n");
    exit(2);
}
$jobs = sched_runner_jobs($with, $root);
$only = $list('only');
if ($only) {
    $unknown = array_diff($only, array_keys($jobs));
    if ($unknown) {
        fwrite(STDERR, 'Unknown --only job(s): ' . implode(', ', $unknown) . ". Run --list to see them.\n");
        exit(2);
    }
    $jobs = array_intersect_key($jobs, array_flip($only));
}

// ── --list ──────────────────────────────────────────────────────────────
if ($flag('list')) {
    printf("%-38s %-9s %-8s %-9s %-20s %s\n", 'JOB', 'EVERY', 'TIMEOUT', 'SOURCE', 'LAST START', 'SCRIPT');
    foreach ($jobs as $key => $j) {
        $last = sched_runner_last_start($stateDir, $key);
        printf("%-38s %-9s %-8s %-9s %-20s %s%s\n", $key, $j['interval_s'] . 's', $j['timeout_s'] . 's',
            $j['source'], $last ? date('Y-m-d H:i:s', $last) : 'never (here)',
            (string) $j['script'], $j['exists'] ? '' : '  (MISSING)');
    }
    echo "\nState directory: " . ($stateDir ?? '(none writable — no cross-process locks)') . "\n";
    echo 'Optional jobs (--with=): ' . implode(', ', array_keys(sched_runner_optional_jobs())) . "\n";
    exit(0);
}

$once = $flag('once');
$loop = $flag('loop');
if ($once === $loop) {
    fwrite(STDERR, "Usage: php tools/run-scheduled-jobs.php --once | --loop | --list | --health  [options]\n"
        . "See the header of this file for the options.\n");
    exit(2);
}

// ── Is the database installed? ──────────────────────────────────────────
/** 0 = ready; 2 = database unreachable; 3 = reachable but not installed yet. */
function rsj_ready(): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'scheduled_job_runs']);
        return $n > 0 ? 0 : 3;
    } catch (Throwable $e) {
        return 2;
    }
}

// ── Reporting ───────────────────────────────────────────────────────────
$stats = [];
$failedAny = false;
$failMemo  = [];   // job key => fingerprint of its last failure, while it keeps failing

// The last failure's fingerprint is also kept in the state directory, so a
// --once runner invoked every minute (Windows, cron) de-duplicates a job that
// keeps failing the same way just as the long-running --loop does. (A --loop
// that starts afresh prints the first failure in full, once.)
if ($stateDir !== null && $flag('once')) {
    foreach (glob($stateDir . DIRECTORY_SEPARATOR . '*.fail') ?: [] as $ff) {
        $failMemo[basename($ff, '.fail')] = trim((string) @file_get_contents($ff));
    }
}
$rememberFailure = static function (string $key, ?string $fp) use ($stateDir): void {
    if ($stateDir === null) return;
    $file = $stateDir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.fail';
    if ($fp === null) { if (is_file($file)) @unlink($file); }
    else              { @file_put_contents($file, $fp); }
};

$report = static function (array $events) use (&$stats, &$failedAny, &$failMemo, $rememberFailure, $jobs, $verbose, $logDir, &$state): void {
    foreach ($events as $ev) {
        $key = $ev['job'];
        $job = $jobs[$key] ?? ['log_success' => false, 'script' => $key];
        if (!isset($stats[$key])) $stats[$key] = ['ok' => 0, 'failed' => 0, 'timeout' => 0, 'busy' => 0];
        $out = (string) ($ev['output'] ?? '');

        switch ($ev['event']) {
            case 'started':
                if ($verbose) rsj_say("{$key}: started");
                continue 2;
            case 'ok':
                $stats[$key]['ok']++;
                if (isset($failMemo[$key])) {
                    unset($failMemo[$key]);
                    $rememberFailure($key, null);
                    rsj_say("{$key}: recovered — ok again ({$ev['secs']}s)");
                }
                if ($verbose || !empty($job['log_success'])) {
                    rsj_say("{$key}: ok ({$ev['secs']}s)");
                    rsj_print_output($out);
                }
                break;
            case 'failed':
            case 'spawn_failed':
                $stats[$key]['failed']++;
                $failedAny = true;
                // A job that fails the same way every minute (say, a feature
                // switched on but not configured) gets its full output once,
                // then one line per run until the output changes or it recovers.
                $fp = md5(preg_replace('/\[[^\]]*\d\d:\d\d:\d\d[^\]]*\]/', '', $out) . '|' . $ev['exit']);
                $repeat = isset($failMemo[$key]) && $failMemo[$key] === $fp && !$verbose;
                if (!$repeat) $rememberFailure($key, $fp);
                $failMemo[$key] = $fp;
                rsj_say("{$key}: FAILED" . ($repeat ? ' again' : '')
                    . ($ev['exit'] !== null ? " (exit {$ev['exit']}, {$ev['secs']}s)" : '')
                    . ($repeat ? ' — same output as the previous failure' : ' — it will be retried at its next interval'));
                if (!$repeat) rsj_print_output($out, 40);
                break;
            case 'timeout':
                $stats[$key]['timeout']++;
                $failedAny = true;
                rsj_say("{$key}: TIMED OUT after {$ev['secs']}s — killed with its child processes; "
                    . 'it will be retried at its next interval');
                rsj_print_output($out, 40);
                break;
            case 'busy':
                $stats[$key]['busy']++;
                if ($verbose || empty($state['noted']['busy:' . $key])) {
                    $state['noted']['busy:' . $key] = true;
                    rsj_say("{$key}: already running in another runner — skipped this time"
                        . ($verbose ? '' : ' (reported once; see the summary for counts)'));
                }
                continue 2;
            case 'missing':
                $failedAny = true;
                rsj_say("{$key}: cannot run — {$out}");
                continue 2;
        }

        if ($logDir !== null && in_array($ev['event'], ['ok', 'failed', 'timeout', 'spawn_failed'], true)) {
            rsj_append_log($logDir, basename((string) ($job['script'] ?? $key), '.php'),
                '[' . date('Y-m-d H:i:s') . "] {$key} {$ev['event']}"
                . ($ev['exit'] !== null ? " exit={$ev['exit']}" : '')
                . ($ev['secs'] !== null ? " {$ev['secs']}s" : '') . "\n"
                . ($out !== '' ? $out . "\n" : ''));
        }
    }
};

function rsj_print_output(string $out, int $maxLines = 0): void {
    $out = trim($out);
    if ($out === '') return;
    $lines = preg_split('/\r?\n/', $out);
    if ($maxLines > 0 && count($lines) > $maxLines) {
        $lines = array_merge(['(… ' . (count($lines) - $maxLines) . ' earlier line(s) omitted)'],
                             array_slice($lines, -$maxLines));
    }
    foreach ($lines as $l) echo '    | ' . $l . "\n";
}

/** Append to DIR/<name>.log, rotating to .1 past 5 MB so it cannot grow forever. */
function rsj_append_log(string $dir, string $name, string $text): void {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . DIRECTORY_SEPARATOR . $name . '.log';
    if (@filesize($file) > 5 * 1024 * 1024) @rename($file, $file . '.1');
    @file_put_contents($file, $text, FILE_APPEND);
}

$opt = [
    'root'         => $root,
    'php'          => PHP_BINARY,
    'state_dir'    => $stateDir,
    'max_parallel' => 6,
    'max_starts'   => 3,
];

// ── --once ──────────────────────────────────────────────────────────────
if ($once) {
    $ready = rsj_ready();
    if ($ready === 2) {
        rsj_say('FAILED: cannot reach the database configured in config.php — no jobs were run.');
        exit(1);
    }
    if ($ready === 3) {
        if ($verbose) rsj_say('The database is not installed yet (no scheduled_job_runs table) — nothing to do.');
        exit(0);
    }

    $now   = time();
    $state = sched_runner_new_state(sched_runner_due_set($jobs, $stateDir, $now, $flag('force')));
    if (empty($state['eligible'])) {
        if ($verbose) rsj_say('Nothing is due.');
        exit(0);
    }
    if ($verbose) rsj_say('Due: ' . implode(', ', array_keys($state['eligible'])));

    while (true) {
        $report(sched_runner_pass($jobs, $state, $opt));
        if (sched_runner_once_done($state)) break;
        usleep(200000);
    }
    exit($failedAny ? 1 : 0);
}

// ── --loop ──────────────────────────────────────────────────────────────
$summaryEvery = isset($args['summary-every']) && $args['summary-every'] !== true
    ? max(0, (int) $args['summary-every']) : 900;

rsj_say('TicketsCAD scheduler starting: ' . count($jobs) . ' job(s); state directory '
    . ($stateDir ?? '(none writable — jobs are still never overlapped within this runner)'));
foreach ($jobs as $key => $j) {
    echo sprintf("    %-38s every %6ss  %s%s\n", $key, $j['interval_s'], (string) $j['script'],
        $j['exists'] ? '' : '  (MISSING)');
}

// Wait for the database to be reachable and installed — indefinitely, since
// nothing can run without it — and then for any migration the app is applying
// right now to finish, but for at most ten minutes: an install whose migrations
// are stuck still needs its jobs run (see sched_runner_pending_migrations()).
$lastWait     = 0;
$pendingSince = null;
while (true) {
    $r = rsj_ready();
    $pending = null;
    if ($r === 0) {
        $pending = sched_runner_pending_migrations($root);
        if (!$pending) break;                                  // 0, or no tracking table to ask
        if ($pendingSince === null) $pendingSince = time();
        if (time() - $pendingSince >= 600) {
            rsj_say("{$pending} database migration(s) are still pending after 10 minutes — starting the jobs anyway. "
                . 'Apply them with: php sql/run_migrations.php');
            break;
        }
    } else {
        $pendingSince = null;
    }
    // Alive while waiting too: --health reports on the RUNNER; whether the
    // jobs themselves are running is what Settings → System Health shows.
    sched_runner_touch_alive($stateDir, time());
    if (time() - $lastWait >= 60) {
        $lastWait = time();
        if ($r === 2)      rsj_say('Waiting for the database to accept connections…');
        elseif ($r === 3)  rsj_say('Waiting for the database to be installed (the app container installs it on first start)…');
        else               rsj_say("Waiting for {$pending} database migration(s) to finish…");
    }
    sleep(5);
}
rsj_say('Database ready — running jobs.');

$state       = sched_runner_new_state(null);
$windowStart = time();
$lastAlive   = 0;
while (true) {
    $now = time();
    $report(sched_runner_pass($jobs, $state, $opt, $now));

    if ($now - $lastAlive >= 30) {
        sched_runner_touch_alive($stateDir, $now);
        $lastAlive = $now;
    }

    if ($summaryEvery > 0 && $now - $windowStart >= $summaryEvery) {
        $ok = $bad = $tmo = $busy = 0;
        $problems = [];
        foreach ($stats as $key => $s) {
            $ok += $s['ok']; $bad += $s['failed']; $tmo += $s['timeout']; $busy += $s['busy'];
            if ($s['failed'] || $s['timeout']) $problems[] = "{$key} ({$s['failed']} failed, {$s['timeout']} timed out)";
        }
        rsj_say(sprintf('Last %d min: %d run(s) ok, %d failed, %d timed out, %d skipped (running elsewhere)%s',
            (int) round(($now - $windowStart) / 60), $ok, $bad, $tmo, $busy,
            $problems ? ' — problems: ' . implode('; ', $problems) : ''));
        $stats = [];
        $windowStart = $now;
    }

    sleep(1);
}
