<?php
/**
 * Phase 112 Phase 3 — radio read-out delivery (DMR via the Phase 85f path).
 *
 * Safety model (claude-on-amateur-radio: no surprise AI on the air):
 *   - action_mode 'notify'            → tray notification only, never keys.
 *   - action_mode 'operator_approve'  → SAFE DEFAULT. Enqueues an
 *     ai_pending_responses card (status 'pending_approval') in the SAME
 *     operator-approval queue the radio widget already shows; nothing
 *     transmits until a human presses Send (api/radio-ai-decide.php, which
 *     owns the §97.103 control decision + the bridge POST).
 *   - action_mode 'auto_fire'         → keys unattended, but ONLY when the
 *     EXTRA master switch weather_radio_allow_autofire = '1' (default OFF).
 *     With the switch off, auto_fire silently degrades to operator_approve.
 *
 * The read-out script comes from weather_build_readout_script() — prefix +
 * event + area + instruction, word-budgeted, with the §97.119 station ID
 * appended as the closing "<CALLSIGN> clear." (only when a callsign is
 * configured). The bridge's /tx/text does the clear-channel wait + Piper TTS
 * + keying — the exact transport the Phase 85f approve path uses.
 *
 * Zello read-out (Phase 6): a zello-target rule queues a `zello_outbox` row
 * with kind='tts' — the Zello proxy synthesizes (Piper) and keys the audio
 * onto the channel (ZelloProxyApp::relayTtsOutbox). Same safety ladder as
 * DMR: notify / operator_approve (card in the radio widget; approve queues
 * the outbox row) / auto_fire (gated by the SAME weather_radio_allow_autofire
 * switch — Zello channels are routinely gatewayed onto RF repeaters, so
 * unattended Zello keying gets the same respect as unattended DMR keying).
 * The Zello script carries NO §97.119 callsign suffix — Zello itself is an
 * IP service, and a gateway that puts it on RF must do its own station ID.
 */

require_once __DIR__ . '/weather_alerts.php';

/** Extra, off-by-default master switch for unattended keying. */
function weather_radio_autofire_allowed(): bool
{
    return weather_setting('weather_radio_allow_autofire', '0') === '1';
}

/** Resolve an enabled DMR channel row by talkgroup (the rule's target_ref). */
function weather_radio_channel_for_tg($talkgroup): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $tg = (int) $talkgroup;
    if ($tg <= 0) return null;
    try {
        $row = db_fetch_one(
            "SELECT `id`, `label`, `talkgroup`, `bridge_host`, `bridge_port`, `bridge_token`
             FROM `{$prefix}dmr_channels`
             WHERE `talkgroup` = ? AND `enabled` = 1
             ORDER BY `id` ASC LIMIT 1",
            [$tg]
        );
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Build the spoken script from the alert + the configured TTS settings.
 * $withCallsign=false drops the §97.119 suffix (Zello: not an RF service).
 */
function weather_radio_script(array $alert, bool $withCallsign = true): string
{
    return weather_build_readout_script($alert, [
        'prefix'      => weather_setting('weather_tts_prefix', 'Weather bulletin from the National Weather Service.'),
        'callsign'    => $withCallsign ? weather_setting('weather_tts_callsign', '') : '',
        'max_seconds' => (int) weather_setting('weather_tts_max_seconds', '45'),
    ]);
}

/**
 * Enqueue a bulletin in the operator-approval queue (the radio widget's card).
 *
 * $target: ['kind'=>'dmr','channel_id'=>int,'label'=>string]  — approve POSTs
 *          the DMR bridge (Phase 85f path), or
 *          ['kind'=>'zello','ref'=>string,'label'=>string]    — approve queues
 *          a zello_outbox kind='tts' row the proxy synthesizes + keys.
 *
 * @return array{ok:bool, detail:string, pending_id?:int}
 */
function weather_radio_enqueue_approval(array $alert, array $rule, array $target, string $script): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        // The transcript slot shows the operator WHAT triggered the draft.
        $context = 'NWS ' . (string) ($alert['event'] ?? 'alert')
                 . ' — ' . (string) ($alert['area_desc'] ?? '')
                 . ((string) ($alert['headline'] ?? '') !== '' ? ' — ' . $alert['headline'] : '');
        // inbound_call_id is varchar(16) (sized for DMR stream ids) — use a
        // short deterministic hash of (alert, rule) instead of the long NWS URN.
        $callId = 'wx' . substr(md5((string) ($alert['nws_id'] ?? '') . ':' . (int) $rule['id']), 0, 12);
        $kind   = (string) ($target['kind'] ?? 'dmr');
        $params = [
            $kind === 'dmr' ? (int) ($target['channel_id'] ?? 0) : 0,
            $kind,
            $kind === 'zello' ? mb_substr((string) ($target['ref'] ?? ''), 0, 100) : null,
            $callId,
            mb_substr($context, 0, 2000),
            $script,
        ];
        try {
            db_query(
                "INSERT INTO `{$prefix}ai_pending_responses`
                    (`channel_id`, `target_kind`, `target_ref`, `caller_src_id`, `caller_callsign`,
                     `inbound_call_id`, `transcript`, `draft_response`, `status`, `created_at`)
                 VALUES (?, ?, ?, 0, 'NWS', ?, ?, ?, 'pending_approval', NOW())",
                $params
            );
        } catch (Throwable $e) {
            // Pre-migration schema (no target_* columns): the DMR path still
            // works via channel_id; a Zello card CANNOT route without them.
            if ($kind !== 'dmr') {
                return ['ok' => false, 'detail' => 'schema missing target_kind/target_ref '
                    . '(run sql/run_weather_zello_readout.php): ' . $e->getMessage()];
            }
            db_query(
                "INSERT INTO `{$prefix}ai_pending_responses`
                    (`channel_id`, `caller_src_id`, `caller_callsign`, `inbound_call_id`,
                     `transcript`, `draft_response`, `status`, `created_at`)
                 VALUES (?, 0, 'NWS', ?, ?, ?, 'pending_approval', NOW())",
                [(int) ($target['channel_id'] ?? 0), $callId, mb_substr($context, 0, 2000), $script]
            );
        }
        $pid = (int) db_insert_id();
        if (function_exists('audit_log')) {
            audit_log('weather', 'radio_queue', 'weather_alert', null,
                'Weather bulletin queued for operator approval (' . (string) ($target['label'] ?? $kind) . ')',
                ['pending_id' => $pid, 'rule' => $rule['label'] ?? '', 'nws_id' => $alert['nws_id'] ?? '']);
        }
        return ['ok' => true, 'detail' => 'queued for operator approval (pending #' . $pid . ')', 'pending_id' => $pid];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'queue insert failed: ' . $e->getMessage()];
    }
}

/**
 * Queue a Zello TTS read-out (auto-fire path). The long-running Zello proxy
 * drains zello_outbox on its loop timer, synthesizes the text with Piper and
 * keys the Opus audio onto the channel — this function only hands off.
 * @return array{ok:bool, detail:string, outbox_id?:int}
 */
function weather_zello_enqueue_tts(string $channel, string $script): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}zello_outbox`
                (`kind`, `channel`, `recipient`, `body`, `status`, `queued_by`, `source`)
             VALUES ('tts', ?, '', ?, 'queued', NULL, 'weather')",
            [mb_substr($channel, 0, 100), mb_substr($script, 0, 1000)]
        );
        $oid = (int) db_insert_id();
        return ['ok' => true, 'detail' => 'queued to zello_outbox #' . $oid . ' (proxy synthesizes + keys)',
                'outbox_id' => $oid];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'zello_outbox insert failed: ' . $e->getMessage()];
    }
}

/** Is Zello configured at all? (mirrors inc/router.php's check) */
function weather_zello_configured(): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` LIKE 'zello\\_%'"
        );
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Direct bridge TX (auto-fire path). Same POST the approve flow makes; the
 * bridge does clear-channel wait + Piper + keying.
 * @param callable|null $tx injectable for tests: fn(string $url, array $payload,
 *                          string $token): array{code:int, body:?array}
 */
function weather_radio_tx(array $channel, string $script, ?callable $tx = null): array
{
    $url = 'http://' . $channel['bridge_host'] . ':' . (int) $channel['bridge_port'] . '/tx/text';
    $payload = ['text' => $script, 'talkgroup' => (int) $channel['talkgroup'], 'dry_run' => false];
    // Phase 113e — let the Voice & Speech page's weather-bulletin voice reach
    // the radio. DMR is 8 kHz AMBE so this only selects a Piper model; the
    // bridge ignores a blank/unreadable path and uses its default.
    if (!function_exists('tts_dmr_piper_voice') && is_file(__DIR__ . '/tts/engine.php')) {
        require_once __DIR__ . '/tts/engine.php';
    }
    if (function_exists('tts_dmr_piper_voice')) {
        $v = tts_dmr_piper_voice('weather_bulletin');
        if ($v !== '') $payload['voice'] = $v;
    }

    if ($tx === null) {
        $tx = static function (string $url, array $payload, string $token): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 60,   // bridge blocks until the TX completes
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['code' => $code, 'body' => is_string($body) ? json_decode($body, true) : null];
        };
    }

    try {
        $resp = $tx($url, $payload, (string) ($channel['bridge_token'] ?? ''));
        $ok = ($resp['code'] === 200) && is_array($resp['body']) && !empty($resp['body']['ok']);
        return ['ok' => $ok, 'detail' => $ok ? 'transmitted (auto-fire)' : ('bridge HTTP ' . $resp['code'])];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'bridge unreachable: ' . $e->getMessage()];
    }
}

/**
 * Deliver an alert to a radio rule (dmr/zello). Returns
 * ['ok'=>bool, 'detail'=>string, 'ledger'=>'sent'|'queued'|'failed'|'skipped'].
 * @param callable|null $tx injectable TX for tests (never keys in CI)
 */
function weather_radio_deliver(array $alert, array $rule, ?callable $tx = null): array
{
    $target = (string) ($rule['target'] ?? '');
    $mode   = (string) ($rule['action_mode'] ?? 'notify');

    // notify = tray only; the radio never keys for this rule.
    if ($mode === 'notify') {
        weather_emit_tray($alert, $rule);
        return ['ok' => true, 'detail' => 'notify only (no TX)', 'ledger' => 'sent'];
    }

    // auto_fire without the extra master switch degrades to operator approval.
    // Applies to Zello too: Zello channels are routinely gatewayed onto RF
    // repeaters, so unattended Zello keying earns the same gate as DMR.
    if ($mode === 'auto_fire' && !weather_radio_autofire_allowed()) {
        $mode = 'operator_approve';
    }

    // ── Zello read-out: zello_outbox kind='tts' (proxy synthesizes + keys) ──
    if ($target === 'zello') {
        if (!weather_zello_configured()) {
            return ['ok' => false, 'detail' => 'Zello is not configured (no zello_* settings)',
                    'ledger' => 'skipped'];
        }
        // Blank target_ref = the proxy's default dispatch channel.
        $zchannel = trim((string) ($rule['target_ref'] ?? ''));
        // No §97.119 suffix: Zello is an IP service; an RF gateway IDs itself.
        $script = weather_radio_script($alert, false);
        if (trim($script) === '') {
            return ['ok' => false, 'detail' => 'empty read-out script', 'ledger' => 'failed'];
        }
        if ($mode === 'operator_approve') {
            $label = 'Zello: ' . ($zchannel !== '' ? $zchannel : 'default channel');
            $r = weather_radio_enqueue_approval($alert, $rule,
                ['kind' => 'zello', 'ref' => $zchannel, 'label' => $label], $script);
            return ['ok' => $r['ok'], 'detail' => $r['detail'], 'ledger' => $r['ok'] ? 'queued' : 'failed'];
        }
        // auto_fire (explicitly enabled): hand off to the proxy's outbox.
        $r = weather_zello_enqueue_tts($zchannel, $script);
        if ($r['ok'] && function_exists('audit_log')) {
            audit_log('weather', 'radio_tx', 'weather_alert', null,
                'Weather bulletin auto-queued for Zello read-out (' . ($zchannel !== '' ? $zchannel : 'default channel') . ')',
                ['rule' => $rule['label'] ?? '', 'nws_id' => $alert['nws_id'] ?? '',
                 'outbox_id' => $r['outbox_id'] ?? null]);
        }
        return ['ok' => $r['ok'], 'detail' => $r['detail'], 'ledger' => $r['ok'] ? 'queued' : 'failed'];
    }

    $channel = weather_radio_channel_for_tg($rule['target_ref'] ?? 0);
    if (!$channel) {
        return ['ok' => false, 'detail' => 'no enabled DMR channel for TG ' . (string) ($rule['target_ref'] ?? '?'),
                'ledger' => 'failed'];
    }

    $script = weather_radio_script($alert);
    if (trim($script) === '') {
        return ['ok' => false, 'detail' => 'empty read-out script', 'ledger' => 'failed'];
    }

    if ($mode === 'operator_approve') {
        $r = weather_radio_enqueue_approval($alert, $rule,
            ['kind' => 'dmr', 'channel_id' => (int) $channel['id'],
             'label' => 'TG ' . $channel['talkgroup']], $script);
        return ['ok' => $r['ok'], 'detail' => $r['detail'], 'ledger' => $r['ok'] ? 'queued' : 'failed'];
    }

    // auto_fire (explicitly enabled): key when the bridge reports clear.
    $r = weather_radio_tx($channel, $script, $tx);
    if ($r['ok'] && function_exists('audit_log')) {
        audit_log('weather', 'radio_tx', 'weather_alert', null,
            'Weather bulletin auto-fired on TG ' . $channel['talkgroup'],
            ['rule' => $rule['label'] ?? '', 'nws_id' => $alert['nws_id'] ?? '']);
    }
    return ['ok' => $r['ok'], 'detail' => $r['detail'], 'ledger' => $r['ok'] ? 'sent' : 'failed'];
}
