<?php
/**
 * Issue #22 (2026-07-02) — regression test for par_user_owns_responder().
 *
 * a beta tester reported his Field Unit responder couldn't ack PAR from the
 * mobile interface — the api/par.php ack gate required manage_par OR
 * create_incident and Field Unit role has neither. Phase 16c fix
 * broadened the gate to also allow change_unit_status, but only when
 * the caller "owns" the responder_id being acked, matching the same
 * 3-path lookup api/mobile-data.php uses to bind logged-in users to
 * their responders.
 *
 * This test:
 *   1. Builds a throwaway user + responder pair via each of the 3
 *      ownership paths (user_id link, personal_for_member_id link,
 *      username-matches-name).
 *   2. Asserts par_user_owns_responder returns true for the matching
 *      user and false for a stranger.
 *   3. Cleans up.
 *
 * Usage: php tools/test_par_ownership.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

echo "=== par_user_owns_responder() ownership tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';
$created = ['users' => [], 'members' => [], 'responders' => []];

function pt_assert(string $label, bool $expected, bool $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        echo "  [FAIL] $label — expected " . ($expected ? 'true' : 'false')
             . ", got " . ($actual ? 'true' : 'false') . "\n";
        $fail++;
    }
}

function pt_create_user(string $username): int {
    global $prefix, $created;
    db_query(
        "INSERT INTO `{$prefix}user` (`user`, `passwd`, `level`, `status`, `open_at`, `org`)
         VALUES (?, '', 0, 'approved', 'd', 0)",
        [$username]
    );
    $id = (int) db_insert_id();
    $created['users'][] = $id;
    return $id;
}

function pt_synth_member_id(): int {
    // We don't need a real `member` row — the ownership check only
    // walks user.member → responder.personal_for_member_id (no FK).
    // Use a large synthetic ID unlikely to collide with real data
    // and stamp it on the user + responder rows we do create.
    return random_int(9_000_000, 9_999_999);
}

function pt_create_responder(array $fields): int {
    global $prefix, $created;
    $cols = array_keys($fields);
    $ph   = array_fill(0, count($cols), '?');
    $vals = array_values($fields);
    db_query(
        "INSERT INTO `{$prefix}responder` (`" . implode('`, `', $cols) . "`)
         VALUES (" . implode(', ', $ph) . ")",
        $vals
    );
    $id = (int) db_insert_id();
    $created['responders'][] = $id;
    return $id;
}

// ── Path 1: direct responder.user_id link ─────────────────────────────
$slug = 'ownr_test_' . bin2hex(random_bytes(3));
$userId1 = pt_create_user($slug . '_p1');
$respId1 = pt_create_responder([
    'name'        => 'Test Unit P1 ' . $slug,
    'description' => 'ownership test path 1',
    'user_id'     => $userId1,
]);
pt_assert('Path 1: owner user sees own responder',
    true,  par_user_owns_responder($userId1, $respId1));

// ── Path 2: personal-resource unit via member ─────────────────────────
$userId2  = pt_create_user($slug . '_p2');
$memberId = pt_synth_member_id();
// The user.member column is where the session picks up membership.
db_query("UPDATE `{$prefix}user` SET `member` = ? WHERE `id` = ?",
    [$memberId, $userId2]);
$respId2 = pt_create_responder([
    'name'                    => 'Test Unit P2 ' . $slug,
    'description'             => 'ownership test path 2',
    'personal_for_member_id'  => $memberId,
]);
pt_assert('Path 2: personal-resource owner sees own responder',
    true,  par_user_owns_responder($userId2, $respId2));

// ── Path 3: username matches responder name ───────────────────────────
$userId3 = pt_create_user($slug . '_p3');
$respId3 = pt_create_responder([
    'name'        => $slug . '_p3',   // matches user.user
    'description' => 'ownership test path 3',
]);
pt_assert('Path 3: username-matches-responder-name resolves',
    true,  par_user_owns_responder($userId3, $respId3));

// ── Negative case: unrelated user cannot ack ─────────────────────────
$strangerId = pt_create_user($slug . '_stranger');
pt_assert('Stranger cannot ack Path 1 responder',
    false, par_user_owns_responder($strangerId, $respId1));
pt_assert('Stranger cannot ack Path 2 responder',
    false, par_user_owns_responder($strangerId, $respId2));
pt_assert('Stranger cannot ack Path 3 responder',
    false, par_user_owns_responder($strangerId, $respId3));

// ── Edge cases ────────────────────────────────────────────────────────
pt_assert('user_id=0 rejected',
    false, par_user_owns_responder(0, $respId1));
pt_assert('responder_id=0 rejected',
    false, par_user_owns_responder($userId1, 0));
pt_assert('nonexistent responder returns false',
    false, par_user_owns_responder($userId1, 99999999));

// ── Cleanup ───────────────────────────────────────────────────────────
foreach ($created['responders'] as $id) {
    try { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$id]); } catch (Exception $e) {}
}
foreach ($created['users'] as $id) {
    try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$id]); } catch (Exception $e) {}
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
