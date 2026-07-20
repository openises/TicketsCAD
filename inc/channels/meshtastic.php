<?php
/**
 * Channel: Meshtastic (Phase 99a #11, 2026-06-28).
 *
 * Wraps the existing mesh_outbox queue (inc/mesh_outbox.php +
 * api/mesh.php) so the unified Compose form can send via
 * broker_send('meshtastic', ...) like any other channel.
 *
 * Recipient shape (`message['to']`):
 *   - 'channel:N'  → broadcast on channel slot N (0-7). Default
 *                    when slot omitted: slot 0 ("Primary").
 *   - '!hex'       → Meshtastic node id (8-char hex prefixed with
 *                    '!'), direct-message that node.
 *   - any other    → treated as a node id literal (validation
 *                    happens at bridge level).
 *
 * Returns immediately after queuing — the bridge drains the queue
 * over its own polling cadence (typically 5-10s). 'success' here
 * means "the row landed in mesh_outbox," NOT "the radio TX
 * completed." The bridge's ack_outbox callback updates the row's
 * status after the actual radio send.
 */

require_once __DIR__ . '/../mesh_outbox.php';

broker_register('meshtastic', [
    'name'    => 'Meshtastic',
    'send'    => '_meshtastic_send',
    'receive' => null,   // ingest is bridge-driven via api/mesh.php?action=ingest
    'status'  => '_meshtastic_status',
]);

function _meshtastic_send(array $message): array {
    return _mesh_broker_send_common($message, 'meshtastic');
}

function _meshtastic_status(): string {
    return _mesh_broker_status_common('meshtastic');
}

/**
 * Shared between meshtastic + meshcore channel handlers — both
 * write to the same mesh_outbox queue with different protocol tags.
 */
function _mesh_broker_send_common(array $message, string $proto): array {
    $to   = trim((string) ($message['to'] ?? ''));
    $body = trim((string) ($message['body'] ?? ''));
    if ($body === '') {
        return ['success' => false, 'error' => 'Message body is required'];
    }

    $slot   = 0;
    $toNode = '';
    if (preg_match('/^channel:(\d+)$/', $to, $m)) {
        $slot = max(0, min(7, (int) $m[1]));
    } elseif ($to !== '') {
        // Anything else is a direct node id (Meshtastic '!hex' or
        // MeshCore pubkey-prefix hex). Bridges validate the format.
        $toNode = $to;
    }

    // Phase 99a-v2 (2026-06-28) — honor target_bridge_id when the
    // Compose form's bridge picker selected a specific bridge.
    //
    // Eric beta follow-up: 'If a bridge is not online currently,
    // ensure the message goes out another secondary bridge.' So:
    // if a specific bridge was requested but it's not online,
    // re-route to a sensible alternative — prefer a bridge that
    // has recently heard the target node (for direct sends), else
    // any online bridge, else queue unpinned (any bridge will
    // claim on next connect).
    //
    // The picked-bridge is reported back in the result note so
    // the UI can surface 'sent via bridge X' transparency.
    $requestedBridgeId = (int) ($message['target_bridge_id'] ?? 0);
    $resolved = _mesh_resolve_bridge_for_send($requestedBridgeId, $toNode);

    try {
        $rowId = mesh_enqueue_send([
            'text'             => $body,
            'protocol'         => $proto,
            'channel_slot'     => $slot,
            'to_node'          => $toNode,
            'target_bridge_id' => $resolved['bridge_id'],
            'queued_by'        => (int) ($message['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
        ]);
        return [
            'success'             => true,
            'queued_id'           => $rowId,
            'target_bridge_id'    => $resolved['bridge_id'],
            'target_bridge_label' => $resolved['label'],
            'note'                => $resolved['note'],
            // Truthy when the system picked a different bridge than the user
            // requested (so the UI can show a soft warning).
            'reroute_warning'     => $resolved['reroute_warning'],
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Queue failed: ' . $e->getMessage()];
    }
}

/**
 * Decide which bridge should claim this send.
 *
 *   $requestedBridgeId  — what the user picked (0 = "Any")
 *   $toNode             — destination node id (or '' for channel broadcast)
 *
 * Returns:
 *   ['bridge_id'        => int    — 0 means "queue unpinned"
 *    'label'            => string — human-friendly name
 *    'note'             => string — UI-displayable status line
 *    'reroute_warning'  => bool   — true if user-requested bridge was overridden]
 *
 * Online threshold: bridge.last_seen_at within the last 5 minutes.
 * "Recently heard": mesh_packet_log row from (bridge, source_node)
 *   within the last 1 hour.
 */
function _mesh_resolve_bridge_for_send(int $requestedBridgeId, string $toNode): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Helper: is this bridge online?
    $isOnline = function (int $bid) use ($prefix): bool {
        try {
            return (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}mesh_bridges`
                  WHERE id = ? AND revoked_at IS NULL
                    AND last_seen_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                [$bid]
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    };

    $bridgeLabel = function (int $bid) use ($prefix): string {
        try {
            $l = (string) db_fetch_value(
                "SELECT label FROM `{$prefix}mesh_bridges` WHERE id = ? LIMIT 1",
                [$bid]
            );
            return $l !== '' ? $l : ('bridge #' . $bid);
        } catch (Throwable $e) {
            return 'bridge #' . $bid;
        }
    };

    // Case 1: user picked a specific bridge AND it's online → use it.
    if ($requestedBridgeId > 0 && $isOnline($requestedBridgeId)) {
        return [
            'bridge_id'       => $requestedBridgeId,
            'label'           => $bridgeLabel($requestedBridgeId),
            'note'            => 'Queued for ' . $bridgeLabel($requestedBridgeId) . ' (online)',
            'reroute_warning' => false,
        ];
    }

    // Case 2: user picked a specific bridge but it's offline → find an alternate.
    if ($requestedBridgeId > 0) {
        $alt = _mesh_pick_alternate_bridge($toNode, $requestedBridgeId);
        if ($alt['bridge_id'] > 0) {
            return [
                'bridge_id'       => $alt['bridge_id'],
                'label'           => $alt['label'],
                'note'            => 'Requested bridge ' . $bridgeLabel($requestedBridgeId) .
                                     ' is offline; re-routed via ' . $alt['label'] . $alt['reason_suffix'],
                'reroute_warning' => true,
            ];
        }
        // No alternate online — queue unpinned so next bridge to come online claims.
        return [
            'bridge_id'       => 0,
            'label'           => '(any)',
            'note'            => 'Requested bridge ' . $bridgeLabel($requestedBridgeId) .
                                 ' is offline AND no other bridge is online — queued unpinned (next bridge online will claim)',
            'reroute_warning' => true,
        ];
    }

    // Case 3: user picked "Any" → prefer heard-by (when direct), else any online.
    $alt = _mesh_pick_alternate_bridge($toNode, 0);
    if ($alt['bridge_id'] > 0) {
        return [
            'bridge_id'       => $alt['bridge_id'],
            'label'           => $alt['label'],
            'note'            => 'Queued for ' . $alt['label'] . $alt['reason_suffix'],
            'reroute_warning' => false,
        ];
    }
    // No bridges online → queue unpinned.
    return [
        'bridge_id'       => 0,
        'label'           => '(any)',
        'note'            => 'No bridges currently online — queued unpinned (next bridge online will claim)',
        'reroute_warning' => false,
    ];
}

/**
 * Pick the best alternate bridge for a send.
 *   - Prefer bridges that recently heard the target node (last 1h)
 *   - Else pick the most-recently-seen online bridge
 *
 * $excludeBridgeId — skip this bridge (the one the user picked that's offline)
 *
 * Returns ['bridge_id', 'label', 'reason_suffix'] — bridge_id=0 when no match.
 */
function _mesh_pick_alternate_bridge(string $toNode, int $excludeBridgeId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Try heard-by-target first (only meaningful for direct sends).
    if ($toNode !== '') {
        try {
            $heard = db_fetch_all(
                "SELECT b.id, b.label
                   FROM `{$prefix}mesh_bridges` b
                   JOIN `{$prefix}mesh_packet_log` p ON p.bridge_id = b.id
                  WHERE b.revoked_at IS NULL
                    AND b.last_seen_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    AND p.src_node = ?
                    AND p.received_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    AND b.id <> ?
                  GROUP BY b.id, b.label
                  ORDER BY MAX(p.received_at) DESC
                  LIMIT 1",
                [$toNode, $excludeBridgeId]
            );
            if (!empty($heard)) {
                return [
                    'bridge_id'     => (int) $heard[0]['id'],
                    'label'         => (string) $heard[0]['label'],
                    'reason_suffix' => ' (recently heard target node)',
                ];
            }
        } catch (Throwable $e) { /* table may not have mesh_packet_log on early installs */ }
    }

    // Fallback: most-recently-seen online bridge that isn't excluded.
    try {
        $online = db_fetch_all(
            "SELECT id, label
               FROM `{$prefix}mesh_bridges`
              WHERE revoked_at IS NULL
                AND last_seen_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                AND id <> ?
              ORDER BY last_seen_at DESC
              LIMIT 1",
            [$excludeBridgeId]
        );
        if (!empty($online)) {
            return [
                'bridge_id'     => (int) $online[0]['id'],
                'label'         => (string) $online[0]['label'],
                'reason_suffix' => ' (most recently online)',
            ];
        }
    } catch (Throwable $e) { /* non-fatal */ }

    return ['bridge_id' => 0, 'label' => '', 'reason_suffix' => ''];
}

/**
 * Channel status — counts bridges currently online for the given
 * protocol. 'active' / 'degraded' / 'offline' / 'unconfigured'.
 */
function _mesh_broker_status_common(string $proto): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        // Bridges are protocol-agnostic on the server side — same
        // bridge can carry Meshtastic + MeshCore. Status = whether
        // ANY non-revoked bridge has heartbeated in the last 5 min.
        $online = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}mesh_bridges`
              WHERE `revoked_at` IS NULL
                AND `last_seen_at` > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $total = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}mesh_bridges`
              WHERE `revoked_at` IS NULL"
        );
        if ($total === 0)  return 'unconfigured';
        if ($online === 0) return 'offline';
        if ($online < $total) return 'degraded';
        return 'active';
    } catch (Throwable $e) {
        return 'unknown';
    }
}
