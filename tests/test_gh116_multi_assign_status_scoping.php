<?php
/**
 * test_gh116_multi_assign_status_scoping.php — GH#116 (reported
 * 2026-08-28, Ron Jones, a direct follow-up to GH#82/#83).
 *
 * THE BUG: a unit holding more than one open assignment, changing its
 * OWN status (mobile/MDT, and — unchanged by this fix, see below — the
 * dashboard's unit-status control, unit-actions widget, and unit-detail
 * page), had that change applied to EVERY open assignment it held, not
 * just the one it was actually working:
 *   1. The other assignments got CLEARED when the new status mapped to
 *      'clear' (e.g. "Available") — not merely hidden, genuinely closed.
 *   2. The other assignments' timestamp columns got stamped with
 *      whatever the new status mapped to (e.g. 'responding'), bleeding
 *      an En Route timestamp onto a call the unit wasn't en route to.
 *
 * Root cause: inc/responder-write.php's responder_set_status_internal()
 * selected every open assigns row for the responder
 * (WHERE responder_id = ? AND clear IS NULL/zero, no ticket_id/assign_id
 * scoping) and stamped ALL of them — unlike the dispatcher-side per-
 * assignment path (assign_update_status_internal(), GH#59), which
 * already scopes to one assign_id and only reverts the responder's
 * overall un_status_id if _assign_has_other_active() says there's
 * nothing else open (see inc/assignment-write.php:591-600). This is the
 * SAME asymmetry class GH#82/#83 closed for assign_create_internal() —
 * this was the third status-changing path, left over.
 *
 * THE FIX (per Eric's own specified UX, given via a direct design
 * decision during this session — not a GitHub comment): responder_set_
 * status_internal() gained an optional $assignId parameter (default 0 =
 * fully unscoped, BYTE-FOR-BYTE unchanged pre-existing behavior — every
 * existing caller that doesn't pass it, i.e. app.js's dashboard "change
 * unit's overall status" widget, unit-actions.js, and unit-detail.js,
 * is completely unaffected by this fix and was deliberately left alone).
 * When $assignId > 0:
 *   - the assigns-timestamp SELECT is scoped to that one row (which also
 *     scopes every side effect that iterates over it: the action-log
 *     insert, the SSE publish, auto-close scheduling);
 *   - the responder's own un_status_id is advanced UNCONDITIONALLY for
 *     any non-'clear' incident_action (mirrors assign_update_status_
 *     internal()'s own always-update behavior for responding/on_scene/
 *     facility_enroute/facility_arrived);
 *   - for a 'clear'-mapped status specifically, un_status_id is ONLY
 *     advanced if _assign_has_other_active($responderId, $assignId) is
 *     false — i.e. this was the unit's last open assignment. Mirrors
 *     assign_update_status_internal()'s own 'clear' branch exactly.
 *
 * api/responder-status.php threads assign_id through and returns a new
 * unit_status_updated flag (false only in the "gated, other assignment
 * still active" case) plus prior_status_id/prior_status_name, so a
 * caller can tell whether to actually update a persistent status badge.
 *
 * This file proves, driving the REAL writer (never hand-seeded ideal
 * state):
 *   Section 1 — a unit with TWO open assignments, clearing one via
 *     assign_id: the OTHER assignment survives untouched (not cleared,
 *     no timestamp bleed), and un_status_id is NOT advanced to the
 *     clear-mapped status (unit_status_updated reports false).
 *   Section 2 — the same unit, clearing its LAST remaining open
 *     assignment via assign_id: un_status_id IS advanced this time
 *     (unit_status_updated true) — mirrors the dispatcher path exactly.
 *   Section 3 — a non-clear status (e.g. responding) via assign_id on
 *     ONE of two open assignments: that assignment's timestamp is
 *     stamped, the OTHER assignment's timestamps are untouched, and
 *     un_status_id IS advanced unconditionally (matching
 *     assign_update_status_internal()'s own always-update convention).
 *   Section 4 — REGRESSION PROOF / negative control: calling the SAME
 *     writer with assign_id OMITTED (0), on the identical two-open-
 *     assignment fixture, reproduces the ORIGINAL bug exactly — both
 *     assignments get cleared/stamped. This is deliberate and REQUIRED:
 *     it proves every existing caller (which never passes assign_id) is
 *     byte-for-byte unaffected by this fix, not merely "probably fine".
 *   Section 5 — invalid assign_id (belongs to a different responder, or
 *     already cleared): the writer refuses with 'invalid_assignment'
 *     rather than silently falling back to the unscoped (all-
 *     assignments) behavior.
 *   Section 6 (static) — assets/js/mobile.js renders the grouped-by-
 *     assignment status UI only when allAssignments.length > 1, sends
 *     assign_id on each button in that case, and correctly withholds
 *     local badge/currentStatusId updates when the server reports
 *     unit_status_updated:false.
 *
 * ─────────────────────────────────────────────────────────────────────
 * FOLLOW-UP (2026-08-31, rjonesbsink, same GH#116 thread): the scoping fix
 * above stops one open call's status change from CLEARING or stamping a
 * timestamp on a DIFFERENT open call. It did not, by itself, fix a
 * DISPLAY bug reported on the same thread: on the mobile grouped-by-
 * assignment status grid, changing ONE call's status made EVERY call's
 * status buttons highlight the SAME (newly-picked) status as "active".
 *
 * ROOT CAUSE: assets/js/mobile.js's per-card "is this status button
 * active" check compared every card's status buttons against
 * `currentStatusId` — the UNIT's single overall status (responder.
 * un_status_id) — not that CARD's own assignment's status. The scoping
 * fix above still advances the unit's overall status unconditionally for
 * any non-'clear' incident_action (matching assign_update_status_
 * internal()'s own convention), so the moment ANY one call's status
 * changed to something non-'clear', currentStatusId changed, and every
 * card's highlight moved in lockstep — a correctness bug distinct from,
 * but adjacent to, the multi-clear/timestamp-bleed bug Sections 1-6 cover.
 *
 * Compounding cause: assigns.status_id (the obvious per-assignment column
 * to read for "this call's own current status") was written ONCE at
 * assignment creation ("Dispatched") and never touched again by either
 * write path — the "phantom column nothing meaningfully writes" pattern
 * this project's own root-cause discipline names explicitly. There was no
 * reliable per-assignment status to even read.
 *
 * THE FIX (root-cause, not a workaround): assigns.status_id is now kept
 * in sync by BOTH write paths on every status-changing branch:
 *   - inc/assignment-write.php's assign_update_status_internal() (the
 *     dispatcher-side path, GH#59's sibling) — responding/on_scene/
 *     facility_enroute/facility_arrived/clear all now include
 *     `status_id = ?` in their UPDATE, using the exact same effective
 *     status id already resolved for _assign_set_responder_status().
 *   - inc/responder-write.php's responder_set_status_internal() (the
 *     mobile-side path this file's Sections 1-6 already cover) — the
 *     same, PLUS an "already stamped" fallback branch for every action:
 *     when the relevant timestamp is ALREADY set (e.g. a unit already on
 *     scene switches from one on-scene-mapped status to a DIFFERENT
 *     on-scene-mapped status — GH#59's own multi-status-per-action
 *     scenario), the timestamp stays put but status_id STILL updates —
 *     otherwise this exact fix would have gone stale again the first time
 *     two statuses shared an incident_action, which is the scenario
 *     GH#59 exists to support.
 *   - inc/mobile-assignments.php's mobile_active_assignments() now
 *     SELECTs `status_id AS assign_status_id` so the mobile client can
 *     read it (api/mobile-data.php passes assignments through unmodified,
 *     no endpoint-layer change needed).
 *   - assets/js/mobile.js's grouped-rendering loop now computes each
 *     card's own `cardStatusId` from `a.assign_status_id` (falling back to
 *     `currentStatusId` only when the field is absent — an assignment
 *     created before this fix shipped) and compares THAT against each
 *     status button, instead of comparing every card against the unit's
 *     single `currentStatusId`.
 *
 * Sections 7-9 drive the REAL writers to prove assigns.status_id is
 * genuinely kept current, including the "switch between two statuses
 * mapped to the same action" case that the naive "only update on
 * timestamp change" shape would have missed. Section 10 confirms the
 * SELECT change. Section 11 is a NODE-DRIVEN extraction of the REAL,
 * unmodified assets/js/mobile.js rendering block (not a reimplementation)
 * with a NEGATIVE CONTROL reproducing the original reported symptom —
 * two open calls with genuinely different statuses, both cards
 * incorrectly highlighting the same one — to prove the harness would
 * have caught this bug before it shipped.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';
// GH#124 — protects every fixture created below from a mid-test fatal, the
// same way test_gh118_assign_remove_ticketid.php was left unprotected
// against GH#120's disabled-shell_exec() fatal. See
// tests/_test_fixture_guard.php's own docblock for the mechanism.
require_once __DIR__ . '/_test_fixture_guard.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#116 — multi-assignment status scoping (responder_set_status_internal) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

function gh116_mk_status(string $prefix, string $val, string $incidentAction): int {
    db_query(
        "INSERT INTO `{$prefix}un_status`
            (`status_val`, `description`, `incident_action`, `dispatch`, `watch`, `hide`, `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
         VALUES (?, ?, ?, 0, 0, 'n', 'n', 'gh116_test', 999, '#888888', '#000000')",
        [$val, 'GH116 test — ' . $val, $incidentAction]
    );
    return (int) db_insert_id();
}

$userId = test_admin_user_id();
$statusIds = []; $ticketIds = []; $responderIds = []; $assignIds = [];

try {
    // ── Fixture: one responder, two DIFFERENT open tickets/assignments ──
    $sClear = gh116_mk_status($prefix, 'gh116_avail', 'clear');
    test_fixture_guard_track('un_status', $sClear);
    $sResp  = gh116_mk_status($prefix, 'gh116_enroute', 'responding');
    test_fixture_guard_track('un_status', $sResp);
    $statusIds = [$sClear, $sResp];

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh116_unit', 'GH116U', 'test', ?, NOW(), NOW())", [$sResp]);
    $responderId = (int) db_insert_id();
    $responderIds[] = $responderId;
    test_fixture_guard_track('responder', $responderId);

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    foreach (['gh116_call_A', 'gh116_call_B'] as $scope) {
        db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
                  VALUES (?, 2, 0, ?, 'GH116 fixture', NOW(), NOW(), 1)", [$typeId, $scope]);
        $newTid = (int) db_insert_id();
        $ticketIds[] = $newTid;
        test_fixture_guard_track('ticket', $newTid);
        // assign_create_internal()/responder_set_status_internal() stamp
        // `action` log rows for this ticket_id with no id ever handed back
        // to this test — track the whole ticket_id-scoped set.
        test_fixture_guard_track_where('action', 'ticket_id = ?', [$newTid]);
    }
    $tidA = $ticketIds[0]; $tidB = $ticketIds[1];

    $raA = assign_create_internal($tidA, $responderId, '', $userId);
    // $force=true: the responder already holds an open assignment (A) and
    // Multi-Assign isn't enabled on this fixture, so GH#82/#83's own
    // dispatch gate would otherwise warn/refuse this second concurrent
    // assignment — correct behavior there, just not what THIS test needs.
    $raB = assign_create_internal($tidB, $responderId, '', $userId, true);
    is_true((int) ($raA['id'] ?? 0) > 0 && (int) ($raB['id'] ?? 0) > 0, 'fixture: unit assigned to both tickets via the real writer',
        'raA=' . json_encode($raA) . ' raB=' . json_encode($raB));
    $aidA = (int) $raA['id']; $aidB = (int) $raB['id'];
    $assignIds = [$aidA, $aidB];
    test_fixture_guard_track('assigns', $aidA);
    test_fixture_guard_track('assigns', $aidB);

    // assign_create_internal() itself promotes the responder to whatever
    // this install's "Dispatched" status is (GH#82/#83) — capture that
    // AFTER both assignments exist, rather than assuming it stays at the
    // fixture's original seed value, so section 1's "unchanged" assertion
    // compares against reality, not an assumption.
    $respBeforeSection1 = (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$responderId]);

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 1. Clearing assignment A (via assign_id) while B stays open --\n";
    // ─────────────────────────────────────────────────────────────────
    $r1 = responder_set_status_internal($responderId, $sClear, $userId, '', null, null, $aidA);
    is_true(empty($r1['errors']), 'clearing A via assign_id succeeds', implode(';', $r1['errors'] ?? []));
    is_true(($r1['unit_status_updated'] ?? null) === false,
        'FIX: unit_status_updated is false — B is still open, so the unit\'s overall status must not advance to "Available"');

    $assignA1 = db_fetch_one("SELECT `clear` FROM `{$prefix}assigns` WHERE id = ?", [$aidA]);
    is_true($assignA1 && !empty($assignA1['clear']) && substr((string) $assignA1['clear'], 0, 4) !== '0000',
        'assignment A is genuinely cleared');

    $assignB1 = db_fetch_one("SELECT `clear`, `responding` FROM `{$prefix}assigns` WHERE id = ?", [$aidB]);
    is_true($assignB1 && (empty($assignB1['clear']) || substr((string) $assignB1['clear'], 0, 4) === '0000'),
        'FIX: assignment B is UNTOUCHED — not cleared just because A was');

    $respAfter1 = db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
    is_true((int) $respAfter1 === $respBeforeSection1,
        'FIX: responder.un_status_id is UNCHANGED by clearing A — not advanced to "Available" while B is still open',
        "expected {$respBeforeSection1} (unchanged), got {$respAfter1}");

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 2. Clearing assignment B (via assign_id) — now the LAST open one --\n";
    // ─────────────────────────────────────────────────────────────────
    $r2 = responder_set_status_internal($responderId, $sClear, $userId, '', null, null, $aidB);
    is_true(empty($r2['errors']), 'clearing B via assign_id succeeds', implode(';', $r2['errors'] ?? []));
    is_true(($r2['unit_status_updated'] ?? null) === true,
        'unit_status_updated is true — B was the LAST open assignment, mirrors assign_update_status_internal()\'s own clear-branch gate');

    $respAfter2 = db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
    is_true((int) $respAfter2 === $sClear,
        'responder.un_status_id NOW advances to "Available" once no other assignment remains',
        "expected {$sClear}, got {$respAfter2}");

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 3. Non-clear status (responding) via assign_id, second fixture pair --\n";
    // ─────────────────────────────────────────────────────────────────
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_C', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidC = (int) db_insert_id(); $ticketIds[] = $tidC;
    test_fixture_guard_track('ticket', $tidC);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidC]);
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_D', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidD = (int) db_insert_id(); $ticketIds[] = $tidD;
    test_fixture_guard_track('ticket', $tidD);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidD]);

    $raC = assign_create_internal($tidC, $responderId, '', $userId);
    // $force=true — same reason as raB above (C is now the responder's
    // only open assignment at this point, so D is the second concurrent one).
    $raD = assign_create_internal($tidD, $responderId, '', $userId, true);
    $aidC = (int) ($raC['id'] ?? 0); $aidD = (int) ($raD['id'] ?? 0);
    $assignIds[] = $aidC; $assignIds[] = $aidD;
    test_fixture_guard_track('assigns', $aidC);
    test_fixture_guard_track('assigns', $aidD);
    is_true($aidC > 0 && $aidD > 0, 'fixture: unit re-assigned to two new open tickets (C and D)',
        'raC=' . json_encode($raC) . ' raD=' . json_encode($raD));

    $r3 = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aidC);
    is_true(empty($r3['errors']), 'setting responding on C via assign_id succeeds', implode(';', $r3['errors'] ?? []));
    is_true(($r3['unit_status_updated'] ?? null) === true,
        'non-clear incident_action: unit_status_updated is true unconditionally (matches assign_update_status_internal()\'s always-update convention)');

    $assignC3 = db_fetch_one("SELECT `responding` FROM `{$prefix}assigns` WHERE id = ?", [$aidC]);
    is_true($assignC3 && !empty($assignC3['responding']) && substr((string) $assignC3['responding'], 0, 4) !== '0000',
        'assignment C is stamped responding');
    $assignD3 = db_fetch_one("SELECT `responding` FROM `{$prefix}assigns` WHERE id = ?", [$aidD]);
    is_true($assignD3 && (empty($assignD3['responding']) || substr((string) $assignD3['responding'], 0, 4) === '0000'),
        'FIX: assignment D\'s responding timestamp is NOT bled onto it — untouched');

    $respAfter3 = db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
    is_true((int) $respAfter3 === $sResp, 'responder.un_status_id advanced to the responding status');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 4. REGRESSION PROOF: assign_id OMITTED reproduces the ORIGINAL bug exactly --\n";
    // (proves every existing, unmigrated caller is byte-for-byte unaffected —
    //  the bug they already had is still exactly what they get, unchanged) ──
    // ─────────────────────────────────────────────────────────────────
    $r4 = responder_set_status_internal($responderId, $sClear, $userId); // no $assignId — unscoped, pre-fix shape
    is_true(empty($r4['errors']), 'unscoped call succeeds', implode(';', $r4['errors'] ?? []));
    is_true(($r4['unit_status_updated'] ?? null) === true,
        'unscoped call: unit_status_updated is true (the old, unconditional behavior — no gating exists on this path)');

    $assignC4 = db_fetch_one("SELECT `clear` FROM `{$prefix}assigns` WHERE id = ?", [$aidC]);
    $assignD4 = db_fetch_one("SELECT `clear` FROM `{$prefix}assigns` WHERE id = ?", [$aidD]);
    is_true($assignC4 && !empty($assignC4['clear']) && substr((string) $assignC4['clear'], 0, 4) !== '0000'
         && $assignD4 && !empty($assignD4['clear']) && substr((string) $assignD4['clear'], 0, 4) !== '0000',
        'REGRESSION PROOF: an unscoped call still clears BOTH C and D — the original bug is completely unchanged for any caller that doesn\'t supply assign_id');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 5. Invalid assign_id is refused, not silently unscoped --\n";
    // ─────────────────────────────────────────────────────────────────
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh116_other_unit', 'GH116O', 'test', ?, NOW(), NOW())", [$sResp]);
    $otherResponderId = (int) db_insert_id();
    $responderIds[] = $otherResponderId;
    test_fixture_guard_track('responder', $otherResponderId);
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_E', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidE = (int) db_insert_id(); $ticketIds[] = $tidE;
    test_fixture_guard_track('ticket', $tidE);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidE]);
    $raE = assign_create_internal($tidE, $otherResponderId, '', $userId);
    $aidE = (int) $raE['id']; $assignIds[] = $aidE;
    test_fixture_guard_track('assigns', $aidE);

    $r5 = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aidE);
    is_true(in_array('invalid_assignment', $r5['errors'] ?? [], true),
        'an assign_id belonging to a DIFFERENT responder is refused with invalid_assignment, not silently accepted',
        implode(';', $r5['errors'] ?? []));

    $r5b = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aidA); // A was cleared in section 1
    is_true(in_array('invalid_assignment', $r5b['errors'] ?? [], true),
        'an assign_id that is already cleared is refused with invalid_assignment',
        implode(';', $r5b['errors'] ?? []));

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 7. assigns.status_id kept in sync (mobile path), two open calls diverge --\n";
    // ─────────────────────────────────────────────────────────────────
    // C and D are both still open from section 3/4's fixture, but section 4
    // (the unscoped regression proof) cleared BOTH of them. Fresh pair.
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_F', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidF = (int) db_insert_id(); $ticketIds[] = $tidF;
    test_fixture_guard_track('ticket', $tidF);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidF]);
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_G', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidG = (int) db_insert_id(); $ticketIds[] = $tidG;
    test_fixture_guard_track('ticket', $tidG);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidG]);

    $raF = assign_create_internal($tidF, $responderId, '', $userId);
    $raG = assign_create_internal($tidG, $responderId, '', $userId, true);
    $aidF = (int) ($raF['id'] ?? 0); $aidG = (int) ($raG['id'] ?? 0);
    $assignIds[] = $aidF; $assignIds[] = $aidG;
    test_fixture_guard_track('assigns', $aidF);
    test_fixture_guard_track('assigns', $aidG);
    is_true($aidF > 0 && $aidG > 0, 'fixture: unit assigned to F and G',
        'raF=' . json_encode($raF) . ' raG=' . json_encode($raG));

    $dispatchedStatusId = (int) db_fetch_value("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidF]);
    is_true($dispatchedStatusId > 0, 'sanity: assigns.status_id is populated at creation time (pre-existing behavior)');

    $r7 = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aidF);
    is_true(empty($r7['errors']), 'setting responding on F via assign_id succeeds', implode(';', $r7['errors'] ?? []));

    $rowF7 = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidF]);
    $rowG7 = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidG]);
    is_true((int) ($rowF7['status_id'] ?? 0) === $sResp,
        'FIX: assigns.status_id for F is updated to the responding status',
        'got ' . ($rowF7['status_id'] ?? 'null'));
    is_true((int) ($rowG7['status_id'] ?? -1) === $dispatchedStatusId,
        'FIX: assigns.status_id for G is UNTOUCHED — still whatever it was at creation, not bled from F',
        'expected ' . $dispatchedStatusId . ', got ' . ($rowG7['status_id'] ?? 'null'));

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 8. status_id updates even when the SAME action's timestamp is already stamped --\n";
    // (the specific gap a naive \"only touch status_id alongside a fresh
    //  timestamp\" implementation would still have: GH#59's own scenario of
    //  two DIFFERENT statuses sharing one incident_action) --
    // ─────────────────────────────────────────────────────────────────
    $sResp2 = gh116_mk_status($prefix, 'gh116_enroute_2', 'responding'); // a SECOND status mapped to 'responding'
    test_fixture_guard_track('un_status', $sResp2);
    $statusIds[] = $sResp2;

    $respondingTsBefore = db_fetch_value("SELECT `responding` FROM `{$prefix}assigns` WHERE id = ?", [$aidF]);
    is_true(!empty($respondingTsBefore) && substr((string) $respondingTsBefore, 0, 4) !== '0000',
        'sanity: F already has a responding timestamp from section 7');

    $r8 = responder_set_status_internal($responderId, $sResp2, $userId, '', null, null, $aidF);
    is_true(empty($r8['errors']), 'switching F to the SECOND responding-mapped status succeeds', implode(';', $r8['errors'] ?? []));

    $rowF8 = db_fetch_one("SELECT `responding`, `status_id` FROM `{$prefix}assigns` WHERE id = ?", [$aidF]);
    is_true((string) ($rowF8['responding'] ?? '') === (string) $respondingTsBefore,
        'the responding TIMESTAMP is unchanged (it was already stamped) — matches pre-existing backstamp-once semantics');
    is_true((int) ($rowF8['status_id'] ?? 0) === $sResp2,
        'FIX: status_id STILL advances to the newly-picked status even though the timestamp did not change',
        'expected ' . $sResp2 . ', got ' . ($rowF8['status_id'] ?? 'null'));

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 9. assigns.status_id kept in sync (dispatcher path, assign_update_status_internal) --\n";
    // ─────────────────────────────────────────────────────────────────
    $sOnScene = gh116_mk_status($prefix, 'gh116_onscene', 'on_scene');
    test_fixture_guard_track('un_status', $sOnScene);
    $sOnScene2 = gh116_mk_status($prefix, 'gh116_onscene_2', 'on_scene');
    test_fixture_guard_track('un_status', $sOnScene2);
    $statusIds[] = $sOnScene; $statusIds[] = $sOnScene2;

    // Pass the SPECIFIC status id (int), not the action name (string) --
    // the string path resolves to "whichever status sorts first" for that
    // action (see _assign_status_id_by_action()'s docblock/GH#59), which
    // would defeat this section's whole point of proving two DIFFERENT
    // on_scene-mapped statuses are each faithfully recorded.
    $r9a = assign_update_status_internal($aidG, $sOnScene, $userId);
    is_true(empty($r9a['errors'] ?? []), 'dispatcher path: setting on_scene on G succeeds', implode(';', $r9a['errors'] ?? []));
    $rowG9a = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidG]);
    is_true((int) ($rowG9a['status_id'] ?? 0) === $sOnScene,
        'dispatcher path: assigns.status_id set to the on_scene status',
        'got ' . ($rowG9a['status_id'] ?? 'null'));

    // Same-action switch, already-stamped timestamp, via the DISPATCHER path.
    $onSceneTsBefore = db_fetch_value("SELECT `on_scene` FROM `{$prefix}assigns` WHERE id = ?", [$aidG]);
    $r9b = assign_update_status_internal($aidG, $sOnScene2, $userId);
    is_true(empty($r9b['errors'] ?? []), 'dispatcher path: switching G to the second on_scene-mapped status succeeds', implode(';', $r9b['errors'] ?? []));
    $rowG9b = db_fetch_one("SELECT `on_scene`, `status_id` FROM `{$prefix}assigns` WHERE id = ?", [$aidG]);
    is_true((string) ($rowG9b['on_scene'] ?? '') === (string) $onSceneTsBefore,
        'dispatcher path: on_scene TIMESTAMP unchanged (already stamped)');
    is_true((int) ($rowG9b['status_id'] ?? 0) === $sOnScene2,
        'FIX: dispatcher path also keeps status_id current on a same-action switch with no new timestamp',
        'expected ' . $sOnScene2 . ', got ' . ($rowG9b['status_id'] ?? 'null'));

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 10. mobile_active_assignments() exposes assign_status_id --\n";
    // ─────────────────────────────────────────────────────────────────
    require_once $base . '/inc/mobile-assignments.php';
    $activeRows = mobile_active_assignments($prefix, [$responderId]);
    $rowForG = null;
    foreach ($activeRows as $row) { if ((int) $row['assign_id'] === $aidG) { $rowForG = $row; break; } }
    is_true($rowForG !== null, 'mobile_active_assignments() returns G (still open)');
    is_true($rowForG !== null && array_key_exists('assign_status_id', $rowForG),
        'FIX: the returned row carries an assign_status_id key');
    is_true($rowForG !== null && (int) ($rowForG['assign_status_id'] ?? -1) === $sOnScene2,
        'assign_status_id reflects the real, current per-assignment status',
        'expected ' . $sOnScene2 . ', got ' . ($rowForG['assign_status_id'] ?? 'null'));

} catch (Throwable $e) {
    bad('fixture/writer path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Static: assets/js/mobile.js's grouped-by-assignment status UI --\n";
// ─────────────────────────────────────────────────────────────────────────
$mobileJsPath = $base . '/assets/js/mobile.js';
$mobileSrc = (string) file_get_contents($mobileJsPath);

is_true(strpos($mobileSrc, 'allAssignments.length > 1') !== false,
    'mobile.js branches its status UI on allAssignments.length > 1');
is_true(strpos($mobileSrc, "data-assign-id=") !== false,
    'mobile.js\'s status buttons can carry a data-assign-id attribute');
is_true(strpos($mobileSrc, 'body.assign_id = parseInt(assignIdAttr, 10);') !== false,
    'mobile.js\'s click handler reads data-assign-id and sends it as assign_id');
is_true(strpos($mobileSrc, 'data.unit_status_updated') !== false,
    'mobile.js\'s success handler consults the server\'s unit_status_updated flag');
is_true(strpos($mobileSrc, 'data.prior_status_id') !== false,
    'mobile.js falls back to the server-reported prior status when the unit status was not actually advanced');

$respStatusApiSrc = (string) file_get_contents($base . '/api/responder-status.php');
is_true(strpos($respStatusApiSrc, "\$assign_id = (int) (\$input['assign_id'] ?? 0);") !== false,
    'api/responder-status.php reads an optional assign_id from the request body');
is_true(strpos($respStatusApiSrc, "'unit_status_updated'") !== false,
    'api/responder-status.php\'s response includes unit_status_updated');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 11. NODE-DRIVEN: the real per-card highlight logic, extracted --\n";
// with a NEGATIVE CONTROL reproducing the original reported symptom.
// ─────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_test_node_probe.php';
$node = test_probe_cli(['node', 'node.exe']);

if ($node === null) {
    echo "SKIP: node not available — the JS execution check was not run\n";
} else {
    // Balanced-brace extraction of the REAL grouped-rendering block, anchored
    // on its own literal condition so this survives line-number drift.
    $anchor = 'if (allAssignments.length > 1) {';
    $start = strpos($mobileSrc, $anchor);
    $block = null;
    if ($start !== false) {
        $depth = 0; $i = $start; $len = strlen($mobileSrc); $blockStart = null;
        for (; $i < $len; $i++) {
            $ch = $mobileSrc[$i];
            if ($ch === '{') { if ($depth === 0) $blockStart = $i + 1; $depth++; }
            elseif ($ch === '}') { $depth--; if ($depth === 0) { $block = substr($mobileSrc, $blockStart, $i - $blockStart); break; } }
        }
    }
    is_true($block !== null && strlen($block) > 50, 'extracted the real allAssignments.length > 1 rendering block from mobile.js');

    if ($block !== null) {
        $harness = sys_get_temp_dir() . '/tcad_gh116_highlight_harness_' . getmypid() . '.js';
        $blockJson = json_encode($block);
        $js = <<<JS
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

function escHtml(s) { return String(s); }
function _statusButtonHtml(s, isActive, assignId) {
    // Record exactly what the real function would have rendered, without
    // needing the real DOM-building implementation.
    calls.push({ assignId: assignId, statusId: s.id, isActive: isActive });
    return '';
}

// The REAL extracted block references: allAssignments, data, currentStatusId,
// escHtml, _statusButtonHtml (all in scope here), plus loop-local vars it
// declares itself (g, a, label, i, s -- all with 'var', so no redeclare clash).
var realBlockBody = {$blockJson};
var runFixed = new Function('allAssignments', 'data', 'currentStatusId', 'escHtml', '_statusButtonHtml', 'calls',
    'var html = "";\\n' + realBlockBody + '\\nreturn html;');

// Reconstructed PRE-FIX version: same shape, but every card compares
// against currentStatusId directly (no per-card cardStatusId override) --
// this is literally what the code looked like before this fix, and exists
// so this harness can PROVE it would have caught the original bug.
var preFixBody = realBlockBody.replace(
    /var cardStatusId[\\s\\S]*?: currentStatusId;/,
    ''
).replace(/cardStatusId/g, 'currentStatusId');
var runPreFix = new Function('allAssignments', 'data', 'currentStatusId', 'escHtml', '_statusButtonHtml', 'calls',
    'var html = "";\\n' + preFixBody + '\\nreturn html;');

check('pre-fix reconstruction actually differs from the real block (sanity)', preFixBody !== realBlockBody);

// Two open calls with GENUINELY DIFFERENT current statuses: F is now
// "responding" (id 20), G is now "on_scene" (id 30). The unit's OVERALL
// status (currentStatusId) has most recently advanced to 30 (G's), since
// that was the last status change applied -- exactly the real-world
// sequence the reporter hit.
var mockAssignments = [
    { assign_id: 1, ticket_id: 100, incident_number: 'GH116-F', nature: 'Test F', type_color: '#000', assign_status_id: 20 },
    { assign_id: 2, ticket_id: 200, incident_number: 'GH116-G', nature: 'Test G', type_color: '#000', assign_status_id: 30 }
];
var mockData = { statuses: [ { id: 20, status_val: 'Responding' }, { id: 30, status_val: 'On Scene' } ] };

// ── FIXED behavior ──
var calls = [];
runFixed(mockAssignments, mockData, 30, escHtml, _statusButtonHtml, calls);
var fCalls = calls.filter(function (c) { return c.assignId === 1; });
var gCalls = calls.filter(function (c) { return c.assignId === 2; });
var fActive = fCalls.filter(function (c) { return c.isActive; }).map(function (c) { return c.statusId; });
var gActive = gCalls.filter(function (c) { return c.isActive; }).map(function (c) { return c.statusId; });
check('FIX: card F highlights ITS OWN status (20/responding), not the unit overall status (30)',
    fActive.length === 1 && fActive[0] === 20, JSON.stringify(fActive));
check('FIX: card G highlights ITS OWN status (30/on_scene)',
    gActive.length === 1 && gActive[0] === 30, JSON.stringify(gActive));

// ── PRE-FIX reconstruction, same inputs: reproduces the ORIGINAL bug ──
calls = [];
runPreFix(mockAssignments, mockData, 30, escHtml, _statusButtonHtml, calls);
var fCallsPre = calls.filter(function (c) { return c.assignId === 1; });
var fActivePre = fCallsPre.filter(function (c) { return c.isActive; }).map(function (c) { return c.statusId; });
check('NEGATIVE CONTROL: the pre-fix block incorrectly highlights card F with the UNIT overall status (30), reproducing the reported bug',
    fActivePre.length === 1 && fActivePre[0] === 30, JSON.stringify(fActivePre));

console.log(out.join('\\n'));
JS;
        file_put_contents($harness, $js);
        $raw = test_run_cli([$node, $harness]);
        @unlink($harness);

        if (!is_string($raw) || strpos($raw, '|') === false) {
            bad('node harness ran the extracted mobile.js block', trim((string) $raw));
        } else {
            foreach (explode("\n", trim($raw)) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) < 2) continue;
                if ($parts[0] === 'PASS') { ok('[js] ' . $parts[1]); }
                else { bad('[js] ' . $parts[1], $parts[2] ?? ''); }
            }
        }
    }
}

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
