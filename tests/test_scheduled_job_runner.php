<?php
/**
 * tools/run-scheduled-jobs.php + inc/scheduled-job-runner.php — the one runner
 * for every background job.
 *
 * Reported 2026-10-17 on the project's Google Group by an operator running
 * TicketsCAD in Docker under Coolify: in Docker nothing ran the scheduled jobs,
 * so inbound call records piled up on the Inbound Calls page. The image had no
 * scheduler at all — every job had never run on every Docker install.
 *
 * What is proven here, by DRIVING the runner rather than reading it:
 *   1. The pure rules: when a job is due; how a registry command maps to a
 *      script.
 *   2. The job table: every job in sched_job_registry() is runnable (its
 *      script exists and carries the CLI guard), every tick script under
 *      tools/ is covered by some job, optional jobs appear only when asked for.
 *   3. The engine, with a probe script standing in for a job: due/skip,
 *      no overlap with itself, no overlap with another runner (the shared
 *      lock), one job's failure does not affect another's run, a hung job is
 *      killed WITH its child process after its timeout, a missing script is
 *      reported rather than retried forever, and the start cap spreads a cold
 *      start.
 *   4. The CLI: --list, argument errors, --health, a real --once run whose
 *      heartbeat appears in scheduled_job_runs (what Settings → System Health
 *      reads), and a real --loop that starts, waits for the database, runs a
 *      job and keeps running.
 *
 * Run: php tests/test_scheduled_job_runner.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/scheduled-job-runner.php';

$pass = 0;
$fail = 0;
function rt(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$label}\n"; }
    else       { $fail++; echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "=== Scheduled job runner (Docker scheduler, 2026-10-17 report) ===\n\n";

$root  = dirname(__DIR__);
$php   = PHP_BINARY;
$probe = __DIR__ . '/_sched_runner_probe.php';
$isWin = sched_is_windows();

$scratch = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tcad-runner-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($scratch, 0775, true);
$cleanupDirs = [$scratch];
register_shutdown_function(static function () use (&$cleanupDirs) {
    foreach ($cleanupDirs as $d) {
        if (!is_dir($d)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($d);
    }
});

/** A probe job for the engine. */
function rt_job(string $mode, array $args = [], int $interval = 3600, int $timeout = 60): array {
    global $probe;
    return [
        'label' => "probe {$mode}", 'script' => 'tests/_sched_runner_probe.php', 'path' => $probe,
        'exists' => true, 'args' => array_merge([$mode], $args), 'interval_s' => $interval,
        'timeout_s' => $timeout, 'source' => 'test', 'log_success' => false,
    ];
}

/** Run passes until $until(events-so-far) is true or $secs elapse. */
function rt_drive(array $jobs, array &$state, array $opt, float $secs, callable $until): array {
    $all = [];
    $deadline = microtime(true) + $secs;
    do {
        foreach (sched_runner_pass($jobs, $state, $opt) as $e) $all[] = $e;
        if ($until($all, $state)) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    return $all;
}

function rt_events(array $events, string $type, ?string $job = null): array {
    return array_values(array_filter($events, static function ($e) use ($type, $job) {
        return $e['event'] === $type && ($job === null || $e['job'] === $job);
    }));
}

/** Is a process still alive (zombies count as dead)? */
function rt_pid_alive(int $pid): bool {
    if ($pid <= 0) return false;
    if (stripos(PHP_OS, 'WIN') === 0) {
        $out = $GLOBALS['scratch'] . DIRECTORY_SEPARATOR . 'tasklist.txt';
        $p = proc_open(['tasklist', '/FI', 'PID eq ' . $pid, '/NH', '/FO', 'CSV'],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', 'NUL', 'w']], $pp);
        if (is_resource($p)) proc_close($p);
        return strpos((string) @file_get_contents($out), '"' . $pid . '"') !== false;
    }
    $stat = @file_get_contents("/proc/{$pid}/stat");
    if ($stat !== false) {
        $after = substr($stat, strrpos($stat, ')') + 2);
        return ($after[0] ?? 'Z') !== 'Z';
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
}

function rt_kill_pid(int $pid): void {
    if ($pid <= 0) return;
    if (stripos(PHP_OS, 'WIN') === 0) {
        $p = proc_open(['taskkill', '/T', '/F', '/PID', (string) $pid],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pp);
        if (is_resource($p)) proc_close($p);
    } elseif (function_exists('posix_kill')) {
        @posix_kill($pid, 9);
    }
}

/** Run the real CLI to completion with a hard deadline; returns [exit, output]. */
function rt_cli(array $cliArgs, float $limit = 120.0): array {
    global $php, $root, $scratch;
    $out = $scratch . DIRECTORY_SEPARATOR . 'cli-' . bin2hex(random_bytes(4)) . '.out';
    $null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $cmd = array_merge([$php, $root . '/tools/run-scheduled-jobs.php'], $cliArgs);
    $p = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
        $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($p)) return [-1, 'could not start'];
    $t0 = microtime(true);
    $code = -1;
    while (true) {
        $s = proc_get_status($p);
        if (!$s['running']) { $code = (int) $s['exitcode']; break; }
        if (microtime(true) - $t0 > $limit) { rt_kill_pid((int) $s['pid']); break; }
        usleep(100000);
    }
    proc_close($p);
    return [$code, (string) @file_get_contents($out)];
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Pure rules --\n";
rt('a job that has never run is due', sched_runner_is_due(null, 60, 1000));
rt('a job started 30s ago with a 60s interval is not due', !sched_runner_is_due(970, 60, 1000));
rt('a job started exactly one interval ago is due', sched_runner_is_due(940, 60, 1000));
rt('a daily job started 23 hours ago is not due', !sched_runner_is_due(2000000000 - 23 * 3600, 86400, 2000000000));
rt('a daily job started 25 hours ago is due', sched_runner_is_due(2000000000 - 25 * 3600, 86400, 2000000000));
rt('a last start in the future (clock set back) counts as due, not "never again"',
    sched_runner_is_due(1000 + 3600, 60, 1000));
rt('POSIX registry command maps to its script',
    sched_runner_script_from_command('php tools/par_tick.php') === 'tools/par_tick.php');
rt('Windows registry command maps to the same script',
    sched_runner_script_from_command('php tools\\par_tick.php') === 'tools/par_tick.php');
rt('a command with no tools/*.php script maps to nothing',
    sched_runner_script_from_command('systemctl start x') === null);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The job table --\n";
$reg  = sched_job_registry();
$jobs = sched_runner_jobs([], $root);
rt('the registry is not empty', count($reg) >= 9, 'count=' . count($reg));

$guard = "if (PHP_SAPI !== 'cli')";
foreach ($reg as $key => $def) {
    rt("registry job {$key} is in the runner's job table", isset($jobs[$key]));
    if (!isset($jobs[$key])) continue;
    $j = $jobs[$key];
    rt("{$key}: its script exists ({$j['script']})", !empty($j['exists']));
    rt("{$key}: runs at the registry's interval", (int) $j['interval_s'] === (int) $def['interval_s']);
    rt("{$key}: has a timeout longer than nothing", (int) $j['timeout_s'] >= 60);
    $src = !empty($j['exists']) ? (string) file_get_contents($j['path']) : '';
    rt("{$key}: the script refuses to run over HTTP", strpos($src, $guard) !== false);
}

foreach (['backup' => 'tools/backup_run.php', 'expire_grants' => 'tools/expire_grants.php',
          'weather_poll' => 'tools/weather_poll.php',
          'matrix_regulatory_audit' => 'tools/matrix_regulatory_audit_tick.php'] as $key => $script) {
    rt("runner-only job {$key} is in the table", isset($jobs[$key]) && $jobs[$key]['script'] === $script);
    rt("{$key}: its script exists", !empty($jobs[$key]['exists']));
}
rt('backups run hourly (the job applies the configured backup interval itself)',
    (int) ($jobs['backup']['interval_s'] ?? 0) === 3600);
rt('hourly-or-slower jobs log their successful output (a backup result is worth keeping)',
    !empty($jobs['backup']['log_success']) && !empty($jobs['audit_log_purge']['log_success']));
rt('the 15-second inbound-call sweep does not flood the log with successes',
    empty($jobs['inbound_calls_tick']['log_success']));

// Every tick script must be reachable by some job — a new *_tick.php added
// to tools/ without a registry entry (or a runner entry) fails here, which is
// how this whole report happened: jobs that existed and that nothing ran.
$covered = [];
foreach ($jobs as $j) { if ($j['script']) $covered[basename($j['script'])] = true; }
foreach (glob($root . '/tools/*_tick.php') ?: [] as $tick) {
    rt('tick script ' . basename($tick) . ' is run by the runner', isset($covered[basename($tick)]));
}

rt('optional jobs are NOT run unless asked for', !isset($jobs['aprs_poller']));
$withAprs = sched_runner_jobs(['aprs_poller'], $root);
rt('--with=aprs_poller adds the aprs.fi poller', isset($withAprs['aprs_poller'])
    && $withAprs['aprs_poller']['source'] === 'optional' && !empty($withAprs['aprs_poller']['exists']));
rt('every job key is safe as a file name (lock and state files)',
    count(array_filter(array_keys($jobs), static function ($k) { return !preg_match('/^[a-z0-9_]+$/', $k); })) === 0);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The engine (a probe script stands in for the jobs) --\n";
$stateDir = $scratch . DIRECTORY_SEPARATOR . 'state';
@mkdir($stateDir, 0775, true);
$opt = ['root' => $root, 'php' => $php, 'state_dir' => $stateDir, 'max_parallel' => 6, 'max_starts' => 3];

// 3a. Due, run, not due again, due after an interval.
$marks = $scratch . DIRECTORY_SEPARATOR . 'marks.txt';
$j1 = ['m' => rt_job('mark', [$marks], 3600)];
$st = sched_runner_new_state(null);
$ev = rt_drive($j1, $st, $opt, 15, static function ($a) { return rt_events($a, 'ok') || rt_events($a, 'failed'); });
rt('a due job is started and succeeds', count(rt_events($ev, 'ok', 'm')) === 1, json_encode($ev));
rt('it actually ran (the probe wrote its mark)', count(file($marks) ?: []) === 1);
rt('its start time is recorded in the state directory', sched_runner_last_start($stateDir, 'm') !== null);
rt('the lock is released when it finishes', is_resource($lk = sched_runner_lock($stateDir, 'm')));
sched_runner_unlock($lk);

// A NEW runner (a restarted container) shares the state directory, so it
// resumes the schedule instead of firing the job again immediately.
$fresh = sched_runner_new_state(null);
$ev4 = sched_runner_pass($j1, $fresh, $opt);
rt('a restarted runner reads the shared last-start and does not re-run a job that just ran',
    rt_events($ev4, 'started') === []);

$ev2 = sched_runner_pass($j1, $st, $opt, time() + 10);
rt('ten seconds later it is NOT started again (interval 3600s)', rt_events($ev2, 'started') === []);
$ev3 = sched_runner_pass($j1, $st, $opt, time() + 3601);
rt('one interval later it is started again', count(rt_events($ev3, 'started', 'm')) === 1);
// Reap only: the pass above was told the time is an hour ahead, so a
// start-enabled pass at the real time would see a future last-start.
rt_drive($j1, $st, $opt + ['start' => false], 15, static function ($a, $s) { return empty($s['running']); });
rt('and ran a second time', count(file($marks) ?: []) === 2);

// 3b. Never two copies of the same job — within this runner...
$jSlow = ['s' => rt_job('sleep', ['3'], 1, 60)];
$st = sched_runner_new_state(null);
$a = sched_runner_pass($jSlow, $st, $opt);
rt('a slow job starts', count(rt_events($a, 'started', 's')) === 1);
$b = sched_runner_pass($jSlow, $st, $opt, time() + 100);
rt('while it is still running, a pass that finds it due again does NOT start a second copy',
    rt_events($b, 'started') === [] && count($st['running']) === 1);
rt_drive($jSlow, $st, $opt, 20, static function ($all, $s) { return empty($s['running']); });

// ...and across runners: another runner holding the job's lock.
$held = sched_runner_lock($stateDir, 'x');
rt('a test can take a job lock (as another runner would)', is_resource($held));
rt('a second attempt at the same lock reports "busy", not "acquired"', sched_runner_lock($stateDir, 'x') === false);
$jx = ['x' => rt_job('ok', [], 60)];
$st = sched_runner_new_state(null);
$c = sched_runner_pass($jx, $st, $opt);
rt('a job running in ANOTHER runner is skipped, not started twice', count(rt_events($c, 'busy', 'x')) === 1
    && rt_events($c, 'started') === []);
$c2 = sched_runner_pass($jx, $st, $opt, time() + 1);
rt('and the runner does not knock on the lock every second after that', rt_events($c2, 'busy') === []);
sched_runner_unlock($held);
$c3 = sched_runner_pass($jx, $st, $opt, time() + 61);
rt('once the other runner lets go, the job runs at its next interval', count(rt_events($c3, 'started', 'x')) === 1);
rt_drive($jx, $st, $opt, 15, static function ($all, $s) { return empty($s['running']); });

// 3c. One job's failure does not affect another's run, and output is captured.
$mixed = ['bad' => rt_job('fail', [], 60), 'good' => rt_job('ok', ['all fine'], 60)];
$st = sched_runner_new_state(null);
$ev = rt_drive($mixed, $st, $opt, 20, static function ($a, $s) { return empty($s['running']) && count($a) >= 4; });
$bad  = rt_events($ev, 'failed', 'bad');
$good = rt_events($ev, 'ok', 'good');
rt('a failing job is reported as failed with its exit code', count($bad) === 1 && $bad[0]['exit'] === 3,
    json_encode($bad));
rt('its stderr is captured for the log', $bad && strpos($bad[0]['output'], 'deliberate failure') !== false);
rt('its stdout is captured too', $bad && strpos($bad[0]['output'], 'before failing') !== false);
rt('the job started beside it still succeeds', count($good) === 1 && strpos($good[0]['output'], 'all fine') !== false);
rt('the failing job is retried at its next interval, not in a tight loop',
    rt_events(sched_runner_pass($mixed, $st, $opt, time() + 5), 'started') === []);

// 3d. A hung job is killed after its timeout — with the process it started.
$pidFile = $scratch . DIRECTORY_SEPARATOR . 'grandchild.pid';
$jHang = ['hang' => rt_job('tree', [$pidFile], 3600, 3)];
$st = sched_runner_new_state(null);
$t0 = microtime(true);
$ev = rt_drive($jHang, $st, $opt, 40, static function ($a) { return (bool) rt_events($a, 'timeout'); });
$took = microtime(true) - $t0;
$gpid = (int) @file_get_contents($pidFile);
rt('a job past its timeout is reported as timed out', count(rt_events($ev, 'timeout', 'hang')) === 1, json_encode($ev));
rt('it was killed promptly after the 3s timeout (not left to run 120s)', $took < 25, sprintf('%.1fs', $took));
rt('the hung job had started a child process (the probe recorded its pid)', $gpid > 0);
$gone = false;
for ($i = 0; $i < 30 && !$gone; $i++) { $gone = !rt_pid_alive($gpid); if (!$gone) usleep(200000); }
rt('the CHILD of the hung job was killed too (the whole tree, not one process)', $gone, "pid {$gpid}");
if (!$gone) rt_kill_pid($gpid);
rt('nothing is left running in the runner', empty($st['running']));
rt('the timed-out job\'s lock was released', is_resource($lk = sched_runner_lock($stateDir, 'hang')));
sched_runner_unlock($lk);

// 3e. A job whose script is missing is reported once and never spawned.
$jMissing = ['gone' => ['label' => 'gone', 'script' => 'tools/no_such_tick.php', 'path' => $root . '/tools/no_such_tick.php',
    'exists' => false, 'args' => [], 'interval_s' => 60, 'timeout_s' => 60, 'source' => 'test', 'log_success' => false]];
$st = sched_runner_new_state(null);
$m1 = sched_runner_pass($jMissing, $st, $opt);
$m2 = sched_runner_pass($jMissing, $st, $opt, time() + 120);
rt('a missing script is reported', count(rt_events($m1, 'missing', 'gone')) === 1);
rt('and only once, not every interval', rt_events($m2, 'missing') === [] && empty($st['running']));

// 3f. The start cap spreads a cold start; --once runs each due job exactly once.
$five = [];
foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $k) $five[$k] = rt_job('ok', [$k], 60);
$st = sched_runner_new_state(array_fill_keys(array_keys($five), true));
$first = sched_runner_pass($five, $st, $opt + ['max_starts' => 3]);
rt('no more than max_starts jobs are started in one pass', count(rt_events($first, 'started')) === 3);
$ev = rt_drive($five, $st, $opt + ['max_starts' => 3], 20, static function ($a, $s) { return sched_runner_once_done($s); });
rt('a --once run finishes every due job and then has nothing left', sched_runner_once_done($st));
rt('each of the five ran exactly once',
    count(rt_events(array_merge($first, $ev), 'ok')) === 5);

$dueDir = $scratch . DIRECTORY_SEPARATOR . 'due';
@mkdir($dueDir, 0775, true);
sched_runner_mark_start($dueDir, 'recent', time() - 10);
sched_runner_mark_start($dueDir, 'old', time() - 4000);
$dueSet = sched_runner_due_set(['recent' => rt_job('ok', [], 3600), 'old' => rt_job('ok', [], 3600),
    'never' => rt_job('ok', [], 3600)], $dueDir, time());
rt('--once picks only the jobs that are due', isset($dueSet['old'], $dueSet['never']) && !isset($dueSet['recent']));
$forced = sched_runner_due_set(['recent' => rt_job('ok', [], 3600)], $dueDir, time(), true);
rt('--force picks every job', isset($forced['recent']));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. The CLI --\n";
$cliState = $scratch . DIRECTORY_SEPARATOR . 'cli-state';
@mkdir($cliState, 0775, true);

[$code, $out] = rt_cli(['--list', '--state-dir=' . $cliState]);
rt('--list exits 0', $code === 0, $out);
$missing = [];
foreach (array_keys($reg) as $k) { if (strpos($out, $k) === false) $missing[] = $k; }
rt('--list names every registered job', $missing === [], implode(',', $missing));

[$code, $out] = rt_cli(['--once', '--only=no_such_job', '--state-dir=' . $cliState]);
rt('an unknown --only job is refused (exit 2), not silently ignored', $code === 2, "exit {$code}: {$out}");
[$code, $out] = rt_cli(['--once', '--with=no_such_job', '--state-dir=' . $cliState]);
rt('an unknown --with job is refused (exit 2)', $code === 2, "exit {$code}");
[$code, $out] = rt_cli(['--state-dir=' . $cliState]);
rt('with neither --once nor --loop it prints usage and exits 2', $code === 2 && stripos($out, 'usage') !== false);

[$code] = rt_cli(['--health', '--state-dir=' . $cliState]);
rt('--health fails when no --loop runner has ever reported in', $code === 1);
sched_runner_touch_alive($cliState, time());
[$code] = rt_cli(['--health', '--state-dir=' . $cliState]);
rt('--health passes when a runner reported in just now', $code === 0);
sched_runner_touch_alive($cliState, time() - 600);
[$code] = rt_cli(['--health', '--state-dir=' . $cliState]);
rt('--health fails when the runner went quiet ten minutes ago', $code === 1);

$haveTable = sched_table_exists();
if (!$haveTable) {
    echo "SKIP: scheduled_job_runs missing — the real --once / --loop heartbeat checks need an installed database\n";
} else {
    // A REAL run through the real CLI. The heartbeat is written by the tick
    // itself, so this proves the runner actually executed it — not that the
    // recorder works. (The backup job is deliberately NOT run here: on a
    // shared developer machine its retention would prune other installs'
    // archives in the shared backup directory.)
    $before = [];
    foreach (['par_tick', 'inbound_calls_tick', 'webhook_retry'] as $k) {
        $before[$k] = (int) (sched_job_last($k)['run_count'] ?? 0);
    }
    [$code, $out] = rt_cli(['--once', '--force', '--only=par_tick,inbound_calls_tick,webhook_retry',
        '--state-dir=' . $cliState, '--verbose']);
    rt('a real --once run exits 0', $code === 0, $out);
    foreach ($before as $k => $n) {
        $after = sched_job_last($k);
        rt("{$k}: the Status-page heartbeat advanced by exactly one",
            (int) ($after['run_count'] ?? 0) === $n + 1, "before {$n}, after " . ($after['run_count'] ?? 'none'));
        rt("{$k}: recorded ok", ($after['last_status'] ?? '') === 'ok', (string) ($after['last_detail'] ?? ''));
    }
    [$code, $out] = rt_cli(['--once', '--only=par_tick,inbound_calls_tick', '--state-dir=' . $cliState, '--verbose']);
    rt('an immediate second --once finds nothing due (state persisted between runs)',
        $code === 0 && strpos($out, 'Nothing is due') !== false, $out);

    // --loop: start it, let it run a job, confirm it is still running, stop it.
    $loopOut   = $scratch . DIRECTORY_SEPARATOR . 'loop.out';
    $loopState = $scratch . DIRECTORY_SEPARATOR . 'loop-state';
    @mkdir($loopState, 0775, true);
    $beforeLoop = (int) (sched_job_last('inbound_calls_tick')['run_count'] ?? 0);
    $null = $isWin ? 'NUL' : '/dev/null';
    $lp = proc_open([$php, $root . '/tools/run-scheduled-jobs.php', '--loop', '--only=inbound_calls_tick',
        '--state-dir=' . $loopState, '--summary-every=0', '--verbose'],
        [0 => ['file', $null, 'r'], 1 => ['file', $loopOut, 'w'], 2 => ['file', $loopOut, 'a']],
        $lpp, $root, null, ['bypass_shell' => true]);
    $lpid = is_resource($lp) ? (int) proc_get_status($lp)['pid'] : 0;
    $ran = false;
    for ($i = 0; $i < 150 && !$ran; $i++) {
        usleep(100000);
        $ran = (int) (sched_job_last('inbound_calls_tick')['run_count'] ?? 0) > $beforeLoop;
    }
    $stillRunning = is_resource($lp) && proc_get_status($lp)['running'];
    $lout = (string) @file_get_contents($loopOut);
    rt('--loop starts and announces itself', strpos($lout, 'scheduler starting') !== false, $lout);
    rt('--loop runs a due job (its heartbeat advanced)', $ran, $lout);
    rt('--loop keeps running after the job finished', $stillRunning);
    usleep(1200000);
    rt('--loop writes the alive marker --health reads', sched_runner_alive_age($loopState, time()) !== null);
    if ($isWin) {
        rt_kill_pid($lpid);
    } elseif (function_exists('posix_kill') && $lpid > 0) {
        @posix_kill($lpid, 15);
    }
    if (is_resource($lp)) { proc_terminate($lp, 9); proc_close($lp); }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
