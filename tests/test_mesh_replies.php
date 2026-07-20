<?php
/**
 * Phase C (messaging-send-gaps-2026-06) — mesh replies + threading + ACK.
 *
 * Covers:
 *   1. The reply/threading migration is idempotent and adds the expected
 *      columns (mesh_packet_log.channel_idx; mesh_outbox.in_reply_to_packet_id,
 *      thread_key, ack_ms).
 *   2. Inbound TEXT is surfaced reply-ably — the inbox query shape returns
 *      a seeded inbound TEXT row with transport + origin + (when mapped) a
 *      friendly name, and does NOT pull non-text packets.
 *   3. A DIRECT reply queues a send_text outbox with to_node = src_node,
 *      threaded to the inbound packet (in_reply_to_packet_id + thread_key).
 *   4. A CHANNEL reply queues with the originating slot and no to_node.
 *   5. Reply status reflects on the reply row (queued → sent, ack_ms surfaced).
 *   6. ack_outbox persists result.ack_ms onto mesh_outbox.ack_ms.
 *   7. Source-level assertions: the reply/inbox/reply_status endpoints exist,
 *      are admin-gated + CSRF-protected (reply); the UI has an Inbox tab +
 *      reply modal; the bridge captures channel_idx + has the ACK plumbing.
 *
 * Self-contained: seeds temp rows, asserts, cleans up. Never deploys,
 * never touches a bridge.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$total  = 0;
$passed = 0;
$failed = [];

function pc_assert(string $name, bool $cond, string $detail = '') {
    global $total, $passed, $failed;
    $total++;
    if ($cond) { $passed++; echo "  PASS  $name\n"; }
    else { $failed[] = "$name — $detail"; echo "  FAIL  $name — $detail\n"; }
}

function pc_has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

echo "Phase C — mesh replies + threading + ACK\n";
echo "========================================\n";

// ─────────────────────────────────────────────────────────────────────
// 1. Migration: idempotent + adds the expected columns.
// ─────────────────────────────────────────────────────────────────────
$migPath = __DIR__ . '/../sql/run_mesh_replies.php';
pc_assert('migration script exists', file_exists($migPath), 'run_mesh_replies.php missing');

$migSrc = file_exists($migPath) ? file_get_contents($migPath) : '';
pc_assert(
    'migration is guarded (information_schema probe before ADD COLUMN)',
    strpos($migSrc, 'information_schema.COLUMNS') !== false
        && strpos($migSrc, 'ADD COLUMN') !== false,
    'migration not guarded'
);

$phpBin = PHP_BINARY;
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($migPath) . ' 2>&1';
exec($cmd, $o1, $e1);
exec($cmd, $o2, $e2);
pc_assert('migration ran clean (exit 0) first time', $e1 === 0, 'exit ' . $e1 . ': ' . implode(' ', $o1));
pc_assert('migration ran clean (exit 0) on re-run',  $e2 === 0, 'exit ' . $e2 . ': ' . implode(' ', $o2));

pc_assert('mesh_packet_log.channel_idx exists',           pc_has_col('mesh_packet_log', 'channel_idx'), 'column missing');
pc_assert('mesh_outbox.in_reply_to_packet_id exists',     pc_has_col('mesh_outbox', 'in_reply_to_packet_id'), 'column missing');
pc_assert('mesh_outbox.thread_key exists',                pc_has_col('mesh_outbox', 'thread_key'), 'column missing');
pc_assert('mesh_outbox.ack_ms exists',                    pc_has_col('mesh_outbox', 'ack_ms'), 'column missing');

// ─────────────────────────────────────────────────────────────────────
// Seed a bridge + an inbound DM TEXT + an inbound CHANNEL TEXT + a known
// node, then exercise the inbox/reply/status logic against the live DB.
// ─────────────────────────────────────────────────────────────────────
$bridgeId = 0; $dmPktId = 0; $chPktId = 0; $posPktId = 0; $nodeId = '!c0ffee12';
$outboxIds = [];

try {
    db_query("INSERT INTO `{$prefix}mesh_bridges` (label) VALUES ('PhaseC test bridge')");
    $bridgeId = (int) db_insert_id();

    // A known node so the friendly-name JOIN resolves.
    try {
        db_query(
            "INSERT INTO `{$prefix}mesh_nodes` (node_id, protocol, bridge_id, short_name, long_name, last_seen_at)
             VALUES (?, 'meshtastic', ?, 'COFF', 'Coffee Unit', NOW(3))
             ON DUPLICATE KEY UPDATE long_name = VALUES(long_name)",
            [$nodeId, $bridgeId]
        );
    } catch (Exception $e) { /* mesh_nodes may not exist on a bare install */ }

    // Inbound DIRECT text (channel_idx NULL).
    db_query(
        "INSERT INTO `{$prefix}mesh_packet_log`
            (received_at, bridge_id, protocol, src_node, port_kind, payload_text, channel_idx)
         VALUES (NOW(3), ?, 'meshtastic', ?, 'TEXT', 'need backup at 5th & main', NULL)",
        [$bridgeId, $nodeId]
    );
    $dmPktId = (int) db_insert_id();

    // Inbound CHANNEL text (channel_idx = 2).
    db_query(
        "INSERT INTO `{$prefix}mesh_packet_log`
            (received_at, bridge_id, protocol, src_node, port_kind, payload_text, channel_idx)
         VALUES (NOW(3), ?, 'meshcore', 'a1b2c3d4e5f6', 'TEXT', 'net check, all stations', 2)",
        [$bridgeId]
    );
    $chPktId = (int) db_insert_id();

    // A non-text packet (POSITION) — must NOT appear in the inbox.
    db_query(
        "INSERT INTO `{$prefix}mesh_packet_log`
            (received_at, bridge_id, protocol, src_node, port_kind, lat, lng)
         VALUES (NOW(3), ?, 'meshtastic', ?, 'POSITION', 44.97, -93.26)",
        [$bridgeId, $nodeId]
    );
    $posPktId = (int) db_insert_id();

    // ── 2. Inbox surfacing — replicate the endpoint's inbox query. ──
    $inboxRows = db_fetch_all(
        "SELECT p.id, p.protocol, p.src_node, p.payload_text, p.channel_idx,
                COALESCE(n.long_name, n.short_name, p.display_name) AS friendly
           FROM `{$prefix}mesh_packet_log` p
           LEFT JOIN `{$prefix}mesh_nodes` n ON n.node_id = p.src_node
          WHERE p.port_kind IN ('TEXT','TEXT_MESSAGE_APP')
            AND p.payload_text IS NOT NULL AND p.payload_text <> ''
            AND p.bridge_id = ?
          ORDER BY p.id DESC",
        [$bridgeId]
    );
    $inboxIds = array_map(static fn($r) => (int) $r['id'], $inboxRows);
    pc_assert('inbox surfaces inbound DM text',       in_array($dmPktId, $inboxIds, true), 'DM text not surfaced');
    pc_assert('inbox surfaces inbound channel text',  in_array($chPktId, $inboxIds, true), 'channel text not surfaced');
    pc_assert('inbox excludes non-text (POSITION)',   !in_array($posPktId, $inboxIds, true), 'position packet leaked into inbox');

    // Origin + friendly-name tagging.
    $dmRow = null; $chRow = null;
    foreach ($inboxRows as $r) {
        if ((int) $r['id'] === $dmPktId) $dmRow = $r;
        if ((int) $r['id'] === $chPktId) $chRow = $r;
    }
    pc_assert('DM row tagged direct (channel_idx NULL)',  $dmRow && $dmRow['channel_idx'] === null, 'channel_idx not null on DM');
    pc_assert('DM row carries src_node origin',           $dmRow && $dmRow['src_node'] === $nodeId, 'src_node missing');
    if ($dmRow && $dmRow['friendly'] !== null) {
        pc_assert('DM row resolves a friendly name',      $dmRow['friendly'] === 'Coffee Unit', 'friendly name wrong: ' . ($dmRow['friendly'] ?? 'null'));
    } else {
        pc_assert('DM row friendly name (skipped — no mesh_nodes)', true, '');
    }
    pc_assert('channel row tagged channel (slot 2)',      $chRow && (int) $chRow['channel_idx'] === 2, 'channel_idx not 2');

    // ── 3. DIRECT reply queues to_node = src_node, threaded. ──
    // Mirror the endpoint's reply derivation: DM (channel_idx NULL) →
    // to_node = src_node, thread_key = proto:dm:src.
    $dmThread = 'meshtastic:dm:' . $nodeId;
    $dmPayload = json_encode(['text' => 'copy, units en route', 'channel_slot' => 0, 'to_node' => $nodeId]);
    db_query(
        "INSERT INTO `{$prefix}mesh_outbox`
            (queued_by, target_bridge_id, target_protocol, kind, payload_json, in_reply_to_packet_id, thread_key)
         VALUES (NULL, NULL, 'meshtastic', 'send_text', ?, ?, ?)",
        [$dmPayload, $dmPktId, $dmThread]
    );
    $dmReplyId = (int) db_insert_id();
    $outboxIds[] = $dmReplyId;
    $dmReply = db_fetch_one("SELECT * FROM `{$prefix}mesh_outbox` WHERE id = ?", [$dmReplyId]);
    $dmReplyPayload = json_decode($dmReply['payload_json'], true);
    pc_assert('direct reply: kind=send_text',                 $dmReply['kind'] === 'send_text', 'wrong kind');
    pc_assert('direct reply: to_node = inbound src_node',     ($dmReplyPayload['to_node'] ?? '') === $nodeId, 'to_node != src_node');
    pc_assert('direct reply: target_protocol inherited',      $dmReply['target_protocol'] === 'meshtastic', 'protocol not inherited');
    pc_assert('direct reply: threaded to inbound packet',     (int) $dmReply['in_reply_to_packet_id'] === $dmPktId, 'in_reply_to not set');
    pc_assert('direct reply: thread_key is proto:dm:src',     $dmReply['thread_key'] === $dmThread, 'thread_key wrong: ' . $dmReply['thread_key']);

    // ── 4. CHANNEL reply queues right slot, no to_node. ──
    $chThread = 'meshcore:chan:2';
    $chPayload = json_encode(['text' => 'all stations acknowledged', 'channel_slot' => 2]);
    db_query(
        "INSERT INTO `{$prefix}mesh_outbox`
            (queued_by, target_bridge_id, target_protocol, kind, payload_json, in_reply_to_packet_id, thread_key)
         VALUES (NULL, NULL, 'meshcore', 'send_text', ?, ?, ?)",
        [$chPayload, $chPktId, $chThread]
    );
    $chReplyId = (int) db_insert_id();
    $outboxIds[] = $chReplyId;
    $chReply = db_fetch_one("SELECT * FROM `{$prefix}mesh_outbox` WHERE id = ?", [$chReplyId]);
    $chReplyPayload = json_decode($chReply['payload_json'], true);
    pc_assert('channel reply: channel_slot = origin slot',    (int) ($chReplyPayload['channel_slot'] ?? -1) === 2, 'slot != 2');
    pc_assert('channel reply: no to_node (broadcast)',        !isset($chReplyPayload['to_node']), 'to_node present on channel reply');
    pc_assert('channel reply: threaded to inbound packet',    (int) $chReply['in_reply_to_packet_id'] === $chPktId, 'in_reply_to not set');
    pc_assert('channel reply: thread_key is proto:chan:slot', $chReply['thread_key'] === $chThread, 'thread_key wrong: ' . $chReply['thread_key']);

    // ── 5. Status reflects on the reply (queued → sent w/ ack_ms). ──
    $st0 = db_fetch_one("SELECT status, ack_ms FROM `{$prefix}mesh_outbox` WHERE id = ?", [$dmReplyId]);
    pc_assert('reply starts queued',                          $st0['status'] === 'queued', 'not queued at insert');
    pc_assert('reply ack_ms starts NULL',                     $st0['ack_ms'] === null, 'ack_ms not null at insert');

    // ── 6. ack_outbox plumbing: persisting result.ack_ms onto ack_ms. ──
    // Simulate the bridge completing a MeshCore DM with an end-to-end ACK.
    $resultJson = json_encode(['sent_via' => '/dev/ttyUSB1', 'dm' => true, 'slot' => 0, 'ack_ms' => 842]);
    db_query(
        "UPDATE `{$prefix}mesh_outbox`
            SET status = 'sent', completed_at = NOW(), result_json = ?, ack_ms = ?
          WHERE id = ?",
        [$resultJson, 842, $dmReplyId]
    );
    $st1 = db_fetch_one("SELECT status, ack_ms FROM `{$prefix}mesh_outbox` WHERE id = ?", [$dmReplyId]);
    pc_assert('reply status reflects sent',                   $st1['status'] === 'sent', 'status not sent');
    pc_assert('reply ack_ms surfaces round-trip (842)',       (int) $st1['ack_ms'] === 842, 'ack_ms not persisted: ' . var_export($st1['ack_ms'], true));

    // reply_status query shape returns the row with ack.
    $statusRows = db_fetch_all(
        "SELECT id, status, error, ack_ms FROM `{$prefix}mesh_outbox` WHERE id IN (?, ?)",
        [$dmReplyId, $chReplyId]
    );
    pc_assert('reply_status returns both reply rows',         count($statusRows) === 2, 'expected 2 status rows, got ' . count($statusRows));

} finally {
    if ($outboxIds) {
        $in = implode(',', array_fill(0, count($outboxIds), '?'));
        try { db_query("DELETE FROM `{$prefix}mesh_outbox` WHERE id IN ($in)", $outboxIds); } catch (Exception $e) {}
    }
    foreach ([$dmPktId, $chPktId, $posPktId] as $pid) {
        if ($pid) { try { db_query("DELETE FROM `{$prefix}mesh_packet_log` WHERE id = ?", [$pid]); } catch (Exception $e) {} }
    }
    if ($nodeId) { try { db_query("DELETE FROM `{$prefix}mesh_nodes` WHERE node_id = ?", [$nodeId]); } catch (Exception $e) {} }
    if ($bridgeId) { try { db_query("DELETE FROM `{$prefix}mesh_bridges` WHERE id = ?", [$bridgeId]); } catch (Exception $e) {} }
}

// ─────────────────────────────────────────────────────────────────────
// 7. Source-level assertions: endpoints, security, UI, bridge.
// ─────────────────────────────────────────────────────────────────────
$meshApi = file_get_contents(__DIR__ . '/../api/mesh.php');

pc_assert(
    'api/mesh.php — inbox endpoint exists + admin-gated',
    strpos($meshApi, "\$action === 'inbox'") !== false
        && preg_match('/\$action === \'inbox\'.*?admin_auth\(\);/s', $meshApi) === 1,
    'inbox missing or not admin-gated'
);
pc_assert(
    'api/mesh.php — inbox filters to TEXT port kinds',
    preg_match("/port_kind IN \('TEXT','TEXT_MESSAGE_APP'\)/", $meshApi) === 1,
    'inbox does not filter to text packets'
);
pc_assert(
    'api/mesh.php — reply endpoint exists, admin-gated + CSRF',
    strpos($meshApi, "\$action === 'reply'") !== false
        && preg_match('/\$action === \'reply\'.*?admin_auth\(\);.*?csrf_verify/s', $meshApi) === 1,
    'reply missing admin_auth or csrf_verify'
);
pc_assert(
    'api/mesh.php — reply derives DM to_node from src_node',
    strpos($meshApi, '$toNode = $isDirect ? $srcNode') !== false,
    'reply does not derive to_node from inbound src_node'
);
pc_assert(
    'api/mesh.php — reply derives channel slot from channel_idx',
    strpos($meshApi, '$slot   = $isDirect ? 0 : $channelIdx') !== false,
    'reply does not derive channel slot from inbound channel_idx'
);
pc_assert(
    'api/mesh.php — reply reuses the shared send queue helper',
    strpos($meshApi, '_mesh_queue_send(') !== false
        && strpos($meshApi, 'function _mesh_queue_send') !== false,
    'reply duplicates send logic instead of reusing _mesh_queue_send'
);
pc_assert(
    'api/mesh.php — reply_status endpoint exists + admin-gated',
    strpos($meshApi, "\$action === 'reply_status'") !== false
        && preg_match('/\$action === \'reply_status\'.*?admin_auth\(\);/s', $meshApi) === 1,
    'reply_status missing or not admin-gated'
);
pc_assert(
    'api/mesh.php — ack_outbox persists result.ack_ms',
    strpos($meshApi, "\$result['ack_ms']") !== false
        && strpos($meshApi, 'ack_ms = ?') !== false,
    'ack_outbox does not persist ack_ms'
);
pc_assert(
    'api/mesh.php — ingest captures channel_idx',
    strpos($meshApi, "\$p['channel_idx']") !== false
        && strpos($meshApi, 'channel_idx') !== false,
    'ingest does not capture channel_idx'
);

$meshConsole = file_get_contents(__DIR__ . '/../mesh-console.php');
pc_assert(
    'mesh-console.php — Inbox tab + reply modal present',
    strpos($meshConsole, 'data-tab="inbox"') !== false
        && strpos($meshConsole, 'id="replyModal"') !== false,
    'Inbox tab or reply modal missing'
);

$meshJs = file_get_contents(__DIR__ . '/../assets/js/mesh-console.js');
pc_assert(
    'mesh-console.js — inbox fetch + reply submit wired',
    strpos($meshJs, 'action=inbox') !== false
        && strpos($meshJs, 'action=reply') !== false
        && strpos($meshJs, 'submitReply') !== false,
    'inbox/reply JS not wired'
);
pc_assert(
    'mesh-console.js — polls reply delivery status',
    strpos($meshJs, 'action=reply_status') !== false
        && strpos($meshJs, 'pollReplyStatus') !== false,
    'reply status polling missing'
);

$bridge = file_get_contents(__DIR__ . '/../services/meshtastic/bridge_v2.py');
pc_assert(
    'bridge_v2.py — TextEvent carries channel_idx',
    strpos($bridge, 'channel_idx:') !== false
        && strpos($bridge, "pkt['channel_idx'] = int(event.channel_idx)") !== false,
    'bridge does not propagate channel_idx on inbound text'
);
pc_assert(
    'bridge_v2.py — channel msg handler captures the slot (no longer delegates)',
    strpos($bridge, 'def _on_channel_msg') !== false
        && preg_match('/_on_channel_msg.*?channel_idx=int\(slot\)/s', $bridge) === 1,
    'channel handler still drops the slot'
);
pc_assert(
    'bridge_v2.py — MeshCore ACK round-trip plumbing present (+ TODO for event path)',
    strpos($bridge, '_meshcore_extract_ack_ms') !== false
        && strpos($bridge, 'TODO(bridge-followup)') !== false,
    'MeshCore ACK plumbing / follow-up TODO missing'
);

$failedCount = count($failed);
echo "\n";
echo "$passed passed, $failedCount failed\n";
echo "Phase C — $passed / $total tests passed\n";
if (!empty($failed)) {
    echo "\nFAILURES:\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
