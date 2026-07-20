<?php
/**
 * Channel: Local Chat
 *
 * In-database chat system. Messages stored in `chat_messages` table,
 * delivered via SSE real-time events. Supports:
 * - Direct messages (user-to-user)
 * - Broadcast (all users)
 * - Channel-based (team, incident)
 * - Signal/code canned messages
 * - Rich message types (text, signal, alert, system)
 */

broker_register('local_chat', [
    'name'    => 'Local Chat',
    'send'    => '_chat_send',
    'receive' => '_chat_receive',
    'status'  => '_chat_status'
]);

/**
 * Idempotent schema bootstrap for chat_messages. Ensures every column
 * the _chat_send INSERT expects exists. Cached in a static so it runs
 * at most once per request.
 *
 * 2026-06-30 (a beta tester beta): a beta tester's install was reporting
 *   "Send failed: SQLSTATE[42S22]: Column not found: 1054 Unknown
 *    column 'user_name' in 'INSERT INTO'"
 * because his chat_messages table predates Phase 77a's user_name
 * column. Rather than make the INSERT defensive at every call site,
 * this helper backfills the column on demand.
 */
function _chat_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Columns the _chat_send INSERT depends on, with their DDL.
    $needed = [
        'user_id'    => "INT(11) NULL",
        'user_name'  => "VARCHAR(64) NULL",
        'channel'    => "VARCHAR(64) NULL",
        'recipient'  => "VARCHAR(64) NULL",
        'body'       => "TEXT NULL",
        'msg_type'   => "VARCHAR(32) NULL DEFAULT 'text'",
        'priority'   => "VARCHAR(16) NULL DEFAULT 'normal'",
        'ticket_id'  => "INT(11) NULL",
        'signal_id'  => "INT(11) NULL",
        'created_at' => "DATETIME NULL",
    ];

    try {
        $existing = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?",
            [$prefix . 'chat_messages']
        );
        if (empty($existing)) {
            // Table doesn't exist at all — Phase 77a never ran here.
            // Create the minimal shape so the rest of the function works.
            db_query(
                "CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages` (
                    `id`         INT(11) NOT NULL AUTO_INCREMENT,
                    `user_id`    INT(11) NULL,
                    `user_name`  VARCHAR(64) NULL,
                    `channel`    VARCHAR(64) NULL,
                    `recipient`  VARCHAR(64) NULL,
                    `body`       TEXT NULL,
                    `msg_type`   VARCHAR(32) NULL DEFAULT 'text',
                    `priority`   VARCHAR(16) NULL DEFAULT 'normal',
                    `ticket_id`  INT(11) NULL,
                    `signal_id`  INT(11) NULL,
                    `created_at` DATETIME NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_created_at` (`created_at`),
                    KEY `idx_recipient` (`recipient`),
                    KEY `idx_ticket_id` (`ticket_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            return;
        }
        $present = [];
        foreach ($existing as $c) $present[strtolower($c['COLUMN_NAME'])] = true;
        foreach ($needed as $col => $ddl) {
            if (!isset($present[strtolower($col)])) {
                try {
                    db_query("ALTER TABLE `{$prefix}chat_messages` ADD COLUMN `{$col}` {$ddl}");
                } catch (Exception $e) {
                    error_log("[_chat_ensure_schema] add {$col} failed: " . $e->getMessage());
                }
            }
        }
        // a beta tester beta 2026-06-30 (round 2) — the legacy `chat_messages.message`
        // column ships as `TEXT NOT NULL` with no default in some older
        // base_schema.sql variants. Our INSERT doesn't write `message`
        // (we use `body` instead), so MySQL strict mode rejects with
        // "Field 'message' doesn't have a default value". ALTER it to
        // nullable so the INSERT succeeds without us having to write a
        // value we don't have. The column stays for legacy callers that
        // still read it.
        //
        // a beta tester beta 2026-07-01 (round 3) — same story for the legacy
        // `from` column (`varchar(16) NOT NULL COMMENT 'ip addr'`).
        // a beta tester's install on training saw "Field 'from' doesn't have
        // a default value" on send. We now populate `from` at INSERT
        // time with the client IP (see _chat_send below), but also
        // relax the schema so any INSERT path that omits it (or that
        // ran BEFORE a beta tester's page was reloaded to pick up the new
        // JS) doesn't blow up.
        //
        // Generalized: iterate every NOT NULL column that has no
        // default. Any that our INSERT does not populate would fail
        // strict mode. This subsumes both the message and from cases
        // and future-proofs against the next legacy column surprise.
        $ourInsertCols = [
            'user_id', 'user_name', 'channel', 'recipient', 'body',
            'msg_type', 'priority', 'ticket_id', 'signal_id',
            'created_at', 'from', // 'from' added a beta tester beta 2026-07-01
        ];
        $ourInsertColsLower = array_map('strtolower', $ourInsertCols);
        try {
            $notNullNoDefault = db_fetch_all(
                "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND IS_NULLABLE = 'NO'
                    AND COLUMN_DEFAULT IS NULL
                    AND EXTRA NOT LIKE '%auto_increment%'",
                [$prefix . 'chat_messages']
            );
            foreach ($notNullNoDefault as $col) {
                $name = $col['COLUMN_NAME'];
                $type = $col['COLUMN_TYPE'];
                // Skip columns our INSERT already populates.
                if (in_array(strtolower($name), $ourInsertColsLower, true)) continue;
                // Relax to NULL — safest reversible fix.
                try {
                    db_query("ALTER TABLE `{$prefix}chat_messages` MODIFY COLUMN `{$name}` {$type} NULL");
                    error_log("[_chat_ensure_schema] relaxed legacy NOT NULL column '{$name}' ({$type}) to NULL");
                } catch (Exception $e) {
                    error_log("[_chat_ensure_schema] failed to relax '{$name}': " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("[_chat_ensure_schema] legacy-column scan failed: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log('[_chat_ensure_schema] ' . $e->getMessage());
    }
}

function _chat_send(array $message) {
    _chat_ensure_schema();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $userName = $_SESSION['user'] ?? 'system';

    $body     = trim($message['body'] ?? '');
    $to       = $message['to'] ?? 'all';
    $channel  = $message['channel'] ?? 'general';
    $msgType  = $message['type'] ?? 'text';
    $priority = $message['priority'] ?? 'normal';
    $ticketId = $message['ticket_id'] ?? null;
    $signalId = $message['signal_id'] ?? null;

    // Phase 73u — only certain msg_types are renderable by chat-widget
    // without an author chrome. In particular 'system' is rendered as
    // a system event with no username, which would let any caller
    // impersonate dispatch. Whitelist user-allowed types; let the
    // broker-internal flag _system=true mark genuine server events.
    $userAllowed = ['text', 'signal', 'alert'];
    $systemFlag = !empty($message['_system']);
    if (!in_array($msgType, $userAllowed, true) && !$systemFlag) {
        $msgType = 'text';
    }

    if (!$body && !$signalId) {
        return ['success' => false, 'error' => 'Message body is required'];
    }

    // If this is a signal/code, look up the text
    if ($signalId) {
        try {
            $signal = db_fetch_one(
                "SELECT * FROM `{$prefix}codes` WHERE id = ?",
                [$signalId]
            );
            if ($signal) {
                $body = ($signal['code'] ?? '') . ': ' . ($signal['meaning'] ?? $body);
                $msgType = 'signal';
            }
        } catch (Exception $e) {
            // codes table might not exist — use body as-is
        }
    }

    // a beta tester beta 2026-07-01 — populate the legacy `from` column with
    // the caller's client IP so the audit-trail intent survives. The
    // column is `varchar(16)` (fits IPv4 exactly, truncates IPv6) —
    // hard-cap at 16 chars defensively so a raw IPv6 doesn't error.
    //
    // Phase 114 smoke (2026-07-07): `from` is a LEGACY column — fresh
    // NewUI schemas never had it, so the unconditional INSERT broke
    // chat send on every fresh install ("Unknown column 'from'").
    // Include it only when the table actually has it.
    static $hasFromCol = null;
    if ($hasFromCol === null) {
        try {
            $hasFromCol = (bool) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ? AND COLUMN_NAME = 'from'",
                [$prefix . 'chat_messages']
            );
        } catch (Exception $e) {
            $hasFromCol = false;
        }
    }
    $fromIp = function_exists('client_ip')
        ? client_ip()
        : ($_SERVER['REMOTE_ADDR'] ?? '');
    $fromIp = substr((string) $fromIp, 0, 16);

    try {
        $cols = '`user_id`, `user_name`, `channel`, `recipient`, `body`, `msg_type`, `priority`, `ticket_id`, `signal_id`, `created_at`';
        $ph   = '?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()';
        $args = [$userId, $userName, $channel, $to, $body, $msgType, $priority, $ticketId, $signalId];
        if ($hasFromCol) {
            $cols .= ', `from`';
            $ph   .= ', ?';
            $args[] = $fromIp;
        }
        db_query(
            "INSERT INTO `{$prefix}chat_messages` ($cols) VALUES ($ph)",
            $args
        );
        $chatId = db_insert_id();

        // Push SSE event for real-time delivery.
        // F-007 long-tail fix: scope the chat event by recipient/incident
        // so non-recipients in other groups don't see private chatter.
        //
        // Issue #50 (a beta tester 2026-07-03): the SSE payload was missing
        // two fields the client's chat-widget.js relies on:
        //   * `id` — the widget's dedupe check reads data.id
        //     (`data.id && data.id <= lastMessageId`). The payload
        //     only had `chat_id`, so this branch was undefined and
        //     the widget didn't advance lastMessageId.
        //   * `created_at` — appendMessage() feeds this to
        //     formatTime() which returns '' when the string is
        //     undefined, so real-time-appended messages rendered
        //     with a blank timestamp. Only the "reload from server
        //     on modal reopen" path (which reads created_at from
        //     the API) got a stamp.
        // Include both fields. created_at is emitted as ISO 8601
        // with timezone offset so JS Date() parses it reliably
        // across browsers (MySQL's native "YYYY-MM-DD HH:MM:SS"
        // space-separated form fails on Safari/older-mobile).
        $payload = [
            'id'         => (int) $chatId,
            'chat_id'    => $chatId,
            'user_id'    => $userId,
            'user_name'  => $userName,
            'channel'    => $channel,
            'recipient'  => $to,
            'body'       => $body,
            'msg_type'   => $msgType,
            'priority'   => $priority,
            'ticket_id'  => $ticketId,
            'created_at' => date('c'),
        ];
        $recipientUid = (is_numeric($to) && (int) $to > 0) ? (int) $to : 0;
        if ($recipientUid > 0 && function_exists('sse_publish_for_user')) {
            // Direct message — scope to recipient + sender so both see it.
            $ids = array_unique(array_filter([$recipientUid, (int) $userId]));
            sse_publish('chat:message', $payload, $userId, 'user', $ids);
        } elseif ($ticketId && function_exists('sse_publish_for_incident')) {
            // Incident-channel chat — scope to the groups allocated to it.
            sse_publish_for_incident('chat:message', $payload, (int) $ticketId, $userId);
        } else {
            // Org-wide channel chat (e.g. main "all" channel) — public.
            sse_publish('chat:message', $payload, $userId);
        }

        return ['success' => true, 'chat_id' => $chatId];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function _chat_receive($limit = 50) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}chat_messages`
             ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    } catch (Exception $e) {
        return [];
    }
}

function _chat_status() {
    return 'active';
}
