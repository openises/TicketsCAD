<?php
/**
 * Channel: Telegram
 *
 * Posts incident / PAR / system alerts to a Telegram chat via a bot, using
 * the Telegram Bot API (sendMessage), and — as of Phase 134 Step 2
 * (2026-08, GH #23 Model 3) — can poll that bot's inbound messages via
 * getUpdates. Polling is NOT wired to a scheduler by this step: nothing
 * calls _telegram_receive() on a schedule until Phase 134 Step 4 (the
 * poller) lands. See specs/phase-134-inbound-routing-model3/plan.md §3.
 *
 * Configuration (Settings → Telegram):
 *   telegram_bot_token     = Bot token from @BotFather
 *   telegram_chat_id       = Target group chat ID (negative, e.g. -100123456789)
 *   telegram_update_offset = getUpdates() cursor; managed internally, not
 *                            a user-facing setting.
 *
 * Setup: docs/TELEGRAM-SETUP-GUIDE.md
 */

broker_register('telegram', [
    'name'       => 'Telegram',
    'send'       => '_telegram_send',
    'receive'    => '_telegram_receive',
    'status'     => '_telegram_status',
    // Phase 134 §1: capability flag a later poller (Step 4, not built yet)
    // reads to decide which registered channels it is allowed to call
    // receive() on. A channel that never sets 'pollable' (local_chat and
    // everything else) is structurally excluded — no allowlist to keep in
    // sync, and no repeat of re-ingesting our own outbound traffic.
    'pollable'   => true,
    // Read by broker_receive() (Phase 134 Step 4) to compute the dedupe
    // table's external_id for a message returned from receive().
    'dedupe_key' => 'update_id',
    // GH #84 (2026-08-19): _telegram_send() below ignores $message['to'] —
    // the destination is always the configured `telegram_chat_id`. Read by
    // inc/notification-engine.php so a notification rule fires this
    // channel exactly once per rule match instead of once per recipient
    // (which would re-post the same message N times to the same chat).
    'shared_destination' => true,
]);

/**
 * Telegram bot tokens are `<numeric bot id>:<35-ish char secret>`. Chat ids
 * are integers, negative for groups and supergroups. Validating both lets a
 * mistyped or whitespace-padded value fail with something an admin can act
 * on, rather than an opaque 404 from Telegram.
 */
define('TELEGRAM_TOKEN_RE',   '/^\d+:[A-Za-z0-9_-]{20,}$/');
define('TELEGRAM_CHAT_ID_RE', '/^-?\d{1,20}$/');

function _telegram_send(array $message) {
    $config = _telegram_get_config();
    $token  = trim((string) ($config['telegram_bot_token'] ?? ''));

    // The destination is bound to the bot credential and comes from
    // configuration ONLY — deliberately not overridable per message.
    //
    // inc/router.php forwards a matched message array wholesale to the
    // destination adapter (_router_transform() rewrites body/priority/type
    // and leaves every other key intact), and two receive handlers return
    // raw third-party JSON into that path — _slack_receive() returns
    // $data['messages'], _sms_receive() returns $data['threads']. Neither
    // provider currently lets a message author set an arbitrary top-level
    // key, so an override here is unreachable today; but that is a property
    // of someone else's response schema, not of this codebase, and nothing
    // tells us when it changes. Reading the chat id from config removes the
    // question entirely.
    //
    // A per-message destination may be worth having later (routing weather
    // to one chat and dispatch to another), but it needs an admin-configured
    // allowlist plus the router's _is_routed_forward trust marker — not an
    // unchecked key. See openises/TicketsCAD#10 review.
    $chatId = trim((string) ($config['telegram_chat_id'] ?? ''));

    if ($token === '' || $chatId === '') {
        return ['success' => false, 'error' => 'Telegram not configured'];
    }
    if (!preg_match(TELEGRAM_TOKEN_RE, $token)) {
        return ['success' => false, 'error' => 'Telegram bot token is malformed (expected "123456:ABC-DEF..." from @BotFather)'];
    }
    if (!preg_match(TELEGRAM_CHAT_ID_RE, $chatId)) {
        return ['success' => false, 'error' => 'Telegram chat ID is malformed (expected an integer; group IDs are negative, e.g. -100123456789)'];
    }

    $body = $message['body'] ?? '';
    if (!$body) {
        return ['success' => false, 'error' => 'Message body required'];
    }

    $payload = json_encode([
        'chat_id' => $chatId,
        'text'    => $body,
    ]);

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    // Stated explicitly rather than relying on defaults: the defaults are
    // safe, but a host with unusual curl.* ini settings changes that, and a
    // reader cannot otherwise tell "safe by default" from "nobody checked".
    // Matches inc/webhooks.php and api/dmr-lookup.php.
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $payload,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 5,
        // Sends are synchronous inside broker_send(), so a route fanning out
        // to Telegram adds up to this many seconds to the request that
        // triggered it.
        CURLOPT_TIMEOUT         => 10,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'error' => 'Telegram request failed: ' . $err];
    }

    $data = json_decode($resp, true);
    if (!empty($data['ok'])) {
        return ['success' => true, 'message_id' => $data['result']['message_id'] ?? null];
    }
    // Surface Telegram's own `description` when it sends one — those are
    // actionable ("chat not found", "bot was blocked by the user"). When it
    // doesn't, return a fixed string rather than echoing the raw body into
    // the admin UI; log the body instead so it's still diagnosable. The
    // token lives in the URL, not the body, so it is not logged here.
    if (isset($data['description'])) {
        return ['success' => false, 'error' => 'Telegram API: ' . $data['description']];
    }
    error_log('[telegram] unexpected sendMessage response: ' . substr((string) $resp, 0, 500));
    return ['success' => false, 'error' => 'Telegram API returned an unexpected response (see error log)'];
}

/**
 * Poll Telegram's getUpdates for messages in the configured chat.
 *
 * Thin wrapper: performs the HTTP fetch, then hands the decoded response
 * to _telegram_parse_updates() — a pure function with no curl, no
 * database, no globals — for the filtering/offset-advancement logic. That
 * split is what makes the interesting part of this function testable:
 * this codebase has no curl-mocking convention (grepped tests/*.php —
 * existing curl-driven adapters are either exercised live, or, for pure
 * security properties, driven through a child PHP process that never
 * reaches curl_exec() at all; see tests/test_telegram_channel_security.php
 * group A). See tests/test_phase134_receivers.php for the fake-response
 * coverage of _telegram_parse_updates().
 *
 * Fails closed (empty array, no request made) on missing or malformed
 * configuration, same guard order as _telegram_send(). Not wired to a
 * scheduler as of this commit — see file header.
 */
function _telegram_receive($limit = 50) {
    $config = _telegram_get_config();
    $token  = trim((string) ($config['telegram_bot_token'] ?? ''));
    $chatId = trim((string) ($config['telegram_chat_id'] ?? ''));

    if ($token === '' || $chatId === '') return [];
    if (!preg_match(TELEGRAM_TOKEN_RE, $token)) return [];
    if (!preg_match(TELEGRAM_CHAT_ID_RE, $chatId)) return [];

    $offset = (int) ($config['telegram_update_offset'] ?? 0);

    // 'timeout' => 0 is Telegram's OWN long-poll parameter, not a cURL
    // option — 0 means "answer immediately with whatever's pending" rather
    // than holding the HTTP connection open waiting for new messages. A
    // scheduled tick controls its own polling cadence (Phase 134 Step 4);
    // this adapter must never itself block for Telegram's long-poll window.
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/getUpdates?' . http_build_query([
        'offset'  => $offset,
        'limit'   => max(1, min(100, (int) $limit)),
        'timeout' => 0,
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_TIMEOUT         => 10,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        error_log('[telegram] getUpdates request failed: ' . $err);
        return [];
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        error_log('[telegram] getUpdates unexpected response: ' . substr((string) $resp, 0, 500));
        return [];
    }

    [$messages, $newOffset] = _telegram_parse_updates($data, $chatId, $offset);
    if ($newOffset !== $offset) {
        _telegram_set_update_offset($newOffset);
    }
    return $messages;
}

/**
 * Pure parse/filter/offset logic for a decoded Telegram getUpdates
 * response. No curl, no database, no globals — everything it needs is a
 * parameter, which is what lets tests/test_phase134_receivers.php drive it
 * with a hand-built fake API response instead of mocking curl.
 *
 * @param array  $apiResponse   Decoded JSON, e.g. ['ok'=>true,'result'=>[...]].
 *                               Caller has already checked ['ok'] truthy.
 * @param string $chatId        The configured telegram_chat_id to filter to
 *                               (string compare — chat ids are negative
 *                               integers for groups/supergroups, and
 *                               json_decode() keeps them as real PHP ints
 *                               within 64-bit range, so casting both sides
 *                               to string is exact, not lossy).
 * @param int    $currentOffset The offset the fetch was made with — the
 *                               value returned unchanged when $apiResponse
 *                               carries no updates at all.
 * @return array{0: array, 1: int} [$messages, $newOffset]. $newOffset
 *         advances past the MAX update_id seen across EVERY update in the
 *         response, matching or not — an unrelated chat's traffic must
 *         never pin the cursor (plan.md §3): offset=N tells Telegram "give
 *         me updates after N", so a cursor that only advances on a match
 *         would re-fetch the same non-matching updates forever.
 */
function _telegram_parse_updates(array $apiResponse, string $chatId, int $currentOffset): array {
    $updates = $apiResponse['result'] ?? [];
    if (!is_array($updates)) $updates = [];

    $messages    = [];
    $maxUpdateId = null;

    foreach ($updates as $update) {
        if (!is_array($update)) continue;

        $updateId = isset($update['update_id']) ? (int) $update['update_id'] : null;
        if ($updateId !== null && ($maxUpdateId === null || $updateId > $maxUpdateId)) {
            $maxUpdateId = $updateId;
        }

        // Only plain text messages are handled — edited_message,
        // callback_query, etc. still count toward the offset above (so
        // they are never re-fetched) but produce no routed message.
        $msg = $update['message'] ?? null;
        if (!is_array($msg)) continue;

        $chat      = $msg['chat'] ?? [];
        $msgChatId = isset($chat['id']) ? (string) $chat['id'] : '';
        if ($msgChatId === '' || $msgChatId !== $chatId) continue; // unrelated chat

        $text = trim((string) ($msg['text'] ?? ''));
        if ($text === '') continue; // no text body (sticker/photo/etc.) — nothing to route

        $from   = $msg['from'] ?? [];
        $sender = '';
        if (!empty($from['username'])) {
            $sender = (string) $from['username'];
        } elseif (isset($from['id'])) {
            $sender = (string) $from['id'];
        }

        $messages[] = [
            'from'      => $sender,
            'body'      => $text,
            'to'        => $msgChatId,
            'type'      => 'message',
            'priority'  => 'normal',
            // Matches the 'dedupe_key' declared in broker_register() above
            // — broker_receive() (Phase 134 Step 4) reads this key to
            // compute the inbound_message_dedupe table's external_id.
            'update_id' => $updateId,
        ];
    }

    $newOffset = ($maxUpdateId !== null) ? ($maxUpdateId + 1) : $currentOffset;
    return [$messages, $newOffset];
}

/**
 * Persist the getUpdates cursor to the `settings` table (name/value) — the
 * SAME store _telegram_get_config() reads, per this codebase's GH #79
 * rule: the runtime-settings store is `settings`, never the separate
 * `config` table (get_setting()). A direct db query is used here rather
 * than get_variable()'s request-scoped static cache deliberately — a
 * poller tick calls _telegram_receive() and may read the offset again
 * later in the SAME process, and get_variable()'s cache would still show
 * the pre-write value.
 */
function _telegram_set_update_offset(int $offset) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('telegram_update_offset', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [(string) $offset]
        );
    } catch (Exception $e) {
        error_log('[telegram] could not persist update offset: ' . $e->getMessage());
    }
}

function _telegram_get_config() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = ['telegram_bot_token', 'telegram_chat_id', 'telegram_update_offset'];
    $config = [];
    foreach ($keys as $k) {
        try {
            $val = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
            $config[$k] = $val;
        } catch (Exception $e) {
            // Treat as unconfigured (_telegram_send then reports "not
            // configured"), but don't swallow the reason — a settings-table
            // failure here is otherwise indistinguishable from a blank field.
            $config[$k] = null;
            error_log("[telegram] could not read setting '{$k}': " . $e->getMessage());
        }
    }
    return $config;
}

function _telegram_status() {
    $config = _telegram_get_config();
    $token  = trim((string) ($config['telegram_bot_token'] ?? ''));
    $chatId = trim((string) ($config['telegram_chat_id'] ?? ''));
    if ($token === '' || $chatId === '') return 'not_configured';
    // Report malformed credentials as not-configured rather than configured:
    // a status of "configured" that cannot send is worse than an honest one.
    if (!preg_match(TELEGRAM_TOKEN_RE, $token))     return 'not_configured';
    if (!preg_match(TELEGRAM_CHAT_ID_RE, $chatId))  return 'not_configured';
    return 'configured';
}
