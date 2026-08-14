<?php
/**
 * Windows scheduling parity for the background jobs.
 *
 * GH openises/TicketsCAD#18, reported by @rjonesbsink from a Windows 11 / IIS
 * install: inc/scheduled-jobs.php named a systemd timer per job, there was no
 * Windows equivalent shipped or documented, and so on Windows NOTHING ran the
 * two ticks. The Status page correctly flagged it CRITICAL and then told the
 * admin to run `systemctl` — a dead end from the one screen that had got it
 * right. The first manual run cleared 19 expired units and 16 expired cycles
 * that had been accumulating since install: PAR checks were being initiated
 * and never timing out, so a unit that failed to answer was never marked
 * missed.
 *
 * What is asserted here:
 *   1. The registry is platform-aware — a Windows install is told about a Task
 *      Scheduler task, a Linux install about a systemd timer, and neither is
 *      told about the other's.
 *   2. The runners actually ship, invoke EVERY job the registry knows about
 *      (not a hardcoded subset — see the 2026-08-14 addendum below), and do
 *      not carry the two defects the old start-proxy.bat had (a hardcoded
 *      XAMPP path, and `pause`, which makes a script interactive-only and so
 *      useless to Task Scheduler).
 *   3. The docs contain the commands, since "the docs mention Task Scheduler
 *      in passing" was the state that produced this report.
 *
 * The registry assertions drive the REAL sched_job_registry() and
 * sched_jobs_status(); the platform branch that this host is not running is
 * exercised by reading the source, which is called out where it happens.
 *
 * ADDENDUM (2026-08-14): this file originally hardcoded "the registry has
 * par_tick and pending_messages_tick" and "the runner invokes par_tick /
 * pending_messages_tick" — true the day it was written, but audit_log_purge
 * (Phase 133) and message_log_purge (GH#42) were added to the REGISTRY later
 * without ever being added to tools/run-scheduled-jobs.bat, and this test
 * had no way to notice because it only ever checked the two names it already
 * knew about. Section 2 below now derives the expected job list FROM
 * sched_job_registry() itself and asserts the runner invokes every one of
 * them — so a 6th job added to the registry without a matching line in the
 * .bat fails this test immediately, instead of silently shipping broken to
 * every Windows install the way audit/message-log purge did.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

echo "=== Windows scheduling parity (GH #18) ===\n\n";
$pass = 0;
$fail = 0;

function sw_ok(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
}

$root = dirname(__DIR__);

// ──────────────────────────────────────────────────────────────────────
// 1. The registry is platform-aware
// ──────────────────────────────────────────────────────────────────────
echo "-- registry --\n";

sw_ok('sched_is_windows() exists', function_exists('sched_is_windows'));
sw_ok('sched_is_windows() agrees with PHP about this host',
    sched_is_windows() === (PHP_OS_FAMILY === 'Windows'));

$reg = sched_job_registry();
sw_ok('registry still has the original two jobs',
    isset($reg['par_tick'], $reg['pending_messages_tick']), 'keys: ' . implode(',', array_keys($reg)));
sw_ok('registry has grown past the original two (Phase 133/GH#42/Phase 134 jobs present)',
    count($reg) >= 5, 'keys: ' . implode(',', array_keys($reg)));

$isWin = sched_is_windows();
foreach ($reg as $key => $def) {
    sw_ok("{$key} declares a unit", !empty($def['unit']));
    sw_ok("{$key} declares what that unit IS", !empty($def['unit_kind']));
    sw_ok("{$key} unit_kind matches the platform",
        $def['unit_kind'] === ($isWin ? 'schtasks' : 'systemd'),
        "unit_kind={$def['unit_kind']} isWin=" . var_export($isWin, true));

    if ($isWin) {
        // The whole point: a Windows admin must never be handed a systemd
        // unit name to check, because it cannot exist on their machine.
        sw_ok("{$key} does NOT name a systemd timer on Windows",
            strpos($def['unit'], '.timer') === false, "unit={$def['unit']}");
        sw_ok("{$key} command uses Windows path separators",
            strpos($def['command'], 'tools\\') !== false, "command={$def['command']}");
    } else {
        sw_ok("{$key} names a systemd timer on Linux",
            substr($def['unit'], -6) === '.timer', "unit={$def['unit']}");
        sw_ok("{$key} command uses POSIX path separators",
            strpos($def['command'], 'tools/') !== false, "command={$def['command']}");
    }
}

// The status payload must carry the new field through, or a consumer that
// renders it gets an undefined-index notice.
$st = sched_jobs_status();
sw_ok('sched_jobs_status() reports the platform', !empty($st['platform']));
sw_ok('platform is one of the two known values',
    in_array($st['platform'], ['windows', 'unix'], true), "platform={$st['platform']}");
if (!empty($st['jobs'])) {
    foreach ($st['jobs'] as $jb) {
        sw_ok("status row for {$jb['job']} carries unit_kind", !empty($jb['unit_kind']));
        sw_ok("status row for {$jb['job']} still carries unit", !empty($jb['unit']));
    }
}

// The remedy is what an admin actually reads. It must name a scheduler that
// exists on their machine, and must not name one that does not.
echo "\n-- remedy text --\n";
$remedy = (string) ($st['remedy'] ?? '');
sw_ok('a remedy is offered', $remedy !== '');
if ($isWin) {
    sw_ok('Windows remedy names schtasks', stripos($remedy, 'schtasks') !== false, $remedy);
    sw_ok('Windows remedy does NOT tell the admin to run systemctl',
        stripos($remedy, 'systemctl') === false, $remedy);
    sw_ok('Windows remedy points at the Windows guide',
        stripos($remedy, 'INSTALL-WINDOWS-IIS') !== false, $remedy);
} else {
    sw_ok('Unix remedy names systemctl', stripos($remedy, 'systemctl') !== false, $remedy);
    sw_ok('Unix remedy does NOT tell the admin to run schtasks',
        stripos($remedy, 'schtasks') === false, $remedy);
}

// The branch this host is NOT on cannot be executed here, so assert it exists
// in the source. Without this, a one-platform CI run would happily let the
// other platform's branch rot.
$sjSrc = (string) file_get_contents($root . '/inc/scheduled-jobs.php');
sw_ok('both remedy branches exist in the source',
    stripos($sjSrc, 'schtasks') !== false && stripos($sjSrc, 'systemctl') !== false);
sw_ok('both unit vocabularies exist in the source',
    strpos($sjSrc, '.timer') !== false && strpos($sjSrc, 'TicketsCAD Background Jobs') !== false);

// ──────────────────────────────────────────────────────────────────────
// 2. The runners ship, and are usable unattended
// ──────────────────────────────────────────────────────────────────────
echo "\n-- shipped runners --\n";

$jobsBat = $root . '/tools/run-scheduled-jobs.bat';
sw_ok('tools/run-scheduled-jobs.bat ships', is_file($jobsBat));
$jobs = is_file($jobsBat) ? (string) file_get_contents($jobsBat) : '';

// Derive the expected job list from the REGISTRY, not a hardcoded pair --
// this is the whole point of the 2026-08-14 addendum above. Every job's
// command names a script under tools/ (or tools\ on Windows) — the runner
// (a static file, always Windows-syntax on disk regardless of the host this
// TEST happens to run on) must invoke that exact script. sched_job_registry()
// reflects THIS host's platform, so on a Linux CI runner the separator is
// '/', not '\\' — match either.
foreach ($reg as $key => $def) {
    if (!preg_match('~tools[\\\\/](\w+\.php)~', (string) $def['command'], $m)) {
        sw_ok("{$key}'s command names a tools/*.php script (registry sanity)", false,
            "command={$def['command']}");
        continue;
    }
    $script = $m[1];
    sw_ok("job runner invokes {$key} ({$script})", strpos($jobs, $script) !== false);
}

sw_ok('job runner does NOT pause (Task Scheduler has no keyboard)',
    !preg_match('~^\s*pause\s*$~im', $jobs));
sw_ok('job runner does not hardcode a developer XAMPP path',
    stripos($jobs, 'xampp') === false);
sw_ok('job runner allows a PHP override', strpos($jobs, 'TICKETSCAD_PHP') !== false);
sw_ok('job runner cds to the app root before running',
    strpos($jobs, 'cd /d "%~dp0..') !== false);
sw_ok('job runner reports a missing PHP rather than logging nothing forever',
    stripos($jobs, 'FATAL') !== false);
sw_ok('job runner does not chain invocations with && (one failure must not skip the rest)',
    !preg_match('~\.php["\'\s]*&&~', $jobs));
sw_ok('job runner propagates a failure as its exit code',
    strpos($jobs, 'exit /b %RC%') !== false);

$proxyBat = $root . '/proxy/start-proxy.bat';
sw_ok('proxy/start-proxy.bat still ships', is_file($proxyBat));
$px = is_file($proxyBat) ? (string) file_get_contents($proxyBat) : '';
sw_ok('interactive proxy launcher no longer hardcodes C:\\xampp',
    stripos($px, 'C:\\xampp') === false, 'still hardcoded');
sw_ok('interactive proxy launcher allows a PHP override',
    strpos($px, 'TICKETSCAD_PHP') !== false);

$svcBat = $root . '/proxy/start-proxy-service.bat';
sw_ok('proxy/start-proxy-service.bat ships (the unattended counterpart)', is_file($svcBat));
$svc = is_file($svcBat) ? (string) file_get_contents($svcBat) : '';
sw_ok('unattended proxy launcher does NOT pause',
    !preg_match('~^\s*pause\s*$~im', $svc));
sw_ok('unattended proxy launcher restarts on exit (mirrors Restart=on-failure)',
    stripos($svc, 'goto loop') !== false);
sw_ok('unattended proxy launcher redirects to a log',
    strpos($svc, '>>') !== false);
sw_ok('unattended proxy launcher does not hardcode a developer XAMPP path',
    stripos($svc, 'xampp') === false);

// Every .bat we ship must be free of the two defects that made the original
// one unusable. Catches a future addition as well as these three.
echo "\n-- all shipped .bat files --\n";
$bats = array_merge(
    glob($root . '/tools/*.bat') ?: [],
    glob($root . '/proxy/*.bat') ?: []
);
sw_ok('found shipped .bat files to check', count($bats) > 0);
foreach ($bats as $b) {
    $name = basename($b);
    $body = (string) file_get_contents($b);
    sw_ok("{$name} does not hardcode a versioned XAMPP PHP path",
        !preg_match('~[a-z]:\\\\xampp~i', $body));
    // start-proxy.bat is deliberately interactive; everything else must be
    // runnable by a scheduler with no console.
    if ($name !== 'start-proxy.bat') {
        sw_ok("{$name} is usable unattended (no bare pause)",
            !preg_match('~^\s*pause\s*$~im', $body));
    }
}

// ──────────────────────────────────────────────────────────────────────
// 3. The docs carry the commands
// ──────────────────────────────────────────────────────────────────────
echo "\n-- documentation --\n";
$docPath = $root . '/docs/INSTALL-WINDOWS-IIS.md';
sw_ok('docs/INSTALL-WINDOWS-IIS.md exists', is_file($docPath));
$doc = is_file($docPath) ? (string) file_get_contents($docPath) : '';

sw_ok('the guide has a scheduling section', stripos($doc, 'Task Scheduler') !== false);
sw_ok('the guide gives the schtasks command, not just the concept',
    stripos($doc, 'schtasks /Create') !== false);
sw_ok('the guide names the shipped job runner',
    stripos($doc, 'run-scheduled-jobs.bat') !== false);
sw_ok('the guide names the shipped proxy runner',
    stripos($doc, 'start-proxy-service.bat') !== false);
sw_ok('the guide says to verify the task FIRES, not merely that it registered',
    stripos($doc, 'firing') !== false || stripos($doc, 'not merely registered') !== false
    || stripos($doc, 'merely registered') !== false);
sw_ok('the guide warns php-cgi.exe will not work for the CLI jobs',
    stripos($doc, 'php-cgi.exe') !== false);
sw_ok('the guide covers reboot survival for the proxy',
    stripos($doc, 'ONSTART') !== false);

// The FastCGI correction from #8 — the guide credited the reporter for a
// mechanism he later disproved, so this asserts the wrong claim is gone.
sw_ok('the disproved "replaces the inherited environment" claim is gone',
    !preg_match('~collection \*\*replaces\*\*~i', $doc)
    && !preg_match('~\*\*replaces\*\*\s*\n?the environment~i', $doc));
sw_ok('the guide records the pooled php-cgi.exe gotcha instead',
    stripos($doc, 'taskkill /F /IM php-cgi.exe') !== false);
sw_ok('the guide says either OPENSSL_CONF location works alone',
    stripos($doc, 'works on its own') !== false || stripos($doc, 'Either of the two') !== false);

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
