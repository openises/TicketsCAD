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

    // Every recipient must be a real address. The relay below writes each one
    // into an SMTP command (RCPT TO:<...>) and mail() puts it in a header, so a
    // value carrying CR/LF would be a command or header injection. A rule's
    // address can come from a roster or contact record, or a user's own profile
    // - not only from an administrator - so it is validated HERE, at the one
    // place every sender passes through. FILTER_VALIDATE_EMAIL rejects CR/LF.
    $recipients = _smtp_clean_recipients((string) $to);
    if (!$recipients) {
        return ['success' => false, 'error' => 'Invalid recipient email address'];
    }
    $to = implode(', ', $recipients);

    // Plain text or HTML. Every pre-existing caller left this unset and keeps
    // getting HTML; Notification Rules send text/plain unless the administrator
    // chose HTML (Settings -> Notification Rules -> delivery settings).
    $contentType = _smtp_content_type($message['content_type'] ?? '');

    $headers = [
        'From'         => "{$fromName} <{$from}>",
        'Reply-To'     => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => $contentType,
        'X-Mailer'     => 'TicketsCAD/4.0'
    ];

    $mode = $config['email_mode'] ?? 'sendmail';

    if ($mode === 'sendmail') {
        // Use PHP mail()
        $clean = static function ($v) { return preg_replace('/[\r\n\0]+/', ' ', (string) $v); };
        $headerStr = '';
        foreach ($headers as $k => $v) {
            $headerStr .= $clean($k) . ': ' . $clean($v) . "\r\n";
        }
        $sent = @mail($to, _smtp_encode_subject($subject), $body, $headerStr);
        if ($sent) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'mail() failed — check sendmail config'];
    }

    if ($mode === 'smtp') {
        return _smtp_relay($config, $recipients, $subject, $body, $headers);
    }

    return ['success' => false, 'error' => "Unknown email mode: $mode"];
}

/**
 * Split a recipient string (comma / semicolon separated) into validated
 * addresses. Anything that is not a valid address is DROPPED - and if that
 * leaves nothing, the caller refuses to send. Pure.
 *
 * @return string[]
 */
function _smtp_clean_recipients(string $to): array {
    $out = [];
    foreach (preg_split('/[,;]+/', $to) as $r) {
        $r = trim($r);
        if ($r === '' || strlen($r) > 254) continue;
        if (filter_var($r, FILTER_VALIDATE_EMAIL) === false) continue;
        if (!isset($out[strtolower($r)])) $out[strtolower($r)] = $r;   // first spelling wins
    }
    return array_values($out);
}

/** Whitelist the Content-Type a caller may ask for; anything else is HTML (the historic default). Pure. */
function _smtp_content_type($requested): string {
    $r = strtolower(trim((string) $requested));
    if (strpos($r, 'text/plain') === 0) return 'text/plain; charset=UTF-8';
    return 'text/html; charset=UTF-8';
}

/**
 * RFC 2047-encode a Subject that contains non-ASCII text, on one line. An
 * incident title with an accented letter used to go out as raw 8-bit in a header,
 * which many servers mangle. ASCII subjects are untouched (every existing
 * caller's output is byte-identical). Pure given mbstring.
 */
function _smtp_encode_subject($subject): string {
    $s = (string) preg_replace('/[\r\n\0]+/', ' ', (string) $subject);
    if (!preg_match('/[^\x20-\x7E]/', $s)) return $s;
    if (function_exists('mb_encode_mimeheader')) {
        $enc = mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
        // One line: adjacent encoded-words may be separated by plain whitespace.
        return (string) preg_replace('/\r\n[ \t]*/', ' ', $enc);
    }
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/**
 * Prepare a message body for the SMTP DATA command: CRLF line endings, and
 * "dot-stuffing" (RFC 5321 4.5.2) - a body line that begins with '.' gets a
 * second one. Without it a line consisting of a single '.' in the body ENDS the
 * message, and whatever follows is read by the server as SMTP commands (a mail
 * smuggling path for any text an inbound message can put into an incident).
 * Pure.
 */
function _smtp_prepare_body($body): string {
    $b = (string) preg_replace('/\r\n|\r|\n/', "\r\n", (string) $body);
    $b = str_replace("\0", '', $b);
    return (string) preg_replace('/(^|\r\n)\./', '$1..', $b);
}

/**
 * @param string[]|string $to validated recipient addresses
 */
function _smtp_relay(array $config, $to, $subject, $body, array $headers) {
    $host = $config['smtp_host'] ?? 'localhost';
    $port = (int) ($config['smtp_port'] ?? 587);
    $encryption = $config['smtp_encryption'] ?? 'tls';
    $user = $config['smtp_user'] ?? '';
    $pass = $config['smtp_pass'] ?? '';
    $from = $config['email_from'] ?? 'noreply@ticketscad.local';

    // Connect + read timeouts are clamped to the caller's wall-clock budget when
    // one is in force (the inline attempt a dispatch makes when no scheduler is
    // running). Fixed 10 s + 15 s timeouts held that request for as long as a
    // dead relay cared to take.
    $connectTimeout = function_exists('notify_adapter_timeout') ? notify_adapter_timeout(10) : 10;
    $readTimeout    = function_exists('notify_adapter_timeout') ? notify_adapter_timeout(15) : 15;

    $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, $connectTimeout);
    if (!$socket) {
        return ['success' => false, 'error' => "Connection failed: $errstr ($errno)"];
    }

    // Set timeout
    stream_set_timeout($socket, $readTimeout);

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

    // MAIL FROM (the configured sender - strip anything that could end the command)
    _smtp_write($socket, 'MAIL FROM:<' . $clean($from) . ">\r\n");
    _smtp_read($socket);

    // RCPT TO: one command per recipient, each already validated by
    // _smtp_clean_recipients(). At least one must be ACCEPTED, or there is
    // nothing to send to - the old code ignored the replies and reported
    // whatever DATA said.
    $recipients = is_array($to) ? $to : _smtp_clean_recipients((string) $to);
    $accepted = 0;
    $lastRcpt = '';
    foreach ($recipients as $rcpt) {
        _smtp_write($socket, 'RCPT TO:<' . $rcpt . ">\r\n");
        $lastRcpt = _smtp_read($socket);
        if (in_array(substr($lastRcpt, 0, 3), ['250', '251'], true)) $accepted++;
    }
    if ($accepted === 0) {
        _smtp_write($socket, "QUIT\r\n");
        fclose($socket);
        return ['success' => false, 'error' => 'Send failed: ' . ($lastRcpt !== '' ? 'recipient rejected: ' . $lastRcpt : 'no recipient accepted')];
    }

    // DATA
    _smtp_write($socket, "DATA\r\n");
    _smtp_read($socket);

    $subject = _smtp_encode_subject($subject);
    $toHeader = $clean(implode(', ', $recipients));

    // Build email
    $headerStr = "Subject: {$subject}\r\n";
    $headerStr .= "To: {$toHeader}\r\n";
    foreach ($headers as $k => $v) {
        $headerStr .= $clean($k) . ": " . $clean($v) . "\r\n";
    }
    $headerStr .= "Date: " . date('r') . "\r\n";

    _smtp_write($socket, $headerStr . "\r\n" . _smtp_prepare_body($body) . "\r\n.\r\n");
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
