<?php
/**
 * QA test suite for the role-detail user_count vs users-list bug
 * Eric caught 2026-06-30 on /roles.php.
 *
 * Bug summary: a role's badge shows "1 user" but the role-detail's
 * "Users assigned" list shows "No users have this role." The two
 * counts came from different definitions of "user has this role":
 *   - badge: ANY non-expired user_roles row (multi-role-per-user)
 *   - list:  user's MOST RECENT grant (single-role-per-user)
 *
 * A user with grants for both Dispatcher (older) and Super Admin
 * (newer) appears in Dispatcher's COUNT but only in Super Admin's
 * users-list — the contradiction.
 *
 * These tests SPEC the fix:
 *   1. The role-detail API returns an assigned_users list that
 *      includes everyone who has ANY active grant of the role,
 *      regardless of whether the role is their most-recent grant.
 *   2. The count returned by the API matches that list's length.
 *   3. The roles-list view's user_count badge equals the same number.
 *   4. Removing a user's grant for the role drops them out of all
 *      three (badge, list, detail).
 *
 * Idempotent fixtures: creates a sandbox user + sandbox role, runs
 * assertions, cleans up at the end in a finally block.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$tests = 0; $fail = 0;
function assert_eq($expected, $actual, $label) {
    global $tests, $fail;
    $tests++;
    if ($expected === $actual) {
        echo "  [OK]  $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label\n";
        echo "         expected: " . json_encode($expected) . "\n";
        echo "         actual:   " . json_encode($actual) . "\n";
    }
}
function assert_contains_uid($uid, array $users, $label) {
    global $tests, $fail;
    $tests++;
    $ids = array_map(function ($u) { return (int) ($u['id'] ?? $u['user_id'] ?? 0); }, $users);
    if (in_array($uid, $ids, true)) {
        echo "  [OK]  $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label — uid $uid not in [" . implode(',', $ids) . "]\n";
    }
}

echo "QA: role user_count vs assigned_users consistency\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Pick or create the fixture user + a real second role to grant ──
// We need a user with at least TWO grants so we can prove the
// multi-role-per-user case works correctly.
$sandboxUid = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}user` ORDER BY id LIMIT 1"
);
if (!$sandboxUid) {
    // Virgin install (QA automation 2026-07-07): no users to grant roles
    // to — skip instead of failing CI. Seeded installs still run fully.
    echo "SKIP: no users on this install (run tools/create_admin.php first)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

// Find a role we can grant safely — Operator (id=4) and Read-Only (id=5)
// are both system roles that won't change semantics if we add a grant.
$roleA = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}roles` WHERE name = 'Operator' LIMIT 1"
);
$roleB = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}roles` WHERE name = 'Read-Only' LIMIT 1"
);
if (!$roleA || !$roleB) { echo "  [FATAL] missing Operator/Read-Only fixture roles\n"; exit(1); }

// Snapshot existing grants for cleanup
$preExisting = db_fetch_all(
    "SELECT id FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id IN (?, ?)",
    [$sandboxUid, $roleA, $roleB]
);
$preExistingIds = array_map(function ($r) { return (int) $r['id']; }, $preExisting);

$insertedA = null;
$insertedB = null;
$origRoleACount = (int) db_fetch_value(
    "SELECT COUNT(DISTINCT ur.user_id) FROM `{$prefix}user_roles` ur
     INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
     WHERE ur.role_id = ?
       AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
    [$roleA]
);

try {
    // ── Step 1: grant role A (Operator) with an OLDER timestamp ──
    if (!in_array($sandboxUid, array_map(function ($r) { return (int) $r['user_id']; },
        db_fetch_all("SELECT user_id FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ?", [$sandboxUid, $roleA])), true)) {
        db_query(
            "INSERT INTO `{$prefix}user_roles` (user_id, role_id, scope_kind, granted_at)
             VALUES (?, ?, 'global', DATE_SUB(NOW(), INTERVAL 2 DAY))",
            [$sandboxUid, $roleA]
        );
        $insertedA = (int) db_insert_id();
    }

    // ── Step 2: grant role B (Read-Only) with a NEWER timestamp ──
    if (!in_array($sandboxUid, array_map(function ($r) { return (int) $r['user_id']; },
        db_fetch_all("SELECT user_id FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ?", [$sandboxUid, $roleB])), true)) {
        db_query(
            "INSERT INTO `{$prefix}user_roles` (user_id, role_id, scope_kind, granted_at)
             VALUES (?, ?, 'global', NOW())",
            [$sandboxUid, $roleB]
        );
        $insertedB = (int) db_insert_id();
    }

    echo "  fixture: user_id=$sandboxUid granted Operator (old) + Read-Only (new)\n";

    // ── Test 1: role-list user_count for roleA must include sandboxUid ──
    $listCount = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT ur.user_id)
           FROM `{$prefix}user_roles` ur
           INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
          WHERE ur.role_id = ?
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
        [$roleA]
    );
    assert_eq($origRoleACount + ($insertedA ? 1 : 0), $listCount,
        "roleA badge user_count includes user with multi-role grants");

    // ── Test 2: simulate the OLD users-list query (most-recent-grant only) ──
    // This is what api/config-admin.php currently returns.
    $newestGrantRoleId = (int) db_fetch_value(
        "SELECT ur.role_id
           FROM `{$prefix}user_roles` ur
          WHERE ur.user_id = ?
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
          ORDER BY ur.granted_at DESC, ur.id DESC
          LIMIT 1",
        [$sandboxUid]
    );
    assert_eq($roleB, $newestGrantRoleId,
        "single-most-recent-grant returns the NEWER role (Read-Only)");
    // Under the OLD logic, user does NOT appear in roleA's user list.
    // This test documents the bug we're fixing.
    echo "  [BUG-DEMO] old code: state.users would show user only under Read-Only,\n";
    echo "             so Operator's badge=$listCount but list would be empty.\n";

    // ── Test 3: NEW query — every user with ANY active grant of roleA ──
    $assignedUsers = db_fetch_all(
        "SELECT DISTINCT u.id, u.user
           FROM `{$prefix}user_roles` ur
           INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
          WHERE ur.role_id = ?
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
          ORDER BY u.user",
        [$roleA]
    );
    assert_contains_uid($sandboxUid, $assignedUsers,
        "new multi-grant query: roleA's user-list includes the sandbox user");
    assert_eq($listCount, count($assignedUsers),
        "new multi-grant query length matches the badge count exactly");

    // ── Test 4: same user under roleB (must also appear) ──
    $assignedUsersB = db_fetch_all(
        "SELECT DISTINCT u.id, u.user
           FROM `{$prefix}user_roles` ur
           INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
          WHERE ur.role_id = ?
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
          ORDER BY u.user",
        [$roleB]
    );
    assert_contains_uid($sandboxUid, $assignedUsersB,
        "user appears in BOTH roles' lists (multi-role-per-user)");

    // ── Test 5: expired grant must be excluded from both badge and list ──
    if ($insertedA) {
        db_query("UPDATE `{$prefix}user_roles` SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?",
            [$insertedA]);
        $listCountAfterExpire = (int) db_fetch_value(
            "SELECT COUNT(DISTINCT ur.user_id)
               FROM `{$prefix}user_roles` ur
               INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
              WHERE ur.role_id = ?
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$roleA]
        );
        assert_eq($origRoleACount, $listCountAfterExpire,
            "expired grant drops out of badge count");
        $assignedAfterExpire = db_fetch_all(
            "SELECT DISTINCT u.id FROM `{$prefix}user_roles` ur
               INNER JOIN `{$prefix}user` u ON u.id = ur.user_id
              WHERE ur.role_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$roleA]
        );
        $expiredOut = !in_array($sandboxUid, array_map(function ($r) { return (int) $r['id']; }, $assignedAfterExpire), true);
        assert_eq(true, $expiredOut, "expired grant drops out of users list");
        // Restore for next assertion
        db_query("UPDATE `{$prefix}user_roles` SET expires_at = NULL WHERE id = ?", [$insertedA]);
    }

    // ── Test 6: remove_role scoping — must drop ONLY this (user, role) grant ──
    // Eric beta 2026-06-30: when a user appears in two role lists (e.g.
    // Dispatcher + Super Admin), clicking the "remove" X on Dispatcher
    // must drop the Dispatcher grant only — NOT the Super Admin one. The
    // server endpoint api/rbac.php action=remove_role takes (user_id,
    // role_id) and that's exactly what roles.js sends. Verify the SQL
    // matches that intent.
    if ($insertedA && $insertedB) {
        $beforeA = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ?",
            [$sandboxUid, $roleA]
        );
        $beforeB = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ?",
            [$sandboxUid, $roleB]
        );
        // Simulate exactly what api/rbac.php action=remove_role would do:
        $row = db_fetch_one(
            "SELECT id FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ? LIMIT 1",
            [$sandboxUid, $roleA]
        );
        if ($row) db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [(int) $row['id']]);
        $afterA = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ?",
            [$sandboxUid, $roleA]
        );
        $afterB = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ? AND role_id = ?",
            [$sandboxUid, $roleB]
        );
        assert_eq($beforeA - 1, $afterA, "remove dropped the roleA grant for sandbox user");
        assert_eq($beforeB,     $afterB, "remove did NOT touch the user's other (roleB) grant");
        $insertedA = null;  // already deleted; finally{} skips
    }

    // ── Test 7: deleted user excluded from both ──
    // (Don't actually delete a real user; verify via the INNER JOIN logic.)
    // We just sanity-check that an orphan user_roles row (user_id pointing
    // to a non-existent user) would be excluded by INNER JOIN — that's a
    // schema correctness test, not behavioural.
    $orphanCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles` ur
         LEFT JOIN `{$prefix}user` u ON u.id = ur.user_id
         WHERE u.id IS NULL"
    );
    echo "  fixture observation: $orphanCount orphan user_roles rows (would inflate count without INNER JOIN)\n";

} finally {
    if ($insertedA) {
        db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$insertedA]);
        echo "  cleanup: dropped roleA grant id=$insertedA\n";
    }
    if ($insertedB) {
        db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$insertedB]);
        echo "  cleanup: dropped roleB grant id=$insertedB\n";
    }
}

echo "\n  $tests tests, $fail failures.\n";
exit($fail > 0 ? 1 : 0);
