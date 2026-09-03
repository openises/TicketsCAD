<?php
/**
 * CLI probe for tests/test_gh135_quick_incident_notes.php and
 * tests/test_log_org_seclabel_scope.php — drives api/log.php through the
 * REAL endpoint file (same discipline as tests/_gh96_mileage_report_probe.php).
 *
 * Usage: php tests/_gh135_log_probe.php [days] [user_id] [active_org_id]
 * user_id defaults to test_admin_user_id() (Super Admin) when omitted, so
 * every pre-existing call site (bare `days` argument) is unaffected.
 * active_org_id is REQUIRED for an 'org'-scope_kind RBAC grant to satisfy
 * rbac_can()/org_visible_ids() at all -- see tests/_gh96_mileage_report_probe.php's
 * own docblock for the full reasoning (inc/rbac.php's _rbac_scope_satisfied()
 * reads $_SESSION['active_org_id'], a real login flow sets it, a bare
 * user_roles.org_id grant with no active_org_id set can never satisfy it).
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
$_SESSION['user_id']  = !empty($argv[2]) ? (int) $argv[2] : test_admin_user_id();
$_SESSION['username'] = 'gh135-probe';
if (!empty($argv[3])) {
    $_SESSION['active_org_id'] = (int) $argv[3];
}
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gh135-probe';

$_GET['days'] = (string) ($argv[1] ?? 30);

include $root . '/api/log.php';
