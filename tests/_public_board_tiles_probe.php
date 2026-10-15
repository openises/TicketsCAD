<?php
/**
 * CLI probe for tests/test_public_board_tiles.php. One call = one fresh PHP
 * process, so get_variable()'s per-process settings cache always starts empty
 * (the same reason tests/_gh142_probe.php exists).
 *
 * Usage:
 *   php tests/_public_board_tiles_probe.php admin <GET|POST> <action> <user_id> '<json body or query string>'
 *       Drives the REAL api/public-board-admin.php (via include, no web
 *       server), with a session for <user_id> and that session's real
 *       csrf_token injected into a POST body (unless the body names one).
 *       Prints the endpoint's raw JSON body.
 *   php tests/_public_board_tiles_probe.php config
 *       Prints json_encode(pb_board_tile_config()) — the value the anonymous
 *       board API publishes.
 *
 * Leading underscore: tools/test_all.php only runs test_*.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$mode = (string) ($argv[1] ?? '');

if ($mode === 'config') {
    require_once $root . '/inc/public-board.php';
    echo json_encode(pb_board_tile_config(), JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($mode !== 'admin') {
    echo json_encode(['probe_error' => 'unknown mode']);
    exit(2);
}

$method  = strtoupper((string) ($argv[2] ?? 'GET'));
$action  = (string) ($argv[3] ?? '');
$userId  = (int) ($argv[4] ?? 0);
$payload = (string) ($argv[5] ?? '');

$dir = sys_get_temp_dir() . '/newui_pb_tiles_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'pbtiles' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$csrf = bin2hex(random_bytes(16));
$_SESSION['user_id']    = $userId;
$_SESSION['username']   = 'pb-tiles-probe';
$_SESSION['user']       = 'pb-tiles-probe';
$_SESSION['csrf_token'] = $csrf;
session_write_close();

$_COOKIE[session_name()]    = $sid;
$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'pb-tiles-probe';
$_GET['action'] = $action;

if ($method === 'GET') {
    if ($payload !== '') {
        parse_str($payload, $extra);
        $_GET = array_merge($_GET, $extra);
    }
} else {
    $body = $payload === '' ? [] : (json_decode($payload, true) ?: []);
    $body['action'] = $action;
    if (!array_key_exists('csrf_token', $body)) {
        $body['csrf_token'] = $csrf;
    }

    class PbTilesFakeInputStream {
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
    stream_wrapper_register('php', 'PbTilesFakeInputStream');
    PbTilesFakeInputStream::setData(json_encode($body));
}

ob_start();
try {
    include $root . '/api/public-board-admin.php';
} catch (Throwable $e) {
    echo json_encode(['probe_exception' => $e->getMessage()]);
}
echo ob_get_clean();
