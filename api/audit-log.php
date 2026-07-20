<?php
/**
 * NewUI v4.0 API - Audit Log Viewer
 *
 * GET /api/audit-log.php
 *
 * Query params:
 *   category    - Filter by category (auth, config, personnel, incident, etc.)
 *   activity    - Filter by activity (create, update, delete, login, etc.)
 *   severity    - Filter by min severity (0-5)
 *   user        - Filter by user_name (partial match)
 *   q           - Text search on summary
 *   date_from   - ISO date (YYYY-MM-DD)
 *   date_to     - ISO date (YYYY-MM-DD)
 *   sort        - Column to sort by (default: event_time)
 *   order       - asc/desc (default: desc)
 *   limit       - Page size (default 50, max 200)
 *   offset      - Pagination offset
 *
 * Returns: { entries: [...], total: N, limit: N, offset: N, categories: [...], activities: [...] }
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET required', 405);
}

// Require admin level
$userLevel = (int) ($_SESSION['level'] ?? 99);
if ($userLevel > 1) {
    json_error('Admin access required', 403);
}

// Ensure table exists
audit_ensure_table();

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = db_table('newui_audit_log');

// ── Parse filters ──
$category  = trim($_GET['category'] ?? '');
$activity  = trim($_GET['activity'] ?? '');
$severity  = $_GET['severity'] ?? '';
$userFilter = trim($_GET['user'] ?? '');
$q         = trim($_GET['q'] ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');

// ── Sort ──
$sortMap = [
    'event_time'  => 'event_time',
    'category'    => 'category',
    'activity'    => 'activity',
    'severity'    => 'severity',
    'user_name'   => 'user_name',
    'summary'     => 'summary',
    'target_type' => 'target_type',
];
$sort  = isset($sortMap[$_GET['sort'] ?? '']) ? $sortMap[$_GET['sort']] : 'event_time';
$order = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

// ── Pagination ──
$limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

// ── Build WHERE ──
$where  = [];
$params = [];

if ($category !== '') {
    $where[]  = '`category` = ?';
    $params[] = $category;
}
if ($activity !== '') {
    $where[]  = '`activity` = ?';
    $params[] = $activity;
}
if ($severity !== '' && is_numeric($severity)) {
    $where[]  = '`severity` >= ?';
    $params[] = (int) $severity;
}
if ($userFilter !== '') {
    $where[]  = '`user_name` LIKE ?';
    $params[] = '%' . $userFilter . '%';
}
if ($q !== '') {
    $where[]  = '`summary` LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($dateFrom !== '') {
    $where[]  = '`event_time` >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[]  = '`event_time` <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Count total ──
try {
    $total = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$table} {$whereSQL}",
        $params
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Query error: ' . $e->getMessage(), 500);
}

// ── Fetch entries ──
try {
    $entries = db_fetch_all(
        "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                `category`, `activity`, `severity`, `target_type`, `target_id`,
                `summary`, `details`
         FROM {$table}
         {$whereSQL}
         ORDER BY `{$sort}` {$order}
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Query error: ' . $e->getMessage(), 500);
}

// Parse JSON details field
for ($i = 0; $i < count($entries); $i++) {
    if (!empty($entries[$i]['details'])) {
        $entries[$i]['details'] = json_decode($entries[$i]['details'], true);
    }
    $entries[$i]['severity'] = (int) $entries[$i]['severity'];
}

// GH #86 — attach the configured case/incident number to ticket targets so the
// audit viewer shows BOTH the case number AND the raw DB id (a beta tester: "reference
// both for troubleshooting"). One batched lookup — no N+1. Non-fatal.
try {
    $auditPrefix = $GLOBALS['db_prefix'] ?? '';
    $ticketIds = [];
    foreach ($entries as $e) {
        if (in_array(($e['target_type'] ?? ''), ['ticket', 'incident'], true) && (int) ($e['target_id'] ?? 0) > 0) {
            $ticketIds[(int) $e['target_id']] = true;
        }
    }
    if (!empty($ticketIds)) {
        $ids = array_keys($ticketIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $numById = [];
        foreach (db_fetch_all("SELECT `id`, `incident_number` FROM `{$auditPrefix}ticket` WHERE `id` IN ($ph)", $ids) as $t) {
            $n = trim((string) ($t['incident_number'] ?? ''));
            if ($n !== '') $numById[(int) $t['id']] = $n;
        }
        for ($i = 0; $i < count($entries); $i++) {
            $tid = (int) ($entries[$i]['target_id'] ?? 0);
            if (in_array(($entries[$i]['target_type'] ?? ''), ['ticket', 'incident'], true) && isset($numById[$tid])) {
                $entries[$i]['target_incident_number'] = $numById[$tid];
            }
        }
    }
} catch (Exception $e) { /* non-fatal — viewer falls back to the raw DB id */ }

// ── Fetch distinct categories and activities for filter dropdowns ──
$categories = [];
$activities = [];
try {
    $catRows = db_fetch_all("SELECT DISTINCT `category` FROM {$table} ORDER BY `category`");
    foreach ($catRows as $r) { $categories[] = $r['category']; }

    $actRows = db_fetch_all("SELECT DISTINCT `activity` FROM {$table} ORDER BY `activity`");
    foreach ($actRows as $r) { $activities[] = $r['activity']; }
} catch (Exception $e) {
    // non-fatal
}

ini_set('display_errors', $prevDisplay);
json_response([
    'entries'    => $entries,
    'total'      => $total,
    'limit'      => $limit,
    'offset'     => $offset,
    'categories' => $categories,
    'activities' => $activities,
]);
