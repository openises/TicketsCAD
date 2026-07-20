<?php
/**
 * Phase 99v-4 follow-on (Eric beta 2026-06-30) — Test-send endpoint.
 *
 * Admin clicks "Send test" in the Settings → Message Routing form.
 * This endpoint fires a synthetic message through router_forward
 * end-to-end so the admin gets the real notification (push on phone,
 * Slack message, etc.) — turning route configuration from "I think
 * this works" into "I just saw it work."
 *
 * POST /api/router-test-send.php
 *   {
 *     "predicate":     {...} | null,    // recipient predicate (null = channel broadcast)
 *     "dest_channel":  "push" | "slack" | etc.,
 *     "sample_payload": {"ticket_id": 73, "severity": 3, ...} | null,
 *     "route_name":    "human-readable label for the audit log",
 *     "route_id":      int | null       // if test-sending a saved route
 *   }
 *
 * Returns:
 *   {
 *     "ok": true,
 *     "delivered": N,
 *     "recipients_resolved": N,
 *     "channel": "push",
 *     "note": "[TEST] message routed to N users via push."
 *   }
 *
 * Admin-only via action.manage_routing.
 *
 * SAFETY:
 *   - Body is prefixed "[TEST] " so recipients know it's not real
 *   - subject is prefixed "[TEST] "
 *   - Audit logged as category=config, activity=test_send so it's
 *     easy to find later
 *   - Synthetic _is_routed_forward=true so loop-prevention works
 *   - source_channel is 'audit_event' so the dispatch matches the
 *     production push path
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/broker.php';
require_once __DIR__ . '/../inc/router.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

if (!is_admin() && !rbac_can('action.manage_routing')) {
    json_error('Insufficient permissions', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$predicate    = $input['predicate']      ?? null;
$destChannel  = trim((string) ($input['dest_channel'] ?? ''));
$samplePayload = is_array($input['sample_payload'] ?? null) ? $input['sample_payload'] : [];
$routeName    = trim((string) ($input['route_name'] ?? 'Untitled route under test'));
$routeIdHint  = (int) ($input['route_id'] ?? 0);

if (!$destChannel) {
    json_error('dest_channel required');
}

// Build a synthetic message. The body + subject get the [TEST] marker so
// recipients can't mistake it for a real event. We also stamp the actor
// (whoever clicked the button) so cross-org tests don't look like spam.
$actorUser = $_SESSION['user'] ?? 'admin';
$nowText   = date('H:i');
$testBody  = "[TEST] Message-routing test from $actorUser at $nowText. "
           . "If you received this, your route '$routeName' is configured correctly. No action required.";

$message = array_merge($samplePayload, [
    '_event_type'    => 'route.test',
    '_event_payload' => $samplePayload,
    'subject'        => "[TEST] $routeName",
    'body'           => $testBody,
    '_test_send'     => true,
    '_test_actor'    => $actorUser,
]);

// Build a synthetic route shaped just enough for router_forward. The
// id=0 sentinel is a flag that this is a transient test route — the
// routing_log row will record route_id=0 + the human name so admins
// can correlate.
$route = [
    'id'                       => $routeIdHint,
    'name'                     => "[TEST] $routeName",
    'enabled'                  => 1,
    'priority'                 => 9999,
    'source_channel'           => 'audit_event',
    'dest_channel'             => $destChannel,
    'direction'                => 'outbound',
    'filters_json'             => null,
    'transform_json'           => null,
    'dest_subaddress_json'     => null,
    'recipient_predicate_json' => $predicate ? json_encode($predicate) : null,
    'routing_kind'             => 'broadcast',
];

try {
    $result = router_forward($route, $message, null);
} catch (Throwable $e) {
    json_error('router_forward failed: ' . $e->getMessage(), 500);
}

$status = $result['status'] ?? 'failed';
$err    = $result['error']  ?? null;

// Audit the test send so it shows up in the recent-activity log next
// to the admin's other config actions. Doesn't fail the response if
// audit_log itself can't write.
audit_log('config', 'test_send', 'message_route', $routeIdHint ?: null,
    "Test-send fired '$routeName' via $destChannel (result: $status)",
    [
        'dest_channel'  => $destChannel,
        'predicate'     => $predicate,
        'sample_payload' => $samplePayload,
        'result'        => $result,
    ]);

if ($status !== 'forwarded') {
    json_response([
        'ok'      => false,
        'status'  => $status,
        'error'   => $err,
        'channel' => $destChannel,
        'note'    => "Test message did NOT deliver. Channel '{$destChannel}' returned: " . ($err ?? $status),
    ]);
}

// Success — pull the recipients-resolved count from the result if the
// channel adapter returned it (push does; others may not).
$resolved = $result['recipients_resolved'] ?? null;
$delivered = $result['delivered'] ?? null;

$note = "[TEST] Sent via $destChannel.";
if ($resolved !== null) $note .= " Predicate resolved to $resolved recipient(s).";
if ($delivered !== null) $note .= " $delivered push(es) actually delivered.";
if ($predicate === null) $note .= " (No predicate — channel-broadcast send.)";

json_response([
    'ok'                 => true,
    'status'             => $status,
    'channel'            => $destChannel,
    'recipients_resolved' => $resolved,
    'delivered'          => $delivered,
    'note'               => $note,
]);
