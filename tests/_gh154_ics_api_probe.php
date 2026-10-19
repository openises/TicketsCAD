<?php
/**
 * GH#154 — CLI probe that drives the REAL api/ics-forms.php (via `include`, no
 * web server) as a given user and prints ONE line of JSON:
 *   {"status": <http code>, "body": <decoded JSON or raw>}
 *
 * Same discipline as tests/_p155_api_probe.php (real endpoint, real
 * csrf_verify() against the session's own token, php://input faked for POST),
 * plus the JSON Content-Type header this endpoint reads to decide whether the
 * body is JSON. json_response()/json_error() exit, so one request = one
 * process. File name starts with `_` so tools/test_all.php does not run it.
 *
 * Usage:
 *   php tests/_gh154_ics_api_probe.php GET  <user_id> "<query string>"
 *   php tests/_gh154_ics_api_probe.php POST <user_id> '<json body>'
 *   (optional 4th argument: the endpoint, api/ics-forms.php by default or
 *    api/ics-form-types.php)
 *
 * The POST body gets the session's csrf_token injected unless it carries its
 * own. Call through proc_open() with an ARRAY command on Windows --
 * escapeshellarg() mangles the double quotes in a JSON argument.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_gh154_probe_sess';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
session_save_path($dir);
$sid = 'gh154' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = (int) ($argv[2] ?? 0);
$_SESSION['username']   = 'gh154-probe';
$_SESSION['user']       = 'gh154-probe';
$_SESSION['csrf_token'] = $csrf;
session_write_close();

$endpoint = (string) ($argv[4] ?? 'api/ics-forms.php');
if (!in_array($endpoint, ['api/ics-forms.php', 'api/ics-form-types.php'], true)) {
    echo json_encode(['status' => 0, 'body' => 'probe: endpoint not allowed']);
    exit(0);
}
$method  = strtoupper((string) ($argv[1] ?? 'GET'));
$payload = (string) ($argv[3] ?? '');

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gh154-probe';

if ($method === 'GET') {
    parse_str($payload, $_GET);
} else {
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $body = $payload === '' ? [] : (json_decode($payload, true) ?: []);
    if (!array_key_exists('csrf_token', $body)) { $body['csrf_token'] = $csrf; }

    class Gh154ProbeFakeInput {
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
    stream_wrapper_register('php', 'Gh154ProbeFakeInput');
    Gh154ProbeFakeInput::setData(json_encode($body));
}

$captured = '';
ob_start(function ($chunk) use (&$captured) { $captured .= $chunk; return ''; });
register_shutdown_function(function () use (&$captured) {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    $code = http_response_code();
    $decoded = json_decode(trim($captured), true);
    echo json_encode(['status' => $code === false ? 200 : (int) $code, 'body' => $decoded === null ? $captured : $decoded]);
});

try {
    include $root . '/' . $endpoint;
} catch (Throwable $e) {
    $captured .= json_encode(['probe_exception' => $e->getMessage()]);
}
