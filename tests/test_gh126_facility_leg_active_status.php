<?php
/**
 * test_gh126_facility_leg_active_status.php — GH#126, a community-submitted
 * patch from rjonesbsink (rjonesbsink/TicketsCAD:fix/unit-detail-
 * facility-leg-status, applied here with attribution preserved).
 *
 * THE BUG: unit-detail.php's Active Assignments list never advanced past
 * "On Scene". GH#64 gave the facility leg its own write-once timestamp
 * slots (assigns.u2fenr/u2farr) rather than overloading on_scene a second
 * time, but api/responder-detail.php's active-assignments query never
 * selected either column, and its currentState ladder only checked
 * on_scene/responding — so a unit stamped through Transport (u2fenr) or
 * Trans Arrived (u2farr) still read "On Scene" even though the underlying
 * assigns row was correctly stamped. The file's OWN recent-assignments
 * block (further down the same file) already selected both columns —
 * only the active list missed them.
 *
 * THE FIX: add u2fenr/u2farr to the active query's SELECT, and extend the
 * ladder latest-milestone-first (At Facility > To Facility > On Scene >
 * Responding > Dispatched), reusing labels already established elsewhere
 * in this codebase (api/reports.php's interval report, api/log.php's
 * action names) rather than inventing new ones. Display-only — no writer,
 * schema, or API-shape change; the two new response keys are additive.
 *
 * This file proves the fix by driving the REAL writer
 * (responder_set_status_internal()) through every milestone in sequence
 * against a real fixture, then reading each intermediate state back
 * through the REAL api/responder-detail.php endpoint via a CLI subprocess
 * probe — never hand-seeding assigns rows to the "already correct" shape.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_fixture_guard.php';
require_once __DIR__ . '/_test_node_probe.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#126 — unit-detail Active Assignments facility-leg display (rjonesbsink patch) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

function gh126_probe(int $responderId, int $userId): ?array {
    $php = PHP_BINARY ?: 'php';
    $out = test_run_cli([$php, __DIR__ . '/_gh126_responder_detail_probe.php', (string) $responderId, (string) $userId]);
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

function gh126_active_row(array $payload, int $assignId): ?array {
    foreach (($payload['active_assignments'] ?? []) as $row) {
        if ((int) ($row['assign_id'] ?? 0) === $assignId) { return $row; }
    }
    return null;
}

$userId = test_admin_user_id();
$responderIds = []; $ticketIds = []; $assignIds = []; $statusIds = [];

function gh126_mk_status(string $prefix, array &$statusIds, string $val, string $incidentAction): int {
    db_query(
        "INSERT INTO `{$prefix}un_status`
            (`status_val`, `description`, `incident_action`, `dispatch`, `watch`, `hide`, `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
         VALUES (?, ?, ?, 0, 0, 'n', 'n', 'gh126_test', 999, '#888888', '#000000')",
        [$val, 'GH126 test — ' . $val, $incidentAction]
    );
    $id = (int) db_insert_id();
    test_fixture_guard_track('un_status', $id);
    $statusIds[] = $id;
    return $id;
}

try {
    $sResp    = gh126_mk_status($prefix, $statusIds, 'gh126_resp', 'responding');
    $sScene   = gh126_mk_status($prefix, $statusIds, 'gh126_onscene', 'on_scene');
    $sEnroute = gh126_mk_status($prefix, $statusIds, 'gh126_facenr', 'facility_enroute');
    $sArrived = gh126_mk_status($prefix, $statusIds, 'gh126_facarr', 'facility_arrived');

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh126_unit', 'GH126U', 'test', 1, NOW(), NOW())");
    $responderId = (int) db_insert_id();
    $responderIds[] = $responderId;
    test_fixture_guard_track('responder', $responderId);

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh126_call', 'GH126 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id(); $ticketIds[] = $tid;
    test_fixture_guard_track('ticket', $tid);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);

    $ra = assign_create_internal($tid, $responderId, '', $userId);
    $aid = (int) ($ra['id'] ?? 0);
    is_true($aid > 0, 'fixture: unit assigned via the real writer', json_encode($ra));
    $assignIds[] = $aid;
    test_fixture_guard_track('assigns', $aid);

    // ── Step 0: freshly dispatched ──
    $p0 = gh126_probe($responderId, $userId);
    is_true($p0 !== null, 'probe (dispatched) returned decodable JSON', json_encode($p0));
    $row0 = $p0 !== null ? gh126_active_row($p0, $aid) : null;
    is_true($row0 !== null && $row0['status'] === 'Dispatched', 'state 0: Dispatched', json_encode($row0));

    // ── Step 1: responding (via the REAL mobile-side writer) ──
    $r1 = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aid);
    is_true(empty($r1['errors']), 'writer: responding succeeds', implode(';', $r1['errors'] ?? []));
    $p1 = gh126_probe($responderId, $userId);
    $row1 = $p1 !== null ? gh126_active_row($p1, $aid) : null;
    is_true($row1 !== null && $row1['status'] === 'Responding', 'state 1: Responding', json_encode($row1));

    // ── Step 2: on scene ──
    $r2 = responder_set_status_internal($responderId, $sScene, $userId, '', null, null, $aid);
    is_true(empty($r2['errors']), 'writer: on_scene succeeds', implode(';', $r2['errors'] ?? []));
    $p2 = gh126_probe($responderId, $userId);
    $row2 = $p2 !== null ? gh126_active_row($p2, $aid) : null;
    is_true($row2 !== null && $row2['status'] === 'On Scene', 'state 2: On Scene', json_encode($row2));

    // ── Step 3: facility_enroute (Transport) — THE BUG: pre-fix this read "On Scene" ──
    $r3 = responder_set_status_internal($responderId, $sEnroute, $userId, '', null, null, $aid);
    is_true(empty($r3['errors']), 'writer: facility_enroute succeeds', implode(';', $r3['errors'] ?? []));
    $p3 = gh126_probe($responderId, $userId);
    $row3 = $p3 !== null ? gh126_active_row($p3, $aid) : null;
    is_true($row3 !== null && $row3['status'] === 'To Facility',
        'FIX: state 3 advances to "To Facility" (was stuck at "On Scene" before this fix)', json_encode($row3));

    // ── Step 4: facility_arrived (Trans Arrived) ──
    $r4 = responder_set_status_internal($responderId, $sArrived, $userId, '', null, null, $aid);
    is_true(empty($r4['errors']), 'writer: facility_arrived succeeds', implode(';', $r4['errors'] ?? []));
    $p4 = gh126_probe($responderId, $userId);
    $row4 = $p4 !== null ? gh126_active_row($p4, $aid) : null;
    is_true($row4 !== null && $row4['status'] === 'At Facility',
        'FIX: state 4 advances to "At Facility" (was stuck at "On Scene" before this fix)', json_encode($row4));

} catch (Throwable $e) {
    bad('fixture/writer/probe path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ── Static: the endpoint file actually has the fix (not just this test) ──
$src = (string) file_get_contents($base . '/api/responder-detail.php');
is_true(strpos($src, "'At Facility'") !== false, 'api/responder-detail.php\'s active-assignments ladder includes At Facility');
is_true(strpos($src, "'To Facility'") !== false, 'api/responder-detail.php\'s active-assignments ladder includes To Facility');

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown ──
try {
    foreach ($assignIds as $aid) { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$aid]); }
    foreach ($ticketIds as $tid) {
        db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    }
    foreach ($responderIds as $rid) { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]); }
    foreach ($statusIds as $sid) { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sid]); }
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

exit($fail === 0 ? 0 : 1);
