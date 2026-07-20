<?php
/**
 * GH #8 regression — mobile/PWA push recipient resolution.
 *
 * The mobile push never fired on dispatch because the incident.created audit
 * event carried the ticket id only as `target_id`, while the push recipient
 * predicate `assigned_to_incident` reads `$payload.ticket_id`. It resolved to
 * null → ticket_id 0 → zero recipients → push silently skipped. This survived
 * TWO prior "fixes" because nothing tested the audit->push->predicate chain.
 *
 * Part A (always): audit_flatten_ticket_id() puts the ticket id top-level.
 * Part B (DB; self-skips on a virgin DB): the assigned_to_incident predicate
 *   resolves the dispatched responder's user ONLY when the payload carries a
 *   top-level ticket_id — proving both the bug and the fix.
 *
 * Usage: php tests/test_push_recipient.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/audit.php';
require_once 'inc/router_recipients.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

// ── Part A: pure flatten helper ──────────────────────────────────────
test('flatten: ticket target -> ticket_id',
    audit_flatten_ticket_id('ticket', 42, null) === 42);
test('flatten: incident.created (target_id, no details) still resolves',
    audit_flatten_ticket_id('ticket', '77', ['severity' => 3]) === 77);
test('flatten: assign event nests ticket_id in details',
    audit_flatten_ticket_id('assign', 0, ['ticket_id' => 88]) === 88);
test('flatten: non-ticket event -> null',
    audit_flatten_ticket_id('responder', 5, ['foo' => 'bar']) === null);
test('flatten: zero/absent details ticket_id -> null',
    audit_flatten_ticket_id('member', 9, ['ticket_id' => 0]) === null);

// ── Part B: predicate resolution against real rows ───────────────────
$uid = (int) db_fetch_value("SELECT `id` FROM `{$prefix}user` ORDER BY `id` LIMIT 1");
$typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");
$hasResponderUser = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'user_id'",
    [$prefix . 'responder']);

if (!$uid || !$hasResponderUser) {
    echo "SKIP: no user row or responder.user_id column (virgin DB) — Part B skipped\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}

$now = date('Y-m-d H:i:s');
$ticketId = 0; $respId = 0;
try {
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, scope, description, date)
              VALUES (?, 2, 'gh8 push test', 'gh8 push test', ?)", [$typeId ?: 0, $now]);
    $ticketId = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, description, user_id)
              VALUES ('GH8 Test Unit', 'gh8 push test', ?)", [$uid]);
    $respId = (int) db_insert_id();
    // assigns.user_id is NOT NULL without default — must be supplied.
    // clear stays NULL (open assignment) so the predicate matches.
    db_query("INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, as_of, dispatched)
              VALUES (?, ?, ?, ?, ?)", [$ticketId, $respId, $uid, $now, $now]);

    $predicate = ['predicate' => 'assigned_to_incident',
                  'params' => ['ticket_id' => '$payload.ticket_id']];

    // The FIXED payload — top-level ticket_id present — resolves the user.
    $good = router_recipients_resolve($predicate, ['ticket_id' => $ticketId]);
    test('predicate resolves assigned user when ticket_id is top-level',
        in_array($uid, array_map('intval', $good), true));

    // The OLD BROKEN payload — only target_id, no ticket_id — resolves nobody.
    $broken = router_recipients_resolve($predicate, ['target_id' => $ticketId]);
    test('predicate resolves NOBODY without a top-level ticket_id (the bug)',
        empty($broken));

    // The fix bridges them: audit_flatten_ticket_id() turns the broken shape
    // into the working one.
    $payload = ['target_type' => 'ticket', 'target_id' => $ticketId];
    $tid = audit_flatten_ticket_id($payload['target_type'], $payload['target_id'], null);
    if ($tid !== null) { $payload['ticket_id'] = $tid; }
    $fixed = router_recipients_resolve($predicate, $payload);
    test('flatten() makes the incident.created payload resolve the user',
        in_array($uid, array_map('intval', $fixed), true));
} finally {
    if ($ticketId) { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]); }
    if ($ticketId) { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); }
    if ($respId)   { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$respId]); }
}

// ── Part C: GH #8 — personnel-on-unit path (a beta tester's exact case) ──────
// A unit with NO direct user_id but with an assigned person (linked to a
// login user via member.user_id) must still resolve that user as a push
// recipient. Uses an existing member that already has a user_id so we don't
// have to build a member row (legacy NOT NULL columns make that fragile).
$hasUpa = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?",
    [$prefix . 'unit_personnel_assignments']);
$memberRow = $hasUpa
    ? db_fetch_one("SELECT id, user_id FROM `{$prefix}member`
                    WHERE user_id IS NOT NULL AND user_id > 0 LIMIT 1")
    : null;

if (!$memberRow) {
    echo "SKIP: no unit_personnel_assignments table or no member with a user link"
       . " — Part C (personnel path) skipped\n";
} else {
    $t2 = 0; $r2 = 0; $upaId = 0;
    $memUid = (int) $memberRow['user_id'];
    try {
        db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, scope, description, date)
                  VALUES (?, 2, 'gh8 personnel test', 'gh8 personnel test', ?)", [$typeId ?: 0, $now]);
        $t2 = (int) db_insert_id();
        // Unit with NO user_id — the whole point.
        db_query("INSERT INTO `{$prefix}responder` (name, description)
                  VALUES ('GH8 No-User Unit', 'gh8 personnel test')");
        $r2 = (int) db_insert_id();
        db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
                  (responder_id, member_id, role, status, assigned_at)
                  VALUES (?, ?, 'crew', 'active', ?)", [$r2, (int) $memberRow['id'], $now]);
        $upaId = (int) db_insert_id();
        db_query("INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, as_of, dispatched)
                  VALUES (?, ?, ?, ?, ?)", [$t2, $r2, $memUid, $now, $now]);

        $predicate = ['predicate' => 'assigned_to_incident',
                      'params' => ['ticket_id' => '$payload.ticket_id']];
        $res = router_recipients_resolve($predicate, ['ticket_id' => $t2]);
        test('unit with NO user_id resolves the assigned person via personnel',
            in_array($memUid, array_map('intval', $res), true));
    } finally {
        if ($t2)    { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$t2]); }
        if ($upaId) { db_query("DELETE FROM `{$prefix}unit_personnel_assignments` WHERE id = ?", [$upaId]); }
        if ($t2)    { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$t2]); }
        if ($r2)    { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$r2]); }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
