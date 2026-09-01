<?php
/**
 * CLI probe for tests/test_gh126_facility_leg_active_status.php — drives
 * api/responder-detail.php through the REAL endpoint file (which finishes
 * via json_response() and exits, so one call = one subprocess), same
 * discipline as tests/_gh96_mileage_report_probe.php.
 *
 * Usage: php tests/_gh126_responder_detail_probe.php <responder_id> [user_id]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$dir = sys_get_temp_dir() . '/newui_gh126_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'gh126' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = !empty($argv[2]) ? (int) $argv[2] : test_admin_user_id();
$_SESSION['username'] = 'gh126-probe';
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gh126-probe';

$_GET = ['id' => (string) ($argv[1] ?? '0')];

include $root . '/api/responder-detail.php';
