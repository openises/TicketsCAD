<?php
/**
 * Phase 155 (GH#144) - how Notification Rule deliveries leave the building.
 *
 * THE RULE THIS FILE ENFORCES (the same one inc/notify-fanout.php enforces for
 * webhooks and push): a dispatch action never waits on the internet.
 *
 * The engine used to call broker_send() INSIDE the dispatcher's own request -
 * one blocking SMTP session per recipient. With a 100-address list and a dead
 * mail relay that is minutes of a held PHP worker for a single "create
 * incident", the exact 21-second-per-action stall notify-fanout.php measured
 * and removed on 2026-07-31. Now:
 *
 *   engine ---- one notification_log row per delivery (status `queued`)
 *          \--- one pending_routed_messages row per queued delivery,
 *               channel NOTIFY_RULE_CHANNEL, body {"v":1,"log_id":N,...}
 *
 *   the scheduled sweep (tools/pending_messages_tick.php -> pending_sweep())
 *   replays each row through notification_delivery_process_row().
 *
 * WHY A LOG ROW AND A QUEUE ROW. The log row is the single source of truth for
 * WHAT is sent (subject, body, channel, recipient) and what became of it; the
 * queue row only says "send log row N, to this address, now". So the log shows
 * an operator the truth even if a queue row is later killed or expires, and a
 * replay is idempotent: a log row already `sent` is never sent again.
 *
 * NO SCHEDULER. Installs without a timer (the Docker stack; both live hosts in
 * July 2026) are handled exactly as the fan-out handles them: when the sweep
 * has not run recently, notification_delivery_kick() makes ONE bounded inline
 * attempt (notify_inline_budget_s(), adapters clamp their timeouts to what is
 * left of it) behind a circuit breaker - per CHANNEL here, because one dead
 * SMTP relay must not pause a working Slack webhook or Web Push (which is how
 * a volunteer's phone is reached).
 *
 * TRANSIENT vs PERMANENT. A network failure keeps the queue row pending and is
 * retried until the stale cutoff (sched_stale_cutoff_min) expires it - a
 * callout that arrives two hours late is worse than none. A configuration
 * failure (channel not configured, no such address, 4xx from the provider) is
 * marked failed at once: retrying cannot fix it, and a failure that never goes
 * red is a failure nobody fixes.
 */

require_once __DIR__ . '/notify-fanout.php';

/**
 * Queue channel for a Notification Rule delivery. Deliberately not a broker
 * channel name (same convention as NOTIFY_FANOUT_CHANNEL): pending_sweep()
 * recognises it and replays the delivery rather than handing the row to
 * broker_send(). The leading underscore keeps it out of any namespace an
 * administrator can configure.
 */
if (!defined('NOTIFY_RULE_CHANNEL')) {
    define('NOTIFY_RULE_CHANNEL', '_notify_rule');
}

/** Setting holding the per-channel breaker state (a JSON map: channel => counters). */
if (!defined('NOTIFY_RULE_BREAKER_SETTING')) {
    define('NOTIFY_RULE_BREAKER_SETTING', 'notify_rule_breaker');
}

// ─────────────────────────────────────────────────────────────────────────
// Persisting a plan
// ─────────────────────────────────────────────────────────────────────────

/**
 * Write a planned set of deliveries: one log row each; one queue row per
 * deliverable one. Returns one result row per delivery.
 *
 * If the queue cannot be written (a pre-migration install, or the table is
 * gone) the delivery is NOT dropped: it is attempted inline, bounded and behind
 * the per-channel breaker, and the log row records what happened. A
 * notification matters more than the stall - but never an unbounded one.
 *
 * @return array<int,array>
 */
function notification_delivery_persist(array $rule, array $ctx, array $plan): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $out = [];
    $event = (string) $rule['event_type'];
    $tid = (int) ($ctx['ticket_id'] ?? 0);

    foreach ($plan['deliveries'] as $d) {
        $base = [
            'rule_id' => (int) $rule['id'], 'event_type' => $event, 'ticket_id' => $tid > 0 ? $tid : null,
            'channel' => (string) $d['channel'], 'recipient' => (string) $d['address'],
            'subject' => (string) $d['subject'], 'body' => (string) $d['body'],
        ];
        $res = ['rule_id' => (int) $rule['id'], 'channel' => (string) $d['channel'],
                'recipient' => (string) $d['address'], 'success' => false, 'error' => null,
                'queue_id' => null, 'log_id' => null];

        if ($d['status'] === 'skipped') {
            $res['log_id'] = notification_log_insert($base + ['status' => 'skipped', 'error' => (string) $d['reason']]);
            $res['status'] = 'skipped';
            $res['error'] = (string) $d['reason'];
            $out[] = $res;
            continue;
        }

        $logId = notification_log_insert($base + ['status' => 'queued', 'error' => null]);
        $res['log_id'] = $logId;
        $qid = null;
        if ($logId && function_exists('pending_enqueue')) {
            $payload = json_encode([
                'v' => 1, 'log_id' => $logId, 'channel' => (string) $d['channel'],
                'to' => $d['to'], 'user_id' => $d['user_id'],
                'priority' => (string) $d['priority'], 'content_type' => (string) $d['content_type'],
            ]);
            $qid = pending_enqueue([
                'ticket_id'         => $tid > 0 ? $tid : null,
                'route_id'          => null,
                'channel'           => NOTIFY_RULE_CHANNEL,
                'target'            => substr($d['channel'] . ':' . $d['address'], 0, 255),
                'subject'           => substr((string) $d['subject'], 0, 255),
                'body'              => $payload,
                'priority'          => (string) $d['priority'],
                'scheduled_send_at' => $d['scheduled_send_at'] ?? date('Y-m-d H:i:s'),
                'created_by'        => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ]);
            if ($qid) {
                try {
                    db_query("UPDATE `{$prefix}notification_log` SET `queue_id` = ? WHERE `id` = ?", [$qid, $logId]);
                } catch (\Throwable $e) {
                    // queue_id is a join aid for the log viewer; a missing column
                    // (pre-migration) must not cost the delivery.
                    error_log('[notification-delivery] queue_id link failed: ' . $e->getMessage());
                }
            }
        }

        if (!$qid) {
            // No queue to hand this to. Deliver now, bounded.
            $payloadNow = ['channel' => (string) $d['channel'], 'to' => $d['to'], 'user_id' => $d['user_id'],
                           'priority' => (string) $d['priority'], 'content_type' => (string) $d['content_type']];
            $sent = notification_delivery_send_inline($payloadNow, (string) $d['subject'], (string) $d['body']);
            if ($logId) {
                try {
                    db_query("UPDATE `{$prefix}notification_log` SET `status` = ?, `error` = ?, `sent_at` = NOW() WHERE `id` = ?",
                        [$sent['ok'] ? 'sent' : 'failed', $sent['ok'] ? null : $sent['error'], $logId]);
                } catch (\Throwable $e) { error_log('[notification-delivery] log update failed: ' . $e->getMessage()); }
            }
            $res['status'] = $sent['ok'] ? 'sent' : 'failed';
            $res['success'] = $sent['ok'];
            $res['error'] = $sent['ok'] ? null : $sent['error'];
            $out[] = $res;
            continue;
        }

        $res['status'] = 'queued';
        $res['queue_id'] = $qid;
        $out[] = $res;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Sending
// ─────────────────────────────────────────────────────────────────────────

/**
 * Hand one message to the broker under an optional wall-clock budget.
 *
 * The outbound adapters read notify_deadline_remaining() and clamp their own
 * timeouts to it (inc/channels/{smtp,sms,slack,telegram,push}.php). Marking the
 * fan-out as "draining" for the duration stops a routing:forwarded SSE event
 * published from inside broker_send() from re-entering the fan-out and opening
 * a second socket under the same budget.
 *
 * @return array{success:bool,error:?string}
 */
function notification_deliver_message(string $channel, array $message, ?float $budgetS = null): array
{
    if (!function_exists('broker_send')) require_once __DIR__ . '/broker.php';
    if (is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';

    $prev = $GLOBALS['_notify_fanout_draining'] ?? false;
    $GLOBALS['_notify_fanout_draining'] = true;
    if ($budgetS !== null) notify_deadline_set($budgetS);
    try {
        $r = broker_send($channel, $message);
    } catch (\Throwable $e) {
        $r = ['success' => false, 'error' => $e->getMessage()];
    }
    if ($budgetS !== null) notify_deadline_clear();
    $GLOBALS['_notify_fanout_draining'] = $prev;
    return ['success' => !empty($r['success']), 'error' => isset($r['error']) ? (string) $r['error'] : null];
}

/** Build the broker message from a queue payload + the log row's text. */
function notification_delivery_message(array $payload, string $subject, string $body): array
{
    $m = [
        'to'       => $payload['to'] ?? 'all',
        'subject'  => $subject,
        'body'     => $body,
        'type'     => 'notification',
        'priority' => ($payload['priority'] ?? 'normal') ?: 'normal',
    ];
    if (!empty($payload['content_type'])) $m['content_type'] = (string) $payload['content_type'];
    // push.php does not read `to`: it requires the resolved user-id list the
    // routing engine's predicate resolver normally produces (GH #84).
    if (($payload['channel'] ?? '') === 'push' && !empty($payload['user_id'])) {
        $m['_recipient_user_ids'] = [(int) $payload['user_id']];
    }
    return $m;
}

/**
 * Deliver one message NOW, bounded, behind the per-channel breaker. Used only
 * when there is no queue to hand it to.
 *
 * @return array{ok:bool,error:string}
 */
function notification_delivery_send_inline(array $payload, string $subject, string $body): array
{
    $channel = (string) ($payload['channel'] ?? '');
    $b = notification_breaker_check($channel);
    if ($b['open']) {
        error_log('[notification-delivery] no queue and breaker open - ' . $channel . ' not delivered');
        return ['ok' => false, 'error' => 'no queue available and delivery on ' . $channel . ' is paused: ' . $b['reason']];
    }
    $r = notification_deliver_message($channel, notification_delivery_message($payload, $subject, $body),
        (float) notify_inline_budget_s());
    if ($r['success']) { notification_breaker_record_success($channel); return ['ok' => true, 'error' => '']; }
    $err = (string) ($r['error'] ?? 'delivery failed');
    if (!notification_error_is_permanent($err)) notification_breaker_record_failure($channel, $err);
    return ['ok' => false, 'error' => $err];
}

/**
 * Will retrying this failure ever help? Pure.
 *
 * Permanent: the channel or its provider is not configured, a required value is
 * missing, the address is not valid, the provider answered 4xx (other than 408 /
 * 429), the SMTP server answered 5xx or rejected our login, or local mail() is
 * not configured. Everything else - connection failures, timeouts, 5xx from an
 * HTTP provider, SMTP 4xx - is the network, and the network comes back.
 */
function notification_error_is_permanent(string $error): bool
{
    $e = strtolower($error);
    foreach ([
        'not configured', 'required', 'unknown channel', 'unknown email mode', 'unknown sms provider',
        'unknown slack mode', 'does not support', 'no user ids resolved', 'not loaded',
        'no sms-capable', 'authentication failed', 'mail() failed', 'must be', 'invalid',
    ] as $needle) {
        if (strpos($e, $needle) !== false) return true;
    }
    if (preg_match('/(?:send failed|recipient rejected): 5\d\d/', $e)) return true;
    if (preg_match('/\bhttp (400|401|402|403|404|405|406|410|413|415|422)\b/', $e)) return true;
    return false;
}

// ─────────────────────────────────────────────────────────────────────────
// Replay (the sweep's half)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Replay one queued delivery. The queue row carries the routing facts; the
 * notification_log row carries the text and is updated with the outcome.
 *
 * Idempotent: a log row already `sent` is reported ok without sending again,
 * so a sweep killed between the send and its own bookkeeping, then re-run,
 * does not double-send.
 *
 * @return array{ok:bool,error:string,permanent:bool,channel:string}
 */
function notification_delivery_replay(array $row, ?float $budgetS = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $payload = json_decode((string) ($row['body'] ?? ''), true);
    if (!is_array($payload) || empty($payload['log_id'])) {
        return ['ok' => false, 'error' => 'unreadable notification payload', 'permanent' => true, 'channel' => ''];
    }
    $channel = (string) ($payload['channel'] ?? '');
    try {
        $log = db_fetch_one("SELECT * FROM `{$prefix}notification_log` WHERE `id` = ?", [(int) $payload['log_id']]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'could not read the notification log', 'permanent' => false, 'channel' => $channel];
    }
    if (!$log) return ['ok' => false, 'error' => 'the log row for this delivery no longer exists', 'permanent' => true, 'channel' => $channel];
    if ($log['status'] === 'sent') return ['ok' => true, 'error' => '', 'permanent' => false, 'channel' => $channel];
    if ($log['status'] !== 'queued') {
        return ['ok' => false, 'error' => 'delivery is already ' . $log['status'], 'permanent' => true, 'channel' => $channel];
    }

    $r = notification_deliver_message($channel,
        notification_delivery_message($payload, (string) $log['subject'], (string) $log['body']), $budgetS);

    try {
        if ($r['success']) {
            db_query("UPDATE `{$prefix}notification_log` SET `status` = 'sent', `error` = NULL, `sent_at` = NOW() WHERE `id` = ?",
                [(int) $log['id']]);
            return ['ok' => true, 'error' => '', 'permanent' => false, 'channel' => $channel];
        }
        $err = (string) ($r['error'] ?? 'delivery failed');
        $perm = notification_error_is_permanent($err);
        if ($perm) {
            db_query("UPDATE `{$prefix}notification_log` SET `status` = 'failed', `error` = ?, `sent_at` = NOW() WHERE `id` = ?",
                [substr($err, 0, 1000), (int) $log['id']]);
        } else {
            db_query("UPDATE `{$prefix}notification_log` SET `error` = ? WHERE `id` = ?",
                [substr('retrying: ' . $err, 0, 1000), (int) $log['id']]);
        }
        return ['ok' => false, 'error' => $err, 'permanent' => $perm, 'channel' => $channel];
    } catch (\Throwable $e) {
        error_log('[notification-delivery] log update failed: ' . $e->getMessage());
        return ['ok' => $r['success'], 'error' => (string) ($r['error'] ?? ''), 'permanent' => false, 'channel' => $channel];
    }
}

/**
 * Process one pending_routed_messages row of channel NOTIFY_RULE_CHANNEL:
 * the per-channel short-circuits, the replay, and the queue row's own
 * bookkeeping. The ONE function both the timer sweep (pending_sweep()) and the
 * synchronous path (notification_check()) go through.
 *
 * $state is per-sweep scratch the caller keeps across rows:
 *   ['defer' => [channel => true], 'fails' => [channel => consecutive transient failures]]
 * Two consecutive transient failures on a channel defer that channel's
 * REMAINING rows for the rest of this sweep (a 100-row list on a dead relay
 * pays two timeouts, not a hundred).
 *
 * @return array{outcome:string,error:string} outcome: sent | failed | deferred
 */
function notification_delivery_process_row(array $row, ?float $budgetS, array &$state, ?int $now = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = $now ?? time();
    $payload = json_decode((string) ($row['body'] ?? ''), true);
    $channel = is_array($payload) ? (string) ($payload['channel'] ?? '') : '';

    if ($channel !== '') {
        if (!empty($state['defer'][$channel])) return ['outcome' => 'deferred', 'error' => ''];
        $b = notification_breaker_check($channel, $now);
        if ($b['open']) {
            $state['defer'][$channel] = true;
            return ['outcome' => 'deferred', 'error' => $b['reason']];
        }
    }

    $res = notification_delivery_replay($row, $budgetS);
    $err = $res['error'];

    try {
        if ($res['ok']) {
            db_query("UPDATE `{$prefix}pending_routed_messages` SET `status` = 'sent', `sent_at` = NOW(), `send_error` = NULL WHERE `id` = ?",
                [(int) $row['id']]);
            if ($channel !== '') { notification_breaker_record_success($channel); $state['fails'][$channel] = 0; }
            return ['outcome' => 'sent', 'error' => ''];
        }
        if ($res['permanent']) {
            db_query("UPDATE `{$prefix}pending_routed_messages` SET `status` = 'failed', `send_error` = ? WHERE `id` = ?",
                [substr($err, 0, 255), (int) $row['id']]);
            return ['outcome' => 'failed', 'error' => $err];
        }
        // Transient: stays pending, retried until the stale cutoff expires it.
        db_query("UPDATE `{$prefix}pending_routed_messages` SET `send_error` = ? WHERE `id` = ?",
            [substr('retrying: ' . $err, 0, 255), (int) $row['id']]);
    } catch (\Throwable $e) {
        error_log('[notification-delivery] queue bookkeeping failed: ' . $e->getMessage());
    }
    if ($channel !== '') {
        notification_breaker_record_failure($channel, $err, $now);
        $state['fails'][$channel] = (int) ($state['fails'][$channel] ?? 0) + 1;
        if ($state['fails'][$channel] >= 2) $state['defer'][$channel] = true;
    }
    return ['outcome' => 'failed', 'error' => $err];
}

/**
 * The synchronous path: process exactly one queue row by id (used by
 * notification_check() and tests). A row whose scheduled time has not come
 * (a security-label send delay) is left alone - the delay is the policy.
 *
 * @return array{outcome:string,error:string} outcome: sent | failed | queued
 */
function notification_delivery_process_queue_id(int $queueId, ?float $budgetS = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}pending_routed_messages`
              WHERE `id` = ? AND `channel` = ? AND `status` = 'pending'", [$queueId, NOTIFY_RULE_CHANNEL]);
    } catch (\Throwable $e) {
        return ['outcome' => 'failed', 'error' => 'queue unreadable'];
    }
    if (!$row) return ['outcome' => 'failed', 'error' => 'queue row not pending'];
    if (strtotime((string) $row['scheduled_send_at']) > time()) {
        return ['outcome' => 'queued', 'error' => 'held for a security-label send delay'];
    }
    $state = ['defer' => [], 'fails' => []];
    $r = notification_delivery_process_row($row, $budgetS, $state);
    if ($r['outcome'] === 'deferred') return ['outcome' => 'queued', 'error' => $r['error']];
    return $r;
}

/**
 * After the request has queued its deliveries: deliver them if nothing else
 * will. If the scheduled sweep has run recently, return at once - ZERO network
 * on the dispatch path, the timer owns delivery. Otherwise make a bounded
 * inline attempt so an install with no scheduler does not silently stop
 * notifying.
 */
function notification_delivery_kick(): void
{
    if (!empty($GLOBALS['_notify_fanout_draining'])) return;   // inside a drain already
    if (notify_scheduler_is_live()) return;
    notification_delivery_drain((float) notify_inline_budget_s(), 25);
}

/** Drain queued rule deliveries within a budget: one sweep, one cutoff contract. */
function notification_delivery_drain(?float $budgetS = null, int $limit = 200, ?int $now = null): array
{
    if (!function_exists('pending_sweep')) {
        return ['considered' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0, 'deferred' => 0];
    }
    return pending_sweep($now, null, $limit, $budgetS, NOTIFY_RULE_CHANNEL);
}

/**
 * A queue row that will never be sent (killed during a security-label delay, or
 * expired by the stale cutoff): the log row must say so, or it reads `queued`
 * for ever and "once per incident" would believe someone had been told.
 *
 * @param string $status 'failed' (expired) | 'skipped' (killed)
 */
function notification_delivery_mark_unsent(int $queueId, string $reason, string $status): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("UPDATE `{$prefix}notification_log` SET `status` = ?, `error` = ?
                   WHERE `queue_id` = ? AND `status` = 'queued'",
            [$status, substr($reason, 0, 1000), $queueId]);
    } catch (\Throwable $e) {
        error_log('[notification-delivery] mark-unsent failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Per-channel circuit breaker
// ─────────────────────────────────────────────────────────────────────────
//
// inc/notify-fanout.php's breaker is ONE counter for webhooks + push. Sharing it
// would let a dead SMTP relay pause Web Push (how a volunteer's phone is
// reached), so the rule deliveries keep their own, per channel, with the same
// pure state machine (notify_breaker_decide) and the same thresholds. Read
// directly from `settings`, not get_variable(), for the reason notify-fanout.php
// documents: a counter read from a frozen snapshot never reaches its threshold.

function _notification_breaker_all(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $raw = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1", [NOTIFY_RULE_BREAKER_SETTING]);
    } catch (\Throwable $e) { return []; }
    if ($raw === false || $raw === null || $raw === '') return [];
    $d = json_decode((string) $raw, true);
    return is_array($d) ? $d : [];
}

function _notification_breaker_save(array $all): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $json = json_encode($all);
    try {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [NOTIFY_RULE_BREAKER_SETTING]);
        if ($n > 0) db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?", [$json, NOTIFY_RULE_BREAKER_SETTING]);
        else db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [NOTIFY_RULE_BREAKER_SETTING, $json]);
    } catch (\Throwable $e) {
        error_log('[notification-delivery] breaker write failed: ' . $e->getMessage());
    }
}

function _notification_breaker_state(array $all, string $channel): array
{
    $s = $all[$channel] ?? [];
    return [
        'fails' => max(0, (int) ($s['fails'] ?? 0)), 'opened_at' => (int) ($s['opened_at'] ?? 0),
        'last_error' => substr((string) ($s['last_error'] ?? ''), 0, 180),
        'last_fail_at' => (int) ($s['last_fail_at'] ?? 0),
    ];
}

/** Request-path gate: decision + the half-open re-stamp (one probe at a time). */
function notification_breaker_check(string $channel, ?int $now = null): array
{
    $now = $now ?? time();
    $all = _notification_breaker_all();
    $st = _notification_breaker_state($all, $channel);
    $d = notify_breaker_decide($st, $now, notify_breaker_threshold(), notify_breaker_cooloff_s());
    if ($d['half_open']) {
        $st['opened_at'] = $now;
        $all[$channel] = $st;
        _notification_breaker_save($all);
    }
    return $d;
}

function notification_breaker_record_failure(string $channel, string $error, ?int $now = null): void
{
    $now = $now ?? time();
    $all = _notification_breaker_all();
    $st = _notification_breaker_state($all, $channel);
    $st['fails'] += 1;
    $st['last_error'] = substr((string) preg_replace('/[^\x20-\x7E]/', '', $error), 0, 180);
    $st['last_fail_at'] = $now;
    if ($st['fails'] >= notify_breaker_threshold() && $st['opened_at'] === 0) $st['opened_at'] = $now;
    $all[$channel] = $st;
    _notification_breaker_save($all);
}

function notification_breaker_record_success(string $channel): void
{
    $all = _notification_breaker_all();
    if (!isset($all[$channel])) return;
    $st = _notification_breaker_state($all, $channel);
    if ($st['fails'] === 0 && $st['opened_at'] === 0) return;
    unset($all[$channel]);
    _notification_breaker_save($all);
}

function notification_breaker_reset(?string $channel = null): void
{
    if ($channel === null) { _notification_breaker_save([]); return; }
    $all = _notification_breaker_all();
    unset($all[$channel]);
    _notification_breaker_save($all);
}

/** Read-only view for the panel's status strip and tests: channel => decision. */
function notification_breaker_status(?int $now = null): array
{
    $now = $now ?? time();
    $all = _notification_breaker_all();
    $out = [];
    foreach ($all as $channel => $_) {
        $st = _notification_breaker_state($all, $channel);
        $d = notify_breaker_decide($st, $now, notify_breaker_threshold(), notify_breaker_cooloff_s());
        $d['last_error'] = $st['last_error'];
        $out[$channel] = $d;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Retention
// ─────────────────────────────────────────────────────────────────────────

/**
 * Delete delivery-log rows older than the retention setting
 * (notification_log_retention_days; 0 keeps everything). A row still `queued`
 * is live queue state and is never purged. Batched so a large backlog never
 * holds a long lock. Called from tools/pending_messages_tick.php.
 *
 * @return int rows deleted
 */
function notification_log_purge(?int $now = null): int
{
    $days = (int) notification_setting('notification_log_retention_days');
    if ($days <= 0) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $cutoff = date('Y-m-d H:i:s', ($now ?? time()) - $days * 86400);
    $total = 0;
    try {
        for ($i = 0; $i < 20; $i++) {
            $stmt = db_query(
                "DELETE FROM `{$prefix}notification_log`
                  WHERE `sent_at` < ? AND `status` <> 'queued' LIMIT 1000", [$cutoff]);
            $n = $stmt->rowCount();
            $total += $n;
            if ($n < 1000) break;
        }
    } catch (\Throwable $e) {
        error_log('[notification-delivery] log purge failed: ' . $e->getMessage());
    }
    return $total;
}
