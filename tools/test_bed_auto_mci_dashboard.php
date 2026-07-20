<?php
/**
 * GH #20 round 4 (a beta tester 2026-07-17) — MCI bed-count via the DASHBOARD status
 * path, with the REAL-WORLD status config.
 *
 * a beta tester: two units set to Transport, each with a DIFFERENT destination, but
 * "reduced both beds at only one destination." The existing MCI test (Test 8 in
 * test_bed_auto.php) hand-configured the delivery status with
 * extra_data_target='assignment' AND drove assign_update_status_internal (the
 * incident-detail path, which always writes per-assign) — so it passed while the
 * real flow failed. The real flow is: the dashboard units-widget Status button →
 * responder_set_status_internal, and a dispatcher-created delivery status
 * defaults to extra_data_target='incident'. That routed the facility to the
 * shared ticket.rec_facility (last-write-wins), so bed_auto decremented ONE
 * facility twice.
 *
 * This test reproduces THAT: a delivery status with target='INCIDENT', driven
 * through responder_set_status_internal for two units → two hospitals, asserting
 * each facility decrements independently. Fails without the routing fix
 * (facility is always per-unit); passes with it.
 *
 * Self-skips on a virgin DB missing the bed_auto_mode column.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/responder-write.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== GH #20 MCI via dashboard status path (target=incident) ===\n\n";
$pass = 0; $fail = 0;
function ok($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l\n"; } }

if (empty(db_fetch_all("SHOW COLUMNS FROM `{$prefix}facilities` LIKE 'bed_auto_mode'"))) {
    echo "SKIP: facilities.bed_auto_mode missing (pre-Phase-103 DB).\n\n=== Results: 0 passed, 0 failed ===\n";
    exit(0);
}

$fidA = 0; $fidB = 0; $tid = 0; $ridA = 0; $ridB = 0; $stat = 0;
try {
    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('p20d_fac_A', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $fidA = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('p20d_fac_B', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $fidB = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('p20d_unit_A', 'P20DA', 'test', 1, NOW(), NOW())");
    $ridA = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('p20d_unit_B', 'P20DB', 'test', 1, NOW(), NOW())");
    $ridB = (int) db_insert_id();

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'p20d_ticket', 'MCI dashboard fixture', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();

    // The KEY: a delivery status carrying a facility, routed to the INCIDENT —
    // exactly what a dispatcher-created transport status defaults to.
    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par, extra_data_type,
               extra_data_required, extra_data_target, bed_delivery)
              VALUES ('p20d Transport', 'MCI dashboard test', 0, 0, 'n', 'n', 'busy', 98,
                      'transparent', '#000000', '', 0, 'facility', 0, 'incident', 1)");
    $stat = (int) db_insert_id();

    $ra = assign_create_internal($tid, $ridA, '', 1);
    $rb = assign_create_internal($tid, $ridB, '', 1);
    ok('both units assigned via real writer', (int)($ra['id'] ?? 0) > 0 && (int)($rb['id'] ?? 0) > 0);
    $aidA = (int) $ra['id']; $aidB = (int) $rb['id'];

    // Drive the DASHBOARD path: unit A -> Transport, destination A; unit B -> B.
    responder_set_status_internal($ridA, $stat, 1, '', ['value' => $fidA]);
    responder_set_status_internal($ridB, $stat, 1, '', ['value' => $fidB]);

    // The facility must land on EACH unit's own assignment, not the shared incident.
    $facA = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidA]);
    $facB = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidB]);
    ok('unit A assign destination == facility A', $facA === $fidA);
    ok('unit B assign destination == facility B', $facB === $fidB);
    ok('the two per-unit destinations differ', $facA !== $facB && $facA > 0 && $facB > 0);

    // Each facility decremented INDEPENDENTLY by exactly 1 (a beta tester's bug: both -1 on one).
    $fa = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$fidA]);
    $fb = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$fidB]);
    ok('facility A beds_a 10->9', (int)$fa['beds_a'] === 9);
    ok('facility B beds_a 10->9', (int)$fb['beds_a'] === 9);
    ok('facility A beds_o 0->1',  (int)$fa['beds_o'] === 1);
    ok('facility B beds_o 0->1',  (int)$fb['beds_o'] === 1);

    // The incident-level ticket.rec_facility must NOT have been clobbered by the facility.
    $tRec = (int) db_fetch_value("SELECT rec_facility FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    ok('incident-level rec_facility left untouched (facility is per-unit)', $tRec === 0);
} catch (Exception $e) {
    echo "  [FAIL] fixture threw: " . $e->getMessage() . "\n"; $fail++;
}

// Teardown.
try {
    if ($ridA > 0 || $ridB > 0) db_query("DELETE FROM `{$prefix}facility_bed_auto_log` WHERE responder_id IN (?, ?)", [$ridA, $ridB]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($ridA > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridA]);
    if ($ridB > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridB]);
    if ($fidA > 0) db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$fidA]);
    if ($fidB > 0) db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$fidB]);
    if ($stat > 0) db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$stat]);
    ok('teardown complete', true);
} catch (Exception $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
