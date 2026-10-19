<?php
/**
 * NewUI v4.0 — one portable runner for every scheduled background job.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-10-17 an operator running TicketsCAD in Docker under Coolify (with
 * the 3CX bridge) reported on the project's Google Group that inbound call
 * records kept piling up on the Inbound Calls page: "in a non-Docker install,
 * cron usually takes care of it. In Docker nothing runs it." He was right, and
 * it was not only that job. The Dockerfile, docker-entrypoint.sh and
 * docker-compose.yml contained no scheduler of any kind, so on every Docker
 * install NONE of the background jobs had ever run: the inbound-call wrap-up
 * fold (finished calls never left the active list), the PAR scheduler, the
 * notification and held-message sweep, scheduled-incident activation, the
 * retention purges, webhook retries, expired-grant cleanup, weather polling
 * and the scheduled backups. Settings → System Health said "never run" and
 * then told the admin to type `systemctl`, inside a container that has no
 * systemd.
 *
 * Linux installs schedule each job with its own systemd timer
 * (docs/MAINTENANCE-RUNBOOK.md) and Windows ran nine of them from a batch
 * file. Neither travels into a container. This file is the one piece that
 * does: it knows every job and its interval, and tools/run-scheduled-jobs.php
 * drives it either as a long-running loop (the Docker `scheduler` service) or
 * once per invocation (cron, a Coolify scheduled task, Windows Task Scheduler).
 *
 * WHAT IT GUARANTEES
 * ------------------
 *  - Every job runs as its OWN process (argv-array proc_open, never a shell
 *    string), so one job that fails, fatals or hangs cannot take the others
 *    with it.
 *  - Output goes to temp FILES, never pipes. On Windows stream_set_blocking()
 *    is a no-op on a proc_open pipe, so a pipe-based reader can deadlock on a
 *    full buffer (CLAUDE.md, the #28 pitfall).
 *  - A job that outlives its timeout is killed WITH ITS CHILDREN: taskkill /T
 *    on Windows; on Linux the job is started under setsid so the whole process
 *    group can be signalled.
 *  - Never two copies of the same job at once: an flock() per job, in a state
 *    directory that the Docker `scheduler` and `app` containers share, so a
 *    `--once` run in one cannot overlap a `--loop` run in the other.
 *  - "Due" is measured from the last START, which is kept in the same
 *    directory, so a --once runner invoked every minute still runs a daily job
 *    once a day, and a restarted loop resumes its schedule instead of firing
 *    everything at once. If the directory cannot be written, the runner keeps
 *    the times in memory (--loop) or treats every job as due (--once) — every
 *    job is safe to run early; the daily purges are cutoff-date queries.
 *
 * What it deliberately does NOT do: decide whether a job's work is stale. The
 * jobs already refuse to act retroactively on work older than
 * sched_stale_cutoff_min (inc/scheduled-jobs.php) — which is what makes it
 * safe to start this runner on an install where nothing has run for months.
 * The one job that lacked that guard, webhook retries, got it in the same
 * change (inc/webhooks.php, webhook_process_retries()).
 *
 * The job heartbeat on Settings → System Health is still written by each job
 * itself (sched_job_record()), not by this runner, so the Status page cannot
 * report a run that did not happen.
 */

require_once __DIR__ . '/scheduled-jobs.php';

/**
 * Jobs this runner runs that are not in sched_job_registry().
 *
 * They are kept out of the registry because they already have their own
 * status surface (backups: health_check_backups(); weather: the Weather Alerts
 * page) or are bookkeeping with nothing to alarm about (expired grants are
 * filtered by rbac_can() whether or not they are swept; the regulatory audit
 * reports through health_check_matrix_regulatory(), which recomputes live).
 *
 * Each one is cheap and inert when its feature is not in use:
 *   backup                   tools/backup_run.php backs up only when a backup
 *                            is due and automatic backups are on.
 *   expire_grants            one indexed DELETE of already-expired role grants.
 *   weather_poll             exits immediately unless weather alerts are on.
 *   matrix_regulatory_audit  read-only graph scan; never changes a route.
 */
function sched_runner_extra_jobs(): array {
    return [
        'backup' => [
            'label'      => 'Automatic database backup',
            'script'     => 'tools/backup_run.php',
            'interval_s' => 3600,    // the documented hourly cron; the job itself applies the backup interval
            'timeout_s'  => 3600,
        ],
        'expire_grants' => [
            'label'      => 'Expired role-grant cleanup',
            'script'     => 'tools/expire_grants.php',
            'interval_s' => 3600,
            'timeout_s'  => 300,
        ],
        'weather_poll' => [
            'label'      => 'NWS weather-alert poll',
            'script'     => 'tools/weather_poll.php',
            'interval_s' => 60,      // matches docs/WEATHER-ALERTS-GUIDE.md's timer and the default Poll interval
            'timeout_s'  => 300,
        ],
        'matrix_regulatory_audit' => [
            'label'      => 'Patch-rail cross-class regulatory audit',
            'script'     => 'tools/matrix_regulatory_audit_tick.php',
            'interval_s' => 900,     // the cadence the tick's own docblock suggests
            'timeout_s'  => 300,
        ],
    ];
}

/**
 * Jobs that run only when asked for with --with=<key>. An install that does
 * not use the integration would otherwise log a failure on every run: the
 * aprs.fi poller exits 1 when no API key is configured.
 */
function sched_runner_optional_jobs(): array {
    return [
        'aprs_poller' => [
            'label'      => 'aprs.fi position poll',
            'script'     => 'tools/aprs-poller.php',
            'interval_s' => 300,
            'timeout_s'  => 300,
        ],
    ];
}

/**
 * How long a registry job may run before it is killed. Generous on purpose:
 * this exists to recover from a job that has WEDGED, not to cut short one that
 * is merely busy. A job killed here is retried at its next interval.
 */
function sched_runner_timeout_s(string $key, int $intervalS): int {
    $explicit = [
        'inbound_calls_tick'                  => 120,
        'matrix_expiry_warning'               => 120,
        'par_tick'                            => 300,
        'channel_receive_tick'                => 300,
        'scheduled_incidents_tick'            => 300,
        'org_relationship_activation_cleanup' => 300,
        'pending_messages_tick'               => 600,   // sends notifications, each with its own network timeout
        'webhook_retry'                       => 600,   // up to 50 deliveries per run, same reason
        'audit_log_purge'                     => 3600,  // archives before it deletes
        'message_log_purge'                   => 3600,
    ];
    if (isset($explicit[$key])) return $explicit[$key];
    return max(300, min(3600, $intervalS * 3));
}

/** "php tools/par_tick.php" / "php tools\par_tick.php" → "tools/par_tick.php". */
function sched_runner_script_from_command(string $command): ?string {
    if (preg_match('~tools[\\\\/]([A-Za-z0-9_.-]+\.php)~', $command, $m)) {
        return 'tools/' . $m[1];
    }
    return null;
}

/**
 * Every job this runner knows about, keyed by job key.
 *
 * @param string[] $with optional job keys to add (sched_runner_optional_jobs())
 * @return array<string,array> key, label, script, path, exists, args,
 *         interval_s, timeout_s, source ('registry'|'runner'|'optional'),
 *         log_success (print successful output: true for hourly-or-slower jobs,
 *         which are rare and whose output — "backup OK" — is worth keeping).
 */
function sched_runner_jobs(array $with = [], ?string $root = null): array {
    if ($root === null) $root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
    $jobs = [];

    foreach (sched_job_registry() as $key => $def) {
        $interval = (int) ($def['interval_s'] ?? 60);
        $jobs[$key] = [
            'label'      => (string) ($def['label'] ?? $key),
            'script'     => sched_runner_script_from_command((string) ($def['command'] ?? '')),
            'interval_s' => $interval,
            'timeout_s'  => sched_runner_timeout_s($key, $interval),
            'source'     => 'registry',
        ];
    }
    foreach (sched_runner_extra_jobs() as $key => $def) {
        if (isset($jobs[$key])) continue;               // a registry entry always wins
        $jobs[$key] = $def + ['source' => 'runner'];
    }
    $optional = sched_runner_optional_jobs();
    foreach ($with as $key) {
        if (isset($optional[$key]) && !isset($jobs[$key])) {
            $jobs[$key] = $optional[$key] + ['source' => 'optional'];
        }
    }

    foreach ($jobs as $key => $j) {
        $path = $j['script'] !== null ? $root . '/' . $j['script'] : null;
        $jobs[$key] = $j + [
            'key'         => $key,
            'path'        => $path,
            'exists'      => $path !== null && is_file($path),
            'args'        => [],
            'log_success' => (int) $j['interval_s'] >= 3600,
        ];
    }
    return $jobs;
}

/** Pure: is a job due? Never started → due. A start "in the future" (the clock
 *  was set back) → due, rather than silently never running again. */
function sched_runner_is_due(?int $lastStart, int $intervalS, int $now): bool {
    if ($lastStart === null || $lastStart <= 0) return true;
    if ($lastStart > $now + 60) return true;
    return ($now - $lastStart) >= max(1, $intervalS);
}

// ─────────────────────────────────────────────────────────────────────────
// State directory: per-job lock + last-start time
// ─────────────────────────────────────────────────────────────────────────

/**
 * Where the per-job lock and last-start files live. First writable of:
 *   1. an explicit --state-dir
 *   2. $TICKETSCAD_JOB_STATE_DIR
 *   3. <app>/cache/job-state — in Docker this is the app_cache volume, which
 *      the `app` and `scheduler` containers share, so their locks agree; on
 *      Windows it sits beside the cache\job-logs directory the batch file has
 *      always written. The files hold a timestamp each and nothing else.
 *   4. a per-install directory under the system temp dir.
 * Null when none is writable: the runner then works without cross-process
 * locks (it still never overlaps a job with itself).
 */
function sched_runner_state_dir(string $root, ?string $override = null): ?string {
    $cands = [];
    if ($override !== null && $override !== '') $cands[] = $override;
    $env = getenv('TICKETSCAD_JOB_STATE_DIR');
    if ($env !== false && $env !== '') $cands[] = (string) $env;
    $cands[] = $root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'job-state';
    $cands[] = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
             . 'ticketscad-job-state-' . substr(md5($root), 0, 10);
    foreach ($cands as $d) {
        $d = rtrim($d, '/\\');
        if (!is_dir($d)) @mkdir($d, 0775, true);
        if (is_dir($d) && is_writable($d)) return $d;
    }
    return null;
}

function _sched_runner_state_file(string $dir, string $key, string $ext): string {
    return $dir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . $ext;
}

/** Last recorded start of a job, or null. */
function sched_runner_last_start(?string $dir, string $key): ?int {
    if ($dir === null) return null;
    $raw = @file_get_contents(_sched_runner_state_file($dir, $key, '.last'));
    if ($raw === false) return null;
    $ts = (int) trim($raw);
    return $ts > 0 ? $ts : null;
}

function sched_runner_mark_start(?string $dir, string $key, int $ts): bool {
    if ($dir === null) return false;
    return @file_put_contents(_sched_runner_state_file($dir, $key, '.last'), (string) $ts) !== false;
}

/**
 * Take a job's lock without waiting.
 * @return resource|false|null  the lock handle; false = another runner holds
 *         it (the job is running elsewhere); null = no lock is possible here
 *         (no state dir, or the file cannot be opened).
 */
function sched_runner_lock(?string $dir, string $key) {
    if ($dir === null) return null;
    $path = _sched_runner_state_file($dir, $key, '.lock');
    $fh = @fopen($path, 'c');
    if (!$fh) $fh = @fopen($path, 'r');   // created by another user: a read handle can still flock
    if (!$fh) return null;
    $wouldBlock = 0;
    if (!@flock($fh, LOCK_EX | LOCK_NB, $wouldBlock)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

function sched_runner_unlock($fh): void {
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

/** Record that a --loop runner is alive (read by --health). */
function sched_runner_touch_alive(?string $dir, int $now): void {
    if ($dir !== null) @file_put_contents($dir . DIRECTORY_SEPARATOR . 'runner.alive', (string) $now);
}

function sched_runner_alive_age(?string $dir, int $now): ?int {
    if ($dir === null) return null;
    $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'runner.alive');
    if ($raw === false || (int) $raw <= 0) return null;
    return max(0, $now - (int) $raw);
}

// ─────────────────────────────────────────────────────────────────────────
// Processes
// ─────────────────────────────────────────────────────────────────────────

/** util-linux setsid, if this host has it (every Debian, including the image). */
function sched_runner_setsid_path(): ?string {
    if (sched_is_windows()) return null;
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $p) {
        if (@is_executable($p)) return $p;
    }
    return null;
}

/**
 * Start one process. stdin is the null device; stdout and stderr go to temp
 * FILES (never pipes — see the file docblock). On Linux the command is started
 * under setsid, so it leads its own process group and a timeout can kill the
 * whole tree. Returns null if the process could not be started.
 *
 * @param array $argv program and arguments, passed to the OS as-is — no shell.
 */
function sched_runner_spawn(array $argv, string $cwd): ?array {
    $win  = sched_is_windows();
    $tmp  = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
    $tag  = 'tcad-job-' . getmypid() . '-' . bin2hex(random_bytes(5));
    $out  = $tmp . $tag . '.out';
    $err  = $tmp . $tag . '.err';
    $null = $win ? 'NUL' : '/dev/null';

    $group  = false;
    $setsid = sched_runner_setsid_path();
    if ($setsid !== null) {
        $argv  = array_merge([$setsid], $argv);
        $group = true;
    }

    $pipes = [];
    $proc = @proc_open($argv, [
        0 => ['file', $null, 'r'],
        1 => ['file', $out, 'w'],
        2 => ['file', $err, 'w'],
    ], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        @unlink($out);
        @unlink($err);
        return null;
    }
    $st = @proc_get_status($proc);
    return [
        'proc'    => $proc,
        'pid'     => is_array($st) ? (int) ($st['pid'] ?? 0) : 0,
        'out'     => $out,
        'err'     => $err,
        'group'   => $group,
        'started' => microtime(true),
    ];
}

/** Kill a started process and everything it started. */
function sched_runner_kill_tree(array $run): void {
    $pid = (int) ($run['pid'] ?? 0);
    if ($pid > 0) {
        if (sched_is_windows()) {
            // PHP's proc_terminate() ends only the one process; a job's own
            // children would survive and keep its output files open.
            $pidArg = (string) $pid;
            $k = @proc_open(['taskkill', '/T', '/F', '/PID', $pidArg], [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'w'],
                2 => ['file', 'NUL', 'w'],
            ], $kp, null, null, ['bypass_shell' => true]);
            if (is_resource($k)) {
                $until = microtime(true) + 10.0;
                while (microtime(true) < $until) {
                    $s = @proc_get_status($k);
                    if (!is_array($s) || empty($s['running'])) break;
                    usleep(50000);
                }
                @proc_close($k);
            }
        } elseif (!empty($run['group'])) {
            if (function_exists('posix_kill')) {
                @posix_kill(-$pid, 9);
            } else {
                $groupArg = '-' . $pid;
                $k = @proc_open(['kill', '-KILL', '--', $groupArg], [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ], $kp);
                if (is_resource($k)) @proc_close($k);
            }
        } elseif (function_exists('posix_kill')) {
            @posix_kill($pid, 9);
        }
    }
    if (isset($run['proc']) && is_resource($run['proc'])) @proc_terminate($run['proc'], 9);
}

/** The last $max bytes of a file ('' when missing). */
function sched_runner_read_tail(string $file, int $max = 65536): string {
    $size = @filesize($file);
    if ($size === false || $size <= 0) return '';
    $fh = @fopen($file, 'rb');
    if (!$fh) return '';
    if ($size > $max) fseek($fh, $size - $max);
    $data = (string) stream_get_contents($fh);
    fclose($fh);
    return $data;
}

// ─────────────────────────────────────────────────────────────────────────
// The scheduler itself
// ─────────────────────────────────────────────────────────────────────────

/**
 * Fresh runner state.
 *  running   key => process record of a job this runner started
 *  last      key => last start this runner knows of (in-memory fallback)
 *  eligible  null (loop: every job, when due) | key => true (once: the jobs
 *            found due at start, each run at most once)
 *  noted     key => true for one-off notices already reported
 */
function sched_runner_new_state(?array $eligible = null): array {
    return ['running' => [], 'last' => [], 'eligible' => $eligible, 'noted' => []];
}

/** Which of $jobs are due now (for a --once run). */
function sched_runner_due_set(array $jobs, ?string $stateDir, int $now, bool $force = false): array {
    $due = [];
    foreach ($jobs as $key => $job) {
        if ($force || sched_runner_is_due(sched_runner_last_start($stateDir, $key), (int) $job['interval_s'], $now)) {
            $due[$key] = true;
        }
    }
    return $due;
}

/**
 * One scheduling pass: reap finished jobs, kill any past its timeout, then
 * start the jobs that are due and not running. Never blocks for longer than
 * it takes to start or kill a process.
 *
 * Options: root (cwd for the jobs), php (interpreter, default PHP_BINARY),
 * state_dir (null = no shared state), max_parallel (default 6), max_starts
 * per pass (default 3 — spreads a cold start over a few seconds instead of
 * starting a dozen PHP processes in the same instant), start (default true).
 *
 * @return array[] events: ['event' => started|ok|failed|timeout|busy|missing|
 *         spawn_failed, 'job' => key, 'exit' => ?int, 'secs' => ?float,
 *         'output' => string]
 */
function sched_runner_pass(array $jobs, array &$state, array $opt, ?int $now = null): array {
    if ($now === null) $now = time();
    $events      = [];
    $dir         = $opt['state_dir'] ?? null;
    $php         = (string) ($opt['php'] ?? PHP_BINARY);
    $root        = (string) ($opt['root'] ?? (defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__)));
    $maxParallel = max(1, (int) ($opt['max_parallel'] ?? 6));
    $maxStarts   = max(1, (int) ($opt['max_starts'] ?? 3));
    $mayStart    = $opt['start'] ?? true;

    // 1. Reap, and enforce timeouts.
    foreach ($state['running'] as $key => $run) {
        $st      = @proc_get_status($run['proc']);
        $elapsed = microtime(true) - $run['started'];
        $done    = !is_array($st) || empty($st['running']);
        $timeout = !$done && $elapsed > (int) $run['timeout'];
        if (!$done && !$timeout) continue;

        if ($timeout) {
            sched_runner_kill_tree($run);
            $code = null;
        } else {
            $code = is_array($st) ? (int) $st['exitcode'] : -1;
        }
        @proc_close($run['proc']);
        sched_runner_unlock($run['lock'] ?? null);

        $output = trim(sched_runner_read_tail($run['out']) . "\n" . sched_runner_read_tail($run['err']));
        @unlink($run['out']);
        @unlink($run['err']);
        unset($state['running'][$key]);

        $events[] = [
            'event'  => $timeout ? 'timeout' : ($code === 0 ? 'ok' : 'failed'),
            'job'    => $key,
            'exit'   => $code,
            'secs'   => round(microtime(true) - $run['started'], 2),
            'output' => $output,
        ];
    }

    if (!$mayStart) return $events;

    // 2. Start what is due.
    $started = 0;
    foreach ($jobs as $key => $job) {
        if (isset($state['running'][$key])) continue;
        if ($state['eligible'] !== null && !isset($state['eligible'][$key])) continue;

        if (empty($job['exists'])) {
            unset($state['eligible'][$key]);
            if (empty($state['noted']['missing:' . $key])) {
                $state['noted']['missing:' . $key] = true;
                $events[] = ['event' => 'missing', 'job' => $key, 'exit' => null, 'secs' => null,
                             'output' => 'script not found: ' . (string) ($job['script'] ?? '(no script)')];
            }
            continue;
        }

        if ($state['eligible'] === null) {
            $last = sched_runner_last_start($dir, $key);
            $mem  = $state['last'][$key] ?? null;
            if ($mem !== null && ($last === null || $mem > $last)) $last = $mem;
            if (!sched_runner_is_due($last, (int) $job['interval_s'], $now)) continue;
        }

        if (count($state['running']) >= $maxParallel || $started >= $maxStarts) break;

        $lock = sched_runner_lock($dir, $key);
        if ($lock === false) {
            // Another runner is running this job right now. Count that as this
            // interval's run rather than knocking on the lock every second.
            $state['last'][$key] = $now;
            unset($state['eligible'][$key]);
            $events[] = ['event' => 'busy', 'job' => $key, 'exit' => null, 'secs' => null,
                         'output' => 'already running in another runner — skipped'];
            continue;
        }

        $jobArgv = array_merge([$php, (string) $job['path']], array_values($job['args'] ?? []));
        $run = sched_runner_spawn($jobArgv, $root);
        $state['last'][$key] = $now;
        unset($state['eligible'][$key]);
        if ($run === null) {
            sched_runner_unlock($lock);
            $events[] = ['event' => 'spawn_failed', 'job' => $key, 'exit' => null, 'secs' => null,
                         'output' => 'could not start ' . $php];
            continue;
        }
        sched_runner_mark_start($dir, $key, $now);
        $run['timeout'] = (int) $job['timeout_s'];
        $run['lock']    = $lock;
        $state['running'][$key] = $run;
        $started++;
        $events[] = ['event' => 'started', 'job' => $key, 'exit' => null, 'secs' => null, 'output' => ''];
    }

    return $events;
}

/**
 * How many sql/run_*.php migrations on disk have no record in `_migrations`
 * for their current content — the same comparison api/migrations-check.php
 * makes. A script whose last attempt FAILED counts as attempted, not pending,
 * so a single broken migration cannot hold the jobs back forever. Null when
 * the tracking table does not exist yet.
 *
 * Why the --loop runner asks: in Docker the `app` container installs or
 * migrates the database every time it starts, and the `scheduler` container
 * starts at the same moment. A job that runs half-way through a migration
 * reads a schema that is being changed under it — harmless, but it fills the
 * log with column errors on the very first start, which is exactly when an
 * operator is reading it.
 */
function sched_runner_pending_migrations(string $root): ?int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all("SELECT script_name, script_hash FROM `{$prefix}_migrations`");
    } catch (Throwable $e) {
        return null;
    }
    $seen = [];
    foreach ($rows as $r) $seen[$r['script_name'] . '|' . $r['script_hash']] = true;
    $pending = 0;
    foreach (glob($root . '/sql/run_*.php') ?: [] as $path) {
        $name = basename($path);
        if ($name === 'run_migrations.php') continue;
        if (!isset($seen[$name . '|' . hash_file('sha256', $path)])) $pending++;
    }
    return $pending;
}

/** True when a --once run has nothing left to start or wait for. */
function sched_runner_once_done(array $state): bool {
    return empty($state['running']) && empty($state['eligible']);
}

/** Kill everything still running (used when a runner is told to stop). */
function sched_runner_abort_all(array &$state): void {
    foreach ($state['running'] as $key => $run) {
        sched_runner_kill_tree($run);
        @proc_close($run['proc']);
        sched_runner_unlock($run['lock'] ?? null);
        @unlink($run['out']);
        @unlink($run['err']);
        unset($state['running'][$key]);
    }
}
