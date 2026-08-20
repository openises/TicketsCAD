<?php
/**
 * Endpoint probe for tests/test_org_sharing_manual_api.php — GET only.
 *
 * Runs api/incident-share.php's GET branch in its own process and prints
 * exactly what it wrote, driving the REAL file end to end (real
 * session_start(), real rbac_can(), real org_ticket_is_owned_by_caller(),
 * real json_response()/json_error()). Same session-attach trick as
 * tests/_gh25_endpoint_probe.php: a session file is written directly and
 * its id handed over in $_COOKIE — no login flow, nobody's credentials
 * touched.
 *
 * POST is NOT probed this way (see test_org_sharing_manual_api.php's own
 * docblock for why: this PHP build's CLI SAPI does not deliver piped
 * STDIN through php://input, which api/incident-share.php's POST body
 * parsing depends on) — POST is covered by driving rbac_can() and the
 * real org_sharing_create_manual_share()/org_sharing_revoke_share()
 * writers directly, in-process, with a hand-mirrored copy of the
 * endpoint's own status-code mapping (same technique
 * tests/test_org_sharing_write_endpoints.php already established for
 * Phase 141's write endpoints).
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage: php tests/_incident_share_get_probe.php <user_id> <ticket_id> [active_org_id]
 *
 * active_org_id mirrors what a real login sets in $_SESSION -- an
 * org-scoped RBAC grant (scope_kind='org') only satisfies rbac_can() when
 * $_SESSION['active_org_id'] matches the grant's scope_id (inc/rbac.php's
 * _rbac_scope_satisfied(), 'org' case) or an explicit $context['org_id']
 * is passed to rbac_can() -- api/incident-share.php passes neither, so
 * the session's own active_org_id is what has to carry it, exactly as it
 * would for a real dispatcher's browser session.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$userId      = (int) ($argv[1] ?? 0);
$ticketId    = (string) ($argv[2] ?? '0');
$activeOrgId = isset($argv[3]) ? (int) $argv[3] : 0;

$dir = sys_get_temp_dir() . '/newui_p142_sess';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
session_save_path($dir);
$sid = 'p142' . bin2hex(random_bytes(8));
session_id($sid);
session_start();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = 'p142-probe';
if ($activeOrgId > 0) $_SESSION['active_org_id'] = $activeOrgId;
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p142-probe';
$_GET = ['ticket_id' => $ticketId];

// json_response()/json_error() call http_response_code($status) right
// before echo+exit. CLI SAPI never transmits a real status line, but the
// value IS still recorded internally — grab it on shutdown (runs even
// after exit()) and print it on its OWN trailing line, after the JSON
// body, with a marker prefix the caller splits on. (STDERR would also
// work but shell_exec-based callers commonly merge streams with 2>&1,
// which would corrupt the JSON parse — a same-stream marker line is
// simpler to consume correctly either way.)
register_shutdown_function(function () {
    echo "\n__HTTP_STATUS__:" . http_response_code() . "\n";
});

include $root . '/api/incident-share.php';
