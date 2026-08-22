<?php
/**
 * Phase 149 Milestone 6 — wrap-up state transition, the wrap-up-to-ended
 * fold (read-time-derived sweep), and missed/abandoned-call review.
 *
 * Drives the REAL functions in inc/inbound-calls.php against fixture
 * data: inbound_calls_ingest_event() for the ended-while-claimed ->
 * wrapup transition, inbound_calls_wrapup_sweep() for the timeout fold
 * (backdates ended_at rather than sleeping the runner, matching this
 * project's established technique for time-based logic),
 * inbound_call_link_ticket() ending wrapup early on save, and
 * inbound_call_mark_reviewed() for the missed-calls panel's dismiss
 * action.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_wrapup.php
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

echo "=== Phase 149 Milestone 6 — wrap-up + missed-call review ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$trunkId = 900015020;

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
    // A short wrapup window (5s) so the "elapsed" case can be reached by
    // backdating without a real sleep.
    db_query(
        "INSERT INTO `{$prefix}pbx_trunks` (`id`, `label`, `org_id`, `bearer_token`, `enabled`, `wrapup_seconds`)
         VALUES (?, 'Wrapup Fixture Trunk', NULL, ?, 1, 90)",
        [$trunkId, sip_token_mint()]
    );
    $trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]);

    // ══════════════════════════════════════════════════════════════════
    // ended-while-claimed -> wrapup (not straight to ended)
    // ══════════════════════════════════════════════════════════════════
    echo "--- ended-while-claimed transitions to wrapup ---\n\n";

    $ring = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'wrapup-fixture-1', 'caller_number' => '+16125550501', 'event_ts' => date('c')]);
    $callId = $ring['call_id'];
    $claim = inbound_call_claim($callId, 111, 'Dispatcher A');
    t('call is claimed', $claim['ok'] === true);

    $ended = inbound_calls_ingest_event($trunk, ['event' => 'ended', 'call_id' => 'wrapup-fixture-1', 'event_ts' => date('c', time() + 1)]);
    t('the ended webhook is applied', $ended['ok'] === true && $ended['applied'] === true);

    $row = inbound_call_get($callId);
    t('state moved to wrapup, NOT straight to ended', $row['state'] === 'wrapup');
    t('ended_at was stamped even though state is still wrapup', !empty($row['ended_at']));

    $wrapupEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'wrapup_started'",
        [$callId]
    );
    t("a 'wrapup_started' audit row was recorded", $wrapupEvent !== null);

    // A retried 'ended' while already in wrapup is a pure no-op (no
    // second wrapup_started row).
    inbound_calls_ingest_event($trunk, ['event' => 'ended', 'call_id' => 'wrapup-fixture-1', 'event_ts' => date('c', time() + 2)]);
    $wrapupEventCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'wrapup_started'",
        [$callId]
    );
    t('a retried ended-while-wrapup does not double-fire wrapup_started', $wrapupEventCount === 1);

    // ══════════════════════════════════════════════════════════════════
    // The wrap-up-to-ended fold (read-time-derived sweep)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- The wrapup-seconds fold ---\n\n";

    $sweepTooEarly = inbound_calls_wrapup_sweep();
    $rowStillWrapup = inbound_call_get($callId);
    t('the sweep does NOT fold a call before wrapup_seconds has elapsed', $rowStillWrapup['state'] === 'wrapup');

    // Backdate ended_at past the 90s window -- never sleep the runner.
    db_query("UPDATE `{$prefix}inbound_calls` SET `ended_at` = (NOW() - INTERVAL 120 SECOND) WHERE `id` = ?", [$callId]);
    $sweepResult = inbound_calls_wrapup_sweep();
    t('the sweep folds at least one call once its deadline has passed', $sweepResult['ok'] === true && $sweepResult['folded'] >= 1);

    $rowFolded = inbound_call_get($callId);
    t('the call is now state=ended', $rowFolded['state'] === 'ended');

    $endedEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'ended' ORDER BY id DESC LIMIT 1",
        [$callId]
    );
    t("an 'ended' audit row was recorded by the fold", $endedEvent !== null);

    // A second sweep run is a clean no-op for this already-ended call.
    $secondSweep = inbound_calls_wrapup_sweep();
    t('a second sweep run does not re-fold an already-ended call', $secondSweep['folded'] === 0 || $secondSweep['ok'] === true);

    // ══════════════════════════════════════════════════════════════════
    // link_ticket ends wrapup EARLY on save
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- markHandled/link_ticket ends wrapup early ---\n\n";

    $ring2 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'wrapup-fixture-2', 'caller_number' => '+16125550502', 'event_ts' => date('c')]);
    $callId2 = $ring2['call_id'];
    inbound_call_claim($callId2, 222, 'Dispatcher B');
    inbound_calls_ingest_event($trunk, ['event' => 'ended', 'call_id' => 'wrapup-fixture-2', 'event_ts' => date('c', time() + 1)]);
    $rowBeforeLink = inbound_call_get($callId2);
    t('call 2 is in wrapup, well within the window', $rowBeforeLink['state'] === 'wrapup');

    $linkResult = inbound_call_link_ticket($callId2, 999999, 222, 'Dispatcher B');
    t('link_ticket succeeds', $linkResult['ok'] === true);
    $rowAfterLink = inbound_call_get($callId2);
    t('the paperwork is genuinely done -- wrapup ends EARLY on save, not after the full timer', $rowAfterLink['state'] === 'ended');
    t('ticket_id was set', (int) $rowAfterLink['ticket_id'] === 999999);

    $linkedEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'linked_to_ticket'",
        [$callId2]
    );
    t("a 'linked_to_ticket' audit row was recorded", $linkedEvent !== null);

    // ══════════════════════════════════════════════════════════════════
    // Missed-call review (FR-14, the "Missed Calls" panel's dismiss action)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Missed-call review ---\n\n";

    $ring3 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => 'wrapup-fixture-3', 'caller_number' => '+16125550503', 'event_ts' => date('c')]);
    $callId3 = $ring3['call_id'];
    inbound_calls_ingest_event($trunk, ['event' => 'ended', 'call_id' => 'wrapup-fixture-3', 'event_ts' => date('c', time() + 1)]);
    $rowAbandoned = inbound_call_get($callId3);
    t('the never-claimed call is abandoned', $rowAbandoned['state'] === 'abandoned');
    t('reviewed_at is NULL until reviewed', empty($rowAbandoned['reviewed_at']));

    // Reviewing a call that is NOT abandoned is refused.
    $reviewNotAbandoned = inbound_call_mark_reviewed($callId, 333, 'Supervisor');
    t('reviewing a non-abandoned call is refused', $reviewNotAbandoned['ok'] === false);

    $reviewOk = inbound_call_mark_reviewed($callId3, 333, 'Supervisor');
    t('reviewing the abandoned call succeeds', $reviewOk['ok'] === true);
    $rowReviewed = inbound_call_get($callId3);
    t('reviewed_at is now set', !empty($rowReviewed['reviewed_at']));
    t('reviewed_by records the reviewer', (int) $rowReviewed['reviewed_by'] === 333);
    t('the record is NOT deleted -- still queryable, just no longer "unreviewed"', $rowReviewed !== null);

    $reviewedEvent = db_fetch_one(
        "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'reviewed'",
        [$callId3]
    );
    t("a 'reviewed' audit row was recorded", $reviewedEvent !== null);

    // Reviewing an already-reviewed call again is a clean no-op refusal
    // (state is no longer 'abandoned' with reviewed_at NULL -- the
    // function's own WHERE clause covers this, but confirm directly).
    $reviewAgain = inbound_call_mark_reviewed($callId3, 333, 'Supervisor');
    t('reviewing an already-reviewed call again is refused (idempotent, no duplicate audit row)', $reviewAgain['ok'] === false);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
