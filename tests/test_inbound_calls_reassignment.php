<?php
/**
 * Phase 149 — FR-18a/plan.md §4a: quick reassignment within the grace
 * window, and graceful degradation to the FR-17 override path once the
 * window elapses.
 *
 * Drives the REAL inbound_call_reassign() / inbound_call_force_reclaim()
 * functions against fixture data. Backdates `claimed_at` (never sleeps
 * the runner) to simulate the grace window elapsing, matching this
 * project's established technique for time-based logic.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_reassignment.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/inbound-calls.php';
require_once __DIR__ . '/../inc/sip_token.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 — quick reassignment (FR-18a) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$trunkId = 900015000;

$cleanup = function () use ($prefix, $trunkId) {
    try {
        $ids = db_fetch_all("SELECT id FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
        foreach ($ids as $r) db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE `call_id` = ?", [(int) $r['id']]);
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // A short grace window (3s) so the "elapsed" case can be reached by
    // backdating, without needing a huge sleep or a huge window.
    db_query(
        "INSERT INTO `{$prefix}pbx_trunks`
            (`id`, `label`, `org_id`, `bearer_token`, `enabled`, `reassign_grace_seconds`)
         VALUES (?, 'Reassign Fixture Trunk', NULL, ?, 1, 20)",
        [$trunkId, sip_token_mint()]
    );
    $trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]);

    // ══════════════════════════════════════════════════════════════════
    // A claims, B quick-reassigns within the grace window
    // ══════════════════════════════════════════════════════════════════
    echo "--- Within the grace window ---\n\n";

    $ring = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'reassign-fixture-1', 'caller_number' => '+16125550401', 'event_ts' => date('c')]);
    $callId = $ring['call_id'];

    $claimA = inbound_call_claim($callId, 111, 'Dispatcher A');
    t('user A claims the call', $claimA['ok'] === true);

    $reassign = inbound_call_reassign($callId, 222, 'Dispatcher B');
    t('user B quick-reassigns within the grace window', $reassign['ok'] === true);
    t('the call is now attributed to B', $reassign['call']['claimed_by'] == 222);
    t('reassigned_from records the PREVIOUS claimant (A)', (int) $reassign['call']['reassigned_from'] === 111);

    $reassignedEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'reassigned' ORDER BY id DESC LIMIT 1",
        [$callId]
    );
    t("a 'reassigned' audit row exists -- its OWN distinct event type, never conflated with force_reclaimed_active",
        $reassignedEvent !== null);
    t('the reassigned audit row requires NO reason (fast and ungated per FR-18a)', empty($reassignedEvent['reason']));
    t('the reassigned audit row names the new claimant as actor', (int) $reassignedEvent['actor_user_id'] === 222);

    $forceReclaimedActiveCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'force_reclaimed_active'",
        [$callId]
    );
    t('NO force_reclaimed_active event was recorded for this reassignment', $forceReclaimedActiveCount === 0);

    // Reassigning to yourself (already the claimant) is a no-op refusal.
    $selfReassign = inbound_call_reassign($callId, 222, 'Dispatcher B');
    t('reassigning to the CURRENT claimant is refused (already_yours)', $selfReassign['ok'] === false && $selfReassign['reason'] === 'already_yours');

    // ══════════════════════════════════════════════════════════════════
    // Grace window elapsed — the SAME quick action must now be refused
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- After the grace window elapses ---\n\n";

    $ring2 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'reassign-fixture-2', 'caller_number' => '+16125550402', 'event_ts' => date('c')]);
    $callId2 = $ring2['call_id'];
    $claimC = inbound_call_claim($callId2, 333, 'Dispatcher C');
    t('user C claims the second call', $claimC['ok'] === true);

    // Backdate claimed_at past the 20s grace window — never sleep the runner.
    db_query("UPDATE `{$prefix}inbound_calls` SET `claimed_at` = (NOW() - INTERVAL 60 SECOND) WHERE `id` = ?", [$callId2]);

    $lateReassign = inbound_call_reassign($callId2, 444, 'Dispatcher D');
    t('quick-reassignment is REFUSED once the grace window has elapsed', $lateReassign['ok'] === false);
    t("the refusal reason is 'grace_window_elapsed'", $lateReassign['reason'] === 'grace_window_elapsed');
    t('the refusal still names who currently has it', $lateReassign['claimed_by_name'] === 'Dispatcher C');

    // The FR-17 override path must still succeed in this exact refused
    // case -- the capability degrades gracefully, it never disappears.
    $forceReclaim = inbound_call_force_reclaim($callId2, 444, 'Dispatcher D', 'Dispatcher C stepped away, taking over per shift lead');
    t('the FR-17 supervisor-override path still succeeds after the grace window elapsed', $forceReclaim['ok'] === true);
    t('the override is recorded as force_reclaimed_active, NOT reassigned', $forceReclaim['was_stale'] === false);

    $activeEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'force_reclaimed_active' ORDER BY id DESC LIMIT 1",
        [$callId2]
    );
    t('the force_reclaimed_active event recorded the REQUIRED reason', !empty($activeEvent['reason']));

    // A force-reclaim on an ACTIVE (non-stale) claim with NO reason is refused.
    $ring3 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'reassign-fixture-3', 'caller_number' => '+16125550403', 'event_ts' => date('c')]);
    $callId3 = $ring3['call_id'];
    inbound_call_claim($callId3, 555, 'Dispatcher E');
    $noReason = inbound_call_force_reclaim($callId3, 666, 'Dispatcher F', null);
    t('force-reclaiming an ACTIVE claim with NO reason is refused', $noReason['ok'] === false && $noReason['reason'] === 'reason_required');

    // A STALE claim's force-reclaim needs no reason (low-friction, FR-16).
    db_query("UPDATE `{$prefix}inbound_calls` SET `stale_since` = NOW() WHERE `id` = ?", [$callId3]);
    $staleReclaim = inbound_call_force_reclaim($callId3, 666, 'Dispatcher F', null);
    t('force-reclaiming a STALE claim needs no reason', $staleReclaim['ok'] === true && $staleReclaim['was_stale'] === true);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
