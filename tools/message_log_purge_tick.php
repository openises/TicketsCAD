<?php
/**
 * GH#42 (2026-08-13) — message log retention scheduled tick.
 *
 * Run once a day. Deletes `messages` rows (excluding local_chat) older than
 * `message_log_retention_days`, if that setting is nonzero. A no-op, exit 0,
 * when retention is disabled. See inc/message-log-retention.php.
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST (see
 * tools/audit_log_purge_tick.php's docblock and CLAUDE.md, "A file in
 * /etc/cron.d on a host with NO cron daemon fails completely silently").
 *
 *   sudo systemctl enable --now ticketscad-message-log-purge.timer
 *
 * Settings → System Health → Scheduled Jobs shows the last run and turns red
 * if this job stops -- but only once retention is actually turned on.
 *
 * Usage: php tools/message_log_purge_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/message-log-retention.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $r = message_log_purge_run();
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($r['ok']) {
        echo "[{$ts}] message_log_purge_tick: {$r['detail']}\n";
        sched_job_record('message_log_purge', 'ok', $r['detail'], $ms);
        exit(0);
    }

    fwrite(STDERR, "[{$ts}] message_log_purge_tick FAILED: {$r['detail']}\n");
    sched_job_record('message_log_purge', 'error', $r['detail'], $ms);
    exit(1);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    fwrite(STDERR, "[{$ts}] message_log_purge_tick FAILED: {$msg}\n");
    sched_job_record('message_log_purge', 'error', $msg, $ms);
    exit(1);
}
