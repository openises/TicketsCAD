<?php
/**
 * Webhook retry tick — runs webhook_process_retries() in a loop.
 *
 * Scheduled via systemd timer (one-minute cadence). Each tick:
 *   1. Processes up to 50 failed deliveries eligible for retry
 *      (exponential backoff: 30s, 60s, 120s, 240s, 480s).
 *   2. Marks any delivery whose attempt count has reached the
 *      subscription's max_attempts as 'dead_letter' (terminal state;
 *      requires admin replay via api/webhooks.php action=replay).
 *
 * Output is structured for journalctl readability.
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
 * Or via cron (less reliable but simpler):
 *   * * * * * www-data php /var/www/newui/tools/webhook_retry_tick.php >/dev/null 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/webhooks.php';

$start = microtime(true);
$result = webhook_process_retries();
$elapsedMs = (int) ((microtime(true) - $start) * 1000);

$now = gmdate('Y-m-d\TH:i:s\Z');

if (!empty($result['error'])) {
    fwrite(STDERR, "[{$now}] webhook_retry_tick ERROR: {$result['error']}\n");
    exit(1);
}

$retried = (int) ($result['retried'] ?? 0);
$dead    = (int) ($result['dead_lettered'] ?? 0);

if ($retried === 0 && $dead === 0) {
    // Idle tick — log nothing to keep journals clean. Operators who
    // want to confirm the timer is alive can check
    // `systemctl list-timers newui-webhook-retry.timer`.
    exit(0);
}

echo "[{$now}] webhook_retry_tick retried={$retried} dead_lettered={$dead} elapsed_ms={$elapsedMs}\n";
exit(0);
