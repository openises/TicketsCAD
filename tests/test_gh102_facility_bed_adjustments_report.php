<?php
/**
 * GH#102 — the "Facility Bed Adjustments" report (api/reports.php,
 * `facility_bed_adjustments`), driven through the REAL api/reports.php
 * endpoint via a CLI subprocess probe (same discipline as
 * tests/test_gh96_mileage_report.php -- reuses its existing
 * tests/_gh96_mileage_report_probe.php probe script verbatim, since that
 * probe is report-agnostic: it just forwards a query string to the real
 * endpoint file under a simulated session).
 *
 * Seeds BOTH source rows through the real writers this report merges:
 *   - an automatic decrement via inc/bed_auto.php's
 *     bed_auto_apply_on_status_change() (a real delivery, not a
 *     hand-inserted facility_bed_auto_log row)
 *   - a facility self-release via inc/facility-bed-release.php's
 *     facility_bed_release_apply() (GH#102's own new writer)
 *
 * Proves:
 *   - both event types appear, correctly labeled and signed
 *   - newest-first ordering across the two merged tables
 *   - the Facility filter (facility_id) narrows to one facility
 *   - the Facility filter's IDOR boundary: a caller with no entity-level
 *     access to the requested facility (and no aggregate permission)
 *     gets a 404-shaped refusal, not a data leak
 *   - the summary counts (auto_decrement_count / self_release_count /
 *     beds_released_total) are non-null and correct -- named explicitly
 *     because this exact file's own CLAUDE.md history documents a
 *     summary field existing in the JSON response but never rendered
 *     (mileage_report's dead-key regression) -- this test proves the
 *     numbers are actually IN the response, and assets/js/reports.js's
 *     own wiring is checked separately by grepping for the currentReport
 *     === 'facility_bed_adjustments' branch this fix added.
 *
 * @requires-db
 * Usage: php tests/test_gh102_facility_bed_adjustments_report.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/bed_auto.php';
require_once __DIR__ . '/../inc/facility-bed-release.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#102 — Facility Bed Adjustments report ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function gh102bar_probe(string $qs, int $userId): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_gh96_mileage_report_probe.php')
         . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg((string) $userId);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

// Dedicated, unused fixture id block.
$facAId       = 900022401; // gets BOTH an auto decrement and a release
$facBId       = 900022402; // gets ONLY an auto decrement -- the filter target
$responderId  = 900022411;
$ticketId     = 900022421;
$noPermUserId = 900022431;

$createdAssignIds = [];
$createdStatusIds = [];

$cleanup = function () use ($prefix, $facAId, $facBId, $responderId, $ticketId, $noPermUserId, &$createdAssignIds, &$createdStatusIds) {
    foreach ($createdAssignIds as $id) { try { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$responderId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facility_bed_auto_log` WHERE facility_id IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facility_bed_release_log` WHERE facility_id IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facilities` WHERE id IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$noPermUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$noPermUserId]); } catch (Throwable $e) {}
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
         VALUES (?, 'GH102RPT Facility A', 'fixture', 0, 0, '5', '0', 'auto', NOW(), 1, NOW())",
        [$facAId]
    );
    db_query(
        "INSERT INTO `{$prefix}facilities` (id, name, description, type, status_id, beds_a, beds_o, bed_auto_mode, updated, _by, _on)
         VALUES (?, 'GH102RPT Facility B', 'fixture', 0, 0, '3', '0', 'auto', NOW(), 1, NOW())",
        [$facBId]
    );
    db_query(
        "INSERT INTO `{$prefix}responder` (id, name, handle, description, un_status_id, status_updated, updated)
         VALUES (?, 'GH102RPT Test Unit', 'G102R', 'fixture', 1, NOW(), NOW())",
        [$responderId]
    );
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query(
        "INSERT INTO `{$prefix}ticket` (id, in_types_id, status, severity, scope, description, date, problemstart, _by)
         VALUES (?, ?, 2, 0, 'GH102RPT fixture', 'gh102 report fixture', NOW(), NOW(), 1)",
        [$ticketId, $typeId]
    );

    $stAtFac = (int) db_fetch_value("SELECT id FROM `{$prefix}un_status` WHERE LOWER(status_val) = 'at facility' LIMIT 1");
    if (!$stAtFac) {
        db_query(
            "INSERT INTO `{$prefix}un_status`
             (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
              bg_color, text_color, incident_action, resets_par, extra_data_type,
              extra_data_required, extra_data_target, bed_delivery)
             VALUES ('At Facility', 'gh102 report fixture', 0, 0, 'n', 'n', 'busy', 98,
                     'transparent', '#000000', '', 0, 'none', 0, 'action_log', 1)"
        );
        $stAtFac = (int) db_insert_id();
        $createdStatusIds[] = $stAtFac;
    }
    $stName = (string) db_fetch_value("SELECT status_val FROM `{$prefix}un_status` WHERE id = ?", [$stAtFac]);

    // Facility A: one real delivery (auto decrement), then a real release.
    db_query(
        "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, rec_facility_id, as_of, status_id)
         VALUES (?, ?, 1, ?, NOW(), ?)",
        [$ticketId, $responderId, $facAId, $stAtFac]
    );
    $createdAssignIds[] = (int) db_insert_id();
    $autoResult = bed_auto_apply_on_status_change($responderId, $stAtFac, $stName, 1);
    t('facility A: real delivery applied', $autoResult['applied'] === 1);

    // Both real writers stamp applied_at via NOW() -- DATETIME's 1-second
    // resolution means a fast automated run can land the auto decrement
    // and the release in the SAME second, making chronological ordering
    // ambiguous by construction (not a product bug -- both events are
    // still recorded correctly, just not orderable to sub-second
    // precision). Backdate the already-writer-stamped auto row by a few
    // seconds, same technique this codebase's interval-report tests use
    // ("backstamp the milestone" rather than sleep the runner) -- gives
    // this test a real, deterministic time gap to assert ordering against
    // without slowing the suite down.
    db_query(
        "UPDATE `{$prefix}facility_bed_auto_log` SET applied_at = DATE_SUB(applied_at, INTERVAL 5 SECOND)
         WHERE facility_id = ? AND responder_id = ?",
        [$facAId, $responderId]
    );

    $adminId = test_admin_user_id();
    $release = facility_bed_release_apply($facAId, 1, 'GH102 report fixture release', $adminId, 'GH102RPT Actor');
    t('facility A: real release applied', $release['success'] === true);

    // Facility B: a real delivery only (no release) -- the filter target,
    // and proof the report doesn't collapse the two facilities together.
    db_query(
        "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, rec_facility_id, as_of, status_id)
         VALUES (?, ?, 1, ?, NOW(), ?)",
        [$ticketId, $responderId, $facBId, $stAtFac]
    );
    $createdAssignIds[] = (int) db_insert_id();
    $autoResultB = bed_auto_apply_on_status_change($responderId, $stAtFac, $stName, 1);
    t('facility B: real delivery applied', $autoResultB['applied'] === 1);

    db_query(
        "INSERT INTO `{$prefix}user` (id, user, passwd, can_login) VALUES (?, 'gh102rpt-noperm', 'x', 0)",
        [$noPermUserId]
    );
    t('fixture no-permission user created (zero role grants)', true);

    $periodQs = 'period=today';

    // ══════════════════════════════════════════════════════════════════
    // A. Unfiltered, Super Admin -- both event types present and correct
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- A. unfiltered, as admin ---\n\n";
    $payload = gh102bar_probe('report=facility_bed_adjustments&' . $periodQs, $adminId);
    t('report=facility_bed_adjustments returns a decodable JSON payload', $payload !== null);

    if ($payload !== null) {
        t('report_title is "Facility Bed Adjustments"', ($payload['report_title'] ?? '') === 'Facility Bed Adjustments');
        t('columns match the spec (7 columns)',
            ($payload['columns'] ?? []) === ['Facility', 'When', 'Source', 'Available Δ', 'Occupied Δ', 'Actor', 'Detail']);

        $rows = $payload['rows'] ?? [];
        $facARows = array_values(array_filter($rows, function ($r) { return ($r[0] ?? '') === 'GH102RPT Facility A'; }));
        $facBRows = array_values(array_filter($rows, function ($r) { return ($r[0] ?? '') === 'GH102RPT Facility B'; }));
        t('facility A has exactly 2 rows (1 auto + 1 release)', count($facARows) === 2);
        t('facility B has exactly 1 row (1 auto only)', count($facBRows) === 1);

        $facAAuto = null; $facARelease = null;
        foreach ($facARows as $r) {
            if (($r[2] ?? '') === 'Automatic (delivery)') $facAAuto = $r;
            if (($r[2] ?? '') === 'Facility self-release') $facARelease = $r;
        }
        t('facility A auto row found', $facAAuto !== null);
        t('facility A auto row: Available Δ = -1', $facAAuto !== null && ($facAAuto[3] ?? null) === '-1');
        t('facility A auto row: Occupied Δ = +1', $facAAuto !== null && ($facAAuto[4] ?? null) === '+1');

        t('facility A release row found', $facARelease !== null);
        t('facility A release row: Available Δ = +1', $facARelease !== null && ($facARelease[3] ?? null) === '+1');
        t('facility A release row: Occupied Δ = -1', $facARelease !== null && ($facARelease[4] ?? null) === '-1');
        t('facility A release row: Actor = "GH102RPT Actor"', $facARelease !== null && ($facARelease[5] ?? '') === 'GH102RPT Actor');
        t('facility A release row: Detail carries the note', $facARelease !== null && strpos((string) ($facARelease[6] ?? ''), 'GH102 report fixture release') !== false);

        // Newest-first ordering: the release happened strictly AFTER the
        // auto decrement in this fixture, so it must sort before it.
        $autoIdx = null; $releaseIdx = null;
        foreach ($facARows as $i => $r) {
            if ($r === $facAAuto) $autoIdx = $i;
            if ($r === $facARelease) $releaseIdx = $i;
        }
        t('newest-first ordering: release (later) sorts before the auto decrement (earlier)',
            $releaseIdx !== null && $autoIdx !== null && $releaseIdx < $autoIdx);

        // GH#96's own lesson, applied here: a summary field that exists in
        // the JSON but nothing renders is a dead key. Prove the numbers
        // are actually in the payload, at minimum.
        $summary = $payload['summary'] ?? [];
        t('summary.adjustment_count >= 3 (2 auto + 1 release across both facilities)', ($summary['adjustment_count'] ?? 0) >= 3);
        t('summary.auto_decrement_count >= 2', ($summary['auto_decrement_count'] ?? 0) >= 2);
        t('summary.self_release_count >= 1', ($summary['self_release_count'] ?? 0) >= 1);
        t('summary.beds_released_total >= 1', ($summary['beds_released_total'] ?? 0) >= 1);
    }

    // ══════════════════════════════════════════════════════════════════
    // B. Facility filter narrows to one facility
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- B. facility_id filter ---\n\n";
    $payloadB = gh102bar_probe('report=facility_bed_adjustments&' . $periodQs . '&facility_id=' . $facBId, $adminId);
    t('facility_id=B returns a decodable JSON payload', $payloadB !== null);
    if ($payloadB !== null) {
        $rowsB = $payloadB['rows'] ?? [];
        $onlyFacB = true;
        foreach ($rowsB as $r) {
            if (($r[0] ?? '') !== 'GH102RPT Facility B') { $onlyFacB = false; break; }
        }
        t('facility_id=B: every returned row belongs to Facility B only', $onlyFacB && count($rowsB) >= 1);
    }

    // ══════════════════════════════════════════════════════════════════
    // C. IDOR boundary: a caller with no access to the requested facility
    //    (and no aggregate permission) is refused, not shown a filtered
    //    slice of someone else's data.
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- C. facility_id IDOR boundary ---\n\n";
    $payloadC = gh102bar_probe('report=facility_bed_adjustments&' . $periodQs . '&facility_id=' . $facAId, $noPermUserId);
    t('no-permission caller requesting facility_id=A: refused (error present, no rows leaked)',
        $payloadC !== null && !empty($payloadC['error']) && empty($payloadC['rows']));

    // Same no-permission caller, UNFILTERED: refused for lack of the
    // aggregate permission (the pre-existing, unrelated gate) -- confirms
    // the refusal above is really the facility IDOR check doing its job,
    // not just "this user can never get anything back from this report".
    $payloadCUnfiltered = gh102bar_probe('report=facility_bed_adjustments&' . $periodQs, $noPermUserId);
    t('no-permission caller, unfiltered: also refused (the aggregate-permission gate)',
        $payloadCUnfiltered !== null && !empty($payloadCUnfiltered['error']));

    // ══════════════════════════════════════════════════════════════════
    // D. Frontend wiring -- the summary cards this report's own numbers
    //    need are actually rendered, not a dead JSON key (GH#96's own
    //    named lesson, applied here as a build-time guard).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- D. frontend wiring ---\n\n";
    $root = dirname(__DIR__);
    $reportsJsSrc = file_get_contents($root . '/assets/js/reports.js');
    t("reports.js has a currentReport === 'facility_bed_adjustments' branch",
        strpos($reportsJsSrc, "currentReport === 'facility_bed_adjustments'") !== false);
    t('reports.js reads summary.adjustment_count', strpos($reportsJsSrc, 'summary.adjustment_count') !== false);
    t('reports.js reads summary.auto_decrement_count', strpos($reportsJsSrc, 'summary.auto_decrement_count') !== false);
    t('reports.js reads summary.self_release_count', strpos($reportsJsSrc, 'summary.self_release_count') !== false);

    $reportsPhpSrc = file_get_contents($root . '/reports.php');
    t('reports.php has a "Bed Adjustments" report-picker button', strpos($reportsPhpSrc, 'data-report="facility_bed_adjustments"') !== false);

} finally {
    // cleanup runs via register_shutdown_function above
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
