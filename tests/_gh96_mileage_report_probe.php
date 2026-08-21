<?php
/**
 * CLI probe for tests/test_gh96_mileage_report.php — drives api/reports.php
 * through the REAL endpoint file (which finishes via json_response() and
 * exits, so one call = one subprocess), same discipline as
 * tests/_reports_links_probe.php.
 *
 * Usage: php tests/_gh96_mileage_report_probe.php "<query-string>" [user_id] [active_org_id]
 *
 * The query string is parsed the same way a real GET request's would be
 * (parse_str), so any api/reports.php $_GET param can be exercised --
 * report, period, start_date, end_date, responder_id, driver_id, org_id,
 * list_filters. An optional second argv overrides which user_id the
 * session carries (default: test_admin_user_id(), i.e. Super Admin) --
 * used to drive the report as a non-Super-Admin org-scoped caller. An
 * optional third argv sets $_SESSION['active_org_id'] -- REQUIRED for an
 * org-scope_kind RBAC grant to satisfy rbac_can() at all
 * (inc/rbac.php's _rbac_scope_satisfied() reads $context['org_id'], which
 * falls back to $_SESSION['active_org_id'] when no $context is passed --
 * exactly api/reports.php's own rbac_can('action.view_reports') call
 * shape -- so an org-scoped grant with no active_org_id set can NEVER
 * satisfy it, matching what a real login flow would have set).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$dir = sys_get_temp_dir() . '/newui_gh96_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'gh96' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = !empty($argv[2]) ? (int) $argv[2] : test_admin_user_id();
$_SESSION['username'] = 'gh96-probe';
if (!empty($argv[3])) {
    $_SESSION['active_org_id'] = (int) $argv[3];
}
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'gh96-probe';

$qs = (string) ($argv[1] ?? 'report=mileage_report&period=this_year');
parse_str($qs, $_GET);

include $root . '/api/reports.php';
