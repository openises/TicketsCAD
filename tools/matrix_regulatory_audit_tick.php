<?php
/**
 * Phase 152 prerequisite #5 — periodic cross-class regulatory audit tick.
 *
 * Scans the live comm_routes graph for a connected component that mixes
 * an `amateur` channel with a `commercial`/`pstn` one and has NO edge
 * inside it carrying `allow_cross_class=1` — i.e. a mixing nobody ever
 * actually acknowledged. See inc/matrix-routes.php's
 * matrix_regulatory_scan_components() for the full reasoning: every
 * route create/update already re-validates the WHOLE graph on every
 * edit, so a route-level mutation can never silently introduce this. The
 * gap this closes is a CHANNEL's own regulatory_class being changed
 * later (api/channels.php, prerequisite #1) with no comm_routes write at
 * all — which retroactively turns a previously-safe topology into a live
 * cross-class path with nothing in the create/update path ever noticing.
 *
 * NON-AUTHORITATIVE by construction, matching this project's Phase 143
 * "sweep only closes the audit record" convention: this job NEVER
 * disables, deletes, or otherwise mutates a route. It only alerts (a
 * stderr line + health_check_matrix_regulatory(), which the Status page
 * reads). A human decides what a real violation means — undo the
 * reclassification, or explicitly acknowledge the mixing by editing one
 * of the component's routes with the cross-class override.
 *
 * NOT wired into inc/scheduled-jobs.php's "required jobs" registry —
 * unlike PAR/pending-message ticks, this subsystem (the audio-matrix
 * service) has no systemd unit or deploy step on ANY install yet (see
 * CLAUDE.md's Phase 114c entry), so demanding this timer run everywhere
 * would flag every install as missing a job for a feature it doesn't
 * use. Wire it in once the matrix service itself has a real deployment
 * story. Suggested cadence once it does: every 15 minutes (systemd timer,
 * same shape as org_relationship_cleanup_tick.php).
 *
 * Usage: php tools/matrix_regulatory_audit_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/matrix-routes.php';
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
        $detail = 'comm_routes table not present -- nothing to audit';
        echo "[{$ts}] matrix_regulatory_audit: {$detail}\n";
        sched_job_record('matrix_regulatory_audit', 'ok', $detail,
                         (int) round((microtime(true) - $t0) * 1000));
        exit(0);
    }

    $violations = matrix_regulatory_scan_components();

    if (empty($violations)) {
        $detail = 'clean -- no unaudited cross-class components';
        echo "[{$ts}] matrix_regulatory_audit: {$detail}\n";
        sched_job_record('matrix_regulatory_audit', 'ok', $detail,
                         (int) round((microtime(true) - $t0) * 1000));
        exit(0);
    }

    foreach ($violations as $v) {
        $msg = sprintf(
            'UNAUDITED cross-class component: amateur "%s" (#%d) and %s "%s" (#%d) share a live '
            . 'connected component of %d channel(s) with no allow_cross_class=1 edge anywhere in it',
            $v['amateur']['label'], $v['amateur']['id'],
            $v['conflict']['class'], $v['conflict']['label'], $v['conflict']['id'],
            count($v['channel_ids'])
        );
        error_log('[matrix_regulatory_audit_tick] ' . $msg);
        echo "[{$ts}] VIOLATION: {$msg}\n";
    }

    // The scan itself completed successfully -- 'ok' status for the
    // scheduled-job record (a run that FOUND something is still a run
    // that WORKED; the finding itself is reported through
    // health_check_matrix_regulatory(), not through this status field —
    // matches org_relationship_cleanup_tick.php's own $failed-count
    // precedent).
    $detail = count($violations) . ' unaudited cross-class component(s) found -- see System Health';
    sched_job_record('matrix_regulatory_audit', 'ok', $detail,
                     (int) round((microtime(true) - $t0) * 1000));
    echo "[{$ts}] matrix_regulatory_audit: {$detail}\n";
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[{$ts}] matrix_regulatory_audit FAILED: {$msg}\n");
    sched_job_record('matrix_regulatory_audit', 'error', $msg,
                     (int) round((microtime(true) - $t0) * 1000));
    exit(1);
}
