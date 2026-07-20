<?php
/**
 * NewUI v4.0 API — Mesh-bridge endpoint (Phase 35, 2026-06-12).
 *
 * Two consumer paths:
 *
 *   Bridges (bearer token auth):
 *     POST  ?action=ingest      — bridge submits received packets (1+)
 *     GET   ?action=poll_outbox — bridge asks "what should I send next?"
 *     POST  ?action=ack_outbox  — bridge reports completion / error
 *
 *   Admin UI (session auth + manage_mesh_bridges perm):
 *     POST  ?action=mint_token   — issue a new bearer token for a bridge
 *     GET   ?action=bridges      — list registered bridges + last_seen
 *     GET   ?action=feed         — recent mesh_packet_log rows
 *     POST  ?action=send         — queue an outbound text
 *     POST  ?action=set_config   — queue a device-config change
 *     POST  ?action=revoke       — revoke a bridge token
 *     POST  ?action=delete_bridge — soft-delete a bridge
 *     GET   ?action=coverage     — per-source-node receive matrix
 *
 * Bearer auth: Authorization: Bearer <hex token>. We sha256() it and look
 * up in bridge_tokens; if found + not revoked, the call is authenticated
 * as that bridge.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

// Phase 73x — rate-limit bridge ingest. Each packet writes a row
// with up to 65KB raw_data + a geofence_check pass; flood would
// DoS the DB. 1200 packets/min/IP is well above any legitimate
// bridge's burst rate.
if ($action === 'ingest' && $method === 'POST') {
    require_once __DIR__ . '/../inc/rate-limit.php';
    $srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!rate_limit_ok('mesh-ingest:' . $srcIp, 1200, 60)) {
        rate_limit_reject(60);
    }
}

/**
 * Phase 73w — XSS hardening for bridge-supplied node metadata.
 *
 * short_name / long_name / hw_model / role come straight from
 * bridge daemons that relay mesh node announcements. A compromised
 * or malicious bridge token can register a node with
 * `<img src=x onerror=...>` as its name and pop sessions when an
 * admin opens the Mesh Console feed. The dispatcher UI today calls
 * innerHTML on parts of the feed render, so the safe path is to
 * sanitize at ingest: strip < > and trim. Legitimate node names
 * are alphanumeric + a few separators; angle brackets aren't real
 * Meshtastic/MeshCore name content.
 */
function _mesh_sanitize_name(?string $val, int $maxLen): ?string {
    if ($val === null) return null;
    $val = (string) $val;
    // Strip angle brackets so <script>, <img onerror=>, etc. never
    // make it into the row. Keep printable text otherwise.
    $val = str_replace(['<', '>'], '', $val);
    // Strip control chars except tab/space.
    $val = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $val);
    $val = trim((string) $val);
    if ($val === '') return null;
    return mb_substr($val, 0, $maxLen);
}

// JSON input for POST
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : [];
    if (empty($action) && !empty($input['action'])) $action = $input['action'];
}

// ─────────────────────────────────────────────────────────────────────
//  Bearer token auth helper
// ─────────────────────────────────────────────────────────────────────
function bridge_auth(): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $hdr = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $h) {
        if (!empty($_SERVER[$h])) { $hdr = $_SERVER[$h]; break; }
    }
    if (!$hdr || stripos($hdr, 'Bearer ') !== 0) return null;
    $token = trim(substr($hdr, 7));
    if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) return null;
    $hash = hash('sha256', $token);
    try {
        $row = db_fetch_one(
            "SELECT t.id AS token_id, t.bridge_id, b.label, b.revoked_at AS bridge_revoked
               FROM `{$prefix}bridge_tokens` t
               JOIN `{$prefix}mesh_bridges` b ON b.id = t.bridge_id
              WHERE t.token_hash = ? AND t.revoked_at IS NULL LIMIT 1",
            [$hash]
        );
        if (!$row) return null;
        if (!empty($row['bridge_revoked'])) return null;
        db_query(
            "UPDATE `{$prefix}bridge_tokens` SET last_used_at = NOW() WHERE id = ?",
            [(int) $row['token_id']]
        );
        db_query(
            "UPDATE `{$prefix}mesh_bridges` SET last_seen_at = NOW() WHERE id = ?",
            [(int) $row['bridge_id']]
        );
        return ['bridge_id' => (int) $row['bridge_id'], 'label' => $row['label']];
    } catch (Exception $e) {
        return null;
    }
}

// ─────────────────────────────────────────────────────────────────────
//  Session+perm auth helper for admin actions
// ─────────────────────────────────────────────────────────────────────
function admin_auth(): void {
    require_once __DIR__ . '/auth.php';   // exits if not authenticated
    require_once __DIR__ . '/../inc/rbac.php';
    if (!rbac_can('action.manage_mesh_bridges')) {
        json_error('Forbidden — need action.manage_mesh_bridges', 403);
    }
}

function bridge_or_admin(): ?array {
    // Try bearer first
    $b = bridge_auth();
    if ($b) return $b;
    admin_auth();
    return null;  // admin (no bridge context)
}

// ─────────────────────────────────────────────────────────────────────
//  Phase C — shared outbound-send queue helper.
//
//  Both ?action=send and ?action=reply queue an identical send_text
//  mesh_outbox row; reply just derives its address/slot/thread from an
//  inbound packet first. Building the row in one place keeps the payload
//  shape and the optional reply/thread columns consistent.
//
//  $opts:
//    text                (required) message body
//    protocol            meshtastic|meshcore|any
//    channel_slot        int 0..7
//    to_node             '' for channel broadcast, else direct address
//    target_bridge_id    int|0
//    queued_by           int|0  (user id)
//    in_reply_to_packet_id int|null
//    thread_key          string|null
//
//  Returns the inserted outbox id. Throws on DB error (caller handles).
//
//  Reply/thread columns are written only when present on the schema
//  (probed once) so a pre-migration install still queues a plain send.
// ─────────────────────────────────────────────────────────────────────
// Phase D: the queue + thread-key logic moved to inc/mesh_outbox.php so the
// router can reuse it (api/mesh.php executes at include time and can't be
// required). These remain as thin back-compat wrappers for in-file callers.
require_once __DIR__ . '/../inc/mesh_outbox.php';

function _mesh_queue_send(array $opts): int {
    return mesh_enqueue_send($opts);
}

function _mesh_thread_key(string $proto, ?string $srcNode, ?int $channelIdx, bool $isDirect): string {
    return mesh_build_thread_key($proto, $srcNode, $channelIdx, $isDirect);
}

// ═══════════════════════════════════════════════════════════════════
//  BRIDGE-FACING ACTIONS
// ═══════════════════════════════════════════════════════════════════

// ── ingest: bridge submits one or more received packets ─────────────
if ($action === 'ingest' && $method === 'POST') {
    $bridge = bridge_auth();
    if (!$bridge) json_error('Bridge auth required', 401);
    $bridgeId = (int) $bridge['bridge_id'];

    $packets = $input['packets'] ?? null;
    if (!is_array($packets)) json_error('packets[] required');

    $stored = 0;
    foreach ($packets as $p) {
        $protocol = isset($p['protocol']) && in_array($p['protocol'], ['meshtastic','meshcore'], true)
            ? $p['protocol'] : 'meshtastic';
        try {
            $srcNode = isset($p['src_node']) ? substr((string) $p['src_node'], 0, 48) : null;
            // Phase 39A: denormalize display_name from mesh_nodes for fast feed render.
            $displayName = null;
            if ($srcNode) {
                try {
                    $displayName = db_fetch_value(
                        "SELECT COALESCE(long_name, short_name) FROM `{$prefix}mesh_nodes` WHERE node_id = ?",
                        [$srcNode]
                    );
                } catch (Exception $e) { /* column not present yet */ }
            }
            // Phase C: channel_idx — the slot an inbound CHANNEL message
            // arrived on, so a channel reply can target the same slot. The
            // column is added by sql/run_mesh_replies.php; probe once so a
            // bridge ingesting before the migration ran doesn't 1054-error
            // the whole insert (graceful degradation per the DB conventions).
            static $_hasChannelIdx = null;
            if ($_hasChannelIdx === null) {
                try {
                    $_hasChannelIdx = (bool) db_fetch_value(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME = ? AND COLUMN_NAME = 'channel_idx'",
                        [$prefix . 'mesh_packet_log']
                    );
                } catch (Exception $e) { $_hasChannelIdx = false; }
            }
            // channel_idx may arrive as channel_idx (preferred) or channel_slot.
            $channelIdx = null;
            if (isset($p['channel_idx']) && $p['channel_idx'] !== null && $p['channel_idx'] !== '') {
                $channelIdx = max(0, min(255, (int) $p['channel_idx']));
            } elseif (isset($p['channel_slot']) && $p['channel_slot'] !== null && $p['channel_slot'] !== '') {
                $channelIdx = max(0, min(255, (int) $p['channel_slot']));
            }

            $cols = "received_at, bridge_id, protocol, packet_id, src_node, display_name, dst_node,
                     port_kind, snr, rssi, hops, payload_text, payload_json, lat, lng";
            $vals = "COALESCE(?, NOW(3)), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
            $args = [
                    isset($p['received_at']) ? (gmdate('Y-m-d H:i:s', (int) $p['received_at']) . sprintf('.%03d', (int) ((floatval($p['received_at']) - intval($p['received_at'])) * 1000))) : null,
                    $bridgeId,
                    $protocol,
                    isset($p['packet_id']) ? (int) $p['packet_id'] : null,
                    $srcNode,
                    $displayName,
                    isset($p['dst_node']) ? substr((string) $p['dst_node'], 0, 48) : null,
                    isset($p['port_kind']) ? substr((string) $p['port_kind'], 0, 32) : null,
                    isset($p['snr']) ? (float) $p['snr'] : null,
                    isset($p['rssi']) ? (int) $p['rssi'] : null,
                    isset($p['hops']) ? (int) $p['hops'] : null,
                    isset($p['payload_text']) ? substr((string) $p['payload_text'], 0, 512) : null,
                    isset($p['payload_json']) ? json_encode($p['payload_json']) : null,
                    isset($p['lat']) ? (float) $p['lat'] : null,
                    isset($p['lng']) ? (float) $p['lng'] : null,
            ];
            if ($_hasChannelIdx) {
                $cols .= ", channel_idx";
                $vals .= ", ?";
                $args[] = $channelIdx;
            }
            db_query(
                "INSERT INTO `{$prefix}mesh_packet_log` ($cols) VALUES ($vals)",
                $args
            );
            $stored++;

            // Phase 39A: also bump mesh_nodes last_* fields if we have a src_node.
            if ($srcNode) {
                try {
                    db_query(
                        "INSERT INTO `{$prefix}mesh_nodes`
                            (node_id, protocol, bridge_id, last_snr, last_rssi, last_hops,
                             last_lat, last_lng, last_seen_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
                         ON DUPLICATE KEY UPDATE
                            protocol     = COALESCE(VALUES(protocol), protocol),
                            bridge_id    = VALUES(bridge_id),
                            last_snr     = COALESCE(VALUES(last_snr), last_snr),
                            last_rssi    = COALESCE(VALUES(last_rssi), last_rssi),
                            last_hops    = COALESCE(VALUES(last_hops), last_hops),
                            last_lat     = COALESCE(VALUES(last_lat), last_lat),
                            last_lng     = COALESCE(VALUES(last_lng), last_lng),
                            last_seen_at = NOW(3)",
                        [
                            $srcNode,
                            $protocol,
                            $bridgeId,
                            isset($p['snr'])  ? (float) $p['snr']  : null,
                            isset($p['rssi']) ? (int) $p['rssi']   : null,
                            isset($p['hops']) ? (int) $p['hops']   : null,
                            isset($p['lat'])  ? (float) $p['lat']  : null,
                            isset($p['lng'])  ? (float) $p['lng']  : null,
                        ]
                    );
                } catch (Exception $e) { /* table may not exist yet */ }
            }

            // Phase 91 consolidation — ATAK CoT routing hook.
            // If this packet is an ATAK Plugin protobuf (port 72) OR a
            // text-message that contains a CoT XML <event>, fan out to
            // the CoT router AFTER the normal mesh_packet_log insert.
            // The packet is still fully audited above; this just adds
            // the downstream side-effects (location_reports row,
            // incident/note creation, chat broker emission). Wrapped
            // in try/catch so an ATAK-side issue never breaks the
            // primary ingest path.
            try {
                $pk = $p['port_kind'] ?? '';
                if ($pk === 'ATAK_PLUGIN' || $pk === 'TEXT_MESSAGE_APP') {
                    @require_once __DIR__ . '/../inc/cot.php';
                    @require_once __DIR__ . '/../inc/atak_route.php';
                    if (function_exists('atak_route_inbound')) {
                        $entity = _atak_extract_entity_from_packet($p);
                        if ($entity) {
                            $chanRef = _atak_channel_ref_for_bridge($prefix, $bridgeId);
                            atak_route_inbound($entity, 'meshtastic', $chanRef, null);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("[mesh.php atak route] " . $e->getMessage());
            }
        } catch (Exception $e) {
            // skip this packet, continue
        }
    }
    // Stamp last_packet_at on the bridge if anything stored
    if ($stored > 0) {
        try { db_query("UPDATE `{$prefix}mesh_bridges` SET last_packet_at = NOW() WHERE id = ?", [$bridgeId]); }
        catch (Exception $e) {}
    }
    json_response(['stored' => $stored]);
}

// ─── Phase 91 helpers ──────────────────────────────────────────
//
// Translate a mesh-ingest packet payload into the CoT entity-dict
// shape inc/atak_route.php expects. Two paths:
//   - port_kind=ATAK_PLUGIN: payload_json carries the parsed
//     TAKPacket fields (pli for position, chat for chat). Bridge
//     daemons are expected to decode the protobuf and include it
//     under payload_json before ingest. If a bridge doesn't, we
//     log nothing here and the packet still lands in mesh_packet_log.
//   - port_kind=TEXT_MESSAGE_APP: if payload_text starts with
//     '<event', it's raw CoT XML — hand to cot_decode_xml().

function _atak_extract_entity_from_packet(array $p): ?array {
    $portKind = $p['port_kind'] ?? '';
    $srcNode  = $p['src_node'] ?? '';
    if ($srcNode === '') return null;

    if ($portKind === 'TEXT_MESSAGE_APP') {
        $text = (string) ($p['payload_text'] ?? '');
        if (strpos($text, '<event') === false) return null;
        return function_exists('cot_decode_xml') ? cot_decode_xml($text) : null;
    }

    if ($portKind !== 'ATAK_PLUGIN') return null;

    // payload_json from the bridge may arrive as either an array
    // (already-parsed) or a JSON-encoded string. Handle both.
    $j = $p['payload_json'] ?? null;
    if (is_string($j)) $j = json_decode($j, true);
    if (!is_array($j)) return null;

    if (isset($j['pli']) && is_array($j['pli'])) {
        $pli     = $j['pli'];
        $contact = is_array($j['contact'] ?? null) ? $j['contact'] : [];
        $lat = isset($pli['latitudeI'])  ? ((float) $pli['latitudeI'])  / 1e7 : ($pli['latitude']  ?? null);
        $lng = isset($pli['longitudeI']) ? ((float) $pli['longitudeI']) / 1e7 : ($pli['longitude'] ?? null);
        if ($lat === null || $lng === null) return null;
        return [
            'uid'         => $srcNode,
            'kind'        => 'responder',
            'lat'         => (float) $lat,
            'lng'         => (float) $lng,
            'altitude'    => $pli['altitude'] ?? null,
            'speed'       => $pli['speed']    ?? null,
            'course'      => $pli['course']   ?? null,
            'callsign'    => $contact['callsign'] ?? $contact['deviceCallsign'] ?? 'ATAK',
            'reported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
    if (isset($j['chat']) && is_array($j['chat'])) {
        $chat    = $j['chat'];
        $contact = is_array($j['contact'] ?? null) ? $j['contact'] : [];
        return [
            'uid'         => $srcNode,
            'kind'        => 'chat',
            'lat'         => isset($p['lat']) ? (float) $p['lat'] : 0.0,
            'lng'         => isset($p['lng']) ? (float) $p['lng'] : 0.0,
            'callsign'    => $contact['callsign'] ?? 'ATAK',
            'remarks'     => (string) ($chat['message'] ?? ''),
            'reported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
    return null;
}

// Find which mesh_channels.name to attribute this bridge's traffic to
// for the ATAK policy lookup. Returns 'unknown' if no ATAK-enabled
// channel is assigned to the bridge yet (route falls back to safe
// defaults: 'new_incident' marker action, etc.).
function _atak_channel_ref_for_bridge(string $prefix, int $bridgeId): string {
    try {
        $v = db_fetch_value(
            "SELECT c.name
               FROM `{$prefix}mesh_bridge_channels` bc
               JOIN `{$prefix}mesh_channels` c ON c.id = bc.channel_id
              WHERE bc.bridge_id = ?
                AND c.atak_enabled = 1
                AND c.archived_at IS NULL
              ORDER BY bc.slot ASC
              LIMIT 1",
            [$bridgeId]
        );
        return is_string($v) && $v !== '' ? $v : 'unknown';
    } catch (Exception $e) {
        return 'unknown';
    }
}

// ── poll_outbox: bridge asks "what should I send next?" ─────────────
if ($action === 'poll_outbox' && $method === 'GET') {
    $bridge = bridge_auth();
    if (!$bridge) json_error('Bridge auth required', 401);
    $bridgeId = (int) $bridge['bridge_id'];

    try {
        // Pick the oldest queued item targeted at this bridge or any.
        $row = db_fetch_one(
            "SELECT id, target_protocol, kind, payload_json
               FROM `{$prefix}mesh_outbox`
              WHERE status = 'queued'
                AND (target_bridge_id IS NULL OR target_bridge_id = ?)
              ORDER BY queued_at ASC LIMIT 1",
            [$bridgeId]
        );
        if (!$row) {
            json_response(['work' => null]);
        }
        $oid = (int) $row['id'];
        // Claim it (best-effort, race-tolerant — if 0 rows affected we lost
        // the race and return no work; bridge polls again).
        $upd = db_query(
            "UPDATE `{$prefix}mesh_outbox`
                SET status = 'claimed', claimed_at = NOW(), claimed_by_bridge_id = ?
              WHERE id = ? AND status = 'queued'",
            [$bridgeId, $oid]
        );
        $affected = $upd ? $upd->rowCount() : 0;
        if ($affected !== 1) {
            json_response(['work' => null]);
        }
        json_response([
            'work' => [
                'id'              => $oid,
                'target_protocol' => $row['target_protocol'],
                'kind'            => $row['kind'],
                'payload'         => json_decode($row['payload_json'], true),
            ],
        ]);
    } catch (Exception $e) {
        json_error('poll failed: ' . $e->getMessage(), 500);
    }
}

// ── ack_outbox: bridge reports completion of an outbox item ─────────
if ($action === 'ack_outbox' && $method === 'POST') {
    $bridge = bridge_auth();
    if (!$bridge) json_error('Bridge auth required', 401);
    $oid    = (int) ($input['id'] ?? 0);
    $ok     = !empty($input['ok']);
    $result = $input['result'] ?? null;
    $err    = isset($input['error']) ? substr((string) $input['error'], 0, 255) : null;
    if ($oid <= 0) json_error('id required');

    // Phase C: surface an end-to-end ACK round-trip (MeshCore) when the
    // bridge reports one in result.ack_ms. Meshtastic want-ack results have
    // no round-trip ms; ack_ms stays NULL there. Stored on its own column so
    // the operator's reply can show "delivered in N ms". Probe once — the
    // column is added by sql/run_mesh_replies.php and may be absent on an
    // install that hasn't migrated yet.
    $ackMs = null;
    if (is_array($result) && isset($result['ack_ms']) && is_numeric($result['ack_ms'])) {
        $ackMs = max(0, (int) $result['ack_ms']);
    }
    static $_ackHasAckMs = null;
    if ($_ackHasAckMs === null) {
        try {
            $_ackHasAckMs = (bool) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ? AND COLUMN_NAME = 'ack_ms'",
                [$prefix . 'mesh_outbox']
            );
        } catch (Exception $e) { $_ackHasAckMs = false; }
    }

    try {
        if ($_ackHasAckMs) {
            db_query(
                "UPDATE `{$prefix}mesh_outbox`
                    SET status = ?, completed_at = NOW(),
                        result_json = ?, error = ?, ack_ms = ?
                  WHERE id = ? AND claimed_by_bridge_id = ?",
                [$ok ? 'sent' : 'failed',
                 $result === null ? null : json_encode($result),
                 $err,
                 $ackMs,
                 $oid,
                 (int) $bridge['bridge_id']]
            );
        } else {
            db_query(
                "UPDATE `{$prefix}mesh_outbox`
                    SET status = ?, completed_at = NOW(),
                        result_json = ?, error = ?
                  WHERE id = ? AND claimed_by_bridge_id = ?",
                [$ok ? 'sent' : 'failed',
                 $result === null ? null : json_encode($result),
                 $err,
                 $oid,
                 (int) $bridge['bridge_id']]
            );
        }
        json_response(['ok' => true]);
    } catch (Exception $e) {
        json_error('ack failed: ' . $e->getMessage(), 500);
    }
}

// ── node_info: bridge tells us about a node it saw (long/short name etc) ──
// Phase 39A. Body: { node_id: "!abcd1234", protocol, short_name, long_name,
//                    hw_model, role, lat, lng, alt_m }
if ($action === 'node_info' && $method === 'POST') {
    $bridge = bridge_auth();
    if (!$bridge) json_error('Bridge auth required', 401);
    $bridgeId = (int) $bridge['bridge_id'];

    $nodes = isset($input['nodes']) && is_array($input['nodes']) ? $input['nodes'] : [$input];
    $stored = 0;
    foreach ($nodes as $n) {
        $nodeId = isset($n['node_id']) ? substr((string) $n['node_id'], 0, 32) : null;
        if (!$nodeId) continue;
        // Phase 42b: enriched MeshCore fields. All optional + null-safe via COALESCE
        // so a legacy Meshtastic bridge that doesn't send them isn't disturbed.
        // Probe once per request whether the enriched columns exist; older installs
        // that haven't run the Phase 42 migration still ingest the base shape.
        static $_meshEnriched = null;
        if ($_meshEnriched === null) {
            try {
                $_meshEnriched = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND COLUMN_NAME = 'public_key'",
                    [$prefix . 'mesh_nodes']
                );
            } catch (Exception $e) { $_meshEnriched = false; }
        }
        try {
            if ($_meshEnriched) {
                db_query(
                    "INSERT INTO `{$prefix}mesh_nodes`
                        (node_id, protocol, bridge_id, short_name, long_name, hw_model, role,
                         last_lat, last_lng, last_alt_m,
                         last_snr, last_rssi, last_hops,
                         public_key, firmware_ver, manuf_name, adv_type,
                         radio_freq, radio_bw, radio_sf, radio_cr,
                         tx_power, max_tx_power, adv_lat, adv_lon, is_self,
                         self_info_at, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                             IF(? IS NOT NULL, NOW(3), NULL), NOW(3))
                     ON DUPLICATE KEY UPDATE
                        protocol      = COALESCE(VALUES(protocol),      protocol),
                        bridge_id     = VALUES(bridge_id),
                        short_name    = COALESCE(VALUES(short_name),    short_name),
                        long_name     = COALESCE(VALUES(long_name),     long_name),
                        hw_model      = COALESCE(VALUES(hw_model),      hw_model),
                        role          = COALESCE(VALUES(role),          role),
                        last_lat      = COALESCE(VALUES(last_lat),      last_lat),
                        last_lng      = COALESCE(VALUES(last_lng),      last_lng),
                        last_alt_m    = COALESCE(VALUES(last_alt_m),    last_alt_m),
                        last_snr      = COALESCE(VALUES(last_snr),      last_snr),
                        last_rssi     = COALESCE(VALUES(last_rssi),     last_rssi),
                        last_hops     = COALESCE(VALUES(last_hops),     last_hops),
                        public_key    = COALESCE(VALUES(public_key),    public_key),
                        firmware_ver  = COALESCE(VALUES(firmware_ver),  firmware_ver),
                        manuf_name    = COALESCE(VALUES(manuf_name),    manuf_name),
                        adv_type      = COALESCE(VALUES(adv_type),      adv_type),
                        radio_freq    = COALESCE(VALUES(radio_freq),    radio_freq),
                        radio_bw      = COALESCE(VALUES(radio_bw),      radio_bw),
                        radio_sf      = COALESCE(VALUES(radio_sf),      radio_sf),
                        radio_cr      = COALESCE(VALUES(radio_cr),      radio_cr),
                        tx_power      = COALESCE(VALUES(tx_power),      tx_power),
                        max_tx_power  = COALESCE(VALUES(max_tx_power),  max_tx_power),
                        adv_lat       = COALESCE(VALUES(adv_lat),       adv_lat),
                        adv_lon       = COALESCE(VALUES(adv_lon),       adv_lon),
                        is_self       = GREATEST(is_self, VALUES(is_self)),
                        self_info_at  = IF(VALUES(self_info_at) IS NOT NULL, VALUES(self_info_at), self_info_at),
                        last_seen_at  = NOW(3)",
                    [
                        $nodeId,
                        isset($n['protocol']) ? substr((string) $n['protocol'], 0, 16) : null,
                        $bridgeId,
                        _mesh_sanitize_name($n['short_name'] ?? null, 32),
                        _mesh_sanitize_name($n['long_name']  ?? null, 128),
                        _mesh_sanitize_name($n['hw_model']   ?? null, 64),
                        _mesh_sanitize_name($n['role']       ?? null, 32),
                        isset($n['lat']) ? (float) $n['lat'] : null,
                        isset($n['lng']) ? (float) $n['lng'] : null,
                        isset($n['alt_m']) ? (int) $n['alt_m'] : null,
                        // Signal quality (null-safe; COALESCE preserves prior on absence):
                        isset($n['snr'])  ? (float) $n['snr']  : null,
                        isset($n['rssi']) ? (int)   $n['rssi'] : null,
                        isset($n['hops']) ? (int)   $n['hops'] : null,
                        // MeshCore-specific:
                        isset($n['public_key']) ? substr((string) $n['public_key'], 0, 128) : null,
                        isset($n['firmware_ver']) ? substr((string) $n['firmware_ver'], 0, 64) : null,
                        isset($n['manuf_name']) ? substr((string) $n['manuf_name'], 0, 64) : null,
                        isset($n['adv_type']) ? max(0, min(255, (int) $n['adv_type'])) : null,
                        isset($n['radio_freq']) ? (float) $n['radio_freq'] : null,
                        isset($n['radio_bw']) ? (float) $n['radio_bw'] : null,
                        isset($n['radio_sf']) ? max(0, min(255, (int) $n['radio_sf'])) : null,
                        isset($n['radio_cr']) ? max(0, min(255, (int) $n['radio_cr'])) : null,
                        isset($n['tx_power']) ? (int) $n['tx_power'] : null,
                        isset($n['max_tx_power']) ? (int) $n['max_tx_power'] : null,
                        isset($n['adv_lat']) ? (float) $n['adv_lat'] : null,
                        isset($n['adv_lon']) ? (float) $n['adv_lon'] : null,
                        !empty($n['is_self']) ? 1 : 0,
                        // self_info_at trigger column: NULL when bridge is just relaying a hearsay
                        // advert, non-null when bridge is reporting on its own attached radio.
                        !empty($n['is_self']) || !empty($n['from_self_info']) ? 1 : null,
                    ]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}mesh_nodes`
                        (node_id, protocol, bridge_id, short_name, long_name, hw_model, role,
                         last_lat, last_lng, last_alt_m,
                         last_snr, last_rssi, last_hops, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
                     ON DUPLICATE KEY UPDATE
                        protocol    = COALESCE(VALUES(protocol), protocol),
                        bridge_id   = VALUES(bridge_id),
                        short_name  = COALESCE(VALUES(short_name), short_name),
                        long_name   = COALESCE(VALUES(long_name), long_name),
                        hw_model    = COALESCE(VALUES(hw_model), hw_model),
                        role        = COALESCE(VALUES(role), role),
                        last_lat    = COALESCE(VALUES(last_lat), last_lat),
                        last_lng    = COALESCE(VALUES(last_lng), last_lng),
                        last_alt_m  = COALESCE(VALUES(last_alt_m), last_alt_m),
                        last_snr    = COALESCE(VALUES(last_snr), last_snr),
                        last_rssi   = COALESCE(VALUES(last_rssi), last_rssi),
                        last_hops   = COALESCE(VALUES(last_hops), last_hops),
                        last_seen_at = NOW(3)",
                    [
                        $nodeId,
                        isset($n['protocol']) ? substr((string) $n['protocol'], 0, 16) : null,
                        $bridgeId,
                        _mesh_sanitize_name($n['short_name'] ?? null, 32),
                        _mesh_sanitize_name($n['long_name']  ?? null, 128),
                        _mesh_sanitize_name($n['hw_model']   ?? null, 64),
                        _mesh_sanitize_name($n['role']       ?? null, 32),
                        isset($n['lat']) ? (float) $n['lat'] : null,
                        isset($n['lng']) ? (float) $n['lng'] : null,
                        isset($n['alt_m']) ? (int) $n['alt_m'] : null,
                        isset($n['snr'])  ? (float) $n['snr']  : null,
                        isset($n['rssi']) ? (int)   $n['rssi'] : null,
                        isset($n['hops']) ? (int)   $n['hops'] : null,
                    ]
                );
            }
            $stored++;
        } catch (Exception $e) { /* skip and continue */ }
    }
    json_response(['stored' => $stored]);
}

// ═══════════════════════════════════════════════════════════════════
//  ADMIN-FACING ACTIONS
// ═══════════════════════════════════════════════════════════════════

if ($action === 'bridges' && $method === 'GET') {
    admin_auth();
    try {
        $rows = db_fetch_all(
            "SELECT b.id, b.label, b.host_hint, b.notes, b.last_seen_at, b.last_packet_at,
                    b.created_at, b.revoked_at,
                    (SELECT COUNT(*) FROM `{$prefix}bridge_tokens` t WHERE t.bridge_id = b.id AND t.revoked_at IS NULL) AS active_tokens,
                    (SELECT COUNT(*) FROM `{$prefix}mesh_packet_log` p WHERE p.bridge_id = b.id) AS packet_count
               FROM `{$prefix}mesh_bridges` b
              ORDER BY b.id"
        );
        json_response(['bridges' => $rows]);
    } catch (Exception $e) {
        json_error('bridges query failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'mint_token' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $bid   = (int) ($input['bridge_id'] ?? 0);
    $label = trim((string) ($input['label'] ?? ''));
    $host  = trim((string) ($input['host_hint'] ?? ''));
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        if ($bid <= 0) {
            if ($label === '') json_error('label or bridge_id required');
            db_query(
                "INSERT INTO `{$prefix}mesh_bridges` (label, host_hint, created_by)
                 VALUES (?, ?, ?)",
                [$label, $host ?: null, $userId ?: null]
            );
            $bid = (int) db_insert_id();
        }
        // 32 bytes of entropy → 64 hex chars
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        db_query(
            "INSERT INTO `{$prefix}bridge_tokens` (bridge_id, token_hash, created_by)
             VALUES (?, ?, ?)",
            [$bid, $hash, $userId ?: null]
        );
        json_response([
            'bridge_id' => $bid,
            'token'     => $token,  // shown ONCE — admin must copy
            'note'      => 'Save this token — it will not be shown again.',
        ]);
    } catch (Exception $e) {
        json_error('mint failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'revoke' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $tid = (int) ($input['token_id'] ?? 0);
    $bid = (int) ($input['bridge_id'] ?? 0);
    try {
        if ($tid > 0) {
            db_query("UPDATE `{$prefix}bridge_tokens` SET revoked_at = NOW() WHERE id = ?", [$tid]);
        } elseif ($bid > 0) {
            db_query("UPDATE `{$prefix}bridge_tokens` SET revoked_at = NOW() WHERE bridge_id = ?", [$bid]);
            db_query("UPDATE `{$prefix}mesh_bridges`  SET revoked_at = NOW() WHERE id = ?", [$bid]);
        } else {
            json_error('token_id or bridge_id required');
        }
        json_response(['ok' => true]);
    } catch (Exception $e) {
        json_error('revoke failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'feed' && $method === 'GET') {
    admin_auth();
    $limit  = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $since  = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
    $bridge = isset($_GET['bridge_id']) ? (int) $_GET['bridge_id'] : 0;

    $where  = "1=1";
    $params = [];
    if ($since > 0) { $where .= " AND p.id > ?"; $params[] = $since; }
    if ($bridge > 0) { $where .= " AND p.bridge_id = ?"; $params[] = $bridge; }
    $params[] = $limit;

    try {
        // Phase 39A: include node display_name + JOINed short/long for tooltips.
        $rows = db_fetch_all(
            "SELECT p.id, p.received_at, p.bridge_id, b.label AS bridge_label,
                    p.protocol, p.packet_id, p.src_node, p.display_name,
                    n.short_name, n.long_name,
                    p.dst_node, p.port_kind,
                    p.snr, p.rssi, p.hops, p.payload_text, p.lat, p.lng
               FROM `{$prefix}mesh_packet_log` p
               LEFT JOIN `{$prefix}mesh_bridges` b ON b.id = p.bridge_id
               LEFT JOIN `{$prefix}mesh_nodes` n ON n.node_id = p.src_node
              WHERE $where
              ORDER BY p.id DESC LIMIT ?",
            $params
        );
        json_response(['packets' => $rows]);
    } catch (Exception $e) {
        json_error('feed query failed: ' . $e->getMessage(), 500);
    }
}

// Phase 39A: detail for a single packet (modal lookup).
if ($action === 'packet_detail' && $method === 'GET') {
    admin_auth();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        $row = db_fetch_one(
            "SELECT p.*, b.label AS bridge_label,
                    n.short_name, n.long_name, n.hw_model, n.role,
                    n.last_lat, n.last_lng, n.last_alt_m, n.last_seen_at AS node_last_seen
               FROM `{$prefix}mesh_packet_log` p
               LEFT JOIN `{$prefix}mesh_bridges` b ON b.id = p.bridge_id
               LEFT JOIN `{$prefix}mesh_nodes` n ON n.node_id = p.src_node
              WHERE p.id = ? LIMIT 1",
            [$id]
        );
        if (!$row) json_error('not found', 404);
        if (!empty($row['payload_json'])) $row['payload_json'] = json_decode($row['payload_json'], true);
        json_response(['packet' => $row]);
    } catch (Exception $e) {
        json_error('detail failed: ' . $e->getMessage(), 500);
    }
}

// Phase 39A: list known nodes for picker + map views.
if ($action === 'nodes' && $method === 'GET') {
    admin_auth();
    $hours = max(1, min(720, (int) ($_GET['hours'] ?? 168)));   // last 7 days default
    try {
        $rows = db_fetch_all(
            "SELECT n.node_id, n.protocol, n.bridge_id, b.label AS bridge_label,
                    n.short_name, n.long_name, n.hw_model, n.role,
                    n.last_lat, n.last_lng, n.last_alt_m,
                    n.last_snr, n.last_rssi, n.last_hops,
                    n.last_seen_at, n.first_seen_at
               FROM `{$prefix}mesh_nodes` n
               LEFT JOIN `{$prefix}mesh_bridges` b ON b.id = n.bridge_id
              WHERE n.last_seen_at > NOW() - INTERVAL ? HOUR
              ORDER BY n.last_seen_at DESC",
            [$hours]
        );
        json_response(['nodes' => $rows, 'hours' => $hours]);
    } catch (Exception $e) {
        json_error('nodes query failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'send' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $text = trim((string) ($input['text'] ?? ''));
    if ($text === '') json_error('text required');

    $bridge = isset($input['target_bridge_id']) ? (int) $input['target_bridge_id'] : 0;

    // ── Phase B (messaging-send-gaps-2026-06): one uniform send shape ──
    //
    // A normalized send targets {transport, channel_slot, OR a direct
    // address}. The direct address can be supplied three ways, in
    // precedence order:
    //
    //   1. to_node      — a raw transport address (Meshtastic "!hex" node
    //                     id, or a MeshCore pubkey-prefix hex). Direct.
    //   2. unit_id      — a responder (unit) id → resolved to that unit's
    //                     transport address via the comm-identifier
    //                     resolver (inc/comm_resolve.php).
    //   3. member_id    — a member/person id → resolved the same way.
    //
    // When none of those is set, it's a CHANNEL broadcast on channel_slot.
    //
    // Backward-compat: the legacy params still work unchanged —
    // `target_protocol` is honoured as an alias for `protocol`, and a
    // raw `to_node` behaves exactly as before. The resolver only runs
    // when unit_id/member_id is supplied and no raw to_node is given.

    // Accept `protocol` (new) or `target_protocol` (legacy alias).
    $protocol = (string) ($input['protocol'] ?? $input['target_protocol'] ?? 'any');
    if (!in_array($protocol, ['meshtastic','meshcore','any'], true)) $protocol = 'any';

    // Channel slot (0..7); default 0 = primary/public.
    $slot = isset($input['channel_slot']) ? max(0, min(7, (int) $input['channel_slot'])) : 0;

    // 1. Raw direct address wins if present.
    $toNode = isset($input['to_node']) ? substr(trim((string) $input['to_node']), 0, 32) : '';

    // 2/3. Resolve unit_id / member_id → address when no raw to_node given.
    $resolvedFrom = null;   // for the response: how the address was derived
    if ($toNode === '') {
        $unitId   = isset($input['unit_id'])   ? (int) $input['unit_id']   : 0;
        $memberId = isset($input['member_id']) ? (int) $input['member_id'] : 0;
        if ($unitId > 0 || $memberId > 0) {
            // A resolve target needs a concrete transport — 'any' can't
            // pick which identifier (Meshtastic node vs MeshCore prefix).
            if ($protocol !== 'meshtastic' && $protocol !== 'meshcore') {
                json_error('protocol must be meshtastic or meshcore when resolving a unit_id/member_id to an address');
            }
            require_once __DIR__ . '/../inc/comm_resolve.php';
            if ($unitId > 0) {
                $addr = resolve_unit_address($unitId, $protocol, 'unit');
                $resolvedFrom = 'unit_id';
                if ($addr === null) {
                    json_error('No ' . $protocol . ' address on file for unit #' . $unitId .
                               ' (no comm identifier, or unit not linked to a member)', 422);
                }
            } else {
                $addr = resolve_unit_address($memberId, $protocol, 'member');
                $resolvedFrom = 'member_id';
                if ($addr === null) {
                    json_error('No ' . $protocol . ' address on file for member #' . $memberId, 422);
                }
            }
            $toNode = substr(trim((string) $addr), 0, 32);
        }
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    // Thread a manual DM under the same key a reply would use, so a
    // back-and-forth with one node groups together. Channel broadcasts get
    // a channel thread key on the chosen slot.
    $threadKey = _mesh_thread_key($protocol, $toNode !== '' ? $toNode : null, $slot, $toNode !== '');
    try {
        $oid = _mesh_queue_send([
            'text'             => $text,
            'protocol'         => $protocol,
            'channel_slot'     => $slot,
            'to_node'          => $toNode,
            'target_bridge_id' => $bridge,
            'queued_by'        => $userId,
            'thread_key'       => $threadKey,
        ]);
        json_response([
            'queued'        => true,
            'id'            => $oid,
            'direct'        => ($toNode !== ''),
            'to_node'       => $toNode !== '' ? $toNode : null,
            'channel_slot'  => $slot,
            'resolved_from' => $resolvedFrom,
            'thread_key'    => $threadKey,
        ]);
    } catch (Exception $e) {
        json_error('queue failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'set_config' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $bridge = (int) ($input['target_bridge_id'] ?? 0);
    $kind   = (string) ($input['kind'] ?? '');
    $payload = $input['payload'] ?? [];
    $allowed = ['set_owner','set_channel','set_region','reboot'];
    if (!in_array($kind, $allowed, true)) json_error('kind must be one of: ' . implode(', ', $allowed));
    if ($bridge <= 0) json_error('target_bridge_id required');

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    try {
        db_query(
            "INSERT INTO `{$prefix}mesh_outbox`
                (queued_by, target_bridge_id, target_protocol, kind, payload_json)
             VALUES (?, ?, 'any', ?, ?)",
            [$userId ?: null, $bridge, $kind, json_encode($payload)]
        );
        json_response(['queued' => true, 'id' => (int) db_insert_id()]);
    } catch (Exception $e) {
        json_error('queue failed: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════
//  Phase 39B — Mesh channels (admin)
// ═════════════════════════════════════════════════════════════════

if ($action === 'channels' && $method === 'GET') {
    admin_auth();
    try {
        $rows = db_fetch_all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM `{$prefix}mesh_bridge_channels` bc WHERE bc.channel_id = c.id) AS bridge_count
               FROM `{$prefix}mesh_channels` c
              WHERE c.archived_at IS NULL
              ORDER BY c.is_primary DESC, c.name ASC"
        );
        json_response(['channels' => $rows]);
    } catch (Exception $e) { json_error('channels failed: ' . $e->getMessage(), 500); }
}

if ($action === 'channel_create' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $name = substr(trim((string) ($input['name'] ?? '')), 0, 32);
    if ($name === '') json_error('name required');
    $region = substr((string) ($input['region'] ?? 'US'), 0, 16) ?: 'US';
    $modem  = substr((string) ($input['modem_preset'] ?? 'LONG_FAST'), 0, 32) ?: 'LONG_FAST';
    // PSK: either supplied (base64) or auto-generate 32 bytes (AES-256)
    $pskB64 = trim((string) ($input['psk_b64'] ?? ''));
    if ($pskB64 === '') {
        $pskB64 = base64_encode(random_bytes(32));
    } else {
        $raw = base64_decode($pskB64, true);
        if ($raw === false || !in_array(strlen($raw), [0, 1, 16, 32], true)) {
            json_error('psk_b64 must be base64 of 1/16/32 bytes');
        }
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    try {
        db_query(
            "INSERT INTO `{$prefix}mesh_channels`
                (name, psk_b64, region, modem_preset, downlink_enabled, uplink_enabled, is_primary, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)",
            [$name, $pskB64, $region, $modem,
             empty($input['downlink_enabled']) ? 0 : 1,
             empty($input['uplink_enabled'])   ? 0 : 1,
             isset($input['notes']) ? substr((string) $input['notes'], 0, 1024) : null,
             $userId ?: null]
        );
        json_response(['id' => (int) db_insert_id(), 'name' => $name, 'psk_b64' => $pskB64]);
    } catch (Exception $e) {
        json_error('create failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'channel_update' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    $sets = [];
    $params = [];
    foreach (['name','region','modem_preset','notes'] as $k) {
        if (array_key_exists($k, $input)) {
            $sets[] = "`{$k}` = ?";
            $params[] = substr((string) $input[$k], 0, 1024);
        }
    }
    if (isset($input['downlink_enabled'])) { $sets[] = "downlink_enabled = ?"; $params[] = $input['downlink_enabled'] ? 1 : 0; }
    if (isset($input['uplink_enabled']))   { $sets[] = "uplink_enabled = ?";   $params[] = $input['uplink_enabled']   ? 1 : 0; }
    if (!$sets) json_error('nothing to update');
    $params[] = $id;
    try {
        db_query("UPDATE `{$prefix}mesh_channels` SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('update failed: ' . $e->getMessage(), 500); }
}

if ($action === 'channel_archive' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        db_query("UPDATE `{$prefix}mesh_channels` SET archived_at = NOW() WHERE id = ?", [$id]);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('archive failed: ' . $e->getMessage(), 500); }
}

if ($action === 'channel_assign' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $bridgeId  = (int) ($input['bridge_id'] ?? 0);
    $channelId = (int) ($input['channel_id'] ?? 0);
    $slot      = max(0, min(7, (int) ($input['slot'] ?? 0)));
    if ($bridgeId <= 0 || $channelId <= 0) json_error('bridge_id + channel_id required');

    try {
        // Upsert mesh_bridge_channels (replace whatever is in that slot)
        db_query(
            "INSERT INTO `{$prefix}mesh_bridge_channels` (bridge_id, channel_id, slot)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE channel_id = VALUES(channel_id), assigned_at = NOW()",
            [$bridgeId, $channelId, $slot]
        );

        // Queue a set_channel outbox item so the bridge actually applies it
        $ch = db_fetch_one("SELECT * FROM `{$prefix}mesh_channels` WHERE id = ?", [$channelId]);
        if ($ch) {
            $payload = [
                'slot'             => $slot,
                'name'             => $ch['name'],
                'psk_b64'          => $ch['psk_b64'],
                'region'           => $ch['region'],
                'modem_preset'     => $ch['modem_preset'],
                'downlink_enabled' => (bool) $ch['downlink_enabled'],
                'uplink_enabled'   => (bool) $ch['uplink_enabled'],
                'is_primary'       => (bool) $ch['is_primary'],
            ];
            db_query(
                "INSERT INTO `{$prefix}mesh_outbox`
                    (queued_by, target_bridge_id, target_protocol, kind, payload_json)
                 VALUES (?, ?, 'meshtastic', 'set_channel', ?)",
                [(int) ($_SESSION['user_id'] ?? 0) ?: null, $bridgeId, json_encode($payload)]
            );
        }
        json_response(['ok' => true]);
    } catch (Exception $e) {
        json_error('assign failed: ' . $e->getMessage(), 500);
    }
}

// Returns a Meshtastic-style channel-share URL. The URL contains a
// protobuf ChannelSet but we use a lightweight wrapper format that any
// recipient with the same install can decode. Format:
//   tickets://channel?n=<name>&k=<psk_b64url>&r=<region>&m=<modem>
// (We deliberately avoid emitting the real meshtastic.org URL format
// because that requires protobuf encoding the ChannelSet message.)
if ($action === 'channel_share_url' && $method === 'GET') {
    admin_auth();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        $c = db_fetch_one("SELECT * FROM `{$prefix}mesh_channels` WHERE id = ?", [$id]);
        if (!$c) json_error('not found', 404);
        $q = http_build_query([
            'n' => $c['name'],
            'k' => rtrim(strtr($c['psk_b64'], '+/', '-_'), '='),
            'r' => $c['region'] ?? 'US',
            'm' => $c['modem_preset'] ?? 'LONG_FAST',
        ]);
        json_response([
            'url'      => 'tickets://channel?' . $q,
            'qr_text'  => 'tickets://channel?' . $q,
            'psk_b64'  => $c['psk_b64'],
            'channel'  => $c,
        ]);
    } catch (Exception $e) { json_error('share failed: ' . $e->getMessage(), 500); }
}

if ($action === 'coverage' && $method === 'GET') {
    admin_auth();
    $hours = max(1, min(72, (int) ($_GET['hours'] ?? 24)));
    try {
        // For each src_node, list which bridges heard it + earliest/latest receive + worst/best signal.
        $rows = db_fetch_all(
            "SELECT p.src_node,
                    p.bridge_id,
                    b.label AS bridge_label,
                    COUNT(*) AS pkt_count,
                    MIN(p.received_at) AS first_heard,
                    MAX(p.received_at) AS last_heard,
                    AVG(p.snr)  AS avg_snr,
                    AVG(p.rssi) AS avg_rssi
               FROM `{$prefix}mesh_packet_log` p
               LEFT JOIN `{$prefix}mesh_bridges` b ON b.id = p.bridge_id
              WHERE p.src_node IS NOT NULL
                AND p.received_at > NOW() - INTERVAL ? HOUR
              GROUP BY p.src_node, p.bridge_id
              ORDER BY p.src_node ASC, p.bridge_id ASC",
            [$hours]
        );
        // Latency: per packet_id with the same src_node, max(received_at)-min(received_at)
        // across bridges. Bench it.
        $latency = db_fetch_all(
            "SELECT src_node, packet_id,
                    COUNT(DISTINCT bridge_id) AS heard_by,
                    TIMESTAMPDIFF(MICROSECOND, MIN(received_at), MAX(received_at)) / 1000 AS spread_ms
               FROM `{$prefix}mesh_packet_log`
              WHERE packet_id IS NOT NULL
                AND received_at > NOW() - INTERVAL ? HOUR
              GROUP BY src_node, packet_id
             HAVING heard_by > 1
              ORDER BY received_at DESC LIMIT 100",
            [$hours]
        );
        json_response(['coverage' => $rows, 'latency_samples' => $latency, 'hours' => $hours]);
    } catch (Exception $e) {
        json_error('coverage failed: ' . $e->getMessage(), 500);
    }
}

// ── Phase B: send_targets — units/people that resolve to a mesh address ──
// Feeds the Send tab's "To unit / person" picker. Returns, per transport,
// the members (with a meshtastic/meshcore comm identifier) and the units
// (responders linked to such a member). The actual address is NOT returned
// here — the resolver runs server-side at send time so the address can't be
// scraped from this list. We only return whether each transport resolves.
if ($action === 'send_targets' && $method === 'GET') {
    admin_auth();
    require_once __DIR__ . '/../inc/comm_resolve.php';
    try {
        // Members holding at least one meshtastic or meshcore identifier.
        $memberRows = db_fetch_all(
            "SELECT DISTINCT mci.member_id,
                    m.first_name, m.last_name, m.callsign
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
               JOIN `{$prefix}member` m ON m.id = mci.member_id
              WHERE cm.code IN ('meshtastic','meshcore')
                AND cm.enabled = 1
              ORDER BY m.last_name, m.first_name"
        );
        $members = [];
        foreach ($memberRows as $r) {
            $mid = (int) $r['member_id'];
            $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
            if ($name === '') $name = 'Member #' . $mid;
            if (!empty($r['callsign'])) $name .= ' (' . $r['callsign'] . ')';
            $members[] = [
                'member_id'  => $mid,
                'name'       => $name,
                'meshtastic' => comm_resolve_member_address($mid, 'meshtastic') !== null,
                'meshcore'   => comm_resolve_member_address($mid, 'meshcore') !== null,
            ];
        }

        // Units (responders) that map to such a member via either linkage.
        // Personal-unit linkage:
        $units = [];
        $seen  = [];
        try {
            $personal = db_fetch_all(
                "SELECT r.id, r.name, r.personal_for_member_id AS member_id
                   FROM `{$prefix}responder` r
                  WHERE r.personal_for_member_id IS NOT NULL"
            );
        } catch (Exception $e) { $personal = []; }
        // Active assignment linkage:
        try {
            $assigned = db_fetch_all(
                "SELECT DISTINCT r.id, r.name, upa.member_id
                   FROM `{$prefix}unit_personnel_assignments` upa
                   JOIN `{$prefix}responder` r ON r.id = upa.responder_id
                  WHERE upa.status = 'active' AND upa.released_at IS NULL"
            );
        } catch (Exception $e) { $assigned = []; }

        foreach (array_merge($personal, $assigned) as $r) {
            $rid = (int) $r['id'];
            if (isset($seen[$rid])) continue;
            $mt = comm_resolve_unit_address_by_responder($rid, 'meshtastic') !== null;
            $mc = comm_resolve_unit_address_by_responder($rid, 'meshcore') !== null;
            if (!$mt && !$mc) continue;   // unit has no resolvable mesh address
            $seen[$rid] = true;
            $units[] = [
                'unit_id'    => $rid,
                'name'       => (string) ($r['name'] ?? ('Unit #' . $rid)),
                'meshtastic' => $mt,
                'meshcore'   => $mc,
            ];
        }

        json_response(['members' => $members, 'units' => $units]);
    } catch (Exception $e) {
        json_error('send_targets failed: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════
//  Phase C — inbound text inbox + reply + reply status
// ═════════════════════════════════════════════════════════════════

// ── inbox: reply-able inbound TEXT messages (operator surface) ──
//
// The Live Feed (?action=feed) shows EVERY packet (position, nodeinfo,
// telemetry, text) for admin observation. The inbox is the narrower,
// action-oriented view: inbound TEXT only, newest first, each row carrying
// what a reply needs — transport, origin (src_node + channel_idx), a
// friendly name when the node maps to a known mesh_nodes row, whether a
// reply would be a DM or a channel reply, and the latest reply's status if
// one has been queued. Does NOT replace the admin packet feed.
if ($action === 'inbox' && $method === 'GET') {
    admin_auth();
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 60)));
    $since = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

    // channel_idx is added by sql/run_mesh_replies.php; select it only if present.
    $hasChannelIdx = false;
    try {
        $hasChannelIdx = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'channel_idx'",
            [$prefix . 'mesh_packet_log']
        );
    } catch (Exception $e) { $hasChannelIdx = false; }
    $chanCol = $hasChannelIdx ? 'p.channel_idx' : 'NULL AS channel_idx';

    $where  = "p.port_kind IN ('TEXT','TEXT_MESSAGE_APP')
               AND p.payload_text IS NOT NULL AND p.payload_text <> ''";
    $params = [];
    if ($since > 0) { $where .= " AND p.id > ?"; $params[] = $since; }
    $params[] = $limit;

    try {
        $rows = db_fetch_all(
            "SELECT p.id, p.received_at, p.bridge_id, b.label AS bridge_label,
                    p.protocol, p.src_node, p.display_name,
                    n.short_name, n.long_name,
                    p.payload_text, $chanCol
               FROM `{$prefix}mesh_packet_log` p
               LEFT JOIN `{$prefix}mesh_bridges` b ON b.id = p.bridge_id
               LEFT JOIN `{$prefix}mesh_nodes` n ON n.node_id = p.src_node
              WHERE $where
              ORDER BY p.id DESC LIMIT ?",
            $params
        );

        // Attach the most-recent reply status per inbound row (if the
        // mesh_outbox reply columns exist).
        $hasReplyCols = false;
        try {
            $hasReplyCols = (bool) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ? AND COLUMN_NAME = 'in_reply_to_packet_id'",
                [$prefix . 'mesh_outbox']
            );
        } catch (Exception $e) { $hasReplyCols = false; }

        $replyByPacket = [];
        if ($hasReplyCols && $rows) {
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            try {
                // Latest reply row per inbound packet.
                $reps = db_fetch_all(
                    "SELECT o.in_reply_to_packet_id AS pkt, o.id, o.status, o.ack_ms,
                            o.error, o.queued_at, o.completed_at
                       FROM `{$prefix}mesh_outbox` o
                       JOIN (
                            SELECT in_reply_to_packet_id, MAX(id) AS max_id
                              FROM `{$prefix}mesh_outbox`
                             WHERE in_reply_to_packet_id IN ($in)
                             GROUP BY in_reply_to_packet_id
                       ) latest ON latest.max_id = o.id",
                    $ids
                );
                foreach ($reps as $rp) $replyByPacket[(int) $rp['pkt']] = $rp;
            } catch (Exception $e) { /* leave replies empty */ }
        }

        foreach ($rows as &$r) {
            $r['friendly']    = $r['long_name'] ?: ($r['short_name'] ?: $r['display_name']);
            $r['is_direct']   = ($r['channel_idx'] === null);
            $r['reply_kind']  = $r['is_direct'] ? 'direct' : 'channel';
            $pid = (int) $r['id'];
            $r['last_reply']  = $replyByPacket[$pid] ?? null;
        }
        unset($r);

        json_response(['messages' => $rows]);
    } catch (Exception $e) {
        json_error('inbox query failed: ' . $e->getMessage(), 500);
    }
}

// ── reply: queue an outbound reply threaded to an inbound packet ──
//
// Given an inbound mesh_packet_log id + reply text, derive the address from
// the inbound row's origin and queue via the shared send helper:
//   - DM     (channel_idx IS NULL): to_node = src_node
//   - channel(channel_idx present): channel_slot = channel_idx, no to_node
// The reply inherits the inbound packet's protocol. Records the thread key
// + in_reply_to_packet_id so status threads back to the row.
if ($action === 'reply' && $method === 'POST') {
    admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $packetId = (int) ($input['packet_id'] ?? 0);
    $text     = trim((string) ($input['text'] ?? ''));
    if ($packetId <= 0) json_error('packet_id required');
    if ($text === '') json_error('text required');

    $hasChannelIdx = false;
    try {
        $hasChannelIdx = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'channel_idx'",
            [$prefix . 'mesh_packet_log']
        );
    } catch (Exception $e) { $hasChannelIdx = false; }
    $chanCol = $hasChannelIdx ? 'channel_idx' : 'NULL AS channel_idx';

    try {
        $pkt = db_fetch_one(
            "SELECT id, protocol, src_node, port_kind, $chanCol
               FROM `{$prefix}mesh_packet_log` WHERE id = ? LIMIT 1",
            [$packetId]
        );
    } catch (Exception $e) {
        json_error('packet lookup failed: ' . $e->getMessage(), 500);
    }
    if (!$pkt) json_error('inbound packet not found', 404);

    $proto      = in_array($pkt['protocol'], ['meshtastic', 'meshcore'], true)
                    ? $pkt['protocol'] : 'any';
    $channelIdx = $pkt['channel_idx'] !== null ? (int) $pkt['channel_idx'] : null;
    $isDirect   = ($channelIdx === null);
    $srcNode    = (string) ($pkt['src_node'] ?? '');

    // A direct reply needs an origin address to answer.
    if ($isDirect && $srcNode === '') {
        json_error('inbound packet has no src_node — cannot address a direct reply', 422);
    }

    $toNode = $isDirect ? $srcNode : '';
    $slot   = $isDirect ? 0 : $channelIdx;
    $bridge = isset($input['target_bridge_id']) ? (int) $input['target_bridge_id'] : 0;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $threadKey = _mesh_thread_key((string) $proto, $srcNode, $channelIdx, $isDirect);

    try {
        $oid = _mesh_queue_send([
            'text'                  => $text,
            'protocol'              => $proto,
            'channel_slot'          => $slot,
            'to_node'               => $toNode,
            'target_bridge_id'      => $bridge,
            'queued_by'             => $userId,
            'in_reply_to_packet_id' => $packetId,
            'thread_key'            => $threadKey,
        ]);
        json_response([
            'queued'        => true,
            'id'            => $oid,
            'reply_kind'    => $isDirect ? 'direct' : 'channel',
            'to_node'       => $isDirect ? $toNode : null,
            'channel_slot'  => $isDirect ? null : $slot,
            'protocol'      => $proto,
            'in_reply_to'   => $packetId,
            'thread_key'    => $threadKey,
            'status'        => 'queued',
        ]);
    } catch (Exception $e) {
        json_error('reply queue failed: ' . $e->getMessage(), 500);
    }
}

// ── reply_status: delivery status of one (or several) outbox sends ──
//
// Poll target for the operator UI: returns the current status of a queued
// reply/send by mesh_outbox id, including ack_ms (MeshCore end-to-end ACK
// round-trip) when the bridge reported one. Accepts a single id or a
// comma-separated list (ids=).
if ($action === 'reply_status' && $method === 'GET') {
    admin_auth();
    $idsRaw = (string) ($_GET['ids'] ?? $_GET['id'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), static fn($v) => $v > 0));
    if (!$ids) json_error('id or ids required');
    $ids = array_slice($ids, 0, 100);

    $hasAckMs = false;
    try {
        $hasAckMs = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'ack_ms'",
            [$prefix . 'mesh_outbox']
        );
    } catch (Exception $e) { $hasAckMs = false; }
    $ackCol = $hasAckMs ? 'ack_ms' : 'NULL AS ack_ms';

    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rows = db_fetch_all(
            "SELECT id, status, error, queued_at, claimed_at, completed_at, $ackCol
               FROM `{$prefix}mesh_outbox`
              WHERE id IN ($in)",
            $ids
        );
        json_response(['statuses' => $rows]);
    } catch (Exception $e) {
        json_error('status query failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action: ' . $action);
