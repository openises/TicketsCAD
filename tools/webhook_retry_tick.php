<?php
/**
 * Webhook retry tick — runs webhook_process_retries() in a loop.
 *
 * Scheduled via systemd timer (one-minute cadence). Each tick:
 *   1. Dead-letters any failed delivery whose retry has been due for longer
 *      than sched_stale_cutoff_min (default 60 min) — it was missed because
 *      nothing was running this job, and sending it now would announce an
 *      old event as new. The reason is written into the row; an admin can
 *      still replay it. (2026-10-19, with the Docker scheduler.)
 *   2. Processes up to 50 failed deliveries eligible for retry
 *      (exponential backoff: 30s, 60s, 120s, 240s, 480s).
 *   3. Marks any delivery whose attempt count has reached the
 *      subscription's max_attempts as 'dead_letter' (terminal state;
 *      requires admin replay via api/webhooks.php action=replay).
 *
 * Output is structured for journalctl readability. Every run records a
 * heartbeat (sched_job_record('webhook_retry')) for Settings → System Health.
 *
 * Phase 94 Stage 5 (2026-06-27) — was the missing piece that made
 * a beta tester's "webhooks don't fire reliably" symptom show as
 * "first attempt fails, then nothing ever retries." Now retries
 * happen automatically; dead-letter state is reachable.
 *
 * Install:
 *   sudo cp tools/newui-webhook-retry.service.example /etc/systemd/system/newui-webhook-retry.service
 *   sudo cp tools/newui-webhook-retry.timer.example   /etc/systemd/system/newui-webhook-retry.timer
 *   (edit the .service paths to match your install)
 *   sudo systemctl daemon-reload
 *   sudo systemctl enable --now newui-webhook-retry.timer
 *
 * Or let tools/run-scheduled-jobs.php run it with every other job (the
 * Docker `scheduler` service and the Windows Task Scheduler entry do).
 *
 * Or via cron (less reliable but simpler):
 *   * * * * * www-data php /var/www/newui/tools/webhook_retry_tick.php >/dev/null 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/webhooks.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$start = microtime(true);
$result = webhook_process_retries();
$elapsedMs = (int) ((microtime(true) - $start) * 1000);

$now = gmdate('Y-m-d\TH:i:s\Z');

if (!empty($result['error'])) {
    fwrite(STDERR, "[{$now}] webhook_retry_tick ERROR: {$result['error']}\n");
    sched_job_record('webhook_retry', 'error', (string) $result['error'], $elapsedMs);
    exit(1);
}

$retried = (int) ($result['retried'] ?? 0);
$dead    = (int) ($result['dead_lettered'] ?? 0);
$expired = (int) ($result['expired'] ?? 0);

sched_job_record('webhook_retry', 'ok',
    "retried {$retried}; dead-lettered {$dead} out of attempts; expired {$expired} past the stale-work cutoff",
    $elapsedMs);

if ($retried === 0 && $dead === 0 && $expired === 0) {
    // Idle tick — log nothing to keep journals clean. Operators who
    // want to confirm the job is alive can read the "Webhook delivery
    // retries" row on Settings → System Health, or check
    // `systemctl list-timers newui-webhook-retry.timer`.
    exit(0);
}

echo "[{$now}] webhook_retry_tick retried={$retried} dead_lettered={$dead} expired={$expired} elapsed_ms={$elapsedMs}\n";
exit(0);
