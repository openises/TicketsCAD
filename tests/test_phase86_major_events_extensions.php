<?php
/**
 * Phase 86 (2026-09-02) — Major Events extensions, driven through the REAL
 * api/major-incidents.php endpoint (tests/_phase86_major_events_probe.php),
 * not a reimplementation of its logic. Covers the schema additions, the
 * three-way RBAC split, the unified-command junction table (add/soft-remove/
 * close-ends-active), the live resource rollup, and cross-org visibility —
 * per specs/phase-86-major-events/changes.md.
 *
 * Usage: php tests/test_phase86_major_events_extensions.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/db.php';
require_once $root . '/tests/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }

echo "\n=== Phase 86 — Major Events extensions ===\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$probe = $root . '/tests/_phase86_major_events_probe.php';
$php = PHP_BINARY ?: 'php';

function p86_run(string $method, int $userId, $body): array {
    global $php, $probe;
    $payload = is_array($body) ? json_encode($body) : (string) $body;
    // proc_open() with an ARRAY command, not shell_exec()/exec() with
    // escapeshellarg()-joined strings: on Windows, escapeshellarg() mangles
    // an embedded double-quote (replaces it with a bare space), silently
    // corrupting a JSON payload passed as a shell argument -- this exact
    // codebase has hit this before (see CLAUDE.md's GH#149 sip-trunks-admin
    // pitfall entry). An array command bypasses cmd.exe/escapeshellarg
    // entirely, so the JSON reaches the child process byte-for-byte.
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $probe, $method, (string) $userId, $payload], $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['_raw' => null, '_error' => 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode((string) $out, true);
    return is_array($decoded) ? $decoded : ['_raw' => $out, '_stderr' => $err];
}

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 1. Schema --\n";
is_ok((bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='event_type'",
    [$prefix . 'newui_major_incidents']
), 'newui_major_incidents.event_type exists');
is_ok((bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='parent_incident_id'",
    [$prefix . 'newui_major_incidents']
), 'newui_major_incidents.parent_incident_id exists');
is_ok((bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
    [$prefix . 'newui_major_incident_command']
), 'newui_major_incident_command table exists');
is_ok((bool) db_fetch_value("SELECT 1 FROM `{$prefix}permissions` WHERE code='action.create_major_event'"),
    'action.create_major_event permission exists');
is_ok((bool) db_fetch_value("SELECT 1 FROM `{$prefix}permissions` WHERE code='action.manage_major_event_command'"),
    'action.manage_major_event_command permission exists');
$tiers = db_fetch_all(
    "SELECT code, admin_only FROM `{$prefix}permissions`
      WHERE code IN ('action.create_major_event', 'action.manage_major_event_command')"
);
$allTier1 = count($tiers) === 2;
foreach ($tiers as $t) { if ((int) $t['admin_only'] !== 1) { $allTier1 = false; } }
is_ok($allTier1, 'both new permissions are classified admin_only=1 (Org Admin or above)');

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 2. RBAC: neither new permission reaches Dispatcher/Operator/Read-Only/Field Unit --\n";
$leaked = db_fetch_all(
    "SELECT rp.role_id, p.code FROM `{$prefix}role_permissions` rp
     JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
      WHERE p.code IN ('action.create_major_event', 'action.manage_major_event_command')
        AND rp.role_id NOT IN (1, 2)"
);
is_ok(empty($leaked), 'no non-supervisor role holds either new permission', json_encode($leaked));

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 3. Fixtures --\n";
$adminId = test_admin_user_id();

// A throwaway Dispatcher-tier (role_id=3) user, matching this project's
// standing "dedicated throwaway account" convention.
db_query(
    "INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`) VALUES (?, ?, ?, ?)",
    ['claude-phase86-dispatcher', password_hash('unused', PASSWORD_BCRYPT), 'Claude', 'Phase86Test']
);
$dispatcherId = (int) db_insert_id();
db_query(
    "INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `scope_kind`) VALUES (?, 3, 'global')",
    [$dispatcherId]
);
ok("throwaway Dispatcher-tier user created (id={$dispatcherId})");

$now = date('Y-m-d H:i:s');
db_query(
    "INSERT INTO `{$prefix}ticket`
        (`scope`, `description`, `status`, `severity`, `date`, `updated`, `_by`, `owner`, `in_types_id`)
     VALUES (?, ?, 2, 1, ?, ?, ?, 0, 1)",
    ['claude-phase86-test-incident', 'Phase 86 extensions test fixture', $now, $now, $adminId]
);
$ticketId = (int) db_insert_id();
ok("test incident created (id={$ticketId})");

$createdMajorIds = [];
$createdCommandIds = [];
register_shutdown_function(function () use (&$createdMajorIds, &$createdCommandIds, $ticketId, $dispatcherId) {
    global $prefix;
    foreach ($createdCommandIds as $cid) {
        try { db_query("DELETE FROM `{$prefix}newui_major_incident_command` WHERE id = ?", [$cid]); } catch (Throwable $e) {}
    }
    foreach ($createdMajorIds as $mid) {
        try { db_query("DELETE FROM `{$prefix}newui_major_incident_links` WHERE major_id = ?", [$mid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}newui_major_incident_command` WHERE major_incident_id = ?", [$mid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}newui_major_incidents` WHERE id = ?", [$mid]); } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$dispatcherId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$dispatcherId]); } catch (Throwable $e) {}
});

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 4. RBAC enforced through the REAL endpoint --\n";
$r = p86_run('POST', $dispatcherId, ['action' => 'create', 'name' => 'claude-phase86-should-fail']);
is_ok(empty($r['success']), 'Dispatcher-tier user is REFUSED create', json_encode($r));

$r = p86_run('POST', $dispatcherId, ['action' => 'escalate', 'incident_id' => $ticketId]);
is_ok(empty($r['success']), 'Dispatcher-tier user is REFUSED escalate', json_encode($r));

$r = p86_run('POST', $dispatcherId, ['action' => 'add_command', 'major_id' => 999999, 'agency' => 'Test', 'external_name' => 'X']);
is_ok(empty($r['success']), 'Dispatcher-tier user is REFUSED add_command', json_encode($r));

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 5. create with event_type (admin) --\n";
$r = p86_run('POST', $adminId, [
    'action' => 'create', 'name' => 'claude-phase86-structure-fire',
    'event_type' => 'structure-fire', 'mutual_aid_requested' => true,
]);
is_ok(!empty($r['success']) && !empty($r['major_id']), 'admin can create with event_type', json_encode($r));
$majorId = (int) ($r['major_id'] ?? 0);
if ($majorId > 0) { $createdMajorIds[] = $majorId; }

$row = db_fetch_one("SELECT event_type, mutual_aid_requested FROM `{$prefix}newui_major_incidents` WHERE id = ?", [$majorId]);
is_ok($row && $row['event_type'] === 'structure-fire', 'event_type persisted correctly');
is_ok($row && (int) $row['mutual_aid_requested'] === 1, 'mutual_aid_requested persisted correctly');

$r = p86_run('POST', $adminId, ['action' => 'create', 'name' => 'claude-phase86-bad-type', 'event_type' => 'not-a-real-type']);
$badTypeRow = null;
if (!empty($r['success'])) {
    $createdMajorIds[] = (int) $r['major_id'];
    $badTypeRow = db_fetch_one("SELECT event_type FROM `{$prefix}newui_major_incidents` WHERE id = ?", [(int) $r['major_id']]);
}
is_ok($badTypeRow && $badTypeRow['event_type'] === null, 'an invalid event_type is silently rejected to NULL, not stored verbatim');

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 6. Dispatcher CAN link/unlink (unchanged, routine-tier permission) --\n";
$r = p86_run('POST', $dispatcherId, ['action' => 'link', 'major_id' => $majorId, 'ticket_id' => $ticketId]);
is_ok(!empty($r['success']), 'Dispatcher-tier user CAN link an incident', json_encode($r));

$detail = p86_run('GET', $adminId, "id={$majorId}");
$linkedIds = array_map(fn($l) => (int) $l['ticket_id'], $detail['linked_incidents'] ?? []);
is_ok(in_array($ticketId, $linkedIds, true), 'linked incident appears in the detail view');

$r = p86_run('POST', $dispatcherId, ['action' => 'unlink', 'major_id' => $majorId, 'ticket_id' => $ticketId]);
is_ok(!empty($r['success']), 'Dispatcher-tier user CAN unlink', json_encode($r));

// re-link for the rollup/close tests below
p86_run('POST', $adminId, ['action' => 'link', 'major_id' => $majorId, 'ticket_id' => $ticketId]);

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 7. escalate creates a new major incident with parent_incident_id set --\n";
$r = p86_run('POST', $adminId, ['action' => 'escalate', 'incident_id' => $ticketId, 'event_type' => 'mci']);
is_ok(!empty($r['success']) && !empty($r['major_id']), 'admin can escalate an incident', json_encode($r));
$escalatedId = (int) ($r['major_id'] ?? 0);
if ($escalatedId > 0) { $createdMajorIds[] = $escalatedId; }
$erow = db_fetch_one("SELECT parent_incident_id, event_type FROM `{$prefix}newui_major_incidents` WHERE id = ?", [$escalatedId]);
is_ok($erow && (int) $erow['parent_incident_id'] === $ticketId, 'parent_incident_id set to the originating ticket');
$escLink = db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` WHERE major_id = ? AND ticket_id = ?",
    [$escalatedId, $ticketId]
);
is_ok((int) $escLink === 1, 'escalate also links the originating incident in the same action');

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 8. Unified command: real table, member + external, soft-remove --\n";
$r = p86_run('POST', $adminId, [
    'action' => 'add_command', 'major_id' => $majorId,
    'member_id' => $adminId, 'agency' => 'claude-phase86-fire-dept', 'role' => 'incident_commander',
]);
is_ok(!empty($r['success']) && !empty($r['command_id']), 'admin can add a linked-account command member', json_encode($r));
$cmdId1 = (int) ($r['command_id'] ?? 0);
if ($cmdId1 > 0) { $createdCommandIds[] = $cmdId1; }

$r = p86_run('POST', $adminId, [
    'action' => 'add_command', 'major_id' => $majorId,
    'external_name' => 'Chief Ramirez', 'agency' => 'claude-phase86-county-fire', 'role' => 'unified_command',
]);
is_ok(!empty($r['success']), 'admin can add an EXTERNAL (no TicketsCAD account) command member', json_encode($r));
$cmdId2 = (int) ($r['command_id'] ?? 0);
if ($cmdId2 > 0) { $createdCommandIds[] = $cmdId2; }

$detail = p86_run('GET', $adminId, "id={$majorId}");
$agencies = array_map(fn($c) => $c['agency'], $detail['command'] ?? []);
is_ok(in_array('claude-phase86-fire-dept', $agencies, true) && in_array('claude-phase86-county-fire', $agencies, true),
    'both command members appear in the detail view', json_encode($detail['command'] ?? null));
$externalRow = null;
foreach (($detail['command'] ?? []) as $c) { if ($c['agency'] === 'claude-phase86-county-fire') { $externalRow = $c; } }
is_ok($externalRow && $externalRow['member_id'] === null, 'the external commander has member_id=NULL (distinguishable from a linked user)');

$r = p86_run('POST', $dispatcherId, ['action' => 'remove_command', 'command_id' => $cmdId2]);
is_ok(empty($r['success']), 'Dispatcher-tier user is REFUSED remove_command');

$r = p86_run('POST', $adminId, ['action' => 'remove_command', 'command_id' => $cmdId2]);
is_ok(!empty($r['success']), 'admin can remove a command member', json_encode($r));
$removed = db_fetch_one("SELECT left_at FROM `{$prefix}newui_major_incident_command` WHERE id = ?", [$cmdId2]);
is_ok($removed && $removed['left_at'] !== null, 'removal is a SOFT remove (left_at stamped), the row is not deleted');

$detail = p86_run('GET', $adminId, "id={$majorId}");
$agenciesAfter = array_map(fn($c) => $c['agency'], $detail['command'] ?? []);
is_ok(!in_array('claude-phase86-county-fire', $agenciesAfter, true), 'a removed (left_at set) command member no longer appears as active');

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 9. Live resource rollup (never cached) --\n";
require_once $root . '/inc/major-events-rollup.php';
$rollupBefore = major_event_resource_rollup($majorId);
is_ok($rollupBefore['units_assigned'] === 0, 'rollup starts at 0 with no assignments', json_encode($rollupBefore));

db_query(
    "INSERT INTO `{$prefix}responder` (`name`, `description`) VALUES (?, ?)",
    ['claude-phase86-unit', 'Phase 86 test fixture responder']
);
$responderId = (int) db_insert_id();
db_query(
    "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `dispatched`) VALUES (?, ?, ?, ?)",
    [$ticketId, $responderId, $adminId, $now]
);
$assignId = (int) db_insert_id();

$rollupAfter = major_event_resource_rollup($majorId);
is_ok($rollupAfter['units_assigned'] === 1, 'rollup reflects the real assignment WITHOUT any cache/recompute step', json_encode($rollupAfter));
is_ok($rollupAfter['units_active'] === 1, 'the unassigned-clear unit counts as active');

db_query("UPDATE `{$prefix}assigns` SET `clear` = ? WHERE id = ?", [$now, $assignId]);
$rollupCleared = major_event_resource_rollup($majorId);
is_ok($rollupCleared['units_assigned'] === 1 && $rollupCleared['units_active'] === 0,
    'a cleared unit still counts as assigned but no longer active', json_encode($rollupCleared));

db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$responderId]);

// ═══════════════════════════════════════════════════════════════════════
echo "\n-- 10. close ends any active command-roster entries --\n";
$r = p86_run('POST', $adminId, [
    'action' => 'add_command', 'major_id' => $majorId,
    'external_name' => 'Ops Chief', 'agency' => 'claude-phase86-still-active', 'role' => 'ops',
]);
$cmdId3 = (int) ($r['command_id'] ?? 0);
if ($cmdId3 > 0) { $createdCommandIds[] = $cmdId3; }
is_ok($cmdId3 > 0, 'command member added for the close test');

$r = p86_run('POST', $dispatcherId, ['action' => 'close', 'major_id' => $majorId]);
is_ok(empty($r['success']), 'Dispatcher-tier user is REFUSED close');

$r = p86_run('POST', $adminId, ['action' => 'close', 'major_id' => $majorId]);
is_ok(!empty($r['success']), 'admin can close the major incident', json_encode($r));

$closedCmd = db_fetch_one("SELECT left_at FROM `{$prefix}newui_major_incident_command` WHERE id = ?", [$cmdId3]);
is_ok($closedCmd && $closedCmd['left_at'] !== null, 'closing the event ends the still-active command-roster entry');

$linkedTicketStatus = db_fetch_value("SELECT status FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]);
is_ok((int) $linkedTicketStatus === 1, 'closing the major event still cascade-closes its linked incident (unchanged pre-existing behavior)');

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
