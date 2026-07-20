<?php
/**
 * Channel: SMTP Email
 *
 * Supports two modes:
 * 1. Local sendmail — uses PHP's mail() function
 * 2. SMTP relay — direct socket connection to an SMTP server (Gmail, etc.)
 *
 * Configuration stored in settings table:
 *   email_mode      = 'sendmail' | 'smtp'
 *   smtp_host       = SMTP server hostname
 *   smtp_port       = 25 | 465 | 587
 *   smtp_encryption = 'none' | 'tls' | 'ssl'
 *   smtp_user       = Username/email for auth
 *   smtp_pass       = Password (encrypted)
 *   email_from      = From address
 *   email_from_name = From display name
 */

broker_register('smtp', [
    'name'    => 'Email (SMTP)',
    'send'    => '_smtp_send',
    'receive' => null,  // Email receive is not supported (use IMAP/POP3 separately)
    'status'  => '_smtp_status'
]);

function _smtp_send(array $message) {
    $config = _smtp_get_config();

    $to      = $message['to'] ?? '';
    $subject = $message['subject'] ?? 'TicketsCAD Notification';
    $body    = $message['body'] ?? '';
    $from    = $config['email_from'] ?? 'noreply@ticketscad.local';
    $fromName = $config['email_from_name'] ?? 'TicketsCAD';

    if (!$to) {
        return ['success' => false, 'error' => 'Recipient email address required'];
    }

    $headers = [
        'From'         => "{$fromName} <{$from}>",
        'Reply-To'     => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Mailer'     => 'TicketsCAD/4.0'
    ];

    $mode = $config['email_mode'] ?? 'sendmail';

    if ($mode === 'sendmail') {
        // Use PHP mail()
        $headerStr = '';
        foreach ($headers as $k => $v) {
            $headerStr .= "{$k}: {$v}\r\n";
        }
        $sent = @mail($to, $subject, $body, $headerStr);
        if ($sent) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'mail() failed — check sendmail config'];
    }

    if ($mode === 'smtp') {
        return _smtp_relay($config, $to, $subject, $body, $headers);
    }

    return ['success' => false, 'error' => "Unknown email mode: $mode"];
}

function _smtp_relay(array $config, $to, $subject, $body, array $headers) {
    $host = $config['smtp_host'] ?? 'localhost';
    $port = (int) ($config['smtp_port'] ?? 587);
    $encryption = $config['smtp_encryption'] ?? 'tls';
    $user = $config['smtp_user'] ?? '';
    $pass = $config['smtp_pass'] ?? '';
    $from = $config['email_from'] ?? 'noreply@ticketscad.local';

    $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$socket) {
        return ['success' => false, 'error' => "Connection failed: $errstr ($errno)"];
    }

    // Set timeout
    stream_set_timeout($socket, 15);

    $response = _smtp_read($socket);
    if (substr($response, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'error' => "Unexpected greeting: $response"];
    }

    // EHLO
    _smtp_write($socket, "EHLO ticketscad.local\r\n");
    _smtp_read($socket);

    // STARTTLS if needed
    if ($encryption === 'tls') {
        _smtp_write($socket, "STARTTLS\r\n");
        $resp = _smtp_read($socket);
        if (substr($resp, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS failed: $resp"];
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        _smtp_write($socket, "EHLO ticketscad.local\r\n");
        _smtp_read($socket);
    }

    // AUTH
    if ($user && $pass) {
        _smtp_write($socket, "AUTH LOGIN\r\n");
        _smtp_read($socket);
        _smtp_write($socket, base64_encode($user) . "\r\n");
        _smtp_read($socket);
        _smtp_write($socket, base64_encode($pass) . "\r\n");
        $authResp = _smtp_read($socket);
        if (substr($authResp, 0, 3) !== '235') {
            fclose($socket);
            return ['success' => false, 'error' => "Authentication failed: $authResp"];
        }
    }

    // MAIL FROM
    _smtp_write($socket, "MAIL FROM:<{$from}>\r\n");
    _smtp_read($socket);

    // RCPT TO (support multiple recipients)
    $recipients = array_map('trim', explode(',', $to));
    foreach ($recipients as $rcpt) {
        _smtp_write($socket, "RCPT TO:<{$rcpt}>\r\n");
        _smtp_read($socket);
    }

    // DATA
    _smtp_write($socket, "DATA\r\n");
    _smtp_read($socket);

    // Email-header-injection guard. Any value that gets interpolated
    // into "Header: <value>\r\n" must NOT itself contain CR/LF/NUL or
    // an attacker who controls that value can inject arbitrary headers
    // (Bcc, Reply-To, X-anything) and exfiltrate or redirect mail.
    // Strip them at the relay so every caller gets the defense; callers
    // that build subjects from user input should also sanitize at the
    // call site (defense in depth).
    $clean = static function ($v) {
        return preg_replace('/[\r\n\0]+/', ' ', (string) $v);
    };
    $subject = $clean($subject);
    $to      = $clean($to);

    // Build email
    $headerStr = "Subject: {$subject}\r\n";
    $headerStr .= "To: {$to}\r\n";
    foreach ($headers as $k => $v) {
        $headerStr .= $clean($k) . ": " . $clean($v) . "\r\n";
    }
    $headerStr .= "Date: " . date('r') . "\r\n";

    _smtp_write($socket, $headerStr . "\r\n" . $body . "\r\n.\r\n");
    $dataResp = _smtp_read($socket);

    // QUIT
    _smtp_write($socket, "QUIT\r\n");
    fclose($socket);

    if (substr($dataResp, 0, 3) === '250') {
        return ['success' => true];
    }
    return ['success' => false, 'error' => "Send failed: $dataResp"];
}

function _smtp_write($socket, $data) {
    fwrite($socket, $data);
}

function _smtp_read($socket) {
    $response = '';
    while ($line = fgets($socket, 512)) {
        $response .= $line;
        // Multi-line responses have '-' as 4th char; last line has ' '
        if (isset($line[3]) && $line[3] !== '-') break;
    }
    return trim($response);
}

function _smtp_get_config() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = ['email_mode', 'smtp_host', 'smtp_port', 'smtp_encryption',
             'smtp_user', 'smtp_pass', 'email_from', 'email_from_name'];
    $config = [];
    foreach ($keys as $k) {
        try {
            // Phase 87: legacy settings table uses column `name`, NOT
            // `key` (see CLAUDE.md gotchas). The earlier query was
            // silently catching the SQL error and returning null for
            // every key, so the smtp broker channel was a no-op even
            // when settings were populated. Fixed here.
            $val = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?",
                [$k]
            );
            $config[$k] = $val;
        } catch (Exception $e) {
            $config[$k] = null;
        }
    }
    return $config;
}

function _smtp_status() {
    $config = _smtp_get_config();
    $mode = $config['email_mode'] ?? '';
    if (!$mode) return 'not_configured';
    if ($mode === 'sendmail') return 'active';
    if ($mode === 'smtp' && $config['smtp_host']) return 'configured';
    return 'not_configured';
}
