<?php
/**
 * Channel: SMS
 *
 * Supports multiple SMS providers:
 * 1. Generic REST — configurable GET/POST endpoint with template variables
 * 2. Twilio — REST API
 * 3. BulkVS — REST API (portal.bulkvs.com/api/v1.0)
 * 4. Pushbullet — SMS via linked phone
 *
 * Configuration stored in settings table:
 *   sms_provider    = 'generic' | 'twilio' | 'bulkvs' | 'pushbullet'
 *   sms_*           = Provider-specific settings
 */

broker_register('sms', [
    'name'    => 'SMS',
    'send'    => '_sms_send',
    'receive' => '_sms_receive',
    'status'  => '_sms_status'
]);

function _sms_send(array $message) {
    $config = _sms_get_config();
    $provider = $config['sms_provider'] ?? '';

    if (!$provider) {
        return ['success' => false, 'error' => 'SMS provider not configured'];
    }

    $to   = $message['to'] ?? '';
    $body = $message['body'] ?? '';

    if (!$to || !$body) {
        return ['success' => false, 'error' => 'Phone number and message body required'];
    }

    switch ($provider) {
        case 'generic':  return _sms_send_generic($config, $to, $body, $message);
        case 'twilio':   return _sms_send_twilio($config, $to, $body);
        case 'bulkvs':   return _sms_send_bulkvs($config, $to, $body);
        case 'pushbullet': return _sms_send_pushbullet($config, $to, $body);
        default:
            return ['success' => false, 'error' => "Unknown SMS provider: $provider"];
    }
}

// ── Generic REST ──────────────────────────────────────────────

function _sms_send_generic(array $config, $to, $body, array $message) {
    $url    = $config['sms_generic_url'] ?? '';
    $method = strtoupper($config['sms_generic_method'] ?? 'POST');
    $tpl    = $config['sms_generic_template'] ?? '';

    if (!$url) {
        return ['success' => false, 'error' => 'Generic SMS URL not configured'];
    }

    // Template variable substitution
    $vars = [
        '{to}'       => $to,
        '{body}'     => $body,
        '{from}'     => $config['sms_from'] ?? '',
        '{subject}'  => $message['subject'] ?? '',
        '{api_key}'  => $config['sms_generic_api_key'] ?? '',
    ];

    // Apply template to URL and body
    $url = str_replace(array_keys($vars), array_values($vars), $url);

    $postData = '';
    if ($tpl) {
        $postData = str_replace(array_keys($vars), array_values($vars), $tpl);
    } else {
        $postData = http_build_query(['to' => $to, 'body' => $body]);
    }

    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($config['sms_generic_content_type'] ?? '' === 'json') {
        $headers = ['Content-Type: application/json'];
    }

    // Add auth header if configured
    $authHeader = $config['sms_generic_auth_header'] ?? '';
    if ($authHeader) {
        $authHeader = str_replace(array_keys($vars), array_values($vars), $authHeader);
        $headers[] = $authHeader;
    }

    return _sms_http_request($method, $url, $postData, $headers);
}

// ── Twilio ────────────────────────────────────────────────────

function _sms_send_twilio(array $config, $to, $body) {
    $sid   = $config['sms_twilio_sid'] ?? '';
    $token = $config['sms_twilio_token'] ?? '';
    $from  = $config['sms_from'] ?? '';

    if (!$sid || !$token || !$from) {
        return ['success' => false, 'error' => 'Twilio SID, Token, and From number required'];
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
    $postData = http_build_query([
        'To'   => $to,
        'From' => $from,
        'Body' => $body
    ]);

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode("{$sid}:{$token}")
    ];

    return _sms_http_request('POST', $url, $postData, $headers);
}

// ── BulkVS ────────────────────────────────────────────────────

function _sms_send_bulkvs(array $config, $to, $body) {
    $apiKey = $config['sms_bulkvs_api_key'] ?? '';
    $secret = $config['sms_bulkvs_secret'] ?? '';
    $from   = $config['sms_from'] ?? '';

    if (!$apiKey || !$secret || !$from) {
        return ['success' => false, 'error' => 'BulkVS API key, secret, and From number required'];
    }

    $url = 'https://portal.bulkvs.com/api/v1.0/messageSend';
    $postData = json_encode([
        'From' => $from,
        'To'   => [$to],
        'Message' => $body
    ]);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode("{$apiKey}:{$secret}")
    ];

    return _sms_http_request('POST', $url, $postData, $headers);
}

// ── Pushbullet ────────────────────────────────────────────────

function _sms_send_pushbullet(array $config, $to, $body) {
    $token    = $config['sms_pushbullet_token'] ?? '';
    $deviceId = $config['sms_pushbullet_device'] ?? '';

    if (!$token) {
        return ['success' => false, 'error' => 'Pushbullet access token required'];
    }

    // If no device specified, get the first phone device
    if (!$deviceId) {
        $deviceId = _sms_pushbullet_get_device($token);
        if (!$deviceId) {
            return ['success' => false, 'error' => 'No SMS-capable Pushbullet device found'];
        }
    }

    $url = 'https://api.pushbullet.com/v2/texts';
    $postData = json_encode([
        'data' => [
            'addresses'        => [$to],
            'message'          => $body,
            'target_device_iden' => $deviceId
        ]
    ]);

    $headers = [
        'Content-Type: application/json',
        'Access-Token: ' . $token
    ];

    return _sms_http_request('POST', $url, $postData, $headers);
}

function _sms_pushbullet_get_device($token) {
    $ch = curl_init('https://api.pushbullet.com/v2/devices');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Access-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!empty($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            if ($dev['has_sms'] ?? false) {
                return $dev['iden'];
            }
        }
    }
    return null;
}

// ── Receive (Pushbullet only) ─────────────────────────────────

function _sms_receive($limit = 50) {
    $config = _sms_get_config();
    if (($config['sms_provider'] ?? '') !== 'pushbullet') return [];

    $token = $config['sms_pushbullet_token'] ?? '';
    if (!$token) return [];

    // Fetch recent SMS via Pushbullet
    $ch = curl_init('https://api.pushbullet.com/v2/permanents/sms_threads');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Access-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    // Return raw threads — let the caller parse
    return $data['threads'] ?? [];
}

// ── HTTP Helper ───────────────────────────────────────────────

function _sms_http_request($method, $url, $postData, array $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    } elseif ($method === 'GET') {
        // Parameters already in URL
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "HTTP error: $error"];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'response' => $response];
    }

    return ['success' => false, 'error' => "HTTP $httpCode: $response"];
}

// ── Config ────────────────────────────────────────────────────

function _sms_get_config() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = ['sms_provider', 'sms_from',
             'sms_generic_url', 'sms_generic_method', 'sms_generic_template',
             'sms_generic_api_key', 'sms_generic_content_type', 'sms_generic_auth_header',
             'sms_twilio_sid', 'sms_twilio_token',
             'sms_bulkvs_api_key', 'sms_bulkvs_secret',
             'sms_pushbullet_token', 'sms_pushbullet_device'];
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

function _sms_status() {
    $config = _sms_get_config();
    $provider = $config['sms_provider'] ?? '';
    if (!$provider) return 'not_configured';
    return 'configured';
}
