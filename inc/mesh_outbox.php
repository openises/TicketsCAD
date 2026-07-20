<?php
/**
 * Phase D (messaging-send-gaps-2026-06) — shared mesh_outbox queue helper.
 *
 * The Phase-B/-C send + reply path queues an outbound `mesh_outbox`
 * `send_text` row. That logic lived only in api/mesh.php (which executes
 * at include time, so it can't be required from other code paths). The
 * router now also needs to queue mesh sends when a routing RULE targets a
 * mesh transport, so the queue logic is lifted here — one side-effect-free
 * include any caller (api/mesh.php, inc/router.php) can require.
 *
 * mesh_enqueue_send() builds the identical row api/mesh.php used to build
 * inline, including the optional reply/thread columns (probed once, so a
 * pre-Phase-C install still queues a plain send). It NEVER sends RF — it
 * only writes the queue row a bridge later drains via ?action=poll_outbox.
 */

if (!function_exists('db_query')) {
    require_once __DIR__ . '/db.php';
}

if (!function_exists('mesh_enqueue_send')) {

    /**
     * Queue an outbound mesh send_text row. Returns the inserted outbox id.
     * Throws on DB error (caller handles).
     *
     * $opts:
     *   text                  (required) message body
     *   protocol              meshtastic|meshcore|any
     *   channel_slot          int 0..7
     *   to_node               '' for channel broadcast, else direct address
     *   target_bridge_id      int|0  (0/NULL = any bridge)
     *   queued_by             int|0  (user id)
     *   in_reply_to_packet_id int|null
     *   thread_key            string|null
     */
    function mesh_enqueue_send(array $opts): int {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $text   = substr((string) ($opts['text'] ?? ''), 0, 200);
        $slot   = max(0, min(7, (int) ($opts['channel_slot'] ?? 0)));
        $toNode = substr(trim((string) ($opts['to_node'] ?? '')), 0, 32);
        $proto  = (string) ($opts['protocol'] ?? 'any');
        if (!in_array($proto, ['meshtastic', 'meshcore', 'any'], true)) $proto = 'any';
        $bridge = (int) ($opts['target_bridge_id'] ?? 0);
        $userId = (int) ($opts['queued_by'] ?? 0);
        $replyTo   = isset($opts['in_reply_to_packet_id']) ? (int) $opts['in_reply_to_packet_id'] : 0;
        $threadKey = isset($opts['thread_key']) ? substr((string) $opts['thread_key'], 0, 96) : '';

        $payload = ['text' => $text, 'channel_slot' => $slot];
        if ($toNode !== '') $payload['to_node'] = $toNode;

        static $_hasReplyCols = null;
        if ($_hasReplyCols === null) {
            try {
                $_hasReplyCols = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ? AND COLUMN_NAME = 'in_reply_to_packet_id'",
                    [$prefix . 'mesh_outbox']
                );
            } catch (Exception $e) { $_hasReplyCols = false; }
        }

        if ($_hasReplyCols) {
            db_query(
                "INSERT INTO `{$prefix}mesh_outbox`
                    (queued_by, target_bridge_id, target_protocol, kind, payload_json,
                     in_reply_to_packet_id, thread_key)
                 VALUES (?, ?, ?, 'send_text', ?, ?, ?)",
                [$userId ?: null, $bridge ?: null, $proto, json_encode($payload),
                 $replyTo ?: null, $threadKey !== '' ? $threadKey : null]
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}mesh_outbox`
                    (queued_by, target_bridge_id, target_protocol, kind, payload_json)
                 VALUES (?, ?, ?, 'send_text', ?)",
                [$userId ?: null, $bridge ?: null, $proto, json_encode($payload)]
            );
        }
        return (int) db_insert_id();
    }

    /**
     * Build the conversation thread key for a transport+origin.
     *   direct  → "<proto>:dm:<src_node>"
     *   channel → "<proto>:chan:<slot>"
     */
    function mesh_build_thread_key(string $proto, ?string $srcNode, ?int $channelIdx, bool $isDirect): string {
        $proto = in_array($proto, ['meshtastic', 'meshcore'], true) ? $proto : 'mesh';
        if ($isDirect) {
            return $proto . ':dm:' . substr((string) ($srcNode ?? ''), 0, 64);
        }
        return $proto . ':chan:' . (int) ($channelIdx ?? 0);
    }
}
