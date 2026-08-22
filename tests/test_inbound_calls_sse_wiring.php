<?php
/**
 * Phase 149 Milestone 3 — SSE wiring: the three-allowlist proof (this
 * project's own "plumbing exists, nobody wired the last mile" gate,
 * applied to call:* the same way Phase 141/143 required it) plus a live
 * end-to-end proof of the org-scoping layered on top of 'entitled'
 * (plan.md §6).
 *
 * api/stream.php is a long-running polling script, not a set of callable
 * functions (Phase 142's test_org_sharing_sse.php precedent) — its
 * per-connection visibility-WHERE construction for the new `call:%`
 * branch is mirrored here, with Part 0 asserting the mirror's exact
 * clause text is still present in the real file's source, so a future
 * drift fails loudly instead of silently making this test meaningless.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_sse_wiring.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 3 — SSE wiring ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');
_sse_ensure_schema();

$CALL_TYPES = ['call:ringing', 'call:claimed', 'call:released', 'call:stale', 'call:wrapup', 'call:ended', 'call:abandoned'];

// ══════════════════════════════════════════════════════════════════════
// Part 1: the three-allowlist static proof
// ══════════════════════════════════════════════════════════════════════
echo "--- Three-allowlist wiring (static) ---\n\n";

$eventBusSrc = file_get_contents($base . '/assets/js/event-bus.js');
foreach ($CALL_TYPES as $type) {
    t("event-bus.js SSE_TYPES contains '{$type}'", strpos($eventBusSrc, "'{$type}'") !== false);
}

$trayS = file_get_contents($base . '/assets/js/notification-tray.js');
foreach ($CALL_TYPES as $type) {
    t("notification-tray.js EVENT_META has an entry for '{$type}'", strpos($trayS, "'{$type}'") !== false);
}
t("notification-tray.js alwaysShow exempts the 'call:' prefix", strpos($trayS, "eventType.indexOf('call:') === 0") !== false);

$streamSrc = file_get_contents($base . '/api/stream.php');
t("stream.php's entPermMap has a 'call:%' entry naming screen.call_queue",
    (bool) preg_match('/[\'"]call:%[\'"]\s*=>\s*\[\s*[\'"]screen\.call_queue[\'"]/', $streamSrc));

$sseSrc = file_get_contents($base . '/inc/sse.php');
t('inc/sse.php defines sse_publish_for_call()', strpos($sseSrc, 'function sse_publish_for_call(') !== false);
t("sse_publish_for_call() uses scope 'entitled'", (bool) preg_match('/function sse_publish_for_call.*?entitled/s', $sseSrc));

// ══════════════════════════════════════════════════════════════════════
// Part 0: mirror the org-scoped 'call:%' clause from the REAL stream.php,
// asserting the mirror's shape is still present in the live source.
// ══════════════════════════════════════════════════════════════════════
echo "\n--- Mirror drift guard (Part 0) ---\n\n";

t("stream.php still has the 'visibility_ids IS NULL' unconditional-broadcast branch for call:%",
    strpos($streamSrc, "\$orgOrs = [\"`visibility_ids` IS NULL\"];") !== false);
t('stream.php still guards on $userOrgIds === null for the call:% org-scope branch',
    strpos($streamSrc, 'if ($userOrgIds === null) {') !== false);

/** Mirrors api/stream.php's call:% entitled+org visibility clause. */
function p149_stream_call_visible(array $event, ?array $userOrgIds, bool $isAdmin): bool {
    if ($isAdmin) return true;
    if ($event['visibility_scope'] !== 'entitled') return false;
    if (strpos($event['event_type'], 'call:') !== 0) return false;
    if ($userOrgIds === null) return true; // unrestricted org visibility
    if ($event['visibility_ids'] === null) return true; // install-wide trunk
    $ids = array_map('intval', explode(',', (string) $event['visibility_ids']));
    foreach ($userOrgIds as $oid) {
        if (in_array((int) $oid, $ids, true)) return true;
    }
    return false;
}

// ══════════════════════════════════════════════════════════════════════
// Part 2: live publish -> real sse_events row -> the mirror's verdict
// ══════════════════════════════════════════════════════════════════════
echo "\n--- Live publish + org-scope filtering ---\n\n";

$orgAId = 900014960;
$orgBId = 900014961;
$fixtureCallId = 900014962;

$cleanup = function () use ($prefix) {
    try { db_query("DELETE FROM `{$prefix}sse_events` WHERE `event_type` LIKE 'call:%p149test%'"); } catch (Throwable $e) {}
};

try {
    // Broadcast (NULL org) call event — every entitled session sees it.
    $ok1 = sse_publish_for_call($fixtureCallId, 'call:ringing.p149test', ['x' => 1], null);
    t('sse_publish_for_call(orgId=null) publishes successfully', $ok1 === true);
    $rowBroadcast = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE `event_type` = 'call:ringing.p149test' ORDER BY id DESC LIMIT 1"
    );
    t('the broadcast row has visibility_scope=entitled', $rowBroadcast && $rowBroadcast['visibility_scope'] === 'entitled');
    t('the broadcast row has NULL visibility_ids', $rowBroadcast && $rowBroadcast['visibility_ids'] === null);

    // Org-scoped call event.
    $ok2 = sse_publish_for_call($fixtureCallId, 'call:ringing.p149test.org', ['x' => 1], $orgAId);
    t('sse_publish_for_call(orgId=X) publishes successfully', $ok2 === true);
    $rowOrgScoped = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE `event_type` = 'call:ringing.p149test.org' ORDER BY id DESC LIMIT 1"
    );
    t('the org-scoped row has visibility_scope=entitled', $rowOrgScoped && $rowOrgScoped['visibility_scope'] === 'entitled');
    t('the org-scoped row carries the org id in visibility_ids', $rowOrgScoped && (string) $rowOrgScoped['visibility_ids'] === (string) $orgAId);

    // Mirror verdicts.
    if ($rowBroadcast) {
        t('a session in org B sees the BROADCAST (NULL-org) event', p149_stream_call_visible($rowBroadcast, [$orgBId], false));
        t('an unrestricted (global-grant) session sees the BROADCAST event', p149_stream_call_visible($rowBroadcast, null, false));
    }
    if ($rowOrgScoped) {
        t('a session in org A sees the ORG-SCOPED event', p149_stream_call_visible($rowOrgScoped, [$orgAId], false));
        t('a session in org B does NOT see the ORG-SCOPED event (no cross-org leakage)', !p149_stream_call_visible($rowOrgScoped, [$orgBId], false));
        t('Super Admin sees the ORG-SCOPED event regardless', p149_stream_call_visible($rowOrgScoped, [$orgBId], true));
        t('an unrestricted (global-grant) session sees the ORG-SCOPED event too', p149_stream_call_visible($rowOrgScoped, null, false));
    }

    // A pre-existing 'entitled' caller with no scopeIds (e.g. incident:new)
    // must remain a pure broadcast — the opt-in branch must never silently
    // start requiring org membership for callers that never asked for it.
    $ok3 = sse_publish('incident:new.p149test', ['x' => 1], null, 'entitled');
    t('an ordinary entitled publish with no scopeIds still succeeds (backward-compat)', $ok3 === true);
    $rowOrdinary = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE `event_type` = 'incident:new.p149test' ORDER BY id DESC LIMIT 1"
    );
    t('an ordinary entitled publish still has NULL visibility_ids (unaffected by Phase 149)',
        $rowOrdinary && $rowOrdinary['visibility_ids'] === null);

} finally {
    try {
        db_query("DELETE FROM `{$prefix}sse_events` WHERE `event_type` IN (?, ?, ?)",
            ['call:ringing.p149test', 'call:ringing.p149test.org', 'incident:new.p149test']);
    } catch (Throwable $e) {}
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
