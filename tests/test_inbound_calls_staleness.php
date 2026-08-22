<?php
/**
 * Phase 149 Milestone 7 — the claim-heartbeat staleness sweep (FR-15).
 *
 * Drives the REAL inbound_calls_staleness_sweep() / inbound_call_heartbeat()
 * functions against fixture data. Backdates claim_heartbeat_at rather than
 * sleeping the runner (this project's established technique for time-based
 * logic), and asserts `state` never silently changes on its own — a stale
 * claim is a liveness flag layered on top of an otherwise-unchanged claim,
 * never a distinct lifecycle state (plan.md §4).
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_staleness.php
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

echo "=== Phase 149 Milestone 7 — claim heartbeat + staleness sweep (FR-15) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$trunkId = 900015050;

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
    db_query(
        "INSERT INTO `{$prefix}pbx_trunks` (`id`, `label`, `org_id`, `bearer_token`, `enabled`)
         VALUES (?, 'Staleness Fixture Trunk', NULL, ?, 1)",
        [$trunkId, sip_token_mint()]
    );
    $trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]);

    // ══════════════════════════════════════════════════════════════════
    // A fresh claim (current heartbeat) is never flagged
    // ══════════════════════════════════════════════════════════════════
    echo "--- A fresh claim is not stale ---\n\n";

    $ring1 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'stale-fixture-1', 'caller_number' => '+16125550601', 'event_ts' => date('c')]);
    $callId1 = $ring1['call_id'];
    $claim1 = inbound_call_claim($callId1, 111, 'Dispatcher A');
    t('call 1 is claimed', $claim1['ok'] === true);

    $sweep1 = inbound_calls_staleness_sweep(45);
    $row1 = inbound_call_get($callId1);
    t('a claim with a current heartbeat is NOT flagged stale', empty($row1['stale_since']));
    t('state is unchanged by the sweep (still claimed)', $row1['state'] === 'claimed');

    // ══════════════════════════════════════════════════════════════════
    // A lapsed heartbeat is flagged stale by the sweep (backdated, never sleeps)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- A lapsed heartbeat is flagged stale ---\n\n";

    $ring2 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'stale-fixture-2', 'caller_number' => '+16125550602', 'event_ts' => date('c')]);
    $callId2 = $ring2['call_id'];
    $claim2 = inbound_call_claim($callId2, 222, 'Dispatcher B');
    t('call 2 is claimed', $claim2['ok'] === true);

    // Backdate past the 45s threshold — never sleep the runner.
    db_query("UPDATE `{$prefix}inbound_calls` SET `claim_heartbeat_at` = (NOW() - INTERVAL 90 SECOND) WHERE `id` = ?", [$callId2]);

    $sweep2 = inbound_calls_staleness_sweep(45);
    t('the sweep reports at least one newly-stale call', $sweep2['ok'] === true && $sweep2['found'] >= 1);

    $row2 = inbound_call_get($callId2);
    t('stale_since is now populated', !empty($row2['stale_since']));
    t('state DID NOT silently change -- still claimed, per plan.md §4 (staleness is a liveness flag, not a lifecycle state)',
        $row2['state'] === 'claimed');
    t('claimed_by is UNCHANGED -- the system never auto-releases/auto-transfers a stale claim',
        (int) $row2['claimed_by'] === 222);

    $staleEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'stale_detected'",
        [$callId2]
    );
    t("a 'stale_detected' audit row was recorded", $staleEvent !== null);

    // A second sweep run does not re-flag (re-stamp) an already-stale call.
    $staleSinceBefore = $row2['stale_since'];
    $sweep2b = inbound_calls_staleness_sweep(45);
    $row2b = inbound_call_get($callId2);
    t('a second sweep run does not re-stamp stale_since on an already-stale call', $row2b['stale_since'] === $staleSinceBefore);
    $staleEventCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'stale_detected'",
        [$callId2]
    );
    t('exactly ONE stale_detected audit row exists despite two sweep runs', $staleEventCount === 1);

    // ══════════════════════════════════════════════════════════════════
    // A resumed heartbeat clears stale_since -- read-time-derived, not
    // a one-shot decision (plan.md §4).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- A resumed heartbeat clears the stale flag ---\n\n";

    $hbResult = inbound_call_heartbeat($callId2, 222);
    t('the claimant\'s own heartbeat succeeds', $hbResult['ok'] === true);

    $row2c = inbound_call_get($callId2);
    t('stale_since is cleared back to NULL by a resumed heartbeat', empty($row2c['stale_since']));
    t('state is still claimed -- resuming a heartbeat does not "re-claim" anything', $row2c['state'] === 'claimed');

    // A heartbeat from someone who does NOT hold the claim is refused.
    $hbWrongUser = inbound_call_heartbeat($callId2, 999);
    t('a heartbeat from a user who does NOT hold the claim is refused', $hbWrongUser['ok'] === false);

    // A heartbeat on a call not in state='claimed' is refused (matches
    // plan.md §4's "only while state IN ('claimed')" scope).
    inbound_calls_ingest_event($trunk, ['event' => 'ended', 'call_id' => 'stale-fixture-2', 'event_ts' => date('c', time() + 1)]);
    $rowWrapup = inbound_call_get($callId2);
    t('call 2 moved to wrapup after ended', $rowWrapup['state'] === 'wrapup');
    $hbAfterWrapup = inbound_call_heartbeat($callId2, 222);
    t('a heartbeat is refused once the call has moved past claimed (into wrapup)', $hbAfterWrapup['ok'] === false);

    // ══════════════════════════════════════════════════════════════════
    // The sweep never touches a call that isn't currently claimed
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- The sweep only ever considers state='claimed' rows ---\n\n";

    $ring3 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'stale-fixture-3', 'caller_number' => '+16125550603', 'event_ts' => date('c')]);
    $callId3 = $ring3['call_id'];
    // A ringing (never-claimed) call has no heartbeat at all -- must never
    // be flagged stale regardless of how old it is.
    db_query("UPDATE `{$prefix}inbound_calls` SET `ringing_at` = (NOW() - INTERVAL 1 HOUR) WHERE `id` = ?", [$callId3]);
    inbound_calls_staleness_sweep(45);
    $row3 = inbound_call_get($callId3);
    t('a never-claimed ringing call is never flagged stale (no heartbeat to lapse)', empty($row3['stale_since']));
    t('a ringing call is unaffected by the sweep', $row3['state'] === 'ringing');

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
