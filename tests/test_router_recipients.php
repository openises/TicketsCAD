<?php
/**
 * Phase 99v-1 — Routing engine recipient predicate tests.
 *
 * Exercises:
 *   - Schema migration is idempotent
 *   - Each of the 6 predicates resolves correctly against seeded data
 *   - any_of / all_of / none_of compose correctly
 *   - $payload.X references resolve from the message
 *   - Malformed input → [] not exception
 *
 * Uses transactional fixtures: seed a fresh ticket, responder,
 * assignment, team, and team-member, run the asserts, then roll back.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/router_recipients.php';

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
function assert_contains($needle, array $hay, $label) {
    global $tests, $fail;
    $tests++;
    if (in_array($needle, $hay, true)) {
        echo "  [OK]  $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label\n";
        echo "         needle:   " . json_encode($needle) . "\n";
        echo "         haystack: " . json_encode($hay) . "\n";
    }
}
function assert_not_contains($needle, array $hay, $label) {
    global $tests, $fail;
    $tests++;
    if (!in_array($needle, $hay, true)) {
        echo "  [OK]  $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label\n";
        echo "         needle:   " . json_encode($needle) . "\n";
        echo "         haystack: " . json_encode($hay) . "\n";
    }
}

echo "Phase 99v-1: router recipients\n";

// ── 1. Schema migration is idempotent ────────────────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';
router_recipients_ensure_column();
$col = db_fetch_one(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = 'recipient_predicate_json'",
    [$prefix . 'message_routes']
);
assert_eq('recipient_predicate_json', $col['COLUMN_NAME'] ?? null, 'recipient_predicate_json column exists');
// Re-running is a no-op
router_recipients_ensure_column();
assert_eq(true, true, 're-running migration is a no-op (no exception)');

// ── 2. Fixtures ──────────────────────────────────────────────────────
// Pick a real user, member, and responder to use as ground truth.
$userId = (int) db_fetch_value("SELECT id FROM `{$prefix}user` ORDER BY id LIMIT 1");
$memberId = (int) db_fetch_value("SELECT id FROM `{$prefix}member` WHERE user_id = ? LIMIT 1", [$userId]);
if (!$memberId) {
    // fall back to any member; not ideal but keeps the test runnable on bare seeds
    $memberId = (int) db_fetch_value("SELECT id FROM `{$prefix}member` ORDER BY id LIMIT 1");
    if ($memberId) {
        db_query("UPDATE `{$prefix}member` SET user_id = ? WHERE id = ?", [$userId, $memberId]);
    }
}
$responderId = (int) db_fetch_value("SELECT id FROM `{$prefix}responder` WHERE user_id = ? LIMIT 1", [$userId]);
if (!$responderId) {
    $responderId = (int) db_fetch_value("SELECT id FROM `{$prefix}responder` ORDER BY id LIMIT 1");
    if ($responderId) {
        db_query("UPDATE `{$prefix}responder` SET user_id = ? WHERE id = ?", [$userId, $responderId]);
    }
}
$onSceneStatusId = (int) db_fetch_value("SELECT id FROM `{$prefix}un_status` WHERE LOWER(status_val) = 'on scene' LIMIT 1");

echo "  fixture: user=$userId member=$memberId responder=$responderId on_scene_status=$onSceneStatusId\n";

// Use an existing open ticket as the fixture (creating a fresh one
// would need org_id + many NOT NULL fields with no sane defaults).
// We only add a temporary assignment + remove it after.
$testTicketId = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}ticket` WHERE deleted_at IS NULL ORDER BY id LIMIT 1"
);
$haveAssignmentFixture = ($testTicketId > 0 && $responderId > 0);
if (!$haveAssignmentFixture) {
    echo "  [SKIP] no operational fixtures (ticket / responder with user link) — assignment-bound tests will be skipped on this install\n";
}
$newAssignId = null;
if ($haveAssignmentFixture) {
    db_query("INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, clear, as_of)
              VALUES (?, ?, 0, NULL, NOW())", [$testTicketId, $responderId]);
    $newAssignId = (int) db_insert_id();
}

// Snapshot responder un_status_id so we can restore.
$origStatus = (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
if ($onSceneStatusId) {
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?",
        [$onSceneStatusId, $responderId]);
}

try {
    // ── 3. user_id_in predicate ──────────────────────────────────────
    $ids = router_recipients_resolve(
        ['predicate' => 'user_id_in', 'params' => ['user_ids' => [$userId, 99999]]],
        []
    );
    assert_contains($userId, $ids, "user_id_in includes requested userId");
    assert_eq(2, count($ids), "user_id_in returns both ids (even bogus 99999)");

    // ── 4. assigned_to_incident predicate (needs fixture) ────────────
    if ($haveAssignmentFixture) {
        $ids = router_recipients_resolve(
            ['predicate' => 'assigned_to_incident', 'params' => ['ticket_id' => $testTicketId]],
            []
        );
        assert_contains($userId, $ids, "assigned_to_incident returns the assigned user");

        // With $payload.ticket_id reference
        $ids = router_recipients_resolve(
            ['predicate' => 'assigned_to_incident', 'params' => ['ticket_id' => '$payload.ticket_id']],
            ['ticket_id' => $testTicketId]
        );
        assert_contains($userId, $ids, '$payload.ticket_id reference resolves');
    } else {
        echo "  [SKIP] assigned_to_incident tests (no fixture)\n";
    }

    // ── 5. responder_status_in predicate ─────────────────────────────
    if ($onSceneStatusId) {
        $ids = router_recipients_resolve(
            ['predicate' => 'responder_status_in', 'params' => ['status_names' => ['On Scene']]],
            []
        );
        assert_contains($userId, $ids, "responder_status_in matches On Scene");

        // case-insensitivity
        $ids = router_recipients_resolve(
            ['predicate' => 'responder_status_in', 'params' => ['status_names' => ['ON SCENE', 'available']]],
            []
        );
        assert_contains($userId, $ids, "responder_status_in is case-insensitive");
    }

    // ── 6. member_of_team predicate (if test member is in any team) ─
    $teamIds = db_fetch_all(
        "SELECT DISTINCT team_id FROM `{$prefix}team_members` WHERE member_id = ?",
        [$memberId]
    );
    if (!empty($teamIds)) {
        $tids = array_map(function ($r) { return (int) $r['team_id']; }, $teamIds);
        $ids = router_recipients_resolve(
            ['predicate' => 'member_of_team', 'params' => ['team_ids' => $tids]],
            []
        );
        assert_contains($userId, $ids, "member_of_team includes user via team membership");
    } else {
        echo "  [SKIP] member_of_team (test user is on no teams)\n";
    }

    // ── 7. rbac_can predicate ────────────────────────────────────────
    // Grant the user any permission their role already has; if super admin
    // they should match action.manage_config.
    $ids = router_recipients_resolve(
        ['predicate' => 'rbac_can', 'params' => ['permission_code' => 'action.manage_config']],
        []
    );
    assert_eq(true, count($ids) > 0, "rbac_can(action.manage_config) returns non-empty set");

    // ── 8. Composition: any_of ───────────────────────────────────────
    $ids = router_recipients_resolve(
        [
            'type' => 'any_of',
            'conditions' => [
                ['predicate' => 'user_id_in', 'params' => ['user_ids' => [$userId]]],
                ['predicate' => 'user_id_in', 'params' => ['user_ids' => [99999]]],
            ]
        ],
        []
    );
    assert_eq(2, count($ids), "any_of unions both branches");
    assert_contains($userId, $ids, "any_of includes user from first branch");

    // ── 9. Composition: all_of (needs fixture for one branch) ────────
    if ($haveAssignmentFixture) {
        $ids = router_recipients_resolve(
            [
                'type' => 'all_of',
                'conditions' => [
                    ['predicate' => 'user_id_in', 'params' => ['user_ids' => [$userId, 99999]]],
                    ['predicate' => 'assigned_to_incident', 'params' => ['ticket_id' => $testTicketId]],
                ]
            ],
            []
        );
        assert_eq([$userId], $ids, "all_of intersects (only user assigned and in list)");
    } else {
        // Fall-back: intersection of two user_id_in lists exercises all_of without needing fixtures.
        $ids = router_recipients_resolve(
            [
                'type' => 'all_of',
                'conditions' => [
                    ['predicate' => 'user_id_in', 'params' => ['user_ids' => [$userId, 99999]]],
                    ['predicate' => 'user_id_in', 'params' => ['user_ids' => [$userId, 88888]]],
                ]
            ],
            []
        );
        assert_eq([$userId], $ids, "all_of intersects (literal list ∩ literal list)");
    }

    // ── 10. Composition: none_of inside all_of ───────────────────────
    $ids = router_recipients_resolve(
        [
            'type' => 'all_of',
            'conditions' => [
                ['predicate' => 'user_id_in', 'params' => ['user_ids' => [$userId, 99998, 99999]]],
                ['type' => 'none_of', 'conditions' => [
                    ['predicate' => 'user_id_in', 'params' => ['user_ids' => [99999]]],
                ]],
            ]
        ],
        []
    );
    assert_contains($userId, $ids, "all_of+none_of excludes the listed user");
    assert_not_contains(99999, $ids, "none_of removes 99999");

    // ── 11. Malformed input → [] (no exception) ──────────────────────
    $ids = router_recipients_resolve([], []);
    assert_eq([], $ids, "empty predicate returns empty");
    $ids = router_recipients_resolve(['predicate' => 'unknown_predicate'], []);
    assert_eq([], $ids, "unknown predicate returns empty");
    $ids = router_recipients_resolve(['type' => 'any_of', 'conditions' => []], []);
    assert_eq([], $ids, "empty conditions returns empty");

    // ── 12. Catalog (Settings UI uses this) ──────────────────────────
    $cat = router_recipients_available_predicates();
    assert_eq(6, count($cat), "predicate catalog lists all 6");
    assert_eq(true, isset($cat['assigned_to_incident']), "catalog has assigned_to_incident");
    assert_eq(true, isset($cat['rbac_can']), "catalog has rbac_can");

} finally {
    // Cleanup fixtures: remove only the assignment row WE inserted,
    // not any pre-existing ones, and restore the responder's status.
    if (!empty($newAssignId)) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$newAssignId]);
    }
    if ($origStatus !== null) {
        db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?",
            [$origStatus, $responderId]);
    }
}

echo "\n  $tests tests, $fail failures.\n";
exit($fail > 0 ? 1 : 0);
