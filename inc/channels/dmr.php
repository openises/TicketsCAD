<?php
/**
 * Channel: DMR (BrandMeister)
 *
 * Text messaging via the BrandMeister DMR network.
 * DMR (Digital Mobile Radio) is used by amateur radio operators and
 * public safety agencies for digital voice and data communication.
 *
 * Connection Modes:
 * ────────────────────────────────────────────────────────────────
 * 1. BrandMeister MQTT
 *    - Subscribe to BrandMeister's MQTT server for incoming messages
 *    - Publish text messages via MQTT or REST API
 *    - Config: brandmeister_api_key, dmr_id, talkgroup
 *
 * 2. DVSwitch Proxy (Future)
 *    - Connect to a local DVSwitch instance for DMR voice/text
 *    - Similar architecture to the Zello proxy service
 *    - Config: dvswitch_host, dvswitch_port
 *
 * Configuration stored in settings table:
 *   dmr_enabled              = 1/0
 *   dmr_mode                 = brandmeister_mqtt | dvswitch_proxy
 *   dmr_brandmeister_api_key = BrandMeister API key
 *   dmr_dmr_id               = TicketsCAD station DMR ID
 *   dmr_talkgroup            = Default talkgroup
 *   dmr_dvswitch_host        = DVSwitch proxy host (future)
 *   dmr_dvswitch_port        = DVSwitch proxy port (future)
 */

broker_register('dmr', [
    'name'    => 'DMR (BrandMeister)',
    'send'    => '_dmr_send',
    'receive' => '_dmr_receive',
    'status'  => '_dmr_status'
]);

/**
 * Send a text message via DMR.
 *
 * Recipient formats (Phase 99e Compose form):
 *   tg:31673        — Talkgroup broadcast (group call)
 *   radioid:3104410 — Direct DMR radio ID (private call)
 *
 * Currently this is a stub — the actual DMR text-data protocol
 * (BrandMeister REST or Homebrew/HBP DATA frames) is not yet
 * implemented. The Compose form picker is wired so the UX can
 * be validated; sends queue an audit entry but do not reach BM.
 *
 * @param array $message Keys: to, body, speak_on_channel? (bool)
 * @return array ['success' => bool, 'error' => string|null, 'note' => string]
 */
function _dmr_send(array $message) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $to     = (string) ($message['to'] ?? '');
    $body   = (string) ($message['body'] ?? '');
    $speak  = !empty($message['speak_on_channel']);

    if ($to === '') {
        return ['success' => false, 'error' => 'recipient is required'];
    }
    if ($body === '') {
        return ['success' => false, 'error' => 'body is required'];
    }

    // Parse the recipient prefix to determine call type.
    $isTalkgroup = (strpos($to, 'tg:') === 0);
    $isRadioId   = (strpos($to, 'radioid:') === 0);
    $target      = '';
    $callType    = '';
    if ($isTalkgroup) {
        $target   = (int) substr($to, 3);
        $callType = 'group';
    } elseif ($isRadioId) {
        $target   = (int) substr($to, 8);
        $callType = 'private';
    } else {
        // Bare numeric → assume radio id (private).
        $target = (int) $to;
        $callType = 'private';
    }
    if ($target <= 0) {
        return ['success' => false, 'error' => 'invalid DMR target id'];
    }

    // Speak-on-channel routes via the existing radio widget TX path
    // (Phase 84 — Piper TTS → AMBE encode → HBP frames → BrandMeister).
    // The text-data path itself is not yet built — when it is, the
    // route divides here based on $speak.
    if ($speak) {
        return [
            'success' => false,
            'error'   => 'speak_not_wired_from_compose',
            'note'    => 'Speak-on-channel from Compose is not yet wired — use the DMR radio widget for live TTS. Coming in a future build.',
        ];
    }

    // Resolve talkgroup name for the audit message.
    $name = '';
    if ($isTalkgroup) {
        try {
            $name = (string) db_fetch_value(
                "SELECT name FROM `{$prefix}talkgroups` WHERE dmr_id = ? LIMIT 1",
                [$target]
            );
        } catch (Throwable $e) { /* ignore */ }
    }

    // Honest stub — the text path needs BrandMeister Homebrew DATA frames
    // (HBP_DATA on slot 1 with the body framed per ETSI TS 102 361-2).
    // For now we record the attempt and tell the user.
    return [
        'success' => false,
        'error'   => 'dmr_text_not_implemented',
        'note'    => 'DMR text send to ' . ($isTalkgroup
            ? ('talkgroup ' . $target . ($name !== '' ? ' (' . $name . ')' : '') . ' — group call')
            : ('radio id ' . $target . ' — private call')) .
            ' is not yet wired. Picker validated; protocol pending.',
    ];
}

/**
 * Receive pending messages from DMR.
 *
 * @param int $limit Maximum messages to return
 * @return array List of received messages
 */
function _dmr_receive($limit = 50) {
    // Stub — will poll BrandMeister MQTT or DVSwitch proxy for queued messages.
    return [];
}

/**
 * Check if DMR is configured and enabled.
 *
 * @return string 'configured' | 'not_configured'
 */
function _dmr_status() {
    $config = _dmr_get_config();
    if (!$config) {
        return 'not_configured';
    }

    $mode = $config['mode'] ?? '';
    if ($mode === 'brandmeister_mqtt') {
        if (empty($config['brandmeister_api_key']) || empty($config['dmr_id'])) {
            return 'not_configured';
        }
        return 'configured';
    }

    if ($mode === 'dvswitch_proxy') {
        if (empty($config['dvswitch_host']) || empty($config['dvswitch_port'])) {
            return 'not_configured';
        }
        return 'configured';
    }

    return 'not_configured';
}

/**
 * Get DMR configuration from the settings table.
 *
 * @return array|null Config array or null if not found
 */
function _dmr_get_config() {
    $keys = [
        'dmr_enabled', 'dmr_mode', 'dmr_brandmeister_api_key', 'dmr_dmr_id',
        'dmr_talkgroup', 'dmr_dvswitch_host', 'dmr_dvswitch_port'
    ];
    $config = [];
    $hasAny = false;

    foreach ($keys as $key) {
        $shortKey = str_replace('dmr_', '', $key);
        if (function_exists('get_variable')) {
            $val = get_variable($key);
            if ($val !== false) {
                $config[$shortKey] = $val;
                $hasAny = true;
            } else {
                $config[$shortKey] = '';
            }
        } else {
            $config[$shortKey] = '';
        }
    }

    if (!$hasAny) {
        return null;
    }

    if (isset($config['enabled']) && !(int) $config['enabled']) {
        return null;
    }

    return $config;
}
