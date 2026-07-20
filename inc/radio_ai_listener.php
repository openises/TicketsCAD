<?php
/**
 * Phase 85f-3 — Claude-on-radio wake-word listener daemon.
 *
 * Runs as a CLI process under systemd. Polls dmr_messages every few
 * seconds for new inbound transcripts. When a transcript contains the
 * configured wake word ("claude" by default), looks up the caller's
 * callsign, inserts an ai_pending_responses row, calls Claude via
 * radio_ai_client, and updates the row to pending_approval for the
 * operator widget to pick up.
 *
 * Polling vs SSE subscription: polling is dramatically simpler and the
 * latency (~5 sec) is invisible to operators since the operator-approval
 * step adds its own latency anyway. SSE would buy us nothing here.
 *
 * Tracks progress via the radio_ai_last_processed_msg_id setting, so a
 * restart only re-examines the last few seconds of traffic, not the
 * whole history.
 *
 * Usage:
 *   php inc/radio_ai_listener.php
 *
 * Environment:
 *   RADIO_AI_LOOP_INTERVAL   — seconds between polls (default 5)
 *   RADIO_AI_BATCH_SIZE      — max rows per poll (default 10)
 *   RADIO_AI_TRANSCRIPT_AGE  — only process rows newer than this many
 *                              seconds (default 600 = 10 min). Prevents
 *                              backlog processing on startup.
 */

declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/radio_ai_client.php';

const RADIO_AI_LAST_ID_SETTING = 'radio_ai_last_processed_msg_id';

function radio_ai_listener_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    @flush();
}

function radio_ai_listener_get_last_id(string $prefix): int
{
    $stmt = db()->prepare(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?"
    );
    $stmt->execute([RADIO_AI_LAST_ID_SETTING]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function radio_ai_listener_set_last_id(string $prefix, int $id): void
{
    db()->prepare(
        "INSERT INTO `{$prefix}settings` (`name`, `value`)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    )->execute([RADIO_AI_LAST_ID_SETTING, (string) $id]);
}

function radio_ai_listener_extract_question(string $transcript, string $wakeWord): string
{
    // Strip leading "Claude, " or "Hey Claude — " etc. Anything up to
    // and including the wake word and one trailing punctuation/space
    // group is dropped. If the wake word is at position 0, the rest
    // becomes the question; if it's mid-sentence, we keep the whole
    // transcript since the caller may be addressing Claude indirectly
    // ("Claude can probably answer this") and the surrounding text is
    // part of the question.
    $lower = strtolower($transcript);
    $pos   = strpos($lower, $wakeWord);
    if ($pos === false) return trim($transcript);
    // Wake word near the start (within first 4 words) → strip the
    // address phrase and use what follows.
    if ($pos < 30) {
        $after = substr($transcript, $pos + strlen($wakeWord));
        // Drop leading comma, dash, "can you", etc.
        $after = preg_replace('/^[,\-–—\s]*(can you|could you|will you|please)?[,\s]*/i', '', $after);
        return trim($after) ?: trim($transcript);
    }
    return trim($transcript);
}

function radio_ai_listener_resolve_callsign(?string $existing, int $srcId): ?string
{
    // If echo_bot already resolved the callsign from radioid.net,
    // trust that. Otherwise we just don't know — return null and let
    // the system prompt note "callsign not resolved". A future
    // enhancement could hit the radioid.net API here, but the existing
    // dmr-lookup cache is the source of truth.
    if ($existing && trim($existing) !== '') return strtoupper(trim($existing));
    return null;
}

function radio_ai_listener_process_row(array $row, string $prefix, string $wakeWord, array $channelIds): bool
{
    $msgId      = (int) $row['id'];
    $channelId  = (int) $row['channel_id'];
    $srcId      = (int) $row['radio_id'];
    $transcript = (string) ($row['transcript'] ?? '');
    if ($transcript === '') return false;

    // Channel filter (if configured)
    if (!empty($channelIds) && !in_array($channelId, $channelIds, true)) {
        return false;
    }

    // Wake-word match with word boundary
    if (!preg_match('/\b' . preg_quote($wakeWord, '/') . '\b/i', $transcript)) {
        return false;
    }

    $question = radio_ai_listener_extract_question($transcript, strtolower($wakeWord));
    if (strlen($question) < 3) {
        radio_ai_listener_log("msg #{$msgId}: wake word matched but no question — skipping");
        return false;
    }

    $callsign = radio_ai_listener_resolve_callsign($row['radio_callsign'] ?? null, $srcId);

    // Idempotency check: have we already queued this exact message?
    $existing = db()->prepare(
        "SELECT `id` FROM `{$prefix}ai_pending_responses`
          WHERE `inbound_call_id` = ?
          LIMIT 1"
    );
    $existing->execute(["msg-{$msgId}"]);
    if ($existing->fetchColumn()) {
        radio_ai_listener_log("msg #{$msgId}: already queued — skipping duplicate");
        return false;
    }

    // Insert the pending row first so the UI sees something even if the
    // API call below hangs or fails.
    $insert = db()->prepare(
        "INSERT INTO `{$prefix}ai_pending_responses`
            (channel_id, caller_src_id, caller_callsign, inbound_call_id,
             transcript, status)
         VALUES (?, ?, ?, ?, ?, 'pending_generation')"
    );
    $insert->execute([
        $channelId, $srcId, $callsign, "msg-{$msgId}", $question,
    ]);
    $pendingId = (int) db()->lastInsertId();
    radio_ai_listener_log(
        "msg #{$msgId}: queued as pending #{$pendingId} " .
        "(caller=" . ($callsign ?: $srcId) . ", q=\"" .
        substr($question, 0, 60) . "\")"
    );

    // Call Claude. This blocks ~3-15 sec depending on whether web
    // search activates. The listener loop pauses during this; not a
    // problem since simultaneous radio Qs are rare and the operator
    // can't approve multiple drafts at once anyway.
    $result = radio_ai_generate_response([
        'transcript' => $question,
        'callsign'   => $callsign ?: '',
        'radio_id'     => $srcId,
        'pending_id' => $pendingId,
    ]);

    if (!$result['ok']) {
        db()->prepare(
            "UPDATE `{$prefix}ai_pending_responses`
                SET status = 'error', error_msg = ?, decided_at = NOW()
              WHERE id = ?"
        )->execute([substr((string) $result['error'], 0, 500), $pendingId]);
        radio_ai_listener_log(
            "msg #{$msgId}: API error — {$result['error']}"
        );
        return true;
    }

    $hasFilterFlags = !empty($result['filter_flags']);
    $finalStatus    = $hasFilterFlags ? 'filtered' : 'pending_approval';
    db()->prepare(
        "UPDATE `{$prefix}ai_pending_responses`
            SET draft_response = ?,
                status         = ?,
                api_tokens_in  = ?,
                api_tokens_out = ?
          WHERE id = ?"
    )->execute([
        $result['draft_response'],
        $finalStatus,
        $result['tokens_in'],
        $result['tokens_out'],
        $pendingId,
    ]);
    $flagNote = $hasFilterFlags ? ' [flags: ' . implode(',', $result['filter_flags']) . ']' : '';
    radio_ai_listener_log(
        "msg #{$msgId}: draft ready ({$result['tokens_in']}+{$result['tokens_out']} tokens){$flagNote}"
    );
    return true;
}

function radio_ai_listener_loop(): void
{
    $prefix       = $GLOBALS['db_prefix'] ?? '';
    $interval     = (int) (getenv('RADIO_AI_LOOP_INTERVAL') ?: 5);
    $batchSize    = (int) (getenv('RADIO_AI_BATCH_SIZE') ?: 10);
    $maxAge       = (int) (getenv('RADIO_AI_TRANSCRIPT_AGE') ?: 600);

    radio_ai_listener_log("starting — poll every {$interval}s, batch {$batchSize}, max age {$maxAge}s");

    pcntl_async_signals(true);
    $running = true;
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });

    // On startup, jump the cursor forward to the current max ID — so
    // a fresh listener doesn't try to reprocess years of history.
    $lastId = radio_ai_listener_get_last_id($prefix);
    if ($lastId === 0) {
        $maxId = (int) db()->query("SELECT IFNULL(MAX(id), 0) FROM `{$prefix}dmr_messages`")->fetchColumn();
        radio_ai_listener_set_last_id($prefix, $maxId);
        $lastId = $maxId;
        radio_ai_listener_log("first run — cursor primed at id {$maxId}");
    }

    while ($running) {
        try {
            $enabled = (int) radio_ai_setting('radio_ai_enabled', '0');
            if (!$enabled) {
                sleep($interval);
                continue;
            }

            $wakeWord = trim((string) radio_ai_setting('radio_ai_wake_word', 'claude'));
            if ($wakeWord === '') $wakeWord = 'claude';

            $channelIdsRaw = (string) radio_ai_setting('radio_ai_channel_ids', '');
            $channelIds = $channelIdsRaw !== ''
                ? array_map('intval', array_filter(array_map('trim', explode(',', $channelIdsRaw))))
                : [];

            $stmt = db()->prepare(
                "SELECT id, channel_id, radio_id, radio_callsign, talkgroup, transcript, audio_path
                   FROM `{$prefix}dmr_messages`
                  WHERE id > ?
                    AND direction = 'rx'
                    AND transcript IS NOT NULL
                    AND transcript <> ''
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                  ORDER BY id ASC
                  LIMIT ?"
            );
            $stmt->bindValue(1, $lastId, PDO::PARAM_INT);
            $stmt->bindValue(2, $maxAge, PDO::PARAM_INT);
            $stmt->bindValue(3, $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    radio_ai_listener_process_row($row, $prefix, $wakeWord, $channelIds);
                    $lastId = max($lastId, (int) $row['id']);
                }
                radio_ai_listener_set_last_id($prefix, $lastId);
            }
        } catch (Throwable $e) {
            radio_ai_listener_log("loop error: " . $e->getMessage());
        }

        // Sleep responsively so SIGTERM is honored quickly.
        for ($i = 0; $i < $interval && $running; $i++) {
            sleep(1);
        }
    }

    radio_ai_listener_log("shutting down cleanly");
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "radio_ai_listener must be run from CLI\n");
    exit(1);
}

if (!extension_loaded('pcntl')) {
    fwrite(STDERR, "radio_ai_listener requires the pcntl extension\n");
    exit(1);
}

radio_ai_listener_loop();
