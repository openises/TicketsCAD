<?php
/**
 * Phase 114a — communications channel registry API
 *
 * GET                       — list channels + state (screen.console)
 *   ?enabled=1              — enabled channels only
 *   ?probe=1                — refresh best-effort health first
 *   ?feed=<id>&limit=N      — recent activity for one channel (unified
 *                             view over zello_messages / dmr_messages /
 *                             chat_messages / messages / weather_alerts /
 *                             sse_events, per adapter)
 * POST action=send          — text send on a text_tx-capable channel via
 *                             the broker (action.send_chat or
 *                             action.console_tx)
 * POST action=update        — presentation overrides: label, short_label,
 *                             color, enabled, sort_order (console.design)
 * POST action=sync          — re-derive managed rows from config sources
 *                             (console.design)
 *
 * Adapter state reporting stays server-side (channel_state_set() from the
 * adapters' own PHP paths) — no HTTP state ingest in 114a.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/channel_registry.php';

if (!rbac_can('screen.console') && !rbac_can('screen.settings')) {
    json_error('Forbidden', 403);
}

/**
 * Unified recent-activity feed for one channel. Every item normalizes to
 * {when, who, body, dir} regardless of which backing table it came from.
 * Missing tables / optional features return an empty feed, never an error.
 */
function channel_feed(array $ch, $limit) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit  = max(1, min(100, (int) $limit));
    $items  = [];
    try {
        if ($ch['adapter'] === 'zello' && !empty($ch['config']['channel'])) {
            foreach (db_fetch_all(
                "SELECT created, sender_display, sender_username, content, direction, message_type
                   FROM `{$prefix}zello_messages`
                  WHERE channel = ? ORDER BY id DESC LIMIT $limit",
                [$ch['config']['channel']]
            ) as $r) {
                $items[] = [
                    'when' => $r['created'],
                    'who'  => $r['sender_display'] ?: $r['sender_username'],
                    'body' => $r['message_type'] === 'text' ? $r['content'] : '[' . $r['message_type'] . ']',
                    'dir'  => $r['direction'],
                ];
            }
        } elseif ($ch['adapter'] === 'dmr_bm' && !empty($ch['config']['dmr_channel_id'])) {
            foreach (db_fetch_all(
                "SELECT call_started_at, radio_callsign, radio_id, transcript, direction
                   FROM `{$prefix}dmr_messages`
                  WHERE channel_id = ? ORDER BY id DESC LIMIT $limit",
                [$ch['config']['dmr_channel_id']]
            ) as $r) {
                $items[] = [
                    'when' => $r['call_started_at'],
                    'who'  => $r['radio_callsign'] ?: $r['radio_id'],
                    'body' => $r['transcript'] !== null && $r['transcript'] !== ''
                              ? $r['transcript'] : '[voice call]',
                    'dir'  => $r['direction'],
                ];
            }
        } elseif ($ch['channel_key'] === 'broker:local_chat') {
            foreach (db_fetch_all(
                "SELECT created_at, user_name, body FROM `{$prefix}chat_messages`
                  ORDER BY id DESC LIMIT $limit"
            ) as $r) {
                $items[] = ['when' => $r['created_at'], 'who' => $r['user_name'],
                            'body' => $r['body'], 'dir' => null];
            }
        } elseif ($ch['adapter'] === 'nws') {
            foreach (db_fetch_all(
                "SELECT first_seen, event, headline, severity FROM `{$prefix}weather_alerts`
                  ORDER BY id DESC LIMIT $limit"
            ) as $r) {
                $items[] = ['when' => $r['first_seen'], 'who' => $r['severity'],
                            'body' => $r['event'] . ($r['headline'] ? ' — ' . $r['headline'] : ''),
                            'dir' => 'rx'];
            }
        } elseif ($ch['adapter'] === 'eventbus') {
            foreach (db_fetch_all(
                "SELECT created_at, event_type FROM `{$prefix}sse_events`
                  ORDER BY id DESC LIMIT $limit"
            ) as $r) {
                $items[] = ['when' => $r['created_at'], 'who' => null,
                            'body' => $r['event_type'], 'dir' => 'rx'];
            }
        } else {
            // Broker-backed adapters (mesh, meshcore, aprs, sms, smtp, ...)
            $code = $ch['config']['broker_channel']
                ?? ($ch['adapter'] === 'mesh' ? 'meshtastic' : $ch['adapter']);
            foreach (db_fetch_all(
                "SELECT created_at, sender, body, direction FROM `{$prefix}messages`
                  WHERE channel = ? ORDER BY id DESC LIMIT $limit",
                [$code]
            ) as $r) {
                $items[] = ['when' => $r['created_at'], 'who' => $r['sender'],
                            'body' => $r['body'], 'dir' => $r['direction']];
            }
        }
    } catch (Exception $e) {
        // Optional table missing on this install — empty feed, not an error.
    }
    return array_reverse($items); // oldest first for display
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (!empty($_GET['feed'])) {
            $ch = channel_get((int) $_GET['feed']);
            if (!$ch) { json_error('Channel not found', 404); }
            json_response(['feed' => channel_feed($ch, $_GET['limit'] ?? 30)]);
        }
        if (!empty($_GET['probe'])) {
            channel_registry_probe();
        }
        $channels = channels_all(!empty($_GET['enabled']));
        json_response(['channels' => $channels]);
    } catch (Exception $e) {
        json_error_safe('Failed to load channels', $e);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}

require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$action = $input['action'] ?? '';

// ── Text send: operational (not a designer action) ──────────────────────
if ($action === 'send') {
    if (!rbac_can('action.send_chat') && !rbac_can('action.console_tx')) {
        json_error('Forbidden', 403);
    }
    $ch = channel_get((int) ($input['id'] ?? 0));
    if (!$ch) { json_error('Channel not found', 404); }
    if (empty($ch['capabilities']['text_tx'])) {
        json_error('Channel does not support text transmit');
    }
    if ((int) $ch['enabled'] !== 1) { json_error('Channel is disabled'); }
    $body = trim((string) ($input['body'] ?? ''));
    if ($body === '') { json_error('Empty message'); }

    try {
        require_once __DIR__ . '/../inc/sse.php';    // channel handlers publish SSE
        require_once __DIR__ . '/../inc/broker.php';
        $code = $ch['config']['broker_channel']
            ?? ($ch['adapter'] === 'mesh' ? 'meshtastic'
              : ($ch['adapter'] === 'zello' ? 'zello' : $ch['adapter']));
        $msg = ['body' => $body, 'to' => 'all', 'priority' => 'normal', 'type' => 'text'];
        if ($ch['adapter'] === 'zello' && !empty($ch['config']['channel'])) {
            $msg['channel'] = $ch['config']['channel'];
        } elseif ($ch['channel_key'] === 'broker:local_chat') {
            $msg['channel'] = 'general';
        } elseif ($ch['adapter'] === 'mesh' && !empty($ch['config']['name'])) {
            $msg['channel'] = $ch['config']['name'];
        }
        $result = broker_send($code, $msg);
        $ok = !empty($result['success']);
        if ($ok) {
            channel_state_set($ch['id'], ['last_tx_at' => date('Y-m-d H:i:s')]);
        }
        json_response(['ok' => $ok, 'result' => $result]);
    } catch (Exception $e) {
        json_error_safe('Send failed', $e);
    }
}

// ── Designer/admin actions below ─────────────────────────────────────────
// console.design ONLY — screen.settings is deliberately NOT accepted here:
// some installs grant it to Dispatchers, which must not imply authoring
// shared console configuration (Phase 114 smoke finding, 2026-07-07).
if (!rbac_can('console.design')) {
    json_error('Forbidden', 403);
}

if ($action === 'sync') {
    try {
        $r = channel_registry_sync();
        audit_log('config', 'channels.sync', 'comm_channels', null,
            "Channel registry sync: {$r['created']} created, {$r['updated']} updated, {$r['pruned']} pruned");
        json_response(['ok' => true, 'result' => $r]);
    } catch (Exception $e) {
        json_error_safe('Sync failed', $e);
    }
}

if ($action === 'update') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { json_error('Missing channel id'); }
    $ch = channel_get($id);
    if (!$ch) { json_error('Channel not found', 404); }

    $sets = []; $args = []; $changed = [];
    if (array_key_exists('label', $input) && trim((string) $input['label']) !== '') {
        $sets[] = 'label = ?';       $args[] = trim((string) $input['label']);
        $changed['label'] = $args[count($args) - 1];
    }
    if (array_key_exists('short_label', $input)) {
        $v = trim((string) $input['short_label']);
        $sets[] = 'short_label = ?'; $args[] = ($v === '') ? null : substr($v, 0, 24);
        $changed['short_label'] = $args[count($args) - 1];
    }
    if (array_key_exists('color', $input)) {
        $v = trim((string) $input['color']);
        if ($v !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)) {
            json_error('Invalid color');
        }
        $sets[] = 'color = ?';       $args[] = ($v === '') ? null : $v;
        $changed['color'] = $args[count($args) - 1];
    }
    if (array_key_exists('enabled', $input)) {
        $sets[] = 'enabled = ?';     $args[] = $input['enabled'] ? 1 : 0;
        $changed['enabled'] = $args[count($args) - 1];
    }
    if (array_key_exists('sort_order', $input)) {
        $sets[] = 'sort_order = ?';  $args[] = (int) $input['sort_order'];
        $changed['sort_order'] = $args[count($args) - 1];
    }
    if (!$sets) { json_error('Nothing to update'); }

    try {
        $args[] = $id;
        db_query("UPDATE `{$prefix}comm_channels` SET " . implode(', ', $sets) . " WHERE id = ?", $args);
        audit_log('config', 'channels.update', 'comm_channels', $id,
            'Channel "' . $ch['label'] . '" updated', $changed);
        json_response(['ok' => true, 'channel' => channel_get($id)]);
    } catch (Exception $e) {
        json_error_safe('Update failed', $e);
    }
}

json_error('Unknown action');
