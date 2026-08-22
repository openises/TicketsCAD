<?php
/**
 * CLI probe for tests/test_inbound_calls_sip_trunks_admin.php — drives the
 * REAL api/sip-trunks.php (via `include`, no web server involved), same
 * discipline as tests/_p149_endpoint_probe.php (GET) and
 * tests/_p149_claim_race_probe.php (POST + faked php://input body).
 * Combined into one probe here because sip-trunks.php's five actions are
 * a mix of GET (trunks/trunk) and POST (trunk_create/update/toggle/
 * delete/rotate_token), and every POST action needs the SAME session's
 * csrf_token to exercise the real csrf_verify() path, not a mocked one.
 *
 * Usage: php tests/_p149_sip_trunks_probe.php <METHOD> <action> <user_id> <json-body-or-querystring>
 *   GET  action query-string params come from the 4th arg parsed as a
 *        query string (e.g. "id=5"); pass "" for none.
 *   POST action body comes from the 4th arg as a JSON string; the probe
 *        injects csrf_token itself so callers never need to know it.
 * Prints one line: the endpoint's raw JSON response body.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_p149_sip_trunks_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'p149st' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$userId = (int) ($argv[3] ?? 0);
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'p149-sip-trunks-probe';
$_SESSION['user']       = 'p149-sip-trunks-probe';
$_SESSION['csrf_token'] = $csrf;
session_write_close();

$method  = strtoupper((string) ($argv[1] ?? 'GET'));
$action  = (string) ($argv[2] ?? '');
$payload = (string) ($argv[4] ?? '');

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p149-sip-trunks-probe';
$_GET['action'] = $action;

if ($method === 'GET') {
    if ($payload !== '') {
        parse_str($payload, $extra);
        $_GET = array_merge($_GET, $extra);
    }
} else {
    $body = $payload === '' ? [] : (json_decode($payload, true) ?: []);
    // Only inject the session's real csrf_token when the caller's own
    // payload didn't already specify one -- this lets a test deliberately
    // send a bogus/missing token to exercise the csrf_verify() rejection
    // path (pass {"csrf_token":"bogus"} or {"csrf_token":null} explicitly).
    if (!array_key_exists('csrf_token', $body)) {
        $body['csrf_token'] = $csrf;
    }

    class P149SipTrunksFakeInputStream {
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
    stream_wrapper_register('php', 'P149SipTrunksFakeInputStream');
    P149SipTrunksFakeInputStream::setData(json_encode($body));
}

ob_start();
try {
    include $root . '/api/sip-trunks.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'reason' => 'probe_exception', 'message' => $e->getMessage()]);
}
$out = ob_get_clean();
echo $out;
