<?php
/**
 * Endpoint probe for tests/test_org_sharing_manual_ui_wiring.php.
 *
 * Runs api/incident-detail.php's GET in its own process, driving the real
 * file end to end (real session_start(), real rbac_can(), real
 * org_ticket_is_owned_by_caller(), real json_response()). Same
 * session-attach trick as tests/_gh25_endpoint_probe.php's own docblock:
 * a session file is written directly and its id handed over in
 * $_COOKIE — no login flow, nobody's credentials touched.
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage: php tests/_incident_detail_can_manage_sharing_probe.php <user_id> <ticket_id> [active_org_id]
 *
 * active_org_id mirrors what a real login sets in $_SESSION -- an
 * org-scoped RBAC grant (scope_kind='org') only satisfies rbac_can() when
 * $_SESSION['active_org_id'] matches, same reasoning as
 * tests/_incident_share_get_probe.php's own docblock. api/incident-detail.php
 * indirectly depends on this too: inc/access.php's user_can_access_entity()
 * (the F-004 IDOR check, run before org_can_see_ticket()) calls rbac_can()
 * for screen.incident_detail/incident.view, which are ALSO org-scoped
 * grants for a Dispatcher/Org-Admin role assignment.
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
$_SESSION['username'] = 'p142-ui-probe';
if ($activeOrgId > 0) $_SESSION['active_org_id'] = $activeOrgId;
session_write_close();

$_COOKIE[session_name()] = $sid;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p142-ui-probe';
$_GET = ['id' => $ticketId];

include $root . '/api/incident-detail.php';
