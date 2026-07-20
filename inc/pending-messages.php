<?php
/**
 * NewUI v4.0 — Pending routed messages (Phase 18e, 2026-06-11).
 *
 * Eric's spec ask: instead of post-send recall (which depends on
 * fragile protocol delete APIs), queue routed messages with a per-
 * label send delay. During the delay window, dispatchers can Kill
 * the row; killed messages NEVER go out.
 *
 * Public API:
 *
 *   pending_enqueue(array $msg): ?int
 *     Stash a routed message in the queue. Returns the row id, or null.
 *     The routing engine calls this when the resolved security label
 *     has routing_send_delay_secs > 0.
 *
 *   pending_list(string $status='pending', int $limit=50): array
 *     Rows for the admin/dispatcher UI.
 *
 *   pending_kill(int $id, ?int $userId, ?string $reason): bool
 *     Mark a row 'killed'. The cron sweep will not send killed rows.
 *
 *   pending_sweep(?int $now=null): array
 *     Cron entry point. For each pending row whose scheduled_send_at
 *     has passed, dispatch via the broker and mark sent/failed. Caps
 *     work at 200 rows per tick to avoid runaway.
 */

function pending_enqueue(array $msg): ?int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("
            INSERT INTO `{$prefix}pending_routed_messages`
                (ticket_id, route_id, channel, target, subject, body, priority,
                 scheduled_send_at, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $msg['ticket_id']      ?? null,
                $msg['route_id']       ?? null,
                $msg['channel']        ?? '',
                $msg['target']         ?? '',
                $msg['subject']        ?? null,
                $msg['body']           ?? '',
                $msg['priority']       ?? null,
                $msg['scheduled_send_at'] ?? date('Y-m-d H:i:s'),
                $msg['created_by']     ?? null,
            ]
        );
        return (int) db_insert_id();
    } catch (Exception $e) { return null; }
}

function pending_list(string $status = 'pending', int $limit = 50): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(200, $limit));
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}pending_routed_messages`
              WHERE status = ?
              ORDER BY scheduled_send_at ASC
              LIMIT {$limit}", [$status]);
    } catch (Exception $e) { return []; }
}

function pending_kill(int $id, ?int $userId, ?string $reason): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("
            UPDATE `{$prefix}pending_routed_messages`
               SET status = 'killed',
                   killed_at = NOW(),
                   killed_by = ?,
                   killed_reason = ?
             WHERE id = ? AND status = 'pending'",
            [$userId, $reason, $id]
        );
        if (function_exists('audit_log')) {
            audit_log('routing', 'kill', 'pending_message', $id,
                "Killed pending routed message #{$id}", ['reason' => $reason]);
        }
        return true;
    } catch (Exception $e) { return false; }
}

function pending_sweep(?int $now = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($now === null) $now = time();
    $nowStr = date('Y-m-d H:i:s', $now);
    $sent = 0; $failed = 0; $considered = 0;
    try {
        $rows = db_fetch_all(
            "SELECT * FROM `{$prefix}pending_routed_messages`
              WHERE status = 'pending'
                AND scheduled_send_at <= ?
              ORDER BY scheduled_send_at ASC
              LIMIT 200", [$nowStr]);
    } catch (Exception $e) { return ['considered' => 0, 'sent' => 0, 'failed' => 0]; }

    foreach ($rows as $r) {
        $considered++;
        $ok = false;
        $err = null;
        if (function_exists('broker_send')) {
            try {
                // Phase 44 (Sonar php:S930): broker_send signature is
                // (channel, message_array). Earlier this passed a single
                // array; the channel field never reached the broker and the
                // retry silently went to the default channel. Fixed alongside
                // the matching bug in inc/par.php (commit c0c6677).
                $resp = broker_send($r['channel'], [
                    'from'     => 'pending-sweep',
                    'target'   => $r['target'],
                    'subject'  => $r['subject'],
                    'body'     => $r['body'],
                    'priority' => $r['priority'] ?? 'normal',
                    '_is_routed_forward' => true,
                ]);
                $ok = !empty($resp['success']);
                if (!$ok) $err = $resp['error'] ?? 'broker rejected';
            } catch (Exception $e) {
                $err = $e->getMessage();
            }
        } else {
            // No broker available — mark failed and log so an admin can investigate.
            $err = 'broker_send() not loaded';
        }
        try {
            if ($ok) {
                db_query("UPDATE `{$prefix}pending_routed_messages`
                             SET status = 'sent', sent_at = NOW(), send_error = NULL
                           WHERE id = ?", [$r['id']]);
                $sent++;
            } else {
                db_query("UPDATE `{$prefix}pending_routed_messages`
                             SET status = 'failed', send_error = ?
                           WHERE id = ?", [substr($err ?? 'unknown', 0, 255), $r['id']]);
                $failed++;
            }
        } catch (Exception $e) {}
    }
    return ['considered' => $considered, 'sent' => $sent, 'failed' => $failed];
}
