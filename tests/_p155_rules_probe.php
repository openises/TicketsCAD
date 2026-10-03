<?php
/**
 * CLI probe for the Phase 155 notification tests - drives the REAL
 * api/notification-rules.php (or api/email-lists.php) through `include`, with a
 * real session and a real csrf_token, no web server involved. Same discipline as
 * tests/_p149_sip_trunks_probe.php.
 *
 * Usage: php tests/_p155_rules_probe.php <METHOD> <action> <user_id> <payload> [endpoint]
 *   GET   payload is a query string ("id=5"; "" for none)
 *   POST  payload is a JSON object; the probe injects the session's csrf_token
 *         unless the payload already names one (pass {"csrf_token":"bogus"} or
 *         {"csrf_token":null} to exercise the rejection path)
 *   endpoint  rules (default) | email-lists | messaging
 *
 * Environment:
 *   P155_CAPTURE=1       replace the email / sms / local_chat / slack / telegram /
 *                        push broker channels with stubs that APPEND one JSON line
 *                        per send to $P155_CAPTURE_FILE and report success
 *                        (or failure with a message, when P155_CAPTURE_FAIL is set)
 *
 * stdout: the endpoint's raw JSON body.  stderr: one line `HTTP_STATUS=<code>`
 * (and whatever PHP logs - tests read the two pipes separately).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_p155_rules_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'p155r' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$userId = (int) ($argv[3] ?? 0);
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'p155-rules-probe';
$_SESSION['user']       = 'p155-rules-probe';
$_SESSION['csrf_token'] = $csrf;
session_write_close();

$method  = strtoupper((string) ($argv[1] ?? 'GET'));
$action  = (string) ($argv[2] ?? '');
$payload = (string) ($argv[4] ?? '');
$endpoint = (string) ($argv[5] ?? 'rules');

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p155-rules-probe';
$_GET['action'] = $action;

if (getenv('P155_CAPTURE')) {
    require_once $root . '/inc/notification-engine.php';   // loads the broker + every adapter first
    $file = (string) getenv('P155_CAPTURE_FILE');
    $fail = getenv('P155_CAPTURE_FAIL');
    foreach (['email', 'smtp', 'sms', 'local_chat', 'slack', 'telegram', 'push'] as $code) {
        $shared = in_array($code, ['slack', 'telegram'], true);
        broker_register($code, [
            'name' => 'probe capture ' . $code,
            'send' => static function (array $m) use ($code, $file, $fail) {
                if ($file !== '') {
                    @file_put_contents($file, json_encode(['channel' => $code, 'to' => $m['to'] ?? null,
                        'subject' => $m['subject'] ?? '', 'body' => $m['body'] ?? '',
                        'content_type' => $m['content_type'] ?? '', 'uids' => $m['_recipient_user_ids'] ?? null]) . "\n",
                        FILE_APPEND);
                }
                return $fail ? ['success' => false, 'error' => (string) $fail] : ['success' => true];
            },
            'receive' => null,
            'status' => static function () { return 'active'; },
            'shared_destination' => $shared,
        ]);
    }
}

if ($method === 'GET') {
    if ($payload !== '') {
        parse_str($payload, $extra);
        $_GET = array_merge($_GET, $extra);
    }
} else {
    $body = $payload === '' ? [] : (json_decode($payload, true) ?: []);
    if (!array_key_exists('csrf_token', $body)) {
        $body['csrf_token'] = $csrf;
    }

    class P155RulesFakeInputStream {
        private static $data = '';
        private $pos = 0;
        public $context; // PHP stream wrappers require this property to exist
        public static function setData(string $d): void { self::$data = $d; }
        public function stream_open($path, $mode, $options, &$opened_path) { $this->pos = 0; return true; }
        public function stream_read($count) { $ret = substr(self::$data, $this->pos, $count); $this->pos += strlen($ret); return $ret; }
        public function stream_eof() { return $this->pos >= strlen(self::$data); }
        public function stream_stat() { return []; }
    }
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'P155RulesFakeInputStream');
    P155RulesFakeInputStream::setData(json_encode($body));
}

register_shutdown_function(static function () {
    fwrite(STDERR, 'HTTP_STATUS=' . (int) http_response_code() . "\n");
});

$targets = ['rules' => '/api/notification-rules.php', 'email-lists' => '/api/email-lists.php', 'messaging' => '/api/messaging.php'];
$target = $targets[$endpoint] ?? '/api/notification-rules.php';
ob_start();
try {
    include $root . $target;
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'reason' => 'probe_exception', 'message' => $e->getMessage()]);
}
$out = ob_get_clean();
echo $out;
