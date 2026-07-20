<?php
/**
 * Phase 115 — Zone Coverage (GH #64) tests.
 *
 * Exercises the two endpoints' load-bearing queries with controlled data, plus
 * the RBAC grants, without booting the HTTP/auth stack:
 *   1. RBAC: screen.zone_coverage granted to all 6 roles; action.set_own_zone
 *      to 1,2,3,4,6 but NOT Read-Only (5).
 *   2. Coverage aggregation buckets active units into zones + an unassigned
 *      bucket (the exact query api/zone-coverage.php runs).
 *   3. Self-report SELF-SCOPE: the "resolve my own unit" query (used by both
 *      api/zone-coverage.php's `me` and api/zone-self-report.php) returns the
 *      caller's own assignment via the personnel link, and returns NOTHING for
 *      a stranger — so a volunteer can never move another unit.
 *   4. Auto-pick: the events-with-zones query surfaces the active event.
 *
 * Self-skips if event_zones / assigns.current_zone_id aren't present (pre-Phase
 * 109). Idempotent temp data, cleaned up in finally.
 *
 * Usage: php tests/test_zone_coverage.php
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

// Preconditions — need Phase 109 zone schema.
$hasZones = false; $hasZoneCol = false;
try {
    db_fetch_value("SELECT 1 FROM `{$prefix}event_zones` LIMIT 1");
    $hasZones = true;
    $hasZoneCol = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'current_zone_id'",
        [$prefix . 'assigns']);
} catch (Exception $e) { /* pre-109 */ }

if (!$hasZones || !$hasZoneCol) {
    echo "SKIP: Phase 109 zone schema not present (event_zones / assigns.current_zone_id)\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// ── 1. RBAC grants ──
function _zct_roles_for(string $prefix, string $code): array {
    $rows = db_fetch_all(
        "SELECT rp.role_id FROM `{$prefix}role_permissions` rp
           JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE p.code = ? ORDER BY rp.role_id", [$code]);
    return array_map(fn($r) => (int) $r['role_id'], $rows);
}
$covRoles = _zct_roles_for($prefix, 'screen.zone_coverage');
test('screen.zone_coverage granted to all six roles',
    $covRoles === [1, 2, 3, 4, 5, 6]);
$ownRoles = _zct_roles_for($prefix, 'action.set_own_zone');
test('action.set_own_zone granted to 1,2,3,4,6 (not Read-Only)',
    $ownRoles === [1, 2, 3, 4, 6]);

// ── Seed a self-contained event + zones + units ──
$cleanup = [];
function track(&$c, $t, $id) { $c[$t][] = (int) $id; }
$TID_ZONE_A = null; $TID_ZONE_B = null;

try {
    // Event (active, status=2). in_types_id/scope/description are required.
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `status`)
         VALUES (0, ?, '', 2)", ['ZoneCov Test Event']);
    $eventId = (int) db_insert_id();
    track($cleanup, 'ticket', $eventId);

    // Three zones.
    $zoneIds = [];
    foreach ([['Zone A', 'A'], ['Zone B', 'B'], ['Zone C', 'C']] as $i => $z) {
        db_query(
            "INSERT INTO `{$prefix}event_zones` (`ticket_id`, `name`, `code`, `sort_order`)
             VALUES (?, ?, ?, ?)", [$eventId, $z[0], $z[1], $i]);
        $zid = (int) db_insert_id();
        $zoneIds[$z[1]] = $zid;
        track($cleanup, 'event_zones', $zid);
    }
    $TID_ZONE_A = $zoneIds['A'];
    $TID_ZONE_B = $zoneIds['B'];

    // Four units: 2 in Zone A, 1 in Zone B, 1 with no zone.
    $placements = [
        ['CS-1', $zoneIds['A']],
        ['CS-2', $zoneIds['A']],
        ['CS-3', $zoneIds['B']],
        ['CS-4', null],
    ];
    foreach ($placements as $pl) {
        db_query("INSERT INTO `{$prefix}responder` (`name`, `description`, `handle`) VALUES (?, '', ?)",
            ['Unit ' . $pl[0], $pl[0]]);
        $rid = (int) db_insert_id();
        track($cleanup, 'responder', $rid);
        db_query(
            "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `current_zone_id`, `clear`)
             VALUES (?, ?, 0, ?, NULL)", [$eventId, $rid, $pl[1]]);
        track($cleanup, 'assigns', db_insert_id());
    }

    // ── 2. Coverage aggregation — the exact bucketing api/zone-coverage.php does. ──
    $unitRows = db_fetch_all(
        "SELECT a.`current_zone_id`
         FROM `{$prefix}assigns` a
         WHERE a.`ticket_id` = ?
           AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')",
        [$eventId]);
    $counts = ['A' => 0, 'B' => 0, 'unassigned' => 0];
    foreach ($unitRows as $u) {
        $zid = (int) ($u['current_zone_id'] ?? 0);
        if ($zid === $zoneIds['A'])      $counts['A']++;
        elseif ($zid === $zoneIds['B'])  $counts['B']++;
        else                             $counts['unassigned']++;
    }
    test('Zone A has 2 units', $counts['A'] === 2);
    test('Zone B has 1 unit',  $counts['B'] === 1);
    test('1 unit is unassigned (no zone)', $counts['unassigned'] === 1);

    // ── 3. Self-report self-scope (personnel path) ──
    // A login user who is the active person on one of the units.
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `can_login`) VALUES (?, 'x', 1)",
        ['zct_owner_' . $eventId]);
    $ownerUser = (int) db_insert_id();
    track($cleanup, 'user', $ownerUser);
    db_query("INSERT INTO `{$prefix}member` (`user_id`) VALUES (?)", [$ownerUser]);
    $ownerMember = (int) db_insert_id();
    track($cleanup, 'member', $ownerMember);
    // Attach that member to CS-1's responder (first seeded responder in Zone A).
    $firstRid = $cleanup['responder'][0];
    $firstAssign = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ?",
        [$eventId, $firstRid]);
    db_query(
        "INSERT INTO `{$prefix}unit_personnel_assignments` (`responder_id`, `member_id`, `status`)
         VALUES (?, ?, 'active')", [$firstRid, $ownerMember]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    // The "resolve MY own assign" query (identical to the endpoints').
    $resolveMine = function (int $uid) use ($prefix, $eventId) {
        return db_fetch_one(
            "SELECT a.`id`
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}unit_personnel_assignments` upa
                  ON upa.responder_id = a.`responder_id` AND upa.status IN ('active','standby')
             JOIN `{$prefix}member` m ON m.id = upa.member_id
             WHERE a.`ticket_id` = ? AND m.`user_id` = ?
               AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
             ORDER BY a.`id` ASC LIMIT 1",
            [$eventId, $uid]);
    };
    $mine = $resolveMine($ownerUser);
    test('owner resolves their own assignment (self-report target)',
        $mine && (int) $mine['id'] === $firstAssign);

    // A stranger (some other user id not linked to any unit here) resolves nothing —
    // proving a volunteer can only ever move their OWN unit.
    $strangerUser = 2147480000 + ($eventId % 1000); // an id that isn't our owner
    $strangerHit = $resolveMine($strangerUser);
    test('a stranger resolves NO assignment (cannot move another unit)',
        $strangerHit === null || $strangerHit === false);

    // ── 4. Auto-pick: the events-with-zones query surfaces this active event. ──
    $picked = db_fetch_all(
        "SELECT t.`id`
         FROM `{$prefix}event_zones` z
         JOIN `{$prefix}ticket` t ON t.`id` = z.`ticket_id`
         WHERE (z.`hide` = 0 OR z.`hide` IS NULL)
         GROUP BY t.`id`, t.`status`
         ORDER BY (t.`status` = 2) DESC, t.`id` DESC");
    $pickedIds = array_map(fn($r) => (int) $r['id'], $picked);
    test('auto-pick lists the seeded active event with zones',
        in_array($eventId, $pickedIds, true));

} catch (Throwable $e) {
    echo "[FAIL] setup/exec threw: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    foreach (['unit_personnel_assignments', 'assigns', 'event_zones', 'responder', 'member', 'user', 'ticket'] as $t) {
        foreach (($cleanup[$t] ?? []) as $id) {
            try { db_query("DELETE FROM `{$prefix}{$t}` WHERE `id` = ?", [$id]); }
            catch (Throwable $e) { /* best-effort */ }
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
