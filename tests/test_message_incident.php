<?php
/**
 * Phase 111 Slice A — Message → active-event incident auto-logging tests.
 *
 * Covers:
 *   - Migration idempotency (double shell_exec of run_message_incident_link.php)
 *   - action columns added (source_channel, source_message_id, author_member_id)
 *   - message_routes columns added (attach_action, attach_ticket_id) — skip-safe
 *   - RBAC permission action.manage_active_event seeded
 *   - comm_resolve_member_by_address() round-trip (zello) + case-insensitivity
 *     + unknown-handle null + unknown-transport null
 *   - mi_active_event_ticket_id() defaults 0 (feature off)
 *   - mi_attach_message_to_active_event() is a NO-OP when active event is 0
 *     (asserts NO action row is written)
 *   - mi_attach_message_to_active_event() writes a note (with source_channel
 *     + author_member_id) when an active event IS set
 *   - CRITICAL: incident_add_note_internal() with NO $meta still writes
 *     EXACTLY as before (existing-caller compatibility) — no meta columns
 *     populated, action_type=0, correct body/user.
 *
 * SAFETY: the active_event_ticket_id setting is RESTORED to "off" at the end
 * regardless of pass/fail via register_shutdown_function, and every scratch
 * row (ticket, action, member, member_comm_identifiers) is cleaned up.
 *
 * Usage: php tests/test_message_incident.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/comm_resolve.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/message-incident.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0;
$failed = 0;

function test($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

// ── Shutdown safety net: ALWAYS clear the active-event setting so we never
//    leave the live feature ON after the test, even on a fatal error. ──
register_shutdown_function(function () use ($prefix) {
    try {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['active_event_ticket_id']);
    } catch (Exception $e) {
        // best effort — nothing else we can do at shutdown
    }
});

echo "=== Phase 111 Slice A — Message→Incident Tests ===\n\n";

// ─────────────────────────────────────────────────────────────────────
// 1. Migration idempotency — run the migration twice via shell_exec.
// ─────────────────────────────────────────────────────────────────────
echo "-- Migration idempotency --\n";

$php = PHP_BINARY;
$migration = __DIR__ . '/../sql/run_message_incident_link.php';
$run1 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($migration) . ' 2>&1');
$run2 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($migration) . ' 2>&1');

test('Migration run 1 reports Done', $run1 !== null && strpos($run1, 'Done.') !== false);
test('Migration run 1 has no [ERR]', $run1 !== null && strpos($run1, '[ERR]') === false);
test('Migration run 2 (idempotent) reports Done', $run2 !== null && strpos($run2, 'Done.') !== false);
test('Migration run 2 has no [ERR]', $run2 !== null && strpos($run2, '[ERR]') === false);
test('Migration run 2 shows columns already present',
    $run2 !== null && strpos($run2, 'already present') !== false);

// ─────────────────────────────────────────────────────────────────────
// 2. action columns added.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- action columns --\n";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}action`");
    $names = array_column($cols, 'Field');
    test('action.source_channel exists', in_array('source_channel', $names, true));
    test('action.source_message_id exists', in_array('source_message_id', $names, true));
    test('action.author_member_id exists', in_array('author_member_id', $names, true));
} catch (Exception $e) {
    test('action columns check: ' . $e->getMessage(), false);
}

// ─────────────────────────────────────────────────────────────────────
// 3. message_routes columns added (skip-safe if routing not migrated).
// ─────────────────────────────────────────────────────────────────────
echo "\n-- message_routes columns --\n";
$mrExists = false;
try {
    $mrExists = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'message_routes']
    );
} catch (Exception $e) { $mrExists = false; }

if ($mrExists) {
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}message_routes`");
        $names = array_column($cols, 'Field');
        test('message_routes.attach_action exists', in_array('attach_action', $names, true));
        test('message_routes.attach_ticket_id exists', in_array('attach_ticket_id', $names, true));
    } catch (Exception $e) {
        test('message_routes columns check: ' . $e->getMessage(), false);
    }
} else {
    test('message_routes not present — column check skipped (OK)', true);
    test('message_routes not present — attach_ticket_id skipped (OK)', true);
}

// ─────────────────────────────────────────────────────────────────────
// 4. RBAC permission seeded.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- RBAC permission --\n";
try {
    $perm = db_fetch_one(
        "SELECT id, category FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_active_event']
    );
    test('permission action.manage_active_event seeded', $perm !== null);
    test('permission is in the action category', $perm && $perm['category'] === 'action');
} catch (Exception $e) {
    // RBAC may not be installed on a bare test DB — treat as skip-pass.
    test('permissions table absent — RBAC check skipped (OK)', true);
    test('permission category skipped (OK)', true);
}

// ─────────────────────────────────────────────────────────────────────
// 5. Reverse resolver round-trip: seed a member + zello identifier.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- comm_resolve_member_by_address (Link 1) --\n";

$zMode = db_fetch_one("SELECT id, enabled FROM `{$prefix}comm_modes` WHERE code = 'zello' LIMIT 1");
$zModeId = $zMode ? (int) $zMode['id'] : 0;
$testMemberId = 0;
$testMciId = 0;

if ($zModeId > 0) {
    // Scratch member (handle the legacy generated-column install).
    $genFirst = db_fetch_value(
        "SELECT EXTRA FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'first_name'",
        [$prefix . 'member']
    );
    $firstIsGenerated = is_string($genFirst) && stripos($genFirst, 'GENERATED') !== false;
    if ($firstIsGenerated) {
        db_query("INSERT INTO `{$prefix}member` (field2, field1, field4)
                  VALUES ('P111', 'Reverse', 'P111REV')");
    } else {
        db_query("INSERT INTO `{$prefix}member` (first_name, last_name, callsign)
                  VALUES ('P111', 'Reverse', 'P111REV')");
    }
    $testMemberId = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}member_comm_identifiers`
            (member_id, comm_mode_id, label, values_json, is_primary)
         VALUES (?, ?, 'p111-test', ?, 1)",
        [$testMemberId, $zModeId, json_encode(['username' => 'P111_Alice'])]
    );
    $testMciId = (int) db_insert_id();
}

test('Scratch member + zello identifier seeded', $testMemberId > 0 && $testMciId > 0);

// Exact match.
$resolvedExact = comm_resolve_member_by_address('zello', 'P111_Alice');
test('Reverse resolve exact handle → member id',
    $resolvedExact === $testMemberId);

// Case-insensitive match.
$resolvedCi = comm_resolve_member_by_address('zello', 'p111_alice');
test('Reverse resolve is case-insensitive',
    $resolvedCi === $testMemberId);

// Handle with surrounding whitespace.
$resolvedTrim = comm_resolve_member_by_address('zello', '  P111_Alice  ');
test('Reverse resolve trims whitespace',
    $resolvedTrim === $testMemberId);

// Unknown handle → null.
$resolvedUnknown = comm_resolve_member_by_address('zello', 'nobody_here_xyz');
test('Reverse resolve unknown handle → null', $resolvedUnknown === null);

// Unknown transport → null.
$resolvedBadTransport = comm_resolve_member_by_address('carrier_pigeon', 'P111_Alice');
test('Reverse resolve unknown transport → null', $resolvedBadTransport === null);

// Empty handle → null.
test('Reverse resolve empty handle → null',
    comm_resolve_member_by_address('zello', '') === null);

// ─────────────────────────────────────────────────────────────────────
// 6. mi_active_event_ticket_id() defaults to 0 (feature off).
// ─────────────────────────────────────────────────────────────────────
echo "\n-- Active-event default --\n";

// Make sure it's cleared first.
try { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['active_event_ticket_id']); }
catch (Exception $e) {}
mi_reset_active_event_cache();

test('mi_active_event_ticket_id() defaults to 0 when unset',
    mi_active_event_ticket_id() === 0);

// ─────────────────────────────────────────────────────────────────────
// 7. Helper for counting action rows on a ticket.
// ─────────────────────────────────────────────────────────────────────
function _mi_action_count(string $prefix, int $ticketId): int {
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}action` WHERE `ticket_id` = ?",
            [$ticketId]
        );
    } catch (Exception $e) { return -1; }
}

// Create a scratch ticket to attach to. `description` + `scope` are NOT NULL
// with no default → must be supplied.
$now = date('Y-m-d H:i:s');
$scratchTicketId = 0;
try {
    db_query(
        "INSERT INTO `{$prefix}ticket`
            (`in_types_id`, `contact`, `scope`, `description`, `status`, `severity`, `owner`, `date`, `updated`)
         VALUES (?, '', ?, ?, 2, 0, 0, ?, ?)",
        [1, 'P111 scratch event', 'P111 scratch event', $now, $now]
    );
    $scratchTicketId = (int) db_insert_id();
} catch (Exception $e) {
    echo "[WARN] could not create scratch ticket: " . $e->getMessage() . "\n";
}
test('Scratch ticket created', $scratchTicketId > 0);

// ─────────────────────────────────────────────────────────────────────
// 8. NO-OP when active event is 0: mi_attach writes NOTHING.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- NO-OP when feature off --\n";

$before = _mi_action_count($prefix, $scratchTicketId);
mi_attach_message_to_active_event(
    ['body' => 'this must NOT be logged', 'from' => 'P111_Alice'],
    'zello'
);
$after = _mi_action_count($prefix, $scratchTicketId);
test('mi_attach writes NO action row when active event is 0',
    $before === $after);

// ─────────────────────────────────────────────────────────────────────
// 9. Writes a note when active event IS set (with source_channel + author).
// ─────────────────────────────────────────────────────────────────────
echo "\n-- Writes note when active event set --\n";

// Point the active event at the scratch ticket.
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
    ['active_event_ticket_id', (string) $scratchTicketId]
);
mi_reset_active_event_cache();

test('mi_active_event_ticket_id() now returns the scratch ticket',
    mi_active_event_ticket_id() === $scratchTicketId);

$beforeSet = _mi_action_count($prefix, $scratchTicketId);
mi_attach_message_to_active_event(
    ['body' => 'crowd heavy at bandshell', 'from' => 'P111_Alice', 'id' => 4242],
    'zello'
);
$afterSet = _mi_action_count($prefix, $scratchTicketId);
test('mi_attach wrote exactly one action row when active event set',
    $afterSet === $beforeSet + 1);

// Inspect the written row.
$loggedRow = null;
try {
    $loggedRow = db_fetch_one(
        "SELECT * FROM `{$prefix}action`
          WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 1",
        [$scratchTicketId]
    );
} catch (Exception $e) {}

test('logged row exists', $loggedRow !== null);
test('logged row has source_channel = zello',
    $loggedRow && ($loggedRow['source_channel'] ?? null) === 'zello');
test('logged row note is prefixed with [Zello: P111_Alice]',
    $loggedRow && strpos($loggedRow['description'] ?? '', '[Zello: P111_Alice]') === 0);
test('logged row body contains the message text',
    $loggedRow && strpos($loggedRow['description'] ?? '', 'crowd heavy at bandshell') !== false);
test('logged row author_member_id resolved to the seeded member',
    $loggedRow && (int) ($loggedRow['author_member_id'] ?? 0) === $testMemberId);
test('logged row source_message_id captured from message id',
    $loggedRow && (int) ($loggedRow['source_message_id'] ?? 0) === 4242);
test('logged row action_type = 0 (general note)',
    $loggedRow && (int) ($loggedRow['action_type'] ?? -1) === 0);
test('logged row user = 0 (system)',
    $loggedRow && (int) ($loggedRow['user'] ?? -1) === 0);

// Unattributed sender still logs (author null) but does not error.
$beforeUnattr = _mi_action_count($prefix, $scratchTicketId);
mi_attach_message_to_active_event(
    ['body' => 'unknown reporter update', 'from' => 'somebody_unmapped'],
    'zello'
);
$afterUnattr = _mi_action_count($prefix, $scratchTicketId);
test('mi_attach logs an unattributed message (sender unknown)',
    $afterUnattr === $beforeUnattr + 1);
$unattrRow = null;
try {
    $unattrRow = db_fetch_one(
        "SELECT author_member_id FROM `{$prefix}action`
          WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 1",
        [$scratchTicketId]
    );
} catch (Exception $e) {}
test('unattributed row has NULL author_member_id',
    $unattrRow && ($unattrRow['author_member_id'] === null));

// Empty-body message must NOT log (no content).
$beforeEmpty = _mi_action_count($prefix, $scratchTicketId);
mi_attach_message_to_active_event(['body' => '   ', 'from' => 'P111_Alice'], 'zello');
$afterEmpty = _mi_action_count($prefix, $scratchTicketId);
test('mi_attach skips an empty-body message', $afterEmpty === $beforeEmpty);

// ─────────────────────────────────────────────────────────────────────
// 10. CRITICAL — existing-caller compatibility:
//     incident_add_note_internal() with NO $meta writes exactly as before.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- CRITICAL: existing-caller compatibility (no \$meta) --\n";

$compatBefore = _mi_action_count($prefix, $scratchTicketId);
$compatResult = incident_add_note_internal($scratchTicketId, 'plain legacy note', 77);
$compatAfter = _mi_action_count($prefix, $scratchTicketId);

test('incident_add_note_internal (no meta) returns an id, no errors',
    isset($compatResult['id']) && $compatResult['id'] > 0 && empty($compatResult['errors']));
test('incident_add_note_internal (no meta) wrote exactly one row',
    $compatAfter === $compatBefore + 1);

$compatRow = null;
try {
    $compatRow = db_fetch_one(
        "SELECT * FROM `{$prefix}action` WHERE `id` = ?",
        [(int) $compatResult['id']]
    );
} catch (Exception $e) {}

test('legacy note has correct body', $compatRow && $compatRow['description'] === 'plain legacy note');
test('legacy note has correct user', $compatRow && (int) $compatRow['user'] === 77);
test('legacy note has action_type = 0', $compatRow && (int) $compatRow['action_type'] === 0);
// The whole point: NO metadata columns populated for a legacy caller.
test('legacy note has NULL source_channel (no meta leaked)',
    $compatRow && $compatRow['source_channel'] === null);
test('legacy note has NULL source_message_id (no meta leaked)',
    $compatRow && $compatRow['source_message_id'] === null);
test('legacy note has NULL author_member_id (no meta leaked)',
    $compatRow && $compatRow['author_member_id'] === null);

// Validation guards unchanged.
$badTicket = incident_add_note_internal(0, 'x', 1);
test('incident_add_note_internal rejects ticket id 0', !empty($badTicket['errors']));
$badNote = incident_add_note_internal($scratchTicketId, '   ', 1);
test('incident_add_note_internal rejects empty note', !empty($badNote['errors']));

// ─────────────────────────────────────────────────────────────────────
// Cleanup — remove every scratch row + restore feature OFF.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- Cleanup --\n";
try {
    if ($scratchTicketId > 0) {
        db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$scratchTicketId]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$scratchTicketId]);
    }
    if ($testMciId > 0) {
        db_query("DELETE FROM `{$prefix}member_comm_identifiers` WHERE `id` = ?", [$testMciId]);
    }
    if ($testMemberId > 0) {
        db_query("DELETE FROM `{$prefix}member` WHERE `id` = ?", [$testMemberId]);
    }
    // Explicit clear (the shutdown net also does this).
    db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['active_event_ticket_id']);
    echo "[OK] Test data cleaned up; active-event setting restored to OFF\n";
} catch (Exception $e) {
    echo "[WARN] Cleanup: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
