<?php
/**
 * NewUI v4.0 API — Pending routed messages (Phase 18e).
 *
 * GET  ?status=pending          → list
 * POST {action: 'kill', id, reason}
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/pending-messages.php';
require_once __DIR__ . '/../inc/audit.php';
if (file_exists(__DIR__ . '/../inc/security-labels.php')) {
    require_once __DIR__ . '/../inc/security-labels.php';
}

ini_set('display_errors', '0');

if (!rbac_can('action.kill_pending_message') && !is_admin()) {
    json_error('Forbidden', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = (string) ($_GET['status'] ?? 'pending');
    if (!in_array($status, ['pending','sent','killed','failed'], true)) $status = 'pending';
    json_response([
        'rows' => pending_list($status, (int) ($_GET['limit'] ?? 50)),
        'now'  => date('Y-m-d H:i:s'),
    ]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('CSRF', 403);
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'kill') {
        $id = (int) ($input['id'] ?? 0);
        $reason = (string) ($input['reason'] ?? '');
        if ($id <= 0) json_error('id required');
        if (pending_kill($id, (int) ($_SESSION['user_id'] ?? 0) ?: null, $reason)) {
            json_response(['ok' => true]);
        }
        json_error('Kill failed (already sent or killed?)');
    }

    // Phase 18 polish — post-send recall stub.
    // Best-effort: posts a "RECALLED — disregard prior" follow-up to
    // the original target via broker_send(). Protocol-specific delete
    // APIs (Slack chat.delete, etc.) are deferred — admins should rely
    // on the send-delay queue (Kill before send) as the primary stop.
    if ($action === 'recall') {
        if (!rbac_can('action.recall_routed_message') && !is_admin()) json_error('Forbidden', 403);
        $id = (int) ($input['id'] ?? 0);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($id <= 0) json_error('id required');

        // Look up the original sent row.
        $row = db_fetch_one(
            "SELECT * FROM `" . ($GLOBALS['db_prefix'] ?? '') . "pending_routed_messages` WHERE id = ? LIMIT 1",
            [$id]);
        if (!$row) json_error('not found', 404);
        if ($row['status'] !== 'sent') json_error('Only sent messages can be recalled', 400);

        // Check recall window per the message's resolved security
        // label, if a ticket_id is present.
        if (!empty($row['ticket_id']) && function_exists('seclabel_resolve')) {
            $sec = seclabel_resolve((int) $row['ticket_id']);
            $window = (int) ($sec['routing_recall_window_s'] ?? 0);
            if ($window === 0) {
                json_error('This message\'s security label does not allow post-send recall', 400);
            }
            $sentTs = strtotime($row['sent_at'] ?: '0');
            if ($sentTs > 0 && (time() - $sentTs) > $window) {
                json_error('Recall window expired (' . $window . 's after send)', 400);
            }
        }

        // Best-effort: post a retraction follow-up.
        $sent = false;
        if (function_exists('broker_send')) {
            try {
                $resp = broker_send($row['channel'], [
                    'from'    => 'recall',
                    'target'  => $row['target'],
                    'subject' => 'RECALLED — disregard prior',
                    'body'    => "The prior message" .
                                 ($row['subject'] ? ' ("' . $row['subject'] . '")' : '') .
                                 " has been RECALLED" . ($reason ? ': ' . $reason : '.') .
                                 "\n\nNote: this is a follow-up retraction post; the recipient's copy of the original message may still be visible.",
                    'priority' => 'urgent',
                    '_is_routed_forward' => true,
                ]);
                $sent = !empty($resp['success']);
            } catch (Exception $e) {}
        }
        audit_log('routing', 'recall', 'pending_message', $id,
            "Recall posted for pending_routed_message #{$id}", [
                'reason'    => $reason,
                'follow_up' => $sent ? 'posted' : 'failed',
            ]);
        json_response(['ok' => true, 'follow_up_posted' => $sent]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
