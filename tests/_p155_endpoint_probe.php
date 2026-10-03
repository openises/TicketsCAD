<?php
/**
 * Phase 155 -- generic CLI endpoint probe: drives a REAL api/ file (via
 * `include`, no web server) as either a logged-in session user or an External
 * API bearer token, and prints exactly what the endpoint answered.
 *
 * One call = one subprocess, because every endpoint finishes through
 * json_response()/ext_api_error(), which exit. Same discipline as
 * tests/_p149_endpoint_probe.php and tests/_gh96_mileage_report_probe.php;
 * this one adds the HTTP status code and a bearer mode so the External API's
 * PATCH can be exercised for real (GH#147's ordering fix).
 *
 * Usage (spawn with proc_open and an ARGV ARRAY -- escapeshellarg mangles
 * quotes on Windows):
 *
 *   php tests/_p155_endpoint_probe.php session <api-path> <user_id> <METHOD> <json-body> [query-string] [active_org_id]
 *   php tests/_p155_endpoint_probe.php bearer  <api-path> <raw-token> <METHOD> <json-body> [query-string]
 *
 * `session` injects the session's own csrf_token into a POST body unless the
 * body already names one. `bearer` presents HTTPS (the External API refuses
 * plaintext by default) and the Authorization header.
 *
 * Prints ONE line of JSON: {"http": <int>, "body": "<raw response body>"}.
 *
 * File name starts with `_` so tools/test_all.php (which globs test_*.php)
 * does not try to run it as a test.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);

$mode     = (string) ($argv[1] ?? '');
$apiPath  = (string) ($argv[2] ?? '');
$identity = (string) ($argv[3] ?? '');
$method   = strtoupper((string) ($argv[4] ?? 'GET'));
$payload  = (string) ($argv[5] ?? '');
$qs       = (string) ($argv[6] ?? '');
$activeOrg = (int) ($argv[7] ?? 0);      // session mode only: $_SESSION['active_org_id']

if (!in_array($mode, ['session', 'bearer'], true) || $apiPath === '') {
    fwrite(STDERR, "usage: session|bearer <api-path> <identity> <METHOD> <json-body> [qs]\n");
    exit(2);
}

// The probe's own output envelope. Registered BEFORE the endpoint runs so it
// is the first shutdown function to fire, after the endpoint's exit().
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
$_SERVER['HTTP_USER_AGENT'] = 'p155-probe';
if ($qs !== '') {
    parse_str($qs, $extra);
    $_GET = array_merge($_GET, $extra);
}

$body = $payload === '' ? [] : (json_decode($payload, true) ?: []);

if ($mode === 'session') {
    $dir = sys_get_temp_dir() . '/newui_p155_sess';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    session_save_path($dir);
    $sid = 'p155' . bin2hex(random_bytes(8));
    session_id($sid);
    session_start();
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['user_id']    = (int) $identity;
    $_SESSION['username']   = 'p155-probe';
    $_SESSION['user']       = 'p155-probe';
    $_SESSION['csrf_token'] = $csrf;
    // An org-scoped RBAC grant is satisfied only when active_org_id equals the
    // grant's scope_id (inc/rbac.php) -- exactly what a real login would have set.
    if ($activeOrg > 0) { $_SESSION['active_org_id'] = $activeOrg; }
    session_write_close();
    $_COOKIE[session_name()] = $sid;
    if ($method !== 'GET' && !array_key_exists('csrf_token', $body)) {
        $body['csrf_token'] = $csrf;
    }
} else {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $identity;
    $_SERVER['HTTPS'] = 'on';
}

if ($method !== 'GET') {
    // The endpoints read their JSON body with file_get_contents('php://input'),
    // which cannot be faked through $_POST. Serve a fixed in-memory body by
    // swapping the 'php' stream wrapper (the standard CLI-harness trick).
    class P155FakeInputStream {
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
    stream_wrapper_register('php', 'P155FakeInputStream');
    P155FakeInputStream::setData(json_encode($body));
}

try {
    include $root . '/' . ltrim($apiPath, '/');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'probe_exception' => $e->getMessage()]);
}
