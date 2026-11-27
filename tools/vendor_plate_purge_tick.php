<?php
/**
 * GH#148 — towing / roadside plate-retention scheduled tick.
 *
 * Run once a day. Clears the licence-plate + vehicle-identifying fields
 * (vehicle_desc, plate, plate_state) on `vendor_dispatches` rows older than
 * `vendor_plate_retention_days`, if that setting is nonzero. The dispatch row
 * and the whole append-only ledger are left intact. A no-op, exit 0, when
 * retention is disabled (the default) — see inc/vendor-plate-retention.php for
 * the full model.
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST. This project has
 * twice shipped a background job as a cron/cron.d drop-in onto hosts with no
 * cron daemon at all, and it failed completely silently both times (CLAUDE.md,
 * "A file in /etc/cron.d on a host with NO cron daemon fails completely
 * silently"). Verify before relying on anything:
 *
 *   systemctl is-active cron                                # "not-found" => no cron daemon
 *   systemctl list-timers --all | grep vendor-plate-purge  # is a timer scheduled instead?
 *
 * On a stock install this job runs from the one portable runner that every
 * platform shares (tools/run-scheduled-jobs.php): a systemd timer on Linux,
 * the "scheduler" service in docker-compose.yml under Docker, and the Windows
 * Task Scheduler entry that runs tools\run-scheduled-jobs.bat — so there is
 * nothing to install by hand beyond whichever of those the install already
 * uses. Settings → System Health → Scheduled Jobs shows the last run and turns
 * red if it stops, but ONLY once retention is actually turned on (an install
 * with retention off is never nagged about a job it does not need — see
 * sched_job_required('vendor_plate_purge') in inc/scheduled-jobs.php).
 *
 * Usage: php tools/vendor_plate_purge_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/vendor-plate-retention.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $r  = vendor_plate_purge_run(['triggered_by' => 'scheduled']);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($r['ok']) {
        echo "[{$ts}] vendor_plate_purge_tick: {$r['detail']}\n";
        sched_job_record('vendor_plate_purge', 'ok', $r['detail'], $ms);
        exit(0);
    }

    $detail = $r['detail'] ?? 'unknown failure';
    fwrite(STDERR, "[{$ts}] vendor_plate_purge_tick FAILED: {$detail}\n");
    sched_job_record('vendor_plate_purge', 'error', $detail, $ms);
    exit(1);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    fwrite(STDERR, "[{$ts}] vendor_plate_purge_tick FAILED: {$msg}\n");
    sched_job_record('vendor_plate_purge', 'error', $msg, $ms);
    exit(1);
}
