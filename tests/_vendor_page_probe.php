<?php
/**
 * CLI probe for tests/test_vendor_settings_observable.php: renders incident-detail.php (the real page, real session_start(),
 * real rbac, real asset_v()) as a given user and prints the HTML it produced, so a test can assert what the page
 * contains and, just as important for a feature that is OFF by default, what it does NOT contain.
 *
 * Usage: php tests/_vendor_page_probe.php <user_id> <ticket_id> [active_org_id]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$userId = (int) ($argv[1] ?? 0);
$ticketId = (string) ($argv[2] ?? '0');
$activeOrg = (int) ($argv[3] ?? 0);

$dir = sys_get_temp_dir() . '/newui_vendor_page_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'vpage' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = 'vendor-page-probe';
$_SESSION['user']     = 'vendor-page-probe';
if ($activeOrg > 0) $_SESSION['active_org_id'] = $activeOrg;
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'vendor-page-probe';
$_SERVER['HTTP_HOST']       = 'localhost';
$_GET = ['id' => $ticketId];

include $root . '/incident-detail.php';
