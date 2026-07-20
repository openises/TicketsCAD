<?php
/**
 * NewUI v4.0 API - Zello Message History
 *
 * GET /api/zello-messages.php
 *   ?channel=  — filter by channel name (optional)
 *   ?since=    — only messages after this datetime, ISO format (optional)
 *   ?limit=    — max rows to return, 1-200, default 50 (optional)
 *   ?incident= — filter by incident_id (optional)
 *
 * Returns { "messages": [...] } ordered by created DESC.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$channel  = trim($_GET['channel'] ?? '');
$since    = trim($_GET['since'] ?? '');
$incident = isset($_GET['incident']) ? (int) $_GET['incident'] : null;
$limit    = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;

if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;

try {
    $where  = [];
    $params = [];

    if ($channel !== '') {
        $where[]  = '`channel` = ?';
        $params[] = $channel;
    }

    if ($since !== '') {
        $where[]  = '`created` >= ?';
        $params[] = $since;
    }

    if ($incident !== null) {
        $where[]  = '`incident_id` = ?';
        $params[] = $incident;
    }

    $whereClause = '';
    if (!empty($where)) {
        $whereClause = ' WHERE ' . implode(' AND ', $where);
    }

    // Phase 101 fix (Eric beta 2026-07-01) — dynamically pick only the
    // columns that exist. The base schema has latitude/longitude/
    // responder_id/read_at optional; installs seeded from older
    // migrations lack them. Old fixed SELECT blew up with "Column not
    // found" and returned empty — the widget's loadHistory + the
    // archive page both silently showed nothing. Query
    // information_schema once per request, build the column list.
    $desiredCols = ['id', 'channel', 'sender_username', 'sender_display',
                    'message_type', 'content', 'duration_ms', 'media_url',
                    'latitude', 'longitude', 'direction', 'responder_id',
                    'incident_id', 'read_at', 'created'];
    $presentCols = [];
    try {
        $rows = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?",
            [$prefix . 'zello_messages']
        );
        $have = [];
        foreach ($rows as $r) $have[strtolower($r['COLUMN_NAME'])] = true;
        foreach ($desiredCols as $c) {
            if (isset($have[strtolower($c)])) $presentCols[] = $c;
        }
    } catch (Exception $e) {
        // Fallback: assume the columns declared in the base schema exist
        $presentCols = ['id', 'channel', 'sender_username', 'sender_display',
                        'message_type', 'content', 'duration_ms', 'media_url',
                        'direction', 'incident_id', 'created'];
    }
    if (empty($presentCols)) {
        json_response(['messages' => [], 'error' => 'zello_messages table missing or unreadable']);
    }
    $colList = '`' . implode('`, `', $presentCols) . '`';
    $sql = "SELECT {$colList}
            FROM `{$prefix}zello_messages`
            {$whereClause}
            ORDER BY `created` DESC
            LIMIT ?";
    $params[] = $limit;

    $messages = db_fetch_all($sql, $params);

    json_response(['messages' => $messages]);
} catch (Exception $e) {
    json_error('Failed to load messages: ' . $e->getMessage(), 500);
}
