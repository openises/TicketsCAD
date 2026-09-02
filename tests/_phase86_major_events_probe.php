<?php
/**
 * CLI probe for tests/test_phase86_major_events_extensions.php — drives
 * the REAL api/major-incidents.php (via `include`, no web server
 * involved), same discipline as tests/_p149_sip_trunks_probe.php.
 *
 * Usage:
 *   php tests/_phase86_major_events_probe.php GET <user_id> "<query-string>"
 *   php tests/_phase86_major_events_probe.php POST <user_id> '<json-body>'
 *
 * POST injects the session's own real csrf_token unless the caller's JSON
 * body already names one. Prints the endpoint's raw JSON response body.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_phase86_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'p86' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$userId = (int) ($argv[2] ?? 0);
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'phase86-probe';
$_SESSION['user']       = 'phase86-probe';
$_SESSION['csrf_token'] = $csrf;
session_write_close();

$method  = strtoupper((string) ($argv[1] ?? 'GET'));
$payload = (string) ($argv[3] ?? '');

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'phase86-probe';

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

    class Phase86FakeInputStream {
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
    stream_wrapper_register('php', 'Phase86FakeInputStream');
    Phase86FakeInputStream::setData(json_encode($body));
}

ob_start();
try {
    include $root . '/api/major-incidents.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'reason' => 'probe_exception', 'message' => $e->getMessage()]);
}
$out = ob_get_clean();
echo $out;
