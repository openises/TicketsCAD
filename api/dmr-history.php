<?php
/**
 * NewUI v4.0 API — Recent DMR call history (per channel).
 *
 * GET /api/dmr-history.php?channel=<dmr_channels.id>&limit=20
 *
 * Phase 84-followup — radio widget shows past traffic on open so a
 * dispatcher who just arrived at the screen can see what's been
 * happening on the channel without waiting for fresh activity.
 *
 * Returns the most-recent N rows from dmr_messages for the channel
 * (default 20, max 100). Each row has the same shape the widget's
 * live SSE call_start event already understands, so the same
 * renderCall() path can populate them.
 *
 * RBAC: action.dmr_receive (or admin).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

$rbacOk = function_exists('rbac_can') && (
    rbac_can('action.dmr_receive') || rbac_can('action.play_dmr_audio')
);
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing required permission: action.dmr_receive']);
    exit;
}

$prefix    = $GLOBALS['db_prefix'] ?? '';
$channelId = (int) ($_GET['channel'] ?? 0);
$limit     = (int) ($_GET['limit']   ?? 100);
if ($limit <= 0) $limit = 100;
if ($limit > 1000) $limit = 1000;

// Phase 86-archive: optional date-range + direction + search filters
// for the archive page. Validated tightly because they go into SQL.
$dateFrom = '';
$dateTo   = '';
if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
    $dateFrom = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
    $dateTo = $_GET['date_to'] . ' 23:59:59';
}
$direction = in_array($_GET['direction'] ?? '', ['rx', 'tx'], true) ? $_GET['direction'] : '';
$search = '';
if (!empty($_GET['search'])) {
    $s = trim((string) $_GET['search']);
    if (strlen($s) > 0 && strlen($s) < 100) $search = $s;
}

// Resolve channel (defaults to first enabled).
if ($channelId > 0) {
    $channel = db_fetch_one(
        "SELECT id, label, talkgroup FROM `{$prefix}dmr_channels`
         WHERE id = ? LIMIT 1",
        [$channelId]
    );
} else {
    $channel = db_fetch_one(
        "SELECT id, label, talkgroup FROM `{$prefix}dmr_channels`
         WHERE enabled = 1 ORDER BY id LIMIT 1"
    );
}
if (!$channel) {
    http_response_code(404);
    echo json_encode(['error' => 'No DMR channel available']);
    exit;
}

// Build the WHERE clause and bind params in step. All columns are
// qualified with `m.` because the SELECT below joins radioid_users
// and the unqualified names would be ambiguous after the JOIN.
// Search also looks at the cache's callsign so a user typing an
// unfamiliar callsign still finds calls even if the stored row
// hasn't been backfilled yet.
$where  = ['m.channel_id = ?'];
$params = [(int) $channel['id']];
if ($dateFrom !== '') { $where[] = 'm.call_started_at >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'm.call_started_at <= ?'; $params[] = $dateTo;   }
if ($direction !== '') { $where[] = 'm.direction = ?';       $params[] = $direction; }
if ($search !== '') {
    $where[] = '(m.transcript LIKE ? OR m.radio_callsign LIKE ? OR r.callsign LIKE ? OR m.radio_id LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    // Defensive resolve via LEFT JOIN: prefer whatever was written
    // into dmr_messages.radio_callsign at ingest time, but fall back
    // to the cached radioid_users row if the writer missed the
    // lookup. (Phase 86-archive-cleanup: ~85% of historical rows
    // arrived with radio_callsign NULL even though the ID was
    // already cached — backfill ran once, the JOIN guards future
    // ingestion gaps.) Also surfaces fname/surname so the playback
    // page can show "KE0XYZ — Dan A" instead of just the callsign.
    $rows = db_fetch_all(
        "SELECT m.id, m.direction, m.call_started_at, m.call_ended_at,
                m.duration_ms, m.talkgroup, m.radio_id,
                COALESCE(NULLIF(m.radio_callsign, ''), r.callsign) AS radio_callsign,
                r.fname    AS lookup_fname,
                r.surname  AS lookup_surname,
                m.member_id, m.transcript, m.transcript_engine,
                m.audio_path, m.audio_format
           FROM `{$prefix}dmr_messages` m
           LEFT JOIN `{$prefix}radioid_users` r ON r.dmr_id = m.radio_id
          {$whereSql}
          ORDER BY m.call_started_at DESC, m.id DESC
          LIMIT " . (int) $limit,
        $params
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'history query failed', 'detail' => $e->getMessage()]);
    exit;
}

$out = [];
foreach (array_reverse($rows) as $r) {
    $out[] = [
        'id'              => (int) $r['id'],
        'direction'       => $r['direction'],
        'started_at'      => $r['call_started_at'],
        'ended_at'        => $r['call_ended_at'],
        'duration_ms'     => (int) ($r['duration_ms'] ?? 0),
        'talkgroup'       => $r['talkgroup'],
        'src_id'          => (int) ($r['radio_id'] ?? 0) ?: null,
        'callsign'        => $r['radio_callsign'],
        // Display name from the radioid.net cache. Some cached rows
        // have the full name in `fname` and an empty `surname` — fine
        // for display, just trim before joining.
        'name'            => trim(trim((string) ($r['lookup_fname'] ?? '')) . ' '
                                . trim((string) ($r['lookup_surname'] ?? ''))) ?: null,
        'member_id'       => $r['member_id'] ? (int) $r['member_id'] : null,
        'transcript'      => $r['transcript'],
        'transcript_engine' => $r['transcript_engine'],
        'audio_path'      => $r['audio_path'],
        'audio_format'    => $r['audio_format'],
    ];
}

echo json_encode([
    'channel_id' => (int) $channel['id'],
    'channel'    => $channel['label'],
    'talkgroup'  => $channel['talkgroup'],
    'rows'       => $out,
]);
