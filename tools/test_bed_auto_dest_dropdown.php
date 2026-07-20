<?php
/**
 * GH #20 (a beta tester 2026-07-17) — MCI bed-count via the INCIDENT-DETAIL "Dest"
 * dropdown path, driven through the REAL writer (assign_set_rec_facility).
 *
 * a beta tester reported the two-units-two-hospitals bug on TWO paths:
 *   (A) the dashboard units-widget Status button → responder_set_status_internal
 *       (covered by test_bed_auto_mci_dashboard.php), and
 *   (B) the incident-detail per-unit "Dest" dropdown → api/incident-assign.php
 *       action=set_rec_facility → assign_set_rec_facility().  ← THIS test.
 *
 * Path B had NO regression test, which is exactly how the per-unit routing
 * regressed unnoticed. This drives assign_set_rec_facility() for two units to two
 * DIFFERENT facilities, then moves each unit to a delivery-flagged status with NO
 * facility prompt (a plain status change — the facility already lives on the
 * assignment from the Dest dropdown). Each facility must decrement independently;
 * the bug was "both beds at only one facility."
 *
 * Real-writer discipline (see the project root-cause note): the destination is set
 * ONLY through assign_set_rec_facility() — never hand-inserted into assigns — and
 * the delivery is triggered ONLY through responder_set_status_internal(). No row is
 * seeded into a state the production write path can't itself produce.
 *
 * Self-skips on a virgin DB missing the bed_auto_mode column.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/responder-write.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== GH #20 MCI via incident-detail Dest dropdown (assign_set_rec_facility) ===\n\n";
$pass = 0; $fail = 0;
function ok($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l\n"; } }

if (empty(db_fetch_all("SHOW COLUMNS FROM `{$prefix}facilities` LIKE 'bed_auto_mode'"))) {
    echo "SKIP: facilities.bed_auto_mode missing (pre-Phase-103 DB).\n\n=== Results: 0 passed, 0 failed ===\n";
    exit(0);
}

$fidA = 0; $fidB = 0; $tid = 0; $ridA = 0; $ridB = 0; $stat = 0;
try {
    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('p20b_fac_A', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $fidA = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
              VALUES ('p20b_fac_B', 'test', 0, 0, '10', '0', 'auto', NOW(), 1, NOW())");
    $fidB = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('p20b_unit_A', 'P20BA', 'test', 1, NOW(), NOW())");
    $ridA = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('p20b_unit_B', 'P20BB', 'test', 1, NOW(), NOW())");
    $ridB = (int) db_insert_id();

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'p20b_ticket', 'MCI Dest-dropdown fixture', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();

    // A delivery status with NO extra-data prompt (extra_data_type='none') — the
    // dispatcher already set each unit's Dest via the incident-detail dropdown, so
    // moving to this status just triggers bed_auto off the per-assign facility.
    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par, extra_data_type,
               extra_data_required, extra_data_target, bed_delivery)
              VALUES ('p20b Delivered', 'Dest-dropdown test', 0, 0, 'n', 'n', 'busy', 95,
                      'transparent', '#000000', '', 0, 'none', 0, 'incident', 1)");
    $stat = (int) db_insert_id();

    $ra = assign_create_internal($tid, $ridA, '', 1);
    $rb = assign_create_internal($tid, $ridB, '', 1);
    ok('both units assigned via real writer', (int)($ra['id'] ?? 0) > 0 && (int)($rb['id'] ?? 0) > 0);
    $aidA = (int) $ra['id']; $aidB = (int) $rb['id'];

    // The incident-detail Dest dropdown write path (per-unit). Cross-assign the
    // destinations (A→facB, B→facA) so a "both to one" collapse can't accidentally
    // pass.
    assign_set_rec_facility($aidA, $fidB, 1);
    assign_set_rec_facility($aidB, $fidA, 1);
    $rfA = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidA]);
    $rfB = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$aidB]);
    ok('Dest dropdown wrote unit A assign -> facility B', $rfA === $fidB);
    ok('Dest dropdown wrote unit B assign -> facility A', $rfB === $fidA);
    ok('the two per-unit destinations differ', $rfA !== $rfB && $rfA > 0 && $rfB > 0);

    // Deliver: plain status change (no prompt) through the real writer.
    responder_set_status_internal($ridA, $stat, 1, '', null);
    responder_set_status_internal($ridB, $stat, 1, '', null);

    $fa = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$fidA]);
    $fb = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$fidB]);
    ok('facility A beds_a 10->9 (unit B delivered here)', (int)$fa['beds_a'] === 9);
    ok('facility B beds_a 10->9 (unit A delivered here)', (int)$fb['beds_a'] === 9);
    ok('facility A beds_o 0->1', (int)$fa['beds_o'] === 1);
    ok('facility B beds_o 0->1', (int)$fb['beds_o'] === 1);
    ok('each facility decremented exactly once — NOT both on one (the bug)',
        (int)$fa['beds_a'] === 9 && (int)$fb['beds_a'] === 9);
} catch (Exception $e) {
    echo "  [FAIL] fixture threw: " . $e->getMessage() . "\n"; $fail++;
}

// Teardown.
try {
    if ($ridA > 0 || $ridB > 0) db_query("DELETE FROM `{$prefix}facility_bed_auto_log` WHERE responder_id IN (?, ?)", [$ridA, $ridB]);
    if ($tid > 0)  db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
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
