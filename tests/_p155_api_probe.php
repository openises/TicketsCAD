<?php
/**
 * Generic CLI probe for Phase 155 phone tests: drives a REAL api/*.php file
 * (via `include`, no web server) as a given user, GET or POST, and prints
 * ONE line of JSON:  {"status": <http code>, "body": <decoded JSON or raw>}
 *
 * Same discipline as tests/_p149_sip_trunks_probe.php (real endpoint, real
 * csrf_verify() against the session's own token, php://input faked for POST),
 * generalised so every Phase 155 test shares one harness instead of copying
 * the stream-wrapper boilerplate. File name starts with `_` so
 * tools/test_all.php does not run it as a test.
 *
 * Usage:
 *   php tests/_p155_api_probe.php <api/file.php> <METHOD> <action> <user_id> [payload] [session-json]
 *     GET : payload is a query string ("id=5&workstation_token=abc")
 *     POST: payload is a JSON body; the session's real csrf_token is injected
 *           unless the payload carries its own "csrf_token" key (pass
 *           {"csrf_token":"bogus"} to exercise the rejection path).
 *     session-json: optional extra $_SESSION values (e.g. {"active_org_id":7}).
 *
 * proc_open() with an ARRAY command is required on Windows: escapeshellarg()
 * replaces embedded double quotes with spaces and would destroy the JSON.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$apiPath = (string) ($argv[1] ?? '');
if (!preg_match('#^api/[A-Za-z0-9_\-/]+\.php$#', $apiPath) || !is_file($root . '/' . $apiPath)) {
    echo json_encode(['status' => 0, 'body' => 'probe: bad api path']);
    exit(0);
}

require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_p155_probe_sess';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
session_save_path($dir);
$sid = 'p155' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = (int) ($argv[4] ?? 0);
$_SESSION['username']   = 'p155-probe';
$_SESSION['user']       = 'p155-probe';
$_SESSION['csrf_token'] = $csrf;
$extra = isset($argv[6]) && $argv[6] !== '' ? json_decode((string) $argv[6], true) : null;
if (is_array($extra)) {
    foreach ($extra as $k => $v) { $_SESSION[$k] = $v; }
}
session_write_close();

$method  = strtoupper((string) ($argv[2] ?? 'GET'));
$action  = (string) ($argv[3] ?? '');
$payload = (string) ($argv[5] ?? '');

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p155-probe';
$_GET['action'] = $action;

if ($method === 'GET') {
    if ($payload !== '') {
        parse_str($payload, $more);
        $_GET = array_merge($_GET, $more);
    }
} else {
    $body = $payload === '' ? [] : (json_decode($payload, true) ?: []);
    if (!array_key_exists('csrf_token', $body)) { $body['csrf_token'] = $csrf; }

    class P155ProbeFakeInput {
        private static $data = '';
        private $pos = 0;
        public $context;
        public static function setData(string $d): void { self::$data = $d; }
        public function stream_open($path, $mode, $options, &$opened_path) { $this->pos = 0; return true; }
        public function stream_read($count) { $ret = substr(self::$data, $this->pos, $count); $this->pos += strlen($ret); return $ret; }
        public function stream_eof() { return $this->pos >= strlen(self::$data); }
        public function stream_stat() { return []; }
    }
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'P155ProbeFakeInput');
    P155ProbeFakeInput::setData(json_encode($body));
}

// json_response()/json_error() exit, so the status line is written from a
// shutdown handler, reading what they set.
$captured = '';
ob_start(function ($chunk) use (&$captured) { $captured .= $chunk; return ''; });
register_shutdown_function(function () use (&$captured) {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    $code = http_response_code();
    $decoded = json_decode(trim($captured), true);
    echo json_encode(['status' => $code === false ? 200 : (int) $code, 'body' => $decoded === null ? $captured : $decoded]);
});

try {
    include $root . '/' . $apiPath;
} catch (Throwable $e) {
    $captured .= json_encode(['probe_exception' => $e->getMessage()]);
}
