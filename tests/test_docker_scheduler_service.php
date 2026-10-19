<?php
/**
 * The Docker deployment runs the scheduled background jobs.
 *
 * Reported 2026-10-17 on the project's Google Group, by an operator running
 * TicketsCAD in Docker under Coolify with the 3CX bridge: "In Docker nothing
 * runs it, so inbound call records keep piling up." Dockerfile,
 * docker-entrypoint.sh and docker-compose.yml contained no scheduler of any
 * kind — no cron, no loop, no sidecar — so every background job had never run
 * on any Docker install.
 *
 * This is a static gate on the shipped files (CI has no Docker daemon). The
 * behaviour itself — the scheduler service starting, waiting for the install,
 * running each job on its cadence, surviving a failing job and taking a backup
 * — was verified on a real Docker host (2026-10-19; the steps and results are
 * in the commit message that added this file). What this file keeps true is
 * the SHAPE that made that work:
 *   - a `scheduler` service exists, built from the SAME image as `app` (no new
 *     image to publish or pull), restarts unless stopped, and runs
 *     tools/run-scheduled-jobs.php --loop;
 *   - it never runs the install/migrate entrypoint (two containers migrating
 *     at once corrupts the install);
 *   - it waits for a healthy database, uses the same DB settings as `app`, and
 *     runs as the web server's user;
 *   - it mounts EVERY volume `app` mounts, at the same paths — a job writes
 *     where the app writes (a backup to app_backups), and the per-job locks
 *     live in app_cache, which both containers must share;
 *   - the image tells the app it is in Docker, so the Status page's advice
 *     names the scheduler service rather than systemctl;
 *   - the docs say how to see it, and how Coolify-style platforms run it.
 *
 * Run: php tests/test_docker_scheduler_service.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$pass = 0;
$fail = 0;
function dsc(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$label}\n"; }
    else       { $fail++; echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "=== Docker: the scheduler service ===\n\n";

$root    = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$compose = str_replace("\r\n", "\n", (string) @file_get_contents($root . '/docker-compose.yml'));
$docker  = (string) @file_get_contents($root . '/Dockerfile');
dsc('docker-compose.yml exists', $compose !== '');

/** The text of one service block under `services:` (2-space indented keys). */
function dsc_service(string $compose, string $name): string {
    if (!preg_match('/\n  ' . preg_quote($name, '/') . ":\n(.*?)(?=\n  [a-z0-9_-]+:\n|\n[a-z]|\z)/s", $compose, $m)) {
        return '';
    }
    // Drop full-line comments so commented-out examples never count.
    return implode("\n", array_filter(explode("\n", $m[1]), static function ($l) {
        return !preg_match('/^\s*#/', $l);
    }));
}

/** Container paths mounted by a service block. */
function dsc_mounts(string $block): array {
    $out = [];
    if (preg_match('/\n    volumes:\n((?:      .*\n?)+)/', "\n" . $block . "\n", $m)) {
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim(explode('#', $line)[0]);
            if ($line === '' || $line[0] !== '-') continue;
            $bits = explode(':', trim(substr($line, 1)));
            if (count($bits) >= 2) $out[$bits[0]] = rtrim($bits[1], '/');
        }
    }
    return $out;
}

$app   = dsc_service($compose, 'app');
$sched = dsc_service($compose, 'scheduler');
dsc('the app service is found', $app !== '');
dsc('a `scheduler` service exists', $sched !== '');

echo "\n-- same image, nothing new to publish --\n";
preg_match('/^    image:\s*(\S+)/m', $app, $ai);
preg_match('/^    image:\s*(\S+)/m', $sched, $si);
dsc('the scheduler uses the same image as app', ($si[1] ?? '') !== '' && ($si[1] ?? '') === ($ai[1] ?? ''),
    'app=' . ($ai[1] ?? '?') . ' scheduler=' . ($si[1] ?? '?'));
dsc('...which is built locally (build: .), not a published image', preg_match('/^    build:\s*\.\s*$/m', $sched) === 1);
dsc('it restarts unless stopped', preg_match('/^    restart:\s*unless-stopped\s*$/m', $sched) === 1);
dsc('it publishes no port', strpos($sched, 'ports:') === false);

echo "\n-- what it runs --\n";
preg_match('/^    entrypoint:\s*(.+)$/m', $sched, $ep);
$entry = $ep[1] ?? '';
dsc('it runs tools/run-scheduled-jobs.php --loop', strpos($entry, 'tools/run-scheduled-jobs.php --loop') !== false, $entry);
dsc('it writes config.php from the environment when none is mounted',
    strpos($entry, 'docker-config-gen.php') !== false && strpos($entry, '-f /var/www/html/config.php') !== false);
dsc('it does NOT run the install/migrate entrypoint',
    strpos($entry, 'docker-entrypoint.sh') === false && strpos($entry, 'install_fresh') === false
    && strpos($entry, 'run_migrations') === false);
dsc('the runner script ships', is_file($root . '/tools/run-scheduled-jobs.php'));
dsc('the runner is the exec\'d process (signals reach it)', strpos($entry, 'exec php') !== false);
dsc('init: true (forwards stop signals, reaps job processes)', preg_match('/^    init:\s*true\s*$/m', $sched) === 1);
dsc('a healthcheck asks the runner whether it is alive',
    strpos($sched, 'healthcheck:') !== false && strpos($sched, '--health') !== false);

echo "\n-- database and identity --\n";
dsc('it waits for a healthy database',
    preg_match('/depends_on:\s*\n\s+db:\s*\n\s+condition:\s*service_healthy/', $sched) === 1);
foreach (['NEWUI_DB_HOST', 'NEWUI_DB_NAME', 'NEWUI_DB_USER', 'NEWUI_DB_PASS', 'NEWUI_BASE_URL'] as $v) {
    preg_match('/^      ' . $v . ':\s*(.+)$/m', $app, $av);
    preg_match('/^      ' . $v . ':\s*(.+)$/m', $sched, $sv);
    dsc("{$v} matches the app service", isset($sv[1]) && trim($sv[1]) === trim($av[1] ?? ''),
        'app=' . ($av[1] ?? '?') . ' scheduler=' . ($sv[1] ?? '?'));
}
dsc('it runs as www-data (files the jobs write belong to the web server user)',
    preg_match('/^    user:\s*www-data\s*$/m', $sched) === 1);

echo "\n-- volumes: the jobs see what the app sees --\n";
$appMounts   = dsc_mounts($app);
$schedMounts = dsc_mounts($sched);
dsc('parsed the app service\'s volumes', count($appMounts) >= 5, json_encode($appMounts));
foreach ($appMounts as $vol => $path) {
    dsc("scheduler mounts {$vol} at {$path}", ($schedMounts[$vol] ?? null) === $path,
        'scheduler mounts: ' . json_encode($schedMounts));
}
dsc('backups in particular (the backup job writes there)', in_array('/var/www/backups', $schedMounts, true));
dsc('the cache volume in particular (per-job locks shared with the app container)',
    ($schedMounts['app_cache'] ?? '') === '/var/www/html/cache');

echo "\n-- the app knows it is in Docker --\n";
dsc('the Dockerfile sets TICKETSCAD_RUNTIME=docker', preg_match('/^ENV TICKETSCAD_RUNTIME=docker\s*$/m', $docker) === 1);
$remedy = sched_jobs_remedy('docker');
dsc('the Docker remedy names the scheduler service', stripos($remedy, 'scheduler') !== false && stripos($remedy, 'docker compose') !== false);
dsc('the Docker remedy does not tell anyone to run systemctl', stripos($remedy, 'systemctl') === false);
dsc('the Docker remedy gives the Coolify-style alternative', strpos($remedy, '--once') !== false);
dsc('sched_unit_kind() reports docker when TICKETSCAD_RUNTIME=docker', (static function () {
    $old = getenv('TICKETSCAD_RUNTIME');
    putenv('TICKETSCAD_RUNTIME=docker');
    $k = sched_unit_kind();
    putenv($old === false ? 'TICKETSCAD_RUNTIME' : 'TICKETSCAD_RUNTIME=' . $old);
    return sched_is_windows() ? $k === 'schtasks' : $k === 'docker';
})());
dsc('...and an explicit non-docker value is not mistaken for Docker', (static function () {
    $old = getenv('TICKETSCAD_RUNTIME');
    putenv('TICKETSCAD_RUNTIME=host');
    $d = sched_is_docker();
    putenv($old === false ? 'TICKETSCAD_RUNTIME' : 'TICKETSCAD_RUNTIME=' . $old);
    return $d === false;
})());

echo "\n-- documentation --\n";
$doc = (string) @file_get_contents($root . '/docs/DOCKER.md');
dsc('docs/DOCKER.md has a Background jobs section', stripos($doc, '## ') !== false && stripos($doc, 'Background jobs') !== false);
dsc('...that shows how to read the scheduler\'s log', strpos($doc, 'docker compose logs') !== false && strpos($doc, 'scheduler') !== false);
dsc('...and how a Coolify-style platform runs the jobs', stripos($doc, 'Coolify') !== false
    && strpos($doc, 'run-scheduled-jobs.php --once') !== false);
$runbook = (string) @file_get_contents($root . '/docs/MAINTENANCE-RUNBOOK.md');
dsc('docs/MAINTENANCE-RUNBOOK.md mentions the single runner', strpos($runbook, 'run-scheduled-jobs.php') !== false);

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
