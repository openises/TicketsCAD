<?php
/**
 * GH #77 — mobile crew-only responder resolution regression test.
 *
 * Root cause (confirmed live on the GH #77 thread by rjonesbsink +
 * ejosterberg, and independently re-confirmed here by reading the current
 * api/mobile-data.php before this fix): the GET dashboard handler resolves
 * "which unit is this user" via a 3-path lookup (responder.user_id,
 * responder.personal_for_member_id, name/handle match) PLUS a Phase 116c /
 * GH #85 crew fallback through unit_personnel_assignments when the user has
 * no personal responder row of their own. The add_note (~line 390 now,
 * previously ~329) and report_location (~line 502 now, previously ~456)
 * POST actions each ran their OWN narrower resolution — add_note tried only
 * user_id/personal_for_member_id (not even the name/handle path),
 * report_location tried all 3 narrow paths — and NEITHER ever consulted
 * crew assignments. A crew-only member (assigned to a unit via "Edit unit
 * -> assign personnel", the supported path, with no personal responder row
 * of their own) therefore passed the GET header/assignment lookup but was
 * rejected outright by report_location ("No responder linked to this
 * account -- clock in first") and had their notes written with
 * responder=NULL by add_note, in the same request/session where the header
 * correctly showed their unit.
 *
 * Fix (api/mobile-data.php): ONE shared resolver, mobile_resolve_responder_id()
 * (backed by mobile_crew_unit_ids(), the same query the GET handler's own
 * crew-list build now also calls), used by add_note, report_location, and
 * the GET handler — so the write paths can't independently drift from the
 * read path again the way they had.
 *
 * This test:
 *   1. Reproduces the exact GH #77 precondition through real INSERTs (a
 *      brand-new throwaway login user + member + unit — never touches any
 *      real account, matching this project's standing rule against
 *      resetting/mutating live user data; precedented by
 *      tests/test_push_recipient_resolution.php's disposable test users)
 *      — crew-only, no personal responder row — and proves the OLD narrow
 *      lookup finds nothing while the shared resolver finds the crewed
 *      unit.
 *   2. Proves the add_note INSERT shape, given the resolved id, attributes
 *      the note to the crewed unit instead of NULL — rjonesbsink's live
 *      test on the issue thread, reproduced here as a repeatable assertion.
 *   3. Statically verifies (source inspection) that BOTH POST action blocks
 *      in api/mobile-data.php actually call the shared resolver — not a
 *      re-inlined narrow copy — so a future edit can't silently reintroduce
 *      the split that caused GH #77 in the first place.
 *
 * Usage: php tests/test_gh77_mobile_crew_responder.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

// ── Mirror of api/mobile-data.php's shared resolver, used only for the
// functional half of this test (the static wiring checks at the bottom of
// this file independently verify the PRODUCTION call sites actually use
// these functions rather than a re-inlined narrow copy — that's what
// catches this mirror silently drifting from the real one). ──
function gh77_safe_fetch($sql, $params = []) {
    try { return db_fetch_all($sql, $params); } catch (Exception $e) { return []; }
}
function gh77_crew_unit_ids($prefix, $userId) {
    $ids = [];
    try {
        $rows = gh77_safe_fetch(
            "SELECT DISTINCT upa.`responder_id`
               FROM `{$prefix}unit_personnel_assignments` upa
               JOIN `{$prefix}member` m ON m.`id` = upa.`member_id`
              WHERE m.`user_id` = ?
                AND upa.`status` IN ('active','standby')
                AND (upa.`released_at` IS NULL OR DATE_FORMAT(upa.`released_at`,'%y') = '00')",
            [$userId]
        );
        foreach ($rows as $r) { $rid = (int) $r['responder_id']; if ($rid > 0) $ids[] = $rid; }
    } catch (Exception $e) { /* older install without unit_personnel_assignments */ }
    return $ids;
}
function gh77_resolve_responder_id($prefix, $userId, $memberId, $username) {
    $row = gh77_safe_fetch(
        "SELECT `id` FROM `{$prefix}responder` WHERE `user_id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
        [$userId]
    );
    if (empty($row) && $memberId) {
        $row = gh77_safe_fetch(
            "SELECT `id` FROM `{$prefix}responder` WHERE `personal_for_member_id` = ? AND (`deleted_at` IS NULL) LIMIT 1",
            [$memberId]
        );
    }
    if (empty($row)) {
        $row = gh77_safe_fetch(
            "SELECT `id` FROM `{$prefix}responder` WHERE (`name` = ? OR `handle` = ?) AND (`deleted_at` IS NULL) LIMIT 1",
            [$username, $username]
        );
    }
    if (!empty($row)) return (int) $row[0]['id'];
    $crewIds = gh77_crew_unit_ids($prefix, $userId);
    return !empty($crewIds) ? $crewIds[0] : 0;
}

// The OLD (buggy) narrow lookup add_note/report_location ran before this
// fix — reproduced ONLY to prove the bug precondition still reproduces
// (that it truly finds nothing for a crew-only user), never used to decide
// pass/fail on its own.
function gh77_old_narrow_lookup($prefix, $userId, $memberId, $username) {
    $row = gh77_safe_fetch(
        "SELECT `id` FROM `{$prefix}responder`
          WHERE (`user_id` = ? OR `personal_for_member_id` = ? OR `name` = ? OR `handle` = ?)
            AND (`deleted_at` IS NULL) LIMIT 1",
        [$userId, (int) $memberId, $username, $username]
    );
    return !empty($row) ? (int) $row[0]['id'] : 0;
}

$cleanup = [];   // table => [ids...]
function track(&$cleanup, $table, $id) { $cleanup[$table][] = (int) $id; }

$marker = 'gh77_' . substr(md5(uniqid('', true)), 0, 8);

try {
    // ── Brand-new throwaway login user + member (never touches a real
    // account — precedented by tests/test_push_recipient_resolution.php's
    // disposable push_test_unit_*/push_test_person_* users). ──
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`, `can_login`)
              VALUES (?, 'x', 'GH77', 'CrewOnly', 1)", [$marker . '_user']);
    $userId = (int) db_insert_id();
    track($cleanup, 'user', $userId);

    db_query("INSERT INTO `{$prefix}member` (`user_id`) VALUES (?)", [$userId]);
    $memberId = (int) db_insert_id();
    track($cleanup, 'member', $memberId);

    // The unit to be crewed — deliberately has NO responder.user_id /
    // personal_for_member_id / name / handle link to our test user, so the
    // narrow 3-path lookup can never resolve it directly.
    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`, `un_status_id`, `status_updated`, `updated`)
              VALUES (?, ?, 'GH77 regression fixture', 1, NOW(), NOW())",
              [$marker . '_unit', substr($marker, -8)]);
    $unitId = (int) db_insert_id();
    track($cleanup, 'responder', $unitId);

    // ── Precondition: before crewing, nothing resolves for this user at
    // all — proves the fixture starts from "no personal responder". ──
    test('precondition: narrow lookup finds nothing before crewing',
        gh77_old_narrow_lookup($prefix, $userId, $memberId, $marker . '_user') === 0);
    test('precondition: shared resolver also finds nothing before crewing (no crew yet either)',
        gh77_resolve_responder_id($prefix, $userId, $memberId, $marker . '_user') === 0);

    // Crew the unit — the same row shape "Edit unit -> assign personnel"
    // writes (api/unit-assignments.php), status='active'.
    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `role`, `status`, `assigned_by`)
              VALUES (?, ?, 'driver', 'active', ?)", [$unitId, $memberId, $userId]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    // ── This IS GH #77: even while actively crewing the unit, the OLD
    // narrow lookup still finds nothing (it never consults
    // unit_personnel_assignments at all). ──
    test('bug precondition: narrow lookup still finds nothing while crewing (this is GH #77)',
        gh77_old_narrow_lookup($prefix, $userId, $memberId, $marker . '_user') === 0);

    // ── The fix: the shared resolver falls back to the crewed unit. ──
    $resolved = gh77_resolve_responder_id($prefix, $userId, $memberId, $marker . '_user');
    test('fix: shared resolver finds the crewed unit for report_location/add_note',
        $resolved === $unitId);

    // Same crew-id list the GET handler's header/assignment lookup uses.
    test('crew-unit-ids list also includes the unit',
        in_array($unitId, gh77_crew_unit_ids($prefix, $userId), true));

    // ── Second half of GH #77: note attribution (rjonesbsink's live
    // INSERT test on the issue thread). Reproduce the same `action` table
    // INSERT api/mobile-data.php's add_note runs, using the shared
    // resolver's result, and confirm the note lands attributed to the
    // crewed unit instead of NULL. ──
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, ?, 'GH77 mobile crew note regression', NOW(), NOW(), 1)",
              [$typeId, $marker . '_ticket']);
    $ticketId = (int) db_insert_id();
    track($cleanup, 'ticket', $ticketId);

    $noteResponderId = $resolved ?: null;
    db_query("INSERT INTO `{$prefix}action`
              (`ticket_id`, `date`, `description`, `user`, `action_type`, `responder`, `updated`)
              VALUES (?, NOW(), 'GH77 crew note regression', ?, 11, ?, NOW())",
              [$ticketId, $userId, $noteResponderId]);
    $actionId = (int) db_insert_id();
    track($cleanup, 'action', $actionId);

    $writtenResponder = db_fetch_value(
        "SELECT `responder` FROM `{$prefix}action` WHERE `id` = ?", [$actionId]);
    test('note is attributed to the crewed unit, not NULL (GH #77 note half)',
        (int) $writtenResponder === $unitId);

    // Releasing the crew assignment must stop the resolver from finding it.
    db_query("UPDATE `{$prefix}unit_personnel_assignments`
              SET status = 'released', released_at = NOW()
              WHERE responder_id = ? AND member_id = ?", [$unitId, $memberId]);
    test('released crew member no longer resolves the unit',
        gh77_resolve_responder_id($prefix, $userId, $memberId, $marker . '_user') === 0);

} catch (Throwable $e) {
    echo "[FAIL] fixture/setup threw: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    // Reverse-dependency-order cleanup. Never touches any pre-existing row.
    foreach (['action', 'ticket', 'unit_personnel_assignments', 'responder', 'member', 'user'] as $t) {
        foreach (($cleanup[$t] ?? []) as $id) {
            try { db_query("DELETE FROM `{$prefix}{$t}` WHERE `id` = ?", [$id]); }
            catch (Throwable $e) { /* best-effort */ }
        }
    }
}

// ── Static wiring check: the PRODUCTION file must actually call the
// shared resolver from both write actions, not a re-inlined narrow copy —
// the exact shape of the original bug. This is what stops a future edit
// from silently re-splitting the logic even if the functional assertions
// above (which exercise a mirrored copy) keep passing. ──
$src = @file_get_contents(__DIR__ . '/../api/mobile-data.php') ?: '';
test('production: mobile_resolve_responder_id() is defined',
    (bool) preg_match('/function\s+mobile_resolve_responder_id\s*\(/', $src));
// GH#113 (2026-08-25): mobile_crew_unit_ids() moved to
// inc/mobile-assignments.php so inc/par.php's PAR ack gate can share it
// too, instead of re-deriving the query a third time. api/mobile-data.php
// still CALLS it (checked below via $src) — only the definition moved.
$mobileAssignmentsSrc = @file_get_contents(__DIR__ . '/../inc/mobile-assignments.php') ?: '';
test('production: mobile_crew_unit_ids() is defined in inc/mobile-assignments.php',
    (bool) preg_match('/function\s+mobile_crew_unit_ids\s*\(/', $mobileAssignmentsSrc));
test('production: mobile_crew_unit_ids() is no longer (re)defined in api/mobile-data.php',
    !preg_match('/function\s+mobile_crew_unit_ids\s*\(/', $src));
test('production: the shared resolver itself falls back to crew units (Path 4 present)',
    strpos($src, '$crewIds = mobile_crew_unit_ids($prefix, $userId);') !== false);
test('production: add_note calls the shared resolver',
    (bool) preg_match('/\$noteResponderId\s*=\s*mobile_resolve_responder_id\(/', $src));
test('production: report_location calls the shared resolver',
    (bool) preg_match('/\$responderId\s*=\s*mobile_resolve_responder_id\(/', $src));
// Guard against the GET handler's own crew-list build silently forking
// away from the shared helper again (it stays inline for the full
// responder row + multi-unit list, but must still call mobile_crew_unit_ids()).
test('production: GET handler builds $crewUnitIds via the shared helper',
    strpos($src, '$crewUnitIds = mobile_crew_unit_ids($prefix, $current_user_id);') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
