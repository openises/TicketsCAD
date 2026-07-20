<?php
/**
 * NewUI v4.0 API — Server-Sent Events (SSE) Stream
 *
 * GET /api/stream.php
 *
 * Pushes real-time updates to connected clients via SSE.
 * The server polls the `sse_events` table every few seconds and sends
 * any new events since the client's last-seen ID.
 *
 * Events sent:
 *   incident:new       — New incident created
 *   incident:update    — Incident fields changed
 *   incident:close     — Incident closed/reopened
 *   incident:note      — Note added to incident
 *   responder:status   — Responder status changed
 *   responder:assign   — Responder assigned/unassigned
 *   facility:update    — Facility data changed
 *   chat:message       — New chat message
 *   system:refresh     — Force full dashboard refresh
 *   system:ping        — Keepalive (every 30s)
 *
 * Query params:
 *   last_id=N          — Resume from event ID (default: latest)
 */

// Phase 84s fix — config.php sets session.cookie_* ini values via
// ini_set(). Those calls MUST happen BEFORE session_start(), otherwise
// PHP emits 'Session ini settings cannot be changed when a session is
// active' warnings. Those warnings stream HTML into the response body,
// then header('Content-Type: text/event-stream') fails with 'headers
// already sent', and the browser EventSource hammers reconnect (the
// yellow status dot). Load config/RBAC FIRST, then start the session.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
// Phase 104e (a beta tester GH #6) — mobile PWA clients send the TCADMOBILE
// session cookie, not PHPSESSID. Every endpoint that calls
// session_start() must pick the matching profile FIRST or PHP just
// opens an empty desktop session and every downstream $_SESSION
// check fails. api/auth.php already does this; api/stream.php did
// not, which caused a beta tester GH #13 regression after 059d108 fixed
// the mobile.js URL (mobile now hits stream.php correctly but the
// endpoint returned {"error":"Not authenticated"} because it was
// reading PHPSESSID that mobile never sent).
require_once __DIR__ . '/../inc/session-bootstrap.php';
sess_bootstrap_auto();

// No output buffering — SSE needs immediate flush. After requiring
// config.php in case it left an output buffer open via display_errors.
while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');  // never emit warnings into the stream body

// SSE streams run long — override PHP's default execution timeout
// The stream self-terminates after $maxRuntime (300s) and the client reconnects
set_time_limit(360); // 6 minutes (above $maxRuntime of 5 min to allow clean shutdown)
ignore_user_abort(false); // Stop when client disconnects

// Auth check (session-based)
session_start();
if (function_exists('sess_touch_mobile_cookie')) sess_touch_mobile_cookie();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo "data: {\"error\":\"Not authenticated\"}\n\n";
    exit;
}
$userId     = (int) $_SESSION['user_id'];
$userLevel  = (int) ($_SESSION['level'] ?? 99);
$userGroups = $_SESSION['user_groups'] ?? [];
$userGroups = array_values(array_filter(array_map('intval', is_array($userGroups) ? $userGroups : []), function ($g) { return $g > 0; }));

$userIsAdmin = is_admin();

// GH #13 (2026-07-07) — RBAC entitlement, computed while the session is
// still open. Mirrors the READ path (inc/access.php + api/incidents.php):
// a user holding an entity's RBAC view permission can see that entity on
// every page, so they must also receive its events — group-scoped or the
// 'entitled' no-allocates fallback. Before this, those events collapsed to
// admin-only whenever an incident had no allocates rows (the common case on
// installs that don't use group allocation), so non-admin mobile users got
// NO CAD→mobile real-time updates at all.
$entitledPrefixes = [];
if (!$userIsAdmin) {
    require_once __DIR__ . '/../inc/rbac.php';
    if (function_exists('rbac_can')) {
        $entPermMap = [
            // event_type prefix => RBAC view permissions (same lists as
            // inc/access.php). chat:message is published per-incident.
            'incident:%'  => ['screen.incidents', 'screen.incident_detail', 'incident.view', 'widget.incidents'],
            'chat:%'      => ['screen.incidents', 'screen.incident_detail', 'incident.view', 'widget.incidents'],
            // GH #13 round 4 (a beta tester 2026-07-07): responder:status events are
            // published PER-INCIDENT (unit status changes on a call), and the
            // mobile read path (api/mobile-data.php) already shows unit
            // statuses to any authenticated user. Field Unit holds
            // screen.incidents but none of the units-screen perms, so
            // CAD->mobile status changes were withheld while notes flowed.
            // Union the incident-view perms so the stream matches the pages.
            'responder:%' => ['screen.units', 'screen.unit_detail', 'responder.view', 'unit.view', 'widget.units',
                              'screen.incidents', 'screen.incident_detail', 'incident.view', 'widget.incidents'],
            'facility:%'  => ['screen.facilities', 'screen.facility_detail', 'facility.view', 'widget.facilities'],
        ];
        foreach ($entPermMap as $pfx => $perms) {
            foreach ($perms as $p) {
                if (rbac_can($p)) { $entitledPrefixes[] = $pfx; break; }
            }
        }
    }
}

// Release session lock so other requests aren't blocked
session_write_close();

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Ensure the sse_events table exists with the F-007 visibility columns.
// Schema upkeep is centralised in inc/sse.php so the publisher and consumer
// agree on the shape.
$prefix = $GLOBALS['db_prefix'] ?? '';
require_once __DIR__ . '/../inc/sse.php';
_sse_ensure_schema();

// Build the per-user visibility WHERE clause and parameter array. All connected
// users see 'public' events; admins additionally see 'admin' events; group
// members see 'group' events whose visibility_ids intersect their groups; users
// see 'user' events targeted to themselves.
$visibilityClauses = ["`visibility_scope` = 'public'"];
$visibilityParams  = [];

if ($userIsAdmin) {
    $visibilityClauses[] = "`visibility_scope` IN ('admin','group','entitled')";
} else {
    if (!empty($userGroups)) {
        $groupOrs = [];
        foreach ($userGroups as $g) {
            $groupOrs[] = "FIND_IN_SET(?, `visibility_ids`) > 0";
            $visibilityParams[] = (string) $g;
        }
        $visibilityClauses[] = "(`visibility_scope` = 'group' AND (" . implode(' OR ', $groupOrs) . "))";
    }
    // GH #13 — RBAC view-permission holders receive the entity's events
    // (both group-scoped and the 'entitled' no-allocates fallback), exactly
    // as the read path already lets them view the entity itself.
    foreach ($entitledPrefixes as $pfx) {
        $visibilityClauses[] = "(`visibility_scope` IN ('group','entitled') AND `event_type` LIKE ?)";
        $visibilityParams[] = $pfx;
    }
}

// Per-user notifications — visibility_ids may carry multiple recipients
// (e.g. multi-recipient messaging) so use FIND_IN_SET, not strict equality.
$visibilityClauses[] = "(`visibility_scope` = 'user' AND FIND_IN_SET(?, `visibility_ids`) > 0)";
$visibilityParams[] = (string) $userId;

$visibilityWhere = '(' . implode(' OR ', $visibilityClauses) . ')';

// Determine starting point
$lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : 0;
if ($lastId === 0) {
    // Start from the latest event
    try {
        $maxId = db_fetch_value("SELECT MAX(id) FROM `{$prefix}sse_events`");
        $lastId = $maxId ? (int) $maxId : 0;
    } catch (Exception $e) {
        $lastId = 0;
    }
}

// Send initial connection event
echo "event: connected\n";
echo "data: " . json_encode([
    'user_id'  => $userId,
    'last_id'  => $lastId,
    'server_time' => date('c')
]) . "\n\n";
flush();

// Polling loop
$pollInterval = 3;      // Seconds between DB polls
$pingInterval = 30;     // Seconds between keepalive pings
$maxRuntime   = 300;    // Max 5 minutes per connection (client reconnects automatically)
$startTime    = time();
$lastPing     = time();
$lastAutoSweep = 0; // Force first sweep on the very first iteration

while (true) {
    // Check if client disconnected
    if (connection_aborted()) break;

    // Check max runtime
    if ((time() - $startTime) >= $maxRuntime) {
        echo "event: reconnect\n";
        echo "data: {\"reason\":\"max_runtime\"}\n\n";
        flush();
        break;
    }

    // Issue #11 (a beta tester 2026-07-03): auto-close incidents needed a
    // sweep driver — the code path that SCHEDULES the close
    // (assignment-write.php after last unit clears) was working,
    // but only api/incidents.php GET was firing the sweep that
    // ACTUALLY closes the ticket. A dispatcher who cleared the
    // last unit and stayed on the incident-detail page never
    // triggered a sweep, so the ticket sat scheduled indefinitely.
    // Run the sweep from the SSE loop so every active dispatcher's
    // stream drives closes at grace-second cadence. Runs once every
    // 5s per connection, bounded by LIMIT 20 in the sweep query.
    if ((time() - $lastAutoSweep) >= 5) {
        try {
            require_once __DIR__ . '/../inc/auto_close.php';
            auto_close_sweep(20);
        } catch (Throwable $e) {
            error_log('[stream] auto_close_sweep: ' . $e->getMessage());
        }
        $lastAutoSweep = time();
    }

    // Poll for new events — visibility-filtered per F-007
    try {
        $events = db_fetch_all(
            "SELECT id, event_type, payload, user_id, created_at
             FROM `{$prefix}sse_events`
             WHERE id > ?
               AND {$visibilityWhere}
             ORDER BY id ASC
             LIMIT 50",
            array_merge([$lastId], $visibilityParams)
        );

        foreach ($events as $evt) {
            $lastId = (int) $evt['id'];

            // Don't echo the user's own events back to them (optional)
            // Uncomment if you want to skip self-originated events:
            // if ((int) $evt['user_id'] === $userId) continue;

            echo "id: " . $lastId . "\n";
            echo "event: " . $evt['event_type'] . "\n";

            $payload = json_decode($evt['payload'], true) ?: [];
            $payload['_event_id'] = $lastId;
            $payload['_timestamp'] = $evt['created_at'];
            $payload['_origin_user'] = (int) $evt['user_id'];

            echo "data: " . json_encode($payload) . "\n\n";
            flush();
        }
    } catch (Exception $e) {
        // DB error — send error event and continue
        echo "event: error\n";
        echo "data: " . json_encode(['message' => 'DB poll failed']) . "\n\n";
        flush();
    }

    // Keepalive ping
    if ((time() - $lastPing) >= $pingInterval) {
        echo "event: ping\n";
        echo "data: " . json_encode(['time' => date('c')]) . "\n\n";
        flush();
        $lastPing = time();
    }

    // Cleanup old events (older than 1 hour) — do this occasionally
    if (random_int(1, 100) === 1) {
        try {
            db_query(
                "DELETE FROM `{$prefix}sse_events` WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }

    // Sleep before next poll
    sleep($pollInterval);
}
