<?php
/**
 * GH #85 — crew-on-incident regression test.
 *
 * When a unit with assigned crew (unit_personnel_assignments) is dispatched to
 * an incident (assigns), the crew must surface on the incident for accountability.
 * api/incident-detail.php attaches each active unit's crew via a batched join.
 * This test reproduces the real state — member assigned to a unit via the real
 * assignment shape + unit assigned to the incident via assign_create_internal
 * (the real writer) — and asserts the SAME crew query the endpoint uses returns
 * the crew, so the display can't silently regress.
 *
 * Self-skips on a virgin DB that has fewer than 2 members to attach.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== GH #85 crew-on-incident ===\n\n";

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

// The exact crew query api/incident-detail.php runs for an incident's units.
function crew_for_incident(string $prefix, int $ticketId): array {
    $rids = array_map(
        fn($r) => (int) $r['responder_id'],
        db_fetch_all(
            "SELECT a.responder_id FROM `{$prefix}assigns` a
              WHERE a.ticket_id = ? AND (a.clear IS NULL OR DATE_FORMAT(a.clear,'%y') = '00')",
            [$ticketId])
    );
    if (!$rids) return [];
    $ph = implode(',', array_fill(0, count($rids), '?'));
    return db_fetch_all(
        "SELECT upa.responder_id, m.id AS member_id, m.first_name, m.last_name, m.callsign, upa.role
           FROM `{$prefix}unit_personnel_assignments` upa
           JOIN `{$prefix}member` m ON m.id = upa.member_id
          WHERE upa.responder_id IN ($ph)
            AND upa.status IN ('active','standby')
            AND (upa.released_at IS NULL OR DATE_FORMAT(upa.released_at,'%y') = '00')
          ORDER BY FIELD(upa.role,'commander','operator','driver','medic','observer','trainee','support'),
                   m.last_name, m.first_name",
        $rids);
}

$mem = db_fetch_all(
    "SELECT id FROM `{$prefix}member`
      WHERE (deleted_at IS NULL OR DATE_FORMAT(deleted_at,'%y') = '00') ORDER BY id LIMIT 2");
if (count($mem) < 2) {
    echo "SKIP: needs >= 2 members (found " . count($mem) . ") — virgin DB.\n";
    echo "\n=== Results: 0 passed, 0 failed ===\n";
    exit(0);
}
$m1 = (int) $mem[0]['id']; $m2 = (int) $mem[1]['id'];

$tid = 0; $rid = 0; $aid = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'crew85_test_ticket', 'crew85 regression', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('crew85_unit', 'C85U', 'crew85', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();

    // Real writer: unit -> incident.
    $r = assign_create_internal($tid, $rid, '', 1);
    $aid = (int) ($r['id'] ?? 0);
    ok('unit assigned to incident via real writer', $aid > 0);

    // Before crew: no crew on the incident.
    ok('no crew before assignment', count(crew_for_incident($prefix, $tid)) === 0);

    // Assign two members to the unit (same shape api/unit-assignments.php writes).
    db_query("INSERT INTO `{$prefix}unit_personnel_assignments` (responder_id, member_id, role, status, assigned_by)
              VALUES (?, ?, 'commander', 'active', 1)", [$rid, $m1]);
    db_query("INSERT INTO `{$prefix}unit_personnel_assignments` (responder_id, member_id, role, status, assigned_by)
              VALUES (?, ?, 'driver', 'active', 1)", [$rid, $m2]);

    $crew = crew_for_incident($prefix, $tid);
    ok('crew surfaces on the incident (2 members)', count($crew) === 2);
    ok('commander sorts first', ($crew[0]['role'] ?? '') === 'commander');
    ok('driver second',         ($crew[1]['role'] ?? '') === 'driver');
    ok('crew member ids match the assigned members',
        in_array($m1, array_column($crew, 'member_id')) && in_array($m2, array_column($crew, 'member_id')));

    // Releasing a crew member drops them from the incident view.
    db_query("UPDATE `{$prefix}unit_personnel_assignments` SET status='released', released_at=NOW()
              WHERE responder_id=? AND member_id=?", [$rid, $m1]);
    ok('released crew member drops off', count(crew_for_incident($prefix, $tid)) === 1);
} catch (Exception $e) {
    echo "  [FAIL] fixture threw: " . $e->getMessage() . "\n";
    $fail++;
}

// Teardown — never delete the (pre-existing) members.
try {
    if ($rid > 0) db_query("DELETE FROM `{$prefix}unit_personnel_assignments` WHERE responder_id = ?", [$rid]);
    if ($aid > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$aid]);
    if ($rid > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE scope = 'crew85_test_ticket'");
    ok('teardown complete', true);
} catch (Exception $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
