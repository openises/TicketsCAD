<?php
/**
 * CLI probe for tests/test_gh135_quick_incident_notes.php — drives
 * api/log.php through the REAL endpoint file (same discipline as
 * tests/_gh96_mileage_report_probe.php).
 *
 * Usage: php tests/_gh135_log_probe.php [days]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$dir = sys_get_temp_dir() . '/newui_gh135_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'gh135' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = test_admin_user_id();
$_SESSION['username'] = 'gh135-probe';
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gh135-probe';

$_GET['days'] = (string) ($argv[1] ?? 30);

include $root . '/api/log.php';
