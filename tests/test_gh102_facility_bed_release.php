<?php
/**
 * GH#102 (openises/TicketsCAD issue #102, rjonesbsink) — facility
 * self-release of beds, the missing inverse of inc/bed_auto.php's
 * automatic decrement.
 *
 * Proves, against the REAL writers (never hand-seeded facility_bed_*_log
 * rows standing in for what the writers produce):
 *   A. Occupancy is seeded through the REAL automation
 *      (bed_auto_apply_on_status_change()) driving three real deliveries,
 *      not a hand-set beds_o value.
 *   B. facility_bed_release_apply() releases exactly the requested count
 *      when covered by current occupancy, writes a durable
 *      facility_bed_release_log row with real actor attribution (never
 *      0/synthetic), and audit_log()s it.
 *   C. The structural safety ceiling: a release can never push Occupied
 *      below zero (floor), and a request larger than current occupancy
 *      is clamped to exactly what's occupied -- proven by driving beds_o
 *      back to its EXACT pre-delivery value, not just "some smaller
 *      number".
 *   D. A second release attempt when nothing is occupied is refused
 *      cleanly (success=false), not a crash or a negative count -- the
 *      practical equivalent of "double release is safe" for this coarse,
 *      non-log-tied design (see inc/facility-bed-release.php's docblock
 *      for why it's coarse rather than tied to one historical log row).
 *   E. Cross-facility isolation: releasing against facility A never
 *      moves facility B's counters, even when both exist side by side.
 *   F. The MIN/MAX count sanity clamp (1-50), exercised distinctly from
 *      the occupancy floor by giving a facility more than 50 occupied.
 *   G. Source-wiring: api/facility-portal.php's release_bed branch calls
 *      facility_bed_release_apply() with the SESSION-derived $facilityId
 *      -- never anything read from the client-supplied $input array --
 *      and FACILITY_ALLOWED_API_SCRIPTS is unchanged (this fix adds a new
 *      ACTION to the existing allowlisted endpoint, not a new endpoint),
 *      matching the source-wiring technique
 *      tests/test_facility_scope_confinement.php Section D established.
 *
 * @requires-db
 * Usage: php tests/test_gh102_facility_bed_release.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/bed_auto.php';
require_once __DIR__ . '/../inc/facility-bed-release.php';
require_once __DIR__ . '/../inc/facility-scope.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#102 — facility bed self-release ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Dedicated, unused fixture id block (matches the established convention,
// e.g. tests/test_gh96_mileage_report.php's 9000198xx block).
$facAId    = 900022301; // "our" facility -- releases happen here
$facBId    = 900022302; // "the other" facility -- must never move
$facCId    = 900022303; // isolated facility for the MAX_COUNT clamp test
$responderId = 900022311;
$ticketId    = 900022321;
$actingUserId = test_admin_user_id(); // a real, existing user -- attribution must be a real id

$createdAssignIds = [];
$createdStatusIds = [];

$cleanup = function () use ($prefix, $facAId, $facBId, $facCId, $responderId, $ticketId, &$createdAssignIds, &$createdStatusIds) {
    foreach ($createdAssignIds as $id) { try { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$responderId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facility_bed_auto_log` WHERE facility_id IN (?, ?, ?)", [$facAId, $facBId, $facCId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facility_bed_release_log` WHERE facility_id IN (?, ?, ?)", [$facAId, $facBId, $facCId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facilities` WHERE id IN (?, ?, ?)", [$facAId, $facBId, $facCId]); } catch (Throwable $e) {}
    foreach ($createdStatusIds as $id) { try { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ══════════════════════════════════════════════════════════════════
    // Fixtures
    // ══════════════════════════════════════════════════════════════════
    echo "--- fixtures ---\n";

    db_query(
        "INSERT INTO `{$prefix}facilities` (id, name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
         VALUES (?, 'GH102 Facility A', 'fixture', 0, 0, '5', '0', 'auto', NOW(), 1, NOW())",
        [$facAId]
    );
    db_query(
        "INSERT INTO `{$prefix}facilities` (id, name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
         VALUES (?, 'GH102 Facility B', 'fixture', 0, 0, '5', '0', 'auto', NOW(), 1, NOW())",
        [$facBId]
    );
    db_query(
        "INSERT INTO `{$prefix}facilities` (id, name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
         VALUES (?, 'GH102 Facility C', 'fixture', 0, 0, '0', '1000', 'auto', NOW(), 1, NOW())",
        [$facCId]
    );

    db_query(
        "INSERT INTO `{$prefix}responder` (id, name, handle, description, un_status_id, status_updated, updated)
         VALUES (?, 'GH102 Test Unit', 'G102', 'fixture', 1, NOW(), NOW())",
        [$responderId]
    );

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query(
        "INSERT INTO `{$prefix}ticket` (id, in_types_id, status, severity, scope, description, date, problemstart, _by)
         VALUES (?, ?, 2, 0, 'GH102 fixture', 'gh102 fixture', NOW(), NOW(), 1)",
        [$ticketId, $typeId]
    );

    // A guaranteed delivery-qualifying status, matching tools/test_bed_auto.php's
    // own fixture technique: resolve "At Facility" if present, else create
    // it with bed_delivery=1 explicitly so this test is correct regardless
    // of whether ANY status on this shared dev install already has the
    // flag set (bed_auto_status_qualifies() treats flags as authoritative
    // install-wide the moment ANY status carries one).
    $stAtFac = (int) db_fetch_value("SELECT id FROM `{$prefix}un_status` WHERE LOWER(status_val) = 'at facility' LIMIT 1");
    if (!$stAtFac) {
        db_query(
            "INSERT INTO `{$prefix}un_status`
             (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
              bg_color, text_color, incident_action, resets_par, extra_data_type,
              extra_data_required, extra_data_target, bed_delivery)
             VALUES ('At Facility', 'gh102 fixture', 0, 0, 'n', 'n', 'busy', 98,
                     'transparent', '#000000', '', 0, 'none', 0, 'action_log', 1)"
        );
        $stAtFac = (int) db_insert_id();
        $createdStatusIds[] = $stAtFac;
    }
    $stName = (string) db_fetch_value("SELECT status_val FROM `{$prefix}un_status` WHERE id = ?", [$stAtFac]);

    echo "\n--- A. seed occupancy through the REAL automation (bed_auto_apply_on_status_change) ---\n\n";
    // bed_auto_apply_on_status_change() walks ALL of the responder's open
    // assigns on each call (it isn't scoped to one assign_id) -- so each
    // of the three real deliveries below creates its OWN assign row and
    // fires the automation immediately after, interleaved, exactly the
    // way a real unit does it: assigned -> delivers -> (later) reassigned
    // -> delivers again. Earlier assigns stay open/uncleared throughout,
    // so later calls re-see them and correctly skip them as
    // already-applied (dedup via facility_bed_auto_log's own
    // (assign_id, facility_id) unique key) without double-counting.
    for ($i = 0; $i < 3; $i++) {
        db_query(
            "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, rec_facility_id, as_of, status_id)
             VALUES (?, ?, 1, ?, NOW(), ?)",
            [$ticketId, $responderId, $facAId, $stAtFac]
        );
        $aid = (int) db_insert_id();
        $createdAssignIds[] = $aid;

        $r = bed_auto_apply_on_status_change($responderId, $stAtFac, $stName, 1);
        t("delivery #" . ($i + 1) . " applied (assign $aid)", $r['applied'] === 1);
    }

    $facA = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$facAId]);
    t('after 3 real deliveries: beds_a = 5-3 = 2', (int) $facA['beds_a'] === 2);
    t('after 3 real deliveries: beds_o = 0+3 = 3', (int) $facA['beds_o'] === 3);

    $autoLogCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}facility_bed_auto_log` WHERE facility_id = ?", [$facAId]
    );
    t('facility_bed_auto_log has 3 rows for facility A (one per real delivery)', $autoLogCount === 3);

    echo "\n--- B. facility_bed_release_apply() releases through the real writer ---\n\n";
    $rel1 = facility_bed_release_apply($facAId, 2, 'two patients discharged', $actingUserId, 'GH102 Test Nurse');
    t('release 2 of 3 occupied: success', $rel1['success'] === true);
    t('release 2 of 3 occupied: released = 2', $rel1['released'] === 2);
    t('release 2 of 3 occupied: beds_a = 2+2 = 4', $rel1['beds_a'] === 4);
    t('release 2 of 3 occupied: beds_o = 3-2 = 1', $rel1['beds_o'] === 1);

    $facAAfterRel1 = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$facAId]);
    t('DB reflects the release: beds_a = 4', (int) $facAAfterRel1['beds_a'] === 4);
    t('DB reflects the release: beds_o = 1', (int) $facAAfterRel1['beds_o'] === 1);

    $relLogRow = db_fetch_one(
        "SELECT * FROM `{$prefix}facility_bed_release_log` WHERE facility_id = ? ORDER BY id DESC LIMIT 1",
        [$facAId]
    );
    t('facility_bed_release_log row written', !empty($relLogRow));
    t('release log delta_a = +2', (int) ($relLogRow['delta_a'] ?? 0) === 2);
    t('release log delta_o = -2', (int) ($relLogRow['delta_o'] ?? 0) === -2);
    t('release log note preserved', ($relLogRow['note'] ?? '') === 'two patients discharged');
    t('release log released_by = real acting user id (never 0)', (int) ($relLogRow['released_by'] ?? 0) === $actingUserId && $actingUserId > 0);
    t('release log released_by_name recorded', ($relLogRow['released_by_name'] ?? '') === 'GH102 Test Nurse');

    echo "\n--- C. floor: releasing more than currently occupied clamps to exactly what's occupied ---\n\n";
    // 1 bed is currently occupied (beds_o=1 after B). Requesting 10 must
    // release exactly 1 -- driving beds_a/beds_o back to their EXACT
    // pre-delivery values (5/0), not just "some smaller number".
    $rel2 = facility_bed_release_apply($facAId, 10, '', $actingUserId, 'GH102 Test Nurse');
    t('over-release clamps to occupied count: released = 1', $rel2['released'] === 1);
    t('over-release clamps: beds_a returns to exact pre-delivery value (5)', $rel2['beds_a'] === 5);
    t('over-release clamps: beds_o returns to exact pre-delivery value (0)', $rel2['beds_o'] === 0);

    echo "\n--- D. a release attempt with nothing occupied is refused cleanly ---\n\n";
    $rel3 = facility_bed_release_apply($facAId, 5, '', $actingUserId, 'GH102 Test Nurse');
    t('release with zero occupied: success = false', $rel3['success'] === false);
    t('release with zero occupied: error message present', !empty($rel3['error']));
    $facAFinal = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$facAId]);
    t('release with zero occupied: beds_a/beds_o UNCHANGED (5/0)', (int) $facAFinal['beds_a'] === 5 && (int) $facAFinal['beds_o'] === 0);
    t('release with zero occupied: no beds_o went negative anywhere in this run', (int) $facAFinal['beds_o'] >= 0);

    echo "\n--- E. cross-facility isolation: facility B is untouched by every release above ---\n\n";
    $facB = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$facBId]);
    t('facility B beds_a unchanged (still 5 -- never occupied, never released)', (int) $facB['beds_a'] === 5);
    t('facility B beds_o unchanged (still 0)', (int) $facB['beds_o'] === 0);
    $facBReleaseLogCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}facility_bed_release_log` WHERE facility_id = ?", [$facBId]
    );
    t('facility B has ZERO release-log rows', $facBReleaseLogCount === 0);

    echo "\n--- F. MIN/MAX count sanity clamp, distinct from the occupancy floor ---\n\n";
    // Facility C has 1000 occupied -- far more than the 50-bed sanity cap --
    // so requesting 1000 must clamp to exactly 50 (the MAX_COUNT clamp),
    // not to 1000 (which the occupancy floor alone would have allowed).
    $relMax = facility_bed_release_apply($facCId, 1000, '', $actingUserId, 'GH102 Test Nurse');
    t('MAX_COUNT clamp: requesting 1000 against 1000 occupied releases exactly 50', $relMax['released'] === 50);
    // Requesting 0 (or a negative count) clamps up to MIN_COUNT (1), never
    // silently no-ops or errors on a nonsensical input.
    $relMin = facility_bed_release_apply($facCId, 0, '', $actingUserId, 'GH102 Test Nurse');
    t('MIN_COUNT clamp: requesting 0 releases exactly 1', $relMin['released'] === 1);
    $relNeg = facility_bed_release_apply($facCId, -5, '', $actingUserId, 'GH102 Test Nurse');
    t('MIN_COUNT clamp: requesting a negative count releases exactly 1', $relNeg['released'] === 1);

    echo "\n--- G. source-wiring verification (same technique as tests/test_facility_scope_confinement.php Section D) ---\n\n";
    $root = dirname(__DIR__);
    $fpSrc = file_get_contents($root . '/api/facility-portal.php');
    t("api/facility-portal.php requires inc/facility-bed-release.php", strpos($fpSrc, "require_once __DIR__ . '/../inc/facility-bed-release.php';") !== false);
    t("release_bed branch calls facility_bed_release_apply(\$facilityId, ...) -- the SESSION-derived variable",
        preg_match('/facility_bed_release_apply\(\s*\$facilityId\s*,/', $fpSrc) === 1);
    // The release_bed branch must never read a facility id out of the
    // client-supplied $input array anywhere -- grep the whole file (not
    // just the branch) for the one shape that would defeat confinement.
    t("no occurrence of \$input['facility_id'] anywhere in this file",
        strpos($fpSrc, "\$input['facility_id']") === false && strpos($fpSrc, '$input["facility_id"]') === false);
    t("release_bed is a POST action on facility-portal.php, not a new endpoint (grep for the action string)",
        strpos($fpSrc, "\$action === 'release_bed'") !== false);

    $scopeSrc = file_get_contents($root . '/inc/facility-scope.php');
    t('FACILITY_ALLOWED_API_SCRIPTS is UNCHANGED by this fix (still exactly the 3 pre-existing scripts)',
        preg_match(
            "/define\\('FACILITY_ALLOWED_API_SCRIPTS',\\s*\\[\\s*'facility-portal\\.php',\\s*'profile\\.php',\\s*'tfa\\.php',?\\s*\\]\\)/s",
            $scopeSrc
        ) === 1);

} finally {
    // cleanup runs via register_shutdown_function above
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
