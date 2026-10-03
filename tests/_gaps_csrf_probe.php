<?php
/**
 * Phase 155 (found-gaps slice) -- drive a REAL api/ endpoint as a logged-in session user
 * with a chosen way of presenting (or withholding) the CSRF token, and print what it answered.
 *
 * One call = one subprocess, because every endpoint finishes through json_response() /
 * json_error(), which exit. Same discipline as tests/_p155_endpoint_probe.php; this one
 * exists because that probe always injects a VALID body token, so it cannot ask the
 * question the CSRF tests need: "what does the endpoint do with no token / a wrong token /
 * a token in the header / a token in the query string".
 *
 * Usage (spawn with proc_open and an ARGV ARRAY):
 *
 *   php tests/_gaps_csrf_probe.php <api-path> <user_id> <METHOD> <json-body> <query-string> <token-mode>
 *
 * token-mode:
 *   body    the session's real token in the JSON body (csrf_token)
 *   header  the session's real token in the X-CSRF-Token header only
 *   query   the session's real token in ?csrf_token= only
 *   none    no token anywhere
 *   bad     a WRONG token in the body, in the header and in the query string
 *
 * Prints ONE line of JSON: {"http": <int>, "body": "<raw response body>"}.
 * File name starts with `_` so tools/test_all.php does not run it as a test.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);

$apiPath  = (string) ($argv[1] ?? '');
$userId   = (int) ($argv[2] ?? 0);
$method   = strtoupper((string) ($argv[3] ?? 'GET'));
$payload  = (string) ($argv[4] ?? '');
$qs       = (string) ($argv[5] ?? '');
$tokenMode = (string) ($argv[6] ?? 'body');

if ($apiPath === '' || !in_array($tokenMode, ['body', 'header', 'query', 'none', 'bad'], true)) {
    fwrite(STDERR, "usage: <api-path> <user_id> <METHOD> <json-body> <query-string> body|header|query|none|bad\n");
    exit(2);
}

ob_start();
register_shutdown_function(function () {
    $body = '';
    while (ob_get_level() > 0) { $body = ob_get_clean() . $body; }
    $code = http_response_code();
    echo json_encode(['http' => $code === false ? 200 : (int) $code, 'body' => $body]) . "\n";
});

require_once $root . '/config.php';

$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gaps-csrf-probe';
if ($qs !== '') {
    parse_str($qs, $extra);
    $_GET = array_merge($_GET, $extra);
}
$body = $payload === '' ? [] : (json_decode($payload, true) ?: []);

$dir = sys_get_temp_dir() . '/newui_gaps_csrf_sess';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
session_save_path($dir);
$sid = 'gaps' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$real = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'p155-csrf-probe';
$_SESSION['user']       = 'p155-csrf-probe';
$_SESSION['csrf_token'] = $real;
session_write_close();
$_COOKIE[session_name()] = $sid;

unset($body['csrf_token']);
switch ($tokenMode) {
    case 'body':   $body['csrf_token'] = $real; break;
    case 'header': $_SERVER['HTTP_X_CSRF_TOKEN'] = $real; break;
    case 'query':  $_GET['csrf_token'] = $real; break;
    case 'bad':
        $wrong = 'not-the-token-' . bin2hex(random_bytes(4));
        $body['csrf_token'] = $wrong;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $wrong;
        $_GET['csrf_token'] = $wrong;
        break;
    case 'none':   break;
}

if ($method !== 'GET') {
    // The endpoints read their JSON body with file_get_contents('php://input'), which cannot be
    // faked through $_POST. Serve a fixed in-memory body by swapping the 'php' stream wrapper.
    class GapsFakeInputStream {
        private static $data = '';
        private $pos = 0;
        public $context;
        public static function setData(string $d): void { self::$data = $d; }
        public function stream_open($path, $mode, $options, &$opened_path) { $this->pos = 0; return true; }
        public function stream_read($count) {
            $ret = substr(self::$data, $this->pos, $count);
            $this->pos += strlen($ret);
            return $ret;
        }
        public function stream_eof() { return $this->pos >= strlen(self::$data); }
        public function stream_stat() { return []; }
    }
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'GapsFakeInputStream');
    GapsFakeInputStream::setData(json_encode($body));
}

try {
    include $root . '/' . ltrim($apiPath, '/');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'probe_exception' => $e->getMessage()]);
}
