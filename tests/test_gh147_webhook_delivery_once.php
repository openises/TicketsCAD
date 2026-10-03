<?php
/**
 * GH#147 -- a REAL delivery, end to end: exactly one `incident.status_changed`
 * webhook POST per real status change, none for a no-op, and a documented
 * set for a wildcard subscriber.
 *
 * Why a live receiver rather than an unreachable URL: a failed delivery is
 * retried and logs again, so a failure COUNT is not an event count. This test
 * starts a throwaway local HTTP receiver (tests/_p155_webhook_receiver.php),
 * subscribes to it, performs real transitions through the real endpoints, drains
 * the notification queue and counts what actually arrived.
 *
 * It also settles the question GH#147's own spec raised about Phase 151:
 * incident.primary_changed was delivered TWICE per change (once as the audit
 * envelope, once as the SSE-derived bare payload). Measured here, fixed in
 * inc/sse.php (sse_webhook_suppressed_types()), and held by this test.
 *
 * @requires-db
 * Usage: php tests/test_gh147_webhook_delivery_once.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/scheduled-incidents.php';
require_once __DIR__ . '/../inc/notify-fanout.php';
require_once __DIR__ . '/../inc/external-auth.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#147 -- one delivery per real status change ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh147-test-user';

// The SSRF guard refuses loopback targets and caches its allowlist for the life
// of a process: allow the receiver's host BEFORE anything fires.
p155_remember_settings(['webhook_url_allowlist', 'primary_unit_mode']);
p155_set_setting('webhook_url_allowlist', '127.0.0.1');
p155_track_fanout_queue();

$rcv = p155_start_receiver();
if (!$rcv) {
    echo "SKIP: could not start the local webhook receiver (php -S) on this machine\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
$url = 'http://127.0.0.1:' . $rcv['port'] . '/hook';
$subStatus   = p155_make_subscription('gh147_status',   ['incident.status_changed'], $url);
$subWildcard = p155_make_subscription('gh147_wildcard', ['incident.*'],              $url);
$subOnlyLegacyClosed = p155_make_subscription('gh147_closed', ['incident.closed'],   $url);
notify_fanout_forget_channel_cache();

// Which events did the receiver see, per subscription? Deliveries are keyed by
// subscription in webhook_deliveries (the receiver cannot tell subscriptions apart).
$delivered = function (int $sub, ?string $event = null) use ($prefix) {
    $sql = "SELECT COUNT(DISTINCT delivery_uid) FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ? AND status = 'success'";
    $p = [$sub];
    if ($event !== null) { $sql .= ' AND event_type = ?'; $p[] = $event; }
    return (int) db_fetch_value($sql, $p);
};
$eventsFor = function (int $sub) use ($prefix): array {
    $rows = db_fetch_all("SELECT event_type, COUNT(DISTINCT delivery_uid) n FROM `{$prefix}webhook_deliveries`
                           WHERE subscription_id = ? AND status = 'success' GROUP BY event_type ORDER BY event_type", [$sub]);
    $o = [];
    foreach ($rows as $r) { $o[$r['event_type']] = (int) $r['n']; }
    return $o;
};

// ── 1. A UI close ────────────────────────────────────────────────────────
echo "--- a dispatcher closes an incident (api/incident-update.php) ---\n";
$t1 = p155_make_ticket(2, null, 'GH147 delivery close');
$r = p155_probe('session', 'api/incident-update.php', $adminId, ['action' => 'update_status', 'ticket_id' => $t1, 'new_status' => 1]);
t('the close succeeds', $r['http'] === 200 && !empty($r['json']['success']), $r['raw']);
p155_drain();
t("the status_changed subscriber received EXACTLY ONE delivery of incident.status_changed", $delivered($subStatus, 'incident.status_changed') === 1, json_encode($eventsFor($subStatus)));
t('and nothing else', $delivered($subStatus) === 1);
$legacyOnly = $eventsFor($subOnlyLegacyClosed);
t("the incident.closed subscriber still gets exactly one incident.closed (existing subscribers are unaffected)",
    ($legacyOnly['incident.closed'] ?? 0) === 1 && count($legacyOnly) === 1, json_encode($legacyOnly));
$wild = $eventsFor($subWildcard);
t("an incident.* subscriber gets the documented set: status_changed + closed + the SSE-derived incident.close, one each",
    $wild === ['incident.close' => 1, 'incident.closed' => 1, 'incident.status_changed' => 1], json_encode($wild));
$evs = p155_receiver_events($rcv);
$payload = null;
foreach ($evs as $e) { if (($e['event_type'] ?? '') === 'incident.status_changed') { $payload = $e['data']; break; } }
t("the delivered payload carries old/new status, labels, case number, source and actor",
    is_array($payload) && ($payload['details']['old_status_label'] ?? '') === 'Open' && ($payload['details']['new_status_label'] ?? '') === 'Closed'
    && ($payload['details']['source'] ?? '') === 'ui' && !empty($payload['details']['incident_number'])
    && ($payload['details']['actor_type'] ?? '') === 'user', json_encode($payload));
t("(the envelope's top-level ticket_id is present for receivers that route on it)", is_array($payload) && ($payload['ticket_id'] ?? null) === $t1);

// ── 2. A no-op is silent ─────────────────────────────────────────────────
echo "\n--- an External API retry of the same close is silent ---\n";
$tok = ext_api_mint_token($adminId, ['incidents:write'], $adminId, ['name' => 'gh147-delivery-token']);
test_fixture_guard_track('external_api_tokens', (int) $tok['id']);
$before = $delivered($subStatus);
$r = p155_probe('bearer', 'api/external/v1/incidents.php', $tok['raw_token'], ['status' => 1], 'PATCH', 'id=' . $t1);
t('the retry is accepted (200) and reports no change', $r['http'] === 200 && ($r['json']['data']['status_changed'] ?? null) === false, $r['raw']);
p155_drain();
t('NO new delivery reached the status_changed subscriber', $delivered($subStatus) === $before);
t('NO new delivery reached the incident.* subscriber either', array_sum($eventsFor($subWildcard)) === 3);

// ── 3. A real External API change ────────────────────────────────────────
echo "\n--- an External API reopen ---\n";
$r = p155_probe('bearer', 'api/external/v1/incidents.php', $tok['raw_token'], ['status' => 2], 'PATCH', 'id=' . $t1);
p155_drain();
t('one more incident.status_changed delivery for the real reopen', $delivered($subStatus, 'incident.status_changed') === 2, json_encode($eventsFor($subStatus)));
$apiPayload = null;
foreach (array_reverse(p155_receiver_events($rcv)) as $e) { if (($e['event_type'] ?? '') === 'incident.status_changed') { $apiPayload = $e['data']; break; } }
t("it carries source=external_api, actor_type=api_token, transition=reopened",
    is_array($apiPayload) && ($apiPayload['details']['source'] ?? '') === 'external_api'
    && ($apiPayload['details']['actor_type'] ?? '') === 'api_token' && ($apiPayload['details']['transition'] ?? '') === 'reopened', json_encode($apiPayload));

// ── 4. A scheduled activation by the system ──────────────────────────────
echo "\n--- scheduled activation (the system, no dispatcher) ---\n";
$t2 = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 2 MINUTE)', 'GH147 delivery activation');
$beforeAct = $delivered($subStatus, 'incident.status_changed');
incident_activate_due_scheduled(50);
p155_drain();
t('the activation produced exactly one more incident.status_changed delivery', $delivered($subStatus, 'incident.status_changed') === $beforeAct + 1);
$actPayload = null;
foreach (array_reverse(p155_receiver_events($rcv)) as $e) { if (($e['event_type'] ?? '') === 'incident.status_changed' && ($e['data']['ticket_id'] ?? 0) === $t2) { $actPayload = $e['data']; break; } }
t("its payload says transition=activated, source=scheduled_activation, actor_type=system, actor_name System",
    is_array($actPayload) && ($actPayload['details']['transition'] ?? '') === 'activated'
    && ($actPayload['details']['source'] ?? '') === 'scheduled_activation'
    && ($actPayload['details']['actor_type'] ?? '') === 'system' && ($actPayload['actor_name'] ?? '') === 'System', json_encode($actPayload));

// ── 5. Phase 151's suspected double delivery ─────────────────────────────
echo "\n--- incident.primary_changed (Phase 151) is delivered once ---\n";
p155_set_setting('primary_unit_mode', 'manual');
$subPrimary = p155_make_subscription('gh147_primary', ['incident.primary_changed'], $url);
notify_fanout_forget_channel_cache();
$unit = p155_make_unit('GH147-PRIM');
$tp = p155_make_ticket(2, null, 'GH147 primary');
assign_create_internal($tp, $unit, '', $adminId);
$r = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'set_primary', 'ticket_id' => $tp, 'responder_id' => $unit]);
t('setting the primary unit succeeds', $r['http'] === 200 && !empty($r['json']['success']), $r['raw']);
p155_drain();
$primaryDeliveries = $delivered($subPrimary, 'incident.primary_changed');
t('EXACTLY ONE incident.primary_changed delivery (it was two: the audit envelope AND an SSE-derived bare payload)',
    $primaryDeliveries === 1, (string) $primaryDeliveries);
$ssrc = (string) file_get_contents(__DIR__ . '/../inc/sse.php');
t("inc/sse.php names the suppressed type", strpos($ssrc, "'incident:primary_changed'") !== false);
t('the browser still receives the SSE event (it is only the second webhook delivery that is suppressed)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'incident:primary_changed' AND JSON_EXTRACT(payload, '$.ticket_id') = ?", [$tp]) >= 1);

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
