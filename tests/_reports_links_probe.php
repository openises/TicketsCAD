<?php
/**
 * CLI probe for tests/test_reports_drilldown_links.php — drives
 * api/reports.php through the REAL endpoint file (which finishes via
 * json_response() and exits, so one call = one subprocess), same
 * discipline as tests/_soft_delete_sweep_probe.php and
 * tests/_reports_stats_probe.php.
 *
 * Usage:  php tests/_reports_links_probe.php <report> <period> [incident_id] [in_types_id]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$dir = sys_get_temp_dir() . '/newui_rlp_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'rlp' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = test_admin_user_id();
$_SESSION['username'] = 'rlp-probe';
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'rlp-probe';

$_GET = [
    'report' => (string) ($argv[1] ?? 'incident_report'),
    'period' => (string) ($argv[2] ?? 'this_year'),
];
if (!empty($argv[3])) {
    $_GET['incident_id'] = (string) $argv[3];
}
if (!empty($argv[4])) {
    $_GET['in_types_id'] = (string) $argv[4];
}

include $root . '/api/reports.php';
