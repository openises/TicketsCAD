<?php
/**
 * Phase 149 — webhook ingest: idempotency, the event_ts ordering guard,
 * and the 'ringing'/'claimed_externally'/'ended'/'abandoned' state
 * machine. Drives the REAL inbound_calls_ingest_event() function
 * (inc/inbound-calls.php) against a fixture pbx_trunks row — not a
 * hand-seeded stand-in for what ingest would produce.
 *
 * Bearer-token auth + rate-limiting themselves are also exercised
 * end-to-end in Milestone 9's live-verification pass against the real
 * HTTP endpoint (api/sip-ingest.php cannot be driven meaningfully without
 * a running web server for the Authorization header path); this file
 * covers the DB-level business logic that both the HTTP endpoint and any
 * future consumer share.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_ingest.php
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

echo "=== Phase 149 — inbound-calls ingest ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Dedicated fixture id block (grepped clean against the rest of tests/).
$trunkId = 900014910;

$cleanup = function () use ($prefix, $trunkId) {
    try {
        $ids = db_fetch_all("SELECT id FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
        foreach ($ids as $r) {
            db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE `call_id` = ?", [(int) $r['id']]);
        }
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

// Ensure the schema exists (this file may run standalone).
try { db_fetch_value("SELECT 1 FROM `{$prefix}pbx_trunks` LIMIT 1"); } catch (Throwable $e) {
    ob_start(); include __DIR__ . '/../sql/run_phase149_inbound_sip_calls.php'; ob_end_clean();
}

$rawToken = sip_token_mint();
db_query(
    "INSERT INTO `{$prefix}pbx_trunks`
        (`id`, `label`, `org_id`, `bearer_token`, `mute_bypass_enabled`,
         `wrapup_seconds`, `reassign_grace_seconds`, `enabled`)
     VALUES (?, 'Fixture Trunk', NULL, ?, 1, 90, 20, 1)",
    [$trunkId, $rawToken]
);
$trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);

try {

    // ══════════════════════════════════════════════════════════════════
    // sip_token: mint / mask / resolve
    // ══════════════════════════════════════════════════════════════════
    echo "--- sip_token ---\n\n";

    t('sip_token_mint() returns 64 hex chars', preg_match('/^[0-9a-f]{64}$/', $rawToken) === 1);
    $masked = sip_token_mask($rawToken);
    t('sip_token_mask() never returns the raw value', $masked !== $rawToken);
    t('sip_token_mask() preserves the last 4 characters', substr($masked, -4) === substr($rawToken, -4));

    $resolved = sip_token_resolve_trunk($rawToken);
    t('sip_token_resolve_trunk() finds the trunk by its real token', $resolved !== null && (int) $resolved['id'] === $trunkId);
    t('sip_token_resolve_trunk() rejects a wrong token', sip_token_resolve_trunk('0000000000000000000000000000000000000000000000000000000000000000') === null);
    t('sip_token_resolve_trunk() rejects an empty token', sip_token_resolve_trunk('') === null);

    // ══════════════════════════════════════════════════════════════════
    // FR-1/FR-3: ringing creates a row; a duplicate never double-rings
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Idempotency (FR-3) ---\n\n";

    $r1 = inbound_calls_ingest_event($trunk, [
        'event' => 'ringing', 'call_id' => 'call-abc-1',
        'caller_number' => '+16125551234', 'called_number' => '+16125559999',
        'event_ts' => date('c'),
    ]);
    t('first ringing event is applied', $r1['ok'] === true && $r1['applied'] === true);
    $callId = $r1['call_id'];
    t('a call row was created', $callId !== null);

    $row = inbound_call_get($callId);
    t('the row lands in state=ringing', $row !== null && $row['state'] === 'ringing');
    t('caller_number was stored', $row['caller_number'] === '+16125551234');

    $rowCountBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);

    // Retried/duplicate webhook, same provider call id — must NOT create
    // a second row, a second ring, or corrupt state.
    $r2 = inbound_calls_ingest_event($trunk, [
        'event' => 'ringing', 'call_id' => 'call-abc-1',
        'caller_number' => '+16125551234', 'event_ts' => date('c'),
    ]);
    t('a duplicate ringing webhook is accepted (ok=true)', $r2['ok'] === true);
    t('a duplicate ringing webhook is a no-op on state (applied=false)', $r2['applied'] === false);
    $rowCountAfter = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
    t('no second row/ring was created by the duplicate', $rowCountAfter === $rowCountBefore);

    $eventCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'rang'", [$callId]);
    t('exactly one "rang" audit row exists despite the duplicate webhook', $eventCount === 1);

    // ══════════════════════════════════════════════════════════════════
    // FR-4: out-of-order delivery must not corrupt state
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Out-of-order delivery (FR-4) ---\n\n";

    $r3 = inbound_calls_ingest_event($trunk, [
        'event' => 'ringing', 'call_id' => 'call-abc-1',
        // Older than the already-applied event_ts above.
        'event_ts' => date('c', strtotime('-1 hour')),
    ]);
    t('an event older than last_event_at is accepted (ok=true)', $r3['ok'] === true);
    t('an out-of-order event does not mutate state (applied=false)', $r3['applied'] === false);
    $rowAfterStale = inbound_call_get($callId);
    t('state is unchanged by the stale/out-of-order event', $rowAfterStale['state'] === 'ringing');

    // ══════════════════════════════════════════════════════════════════
    // claimed_externally: informational only, never mutates state
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- claimed_externally (informational-only, plan.md §2) ---\n\n";

    $r4 = inbound_calls_ingest_event($trunk, [
        'event' => 'claimed_externally', 'call_id' => 'call-abc-1', 'event_ts' => date('c'),
    ]);
    t('claimed_externally is accepted', $r4['ok'] === true);
    $rowAfterExternal = inbound_call_get($callId);
    t('claimed_externally does NOT change state', $rowAfterExternal['state'] === 'ringing');
    $extEventCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'claimed_externally'",
        [$callId]
    );
    t('claimed_externally is recorded as an audit note', $extEventCount === 1);

    // ══════════════════════════════════════════════════════════════════
    // A call abandoned before anyone claims it (FR-14)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Abandoned before claim (FR-14) ---\n\n";

    $r5 = inbound_calls_ingest_event($trunk, [
        'event' => 'ended', 'call_id' => 'call-abc-1', 'event_ts' => date('c'),
    ]);
    t('ended-while-ringing is applied', $r5['ok'] === true && $r5['applied'] === true);
    $rowAbandoned = inbound_call_get($callId);
    t('state moves to abandoned, not ended', $rowAbandoned['state'] === 'abandoned');
    t('ended_at was stamped', !empty($rowAbandoned['ended_at']));

    // A retried "ended" after the call is already terminal is a no-op.
    $r6 = inbound_calls_ingest_event($trunk, [
        'event' => 'ended', 'call_id' => 'call-abc-1', 'event_ts' => date('c'),
    ]);
    t('a retried terminal ended event is accepted but not re-applied', $r6['ok'] === true && $r6['applied'] === false);

    // ══════════════════════════════════════════════════════════════════
    // A second, independent call on the same trunk is unaffected
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Independent calls don't cross-contaminate ---\n\n";

    $r7 = inbound_calls_ingest_event($trunk, [
        'event' => 'ringing', 'call_id' => 'call-xyz-2', 'caller_number' => '+16125550000', 'event_ts' => date('c'),
    ]);
    t('a second call on the same trunk creates its own row', $r7['ok'] === true && $r7['call_id'] !== $callId);
    $row2 = inbound_call_get($r7['call_id']);
    t('the second call is independently ringing', $row2['state'] === 'ringing');
    $row1Recheck = inbound_call_get($callId);
    t('the first call is unaffected (still abandoned)', $row1Recheck['state'] === 'abandoned');

    // ══════════════════════════════════════════════════════════════════
    // Unknown event / missing call_id are rejected cleanly
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Input validation ---\n\n";

    $rBad1 = inbound_calls_ingest_event($trunk, ['event' => 'nonsense', 'call_id' => 'x']);
    t('an unknown event type is rejected', $rBad1['ok'] === false);
    $rBad2 = inbound_calls_ingest_event($trunk, ['event' => 'ringing', 'call_id' => '']);
    t('a missing call_id is rejected', $rBad2['ok'] === false);

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
