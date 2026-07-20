<?php
/**
 * NewUI v4.0 — Comprehensive Functional Test Suite
 *
 * Exercises all major NewUI subsystems with a CERT organization scenario:
 *   - Core utility functions (e, format_phone, toIso, get_variable, hash/verify)
 *   - RBAC permission system (roles, permissions, per-level checks)
 *   - Audit logging (categories, severities, data access)
 *   - SSE real-time events (publish, batch)
 *   - Message broker (channels, send, statuses)
 *   - Message routing engine (create, evaluate, filter, transform, loop prevention)
 *   - Incident CRUD (create, query, search, status transitions)
 *   - Responder management (create, status change, assignment)
 *   - Facility management (create, query)
 *   - Chat system (send, receive)
 *   - Edge cases & security (XSS, SQL injection, NULL handling)
 *   - Complete cleanup
 *
 * All test data uses __NUT_ prefix (NewUI Test) for identification and cleanup.
 *
 * Usage:
 *   php tests/test_newui_full.php
 */

// ── Bootstrap ────────────────────────────────────────────────
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'NewUI-FullTest/1.0';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config.php';

@session_start();
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

// Load subsystems
$inc = __DIR__ . '/../inc/';
if (file_exists($inc . 'rbac.php'))    require_once $inc . 'rbac.php';
if (file_exists($inc . 'sse.php'))     require_once $inc . 'sse.php';
if (file_exists($inc . 'audit.php'))   require_once $inc . 'audit.php';
if (file_exists($inc . 'broker.php'))  require_once $inc . 'broker.php';
// router.php loaded by broker.php

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== NewUI v4.0 Comprehensive Functional Test Suite ===\n";
echo "PHP: " . PHP_VERSION . " | NewUI: " . NEWUI_VERSION . "\n\n";

$passed = 0;
$failed = 0;
$total  = 0;

function test($name, $condition, $detail = '') {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        echo "  PASS: {$name}\n";
        $passed++;
    } else {
        echo "  FAIL: {$name}" . ($detail ? " - {$detail}" : '') . "\n";
        $failed++;
    }
}

// Track IDs for cleanup
$test_ids = [
    'in_types'   => [],
    'responder'  => [],
    'facilities' => [],
    'ticket'     => [],
    'action'     => [],
    'assigns'    => [],
    'patient'    => [],
    'routes'     => [],
    'settings'   => [],
];

// Cleanup helper
function cleanup_test_data() {
    global $prefix, $test_ids;
    $ticket_ids = implode(',', array_map('intval', $test_ids['ticket'] ?: [0]));
    $resp_ids = implode(',', array_map('intval', $test_ids['responder'] ?: [0]));

    @db_query("DELETE FROM `{$prefix}patient` WHERE `ticket_id` IN ({$ticket_ids})");
    @db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` IN ({$ticket_ids})");
    @db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` IN ({$ticket_ids})");
    @db_query("DELETE FROM `{$prefix}allocates` WHERE `type` = 1 AND `resource_id` IN ({$ticket_ids})");
    @db_query("DELETE FROM `{$prefix}ticket` WHERE `id` IN ({$ticket_ids})");
    @db_query("DELETE FROM `{$prefix}responder` WHERE `name` LIKE '__NUT_%'");
    @db_query("DELETE FROM `{$prefix}facilities` WHERE `name` LIKE '__NUT_%'");
    @db_query("DELETE FROM `{$prefix}in_types` WHERE `type` LIKE '__NUT_%'");
    @db_query("DELETE FROM `{$prefix}chat_messages` WHERE `body` LIKE '__NUT_%'");
    @db_query("DELETE FROM `{$prefix}messages` WHERE `body` LIKE '__NUT_%'");
    @db_query("DELETE FROM `{$prefix}sse_events` WHERE `payload` LIKE '%__NUT_%'");
    @db_query("DELETE FROM `{$prefix}message_routes` WHERE `name` LIKE '__NUT_%'");
    @db_query("DELETE FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE '%__NUT_%'");
    @db_query("DELETE FROM `{$prefix}settings` WHERE `name` LIKE '__nut_%'");
    try { @db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `summary` LIKE '%__NUT_%'"); } catch (Exception $e) {}
}

register_shutdown_function('cleanup_test_data');

// ═══════════════════════════════════════════════════════════════
// SECTION 1: Core Utility Functions
// ═══════════════════════════════════════════════════════════════
echo "── Section 1: Core Utility Functions ──\n";

// e() — XSS escaping
test("e() escapes script tags", e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
test("e() escapes double quotes", strpos(e('"onclick="hack"'), '&quot;') !== false);
test("e() escapes single quotes", strpos(e("'"), '&#039;') !== false || strpos(e("'"), '&apos;') !== false);
test("e() handles null", e(null) === '');
test("e() handles numeric string", e('42') === '42');
test("e() handles empty string", e('') === '');
test("e() preserves safe text", e('Hello World') === 'Hello World');

// format_phone()
test("format_phone() US format", format_phone('5551234567', 'us') === '(555) 123-4567');
test("format_phone() dash format", format_phone('5551234567', 'dash') === '555-123-4567');
test("format_phone() dots format", format_phone('5551234567', 'dots') === '555.123.4567');
test("format_phone() off format", format_phone('5551234567', 'off') === '5551234567');
test("format_phone() short number unchanged", format_phone('911') === '911');

// toIso()
$iso = toIso('2026-01-15 08:30:00');
test("toIso() converts MySQL datetime", $iso !== null && strpos($iso, '2026-01-15T08:30:00') === 0);
test("toIso() returns null for zero date", toIso('0000-00-00 00:00:00') === null);
test("toIso() returns null for null", toIso(null) === null);
test("toIso() returns null for empty string", toIso('') === null);

// get_variable()
$GLOBALS['variables'] = null; // Clear cache
test("get_variable() reads settings", get_variable('date_format') !== false);
test("get_variable() returns FALSE for missing", get_variable('__nut_nonexistent_xyz') === false);

// Insert and verify a test setting via direct DB query
// Note: get_variable() uses a static cache loaded once per request,
// so settings inserted after the first call won't be visible via get_variable().
// This is correct behavior — test the DB layer directly.
// ON DUPLICATE KEY: a prior run that fataled mid-suite never reached the
// cleanup section, so the fixture row may still exist (settings.name is
// UNIQUE since phase 24).
db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
          ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", ['__nut_test_setting', 'test_value_42']);
$direct = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", ['__nut_test_setting']);
test("Setting stored and readable via DB", $direct !== null && $direct['value'] === 'test_value_42');

// get_setting() (reads from config table)
if (function_exists('get_setting')) {
    $tfa = get_setting('tfa_enabled');
    test("get_setting() reads config table", $tfa !== null);
} else {
    test("get_setting() exists", false);
}

// Phase 12 (2026-06-11): get_level_text() is now a compat shim that
// returns the current user's RBAC role name regardless of the integer
// argument. The legacy integer-to-display mapping is sunsetted. We
// verify the shim is callable and returns a string; no longer assert
// specific text per integer.
test("get_level_text() returns a string", is_string(get_level_text(0)));
test("get_level_text() is a deprecated compat shim, not the legacy mapping",
    get_level_text(0) !== 'Super' || current_role_name() === 'Super');
test("current_role_name() returns a string", is_string(current_role_name()));

// Password hashing
$hash = hash_new_password('TestPass!2026');
test("hash_new_password() returns bcrypt hash", strpos($hash, '$2y$') === 0);
$vr = verify_password('TestPass!2026', $hash);
test("verify_password() accepts correct password", $vr['valid'] === true);
test("verify_password() rejects wrong password", verify_password('wrong', $hash)['valid'] === false);
test("verify_password() handles MD5 legacy", verify_password('legacy', md5('legacy'))['valid'] === true);
test("verify_password() flags MD5 for rehash", verify_password('legacy', md5('legacy'))['needs_rehash'] === true);

// CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['csrf_token'];
test("csrf_verify() accepts valid token", csrf_verify($token));
test("csrf_verify() rejects invalid token", !csrf_verify('bad_token'));
test("csrf_verify() rejects empty token", !csrf_verify(''));

// ═══════════════════════════════════════════════════════════════
// SECTION 2: RBAC Permission System
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 2: RBAC Permission System ──\n";

if (function_exists('rbac_can')) {
    // Super admin (level 0)
    $_SESSION['level'] = 0;
    $_SESSION['user_id'] = 1;

    test("Super: rbac_can('screen.incidents')", rbac_can('screen.incidents'));
    test("Super: rbac_can('screen.settings')", rbac_can('screen.settings'));
    test("Super: rbac_can('action.create_incident')", rbac_can('action.create_incident'));
    test("Super: rbac_can('action.manage_config')", rbac_can('action.manage_config'));
    test("Super: rbac_can('action.manage_users')", rbac_can('action.manage_users'));
    test("Super: rbac_can('action.manage_routing')", rbac_can('action.manage_routing'));
    test("Super: rbac_can('field.view_patient')", rbac_can('field.view_patient'));

    // Get all permissions for super
    if (function_exists('rbac_user_permissions')) {
        $perms = rbac_user_permissions();
        test("Super has 60+ permissions", is_array($perms) && count($perms) >= 60,
            "count=" . (is_array($perms) ? count($perms) : 'not array'));
    }

    // Get roles
    if (function_exists('rbac_user_roles')) {
        $roles = rbac_user_roles();
        test("rbac_user_roles() returns array", is_array($roles));
    }

    // Admin (level 1)
    $_SESSION['level'] = 1;
    test("Admin: rbac_can('screen.incidents')", rbac_can('screen.incidents'));
    test("Admin: rbac_can('action.create_incident')", rbac_can('action.create_incident'));

    // Test the legacy fallback function directly (rbac_can uses static cache
    // which can't be reset within the same process — by design for perf)
    if (function_exists('_rbac_legacy_check')) {
        test("Legacy fallback: guest can view screens", _rbac_legacy_check('screen.incidents', 3));
        test("Legacy fallback: guest blocked from config", !_rbac_legacy_check('action.manage_config', 3));
        test("Legacy fallback: guest blocked from users", !_rbac_legacy_check('action.manage_users', 3));
        test("Legacy fallback: operator blocked from config", !_rbac_legacy_check('action.manage_config', 2));
        test("Legacy fallback: operator can create incident", _rbac_legacy_check('action.create_incident', 2));
    } else {
        test("_rbac_legacy_check available for testing", false);
    }

    // Restore super for remaining tests
    $_SESSION['level'] = 0;

    // Verify permission categories in DB
    $categories = db_fetch_all("SELECT DISTINCT category FROM `{$prefix}permissions` ORDER BY category");
    $cats = array_column($categories, 'category');
    test("Permission categories: screen", in_array('screen', $cats));
    test("Permission categories: action", in_array('action', $cats));
    test("Permission categories: widget", in_array('widget', $cats));
    test("Permission categories: field", in_array('field', $cats));

    // Verify role count
    $role_count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
    test("At least 5 roles defined", $role_count >= 5, "count={$role_count}");
} else {
    test("rbac_can() available", false, "RBAC module not loaded");
}

// ═══════════════════════════════════════════════════════════════
// SECTION 3: Audit Logging
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 3: Audit Logging ──\n";

if (function_exists('audit_log')) {
    // Ensure audit table exists
    if (function_exists('audit_ensure_table')) {
        audit_ensure_table();
    }

    $r = audit_log('system', 'create', 'test', 0, '__NUT_ Test audit entry', ['test' => true]);
    test("audit_log() returns true", $r === true);

    $r2 = audit_log('auth', 'login', 'user', 1, '__NUT_ Login test', null, defined('AUDIT_INFO') ? AUDIT_INFO : 1);
    test("audit_log() with severity", $r2 === true);

    // Verify entries were written
    try {
        $entries = db_fetch_all(
            "SELECT * FROM `{$prefix}newui_audit_log` WHERE `summary` LIKE '__NUT_%' ORDER BY id DESC LIMIT 5"
        );
        test("Audit entries written to DB", count($entries) >= 2);
        test("Audit entry has category", !empty($entries) && $entries[0]['category'] !== null);
        test("Audit entry has activity", !empty($entries) && $entries[0]['activity'] !== null);
        test("Audit entry has event_time", !empty($entries) && $entries[0]['event_time'] !== null);
    } catch (Exception $e) {
        test("Audit log table accessible", false, $e->getMessage());
    }

    // Specialized audit functions
    if (function_exists('audit_admin')) {
        $r3 = audit_admin(1, 'config_change', 'settings', '__NUT_ Config change test');
        test("audit_admin() works", $r3 === true);
    }

    // Severity helpers
    if (function_exists('audit_severity_label')) {
        test("audit_severity_label(1) = 'Info'", audit_severity_label(1) === 'Info');
        test("audit_severity_label(4) = 'High'", audit_severity_label(4) === 'High');
    }
} else {
    test("audit_log() available", false, "Audit module not loaded");
}

// ═══════════════════════════════════════════════════════════════
// SECTION 4: SSE Real-Time Events
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 4: SSE Real-Time Events ──\n";

if (function_exists('sse_publish')) {
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events`");

    $r = sse_publish('test:newui_full', ['source' => '__NUT_', 'test' => true]);
    test("sse_publish() returns true", $r === true);

    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events`");
    test("SSE event inserted", $after > $before);

    // Verify event content
    $evt = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE `event_type` = 'test:newui_full' ORDER BY id DESC LIMIT 1"
    );
    test("SSE event has correct type", $evt && $evt['event_type'] === 'test:newui_full');
    test("SSE event has payload", $evt && strpos($evt['payload'], '__NUT_') !== false);

    // Batch publish
    if (function_exists('sse_publish_batch')) {
        $count = sse_publish_batch([
            ['type' => 'test:batch1', 'payload' => ['source' => '__NUT_']],
            ['type' => 'test:batch2', 'payload' => ['source' => '__NUT_']],
        ]);
        test("sse_publish_batch() publishes 2 events", $count === 2);
    }

    // Cleanup test SSE events
    db_query("DELETE FROM `{$prefix}sse_events` WHERE `event_type` LIKE 'test:%' AND `payload` LIKE '%__NUT_%'");
} else {
    test("sse_publish() available", false, "SSE module not loaded");
}

// ═══════════════════════════════════════════════════════════════
// SECTION 5: Message Broker & Channels
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 5: Message Broker & Channels ──\n";

if (function_exists('broker_channel_statuses')) {
    $statuses = broker_channel_statuses();
    $codes = array_column($statuses, 'code');

    test("Channel: local_chat registered", in_array('local_chat', $codes));
    test("Channel: sms registered", in_array('sms', $codes));
    test("Channel: smtp registered", in_array('smtp', $codes));
    test("Channel: slack registered", in_array('slack', $codes));
    // Phase D deleted the dead meshtastic + zello stubs. Phase 99a
    // (2026-06-28) re-registered meshtastic as a REAL channel that queues
    // into mesh_outbox (inc/channels/meshtastic.php); zello remains a
    // unified routing destination (zello_outbox), not a broker channel.
    test("Channel: meshtastic real channel registered (Phase 99a)",
        in_array('meshtastic', $codes) && function_exists('_meshtastic_send'));
    test("Channel: zello broker stub removed", !in_array('zello', $codes));
    test("Channel: dmr registered", in_array('dmr', $codes));
    test("Channel: email registered", in_array('email', $codes));
    // Remaining registered broker channels: local_chat, sms, smtp, slack, dmr, email.
    test("Total channels >= 6", count($codes) >= 6, "count=" . count($codes));

    // Send via local_chat
    $result = broker_send('local_chat', [
        'body' => '__NUT_ Test chat message from full suite',
        'to'   => 'all',
        'type' => 'text'
    ]);
    test("broker_send() to local_chat succeeds", $result['success'] === true);
    test("broker_send() returns message_id", isset($result['message_id']) && $result['message_id'] > 0);

    // Phase D (messaging-send-gaps): the zello broker stub was deleted —
    // zello is now a unified routing destination (zello_outbox), not a broker
    // channel. broker_send('zello') must report it as an unknown channel.
    $zello_result = broker_send('zello', ['body' => '__NUT_ test', 'to' => 'all']);
    test("Zello broker stub removed (unknown channel)", !$zello_result['success'] && strpos($zello_result['error'], 'Unknown') !== false);

    // DMR text-data path is still an honest stub (Phase 99e validates the
    // recipient — tg:<id> / radioid:<id> — then reports
    // dmr_text_not_implemented until BrandMeister HBP DATA framing lands).
    $dmr_result = broker_send('dmr', ['body' => '__NUT_ test', 'to' => 'tg:99']);
    test("DMR send returns not_implemented", !$dmr_result['success'] && strpos($dmr_result['error'], 'not_implemented') !== false);

    // Unknown channel
    $unk = broker_send('nonexistent_xyz', ['body' => 'test']);
    test("Unknown channel fails gracefully", !$unk['success'] && strpos($unk['error'], 'Unknown') !== false);
} else {
    test("broker_channel_statuses() available", false);
}

// ═══════════════════════════════════════════════════════════════
// SECTION 6: Message Routing Engine
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 6: Message Routing Engine ──\n";

if (function_exists('router_create')) {
    // Create test route
    $routeId = router_create([
        'name'           => '__NUT_ Chat to SMS (urgent)',
        'description'    => 'Test route for full suite',
        'source_channel' => 'local_chat',
        'dest_channel'   => 'sms',
        'priority'       => 10,
        'direction'      => 'outbound',
        'filters'        => ['priority_in' => ['high', 'urgent'], 'keywords' => ['emergency']],
        'transform'      => ['prefix' => '[ALERT] ', 'override_priority' => 'urgent']
    ]);
    $test_ids['routes'][] = $routeId;
    test("router_create() returns valid ID", $routeId > 0);

    // Get route
    $route = router_get($routeId);
    test("router_get() returns route", $route !== null && $route['name'] === '__NUT_ Chat to SMS (urgent)');

    // Dry-run test
    $matches = router_test('local_chat', 'outbound', [
        'body' => '__NUT_ emergency at sector 4',
        'priority' => 'urgent'
    ]);
    test("router_test() finds matching route", count($matches) > 0);

    $found = false;
    foreach ($matches as $m) {
        if ($m['route_id'] === $routeId) {
            $found = true;
            test("Matched route has correct transform", strpos($m['transformed']['body'], '[ALERT]') === 0);
            test("Transform overrides priority to urgent", $m['transformed']['priority'] === 'urgent');
        }
    }
    test("Test route found in matches", $found);

    // Non-matching message
    $no_match = router_test('local_chat', 'outbound', [
        'body' => '__NUT_ routine status update',
        'priority' => 'normal'
    ]);
    $found_in_nomatch = false;
    foreach ($no_match as $m) {
        if ($m['route_id'] === $routeId) $found_in_nomatch = true;
    }
    test("Normal priority doesn't match urgent-only route", !$found_in_nomatch);

    // Toggle
    router_toggle($routeId, false);
    $disabled = router_get($routeId);
    test("router_toggle() disables route", (int)$disabled['enabled'] === 0);
    router_toggle($routeId, true);

    // Delete
    router_delete($routeId);
    test("router_delete() removes route", router_get($routeId) === null);
    $test_ids['routes'] = []; // Already cleaned
} else {
    test("router_create() available", false, "Router not loaded");
}

// ═══════════════════════════════════════════════════════════════
// SECTION 7: Incident CRUD
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 7: Incident CRUD ──\n";

$_SESSION['level'] = 0;
$_SESSION['user_id'] = 1;

// Create test incident type
db_query(
    "INSERT INTO `{$prefix}in_types` (`type`, `description`, `protocol`, `set_severity`, `sort`)
     VALUES (?, ?, ?, ?, ?)",
    ['__NUT_ MedEmrg', 'Medical Emergency', 'Assess patient. Apply triage. Transport.', 2, 10]
);
$type_id = (int) db_insert_id();
$test_ids['in_types'][] = $type_id;
test("Create test incident type", $type_id > 0);

// Create incident directly in DB (simulating api/incident-create.php)
$now = date('Y-m-d H:i:s');
db_query(
    "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
     `street`, `city`, `state`, `lat`, `lng`, `date`, `problemstart`, `contact`, `phone`, `_by`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$type_id, 2, 2, '__NUT_ Mass Casualty Incident',
     'Multiple injuries at concert venue. 5 patients triaged.',
     '100 Arena Blvd', 'Springfield', 'CA', 34.0522, -118.2437,
     $now, $now, 'Security Guard', '555-0100', 1]
);
$ticket_id = (int) db_insert_id();
$test_ids['ticket'][] = $ticket_id;
test("Create incident", $ticket_id > 0);

// Query incident back
$t = db_fetch_one("SELECT * FROM `{$prefix}ticket` WHERE `id` = ?", [$ticket_id]);
test("Incident status is OPEN (2)", (int)$t['status'] === 2);
test("Incident severity is HIGH (2)", (int)$t['severity'] === 2);
test("Incident has lat/lng", abs((float)$t['lat'] - 34.0522) < 0.001);
test("Incident scope preserved", $t['scope'] === '__NUT_ Mass Casualty Incident');

// Create a second incident
db_query(
    "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
     `street`, `city`, `state`, `date`, `problemstart`, `_by`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$type_id, 2, 0, '__NUT_ Routine Welfare Check',
     'Neighbor reports no activity for 24 hours.',
     '200 Oak Lane', 'Springfield', 'CA', $now, $now, 1]
);
$ticket2_id = (int) db_insert_id();
$test_ids['ticket'][] = $ticket2_id;
test("Create second incident", $ticket2_id > 0);

// Add action note
db_query(
    "INSERT INTO `{$prefix}action` (`ticket_id`, `description`, `user`, `action_type`, `date`)
     VALUES (?, ?, ?, ?, ?)",
    [$ticket_id, '__NUT_ Initial triage complete. 2 red, 2 yellow, 1 green.', 1, 10, $now]
);
$action_id = (int) db_insert_id();
$test_ids['action'][] = $action_id;
test("Add action note", $action_id > 0);

// Search-like query
$search_results = db_fetch_all(
    "SELECT t.*, it.type AS type_name FROM `{$prefix}ticket` t
     LEFT JOIN `{$prefix}in_types` it ON t.in_types_id = it.id
     WHERE t.scope LIKE ? ORDER BY t.id DESC",
    ['%__NUT_%']
);
test("Search query returns 2 test incidents", count($search_results) === 2);
test("Search JOIN returns type name", !empty($search_results) && $search_results[0]['type_name'] === '__NUT_ MedEmrg');

// Close incident
db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = ? WHERE `id` = ?", [$now, $ticket_id]);
$closed = db_fetch_one("SELECT status FROM `{$prefix}ticket` WHERE `id` = ?", [$ticket_id]);
test("Close incident (status=1)", (int)$closed['status'] === 1);

// ═══════════════════════════════════════════════════════════════
// SECTION 8: Responder Management
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 8: Responder Management ──\n";

// Get available status
$avail = db_fetch_one("SELECT id FROM `{$prefix}un_status` ORDER BY id LIMIT 1");
$status_id = $avail ? (int)$avail['id'] : 1;

// Create test responders
db_query(
    "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`, `handle`, `lat`, `lng`, `multi`)
     VALUES (?, ?, ?, ?, ?, ?, ?)",
    ['__NUT_ Engine 1', 'Primary response engine', $status_id, 'E1', 34.0522, -118.2437, 1]
);
$resp1_id = (int) db_insert_id();
$test_ids['responder'][] = $resp1_id;
test("Create responder Engine 1", $resp1_id > 0);

db_query(
    "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`, `handle`, `lat`, `lng`, `multi`)
     VALUES (?, ?, ?, ?, ?, ?, ?)",
    ['__NUT_ Medic 1', 'ALS ambulance', $status_id, 'M1', 34.0530, -118.2450, 0]
);
$resp2_id = (int) db_insert_id();
$test_ids['responder'][] = $resp2_id;
test("Create responder Medic 1", $resp2_id > 0);

// Query responders
$resps = db_fetch_all(
    "SELECT r.*, us.description AS status_desc FROM `{$prefix}responder` r
     LEFT JOIN `{$prefix}un_status` us ON r.un_status_id = us.id
     WHERE r.name LIKE '__NUT_%' ORDER BY r.id"
);
test("Query responders with JOIN returns 2", count($resps) === 2);
test("Responder has status description", !empty($resps) && $resps[0]['status_desc'] !== null);

// Assign to incident
db_query(
    "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `status_id`, `dispatched`, `user_id`, `as_of`)
     VALUES (?, ?, ?, ?, ?, ?)",
    [$ticket2_id, $resp1_id, 1, $now, 1, $now]
);
$assign_id = (int) db_insert_id();
$test_ids['assigns'][] = $assign_id;
test("Assign Engine 1 to incident", $assign_id > 0);

// Status progression
db_query("UPDATE `{$prefix}assigns` SET `responding` = ? WHERE `id` = ?", [$now, $assign_id]);
db_query("UPDATE `{$prefix}assigns` SET `on_scene` = ? WHERE `id` = ?", [$now, $assign_id]);
$assign = db_fetch_one("SELECT * FROM `{$prefix}assigns` WHERE `id` = ?", [$assign_id]);
test("Assignment has all timestamps", $assign && $assign['dispatched'] && $assign['responding'] && $assign['on_scene']);

// Add patient
db_query(
    "INSERT INTO `{$prefix}patient` (`ticket_id`, `name`, `fullname`, `gender`, `description`, `user`, `action_type`, `date`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    [$ticket2_id, '__NUT_ Doe J', 'John Doe', 1, 'Alert and oriented. Minor laceration.', 1, 10, $now]
);
$patient_id = (int) db_insert_id();
$test_ids['patient'][] = $patient_id;
test("Add patient to incident", $patient_id > 0);

// ═══════════════════════════════════════════════════════════════
// SECTION 9: Facility Management
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 9: Facility Management ──\n";

// fac_types / fac_status ship EMPTY on a fresh install (same as legacy —
// admins define them in Config). Self-seed one row of each when absent so
// the JOIN assertions below exercise real lookup data; remove in cleanup.
$nut_seeded_fac_type_id = 0;
$nut_seeded_fac_status_id = 0;
$fac_stat = db_fetch_one("SELECT id FROM `{$prefix}fac_status` ORDER BY id LIMIT 1");
if (!$fac_stat) {
    db_query("INSERT INTO `{$prefix}fac_status` (`status_val`, `description`, `_by`, `_from`, `_on`)
              VALUES ('__NUT_open', '__NUT_ Open', 0, 'test', NOW())");
    $nut_seeded_fac_status_id = (int) db_insert_id();
    $fac_stat = ['id' => $nut_seeded_fac_status_id];
}
$fac_status_id = (int) $fac_stat['id'];
$fac_type = db_fetch_one("SELECT id FROM `{$prefix}fac_types` ORDER BY id LIMIT 1");
if (!$fac_type) {
    db_query("INSERT INTO `{$prefix}fac_types` (`name`, `description`, `_by`, `_from`, `_on`)
              VALUES ('__NUT_hospital', '__NUT_ Hospital', 0, 'test', NOW())");
    $nut_seeded_fac_type_id = (int) db_insert_id();
    $fac_type = ['id' => $nut_seeded_fac_type_id];
}
$fac_type_id = (int) $fac_type['id'];

db_query(
    "INSERT INTO `{$prefix}facilities` (`name`, `description`, `type`, `status_id`, `street`, `city`, `state`, `lat`, `lng`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    ['__NUT_ General Hospital', 'Level I Trauma Center', $fac_type_id, $fac_status_id,
     '500 Hospital Dr', 'Springfield', 'CA', 34.0600, -118.2500]
);
$fac_id = (int) db_insert_id();
$test_ids['facilities'][] = $fac_id;
test("Create facility", $fac_id > 0);

$fac = db_fetch_one(
    "SELECT f.*, ft.description AS type_desc, fs.description AS status_desc
     FROM `{$prefix}facilities` f
     LEFT JOIN `{$prefix}fac_types` ft ON f.type = ft.id
     LEFT JOIN `{$prefix}fac_status` fs ON f.status_id = fs.id
     WHERE f.id = ?",
    [$fac_id]
);
test("Facility JOIN returns type description", $fac && $fac['type_desc'] !== null);
test("Facility JOIN returns status description", $fac && $fac['status_desc'] !== null);
test("Facility has coordinates", $fac && abs((float)$fac['lat'] - 34.06) < 0.01);

// ═══════════════════════════════════════════════════════════════
// SECTION 10: Chat System
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 10: Chat System ──\n";

// Ensure chat tables exist
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
} catch (Exception $e) {}

// Send a chat message via broker
$chat_result = broker_send('local_chat', [
    'body' => '__NUT_ All units: staging area relocated to parking lot B',
    'to' => 'all',
    'type' => 'text',
    'priority' => 'high'
]);
test("Chat message sent via broker", $chat_result['success'] === true);

// Verify stored in chat_messages
$chat_msg = db_fetch_one(
    "SELECT * FROM `{$prefix}chat_messages` WHERE `body` LIKE '__NUT_%' ORDER BY id DESC LIMIT 1"
);
test("Chat message stored in DB", $chat_msg !== null);
test("Chat message has correct priority", $chat_msg && $chat_msg['priority'] === 'high');
test("Chat message has user_name", $chat_msg && $chat_msg['user_name'] === 'admin');

// Verify logged in messages table
$msg_log = db_fetch_one(
    "SELECT * FROM `{$prefix}messages` WHERE `channel` = 'local_chat' AND `body` LIKE '__NUT_%' ORDER BY id DESC LIMIT 1"
);
test("Chat logged in messages table", $msg_log !== null);
test("Message status is delivered", $msg_log && $msg_log['status'] === 'delivered');

// ═══════════════════════════════════════════════════════════════
// SECTION 11: Edge Cases & Security
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 11: Edge Cases & Security ──\n";

// Special characters
db_query(
    "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
     `city`, `date`, `problemstart`, `contact`, `_by`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$type_id, 2, 0, "__NUT_ O'Brien & Sons <script>alert('xss')</script>",
     'Patient says "I can\'t breathe" -- suspected CO exposure',
     null, $now, $now, "Dr. Martinez", 1]
);
$edge_id = (int) db_insert_id();
$test_ids['ticket'][] = $edge_id;
test("Insert with special chars succeeds", $edge_id > 0);

$edge = db_fetch_one("SELECT * FROM `{$prefix}ticket` WHERE `id` = ?", [$edge_id]);
test("Apostrophe preserved", $edge && strpos($edge['scope'], "O'Brien") !== false);
test("Script tag stored literally", $edge && strpos($edge['scope'], '<script>') !== false);
test("Contact name preserved", $edge && $edge['contact'] === 'Dr. Martinez');
test("NULL city stored", $edge && $edge['city'] === null);
test("Double dash preserved", $edge && strpos($edge['description'], '--') !== false);

// SQL injection via prepared statements
db_query(
    "INSERT INTO `{$prefix}action` (`ticket_id`, `description`, `user`, `action_type`, `date`)
     VALUES (?, ?, ?, ?, ?)",
    [$edge_id, "__NUT_ '; DROP TABLE ticket; --", 1, 10, $now]
);
$sqli_id = (int) db_insert_id();
$test_ids['action'][] = $sqli_id;
test("SQL injection stored as literal", $sqli_id > 0);

// Verify tables still exist
$tbl_check = db_fetch_all("SHOW TABLES LIKE '{$prefix}ticket'");
test("Tables survive injection attempt", count($tbl_check) > 0);

// XSS escaping
test("e() blocks XSS in scope", strpos(e($edge['scope']), '<script>') === false);
test("e() preserves safe content", strpos(e($edge['scope']), "O&#039;Brien") !== false || strpos(e($edge['scope']), "O&apos;Brien") !== false);

// Zero/boundary values
db_query(
    "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
     `lat`, `lng`, `date`, `problemstart`, `contact`, `_by`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$type_id, 2, 0, '__NUT_ Zero Coords Test', 'Testing at equator/prime meridian',
     0.0, 0.0, $now, $now, '', 1]
);
$zero_id = (int) db_insert_id();
$test_ids['ticket'][] = $zero_id;
test("Zero coordinates stored", $zero_id > 0);

$zero_t = db_fetch_one("SELECT * FROM `{$prefix}ticket` WHERE `id` = ?", [$zero_id]);
test("Zero lat retrieved correctly", abs((float)$zero_t['lat']) < 0.001);
test("Empty contact stored", $zero_t['contact'] === '');

// ═══════════════════════════════════════════════════════════════
// SECTION 12: Cleanup
// ═══════════════════════════════════════════════════════════════
echo "\n── Section 12: Cleanup ──\n";

cleanup_test_data();

// Remove the fac_types / fac_status lookup rows Section 9 seeded (only
// when this run created them — never touch operator-defined rows).
if (!empty($nut_seeded_fac_type_id)) {
    db_query("DELETE FROM `{$prefix}fac_types` WHERE id = ? AND `name` LIKE '__NUT_%'",
        [$nut_seeded_fac_type_id]);
}
if (!empty($nut_seeded_fac_status_id)) {
    db_query("DELETE FROM `{$prefix}fac_status` WHERE id = ? AND `status_val` LIKE '__NUT_%'",
        [$nut_seeded_fac_status_id]);
}

// Verify cleanup
$checks = [
    ['ticket', 'scope', '__NUT_%'],
    ['responder', 'name', '__NUT_%'],
    ['facilities', 'name', '__NUT_%'],
    ['in_types', 'type', '__NUT_%'],
];
$all_clean = true;
foreach ($checks as $c) {
    $rem = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}{$c[0]}` WHERE `{$c[1]}` LIKE ?",
        [$c[2]]
    );
    if ($rem > 0) {
        $all_clean = false;
        echo "  WARN: {$c[0]} has {$rem} leftover rows\n";
    }
}
test("All __NUT_ test data cleaned up", $all_clean);

// Check secondary tables
$chat_rem = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}chat_messages` WHERE `body` LIKE '__NUT_%'");
test("Chat messages cleaned up", $chat_rem === 0);

$msg_rem = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}messages` WHERE `body` LIKE '__NUT_%'");
test("Broker messages cleaned up", $msg_rem === 0);

$settings_rem = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` LIKE '__nut_%'");
test("Test settings cleaned up", $settings_rem === 0);

$route_rem = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}message_routes` WHERE `name` LIKE '__NUT_%'");
test("Routing rules cleaned up", $route_rem === 0);

// ═══════════════════════════════════════════════════════════════
// Results
// ═══════════════════════════════════════════════════════════════
echo "\n═══════════════════════════════════════════════════\n";
echo "=== RESULTS: $passed passed, $failed failed out of $total tests ===\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
