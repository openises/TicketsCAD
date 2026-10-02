<?php
/**
 * Phase 152 prerequisite #6 — route-expiry WARNING tick.
 *
 * This is bookkeeping only, not enforcement. The matrix service itself
 * (services/audio-matrix/matrix_core.py's Route.is_expired(), checked
 * fresh on every mix tick) already refuses to relay an expired route's
 * audio completely independent of whether this script has ever run — see
 * inc/matrix-routes.php's own docblock for the full "read-time
 * enforcement, sweep only closes the audit record" reasoning (this
 * project's third application of that Phase 143 pattern within this one
 * phase alone: see also tools/matrix_regulatory_audit_tick.php).
 *
 * This job's ONLY job is to give a dispatcher advance notice before that
 * happens: for every enabled comm_routes row with an expires_at inside
 * the configured lead window (setting `matrix_expiry_warning_lead_secs`,
 * default 300s = 5 min) that hasn't already been warned about, fire a
 * `comm:route_expiring` SSE event and stamp `warned_at` so it fires
 * EXACTLY ONCE per approaching deadline — a fresh renewal
 * (matrix_route_renew()/matrix_route_update() with a new expires_at)
 * clears warned_at again, re-arming the warning for the new deadline.
 *
 * NEVER disables a route, never changes expires_at, never deletes
 * anything — a missed or delayed warning (job wasn't running, SSE had no
 * subscribers) does not change whether the route keeps mixing; that is
 * decided solely by matrix_core.py reading expires_at itself.
 *
 * Console UI (countdown chip + one-click renew) shipped with the Console
 * rebuild (console.php's patch rail) -- this tick + api/matrix.php's
 * `renew` action are the backend it calls.
 *
 * Registered in inc/scheduled-jobs.php's required-jobs registry as of
 * 2026-09-08 (a net-control persona review flagged that a live
 * cross-class bridge could go dead mid-net with only the console's own
 * countdown chip as warning, and no advance SSE notice, because this tick
 * had never actually been wired in). It originally wasn't, for the same
 * reason tools/matrix_regulatory_audit_tick.php still isn't: the
 * audio-matrix service had no deploy story on any install. It does now
 * (services/audio-matrix/install.sh, same phase) -- see docs/MAINTENANCE-
 * RUNBOOK.md for the systemd timer to install (every 60 seconds, matching
 * this file's own cadence choice below).
 *
 * Usage: php tools/matrix_expiry_warning_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');
$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'comm_routes']
    );
    if ((int) $exists !== 1) {
        $detail = 'comm_routes table not present -- nothing to warn about';
        echo "[{$ts}] matrix_expiry_warning: {$detail}\n";
        sched_job_record('matrix_expiry_warning', 'ok', $detail, (int) round((microtime(true) - $t0) * 1000));
        exit(0);
    }

    $leadSecs = (int) get_variable('matrix_expiry_warning_lead_secs');
    if ($leadSecs <= 0) { $leadSecs = 300; }

    $rows = db_fetch_all(
        "SELECT r.id, r.expires_at, r.operator_callsign,
                sc.label AS src_label, dc.label AS dst_label
           FROM `{$prefix}comm_routes` r
           JOIN `{$prefix}comm_channels` sc ON sc.id = r.src_channel_id
           JOIN `{$prefix}comm_channels` dc ON dc.id = r.dst_channel_id
          WHERE r.enabled = 1
            AND r.expires_at IS NOT NULL
            AND r.warned_at IS NULL
            AND r.expires_at > NOW()
            AND r.expires_at <= DATE_ADD(NOW(), INTERVAL ? SECOND)",
        [$leadSecs]
    );

    $warned = 0;
    foreach ($rows as $row) {
        $ok = sse_publish('comm:route_expiring', [
            'route_id'    => (int) $row['id'],
            'expires_at'  => $row['expires_at'],
            'src_label'   => $row['src_label'],
            'dst_label'   => $row['dst_label'],
            'callsign'    => $row['operator_callsign'],
        ], null, 'admin');
        // Stamp warned_at regardless of the publish outcome -- a missing
        // SSE subscriber right now must not mean this route gets warned
        // about again on every subsequent tick until the deadline; the
        // console can still poll api/matrix.php on load and see the real
        // expires_at directly.
        db_query("UPDATE `{$prefix}comm_routes` SET warned_at = NOW() WHERE id = ?", [(int) $row['id']]);
        if ($ok) { $warned++; }
    }

    $detail = 'considered=' . count($rows) . " warned={$warned} lead_secs={$leadSecs}";
    echo "[{$ts}] matrix_expiry_warning: {$detail}\n";
    sched_job_record('matrix_expiry_warning', 'ok', $detail, (int) round((microtime(true) - $t0) * 1000));
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[{$ts}] matrix_expiry_warning FAILED: {$msg}\n");
    sched_job_record('matrix_expiry_warning', 'error', $msg, (int) round((microtime(true) - $t0) * 1000));
    exit(1);
}
