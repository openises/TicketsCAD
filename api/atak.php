<?php
/**
 * Phase 91 Slice 5 (post-consolidation) — ATAK / TAK admin API.
 *
 * Reads ATAK policy from mesh_channels (with atak_* columns added by
 * sql/run_atak_consolidation.php), and reads the CoT event feed from
 * mesh_packet_log (Phase 35) filtered to ATAK-bearing port_kinds.
 *
 * Endpoints:
 *   GET  ?action=channels                  list mesh_channels with atak_enabled flag + policy
 *   POST ?action=save_channel_atak         update the ATAK policy of a mesh_channels row
 *                                          (does NOT touch non-ATAK channel fields — that's mesh.php's job)
 *   GET  ?action=recent[&direction=in|out][&limit=50]
 *                                          last N CoT events from mesh_packet_log
 *   GET  ?action=unbound                   atak_unbound_uids review list
 *   POST ?action=bind_unbound              {atak_uid, member_id} → write member_comm_identifiers row
 *
 * Auth: admin OR action.manage_atak OR action.manage_mesh_bridges
 *       (the latter is the existing mesh-console permission).
 * CSRF: required on POSTs.
 *
 * Tokens for ATAK ingest: not minted via this file. The mesh bridges
 * authenticate as bridges via api/mesh.php (existing) — bridge token
 * is the trust boundary. The location_ingest_tokens path remains for
 * future non-mesh sources (TAK Server v1.5).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function _atak_admin_can(): bool {
    if (is_admin()) return true;
    if (!function_exists('rbac_can')) return false;
    return rbac_can('action.manage_atak')
        || rbac_can('action.manage_mesh_bridges')
        || rbac_can('action.manage_config');
}

if (!_atak_admin_can()) {
    json_error('Forbidden — manage_atak or manage_mesh_bridges required', 403);
}

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }
}

$action = $_GET['action'] ?? ($input['action'] ?? '');

// ── GET channels: all mesh_channels + their ATAK policy ─────────
if ($method === 'GET' && $action === 'channels') {
    try {
        $rows = db_fetch_all(
            "SELECT c.id, c.name, c.region, c.modem_preset, c.is_primary,
                    c.archived_at,
                    c.atak_enabled, c.atak_sensitive_flag,
                    c.atak_push_incidents, c.atak_push_units,
                    c.atak_push_facilities, c.atak_push_chat,
                    c.atak_marker_action,
                    c.atak_position_min_secs, c.atak_position_min_m,
                    (SELECT COUNT(*) FROM `{$prefix}mesh_bridge_channels` bc
                       WHERE bc.channel_id = c.id) AS bridge_count
               FROM `{$prefix}mesh_channels` c
              WHERE c.archived_at IS NULL
              ORDER BY c.atak_enabled DESC, c.is_primary DESC, c.name ASC"
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['channels' => $rows, 'count' => count($rows)]);
}

// ── POST save_channel_atak: only the ATAK policy fields ────────
if ($method === 'POST' && $action === 'save_channel_atak') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) json_error('id (mesh_channels.id) required', 400);

    $sensitive = !empty($input['atak_sensitive_flag']) ? 1 : 0;
    $pushI     = !empty($input['atak_push_incidents'])  ? 1 : 0;
    $pushU     = !empty($input['atak_push_units'])      ? 1 : 0;
    $pushF     = !empty($input['atak_push_facilities']) ? 1 : 0;
    $pushC     = !empty($input['atak_push_chat'])       ? 1 : 0;
    $enabled   = !empty($input['atak_enabled'])         ? 1 : 0;
    $marker    = trim((string) ($input['atak_marker_action'] ?? 'new_incident'));
    if (!in_array($marker, ['new_incident','note_nearest'], true)) {
        json_error('atak_marker_action must be new_incident or note_nearest', 400);
    }
    $minSec = max(5,  min(3600,  (int) ($input['atak_position_min_secs'] ?? 60)));
    $minM   = max(0,  min(10000, (int) ($input['atak_position_min_m']    ?? 25)));

    try {
        db_query(
            "UPDATE `{$prefix}mesh_channels`
                SET atak_enabled = ?, atak_sensitive_flag = ?,
                    atak_push_incidents = ?, atak_push_units = ?,
                    atak_push_facilities = ?, atak_push_chat = ?,
                    atak_marker_action = ?,
                    atak_position_min_secs = ?, atak_position_min_m = ?
              WHERE id = ?",
            [$enabled, $sensitive, $pushI, $pushU, $pushF, $pushC,
             $marker, $minSec, $minM, $id]
        );
        audit_log('config', 'update', 'mesh_channel', $id,
                  "Updated ATAK policy on mesh_channel #{$id} (enabled={$enabled})");
        json_response(['saved' => true, 'id' => $id]);
    } catch (Exception $e) {
        json_error('save failed: ' . $e->getMessage(), 500);
    }
}

// ── GET recent: CoT-bearing rows from mesh_packet_log ──────────
if ($method === 'GET' && $action === 'recent') {
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 50)));
    // direction filter — for now mesh_packet_log doesn't distinguish
    // in/out (it's all inbound from the bridges' POV). Outbound goes
    // via mesh_outbox which we don't surface here yet. If/when the
    // outbound emitter ships, query mesh_outbox + UNION.
    try {
        $rows = db_fetch_all(
            "SELECT l.id, l.received_at, l.bridge_id, l.protocol,
                    l.src_node, l.display_name, l.port_kind,
                    l.snr, l.rssi, l.hops, l.payload_text,
                    l.lat, l.lng,
                    b.label AS bridge_label,
                    TIMESTAMPDIFF(SECOND, l.received_at, NOW()) AS age_seconds
               FROM `{$prefix}mesh_packet_log` l
               LEFT JOIN `{$prefix}mesh_bridges` b ON b.id = l.bridge_id
              WHERE l.port_kind IN ('ATAK_PLUGIN','TEXT_MESSAGE_APP')
                AND (l.port_kind = 'ATAK_PLUGIN'
                     OR l.payload_text LIKE '<event%')
              ORDER BY l.id DESC
              LIMIT {$limit}"
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['events' => $rows, 'count' => count($rows)]);
}

// ── GET unbound (atak_unbound_uids — survives consolidation) ───
if ($method === 'GET' && $action === 'unbound') {
    try {
        $rows = db_fetch_all(
            "SELECT id, atak_uid, callsign_seen, transport, channel_ref,
                    first_seen, last_seen, position_count,
                    last_lat, last_lng, bound_to
               FROM `{$prefix}atak_unbound_uids`
              WHERE bound_to IS NULL
              ORDER BY last_seen DESC
              LIMIT 200"
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['unbound' => $rows, 'count' => count($rows)]);
}

// ── POST bind_unbound (writes member_comm_identifiers row) ─────
if ($method === 'POST' && $action === 'bind_unbound') {
    $uid = trim((string) ($input['atak_uid'] ?? ''));
    $memberId = (int) ($input['member_id'] ?? 0);
    if ($uid === '' || $memberId <= 0) json_error('atak_uid and member_id required', 400);

    try {
        $atakModeId = (int) db_fetch_value(
            "SELECT id FROM `{$prefix}comm_modes` WHERE code = 'atak' LIMIT 1"
        );
        if (!$atakModeId) {
            json_error('ATAK comm_mode not seeded — run sql/run_atak_schema.php', 500);
        }

        $exists = db_fetch_value(
            "SELECT id FROM `{$prefix}member_comm_identifiers`
              WHERE comm_mode_id = ?
                AND JSON_EXTRACT(values_json, '$.atak_uid') = JSON_QUOTE(?)
              LIMIT 1",
            [$atakModeId, $uid]
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}member_comm_identifiers`
                    (member_id, comm_mode_id, label, values_json, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$memberId, $atakModeId, 'ATAK', json_encode(['atak_uid' => $uid])]
            );
        }
        db_query(
            "UPDATE `{$prefix}atak_unbound_uids`
                SET bound_to = ?
              WHERE atak_uid = ?",
            [$memberId, $uid]
        );
        audit_log('config', 'create', 'atak_binding', $memberId,
                  "Bound ATAK uid '{$uid}' to member #{$memberId}");
        json_response(['saved' => true]);
    } catch (Exception $e) {
        json_error('bind failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action: ' . $action, 400);
