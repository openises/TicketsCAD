<?php
/**
 * GH#96 Step 2 (2026-08-20) — the Mileage Log report, driven through the
 * REAL api/reports.php endpoint (CLI subprocess probe, same discipline as
 * tests/_reports_links_probe.php / tests/test_reports_personnel_drilldown_links.php),
 * against real fixture rows inserted directly into mileage_log (this test
 * is about the REPORT's query/scoping/aggregation logic; the write-path
 * itself is covered separately by tests/test_gh96_mileage_log_write_paths.php).
 *
 * Fixture spans two organizations, two vehicles, two drivers, one linked
 * incident, one OPEN (unended) trip, and one NULL-org/NULL-ticket trip --
 * exactly the shapes the spec calls out. Also drives the responder_id-
 * narrowed IDOR exemption (a zero-permission caller can still run the
 * report when scoped to one vehicle) and the Organization filter's
 * silent-ignore-when-unauthorized behavior (an org-scoped, non-Super-Admin
 * caller requesting a DIFFERENT org's org_id gets no error and no widened
 * visibility -- the request behaves identically to sending no org_id at
 * all).
 *
 * @requires-db
 * Usage: php tests/test_gh96_mileage_report.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#96 Step 2 — Mileage Log report ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Fixture ids — a dedicated, unused block (matches the established
// convention, e.g. test_facility_capacity_summary_scope.php).
$orgAId        = 900019801;
$orgBId        = 900019802;
$vehicleAId    = 900019811;
$vehicleBId    = 900019812;
$driverAId     = 900019821;
$driverBId     = 900019822;
$noPermUserId  = 900019823;
$scopedUserId  = 900019824;
$ticketId      = 900019831;

$mlIds = [];

function gh96mr_probe(string $qs, int $userId, int $activeOrgId = 0): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_gh96_mileage_report_probe.php')
         . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg((string) $userId)
         . ' ' . escapeshellarg((string) $activeOrgId);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

/** Rows whose Vehicle column (index 1) matches a given vehicle label. */
function gh96mr_rows_for_vehicle(array $payload, string $vehicleLabel): array {
    $out = [];
    foreach (($payload['rows'] ?? []) as $r) {
        if (($r[1] ?? '') === $vehicleLabel) $out[] = $r;
    }
    return $out;
}

$cleanup = function () use ($prefix, $orgAId, $orgBId, $vehicleAId, $vehicleBId,
    $driverAId, $driverBId, $noPermUserId, $scopedUserId, $ticketId, &$mlIds) {
    try {
        if (!empty($mlIds)) {
            $ph = implode(',', array_fill(0, count($mlIds), '?'));
            db_query("DELETE FROM `{$prefix}mileage_log` WHERE id IN ($ph)", $mlIds);
        }
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}responder` WHERE id IN (?, ?)", [$vehicleAId, $vehicleBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id IN (?, ?)", [$scopedUserId, $noPermUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE id IN (?, ?, ?, ?)", [$driverAId, $driverBId, $noPermUserId, $scopedUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}organizations` WHERE id IN (?, ?)", [$orgAId, $orgBId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ══════════════════════════════════════════════════════════════════
    // Fixtures
    // ══════════════════════════════════════════════════════════════════
    db_query("INSERT INTO `{$prefix}organizations` (id, name) VALUES (?, 'GH96 Org A')", [$orgAId]);
    db_query("INSERT INTO `{$prefix}organizations` (id, name) VALUES (?, 'GH96 Org B')", [$orgBId]);
    t('fixture organizations A and B created', true);

    db_query("INSERT INTO `{$prefix}responder` (id, name, description) VALUES (?, 'GH96 Vehicle A', 'gh96 fixture')", [$vehicleAId]);
    db_query("INSERT INTO `{$prefix}responder` (id, name, description) VALUES (?, 'GH96 Vehicle B', 'gh96 fixture')", [$vehicleBId]);
    t('fixture vehicles A and B created', true);

    db_query("INSERT INTO `{$prefix}user` (id, user, passwd, name_f, name_l, can_login) VALUES (?, 'gh96-driver-a', 'x', 'GH96', 'DriverA', 0)", [$driverAId]);
    db_query("INSERT INTO `{$prefix}user` (id, user, passwd, name_f, name_l, can_login) VALUES (?, 'gh96-driver-b', 'x', 'GH96', 'DriverB', 0)", [$driverBId]);
    db_query("INSERT INTO `{$prefix}user` (id, user, passwd, can_login) VALUES (?, 'gh96-lowperm', 'x', 0)", [$noPermUserId]);
    db_query("INSERT INTO `{$prefix}user` (id, user, passwd, can_login) VALUES (?, 'gh96-scoped-orgadmin', 'x', 0)", [$scopedUserId]);
    t('fixture users created (2 drivers, 1 low-permission, 1 org-scoped Org Admin)', true);

    // Read-Only (role_id=5, global scope) -- holds screen.unit_detail (so
    // user_can_access_entity('responder', ...) passes) but NOT
    // action.view_reports (so this caller is still refused an UNFILTERED
    // aggregate report -- the control case in section B below). This is
    // the real-world shape of "responder_id-narrowing exemption": some
    // access, not none.
    db_query(
        "INSERT INTO `{$prefix}user_roles` (user_id, role_id, scope_kind) VALUES (?, 5, 'global')",
        [$noPermUserId]
    );
    // Org Admin (role_id=2, is_super=0) scoped to Org A only. TWO
    // different columns both need to carry orgAId: `org_id` is what
    // org_visible_ids() walks (via org_descendant_ids()) to build the
    // caller's visible-org set; `scope_id` is the SEPARATE column
    // inc/rbac.php's _rbac_scope_satisfied() reads for an 'org'-scope_kind
    // grant's own permission check (rbac_can('action.view_reports')
    // requires scope_id === $_SESSION['active_org_id']). A real grant
    // (e.g. through the Roles admin UI) sets both together; a fixture
    // that sets only org_id passes org_visible_ids() but rbac_can() then
    // silently fails on a NULL scope_id.
    db_query(
        "INSERT INTO `{$prefix}user_roles` (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 2, ?, 'org', ?)",
        [$scopedUserId, $orgAId, $orgAId]
    );
    t('fixture role grants created (Read-Only global; Org Admin scoped to Org A)', true);

    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`)
         VALUES (0, 'GH96 mileage report fixture incident', 'gh96 fixture', NOW(), 2, 0)"
    );
    $ticketId = (int) db_insert_id();
    t('fixture incident created', $ticketId > 0);

    // Schema-shape detection (same technique inc/responder-write.php's
    // _phase95_record_mileage_log() uses) -- write `miles` directly only
    // when the column is plain; a GENERATED install computes it itself
    // from start_odo/end_odo.
    $milesExtra = (string) db_fetch_value(
        "SELECT EXTRA FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'miles'",
        [$prefix . 'mileage_log']
    );
    $milesIsGenerated = (stripos($milesExtra, 'GENERATED') !== false);

    function gh96mr_insert_trip($prefix, $milesIsGenerated, $orgId, $responderId, $userId, $ticketId,
            $startedAt, $endedAt, $startOdo, $endOdo, $notes) {
        if ($milesIsGenerated) {
            db_query(
                "INSERT INTO `{$prefix}mileage_log`
                    (org_id, responder_id, user_id, ticket_id, started_at, ended_at, start_odo, end_odo, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$orgId, $responderId, $userId, $ticketId, $startedAt, $endedAt, $startOdo, $endOdo, $notes]
            );
        } else {
            $miles = ($startOdo !== null && $endOdo !== null) ? ($endOdo - $startOdo) : null;
            db_query(
                "INSERT INTO `{$prefix}mileage_log`
                    (org_id, responder_id, user_id, ticket_id, started_at, ended_at, start_odo, end_odo, miles, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$orgId, $responderId, $userId, $ticketId, $startedAt, $endedAt, $startOdo, $endOdo, $miles, $notes]
            );
        }
        return (int) db_insert_id();
    }

    $base = strtotime('-1 hour');
    $t1 = date('Y-m-d H:i:s', $base);
    $t2 = date('Y-m-d H:i:s', $base + 60);
    $t3 = date('Y-m-d H:i:s', $base + 120);
    $t4 = date('Y-m-d H:i:s', $base + 180);
    $t5 = date('Y-m-d H:i:s', $base + 240);
    $t6 = date('Y-m-d H:i:s', $base + 300);
    $t7 = date('Y-m-d H:i:s', $base + 360);

    // Row 1: Org A, Vehicle A, Driver A, LINKED to fixture ticket. 50 miles.
    $mlIds[] = gh96mr_insert_trip($prefix, $milesIsGenerated, $orgAId, $vehicleAId, $driverAId, $ticketId,
        $t1, $t2, '100.0', '150.0', 'GH96 fixture row1');
    // Row 2: Org A, Vehicle A, Driver A, no ticket. 20 miles.
    $mlIds[] = gh96mr_insert_trip($prefix, $milesIsGenerated, $orgAId, $vehicleAId, $driverAId, null,
        $t3, $t4, '0.0', '20.0', 'GH96 fixture row2');
    // Row 3: Org B, Vehicle B, Driver B, no ticket. 75 miles.
    $mlIds[] = gh96mr_insert_trip($prefix, $milesIsGenerated, $orgBId, $vehicleBId, $driverBId, null,
        $t5, $t6, '0.0', '75.0', 'GH96 fixture row3');
    // Row 4: org_id NULL ("Unattributed"), Vehicle A, Driver A, OPEN trip
    // (ended_at NULL) -- start_odo present, end_odo/miles NULL.
    $mlIds[] = gh96mr_insert_trip($prefix, $milesIsGenerated, null, $vehicleAId, $driverAId, null,
        $t7, null, '500.0', null, 'GH96 fixture row4 open');
    // Row 5: Org A, Vehicle A, Driver A -- the "instant entry" shape a
    // status-extra-data mileage prompt actually produces (confirmed live
    // on your-server.example.com): inc/responder-write.php's
    // _phase95_record_mileage_log() sets start_odo/end_odo TOGETHER but
    // never touches ended_at (there is no discrete "stop" event for this
    // entry type, unlike the mobile app's two-phase start/stop trip
    // tracker). Complete odometer data (end_odo=30, miles=30), but
    // ended_at IS NULL -- must be classified as COMPLETED, not open.
    $mlIds[] = gh96mr_insert_trip($prefix, $milesIsGenerated, $orgAId, $vehicleAId, $driverAId, null,
        $t7, null, '0.0', '30.0', 'GH96 fixture row5 instant');

    t('5 fixture mileage_log rows created (2 orgs, 2 vehicles, 2 drivers, 1 linked incident, 1 open trip, 1 unattributed, 1 instant-entry)',
        count(array_filter($mlIds)) === 5);

    $periodQs = 'period=custom&start_date=' . date('Y-m-d', $base - 3600) . '&end_date=' . date('Y-m-d', $base + 3600);
    $adminId = test_admin_user_id();

    // ══════════════════════════════════════════════════════════════════
    // A. Unfiltered, Super Admin — every fixture row present, columns and
    //    breakdowns correct.
    // ══════════════════════════════════════════════════════════════════
    $payload = gh96mr_probe('report=mileage_report&' . $periodQs, $adminId);
    t('report=mileage_report returns a decodable JSON payload', $payload !== null);

    if ($payload !== null) {
        t('report_title is "Mileage Log"', ($payload['report_title'] ?? '') === 'Mileage Log');
        t('columns match the spec (10 columns)',
            ($payload['columns'] ?? []) === ['Organization', 'Vehicle', 'Driver', 'Incident #',
                'Started', 'Ended', 'Start Odo', 'End Odo', 'Miles', 'Notes']);

        $vehARows = gh96mr_rows_for_vehicle($payload, 'GH96 Vehicle A');
        $vehBRows = gh96mr_rows_for_vehicle($payload, 'GH96 Vehicle B');
        t('Vehicle A has exactly 4 rows (row1, row2, row4, row5)', count($vehARows) === 4);
        t('Vehicle B has exactly 1 row (row3)', count($vehBRows) === 1);

        $row1 = null;
        foreach ($vehARows as $r) { if (($r[9] ?? '') === 'GH96 fixture row1') { $row1 = $r; break; } }
        t('row1 found with Organization = "GH96 Org A"', $row1 !== null && ($row1[0] ?? '') === 'GH96 Org A');
        t('row1 Driver = "GH96 DriverA"', $row1 !== null && strpos((string) ($row1[2] ?? ''), 'GH96 DriverA') !== false);
        t('row1 Incident # is non-empty (linked to the fixture ticket)', $row1 !== null && ($row1[3] ?? '') !== '' && ($row1[3] ?? '') !== '—');
        t('row1 Miles = 50', $row1 !== null && (float) ($row1[8] ?? 0) === 50.0);

        $row4 = null;
        foreach ($vehARows as $r) { if (($r[9] ?? '') === 'GH96 fixture row4 open') { $row4 = $r; break; } }
        t('row4 (open trip) found with Organization = "Unattributed"', $row4 !== null && ($row4[0] ?? '') === 'Unattributed');
        t('row4 Incident # renders as "—" (no ticket link)', $row4 !== null && ($row4[3] ?? '') === '—');
        t('row4 Ended is blank (still open)', $row4 !== null && ($row4[5] ?? '') === '');
        t('row4 Miles is blank (open trip, no end odometer)', $row4 !== null && ($row4[8] ?? '') === '');

        // row5 — the "instant entry" shape (confirmed live on
        // your-server.example.com, see api/reports.php's $isOpen comment):
        // ended_at is NULL but end_odo/miles ARE present. Must render
        // Miles correctly and NOT be counted as an open trip.
        $row5 = null;
        foreach ($vehARows as $r) { if (($r[9] ?? '') === 'GH96 fixture row5 instant') { $row5 = $r; break; } }
        t('row5 (instant entry) found with Miles = 30 despite Ended being blank',
            $row5 !== null && (float) ($row5[8] ?? -1) === 30.0 && ($row5[5] ?? '') === '');

        $row3 = $vehBRows[0] ?? null;
        t('row3 Organization = "GH96 Org B"', $row3 !== null && ($row3[0] ?? '') === 'GH96 Org B');
        t('row3 Miles = 75', $row3 !== null && (float) ($row3[8] ?? 0) === 75.0);

        // Breakdowns — vehicle keys are fixture-unique ids, safe to assert exactly.
        $byOrg  = $payload['mileage_by_org']  ?? [];
        $byUnit = $payload['mileage_by_unit'] ?? [];
        $orgAEntry  = null; foreach ($byOrg as $o)  { if (($o['label'] ?? '') === 'GH96 Org A') $orgAEntry = $o; }
        $orgBEntry  = null; foreach ($byOrg as $o)  { if (($o['label'] ?? '') === 'GH96 Org B') $orgBEntry = $o; }
        $unitAEntry = null; foreach ($byUnit as $u) { if (($u['label'] ?? '') === 'GH96 Vehicle A') $unitAEntry = $u; }
        $unitBEntry = null; foreach ($byUnit as $u) { if (($u['label'] ?? '') === 'GH96 Vehicle B') $unitBEntry = $u; }

        t('mileage_by_org: Org A trip_count=3, total_miles=100 (50+20+30)',
            $orgAEntry !== null && (int) $orgAEntry['trip_count'] === 3 && (float) $orgAEntry['total_miles'] === 100.0);
        t('mileage_by_org: Org B trip_count=1, total_miles=75',
            $orgBEntry !== null && (int) $orgBEntry['trip_count'] === 1 && (float) $orgBEntry['total_miles'] === 75.0);
        t('mileage_by_unit: Vehicle A trip_count=4, total_miles=100 (open trip contributes 0 miles, not an error)',
            $unitAEntry !== null && (int) $unitAEntry['trip_count'] === 4 && (float) $unitAEntry['total_miles'] === 100.0);
        t('mileage_by_unit: Vehicle B trip_count=1, total_miles=75',
            $unitBEntry !== null && (int) $unitBEntry['trip_count'] === 1 && (float) $unitBEntry['total_miles'] === 75.0);

        // Summary object — these fields are computed over the WHOLE period
        // (not scoped to this test's fixture markers, unlike every check
        // above), so this shared long-lived dev database's own unrelated
        // data in the same window means only >= floors are safe here, not
        // exact equality. The property under test is the completed/open
        // CLASSIFICATION (keyed on end_odo, not ended_at — row5 has NULL
        // ended_at but a real end_odo and must count as completed; row4
        // has NULL end_odo and must count as open — see api/reports.php's
        // $isOpen for the full story), which the open/completed counts
        // below still verify without needing exact totals.
        $summary = $payload['summary'] ?? [];
        t('summary.trip_count >= 5 (at least this fixture\'s own rows)', (int) ($summary['trip_count'] ?? -1) >= 5);
        t('summary.total_miles >= 175 (at least 50+20+75+30, row4 contributes 0)', (float) ($summary['total_miles'] ?? -1) >= 175.0);
        t('summary.open_trip_count >= 1 (at least row4, the genuinely open trip)', (int) ($summary['open_trip_count'] ?? -1) >= 1);
        t('summary.completed_trip_count >= 4 (row5 the instant entry counts as completed, not open)',
            (int) ($summary['completed_trip_count'] ?? -1) >= 4);
        t('summary.unattributed_trip_count >= 1 (at least row4, org_id NULL)', (int) ($summary['unattributed_trip_count'] ?? -1) >= 1);

        // links[] — Vehicle (col 1) and Incident (col 3) both carry drill-down ids.
        $unitLinkCol = null; $incLinkCol = null;
        foreach (($payload['links'] ?? []) as $d) {
            if (($d['kind'] ?? '') === 'unit') $unitLinkCol = $d['col'];
            if (($d['kind'] ?? '') === 'incident') $incLinkCol = $d['col'];
        }
        t('links[] carries a unit-kind descriptor for column 1 (Vehicle)', $unitLinkCol === 1);
        t('links[] carries an incident-kind descriptor for column 3 (Incident #)', $incLinkCol === 3);
    } else {
        for ($i = 0; $i < 18; $i++) { t('(skipped — no payload)', false); }
    }

    // ══════════════════════════════════════════════════════════════════
    // B. responder_id-narrowed IDOR exemption — a caller who holds
    //    responder-view access but NOT the aggregate "Run Aggregate
    //    Reports" permission (Read-Only) can still run the report when
    //    scoped to one Vehicle, and only sees that vehicle's rows.
    // ══════════════════════════════════════════════════════════════════
    $payloadB = gh96mr_probe('report=mileage_report&' . $periodQs . '&responder_id=' . $vehicleAId, $noPermUserId);
    t('low-permission caller narrowed by responder_id gets a successful response, not a 403',
        $payloadB !== null && !isset($payloadB['error']));
    if ($payloadB !== null && !isset($payloadB['error'])) {
        $vehARowsB = gh96mr_rows_for_vehicle($payloadB, 'GH96 Vehicle A');
        $vehBRowsB = gh96mr_rows_for_vehicle($payloadB, 'GH96 Vehicle B');
        t('responder_id-narrowed result contains only Vehicle A rows (4)', count($vehARowsB) === 4);
        t('responder_id-narrowed result contains zero Vehicle B rows', count($vehBRowsB) === 0);
    } else {
        t('(skipped — no payload)', false);
        t('(skipped — no payload)', false);
    }

    // Confirm the same low-permission caller, UNFILTERED, is refused (the
    // control case proving B above is really the responder_id-narrowing
    // exemption at work, not a caller who happens to hold
    // action.view_reports some other way).
    $payloadBUnfiltered = gh96mr_probe('report=mileage_report&' . $periodQs, $noPermUserId);
    t('the SAME low-permission caller, unfiltered, is refused (control case — Read-Only lacks action.view_reports)',
        $payloadBUnfiltered !== null && isset($payloadBUnfiltered['error']));

    // ══════════════════════════════════════════════════════════════════
    // C. Driver filter.
    // ══════════════════════════════════════════════════════════════════
    $payloadC = gh96mr_probe('report=mileage_report&' . $periodQs . '&driver_id=' . $driverBId, $adminId);
    t('driver_id=DriverB returns a payload', $payloadC !== null);
    if ($payloadC !== null) {
        $rowsC = $payloadC['rows'] ?? [];
        $gh96RowsC = array_filter($rowsC, fn($r) => strpos((string) ($r[9] ?? ''), 'GH96 fixture') === 0);
        t('driver_id=DriverB result contains exactly the 1 fixture row belonging to Driver B',
            count($gh96RowsC) === 1 && (($gh96RowsC[array_key_first($gh96RowsC)][9] ?? '') === 'GH96 fixture row3'));
    } else {
        t('(skipped — no payload)', false);
    }

    // ══════════════════════════════════════════════════════════════════
    // D. Organization filter, AUTHORIZED (Super Admin — always authorized).
    // ══════════════════════════════════════════════════════════════════
    $payloadD = gh96mr_probe('report=mileage_report&' . $periodQs . '&org_id=' . $orgAId, $adminId);
    t('org_id=OrgA (Super Admin) returns a payload', $payloadD !== null);
    if ($payloadD !== null) {
        $rowsD = $payloadD['rows'] ?? [];
        $gh96RowsD = array_filter($rowsD, fn($r) => strpos((string) ($r[9] ?? ''), 'GH96 fixture') === 0);
        $labelsD = array_map(fn($r) => $r[0] ?? '', $gh96RowsD);
        t('org_id=OrgA result contains only Org A fixture rows (3: row1, row2, row5), never Org B',
            count($gh96RowsD) === 3 && !in_array('GH96 Org B', $labelsD, true));
    } else {
        t('(skipped — no payload)', false);
    }

    // ══════════════════════════════════════════════════════════════════
    // E. Organization filter, UNAUTHORIZED — the security-critical case.
    //    The org-scoped Org Admin (visible = Org A only) requests org_id
    //    = Org B. Must NOT error, and must NOT widen visibility to Org B.
    //    Comparisons are scoped to THIS test's own fixture rows (matched
    //    by the distinctive 'GH96 fixture rowN' notes marker each row
    //    carries), not raw row counts across the whole table -- whether
    //    org_id IS NULL rows (row4, "Unattributed") are visible to a
    //    scoped caller at all depends on the install's own
    //    org_strict_isolation setting (org_query_filter()'s own
    //    documented legacy-vs-strict fork), which is NOT something this
    //    report changes and NOT what this test exists to pin down --
    //    fixture-marker comparison stays correct either way.
    // ══════════════════════════════════════════════════════════════════
    $payloadEUnfiltered = gh96mr_probe('report=mileage_report&' . $periodQs, $scopedUserId, $orgAId);
    $payloadEMalicious  = gh96mr_probe('report=mileage_report&' . $periodQs . '&org_id=' . $orgBId, $scopedUserId, $orgAId);
    t('org-scoped caller, unfiltered, gets a successful response', $payloadEUnfiltered !== null && !isset($payloadEUnfiltered['error']));
    t('org-scoped caller requesting an unauthorized org_id gets NO error (silently ignored, not refused)',
        $payloadEMalicious !== null && !isset($payloadEMalicious['error']));
    if ($payloadEUnfiltered !== null && $payloadEMalicious !== null
            && !isset($payloadEUnfiltered['error']) && !isset($payloadEMalicious['error'])) {
        $gh96NotesFilter = fn($r) => strpos((string) ($r[9] ?? ''), 'GH96 fixture') === 0;
        $gh96RowsUnfiltered = array_values(array_filter($payloadEUnfiltered['rows'] ?? [], $gh96NotesFilter));
        $gh96RowsMalicious  = array_values(array_filter($payloadEMalicious['rows'] ?? [], $gh96NotesFilter));
        $notesUnfiltered = array_map(fn($r) => $r[9], $gh96RowsUnfiltered);
        $notesMalicious  = array_map(fn($r) => $r[9], $gh96RowsMalicious);
        sort($notesUnfiltered);
        sort($notesMalicious);
        t('unauthorized org_id=OrgB has ZERO narrowing effect — the exact same SET of fixture rows as the unfiltered request',
            $notesUnfiltered === $notesMalicious);

        $labelsE = array_map(fn($r) => $r[0] ?? '', $gh96RowsMalicious);
        t('unauthorized org_id=OrgB never leaks the Org B fixture row to the Org-A-scoped caller',
            !in_array('GH96 Org B', $labelsE, true));
        t('org-scoped caller (Org A) still sees their own Org A fixture rows (row1 + row2)',
            in_array('GH96 fixture row1', $notesMalicious, true) && in_array('GH96 fixture row2', $notesMalicious, true));
    } else {
        t('(skipped — no payload)', false);
        t('(skipped — no payload)', false);
        t('(skipped — no payload)', false);
    }

} catch (Throwable $e) {
    t('unexpected exception: ' . $e->getMessage(), false);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
