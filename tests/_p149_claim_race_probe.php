<?php
/**
 * CLI probe for tests/test_inbound_calls_claim_race.php's Part B — drives
 * the REAL api/inbound-calls.php `claim` action (via `include`, simulating
 * a POST through a faked php://input body — no web server involved),
 * with its own distinct logged-in session (same discipline as
 * tests/_p149_endpoint_probe.php / tests/_gh96_mileage_report_probe.php).
 * Two of these are launched as separate OS processes at nearly the same
 * instant (via proc_open(), not a shell background job) so the
 * concurrency the atomic UPDATE defends against is real.
 *
 * Usage: php tests/_p149_claim_race_probe.php <call_id> <user_id>
 * Prints one line of JSON: the endpoint's raw response body.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_p149_race_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'p149race' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$userId = (int) ($argv[2] ?? 0);
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']     = $userId;
$_SESSION['username']    = 'p149-race-probe';
$_SESSION['user']        = 'p149-race-probe';
$_SESSION['csrf_token']  = $csrf;
session_write_close();

$callId = (int) ($argv[1] ?? 0);

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'POST';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p149-race-probe';
$_GET['action'] = 'claim';

// api/inbound-calls.php reads its JSON body via
// file_get_contents('php://input'), which cannot be faked through
// $GLOBALS/$_POST the way query-string params can. Overriding the 'php'
// stream wrapper to serve a fixed in-memory body is the standard trick
// for driving a raw-body-reading endpoint from a CLI harness without a
// real HTTP request.
$body = json_encode(['id' => $callId, 'csrf_token' => $csrf]);
class P149FakeInputStream {
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
stream_wrapper_register('php', 'P149FakeInputStream');
P149FakeInputStream::setData($body);

ob_start();
try {
    include $root . '/api/inbound-calls.php';
} catch (Throwable $e) {
    // json_error()/json_response() call exit(), so a real Throwable here
    // means something failed before that — surface it as JSON for the
    // parent test to report cleanly instead of hanging on invalid output.
    echo json_encode(['success' => false, 'reason' => 'probe_exception', 'message' => $e->getMessage()]);
}
$out = ob_get_clean();
echo $out;
