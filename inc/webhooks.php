<?php
/**
 * NewUI v4.0 — Outbound Webhook Dispatcher
 *
 * Fires HTTP POST callbacks to registered external subscribers when
 * relevant audit events occur. Subscriptions live in the
 * webhook_subscriptions table (Phase 94 Stage 1, Decision #3);
 * webhook_fire() reads ONLY from webhook_subscriptions. The legacy
 * webhooks table was dropped 2026-08-21 (SPEC-STATUS.md B20,
 * sql/run_webhooks_legacy_table_drop.php) after Stage 6 verification
 * found it had zero readers/writers anywhere in the app.
 *
 * Two ways events get fired:
 *
 *   1. Audit-driven (Phase 94 Stage 5, the reliability fix for a beta
 *      tester's report). inc/audit.php's audit_log() calls
 *      webhook_fire() after a successful INSERT, using
 *      _audit_to_webhook_event() to map (category, activity,
 *      target_type) → event_type. This is the canonical path — if it's
 *      in the audit log, it's eligible for delivery.
 *
 *   2. Direct (legacy + admin tools). webhook_fire('incident.created',
 *      ['ticket_id' => 42, ...]) still works for callers that want to
 *      fire explicitly. Pre-Stage-5 internal endpoints that called
 *      webhook_fire directly still work; their calls now layer with the
 *      audit-driven path — if both fire, the subscriber gets two
 *      deliveries (the per-subscription dedupe is an out-of-scope
 *      future cleanup).
 *
 * Each delivery is logged to the webhook_deliveries table. Failed
 * deliveries are retried by tools/webhook_retry_tick.php with
 * exponential backoff up to max_attempts (read from the subscription's
 * retry_policy_json, defaulting to 5). After max_attempts, the
 * delivery transitions to status='dead_letter' and surfaces in the
 * admin UI (Stage 6) for manual replay.
 */

/* ────────────────────────────────────────────────────────────────────
 * Signing, replay protection and the reference verifier
 * ────────────────────────────────────────────────────────────────────
 *
 * Reported privately by Ron Jones (@rjonesbsink) on 2026-08-02 and
 * confirmed against a live capture on the same day.
 *
 * WHAT WAS WRONG
 *
 * Deliveries were signed `hash_hmac('sha256', $body, $secret)` — the body
 * and nothing else. No timestamp, no nonce, no delivery id, in the
 * headers or the body. A captured delivery could therefore be re-POSTed
 * unchanged at any later time and would verify as authentic forever,
 * because the signature had no time-varying input that could go stale.
 *
 * This is an OUTBOUND concern: TicketsCAD is the sender, so the party
 * that has to reject a replay is the receiver. The sender cannot reject a
 * replay of its own message — all it can do is put material on the wire
 * that lets the receiver make the decision. That is what this block adds.
 *
 * The signing expression was copy-pasted at FOUR call sites (fire, test,
 * retry, and the admin replay in api/webhooks.php). That duplication is
 * why the wire format and docs/WEBHOOKS-INTEGRATOR-GUIDE.md were free to
 * drift apart for as long as they did — there was no single definition
 * either could be checked against. There is now exactly one signer and
 * one header builder, and every send path goes through them. Callers no
 * longer pass a precomputed signature; they cannot, which is the point.
 *
 * THE WIRE FORMAT
 *
 *   X-Webhook-Timestamp     unix seconds, stamped per TRANSMISSION
 *   X-Webhook-Delivery      stable uid — the idempotency key
 *   X-Webhook-Event         event type
 *   X-Webhook-Signature-V2  sha256=HMAC(secret, "<timestamp>.<body>")
 *   X-Webhook-Signature     sha256=HMAC(secret, body)   [legacy, optional]
 *
 * Why the timestamp is per-transmission and not the `timestamp` already
 * inside the body: webhook_process_retries() re-sends the ORIGINAL stored
 * body, so the body's timestamp is the time of the FIRST attempt. A
 * receiver enforcing a freshness window against it would reject every
 * legitimate retry that arrived after the window. The signed timestamp
 * has to describe this attempt.
 *
 * Why the delivery uid is not simply webhook_deliveries.id: retries
 * INSERT a new delivery row and therefore get a new id, so the row id
 * changes across attempts and is useless as a dedupe key. The uid is
 * minted once for the logical delivery and carried across every retry
 * and admin replay of it — which is what the integrator guide has always
 * promised ("UUID shared across retries").
 *
 * WHY THE LEGACY HEADER IS STILL SENT BY DEFAULT
 *
 * Every receiver that works TODAY reverse-engineered the body-only
 * scheme, because a receiver written from the guide could never verify
 * anything (the guide documented a timestamped scheme that was not
 * implemented). Silently changing what X-Webhook-Signature means would
 * therefore break exactly the integrations that currently work, and in a
 * dispatch system a webhook that stops verifying can be a station that
 * stops getting alerted. So the new scheme arrives in a NEW header and
 * the old one keeps its meaning; `webhook_legacy_signature` lets an
 * admin switch the old header off once their receivers have moved.
 */

/**
 * Freshness window, in seconds, that receivers should apply to
 * X-Webhook-Timestamp. Configurable via the `webhook_replay_tolerance_sec`
 * setting; advertised in the integrator guide and used by
 * webhook_verify_signature().
 *
 * Clamped to 30 s … 86400 s. The floor exists because a tolerance of 0
 * would make every delivery unverifiable and the natural "fix" for that
 * is to stop checking; the ceiling keeps a typo from widening the replay
 * window to something meaningless.
 */
function webhook_replay_tolerance(): int {
    $v = function_exists('get_variable') ? get_variable('webhook_replay_tolerance_sec') : false;
    if ($v === false || $v === null || trim((string) $v) === '') return 300;
    $n = (int) $v;
    if ($n < 30)    return 30;
    if ($n > 86400) return 86400;
    return $n;
}

/**
 * Whether to keep emitting the legacy body-only X-Webhook-Signature
 * header alongside the timestamped one. Default ON so an upgrade breaks
 * nothing; set `webhook_legacy_signature` to 0 once every receiver has
 * moved to X-Webhook-Signature-V2.
 */
function webhook_legacy_signature_enabled(): bool {
    $v = function_exists('get_variable') ? get_variable('webhook_legacy_signature') : false;
    if ($v === false || $v === null || trim((string) $v) === '') return true; // default on
    return !in_array(strtolower(trim((string) $v)), ['0', 'no', 'off', 'false'], true);
}

/**
 * THE canonical signature. Binds the delivery to a point in time by
 * signing "<timestamp>.<body>" — matching what the integrator guide has
 * documented all along.
 */
function webhook_sign(string $timestamp, string $body, string $secret): string {
    return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/**
 * The pre-2026-08-02 signature: body only, no time input. Kept solely so
 * receivers built against the old undocumented format keep working
 * during migration. It offers NO replay protection — that is the defect
 * this change exists to fix — so nothing new should adopt it.
 */
function webhook_sign_legacy(string $body, string $secret): string {
    return hash_hmac('sha256', $body, $secret);
}

/**
 * Mint a delivery uid (UUIDv4). One per logical delivery; reused by every
 * retry and admin replay of that delivery so receivers can deduplicate.
 */
function webhook_new_delivery_uid(): string {
    try {
        $b = random_bytes(16);
    } catch (Throwable $e) {
        // Non-cryptographic fallback. The uid is a dedupe key, not a
        // secret — it is never trusted for authentication — so a weaker
        // source degrades uniqueness, not security.
        $b = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()); // NOSONAR S2245: fallback only when random_bytes() itself throws; uid is a dedupe key, never used for authentication
    }
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Build the outbound header list. THE single place headers are made, so
 * every delivery path — fire, test, retry, admin replay — is identical on
 * the wire by construction rather than by four people remembering.
 *
 * @param string      $body       Raw JSON body (signed verbatim)
 * @param string      $secret     Subscription HMAC secret
 * @param string      $eventType  Dotted event type
 * @param string      $uid        Stable delivery uid
 * @param int|null    $timestamp  Unix seconds; defaults to now
 * @return array                  cURL-style "Header: value" strings
 */
function webhook_build_headers(string $body, string $secret, string $eventType,
                               string $uid, ?int $timestamp = null): array {
    $ts = (string) ($timestamp ?? time());

    $headers = [
        'Content-Type: application/json',
        'User-Agent: TicketsCAD-Webhook/4.0',
        'X-Webhook-Event: ' . $eventType,
        'X-Webhook-Timestamp: ' . $ts,
        'X-Webhook-Delivery: ' . $uid,
        'X-Webhook-Signature-V2: sha256=' . webhook_sign($ts, $body, $secret),
    ];

    if (webhook_legacy_signature_enabled()) {
        $headers[] = 'X-Webhook-Signature: sha256=' . webhook_sign_legacy($body, $secret);
    }

    return $headers;
}

/**
 * Reference verifier — the receiver side of the contract.
 *
 * Shipped, and exercised by tests/test_webhook_replay_protection.php,
 * because the most damaging half of Ron's report was not the replay
 * window: it was that a receiver written from the documentation could
 * not verify ANY delivery, and the path of least resistance when
 * verification cannot be made to work is to switch verification off.
 * That turns the receiver into an endpoint that acts on any POST from
 * anyone who learns the URL — worse than the replay issue it was meant
 * to avoid. Integrators can now copy code that is known to work.
 *
 * Accepts the signature with or without the `sha256=` prefix, because
 * the prefix was undocumented and receivers in the field handle it
 * inconsistently.
 *
 * @param string   $body       Raw request body bytes, exactly as received
 * @param string   $signature  X-Webhook-Signature-V2 header value
 * @param string   $timestamp  X-Webhook-Timestamp header value
 * @param string   $secret     Shared secret
 * @param int|null $tolerance  Seconds; defaults to webhook_replay_tolerance()
 * @param int|null $now        Current unix time; injectable for testing
 * @return array               ['valid' => bool, 'reason' => string]
 */
function webhook_verify_signature(string $body, string $signature, string $timestamp,
                                  string $secret, ?int $tolerance = null,
                                  ?int $now = null): array {
    $signature = trim($signature);
    $timestamp = trim($timestamp);

    if ($signature === '') return ['valid' => false, 'reason' => 'missing_signature'];
    if ($timestamp === '') return ['valid' => false, 'reason' => 'missing_timestamp'];
    if (!preg_match('/^\d+$/', $timestamp)) {
        return ['valid' => false, 'reason' => 'bad_timestamp'];
    }

    $tolerance = $tolerance ?? webhook_replay_tolerance();
    $now       = $now ?? time();

    // Freshness first: a stale delivery is rejected even if the signature
    // is perfect, which is precisely what stops a captured-and-replayed
    // request. abs() also rejects timestamps implausibly far in the
    // future, so a forged far-future stamp cannot buy an unlimited window.
    if (abs($now - (int) $timestamp) > $tolerance) {
        return ['valid' => false, 'reason' => 'stale'];
    }

    if (stripos($signature, 'sha256=') === 0) $signature = substr($signature, 7);

    $expected = webhook_sign($timestamp, $body, $secret);
    if (!hash_equals($expected, $signature)) {
        return ['valid' => false, 'reason' => 'bad_signature'];
    }

    return ['valid' => true, 'reason' => 'ok'];
}

/**
 * Fire all active subscriptions that subscribe to the given event type.
 *
 * @param string $eventType  Dotted-notation event (e.g. 'incident.created')
 *                           Legacy colon-notation ('incident:new') also
 *                           accepted for back-compat — converted to
 *                           dotted before matching.
 * @param array  $payload    Arbitrary event data
 * @return int   Number of subscriptions fired
 */
function webhook_fire($eventType, array $payload = []) {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Normalize event type: accept legacy colon-notation, normalize to
    // dotted so the audit-driven canonical names work consistently.
    $eventType = str_replace(':', '.', (string) $eventType);

    // Find active subscriptions
    try {
        $subs = db_fetch_all(
            "SELECT `id`, `target_url`, `hmac_secret`, `event_filters_json`, `retry_policy_json`
             FROM `{$prefix}webhook_subscriptions`
             WHERE `active` = 1"
        );
    } catch (Exception $e) {
        // Table may not exist yet — pre-Phase-94 install. Fail silently.
        return 0;
    }

    if (empty($subs)) {
        $GLOBALS['_webhook_last_result'] = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];
        return 0;
    }

    $fired = 0;
    $delivered = 0;
    $failedCount = 0;
    $GLOBALS['_webhook_last_result'] = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];
    foreach ($subs as $sub) {
        // Check if this subscription subscribes to the event type
        $filters = @json_decode($sub['event_filters_json'], true);
        if (!is_array($filters) || empty($filters)) continue;

        // Match: exact event type, '*' wildcard, OR 'incident.*' prefix wildcard
        $matched = false;
        foreach ($filters as $filter) {
            if ($filter === '*' || $filter === $eventType) {
                $matched = true;
                break;
            }
            // Prefix wildcard: 'incident.*' matches any 'incident.X'
            if (substr($filter, -2) === '.*') {
                $prefix2 = substr($filter, 0, -1); // 'incident.'
                if (strpos($eventType, $prefix2) === 0) {
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched) continue;

        // Build the delivery body
        $body = json_encode([
            'event_type' => $eventType,
            'timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
            'data'       => $payload
        ]);

        // Mint the stable delivery uid. Retries and admin replays of this
        // delivery carry the SAME uid so a receiver can deduplicate them.
        $uid = webhook_new_delivery_uid();

        // Log the pending delivery
        $deliveryId = _webhook_log_delivery(
            $sub['id'], $eventType, $body, 1, 'pending', $uid
        );

        // Out of budget. Leave the remaining subscriptions as 'pending'
        // deliveries — tools/webhook_retry_tick.php is what picks those up,
        // and it always was. Better a retried webhook than a held dispatcher.
        if (function_exists('notify_deadline_expired') && notify_deadline_expired()) {
            error_log('[webhook_fire] out of budget after ' . $fired
                      . ' subscriber(s) — remaining deliveries left pending for the retry tick');
            break;
        }

        // Fire HTTP POST (bounded; see _webhook_send for the budget clamp)
        $sent = _webhook_send(
            ['id' => $sub['id'], 'target_url' => $sub['target_url'], 'hmac_secret' => $sub['hmac_secret']],
            $body, $eventType, $uid, $deliveryId, 1
        );
        $fired++;
        if ($sent) $delivered++; else $failedCount++;
    }

    // What actually happened, for callers that must decide something on it
    // (inc/notify-fanout.php uses this to keep a queued row for retry and to
    // open the outbound breaker). The return value stays "how many were
    // attempted" so no existing caller changes meaning.
    $GLOBALS['_webhook_last_result'] = [
        'attempted' => $fired, 'delivered' => $delivered, 'failed' => $failedCount,
    ];

    return $fired;
}

/**
 * Map an audit-log (category, activity, target_type) tuple to a webhook
 * event type, OR null if the audit row is not webhook-eligible.
 *
 * Per spec §7.4 (Decision #4): explicit allowlist, not auto-fire. Adding
 * a new webhook-eligible event requires one line here. Anything not in
 * the map fires nothing — admin/config/security audit rows are
 * deliberately absent and CANNOT leak to external subscribers.
 *
 * @param string      $cat    Audit category (incident, personnel, asset, comms, data, …)
 * @param string      $act    Audit activity (create, update, delete, …)
 * @param string|null $target Audit target_type (ticket, member, responder, …)
 * @return string|null        Webhook event type (e.g. 'incident.created') or null
 */
function _audit_to_webhook_event(string $cat, string $act, ?string $target): ?string {
    static $map = [
        // Incidents
        'incident|create|ticket'        => 'incident.created',
        'incident|update|ticket'        => 'incident.updated',
        'incident|delete|ticket'        => 'incident.deleted',
        'incident|close|ticket'         => 'incident.closed',
        'incident|reopen|ticket'        => 'incident.reopened',
        // Action notes (the incident activity log)
        'incident|note_add|action'      => 'incident.note_added',
        // Phase 151 (GH#138) — primary/responsible unit designation changed
        // (manual, or auto-populated when primary_unit_mode = auto). No-op
        // itself when primary_unit_mode = off (incident_set_primary_internal()
        // never writes/audits in that mode, so this event never fires either).
        'incident|primary_change|ticket' => 'incident.primary_changed',
        // Assignments
        'incident|assign|assigns'       => 'assign.created',
        'incident|unassign|assigns'     => 'assign.removed',
        'incident|update|responder'     => 'responder.status_changed', // setResponderStatus path
        // Members (personnel)
        'personnel|create|member'       => 'member.created',
        'personnel|update|member'       => 'member.updated',
        'personnel|delete|member'       => 'member.deleted',
        'personnel|status_change|member' => 'member.status_changed', // Phase 94 Stage 4i — member-status PATCH
        'personnel|location_update|location_reports' => 'member.location_updated',
        // Units (responders)
        'asset|create|responder'        => 'responder.created',
        'asset|update|responder'        => 'responder.updated',
        'asset|delete|responder'        => 'responder.deleted',
        'asset|status_change|responder' => 'responder.status_changed',
        // Facilities
        'asset|create|facility'         => 'facility.created',
        'asset|update|facility'         => 'facility.updated',
        'asset|delete|facility'         => 'facility.deleted',
        // Teams
        'asset|create|team'             => 'team.created',
        'asset|update|team'             => 'team.updated',
        'asset|delete|team'             => 'team.deleted',
        // Incident types config. 2026-06-28: target_type was originally
        // 'in_type' in the Stage 5 map but the actual audit_log callers
        // (api/config-admin.php?section=types AND the new
        // api/external/v1/incident-types.php) pass target='incident_type'.
        // Renaming the map keys to match — otherwise no incident-type
        // create ever fired a webhook.
        'config|create|incident_type'   => 'incident_type.created',
        'config|update|incident_type'   => 'incident_type.updated',
        'config|delete|incident_type'   => 'incident_type.deleted',
        // Attachments
        'data|create|file'              => 'attachment.created',
        'data|delete|file'              => 'attachment.deleted',
        // 2026-06-28 — legacy-alias entries REMOVED. They were added
        // earlier in the day to work around 10 internal endpoints that
        // emitted non-canonical audit categories (e.g. responder-save
        // emitted `incident|create|responder` instead of canonical
        // `asset|create|responder`). Both Phase 1 and Phase 2 of the
        // internal-endpoint refactor are now shipped — every internal
        // endpoint calls the canonical inc/*-write.php helpers which
        // emit the canonical tuples directly. The aliases are no longer
        // load-bearing and are removed for clarity.
        //
        // If any external integration was relying on a legacy tuple
        // resolving (it shouldn't have been — the aliases mapped to
        // the SAME event_type the canonical tuple now produces), it
        // will keep working: same event_type, same payload shape.
        //
        // History (in git): commit e85c7e0 added the aliases; this
        // removal closes that work item.
    ];
    $key = $cat . '|' . $act . '|' . ($target ?? '');
    return $map[$key] ?? null;
}

/**
 * Send a test payload to an arbitrary URL. Does NOT go through the
 * subscription path. Used by the Settings → Webhooks / Events "Test URL"
 * button. For exercising a real subscription end-to-end, use the
 * action=fire_now admin endpoint instead (which goes through
 * webhook_fire's full audit→event→subscription chain).
 */
function webhook_test($url, $secret) {
    $body = json_encode([
        'event_type' => 'test',
        'timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
        'data'       => [
            'message' => 'This is a test webhook from TicketsCAD NewUI.',
            'version' => '4.0'
        ]
    ]);

    // The test delivery is signed and headed exactly like a real one —
    // it is what an integrator points their receiver at while building
    // it, so if it differed from production traffic in any way it would
    // be actively misleading.
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => webhook_build_headers(
            $body, $secret, 'test', webhook_new_delivery_uid()
        ),
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response   = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error      = curl_error($ch);
    curl_close($ch);

    $durationMs = (int) ((microtime(true) - $start) * 1000);

    return [
        'success'     => ($httpStatus >= 200 && $httpStatus < 300),
        'http_status' => $httpStatus,
        'response'    => substr($response ?: $error, 0, 1000),
        'duration_ms' => $durationMs
    ];
}

/**
 * Process pending retries. Called by tools/webhook_retry_tick.php on a
 * systemd timer (every minute). Finds failed deliveries that have not
 * exceeded the subscription's max_attempts and retries them.
 *
 * After retrying, performs a second pass to mark any delivery whose
 * attempt count has reached max_attempts as 'dead_letter' (per Decision
 * #3's webhook_subscriptions retry_policy_json shape).
 *
 * @return array ['retried' => N, 'dead_lettered' => M]
 */
function webhook_process_retries() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $retried = 0;
    $deadLettered = 0;

    // ── Retry pass ──
    try {
        $rows = db_fetch_all(
            "SELECT d.*, s.target_url, s.hmac_secret, s.retry_policy_json
             FROM `{$prefix}webhook_deliveries` d
             JOIN `{$prefix}webhook_subscriptions` s ON s.id = d.subscription_id
             WHERE d.status = 'failed'
               AND s.active = 1
               AND d.dead_lettered_at IS NULL
               AND d.created_at < DATE_SUB(NOW(), INTERVAL POW(2, d.attempt) * 30 SECOND)
             ORDER BY d.created_at ASC
             LIMIT 50"
        );
    } catch (Exception $e) {
        return ['retried' => 0, 'dead_lettered' => 0, 'error' => $e->getMessage()];
    }

    foreach ($rows as $row) {
        $policy = @json_decode($row['retry_policy_json'] ?: '{"max_attempts":5}', true);
        $maxAttempts = (int) ($policy['max_attempts'] ?? 5);
        if ((int) $row['attempt'] >= $maxAttempts) continue; // dead-letter pass below will catch this

        $body = $row['payload'];
        $attempt = (int) $row['attempt'] + 1;

        // Carry the ORIGINAL delivery uid forward. The retry is the same
        // logical delivery, so the receiver must see the same idempotency
        // key and be able to recognise it as one it may already have
        // processed. Pre-upgrade rows have no uid — mint one so the retry
        // is still well-formed.
        $uid = (string) ($row['delivery_uid'] ?? '');
        if ($uid === '') $uid = webhook_new_delivery_uid();

        // Create a new delivery record for this retry attempt
        $deliveryId = _webhook_log_delivery(
            $row['subscription_id'], $row['event_type'], $body, $attempt, 'pending', $uid
        );

        _webhook_send(
            ['id' => $row['subscription_id'], 'target_url' => $row['target_url'], 'hmac_secret' => $row['hmac_secret']],
            $body, $row['event_type'], $uid, $deliveryId, $attempt
        );

        // Mark the old delivery as superseded
        try {
            db_query(
                "UPDATE `{$prefix}webhook_deliveries` SET `status` = 'retried' WHERE `id` = ?",
                [$row['id']]
            );
        } catch (Exception $e) {}

        $retried++;
    }

    // ── Dead-letter pass ──
    // Mark any failed delivery whose attempt count has reached the
    // subscription's max_attempts. The dead-letter row stays in place
    // for the audit trail; admin can replay it via api/webhooks.php
    // action=replay (Stage 5.3, queued).
    try {
        $result = db_query(
            "UPDATE `{$prefix}webhook_deliveries` d
             JOIN `{$prefix}webhook_subscriptions` s ON s.id = d.subscription_id
             SET d.status = 'dead_letter', d.dead_lettered_at = NOW()
             WHERE d.status = 'failed'
               AND d.dead_lettered_at IS NULL
               AND d.attempt >= CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(s.retry_policy_json, '{\"max_attempts\":5}'), '$.max_attempts')) AS UNSIGNED)"
        );
        $deadLettered = $result instanceof PDOStatement ? $result->rowCount() : 0;

        // Update dead_letter_count on each affected subscription. Cheap
        // recompute on the affected set rather than threading deltas.
        if ($deadLettered > 0) {
            db_query(
                "UPDATE `{$prefix}webhook_subscriptions` s
                 SET dead_letter_count = (SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` d
                                          WHERE d.subscription_id = s.id AND d.status = 'dead_letter')
                 WHERE s.id IN (SELECT subscription_id FROM `{$prefix}webhook_deliveries` WHERE dead_lettered_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE))"
            );
        }
    } catch (Exception $e) {
        // Dead-letter sweep failure is non-fatal — retries continue
    }

    return ['retried' => $retried, 'dead_lettered' => $deadLettered];
}

/**
 * Internal: send HTTP POST to a subscription's target_url.
 *
 * On success (2xx), updates the delivery row to status='success' AND
 * stamps last_success_at on the subscription. On failure, sets
 * status='failed' AND stamps last_failure_at on the subscription. The
 * subscription's stamps give operators an at-a-glance health signal
 * without scanning the deliveries table.
 */
function _webhook_send($sub, $body, $eventType, $uid, $deliveryId, $attempt) {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // 2026-06-28 security fix #4 — SSRF guard. This call was added to
    // the security commit 372a5c2 but silently dropped from the
    // applied patch (Edit failed); test_webhook_delivery.php caught
    // the regression on 2026-06-28 evening. Re-applied here.
    //
    // The function _webhook_url_safe() (defined further down) rejects
    // URLs that resolve to loopback / link-local / RFC1918 / non-http
    // schemes. Without this gate, an admin (or compromised admin) could
    // point a webhook at http://169.254.169.254/ to harvest AWS
    // metadata credentials, http://127.0.0.1:6379/ to hit Redis, etc.
    if (!_webhook_url_safe($sub['target_url'])) {
        try {
            db_query(
                "UPDATE `{$prefix}webhook_deliveries`
                 SET `status` = 'failed', `error` = ?, `http_status` = 0, `duration_ms` = 0
                 WHERE `id` = ?",
                ['target URL rejected by SSRF guard', $deliveryId]
            );
        } catch (Exception $e) { /* non-fatal */ }
        return false;
    }

    $start = microtime(true);

    // 2026-07-31 — honour a caller-imposed wall-clock budget. The fan-out is
    // normally delivered by the scheduled sweep, where no deadline is set and
    // these defaults apply; when it is being attempted from a request path
    // (an install with no scheduler), the budget clamps them so five
    // subscribers cannot become 25 seconds of a dispatcher's time.
    $_whRemaining = function_exists('notify_deadline_remaining') ? notify_deadline_remaining() : null;
    $_whTimeout   = function_exists('notify_clamp_timeout') ? notify_clamp_timeout(5, $_whRemaining) : 5;
    $_whConnect   = function_exists('notify_clamp_timeout') ? notify_clamp_timeout(3, $_whRemaining) : 3;

    $ch = curl_init($sub['target_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $_whTimeout,
        CURLOPT_CONNECTTIMEOUT => $_whConnect,
        // Signed HERE, at the moment of transmission, so X-Webhook-Timestamp
        // describes THIS attempt. A retry of a delivery stored an hour ago
        // is signed with the retry's own clock and stays inside the
        // receiver's freshness window.
        CURLOPT_HTTPHEADER     => webhook_build_headers(
            $body, $sub['hmac_secret'], (string) $eventType, (string) $uid
        ),
        CURLOPT_SSL_VERIFYPEER => true,
        // 2026-06-28: pin protocols + disable redirects so a 302 to
        // file:// / gopher:// can't sneak past the SSRF guard
        // (which only checked the initial URL).
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response   = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error      = curl_error($ch);
    curl_close($ch);

    $durationMs = (int) ((microtime(true) - $start) * 1000);
    $success    = ($httpStatus >= 200 && $httpStatus < 300);

    // Update delivery log
    try {
        db_query(
            "UPDATE `{$prefix}webhook_deliveries`
             SET `http_status` = ?, `response_body` = ?, `duration_ms` = ?,
                 `status` = ?, `error` = ?
             WHERE `id` = ?",
            [
                $httpStatus,
                substr($response ?: '', 0, 1000),
                $durationMs,
                $success ? 'success' : 'failed',
                $success ? null : substr($error ?: "HTTP $httpStatus", 0, 512),
                $deliveryId
            ]
        );
    } catch (Exception $e) {
        // Logging failure is not critical
    }

    // Stamp subscription health
    try {
        if ($success) {
            db_query(
                "UPDATE `{$prefix}webhook_subscriptions` SET `last_success_at` = NOW() WHERE `id` = ?",
                [$sub['id']]
            );
        } else {
            db_query(
                "UPDATE `{$prefix}webhook_subscriptions` SET `last_failure_at` = NOW() WHERE `id` = ?",
                [$sub['id']]
            );
        }
    } catch (Exception $e) {
        // Stamp failure non-fatal
    }

    // 2026-07-31 — this used to fall off the end returning null, so every
    // caller was blind to whether anything was actually delivered. The
    // notification fan-out needs to know: "attempted 1, delivered 0" is what
    // an outage looks like, and it has to open the circuit breaker rather
    // than be inferred from how long the call took.
    return $success;
}

/**
 * Internal: insert a delivery log record.
 *
 * @param int    $subscriptionId  webhook_subscriptions.id (NOT the legacy webhooks.id)
 * @param string $eventType
 * @param string $payload         JSON body
 * @param int    $attempt
 * @param string $status          'pending', 'success', 'failed', 'retried', 'dead_letter'
 * @return int   Delivery ID
 */
function _webhook_log_delivery($subscriptionId, $eventType, $payload, $attempt, $status, $uid = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // delivery_uid arrived 2026-08-02 (sql/run_webhook_replay_protection.php).
    // An install that has not run migrations yet still delivers — it just
    // cannot persist the uid, so retries of a delivery made in that window
    // mint a fresh one. Checked once per request, not per delivery.
    static $hasUid = null;
    if ($hasUid === null) {
        try {
            $hasUid = (int) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'delivery_uid'",
                [$prefix . 'webhook_deliveries']
            ) > 0;
        } catch (Exception $e) {
            $hasUid = false;
        }
    }

    if ($hasUid && $uid !== null && $uid !== '') {
        try {
            db_query(
                "INSERT INTO `{$prefix}webhook_deliveries`
                 (`webhook_id`, `subscription_id`, `event_type`, `payload`, `attempt`, `status`, `delivery_uid`)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?)",
                [$subscriptionId, $eventType, $payload, $attempt, $status, $uid]
            );
            return (int) db_insert_id();
        } catch (Exception $e) {
            // Fall through to the uid-less paths below rather than losing
            // the delivery entirely.
            error_log('[webhooks] delivery INSERT with delivery_uid failed, retrying without: ' . $e->getMessage());
        }
    }

    // 2026-06-28 reliability fix — explicitly set webhook_id = NULL.
    // Pre-2026-06-28, webhook_deliveries.webhook_id was a legacy
    // NOT-NULL-no-default column that silently failed this INSERT on
    // every installs that hadn't run the updated Stage 1 migration.
    // sql/run_phase94_external_api.php now ALTERs the column to
    // NULLable, but install ordering can leave a window — explicitly
    // setting it to NULL here makes the INSERT robust regardless of
    // whether the migration has applied yet.
    try {
        db_query(
            "INSERT INTO `{$prefix}webhook_deliveries`
             (`webhook_id`, `subscription_id`, `event_type`, `payload`, `attempt`, `status`)
             VALUES (NULL, ?, ?, ?, ?, ?)",
            [$subscriptionId, $eventType, $payload, $attempt, $status]
        );
        return (int) db_insert_id();
    } catch (Exception $e) {
        // Fallback for installs where webhook_id has been dropped from
        // the schema entirely (Stage 6 + cleanup): try the INSERT
        // without it. If THAT also fails the catch returns 0 and the
        // caller treats it as a delivery-log failure (non-fatal).
        try {
            db_query(
                "INSERT INTO `{$prefix}webhook_deliveries`
                 (`subscription_id`, `event_type`, `payload`, `attempt`, `status`)
                 VALUES (?, ?, ?, ?, ?)",
                [$subscriptionId, $eventType, $payload, $attempt, $status]
            );
            return (int) db_insert_id();
        } catch (Exception $e2) {
            error_log('[webhooks] _webhook_log_delivery INSERT failed (both with and without webhook_id): ' . $e2->getMessage());
            return 0;
        }
    }
}

/**
 * SSRF guard for outbound webhook URLs (2026-06-28 security audit fix #4).
 *
 * Rejects URLs that should NEVER be valid webhook destinations:
 *   - Non-http(s) schemes (file://, gopher://, dict://, ftp://, etc.)
 *   - Hostnames that resolve to loopback (127.0.0.0/8, ::1)
 *   - Link-local (169.254.0.0/16) — includes AWS/GCP/Azure metadata
 *   - RFC1918 private ranges (10/8, 172.16/12, 192.168/16)
 *   - IPv6 ULA (fc00::/7) and link-local (fe80::/10)
 *   - Unresolvable hostnames (DNS failure → defense in depth)
 *
 * Whitelist of permitted destination hostnames can be added via the
 * setting 'webhook_url_allowlist' (newline-separated suffixes) for
 * installs that legitimately need to webhook into a private host —
 * but then it's an explicit opt-in.
 *
 * Returns true if the URL is acceptable, false otherwise.
 */
function _webhook_url_safe(string $url): bool {
    $url = trim($url);
    if ($url === '') return false;
    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') return false;

    // Explicit allowlist of hostname suffixes (for installs that
    // legitimately point webhooks at an internal host)
    static $allowlistCache = null;
    if ($allowlistCache === null) {
        $allowlistCache = [];
        try {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            $row = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'webhook_url_allowlist' LIMIT 1"
            );
            if (is_string($row) && $row !== '') {
                foreach (preg_split('/[\r\n]+/', $row) as $line) {
                    $line = trim($line);
                    if ($line !== '') $allowlistCache[] = strtolower($line);
                }
            }
        } catch (Exception $e) { /* table missing on fresh installs — OK */ }
    }
    $host = strtolower($parts['host']);
    foreach ($allowlistCache as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return true;
        }
    }

    // Resolve and check every A/AAAA record. gethostbynamel() returns
    // IPv4-only — that's fine for the most common deployments and
    // matches what curl would actually connect to first.
    $ips = @gethostbynamel($host);
    if (!$ips) {
        // Treat unresolvable as untrusted. The webhook delivery would
        // have failed anyway; we surface it earlier with a clear reason.
        return false;
    }
    foreach ($ips as $ip) {
        if (_webhook_ip_is_internal($ip)) return false;
    }
    return true;
}

/**
 * True if the given IPv4 address is loopback, link-local, RFC1918,
 * 0.0.0.0/8, or any other range that should NOT receive a webhook
 * call. Helper for _webhook_url_safe().
 */
function _webhook_ip_is_internal(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        // IPv6 — be conservative and refuse anything in fc00::/7 (ULA)
        // or fe80::/10 (link-local) or ::1 (loopback). Easiest portable
        // check: drop to the standard PHP FILTER_FLAG_NO_PRIV_RANGE +
        // FILTER_FLAG_NO_RES_RANGE pair via filter_var.
        return filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    // IPv4 — FILTER_FLAG_NO_PRIV_RANGE catches 10/8, 172.16/12, 192.168/16
    // and FILTER_FLAG_NO_RES_RANGE catches 127/8, 169.254/16, 0/8, 224/4 etc.
    return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
