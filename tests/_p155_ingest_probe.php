<?php
/**
 * CLI probe for tests/test_phase155_sip_bridge_status.php -- drives the REAL
 * api/sip-ingest.php (via `include`, no web server involved) with a bearer
 * token and a faked php://input body, same technique as
 * tests/_p149_claim_race_probe.php.
 *
 * Usage: php tests/_p155_ingest_probe.php <bearer-token> <json-body>
 * Prints the endpoint's raw response body (one JSON document).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$token = (string) ($argv[1] ?? '');
$body  = (string) ($argv[2] ?? '{}');

$_SERVER['REQUEST_METHOD']     = 'POST';
$_SERVER['REMOTE_ADDR']        = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT']    = 'p155-ingest-probe';
$_SERVER['HTTP_AUTHORIZATION'] = $token === '' ? '' : 'Bearer ' . $token;

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
P155FakeInputStream::setData($body);

ob_start();
try {
    include $root . '/api/sip-ingest.php';
} catch (Throwable $e) {
    echo json_encode(['error' => 'probe_exception', 'message' => $e->getMessage()]);
}
echo ob_get_clean();
