<?php
/**
 * CLI probe for tests/test_gh100_gh101_ics213_fixes.php — drives
 * api/winlink-export.php through the REAL endpoint file (which echoes XML
 * and exit()s, so one call = one subprocess), same discipline as
 * tests/_gh96_mileage_report_probe.php / tests/_reports_links_probe.php /
 * tests/_incident_detail_can_manage_sharing_probe.php.
 *
 * Usage: php tests/_gh100_winlink_export_probe.php "<query-string>" [user_id]
 *
 * The query string is parsed the same way a real GET request's would be
 * (parse_str), so form=ics213&ticket_id=N (or no ticket_id at all, for the
 * blank-form path) round-trips exactly as a browser's would.
 *
 * File name starts with `_` so tools/test_all.php (which globs test_*.php)
 * does not try to run it as a test.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$dir = sys_get_temp_dir() . '/newui_gh100_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'gh100' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = !empty($argv[2]) ? (int) $argv[2] : test_admin_user_id();
$_SESSION['username'] = 'gh100-probe';
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gh100-probe';

$qs = (string) ($argv[1] ?? 'form=ics213');
parse_str($qs, $_GET);

include $root . '/api/winlink-export.php';
