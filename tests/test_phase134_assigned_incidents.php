<?php
/**
 * Phase 134 (Model 3, GH #23), Step 3 — member -> responder -> open-
 * assignment -> ticket resolution, and the attach-to-assigned-incidents
 * writer (inc/message-incident.php's mi_assigned_incident_ticket_ids() and
 * mi_attach_message_to_assigned_incidents()).
 *
 * ── WHY THE FIXTURES ARE BUILT THE WAY THEY ARE ─────────────────────────
 *
 * This mirrors tests/test_push_recipient_resolution.php's structure — the
 * SAME two responder<->member link shapes GH #8 proved out (UNIT-style
 * direct link / PERSONNEL-style via unit_personnel_assignments), reused
 * here in the reverse (member -> responder) direction. Unlike that test,
 * this resolver JOINs a real `ticket` row (router_recipients_resolve()
 * never touches the ticket table at all), so a synthetic out-of-range
 * ticket id is not an option here — tickets are created through the real
 * incident_create_internal() writer and hard-deleted in cleanup.
 *
 * `assigns` rows are ALWAYS created/cleared through the real
 * assign_create_internal() / assign_update_status_internal() writers, per
 * this project's repeated "test asserts against state the real writer
 * never produces" failure class (see CLAUDE.md's extensive history on
 * this, most recently the `un_status.extra_data_target` / GH #20 round 4
 * episode). `unit_personnel_assignments` rows are hand-inserted directly —
 * precedented by test_push_recipient_resolution.php itself, which does the
 * exact same thing for the identical join table (there is no dedicated
 * writer function for it, confirmed by grep).
 *
 * `responder.personal_for_member_id` is ALSO hand-set directly rather than
 * driven through inc/personnel-units.php's pu_clock_in(): that function
 * pulls in un_status lookups, contact-field mirroring FROM `member`
 * (requiring first_name/last_name/callsign/etc.), audit_log() calls, and
 * pu_autobind_locations() side effects that have nothing to do with what
 * this file verifies (the bare responder<->member link). Unlike `assigns`,
 * this project's test suite has no established "always use the writer"
 * precedent for this one column — hand-setting it keeps the fixture
 * minimal, legible, and free of unrelated side effects to reason about.
 *
 * Usage:
 *   php tests/test_phase134_assigned_incidents.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/message-incident.php';
require_once 'inc/comm_resolve.php';
require_once 'inc/assignment-write.php';
require_once 'inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

if (!function_exists('mi_assigned_incident_ticket_ids')
    || !function_exists('mi_attach_message_to_assigned_incidents')) {
    echo "SKIP: Phase 134 resolver functions not present\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$adminId = test_admin_user_id();
// Short — responder.handle is only varchar(24), and several handles below
// prepend a few characters of their own to this marker.
$marker  = substr(md5(uniqid('', true)), 0, 8);

$cleanup = [];   // table => [ids...]
function track(&$cleanup, $table, $id) { $cleanup[$table][] = (int) $id; }

/** First real in_types id on this install (never assume id=1 exists). */
function _p134_first_in_types_id(): int {
    static $id = null;
    if ($id !== null) return $id;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $id = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    return $id;
}

/** Create a minimal incident through the REAL writer. Throws on failure. */
function _p134_make_ticket(int $userId, string $label): int {
    $r = incident_create_internal(
        ['in_types_id' => _p134_first_in_types_id(), 'scope' => 'Phase 134 test — ' . $label],
        $userId
    );
    if (empty($r['id'])) {
        throw new RuntimeException('incident_create_internal failed: ' . implode('; ', $r['errors'] ?? ['unknown']));
    }
    return (int) $r['id'];
}

/** Count `action` rows on a ticket carrying our source_channel marker. */
function _p134_note_count(int $ticketId, string $channel): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ? AND source_channel = ?",
        [$ticketId, $channel]
    );
}

/** Count `action` rows carrying a specific source_message_id (any ticket). */
function _p134_note_count_by_msgid(int $msgId): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}action` WHERE source_message_id = ?",
        [$msgId]
    );
}

/**
 * Bare `member` row for a test fixture. `member.first_name`/`last_name`
 * are GENERATED columns (derived from field1/field2 — the same trap
 * test_push_recipient_resolution.php's own comment documents), so an
 * INSERT naming them directly 1906-errors. This resolver doesn't read a
 * member's name at all, so an empty row is sufficient.
 */
function _p134_make_member(): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query("INSERT INTO `{$prefix}member` () VALUES ()");
    return (int) db_insert_id();
}

try {
    $telegramModeId = (int) db_fetch_value("SELECT id FROM `{$prefix}comm_modes` WHERE code = 'telegram'");
    if ($telegramModeId <= 0) {
        throw new RuntimeException('telegram comm_modes row missing — Phase 134 Step 1 seed not present');
    }

    // ── Defensive contract: bad member ids never throw, always [] ──────
    test('mi_assigned_incident_ticket_ids(0) returns []',
        mi_assigned_incident_ticket_ids(0) === []);
    test('mi_assigned_incident_ticket_ids(-5) returns []',
        mi_assigned_incident_ticket_ids(-5) === []);

    // ── Fixture A: PERSONNEL-linkage member with TWO open assignments ──
    // Covers both "PERSONNEL path resolves an open assign" and "a member
    // assigned to two different tickets resolves BOTH ids" in one fixture.
    $memA = _p134_make_member();
    track($cleanup, 'member', $memA);

    // GH#82/GH#83 (2026-08-18) — this fixture deliberately assigns unit A
    // to TWO open tickets; Multi-Assign declares that as intentional so
    // assign_create_internal()'s dispatch gate doesn't ask for confirmation
    // on the second one (see the identical note on fixture F below).
    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`, `multi`) VALUES (?, ?, '', 1)",
        ['Phase134 Unit A', 'p134unitA-' . $marker]);
    $respA = (int) db_insert_id();
    track($cleanup, 'responder', $respA);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
        [$respA, $memA]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    $ticketA1 = _p134_make_ticket($adminId, 'A1 personnel path');
    track($cleanup, 'ticket', $ticketA1);
    $resA1 = assign_create_internal($ticketA1, $respA, 'unit', $adminId);
    test('fixture: assign_create_internal succeeded for ticketA1', empty($resA1['errors']));
    if (!empty($resA1['id'])) track($cleanup, 'assigns', $resA1['id']);

    $ticketA2 = _p134_make_ticket($adminId, 'A2 second incident, same unit');
    track($cleanup, 'ticket', $ticketA2);
    $resA2 = assign_create_internal($ticketA2, $respA, 'unit', $adminId);
    test('fixture: assign_create_internal succeeded for ticketA2', empty($resA2['errors']));
    if (!empty($resA2['id'])) track($cleanup, 'assigns', $resA2['id']);

    $idsA = mi_assigned_incident_ticket_ids($memA);
    test('PERSONNEL-linkage path resolves an open assign',
        in_array($ticketA1, $idsA, true));
    test('member assigned (open) to TWO different tickets resolves BOTH ids',
        in_array($ticketA1, $idsA, true) && in_array($ticketA2, $idsA, true) && count($idsA) === 2);

    // ── Fixture B: personal-unit (personal_for_member_id) linkage ──────
    $memB = _p134_make_member();
    track($cleanup, 'member', $memB);

    db_query("INSERT INTO `{$prefix}responder`
              (`name`, `handle`, `description`, `personal_for_member_id`) VALUES (?, ?, '', ?)",
        ['Phase134 Personal B', 'p134personalB-' . $marker, $memB]);
    $respB = (int) db_insert_id();
    track($cleanup, 'responder', $respB);

    $ticketB = _p134_make_ticket($adminId, 'B personal-unit path');
    track($cleanup, 'ticket', $ticketB);
    $resB = assign_create_internal($ticketB, $respB, '', $adminId);
    test('fixture: assign_create_internal succeeded for ticketB', empty($resB['errors']));
    if (!empty($resB['id'])) track($cleanup, 'assigns', $resB['id']);

    $idsB = mi_assigned_incident_ticket_ids($memB);
    test('personal-unit (personal_for_member_id) linkage path resolves an open assign',
        in_array($ticketB, $idsB, true));

    // ── Fixture C: a CLEARED assignment must NOT resolve ────────────────
    $memC = _p134_make_member();
    track($cleanup, 'member', $memC);

    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`) VALUES (?, ?, '')",
        ['Phase134 Unit C', 'p134unitC-' . $marker]);
    $respC = (int) db_insert_id();
    track($cleanup, 'responder', $respC);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
        [$respC, $memC]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    $ticketC = _p134_make_ticket($adminId, 'C cleared assignment');
    track($cleanup, 'ticket', $ticketC);
    $resC = assign_create_internal($ticketC, $respC, '', $adminId);
    test('fixture: assign_create_internal succeeded for ticketC', empty($resC['errors']));
    $assignC = $resC['id'] ?? 0;
    if ($assignC) track($cleanup, 'assigns', $assignC);

    $clearRes = assign_update_status_internal((int) $assignC, 'clear', $adminId);
    test('fixture: assign_update_status_internal cleared the assignment', empty($clearRes['errors']));

    $idsC = mi_assigned_incident_ticket_ids($memC);
    test('a CLEARED assignment does NOT resolve',
        !in_array($ticketC, $idsC, true));

    // ── Fixture D: a soft-deleted ticket's open assign must NOT resolve ─
    $memD = _p134_make_member();
    track($cleanup, 'member', $memD);

    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`) VALUES (?, ?, '')",
        ['Phase134 Unit D', 'p134unitD-' . $marker]);
    $respD = (int) db_insert_id();
    track($cleanup, 'responder', $respD);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
        [$respD, $memD]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    $ticketD = _p134_make_ticket($adminId, 'D soft-deleted ticket');
    track($cleanup, 'ticket', $ticketD);
    $resD = assign_create_internal($ticketD, $respD, '', $adminId);
    test('fixture: assign_create_internal succeeded for ticketD', empty($resD['errors']));
    if (!empty($resD['id'])) track($cleanup, 'assigns', $resD['id']);

    $delRes = incident_soft_delete_internal($ticketD, $adminId);
    test('fixture: incident_soft_delete_internal soft-deleted ticketD', !empty($delRes['deleted']));

    $idsD = mi_assigned_incident_ticket_ids($memD);
    test("a soft-deleted ticket's open assign does NOT resolve",
        !in_array($ticketD, $idsD, true));

    // ── Fixture E: mi_attach_message_to_assigned_incidents — one open
    //    assignment, resolved sender ──────────────────────────────────
    $memE = _p134_make_member();
    track($cleanup, 'member', $memE);

    $handleE = 'p134_telegram_e_' . $marker;
    // `sort_order` is deliberately NOT in this INSERT's column list — it's a
    // self-healed-at-runtime column (see api/comm-identifiers.php's
    // _ensure_sort_order_column(), and inc/comm_resolve.php's
    // _comm_resolve_has_sort_order() docblock), not part of the base
    // schema. It exists on installs that have hit that admin endpoint at
    // least once but NOT on a genuinely fresh install (caught by CI on the
    // first push of this test — naming it here 1054-errored against a
    // clean DB). This resolver's tests never read sort_order, so the fix
    // is simply not to write a column they don't need.
    db_query("INSERT INTO `{$prefix}member_comm_identifiers`
              (`member_id`, `comm_mode_id`, `values_json`, `is_primary`)
              VALUES (?, ?, ?, 1)",
        [$memE, $telegramModeId, json_encode(['username' => $handleE])]);
    track($cleanup, 'member_comm_identifiers', db_insert_id());

    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`) VALUES (?, ?, '')",
        ['Phase134 Unit E', 'p134unitE-' . $marker]);
    $respE = (int) db_insert_id();
    track($cleanup, 'responder', $respE);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
        [$respE, $memE]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    $ticketE = _p134_make_ticket($adminId, 'E attach single incident');
    track($cleanup, 'ticket', $ticketE);
    $resE = assign_create_internal($ticketE, $respE, '', $adminId);
    test('fixture: assign_create_internal succeeded for ticketE', empty($resE['errors']));
    if (!empty($resE['id'])) track($cleanup, 'assigns', $resE['id']);

    $msgIdE = 800001;
    mi_attach_message_to_assigned_incidents(
        ['from' => $handleE, 'body' => 'Phase 134 test body E', 'message_id' => $msgIdE],
        'telegram'
    );
    test('attach: resolved sender with ONE open assignment writes exactly one note',
        _p134_note_count($ticketE, 'telegram') === 1);
    $noteRowE = db_fetch_one(
        "SELECT `author_member_id`, `source_message_id`, `description`
           FROM `{$prefix}action` WHERE ticket_id = ? AND source_channel = 'telegram' LIMIT 1",
        [$ticketE]
    );
    test('attach: the written note is attributed to the resolved member',
        $noteRowE && (int) $noteRowE['author_member_id'] === $memE);
    test('attach: the written note carries the source message id',
        $noteRowE && (int) $noteRowE['source_message_id'] === $msgIdE);
    test('attach: the written note contains the message body',
        $noteRowE && strpos($noteRowE['description'], 'Phase 134 test body E') !== false);

    // ── Fixture F: mi_attach_message_to_assigned_incidents — TWO open
    //    assignments, resolved sender ──────────────────────────────────
    $memF = _p134_make_member();
    track($cleanup, 'member', $memF);

    $handleF = 'p134_telegram_f_' . $marker;
    db_query("INSERT INTO `{$prefix}member_comm_identifiers`
              (`member_id`, `comm_mode_id`, `values_json`, `is_primary`)
              VALUES (?, ?, ?, 1)",
        [$memF, $telegramModeId, json_encode(['username' => $handleF])]);
    track($cleanup, 'member_comm_identifiers', db_insert_id());

    // GH#82/GH#83 (2026-08-18) — this fixture's whole point is a unit
    // legitimately holding TWO simultaneous open assignments, which is
    // exactly what the Multi-Assign flag exists to declare. Without it,
    // assign_create_internal()'s new dispatch gate treats the second
    // assign as an unconfirmed double-booking (needs_confirmation, no row
    // created) — correct behavior for an UNDECLARED double-assign, but
    // not what this fixture is testing.
    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`, `multi`) VALUES (?, ?, '', 1)",
        ['Phase134 Unit F', 'p134unitF-' . $marker]);
    $respF = (int) db_insert_id();
    track($cleanup, 'responder', $respF);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
        [$respF, $memF]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    $ticketF1 = _p134_make_ticket($adminId, 'F1 attach two incidents');
    track($cleanup, 'ticket', $ticketF1);
    $resF1 = assign_create_internal($ticketF1, $respF, '', $adminId);
    test('fixture: assign_create_internal succeeded for ticketF1', empty($resF1['errors']));
    if (!empty($resF1['id'])) track($cleanup, 'assigns', $resF1['id']);

    $ticketF2 = _p134_make_ticket($adminId, 'F2 attach two incidents');
    track($cleanup, 'ticket', $ticketF2);
    $resF2 = assign_create_internal($ticketF2, $respF, '', $adminId);
    test('fixture: assign_create_internal succeeded for ticketF2', empty($resF2['errors']));
    if (!empty($resF2['id'])) track($cleanup, 'assigns', $resF2['id']);

    $msgIdF = 800002;
    mi_attach_message_to_assigned_incidents(
        ['from' => $handleF, 'body' => 'Phase 134 test body F', 'message_id' => $msgIdF],
        'telegram'
    );
    test('attach: a sender assigned to TWO open incidents attaches a note to BOTH (ticket F1)',
        _p134_note_count($ticketF1, 'telegram') === 1);
    test('attach: a sender assigned to TWO open incidents attaches a note to BOTH (ticket F2)',
        _p134_note_count($ticketF2, 'telegram') === 1);

    // ── B3: an UNRESOLVED sender is a silent no-op ──────────────────────
    $ghostHandle = 'p134_ghost_' . $marker;
    $msgIdGhost  = 800003;
    $threwGhost = false;
    try {
        mi_attach_message_to_assigned_incidents(
            ['from' => $ghostHandle, 'body' => 'nobody should see this', 'message_id' => $msgIdGhost],
            'telegram'
        );
    } catch (Throwable $e) {
        $threwGhost = true;
    }
    test('attach: an UNRESOLVED sender never throws', !$threwGhost);
    test('attach: an UNRESOLVED sender writes no note anywhere',
        _p134_note_count_by_msgid($msgIdGhost) === 0);

    // ── B4: a resolved sender with NO open assignment is a silent no-op ─
    $memG = _p134_make_member();
    track($cleanup, 'member', $memG);

    $handleG = 'p134_telegram_g_' . $marker;
    db_query("INSERT INTO `{$prefix}member_comm_identifiers`
              (`member_id`, `comm_mode_id`, `values_json`, `is_primary`)
              VALUES (?, ?, ?, 1)",
        [$memG, $telegramModeId, json_encode(['username' => $handleG])]);
    track($cleanup, 'member_comm_identifiers', db_insert_id());
    // Deliberately NO responder / assigns for memG — resolved member, zero
    // open assignments.

    $msgIdG = 800004;
    $threwG = false;
    try {
        mi_attach_message_to_assigned_incidents(
            ['from' => $handleG, 'body' => 'resolved but nothing assigned', 'message_id' => $msgIdG],
            'telegram'
        );
    } catch (Throwable $e) {
        $threwG = true;
    }
    test('attach: a resolved sender with NO open assignment never throws', !$threwG);
    test('attach: a resolved sender with NO open assignment writes no note anywhere',
        _p134_note_count_by_msgid($msgIdG) === 0);

    // ── B5: a deliberately malformed $message never throws ─────────────
    $threwMalformed1 = false;
    try {
        mi_attach_message_to_assigned_incidents([], 'telegram');
    } catch (Throwable $e) {
        $threwMalformed1 = true;
    }
    test('attach: a completely empty $message array never throws', !$threwMalformed1);

    $threwMalformed2 = false;
    try {
        // Missing every sender key _mi_message_sender() looks for.
        mi_attach_message_to_assigned_incidents(['body' => 'no sender fields at all'], 'telegram');
    } catch (Throwable $e) {
        $threwMalformed2 = true;
    }
    test('attach: a $message missing all sender keys never throws', !$threwMalformed2);

    $threwMalformed3 = false;
    try {
        // 'from' present but not a scalar — is_scalar() guard in
        // _mi_message_sender() must hold.
        mi_attach_message_to_assigned_incidents(['from' => ['not', 'a', 'string']], 'telegram');
    } catch (Throwable $e) {
        $threwMalformed3 = true;
    }
    test('attach: a non-scalar "from" field never throws', !$threwMalformed3);

} catch (Throwable $e) {
    echo "[FAIL] setup/exec threw: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    // Reverse-dependency cleanup order: notes/assigns before tickets,
    // identifiers/personnel-links before members, responders after their
    // dependents are gone.
    foreach (($cleanup['ticket'] ?? []) as $tid) {
        try { db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]); } catch (Throwable $e) {}
    }
    foreach (['assigns', 'unit_personnel_assignments', 'member_comm_identifiers', 'responder', 'ticket', 'member'] as $t) {
        foreach (($cleanup[$t] ?? []) as $id) {
            try { db_query("DELETE FROM `{$prefix}{$t}` WHERE `id` = ?", [$id]); }
            catch (Throwable $e) { /* best-effort */ }
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
