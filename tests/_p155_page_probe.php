<?php
/**
 * CLI probe: render a REAL top-level page (phone.php, ...) as a given user and
 * print ONE line of JSON {status, headers, body}. Phase 155 counterpart of
 * tests/_p155_api_probe.php for HTML pages (which `header()` + `exit` rather
 * than json_response()). Starts with `_`: not a test.
 *
 * Usage: php tests/_p155_page_probe.php <page.php> <user_id> [session-json] [query-string]
 *   user_id 0 = not logged in.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$page = (string) ($argv[1] ?? '');
if (!preg_match('#^[A-Za-z0-9_\-]+\.php$#', $page) || !is_file($root . '/' . $page)) {
    echo json_encode(['status' => 0, 'headers' => [], 'body' => 'probe: bad page']);
    exit(0);
}
require_once $root . '/config.php';

$dir = sys_get_temp_dir() . '/newui_p155_probe_sess';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
session_save_path($dir);
$sid = 'p155pg' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$uid = (int) ($argv[2] ?? 0);
if ($uid > 0) {
    $_SESSION['user_id']    = $uid;
    $_SESSION['username']   = 'p155-page-probe';
    $_SESSION['user']       = 'p155-page-probe';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$extra = isset($argv[3]) && $argv[3] !== '' ? json_decode((string) $argv[3], true) : null;
if (is_array($extra)) { foreach ($extra as $k => $v) { $_SESSION[$k] = $v; } }
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p155-page-probe';
if (isset($argv[4]) && $argv[4] !== '') { parse_str((string) $argv[4], $_GET); }
$_SERVER['REQUEST_URI']     = '/' . $page . (isset($argv[4]) && $argv[4] !== '' ? '?' . $argv[4] : '');
$_SERVER['SCRIPT_NAME']     = '/' . $page;

$captured = '';
ob_start(function ($chunk) use (&$captured) { $captured .= $chunk; return ''; });
register_shutdown_function(function () use (&$captured) {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    $code = http_response_code();
    echo json_encode(['status' => $code === false ? 200 : (int) $code, 'headers' => headers_list(), 'body' => $captured]);
});
try {
    include $root . '/' . $page;
} catch (Throwable $e) {
    $captured .= '<!-- probe exception: ' . $e->getMessage() . ' -->';
}
