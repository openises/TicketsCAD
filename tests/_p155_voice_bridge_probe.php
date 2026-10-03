<?php
/**
 * CLI probe for the Phase 155 digital-voice-bridge tests — drives a REAL
 * api/*.php endpoint file through `include` (no web server), same discipline
 * as tests/_p149_sip_trunks_probe.php: one call = one fresh PHP process, so
 * get_variable()'s per-process settings cache can never hide a change a test
 * just made.
 *
 * Usage:
 *   php tests/_p155_voice_bridge_probe.php <endpoint.php> <METHOD> <query-string> <user_id> [json-body]
 *
 *   endpoint      file under api/ (e.g. voice-bridge-channels.php)
 *   METHOD        GET or POST
 *   query-string  parsed into $_GET (e.g. "action=list" or "action=create")
 *   user_id       the session's user (decides the RBAC outcome)
 *   json-body     POST only. The probe injects the session's real csrf_token
 *                 unless the body already names one (so a test can send a
 *                 bogus or null token to exercise the rejection path).
 *
 * Prints the endpoint's raw output followed by one line "HTTP <status>" so a
 * test can assert the status code too.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$endpoint = (string) ($argv[1] ?? '');
if (!preg_match('/^[a-z0-9-]+\.php$/', $endpoint) || !is_file($root . '/api/' . $endpoint)) {
    echo json_encode(['error' => 'probe: bad endpoint']) . "\nHTTP 0\n";
    exit(1);
}
$method  = strtoupper((string) ($argv[2] ?? 'GET'));
$query   = (string) ($argv[3] ?? '');
$userId  = (int) ($argv[4] ?? 0);
$payload = (string) ($argv[5] ?? '');

$dir = sys_get_temp_dir() . '/newui_p155_probe_sess';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
session_save_path($dir);
$sid = 'p155' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'p155-probe';
$_SESSION['user']       = 'p155-probe';
$_SESSION['csrf_token'] = $csrf;
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p155-probe';
parse_str($query, $_GET);

if ($method !== 'GET') {
    $body = $payload === '' ? [] : (json_decode($payload, true) ?: []);
    if (!array_key_exists('csrf_token', $body)) {
        $body['csrf_token'] = $csrf;
    }
    class P155FakeInputStream {
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
    stream_wrapper_register('php', 'P155FakeInputStream');
    P155FakeInputStream::setData(json_encode($body));
}

// json_response()/json_error() finish with exit(), which skips anything after
// the include; a shutdown function still runs, after the endpoint's own output.
register_shutdown_function(function () {
    echo "\nHTTP " . (http_response_code() ?: 200) . "\n";
});
try {
    include $root . '/api/' . $endpoint;
} catch (Throwable $e) {
    echo json_encode(['error' => 'probe_exception', 'message' => $e->getMessage()]);
}
