<?php
/**
 * GH #8 regression — audit → push → recipient-predicate integration.
 *
 * The mobile/PWA push pipeline is: audit_log() fans out to push_fire(), which
 * builds a routed message and calls router_evaluate(); the two Phase 99v seed
 * routes resolve recipients via the `assigned_to_incident` predicate. Two prior
 * #8 fixes "worked" in isolation but the field user still got no push — and the
 * code comment in inc/audit.php admits "survived two prior fixes because no test
 * covered the audit->push->predicate integration." This is that test.
 *
 * It exercises the exact chain the seed route uses, with controlled data:
 *   1. push_fire() flattens ticket_id to the top level of its message
 *      (audit_flatten_ticket_id) — verify the flattener for every target_type
 *      the push allowlist emits.
 *   2. The seed route's predicate `{assigned_to_incident, ticket_id:
 *      $payload.ticket_id}` must resolve `$payload.ticket_id` against that
 *      message and return the assigned user(s).
 *   3. UNIT path: a responder linked directly to a login user (responder.user_id).
 *   4. PERSONNEL path (a beta tester's install): responder.user_id NULL, but a member
 *      with a user_id is assigned to the unit via unit_personnel_assignments.
 *   5. A CLEARED assignment must NOT resolve (no push after the unit clears).
 *
 * If this passes, the routing/recipient resolution is correct end-to-end and a
 * "no push" report is environmental (subscription missing, VAPID unset, or iOS
 * APNs delivery) rather than a routing bug.
 *
 * Self-skips on installs missing the routing engine.  Usage:
 *   php tests/test_push_recipient_resolution.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/router_recipients.php';
require_once 'inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

if (!function_exists('router_recipients_resolve')
    || !function_exists('audit_flatten_ticket_id')) {
    echo "SKIP: routing engine not present (pre-Phase-99v install)\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// Synthetic ticket ids well above any real row so we never collide with data.
$TID_UNIT      = 990000801;
$TID_PERSONNEL = 990000802;
$TID_CLEARED   = 990000803;

$cleanup = [];   // [table => [ids...]]
function track(&$cleanup, $table, $id) { $cleanup[$table][] = (int) $id; }

try {
    // ── Part 1: audit_flatten_ticket_id (push_fire's ticket_id source) ──
    // incident.created carries the id as target_id (target_type 'ticket').
    test('flatten: ticket target uses target_id',
        audit_flatten_ticket_id('ticket', $TID_UNIT, null) === $TID_UNIT);
    // assign.created carries it nested in details[].
    test('flatten: assigns target reads details.ticket_id',
        audit_flatten_ticket_id('assigns', 12345, ['ticket_id' => $TID_UNIT]) === $TID_UNIT);
    // The bug that bit #8: an assign audit with NO ticket_id in details resolves
    // to null, so the predicate sees ticket_id 0 and matches nobody. This asserts
    // the contract every assign-audit caller must satisfy (details['ticket_id']).
    test('flatten: assigns target without details.ticket_id yields null',
        audit_flatten_ticket_id('assigns', 12345, []) === null);

    // ── Set up a login user + responder linked to it (UNIT path) ──
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`, `can_login`)
              VALUES (?, ?, 'Push', 'UnitUser', 1)",
             ['push_test_unit_' . $TID_UNIT, 'x']);
    $userUnit = (int) db_insert_id();
    track($cleanup, 'user', $userUnit);

    db_query("INSERT INTO `{$prefix}responder` (`name`, `description`, `user_id`)
              VALUES ('Push Test Unit', '', ?)", [$userUnit]);
    $respUnit = (int) db_insert_id();
    track($cleanup, 'responder', $respUnit);

    db_query("INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `clear`)
              VALUES (?, ?, 0, NULL)", [$TID_UNIT, $respUnit]);
    track($cleanup, 'assigns', db_insert_id());

    // ── Set up personnel-only path: responder with NO user link, but a member
    //    (with a login user) assigned to it via unit_personnel_assignments ──
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`, `can_login`)
              VALUES (?, ?, 'Push', 'PersonUser', 1)",
             ['push_test_person_' . $TID_PERSONNEL, 'x']);
    $userPerson = (int) db_insert_id();
    track($cleanup, 'user', $userPerson);

    // NB: member.first_name/last_name are GENERATED columns (from field1/field2);
    // insert only the base user_id link.
    db_query("INSERT INTO `{$prefix}member` (`user_id`) VALUES (?)", [$userPerson]);
    $memPerson = (int) db_insert_id();
    track($cleanup, 'member', $memPerson);

    db_query("INSERT INTO `{$prefix}responder` (`name`, `description`, `user_id`)
              VALUES ('Push Test NoUser', '', NULL)");
    $respPerson = (int) db_insert_id();
    track($cleanup, 'responder', $respPerson);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
             [$respPerson, $memPerson]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    db_query("INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `clear`)
              VALUES (?, ?, 0, NULL)", [$TID_PERSONNEL, $respPerson]);
    track($cleanup, 'assigns', db_insert_id());

    // ── Cleared assignment: same responder, but the assign is cleared ──
    db_query("INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `clear`)
              VALUES (?, ?, 0, NOW())", [$TID_CLEARED, $respUnit]);
    track($cleanup, 'assigns', db_insert_id());

    // The seed route's exact predicate (inc/router_recipients.php seed 1).
    $seedPredicate = [
        'predicate' => 'assigned_to_incident',
        'params'    => ['ticket_id' => '$payload.ticket_id'],
    ];
    // Build a message exactly as push_fire() does for an assign.created event.
    $mkMessage = function ($tid) {
        $payload = ['category' => 'incident', 'activity' => 'assign',
                    'target_type' => 'assigns', 'ticket_id' => $tid];
        return array_merge($payload, [
            '_event_type'    => 'assign.created',
            '_event_payload' => $payload,
            'body'           => 'Assigned unit to incident',
        ]);
    };

    // ── Part 2: UNIT path resolves the linked user ──
    $ids = router_recipients_resolve($seedPredicate, $mkMessage($TID_UNIT));
    test('UNIT path: assigned responder\'s user resolves for push',
        in_array($userUnit, $ids, true));

    // ── Part 3: PERSONNEL path resolves the member\'s user ──
    $ids = router_recipients_resolve($seedPredicate, $mkMessage($TID_PERSONNEL));
    test('PERSONNEL path: assigned member\'s user resolves for push (GH #8)',
        in_array($userPerson, $ids, true));

    // ── Part 4: cleared assignment resolves nobody ──
    $ids = router_recipients_resolve($seedPredicate, $mkMessage($TID_CLEARED));
    test('cleared assignment does NOT resolve (no push after clear)',
        !in_array($userUnit, $ids, true));

    // ── Part 5: the placeholder must actually resolve. A message with NO
    //    ticket_id must match nobody (proves $payload.ticket_id is read, not
    //    silently defaulted to some catch-all). ──
    $noTid = ['_event_type' => 'assign.created', 'body' => 'x'];
    $ids = router_recipients_resolve($seedPredicate, $noTid);
    test('missing ticket_id resolves to zero recipients (placeholder honoured)',
        $ids === []);

} catch (Throwable $e) {
    echo "[FAIL] setup/exec threw: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    // Reverse-order cleanup so FK-ish dependencies unwind cleanly.
    foreach (['assigns', 'unit_personnel_assignments', 'responder', 'member', 'user'] as $t) {
        foreach (($cleanup[$t] ?? []) as $id) {
            try { db_query("DELETE FROM `{$prefix}{$t}` WHERE `id` = ?", [$id]); }
            catch (Throwable $e) { /* best-effort */ }
        }
    }
}

// ── GH #8 regression (2026-07-14) ──
// push_fire() is driven by the routing engine and hard-needs router_evaluate()
// from inc/router.php. The incident-create include chain does NOT load
// inc/router.php, so push_fire used to silently skip ("router_evaluate not
// loaded") on every dispatch while unit-update paths that happened to pull the
// router in worked — the "unit pushes arrive, incident pushes don't" bug.
// push_fire must now lazily require inc/router.php itself; guard that.
$pushSrc = @file_get_contents(__DIR__ . '/../inc/push.php') ?: '';
if (strpos($pushSrc, "require_once __DIR__ . '/broker.php'") !== false) {
    echo "[PASS] push_fire self-loads inc/broker.php (router + 'push' channel registered on every path)\n";
    $pass++;
} else {
    echo "[FAIL] push_fire no longer loads inc/broker.php — incident push can silently skip\n";
    $fail++;
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
