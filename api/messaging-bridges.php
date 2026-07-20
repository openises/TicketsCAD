<?php
/**
 * Phase 99a-v2 (2026-06-28) — mesh bridge picker data source.
 *
 *   GET /api/messaging-bridges.php?protocol=meshtastic[&for_node=!a1b2c3d4]
 *
 *     protocol: 'meshtastic' | 'meshcore' (others noop empty)
 *     for_node: optional. If supplied, bridges that have HEARD this
 *               node recently float to the top, so the picker can
 *               default to the bridge most likely to reach the target.
 *
 *   Response:
 *     { bridges: [
 *         { id, label, last_seen_at, recently_heard: 0|1, summary },
 *         ...
 *       ] }
 *
 *   summary is the small descriptive string from mesh_bridges.host_hint
 *   (e.g. 'meshbridge-01 (your-host, 10.0.0.10) — 2 nodes: Meshtastic 2.6.4 + MeshCore v1.16.0')
 *
 *   recently_heard = 1 when:
 *     - for_node was supplied AND mesh_packet_log has a row from
 *       (bridge_id, node_id) within the last 1h
 *     OR
 *     - for_node empty AND bridge.last_seen_at within 5 min
 *
 * Eric's UX target: 'when a person selects the protocol, then the
 * target bridge with the default one being mapped to the heard-by
 * default from the database of nodes.'
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!rbac_can('action.send_chat') && !rbac_can('action.send_message')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$protocol = strtolower(trim((string) ($_GET['protocol'] ?? '')));
$forNode  = trim((string) ($_GET['for_node'] ?? ''));
$prefix   = $GLOBALS['db_prefix'] ?? '';

// Only mesh protocols have a bridge concept. Other channels can hit
// this endpoint but get an empty list back (so the UI can decide
// whether to hide the picker entirely).
if (!in_array($protocol, ['meshtastic', 'meshcore'], true)) {
    echo json_encode(['bridges' => []]);
    exit;
}

try {
    // Bridges are protocol-agnostic on the server side (bridge code
    // claims work it can handle). Pull all non-revoked bridges.
    $rows = db_fetch_all(
        "SELECT id, label, host_hint, last_seen_at
           FROM `{$prefix}mesh_bridges`
          WHERE revoked_at IS NULL
          ORDER BY (last_seen_at IS NULL), last_seen_at DESC"
    );

    // If for_node supplied, find which bridges have heard it
    // recently. mesh_packet_log keys packets by source_node + bridge.
    $heardByBridge = [];
    if ($forNode !== '') {
        try {
            $heard = db_fetch_all(
                "SELECT DISTINCT bridge_id
                   FROM `{$prefix}mesh_packet_log`
                  WHERE source_node = ?
                    AND received_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$forNode]
            );
            foreach ($heard as $h) $heardByBridge[(int) $h['bridge_id']] = true;
        } catch (Throwable $e) { /* table absent / column variant — non-fatal */ }
    }

    $now = time();
    $bridges = [];
    foreach ($rows as $r) {
        $bid     = (int) $r['id'];
        $last    = $r['last_seen_at'];
        $lastTs  = $last ? strtotime((string) $last) : 0;
        $age     = $lastTs ? ($now - $lastTs) : null;
        $online  = ($age !== null && $age < 300);   // 5 min
        $recently_heard = isset($heardByBridge[$bid])
            ? 1
            : (($forNode === '' && $online) ? 1 : 0);

        // Human-friendly status pill.
        $status = $online ? 'online' : ($age === null ? 'never' : 'offline');
        $ageStr = '';
        if ($age !== null) {
            if      ($age <   60) $ageStr = (int) $age      . 's ago';
            elseif  ($age < 3600) $ageStr = (int) ($age/60) . 'm ago';
            elseif  ($age <86400) $ageStr = (int) ($age/3600). 'h ago';
            else                  $ageStr = (int) ($age/86400). 'd ago';
        }

        $bridges[] = [
            'id'             => $bid,
            'label'          => (string) $r['label'],
            'summary'        => (string) ($r['host_hint'] ?? ''),
            'last_seen_at'   => $last,
            'last_seen_age'  => $ageStr,
            'status'         => $status,
            'recently_heard' => $recently_heard,
        ];
    }

    // Reorder: recently_heard first (the "default to heard-by" UX),
    // then online, then offline. Stable within bucket.
    usort($bridges, function ($a, $b) {
        if ($a['recently_heard'] !== $b['recently_heard']) {
            return $b['recently_heard'] - $a['recently_heard'];
        }
        $rank = ['online' => 0, 'offline' => 1, 'never' => 2];
        return $rank[$a['status']] - $rank[$b['status']];
    });

    echo json_encode(['bridges' => $bridges]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Bridge lookup failed: ' . $e->getMessage()]);
}
