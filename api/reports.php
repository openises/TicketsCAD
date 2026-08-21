<?php
/**
 * NewUI v4.0 API - Reports
 *
 * GET /api/reports.php?report=unit_log&period=this_month
 *   report:       unit_log | dispatch_log | incident_summary | incident_report | facility_log | after_action
 *   period:       today | this_week | last_week | this_month | last_month | this_year | last_year | custom
 *   start_date:   Y-m-d (required if period=custom)
 *   end_date:     Y-m-d (required if period=custom)
 *   responder_id:     filter by responder/unit (0=all) — the incident-type
 *                      reports (unit_log, dispatch_log, facility_log,
 *                      notes_log)
 *   member_id:         filter by a specific person (0=all) — the personnel
 *                      reports (roster_snapshot, time_summary,
 *                      license_expirations, membership_due,
 *                      inactive_members, dmr_inventory). Distinct from
 *                      responder_id: `responder` is the units/vehicles
 *                      table, `member` is the people/roster table.
 *   incident_number:  filter by incident (used for after_action) — the
 *                      dispatcher's own case number (e.g. "26-0091"),
 *                      resolved server-side via incnum_resolve_input().
 *   incident_id:       legacy alias, still accepted for old bookmarks/
 *                      links — also resolved via incnum_resolve_input()
 *                      so a raw internal id still works.
 *
 * Returns JSON with report_title, period_label, columns, rows, summary.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/severity.php';
// GH#64 — pure interval-math helpers for the 'interval_report' case below.
require_once __DIR__ . '/../inc/interval-report.php';

// Suppress PHP warnings from corrupting JSON output
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// IDOR — reports.php aggregates incident, responder, and facility data.
// Aggregate reports without a single-resource filter expose cross-org data
// to anyone with a session, which violates Constitution rule #5 (every
// endpoint must enforce permission). Only holders of action.view_reports
// may run the aggregate reports; everyone else must scope to a specific
// resource they have access to (the per-resource IDOR check below still
// applies to those).
//
// 2026-07-29 — this gate USED to read the legacy `user.level` column
// (`$_SESSION['level'] <= 1`), which is dead weight after the Phase 12
// RBAC migration: reports.php (the page) gates on rbac_require_screen(
// 'screen.reports'), so an Org Admin passed the page and was then refused
// by the API. On your deployment she was user.level=4 / role_id=2 and could
// not run a single report, while the org-scope filter twenty lines below
// — written specifically so an Org Admin (vs Super Admin) could — was
// unreachable code. The gate now asks the role system, like the rest of
// the app. is_admin() (Super Admin / action.manage_config) is unchanged.
require_once __DIR__ . '/../inc/rbac.php';
$_canAggregate = is_admin() || rbac_can('action.view_reports');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Phase 99j-7 (Billy beta 2026-06-29) — org-scope filter for the
// aggregate reports. Aggregate reports require action.view_reports
// (see the gate above), so the org filter only narrows things when an
// Org Admin (vs Super Admin) runs a report. Super Admin gets ('', [])
// so all queries are unchanged.
// Phase 141 (2026-08-17) — org_ticket_query_filter() is org_query_filter()'s
// ticket-specific sibling: widens the aggregate ticket-count reports to
// include cross-org-shared tickets (per plan.md's tier matrix, reports.php
// only ever surfaces counts, never field-level PII, so both tiers are
// simply "allowed" with no redaction wiring needed here). Deliberately NOT
// applied to org_member_query_filter('m.id') on the next line -- that call
// is untouched, on purpose, per plan.md's roster-isolation boundary.
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
[$rptTicketFrag, $rptTicketVars] = org_ticket_query_filter(null, 't');
[$rptMemberFrag, $rptMemberVars] = org_member_query_filter('m.id');

// Helper: safe query that returns empty array on failure
function safe_fetch_all_rpt($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all_rpt] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

function safe_fetch_value_rpt($sql, $params = []) {
    try {
        return db_fetch_value($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_value_rpt] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return null;
    }
}

// GH#96 (2026-08-20) — Mileage Log report's Driver/Organization filter
// option lists. A dedicated GET branch (not part of the $valid_reports
// switch below) because these are FILTER METADATA, not a report itself --
// same reason api/responders.php/api/members.php exist as their own
// endpoints for the Vehicle/Member filters rather than being folded into
// this file. Gated on $_canAggregate (the same permission the mileage
// report itself needs for its unfiltered/aggregate view) since it exposes
// driver names scoped to the caller's visible orgs, not for anonymous use.
if (($_GET['list_filters'] ?? '') === 'mileage') {
    if (!$_canAggregate) {
        ini_set('display_errors', $prevDisplay);
        json_error('Requires the "Run Aggregate Reports" permission', 403);
    }
    [$mlOrgFrag, $mlOrgVars] = org_mileage_query_filter();
    $mlDrivers = safe_fetch_all_rpt(
        "SELECT DISTINCT u.id,
                COALESCE(NULLIF(TRIM(CONCAT(u.name_f, ' ', u.name_l)), ''), u.`user`) AS name
         FROM `{$prefix}mileage_log` ml
         JOIN `{$prefix}user` u ON ml.user_id = u.id
         WHERE 1=1{$mlOrgFrag}
         ORDER BY name",
        $mlOrgVars
    );
    $mlOrgs = [];
    $mlVisible = org_visible_ids();
    if ($mlVisible === null) {
        $mlOrgs = safe_fetch_all_rpt(
            "SELECT id, name FROM `{$prefix}organizations` ORDER BY sort_order, name"
        );
    } elseif (!empty($mlVisible)) {
        $mlPh = implode(',', array_fill(0, count($mlVisible), '?'));
        $mlOrgs = safe_fetch_all_rpt(
            "SELECT id, name FROM `{$prefix}organizations` WHERE id IN ({$mlPh}) ORDER BY sort_order, name",
            array_values(array_map('intval', $mlVisible))
        );
    }
    ini_set('display_errors', $prevDisplay);
    json_response(['drivers' => $mlDrivers, 'organizations' => $mlOrgs]);
}

// ── Parse parameters ──────────────────────────────────────────────────────────

$report       = $_GET['report'] ?? 'incident_report';
$period       = $_GET['period'] ?? 'this_month';
$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';
$responder_id = max(0, (int) ($_GET['responder_id'] ?? 0));
// GH#57 follow-up (2026-08-15, cbyrdmo) -- the six Personnel reports had no
// way to scope to one person at all; `responder_id` above filters the
// `responder` (units) table and Personnel reports were explicitly exempted
// from it. `member_id` is the equivalent for the `member` (people/roster)
// table.
$member_id = max(0, (int) ($_GET['member_id'] ?? 0));

// GH#96 — Mileage Log report filters: Driver (mileage_log.user_id -- a
// LOGIN account, distinct from `member`/`responder`) and Organization
// (mileage_log.org_id, a direct column -- see sql/run_gh96_mileage_log_org_id.php).
$driver_id      = max(0, (int) ($_GET['driver_id'] ?? 0));
$mileage_org_id = max(0, (int) ($_GET['org_id'] ?? 0));

// GH#102 — Facility Bed Adjustments report's own Facility filter, distinct
// from responder_id/incident_id above (those scope different tables).
$bed_facility_id = max(0, (int) ($_GET['facility_id'] ?? 0));

// GH#51 — accept the dispatcher's own case number, not the internal id.
// incident_number is what the new UI sends; incident_id is kept for old
// bookmarks/links and resolved the same way (a raw numeric id still
// works as a fallback inside incnum_resolve_input()).
require_once __DIR__ . '/../inc/incident-number.php';
$incident_input = trim((string) ($_GET['incident_number'] ?? ($_GET['incident_id'] ?? '')));
$incident_id    = $incident_input !== '' ? incnum_resolve_input($incident_input) : 0;

$valid_reports = [
    'unit_log', 'dispatch_log', 'incident_summary', 'incident_report',
    'facility_log',
    // GH#96 — trip-log/utilization report over mileage_log (org, vehicle,
    // driver, incident-link, odometer, miles, notes). Report-group sibling
    // of Unit Activity Log/Facility Log, not Personnel -- mileage_log's
    // keys (responder_id/vehicle, ticket_id/incident, user_id/driver)
    // match this group's shape.
    'mileage_report',
    // GH#102 — merged timeline over facility_bed_auto_log (automatic
    // decrements) + facility_bed_release_log (a facility's own
    // self-release, GH#102's new inc/facility-bed-release.php) so an
    // operator can tell "who moved this facility's bed count and why"
    // without SSH access to tools/bed_auto_diagnose.php. Report-group
    // sibling of Facility Log / Mileage Log.
    'facility_bed_adjustments',
    'after_action', 'notes_log',
    // GH#64 — response/scene/transport interval reporting over the assigns
    // milestones (dispatched/responding/on_scene/u2fenr/u2farr/clear).
    'interval_report',
    // Personnel / membership reports (#21)
    'license_expirations', 'roster_snapshot', 'dmr_inventory',
    'membership_due', 'inactive_members', 'time_summary',
];
if (!in_array($report, $valid_reports, true)) {
    json_error('Invalid report type', 400);
}

// Personnel reports — read across the org, so they need the same
// aggregate permission as the reports below. They don't take the
// incident_id/responder_id filters below (those scope different tables);
// member_id is their own equivalent, checked separately.
$personnelReports = ['license_expirations', 'roster_snapshot', 'dmr_inventory',
                     'membership_due', 'inactive_members', 'time_summary'];
$isPersonnel = in_array($report, $personnelReports, true);
if ($isPersonnel && !$_canAggregate) {
    ini_set('display_errors', $prevDisplay);
    json_error('Personnel reports require the "Run Aggregate Reports" permission', 403);
}

// Per-resource IDOR check first — a user requesting one specific incident,
// responder, or member must have access to it regardless of role.
if ($incident_id > 0 && !user_can_access_entity('incident', $incident_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}
if ($responder_id > 0 && !user_can_access_entity('responder', $responder_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Responder not found', 404);
}
if ($member_id > 0 && !user_can_access_entity('member', $member_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Member not found', 404);
}
if ($bed_facility_id > 0 && !user_can_access_entity('facility', $bed_facility_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Facility not found', 404);
}

// A single extra WHERE fragment every Personnel report query appends right
// after {$rptMemberFrag} (the org-scope filter) -- combining the two in one
// AND chain means a member outside the caller's visible orgs still can't be
// singled out by guessing an id, same protection the org-scope filter
// already provides on its own.
$memberIdFrag = $member_id > 0 ? ' AND m.id = ?' : '';
$memberIdVars = $member_id > 0 ? [$member_id] : [];

// Aggregate / cross-resource reports (no specific filter) need the
// aggregate permission; everyone else must scope to one resource.
$isFiltered = ($incident_id > 0) || ($responder_id > 0);
if (!$isPersonnel && !$isFiltered && !$_canAggregate) {
    ini_set('display_errors', $prevDisplay);
    json_error('Aggregate reports require the "Run Aggregate Reports" permission — filter by incident or responder', 403);
}

// ── Build date range from period ──────────────────────────────────────────────

$now = new DateTime();
$period_label = '';

switch ($period) {
    case 'today':
        $start_date = $now->format('Y-m-d');
        $end_date   = $now->format('Y-m-d');
        $period_label = 'Today (' . $now->format('M j, Y') . ')';
        break;

    case 'this_week':
        $start = (clone $now)->modify('monday this week');
        $start_date = $start->format('Y-m-d');
        $end_date   = $now->format('Y-m-d');
        $period_label = 'This Week (' . $start->format('M j') . ' - ' . $now->format('M j, Y') . ')';
        break;

    case 'last_week':
        $start = (clone $now)->modify('monday last week');
        $end   = (clone $start)->modify('+6 days');
        $start_date = $start->format('Y-m-d');
        $end_date   = $end->format('Y-m-d');
        $period_label = 'Last Week (' . $start->format('M j') . ' - ' . $end->format('M j, Y') . ')';
        break;

    case 'this_month':
        $start_date = $now->format('Y-m-01');
        $end_date   = $now->format('Y-m-d');
        $period_label = 'This Month (' . $now->format('F Y') . ')';
        break;

    case 'last_month':
        $last = (clone $now)->modify('first day of last month');
        $start_date = $last->format('Y-m-01');
        $end_date   = $last->format('Y-m-t');
        $period_label = 'Last Month (' . $last->format('F Y') . ')';
        break;

    case 'this_year':
        $start_date = $now->format('Y-01-01');
        $end_date   = $now->format('Y-m-d');
        $period_label = 'This Year (' . $now->format('Y') . ')';
        break;

    case 'last_year':
        $yr = (int) $now->format('Y') - 1;
        $start_date = $yr . '-01-01';
        $end_date   = $yr . '-12-31';
        $period_label = 'Last Year (' . $yr . ')';
        break;

    case 'custom':
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            json_error('Invalid custom date range. Use Y-m-d format.', 400);
        }
        $period_label = 'Custom (' . $start_date . ' to ' . $end_date . ')';
        break;

    default:
        json_error('Invalid period', 400);
}

$date_start_sql = $start_date . ' 00:00:00';
$date_end_sql   = $end_date . ' 23:59:59';

// ── Report generators ─────────────────────────────────────────────────────────

$columns = [];
$rows    = [];
$summary = [];
$report_title = '';
// Phase 132 Step 5 (GH #16) — a SEPARATE breakdown, only ever populated by
// the 'incident_summary' case below. Kept as its own top-level response
// key rather than folded into columns/rows: assets/js/reports.js has no
// "second table" concept, and the existing incident-type breakdown (this
// case's original columns/rows/summary) must not change shape.
$disposition_breakdown = [];
// GH#87/GH#88 (2026-08-19) — same "own top-level key" treatment as
// disposition_breakdown immediately above, and for the same reason:
// $columns/$rows are a plain positional table, and the severity
// breakdown is now a variable-length list (however many severity
// levels are configured), not a fixed 3-column shape a positional
// array could safely carry alongside a growing/shrinking column set.
// Only ever populated by the 'incident_summary' case below.
$severity_breakdown = [];
// GH#64 — same "own top-level key" treatment, for the SAME reason: a
// variable-length aggregate breakdown doesn't fit the fixed-shape
// columns/rows table. Only ever populated by the 'interval_report' case
// below; every other report gets [] and the JS only renders these when
// currentReport === 'interval_report' (see assets/js/reports.js), so a
// caller that doesn't know about these keys is unaffected either way —
// same discipline severity_breakdown/disposition_breakdown already
// established just above.
$interval_by_type = [];
$interval_by_unit = [];
// GH#96 — same "own top-level key" treatment as interval_by_type/
// interval_by_unit above, for the same reason. Only ever populated by the
// 'mileage_report' case below, computed by PHP-accumulating over the SAME
// already-fetched, already-filtered rows that build columns/rows -- not a
// second SQL query -- so the breakdowns always reflect exactly the
// filters currently applied, matching interval_report's precedent
// exactly (not incident_summary's disposition_breakdown, which IS a
// second query).
$mileage_by_org  = [];
$mileage_by_unit = [];
// Eric, 2026-08-13 (GH#51 follow-up + drill-down requests) — every report
// that lists incidents/units/facilities/members/teams should let a user
// click through to that record's real page. rows[] is a plain positional
// array with no room for a link target, so each linkable column gets a
// column-index variable (null = this report has no such column) plus a
// parallel array (same length/order as $rows) of the INTERNAL id to link
// to for each row. The visible cell text never changes (incident_number,
// a person's name, a team's name) -- the internal id appears only in the
// link target, never on screen, per Eric's stated rule that the number a
// user sees and the internal id used to locate a database row are two
// different things. Assembled into one generic 'links' array (see just
// before json_response) rather than five near-identical top-level
// response keys.
// Each *_link_cols is a list of column INDICES (usually one; the personnel
// reports link both the first-name and last-name columns to the same
// member, so this must support more than one column per kind).
$incident_link_cols = [];
$row_ticket_ids = [];
$unit_link_cols = [];
$row_responder_ids = [];
$facility_link_cols = [];
$row_facility_ids = [];
$member_link_cols = [];
$row_member_ids = [];
$team_link_cols = [];
$row_team_ids = [];
// A cell that lists MULTIPLE teams (roster_snapshot's comma-joined Teams
// column) can't be expressed as one id per row like every other link kind
// above -- each name in the cell needs its OWN link. row_team_lists[$r] is
// an array of ['id'=>teamId,'name'=>teamName] for row $r; the 'team_names'
// column's flat string value is left alone (still what CSV export and
// sorting see) and the frontend replaces just that cell's rendering with
// one <a> per item when a team_multi descriptor names its column.
$team_multi_link_cols = [];
$row_team_lists = [];
// Incident Summary -> type -> filtered Incidents list. Not a same-kind
// {col,ids} descriptor like the others above -- clicking a type doesn't
// open one record, it switches report tabs and re-runs filtered by
// in_types_id, so the frontend handles this kind with a click handler
// rather than a plain href (see assets/js/reports.js renderRows()).
$incident_type_link_cols = [];
$row_type_ids = [];

switch ($report) {

    // ── UNIT LOG ──────────────────────────────────────────────────────────
    case 'unit_log':
        $report_title = 'Unit Activity Log';
        $columns = ['Unit Name', 'Handle', 'Incident #', 'Scope', 'Dispatched', 'Responding', 'On-Scene', 'Clear', 'Response Time'];

        // Soft-delete sweep (issue #25 follow-up) — seeded first so it
        // survives the optional filters below, same pattern used
        // throughout this file's other report cases.
        $where_parts = [
            "`a`.`dispatched` BETWEEN ? AND ?",
            "(`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
        ];
        $params = [$date_start_sql, $date_end_sql];

        if ($responder_id > 0) {
            $where_parts[] = "`a`.`responder_id` = ?";
            $params[] = $responder_id;
        }
        if ($incident_id > 0) {
            $where_parts[] = "`a`.`ticket_id` = ?";
            $params[] = $incident_id;
        }

        $where = implode(' AND ', $where_parts);
        // Phase 99j-7 — append org-scope filter (empty for Super Admin).
        $where .= $rptTicketFrag;
        $params = array_merge($params, $rptTicketVars);

        $data = safe_fetch_all_rpt(
            "SELECT
                `r`.`id` AS `responder_id`,
                `r`.`name` AS `unit_name`,
                `r`.`handle`,
                `t`.`id` AS `ticket_id`,
                `t`.`incident_number`,
                `t`.`scope`,
                `a`.`dispatched`,
                `a`.`responding`,
                `a`.`on_scene`,
                `a`.`clear`,
                TIMESTAMPDIFF(SECOND, `a`.`dispatched`, `a`.`responding`) AS `response_secs`
            FROM `{$prefix}assigns` `a`
            LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
            LEFT JOIN `{$prefix}ticket` `t` ON `a`.`ticket_id` = `t`.`id`
            WHERE {$where}
            ORDER BY `a`.`dispatched` DESC",
            $params
        );

        $unit_link_cols[] = 0;
        $incident_link_cols[] = 2;
        $total_response = 0;
        $response_count = 0;

        foreach ($data as $row) {
            $resp_secs = $row['response_secs'] !== null ? (int) $row['response_secs'] : null;
            $resp_time = '';
            if ($resp_secs !== null && $resp_secs >= 0) {
                $resp_time = sprintf('%d:%02d', floor($resp_secs / 60), $resp_secs % 60);
                $total_response += $resp_secs;
                $response_count++;
            }

            $rows[] = [
                $row['unit_name'] ?? '',
                $row['handle'] ?? '',
                (!empty($row['incident_number']) ? $row['incident_number'] : ($row['ticket_id'] ? '#' . $row['ticket_id'] : '')),
                $row['scope'] ?? '',
                $row['dispatched'] ?? '',
                $row['responding'] ?? '',
                $row['on_scene'] ?? '',
                $row['clear'] ?? '',
                $resp_time
            ];
            $row_ticket_ids[] = (int) ($row['ticket_id'] ?? 0);
            $row_responder_ids[] = (int) ($row['responder_id'] ?? 0);
        }

        $avg_response = $response_count > 0 ? round($total_response / $response_count) : 0;
        $summary = [
            'total_assignments' => count($data),
            'avg_response_time' => $avg_response > 0 ? sprintf('%d:%02d', floor($avg_response / 60), $avg_response % 60) : 'N/A'
        ];
        break;

    // ── DISPATCH LOG ──────────────────────────────────────────────────────
    case 'dispatch_log':
        $report_title = 'Dispatch Log';
        $columns = ['Incident #', 'Type', 'Severity', 'Scope', 'Unit', 'Dispatched', 'Responding', 'On-Scene', 'Clear', 'Total Time'];

        // Soft-delete sweep (issue #25 follow-up) — see 'unit_log' above.
        $where_parts = [
            "`a`.`dispatched` BETWEEN ? AND ?",
            "(`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
        ];
        $params = [$date_start_sql, $date_end_sql];

        if ($responder_id > 0) {
            $where_parts[] = "`a`.`responder_id` = ?";
            $params[] = $responder_id;
        }

        $where = implode(' AND ', $where_parts);
        // Phase 99j-7 — append org-scope filter (empty for Super Admin).
        $where .= $rptTicketFrag;
        $params = array_merge($params, $rptTicketVars);

        $data = safe_fetch_all_rpt(
            "SELECT
                `t`.`id` AS `ticket_id`,
                `t`.`incident_number`,
                `it`.`type` AS `incident_type`,
                `t`.`severity`,
                `t`.`scope`,
                `r`.`id` AS `responder_id`,
                `r`.`name` AS `unit_name`,
                `a`.`dispatched`,
                `a`.`responding`,
                `a`.`on_scene`,
                `a`.`clear`,
                TIMESTAMPDIFF(SECOND, `a`.`dispatched`, `a`.`clear`) AS `total_secs`
            FROM `{$prefix}assigns` `a`
            LEFT JOIN `{$prefix}ticket` `t` ON `a`.`ticket_id` = `t`.`id`
            LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
            LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
            WHERE {$where}
            ORDER BY `a`.`dispatched` DESC",
            $params
        );

        $incident_link_cols[] = 0;
        $unit_link_cols[] = 4;
        // GH#87/GH#88 (2026-08-19) — sourced from the configurable
        // severity_levels table (inc/severity.php) instead of a
        // hardcoded 3-entry map.
        $sev_labels = severity_label_map();
        $total_time_sum = 0;
        $total_time_count = 0;

        foreach ($data as $row) {
            $total_secs = $row['total_secs'] !== null ? (int) $row['total_secs'] : null;
            $total_time = '';
            if ($total_secs !== null && $total_secs >= 0) {
                $total_time = sprintf('%d:%02d', floor($total_secs / 60), $total_secs % 60);
                $total_time_sum += $total_secs;
                $total_time_count++;
            }

            $rows[] = [
                (!empty($row['incident_number']) ? $row['incident_number'] : ($row['ticket_id'] ? '#' . $row['ticket_id'] : '')),
                $row['incident_type'] ?? '',
                $sev_labels[(int) ($row['severity'] ?? 0)] ?? 'Unknown',
                $row['scope'] ?? '',
                $row['unit_name'] ?? '',
                $row['dispatched'] ?? '',
                $row['responding'] ?? '',
                $row['on_scene'] ?? '',
                $row['clear'] ?? '',
                $total_time
            ];
            $row_ticket_ids[] = (int) ($row['ticket_id'] ?? 0);
            $row_responder_ids[] = (int) ($row['responder_id'] ?? 0);
        }

        $avg_total = $total_time_count > 0 ? round($total_time_sum / $total_time_count) : 0;
        $summary = [
            'total_dispatches'  => count($data),
            'avg_total_time'    => $avg_total > 0 ? sprintf('%d:%02d', floor($avg_total / 60), $avg_total % 60) : 'N/A'
        ];
        break;

    // ── INTERVAL REPORT (GH#64) ─────────────────────────────────────────────
    // Response / scene / transport time intervals over the six assigns
    // milestones (dispatched, responding, on_scene, u2fenr, u2farr, clear).
    // GH#64's reporter confirmed the facility-leg columns (u2fenr/u2farr)
    // are populated by real dispatch traffic (v4.2.21) but that nothing in
    // the tree computed intervals from ANY of the six milestones for
    // reporting purposes -- api/statistics.php's dashboard averages and
    // unit_log/dispatch_log's own response_secs/total_secs are the closest
    // things that existed, and none of them touch on_scene->clear (scene
    // time), the facility leg, or a by-type/by-unit breakdown. This case
    // is the first place all six are read together.
    //
    // Interval math lives in inc/interval-report.php (interval_report_compute()/
    // interval_report_fmt()) -- pure functions, unit-tested directly in
    // tests/test_interval_report_math.php, and driven here against real
    // `assigns` rows. Per GH#64's explicit requirement: an incident missing
    // some milestones (the common no-transport case has no u2fenr/u2farr at
    // all) computes whichever legs it CAN from the milestones it has, and
    // leaves the rest blank -- never an error, never a garbage duration.
    case 'interval_report':
        $report_title = 'Interval Report';
        $columns = ['Incident #', 'Type', 'Unit', 'Dispatched', 'Responding', 'On-Scene',
                    'To Facility', 'At Facility', 'Clear',
                    'Turnout', 'Travel', 'Response', 'Scene', 'Transport', 'Total'];

        // Soft-delete sweep (issue #25 follow-up) — see 'unit_log' above.
        $where_parts = [
            "`a`.`dispatched` BETWEEN ? AND ?",
            "(`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
        ];
        $params = [$date_start_sql, $date_end_sql];

        if ($responder_id > 0) {
            $where_parts[] = "`a`.`responder_id` = ?";
            $params[] = $responder_id;
        }
        if ($incident_id > 0) {
            $where_parts[] = "`a`.`ticket_id` = ?";
            $params[] = $incident_id;
        }

        $where = implode(' AND ', $where_parts);
        // Phase 99j-7 — append org-scope filter (empty for Super Admin).
        $where .= $rptTicketFrag;
        $params = array_merge($params, $rptTicketVars);

        $data = safe_fetch_all_rpt(
            "SELECT
                `t`.`id` AS `ticket_id`,
                `t`.`incident_number`,
                `it`.`id` AS `type_id`,
                COALESCE(`it`.`type`, 'Unknown') AS `incident_type`,
                `r`.`id` AS `responder_id`,
                `r`.`name` AS `unit_name`,
                `r`.`handle`,
                `a`.`dispatched`,
                `a`.`responding`,
                `a`.`on_scene`,
                `a`.`u2fenr`,
                `a`.`u2farr`,
                `a`.`clear`
            FROM `{$prefix}assigns` `a`
            LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
            LEFT JOIN `{$prefix}ticket` `t` ON `a`.`ticket_id` = `t`.`id`
            LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
            WHERE {$where}
            ORDER BY `a`.`dispatched` DESC",
            $params
        );

        $incident_link_cols[] = 0;
        $unit_link_cols[] = 2;

        // Running sums for the overall (period-wide) averages, one
        // accumulator per leg so a leg with fewer populated rows than
        // another (e.g. far fewer transports than responses) still
        // averages correctly over only the rows that actually have it.
        $legSums   = ['turnout' => 0, 'travel' => 0, 'response' => 0, 'scene' => 0, 'transport' => 0, 'total' => 0];
        $legCounts = ['turnout' => 0, 'travel' => 0, 'response' => 0, 'scene' => 0, 'transport' => 0, 'total' => 0];

        // By-type / by-unit breakdown accumulators (Eric, GH#64 — "average
        // response time this month, by incident type, by unit"). Keyed by
        // id so rows for the same type/unit accumulate together regardless
        // of how many separate assignment rows they came from.
        $byType = [];
        $byUnit = [];

        foreach ($data as $row) {
            $legs = interval_report_compute($row);

            $rows[] = [
                (!empty($row['incident_number']) ? $row['incident_number'] : ($row['ticket_id'] ? '#' . $row['ticket_id'] : '')),
                $row['incident_type'] ?? '',
                trim((string) ($row['handle'] ?: $row['unit_name'])),
                $row['dispatched'] ?? '',
                $row['responding'] ?? '',
                $row['on_scene'] ?? '',
                $row['u2fenr'] ?? '',
                $row['u2farr'] ?? '',
                $row['clear'] ?? '',
                interval_report_fmt($legs['turnout_secs']),
                interval_report_fmt($legs['travel_secs']),
                interval_report_fmt($legs['response_secs']),
                interval_report_fmt($legs['scene_secs']),
                interval_report_fmt($legs['transport_secs']),
                interval_report_fmt($legs['total_secs']),
            ];
            $row_ticket_ids[] = (int) ($row['ticket_id'] ?? 0);
            $row_responder_ids[] = (int) ($row['responder_id'] ?? 0);

            foreach (['turnout' => 'turnout_secs', 'travel' => 'travel_secs', 'response' => 'response_secs',
                      'scene' => 'scene_secs', 'transport' => 'transport_secs', 'total' => 'total_secs'] as $legKey => $secsKey) {
                if ($legs[$secsKey] !== null) {
                    $legSums[$legKey]   += $legs[$secsKey];
                    $legCounts[$legKey] += 1;
                }
            }

            $typeId = (int) ($row['type_id'] ?? 0);
            $typeLabel = $row['incident_type'] ?? 'Unknown';
            if (!isset($byType[$typeId])) {
                $byType[$typeId] = ['id' => $typeId, 'label' => $typeLabel, 'count' => 0,
                                     'resp_sum' => 0, 'resp_n' => 0, 'scene_sum' => 0, 'scene_n' => 0];
            }
            $byType[$typeId]['count']++;
            if ($legs['response_secs'] !== null) { $byType[$typeId]['resp_sum'] += $legs['response_secs']; $byType[$typeId]['resp_n']++; }
            if ($legs['scene_secs'] !== null)    { $byType[$typeId]['scene_sum'] += $legs['scene_secs']; $byType[$typeId]['scene_n']++; }

            $unitId = (int) ($row['responder_id'] ?? 0);
            $unitLabel = trim((string) ($row['handle'] ?: $row['unit_name'])) ?: ('unit #' . $unitId);
            if (!isset($byUnit[$unitId])) {
                $byUnit[$unitId] = ['id' => $unitId, 'label' => $unitLabel, 'count' => 0,
                                     'resp_sum' => 0, 'resp_n' => 0, 'scene_sum' => 0, 'scene_n' => 0];
            }
            $byUnit[$unitId]['count']++;
            if ($legs['response_secs'] !== null) { $byUnit[$unitId]['resp_sum'] += $legs['response_secs']; $byUnit[$unitId]['resp_n']++; }
            if ($legs['scene_secs'] !== null)    { $byUnit[$unitId]['scene_sum'] += $legs['scene_secs']; $byUnit[$unitId]['scene_n']++; }
        }

        $avgFmt = function (int $sum, int $n): ?string {
            return $n > 0 ? interval_report_fmt((int) round($sum / $n)) : null;
        };

        foreach ($byType as $t) {
            $interval_by_type[] = [
                'id'                => $t['id'],
                'label'             => $t['label'],
                'count'             => $t['count'],
                'avg_response_time' => $avgFmt($t['resp_sum'], $t['resp_n']),
                'avg_scene_time'    => $avgFmt($t['scene_sum'], $t['scene_n']),
            ];
        }
        usort($interval_by_type, fn($a, $b) => $b['count'] <=> $a['count']);

        foreach ($byUnit as $u) {
            $interval_by_unit[] = [
                'id'                => $u['id'],
                'label'             => $u['label'],
                'count'             => $u['count'],
                'avg_response_time' => $avgFmt($u['resp_sum'], $u['resp_n']),
                'avg_scene_time'    => $avgFmt($u['scene_sum'], $u['scene_n']),
            ];
        }
        usort($interval_by_unit, fn($a, $b) => $b['count'] <=> $a['count']);

        $summary = [
            'total_assignments'  => count($data),
            'avg_turnout_time'   => $legCounts['turnout']   > 0 ? interval_report_fmt((int) round($legSums['turnout']   / $legCounts['turnout']))   : 'N/A',
            'avg_travel_time'    => $legCounts['travel']    > 0 ? interval_report_fmt((int) round($legSums['travel']    / $legCounts['travel']))    : 'N/A',
            'avg_response_time'  => $legCounts['response']  > 0 ? interval_report_fmt((int) round($legSums['response']  / $legCounts['response']))  : 'N/A',
            'avg_scene_time'     => $legCounts['scene']     > 0 ? interval_report_fmt((int) round($legSums['scene']     / $legCounts['scene']))     : 'N/A',
            'avg_transport_time' => $legCounts['transport'] > 0 ? interval_report_fmt((int) round($legSums['transport'] / $legCounts['transport'])) : 'N/A',
            'avg_total_time'     => $legCounts['total']     > 0 ? interval_report_fmt((int) round($legSums['total']     / $legCounts['total']))     : 'N/A',
            'transports_count'   => $legCounts['transport'],
        ];
        break;

    // ── INCIDENT SUMMARY ──────────────────────────────────────────────────
    case 'incident_summary':
        $report_title = 'Incident Summary';

        // GH#87/GH#88 (2026-08-19) — this breakdown used to be 3
        // hardcoded `severity = 2/1/0` SQL buckets labeled "High/Medium/
        // Low Severity", so any level beyond the historical 3 was
        // silently absent from both the per-type rows and the grand
        // totals below — exactly the "would need a decision, not just an
        // edit" gap GH#88's own investigation named. Built from whatever
        // severity_levels are actually configured (inc/severity.php),
        // in the same display order the New Incident dropdown uses.
        // $columns/$rows is a plain positional table (assets/js/reports.js
        // renders it generically — see the column-count-agnostic loop
        // there), so a variable number of severity columns is safe here.
        $sevLevels = severity_levels_load();
        $sevCaseSql = [];
        foreach ($sevLevels as $lvl) {
            $v = (int) $lvl['value'];
            $sevCaseSql[] = "SUM(CASE WHEN `t`.`severity` = {$v} THEN 1 ELSE 0 END) AS `sev_{$v}`";
        }
        $columns = array_merge(
            ['Incident Type', 'Total'],
            array_map(function ($lvl) { return $lvl['label'] . ' Severity'; }, $sevLevels),
            ['Open', 'Closed']
        );

        // Soft-delete sweep (issue #25 follow-up) — both queries in this
        // case excluded, so a deleted incident can't skew the summary
        // counts or the average close-time figure below.
        $data = safe_fetch_all_rpt(
            "SELECT
                `it`.`id` AS `type_id`,
                COALESCE(`it`.`type`, 'Unknown') AS `incident_type`,
                COUNT(*) AS `total`,
                " . implode(",\n                ", $sevCaseSql) . ",
                SUM(CASE WHEN `t`.`status` = 2 THEN 1 ELSE 0 END) AS `open`,
                SUM(CASE WHEN `t`.`status` = 1 THEN 1 ELSE 0 END) AS `closed`
            FROM `{$prefix}ticket` `t`
            LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
            WHERE `t`.`date` BETWEEN ? AND ?
              AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
            GROUP BY `it`.`id`, `it`.`type`
            ORDER BY `total` DESC",
            [$date_start_sql, $date_end_sql]
        );

        // Eric, 2026-08-13 — "click on an incident type and view a list of
        // the incidents of that type". Rows in the 'Unknown' bucket (no
        // in_types_id, e.g. a deleted type) have no type_id to filter by
        // and are simply left unclickable — the JS only links ids > 0.
        $incident_type_link_cols[] = 0;

        $grand_total = 0;
        $grand_sev = [];
        foreach ($sevLevels as $lvl) { $grand_sev[(int) $lvl['value']] = 0; }
        $grand_open = 0;
        $grand_closed = 0;

        foreach ($data as $row) {
            $rowOut = [$row['incident_type'], (int) $row['total']];
            foreach ($sevLevels as $lvl) {
                $v = (int) $lvl['value'];
                $c = (int) ($row['sev_' . $v] ?? 0);
                $rowOut[] = $c;
                $grand_sev[$v] += $c;
            }
            $rowOut[] = (int) $row['open'];
            $rowOut[] = (int) $row['closed'];
            $rows[] = $rowOut;

            $row_type_ids[] = (int) ($row['type_id'] ?? 0);
            $grand_total  += (int) $row['total'];
            $grand_open   += (int) $row['open'];
            $grand_closed += (int) $row['closed'];
        }

        foreach ($sevLevels as $lvl) {
            $v = (int) $lvl['value'];
            $severity_breakdown[] = [
                'value' => $v,
                'label' => $lvl['label'],
                'color' => $lvl['color'],
                'count' => $grand_sev[$v] ?? 0,
            ];
        }

        // ── Disposition breakdown (Phase 132 Step 5, GH #16) ──────────
        // A SEPARATE breakdown from the incident-type rows above — added
        // ALONGSIDE them, not folded in (see $disposition_breakdown's
        // declaration above). Mirrors the incident-type breakdown's own
        // COALESCE(...,'Unknown') pattern: every historical/undispositioned
        // incident (NULL disposition_id) is the NORMAL state, not an
        // error, and must be counted in the totals rather than dropped —
        // tasks.md Step 5 ("Include the NULL bucket ... rather than
        // dropping it silently"). Same soft-delete sweep as the query
        // above (literal deleted_at term, not routed through
        // org_query_filter(), so tools/soft_delete_audit.php resolves it
        // directly — no exception-file entry needed).
        $dispositionData = safe_fetch_all_rpt(
            "SELECT
                COALESCE(`td`.`status_val`, 'No Disposition') AS `disposition_label`,
                COUNT(*) AS `total`
            FROM `{$prefix}ticket` `t`
            LEFT JOIN `{$prefix}ticket_disposition` `td` ON `t`.`disposition_id` = `td`.`id`
            WHERE `t`.`date` BETWEEN ? AND ?
              AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
            GROUP BY `td`.`status_val`
            ORDER BY `total` DESC",
            [$date_start_sql, $date_end_sql]
        );
        foreach ($dispositionData as $drow) {
            $disposition_breakdown[] = [
                'disposition' => $drow['disposition_label'],
                'total'       => (int) $drow['total'],
            ];
        }

        // Average time to close
        $avg_close = safe_fetch_value_rpt(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, `problemstart`, `problemend`))
             FROM `{$prefix}ticket`
             WHERE `status` = 1
               AND `problemstart` IS NOT NULL
               AND `problemend` IS NOT NULL
               AND `date` BETWEEN ? AND ?
               AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            [$date_start_sql, $date_end_sql]
        );

        // GH#87/GH#88 (2026-08-19) — 'high_severity'/'medium_severity'/
        // 'low_severity' (a fixed 3-key shape) are replaced by the
        // variable-length 'severity_breakdown' top-level key (built
        // above, one entry per configured level — see its own
        // declaration near the top of this file). Confirmed unused by
        // assets/js/reports.js before removing: nothing in the tree read
        // these three keys.
        $summary = [
            'total_incidents'     => $grand_total,
            'open_incidents'      => $grand_open,
            'closed_incidents'    => $grand_closed,
            'avg_close_time_mins' => $avg_close !== null ? round((float) $avg_close) : null
        ];
        break;

    // ── INCIDENT REPORT ───────────────────────────────────────────────────
    case 'incident_report':
        $report_title = 'Incident Report';
        $columns = ['ID', 'Scope', 'Type', 'Severity', 'Status', 'Location', 'Created', 'Closed', 'Units Assigned', 'Actions'];

        // Soft-delete sweep (issue #25 follow-up) — see 'unit_log' above.
        $where_parts = [
            "`t`.`date` BETWEEN ? AND ?",
            "(`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
        ];
        $params = [$date_start_sql, $date_end_sql];

        if ($incident_id > 0) {
            $where_parts[] = "`t`.`id` = ?";
            $params[] = $incident_id;
        }

        // Incident Summary -> type drill-down (Eric, 2026-08-13): a type
        // clicked in the Summary report re-runs this report filtered to it.
        $in_types_filter = max(0, (int) ($_GET['in_types_id'] ?? 0));
        if ($in_types_filter > 0) {
            $where_parts[] = "`t`.`in_types_id` = ?";
            $params[] = $in_types_filter;
        }

        // GH#57 follow-up (cbyrdmo, 2026-08-15): the responder-filter
        // dropdown on the Reports page is now shown for Incidents too
        // (assets/js/reports.js) -- this is what actually makes it filter.
        // EXISTS, not a JOIN: the main query below has no assigns join at
        // all (units_assigned is a subquery COUNT), and a ticket can have
        // more than one assigned unit -- a JOIN would duplicate incident
        // rows once per assignment.
        if ($responder_id > 0) {
            $where_parts[] = "EXISTS (SELECT 1 FROM `{$prefix}assigns` `ra`
                               WHERE `ra`.`ticket_id` = `t`.`id` AND `ra`.`responder_id` = ?)";
            $params[] = $responder_id;
        }

        $where = implode(' AND ', $where_parts);
        // Phase 99j-7 — append org-scope filter (empty for Super Admin).
        $where .= $rptTicketFrag;
        $params = array_merge($params, $rptTicketVars);

        $data = safe_fetch_all_rpt(
            "SELECT
                `t`.`id`,
                `t`.`incident_number`,
                `t`.`scope`,
                COALESCE(`it`.`type`, '') AS `incident_type`,
                `t`.`severity`,
                `t`.`status`,
                CONCAT_WS(', ', NULLIF(`t`.`street`,''), NULLIF(`t`.`city`,''), NULLIF(`t`.`state`,'')) AS `location`,
                `t`.`date` AS `created`,
                `t`.`problemend` AS `closed`,
                (SELECT COUNT(*) FROM `{$prefix}assigns` WHERE `ticket_id` = `t`.`id`) AS `units_assigned`,
                (SELECT COUNT(*) FROM `{$prefix}action` WHERE `ticket_id` = `t`.`id`) AS `actions_count`
            FROM `{$prefix}ticket` `t`
            LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
            WHERE {$where}
            ORDER BY `t`.`date` DESC",
            $params
        );

        $incident_link_cols[] = 0;
        // GH#87/GH#88 (2026-08-19) — sourced from the configurable
        // severity_levels table (inc/severity.php) instead of a
        // hardcoded 3-entry map.
        $sev_labels = severity_label_map();
        $status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

        foreach ($data as $row) {
            $rows[] = [
                (!empty($row['incident_number']) ? $row['incident_number'] : '#' . $row['id']),
                $row['scope'] ?? '',
                $row['incident_type'],
                $sev_labels[(int) ($row['severity'] ?? 0)] ?? 'Unknown',
                $status_labels[(int) ($row['status'] ?? 2)] ?? 'Open',
                $row['location'] ?? '',
                $row['created'] ?? '',
                $row['closed'] ?? '',
                (int) $row['units_assigned'],
                (int) $row['actions_count']
            ];
            $row_ticket_ids[] = (int) ($row['id'] ?? 0);
        }

        $summary = [
            'total_incidents' => count($data)
        ];
        break;

    // ── FACILITY LOG ──────────────────────────────────────────────────────
    case 'facility_log':
        $report_title = 'Facility Log';
        $columns = ['Facility Name', 'Incident #', 'Scope', 'Unit', 'Dispatched', 'Arrived', 'Notes'];

        // Soft-delete sweep (issue #25 follow-up) — see 'unit_log' above.
        $where_parts = [
            "`t`.`date` BETWEEN ? AND ?",
            "(`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
        ];
        $params = [$date_start_sql, $date_end_sql];

        // GH#57 follow-up (2026-08-14): the responder-filter dropdown on
        // the Reports page is now shown for Facility Log too (assets/js/
        // reports.js), same as unit_log/dispatch_log above -- this
        // where-clause is what actually makes it filter instead of
        // rendering a control that silently does nothing.
        if ($responder_id > 0) {
            $where_parts[] = "`a`.`responder_id` = ?";
            $params[] = $responder_id;
        }

        $where = implode(' AND ', $where_parts);
        // Phase 99j-7 — append org-scope filter (empty for Super Admin).
        $where .= $rptTicketFrag;
        $params = array_merge($params, $rptTicketVars);

        // Tickets linked to facilities via rec_facility
        $data = safe_fetch_all_rpt(
            "SELECT
                `f`.`id` AS `facility_id`,
                `f`.`name` AS `facility_name`,
                `t`.`id` AS `ticket_id`,
                `t`.`incident_number`,
                `t`.`scope`,
                `r`.`id` AS `responder_id`,
                `r`.`name` AS `unit_name`,
                `a`.`dispatched`,
                `a`.`on_scene` AS `arrived`,
                `t`.`description` AS `notes`
            FROM `{$prefix}ticket` `t`
            INNER JOIN `{$prefix}facilities` `f` ON `t`.`rec_facility` = `f`.`id`
            LEFT JOIN `{$prefix}assigns` `a` ON `a`.`ticket_id` = `t`.`id`
            LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
            WHERE {$where}
            ORDER BY `f`.`name`, `a`.`dispatched` DESC",
            $params
        );

        $facility_link_cols[] = 0;
        $incident_link_cols[] = 1;
        $unit_link_cols[] = 3;

        foreach ($data as $row) {
            $rows[] = [
                $row['facility_name'] ?? '',
                (!empty($row['incident_number']) ? $row['incident_number'] : ($row['ticket_id'] ? '#' . $row['ticket_id'] : '')),
                $row['scope'] ?? '',
                $row['unit_name'] ?? '',
                $row['dispatched'] ?? '',
                $row['arrived'] ?? '',
                $row['notes'] ?? ''
            ];
            $row_ticket_ids[] = (int) ($row['ticket_id'] ?? 0);
            $row_responder_ids[] = (int) ($row['responder_id'] ?? 0);
            $row_facility_ids[] = (int) ($row['facility_id'] ?? 0);
        }

        $summary = [
            'total_entries' => count($data)
        ];
        break;

    // ── MILEAGE LOG (GH#96) ──────────────────────────────────────────────────
    // A neutral trip-log/utilization report over mileage_log -- org,
    // vehicle, driver, incident-link, odometer, miles, notes. Deliberately
    // NOT billing-flavored (no rate tables, no invoice/payment status, no
    // IRS-mileage-rate dollar conversion) -- see the GH#96 design synthesis
    // (multi-persona review: fire chief / ARES volunteer / patient-transport
    // coordinator / campus security / sysadmin) for why every persona who
    // had an opinion on billing scaffolding rejected building it here,
    // including the one persona who actually invoices (she owns separate
    // accounting software and doesn't want this to become a second system
    // of record). docs/NEWUI-USER-GUIDE.md documents the report for admins.
    case 'mileage_report':
        $report_title = 'Mileage Log';
        $columns = ['Organization', 'Vehicle', 'Driver', 'Incident #',
                    'Started', 'Ended', 'Start Odo', 'End Odo', 'Miles', 'Notes'];

        // Matches on started_at only (same convention dispatch_log uses on
        // a.dispatched) -- an OPEN trip (ended_at IS NULL) is included as
        // long as it started in the period; miles/end_odo render blank for
        // those, which is legitimate operational state, not an error.
        $where_parts = ["`ml`.`started_at` BETWEEN ? AND ?"];
        $params = [$date_start_sql, $date_end_sql];

        if ($responder_id > 0) {
            $where_parts[] = "`ml`.`responder_id` = ?";
            $params[] = $responder_id;
        }
        if ($driver_id > 0) {
            $where_parts[] = "`ml`.`user_id` = ?";
            $params[] = $driver_id;
        }
        // Organization filter — only honored when the requested org is
        // within the caller's own visibility (or the caller is Super
        // Admin); an unauthorized org_id is silently ignored rather than
        // erroring, matching this codebase's standing graceful-degradation
        // convention for filters.
        $mileageOrgAuthorized = false;
        if ($mileage_org_id > 0) {
            if (is_admin()) {
                $mileageOrgAuthorized = true;
            } else {
                $mlVisibleForFilter = org_visible_ids();
                if ($mlVisibleForFilter === null) {
                    $mileageOrgAuthorized = true;
                } elseif (in_array($mileage_org_id, array_map('intval', $mlVisibleForFilter), true)) {
                    $mileageOrgAuthorized = true;
                }
            }
        }
        if ($mileageOrgAuthorized) {
            $where_parts[] = "`ml`.`org_id` = ?";
            $params[] = $mileage_org_id;
        }

        $where = implode(' AND ', $where_parts);
        // GH#96 — deliberately org_mileage_query_filter(), NOT
        // org_ticket_query_filter(): org_id is a direct attribute of the
        // trip/vehicle itself, not a ticket-visibility question, so
        // cross-org ticket-sharing/relationship semantics don't apply here.
        [$mlScopeFrag, $mlScopeVars] = org_mileage_query_filter();
        $where .= $mlScopeFrag;
        $params = array_merge($params, $mlScopeVars);

        // Before writing the driver-name JOIN, confirmed via SHOW COLUMNS
        // that the `user` table's display-name columns are name_f/name_l
        // (not first_name/last_name) — same COALESCE(NULLIF(CONCAT(...)))
        // pattern this file already uses for `performed_by_name` (the
        // after_action case's action-log author resolution). Driver is
        // `user`, not `member` — never reuse the Personnel "Member"
        // selector's value against mileage_log.user_id (member.id and
        // user.id are not interchangeable in this schema; see the
        // responder.member_id/member.responder_id reversed-FK pitfall).
        $data = safe_fetch_all_rpt(
            "SELECT
                `ml`.`id`,
                `ml`.`org_id`, COALESCE(`o`.`name`, 'Unattributed') AS `org_label`,
                `ml`.`responder_id`, `r`.`name` AS `vehicle_name`, `r`.`handle`,
                `ml`.`user_id`,
                COALESCE(NULLIF(TRIM(CONCAT(`u`.`name_f`, ' ', `u`.`name_l`)), ''), `u`.`user`) AS `driver_name`,
                `ml`.`ticket_id`, `t`.`incident_number`,
                `ml`.`started_at`, `ml`.`ended_at`,
                `ml`.`start_odo`, `ml`.`end_odo`, `ml`.`miles`, `ml`.`notes`
             FROM `{$prefix}mileage_log` `ml`
             LEFT JOIN `{$prefix}organizations` `o` ON `ml`.`org_id` = `o`.`id`
             LEFT JOIN `{$prefix}responder` `r` ON `ml`.`responder_id` = `r`.`id`
             LEFT JOIN `{$prefix}user` `u` ON `ml`.`user_id` = `u`.`id`
             LEFT JOIN `{$prefix}ticket` `t` ON `ml`.`ticket_id` = `t`.`id`
             WHERE {$where}
             ORDER BY `org_label`, `vehicle_name`, `ml`.`started_at` ASC",
            $params
        );

        $unit_link_cols[]     = 1;
        $incident_link_cols[] = 3;

        $mileageByOrgAcc  = [];
        $mileageByUnitAcc = [];
        $totalMiles         = 0.0;
        $completedTripCount = 0;
        $openTripCount       = 0;
        $unattributedCount   = 0;

        foreach ($data as $row) {
            $vehicleLabel = trim((string) ($row['handle'] ?: $row['vehicle_name'])) ?: ('unit #' . (int) $row['responder_id']);
            $incidentCell = '—';
            if (!empty($row['ticket_id'])) {
                $incidentCell = !empty($row['incident_number'])
                    ? $row['incident_number']
                    : ('#' . $row['ticket_id']);
            }
            $milesVal = $row['miles'] !== null ? (float) $row['miles'] : null;
            // "Open" means no end reading yet -- keyed on end_odo, NOT
            // ended_at. A status-extra-data mileage entry (the un_status
            // extra_data_type='mileage' prompt, any target) is captured as
            // ONE instant reading -- inc/responder-write.php's
            // _phase95_record_mileage_log() sets start_odo=0/end_odo=$miles
            // together but never touches ended_at at all, since there is no
            // discrete "stop" event for that entry shape (unlike the mobile
            // app's two-phase start_mileage/stop_mileage trip tracker,
            // which DOES set ended_at on stop). Keying "open" on ended_at
            // would misclassify every such entry as perpetually open even
            // though it carries a complete odometer delta and a real miles
            // value -- confirmed live (your-server.example.com) via a real
            // status-change mileage entry rendering Miles=42 correctly
            // while showing as "open" until this fix.
            $isOpen   = $row['end_odo'] === null;

            $rows[] = [
                $row['org_label'] ?? 'Unattributed',
                $vehicleLabel,
                $row['driver_name'] ?? '',
                $incidentCell,
                $row['started_at'] ?? '',
                $row['ended_at'] ?? '',
                $row['start_odo'] !== null ? $row['start_odo'] : '',
                $row['end_odo'] !== null ? $row['end_odo'] : '',
                $milesVal !== null ? $milesVal : '',
                $row['notes'] ?? '',
            ];
            $row_responder_ids[] = (int) ($row['responder_id'] ?? 0);
            $row_ticket_ids[]    = (int) ($row['ticket_id'] ?? 0);

            if ($isOpen) {
                $openTripCount++;
            } elseif ($row['start_odo'] !== null && $row['end_odo'] !== null) {
                $completedTripCount++;
            }
            if (empty($row['org_id'])) { $unattributedCount++; }
            if ($milesVal !== null) { $totalMiles += $milesVal; }

            $orgKey = (int) ($row['org_id'] ?? 0);
            if (!isset($mileageByOrgAcc[$orgKey])) {
                $mileageByOrgAcc[$orgKey] = ['id' => $orgKey, 'label' => $row['org_label'] ?? 'Unattributed',
                                              'trip_count' => 0, 'total_miles' => 0.0];
            }
            $mileageByOrgAcc[$orgKey]['trip_count']++;
            if ($milesVal !== null) { $mileageByOrgAcc[$orgKey]['total_miles'] += $milesVal; }

            $unitKey = (int) ($row['responder_id'] ?? 0);
            if (!isset($mileageByUnitAcc[$unitKey])) {
                $mileageByUnitAcc[$unitKey] = ['id' => $unitKey, 'label' => $vehicleLabel,
                                                'trip_count' => 0, 'total_miles' => 0.0, 'last_logged' => null];
            }
            $mileageByUnitAcc[$unitKey]['trip_count']++;
            if ($milesVal !== null) { $mileageByUnitAcc[$unitKey]['total_miles'] += $milesVal; }
            $startedAt = (string) ($row['started_at'] ?? '');
            if ($startedAt !== '' && ($mileageByUnitAcc[$unitKey]['last_logged'] === null
                    || $startedAt > $mileageByUnitAcc[$unitKey]['last_logged'])) {
                $mileageByUnitAcc[$unitKey]['last_logged'] = $startedAt;
            }
        }

        foreach ($mileageByOrgAcc as $orgAgg) {
            $orgAgg['total_miles'] = round($orgAgg['total_miles'], 2);
            $mileage_by_org[] = $orgAgg;
        }
        usort($mileage_by_org, fn($a, $b) => $b['total_miles'] <=> $a['total_miles']);

        foreach ($mileageByUnitAcc as $unitAgg) {
            $unitAgg['total_miles'] = round($unitAgg['total_miles'], 2);
            $mileage_by_unit[] = $unitAgg;
        }
        usort($mileage_by_unit, fn($a, $b) => $b['total_miles'] <=> $a['total_miles']);

        $summary = [
            'report_title'            => 'Mileage Log',
            'period_label'            => $period_label,
            'trip_count'              => count($data),
            'total_miles'             => round($totalMiles, 2),
            'completed_trip_count'    => $completedTripCount,
            'open_trip_count'         => $openTripCount,
            'unattributed_trip_count' => $unattributedCount,
        ];
        break;

    // ── FACILITY BED ADJUSTMENTS (GH#102) ───────────────────────────────────
    // A merged, chronological timeline over the two independent bed-count
    // audit tables: facility_bed_auto_log (inc/bed_auto.php's automatic
    // decrement on delivery) and facility_bed_release_log (a facility's
    // own self-release via the facility portal, inc/facility-bed-release.php
    // -- GH#102's fix for the reported one-way ratchet). Neither table is
    // written by a dispatcher's manual "Fac's > Edit" correction -- that
    // path only writes facility_notes + the standard audit log (see
    // api/facility-action.php's `beds` action), so a manual correction
    // shows up here as an unexplained gap between two rows rather than a
    // third row type; that gap is itself the signal an operator needs to
    // reconcile drift, per the reporter's own framing ("no report... would
    // let anyone reconstruct it after the fact").
    //
    // Two separate queries (the tables have different shapes -- one keys
    // off an assignment/status change, the other off a free-form facility
    // action) merged and re-sorted in PHP rather than a SQL UNION, matching
    // this file's own established pattern for cross-shape merges. Newest
    // first -- this is an audit/QA report ("who moved this number and
    // why"), not a chronological trip log like mileage_report.
    case 'facility_bed_adjustments':
        $report_title = 'Facility Bed Adjustments';
        $columns = ['Facility', 'When', 'Source', 'Available Δ', 'Occupied Δ', 'Actor', 'Detail'];

        $bedWhereAuto = ["`bal`.`applied_at` BETWEEN ? AND ?"];
        $bedParamsAuto = [$date_start_sql, $date_end_sql];
        $bedWhereRel = ["`brl`.`applied_at` BETWEEN ? AND ?"];
        $bedParamsRel = [$date_start_sql, $date_end_sql];
        if ($bed_facility_id > 0) {
            $bedWhereAuto[] = "`bal`.`facility_id` = ?";
            $bedParamsAuto[] = $bed_facility_id;
            $bedWhereRel[] = "`brl`.`facility_id` = ?";
            $bedParamsRel[] = $bed_facility_id;
        }

        $autoRows = safe_fetch_all_rpt(
            "SELECT `bal`.`facility_id`, `f`.`name` AS `facility_name`,
                    `bal`.`applied_at`, `bal`.`delta_a`, `bal`.`delta_o`,
                    `bal`.`ticket_id`, `t`.`incident_number`,
                    `bal`.`responder_id`, `r`.`name` AS `unit_name`,
                    `bal`.`status_val`,
                    COALESCE(NULLIF(TRIM(CONCAT(`u`.`name_f`, ' ', `u`.`name_l`)), ''), `u`.`user`) AS `actor_name`
             FROM `{$prefix}facility_bed_auto_log` `bal`
             LEFT JOIN `{$prefix}facilities` `f` ON `bal`.`facility_id` = `f`.`id`
             LEFT JOIN `{$prefix}ticket` `t` ON `bal`.`ticket_id` = `t`.`id`
             LEFT JOIN `{$prefix}responder` `r` ON `bal`.`responder_id` = `r`.`id`
             LEFT JOIN `{$prefix}user` `u` ON `bal`.`applied_by` = `u`.`id`
             WHERE " . implode(' AND ', $bedWhereAuto),
            $bedParamsAuto
        );

        $releaseRows = safe_fetch_all_rpt(
            "SELECT `brl`.`facility_id`, `f`.`name` AS `facility_name`,
                    `brl`.`applied_at`, `brl`.`delta_a`, `brl`.`delta_o`,
                    `brl`.`note`, `brl`.`released_by`, `brl`.`released_by_name`
             FROM `{$prefix}facility_bed_release_log` `brl`
             LEFT JOIN `{$prefix}facilities` `f` ON `brl`.`facility_id` = `f`.`id`
             WHERE " . implode(' AND ', $bedWhereRel),
            $bedParamsRel
        );

        $merged = [];
        foreach ($autoRows as $row) {
            $merged[] = [
                'applied_at'   => $row['applied_at'] ?? '',
                'facility_id'  => (int) ($row['facility_id'] ?? 0),
                'facility'     => $row['facility_name'] ?? ('facility #' . ($row['facility_id'] ?? 0)),
                'source'       => 'Automatic (delivery)',
                'delta_a'      => (int) ($row['delta_a'] ?? 0),
                'delta_o'      => (int) ($row['delta_o'] ?? 0),
                'actor'        => trim((string) ($row['actor_name'] ?? '')) ?: 'system',
                'detail'       => trim(
                    ($row['unit_name'] ? ('Unit ' . $row['unit_name']) : 'Unit')
                    . ($row['status_val'] ? (' → ' . $row['status_val']) : '')
                ),
                'ticket_id'    => (int) ($row['ticket_id'] ?? 0),
                'incident_num' => $row['incident_number'] ?? null,
                'responder_id' => (int) ($row['responder_id'] ?? 0),
            ];
        }
        foreach ($releaseRows as $row) {
            $merged[] = [
                'applied_at'   => $row['applied_at'] ?? '',
                'facility_id'  => (int) ($row['facility_id'] ?? 0),
                'facility'     => $row['facility_name'] ?? ('facility #' . ($row['facility_id'] ?? 0)),
                'source'       => 'Facility self-release',
                'delta_a'      => (int) ($row['delta_a'] ?? 0),
                'delta_o'      => (int) ($row['delta_o'] ?? 0),
                // released_by_name is the denormalized display name at
                // the moment of release; fall back to the real, permanent
                // released_by user id if the name ever ends up blank
                // (e.g. a legacy row from before this column existed) so
                // the actor is never silently unattributed.
                'actor'        => trim((string) ($row['released_by_name'] ?? ''))
                    ?: (((int) ($row['released_by'] ?? 0) > 0) ? ('user #' . (int) $row['released_by']) : 'facility account'),
                'detail'       => trim((string) ($row['note'] ?? '')),
                'ticket_id'    => 0,
                'incident_num' => null,
                'responder_id' => 0,
            ];
        }

        usort($merged, function ($a, $b) {
            return strcmp((string) $b['applied_at'], (string) $a['applied_at']); // newest first
        });

        // Facility (col 0) drill-down only. The incident number, when
        // present, is folded into the free-text Detail column below rather
        // than given its own linked column -- it only applies to
        // auto-decrement rows, and this report's job is "what moved this
        // facility's counter", not a second incident-drilldown surface.
        $facility_link_cols[] = 0;

        $autoCount = 0;
        $releaseCount = 0;
        $bedsReleasedTotal = 0;
        foreach ($merged as $m) {
            $incidentCell = '—';
            if ($m['ticket_id'] > 0) {
                $incidentCell = !empty($m['incident_num']) ? $m['incident_num'] : ('#' . $m['ticket_id']);
            }
            $rows[] = [
                $m['facility'],
                $m['applied_at'],
                $m['source'],
                ($m['delta_a'] > 0 ? '+' : '') . $m['delta_a'],
                ($m['delta_o'] > 0 ? '+' : '') . $m['delta_o'],
                $m['actor'],
                $m['detail'] . ($m['ticket_id'] > 0 ? (' (' . $incidentCell . ')') : ''),
            ];
            $row_facility_ids[] = $m['facility_id'];
            if ($m['source'] === 'Automatic (delivery)') {
                $autoCount++;
            } else {
                $releaseCount++;
                $bedsReleasedTotal += max(0, $m['delta_a']);
            }
        }

        $summary = [
            'report_title'         => 'Facility Bed Adjustments',
            'period_label'         => $period_label,
            'adjustment_count'     => count($merged),
            'auto_decrement_count' => $autoCount,
            'self_release_count'   => $releaseCount,
            'beds_released_total'  => $bedsReleasedTotal,
        ];
        break;

    // ── NOTES LOG (GH #81) ─────────────────────────────────────────────────
    // Every unit (responder_notes) + facility (facility_notes) note in the
    // period, newest first. Admin-only aggregate (gated above) unless filtered
    // to a specific unit via responder_id, which the framework already access-
    // checks. Defensive: either notes table may be absent on an older install —
    // safe_fetch_all_rpt returns [] (and error_logs) rather than 500-ing.
    case 'notes_log':
        $report_title = 'Notes Log';
        $columns = ['When', 'Type', 'Unit / Facility', 'Note', 'By'];

        $merged = [];

        // Unit notes. Honor a responder_id filter if one was supplied.
        $unitWhere  = "`n`.`deleted_at` IS NULL AND `n`.`created_at` BETWEEN ? AND ?";
        $unitParams = [$date_start_sql, $date_end_sql];
        if ($responder_id > 0) {
            $unitWhere   .= " AND `n`.`responder_id` = ?";
            $unitParams[] = $responder_id;
        }
        $unitNotes = safe_fetch_all_rpt(
            "SELECT `n`.`created_at`, `n`.`note`, `n`.`by_username` AS `author`,
                    COALESCE(NULLIF(`r`.`handle`, ''), `r`.`name`, CONCAT('unit #', `n`.`responder_id`)) AS `entity`
               FROM `{$prefix}responder_notes` `n`
               LEFT JOIN `{$prefix}responder` `r` ON `n`.`responder_id` = `r`.`id`
              WHERE {$unitWhere}",
            $unitParams
        );
        foreach ($unitNotes as $n) {
            $merged[] = ['when' => $n['created_at'], 'type' => 'Unit',
                         'entity' => $n['entity'] ?? '', 'note' => $n['note'] ?? '',
                         'by' => $n['author'] ?? ''];
        }

        // Facility notes — only in the unfiltered (all-notes) view; a unit
        // filter has no facility analogue.
        $facNotes = [];
        if ($responder_id <= 0) {
            $facNotes = safe_fetch_all_rpt(
                "SELECT `n`.`created_at`, `n`.`note`, `n`.`username` AS `author`,
                        COALESCE(`f`.`name`, CONCAT('facility #', `n`.`facility_id`)) AS `entity`
                   FROM `{$prefix}facility_notes` `n`
                   LEFT JOIN `{$prefix}facilities` `f` ON `n`.`facility_id` = `f`.`id`
                  WHERE `n`.`created_at` BETWEEN ? AND ?",
                [$date_start_sql, $date_end_sql]
            );
            foreach ($facNotes as $n) {
                $merged[] = ['when' => $n['created_at'], 'type' => 'Facility',
                             'entity' => $n['entity'] ?? '', 'note' => $n['note'] ?? '',
                             'by' => $n['author'] ?? ''];
            }
        }

        // Newest first across both sources.
        usort($merged, fn($a, $b) => strcmp((string) $b['when'], (string) $a['when']));
        foreach ($merged as $m) {
            $rows[] = [$m['when'], $m['type'], $m['entity'], $m['note'], $m['by']];
        }

        $summary = [
            'total_notes'    => count($merged),
            'unit_notes'     => count($unitNotes),
            'facility_notes' => count($facNotes),
        ];
        break;

    // ── AFTER ACTION ──────────────────────────────────────────────────────
    case 'after_action':
        $report_title = 'After Action Report';

        if ($incident_id <= 0) {
            if ($incident_input !== '') {
                json_error("No incident found matching '{$incident_input}'", 404);
            }
            json_error('Enter an incident number for the After Action report', 400);
        }

        // Incident details
        // Soft-delete sweep (issue #25 follow-up) — a soft-deleted
        // incident must not produce an after-action report; falls through
        // to the same "not found" the missing-id case already returns.
        $ticket = null;
        try {
            $ticket = db_fetch_one(
                "SELECT `t`.*, `it`.`type` AS `incident_type`, `it`.`protocol`
                 FROM `{$prefix}ticket` `t`
                 LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
                 WHERE `t`.`id` = ?
                   AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
                [$incident_id]
            );
        } catch (Exception $e) {
            // fallback
        }

        if (!$ticket) {
            json_error('Incident not found', 404);
        }

        // GH#87/GH#88 (2026-08-19) — sourced from the configurable
        // severity_levels table (inc/severity.php) instead of a
        // hardcoded 3-entry map.
        $sev_labels  = severity_label_map();
        $stat_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

        // Assignments
        $columns = ['Time', 'Event', 'Unit / User', 'Details'];

        $assigns_data = safe_fetch_all_rpt(
            "SELECT `a`.*, `r`.`name` AS `unit_name`, `r`.`handle`
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
             WHERE `a`.`ticket_id` = ?
             ORDER BY `a`.`dispatched`",
            [$incident_id]
        );

        // Build timeline entries
        $timeline = [];
        foreach ($assigns_data as $a) {
            $unit = ($a['unit_name'] ?? '') . ($a['handle'] ? ' (' . $a['handle'] . ')' : '');
            if (!empty($a['dispatched'])) {
                $timeline[] = ['time' => $a['dispatched'], 'event' => 'Dispatched', 'who' => $unit, 'details' => ''];
            }
            if (!empty($a['responding']) && $a['responding'] !== '0000-00-00 00:00:00') {
                $timeline[] = ['time' => $a['responding'], 'event' => 'Responding', 'who' => $unit, 'details' => ''];
            }
            if (!empty($a['on_scene']) && $a['on_scene'] !== '0000-00-00 00:00:00') {
                $timeline[] = ['time' => $a['on_scene'], 'event' => 'On-Scene', 'who' => $unit, 'details' => ''];
            }
            if (!empty($a['clear']) && $a['clear'] !== '0000-00-00 00:00:00') {
                $timeline[] = ['time' => $a['clear'], 'event' => 'Cleared', 'who' => $unit, 'details' => ''];
            }
        }

        // Action log entries. GH#61 (rjonesbsink, 2026-08-14) -- the `action`
        // table has no `action` column, the narrative lives in `description`;
        // reading the wrong key via `??` silently produced an empty string
        // instead of a warning, so the report looked like it worked while
        // every row's details came through blank. Also resolve `user` (an
        // id) to a display name, matching api/equipment.php's own
        // performed_by_name pattern, instead of showing the raw number.
        $actions_data = safe_fetch_all_rpt(
            "SELECT `a`.*,
                    COALESCE(NULLIF(TRIM(CONCAT(u.name_f, ' ', u.name_l)), ''), u.`user`) AS performed_by_name
             FROM `{$prefix}action` `a`
             LEFT JOIN `{$prefix}user` `u` ON `a`.`user` = `u`.`id`
             WHERE `a`.`ticket_id` = ? ORDER BY `a`.`date`",
            [$incident_id]
        );

        foreach ($actions_data as $act) {
            $timeline[] = [
                'time'    => $act['date'] ?? '',
                'event'   => 'Action',
                'who'     => $act['performed_by_name'] ?? '',
                'details' => $act['description'] ?? ''
            ];
        }

        // Sort timeline by time
        usort($timeline, function ($a, $b) {
            return strcmp($a['time'], $b['time']);
        });

        foreach ($timeline as $entry) {
            $rows[] = [
                $entry['time'],
                $entry['event'],
                $entry['who'],
                $entry['details']
            ];
        }

        $location = implode(', ', array_filter([
            $ticket['street'] ?? '',
            $ticket['city'] ?? '',
            $ticket['state'] ?? ''
        ]));

        $summary = [
            'incident_id'     => $incident_id,
            'incident_number' => $ticket['incident_number'] ?? '',
            'scope'          => $ticket['scope'] ?? '',
            'incident_type'  => $ticket['incident_type'] ?? '',
            'severity'       => $sev_labels[(int) ($ticket['severity'] ?? 0)] ?? 'Unknown',
            'status'         => $stat_labels[(int) ($ticket['status'] ?? 2)] ?? 'Open',
            'location'       => $location,
            'description'    => $ticket['description'] ?? '',
            'protocol'       => $ticket['protocol'] ?? '',
            'problem_start'  => $ticket['problemstart'] ?? '',
            'problem_end'    => $ticket['problemend'] ?? '',
            'units_assigned' => count($assigns_data),
            'actions_count'  => count($actions_data)
        ];

        // GH#57 — the internal id is never the number an operator recognizes.
        // Lead with incident_number when the install has one; keep the raw id
        // as a parenthetical so the report still cross-references cleanly with
        // anything (logs, the database) that only knows the internal id.
        $period_label = !empty($ticket['incident_number'])
            ? $ticket['incident_number'] . ' (#' . $incident_id . ')'
            : 'Incident #' . $incident_id;
        break;

    // ─────────────────────────────────────────────────────────────────────
    // Personnel reports (item #21). All require admin; the gate above
    // already short-circuited non-admin callers with 403.
    // ─────────────────────────────────────────────────────────────────────

    case 'license_expirations':
        $report_title = 'License & Credential Expirations';
        // Threshold for "expiring soon" — 90 days unless overridden by ?days=
        $days = max(1, min(365, (int) ($_GET['days'] ?? 90)));
        $cutoff = date('Y-m-d', strtotime("+{$days} days"));
        $today  = date('Y-m-d');

        $columns = [
            ['key' => 'last_name',      'label' => 'Last'],
            ['key' => 'first_name',     'label' => 'First'],
            ['key' => 'callsign',       'label' => 'Callsign'],
            ['key' => 'license_kind',   'label' => 'Type'],
            ['key' => 'identifier',     'label' => 'Identifier'],
            ['key' => 'expiry_date',    'label' => 'Expires'],
            ['key' => 'days_remaining', 'label' => 'Days', 'align' => 'right'],
            ['key' => 'state',          'label' => 'Status'],
        ];
        $rows = [];

        // Personnel drill-down (GH#51 follow-up, 2026-08-13) — last_name /
        // first_name are always columns 0/1 across every personnel report;
        // Eric asked that clicking either name open that member's roster
        // record. member_id rides along as an extra row key (never a
        // visible column) and is extracted into row_member_ids AFTER the
        // switch, from the FINAL row order -- this report usort()s below,
        // so a parallel array built during these loops would desync from
        // the sorted result.
        $member_link_cols[] = 0;
        $member_link_cols[] = 1;

        // FCC amateur + GMRS via member_callsigns
        // GH#95 (rjonesbsink/cbyrdmo, 2026-08-20) — the member table carries
        // two generations of columns: legacy field1/field2/field4 and the
        // named last_name/first_name/callsign. On installs where the named
        // columns are plain (independently-writable, not GENERATED from the
        // legacy ones), a member created/edited through the roster UI lands
        // in the named columns and the legacy ones stay NULL — reading only
        // field1/field2/field4 rendered these reports blank. COALESCE(
        // NULLIF(named,''), legacy) prefers the named column and falls back
        // to legacy, matching the pattern already used by
        // api/equipment.php:316-317 and api/external/v1/teams.php:56-58. On
        // generated-column installs this is a no-op — the named column
        // already resolves to the legacy value.
        $fcc = safe_fetch_all_rpt(
            "SELECT m.id AS member_id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    mc.callsign AS identifier, mc.license_type, mc.expiry_date
             FROM `{$prefix}member_callsigns` mc
             JOIN `{$prefix}member` m ON mc.member_id = m.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
               AND mc.expiry_date IS NOT NULL
               AND mc.expiry_date <= ?
             ORDER BY mc.expiry_date ASC",
            array_merge($rptMemberVars, $memberIdVars, [$cutoff])
        );
        foreach ($fcc as $r) {
            $exp = strtotime((string) $r['expiry_date']);
            $daysRem = $exp ? (int) round(($exp - time()) / 86400) : null;
            $rows[] = [
                'last_name'      => $r['last_name']  ?? '',
                'first_name'     => $r['first_name'] ?? '',
                'callsign'       => $r['callsign']   ?? '',
                'license_kind'   => 'FCC ' . strtoupper((string) $r['license_type']),
                'identifier'     => $r['identifier'] ?? '',
                'expiry_date'    => $r['expiry_date'],
                'days_remaining' => $daysRem,
                'state'          => $daysRem !== null && $daysRem < 0 ? 'EXPIRED' : 'Expiring',
                'member_id'      => (int) ($r['member_id'] ?? 0),
            ];
        }

        // FEMA + custom certifications via member_certifications
        // GH#95 — see the fcc query above for why this reads named-then-legacy.
        $certs = safe_fetch_all_rpt(
            "SELECT m.id AS member_id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    c.name AS cert_name, c.fema_course_code, mc.expiry_date
             FROM `{$prefix}member_certifications` mc
             JOIN `{$prefix}member` m ON mc.member_id = m.id
             JOIN `{$prefix}certifications` c ON mc.certification_id = c.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
               AND mc.expiry_date IS NOT NULL
               AND mc.expiry_date <= ?
             ORDER BY mc.expiry_date ASC",
            array_merge($rptMemberVars, $memberIdVars, [$cutoff])
        );
        foreach ($certs as $r) {
            $exp = strtotime((string) $r['expiry_date']);
            $daysRem = $exp ? (int) round(($exp - time()) / 86400) : null;
            $rows[] = [
                'last_name'      => $r['last_name']  ?? '',
                'first_name'     => $r['first_name'] ?? '',
                'callsign'       => $r['callsign']   ?? '',
                'license_kind'   => $r['fema_course_code'] ? 'FEMA' : 'Cert',
                'identifier'     => $r['cert_name'] ?? '',
                'expiry_date'    => $r['expiry_date'],
                'days_remaining' => $daysRem,
                'state'          => $daysRem !== null && $daysRem < 0 ? 'EXPIRED' : 'Expiring',
                'member_id'      => (int) ($r['member_id'] ?? 0),
            ];
        }

        // Sort by days_remaining (most urgent first; expired = negative = comes first)
        usort($rows, function ($a, $b) {
            return ($a['days_remaining'] ?? 999999) <=> ($b['days_remaining'] ?? 999999);
        });

        $expiredCount  = count(array_filter($rows, fn($r) => ($r['days_remaining'] ?? 0) < 0));
        $upcomingCount = count($rows) - $expiredCount;
        $summary = [
            'window_days'    => $days,
            'total_items'    => count($rows),
            'expired_count'  => $expiredCount,
            'upcoming_count' => $upcomingCount,
        ];
        $period_label = "Expirations within {$days} days (today: {$today})";
        break;

    case 'roster_snapshot':
        $report_title = 'Roster Snapshot';
        $columns = [
            ['key' => 'last_name',  'label' => 'Last'],
            ['key' => 'first_name', 'label' => 'First'],
            ['key' => 'callsign',   'label' => 'Callsign'],
            ['key' => 'type_name',  'label' => 'Type'],
            ['key' => 'status_name','label' => 'Status'],
            ['key' => 'team_names', 'label' => 'Teams'],
            ['key' => 'available',  'label' => 'Avail'],
            ['key' => 'phone_cell', 'label' => 'Phone'],
            ['key' => 'email',      'label' => 'Email'],
        ];
        // GH#95 — see license_expirations above for why these read
        // named-then-legacy. member_type_id is an id column (not a string),
        // so its NULLIF fallback compares against 0, not ''.
        //
        // available/field8 does NOT use the same COALESCE(NULLIF(...))
        // shape as every other field here, deliberately: unlike the other
        // named columns (which DEFAULT NULL), `available` is a NOT-NULL-
        // able ENUM with DEFAULT 'Yes' -- confirmed empirically that a
        // plain INSERT that doesn't specify `available` takes that
        // default, so "never touched" and "explicitly Yes" are the SAME
        // stored value and NULLIF can't tell them apart. field8 carries
        // the identical problem (its own NOT NULL DEFAULT 'Yes'). Since
        // BOTH columns default to 'Yes', a 'No' on EITHER side can only
        // exist because something deliberately wrote it -- so 'No' wins
        // from whichever column has it, rather than preferring the named
        // column unconditionally. This also fixes the case a first attempt
        // at this query (COALESCE-only) missed: a member with a real,
        // deliberately-set field8='No' and an `available` that was simply
        // never written (so it silently carries its own 'Yes' default) --
        // COALESCE-only would have shown 'Yes' as if it were the true,
        // saved value that field8's honest 'No' should defer to, instead
        // of recognizing it as an unwritten default with nothing to defer
        // to.
        $members = safe_fetch_all_rpt(
            "SELECT m.id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    COALESCE(NULLIF(m.email,      ''), m.field6) AS email,
                    COALESCE(NULLIF(m.phone_cell, ''), m.field7) AS phone_cell,
                    CASE WHEN m.available = 'No' OR m.field8 = 'No' THEN 'No' ELSE 'Yes' END AS available,
                    mt.name AS type_name, ms.status_val AS status_name
             FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}member_types`  mt ON mt.id = COALESCE(NULLIF(m.member_type_id, 0), m.field3)
             LEFT JOIN `{$prefix}member_status` ms ON m.member_status_id = ms.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
             ORDER BY last_name, first_name",
            array_merge($rptMemberVars, $memberIdVars)
        );
        // Pull team memberships separately so multi-team is captured
        $tm = safe_fetch_all_rpt(
            "SELECT tm.member_id, t.id AS team_id, t.team AS team_name
             FROM `{$prefix}team_members` tm
             JOIN `{$prefix}teams` t ON tm.team_id = t.id"
        );
        $teamsByMember = [];
        foreach ($tm as $row) {
            $teamsByMember[(int) $row['member_id']][] = [
                'id' => (int) $row['team_id'],
                'name' => $row['team_name'],
            ];
        }
        $member_link_cols[] = 0;
        $member_link_cols[] = 1;
        // Eric, 2026-08-13 — "click on a team name in a report" for a cell
        // that lists several teams: each name gets its own link rather than
        // one link around the whole comma-joined string.
        $team_multi_link_cols[] = 5;
        $rows = [];
        foreach ($members as $m) {
            $teams = $teamsByMember[(int) $m['id']] ?? [];
            $rows[] = [
                'last_name'   => $m['last_name'],
                'first_name'  => $m['first_name'],
                'callsign'    => $m['callsign'] ?: '',
                'type_name'   => $m['type_name'] ?: '',
                'status_name' => $m['status_name'] ?: '',
                'team_names'  => implode(', ', array_column($teams, 'name')),
                'available'   => $m['available'] ?: '',
                'phone_cell'  => $m['phone_cell'] ?: '',
                'email'       => $m['email'] ?: '',
                'member_id'   => (int) $m['id'],
            ];
            $row_team_lists[] = $teams;
        }
        $summary = ['total_members' => count($rows)];
        $period_label = 'As of ' . date('Y-m-d H:i');
        break;

    case 'dmr_inventory':
        $report_title = 'DMR ID Inventory';
        $columns = [
            ['key' => 'last_name',  'label' => 'Last'],
            ['key' => 'first_name', 'label' => 'First'],
            ['key' => 'callsign',   'label' => 'Callsign'],
            ['key' => 'dmr_ids',    'label' => 'DMR ID(s)'],
        ];
        // DMR IDs are stored in member.notes by tools/radioid_lookup.php as
        // "DMR ID: NNNNN (CALL)" — extract them.
        $member_link_cols[] = 0;
        $member_link_cols[] = 1;
        $rows = [];
        // GH#95 — see license_expirations above for why these read
        // named-then-legacy.
        $members = safe_fetch_all_rpt(
            "SELECT m.id AS member_id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    m.notes
             FROM `{$prefix}member` m
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag} AND m.notes LIKE '%DMR ID%'
             ORDER BY last_name, first_name",
            array_merge($rptMemberVars, $memberIdVars)
        );
        foreach ($members as $m) {
            preg_match_all('/DMR ID:\s*(\d+)/i', (string) $m['notes'], $matches);
            $ids = !empty($matches[1]) ? implode(', ', $matches[1]) : '';
            if ($ids === '') continue;
            $rows[] = [
                'last_name'  => $m['last_name'],
                'first_name' => $m['first_name'],
                'callsign'   => $m['callsign'] ?: '',
                'dmr_ids'    => $ids,
                'member_id'  => (int) ($m['member_id'] ?? 0),
            ];
        }
        $totalIds = 0;
        foreach ($rows as $r) {
            $totalIds += count(explode(',', $r['dmr_ids']));
        }
        $summary = ['members_with_dmr' => count($rows), 'total_dmr_ids' => $totalIds];
        $period_label = 'Generated ' . date('Y-m-d H:i');
        break;

    case 'membership_due':
        $report_title = 'Membership Renewals';
        $days = max(1, min(365, (int) ($_GET['days'] ?? 60)));
        $cutoff = date('Y-m-d', strtotime("+{$days} days"));
        $today  = date('Y-m-d');
        $columns = [
            ['key' => 'last_name',      'label' => 'Last'],
            ['key' => 'first_name',     'label' => 'First'],
            ['key' => 'callsign',       'label' => 'Callsign'],
            ['key' => 'membership_due', 'label' => 'Due'],
            ['key' => 'days_remaining', 'label' => 'Days', 'align' => 'right'],
            ['key' => 'state',          'label' => 'Status'],
        ];
        $member_link_cols[] = 0;
        $member_link_cols[] = 1;
        // GH#95 — see license_expirations above for why these read
        // named-then-legacy.
        $members = safe_fetch_all_rpt(
            "SELECT m.id AS member_id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    m.membership_due
             FROM `{$prefix}member` m
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
               AND m.membership_due IS NOT NULL
               AND m.membership_due <= ?
             ORDER BY m.membership_due ASC",
            array_merge($rptMemberVars, $memberIdVars, [$cutoff])
        );
        $rows = [];
        $expired = 0;
        foreach ($members as $m) {
            $due = strtotime((string) $m['membership_due']);
            $daysRem = $due ? (int) round(($due - time()) / 86400) : null;
            $isPast = $daysRem !== null && $daysRem < 0;
            if ($isPast) $expired++;
            $rows[] = [
                'last_name'      => $m['last_name'],
                'first_name'     => $m['first_name'],
                'callsign'       => $m['callsign'] ?: '',
                'membership_due' => $m['membership_due'],
                'days_remaining' => $daysRem,
                'state'          => $isPast ? 'PAST DUE' : 'Upcoming',
                'member_id'      => (int) ($m['member_id'] ?? 0),
            ];
        }
        $summary = [
            'window_days'   => $days,
            'total_members' => count($rows),
            'past_due'      => $expired,
            'upcoming'      => count($rows) - $expired,
        ];
        $period_label = "Renewals within {$days} days (today: {$today})";
        break;

    case 'inactive_members':
        $report_title = 'Inactive Members';
        $columns = [
            ['key' => 'last_name',     'label' => 'Last'],
            ['key' => 'first_name',    'label' => 'First'],
            ['key' => 'callsign',      'label' => 'Callsign'],
            ['key' => 'status_name',   'label' => 'Status'],
            ['key' => 'available',     'label' => 'Avail'],
            ['key' => 'last_activity', 'label' => 'Last Logged Time'],
            ['key' => 'reason',        'label' => 'Reason'],
        ];
        // GH#95 — see license_expirations above for why these read
        // named-then-legacy. The WHERE-clause "not available" filter needs
        // the same shape as the SELECT alias, not just a matching filter --
        // an install where `available` is the populated column would
        // otherwise never match a bare legacy-only comparison even though
        // the SELECT now shows the right value. available/field8 uses a
        // dominant-'No' shape rather than the named-then-legacy COALESCE
        // every other field here uses -- see the matching comment above
        // roster_snapshot's query for why (both columns share the same
        // NOT NULL DEFAULT 'Yes', so an unwritten column and an explicit
        // 'Yes' are the same stored value; 'No' from either side can only
        // be a deliberate write, so it wins).
        $rows = safe_fetch_all_rpt(
            "SELECT m.id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    CASE WHEN m.available = 'No' OR m.field8 = 'No' THEN 'No' ELSE 'Yes' END AS available,
                    ms.status_val AS status_name,
                    (SELECT MAX(te.started_at)
                     FROM `{$prefix}member_time_entries` te
                     WHERE te.member_id = m.id) AS last_activity
             FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}member_status` ms ON m.member_status_id = ms.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
               AND (m.available = 'No' OR m.field8 = 'No'
                    OR ms.status_val IN ('Inactive', 'On Leave')
                    OR NOT EXISTS (
                        SELECT 1 FROM `{$prefix}member_time_entries` te2
                        WHERE te2.member_id = m.id
                          AND te2.started_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    ))
             ORDER BY last_name, first_name",
            array_merge($rptMemberVars, $memberIdVars)
        );
        $member_link_cols[] = 0;
        $member_link_cols[] = 1;
        // Annotate each row with the inactivity reason
        $annotated = [];
        foreach ($rows as $r) {
            $reasons = [];
            if (($r['available'] ?? '') === 'No') $reasons[] = 'available=No';
            if (in_array($r['status_name'] ?? '', ['Inactive', 'On Leave'], true)) {
                $reasons[] = 'status=' . $r['status_name'];
            }
            if (!$r['last_activity']
                || strtotime($r['last_activity']) < strtotime('-90 days')) {
                $reasons[] = 'no time entries in 90 days';
            }
            $annotated[] = [
                'last_name'     => $r['last_name'],
                'first_name'    => $r['first_name'],
                'callsign'      => $r['callsign'] ?: '',
                'status_name'   => $r['status_name'] ?: '',
                'available'     => $r['available'] ?: '',
                'last_activity' => $r['last_activity'] ?: '(never)',
                'reason'        => implode('; ', $reasons),
                'member_id'     => (int) ($r['id'] ?? 0),
            ];
        }
        $rows = $annotated;
        $summary = ['total_inactive' => count($rows)];
        $period_label = 'Generated ' . date('Y-m-d H:i');
        break;

    case 'time_summary':
        $report_title = 'Member Time Totals';
        $columns = [
            ['key' => 'last_name',     'label' => 'Last'],
            ['key' => 'first_name',    'label' => 'First'],
            ['key' => 'callsign',      'label' => 'Callsign'],
            ['key' => 'entry_count',   'label' => 'Entries', 'align' => 'right'],
            ['key' => 'total_hours',   'label' => 'Hours',   'align' => 'right'],
            ['key' => 'last_activity', 'label' => 'Last Logged'],
        ];
        $member_link_cols[] = 0;
        $member_link_cols[] = 1;
        // GH#95 — see license_expirations above for why these read
        // named-then-legacy.
        $rows = safe_fetch_all_rpt(
            "SELECT m.id AS member_id,
                    COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                    COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                    COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                    COUNT(te.id)         AS entry_count,
                    COALESCE(SUM(te.hours), 0) AS total_hours,
                    MAX(te.started_at)   AS last_activity
             FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}member_time_entries` te
                ON te.member_id = m.id
                AND te.started_at >= ?
                AND te.started_at <= ?
                AND te.status IN ('self_reported','approved')
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
             GROUP BY m.id
             HAVING entry_count > 0
             ORDER BY total_hours DESC",
            array_merge([$start_date . ' 00:00:00', $end_date . ' 23:59:59'], $rptMemberVars, $memberIdVars)
        );
        $totalHours = 0;
        foreach ($rows as $r) $totalHours += (float) $r['total_hours'];
        $summary = [
            'period_start'  => $start_date,
            'period_end'    => $end_date,
            'member_count'  => count($rows),
            'total_hours'   => round($totalHours, 2),
        ];
        break;
}

ini_set('display_errors', $prevDisplay);

// Surface the active member filter in the report header, same as the
// incident-type reports do for their responder/incident filters (built
// client-side there from the dropdown's own selected text; done here
// instead since the Personnel filter is new and this is the one place
// that already knows $member_id was applied and IDOR-checked).
if ($isPersonnel && $member_id > 0) {
    // GH#95 — same named-then-legacy fallback as the report queries above,
    // so the header label doesn't silently drop the name on installs where
    // first_name/last_name are the populated columns.
    $memberName = safe_fetch_value_rpt(
        "SELECT TRIM(CONCAT(
                    COALESCE(NULLIF(first_name, ''), field2), ' ',
                    COALESCE(NULLIF(last_name,  ''), field1)))
           FROM `{$prefix}member` WHERE id = ?",
        [$member_id]
    );
    if ($memberName) {
        $period_label .= ' — ' . $memberName;
    }
}

// Personnel report member-id extraction (drill-down links) — read from the
// FINAL $rows order, not built alongside the fetch loops above: several
// personnel cases usort()/annotate/reassign $rows after their row-building
// loop, which would desync a parallel array built during that loop. Reading
// 'member_id' off each row here, after all of that has happened, can't
// desync because it walks whatever order $rows is actually in. This key is
// never in $columns, so the flatten step below drops it from the response —
// same as every other report, the internal id is never a visible cell.
if (!empty($member_link_cols)) {
    foreach ($rows as $r) {
        $row_member_ids[] = is_array($r) ? (int) ($r['member_id'] ?? 0) : 0;
    }
}

// Normalize column/row shape for the legacy renderer (which expects
// columns: string[] and rows: array of indexed arrays). Personnel
// reports are authored with structured columns + associative rows;
// flatten them here so the JSON contract stays uniform.
if (!empty($columns) && is_array($columns[0] ?? null)) {
    $colKeys   = array_map(fn($c) => $c['key']   ?? '', $columns);
    $colLabels = array_map(fn($c) => $c['label'] ?? '', $columns);
    $flatRows  = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { $flatRows[] = []; continue; }
        $flat = [];
        foreach ($colKeys as $k) {
            $flat[] = $row[$k] ?? null;
        }
        $flatRows[] = $flat;
    }
    $columns = $colLabels;
    $rows    = $flatRows;
}

// Eric, 2026-08-13 (GH#51 follow-up, expanded to units/facilities/personnel/
// teams the same day) — drill-down links. Assembled as one generic list of
// {col, kind, ids} descriptors rather than a scalar-per-kind pair, because
// a personnel report links BOTH its first_name and last_name columns to
// the same member — a single column index per kind can't express that.
// `ids` is row-parallel (ids[$r] is the id for row $r); a report with no
// linkable column of a given kind simply never appends to that array, so
// $links is [] for reports that don't apply (e.g. notes_log).
$links = [];
foreach ($incident_link_cols as $c) { $links[] = ['col' => $c, 'kind' => 'incident', 'ids' => $row_ticket_ids]; }
foreach ($unit_link_cols as $c)     { $links[] = ['col' => $c, 'kind' => 'unit',     'ids' => $row_responder_ids]; }
foreach ($facility_link_cols as $c) { $links[] = ['col' => $c, 'kind' => 'facility', 'ids' => $row_facility_ids]; }
foreach ($member_link_cols as $c)   { $links[] = ['col' => $c, 'kind' => 'member',   'ids' => $row_member_ids]; }
foreach ($team_link_cols as $c)     { $links[] = ['col' => $c, 'kind' => 'team',     'ids' => $row_team_ids]; }
// team_multi: a cell listing several teams. 'items' is row-parallel, each
// entry an array of {id,name} for that row -- the frontend renders one <a>
// per item instead of wrapping the whole cell's text in a single link.
foreach ($team_multi_link_cols as $c) { $links[] = ['col' => $c, 'kind' => 'team_multi', 'items' => $row_team_lists]; }
foreach ($incident_type_link_cols as $c) { $links[] = ['col' => $c, 'kind' => 'incident_type', 'ids' => $row_type_ids]; }

json_response([
    'report_title' => $report_title,
    'period_label' => $period_label,
    'columns'      => $columns,
    'rows'         => $rows,
    'summary'      => $summary,
    // Phase 132 Step 5 (GH #16) — only non-empty for 'incident_summary';
    // every other report type gets [] and callers that don't know about
    // this key are unaffected.
    'disposition_breakdown' => $disposition_breakdown,
    // GH#87/GH#88 (2026-08-19) — same treatment: only non-empty for
    // 'incident_summary'. One entry per configured severity level
    // (value/label/color/count), replacing the old fixed
    // high_severity/medium_severity/low_severity summary keys.
    'severity_breakdown' => $severity_breakdown,
    // GH#64 — only non-empty for 'interval_report'. One entry per
    // incident type / per unit seen in the period, each carrying a
    // count + formatted average response/scene time (see the
    // 'interval_report' case above for how these are built).
    'interval_by_type' => $interval_by_type,
    'interval_by_unit' => $interval_by_unit,
    // GH#96 — only non-empty for 'mileage_report'. One entry per
    // organization / per vehicle seen in the period (see the
    // 'mileage_report' case above for how these are built).
    'mileage_by_org'  => $mileage_by_org,
    'mileage_by_unit' => $mileage_by_unit,
    'links' => $links,
]);
