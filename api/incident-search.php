<?php
/**
 * NewUI v4.0 API - Incident Search
 *
 * GET /api/incident-search.php
 *   Parameters:
 *     q         - text search (scope, description, street, contact)
 *     type_id   - filter by incident type
 *     status    - filter by status (1=Closed, 2=Open, 3=Scheduled, 0=All)
 *     severity  - filter by severity (0, 1, 2)
 *     date_from - start date (YYYY-MM-DD)
 *     date_to   - end date (YYYY-MM-DD)
 *     city      - city filter
 *     sort      - sort field (id, date, scope, type, status, severity, city)
 *     order     - sort direction (asc, desc)
 *     limit     - results per page (default 50, max 200)
 *     offset    - pagination offset (default 0)
 *
 *   Returns: { results: [...], total: N, limit: N, offset: N }
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Parse params
$q         = trim($_GET['q'] ?? '');
$type_id   = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int) $_GET['type_id'] : null;
$status    = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : null;
$severity  = isset($_GET['severity']) && $_GET['severity'] !== '' ? (int) $_GET['severity'] : null;
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$city      = trim($_GET['city'] ?? '');
$sort      = trim($_GET['sort'] ?? 'date');
$order     = strtolower(trim($_GET['order'] ?? 'desc'));
$limit     = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset    = max(0, (int) ($_GET['offset'] ?? 0));

// Severity color map
$sev_colors = [
    0 => get_variable('sev_0_color') ?: '#00ff00',
    1 => get_variable('sev_1_color') ?: '#ffff00',
    2 => get_variable('sev_2_color') ?: '#ff0000',
];
$status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

// Build WHERE clauses
$where = [];
$params = [];

// Phase 99j-4 — org-scope filter via the standard helper (see
// specs/phase-99j-org-scoping/spec.md). Super Admin sees all; Org
// Admin sees own + descendants; ordinary users see their home org.
require_once __DIR__ . '/../inc/org-scope.php';
[$orgFrag, $orgVars] = org_query_filter('t.org_id');
if ($orgFrag !== '') {
    $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
    foreach ($orgVars as $v) $params[] = $v;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = "(`t`.`scope` LIKE ? OR `t`.`description` LIKE ? OR `t`.`street` LIKE ? OR `t`.`contact` LIKE ? OR `t`.`phone` LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($type_id !== null) {
    $where[] = "`t`.`in_types_id` = ?";
    $params[] = $type_id;
}

if ($status !== null && $status > 0) {
    $where[] = "`t`.`status` = ?";
    $params[] = $status;
}

if ($severity !== null) {
    $where[] = "`t`.`severity` = ?";
    $params[] = $severity;
}

if ($date_from !== '') {
    $where[] = "`t`.`date` >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to !== '') {
    $where[] = "`t`.`date` <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if ($city !== '') {
    $where[] = "`t`.`city` LIKE ?";
    $params[] = '%' . $city . '%';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Validate sort field
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
    // Count total matching rows
    $total = (int) db_fetch_value(
        "SELECT COUNT(*)
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         {$whereClause}",
        $params
    );

    // Fetch page of results
    $rows = db_fetch_all(
        "SELECT
            `t`.`id`,
            `t`.`scope`,
            `t`.`description`,
            `t`.`street`,
            `t`.`city`,
            `t`.`state`,
            `t`.`severity`,
            `t`.`status`,
            `t`.`date`,
            `t`.`updated`,
            `t`.`contact`,
            `t`.`phone`,
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
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}

$results = [];
foreach ($rows as $row) {
    $sev = (int) $row['severity'];
    $st = (int) $row['status'];
    $results[] = [
        'id'                => (int) $row['id'],
        'scope'             => $row['scope'] ?? '',
        'description'       => $row['description'] ?? '',
        'street'            => $row['street'] ?? '',
        'city'              => $row['city'] ?? '',
        'state'             => $row['state'] ?? '',
        'severity'          => $sev,
        'severity_color'    => $sev_colors[$sev] ?? '#ffffff',
        'status'            => $st,
        'status_text'       => $status_labels[$st] ?? 'Unknown',
        'date'              => $row['date'],
        'updated'           => $row['updated'],
        'contact'           => $row['contact'] ?? '',
        'phone'             => $row['phone'] ?? '',
        'type_name'         => $row['type_name'] ?? '',
        'type_group'        => $row['type_group'] ?? '',
        'active_responders' => (int) $row['active_responders'],
    ];
}

ini_set('display_errors', $prevDisplay);

json_response([
    'results' => $results,
    'total'   => $total,
    'limit'   => $limit,
    'offset'  => $offset,
]);
