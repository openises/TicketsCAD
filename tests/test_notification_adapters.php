<?php
/**
 * Phase 155 (GH#144) - the OUTBOUND ADAPTERS a notification rule sends through:
 * SMTP and the generic SMS provider.
 *
 * A rule's text and addresses are not only administrator-typed. A recipient can
 * come from a roster or contact record, or a user's own profile phone field; the
 * message can carry an incident's title, which can originate from an inbound
 * message. So the adapters are an injection surface, and this file attacks them:
 *
 *   SMTP - the real _smtp_relay() talks to a local fake SMTP server
 *   (tests/_p155_fake_smtp.php) that records EXACTLY what was said:
 *     * a recipient carrying CR/LF cannot add an RCPT TO command
 *     * a body containing a lone "." line cannot end the message early and have
 *       the rest read as SMTP commands (mail smuggling) - dot-stuffing
 *     * a subject carrying CR/LF cannot add a header (Bcc:)
 *     * a rejected recipient is reported, not silently ignored
 *     * the connect timeout is clamped to the caller's wall-clock budget
 *   SMS (generic REST) - values substituted into a JSON template cannot break out
 *   of the string and add a second "to"; into a URL cannot add a parameter; a
 *   value that itself contains "{body}" is not expanded a second time.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
require_once __DIR__ . '/../inc/notification-engine.php';
p155_install_cleanup();

echo "=== Phase 155 / GH#144 - outbound adapters: injection and deadlines ===\n\n";

echo "--- SMTP pure helpers ---\n";
p155_t('_smtp_clean_recipients keeps valid, drops invalid, de-duplicates case-insensitively',
    _smtp_clean_recipients('a@example.invalid, bad address, A@Example.invalid; not-an-address;  b@example.invalid') === ['a@example.invalid', 'b@example.invalid']);
p155_t('a recipient with an embedded CRLF command is dropped entirely',
    _smtp_clean_recipients("ok@example.invalid>\r\nRCPT TO:<evil@example.invalid") === []);
p155_t('_smtp_content_type: text/plain is honoured', _smtp_content_type('text/plain; charset=UTF-8') === 'text/plain; charset=UTF-8');
p155_t('...anything else (including a smuggled header) falls back to the historic text/html',
    _smtp_content_type("text/html\r\nBcc: x@y.z") === 'text/html; charset=UTF-8' && _smtp_content_type('') === 'text/html; charset=UTF-8');
p155_t('_smtp_prepare_body dot-stuffs a lone "." line and normalises line endings',
    _smtp_prepare_body("a\n.\nb\r\n.hidden\rc") === "a\r\n..\r\nb\r\n..hidden\r\nc");
p155_t('_smtp_encode_subject leaves an ASCII subject byte-identical', _smtp_encode_subject('New Incident #5: fire') === 'New Incident #5: fire');
$enc = _smtp_encode_subject("Fire at Caf\xC3\xA9 Rd");
p155_t('...and RFC 2047-encodes the non-ASCII word, on ONE line, all-ASCII on the wire',
    strpos($enc, '=?UTF-8?') !== false && !preg_match('/[\r\n]/', $enc) && !preg_match('/[^\x20-\x7E]/', $enc));
p155_t('...and strips CR/LF from a subject before anything else', strpos(_smtp_encode_subject("a\r\nBcc: x"), "\n") === false);

echo "\n--- SMTP against a real (fake) server ---\n";
$portFile = tempnam(sys_get_temp_dir(), 'p155port');
$logFile = tempnam(sys_get_temp_dir(), 'p155smtp');
@unlink($portFile);
file_put_contents($logFile, '');
$nullDev = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$proc = proc_open([PHP_BINARY, __DIR__ . '/_p155_fake_smtp.php', $portFile, $logFile],
    [1 => ['file', $nullDev, 'w'], 2 => ['file', $nullDev, 'w']], $pipes, null, null, ['bypass_shell' => true]);
$pid = is_resource($proc) ? (int) (proc_get_status($proc)['pid'] ?? 0) : 0;
$cleanupServer = function () use (&$proc, &$pid) {
    if (!is_resource($proc)) return;
    if (stripos(PHP_OS, 'WIN') === 0 && $pid > 0) { @exec('taskkill /T /F /PID ' . (int) $pid . ' 2>NUL'); }
    else { @proc_terminate($proc); }
    @proc_close($proc);
    $proc = null;
};
register_shutdown_function($cleanupServer);
$port = 0;
for ($i = 0; $i < 60 && $port === 0; $i++) {
    if (is_file($portFile) && (int) trim((string) file_get_contents($portFile)) > 0) $port = (int) trim(file_get_contents($portFile));
    else usleep(100000);
}
p155_t('the fake SMTP server is listening', $port > 0);

if ($port > 0) {
    foreach (['email_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'none',
              'smtp_user' => '', 'smtp_pass' => '', 'email_from' => 'cad@example.invalid', 'email_from_name' => 'P155 CAD'] as $k => $v) {
        p155_set_setting($k, $v);
    }
    $transcript = static function (): string { global $logFile; return (string) file_get_contents($logFile); };
    // The commands the server saw OUTSIDE a DATA block, in order: what the sender really COMMANDED.
    $wire = static function (string $t): array {
        $cmds = []; $in = false;
        foreach (preg_split('/\n/', $t) as $line) {
            if ($line === '--- DATA END ---') { $in = false; continue; }
            if (strncmp($line, 'S: 354', 6) === 0) { $in = true; continue; }
            if ($in || strncmp($line, 'C: ', 3) !== 0) continue;
            $cmds[] = strtoupper(substr($line, 3, 4));
        }
        return $cmds;
    };
    $reset = static function (): void { global $logFile; file_put_contents($logFile, ''); };

    // 1. A hostile body: a lone "." then commands.
    $reset();
    $r = _smtp_send(['to' => 'good@example.invalid', 'subject' => 'P155 body smuggling',
        'body' => "hello\r\n.\r\nMAIL FROM:<evil@example.invalid>\r\nRCPT TO:<victim@example.invalid>\r\nDATA\r\nsmuggled\r\n.\r\nmore"]);
    $t = $transcript();
    p155_t('a normal send to the fake server succeeds', !empty($r['success']));
    p155_t('the hostile body produced exactly the five commands of ONE message (EHLO MAIL RCPT DATA QUIT)',
        $wire($t) === ['EHLO', 'MAIL', 'RCPT', 'DATA', 'QUIT']);
    p155_t('...ONE end-of-data marker (the lone "." was stuffed, so it did not end the message)', substr_count($t, "--- DATA END ---") === 1);
    p155_t('...and the server never saw a smuggled command (a smuggled one would draw a 502 reply)', strpos($t, 'S: 502') === false);
    p155_t('...and the stuffed lines are inside the DATA block', strpos($t, "C: ..\n") !== false);

    // 2. A hostile recipient.
    $reset();
    $r = _smtp_send(['to' => "good@example.invalid>\r\nRCPT TO:<evil@example.invalid", 'subject' => 'P155 rcpt injection', 'body' => 'x']);
    $t = $transcript();
    p155_t('a recipient carrying a CRLF command is REFUSED before any connection is made', empty($r['success']) && stripos((string) $r['error'], 'invalid recipient') !== false && $t === '');
    $reset();
    $r = _smtp_send(['to' => "good@example.invalid, evil@example.invalid>\r\nDATA", 'subject' => 'P155 mixed', 'body' => 'x']);
    $t = $transcript();
    p155_t('in a mixed list only the VALID address is sent to', !empty($r['success'])
        && count(array_keys($wire($t), 'RCPT')) === 1 && strpos($t, 'evil') === false);

    // 3. A hostile subject.
    $reset();
    _smtp_send(['to' => 'good@example.invalid', 'subject' => "P155 subject\r\nBcc: evil@example.invalid", 'body' => 'x']);
    $t = $transcript();
    p155_t('a subject carrying CRLF + a header cannot add that header', !preg_match('/^C: Bcc:/m', $t));

    // 4. Content type.
    $reset();
    _smtp_send(['to' => 'good@example.invalid', 'subject' => 'P155 ct', 'body' => 'x', 'content_type' => 'text/plain; charset=UTF-8']);
    $t = $transcript();
    p155_t('a rule delivery asks for text/plain and gets it', strpos($t, 'C: Content-Type: text/plain; charset=UTF-8') !== false);
    $reset();
    _smtp_send(['to' => 'good@example.invalid', 'subject' => 'P155 ct', 'body' => 'x']);
    p155_t('CONTROL: a caller that does not ask (every pre-existing caller) still gets text/html', strpos($transcript(), 'C: Content-Type: text/html; charset=UTF-8') !== false);

    // 5. Rejection is reported.
    $reset();
    $r = _smtp_send(['to' => 'reject@example.invalid', 'subject' => 'P155 reject', 'body' => 'x']);
    p155_t('a server that rejects the ONLY recipient is reported as a failure (it used to be ignored)', empty($r['success']) && stripos((string) $r['error'], 'recipient rejected') !== false);
    p155_t('...which the queue classifies as PERMANENT (5xx: retrying cannot fix it)', notification_error_is_permanent((string) $r['error']));
    $reset();
    $r = _smtp_send(['to' => 'reject@example.invalid, ok@example.invalid', 'subject' => 'P155 half', 'body' => 'x']);
    p155_t('...but one accepted recipient among several is a success', !empty($r['success']));

    // 6. Non-ASCII subject.
    $reset();
    _smtp_send(['to' => 'good@example.invalid', 'subject' => "P155 Fire at Caf\xC3\xA9", 'body' => 'x']);
    p155_t('a non-ASCII subject is RFC 2047 encoded on the wire', (bool) preg_match('/^C: Subject: .*=\?UTF-8\?/m', $transcript()));

    $cleanupServer();
}

echo "\n--- SMTP deadline: the connect timeout is clamped to the caller's budget ---\n";
foreach (['email_mode' => 'smtp', 'smtp_host' => '203.0.113.1', 'smtp_port' => '25', 'smtp_encryption' => 'none'] as $k => $v) p155_set_setting($k, $v);
notify_deadline_set(1.0);
$t0 = microtime(true);
$r = _smtp_send(['to' => 'good@example.invalid', 'subject' => 'P155 blackhole', 'body' => 'x']);
$el = microtime(true) - $t0;
notify_deadline_clear();
p155_t(sprintf('a black-holed relay under a 1 s budget fails in %.1f s (not the fixed 10 s connect timeout)', $el), empty($r['success']) && $el < 4.0);
p155_t('...with an error the queue treats as TRANSIENT (the network comes back)', !notification_error_is_permanent((string) $r['error']));

echo "\n--- notify_adapter_timeout ---\n";
p155_t('with no deadline an adapter keeps its own timeout', notify_adapter_timeout(10) === 10);
notify_deadline_set(2.2);
p155_t('under a 2.2 s budget a 10 s timeout is clamped to 2 (never 0 - 0 means "no timeout" to cURL)', notify_adapter_timeout(10) === 2);
notify_deadline_set(0.0);
p155_t('an exhausted budget clamps to the 1 s floor, never to "unlimited"', notify_adapter_timeout(10) === 1);
notify_deadline_clear();

echo "\n--- SMS generic provider: substitution is escaped for where each value lands ---\n";
$evil = '","to":"+15550000000","x":"';
$json = _sms_generic_substitute('{"to":"{to}","body":"{body}"}', ['to' => '+15551230000', 'body' => $evil], 'json');
$dec = json_decode($json, true);
p155_t('a quote-breaking body stays INSIDE its JSON string (no second "to")', is_array($dec) && $dec['to'] === '+15551230000' && $dec['body'] === $evil && count($dec) === 2);
$url = _sms_generic_substitute('https://sms.example.invalid/send?to={to}&text={body}', ['to' => '+1555', 'body' => 'a&to=999#frag b'], 'url');
p155_t('a body with & # and a space is URL-encoded (cannot add or end a parameter)', $url === 'https://sms.example.invalid/send?to=%2B1555&text=a%26to%3D999%23frag%20b');
$form = _sms_generic_substitute('to={to}&body={body}', ['to' => '+1555', 'body' => 'x&y'], 'form');
p155_t('form bodies are form-encoded', $form === 'to=%2B1555&body=x%26y');
$hdr = _sms_generic_substitute('X-Key: {api_key}', ['api_key' => "abc\r\nX-Evil: 1"], 'header');
p155_t('a header value cannot carry CR/LF', strpos($hdr, "\n") === false && strpos($hdr, "\r") === false);
$second = _sms_generic_substitute('{to}|{body}', ['to' => '{body}', 'body' => 'SECRET'], 'url');
p155_t('strtr: a value that contains "{body}" is NOT expanded a second time', strpos($second, 'SECRET') !== false && substr_count($second, 'SECRET') === 1);
$smsCode = php_strip_whitespace(__DIR__ . '/../inc/channels/sms.php');   // comments and whitespace removed
p155_t('the precedence bug (`?? "" === "json"`, which made EVERY configured content type JSON) is gone from the CODE',
    strpos($smsCode, "??''==='json'") === false);

echo "\n--- transient vs permanent classification ---\n";
foreach ([
    'SMS provider not configured' => true, 'Slack not configured' => true, 'Phone number and message body required' => true,
    'Unknown channel: foo' => true, 'HTTP 401: unauthorized' => true, 'HTTP 404: not found' => true,
    'Send failed: 550 5.1.1 no such user' => true, 'Authentication failed: 535 bad' => true, 'Invalid recipient email address' => true,
    'Connection failed: Connection timed out (110)' => false, 'HTTP error: Operation timed out' => false, 'HTTP 503: unavailable' => false,
    'HTTP 429: slow down' => false, 'Send failed: 451 try later' => false, 'HTTP 408: timeout' => false,
] as $err => $perm) {
    p155_t("'{$err}' is " . ($perm ? 'PERMANENT' : 'transient'), notification_error_is_permanent($err) === $perm);
}

p155_cleanup();
@unlink($portFile); @unlink($logFile);
p155_done();
