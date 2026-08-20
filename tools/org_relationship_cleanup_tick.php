<?php
/**
 * Phase 143 (2026-08-17) — standing cross-org relationship activation
 * cleanup tick.
 *
 * NON-AUTHORITATIVE by construction — read this before assuming this job
 * "expires" anything. It closes out (stamps deactivated_at) activation
 * windows the READ-TIME predicate
 * (inc/org-relationships.php's org_relationship_activation_live_join_sql(),
 * consumed by org_can_see_ticket()/org_ticket_query_filter()/
 * org_can_mutate_ticket()/org_relationship_context_for_ticket()) has
 * ALREADY excluded from every request since the moment the window closed —
 * with or without this job ever running. See
 * tests/test_org_relationships_read_time_expiry.php (which proves the
 * access-loss with this tick script NEVER invoked at all) and
 * tests/test_org_relationship_cleanup_job.php (which proves the access
 * state is UNCHANGED before vs. after this job runs — it only closes the
 * audit record). This is this project's third application of the
 * PAR-scheduler / pending-message-sweep lesson (CLAUDE.md, 2026-07-29):
 * "cleanup that closes out a stale audit record runs whether or not
 * anyone is watching, and grants nothing by running, and revokes nothing
 * by not running."
 *
 * Run every 5 minutes. See docs/MAINTENANCE-RUNBOOK.md for the systemd
 * timer template (same shape as par_tick / pending_messages_tick).
 *
 * Usage: php tools/org_relationship_cleanup_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-relationships.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');
$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $closed = 0;
    $failed = 0;
    $rows = org_relationships_schema_ready()
        ? db_fetch_all(
            "SELECT `relationship_id` FROM `{$prefix}org_relationships_activations`
              WHERE `deactivated_at` IS NULL
                AND `max_activation_minutes` IS NOT NULL
                AND `activated_at` <= DATE_SUB(NOW(), INTERVAL `max_activation_minutes` MINUTE)"
          )
        : [];

    foreach ($rows as $row) {
        $relationshipId = (int) $row['relationship_id'];
        // $canActGlobal=true is irrelevant here -- $autoExpired=true bypasses
        // the membership gate entirely (closing an audit record for a
        // window the read-time predicate has already excluded is
        // housekeeping, not a privileged action). $callerUserId=0 matches
        // this codebase's convention for "the system, not a human, did
        // this" (see org_relationship_deactivate()'s own docblock).
        $result = org_relationship_deactivate($relationshipId, true, 0, '', null, true);
        if (!empty($result['success'])) {
            $closed++;
        } else {
            $failed++;
            error_log('[org_relationship_cleanup_tick] failed to close relationship '
                . $relationshipId . ': ' . implode('; ', $result['errors'] ?? []));
        }
    }

    $detail = 'considered=' . count($rows) . " closed={$closed} failed={$failed}";
    echo "[{$ts}] org_relationship_cleanup: {$detail}\n";
    sched_job_record('org_relationship_activation_cleanup', 'ok', $detail,
                     (int) round((microtime(true) - $t0) * 1000));
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[{$ts}] org_relationship_cleanup FAILED: {$msg}\n");
    sched_job_record('org_relationship_activation_cleanup', 'error', $msg,
                     (int) round((microtime(true) - $t0) * 1000));
    exit(1);
}
