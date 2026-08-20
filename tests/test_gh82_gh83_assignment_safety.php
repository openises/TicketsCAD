<?php
/**
 * GH#82 / GH#83 (2026-08-18) — dispatch-safety regression suite.
 *
 * GH#82: assigning a unit a SECOND active call silently reset its status
 * back to Dispatched (stomping whatever it was really doing — On Scene,
 * Transporting, ...) and, on mobile, dropped the first call off the
 * crew's screen entirely (api/mobile-data.php selected only the newest
 * assignment). The Multi-Assign checkbox (`responder.multi`), meant to be
 * the operator's control for this, was read/written but consulted by
 * nothing.
 *
 * GH#83: the per-status Dispatch level (`un_status.dispatch`) is
 * displayed (badges, colours) but was never enforced anywhere in the
 * assignment path — no warn, no block, despite Settings' own help text
 * promising both.
 *
 * Fixed together in inc/assignment-write.php's assign_create_internal():
 *   - _assign_dispatch_gate() computes a single warn/block decision from
 *     BOTH controls (current-status Dispatch level, and "already has
 *     another active assignment + Multi-Assign is off"), taking the
 *     HIGHER of the two. A hard block (dispatch=2) is never bypassed
 *     by force; a warn (level 1) requires the caller to resubmit with
 *     force=true (mirrors api/unit-assignments.php's existing
 *     needs_confirmation pattern for one-unit-per-person).
 *   - the "promote responder to Dispatched" step now uses the SAME
 *     "no other active assignment" gate the clear/unassign paths already
 *     used — previously the one asymmetric spot in the file.
 *
 * This file both PROVES the fix (run as shipped) and is designed to be
 * run again after reverting inc/assignment-write.php to its pre-fix
 * content, at which point the marked assertions below fail — see the
 * accompanying before/after run in the session that authored this file.
 *
 * Self-skips (0/0 + SKIP) if fewer than 1 un_status row can be created —
 * should never happen on a real install (un_status is core schema).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/mobile-assignments.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== GH#82 / GH#83 — assignment safety (dispatch level + Multi-Assign) ===\n\n";

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

// ── Fixture ids, tracked for teardown ──────────────────────────────
$statusIds    = [];
$ticketIds    = [];
$responderIds = [];
$assignIds    = [];

function mk_status(string $prefix, string $val, int $dispatch): int {
    db_query(
        "INSERT INTO `{$prefix}un_status`
            (`status_val`, `description`, `dispatch`, `watch`, `hide`, `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
         VALUES (?, ?, ?, 0, 'n', 'n', 'gh8283_test', 999, '#888888', '#000000')",
        [$val, 'GH82/83 test — ' . $val, $dispatch]
    );
    return (int) db_insert_id();
}

function mk_ticket(string $prefix, string $scope): int {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query(
        "INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
         VALUES (?, 2, 0, ?, 'gh8283 regression', NOW(), NOW(), 1)",
        [$typeId, $scope]
    );
    return (int) db_insert_id();
}

function mk_responder(string $prefix, string $name, int $statusId, int $multi): int {
    db_query(
        "INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, multi, status_updated, updated)
         VALUES (?, ?, 'gh8283 test unit', ?, ?, NOW(), NOW())",
        [$name, substr($name, 0, 24), $statusId, $multi]
    );
    return (int) db_insert_id();
}

function responder_status(string $prefix, int $rid): int {
    return (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]);
}

function active_assign_count(string $prefix, int $rid): int {
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}assigns` WHERE responder_id = ? AND (clear IS NULL OR DATE_FORMAT(clear,'%y')='00')",
        [$rid]
    );
}

try {
    // Distinct dispatch levels to exercise the gate.
    $stAvail   = mk_status($prefix, 'gh8283_avail',   0); // ordinary, unconfigured
    $stOnScene = mk_status($prefix, 'gh8283_onscene', 0); // a "busy" status an admin never configured
    $stWarn    = mk_status($prefix, 'gh8283_warn',    1); // Inform Only
    $stBlock   = mk_status($prefix, 'gh8283_block',   2); // Unavailable
    $statusIds = [$stAvail, $stOnScene, $stWarn, $stBlock];
    ok('4 test un_status rows created', count(array_filter($statusIds)) === 4);

    $t1 = mk_ticket($prefix, 'gh8283_ticket1');
    $t2 = mk_ticket($prefix, 'gh8283_ticket2');
    $ticketIds = [$t1, $t2];
    ok('2 test tickets created', $t1 > 0 && $t2 > 0);

    // ═══════════════════════════════════════════════════════════
    // GROUP 1 — GH#82 core bug: status must NOT be stomped, and a
    // non-Multi-Assign unit with another active call is now warned
    // (even though its current status was never admin-configured).
    // ═══════════════════════════════════════════════════════════
    $r1 = mk_responder($prefix, 'gh8283_R1', $stAvail, 0);
    $responderIds[] = $r1;

    // First assignment: no other active assignment yet -> proceeds
    // silently and promotes to Dispatched, exactly like before this fix.
    $res1a = assign_create_internal($t1, $r1, '', 1);
    ok('[G1] first assignment succeeds without confirmation', empty($res1a['needs_confirmation']) && (int) ($res1a['id'] ?? 0) > 0);
    if (!empty($res1a['id'])) $assignIds[] = (int) $res1a['id'];
    ok('[G1] first assignment promotes responder to Dispatched (unchanged single-assignment behavior)',
        responder_status($prefix, $r1) !== $stAvail);

    // Simulate real-world progression: the unit is now working the call,
    // On Scene. (Directly writing un_status_id here — not going through
    // assign_update_status_internal — is fine; Fix A only cares what the
    // responder's CURRENT status is at the moment of the second assign.)
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stOnScene, $r1]);
    ok('[G1] responder now On Scene (test setup)', responder_status($prefix, $r1) === $stOnScene);

    // Second call, WITHOUT force: implied WARN (has another active
    // assignment, Multi-Assign is off) even though gh8283_onscene's own
    // Dispatch level was never configured (stays at the column default, 0).
    $res1b = assign_create_internal($t2, $r1, '', 1);
    ok('[GH#82 FIX] second assignment without force asks for confirmation instead of silently succeeding',
        !empty($res1b['needs_confirmation']));
    ok('[G1] no assigns row created yet for the unconfirmed second call', active_assign_count($prefix, $r1) === 1);
    ok('[G1] status still untouched while unconfirmed', responder_status($prefix, $r1) === $stOnScene);

    // Second call, WITH force=true (operator confirmed "assign anyway?").
    $res1c = assign_create_internal($t2, $r1, '', 1, true);
    ok('[G1] forced second assignment succeeds', empty($res1c['errors']) && (int) ($res1c['id'] ?? 0) > 0);
    if (!empty($res1c['id'])) $assignIds[] = (int) $res1c['id'];

    // THE core GH#82 assertion: status must still read On Scene, NOT
    // reset to Dispatched.
    ok('[GH#82 FIX] status NOT stomped back to Dispatched after the second (forced) assignment',
        responder_status($prefix, $r1) === $stOnScene);
    ok('[GH#82 FIX] BOTH assignments remain active (neither silently cleared)',
        active_assign_count($prefix, $r1) === 2);

    // ═══════════════════════════════════════════════════════════
    // GROUP 2 — Multi-Assign=1 bypasses the IMPLIED warn entirely
    // (the operator's explicit "this unit runs more than one call" flag).
    // ═══════════════════════════════════════════════════════════
    $r2 = mk_responder($prefix, 'gh8283_R2', $stAvail, 1); // multi=1
    $responderIds[] = $r2;

    $res2a = assign_create_internal($t1, $r2, '', 1);
    ok('[G2] first assignment succeeds', empty($res2a['errors']) && (int) ($res2a['id'] ?? 0) > 0);
    if (!empty($res2a['id'])) $assignIds[] = (int) $res2a['id'];

    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stOnScene, $r2]);

    $res2b = assign_create_internal($t2, $r2, '', 1); // no force passed
    ok('[GH#82 FIX] Multi-Assign=1 unit gets a second call with NO confirmation prompt',
        empty($res2b['needs_confirmation']) && empty($res2b['errors']) && (int) ($res2b['id'] ?? 0) > 0);
    if (!empty($res2b['id'])) $assignIds[] = (int) $res2b['id'];
    ok('[GH#82 FIX] Multi-Assign unit\'s status also stays untouched (On Scene, not reset)',
        responder_status($prefix, $r2) === $stOnScene);

    // ═══════════════════════════════════════════════════════════
    // GROUP 3 — GH#83 status-driven WARN (Inform Only), on a unit's
    // very FIRST assignment — proves this is driven by current status,
    // not only by double-booking.
    // ═══════════════════════════════════════════════════════════
    $r3 = mk_responder($prefix, 'gh8283_R3', $stWarn, 0);
    $responderIds[] = $r3;

    $res3a = assign_create_internal($t1, $r3, '', 1);
    ok('[GH#83 FIX] a unit whose current status is Dispatch-level Inform Only asks for confirmation on its FIRST assignment',
        !empty($res3a['needs_confirmation']));
    ok('[G3] message names "Assign anyway?"', strpos((string) ($res3a['message'] ?? ''), 'Assign anyway?') !== false);
    ok('[G3] no row created yet', active_assign_count($prefix, $r3) === 0);

    $res3b = assign_create_internal($t1, $r3, '', 1, true);
    ok('[G3] forced assignment succeeds', empty($res3b['errors']) && (int) ($res3b['id'] ?? 0) > 0);
    if (!empty($res3b['id'])) $assignIds[] = (int) $res3b['id'];
    ok('[G3] promoted to Dispatched (sole active assignment)', responder_status($prefix, $r3) !== $stWarn);

    // ═══════════════════════════════════════════════════════════
    // GROUP 4 — GH#83 status-driven BLOCK (Unavailable). Never
    // bypassable, even with force=true.
    // ═══════════════════════════════════════════════════════════
    $r4 = mk_responder($prefix, 'gh8283_R4', $stBlock, 0);
    $responderIds[] = $r4;

    $res4a = assign_create_internal($t1, $r4, '', 1);
    ok('[GH#83 FIX] Dispatch-level Unavailable refuses the assignment outright', !empty($res4a['errors']));
    ok('[G4] refusal is flagged as a hard block, not a generic error', !empty($res4a['blocked']));
    ok('[G4] no row created', active_assign_count($prefix, $r4) === 0);

    $res4b = assign_create_internal($t1, $r4, '', 1, true); // force=true
    ok('[GH#83 FIX] force=true does NOT bypass a hard block', !empty($res4b['errors']) && !empty($res4b['blocked']));
    ok('[G4] still no row created after attempting force', active_assign_count($prefix, $r4) === 0);

    // ═══════════════════════════════════════════════════════════
    // GROUP 5 — precedence: an explicit admin BLOCK always wins over
    // Multi-Assign=1 (Multi-Assign only ever waives the IMPLIED warn;
    // it never suppresses a configured block).
    // ═══════════════════════════════════════════════════════════
    $r5 = mk_responder($prefix, 'gh8283_R5', $stBlock, 1); // multi=1 AND blocked status
    $responderIds[] = $r5;

    $res5a = assign_create_internal($t1, $r5, '', 1, true);
    ok('[GH#83 FIX] Multi-Assign=1 never overrides a Dispatch-level Unavailable block',
        !empty($res5a['errors']) && !empty($res5a['blocked']));
    ok('[G5] no row created', active_assign_count($prefix, $r5) === 0);

    // ═══════════════════════════════════════════════════════════
    // GROUP 6 — GH#82 mobile half: api/mobile-data.php used to select
    // only the NEWEST active assignment (`ORDER BY a.id DESC LIMIT 1`),
    // which drops the crew's original call off their own screen the
    // moment a second one lands. mobile_active_assignments() (inc/
    // mobile-assignments.php) must return BOTH, oldest first. Compare
    // directly against the OLD query shape to show the old query really
    // would have hidden ticket1.
    // ═══════════════════════════════════════════════════════════
    $oldShapeRows = db_fetch_all(
        "SELECT a.id AS assign_id, a.ticket_id
           FROM `{$prefix}assigns` a
           JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
          WHERE a.responder_id = ?
            AND (a.clear IS NULL OR DATE_FORMAT(a.clear,'%y')='00')
            AND t.status = 2
          ORDER BY a.id DESC
          LIMIT 1",
        [$r1]
    );
    ok('[GH#82 pre-fix shape] the OLD single-row/newest-only query would have hidden ticket #' . $t1
        . ' (returns only ticket #' . ($oldShapeRows[0]['ticket_id'] ?? '?') . ')',
        count($oldShapeRows) === 1 && (int) $oldShapeRows[0]['ticket_id'] === $t2);

    $newRows = mobile_active_assignments($prefix, [$r1]);
    ok('[GH#82 FIX] mobile_active_assignments() returns BOTH active calls for the unit', count($newRows) === 2);
    ok('[GH#82 FIX] oldest (original) call sorts first', (int) ($newRows[0]['ticket_id'] ?? 0) === $t1);
    ok('[GH#82 FIX] second call is still present, not dropped', (int) ($newRows[1]['ticket_id'] ?? 0) === $t2);

} catch (Exception $e) {
    echo "  [FAIL] fixture/test threw: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Teardown ────────────────────────────────────────────────────────
try {
    foreach ($assignIds as $aid) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$aid]);
    }
    // Belt-and-suspenders: catch any assigns rows created via responder/
    // ticket id even if an id wasn't captured above (e.g. an assertion
    // threw before we recorded it).
    foreach ($responderIds as $rid) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE responder_id = ?", [$rid]);
        db_query("DELETE FROM `{$prefix}action` WHERE description LIKE 'Assigned %' AND user = 1
                    AND ticket_id IN (" . implode(',', array_map('intval', $ticketIds ?: [0])) . ")");
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    }
    foreach ($ticketIds as $tid) {
        db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    }
    foreach ($statusIds as $sid) {
        db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sid]);
    }
    ok('teardown complete', true);
} catch (Exception $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
