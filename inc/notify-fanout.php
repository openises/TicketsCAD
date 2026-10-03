<?php
/**
 * NewUI v4.0 — the notification fan-out is not allowed to stall a dispatch.
 * 2026-07-31.
 *
 * WHAT WAS WRONG
 * --------------
 * Every state change a dispatcher makes writes an audit row, and audit_log()
 * used to fan that row out to Web Push, webhooks, SMS, e-mail and Slack
 * INSIDE the same request. So the dispatcher's screen did not come back until
 * every one of those had either answered or timed out.
 *
 * Measured on 2026-07-31, driving the real writers (incident_create_internal,
 * responder_set_status_internal) with Web Push enabled and every endpoint
 * pointed at 203.0.113.1 — RFC 5737 TEST-NET-3, which is black-holed, so
 * packets vanish exactly the way they do in an upstream outage:
 *
 *     create an incident ........... 21.34 s
 *     change a unit's status ....... 21.33 s
 *
 * Twenty-one seconds, per action, for the whole outage — and each of those
 * seconds is a PHP worker held open. A few dispatchers working through a
 * storm can exhaust a small server's request slots and take the CAD down
 * during the event it exists for. Web Push is off by default, but it is
 * precisely the feature a volunteer agency turns on, because it is how a
 * callout reaches a member's phone.
 *
 * THE RULE THIS FILE ENFORCES
 * ---------------------------
 * A dispatch action never waits on the internet. The fan-out is written to a
 * queue — one INSERT — and delivered by the scheduled job that already
 * exists for exactly this purpose (tools/pending_messages_tick.php, driven by
 * a systemd timer). Nothing is dropped: a notification that cannot be sent
 * stays in the queue as a row an operator can read, and the queue's depth is
 * what makes the Status page go red.
 *
 * WHY THERE IS STILL AN INLINE PATH
 * ---------------------------------
 * Because on this project, assuming a scheduler exists is how a job sat dead
 * for seven weeks (see inc/scheduled-jobs.php). Neither of the two servers
 * running TicketsCAD in July 2026 had a cron daemon at all. If "queue it and
 * trust the timer" were the whole design, every install without a timer would
 * quietly stop notifying anybody — a worse failure than the one being fixed,
 * because it is silent.
 *
 * So delivery is chosen by asking the database what is actually true:
 *
 *   the sweep has run recently  → queue and return. ZERO network on the
 *                                 dispatch path. The timer owns delivery.
 *   the sweep is not running    → queue, then make ONE best-effort attempt
 *                                 bounded by a hard wall-clock budget and
 *                                 guarded by a circuit breaker, so a dead
 *                                 network costs one short probe per cool-off
 *                                 instead of 21 s per dispatch.
 *
 * Either way the row is written FIRST. The attempt is an optimisation, never
 * the record.
 */

require_once __DIR__ . '/scheduled-jobs.php';
if (is_file(__DIR__ . '/pending-messages.php')) {
    require_once __DIR__ . '/pending-messages.php';
}

/**
 * Queue channel for an audit-driven fan-out.
 *
 * Deliberately not a broker channel name: pending_sweep() recognises it and
 * replays the event, rather than handing it to broker_send(). The leading
 * underscore keeps it out of any namespace an administrator can configure.
 */
if (!defined('NOTIFY_FANOUT_CHANNEL')) {
    define('NOTIFY_FANOUT_CHANNEL', '_notify_fanout');
}

/** Wall-clock budget, seconds, for the best-effort inline attempt. */
if (!defined('NOTIFY_INLINE_BUDGET_DEFAULT_S')) {
    define('NOTIFY_INLINE_BUDGET_DEFAULT_S', 3);
}

/** Consecutive failed attempts before the outbound breaker opens. */
if (!defined('NOTIFY_BREAKER_THRESHOLD_DEFAULT')) {
    define('NOTIFY_BREAKER_THRESHOLD_DEFAULT', 2);
}

/** How long the breaker stays open before one attempt is allowed through. */
if (!defined('NOTIFY_BREAKER_COOLOFF_DEFAULT_S')) {
    define('NOTIFY_BREAKER_COOLOFF_DEFAULT_S', 60);
}

/** Settings key holding the breaker's counters. */
if (!defined('NOTIFY_BREAKER_SETTING')) {
    define('NOTIFY_BREAKER_SETTING', 'notify_outbound_breaker');
}

/** Largest fan-out payload we will store. `body` is TEXT (64 KB). */
if (!defined('NOTIFY_PAYLOAD_MAX_BYTES')) {
    define('NOTIFY_PAYLOAD_MAX_BYTES', 60000);
}

// ─────────────────────────────────────────────────────────────────────────
// Settings
// ─────────────────────────────────────────────────────────────────────────

/**
 * Read an integer setting from the `settings` table.
 *
 * get_variable(), NOT get_setting(). They are two different tables and
 * crossing them makes an admin toggle read as its default forever — see
 * "TWO settings stores" in CLAUDE.md.
 */
function _notify_setting_int(string $key, int $default): int
{
    if (!function_exists('get_variable')) return $default;
    try { $v = get_variable($key); } catch (Exception $e) { return $default; }
    if ($v === false || $v === null || $v === '') return $default;
    $n = (int) $v;
    return $n < 0 ? $default : $n;
}

function notify_inline_budget_s(): int
{
    return _notify_setting_int('notify_inline_budget_s', NOTIFY_INLINE_BUDGET_DEFAULT_S);
}

function notify_breaker_threshold(): int
{
    return _notify_setting_int('notify_breaker_threshold', NOTIFY_BREAKER_THRESHOLD_DEFAULT);
}

function notify_breaker_cooloff_s(): int
{
    return _notify_setting_int('notify_breaker_cooloff_s', NOTIFY_BREAKER_COOLOFF_DEFAULT_S);
}

// ─────────────────────────────────────────────────────────────────────────
// Deadline — a budget the outbound calls themselves can read
// ─────────────────────────────────────────────────────────────────────────
//
// A budget nobody enforces is a comment. These three functions are the
// contract: whoever is about to make an outbound call asks how much time is
// left and clamps its own timeout to it. inc/channels/push.php and
// inc/webhooks.php both do. Absent a deadline (the timer-driven sweep, a CLI
// tool) notify_deadline_remaining() returns null and nothing is clamped.

function notify_deadline_set(float $seconds): void
{
    $GLOBALS['_notify_deadline_ts'] = microtime(true) + max(0.0, $seconds);
}

function notify_deadline_clear(): void
{
    unset($GLOBALS['_notify_deadline_ts']);
}

/** Seconds left, or null when no deadline is in force. Never negative. */
function notify_deadline_remaining(): ?float
{
    if (!isset($GLOBALS['_notify_deadline_ts'])) return null;
    return max(0.0, (float) $GLOBALS['_notify_deadline_ts'] - microtime(true));
}

function notify_deadline_expired(): bool
{
    $r = notify_deadline_remaining();
    return $r !== null && $r <= 0.05;
}

/**
 * Clamp a timeout to whatever budget is left.
 *
 * PURE, so the arithmetic is testable without a clock. Returns at least
 * $floor: a timeout of 0 means "no timeout" to both cURL and Guzzle, which
 * would turn a budget into the exact unbounded call this file exists to
 * prevent. That inversion is the whole reason this is its own function.
 */
function notify_clamp_timeout(int $configured, ?float $remaining, int $floor = 1): int
{
    if ($remaining === null) return $configured;
    $r = (int) floor($remaining);
    if ($r < $floor) return $floor;
    return min($configured, $r);
}

/**
 * The timeout an outbound ADAPTER should use for a send: its own configured
 * value, clamped to whatever is left of the caller's wall-clock budget (none =
 * unclamped). Phase 155 (GH#144): the Notification Rule deliveries run under a
 * budget when they are attempted inline, and smtp / sms / slack / telegram
 * used fixed 10-15 s timeouts that ignored it - the same defect push.php fixed
 * on 2026-07-31. Adapters call this behind function_exists() so they still work
 * when this file is not loaded.
 */
function notify_adapter_timeout(int $configured): int
{
    return notify_clamp_timeout($configured, notify_deadline_remaining());
}

// ─────────────────────────────────────────────────────────────────────────
// Circuit breaker
// ─────────────────────────────────────────────────────────────────────────

/**
 * PURE breaker decision, so the state machine can be tested without a
 * database or a network.
 *
 *   closed     fails < threshold                    → attempt
 *   open       fails >= threshold, within cool-off   → do not attempt
 *   half-open  fails >= threshold, cool-off elapsed  → attempt once
 *
 * @return array{open:bool,half_open:bool,retry_in:int,fails:int,reason:string}
 */
function notify_breaker_decide(array $state, int $now, int $threshold, int $cooloff): array
{
    $fails    = max(0, (int) ($state['fails'] ?? 0));
    $openedAt = (int) ($state['opened_at'] ?? 0);
    $lastErr  = (string) ($state['last_error'] ?? '');

    if ($threshold <= 0 || $fails < $threshold) {
        return ['open' => false, 'half_open' => false, 'retry_in' => 0,
                'fails' => $fails, 'reason' => ''];
    }
    $elapsed = $now - $openedAt;
    if ($openedAt > 0 && $elapsed < $cooloff) {
        return [
            'open' => true, 'half_open' => false,
            'retry_in' => max(1, $cooloff - $elapsed),
            'fails' => $fails,
            'reason' => 'outbound notification delivery paused after ' . $fails
                      . ' consecutive failures' . ($lastErr !== '' ? ' (' . $lastErr . ')' : ''),
        ];
    }
    return ['open' => false, 'half_open' => true, 'retry_in' => 0,
            'fails' => $fails, 'reason' => 'cool-off elapsed — one attempt allowed'];
}

/**
 * Stored counters. Never throws; an unreadable store means "closed".
 *
 * Read with a DIRECT query, deliberately NOT through get_variable(). That
 * helper loads the whole `settings` table into a static on first use and
 * never invalidates it, which is correct for configuration and catastrophic
 * for a counter: a read-modify-write against a frozen snapshot writes 1,
 * reads 0, writes 1 again, and the breaker never reaches its threshold no
 * matter how many times delivery fails. That is exactly what happened on the
 * first cut of this file, and the symptom — a breaker that logs failures and
 * never opens — is quiet enough to ship.
 */
function notify_breaker_read(): array
{
    $empty  = ['fails' => 0, 'opened_at' => 0, 'last_error' => '', 'last_fail_at' => 0];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $raw = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = ? LIMIT 1",
                              [NOTIFY_BREAKER_SETTING]);
    } catch (Exception $e) { return $empty; }
    if ($raw === false || $raw === null || $raw === '') return $empty;
    $st = json_decode((string) $raw, true);
    if (!is_array($st)) return $empty;
    return [
        'fails'        => max(0, (int) ($st['fails'] ?? 0)),
        'opened_at'    => (int) ($st['opened_at'] ?? 0),
        'last_error'   => substr((string) ($st['last_error'] ?? ''), 0, 180),
        'last_fail_at' => (int) ($st['last_fail_at'] ?? 0),
    ];
}

/** Persist counters. Best effort — a write failure leaves the breaker closed. */
function notify_breaker_write(array $state): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $json   = json_encode($state);
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = ?", [NOTIFY_BREAKER_SETTING]);
        if ($n > 0) {
            db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?",
                     [$json, NOTIFY_BREAKER_SETTING]);
        } else {
            db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)",
                     [NOTIFY_BREAKER_SETTING, $json]);
        }
        return true;
    } catch (Exception $e) {
        error_log('[notify] breaker write failed: ' . $e->getMessage());
        return false;
    }
}

/** Read-only view, for tests and the Status page. */
function notify_breaker_status(?int $now = null): array
{
    $st = notify_breaker_read();
    $d  = notify_breaker_decide($st, $now ?? time(),
                                notify_breaker_threshold(), notify_breaker_cooloff_s());
    $d['last_error']   = $st['last_error'];
    $d['last_fail_at'] = $st['last_fail_at'] > 0 ? date('Y-m-d H:i:s', $st['last_fail_at']) : null;
    return $d;
}

/**
 * Request-path gate. Same decision, plus the one side effect that must happen
 * exactly once: on entering half-open, re-stamp the window so a single
 * request probes and concurrent ones keep failing fast.
 */
function notify_breaker_check(?int $now = null): array
{
    $now = $now ?? time();
    $st  = notify_breaker_read();
    $d   = notify_breaker_decide($st, $now, notify_breaker_threshold(), notify_breaker_cooloff_s());
    if ($d['half_open']) {
        $st['opened_at'] = $now;
        notify_breaker_write($st);
    }
    return $d;
}

function notify_breaker_record_failure(string $error, ?int $now = null): void
{
    $now = $now ?? time();
    $st  = notify_breaker_read();
    $st['fails']        = $st['fails'] + 1;
    $st['last_error']   = substr(preg_replace('/[^\x20-\x7E]/', '', $error), 0, 180);
    $st['last_fail_at'] = $now;
    if ($st['fails'] >= notify_breaker_threshold() && (int) $st['opened_at'] === 0) {
        $st['opened_at'] = $now;
    }
    notify_breaker_write($st);
}

function notify_breaker_record_success(): void
{
    $st = notify_breaker_read();
    if ($st['fails'] === 0 && (int) $st['opened_at'] === 0) return;
    notify_breaker_write(['fails' => 0, 'opened_at' => 0, 'last_error' => '', 'last_fail_at' => 0]);
}

function notify_breaker_reset(): void
{
    notify_breaker_write(['fails' => 0, 'opened_at' => 0, 'last_error' => '', 'last_fail_at' => 0]);
}

// ─────────────────────────────────────────────────────────────────────────
// Is anything actually draining the queue?
// ─────────────────────────────────────────────────────────────────────────

/**
 * Has the pending-message sweep run recently enough to be trusted with
 * delivery?
 *
 * Asks the heartbeat the real tick writes (inc/scheduled-jobs.php), not a
 * config flag and not the presence of a unit file — the seven-week outage
 * happened because a cron.d file existed on a host with no cron daemon and
 * everything downstream believed it.
 *
 * Three intervals of grace: a 60-second job that is two minutes late is not
 * news, and being wrong in this direction only costs one short probe.
 */
function notify_scheduler_is_live(?int $now = null): bool
{
    $now = $now ?? time();
    $reg = function_exists('sched_job_registry') ? sched_job_registry() : [];
    $def = $reg['pending_messages_tick'] ?? ['interval_s' => 60];
    $row = function_exists('sched_job_last') ? sched_job_last('pending_messages_tick') : null;
    if (!$row || empty($row['last_ok_at'])) return false;
    $ts = strtotime((string) $row['last_ok_at']);
    if (!$ts) return false;
    return ($now - $ts) <= ((int) $def['interval_s'] * 3);
}

// ─────────────────────────────────────────────────────────────────────────
// The dispatch-path entry point
// ─────────────────────────────────────────────────────────────────────────

/**
 * Which legs of the fan-out could deliver anything on this install?
 *
 * Checked before anything is queued, because on a default install the answer
 * is "neither" and the right amount of work to do is none. sse_publish() runs
 * on every chat line and every status change; writing a queue row each time
 * for an install with no webhook subscribers and push switched off would be a
 * cost with no purpose.
 *
 * Memoised per request — these do not change mid-request, and the callers are
 * hot.
 *
 * @param string[] $channels subset of ['webhook', 'push']
 * @return string[] the ones that could actually deliver
 */
function notify_fanout_channels_live(array $channels): array
{
    static $memo = [];
    $epoch  = (int) ($GLOBALS['_notify_channels_epoch'] ?? 0);
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $live   = [];

    foreach ($channels as $c) {
        $k = $epoch . '|' . $c;
        if (!array_key_exists($k, $memo)) {
            $memo[$k] = false;
            try {
                if ($c === 'webhook') {
                    $memo[$k] = (int) db_fetch_value(
                        "SELECT COUNT(*) FROM `{$prefix}webhook_subscriptions` WHERE active = 1") > 0;
                } elseif ($c === 'push') {
                    $memo[$k] = (string) db_fetch_value(
                        "SELECT value FROM `{$prefix}settings` WHERE name = 'push_enabled' LIMIT 1") === '1';
                }
            } catch (Exception $e) { $memo[$k] = false; }
        }
        if ($memo[$k]) $live[] = $c;
    }
    return $live;
}

/**
 * Forget the per-request memo. A web request never needs this; a test that
 * enables push, or a long-running CLI worker, does — otherwise it keeps
 * answering from a snapshot taken before the change.
 */
function notify_fanout_forget_channel_cache(): void
{
    $GLOBALS['_notify_channels_epoch'] = (int) ($GLOBALS['_notify_channels_epoch'] ?? 0) + 1;
}

/**
 * Hand an event to the notification queue, and decide whether this request
 * should also try to deliver it.
 *
 * Called from audit_log() (webhooks + push) and from sse_publish() (webhooks
 * only — SSE events have always fanned out to webhooks and have never fanned
 * out to push; that is preserved exactly).
 *
 * MUST return promptly whether or not the network is up. That is the entire
 * contract, and tests/test_notify_fanout.php asserts it against black-holed
 * endpoints through the real writers.
 *
 * LOOP TERMINATION. Delivering a push goes through the routing engine, which
 * publishes a `routing:forwarded` SSE event on success, which comes back here
 * — so this could recurse. It cannot, by construction: that nested call is
 * webhook-only, and a webhook-only row never touches the router, so it can
 * generate nothing further. Depth is bounded at two by the channel set, not
 * by a counter that someone has to remember to increment.
 *
 * @param string[] $channels which legs to fan out to
 * @return array{action:string,queued_id:?int,elapsed:float,detail:string}
 */
function notify_fanout_dispatch(string $eventType, array $payload,
                                array $channels = ['webhook', 'push']): array
{
    $t0 = microtime(true);
    $out = function (string $action, ?int $id, string $detail) use ($t0) {
        return ['action' => $action, 'queued_id' => $id,
                'elapsed' => microtime(true) - $t0, 'detail' => $detail];
    };

    $channels = notify_fanout_channels_live($channels);
    if (empty($channels)) {
        return $out('noop', null, 'no outbound notification channel is configured');
    }

    $id = notify_fanout_enqueue($eventType, $payload, $channels);

    // Re-entrancy: we are already inside a drain. The row is written (nothing
    // is lost) but this is not the moment to open another socket.
    if (!empty($GLOBALS['_notify_fanout_draining'])) {
        return $out('queued', $id, 'nested inside a fan-out drain — delivery deferred');
    }

    if ($id === null) {
        // No queue on this install (pre-migration, or the table is gone). The
        // notification still matters more than the stall, so deliver inline —
        // but bounded, and still behind the breaker. Never silently drop.
        $b = notify_breaker_check();
        if ($b['open']) {
            error_log('[notify] queue unavailable and breaker open — ' . $eventType . ' not delivered');
            return $out('dropped', null, 'no queue, breaker open: ' . $b['reason']);
        }
        $r = notify_fanout_deliver($eventType, $payload, (float) notify_inline_budget_s(), $channels);
        return $out($r['ok'] ? 'sent_inline' : 'failed_inline', null, $r['error']);
    }

    // The normal, correct case: something is draining the queue, so this
    // request is done. No outbound network at all.
    if (notify_scheduler_is_live()) {
        return $out('queued', $id, 'scheduled sweep is live');
    }

    // Nothing is draining the queue. Deliver best-effort so this install does
    // not silently stop notifying — bounded, and skipped entirely while the
    // breaker is open.
    $b = notify_breaker_check();
    if ($b['open']) {
        return $out('queued', $id, 'no scheduler; ' . $b['reason']);
    }
    $drained = notify_fanout_drain((float) notify_inline_budget_s(), 3);
    return $out('queued_and_attempted', $id,
                'no scheduler; attempted inline: sent=' . $drained['sent']
                . ' failed=' . $drained['failed']);
}

/**
 * Write the event to the queue. Returns the row id, or null when there is no
 * queue to write to.
 */
function notify_fanout_enqueue(string $eventType, array $payload,
                               array $channels = ['webhook', 'push']): ?int
{
    if (!function_exists('pending_enqueue')) return null;

    $body = _notify_encode_payload($eventType, $payload, $channels);
    if ($body === null) return null;

    $summary = (string) ($payload['summary'] ?? $eventType);

    return pending_enqueue([
        'ticket_id'         => isset($payload['ticket_id']) ? (int) $payload['ticket_id'] : null,
        'route_id'          => null,
        'channel'           => NOTIFY_FANOUT_CHANNEL,
        'target'            => substr($eventType, 0, 255),
        'subject'           => substr($summary !== '' ? $summary : $eventType, 0, 255),
        'body'              => $body,
        'priority'          => null,
        'scheduled_send_at' => date('Y-m-d H:i:s'),
        'created_by'        => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    ]);
}

/**
 * Encode the event for storage, shedding the optional part if it would not
 * fit. `body` is TEXT; a payload that overflows it would make the INSERT fail
 * and lose the notification entirely, which is a far worse outcome than
 * delivering it without its `details` block.
 */
function _notify_encode_payload(string $eventType, array $payload,
                                array $channels = ['webhook', 'push']): ?string
{
    $doc  = ['v' => 1, 'event_type' => $eventType,
             'channels' => array_values($channels), 'payload' => $payload];
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Non-UTF8 in a field somewhere. Try again without the free-form part.
        unset($doc['payload']['details']);
        $doc['payload']['details_dropped'] = 'unencodable';
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
        if ($json === false) return null;
    }
    if (strlen($json) > NOTIFY_PAYLOAD_MAX_BYTES) {
        unset($doc['payload']['details']);
        $doc['payload']['details_dropped'] = 'oversize';
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > NOTIFY_PAYLOAD_MAX_BYTES) return null;
    }
    return $json;
}

/** Decode a queued row back into (event type, payload), or null if unreadable. */
function notify_fanout_decode(string $body): ?array
{
    $doc = json_decode($body, true);
    if (!is_array($doc) || empty($doc['event_type'])) return null;
    $ch = is_array($doc['channels'] ?? null) ? $doc['channels'] : ['webhook', 'push'];
    return [
        'event_type' => (string) $doc['event_type'],
        'channels'   => array_values(array_intersect($ch, ['webhook', 'push'])),
        'payload'    => is_array($doc['payload'] ?? null) ? $doc['payload'] : [],
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// Delivery
// ─────────────────────────────────────────────────────────────────────────

/**
 * Actually fan an event out. This is the ONLY place that calls webhook_fire()
 * and push_fire(), so there is one answer to "what does a dispatch action set
 * in motion" rather than one per caller.
 *
 * @param float|null $budgetS wall-clock budget, or null for no deadline
 * @return array{ok:bool,error:string}
 */
function notify_fanout_deliver(string $eventType, array $payload, ?float $budgetS = null,
                               array $channels = ['webhook', 'push']): array
{
    $prevDraining = $GLOBALS['_notify_fanout_draining'] ?? false;
    $GLOBALS['_notify_fanout_draining'] = true;
    $GLOBALS['_push_last_result']    = null;
    $GLOBALS['_webhook_last_result'] = null;
    if ($budgetS !== null) notify_deadline_set($budgetS);

    $t0     = microtime(true);
    $errors = [];
    if (in_array('webhook', $channels, true)) {
        try {
            require_once __DIR__ . '/webhooks.php';
            if (function_exists('webhook_fire')) {
                webhook_fire($eventType, $payload);
            }
        } catch (Throwable $e) {
            $errors[] = 'webhook: ' . $e->getMessage();
        }
    }
    if (in_array('push', $channels, true)) {
        try {
            if (is_file(__DIR__ . '/push.php')) {
                require_once __DIR__ . '/push.php';
                if (function_exists('push_fire')) {
                    push_fire($eventType, $payload);
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'push: ' . $e->getMessage();
        }
    }
    $elapsed = microtime(true) - $t0;

    if ($budgetS !== null) notify_deadline_clear();
    $GLOBALS['_notify_fanout_draining'] = $prevDraining;

    // ── Did anything actually arrive? ───────────────────────────────────
    //
    // This is decided on what HAPPENED, not on how long it took. An earlier
    // cut of this file inferred failure from elapsed time alone, and the
    // verdict then depended on how many rows a drain happened to process:
    // green run to run in isolation, red in the full suite. Elapsed time is
    // kept below as a backstop, but it is not the evidence.
    //
    // "Nothing attempted" is never a failure — that is an install with no
    // subscribers, which is most of them.
    $wr = $GLOBALS['_webhook_last_result'] ?? null;
    if (is_array($wr) && (int) ($wr['attempted'] ?? 0) > 0 && (int) ($wr['delivered'] ?? 0) === 0) {
        $errors[] = 'webhook: 0 of ' . (int) $wr['attempted'] . ' delivered';
    }
    $pr = $GLOBALS['_push_last_result'] ?? null;
    if (is_array($pr) && (int) ($pr['queued'] ?? 0) > 0 && (int) ($pr['delivered'] ?? 0) === 0) {
        $errors[] = 'push: 0 of ' . (int) $pr['queued'] . ' delivered';
    }

    // Backstop: a run that consumed its whole budget did not finish, whatever
    // the tallies say. The symptom this file exists to prevent IS elapsed time.
    if ($budgetS !== null && $elapsed >= ($budgetS - 0.05)) {
        $errors[] = sprintf('exhausted the %.1fs budget', $budgetS);
    }

    return ['ok' => empty($errors), 'error' => implode('; ', $errors)];
}

/**
 * Replay one queued row. Called by pending_sweep() for rows on the fan-out
 * channel, so the timer-driven sweep and the inline attempt share exactly one
 * delivery path.
 *
 * @return array{ok:bool,error:string}
 */
function notify_fanout_replay(array $row, ?float $budgetS = null): array
{
    $decoded = notify_fanout_decode((string) ($row['body'] ?? ''));
    if ($decoded === null) {
        // Unreadable row. Do NOT keep retrying it forever — say so and let it
        // be marked failed, which is a state an operator can see and act on.
        return ['ok' => false, 'error' => 'unreadable fan-out payload', 'permanent' => true];
    }
    return notify_fanout_deliver($decoded['event_type'], $decoded['payload'],
                                 $budgetS, $decoded['channels']);
}

/**
 * Drain queued fan-out rows within a budget. Thin wrapper over pending_sweep()
 * so there is one sweep, one cutoff contract and one set of state transitions
 * — the inline attempt is the same code the timer runs, with a stopwatch on it.
 */
function notify_fanout_drain(?float $budgetS = null, int $limit = 10, ?int $now = null): array
{
    if (!function_exists('pending_sweep')) {
        return ['considered' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
    }
    return pending_sweep($now, null, $limit, $budgetS, NOTIFY_FANOUT_CHANNEL);
}

/**
 * How many notifications are waiting, and how old is the oldest? Used by the
 * Status page so a backlog is visible rather than inferred.
 *
 * @return array{pending:int,failed:int,oldest_age_s:?int,oldest_at:?string}
 */
function notify_queue_depth(?int $now = null): array
{
    $now    = $now ?? time();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $out    = ['pending' => 0, 'failed' => 0, 'oldest_age_s' => null, 'oldest_at' => null];
    // Phase 155 (GH#144): Notification Rule deliveries (channel _notify_rule,
    // inc/notification-delivery.php) are outbound notifications too, drained by
    // the same sweep - counted here so the Status page and the tick's log line
    // show the whole backlog, not just the fan-out half of it. The literal is
    // the fallback for a caller that has not loaded notification-delivery.php.
    $ruleCh = defined('NOTIFY_RULE_CHANNEL') ? NOTIFY_RULE_CHANNEL : '_notify_rule';
    try {
        $row = db_fetch_one(
            "SELECT COUNT(*) AS n, MIN(scheduled_send_at) AS oldest
               FROM `{$prefix}pending_routed_messages`
              WHERE status = 'pending' AND channel IN (?, ?)", [NOTIFY_FANOUT_CHANNEL, $ruleCh]);
        $out['pending'] = (int) ($row['n'] ?? 0);
        if (!empty($row['oldest'])) {
            $out['oldest_at']    = (string) $row['oldest'];
            $ts                  = strtotime((string) $row['oldest']);
            $out['oldest_age_s'] = $ts ? max(0, $now - $ts) : null;
        }
        $out['failed'] = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}pending_routed_messages`
              WHERE status = 'failed' AND channel IN (?, ?)", [NOTIFY_FANOUT_CHANNEL, $ruleCh]);
    } catch (Exception $e) {
        // Table absent on a pre-migration install — report nothing waiting
        // rather than pretending to know.
    }
    return $out;
}
