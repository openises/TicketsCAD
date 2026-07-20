<?php
/**
 * Cross-Protocol Message Routing Engine Tests
 *
 * Tests the routing engine (inc/router.php) including:
 * - Schema creation
 * - Route CRUD
 * - Filter matching (severity, priority, keywords, incident type, role, combined)
 * - All-matches-fire semantics
 * - Priority ordering
 * - Loop prevention
 * - Channel availability checks
 * - Routing log entries
 * - Message transformation
 * - Direction filtering
 * - Wildcard source matching
 * - Dry-run testing
 * - DMR/Email channels + Phase D unified transport forwarding:
 *     · meshtastic + zello broker stubs removed
 *     · route → mesh queues a mesh_outbox row (channel slot + direct to_node)
 *     · route → Zello: skipped when unconfigured, queues zello_outbox when configured
 *     · dest sub-address persist/decode round-trip
 *     · loop-prevention preserved across the new mesh forward path
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';
require __DIR__ . '/../inc/broker.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Cross-Protocol Message Routing Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

// Ensure prerequisite tables exist
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` INT NOT NULL DEFAULT 0,
        `user_name` VARCHAR(64) NOT NULL DEFAULT 'system', `channel` VARCHAR(64) NOT NULL DEFAULT 'general',
        `recipient` VARCHAR(64) NOT NULL DEFAULT 'all', `body` TEXT NOT NULL,
        `msg_type` VARCHAR(32) NOT NULL DEFAULT 'text', `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
        `ticket_id` INT DEFAULT NULL, `signal_id` INT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`), KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `channel` VARCHAR(64) NOT NULL,
        `direction` ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
        `msg_type` VARCHAR(32) NOT NULL DEFAULT 'general', `sender` VARCHAR(128) NOT NULL DEFAULT 'system',
        `recipient` VARCHAR(256) NOT NULL DEFAULT '', `subject` VARCHAR(256) DEFAULT '',
        `body` TEXT NOT NULL, `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
        `status` VARCHAR(32) NOT NULL DEFAULT 'pending', `error` TEXT DEFAULT NULL,
        `payload` TEXT DEFAULT NULL, `delivered_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}sse_events` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `event_type` VARCHAR(64) NOT NULL,
        `payload` TEXT, `user_id` INT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_type` (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Ensure routing tables exist
_router_ensure_tables();

// Clean up any leftover test data
db_query("DELETE FROM `{$prefix}message_routes` WHERE `name` LIKE 'TEST_%'");
db_query("DELETE FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE '%ROUTING_TEST%'");

// ── Test 1: message_routes table exists ──
echo "[Test 1] message_routes table exists... ";
try {
    $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}message_routes`");
    echo "PASS\n"; $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
}

// ── Test 2: routing_log table exists ──
echo "[Test 2] routing_log table exists... ";
try {
    $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}routing_log`");
    echo "PASS\n"; $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
}

// ── Test 3: Create a route ──
echo "[Test 3] Create route... ";
try {
    $routeId = router_create([
        'name'           => 'TEST_route_1',
        'description'    => 'Test route for unit tests',
        'source_channel' => 'local_chat',
        'dest_channel'   => 'sms',
        'priority'       => 10,
        'direction'      => 'outbound',
        'filters'        => ['priority_in' => ['high', 'urgent']],
        'transform'      => ['prefix' => '[CHAT] ']
    ]);
    if ($routeId > 0) {
        echo "PASS (id=$routeId)\n"; $pass++;
    } else {
        echo "FAIL: no ID returned\n"; $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
    $routeId = 0;
}

// ── Test 4: Fetch route back ──
echo "[Test 4] Fetch route by ID... ";
$route = router_get($routeId);
if ($route && $route['name'] === 'TEST_route_1' && $route['source_channel'] === 'local_chat') {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 5: Update route ──
echo "[Test 5] Update route... ";
try {
    router_update($routeId, ['description' => 'Updated description', 'dest_channel' => 'smtp']);
    $updated = router_get($routeId);
    if ($updated && $updated['description'] === 'Updated description' && $updated['dest_channel'] === 'smtp') {
        echo "PASS\n"; $pass++;
    } else {
        echo "FAIL\n"; $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
}

// Restore dest_channel for later tests
router_update($routeId, ['dest_channel' => 'local_chat']);

// ── Test 6: Toggle route ──
echo "[Test 6] Toggle route enabled/disabled... ";
try {
    router_toggle($routeId, false);
    $toggled = router_get($routeId);
    if ($toggled && (int) $toggled['enabled'] === 0) {
        router_toggle($routeId, true);
        $toggled2 = router_get($routeId);
        if ($toggled2 && (int) $toggled2['enabled'] === 1) {
            echo "PASS\n"; $pass++;
        } else {
            echo "FAIL: re-enable failed\n"; $fail++;
        }
    } else {
        echo "FAIL: disable failed\n"; $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
}

// ── Test 7: Filter — no filters matches any message ──
echo "[Test 7] No filters matches any message... ";
$noFilterId = router_create([
    'name'           => 'TEST_no_filter',
    'source_channel' => 'meshtastic',
    'dest_channel'   => 'local_chat',
    'filters'        => null
]);
$routes = _router_get_routes('meshtastic');
$found = false;
foreach ($routes as $r) {
    if ((int) $r['id'] === $noFilterId) { $found = true; break; }
}
$noFilters = [];
$matchResult = _router_match_filters($noFilters, ['body' => 'anything', 'priority' => 'low']);
if ($found && $matchResult) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 8: Filter — severity_min ──
echo "[Test 8] Severity min filter... ";
$filters = ['severity_min' => 2];
$matchHigh = _router_match_filters($filters, ['body' => 'test', 'severity' => 3]);
$matchLow = _router_match_filters($filters, ['body' => 'test', 'severity' => 1]);
if ($matchHigh && !$matchLow) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL (high=$matchHigh, low=$matchLow)\n"; $fail++;
}

// ── Test 9: Filter — priority_in ──
echo "[Test 9] Priority filter... ";
$filters = ['priority_in' => ['high', 'urgent']];
$matchUrgent = _router_match_filters($filters, ['body' => 'test', 'priority' => 'urgent']);
$matchNormal = _router_match_filters($filters, ['body' => 'test', 'priority' => 'normal']);
if ($matchUrgent && !$matchNormal) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 10: Filter — keywords ──
echo "[Test 10] Keyword filter... ";
$filters = ['keywords' => ['fire', 'mutual aid']];
$matchFire = _router_match_filters($filters, ['body' => 'Structure fire reported at Main St']);
$matchMedical = _router_match_filters($filters, ['body' => 'Medical emergency on Oak Ave']);
if ($matchFire && !$matchMedical) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 11: Filter — exclude_keywords ──
echo "[Test 11] Exclude keyword filter... ";
$filters = ['exclude_keywords' => ['test', 'drill']];
$matchReal = _router_match_filters($filters, ['body' => 'Real emergency at 123 Main St']);
$matchTest = _router_match_filters($filters, ['body' => 'This is a test message']);
if ($matchReal && !$matchTest) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 12: Filter — incident_type_ids ──
echo "[Test 12] Incident type filter... ";
$filters = ['incident_type_ids' => [1, 3, 7]];
$matchType3 = _router_match_filters($filters, ['body' => 'test', 'in_types_id' => 3]);
$matchType5 = _router_match_filters($filters, ['body' => 'test', 'in_types_id' => 5]);
$matchNone = _router_match_filters($filters, ['body' => 'test']);
if ($matchType3 && !$matchType5 && !$matchNone) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 13: Filter — sender_roles ──
echo "[Test 13] Sender role filter... ";
$filters = ['sender_roles' => [0, 1]];
$matchAdmin = _router_match_filters($filters, ['body' => 'test', 'sender_role' => 0]);
$matchOp = _router_match_filters($filters, ['body' => 'test', 'sender_role' => 3]);
if ($matchAdmin && !$matchOp) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 14: Filter — combined AND ──
echo "[Test 14] Combined AND filters... ";
$filters = ['severity_min' => 2, 'keywords' => ['fire']];
$matchBoth = _router_match_filters($filters, ['body' => 'Structure fire', 'severity' => 3]);
$matchKeywordOnly = _router_match_filters($filters, ['body' => 'Structure fire', 'severity' => 1]);
$matchSeverityOnly = _router_match_filters($filters, ['body' => 'Medical call', 'severity' => 3]);
if ($matchBoth && !$matchKeywordOnly && !$matchSeverityOnly) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 15: All-matches-fire — two routes both execute ──
echo "[Test 15] All matches fire (two routes)... ";
// Create two routes from meshtastic -> local_chat with no filters
$routeA = router_create([
    'name'           => 'TEST_multi_A',
    'source_channel' => 'meshtastic',
    'dest_channel'   => 'local_chat',
    'priority'       => 10
]);
$routeB = router_create([
    'name'           => 'TEST_multi_B',
    'source_channel' => 'meshtastic',
    'dest_channel'   => 'local_chat',
    'priority'       => 20
]);
$results = router_evaluate('meshtastic', 'inbound', [
    'body' => 'ROUTING_TEST all-matches-fire test',
    'type' => 'text',
    'priority' => 'normal'
]);
$foundA = false;
$foundB = false;
foreach ($results as $r) {
    if ($r['route_id'] === $routeA) $foundA = true;
    if ($r['route_id'] === $routeB) $foundB = true;
}
if ($foundA && $foundB && count($results) >= 2) {
    echo "PASS (both routes fired)\n"; $pass++;
} else {
    echo "FAIL (foundA=$foundA, foundB=$foundB, count=" . count($results) . ")\n"; $fail++;
}

// ── Test 16: Priority ordering ──
echo "[Test 16] Priority ordering... ";
// routeA has priority 10, routeB has priority 20 — A should fire first
if (!empty($results) && $results[0]['route_id'] === $routeA && $results[1]['route_id'] === $routeB) {
    echo "PASS (A=10 before B=20)\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 17: Disabled route skipped ──
echo "[Test 17] Disabled route skipped... ";
router_toggle($routeA, false);
$results2 = router_evaluate('meshtastic', 'inbound', [
    'body' => 'ROUTING_TEST disabled-test',
    'type' => 'text',
    'priority' => 'normal'
]);
$foundA2 = false;
foreach ($results2 as $r) {
    if ($r['route_id'] === $routeA) $foundA2 = true;
}
if (!$foundA2) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: disabled route still fired\n"; $fail++;
}
router_toggle($routeA, true);

// ── Test 18: Loop prevention — _routed flag (router-internal forward) ──
// Phase 73u — caller-supplied _routed is discarded unless the
// message is flagged as a router-internal forward via
// _is_routed_forward. The pre-Phase-73u form of this test (no flag
// set) would now correctly pass through to evaluate, because
// untrusted input starts depth=0, routed=[]. To assert the loop-
// prevention semantics we must mark the message as trusted.
echo "[Test 18] Loop prevention (_routed flag)... ";
$results3 = router_evaluate('meshtastic', 'inbound', [
    'body'     => 'ROUTING_TEST loop-test',
    'type'     => 'text',
    '_is_routed_forward' => true,
    '_routed'  => [$routeA, $routeB, $noFilterId],
    '_route_depth' => 0
]);
// All meshtastic routes should be skipped because they're already in _routed
$fired = false;
foreach ($results3 as $r) {
    if (in_array($r['route_id'], [$routeA, $routeB, $noFilterId])) {
        $fired = true;
    }
}
if (!$fired) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: routed route still fired\n"; $fail++;
}

// ── Test 19: Loop prevention — depth limit ──
// Phase 73u — likewise must mark trusted to honour depth.
echo "[Test 19] Loop prevention (depth limit)... ";
$results4 = router_evaluate('meshtastic', 'inbound', [
    'body'         => 'ROUTING_TEST depth-test',
    'type'         => 'text',
    '_is_routed_forward' => true,
    '_route_depth' => ROUTER_MAX_DEPTH
]);
if (empty($results4)) {
    echo "PASS (blocked at depth " . ROUTER_MAX_DEPTH . ")\n"; $pass++;
} else {
    echo "FAIL: should have been blocked\n"; $fail++;
}

// ── Test 19b (NEW for Phase 73u): untrusted depth=99 is ignored ──
// A malicious caller sending _route_depth=99 without the trusted
// flag should NOT short-circuit routing. The message must be
// evaluated fresh (depth=0).
echo "[Test 19b] Untrusted depth claim ignored... ";
$results4b = router_evaluate('meshtastic', 'inbound', [
    'body'         => 'ROUTING_TEST untrusted-depth',
    'type'         => 'text',
    '_route_depth' => 99,   // forged — no _is_routed_forward flag
]);
// At least one route should have fired (the no-filter route).
$firedFresh = false;
foreach ($results4b as $r) {
    if ($r['route_id'] == $noFilterId) { $firedFresh = true; break; }
}
if ($firedFresh) {
    echo "PASS (caller-supplied depth bypassed)\n"; $pass++;
} else {
    echo "FAIL: untrusted depth claim short-circuited routing\n"; $fail++;
}

// ── Test 20: Channel availability — skip unconfigured dest ──
echo "[Test 20] Skip non-existent dest channel... ";
$routeGhost = router_create([
    'name'           => 'TEST_ghost_dest',
    'source_channel' => 'local_chat',
    'dest_channel'   => 'nonexistent_protocol',
    'priority'       => 5
]);
$results5 = router_evaluate('local_chat', 'outbound', [
    'body' => 'ROUTING_TEST ghost-dest',
    'type' => 'text'
]);
$ghostResult = null;
foreach ($results5 as $r) {
    if ($r['route_id'] === $routeGhost) $ghostResult = $r;
}
if ($ghostResult && $ghostResult['status'] === 'skipped') {
    echo "PASS (skipped: " . $ghostResult['error'] . ")\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 21: Routing log entry created ──
echo "[Test 21] Routing log entry created... ";
try {
    $logEntry = db_fetch_one(
        "SELECT * FROM `{$prefix}routing_log` WHERE `route_id` = ? ORDER BY id DESC LIMIT 1",
        [$routeGhost]
    );
    if ($logEntry && $logEntry['status'] === 'skipped' && $logEntry['source_channel'] === 'local_chat') {
        echo "PASS\n"; $pass++;
    } else {
        echo "FAIL\n"; $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
}

// ── Test 22: Transform — prefix ──
echo "[Test 22] Transform prefix... ";
$transform = ['prefix' => '[MESH] '];
$original = ['body' => 'Help needed at sector 4', 'priority' => 'normal'];
$transformed = _router_transform($transform, $original, 'meshtastic');
if ($transformed['body'] === '[MESH] Help needed at sector 4' && $transformed['priority'] === 'normal') {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: body={$transformed['body']}\n"; $fail++;
}

// ── Test 23: Transform — override priority ──
echo "[Test 23] Transform override priority... ";
$transform = ['override_priority' => 'urgent'];
$transformed = _router_transform($transform, $original, 'meshtastic');
if ($transformed['priority'] === 'urgent') {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 24: Transform — {source} placeholder in prefix ──
echo "[Test 24] Transform {source} placeholder... ";
$transform = ['prefix' => '[From {source}] '];
$transformed = _router_transform($transform, $original, 'meshtastic');
if ($transformed['body'] === '[From meshtastic] Help needed at sector 4') {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: body={$transformed['body']}\n"; $fail++;
}

// ── Test 25: Direction filter — outbound route ignores inbound ──
echo "[Test 25] Direction filter... ";
$routeOut = router_create([
    'name'           => 'TEST_outbound_only',
    'source_channel' => 'local_chat',
    'dest_channel'   => 'local_chat',
    'direction'      => 'outbound',
    'priority'       => 5
]);
$results6 = router_evaluate('local_chat', 'inbound', [
    'body' => 'ROUTING_TEST direction-test',
    'type' => 'text'
]);
$foundOutRoute = false;
foreach ($results6 as $r) {
    if ($r['route_id'] === $routeOut) $foundOutRoute = true;
}
if (!$foundOutRoute) {
    echo "PASS (outbound-only route skipped for inbound)\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 26: Wildcard source matches any channel ──
echo "[Test 26] Wildcard source channel... ";
$routeWild = router_create([
    'name'           => 'TEST_wildcard',
    'source_channel' => '*',
    'dest_channel'   => 'local_chat',
    'priority'       => 999
]);
$routesForSms = _router_get_routes('sms');
$foundWild = false;
foreach ($routesForSms as $r) {
    if ((int) $r['id'] === $routeWild) { $foundWild = true; break; }
}
if ($foundWild) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 27: Dry-run test ──
echo "[Test 27] Dry-run test (router_test)... ";
$matches = router_test('local_chat', 'outbound', [
    'body'     => 'ROUTING_TEST dry-run urgent message',
    'priority' => 'urgent',
    'type'     => 'text'
]);
// Should match route_1 (priority_in: high/urgent, outbound) and the wildcard
$matchIds = array_column($matches, 'route_id');
if (in_array($routeId, $matchIds)) {
    echo "PASS (matched " . count($matches) . " routes)\n"; $pass++;
} else {
    echo "FAIL (expected route $routeId in matches: " . implode(',', $matchIds) . ")\n"; $fail++;
}

// ── Test 28: Dry-run shows transformed preview ──
echo "[Test 28] Dry-run shows transformed message... ";
$previewFound = false;
foreach ($matches as $m) {
    if ($m['route_id'] === $routeId && strpos($m['transformed']['body'], '[CHAT]') === 0) {
        $previewFound = true;
        break;
    }
}
if ($previewFound) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 29: Delete route ──
echo "[Test 29] Delete route... ";
router_delete($routeId);
$deleted = router_get($routeId);
if ($deleted === null) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: route still exists\n"; $fail++;
}

// ── Test 30: Reorder routes ──
echo "[Test 30] Reorder routes (bulk priority update)... ";
try {
    router_reorder([
        ['id' => $routeA, 'priority' => 500],
        ['id' => $routeB, 'priority' => 200]
    ]);
    $rA = router_get($routeA);
    $rB = router_get($routeB);
    if ($rA && (int) $rA['priority'] === 500 && $rB && (int) $rB['priority'] === 200) {
        echo "PASS\n"; $pass++;
    } else {
        echo "FAIL\n"; $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n"; $fail++;
}

// ── Test 31: Get all routes ──
echo "[Test 31] Get all routes (router_get_all)... ";
$all = router_get_all();
$testCount = 0;
foreach ($all as $r) {
    if (strpos($r['name'], 'TEST_') === 0) $testCount++;
}
if ($testCount >= 5) {
    echo "PASS (found $testCount test routes)\n"; $pass++;
} else {
    echo "FAIL (expected >= 5, found $testCount)\n"; $fail++;
}

// ── Test 32: Routing log with pagination ──
echo "[Test 32] Routing log pagination... ";
$log = router_get_log(10, 0);
$logCount = router_get_log_count();
if (is_array($log) && $logCount >= 0) {
    echo "PASS (count=$logCount)\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 33: Filter — incident_id ──
echo "[Test 33] Incident ID filter... ";
$filters = ['incident_id' => 42];
$matchRight = _router_match_filters($filters, ['body' => 'test', 'ticket_id' => 42]);
$matchWrong = _router_match_filters($filters, ['body' => 'test', 'ticket_id' => 99]);
$matchNone = _router_match_filters($filters, ['body' => 'test']);
if ($matchRight && !$matchWrong && !$matchNone) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 34 (Phase D): Zello broker stub is GONE ──
// The dead inc/channels/zello.php stub was deleted; zello is no longer a
// broker-registered channel — it's a unified routing DESTINATION reached via
// zello_outbox. Assert it's not in the broker registry anymore.
echo "[Test 34] Zello broker stub removed... ";
$statuses = broker_channel_statuses();
$codes = array_column($statuses, 'code');
if (!in_array('zello', $codes)) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL (zello still registered: " . implode(', ', $codes) . ")\n"; $fail++;
}

// ── Test 35: DMR channel registered (dmr.php stub kept — Phase E target) ──
echo "[Test 35] DMR channel registered... ";
if (in_array('dmr', $codes)) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 36: Email channel registered ──
echo "[Test 36] Email channel registered... ";
if (in_array('email', $codes)) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL\n"; $fail++;
}

// ── Test 37: Meshtastic is a REAL broker channel (Phase 99a) ──
// Phase D deleted the dead meshtastic stub; Phase 99a (2026-06-28) then
// re-registered meshtastic as a real channel that queues into mesh_outbox
// (inc/channels/meshtastic.php) so the unified Compose form can
// broker_send('meshtastic', ...). Assert the real handler is wired.
echo "[Test 37] Meshtastic registered as real mesh_outbox channel... ";
if (in_array('meshtastic', $codes) && function_exists('_meshtastic_send')
    && function_exists('_mesh_broker_send_common')) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: meshtastic real channel handler missing\n"; $fail++;
}

// ── Test 38: DMR status is not_configured ──
echo "[Test 38] DMR status not_configured... ";
$dmrStatus = null;
foreach ($statuses as $s) {
    if ($s['code'] === 'dmr') $dmrStatus = $s['status'];
}
if ($dmrStatus === 'not_configured') {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: status=$dmrStatus\n"; $fail++;
}

// ── Test 39 (Phase D): route to mesh QUEUES a mesh_outbox row (was 'failed') ──
// A routing rule whose dest is mesh:meshtastic with a channel-slot sub-address
// must FORWARD by queuing a mesh_outbox send_text — not log the old dead-stub
// 'failed'. We ensure the queue table exists, run the migration columns, create
// the route, fire it, and assert: status='forwarded' AND a fresh mesh_outbox row.
echo "[Test 39] Route to mesh queues mesh_outbox (forwarded)... ";
// Ensure the mesh_outbox table + Phase D sub-address column exist for the test DB.
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}mesh_outbox` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `queued_by` INT DEFAULT NULL,
        `target_bridge_id` INT UNSIGNED DEFAULT NULL,
        `target_protocol` ENUM('meshtastic','meshcore','any') NOT NULL DEFAULT 'any',
        `kind` VARCHAR(32) NOT NULL,
        `payload_json` TEXT NOT NULL,
        `status` ENUM('queued','claimed','sent','failed') NOT NULL DEFAULT 'queued',
        `claimed_at` DATETIME DEFAULT NULL,
        `claimed_by_bridge_id` INT UNSIGNED DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `result_json` TEXT DEFAULT NULL,
        `error` VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hasSubCol = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'dest_subaddress_json'",
        [$prefix . 'message_routes']
    );
    if (!$hasSubCol) {
        db_query("ALTER TABLE `{$prefix}message_routes` ADD COLUMN `dest_subaddress_json` TEXT DEFAULT NULL");
    }
} catch (Exception $e) {}

$meshOutboxBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mesh_outbox`");
$meshRouteId = router_create([
    'name'            => 'TEST_mesh_forward',
    'source_channel'  => 'local_chat',
    'dest_channel'    => 'mesh:meshtastic',
    'priority'        => 3,
    'direction'       => 'outbound',
    'dest_subaddress' => ['channel_slot' => 1],
]);
$meshResults = router_evaluate('local_chat', 'outbound', [
    'body' => 'ROUTING_TEST mesh forward to slot 1',
    'type' => 'text'
]);
$meshRow = null;
foreach ($meshResults as $r) {
    if ($r['route_id'] === $meshRouteId) $meshRow = $r;
}
$meshOutboxAfter = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mesh_outbox`");
if ($meshRow && $meshRow['status'] === 'forwarded' && $meshOutboxAfter === $meshOutboxBefore + 1) {
    // Verify the queued row carries the right protocol + slot, and is NOT a DM.
    $ob = db_fetch_one("SELECT * FROM `{$prefix}mesh_outbox` ORDER BY id DESC LIMIT 1");
    $pl = json_decode($ob['payload_json'], true);
    if ($ob['target_protocol'] === 'meshtastic' && (int) $pl['channel_slot'] === 1 && !isset($pl['to_node'])) {
        echo "PASS (queued send_text slot 1)\n"; $pass++;
    } else {
        echo "FAIL: wrong payload " . $ob['payload_json'] . "\n"; $fail++;
    }
} else {
    echo "FAIL (status=" . ($meshRow['status'] ?? 'none') . ", outbox " . $meshOutboxBefore . "->" . $meshOutboxAfter . ")\n"; $fail++;
}

// ── Test 40: DMR text send still returns not_implemented (honest stub) ──
// _dmr_send() (Phase 99e) validates the recipient first — tg:<id> or
// radioid:<id> — then reports the text-data path as dmr_text_not_implemented
// until BrandMeister HBP DATA framing lands. Use a valid talkgroup target so
// we exercise the stub response, not the recipient validation.
echo "[Test 40] DMR text send returns not_implemented... ";
$dResult = broker_send('dmr', ['body' => 'test', 'to' => 'tg:99']);
if (!$dResult['success'] && strpos($dResult['error'] ?? '', 'not_implemented') !== false) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL (error=" . ($dResult['error'] ?? 'none') . ")\n"; $fail++;
}

// ── Test 41 (Phase D): mesh DM via raw to_node sub-address ──
echo "[Test 41] Route to mesh DM (to_node) queues direct... ";
$dmRouteId = router_create([
    'name'            => 'TEST_mesh_dm',
    'source_channel'  => 'local_chat',
    'dest_channel'    => 'mesh:meshcore',
    'priority'        => 3,
    'direction'       => 'outbound',
    'dest_subaddress' => ['to_node' => 'a1b2c3d4e5f6'],
]);
$dmResults = router_evaluate('local_chat', 'outbound', [
    'body' => 'ROUTING_TEST mesh DM', 'type' => 'text'
]);
$dmRow = null;
foreach ($dmResults as $r) { if ($r['route_id'] === $dmRouteId) $dmRow = $r; }
$obDm = db_fetch_one("SELECT * FROM `{$prefix}mesh_outbox` ORDER BY id DESC LIMIT 1");
$plDm = json_decode($obDm['payload_json'], true);
if ($dmRow && $dmRow['status'] === 'forwarded'
    && $obDm['target_protocol'] === 'meshcore'
    && ($plDm['to_node'] ?? '') === 'a1b2c3d4e5f6') {
    echo "PASS (direct to_node queued)\n"; $pass++;
} else {
    echo "FAIL (status=" . ($dmRow['status'] ?? 'none') . ", payload=" . $obDm['payload_json'] . ")\n"; $fail++;
}

// ── Test 42 (Phase D): route to Zello with no Zello config → 'skipped' ──
// With no zello_* settings present, a Zello route is a config gap, not a
// delivery failure — it must log 'skipped', not 'failed' or a fake 'forwarded'.
echo "[Test 42] Route to Zello (unconfigured) is skipped... ";
$hadZello = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` LIKE 'zello\\_%'");
$zRouteId = router_create([
    'name'            => 'TEST_zello_route',
    'source_channel'  => 'local_chat',
    'dest_channel'    => 'zello',
    'priority'        => 3,
    'direction'       => 'outbound',
    'dest_subaddress' => ['channel' => 'Dispatch'],
]);
$zResults = router_evaluate('local_chat', 'outbound', [
    'body' => 'ROUTING_TEST zello route', 'type' => 'text'
]);
$zRow = null;
foreach ($zResults as $r) { if ($r['route_id'] === $zRouteId) $zRow = $r; }
if ($hadZello === 0) {
    // Unconfigured → skipped
    if ($zRow && $zRow['status'] === 'skipped') {
        echo "PASS (skipped, not faked)\n"; $pass++;
    } else {
        echo "FAIL: status=" . ($zRow['status'] ?? 'none') . "\n"; $fail++;
    }
} else {
    // Zello is configured on this DB → it should queue (forwarded).
    if ($zRow && $zRow['status'] === 'forwarded') {
        echo "PASS (zello configured → queued)\n"; $pass++;
    } else {
        echo "FAIL: status=" . ($zRow['status'] ?? 'none') . "\n"; $fail++;
    }
}

// ── Test 43 (Phase D): Zello route QUEUES a zello_outbox row when configured ──
// Force a minimal zello_* setting so the configured path is exercised, and
// assert a zello_outbox row is queued (proxy-drained — never sent live here).
echo "[Test 43] Configured Zello route queues zello_outbox... ";
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}zello_outbox` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `kind` VARCHAR(16) NOT NULL DEFAULT 'text',
        `channel` VARCHAR(100) NOT NULL DEFAULT '',
        `recipient` VARCHAR(100) NOT NULL DEFAULT '',
        `body` TEXT NOT NULL,
        `status` ENUM('queued','claimed','sent','failed') NOT NULL DEFAULT 'queued',
        `error` VARCHAR(255) DEFAULT NULL,
        `queued_by` INT UNSIGNED DEFAULT NULL,
        `source` VARCHAR(32) NOT NULL DEFAULT 'router',
        `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `claimed_at` DATETIME DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}settings` (`name` VARCHAR(191) PRIMARY KEY, `value` TEXT)");
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('zello_service','test')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $injectedZello = true;
} catch (Exception $e) { $injectedZello = false; }

// Use a unique channel + body so we can scope the assertion to THIS route's
// queued row (other zello routes from earlier tests also match local_chat).
$z2RouteId = router_create([
    'name'            => 'TEST_zello_queue',
    'source_channel'  => 'local_chat',
    'dest_channel'    => 'zello',
    'priority'        => 3,
    'direction'       => 'outbound',
    'dest_subaddress' => ['channel' => 'DispatchPhaseD'],
]);
$z2Body = 'ROUTING_TEST zello queue ' . uniqid();
$z2Results = router_evaluate('local_chat', 'outbound', [
    'body' => $z2Body, 'type' => 'text'
]);
$z2Row = null;
foreach ($z2Results as $r) { if ($r['route_id'] === $z2RouteId) $z2Row = $r; }
$zob = db_fetch_one(
    "SELECT * FROM `{$prefix}zello_outbox` WHERE body = ? AND channel = 'DispatchPhaseD' ORDER BY id DESC LIMIT 1",
    [$z2Body]
);
if ($z2Row && $z2Row['status'] === 'forwarded' && $zob
    && $zob['status'] === 'queued' && $zob['source'] === 'router') {
    echo "PASS (queued for proxy, status=queued)\n"; $pass++;
} else {
    echo "FAIL (status=" . ($z2Row['status'] ?? 'none') . ", row=" . ($zob ? json_encode($zob) : 'none') . ")\n"; $fail++;
}

// ── Test 44 (Phase D): sub-address survives create→get round-trip ──
echo "[Test 44] dest_subaddress persists + decodes... ";
$rtRoute = router_get($meshRouteId);
$sub = !empty($rtRoute['dest_subaddress_json']) ? json_decode($rtRoute['dest_subaddress_json'], true) : null;
if (is_array($sub) && (int) $sub['channel_slot'] === 1) {
    echo "PASS\n"; $pass++;
} else {
    echo "FAIL: sub=" . ($rtRoute['dest_subaddress_json'] ?? 'null') . "\n"; $fail++;
}

// ── Test 45 (Phase D): loop-prevention preserved across mesh forward ──
// A trusted forward whose _routed already contains the mesh route id must NOT
// re-fire that route (the mesh path goes through the same router_evaluate skip).
// Remove the other test mesh route so only $meshRouteId (slot-1 channel) can
// match local_chat outbound — then a clean before/after outbox count proves
// the loop-prevention skip queued NOTHING.
router_delete($dmRouteId);
echo "[Test 45] Loop-prevention skips already-routed mesh route... ";
$obBeforeLoop = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mesh_outbox`");
$loopResults = router_evaluate('local_chat', 'outbound', [
    'body' => 'ROUTING_TEST mesh loop',
    'type' => 'text',
    '_is_routed_forward' => true,
    '_routed' => [$meshRouteId],
    '_route_depth' => 0,
]);
$loopFired = false;
foreach ($loopResults as $r) { if ($r['route_id'] === $meshRouteId) $loopFired = true; }
$obAfterLoop = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mesh_outbox`");
if (!$loopFired && $obAfterLoop === $obBeforeLoop) {
    echo "PASS (route skipped, nothing queued)\n"; $pass++;
} else {
    echo "FAIL (fired=" . ($loopFired ? 'yes' : 'no') . ", outbox " . $obBeforeLoop . "->" . $obAfterLoop . ")\n"; $fail++;
}

// ── Cleanup ──────────────────────────────────────────────────
echo "\nCleaning up test data...\n";
db_query("DELETE FROM `{$prefix}message_routes` WHERE `name` LIKE 'TEST_%'");
db_query("DELETE FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE '%ROUTING_TEST%'");
db_query("DELETE FROM `{$prefix}chat_messages` WHERE `body` LIKE '%ROUTING_TEST%'");
db_query("DELETE FROM `{$prefix}messages` WHERE `body` LIKE '%ROUTING_TEST%'");
db_query("DELETE FROM `{$prefix}sse_events` WHERE `payload` LIKE '%ROUTING_TEST%'");
// Phase D: remove the mesh_outbox + zello_outbox rows this test queued.
try { db_query("DELETE FROM `{$prefix}mesh_outbox` WHERE payload_json LIKE '%ROUTING_TEST%'"); } catch (Exception $e) {}
try { db_query("DELETE FROM `{$prefix}zello_outbox` WHERE body LIKE '%ROUTING_TEST%'"); } catch (Exception $e) {}
// Remove the injected test-only zello setting so we don't leave config behind
// (only if WE injected it — don't clobber a real configured install).
if (!empty($injectedZello) && empty($hadZello)) {
    try { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'zello_service' AND `value` = 'test'"); } catch (Exception $e) {}
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
