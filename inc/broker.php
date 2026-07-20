<?php
/**
 * NewUI v4.0 — Message Broker
 *
 * Pluggable event bus that routes messages to registered channel handlers.
 * Each channel is a self-contained module that handles its own transport
 * (local DB, SMTP, REST API, serial, Bluetooth, etc.).
 *
 * Architecture:
 *   broker_send($channel, $message)  →  ChannelHandler::send()
 *   broker_receive($channel)         →  ChannelHandler::receive()
 *   broker_broadcast($message)       →  All enabled channels
 *
 * Channel handlers are loaded from inc/channels/*.php
 * Each must implement: send(), receive(), getStatus(), getName()
 *
 * Messages are logged to the `messages` table for history/audit.
 *
 * USAGE:
 *   require_once __DIR__ . '/broker.php';
 *
 *   // Send to specific channel
 *   broker_send('local_chat', [
 *       'to'   => 'all',
 *       'body' => 'Structure fire reported at 123 Main St',
 *       'type' => 'dispatch'
 *   ]);
 *
 *   // Broadcast to all enabled channels
 *   broker_broadcast([
 *       'body' => 'All units: mandatory recall',
 *       'type' => 'alert',
 *       'priority' => 'high'
 *   ]);
 *
 *   // Get channel status
 *   $statuses = broker_channel_statuses();
 */

// ── Channel Registry ──────────────────────────────────────────

$_broker_channels = [];

/**
 * Register a channel handler.
 *
 * @param string $code     Unique channel code (e.g. 'local_chat', 'smtp', 'twilio')
 * @param array  $handler  Associative array with callable keys: send, receive, status, name
 */
function broker_register($code, array $handler) {
    global $_broker_channels;
    $_broker_channels[$code] = $handler;
}

/**
 * Get all registered channels with their status.
 *
 * @return array  [{ code, name, enabled, status, config }, ...]
 */
function broker_channel_statuses() {
    global $_broker_channels;
    $result = [];

    // Get enabled channels from DB
    $enabled = _broker_get_enabled_channels();

    foreach ($_broker_channels as $code => $handler) {
        $name = is_callable($handler['name'] ?? null) ? call_user_func($handler['name']) : ($handler['name'] ?? $code);
        $status = 'unknown';
        if (is_callable($handler['status'] ?? null)) {
            try { $status = call_user_func($handler['status']); } catch (Exception $e) { $status = 'error'; }
        }
        $result[] = [
            'code'    => $code,
            'name'    => $name,
            'enabled' => in_array($code, $enabled),
            'status'  => $status
        ];
    }
    return $result;
}

// ── Send / Receive / Broadcast ────────────────────────────────

/**
 * Send a message via a specific channel.
 *
 * @param string $channel  Channel code
 * @param array  $message  Message data (to, body, type, priority, subject, attachments, etc.)
 * @return array  ['success' => bool, 'message_id' => int|null, 'error' => string|null]
 */
function broker_send($channel, array $message) {
    global $_broker_channels;

    if (!isset($_broker_channels[$channel])) {
        return ['success' => false, 'error' => "Unknown channel: $channel"];
    }

    $handler = $_broker_channels[$channel];
    if (!is_callable($handler['send'] ?? null)) {
        return ['success' => false, 'error' => "Channel '$channel' does not support sending"];
    }

    // Log outbound message
    $msgId = _broker_log_message($channel, 'outbound', $message);

    try {
        $result = call_user_func($handler['send'], $message);
        $success = $result['success'] ?? false;

        // Update log with delivery status
        _broker_update_status($msgId, $success ? 'delivered' : 'failed', $result['error'] ?? null);

        // Evaluate routing rules for outbound messages (skip if this is already a routed forward)
        if ($success && empty($message['_is_routed_forward']) && function_exists('router_evaluate')) {
            router_evaluate($channel, 'outbound', $message, $msgId);
        }

        // Phase 99v-4 follow-on (2026-06-30) — pass through optional
        // per-channel metrics so the test-send endpoint can show
        // "N delivered / M resolved" without a separate query. Adapters
        // that don't populate these keys leave them absent (rather than
        // null), so callers can isset()-check them.
        $passthrough = [
            'success'    => $success,
            'message_id' => $msgId,
            'error'      => $result['error'] ?? null,
            'channel'    => $channel
        ];
        foreach (['delivered', 'failed', 'gone', 'queued', 'recipients_resolved', 'subscriptions_matched'] as $k) {
            if (isset($result[$k])) $passthrough[$k] = $result[$k];
        }
        return $passthrough;
    } catch (Exception $e) {
        _broker_update_status($msgId, 'failed', $e->getMessage());
        return ['success' => false, 'message_id' => $msgId, 'error' => $e->getMessage()];
    }
}

/**
 * Receive pending messages from a channel.
 *
 * @param string $channel  Channel code
 * @param int    $limit    Max messages to retrieve
 * @return array  Messages array
 */
function broker_receive($channel, $limit = 50) {
    global $_broker_channels;

    if (!isset($_broker_channels[$channel])) return [];

    $handler = $_broker_channels[$channel];
    if (!is_callable($handler['receive'] ?? null)) return [];

    try {
        $messages = call_user_func($handler['receive'], $limit);

        // Log inbound messages and evaluate routing rules
        foreach ($messages as $msg) {
            $inboundId = _broker_log_message($channel, 'inbound', $msg);
            if (function_exists('router_evaluate')) {
                router_evaluate($channel, 'inbound', $msg, $inboundId);
            }
        }

        return $messages;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Broadcast a message to all enabled channels.
 *
 * @param array $message  Message data
 * @return array  Results per channel
 */
function broker_broadcast(array $message) {
    global $_broker_channels;
    $enabled = _broker_get_enabled_channels();
    $results = [];

    foreach ($enabled as $code) {
        if (isset($_broker_channels[$code])) {
            $results[$code] = broker_send($code, $message);
        }
    }

    return $results;
}

// ── Message Log ───────────────────────────────────────────────

function _broker_log_message($channel, $direction, array $message) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}messages` (`channel`, `direction`, `msg_type`, `sender`, `recipient`, `subject`, `body`, `priority`, `status`, `payload`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())",
            [
                $channel,
                $direction,
                $message['type'] ?? 'general',
                $message['from'] ?? ($_SESSION['user'] ?? 'system'),
                $message['to'] ?? 'all',
                $message['subject'] ?? '',
                $message['body'] ?? '',
                $message['priority'] ?? 'normal',
                json_encode($message)
            ]
        );
        return db_insert_id();
    } catch (Exception $e) {
        return null;
    }
}

function _broker_update_status($msgId, $status, $error = null) {
    if (!$msgId) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}messages` SET `status` = ?, `error` = ?, `delivered_at` = IF(? = 'delivered', NOW(), NULL) WHERE id = ?",
            [$status, $error, $status, $msgId]
        );
    } catch (Exception $e) {
        // Ignore — logging failure is not critical
    }
}

function _broker_get_enabled_channels() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $channels = ['local_chat'];  // baseline default
    try {
        $rows = db_fetch_all(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'broker_enabled_channels'"
        );
        if (!empty($rows)) {
            $decoded = json_decode($rows[0]['value'], true);
            // Only override the default with a valid, non-empty array. A missing
            // row, an empty string, or malformed JSON must NOT collapse the list
            // to nothing — that silently drops every broadcast and route. Fall
            // back to the baseline instead.
            if (is_array($decoded) && !empty($decoded)) {
                $channels = $decoded;
            }
        }
    } catch (Exception $e) {
        // Settings table might not have the key yet
    }
    // local_chat is always enabled — never let a misconfigured row remove it.
    if (!in_array('local_chat', $channels, true)) {
        $channels[] = 'local_chat';
    }
    // Phase 99v-3 (a beta tester/Eric beta 2026-06-29): push is implicitly enabled
    // when VAPID is configured. The push channel adapter has its own
    // per-send gate (push_enabled + VAPID present), so making it
    // discoverable here lets routes target it without an extra Settings
    // toggle. If admin explicitly disables push via settings.push_enabled=0,
    // the channel adapter returns success=false and the route logs a
    // 'skipped' result — no traffic goes on the wire.
    if (!in_array('push', $channels, true) && function_exists('_push_enabled') && _push_enabled()) {
        $channels[] = 'push';
    }
    return $channels;
}

// ── Auto-load channel handlers ────────────────────────────────

$channelDir = __DIR__ . '/channels';
if (is_dir($channelDir)) {
    $files = glob($channelDir . '/*.php');
    foreach ($files as $f) {
        require_once $f;
    }
}

// Load the cross-protocol routing engine (if available)
$_routerFile = __DIR__ . '/router.php';
if (file_exists($_routerFile)) {
    require_once $_routerFile;
}
