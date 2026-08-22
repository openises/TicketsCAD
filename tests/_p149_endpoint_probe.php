<?php
/**
 * CLI probe for tests/test_inbound_calls_rbac.php — drives
 * api/constituents.php and api/call-history.php through the REAL
 * endpoint file (which finishes via json_response()/json_error() and
 * exits, so one call = one subprocess), same discipline as
 * tests/_gh96_mileage_report_probe.php / tests/_reports_links_probe.php.
 *
 * Usage: php tests/_p149_endpoint_probe.php <relative-api-path> "<query-string>" <user_id>
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_p149_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'p149' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = (int) ($argv[3] ?? 0);
$_SESSION['username'] = 'p149-probe';
$_SESSION['user']     = 'p149-probe';
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p149-probe';

$apiPath = (string) ($argv[1] ?? '');
$qs      = (string) ($argv[2] ?? '');
parse_str($qs, $_GET);

include $root . '/' . ltrim($apiPath, '/');
