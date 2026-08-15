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
require_once __DIR__ . '/../inc/org-scope.php';
[$rptTicketFrag, $rptTicketVars] = org_query_filter('t.org_id');
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

// GH#51 — accept the dispatcher's own case number, not the internal id.
// incident_number is what the new UI sends; incident_id is kept for old
// bookmarks/links and resolved the same way (a raw numeric id still
// works as a fallback inside incnum_resolve_input()).
require_once __DIR__ . '/../inc/incident-number.php';
$incident_input = trim((string) ($_GET['incident_number'] ?? ($_GET['incident_id'] ?? '')));
$incident_id    = $incident_input !== '' ? incnum_resolve_input($incident_input) : 0;

$valid_reports = [
    'unit_log', 'dispatch_log', 'incident_summary', 'incident_report',
    'facility_log', 'after_action', 'notes_log',
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
        $sev_labels = [0 => 'Low', 1 => 'Medium', 2 => 'High'];
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
                $sev_labels[(int) ($row['severity'] ?? 0)] ?? 'Low',
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

    // ── INCIDENT SUMMARY ──────────────────────────────────────────────────
    case 'incident_summary':
        $report_title = 'Incident Summary';
        $columns = ['Incident Type', 'Total', 'High Severity', 'Medium Severity', 'Low Severity', 'Open', 'Closed'];

        // Soft-delete sweep (issue #25 follow-up) — both queries in this
        // case excluded, so a deleted incident can't skew the summary
        // counts or the average close-time figure below.
        $data = safe_fetch_all_rpt(
            "SELECT
                `it`.`id` AS `type_id`,
                COALESCE(`it`.`type`, 'Unknown') AS `incident_type`,
                COUNT(*) AS `total`,
                SUM(CASE WHEN `t`.`severity` = 2 THEN 1 ELSE 0 END) AS `high`,
                SUM(CASE WHEN `t`.`severity` = 1 THEN 1 ELSE 0 END) AS `medium`,
                SUM(CASE WHEN `t`.`severity` = 0 THEN 1 ELSE 0 END) AS `low`,
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
        $grand_high = 0;
        $grand_medium = 0;
        $grand_low = 0;
        $grand_open = 0;
        $grand_closed = 0;

        foreach ($data as $row) {
            $rows[] = [
                $row['incident_type'],
                (int) $row['total'],
                (int) $row['high'],
                (int) $row['medium'],
                (int) $row['low'],
                (int) $row['open'],
                (int) $row['closed']
            ];
            $row_type_ids[] = (int) ($row['type_id'] ?? 0);
            $grand_total  += (int) $row['total'];
            $grand_high   += (int) $row['high'];
            $grand_medium += (int) $row['medium'];
            $grand_low    += (int) $row['low'];
            $grand_open   += (int) $row['open'];
            $grand_closed += (int) $row['closed'];
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

        $summary = [
            'total_incidents'     => $grand_total,
            'high_severity'       => $grand_high,
            'medium_severity'     => $grand_medium,
            'low_severity'        => $grand_low,
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
        $sev_labels = [0 => 'Low', 1 => 'Medium', 2 => 'High'];
        $status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

        foreach ($data as $row) {
            $rows[] = [
                (!empty($row['incident_number']) ? $row['incident_number'] : '#' . $row['id']),
                $row['scope'] ?? '',
                $row['incident_type'],
                $sev_labels[(int) ($row['severity'] ?? 0)] ?? 'Low',
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

        $sev_labels  = [0 => 'Low', 1 => 'Medium', 2 => 'High'];
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
            'severity'       => $sev_labels[(int) ($ticket['severity'] ?? 0)] ?? 'Low',
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
        $fcc = safe_fetch_all_rpt(
            "SELECT m.id AS member_id, m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign,
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
        $certs = safe_fetch_all_rpt(
            "SELECT m.id AS member_id, m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign,
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
        $members = safe_fetch_all_rpt(
            "SELECT m.id, m.field2 AS first_name, m.field1 AS last_name,
                    m.field4 AS callsign, m.field6 AS email, m.field7 AS phone_cell,
                    m.field8 AS available,
                    mt.name AS type_name, ms.status_val AS status_name
             FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}member_types`  mt ON m.field3 = mt.id
             LEFT JOIN `{$prefix}member_status` ms ON m.member_status_id = ms.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
             ORDER BY m.field1, m.field2",
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
        $members = safe_fetch_all_rpt(
            "SELECT m.id AS member_id, m.field2 AS first_name, m.field1 AS last_name,
                    m.field4 AS callsign, m.notes
             FROM `{$prefix}member` m
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag} AND m.notes LIKE '%DMR ID%'
             ORDER BY m.field1, m.field2",
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
        $members = safe_fetch_all_rpt(
            "SELECT m.id AS member_id, m.field2 AS first_name, m.field1 AS last_name,
                    m.field4 AS callsign, m.membership_due
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
        $rows = safe_fetch_all_rpt(
            "SELECT m.id, m.field2 AS first_name, m.field1 AS last_name,
                    m.field4 AS callsign, m.field8 AS available,
                    ms.status_val AS status_name,
                    (SELECT MAX(te.started_at)
                     FROM `{$prefix}member_time_entries` te
                     WHERE te.member_id = m.id) AS last_activity
             FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}member_status` ms ON m.member_status_id = ms.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}{$memberIdFrag}
               AND (m.field8 = 'No'
                    OR ms.status_val IN ('Inactive', 'On Leave')
                    OR NOT EXISTS (
                        SELECT 1 FROM `{$prefix}member_time_entries` te2
                        WHERE te2.member_id = m.id
                          AND te2.started_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    ))
             ORDER BY m.field1, m.field2",
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
        $rows = safe_fetch_all_rpt(
            "SELECT m.id AS member_id, m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign,
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
    $memberName = safe_fetch_value_rpt(
        "SELECT TRIM(CONCAT(field2, ' ', field1)) FROM `{$prefix}member` WHERE id = ?",
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
    'links' => $links,
]);
