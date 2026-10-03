<?php
/**
 * Phase 155 (S0, GH#141/GH#147) — scheduled-incident activation tick.
 *
 * Runs every 60s. Two independent, idempotent steps:
 *
 *   1. incident_activate_due_scheduled(): every Scheduled incident (status 3)
 *      whose booked time has arrived becomes Open (status 2) — atomically,
 *      exactly once, whichever process gets there first. This is what makes a
 *      booked time mean something when NOBODY has a dispatch board open (an
 *      External API consumer, a crew's phone, a dispatcher on incident-detail
 *      all used to see a stale "Scheduled" until someone opened the incident
 *      list). The lazy hook in api/incidents.php still calls the very same
 *      function, so an install with no timer behaves as it always did.
 *
 *   2. assign_reservations_promote_due(): when "Units assigned to Scheduled
 *      incidents" is set to reserve (Settings → Incident Lifecycle), a unit
 *      reserved for a booked call is dispatched when the call activates, or
 *      `scheduled_assign_lead_minutes` BEFORE it. A no-op when nothing is
 *      reserved, and on an install without the reservation table.
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST. Neither
 * your-server.example.com nor your-server has a cron daemon (see
 * CLAUDE.md, "A file in /etc/cron.d on a host with NO cron daemon fails
 * completely silently"). Use a systemd timer:
 *
 *   sudo systemctl enable --now ticketscad-scheduled-incidents.timer
 *
 * (unit files are in docs/MAINTENANCE-RUNBOOK.md). On Windows (IIS) it is
 * invoked by tools\run-scheduled-jobs.bat, the single Task Scheduler entry.
 *
 * Settings → System Health → Scheduled background jobs shows the last run and turns
 * red if this stops — but only once something is actually about to need it
 * (inc/scheduled-jobs.php's sched_job_required('scheduled_incidents_tick')):
 * a Scheduled incident booked within the next 24 hours, or a pending
 * reservation. An incident booked three months out must not make the Status
 * page red for three months.
 *
 * Usage: php tools/scheduled_incidents_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
// Without inc/sse.php loaded the announcement of an activation silently
// no-ops (the same class of gap found live in tools/inbound_calls_tick.php).
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/scheduled-incidents.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
if (is_file(__DIR__ . '/../inc/assign-reservations.php')) {
    require_once __DIR__ . '/../inc/assign-reservations.php';
}

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $act = incident_activate_due_scheduled(100);

    $due = ['promoted' => 0, 'blocked' => 0, 'checked' => 0];
    if (function_exists('assign_reservations_promote_due')) {
        $due = assign_reservations_promote_due(100) + $due;
    }

    $ms = (int) round((microtime(true) - $t0) * 1000);
    $detail = sprintf(
        'activated %d scheduled incident(s); reservations promoted %d (including %d at activation), held for a dispatcher %d',
        (int) $act['activated'],
        (int) $due['promoted'] + (int) $act['promoted'],
        (int) $act['promoted'],
        (int) $due['blocked'] + (int) $act['blocked']
    );
    echo "[{$ts}] scheduled_incidents_tick: {$detail}\n";
    sched_job_record('scheduled_incidents_tick', 'ok', $detail, $ms);
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    fwrite(STDERR, "[{$ts}] scheduled_incidents_tick FAILED: {$msg}\n");
    sched_job_record('scheduled_incidents_tick', 'error', $msg, $ms);
    exit(1);
}
