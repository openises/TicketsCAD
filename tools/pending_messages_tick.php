<?php
/**
 * Phase 18e — pending routed-message tick.
 *
 * Run every minute. Each invocation sends any pending message whose
 * scheduled_send_at has passed, and expires — without sending — any whose
 * scheduled time is more than sched_stale_cutoff_min minutes ago
 * (Phase 127; see inc/pending-messages.php for why).
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST
 * -----------------------------------------------------
 * This job was originally shipped as a /etc/cron.d drop-in. Both servers
 * running TicketsCAD turned out to have no cron daemon installed, so it
 * never ran once between 11 June and 29 July 2026 and nothing reported it.
 * A file in /etc/cron.d on a host without cron fails completely silently.
 *
 *   systemctl is-active cron                        # "not-found" => no cron daemon
 *   systemctl list-timers --all | grep pending-msg  # is a timer scheduled instead?
 *   journalctl -u ticketscad-pending-msg -n 20      # what the last runs actually did
 *
 * /var/log/pending_msg_tick.log belonged to the cron line. The timer unit logs
 * to the journal, so that file stays 0 bytes on a healthy host — an empty log
 * is no longer evidence of anything.
 *
 * Prefer a systemd timer, which needs no extra package. Unit files and
 * install steps: docs/MAINTENANCE-RUNBOOK.md, "Scheduled background jobs".
 *
 *   sudo systemctl enable --now ticketscad-pending-msg.timer
 *
 * Whichever you use, Settings → System Health → Scheduled jobs now shows the last
 * successful run and goes red when this job stops.
 *
 * Usage: php tools/pending_messages_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/pending-messages.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/../inc/notification-engine.php';   // notification_log_purge() and the setting it reads (Phase 155)

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $r = pending_sweep();
    $detail = "considered={$r['considered']} sent={$r['sent']} failed={$r['failed']}"
            . ' expired=' . ($r['expired'] ?? 0)
            . ' deferred=' . ($r['deferred'] ?? 0);
    // Since 2026-07-31 this sweep also carries the outbound notifications that
    // used to be sent inside the dispatcher's own request. Report the backlog
    // so `journalctl -u ticketscad-pending-msg` answers "are callouts going
    // out?" without anyone opening a browser.
    if (function_exists('notify_queue_depth')) {
        $q = notify_queue_depth();
        $detail .= ' notify_pending=' . $q['pending'];
        if ($q['oldest_age_s'] !== null) $detail .= ' oldest=' . $q['oldest_age_s'] . 's';
    }
    // Phase 155 (GH#144): Settings -> Notification Rules -> Delivery settings promises
    // "keep the delivery log N days". This is the ONLY caller of the purge, so a
    // setting that nothing here ran would be an inert control. Cheap when there is
    // nothing old (one indexed DELETE ... LIMIT); never touches a delivery still queued.
    $purged = function_exists('notification_log_purge') ? notification_log_purge() : 0;
    if ($purged > 0) $detail .= ' log_purged=' . $purged;
    echo "[{$ts}] pending_sweep: {$detail}\n";
    sched_job_record('pending_messages_tick', 'ok', $detail,
                     (int) round((microtime(true) - $t0) * 1000));
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[{$ts}] pending_sweep FAILED: {$msg}\n");
    sched_job_record('pending_messages_tick', 'error', $msg,
                     (int) round((microtime(true) - $t0) * 1000));
    exit(1);
}
