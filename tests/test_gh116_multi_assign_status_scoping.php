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
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';

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
    $sResp  = gh116_mk_status($prefix, 'gh116_enroute', 'responding');
    $statusIds = [$sClear, $sResp];

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh116_unit', 'GH116U', 'test', ?, NOW(), NOW())", [$sResp]);
    $responderId = (int) db_insert_id();
    $responderIds[] = $responderId;

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    foreach (['gh116_call_A', 'gh116_call_B'] as $scope) {
        db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
                  VALUES (?, 2, 0, ?, 'GH116 fixture', NOW(), NOW(), 1)", [$typeId, $scope]);
        $ticketIds[] = (int) db_insert_id();
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
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_D', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidD = (int) db_insert_id(); $ticketIds[] = $tidD;

    $raC = assign_create_internal($tidC, $responderId, '', $userId);
    // $force=true — same reason as raB above (C is now the responder's
    // only open assignment at this point, so D is the second concurrent one).
    $raD = assign_create_internal($tidD, $responderId, '', $userId, true);
    $aidC = (int) ($raC['id'] ?? 0); $aidD = (int) ($raD['id'] ?? 0);
    $assignIds[] = $aidC; $assignIds[] = $aidD;
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
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh116_call_E', 'GH116 fixture', NOW(), NOW(), 1)", [$typeId]);
    $tidE = (int) db_insert_id(); $ticketIds[] = $tidE;
    $raE = assign_create_internal($tidE, $otherResponderId, '', $userId);
    $aidE = (int) $raE['id']; $assignIds[] = $aidE;

    $r5 = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aidE);
    is_true(in_array('invalid_assignment', $r5['errors'] ?? [], true),
        'an assign_id belonging to a DIFFERENT responder is refused with invalid_assignment, not silently accepted',
        implode(';', $r5['errors'] ?? []));

    $r5b = responder_set_status_internal($responderId, $sResp, $userId, '', null, null, $aidA); // A was cleared in section 1
    is_true(in_array('invalid_assignment', $r5b['errors'] ?? [], true),
        'an assign_id that is already cleared is refused with invalid_assignment',
        implode(';', $r5b['errors'] ?? []));

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
