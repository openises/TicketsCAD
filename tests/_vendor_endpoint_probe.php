<?php
/**
 * CLI probe for tests/test_vendor_endpoints.php: drives api/vendor-dispatch.php or api/vendor-admin.php through the
 * REAL endpoint file with a faked, attached session (no login flow, nobody's credentials touched) -- real
 * session_start(), real rbac_can(), real org scoping, real CSRF check, real json_response() / exit.
 *
 * One call = one process, because the endpoints finish with exit. POST bodies are served through a php:// stream-wrapper
 * override (this PHP build's CLI does not deliver piped STDIN through php://input -- the Phase 149 probe's trick).
 * The HTTP status the endpoint chose is appended after a marker line, since json_response() sets it with
 * http_response_code() and then exits.
 *
 * Usage: php tests/_vendor_endpoint_probe.php <api/file.php> <GET|POST> <user_id> <active_org_id|0> <csrf:good|bad|none> '<query-or-json>'
 * Output: the endpoint's body, then "\n__HTTP__<status>\n".
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$apiFile = (string) ($argv[1] ?? '');
$method = strtoupper((string) ($argv[2] ?? 'GET'));
$userId = (int) ($argv[3] ?? 0);
$activeOrg = (int) ($argv[4] ?? 0);
$csrfMode = (string) ($argv[5] ?? 'good');
$payload = (string) ($argv[6] ?? '');

if (!in_array($apiFile, ['api/vendor-dispatch.php', 'api/vendor-admin.php'], true)) { fwrite(STDERR, "bad api file\n"); exit(2); }

$dir = sys_get_temp_dir() . '/newui_vendor_probe_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'vprobe' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'vendor-probe-' . $userId;
$_SESSION['user']       = 'vendor-probe-' . $userId;
$_SESSION['csrf_token'] = $csrf;
if ($activeOrg > 0) $_SESSION['active_org_id'] = $activeOrg;
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'vendor-endpoint-probe';

if ($method === 'GET') {
    parse_str($payload, $_GET);
} else {
    $body = json_decode($payload, true);
    if (!is_array($body)) $body = [];
    if ($csrfMode === 'good') $body['csrf_token'] = $csrf;
    elseif ($csrfMode === 'bad') $body['csrf_token'] = 'not-the-token';
    else unset($body['csrf_token']);

    class VendorProbeFakeInput {
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
    stream_wrapper_register('php', 'VendorProbeFakeInput');
    VendorProbeFakeInput::setData(json_encode($body));
}

register_shutdown_function(function () {
    // http_response_code() is false when the endpoint never set one (the CSV download path): a web server's default is 200.
    $code = http_response_code();
    echo "\n__HTTP__" . ($code === false ? 200 : (int) $code) . "\n";
});

include $root . '/' . $apiFile;
