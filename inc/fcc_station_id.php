<?php
/**
 * inc/fcc_station_id.php — FCC 47 CFR §97.119 amateur station-ID
 * enforcement for the live DMR/BrandMeister radio widget (Phase 148,
 * closes specs/SPEC-STATUS.md section B3 / specs/phase-85e-fcc-station-id).
 *
 * TIMING MODEL — follows the fcc-amateur-station-id skill precisely.
 * Read that skill before changing anything in this file.
 *
 *   - The regulated unit is the CONVERSATION, not the individual
 *     transmission. A brief silence so the other party can answer is part
 *     of the conversation, not the end of it.
 *   - The 10-minute clock is anchored to `last_id_at` (the timestamp of the
 *     operator's most recent station ID) -- NEVER last-TX time, NEVER
 *     conversation-start time.
 *   - The clock creates an obligation on the operator's NEXT transmission,
 *     NOT a background alarm. Silence past the interval is legal. If the
 *     operator simply stops transmitting, the obligation lapses -- there is
 *     no "ID OVERDUE" state that fires on its own.
 *   - A "Monitoring ID" (a standalone `"<CALLSIGN> monitoring."` TX) is
 *     OPTIONAL remediation the operator can fire at any time to reset the
 *     window -- never auto-fired by the system during silence.
 *   - An "End conversation" action fires a standalone `"<CALLSIGN> clear."`
 *     ONLY when the operator's most recent transmission did not itself
 *     carry an ID (last_tx_at > last_id_at) -- otherwise it just clears the
 *     informational conversation marker.
 *
 * WHY THERE IS NO SPEECH-TO-TEXT ID DETECTION IN THIS PHASE
 * Per specs/phase-85e-fcc-station-id/spec.md, Whisper-based ID confirmation
 * is Phase 85e-5, explicitly deferred ("Adds compute cost; nice-to-have
 * audit feature"). Without it, the software cannot inspect what the
 * operator actually said. `fcc_record_id_event(..., 'confirmed_tx', ...)`
 * is therefore an OPERATOR SELF-REPORT -- the widget asks "did that
 * transmission include your callsign?" only when the timer was in the
 * yellow/red zone, and only records the ID if the operator says yes. This
 * is never assumed silently; see assets/js/radio-widget.js's fccOnPttEnd().
 *
 * WHY `id_enforce='hard'` IS NOT AN UNCONDITIONAL SERVER-SIDE BLOCK
 * The original phase-85e-fcc-station-id spec's own risk register says:
 * "Operator forgets to ID -- B4 timer catches it. Soft enforcement
 * initially so we don't block legitimate emergency traffic." The
 * fcc-amateur-station-id skill is equally explicit that this is an
 * INFORMATIONAL check ("RED means... informational only, not blocking.
 * Operator may still choose to stay silent.") and that the system must
 * never auto-fire a transmission or otherwise force traffic onto the air.
 * Taking that spirit one step further: this build also never lets the
 * SOFTWARE refuse a human operator's deliberate PTT press outright, even in
 * "hard" mode -- 'hard' instead requires an explicit one-click
 * acknowledgment ("Your last station ID was N minutes ago -- include your
 * callsign, <CALLSIGN>, in this transmission." [Continue] [Cancel]) before
 * the audio upload proceeds. An operator who needs to key up in an
 * emergency can always click Continue. This is a deliberate, documented
 * DEVIATION from a literal reading of the original spec.md table's "hard =
 * block TX if missed" -- flagged here because CLAUDE.md's own standing rule
 * is to surface a conflict between the skill's guidance and a prior partial
 * implementation rather than silently pick one.
 *
 * WHY `last_id_at` IS NEVER CACHED
 * `dmr_id_log` is append-only; `fcc_last_id_at()` always computes
 * `MAX(id_at)` live. This project's CLAUDE.md has repeatedly documented the
 * failure mode of a cached/stored "current state" column drifting from the
 * append-only log that is supposed to be its source of truth (Phase
 * 129/143's read-time-derivation lesson) -- so there is no
 * `dmr_ptt_state.last_id_at` column to drift.
 *
 * This file is split into PURE functions (no DB, no config.php --
 * tests/test_fcc_station_id_timing.php drives these directly) and DB
 * functions (require a live PDO connection via the global db_query() /
 * db_fetch_one() / db_fetch_value() helpers already loaded by the caller,
 * same convention as inc/interval-report.php).
 */

// ═══════════════════════════════════════════════════════════════════════
// PURE functions — no DB, no config.php dependency.
// ═══════════════════════════════════════════════════════════════════════

if (!defined('FCC_CALLSIGN_REGEX')) {
    // US amateur callsign shape per specs/phase-85e-fcc-station-id/spec.md
    // B1: 1-2 letters, one digit, 1-3 letters (covers N0NKI, W1AW, AA0E,
    // etc). Deliberately advisory, not a hard PTT block on mismatch --
    // international/visiting operators and club calls can be valid but
    // unusual shapes. Only a BLANK callsign disables PTT (see
    // fcc_status_payload()'s callsign_present).
    define('FCC_CALLSIGN_REGEX', '/^[A-Z]{1,2}[0-9][A-Z]{1,3}$/');
}

if (!function_exists('fcc_callsign_valid')) {
    /** Loose US amateur callsign format check. Advisory only -- see above. */
    function fcc_callsign_valid(string $callsign): bool
    {
        return (bool) preg_match(FCC_CALLSIGN_REGEX, strtoupper(trim($callsign)));
    }
}

if (!function_exists('fcc_ts')) {
    /** Parse a MySQL DATETIME (or null/'') into a unix timestamp, or null. */
    function fcc_ts(?string $dt): ?int
    {
        if ($dt === null || $dt === '' || $dt === '0000-00-00 00:00:00') return null;
        $ts = strtotime($dt);
        return $ts === false ? null : $ts;
    }
}

if (!function_exists('fcc_may_transmit_without_id')) {
    /**
     * The ONE check that matters, per the skill: "The check that actually
     * matters: gates the NEXT TX, not silence." Call this at PTT key-down
     * (or before any system-initiated TX). Never call it on a timer/tick --
     * there is no such thing as a background compliance alarm.
     *
     * @param string|null $lastIdAt        MySQL DATETIME of the operator's
     *                                      most recent station ID on this
     *                                      channel, or null if never IDed.
     * @param int         $intervalSeconds  dmr_channels.id_interval_seconds
     *                                      (regulatory max 600 = 10 min).
     * @param string|null $now              MySQL DATETIME to treat as "now"
     *                                      (injectable for tests; defaults
     *                                      to the real current time).
     */
    function fcc_may_transmit_without_id(?string $lastIdAt, int $intervalSeconds, ?string $now = null): bool
    {
        $lastTs = fcc_ts($lastIdAt);
        if ($lastTs === null) return false; // never IDed -> next TX must contain callsign
        $nowTs = $now !== null ? fcc_ts($now) : time();
        if ($nowTs === null) $nowTs = time();
        return ($nowTs - $lastTs) < max(1, $intervalSeconds);
    }
}

if (!function_exists('fcc_id_zone')) {
    /**
     * UI traffic-light zone. INFORMATIONAL ONLY -- never itself a
     * compliance trigger; fcc_may_transmit_without_id() is the only
     * function whose result has regulatory meaning.
     *
     *   'none'   — no ID on record yet for this (channel, operator).
     *   'green'  — <80% of the interval elapsed since last ID.
     *   'yellow' — 80%-100% elapsed (matches the skill's worked 8:00/10:00
     *              split on the default 600s/10-min interval).
     *   'red'    — >=100% elapsed. The operator's NEXT TX must contain the
     *              callsign. Does NOT mean a violation has occurred, and
     *              does NOT mean the operator must transmit now.
     */
    function fcc_id_zone(?string $lastIdAt, int $intervalSeconds, ?string $now = null): string
    {
        $lastTs = fcc_ts($lastIdAt);
        if ($lastTs === null) return 'none';
        $nowTs = $now !== null ? fcc_ts($now) : time();
        if ($nowTs === null) $nowTs = time();
        $elapsed  = $nowTs - $lastTs;
        $interval = max(1, $intervalSeconds);
        if ($elapsed >= $interval) return 'red';
        if ($elapsed >= (int) round($interval * 0.8)) return 'yellow';
        return 'green';
    }
}

if (!function_exists('fcc_seconds_since')) {
    /** Seconds elapsed since $ts (a MySQL DATETIME), or null if $ts is null/unparseable. */
    function fcc_seconds_since(?string $ts, ?string $now = null): ?int
    {
        $t = fcc_ts($ts);
        if ($t === null) return null;
        $nowTs = $now !== null ? fcc_ts($now) : time();
        if ($nowTs === null) $nowTs = time();
        return max(0, $nowTs - $t);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// DB functions — require the caller to have already loaded config.php /
// inc/db.php (same convention as every api/*.php endpoint). No require_once
// of db.php here, deliberately, so this file stays includable by the pure
// math test with zero DB dependency (mirrors inc/interval-report.php).
// ═══════════════════════════════════════════════════════════════════════

if (!function_exists('fcc_last_id_at')) {
    /**
     * The PRIMARY anchor for the compliance check. Always derived live --
     * never cached. See this file's own docblock for why.
     */
    function fcc_last_id_at(int $channelId, int $userId): ?string
    {
        if ($channelId <= 0 || $userId <= 0) return null;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $v = db_fetch_value(
                "SELECT MAX(`id_at`) FROM `{$prefix}dmr_id_log` WHERE `channel_id` = ? AND `user_id` = ?",
                [$channelId, $userId]
            );
            return $v ?: null;
        } catch (Throwable $e) {
            error_log('[fcc_station_id] fcc_last_id_at failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fcc_ptt_state')) {
    /** Informational (channel,user) state row. Never NULL-return; defaults when absent. */
    function fcc_ptt_state(int $channelId, int $userId): array
    {
        $default = ['last_tx_at' => null, 'conversation_started_at' => null];
        if ($channelId <= 0 || $userId <= 0) return $default;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $row = db_fetch_one(
                "SELECT `last_tx_at`, `conversation_started_at` FROM `{$prefix}dmr_ptt_state`
                  WHERE `channel_id` = ? AND `user_id` = ?",
                [$channelId, $userId]
            );
            if (!$row) return $default;
            return [
                'last_tx_at' => $row['last_tx_at'] ?: null,
                'conversation_started_at' => $row['conversation_started_at'] ?: null,
            ];
        } catch (Throwable $e) {
            error_log('[fcc_station_id] fcc_ptt_state failed: ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('fcc_record_tx')) {
    /**
     * Called by the two real PTT-audio forwarders (api/dmr-tx-audio.php,
     * api/dmr-tx-stream.php) immediately after a successful forward to the
     * bridge -- this is the authoritative "a transmission actually
     * happened" signal, never a client self-report (a flaky/malicious
     * client could otherwise skip reporting it). Updates last_tx_at
     * (informational) and opens a fresh conversation marker when the gap
     * since the previous TX is >= the channel's interval (skill: "New
     * conversation begins on first TX after >10 min silence").
     *
     * Deliberately does NOT touch dmr_id_log / last_id_at -- a TX by
     * itself proves nothing about whether the callsign was spoken. That
     * only advances via fcc_record_id_event().
     */
    function fcc_record_tx(int $channelId, int $userId, int $intervalSeconds = 600): void
    {
        if ($channelId <= 0 || $userId <= 0) return;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $state    = fcc_ptt_state($channelId, $userId);
            $prevTxTs = fcc_ts($state['last_tx_at']);
            $now      = time();
            $newConversation = ($prevTxTs === null) || (($now - $prevTxTs) >= max(1, $intervalSeconds));
            if ($newConversation) {
                db_query(
                    "INSERT INTO `{$prefix}dmr_ptt_state` (`channel_id`,`user_id`,`last_tx_at`,`conversation_started_at`)
                     VALUES (?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE `last_tx_at` = NOW(), `conversation_started_at` = NOW()",
                    [$channelId, $userId]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}dmr_ptt_state` (`channel_id`,`user_id`,`last_tx_at`)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE `last_tx_at` = NOW()",
                    [$channelId, $userId]
                );
            }
        } catch (Throwable $e) {
            error_log('[fcc_station_id] fcc_record_tx failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('fcc_record_id_event')) {
    /**
     * Append a station-ID event. This is the ONLY writer of dmr_id_log,
     * and therefore the only thing that can advance last_id_at.
     *
     * 'monitoring_id' and 'end_of_conversation' close the informational
     * conversation marker (skill: "on operator pressing Monitoring ID: ...
     * conversation_started_at = null"). 'confirmed_tx' does NOT -- a
     * mid-conversation ID (the skill's own ~8-9 minute worked example)
     * doesn't end the conversation, it just resets the compliance clock.
     */
    function fcc_record_id_event(int $channelId, int $userId, string $callsign, string $source, ?string $notes = null): bool
    {
        $cs = strtoupper(trim($callsign));
        if ($channelId <= 0 || $userId <= 0 || $cs === '') return false;
        $validSources = ['confirmed_tx', 'monitoring_id', 'end_of_conversation'];
        if (!in_array($source, $validSources, true)) return false;

        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            db_query(
                "INSERT INTO `{$prefix}dmr_id_log` (`channel_id`,`user_id`,`callsign`,`id_at`,`source`,`notes`)
                 VALUES (?, ?, ?, NOW(), ?, ?)",
                [$channelId, $userId, $cs, $source, $notes]
            );
            if ($source === 'monitoring_id' || $source === 'end_of_conversation') {
                db_query(
                    "UPDATE `{$prefix}dmr_ptt_state` SET `conversation_started_at` = NULL
                      WHERE `channel_id` = ? AND `user_id` = ?",
                    [$channelId, $userId]
                );
            }
            if (function_exists('audit_log')) {
                // action = 'fcc_id', per specs/phase-85e-fcc-station-id/spec.md B6.
                audit_log(
                    'comms',
                    'fcc_id',
                    'dmr_channel',
                    $channelId,
                    'Station ID (' . $source . ') by ' . $cs,
                    ['channel_id' => $channelId, 'callsign' => $cs, 'source' => $source]
                );
            }
            return true;
        } catch (Throwable $e) {
            error_log('[fcc_station_id] fcc_record_id_event failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('fcc_fire_station_tts')) {
    /**
     * POST a standalone Piper TTS phrase to the bridge's /tx/text endpoint
     * -- the same call inc/weather_radio.php's weather_radio_tx() makes for
     * auto-fired weather bulletins. Kept as its own small function (rather
     * than reusing weather_radio_tx(), which expects an $alert-shaped
     * array) because a station ID has nothing to do with a weather alert.
     *
     * @param callable|null $tx injectable for tests, same shape as
     *                          weather_radio_tx()'s: fn(string $url, array
     *                          $payload, string $token): array{code:int,
     *                          body:?array}
     */
    function fcc_fire_station_tts(array $channel, string $text, ?callable $tx = null): array
    {
        require_once __DIR__ . '/dmr_token.php';
        $token = dmr_bridge_token($channel);
        if ($token === '') {
            return ['ok' => false, 'detail' => dmr_token_missing_reason($channel)];
        }
        $bridgeHost = (string) ($channel['bridge_host'] ?? '');
        $bridgePort = (int) ($channel['bridge_port'] ?? 0);
        if ($bridgeHost === '' || $bridgePort <= 0) {
            return ['ok' => false, 'detail' => 'Channel missing bridge_host / bridge_port'];
        }
        $url = 'http://' . $bridgeHost . ':' . $bridgePort . '/tx/text';
        $payload = [
            'text'      => $text,
            'talkgroup' => (int) ($channel['talkgroup'] ?? 0),
            'dry_run'   => false,
        ];

        if ($tx === null) {
            $tx = static function (string $url, array $payload, string $token): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $token,
                    ],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                return ['code' => $code, 'body' => is_string($body) ? json_decode($body, true) : null, 'err' => $err];
            };
        }

        try {
            $resp = $tx($url, $payload, $token);
            $ok = (($resp['code'] ?? 0) === 200) && is_array($resp['body'] ?? null) && !empty($resp['body']['ok']);
            return [
                'ok'     => $ok,
                'detail' => $ok ? 'transmitted' : ('bridge HTTP ' . ($resp['code'] ?? 0) . ' ' . ($resp['err'] ?? '')),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'bridge unreachable: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('fcc_monitoring_id')) {
    /**
     * "🎙️ Monitoring ID" button handler. Optional operator convenience,
     * never auto-fired. Fires `"<CALLSIGN> monitoring."` as a standalone
     * TX and, only on confirmed success, records the ID event.
     */
    function fcc_monitoring_id(array $channel, int $userId, string $callsign, ?callable $tx = null): array
    {
        $cs = strtoupper(trim($callsign));
        if ($cs === '') return ['ok' => false, 'detail' => 'No callsign on file -- set one in your profile first.'];
        $channelId = (int) ($channel['id'] ?? 0);
        if ($channelId <= 0) return ['ok' => false, 'detail' => 'No DMR channel available'];

        $result = fcc_fire_station_tts($channel, $cs . ' monitoring.', $tx);
        if ($result['ok']) {
            fcc_record_id_event($channelId, $userId, $cs, 'monitoring_id');
        }
        return $result;
    }
}

if (!function_exists('fcc_end_conversation')) {
    /**
     * "🛑 End conversation" button handler. Per the skill's pseudocode:
     * "if last_tx_at > (last_id_at or 0): fire_tts(<CALLSIGN> clear)".
     * Fires a standalone closing ID ONLY when the operator's most recent
     * transmission did not itself carry one. Always clears the
     * informational conversation marker afterward (that marker is UX only
     * -- it does not gate compliance, so clearing it even when the closing
     * TTS fails to transmit is safe: the NEXT PTT press still correctly
     * reads last_id_at from dmr_id_log, which only advances on confirmed
     * success).
     */
    function fcc_end_conversation(array $channel, int $userId, string $callsign, ?callable $tx = null): array
    {
        $cs = strtoupper(trim($callsign));
        $channelId = (int) ($channel['id'] ?? 0);
        if ($channelId <= 0) return ['ok' => false, 'fired_closing_id' => false, 'detail' => 'No DMR channel available'];

        $state    = fcc_ptt_state($channelId, $userId);
        $lastTxTs = fcc_ts($state['last_tx_at']);
        $lastIdTs = fcc_ts(fcc_last_id_at($channelId, $userId));
        $needsClosingId = ($lastTxTs !== null) && ($lastIdTs === null || $lastTxTs > $lastIdTs);

        $result = ['ok' => true, 'fired_closing_id' => false, 'detail' => 'Conversation closed.'];
        if ($needsClosingId && $cs !== '') {
            $txResult = fcc_fire_station_tts($channel, $cs . ' clear.', $tx);
            if ($txResult['ok']) {
                fcc_record_id_event($channelId, $userId, $cs, 'end_of_conversation');
                $result['fired_closing_id'] = true;
                $result['detail'] = 'Closing station ID transmitted.';
            } else {
                $result['ok'] = false;
                $result['detail'] = 'Closing ID failed: ' . $txResult['detail'];
            }
        }

        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            db_query(
                "UPDATE `{$prefix}dmr_ptt_state` SET `conversation_started_at` = NULL
                  WHERE `channel_id` = ? AND `user_id` = ?",
                [$channelId, $userId]
            );
        } catch (Throwable $e) {
            error_log('[fcc_station_id] fcc_end_conversation state clear failed: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('fcc_status_payload')) {
    /**
     * Full JSON status blob for the widget: zone, countdown, conversation
     * state, and `server_now` for the browser's own clock-skew correction
     * (same convention as api/org-relationships.php's server_now field).
     *
     * @param array|null $channel a dmr_channels row (or null if no DMR
     *                            channel is configured/enabled -- the
     *                            widget must still render gracefully).
     */
    function fcc_status_payload(?array $channel, int $userId, string $callsign): array
    {
        $now = date('Y-m-d H:i:s');
        $cs  = strtoupper(trim($callsign));

        if (!$channel) {
            return [
                'channel_id' => null,
                'channel_label' => null,
                'id_enforce' => 'off',
                'id_interval_seconds' => 600,
                'callsign' => $cs,
                'callsign_present' => $cs !== '',
                'callsign_valid' => fcc_callsign_valid($cs),
                'last_id_at' => null,
                'last_tx_at' => null,
                'conversation_started_at' => null,
                'zone' => 'none',
                'may_transmit_without_id' => false,
                'seconds_since_id' => null,
                'seconds_until_due' => null,
                'server_now' => $now,
            ];
        }

        $channelId = (int) $channel['id'];
        $interval  = (int) ($channel['id_interval_seconds'] ?? 600);
        if ($interval <= 0) $interval = 600;
        $enforce = (string) ($channel['id_enforce'] ?? 'soft');

        $lastId = fcc_last_id_at($channelId, $userId);
        $state  = fcc_ptt_state($channelId, $userId);
        $zone   = fcc_id_zone($lastId, $interval, $now);
        $mayNoId = fcc_may_transmit_without_id($lastId, $interval, $now);
        $secSince = fcc_seconds_since($lastId, $now);
        $secUntil = $secSince === null ? null : max(0, $interval - $secSince);

        return [
            'channel_id' => $channelId,
            'channel_label' => (string) ($channel['label'] ?? ''),
            'id_enforce' => $enforce,
            'id_interval_seconds' => $interval,
            'callsign' => $cs,
            'callsign_present' => $cs !== '',
            'callsign_valid' => fcc_callsign_valid($cs),
            'last_id_at' => $lastId,
            'last_tx_at' => $state['last_tx_at'],
            'conversation_started_at' => $state['conversation_started_at'],
            'zone' => $zone,
            'may_transmit_without_id' => $mayNoId,
            'seconds_since_id' => $secSince,
            'seconds_until_due' => $secUntil,
            'server_now' => $now,
        ];
    }
}
