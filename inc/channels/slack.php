<?php
/**
 * Channel: Slack
 *
 * Integrates with Slack for sending/receiving messages.
 * Uses Slack Web API (OAuth tokens) or incoming webhooks.
 *
 * Configuration:
 *   slack_mode     = 'webhook' | 'api'
 *   slack_webhook  = Incoming webhook URL
 *   slack_token    = Bot OAuth token (for API mode)
 *   slack_channel  = Default channel (#channel or channel ID)
 *
 * Phase 134 Step 2 (2026-08, GH #23 Model 3) made _slack_receive() real
 * (previously an unbounded, unfiltered conversations.history passthrough).
 * Polling is NOT wired to a scheduler by this step — nothing calls
 * _slack_receive() on a schedule until Phase 134 Step 4 lands. See
 * specs/phase-134-inbound-routing-model3/plan.md §3. Internal-only
 * settings this step adds: slack_last_ts (cursor), slack_resolved_
 * channel_id / slack_resolved_channel_for (name->ID cache).
 */

broker_register('slack', [
    'name'       => 'Slack',
    'send'       => '_slack_send',
    'receive'    => '_slack_receive',
    'status'     => '_slack_status',
    // Phase 134 §1: capability flag a later poller (Step 4, not built yet)
    // reads to decide which registered channels it is allowed to call
    // receive() on. A channel that never sets 'pollable' is structurally
    // excluded — no allowlist to keep in sync.
    'pollable'   => true,
    // Read by broker_receive() (Phase 134 Step 4) to compute the dedupe
    // table's external_id for a message returned from receive().
    'dedupe_key' => 'ts',
    // GH #84 (2026-08-19): _slack_send() below ignores $message['to'] —
    // the destination is always the configured `slack_channel`. Read by
    // inc/notification-engine.php so a notification rule fires this
    // channel exactly once per rule match instead of once per recipient
    // (which would re-post the same message N times to the same channel).
    'shared_destination' => true,
]);

function _slack_send(array $message) {
    $config = _slack_get_config();
    $mode = $config['slack_mode'] ?? '';

    if (!$mode) {
        return ['success' => false, 'error' => 'Slack not configured'];
    }

    $body = $message['body'] ?? '';

    // The destination is PINNED to configuration. It used to read
    // `$message['slack_channel'] ?? $config['slack_channel']`, letting the
    // message array choose where it went.
    //
    // Nothing could reach that today — every broker_send() call site builds its
    // message from a fixed key list, and the one path that forwards an array
    // wholesale (inc/router.php's route forwarding) only rewrites body,
    // priority and type. But two receive handlers return RAW third-party JSON
    // into that path (_slack_receive() and _sms_receive()), so the only thing
    // standing between an inbound message and an attacker-chosen destination
    // was the shape of somebody else's response schema. If a top-level
    // slack_channel ever became settable — a provider schema change, a new
    // ingest endpoint that json_decode()s a request body into a message array —
    // every routed message would go to a channel of their choosing: incident
    // address and type, patient counts, responder callsign and coordinates,
    // with the routing log recording `forwarded` and success.
    //
    // "No path reaches it today" is exactly the state assigns.rec_facility_id
    // and un_status.extra_data_target were in. Grepped first: no file in the
    // tree sets slack_channel in a message array, so pinning removes nothing
    // that works.
    //
    // A per-message destination may be a reasonable feature later — routing
    // weather to one channel and dispatch to another is a fair thing to want.
    // It is not this: it would need an admin-configured allowlist and the
    // router's own trust marker (`_is_routed_forward`), not an unchecked key.
    // This channel id is bound to the bot credential, not a per-message
    // recipient like `to`.
    $channel = $config['slack_channel'] ?? '#general';

    if (!$body) {
        return ['success' => false, 'error' => 'Message body required'];
    }

    if ($mode === 'webhook') {
        return _slack_send_webhook($config, $body, $channel);
    }

    if ($mode === 'api') {
        return _slack_send_api($config, $body, $channel);
    }

    return ['success' => false, 'error' => "Unknown Slack mode: $mode"];
}

function _slack_send_webhook(array $config, $body, $channel) {
    $url = $config['slack_webhook'] ?? '';
    if (!$url) {
        return ['success' => false, 'error' => 'Slack webhook URL not configured'];
    }

    $payload = json_encode([
        'channel' => $channel,
        'text'    => $body,
        'username' => 'TicketsCAD',
        'icon_emoji' => ':rotating_light:'
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === 'ok' || $httpCode === 200) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => "Slack webhook failed: HTTP $httpCode — $resp"];
}

function _slack_send_api(array $config, $body, $channel) {
    $token = $config['slack_token'] ?? '';
    if (!$token) {
        return ['success' => false, 'error' => 'Slack bot token not configured'];
    }

    $payload = json_encode([
        'channel' => $channel,
        'text'    => $body
    ]);

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!empty($data['ok'])) {
        return ['success' => true, 'ts' => $data['ts'] ?? null];
    }
    return ['success' => false, 'error' => 'Slack API: ' . ($data['error'] ?? 'unknown error')];
}

/**
 * Poll Slack's conversations.history for messages in the configured
 * channel.
 *
 * Thin wrapper: resolves the channel id, performs the HTTP fetch, then
 * hands the decoded response to _slack_parse_messages() — a pure
 * function with no curl, no database, no globals — for the bot-filtering
 * and cursor-advancement logic. See tests/test_phase134_receivers.php for
 * the fake-response coverage of _slack_parse_messages() and
 * _slack_should_resolve_channel().
 */
function _slack_receive($limit = 50) {
    $config = _slack_get_config();
    if (($config['slack_mode'] ?? '') !== 'api') return [];

    $token = $config['slack_token'] ?? '';
    if (!$token) return [];

    $channelId = _slack_resolve_channel_id($config);
    if (!$channelId) return [];

    $lastTs = trim((string) ($config['slack_last_ts'] ?? ''));

    $params = [
        'channel' => $channelId,
        'limit'   => $limit,
    ];
    // 'oldest' defaults to EXCLUSIVE (no 'inclusive' param sent), so a
    // stored last_ts equal to the newest message we already returned is
    // correctly excluded from the next page rather than re-delivered.
    if ($lastTs !== '') {
        $params['oldest'] = $lastTs;
    }

    $ch = curl_init('https://slack.com/api/conversations.history?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        error_log('[slack] conversations.history request failed: ' . $err);
        return [];
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        error_log('[slack] conversations.history unexpected response: ' . substr((string) $resp, 0, 500));
        return [];
    }

    [$messages, $newLastTs] = _slack_parse_messages($data, $channelId);
    if ($newLastTs !== null && $newLastTs !== $lastTs) {
        _slack_set_last_ts($newLastTs);
    }
    return $messages;
}

/**
 * Pure filter/cursor logic for a decoded Slack conversations.history
 * response. No curl, no database, no globals — split out of
 * _slack_receive() the same way as Telegram's _telegram_parse_updates(),
 * so it is unit-testable with a hand-built fake API response.
 *
 * Slack's conversations.history returns the BOT'S OWN posts — a
 * channel-specific quirk (Telegram's getUpdates never does this; see
 * plan.md §3). Any message with a truthy bot_id, subtype ===
 * 'bot_message', or ANY non-empty subtype (channel_join, channel_leave,
 * etc. — not real user text) is filtered out before it can reach the
 * broker/routing engine.
 *
 * The cursor still advances across FILTERED-OUT messages — using the
 * newest `ts` seen in the RAW response, not just the messages that
 * survived filtering — or the bot's own chatter would pin `oldest`
 * forever and conversations.history would keep re-returning the same
 * window.
 *
 * @param array  $apiResponse Decoded JSON, e.g. ['ok'=>true,'messages'=>[...]].
 *                             Caller has already checked ['ok'] truthy.
 * @param string $channelId   The resolved channel id, stamped onto each
 *                             returned message's 'to' (conversations.history
 *                             itself does not echo the channel per message).
 * @return array{0: array, 1: ?string} [$messages, $newLastTs]. $newLastTs
 *         is null when the raw response carried no messages at all
 *         (nothing to advance past).
 */
function _slack_parse_messages(array $apiResponse, string $channelId = ''): array {
    $raw = $apiResponse['messages'] ?? [];
    if (!is_array($raw)) $raw = [];

    $messages = [];
    $newestTs = null;

    foreach ($raw as $m) {
        if (!is_array($m)) continue;

        $ts = isset($m['ts']) ? (string) $m['ts'] : '';
        if ($ts !== '' && ($newestTs === null || _slack_ts_gt($ts, $newestTs))) {
            $newestTs = $ts;
        }

        $isBotOrSystem = !empty($m['bot_id'])
            || ($m['subtype'] ?? '') === 'bot_message'
            || !empty($m['subtype']);
        if ($isBotOrSystem) continue;

        $text = trim((string) ($m['text'] ?? ''));
        if ($text === '') continue;

        $messages[] = [
            'from'     => (string) ($m['user'] ?? ''),
            'body'     => $text,
            'to'       => $channelId,
            'type'     => 'message',
            'priority' => 'normal',
            // Matches the 'dedupe_key' declared in broker_register() above
            // — broker_receive() (Phase 134 Step 4) reads this key to
            // compute the inbound_message_dedupe table's external_id.
            'ts'       => $ts,
        ];
    }

    return [$messages, $newestTs];
}

/**
 * Compare two Slack message timestamps ("1622547800.000200" — Unix
 * seconds, dot, zero-padded microseconds). Slack timestamps are unique
 * per-workspace at microsecond resolution, which exceeds a float64's safe
 * integer precision once seconds and microseconds are combined into one
 * number — so this compares the two parts as integers rather than casting
 * the whole string to float.
 */
function _slack_ts_gt(string $a, string $b): bool {
    $aParts = explode('.', $a, 2);
    $bParts = explode('.', $b, 2);
    $aSec = (int) ($aParts[0] ?? 0);
    $bSec = (int) ($bParts[0] ?? 0);
    if ($aSec !== $bSec) return $aSec > $bSec;
    $aFrac = (int) str_pad((string) ($aParts[1] ?? '0'), 6, '0');
    $bFrac = (int) str_pad((string) ($bParts[1] ?? '0'), 6, '0');
    return $aFrac > $bFrac;
}

/**
 * Decide whether the Slack channel-name -> ID lookup (conversations.list)
 * needs to run at all. Split out from _slack_resolve_channel_id() so the
 * short-circuit behaviour is unit-testable without touching curl.
 *
 *   - Already an ID (C.../G.../D... shape)                -> never resolve.
 *   - No cached id, or cached FOR a different channel name
 *     (an admin changed the Settings field)                -> resolve.
 *   - Cache present and still for the configured name       -> reuse it.
 */
function _slack_should_resolve_channel(string $configuredChannel, ?string $cachedId, ?string $cachedFor): bool {
    if ($configuredChannel === '') return false;
    if (preg_match('/^[CGD][A-Z0-9]{8,}$/', $configuredChannel)) return false;
    if ($cachedId === null || $cachedId === '') return true;
    if ($cachedFor !== $configuredChannel) return true;
    return false;
}

/**
 * Resolve the configured slack_channel (a "#name" or a raw ID) to a
 * channel ID, caching the result so conversations.list is called only
 * when there is no usable cache — see _slack_should_resolve_channel().
 *
 * Scoped to types=public_channel ONLY: asking for private_channel in the
 * SAME conversations.list call fails the WHOLE call with a blanket
 * missing_scope error, even when the app has been granted only public
 * access (plan.md §3 / openises/TicketsCAD#23 reporter's second comment).
 * A private channel the bot has been invited to must be configured by its
 * ID directly, which skips this lookup entirely via the ID-shape check in
 * _slack_should_resolve_channel().
 */
function _slack_resolve_channel_id(array $config) {
    $configuredChannel = trim((string) ($config['slack_channel'] ?? ''));
    if ($configuredChannel === '') return null;

    $cachedId  = $config['slack_resolved_channel_id'] ?? null;
    $cachedFor = $config['slack_resolved_channel_for'] ?? null;

    if (!_slack_should_resolve_channel($configuredChannel, $cachedId, $cachedFor)) {
        if (preg_match('/^[CGD][A-Z0-9]{8,}$/', $configuredChannel)) return $configuredChannel;
        return $cachedId;
    }

    $token = $config['slack_token'] ?? '';
    if (!$token) return null;

    $wanted = ltrim($configuredChannel, '#');

    $ch = curl_init('https://slack.com/api/conversations.list?' . http_build_query([
        'types' => 'public_channel',
        'limit' => 200,
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        error_log('[slack] conversations.list request failed: ' . $err);
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        error_log('[slack] conversations.list failed: ' . substr((string) $resp, 0, 300));
        return null;
    }

    // First page only (limit=200) — a workspace with more public channels
    // than that would need pagination via response_metadata.next_cursor,
    // not implemented here. Out of scope for this step; the admin can
    // configure the channel by ID directly to sidestep it entirely.
    foreach (($data['channels'] ?? []) as $c) {
        if (($c['name'] ?? null) === $wanted) {
            _slack_set_resolved_channel((string) $c['id'], $configuredChannel);
            return (string) $c['id'];
        }
    }

    error_log("[slack] could not resolve channel name '{$configuredChannel}' via conversations.list");
    return null;
}

/**
 * Persist the resolved channel-name -> ID cache to the `settings` table,
 * same store/rule as _telegram_set_update_offset() above (GH #79: the
 * runtime-settings store is `settings`, never the separate `config`
 * table). Stores WHICH configured name it was resolved for, so a later
 * change to the Settings field invalidates the cache automatically (see
 * _slack_should_resolve_channel()).
 */
function _slack_set_resolved_channel(string $id, string $forChannel) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('slack_resolved_channel_id', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$id]
        );
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('slack_resolved_channel_for', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$forChannel]
        );
    } catch (Exception $e) {
        error_log('[slack] could not persist resolved channel id: ' . $e->getMessage());
    }
}

/**
 * Persist the conversations.history cursor. Same store/rule as
 * _telegram_set_update_offset() — direct query, not get_variable()'s
 * request-scoped cache, so a poller tick that reads it back later in the
 * SAME process sees the write.
 */
function _slack_set_last_ts(string $ts) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('slack_last_ts', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$ts]
        );
    } catch (Exception $e) {
        error_log('[slack] could not persist last_ts: ' . $e->getMessage());
    }
}

function _slack_get_config() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = [
        'slack_mode', 'slack_webhook', 'slack_token', 'slack_channel',
        'slack_last_ts', 'slack_resolved_channel_id', 'slack_resolved_channel_for',
    ];
    $config = [];
    foreach ($keys as $k) {
        try {
            $val = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
            $config[$k] = $val;
        } catch (Exception $e) {
            $config[$k] = null;
        }
    }
    return $config;
}

function _slack_status() {
    $config = _slack_get_config();
    $mode = $config['slack_mode'] ?? '';
    if (!$mode) return 'not_configured';
    if ($mode === 'webhook' && $config['slack_webhook']) return 'configured';
    if ($mode === 'api' && $config['slack_token']) return 'configured';
    return 'not_configured';
}
