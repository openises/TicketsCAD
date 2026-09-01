<?php
/**
 * GH#116 follow-up — backfill assigns.status_id for currently-OPEN
 * assignments on an install that already had open calls before the
 * status_id-maintenance fix landed.
 * --------------------------------------------------------------------
 *
 * The fix (inc/assignment-write.php's assign_update_status_internal() and
 * inc/responder-write.php's responder_set_status_internal()) keeps
 * assigns.status_id current from here forward, on every status change
 * either write path makes. It does nothing for an assignment that was
 * ALREADY open when the fix deployed — that row's status_id is still
 * whatever it was stamped at CREATION time ("Dispatched"), even though
 * the assignment may have long since progressed to on_scene, en route
 * to a facility, etc. On the mobile grouped-by-assignment status grid,
 * that one call's cards would keep highlighting "Dispatched" until its
 * NEXT status change — usually soon, but not guaranteed, and not
 * something a beta tester should have to notice and work around.
 *
 * This migration is a ONE-TIME best-effort catch-up, not an ongoing
 * write path (the fix itself is the ongoing path). For every currently
 * open assignment (clear IS NULL or zero-dated), it infers the specific
 * status id from the assignment's OWN timestamp ladder — the furthest
 * rung actually reached — using the SAME action-to-status resolution
 * (_assign_status_id_by_action(), inc/assignment-write.php) the real
 * writers use, so the backfilled value is exactly what a real status
 * change to that rung would have produced:
 *
 *   u2farr set  -> facility_arrived
 *   u2fenr set  -> facility_enroute
 *   on_scene set -> on_scene
 *   responding set -> responding
 *   none set    -> leave untouched (creation-time value is already
 *                  correct: the assignment hasn't progressed past
 *                  Dispatched yet)
 *
 * This is DISPLAY-QUALITY best-effort, not an authorization or safety
 * decision — status_id is read only for UI highlighting (this fix's own
 * purpose) and nowhere gates a permission check. A install with zero
 * open assignments (a fresh install, or one where every call has since
 * cleared) is a clean no-op.
 *
 * Idempotent: only UPDATEs rows whose computed status differs from what
 * they already hold, and skips gracefully if _assign_status_id_by_action()
 * can't resolve a mapping (no un_status row for that incident_action on
 * this install — leaves status_id untouched rather than writing null/0).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php'; // for _assign_status_id_by_action()

$prefix = $GLOBALS['db_prefix'] ?? '';

function gh116_backfill_log(string $msg): void { echo $msg . "\n"; }

gh116_backfill_log('=== GH#116 backfill: assigns.status_id for currently-open assignments ===');

try {
    $openAssigns = db_fetch_all(
        "SELECT `id`, `status_id`, `responding`, `on_scene`, `u2fenr`, `u2farr`
           FROM `{$prefix}assigns`
          WHERE (`clear` IS NULL OR DATE_FORMAT(`clear`, '%y') = '00')"
    );
} catch (Exception $e) {
    fwrite(STDERR, "FATAL: could not read assigns: " . $e->getMessage() . "\n");
    exit(1);
}

gh116_backfill_log(count($openAssigns) . ' currently-open assignment(s) found.');

function gh116_has_ts($v): bool {
    return !empty($v) && substr((string) $v, 0, 4) !== '0000';
}

$resolvedCache = []; // action => status id (or null if unresolvable), resolved once per run
function gh116_resolve(string $action) {
    global $resolvedCache;
    if (!array_key_exists($action, $resolvedCache)) {
        $resolvedCache[$action] = _assign_status_id_by_action($action);
    }
    return $resolvedCache[$action];
}

$updated = 0; $unresolved = 0; $alreadyCorrect = 0; $noProgress = 0;

foreach ($openAssigns as $oa) {
    $action = null;
    if (gh116_has_ts($oa['u2farr'] ?? null)) {
        $action = 'facility_arrived';
    } elseif (gh116_has_ts($oa['u2fenr'] ?? null)) {
        $action = 'facility_enroute';
    } elseif (gh116_has_ts($oa['on_scene'] ?? null)) {
        $action = 'on_scene';
    } elseif (gh116_has_ts($oa['responding'] ?? null)) {
        $action = 'responding';
    }

    if ($action === null) {
        // Never progressed past Dispatched — the creation-time value is
        // already the correct rung. Nothing to backfill.
        $noProgress++;
        continue;
    }

    $target = gh116_resolve($action);
    if ($target === null) {
        // This install has no un_status row mapped to this action — can't
        // resolve a specific id. Leave the existing value untouched rather
        // than writing something worse (null/0).
        $unresolved++;
        continue;
    }

    if ((int) ($oa['status_id'] ?? 0) === (int) $target) {
        $alreadyCorrect++;
        continue;
    }

    try {
        db_query(
            "UPDATE `{$prefix}assigns` SET `status_id` = ? WHERE `id` = ?",
            [$target, (int) $oa['id']]
        );
        $updated++;
    } catch (Exception $e) {
        fwrite(STDERR, "  WARNING: failed to update assign id {$oa['id']}: " . $e->getMessage() . "\n");
    }
}

gh116_backfill_log("  Backfilled:        {$updated}");
gh116_backfill_log("  Already correct:   {$alreadyCorrect}");
gh116_backfill_log("  No progress yet:   {$noProgress} (still correctly Dispatched)");
gh116_backfill_log("  Unresolvable:      {$unresolved} (no un_status mapped to that action on this install)");

// Verify: re-count how many open assignments with real ladder progress
// still disagree with what gh116_resolve() would compute now — should be
// zero (modulo any this-run WARNING above, or any genuinely unresolvable
// action, both already reported).
$disagreeing = 0;
try {
    $recheck = db_fetch_all(
        "SELECT `id`, `status_id`, `responding`, `on_scene`, `u2fenr`, `u2farr`
           FROM `{$prefix}assigns`
          WHERE (`clear` IS NULL OR DATE_FORMAT(`clear`, '%y') = '00')"
    );
    foreach ($recheck as $oa) {
        $action = null;
        if (gh116_has_ts($oa['u2farr'] ?? null)) { $action = 'facility_arrived'; }
        elseif (gh116_has_ts($oa['u2fenr'] ?? null)) { $action = 'facility_enroute'; }
        elseif (gh116_has_ts($oa['on_scene'] ?? null)) { $action = 'on_scene'; }
        elseif (gh116_has_ts($oa['responding'] ?? null)) { $action = 'responding'; }
        if ($action === null) { continue; }
        $target = gh116_resolve($action);
        if ($target !== null && (int) ($oa['status_id'] ?? 0) !== (int) $target) {
            $disagreeing++;
        }
    }
} catch (Exception $e) {
    fwrite(STDERR, "WARNING: verification pass failed: " . $e->getMessage() . "\n");
}

if ($disagreeing > 0) {
    fwrite(STDERR, "FAIL: {$disagreeing} open assignment(s) still disagree with their resolved status after backfill.\n");
    exit(1);
}

gh116_backfill_log('Verified: every open assignment with ladder progress now matches its resolved status.');
exit(0);
