<?php
/**
 * NewUI v4.0 API — Chat / Messaging
 *
 * GET  /api/chat.php                  — Get recent messages (general channel)
 * GET  /api/chat.php?channel=X        — Get messages for a specific channel
 * GET  /api/chat.php?signals=1        — Get available signal/code list
 * GET  /api/chat.php?channels=1       — Get broker channel statuses
 * POST action=send                    — Send a chat message
 * POST action=send_signal             — Send a signal/code
 * POST action=broadcast               — Broadcast to all enabled channels
 */

// Issue #27 (a beta tester 2026-07-02): SMTP test at chat.php action=
// test_channel returned an empty body ("Unexpected end of JSON input"
// on the client) with no way to see what was wrong. Route through
// the shared json-safe harness — display_errors off + shutdown
// handler that emits a proper JSON 500 with the real error logged
// server-side.
require_once __DIR__ . '/../inc/json-safe.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/broker.php';

$method = $_SERVER['REQUEST_METHOD'];
$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Ensure chat_messages table exists ──
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`    INT          NOT NULL DEFAULT 0,
        `user_name`  VARCHAR(64)  NOT NULL DEFAULT 'system',
        `channel`    VARCHAR(64)  NOT NULL DEFAULT 'general',
        `recipient`  VARCHAR(64)  NOT NULL DEFAULT 'all',
        `body`       TEXT         NOT NULL,
        `msg_type`   VARCHAR(32)  NOT NULL DEFAULT 'text',
        `priority`   VARCHAR(16)  NOT NULL DEFAULT 'normal',
        `ticket_id`  INT          DEFAULT NULL,
        `signal_id`  INT          DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`),
        KEY `idx_created` (`created_at`),
        KEY `idx_ticket`  (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Table already exists
}

// ── Ensure messages log table exists ──
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}messages` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `channel`      VARCHAR(64)  NOT NULL,
        `direction`    ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
        `msg_type`     VARCHAR(32)  NOT NULL DEFAULT 'general',
        `sender`       VARCHAR(128) NOT NULL DEFAULT 'system',
        `recipient`    VARCHAR(256) NOT NULL DEFAULT '',
        `subject`      VARCHAR(256) DEFAULT '',
        `body`         TEXT         NOT NULL,
        `priority`     VARCHAR(16)  NOT NULL DEFAULT 'normal',
        `status`       VARCHAR(32)  NOT NULL DEFAULT 'pending',
        `error`        TEXT         DEFAULT NULL,
        `payload`      TEXT         DEFAULT NULL,
        `delivered_at` DATETIME     DEFAULT NULL,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`),
        KEY `idx_status`  (`status`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Table already exists
}

if ($method === 'GET') {
    // List available signals/codes. Phase 73g: column is `sort`, not
    // `sort_order` — the old query threw on every request and the chat
    // signal/code picker silently rendered empty.
    if (!empty($_GET['signals'])) {
        try {
            $signals = db_fetch_all("SELECT * FROM `{$prefix}codes` ORDER BY `sort`, `code`");
        } catch (Exception $e) {
            error_log('[chat signals] SQL failure: ' . $e->getMessage());
            $signals = [];
        }
        json_response(['signals' => $signals]);
    }

    // Get broker channel statuses
    if (!empty($_GET['channels'])) {
        json_response(['channels' => broker_channel_statuses()]);
    }

    // Get chat messages
    $channel = $_GET['channel'] ?? 'general';
    $limit   = min((int) ($_GET['limit'] ?? 100), 500);
    $afterId = (int) ($_GET['after_id'] ?? 0);
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        // Phase 73u — DM leak fix. The REST history endpoint must
        // scope DMs the same way SSE delivery already does:
        //   - 'all' / broadcast messages → visible to everyone in
        //     the channel.
        //   - DMs (recipient is a numeric user_id) → visible to
        //     sender + recipient only.
        // Without this, polling /api/chat.php?channel=general would
        // return every DM ever sent through that channel to anyone.
        $where = "WHERE channel = ? AND (recipient = 'all' OR recipient = '' OR recipient IS NULL OR recipient = ? OR user_id = ?)";
        $params = [$channel, (string) $currentUserId, $currentUserId];

        if ($afterId > 0) {
            $where .= " AND id > ?";
            $params[] = $afterId;
        }

        // Admin bypass — required for moderation / audit views.
        if (is_admin()) {
            $where = "WHERE channel = ?";
            $params = [$channel];
            if ($afterId > 0) {
                $where .= " AND id > ?";
                $params[] = $afterId;
            }
        }

        $messages = db_fetch_all(
            "SELECT * FROM `{$prefix}chat_messages` $where ORDER BY id DESC LIMIT ?",
            array_merge($params, [$limit])
        );

        // Reverse to chronological order for display
        $messages = array_reverse($messages);
    } catch (Exception $e) {
        error_log('[chat.php history] ' . $e->getMessage());
        $messages = [];
    }

    json_response(['messages' => $messages, 'channel' => $channel]);
}

if ($method === 'POST') {
    if (!rbac_can('action.send_chat')) {
        json_error('Insufficient permissions', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // Phase 73t — CRITICAL: chat.php had no CSRF check. Any logged-in
    // browser tricked into visiting an attacker page could fire chat
    // messages, signals, and full-broker broadcasts as the victim.
    // Mirror the messaging.php pattern: accept token in body or
    // X-CSRF-Token header.
    $csrf = $input['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['csrf_token']
        ?? '';
    if (!csrf_verify((string) $csrf)) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // Send a chat message
    if ($action === 'send') {
        $result = broker_send('local_chat', [
            'body'      => $input['body'] ?? '',
            'to'        => $input['to'] ?? 'all',
            'channel'   => $input['channel'] ?? 'general',
            'type'      => $input['type'] ?? 'text',
            'priority'  => $input['priority'] ?? 'normal',
            'ticket_id' => $input['ticket_id'] ?? null
        ]);
        json_response($result);
    }

    // Send a signal/code
    if ($action === 'send_signal') {
        $result = broker_send('local_chat', [
            'body'      => $input['body'] ?? '',
            'signal_id' => $input['signal_id'] ?? null,
            'channel'   => $input['channel'] ?? 'general',
            'type'      => 'signal',
            'priority'  => $input['priority'] ?? 'normal'
        ]);
        json_response($result);
    }

    // Broadcast to all enabled channels
    if ($action === 'broadcast') {
        // Phase 73t — CRITICAL: broker_broadcast fans to Slack, SMTP,
        // SMS, Meshtastic, DMR, and every other enabled channel. The
        // parallel api/messaging.php broadcast at line 358 correctly
        // gates this on admin OR action.manage_members. Without the
        // same gate here, anyone with action.send_chat (every chat
        // role) could fire an outbound to every connected channel.
        if (!is_admin()
            && !rbac_can('action.manage_members')
            && !rbac_can('action.broadcast_alerts')) {
            json_error('Broadcast requires admin or broadcast permission', 403);
        }
        $results = broker_broadcast([
            'body'    => $input['body'] ?? '',
            'to'      => $input['to'] ?? 'all',
            'subject' => $input['subject'] ?? '',
            'type'    => $input['type'] ?? 'alert',
            'priority' => $input['priority'] ?? 'high'
        ]);
        json_response(['results' => $results]);
    }

    if ($action === 'test_channel') {
        // Direct provider test — sends through ONE channel handler via
        // broker_send(), bypassing the broker_enabled_channels routing
        // gate. Backs the "Test SMS / Test Email / Test Slack" buttons in
        // Settings, whose job is to verify provider CREDENTIALS, which is
        // independent of whether the channel is enabled for routing.
        // (a beta tester, 2026-06-26: the old buttons POSTed action=broadcast
        // with no CSRF token AND were gated by enabled-channels, so a
        // 403/skip was misreported as "enable SMS as a delivery channel".)
        if (!is_admin()) {
            json_error('Channel test requires admin', 403);
        }
        $channel = (string) ($input['channel'] ?? '');
        global $_broker_channels;
        if ($channel === '' || !isset($_broker_channels[$channel])) {
            json_error('Unknown or unregistered channel: ' . $channel, 400);
        }
        // Phase 102 (a beta tester beta 2026-07-01) — a beta tester hit "JSON.parse:
        // unexpected end of data" on this endpoint from a fresh Linux
        // install. Root cause: broker_send() -> channel handler can
        // throw or trigger a fatal PHP error (e.g. SMTP relay socket
        // fails, TLS handshake blows up, sendmail path missing) which
        // exits before json_response() runs, leaving an empty body for
        // the JS `.json()` parser. Wrap in a defensive try/catch so
        // any failure comes back as a structured JSON error the UI can
        // surface. Set_error_handler catches non-Exception failures
        // (E_WARNING from a bad SMTP socket, etc.) and turns them
        // into Exception so this catches them too.
        set_error_handler(static function ($sev, $msg, $file, $line) {
            if (!(error_reporting() & $sev)) return false;
            throw new ErrorException($msg, 0, $sev, $file, $line);
        });
        try {
            $result = broker_send($channel, [
                'to'       => $input['to'] ?? 'all',
                'body'     => $input['body'] ?? 'Test message from TicketsCAD',
                'subject'  => $input['subject'] ?? 'TicketsCAD test',
                'type'     => 'test',
                'priority' => 'normal',
            ]);
        } catch (Throwable $t) {
            restore_error_handler();
            error_log('[chat.php test_channel] ' . $channel . ': ' . $t->getMessage());
            json_response([
                'result' => ['success' => false, 'error' => $t->getMessage()],
                'channel' => $channel,
            ]);
        }
        restore_error_handler();
        json_response(['result' => $result, 'channel' => $channel]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
