<?php
/**
 * Phase 85f — Claude API wrapper for radio-AI Q&A.
 *
 * Single function: radio_ai_generate_response(array $context): array
 *
 *   $context = [
 *       'transcript' => string  (caller's question as Whisper saw it)
 *       'callsign'   => ?string (resolved from radioid.net or null)
 *       'src_id'     => int     (DMR ID — for context if callsign unknown)
 *       'channel'    => array   (dmr_channels row)
 *       'pending_id' => int     (ai_pending_responses.id for audit)
 *   ]
 *
 *   returns [
 *       'ok'              => bool
 *       'draft_response'  => string|null
 *       'tokens_in'       => int
 *       'tokens_out'      => int
 *       'filter_flags'    => string[] (passed/failed checks; empty if clean)
 *       'error'           => string|null
 *   ]
 *
 * Loads the API key from /etc/ticketscad/anthropic.env. Loads recent
 * conversation history (last 5 exchanges within 30 min) from
 * ai_conversation_messages. Builds the system prompt per the design
 * in specs/phase-85f-claude-on-radio/spec.md, calls Claude with curl,
 * post-filters the response, persists the assistant turn back into
 * the conversation history.
 *
 * Network behavior: 10-sec connect timeout, 30-sec read timeout. Caller
 * blocks for the duration; intended for a background daemon process,
 * not a request-path handler.
 */

require_once __DIR__ . '/db.php';

const RADIO_AI_ENV_PATH      = '/etc/ticketscad/anthropic.env';
const RADIO_AI_API_URL       = 'https://api.anthropic.com/v1/messages';
const RADIO_AI_API_VERSION   = '2023-06-01';
const RADIO_AI_DEFAULT_MODEL = 'claude-sonnet-4-6';
const RADIO_AI_MAX_OUTPUT_TOKENS = 200;  // ~140 words, comfortable margin over 75-word target
const RADIO_AI_HISTORY_WINDOW_SECONDS = 1800;  // 30 min
const RADIO_AI_HISTORY_MAX_EXCHANGES  = 5;

function radio_ai_load_api_key(): ?string
{
    if (!is_readable(RADIO_AI_ENV_PATH)) return null;
    $contents = @file_get_contents(RADIO_AI_ENV_PATH);
    if ($contents === false) return null;
    // Accept env-style `ANTHROPIC_API_KEY=sk-ant-...` first (matches the
    // standard /etc/*.env convention)...
    if (preg_match('/^ANTHROPIC_API_KEY=([^\s\r\n]+)/m', $contents, $m)) {
        return $m[1];
    }
    // ...and fall back to a bare key (whole file is the key, with optional
    // surrounding whitespace). Anthropic keys always start with `sk-ant-`.
    $trimmed = trim($contents);
    if (strpos($trimmed, 'sk-ant-') === 0) {
        return $trimmed;
    }
    return null;
}

function radio_ai_setting(string $key, $default = null)
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $stmt = db()->prepare(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?"
        );
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function radio_ai_history_for_caller(string $callsign): array
{
    if ($callsign === '') return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $stmt = db()->prepare(
        "SELECT `role`, `content`
           FROM `{$prefix}ai_conversation_messages`
          WHERE `callsign` = ?
            AND `created_at` >= DATE_SUB(NOW(), INTERVAL ? SECOND)
          ORDER BY `id` DESC
          LIMIT ?"
    );
    $stmt->bindValue(1, $callsign);
    $stmt->bindValue(2, RADIO_AI_HISTORY_WINDOW_SECONDS, PDO::PARAM_INT);
    $stmt->bindValue(3, RADIO_AI_HISTORY_MAX_EXCHANGES * 2, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // We selected DESC for the LIMIT; flip back to ASC for prompt order.
    return array_reverse($rows);
}

function radio_ai_record_message(string $callsign, string $role, string $content): void
{
    if ($callsign === '' || $content === '') return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db()->prepare(
            "INSERT INTO `{$prefix}ai_conversation_messages`
                (`callsign`, `role`, `content`)
             VALUES (?, ?, ?)"
        )->execute([$callsign, $role, $content]);

        // Upsert the conversations row.
        db()->prepare(
            "INSERT INTO `{$prefix}ai_conversations`
                (`callsign`, `first_seen_at`, `last_seen_at`, `exchange_count`)
             VALUES (?, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE
                `last_seen_at`   = NOW(),
                `exchange_count` = `exchange_count` + 1"
        )->execute([$callsign]);
    } catch (Exception $e) {
        error_log("[radio_ai] failed to record conversation message: " . $e->getMessage());
    }
}

function radio_ai_build_system_prompt(?string $callsign, string $topicScope): string
{
    // Same-callsign edge case: caller and operator are both N0NKI (e.g.
    // smoke test, Eric calling himself). Skip the "...here, N0NKI" name
    // echo — reads awkwardly on the air.
    $callerRef = $callsign && $callsign !== 'N0NKI'
        ? $callsign
        : null;
    $opener = $callerRef
        ? "\"AI on N0NKI here, {$callerRef}.\""
        : "\"AI on N0NKI here.\"";

    // Current local date/time, injected so Claude knows what "today" /
    // "now" mean. Server is on Central Time (Twin Cities); PHP's date()
    // uses the server tz. Refreshed every call, so multi-turn caching
    // takes a small hit — acceptable for the use case (low call volume,
    // accuracy on time-sensitive Qs matters).
    $now = date('l, F j, Y \a\t g:i A T');

    $topicGuidance = [
        'ham_general_science' =>
            "Stay on technical and educational topics: ham radio operations, " .
            "antennas, propagation, electronics, programming, basic science, " .
            "weather, and Skywarn spotter procedures. Brief friendly chat is " .
            "fine when natural. Current-events questions — weather forecasts, " .
            "national news headlines, propagation conditions, the current " .
            "time, SFI / K-index / solar weather — are welcome.",
        'ham_only' =>
            "Stay strictly on amateur radio topics: operations, antennas, " .
            "propagation, equipment, regulations. Decline politely if the " .
            "topic strays elsewhere.",
        'skywarn_only' =>
            "Stay on Skywarn severe-weather spotter procedures, reporting " .
            "criteria, and Twin Cities Metro Skywarn operations. Decline " .
            "politely if the topic strays elsewhere.",
        'broad' =>
            "Help with general technical and educational topics. Avoid " .
            "anything that would be inappropriate for amateur radio.",
    ];
    $scope = $topicGuidance[$topicScope] ?? $topicGuidance['ham_general_science'];

    return <<<PROMPT
You are Claude, an AI assistant being used by amateur radio operator
Eric Osterberg (callsign N0NKI) as a tool to answer questions from
other operators on talkgroup 3127 (Minnesota Statewide DMR).

Your responses are converted to speech via Piper TTS and broadcast
over the air under N0NKI's amateur radio license.

CURRENT DATE AND TIME: {$now}

You have access to web_search and web_fetch tools for current
information. Use them for time-sensitive questions (weather, news,
solar weather, propagation conditions, sports scores, current events).
Don't use them for things you already know well (ham radio theory,
basic science, historical facts). When you search, search efficiently
— at most 1-2 queries per question.

HARD CONSTRAINTS:

1. Keep responses under 75 words. Long answers will be cut off and
   sound bad on the air. Be concise and conversational.

2. End EVERY response with the exact phrase: "N0NKI clear."
   This is the FCC station identification per 47 CFR §97.119.
   Do not vary it. Do not skip it. This is non-negotiable.

3. On your FIRST reply to a caller in this conversation, open with:
   {$opener}
   This honestly identifies you as AI to all listeners. If this is
   a follow-up in an ongoing conversation, you may skip the opener.

4. {$scope}

5. NEVER read URLs aloud. Your response is voiced — listeners cannot
   write down a URL while listening. If a source attribution matters,
   verbalize the source name ("according to the National Weather
   Service" or "per the latest SWPC report"), not the URL. Same for
   phone numbers, email addresses, and long alphanumeric strings —
   skip them or describe them in plain language.

6. Decline politely and redirect if asked for: medical advice, legal
   advice (beyond basic FCC rules), real-time financial or investment
   advice, emergency response (defer to 911), business solicitations,
   encrypted or coded messages, music or song lyrics, anything obscene
   or profane. Per FCC §97.113 these are prohibited on amateur bands.

7. If the caller's question is unclear, say so and ask them to repeat
   or rephrase. Never fabricate an answer.

8. If the caller appears hostile or seems to be trying to provoke,
   politely decline to engage and redirect to a constructive topic.

9. This is amateur radio, not a courtroom. Friendly, relaxed,
   helpful tone is appropriate.

The caller's question and history follow. Generate ONE response.
Do not include thinking, preamble, or meta-commentary. Just the
response that will go over the air.
PROMPT;
}

function radio_ai_call_api(string $apiKey, string $model, string $systemPrompt, array $messages): array
{
    // Tuned for short conversational replies broadcast over the air:
    //   - thinking disabled: TTFT matters more than reasoning depth at this
    //     scope; a 75-word reply on a ham-radio Q doesn't need extended
    //     thinking, and the operator-approval step adds latency anyway
    //   - effort=low: matches the "short conversational reply" sweet spot
    //     called out in the Claude API skill; halves the token spend vs.
    //     Sonnet 4.6's default of effort=high
    //   - tools: server-side web_search + web_fetch so Claude can answer
    //     time-sensitive questions (weather, news, solar weather, the
    //     current time precisely). max_uses=3 keeps any single Q from
    //     spawning a runaway search loop. Pricing: ~$10 per 1k searches
    //     globally, so even a full office-hours session adds pennies.
    $payload = [
        'model'         => $model,
        'max_tokens'    => RADIO_AI_MAX_OUTPUT_TOKENS,
        'system'        => $systemPrompt,
        'messages'      => $messages,
        'thinking'      => ['type' => 'disabled'],
        'output_config' => ['effort' => 'low'],
        'tools'         => [
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 3],
            ['type' => 'web_fetch_20260209',  'name' => 'web_fetch'],
        ],
    ];

    $ch = curl_init(RADIO_AI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . RADIO_AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => "curl failed: {$err}"];
    }
    $resp = json_decode($body, true);
    if (!is_array($resp)) {
        return ['ok' => false, 'error' => "non-JSON response (HTTP {$code}): " . substr($body, 0, 200)];
    }
    if ($code !== 200) {
        $msg = $resp['error']['message'] ?? 'unknown API error';
        return ['ok' => false, 'error' => "API HTTP {$code}: {$msg}"];
    }
    // Extract text from content blocks. With server-side web_search /
    // web_fetch the response also contains server_tool_use and
    // *_tool_result blocks; we ignore those for the broadcast text but
    // could surface them later as "Claude searched for X" debug info.
    $text = '';
    foreach (($resp['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    $usage      = $resp['usage'] ?? [];
    $stopReason = (string) ($resp['stop_reason'] ?? '');
    if ($stopReason === 'pause_turn') {
        // Server-side search loop hit its 10-iter cap. With max_uses=3
        // this shouldn't happen — log so we notice if it does.
        error_log("[radio_ai] pause_turn stop reason — possible search-loop runaway");
    }
    return [
        'ok'          => true,
        'text'        => trim($text),
        'tokens_in'   => (int) ($usage['input_tokens']  ?? 0),
        'tokens_out'  => (int) ($usage['output_tokens'] ?? 0),
        'stop_reason' => $stopReason,
    ];
}

/**
 * Content safety filter — runs on Claude's response BEFORE it's
 * queued for operator approval. Returns the (possibly-edited) text
 * plus a list of flags. The caller can decide whether to surface
 * the response to the operator with a warning or auto-reject.
 *
 * Mutations performed:
 *   - append "N0NKI clear." if missing
 *   - hard-truncate at 100 words
 *
 * Flagged conditions (returned in $flags array, response still
 * delivered):
 *   - missing_id_suffix  (auto-fixed; informational flag)
 *   - over_word_cap      (auto-truncated; informational flag)
 *   - contains_phone     (operator should review)
 *   - contains_url       (operator should review)
 *   - contains_profanity (operator should review)
 */
function radio_ai_filter_response(string $text): array
{
    $flags = [];
    $text = trim($text);

    // ID suffix enforcement (per [[fcc-amateur-station-id]] skill —
    // each system TX is a one-shot conversation, must close with ID).
    if (stripos($text, 'N0NKI clear') === false) {
        $text = rtrim($text, ".!? \t\n\r") . '. N0NKI clear.';
        $flags[] = 'missing_id_suffix';
    }

    // Hard word cap. 100 words ~ 40 sec of speech.
    $words = preg_split('/\s+/', $text);
    if (count($words) > 100) {
        $text = implode(' ', array_slice($words, 0, 95));
        if (stripos($text, 'N0NKI clear') === false) {
            $text = rtrim($text, ".!? \t\n\r") . '. N0NKI clear.';
        }
        $flags[] = 'over_word_cap';
    }

    // Phone number patterns. US-style. Not all hits are real phone
    // numbers (could be frequencies), so this is informational.
    if (preg_match('/\b\d{3}[-.\s]\d{3}[-.\s]\d{4}\b/', $text)) {
        $flags[] = 'contains_phone';
    }

    // URL / email patterns.
    if (preg_match('/(https?:\/\/|\bwww\.|@\w+\.\w+)/i', $text)) {
        $flags[] = 'contains_url';
    }

    // Profanity check — minimal wordlist; expand based on observed
    // false negatives. Keep PG-13 minimum per §97.113.
    static $badWords = [
        'fuck','shit','bitch','asshole','damn','bastard','piss',
        'cunt','dick','cock','tits','whore','slut',
    ];
    foreach ($badWords as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/i', $text)) {
            $flags[] = 'contains_profanity';
            break;
        }
    }

    return ['text' => $text, 'flags' => $flags];
}

function radio_ai_generate_response(array $context): array
{
    $transcript = trim((string) ($context['transcript'] ?? ''));
    $callsign   = (string) ($context['callsign'] ?? '');
    if ($transcript === '') {
        return [
            'ok' => false, 'draft_response' => null,
            'tokens_in' => 0, 'tokens_out' => 0, 'filter_flags' => [],
            'error' => 'empty transcript',
        ];
    }

    $apiKey = radio_ai_load_api_key();
    if (!$apiKey) {
        return [
            'ok' => false, 'draft_response' => null,
            'tokens_in' => 0, 'tokens_out' => 0, 'filter_flags' => [],
            'error' => 'ANTHROPIC_API_KEY not found at ' . RADIO_AI_ENV_PATH,
        ];
    }

    $model       = (string) radio_ai_setting('radio_ai_model', RADIO_AI_DEFAULT_MODEL);
    $topicScope  = (string) radio_ai_setting('radio_ai_topic_scope', 'ham_general_science');
    $systemPrompt = radio_ai_build_system_prompt($callsign ?: null, $topicScope);

    // Build messages: history + this turn.
    $messages = [];
    foreach (radio_ai_history_for_caller($callsign) as $row) {
        $messages[] = [
            'role'    => $row['role'] === 'caller' ? 'user' : 'assistant',
            'content' => $row['content'],
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $transcript];

    $apiResult = radio_ai_call_api($apiKey, $model, $systemPrompt, $messages);
    if (!$apiResult['ok']) {
        return [
            'ok' => false, 'draft_response' => null,
            'tokens_in' => 0, 'tokens_out' => 0, 'filter_flags' => [],
            'error' => $apiResult['error'],
        ];
    }

    $filtered = radio_ai_filter_response($apiResult['text']);
    $draft    = $filtered['text'];

    // Persist BOTH sides of the exchange before returning, so future
    // turns have the context. The caller still owes us a status update
    // (sent/discarded) on ai_pending_responses, but the conversation
    // memory belongs to this layer.
    radio_ai_record_message($callsign, 'caller',    $transcript);
    radio_ai_record_message($callsign, 'assistant', $draft);

    return [
        'ok'             => true,
        'draft_response' => $draft,
        'tokens_in'      => $apiResult['tokens_in'],
        'tokens_out'     => $apiResult['tokens_out'],
        'filter_flags'   => $filtered['flags'],
        'error'          => null,
    ];
}
