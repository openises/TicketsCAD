<?php
/**
 * NewUI v4.0 API - Incident List
 *
 * GET /api/incident-list.php
 *   Parameters:
 *     status  - filter (0=All, 1=Closed, 2=Open, 3=Scheduled) default 0
 *     group   - filter by incident type group
 *     severity - filter by severity (0, 1, 2)
 *     sort    - sort field (id, date, scope, type, status, severity, city, updated)
 *     order   - asc or desc (default desc)
 *     limit   - results per page (default 50, max 500)
 *     offset  - pagination offset (default 0)
 *
 *   Returns: { incidents: [...], total: N, limit: N, offset: N, groups: [...] }
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Parse params
$status   = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : 0;
$group    = trim($_GET['group'] ?? '');
$severity = isset($_GET['severity']) && $_GET['severity'] !== '' ? (int) $_GET['severity'] : null;
$sort     = trim($_GET['sort'] ?? 'date');
$order    = strtolower(trim($_GET['order'] ?? 'desc'));
$limit    = max(1, min(500, (int) ($_GET['limit'] ?? 50)));
$offset   = max(0, (int) ($_GET['offset'] ?? 0));

// Severity color map
$sev_colors = [
    0 => get_variable('sev_0_color') ?: '#00ff00',
    1 => get_variable('sev_1_color') ?: '#ffff00',
    2 => get_variable('sev_2_color') ?: '#ff0000',
];
$status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

// Build WHERE
$where = [];
$params = [];

// Phase 99j-4 (Billy beta 2026-06-29) — replace the per-session
// `active_org_id` filter with the proper org-scope helper. That helper
// reads user_roles to compute the set of orgs this user can see
// (Super Admin → all, Org Admin → own + descendants, ordinary → home),
// and emits a SQL fragment ready to plug in. See specs/phase-99j-
// org-scoping/spec.md.
//
// The fragment begins with " AND ", so we strip the leading " AND "
// for use with the array-of-conditions pattern below.
require_once __DIR__ . '/../inc/org-scope.php';
[$orgFrag, $orgVars] = org_query_filter('t.org_id');
if ($orgFrag !== '') {
    // Strip leading " AND " — the array $where joins with " AND " itself.
    $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
    foreach ($orgVars as $v) $params[] = $v;
}

if ($status > 0) {
    $where[] = "`t`.`status` = ?";
    $params[] = $status;
}

if ($group !== '') {
    $where[] = "`it`.`group` = ?";
    $params[] = $group;
}

if ($severity !== null) {
    $where[] = "`t`.`severity` = ?";
    $params[] = $severity;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Sort
$sort_map = [
    'id'       => '`t`.`id`',
    'date'     => '`t`.`date`',
    'scope'    => '`t`.`scope`',
    'type'     => '`it`.`type`',
    'status'   => '`t`.`status`',
    'severity' => '`t`.`severity`',
    'city'     => '`t`.`city`',
    'updated'  => '`t`.`updated`',
];
$sortCol = $sort_map[$sort] ?? '`t`.`date`';
$sortDir = $order === 'asc' ? 'ASC' : 'DESC';

try {
    // Count
    $total = (int) db_fetch_value(
        "SELECT COUNT(*)
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         {$whereClause}",
        $params
    );

    // Fetch. Phase 99m (Eric beta 2026-06-29): incident_number was
    // being written by incnum_allocate() during incident create but
    // never selected in the list or detail endpoints, so the
    // admin-configured numbering template (e.g. {YY}-{NNNN}) silently
    // never reached the UI. Add it to the projection.
    $rows = db_fetch_all(
        "SELECT
            `t`.`id`,
            `t`.`incident_number`,
            `t`.`scope`,
            `t`.`street`,
            `t`.`city`,
            `t`.`state`,
            `t`.`severity`,
            `t`.`status`,
            `t`.`date`,
            `t`.`updated`,
            `it`.`type`     AS `type_name`,
            `it`.`group`    AS `type_group`,
            (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
             WHERE `a`.`ticket_id` = `t`.`id`
               AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`,'%y') = '00')
            ) AS `active_responders`
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         {$whereClause}
         ORDER BY {$sortCol} {$sortDir}
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    // Fetch available type groups for filter
    $groups = db_fetch_all(
        "SELECT DISTINCT `group` FROM `{$prefix}in_types` WHERE `group` IS NOT NULL AND `group` != '' ORDER BY `group`"
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}

$incidents = [];
foreach ($rows as $row) {
    $sev = (int) $row['severity'];
    $st = (int) $row['status'];
    $incidents[] = [
        'id'                => (int) $row['id'],
        'incident_number'   => $row['incident_number'] ?? null,
        'scope'             => $row['scope'] ?? '',
        'street'            => $row['street'] ?? '',
        'city'              => $row['city'] ?? '',
        'state'             => $row['state'] ?? '',
        'severity'          => $sev,
        'severity_color'    => $sev_colors[$sev] ?? '#ffffff',
        'status'            => $st,
        'status_text'       => $status_labels[$st] ?? 'Unknown',
        'date'              => $row['date'],
        'updated'           => $row['updated'],
        'type_name'         => $row['type_name'] ?? '',
        'type_group'        => $row['type_group'] ?? '',
        'active_responders' => (int) $row['active_responders'],
    ];
}

$groupList = [];
foreach ($groups as $g) {
    $groupList[] = $g['group'];
}

ini_set('display_errors', $prevDisplay);

json_response([
    'incidents' => $incidents,
    'total'     => $total,
    'limit'     => $limit,
    'offset'    => $offset,
    'groups'    => $groupList,
]);
