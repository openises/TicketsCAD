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
 */

broker_register('slack', [
    'name'    => 'Slack',
    'send'    => '_slack_send',
    'receive' => '_slack_receive',
    'status'  => '_slack_status'
]);

function _slack_send(array $message) {
    $config = _slack_get_config();
    $mode = $config['slack_mode'] ?? '';

    if (!$mode) {
        return ['success' => false, 'error' => 'Slack not configured'];
    }

    $body    = $message['body'] ?? '';
    $channel = $message['slack_channel'] ?? $config['slack_channel'] ?? '#general';

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

function _slack_receive($limit = 50) {
    $config = _slack_get_config();
    if (($config['slack_mode'] ?? '') !== 'api') return [];

    $token   = $config['slack_token'] ?? '';
    $channel = $config['slack_channel'] ?? '';
    if (!$token || !$channel) return [];

    $ch = curl_init('https://slack.com/api/conversations.history?' . http_build_query([
        'channel' => $channel,
        'limit'   => $limit
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    return $data['messages'] ?? [];
}

function _slack_get_config() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = ['slack_mode', 'slack_webhook', 'slack_token', 'slack_channel'];
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
