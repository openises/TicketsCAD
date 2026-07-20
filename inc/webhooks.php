<?php
/**
 * NewUI v4.0 — Outbound Webhook Dispatcher
 *
 * Fires HTTP POST callbacks to registered external subscribers when
 * relevant audit events occur. Subscriptions live in the new
 * webhook_subscriptions table (Phase 94 Stage 1, Decision #3). The
 * legacy webhooks table is kept in place until Stage 6 verifies the
 * switchover; webhook_fire() reads ONLY from webhook_subscriptions
 * going forward.
 *
 * Two ways events get fired:
 *
 *   1. Audit-driven (Phase 94 Stage 5, the reliability fix for a beta tester
 *      Gilbert's report). inc/audit.php's audit_log() calls
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

    if (empty($subs)) return 0;

    $fired = 0;
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

        // Compute HMAC-SHA256 signature
        $signature = hash_hmac('sha256', $body, $sub['hmac_secret']);

        // Log the pending delivery
        $deliveryId = _webhook_log_delivery(
            $sub['id'], $eventType, $body, 1, 'pending'
        );

        // Fire HTTP POST (non-blocking, 5s timeout)
        _webhook_send(
            ['id' => $sub['id'], 'target_url' => $sub['target_url'], 'hmac_secret' => $sub['hmac_secret']],
            $body, $signature, $deliveryId, 1
        );
        $fired++;
    }

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
 * subscription path. Used by the Settings → Webhooks "Test URL"
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

    $signature = hash_hmac('sha256', $body, $secret);

    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Webhook-Signature: sha256=' . $signature,
            'User-Agent: TicketsCAD-Webhook/4.0'
        ],
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
        $signature = hash_hmac('sha256', $body, $row['hmac_secret']);
        $attempt = (int) $row['attempt'] + 1;

        // Create a new delivery record for this retry attempt
        $deliveryId = _webhook_log_delivery(
            $row['subscription_id'], $row['event_type'], $body, $attempt, 'pending'
        );

        _webhook_send(
            ['id' => $row['subscription_id'], 'target_url' => $row['target_url'], 'hmac_secret' => $row['hmac_secret']],
            $body, $signature, $deliveryId, $attempt
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
function _webhook_send($sub, $body, $signature, $deliveryId, $attempt) {
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

    $ch = curl_init($sub['target_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Webhook-Signature: sha256=' . $signature,
            'User-Agent: TicketsCAD-Webhook/4.0'
        ],
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
function _webhook_log_delivery($subscriptionId, $eventType, $payload, $attempt, $status) {
    $prefix = $GLOBALS['db_prefix'] ?? '';

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
