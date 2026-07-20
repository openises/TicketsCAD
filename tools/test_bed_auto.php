<?php
/**
 * Phase 103 (a beta tester GH #20) — Facility bed-count automation tests.
 *
 * Verifies:
 *   1. Delivery-status pattern matcher includes the six canonical
 *      "arrived/delivered" statuses and rejects everything else.
 *   2. Manual mode is a no-op on status change.
 *   3. Auto mode decrements beds_a / increments beds_o once per
 *      (assign_id, facility_id) pair, then dedupes.
 *   4. audit_log row + facility_bed_auto_log row are written when
 *      the automation fires.
 *   5. The helper fails soft when the responder has no open assigns.
 *
 * Test isolation: creates its own facility + responder + ticket +
 * assign, tears them down at the end. No permanent state changes.
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';   // Phase 116 — MCI test uses the real assign writer
require_once __DIR__ . '/../inc/bed_auto.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Facility bed-count automation (Phase 103) ===\n\n";

$pass = 0;
$fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

// --- Test 1: pattern matcher ---
echo "1. Pattern matcher\n";
foreach (['At Facility', 'At Hospital', 'Delivered', 'Patient Delivered',
          'Arrived', 'Transfer of Care', 'AT FACILITY', 'at facility'] as $s) {
    ok("'$s' recognized",       _bed_auto_is_delivery_status($s));
}
foreach (['Available', 'Busy', 'Dispatched', 'Responding', 'Out of Service',
          '', 'Unknown', 'Clear'] as $s) {
    ok("'$s' rejected",         !_bed_auto_is_delivery_status($s));
}

// --- Fixtures ---
echo "\n2. Setting up fixtures\n";
try {
    db_query("INSERT INTO {$prefix}facilities (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('bed_auto_test_facility', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $fid = (int) db_insert_id();
    db_query("INSERT INTO {$prefix}responder (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('bed_auto_test_unit', 'BAT1', 'test', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();
    // Use latest existing ticket, or self-seed a minimal one — a fresh
    // install has zero tickets and 'Ticket #0' broke every fixture below.
    $tid = (int) db_fetch_value("SELECT id FROM {$prefix}ticket ORDER BY id DESC LIMIT 1");
    if ($tid <= 0) {
        $typeId = (int) db_fetch_value("SELECT id FROM {$prefix}in_types ORDER BY id LIMIT 1");
        db_query("INSERT INTO {$prefix}ticket (in_types_id, status, severity, scope, description, date, problemstart, _by)
                  VALUES (?, 2, 0, 'bed_auto_test_ticket', 'bed-auto fixture', NOW(), NOW(), 1)", [$typeId]);
        $tid = (int) db_insert_id();
    }
    db_query("INSERT INTO {$prefix}assigns (ticket_id, responder_id, user_id, rec_facility_id, as_of, status_id)
              VALUES (?, ?, 1, ?, NOW(), 1)", [$tid, $rid, $fid]);
    $aid = (int) db_insert_id();

    // Resolve status ids dynamically — the old hardcoded 8/'At Facility'
    // and 1/'Available' only matched training's seed. Create fixture
    // statuses when the install doesn't have them (cleaned up below).
    $createdStatuses = [];
    $stAtFac = (int) db_fetch_value(
        "SELECT id FROM {$prefix}un_status WHERE LOWER(status_val) = 'at facility' LIMIT 1");
    if (!$stAtFac) {
        db_query("INSERT INTO {$prefix}un_status
                  (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
                   bg_color, text_color, incident_action, resets_par, extra_data_type,
                   extra_data_required, extra_data_target, bed_delivery)
                  VALUES ('At Facility', 'bed-auto test fixture', 0, 0, 'n', 'n', 'busy', 98,
                          'transparent', '#000000', '', 0, 'none', 0, 'action_log', 1)");
        $stAtFac = (int) db_insert_id();
        $createdStatuses[] = $stAtFac;
    }
    $stAvail = (int) db_fetch_value(
        "SELECT id FROM {$prefix}un_status
         WHERE LOWER(`group`) LIKE 'av%' OR LOWER(status_val) LIKE 'avail%'
         ORDER BY (LOWER(`group`) LIKE 'av%') DESC, id LIMIT 1");
    if (!$stAvail) $stAvail = 1;

    echo "  Facility #$fid, Responder #$rid, Ticket #$tid, Assign #$aid, AtFac status #$stAtFac\n";
    ok('setup completes', $fid > 0 && $rid > 0 && $tid > 0 && $aid > 0 && $stAtFac > 0);
} catch (Exception $e) {
    echo "  Setup failed: " . $e->getMessage() . "\n"; exit(1);
}

$auditBefore = 0;
try {
    $auditBefore = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}audit_log WHERE entity_type='facility' AND entity_id=?", [$fid]);
} catch (Exception $e) { /* audit_log absent on some installs */ }

// --- Test 3: AUTO mode fires on At Facility status ---
echo "\n3. Auto mode — decrement on 'At Facility'\n";
$r = responder_set_status_internal($rid, $stAtFac, 1);
$after = db_fetch_one("SELECT beds_a, beds_o FROM {$prefix}facilities WHERE id = ?", [$fid]);
ok('beds_a decremented to 9', (int)$after['beds_a'] === 9);
ok('beds_o incremented to 1', (int)$after['beds_o'] === 1);
ok('bed_auto.applied == 1',   ($r['bed_auto']['applied'] ?? 0) === 1);

$logRow = db_fetch_one("SELECT delta_a, delta_o, status_val FROM {$prefix}facility_bed_auto_log
                       WHERE assign_id = ? AND facility_id = ?", [$aid, $fid]);
ok('facility_bed_auto_log row written', $logRow !== null && $logRow !== false);
ok('log delta_a == -1',                 (int)($logRow['delta_a'] ?? 0) === -1);
ok('log delta_o == +1',                 (int)($logRow['delta_o'] ?? 0) === +1);

try {
    $auditAfter = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}audit_log WHERE entity_type='facility' AND entity_id=?", [$fid]);
    ok('audit_log entry written', $auditAfter > $auditBefore);
} catch (Exception $e) {
    echo "  [SKIP] audit_log test — table absent\n";
}

// --- Test 4: dedup on repeated fire ---
echo "\n4. Auto mode — dedup on repeated status change\n";
responder_set_status_internal($rid, $stAvail, 1);  // Available
$r3 = responder_set_status_internal($rid, $stAtFac, 1); // At Facility again
$after2 = db_fetch_one("SELECT beds_a, beds_o FROM {$prefix}facilities WHERE id = ?", [$fid]);
ok('beds_a still 9 after retry',  (int)$after2['beds_a'] === 9);
ok('beds_o still 1 after retry',  (int)$after2['beds_o'] === 1);
ok('bed_auto.applied == 0 (dedup)', ($r3['bed_auto']['applied'] ?? -1) === 0);

// --- Test 5: MANUAL mode is a no-op ---
echo "\n5. Manual mode — no automatic adjustment\n";
db_query("UPDATE {$prefix}facilities SET bed_auto_mode='manual', beds_a='10', beds_o='0' WHERE id = ?", [$fid]);
db_query("DELETE FROM {$prefix}facility_bed_auto_log WHERE facility_id = ?", [$fid]);
db_query("DELETE FROM {$prefix}assigns WHERE id = ?", [$aid]);
db_query("INSERT INTO {$prefix}assigns (ticket_id, responder_id, user_id, rec_facility_id, as_of, status_id)
          VALUES (?, ?, 1, ?, NOW(), 1)", [$tid, $rid, $fid]);
$aid2 = (int) db_insert_id();
$r4 = responder_set_status_internal($rid, $stAtFac, 1);
$after3 = db_fetch_one("SELECT beds_a, beds_o FROM {$prefix}facilities WHERE id = ?", [$fid]);
ok('beds_a still 10 in manual', (int)$after3['beds_a'] === 10);
ok('beds_o still 0 in manual',  (int)$after3['beds_o'] === 0);
ok('reasons include mode_manual',
    in_array('facility_' . $fid . '_mode_manual', $r4['bed_auto']['reasons'] ?? [], true));

// --- Test 6: helper is safe when responder has no open assigns ---
echo "\n6. Helper fails soft on missing assigns\n";
$r5 = bed_auto_apply_on_status_change(999999, $stAtFac, 'At Facility', 1);
ok('no exception thrown', is_array($r5));
ok('applied == 0',        ($r5['applied'] ?? -1) === 0);

// --- Test 7: REAL-WORLD state — facility set on the INCIDENT, not the
// assign (GH #20 round 3, a beta tester 2026-07-15). No dispatch UI writes
// assigns.rec_facility_id; the dispatcher sets ticket.rec_facility on the
// incident form. This is the exact state every real install has, and the
// automation MUST fire from it. Prior tests all hand-inserted
// rec_facility_id into the assign, so they never exercised this path —
// which is precisely why the "still not working" bug survived 32 tests. ---
echo "\n7. Real-world — receiving facility on the incident (assigns.rec_facility_id NULL)\n";
$fid2 = 0; $tid2 = 0; $aid3 = 0;
try {
    // Reset the unit to Available FIRST — Available closes any open
    // assignment (so the following AtFac is a real transition), and it
    // must run BEFORE the fresh assign is created or it would clear it.
    responder_set_status_internal($rid, $stAvail, 1);

    db_query("INSERT INTO {$prefix}facilities (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('bed_auto_test_facility2', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $fid2 = (int) db_insert_id();
    $typeId2 = (int) db_fetch_value("SELECT id FROM {$prefix}in_types ORDER BY id LIMIT 1");
    db_query("INSERT INTO {$prefix}ticket (in_types_id, status, severity, scope, description, rec_facility, date, problemstart, _by)
              VALUES (?, 2, 0, 'bed_auto_test_ticket', 'bed-auto incident-facility fixture', ?, NOW(), NOW(), 1)",
             [$typeId2, $fid2]);
    $tid2 = (int) db_insert_id();
    // NOTE: rec_facility_id deliberately OMITTED — mirrors the real
    // assignment writer (inc/assignment-write.php), which never sets it.
    db_query("INSERT INTO {$prefix}assigns (ticket_id, responder_id, user_id, as_of, status_id)
              VALUES (?, ?, 1, NOW(), 1)", [$tid2, $rid]);
    $aid3 = (int) db_insert_id();

    // sanity: the assign really has no facility of its own
    $assignFac = db_fetch_value("SELECT rec_facility_id FROM {$prefix}assigns WHERE id = ?", [$aid3]);
    ok('assign.rec_facility_id is NULL (as the real writer leaves it)', $assignFac === null || (int)$assignFac === 0);

    $r6 = responder_set_status_internal($rid, $stAtFac, 1);  // delivery status
    $after4 = db_fetch_one("SELECT beds_a, beds_o FROM {$prefix}facilities WHERE id = ?", [$fid2]);
    ok('beds_a decremented to 9 from incident facility', (int)$after4['beds_a'] === 9);
    ok('beds_o incremented to 1 from incident facility',  (int)$after4['beds_o'] === 1);
    ok('bed_auto.applied == 1 via ticket.rec_facility',   ($r6['bed_auto']['applied'] ?? 0) === 1);
} catch (Exception $e) {
    echo "  [FAIL] real-world fixture threw: " . $e->getMessage() . "\n";
    $fail++;
}

// --- Test 8: PER-UNIT receiving facility — the MCI scenario, through the REAL
// writer (Phase 116). Two units on ONE incident, each transported to a DIFFERENT
// facility. This is the capability the legacy app carried and the NewUI rewrite
// had dropped. assign_update_status_internal() (the incident-detail per-unit
// status change) now captures each unit's destination into assigns.rec_facility_id
// AND fires bed_auto for it, so each facility decrements independently. Driven
// through assign_create_internal + assign_update_status_internal — the real
// dispatch writer, NOT hand-seeded rec_facility_id (which is what let the original
// bug hide behind 32 green tests). ---
echo "\n8. Per-unit receiving facility — MCI: two units, two hospitals (Phase 116)\n";
$mFidA = 0; $mFidB = 0; $mTid = 0; $mRidA = 0; $mRidB = 0; $mAidA = 0; $mAidB = 0; $mStat = 0;
try {
    // Two auto-mode destination facilities.
    db_query("INSERT INTO {$prefix}facilities (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('bed_auto_mci_fac_A', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $mFidA = (int) db_insert_id();
    db_query("INSERT INTO {$prefix}facilities (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('bed_auto_mci_fac_B', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $mFidB = (int) db_insert_id();

    // Two units.
    db_query("INSERT INTO {$prefix}responder (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('bed_auto_mci_unit_A', 'MCIA', 'test', 1, NOW(), NOW())");
    $mRidA = (int) db_insert_id();
    db_query("INSERT INTO {$prefix}responder (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('bed_auto_mci_unit_B', 'MCIB', 'test', 1, NOW(), NOW())");
    $mRidB = (int) db_insert_id();

    // One incident — NO incident-level rec_facility; destinations are per-unit.
    $typeId3 = (int) db_fetch_value("SELECT id FROM {$prefix}in_types ORDER BY id LIMIT 1");
    db_query("INSERT INTO {$prefix}ticket (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'bed_auto_test_ticket', 'MCI fixture', NOW(), NOW(), 1)", [$typeId3]);
    $mTid = (int) db_insert_id();

    // A delivery status that carries a facility ROUTED TO THE ASSIGNMENT.
    db_query("INSERT INTO {$prefix}un_status
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par, extra_data_type,
               extra_data_required, extra_data_target, bed_delivery)
              VALUES ('At Facility (dest)', 'MCI test fixture', 0, 0, 'n', 'n', 'busy', 97,
                      'transparent', '#000000', '', 0, 'facility', 0, 'assignment', 1)");
    $mStat = (int) db_insert_id();

    // Assign BOTH units to the incident via the REAL writer.
    $ra = assign_create_internal($mTid, $mRidA, '', 1);
    $rb = assign_create_internal($mTid, $mRidB, '', 1);
    $mAidA = (int) ($ra['id'] ?? 0);
    $mAidB = (int) ($rb['id'] ?? 0);
    ok('both units assigned via real writer', $mAidA > 0 && $mAidB > 0);

    // Unit A → delivery status, destination = facility A (the real incident-detail
    // path: input carries extra_data facility via the endpoint's GLOBALS bridge).
    $GLOBALS['_assign_update_status_input'] = ['extra_data' => ['type' => 'facility', 'value' => $mFidA]];
    assign_update_status_internal($mAidA, $mStat, 1);
    unset($GLOBALS['_assign_update_status_input']);

    // Unit B → delivery status, destination = facility B.
    $GLOBALS['_assign_update_status_input'] = ['extra_data' => ['type' => 'facility', 'value' => $mFidB]];
    assign_update_status_internal($mAidB, $mStat, 1);
    unset($GLOBALS['_assign_update_status_input']);

    // Each assign carries its OWN destination.
    $facA = (int) db_fetch_value("SELECT rec_facility_id FROM {$prefix}assigns WHERE id = ?", [$mAidA]);
    $facB = (int) db_fetch_value("SELECT rec_facility_id FROM {$prefix}assigns WHERE id = ?", [$mAidB]);
    ok('unit A assign destination == facility A', $facA === $mFidA);
    ok('unit B assign destination == facility B', $facB === $mFidB);
    ok('the two destinations differ',             $facA !== $facB && $facA > 0 && $facB > 0);

    // Each facility decremented INDEPENDENTLY by exactly 1.
    $fa = db_fetch_one("SELECT beds_a, beds_o FROM {$prefix}facilities WHERE id = ?", [$mFidA]);
    $fb = db_fetch_one("SELECT beds_a, beds_o FROM {$prefix}facilities WHERE id = ?", [$mFidB]);
    ok('facility A beds_a 10->9', (int)$fa['beds_a'] === 9);
    ok('facility A beds_o 0->1',  (int)$fa['beds_o'] === 1);
    ok('facility B beds_a 10->9', (int)$fb['beds_a'] === 9);
    ok('facility B beds_o 0->1',  (int)$fb['beds_o'] === 1);

    // Two distinct bed_auto_log rows, one per (assign, facility) pair.
    $logA = db_fetch_value("SELECT COUNT(*) FROM {$prefix}facility_bed_auto_log WHERE assign_id = ? AND facility_id = ?", [$mAidA, $mFidA]);
    $logB = db_fetch_value("SELECT COUNT(*) FROM {$prefix}facility_bed_auto_log WHERE assign_id = ? AND facility_id = ?", [$mAidB, $mFidB]);
    ok('bed_auto_log row for unit A -> facility A', (int)$logA === 1);
    ok('bed_auto_log row for unit B -> facility B', (int)$logB === 1);
} catch (Exception $e) {
    echo "  [FAIL] MCI fixture threw: " . $e->getMessage() . "\n";
    $fail++;
}

// --- Cleanup ---
echo "\n9. Teardown\n";
try {
    db_query("DELETE FROM {$prefix}assigns WHERE id IN (?, ?, ?, ?, ?)", [$aid, $aid2, $aid3, $mAidA, $mAidB]);
    db_query("DELETE FROM {$prefix}facility_bed_auto_log WHERE facility_id IN (?, ?, ?, ?)", [$fid, $fid2, $mFidA, $mFidB]);
    db_query("DELETE FROM {$prefix}responder WHERE id IN (?, ?, ?)", [$rid, $mRidA, $mRidB]);
    db_query("DELETE FROM {$prefix}facilities WHERE id IN (?, ?, ?, ?)", [$fid, $fid2, $mFidA, $mFidB]);
    foreach ($createdStatuses as $sid) {
        db_query("DELETE FROM {$prefix}un_status WHERE id = ?", [$sid]);
    }
    if ($mStat > 0) db_query("DELETE FROM {$prefix}un_status WHERE id = ?", [$mStat]);
    db_query("DELETE FROM {$prefix}ticket WHERE scope = 'bed_auto_test_ticket'");
    ok('teardown complete', true);
} catch (Exception $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
