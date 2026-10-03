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
 *     Scheduler entry point. For each pending row whose scheduled_send_at
 *     has passed, dispatch via the broker and mark sent/failed. Caps
 *     work at 200 rows per tick to avoid runaway.
 *
 * STALE WORK IS NOT DELIVERED (Phase 127, 2026-07-29)
 * ---------------------------------------------------
 * This sweep was installed as a cron job on hosts that had no cron daemon,
 * so it never ran once in seven weeks. The queue holds messages that were
 * deliberately delayed by a few minutes to give a dispatcher a kill window
 * — not messages that are meant to arrive in the middle of next month. The
 * first successful tick after a long outage would otherwise deliver the
 * entire backlog at once to a live emergency-response team, out of context
 * and possibly contradicting whatever happened since.
 *
 * So a row more than sched_stale_cutoff_min minutes past its scheduled time
 * is moved to status='expired' and NOT sent. The row is kept, not deleted;
 * send_error records the scheduled time, the age and the cutoff that
 * governed the call, so "why did I not get this?" has an answer. An
 * operator who decides a message should still go out can set it back to
 * 'pending' with a fresh scheduled_send_at.
 */

require_once __DIR__ . '/scheduled-jobs.php';
// The queue carries two kinds of row now (2026-07-31): messages held for a
// security label's kill window, and audit-driven notification fan-outs moved
// off the dispatch request path. notify-fanout.php owns the second kind; this
// file owns the sweep both are drained by. The two require_once each other,
// which is safe because neither uses the other's symbols at load time.
if (is_file(__DIR__ . '/notify-fanout.php')) {
    require_once __DIR__ . '/notify-fanout.php';
}
// Phase 155 (GH#144): a third kind of row - Notification Rule deliveries. The
// delivery code (NOTIFY_RULE_CHANNEL, replay, per-channel breaker) lives in
// notification-delivery.php; this file only recognises the channel in the sweep.
if (is_file(__DIR__ . '/notification-delivery.php')) {
    require_once __DIR__ . '/notification-delivery.php';
}

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
        // A killed Notification Rule delivery must not read `queued` for ever in the
        // delivery log (and "once per incident" must not believe it was sent).
        if (function_exists('notification_delivery_mark_unsent')) {
            notification_delivery_mark_unsent($id, 'killed during the security-label send delay'
                . ($reason !== null && $reason !== '' ? ': ' . $reason : ''), 'skipped');
        }
        return true;
    } catch (Exception $e) { return false; }
}

/**
 * @param ?int    $now         evaluation time; defaults to now
 * @param ?int    $cutoffMin   override the stale cutoff (tests)
 * @param ?int    $limit       rows per sweep; defaults to 200
 * @param ?float  $budgetS     wall-clock budget. Stop when it is gone and
 *                             leave the rest pending — this is what lets the
 *                             same sweep be called from a request path
 *                             without ever becoming the 21-second stall it
 *                             replaced.
 * @param ?string $onlyChannel restrict to one queue channel
 */
function pending_sweep(?int $now = null, ?int $cutoffMin = null,
                       ?int $limit = null, ?float $budgetS = null,
                       ?string $onlyChannel = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($now === null) $now = time();
    $nowStr = date('Y-m-d H:i:s', $now);
    $limit  = max(1, min(500, $limit ?? 200));
    $deadline = $budgetS !== null ? microtime(true) + max(0.0, $budgetS) : null;
    $sent = 0; $failed = 0; $considered = 0; $expired = 0; $deferred = 0;
    $breakerOpen = null;   // resolved lazily, once per sweep
    $ruleState = ['defer' => [], 'fails' => []];   // Notification Rule deliveries: per-channel, per-sweep (Phase 155)
    try {
        $sql  = "SELECT * FROM `{$prefix}pending_routed_messages`
                  WHERE status = 'pending'
                    AND scheduled_send_at <= ?";
        $args = [$nowStr];
        if ($onlyChannel !== null) { $sql .= " AND channel = ?"; $args[] = $onlyChannel; }
        $sql .= " ORDER BY scheduled_send_at ASC LIMIT {$limit}";
        $rows = db_fetch_all($sql, $args);
    } catch (Exception $e) {
        return ['considered' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0, 'deferred' => 0];
    }

    foreach ($rows as $r) {
        // Out of budget. The remaining rows stay pending, which is the whole
        // point: unfinished work waits in a place an operator can see, it is
        // never dropped and it never extends the caller's request.
        //
        // The margin matters. Stopping only once the deadline has actually
        // passed lets a row start with a sliver of budget left, and every
        // timeout downstream has a one-second floor (zero means "no timeout"
        // to both cURL and Guzzle), so a row begun at the last moment can
        // still overrun by seconds. Refuse to start one we cannot finish.
        if ($deadline !== null && ($deadline - microtime(true)) < 0.75) {
            $deferred = count($rows) - $considered;
            break;
        }
        $considered++;

        // ── Stale-work cutoff ────────────────────────────────────────────
        // Too far past due to deliver as if it were current. Expire it,
        // recording why, and move on. Never delete: the message is user
        // data and the decision must be auditable and reversible.
        $dueTs = strtotime((string) $r['scheduled_send_at']);
        if ($dueTs && sched_is_stale($dueTs, $now, $cutoffMin)) {
            $reason = sched_expiry_reason((string) $r['scheduled_send_at'], $dueTs, $now, $cutoffMin);
            try {
                db_query("UPDATE `{$prefix}pending_routed_messages`
                             SET status = 'expired', send_error = ?
                           WHERE id = ? AND status = 'pending'", [substr($reason, 0, 255), $r['id']]);
                $expired++;
                if (function_exists('audit_log')) {
                    audit_log('routing', 'expire', 'pending_message', (int) $r['id'],
                        "Not delivered — {$reason}", [
                            'channel'           => $r['channel'],
                            'target'            => $r['target'],
                            'scheduled_send_at' => $r['scheduled_send_at'],
                            'ticket_id'         => $r['ticket_id'],
                        ]);
                }
                if (defined('NOTIFY_RULE_CHANNEL') && $r['channel'] === NOTIFY_RULE_CHANNEL
                    && function_exists('notification_delivery_mark_unsent')) {
                    notification_delivery_mark_unsent((int) $r['id'], 'not delivered - ' . $reason, 'failed');
                }
            } catch (Exception $e) {
                error_log('pending_sweep expire failed for #' . $r['id'] . ': ' . $e->getMessage());
            }
            continue;
        }

        $ok = false;
        $err = null;
        // A retryable failure keeps the row PENDING rather than marking it
        // failed. A notification that could not go out because the internet
        // was down for ninety seconds must still go out; the stale cutoff
        // above is what bounds the retrying, so this cannot loop forever.
        $retryable = false;

        // ── Audit-driven notification fan-out ────────────────────────────
        if (defined('NOTIFY_FANOUT_CHANNEL') && $r['channel'] === NOTIFY_FANOUT_CHANNEL) {
            // Consult the breaker ONCE per sweep, not once per row. During an
            // outage a busy board can queue hundreds of these, and without
            // this the sweep would walk the entire backlog paying a full
            // timeout for each one, every minute, for the whole outage. The
            // half-open window is what lets exactly one tick probe and find
            // the link back.
            if ($breakerOpen === null) {
                $breakerOpen = function_exists('notify_breaker_check')
                    ? notify_breaker_check($now) : ['open' => false];
            }
            if (!empty($breakerOpen['open'])) {
                $deferred++;
                $considered--;   // not considered: we did not attempt it
                continue;
            }
            if (!function_exists('notify_fanout_replay')) {
                $err = 'notify_fanout_replay() not loaded';
            } else {
                $remaining = $deadline !== null ? max(0.5, $deadline - microtime(true)) : null;
                $res = notify_fanout_replay($r, $remaining);
                $ok  = !empty($res['ok']);
                if (!$ok) {
                    $err = $res['error'] !== '' ? $res['error'] : 'delivery failed';
                    // Unreadable rows are permanent; everything else is the
                    // network, and the network comes back.
                    $retryable = empty($res['permanent']);
                    if (function_exists('notify_breaker_record_failure')) {
                        notify_breaker_record_failure($err, $now);
                    }
                } elseif (function_exists('notify_breaker_record_success')) {
                    notify_breaker_record_success();
                }
            }
            try {
                if ($ok) {
                    db_query("UPDATE `{$prefix}pending_routed_messages`
                                 SET status = 'sent', sent_at = NOW(), send_error = NULL
                               WHERE id = ?", [$r['id']]);
                    $sent++;
                } elseif ($retryable) {
                    db_query("UPDATE `{$prefix}pending_routed_messages`
                                 SET send_error = ? WHERE id = ?",
                             [substr('retrying: ' . ($err ?? 'unknown'), 0, 255), $r['id']]);
                    $failed++;
                } else {
                    db_query("UPDATE `{$prefix}pending_routed_messages`
                                 SET status = 'failed', send_error = ?
                               WHERE id = ?", [substr($err ?? 'unknown', 0, 255), $r['id']]);
                    $failed++;
                }
            } catch (Exception $e) {}
            continue;
        }

        // ── Notification Rule delivery (Phase 155, GH#144) ───────────────
        // One code path for the timer sweep and the synchronous caller:
        // notification_delivery_process_row() does the per-channel breaker, the
        // replay and the queue row's own bookkeeping.
        if (defined('NOTIFY_RULE_CHANNEL') && $r['channel'] === NOTIFY_RULE_CHANNEL) {
            if (!function_exists('notification_delivery_process_row')) {
                try {
                    db_query("UPDATE `{$prefix}pending_routed_messages`
                                 SET status = 'failed', send_error = ? WHERE id = ?",
                             ['notification_delivery_process_row() not loaded', $r['id']]);
                } catch (Exception $e) {}
                $failed++;
                continue;
            }
            $remaining = $deadline !== null ? max(0.5, $deadline - microtime(true)) : null;
            $res = notification_delivery_process_row($r, $remaining, $ruleState, $now);
            if ($res['outcome'] === 'sent') {
                $sent++;
            } elseif ($res['outcome'] === 'deferred') {
                $deferred++;
                $considered--;   // not considered: we did not attempt it
            } else {
                $failed++;
            }
            continue;
        }

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
    return ['considered' => $considered, 'sent' => $sent,
            'failed' => $failed, 'expired' => $expired,
            'deferred' => max(0, $deferred)];
}
