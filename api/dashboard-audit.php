<?php
/**
 * NewUI v4.0 API — Dashboard Audit Activity (Phase 80c)
 *
 * Thin, dashboard-widget-oriented wrapper over the newui_audit_log table.
 * Used by the "Recent activity" GridStack widget. Distinct from
 * api/audit-log.php — that endpoint is the full admin browser with rich
 * filtering and a default 50-row page; this one is read-only, returns a
 * compact projection, and applies non-admin org/self scoping so a regular
 * dispatcher can park the widget on their dashboard without leaking other
 * orgs' activity.
 *
 * GET /api/dashboard-audit.php
 *
 * Query params:
 *   since        — ISO timestamp; only entries with event_time >= since
 *   limit        — page size (default 50, max 200)
 *   event_type   — exact match against `activity` (legacy alias for the
 *                  audit-log "activity" column, since the spec uses
 *                  "event_type" for this dashboard widget)
 *   actor_id     — exact match against `user_id`
 *   category     — exact match against `category`
 *   id           — when set, returns the single row for the modal detail
 *                  view (includes the JSON details blob). Subject to the
 *                  same scoping rules as the list.
 *
 * Returns: { entries: [...], total: N, limit: N, is_admin: bool }
 *   entries[i] = {
 *     id, ts, event_type, actor_id, actor_name, target_table,
 *     target_id, ip, summary, severity, category,
 *     details (only when ?id= is supplied)
 *   }
 *
 * Access:
 *   - is_admin() OR rbac_can('widget.audit_log') — widget visibility
 *   - is_admin() OR rbac_can('action.view_audit') — required to see
 *     entries from users other than the caller. Non-admins without
 *     action.view_audit are scoped to their own user_id only.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/rbac.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET required', 405);
}

// Widget visibility gate. is_admin() always wins; otherwise the user
// must hold the widget.audit_log permission (seeded by sql/run_phase80c_perms.php).
$isAdmin = is_admin();
$canViewWidget = $isAdmin || rbac_can('widget.audit_log');
if (!$canViewWidget) {
    ini_set('display_errors', $prevDisplay);
    json_error('Permission denied — widget.audit_log required', 403);
}

// Cross-actor visibility gate. Admins and holders of action.view_audit
// see everyone's activity; everyone else sees only their own rows.
$canViewAll = $isAdmin || rbac_can('action.view_audit');

audit_ensure_table();

$table = db_table('newui_audit_log');

// ── Parse filters ──
$since      = trim((string) ($_GET['since']      ?? ''));
$eventType  = trim((string) ($_GET['event_type'] ?? ''));
$category   = trim((string) ($_GET['category']   ?? ''));
$actorIdRaw = $_GET['actor_id'] ?? '';
$singleIdRaw = $_GET['id']      ?? '';

$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

$where  = [];
$params = [];

// Non-admins are pinned to their own user_id.
if (!$canViewAll) {
    $where[]  = '`user_id` = ?';
    $params[] = (int) $_SESSION['user_id'];
}

if ($since !== '') {
    // Accept ISO-ish timestamps; MariaDB tolerates the T-separator form.
    $where[]  = '`event_time` >= ?';
    $params[] = $since;
}

if ($eventType !== '') {
    $where[]  = '`activity` = ?';
    $params[] = $eventType;
}

if ($category !== '') {
    $where[]  = '`category` = ?';
    $params[] = $category;
}

if ($actorIdRaw !== '' && is_numeric($actorIdRaw)) {
    $where[]  = '`user_id` = ?';
    $params[] = (int) $actorIdRaw;
}

// Single-row detail mode for the modal.
$singleId = 0;
if ($singleIdRaw !== '' && is_numeric($singleIdRaw)) {
    $singleId = (int) $singleIdRaw;
    $where[]  = '`id` = ?';
    $params[] = $singleId;
}

$whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    if ($singleId > 0) {
        // Detail view — single row, include the JSON details blob.
        $row = db_fetch_one(
            "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                    `category`, `activity`, `severity`, `target_type`, `target_id`,
                    `summary`, `details`
             FROM {$table}
             {$whereSQL}
             LIMIT 1",
            $params
        );
        if (!$row) {
            ini_set('display_errors', $prevDisplay);
            json_error('Not found', 404);
        }
        $displayNames = _dashaud_resolve_target_names([$row]);
        $entry = _dashaud_project($row, true, $displayNames);
        ini_set('display_errors', $prevDisplay);
        json_response([
            'entry'    => $entry,
            'is_admin' => $isAdmin,
        ]);
    }

    // List view.
    $entries = db_fetch_all(
        "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                `category`, `activity`, `severity`, `target_type`, `target_id`,
                `summary`
         FROM {$table}
         {$whereSQL}
         ORDER BY `event_time` DESC, `id` DESC
         LIMIT " . (int) $limit,
        $params
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Query error: ' . $e->getMessage(), 500);
}

// GH#111 (rjonesbsink) — resolve a readable name for the most common
// target types instead of always showing "type #id". One batched query
// per type — no N+1 — same pattern api/audit-log.php already uses for
// ticket/incident (that endpoint's own resolver, `:248-271`, was the
// worked example this one follows). Deliberately scoped to named
// entities a viewer recognizes by name (ticket/incident, user,
// responder, facility, member) rather than every target_type this
// install's audit log records — many of the others (par_cycle,
// org_relationship, settings, security_label, ...) are internal/
// operational records where the id IS the natural identifier and a
// "display name" wouldn't mean anything. Extending this list further is
// a judgment call for whichever of those turns out to matter in
// practice, not attempted here.
$displayNames = _dashaud_resolve_target_names($entries);

$out = [];
foreach ($entries as $row) {
    $out[] = _dashaud_project($row, false, $displayNames);
}

ini_set('display_errors', $prevDisplay);
json_response([
    'entries'  => $out,
    'total'    => count($out),
    'limit'    => $limit,
    'is_admin' => $isAdmin,
    'scoped'   => !$canViewAll,
]);

/**
 * GH#111 — batched resolver: target_type/target_id -> a readable display
 * name, for the handful of target types that are named entities. Returns
 * a map keyed "type:id" => name; a type/id pair with nothing resolved is
 * simply absent from the map (caller falls back to "type #id").
 *
 * One query per TYPE present in $rows (not per row), matching the
 * existing api/audit-log.php ticket/incident resolver's own shape.
 */
function _dashaud_resolve_target_names(array $rows): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $idsByType = [];
    foreach ($rows as $r) {
        $t  = $r['target_type'] ?? '';
        $id = (int) ($r['target_id'] ?? 0);
        if ($t !== '' && $id > 0) {
            $idsByType[$t][$id] = true;
        }
    }
    if (empty($idsByType)) return [];

    // type => [table, id column, SQL expression for the display name]
    $lookups = [
        'ticket'    => [$prefix . 'ticket',      'id', 'NULLIF(`incident_number`, \'\')'],
        'incident'  => [$prefix . 'ticket',      'id', 'NULLIF(`incident_number`, \'\')'],
        'user'      => [$prefix . 'user',        'id', '`user`'],
        'responder' => [$prefix . 'responder',   'id', 'COALESCE(NULLIF(`name`, \'\'), `handle`)'],
        'facility'  => [$prefix . 'facilities',  'id', '`name`'],
        'member'    => [$prefix . 'member',      'id', 'TRIM(CONCAT(COALESCE(`first_name`,\'\'), \' \', COALESCE(`last_name`,\'\')))'],
    ];

    $names = [];
    foreach ($idsByType as $type => $idSet) {
        if (!isset($lookups[$type])) continue;
        list($table, $idCol, $nameExpr) = $lookups[$type];
        $ids = array_keys($idSet);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            foreach (db_fetch_all("SELECT `{$idCol}` AS _id, {$nameExpr} AS _name FROM `{$table}` WHERE `{$idCol}` IN ($ph)", $ids) as $row) {
                $n = trim((string) ($row['_name'] ?? ''));
                if ($n !== '') $names[$type . ':' . (int) $row['_id']] = $n;
            }
        } catch (Exception $e) {
            // Non-fatal — that type's entries fall back to "type #id".
        }
    }
    return $names;
}

/**
 * Project a raw audit_log row into the dashboard-widget shape.
 * The spec uses the "event_type / actor_name / target_table" naming so
 * the JS doesn't have to know about the older column names.
 */
function _dashaud_project(array $row, bool $withDetails, array $displayNames = []): array {
    $targetType = $row['target_type'] ?? '';
    $targetId   = $row['target_id']   ?? '';
    $proj = [
        'id'           => (int) $row['id'],
        'ts'           => $row['event_time'],
        'event_type'   => $row['activity'],
        'category'     => $row['category'],
        'severity'     => (int) $row['severity'],
        'actor_id'     => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'actor_name'   => $row['user_name'] ?? '',
        'target_table' => $targetType,
        'target_id'    => $targetId,
        'target_name'  => $displayNames[$targetType . ':' . (int) $targetId] ?? null,
        'ip'           => $row['ip_address']  ?? '',
        'summary'      => $row['summary']     ?? '',
    ];
    if ($withDetails) {
        $detailsRaw = $row['details'] ?? null;
        $proj['details'] = null;
        if (!empty($detailsRaw)) {
            $decoded = json_decode((string) $detailsRaw, true);
            $proj['details'] = ($decoded === null) ? $detailsRaw : $decoded;
        }
    }
    return $proj;
}
