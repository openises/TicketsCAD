<?php
/**
 * NewUI v4.0 API - Reports
 *
 * GET /api/reports.php?report=unit_log&period=this_month
 *   report:       unit_log | dispatch_log | incident_summary | incident_report | facility_log | after_action
 *   period:       today | this_week | last_week | this_month | last_month | this_year | last_year | custom
 *   start_date:   Y-m-d (required if period=custom)
 *   end_date:     Y-m-d (required if period=custom)
 *   responder_id: filter by responder (0=all)
 *   incident_id:  filter by incident (0=all, used for after_action)
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
// endpoint must enforce permission). Allow only admins (level <= 1) for
// the aggregate reports; non-admins must scope to a specific resource
// they have access to.
$_currentLevel = (int) ($_SESSION['level'] ?? 99);

$prefix = $GLOBALS['db_prefix'] ?? '';

// Phase 99j-7 (Billy beta 2026-06-29) — org-scope filter for the
// aggregate reports. Reports are admin-only (line ~30 below), so
// the org filter only narrows things when an Org Admin (vs Super
// Admin) runs a report. Super Admin gets ('', []) so all queries
// are unchanged.
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
$incident_id  = max(0, (int) ($_GET['incident_id'] ?? 0));

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

// Personnel reports — read across the org, so they require admin like
// the other aggregate reports below. Skip the incident/responder IDOR
// checks because they don't take those filters.
$personnelReports = ['license_expirations', 'roster_snapshot', 'dmr_inventory',
                     'membership_due', 'inactive_members', 'time_summary'];
$isPersonnel = in_array($report, $personnelReports, true);
if ($isPersonnel && $_currentLevel > 1) {
    ini_set('display_errors', $prevDisplay);
    json_error('Personnel reports require admin access', 403);
}

// Per-resource IDOR check first — a user requesting one specific incident
// or responder must have access to it regardless of role.
if ($incident_id > 0 && !user_can_access_entity('incident', $incident_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}
if ($responder_id > 0 && !user_can_access_entity('responder', $responder_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Responder not found', 404);
}

// Aggregate / cross-resource reports (no specific filter) require admin.
$isFiltered = ($incident_id > 0) || ($responder_id > 0);
if (!$isPersonnel && !$isFiltered && $_currentLevel > 1) {
    ini_set('display_errors', $prevDisplay);
    json_error('Aggregate reports require admin access — filter by incident or responder', 403);
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

switch ($report) {

    // ── UNIT LOG ──────────────────────────────────────────────────────────
    case 'unit_log':
        $report_title = 'Unit Activity Log';
        $columns = ['Unit Name', 'Handle', 'Incident #', 'Scope', 'Dispatched', 'Responding', 'On-Scene', 'Clear', 'Response Time'];

        $where_parts = ["`a`.`dispatched` BETWEEN ? AND ?"];
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

        $where_parts = ["`a`.`dispatched` BETWEEN ? AND ?"];
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

        $data = safe_fetch_all_rpt(
            "SELECT
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
            GROUP BY `it`.`type`
            ORDER BY `total` DESC",
            [$date_start_sql, $date_end_sql]
        );

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
            $grand_total  += (int) $row['total'];
            $grand_high   += (int) $row['high'];
            $grand_medium += (int) $row['medium'];
            $grand_low    += (int) $row['low'];
            $grand_open   += (int) $row['open'];
            $grand_closed += (int) $row['closed'];
        }

        // Average time to close
        $avg_close = safe_fetch_value_rpt(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, `problemstart`, `problemend`))
             FROM `{$prefix}ticket`
             WHERE `status` = 1
               AND `problemstart` IS NOT NULL
               AND `problemend` IS NOT NULL
               AND `date` BETWEEN ? AND ?",
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

        $where_parts = ["`t`.`date` BETWEEN ? AND ?"];
        $params = [$date_start_sql, $date_end_sql];

        if ($incident_id > 0) {
            $where_parts[] = "`t`.`id` = ?";
            $params[] = $incident_id;
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
        }

        $summary = [
            'total_incidents' => count($data)
        ];
        break;

    // ── FACILITY LOG ──────────────────────────────────────────────────────
    case 'facility_log':
        $report_title = 'Facility Log';
        $columns = ['Facility Name', 'Incident #', 'Scope', 'Unit', 'Dispatched', 'Arrived', 'Notes'];

        $where_parts = ["`t`.`date` BETWEEN ? AND ?"];
        $params = [$date_start_sql, $date_end_sql];

        $where = implode(' AND ', $where_parts);
        // Phase 99j-7 — append org-scope filter (empty for Super Admin).
        $where .= $rptTicketFrag;
        $params = array_merge($params, $rptTicketVars);

        // Tickets linked to facilities via rec_facility
        $data = safe_fetch_all_rpt(
            "SELECT
                `f`.`name` AS `facility_name`,
                `t`.`id` AS `ticket_id`,
                `t`.`incident_number`,
                `t`.`scope`,
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
            json_error('incident_id is required for after_action report', 400);
        }

        // Incident details
        $ticket = null;
        try {
            $ticket = db_fetch_one(
                "SELECT `t`.*, `it`.`type` AS `incident_type`, `it`.`protocol`
                 FROM `{$prefix}ticket` `t`
                 LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
                 WHERE `t`.`id` = ?",
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

        // Action log entries
        $actions_data = safe_fetch_all_rpt(
            "SELECT * FROM `{$prefix}action` WHERE `ticket_id` = ? ORDER BY `date`",
            [$incident_id]
        );

        foreach ($actions_data as $act) {
            $timeline[] = [
                'time'    => $act['date'] ?? '',
                'event'   => 'Action',
                'who'     => $act['user'] ?? '',
                'details' => $act['action'] ?? ''
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
            'incident_id'   => $incident_id,
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

        $period_label = 'Incident #' . $incident_id;
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

        // FCC amateur + GMRS via member_callsigns
        $fcc = safe_fetch_all_rpt(
            "SELECT m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign,
                    mc.callsign AS identifier, mc.license_type, mc.expiry_date
             FROM `{$prefix}member_callsigns` mc
             JOIN `{$prefix}member` m ON mc.member_id = m.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}
               AND mc.expiry_date IS NOT NULL
               AND mc.expiry_date <= ?
             ORDER BY mc.expiry_date ASC",
            array_merge($rptMemberVars, [$cutoff])
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
            ];
        }

        // FEMA + custom certifications via member_certifications
        $certs = safe_fetch_all_rpt(
            "SELECT m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign,
                    c.name AS cert_name, c.fema_course_code, mc.expiry_date
             FROM `{$prefix}member_certifications` mc
             JOIN `{$prefix}member` m ON mc.member_id = m.id
             JOIN `{$prefix}certifications` c ON mc.certification_id = c.id
             WHERE m.deleted_at IS NULL {$rptMemberFrag}
               AND mc.expiry_date IS NOT NULL
               AND mc.expiry_date <= ?
             ORDER BY mc.expiry_date ASC",
            array_merge($rptMemberVars, [$cutoff])
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
             WHERE m.deleted_at IS NULL {$rptMemberFrag}
             ORDER BY m.field1, m.field2",
            $rptMemberVars
        );
        // Pull team memberships separately so multi-team is captured
        $tm = safe_fetch_all_rpt(
            "SELECT tm.member_id, t.team AS team_name
             FROM `{$prefix}team_members` tm
             JOIN `{$prefix}teams` t ON tm.team_id = t.id"
        );
        $teamsByMember = [];
        foreach ($tm as $row) {
            $teamsByMember[(int) $row['member_id']][] = $row['team_name'];
        }
        $rows = [];
        foreach ($members as $m) {
            $teams = $teamsByMember[(int) $m['id']] ?? [];
            $rows[] = [
                'last_name'   => $m['last_name'],
                'first_name'  => $m['first_name'],
                'callsign'    => $m['callsign'] ?: '',
                'type_name'   => $m['type_name'] ?: '',
                'status_name' => $m['status_name'] ?: '',
                'team_names'  => implode(', ', $teams),
                'available'   => $m['available'] ?: '',
                'phone_cell'  => $m['phone_cell'] ?: '',
                'email'       => $m['email'] ?: '',
            ];
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
        $rows = [];
        $members = safe_fetch_all_rpt(
            "SELECT m.field2 AS first_name, m.field1 AS last_name,
                    m.field4 AS callsign, m.notes
             FROM `{$prefix}member` m
             WHERE m.deleted_at IS NULL {$rptMemberFrag} AND m.notes LIKE '%DMR ID%'
             ORDER BY m.field1, m.field2",
            $rptMemberVars
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
        $members = safe_fetch_all_rpt(
            "SELECT m.field2 AS first_name, m.field1 AS last_name,
                    m.field4 AS callsign, m.membership_due
             FROM `{$prefix}member` m
             WHERE m.deleted_at IS NULL {$rptMemberFrag}
               AND m.membership_due IS NOT NULL
               AND m.membership_due <= ?
             ORDER BY m.membership_due ASC",
            array_merge($rptMemberVars, [$cutoff])
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
             WHERE m.deleted_at IS NULL {$rptMemberFrag}
               AND (m.field8 = 'No'
                    OR ms.status_val IN ('Inactive', 'On Leave')
                    OR NOT EXISTS (
                        SELECT 1 FROM `{$prefix}member_time_entries` te2
                        WHERE te2.member_id = m.id
                          AND te2.started_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    ))
             ORDER BY m.field1, m.field2",
            $rptMemberVars
        );
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
        $rows = safe_fetch_all_rpt(
            "SELECT m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign,
                    COUNT(te.id)         AS entry_count,
                    COALESCE(SUM(te.hours), 0) AS total_hours,
                    MAX(te.started_at)   AS last_activity
             FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}member_time_entries` te
                ON te.member_id = m.id
                AND te.started_at >= ?
                AND te.started_at <= ?
                AND te.status IN ('self_reported','approved')
             WHERE m.deleted_at IS NULL {$rptMemberFrag}
             GROUP BY m.id
             HAVING entry_count > 0
             ORDER BY total_hours DESC",
            array_merge([$start_date . ' 00:00:00', $end_date . ' 23:59:59'], $rptMemberVars)
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

json_response([
    'report_title' => $report_title,
    'period_label' => $period_label,
    'columns'      => $columns,
    'rows'         => $rows,
    'summary'      => $summary,
]);
