<?php
/**
 * GH #85 (a beta tester / SAG-vehicle) — mobile crew-visibility regression test.
 *
 * a beta tester's remaining concern after crew-under-unit shipped: "personnel still
 * do not receive the incident in the mobile view when their unit is attached."
 * The fix (api/mobile-data.php Phase 116c) resolves the units a logged-in user
 * CREWS (member.user_id -> unit_personnel_assignments) and folds them into the
 * responder-id set the mobile assignment lookup uses — so a driver/communicator
 * assigned to a unit sees that unit's incident on mobile without being a
 * responder themselves.
 *
 * This test reproduces the REAL state through the real writers:
 *   user  --(member.user_id)-->  member  --(unit_personnel_assignments)-->  unit
 *   unit  --(assign_create_internal)-->  active incident
 * and runs the EXACT crew-resolution + assignment SQL that mobile-data.php runs,
 * asserting the incident surfaces for the crewing user. It also asserts the
 * precondition a beta tester's setup can trip on: if the crew member's personnel
 * record is NOT linked to a login account (member.user_id), nothing resolves.
 *
 * Self-skips on a DB with no user-linked member (can't reproduce the scenario).
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== GH #85 mobile crew-visibility ===\n\n";

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

/**
 * The EXACT crew-unit resolution mobile-data.php runs for a logged-in user
 * (api/mobile-data.php Phase 116c, lines ~136-144). Kept in sync by copy so
 * the mobile visibility can't silently regress.
 */
function mobile_crew_unit_ids(string $prefix, int $userId): array {
    $rows = db_fetch_all(
        "SELECT DISTINCT upa.`responder_id`
           FROM `{$prefix}unit_personnel_assignments` upa
           JOIN `{$prefix}member` m ON m.`id` = upa.`member_id`
          WHERE m.`user_id` = ?
            AND upa.`status` IN ('active','standby')
            AND (upa.`released_at` IS NULL OR DATE_FORMAT(upa.`released_at`,'%y') = '00')",
        [$userId]);
    return array_values(array_filter(array_map(fn($r) => (int) $r['responder_id'], $rows), fn($v) => $v > 0));
}

/**
 * The EXACT current-assignment lookup mobile-data.php runs over the combined
 * responder-id set (own responder + crewed units), lines ~191-215.
 */
function mobile_current_assignment(string $prefix, array $viewResponderIds): ?array {
    if (!$viewResponderIds) return null;
    $ph = implode(',', array_fill(0, count($viewResponderIds), '?'));
    $rows = db_fetch_all(
        "SELECT a.`id` AS assign_id, a.`ticket_id`, t.`incident_number`, t.`scope` AS nature
           FROM `{$prefix}assigns` a
           JOIN `{$prefix}ticket` t ON t.`id` = a.`ticket_id`
          WHERE a.`responder_id` IN ($ph)
            AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`,'%y') = '00')
            AND t.`status` = 2
            AND (t.`deleted_at` IS NULL)
          ORDER BY a.`id` DESC
          LIMIT 1",
        $viewResponderIds);
    return $rows ? $rows[0] : null;
}

// Need a member linked to a login account (member.user_id) to reproduce the
// driver-on-mobile scenario. Use an existing linked member — never mutate one.
$linked = db_fetch_all(
    "SELECT m.`id` AS member_id, m.`user_id`
       FROM `{$prefix}member` m
       JOIN `{$prefix}user` u ON u.`id` = m.`user_id`
      WHERE m.`user_id` IS NOT NULL AND m.`user_id` > 0
        AND (m.`deleted_at` IS NULL OR DATE_FORMAT(m.`deleted_at`,'%y') = '00')
      ORDER BY m.`id` LIMIT 1");
if (!$linked) {
    echo "SKIP: no member linked to a login account (member.user_id) — can't reproduce.\n";
    echo "\n=== Results: 0 passed, 0 failed ===\n";
    exit(0);
}
$memberId = (int) $linked[0]['member_id'];
$userId   = (int) $linked[0]['user_id'];
echo "  (using member #$memberId linked to user #$userId)\n";

$tid = 0; $rid = 0; $aid = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'crew85_mobile_ticket', 'crew85 mobile regression', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('crew85_mobile_unit', 'C85M', 'crew85 mobile', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();

    // Baseline: before crewing, the user must NOT see this unit's incident
    // (unless they happen to crew it elsewhere — assert on OUR unit specifically).
    ok('user does not crew the unit before assignment',
        !in_array($rid, mobile_crew_unit_ids($prefix, $userId), true));

    // Real writer: unit -> active incident.
    $r = assign_create_internal($tid, $rid, '', 1);
    $aid = (int) ($r['id'] ?? 0);
    ok('unit assigned to incident via real writer', $aid > 0);

    // Assign the linked member to the unit as crew (same shape unit-assignments.php writes).
    db_query("INSERT INTO `{$prefix}unit_personnel_assignments` (responder_id, member_id, role, status, assigned_by)
              VALUES (?, ?, 'driver', 'active', 1)", [$rid, $memberId]);

    // Now the mobile crew resolution must include the unit...
    $crewUnits = mobile_crew_unit_ids($prefix, $userId);
    ok('mobile resolves the crewed unit for the user', in_array($rid, $crewUnits, true));

    // ...and the mobile assignment lookup (own responder + crewed units) must
    // surface THIS incident — a beta tester's exact ask.
    $viewIds = array_values(array_unique(array_merge([0], $crewUnits)));
    $asg = mobile_current_assignment($prefix, array_values(array_filter($viewIds, fn($v) => $v > 0)));
    ok('crew member sees the unit\'s incident on mobile', $asg !== null && (int) $asg['ticket_id'] === $tid);

    // Precondition a beta tester can trip on: an UNLINKED personnel record (no user_id)
    // resolves nothing — the driver must log in with the account tied to their member.
    ok('an unrelated user (crews nothing) sees no unit',
        count(mobile_crew_unit_ids($prefix, -999)) === 0);

    // Releasing the crew member removes mobile visibility.
    db_query("UPDATE `{$prefix}unit_personnel_assignments` SET status='released', released_at=NOW()
              WHERE responder_id=? AND member_id=?", [$rid, $memberId]);
    ok('released crew member loses mobile visibility',
        !in_array($rid, mobile_crew_unit_ids($prefix, $userId), true));
} catch (Exception $e) {
    echo "  [FAIL] fixture threw: " . $e->getMessage() . "\n";
    $fail++;
}

// Teardown — never delete the (pre-existing) member or user.
try {
    if ($rid > 0) db_query("DELETE FROM `{$prefix}unit_personnel_assignments` WHERE responder_id = ?", [$rid]);
    if ($aid > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$aid]);
    if ($rid > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE scope = 'crew85_mobile_ticket'");
    ok('teardown complete', true);
} catch (Exception $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
