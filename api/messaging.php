<?php
/**
 * NewUI v4.0 API — Internal Messaging & HAS Broadcast
 *
 * GET  /api/messaging.php                      — List inbox messages (paginated)
 * GET  /api/messaging.php?folder=sent          — List sent messages
 * GET  /api/messaging.php?id=X                 — Get single message (marks as read)
 * GET  /api/messaging.php?unread_count=1       — Get unread message count
 * GET  /api/messaging.php?users=1              — Get user list for compose dropdown
 * POST action=send                             — Send a message to one/many/all users
 * POST action=delete                           — Soft-delete a message (move to trash)
 * POST action=bulk_delete                      — Soft-delete multiple messages
 * POST action=broadcast                        — HAS broadcast to ALL users (urgent)
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/sse.php';

$method = $_SERVER['REQUEST_METHOD'];
$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Ensure tables exist ──
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}internal_messages` (
        `id`           INT          AUTO_INCREMENT PRIMARY KEY,
        `from_user_id` INT          NOT NULL,
        `subject`      VARCHAR(255) NOT NULL DEFAULT '',
        `body`         TEXT         NOT NULL,
        `priority`     ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
        `incident_id`  INT          DEFAULT NULL,
        `is_broadcast` TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_im_from_user` (`from_user_id`),
        KEY `idx_im_created`   (`created_at`),
        KEY `idx_im_incident`  (`incident_id`),
        KEY `idx_im_broadcast` (`is_broadcast`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* table exists */ }

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}message_recipients` (
        `id`         INT      AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT      NOT NULL,
        `to_user_id` INT      NOT NULL,
        `read_at`    DATETIME DEFAULT NULL,
        `deleted_at` DATETIME DEFAULT NULL,
        KEY `idx_mr_message`     (`message_id`),
        KEY `idx_mr_to_user`     (`to_user_id`),
        KEY `idx_mr_unread`      (`to_user_id`, `read_at`, `deleted_at`),
        KEY `idx_mr_deleted`     (`to_user_id`, `deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* table exists */ }


// ═══════════════════════════════════════════════════════════════
// GET requests
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // ── Unread count (efficient single-value query) ──
    if (!empty($_GET['unread_count'])) {
        try {
            $count = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}message_recipients`
                 WHERE to_user_id = ? AND read_at IS NULL AND deleted_at IS NULL",
                [$current_user_id]
            );
        } catch (Exception $e) {
            $count = 0;
        }
        json_response(['unread_count' => $count]);
    }

    // ── User list for compose dropdown ──
    if (!empty($_GET['users'])) {
        try {
            $users = db_fetch_all(
                "SELECT id, user AS name FROM `{$prefix}user` WHERE id != ? ORDER BY user",
                [$current_user_id]
            );
        } catch (Exception $e) {
            $users = [];
        }
        json_response(['users' => $users]);
    }

    // ── Single message detail ──
    if (!empty($_GET['id'])) {
        $msgId = (int) $_GET['id'];

        try {
            // Fetch message
            $msg = db_fetch_one(
                "SELECT m.*, u.user AS from_name
                 FROM `{$prefix}internal_messages` m
                 LEFT JOIN `{$prefix}user` u ON u.id = m.from_user_id
                 WHERE m.id = ?",
                [$msgId]
            );

            if (!$msg) {
                json_error('Message not found', 404);
            }

            // Verify access: user must be sender or recipient
            $isRecipient = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}message_recipients`
                 WHERE message_id = ? AND to_user_id = ? AND deleted_at IS NULL",
                [$msgId, $current_user_id]
            );
            $isSender = ((int) $msg['from_user_id'] === $current_user_id);

            if (!$isRecipient && !$isSender && !is_admin()) {
                json_error('Access denied', 403);
            }

            // Mark as read for this recipient
            if ($isRecipient) {
                db_query(
                    "UPDATE `{$prefix}message_recipients`
                     SET read_at = NOW()
                     WHERE message_id = ? AND to_user_id = ? AND read_at IS NULL",
                    [$msgId, $current_user_id]
                );
            }

            // Get all recipients for display
            $recipients = db_fetch_all(
                "SELECT mr.to_user_id, u.user AS to_name, mr.read_at
                 FROM `{$prefix}message_recipients` mr
                 LEFT JOIN `{$prefix}user` u ON u.id = mr.to_user_id
                 WHERE mr.message_id = ?
                 ORDER BY u.user",
                [$msgId]
            );
            $msg['recipients'] = $recipients;

        } catch (Exception $e) {
            json_error('Failed to load message: ' . $e->getMessage(), 500);
        }

        json_response(['message' => $msg]);
    }

    // ── Message list (inbox or sent) ──
    $folder = $_GET['folder'] ?? 'inbox';
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limit  = min(100, max(10, (int) ($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    try {
        if ($folder === 'sent') {
            // Phase 99b (2026-06-28) — unified Sent tab. Pulls both
            // internal messages (internal_messages) and outbound
            // broker sends (messages.direction='outbound', excluding
            // local_chat which is the chat widget's own surface).
            //
            // Both sources are normalized to a common shape, merged
            // by created_at DESC, then paginated in PHP. For typical
            // installs (<1000 sent / user) this is well within memory
            // budget; if it scales painfully, a UNION ALL + window-
            // function query would be the next step.
            $currentUserName = (string) ($_SESSION['user'] ?? '');

            $internal = db_fetch_all(
                "SELECT m.id, m.subject, m.priority, m.incident_id, m.is_broadcast, m.created_at,
                        GROUP_CONCAT(u.user ORDER BY u.user SEPARATOR ', ') AS to_names
                 FROM `{$prefix}internal_messages` m
                 LEFT JOIN `{$prefix}message_recipients` mr ON mr.message_id = m.id
                 LEFT JOIN `{$prefix}user` u ON u.id = mr.to_user_id
                 WHERE m.from_user_id = ?
                 GROUP BY m.id
                 ORDER BY m.created_at DESC
                 LIMIT 500",
                [$current_user_id]
            );

            $external = [];
            if ($currentUserName !== '') {
                try {
                    $external = db_fetch_all(
                        "SELECT id, channel, recipient, subject, body, priority, status, error, created_at, delivered_at
                         FROM `{$prefix}messages`
                         WHERE sender = ?
                           AND direction = 'outbound'
                           AND channel <> 'local_chat'
                         ORDER BY created_at DESC
                         LIMIT 500",
                        [$currentUserName]
                    );
                } catch (Exception $e) { /* messages table absent — non-fatal */ }
            }

            // Normalize both into a unified shape the JS can render.
            $unified = [];
            foreach ($internal as $row) {
                $unified[] = [
                    'source'       => 'inbox',
                    'channel'      => 'inbox',
                    'id'           => (int) $row['id'],
                    'subject'      => $row['subject'],
                    'priority'     => $row['priority'],
                    'incident_id'  => $row['incident_id'],
                    'is_broadcast' => (int) $row['is_broadcast'],
                    'to_names'     => $row['to_names'],
                    'status'       => null,
                    'created_at'   => $row['created_at'],
                ];
            }
            foreach ($external as $row) {
                $unified[] = [
                    'source'       => 'external',
                    'channel'      => $row['channel'],
                    'id'           => (int) $row['id'],
                    'subject'      => $row['subject'] !== '' ? $row['subject'] :
                                      // SMS rows often have empty subject — show body excerpt
                                      (function_exists('mb_substr')
                                          ? mb_substr((string) ($row['body'] ?? ''), 0, 80)
                                          : substr((string) ($row['body'] ?? ''), 0, 80)),
                    'priority'     => $row['priority'],
                    'incident_id'  => null,
                    'is_broadcast' => 0,
                    'to_names'     => $row['recipient'],
                    'status'       => $row['status'],
                    'created_at'   => $row['created_at'],
                ];
            }
            // Merge-sort by created_at DESC (string sort works for
            // 'YYYY-MM-DD HH:MM:SS' format).
            usort($unified, function ($a, $b) {
                return strcmp((string) $b['created_at'], (string) $a['created_at']);
            });

            $total    = count($unified);
            $messages = array_slice($unified, $offset, $limit);
        } else {
            // Inbox
            $total = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}message_recipients` mr
                 WHERE mr.to_user_id = ? AND mr.deleted_at IS NULL",
                [$current_user_id]
            );

            $messages = db_fetch_all(
                "SELECT m.id, m.subject, m.priority, m.incident_id, m.is_broadcast, m.created_at,
                        u.user AS from_name, mr.read_at
                 FROM `{$prefix}message_recipients` mr
                 INNER JOIN `{$prefix}internal_messages` m ON m.id = mr.message_id
                 LEFT JOIN `{$prefix}user` u ON u.id = m.from_user_id
                 WHERE mr.to_user_id = ? AND mr.deleted_at IS NULL
                 ORDER BY m.created_at DESC
                 LIMIT ? OFFSET ?",
                [$current_user_id, $limit, $offset]
            );
        }
    } catch (Exception $e) {
        json_error('Failed to load messages: ' . $e->getMessage(), 500);
    }

    json_response([
        'messages' => $messages,
        'folder'   => $folder,
        'page'     => $page,
        'limit'    => $limit,
        'total'    => $total,
        'pages'    => (int) ceil($total / $limit)
    ]);
}


// ═══════════════════════════════════════════════════════════════
// POST requests
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    // CSRF verification
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrfToken = $input['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    if (!csrf_verify($csrfToken)) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // ── Send message ──
    if ($action === 'send') {
        if (!rbac_can('action.send_chat')) {
            json_error('Insufficient permissions', 403);
        }

        $subject    = trim($input['subject'] ?? '');
        $body       = trim($input['body'] ?? '');
        $priority   = $input['priority'] ?? 'normal';
        $incidentId = !empty($input['incident_id']) ? (int) $input['incident_id'] : null;
        $toUsers    = $input['to_users'] ?? [];    // array of user IDs
        $toAll      = !empty($input['to_all']);

        if ($body === '') {
            json_error('Message body is required');
        }
        if (!in_array($priority, ['normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }

        try {
            // Insert message
            db_query(
                "INSERT INTO `{$prefix}internal_messages`
                 (from_user_id, subject, body, priority, incident_id, is_broadcast)
                 VALUES (?, ?, ?, ?, ?, 0)",
                [$current_user_id, $subject, $body, $priority, $incidentId]
            );
            $messageId = (int) db_insert_id();

            // Determine recipients
            if ($toAll) {
                $allUsers = db_fetch_all(
                    "SELECT id FROM `{$prefix}user` WHERE id != ?",
                    [$current_user_id]
                );
                $toUsers = array_column($allUsers, 'id');
            }

            // Insert recipient rows
            foreach ($toUsers as $uid) {
                $uid = (int) $uid;
                if ($uid <= 0 || $uid === $current_user_id) continue;
                db_query(
                    "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id)
                     VALUES (?, ?)",
                    [$messageId, $uid]
                );
            }

            // Publish SSE event for real-time notification.
            // Per F-007, scope to the actual recipient list when not a broadcast,
            // so non-recipients don't see the notification arrive.
            $msgPayload = [
                'message_id'   => $messageId,
                'from_user_id' => $current_user_id,
                'from_name'    => $current_user,
                'subject'      => $subject,
                'priority'     => $priority,
                'to_users'     => $toUsers,
                'to_all'       => $toAll,
                'incident_id'  => $incidentId
            ];
            if ($toAll) {
                sse_publish('message:new', $msgPayload);
            } else {
                $recipientIds = array_values(array_filter(array_map('intval', $toUsers), function ($u) { return $u > 0; }));
                sse_publish('message:new', $msgPayload, null, 'user', $recipientIds);
            }

        } catch (Exception $e) {
            json_error('Failed to send message: ' . $e->getMessage(), 500);
        }

        json_response([
            'ok'         => true,
            'message_id' => $messageId,
            'recipients' => count($toUsers)
        ]);
    }

    // ── Delete message (soft) ──
    if ($action === 'delete') {
        $msgId = (int) ($input['message_id'] ?? 0);
        if ($msgId <= 0) {
            json_error('Invalid message_id');
        }

        try {
            db_query(
                "UPDATE `{$prefix}message_recipients`
                 SET deleted_at = NOW()
                 WHERE message_id = ? AND to_user_id = ? AND deleted_at IS NULL",
                [$msgId, $current_user_id]
            );
        } catch (Exception $e) {
            json_error('Failed to delete message', 500);
        }

        json_response(['ok' => true]);
    }

    // ── Bulk delete ──
    if ($action === 'bulk_delete') {
        $msgIds = $input['message_ids'] ?? [];
        if (!is_array($msgIds) || empty($msgIds)) {
            json_error('No message IDs provided');
        }

        $deleted = 0;
        try {
            foreach ($msgIds as $mid) {
                $mid = (int) $mid;
                if ($mid <= 0) continue;
                $stmt = db_query(
                    "UPDATE `{$prefix}message_recipients`
                     SET deleted_at = NOW()
                     WHERE message_id = ? AND to_user_id = ? AND deleted_at IS NULL",
                    [$mid, $current_user_id]
                );
                $deleted += $stmt->rowCount();
            }
        } catch (Exception $e) {
            json_error('Failed to delete messages', 500);
        }

        json_response(['ok' => true, 'deleted' => $deleted]);
    }

    // ── Bulk Mark Read (Eric 2026-06-28 feedback) ──
    // Same shape as bulk_delete but stamps read_at instead of deleted_at.
    // Useful for clearing the inbox unread badge after a batch of
    // auto-generated messages (PAR overdue broadcasts etc.) accumulate.
    if ($action === 'bulk_mark_read') {
        $msgIds = $input['message_ids'] ?? [];
        if (!is_array($msgIds) || empty($msgIds)) {
            json_error('No message IDs provided');
        }

        $marked = 0;
        try {
            foreach ($msgIds as $mid) {
                $mid = (int) $mid;
                if ($mid <= 0) continue;
                $stmt = db_query(
                    "UPDATE `{$prefix}message_recipients`
                     SET read_at = NOW()
                     WHERE message_id = ? AND to_user_id = ?
                       AND deleted_at IS NULL AND read_at IS NULL",
                    [$mid, $current_user_id]
                );
                $marked += $stmt->rowCount();
            }
        } catch (Exception $e) {
            json_error('Failed to mark messages read', 500);
        }

        json_response(['ok' => true, 'marked_read' => $marked]);
    }

    // ── HAS Broadcast ──
    if ($action === 'broadcast') {
        // Phase 12 (2026-06-11): broadcast requires the chat-send
        // permission AND either admin OR ability to manage members
        // (i.e., Dispatcher / Operator tier).
        if (!rbac_can('action.send_chat') || (!is_admin() && !rbac_can('action.manage_members'))) {
            json_error('Insufficient permissions for broadcast', 403);
        }

        $body    = trim($input['body'] ?? '');
        $subject = trim($input['subject'] ?? 'HAS Broadcast');

        if ($body === '') {
            json_error('Broadcast message body is required');
        }

        try {
            // Insert broadcast message
            db_query(
                "INSERT INTO `{$prefix}internal_messages`
                 (from_user_id, subject, body, priority, is_broadcast)
                 VALUES (?, ?, ?, 'urgent', 1)",
                [$current_user_id, $subject, $body]
            );
            $messageId = (int) db_insert_id();

            // Send to ALL users
            $allUsers = db_fetch_all(
                "SELECT id FROM `{$prefix}user` WHERE id != ?",
                [$current_user_id]
            );
            $recipientCount = 0;
            foreach ($allUsers as $u) {
                db_query(
                    "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id)
                     VALUES (?, ?)",
                    [$messageId, (int) $u['id']]
                );
                $recipientCount++;
            }

            // Publish SSE event — this triggers audio alert + popup on all clients
            sse_publish('message:broadcast', [
                'message_id'   => $messageId,
                'from_user_id' => $current_user_id,
                'from_name'    => $current_user,
                'subject'      => $subject,
                'body'         => $body,
                'priority'     => 'urgent',
                'is_broadcast' => true,
                'recipients'   => $recipientCount
            ]);

        } catch (Exception $e) {
            json_error('Failed to send broadcast: ' . $e->getMessage(), 500);
        }

        json_response([
            'ok'         => true,
            'message_id' => $messageId,
            'recipients' => $recipientCount
        ]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
