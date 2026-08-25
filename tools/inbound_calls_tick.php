<?php
/**
 * Phase 149 (2026-08-22) — inbound SIP/PBX call sweep tick.
 *
 * Runs every 15s (plan.md §4's own claim-heartbeat cadence). Two
 * independent, idempotent sweeps:
 *   - inbound_calls_wrapup_sweep() (Milestone 6): folds any 'wrapup' row
 *     whose deadline (ended_at + trunk.wrapup_seconds) has passed into
 *     'ended'.
 *   - inbound_calls_staleness_sweep() (Milestone 7): flags a 'claimed'
 *     row whose claim_heartbeat_at has gone quiet as stale_since — never
 *     auto-releases the claim (plan.md §4).
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST. Neither
 * your-server.example.com nor your-server has a cron daemon --
 * see CLAUDE.md, "A file in /etc/cron.d on a host with NO cron daemon
 * fails completely silently" (this project's own Phase 127 lesson, hit
 * for real on par_tick.php/pending_messages_tick.php). Use a systemd
 * timer:
 *
 *   sudo systemctl enable --now ticketscad-inbound-calls-tick.timer
 *
 * On Windows (IIS), Task Scheduler (see docs/INSTALL-WINDOWS-IIS.md's
 * general pattern for the other tick scripts).
 *
 * Settings → System Health → Scheduled Jobs shows the last run and turns
 * red if this stops -- but only once at least one enabled trunk exists
 * (inc/scheduled-jobs.php's sched_job_required('inbound_calls_tick')).
 *
 * Usage: php tools/inbound_calls_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
// Same class of gap as api/inbound-calls.php (see that file's comment,
// found live 2026-08-22): without inc/sse.php loaded here,
// inbound_calls_wrapup_sweep()'s 'call:ended' publish and
// inbound_calls_staleness_sweep()'s 'call:stale' publish both silently
// no-op via _p149_sse()'s function_exists('sse_publish_for_call') guard --
// the DB columns (state, stale_since) updated correctly, but no logged-in
// dispatcher ever saw a live stale badge or a live end-of-wrapup update.
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/inbound-calls.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $wrapup = inbound_calls_wrapup_sweep();
    $stale  = inbound_calls_staleness_sweep();
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $detail = sprintf(
        'folded %d wrapup call(s) to ended; flagged %d claim(s) stale',
        (int) ($wrapup['folded'] ?? 0),
        (int) ($stale['found'] ?? 0)
    );

    if (($wrapup['ok'] ?? false) && ($stale['ok'] ?? false)) {
        echo "[{$ts}] inbound_calls_tick: {$detail}\n";
        sched_job_record('inbound_calls_tick', 'ok', $detail, $ms);
        exit(0);
    }

    fwrite(STDERR, "[{$ts}] inbound_calls_tick FAILED: {$detail}\n");
    sched_job_record('inbound_calls_tick', 'error', $detail, $ms);
    exit(1);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    fwrite(STDERR, "[{$ts}] inbound_calls_tick FAILED: {$msg}\n");
    sched_job_record('inbound_calls_tick', 'error', $msg, $ms);
    exit(1);
}
