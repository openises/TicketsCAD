<?php
/**
 * NewUI v4.0 API - Statistics
 *
 * GET /api/statistics.php
 *   Returns aggregate statistics for the dashboard widget.
 *
 * GET /api/statistics.php?mode=reports&period=this_month
 *   Extended mode for reports page. Additional parameters:
 *   period:     today | this_week | last_week | this_month | last_month | this_year | last_year | custom
 *   start_date: Y-m-d (for custom)
 *   end_date:   Y-m-d (for custom)
 *
 *   Returns additional fields: closed_in_period, total_in_period,
 *   avg_response_time, avg_on_scene_time, avg_close_time,
 *   incidents_by_type (top 10), incidents_by_city (top 10).
 */

require_once __DIR__ . '/auth.php';

// Suppress PHP warnings from corrupting JSON output
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Helper: safe query wrappers
function safe_fetch_all_stat($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all_stat] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

function safe_fetch_value_stat($sql, $params = []) {
    try {
        $val = db_fetch_value($sql, $params);
        return $val !== false ? $val : null;
    } catch (Exception $e) {
        return null;
    }
}

function safe_fetch_one_stat($sql, $params = []) {
    try {
        return db_fetch_one($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_one_stat] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return null;
    }
}

// User group filtering — admins (level 0,1) see all
$user_groups = $_SESSION['user_groups'] ?? [];
$is_admin = is_admin();
$group_filter = '';
$group_params = [];
// RBAC-aware bypass — see api/incidents.php for the rationale.
require_once __DIR__ . '/../inc/rbac.php';
$rbacStatsView = (function_exists('rbac_can')
    && (rbac_can('screen.dashboard') || rbac_can('widget.stats') || rbac_can('screen.incidents')));

if ($is_admin || $rbacStatsView) {
    // No additional filter; admin and RBAC-granted users see org-wide stats.
} elseif (!empty($user_groups)) {
    $placeholders = implode(',', array_fill(0, count($user_groups), '?'));
    $group_filter = " AND `a`.`group` IN ({$placeholders})";
    $group_params = $user_groups;
} else {
    $group_filter = " AND 1=0";
}

// Phase 99j-7 (Billy beta 2026-06-29) — org-scope filter for the
// stats aggregates. Build once, append to each ticket-scoped or
// responder-scoped query along with its params. Super Admin gets
// ('', []) so the SQL is unchanged.
require_once __DIR__ . '/../inc/org-scope.php';
ensure_org_id_column('responder');
[$ticketOrgFrag, $ticketOrgVars] = org_query_filter('t.org_id');
[$respOrgFrag,   $respOrgVars]   = org_query_filter('r.org_id');

// ── Core dashboard stats (always returned) ────────────────────────────────────

// Open/active ticket count
$sql = "SELECT COUNT(DISTINCT `t`.`id`) AS `cnt`
FROM `{$prefix}ticket` `t`
LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
WHERE (`t`.`status` = 2 OR `t`.`status` = 3) {$group_filter} {$ticketOrgFrag}";
$open_tickets = (int) safe_fetch_value_stat($sql, array_merge($group_params, $ticketOrgVars));

// Closed today
$sql = "SELECT COUNT(DISTINCT `t`.`id`) AS `cnt`
FROM `{$prefix}ticket` `t`
LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
WHERE `t`.`status` = 1 AND DATE(`t`.`problemend`) = CURDATE() {$group_filter} {$ticketOrgFrag}";
$closed_today = (int) safe_fetch_value_stat($sql, array_merge($group_params, $ticketOrgVars));

// Unassigned (open tickets with 0 active assignments)
$sql = "SELECT COUNT(DISTINCT `t`.`id`) AS `cnt`
FROM `{$prefix}ticket` `t`
LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
WHERE (`t`.`status` = 2 OR `t`.`status` = 3) {$group_filter} {$ticketOrgFrag}
  AND (SELECT COUNT(*) FROM `{$prefix}assigns`
       WHERE `ticket_id` = `t`.`id`
         AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')) = 0";
$unassigned = (int) safe_fetch_value_stat($sql, array_merge($group_params, $ticketOrgVars));

// Responders available (not hidden, status not hidden, no active assignments)
$resp_group_filter = str_replace('`a`.`type` = 1', '`a`.`type` = 2', $group_filter);
$sql = "SELECT COUNT(DISTINCT `r`.`id`) AS `cnt`
FROM `{$prefix}responder` `r`
LEFT JOIN `{$prefix}allocates` `a` ON `r`.`id` = `a`.`resource_id` AND `a`.`type` = 2
LEFT JOIN `{$prefix}un_status` `us` ON `r`.`un_status_id` = `us`.`id`
WHERE `us`.`hide` = 'n' {$resp_group_filter} {$respOrgFrag}
  AND (SELECT COUNT(*) FROM `{$prefix}assigns`
       WHERE `responder_id` = `r`.`id`
         AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')) = 0";
$available_responders = (int) safe_fetch_value_stat($sql, array_merge($group_params, $respOrgVars));

// Dispatched, responding, on-scene counts
$sql = "SELECT
    SUM(CASE WHEN `dispatched` IS NOT NULL AND (`responding` IS NULL OR DATE_FORMAT(`responding`,'%y') = '00')
             AND (`on_scene` IS NULL OR DATE_FORMAT(`on_scene`,'%y') = '00')
             AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00') THEN 1 ELSE 0 END) AS `dispatched_nr`,
    SUM(CASE WHEN `responding` IS NOT NULL AND DATE_FORMAT(`responding`,'%y') != '00'
             AND (`on_scene` IS NULL OR DATE_FORMAT(`on_scene`,'%y') = '00')
             AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00') THEN 1 ELSE 0 END) AS `responding_nos`,
    SUM(CASE WHEN `on_scene` IS NOT NULL AND DATE_FORMAT(`on_scene`,'%y') != '00'
             AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00') THEN 1 ELSE 0 END) AS `on_scene`
FROM `{$prefix}assigns` `asg`
INNER JOIN `{$prefix}ticket` `t` ON `asg`.`ticket_id` = `t`.`id`
WHERE `t`.`status` = 2";

$counts = safe_fetch_one_stat($sql) ?? [];

// Average times (for tickets closed today)
$sql = "SELECT
    AVG(TIMESTAMPDIFF(SECOND, `t`.`date`, `asg`.`dispatched`)) AS `avg_to_dispatch`,
    AVG(TIMESTAMPDIFF(SECOND, `asg`.`dispatched`, `asg`.`responding`)) AS `avg_to_responding`,
    AVG(TIMESTAMPDIFF(SECOND, `asg`.`dispatched`, `asg`.`on_scene`)) AS `avg_to_on_scene`,
    AVG(TIMESTAMPDIFF(SECOND, `t`.`problemstart`, `t`.`problemend`)) AS `avg_open_time`
FROM `{$prefix}ticket` `t`
LEFT JOIN `{$prefix}assigns` `asg` ON `t`.`id` = `asg`.`ticket_id`
WHERE `t`.`status` = 1 AND DATE(`t`.`problemend`) = CURDATE()
  AND `asg`.`dispatched` IS NOT NULL";

$avgs = safe_fetch_one_stat($sql) ?? [];

$response = [
    'open_tickets'         => $open_tickets,
    'closed_today'         => $closed_today,
    'unassigned'           => $unassigned,
    'available_responders' => $available_responders,
    'dispatched_not_responding' => (int) ($counts['dispatched_nr'] ?? 0),
    'responding_not_on_scene'   => (int) ($counts['responding_nos'] ?? 0),
    'on_scene'                  => (int) ($counts['on_scene'] ?? 0),
    'avg_to_dispatch_secs'      => isset($avgs['avg_to_dispatch']) && $avgs['avg_to_dispatch'] ? (int) $avgs['avg_to_dispatch'] : null,
    'avg_to_responding_secs'    => isset($avgs['avg_to_responding']) && $avgs['avg_to_responding'] ? (int) $avgs['avg_to_responding'] : null,
    'avg_to_on_scene_secs'      => isset($avgs['avg_to_on_scene']) && $avgs['avg_to_on_scene'] ? (int) $avgs['avg_to_on_scene'] : null,
    'avg_open_time_secs'        => isset($avgs['avg_open_time']) && $avgs['avg_open_time'] ? (int) $avgs['avg_open_time'] : null,
];

// ── Extended reports mode ─────────────────────────────────────────────────────

$mode = $_GET['mode'] ?? '';

if ($mode === 'reports') {

    $period     = $_GET['period'] ?? 'this_month';
    $start_date = $_GET['start_date'] ?? '';
    $end_date   = $_GET['end_date'] ?? '';
    $now_dt     = new DateTime();

    switch ($period) {
        case 'today':
            $start_date = $now_dt->format('Y-m-d');
            $end_date   = $now_dt->format('Y-m-d');
            break;
        case 'this_week':
            $start_date = (clone $now_dt)->modify('monday this week')->format('Y-m-d');
            $end_date   = $now_dt->format('Y-m-d');
            break;
        case 'last_week':
            $s = (clone $now_dt)->modify('monday last week');
            $start_date = $s->format('Y-m-d');
            $end_date   = (clone $s)->modify('+6 days')->format('Y-m-d');
            break;
        case 'this_month':
            $start_date = $now_dt->format('Y-m-01');
            $end_date   = $now_dt->format('Y-m-d');
            break;
        case 'last_month':
            $lm = (clone $now_dt)->modify('first day of last month');
            $start_date = $lm->format('Y-m-01');
            $end_date   = $lm->format('Y-m-t');
            break;
        case 'this_year':
            $start_date = $now_dt->format('Y-01-01');
            $end_date   = $now_dt->format('Y-m-d');
            break;
        case 'last_year':
            $yr = (int) $now_dt->format('Y') - 1;
            $start_date = $yr . '-01-01';
            $end_date   = $yr . '-12-31';
            break;
        case 'custom':
            // use provided start_date / end_date
            break;
        default:
            $start_date = $now_dt->format('Y-m-01');
            $end_date   = $now_dt->format('Y-m-d');
    }

    $ds = $start_date . ' 00:00:00';
    $de = $end_date . ' 23:59:59';

    $closed_in_period = (int) safe_fetch_value_stat(
        "SELECT COUNT(*) FROM `{$prefix}ticket`
         WHERE `status` = 1 AND `problemend` BETWEEN ? AND ?",
        [$ds, $de]
    );

    $total_in_period = (int) safe_fetch_value_stat(
        "SELECT COUNT(*) FROM `{$prefix}ticket`
         WHERE `date` BETWEEN ? AND ?",
        [$ds, $de]
    );

    // Average response time in period
    $avg_response_period = safe_fetch_value_stat(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, `a`.`dispatched`, `a`.`responding`))
         FROM `{$prefix}assigns` `a`
         WHERE `a`.`dispatched` BETWEEN ? AND ?
           AND `a`.`responding` IS NOT NULL
           AND DATE_FORMAT(`a`.`responding`,'%y') != '00'",
        [$ds, $de]
    );

    // Average on-scene time in period
    $avg_on_scene_period = safe_fetch_value_stat(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, `a`.`dispatched`, `a`.`on_scene`))
         FROM `{$prefix}assigns` `a`
         WHERE `a`.`dispatched` BETWEEN ? AND ?
           AND `a`.`on_scene` IS NOT NULL
           AND DATE_FORMAT(`a`.`on_scene`,'%y') != '00'",
        [$ds, $de]
    );

    // Average close time in period
    $avg_close_period = safe_fetch_value_stat(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, `problemstart`, `problemend`))
         FROM `{$prefix}ticket`
         WHERE `status` = 1
           AND `problemstart` IS NOT NULL
           AND `problemend` IS NOT NULL
           AND `problemend` BETWEEN ? AND ?",
        [$ds, $de]
    );

    // Format seconds helper
    $fmt = function ($secs) {
        if ($secs === null || $secs === false) {
            return null;
        }
        $secs = (int) round((float) $secs);
        if ($secs < 0) {
            return null;
        }
        if ($secs >= 3600) {
            return sprintf('%d:%02d:%02d', floor($secs / 3600), floor(($secs % 3600) / 60), $secs % 60);
        }
        return sprintf('%d:%02d', floor($secs / 60), $secs % 60);
    };

    // Incidents by type (top 10)
    $by_type = safe_fetch_all_stat(
        "SELECT COALESCE(`it`.`type`, 'Unknown') AS `type_name`, COUNT(*) AS `count`
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         WHERE `t`.`date` BETWEEN ? AND ?
         GROUP BY `it`.`type`
         ORDER BY `count` DESC
         LIMIT 10",
        [$ds, $de]
    );

    // Incidents by city (top 10)
    $by_city = safe_fetch_all_stat(
        "SELECT COALESCE(NULLIF(`city`,''), 'Unknown') AS `city_name`, COUNT(*) AS `count`
         FROM `{$prefix}ticket`
         WHERE `date` BETWEEN ? AND ?
         GROUP BY `city`
         ORDER BY `count` DESC
         LIMIT 10",
        [$ds, $de]
    );

    $response['closed_in_period']      = $closed_in_period;
    $response['total_in_period']       = $total_in_period;
    $response['avg_response_time']     = $fmt($avg_response_period);
    $response['avg_on_scene_time']     = $fmt($avg_on_scene_period);
    $response['avg_close_time']        = $fmt($avg_close_period);
    $response['incidents_by_type']     = $by_type;
    $response['incidents_by_city']     = $by_city;
}

ini_set('display_errors', $prevDisplay);

json_response($response);
