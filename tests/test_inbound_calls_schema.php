<?php
/**
 * Phase 149 — schema/migration idempotency for pbx_trunks, inbound_calls,
 * inbound_call_events. Drives the REAL migration script twice (matching
 * this project's convention for schema tests), then asserts every
 * plan.md §3 column exists.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_schema.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 — inbound-calls schema ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function p149t_columns(string $table): array {
    global $prefix;
    $rows = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $table]
    );
    return array_column($rows, 'COLUMN_NAME');
}

// ── Run the real migration twice — must not throw either time ──────────
ob_start();
include __DIR__ . '/../sql/run_phase149_inbound_sip_calls.php';
$firstRun = ob_get_clean();
t('migration ran without throwing (first run)', is_string($firstRun) && $firstRun !== '');

ob_start();
include __DIR__ . '/../sql/run_phase149_inbound_sip_calls.php';
$secondRun = ob_get_clean();
t('migration is idempotent — second run reports [SKIP] for all three tables',
    substr_count($secondRun, '[SKIP]') === 3);

// ── pbx_trunks ───────────────────────────────────────────────────────
$trunkCols = p149t_columns('pbx_trunks');
foreach ([
    'id', 'label', 'org_id', 'bearer_token', 'mute_bypass_enabled',
    'wrapup_seconds', 'reassign_grace_seconds', 'enabled',
    'created_at', 'updated_at',
] as $col) {
    t("pbx_trunks has column `{$col}`", in_array($col, $trunkCols, true));
}

// ── inbound_calls ────────────────────────────────────────────────────
$callCols = p149t_columns('inbound_calls');
foreach ([
    'id', 'trunk_id', 'org_id', 'provider_call_id', 'caller_number',
    'caller_name', 'called_number', 'state', 'claimed_by', 'claimed_by_name',
    'claimed_at', 'claim_heartbeat_at', 'stale_since', 'released_at',
    'reassigned_from', 'ended_at', 'ticket_id', 'reviewed_at', 'reviewed_by',
    'ringing_at', 'last_event_at', 'created_at',
] as $col) {
    t("inbound_calls has column `{$col}`", in_array($col, $callCols, true));
}

// ── inbound_call_events ──────────────────────────────────────────────
$eventCols = p149t_columns('inbound_call_events');
foreach (['id', 'call_id', 'event_type', 'actor_user_id', 'actor_name', 'reason', 'detail_json', 'at'] as $col) {
    t("inbound_call_events has column `{$col}`", in_array($col, $eventCols, true));
}

// ── The idempotency constraint actually exists ──────────────────────
try {
    $indexRows = db_fetch_all(
        "SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_trunk_call'",
        [$prefix . 'inbound_calls']
    );
    $isUnique = !empty($indexRows) && (int) $indexRows[0]['NON_UNIQUE'] === 0;
} catch (Throwable $e) {
    $isUnique = false;
}
t('inbound_calls has a UNIQUE key uk_trunk_call (trunk_id, provider_call_id)', $isUnique);

// ── Verify the unique key actually rejects a duplicate (don't trust the
//    DDL alone — this project's own Phase 129/134/141/143 discipline) ──
$fixtureTrunkId = 900014901;
try {
    db_query(
        "INSERT INTO `{$prefix}inbound_calls`
            (`trunk_id`, `provider_call_id`, `state`, `ringing_at`, `last_event_at`)
         VALUES (?, 'p149-schema-fixture-call', 'ringing', NOW(), NOW())",
        [$fixtureTrunkId]
    );
    $dupRejected = false;
    try {
        db_query(
            "INSERT INTO `{$prefix}inbound_calls`
                (`trunk_id`, `provider_call_id`, `state`, `ringing_at`, `last_event_at`)
             VALUES (?, 'p149-schema-fixture-call', 'ringing', NOW(), NOW())",
            [$fixtureTrunkId]
        );
    } catch (Throwable $e) {
        $dupRejected = true;
    }
    t('a genuine duplicate (trunk_id, provider_call_id) insert is rejected by the live database', $dupRejected);
} finally {
    try {
        db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ? AND `provider_call_id` = 'p149-schema-fixture-call'", [$fixtureTrunkId]);
    } catch (Throwable $e) {}
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
