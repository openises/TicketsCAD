<?php
/**
 * Phase 152 (Console rebuild) — acoustic proximity auto-discovery, an
 * addition to the manual "Nearby workstations" entry (plan.md section
 * 3.5's own sub-section).
 *
 * POST action=start {workstation_token}
 *      screen.console + console_beacon_discovery_enabled must be on.
 *      Broadcasts comm:beacon_request (Prerequisite 7's SSE plumbing,
 *      already registered as an 'entitled' comm:% prefix in api/
 *      stream.php) so every OTHER currently-connected console session's
 *      assets/js/console-beacon.js plays its own workstation's tone.
 *      The caller's own workstation never receives special exclusion at
 *      the SSE layer -- a PHP session has no notion of which browser
 *      tab/workstation it's attached to -- console-beacon.js itself
 *      compares initiator_workstation_token against its own token and
 *      simply never plays its own tone in response to its own request.
 *
 * POST action=report {workstation_token, detected_codes:[int,...]}
 *      The initiator reports which beacon codes its own FFT detection
 *      actually heard during the search window. Each code that resolves
 *      to a real, recently-active OTHER workstation
 *      (inc/console-workstations.php::console_workstation_resolve_
 *      beacon_code()) becomes an immediate, automatic mute pairing in
 *      BOTH directions (console_workstation_set_mute_pair()) -- plan.md's
 *      own explicit reasoning: fixing "I hear myself" needs the
 *      NEIGHBOR to mute the initiator, not the other way around, and a
 *      mutual pairing gets this right regardless of which side ran the
 *      search. Returns the resolved {id,label} pairs so the UI can show
 *      "Found: Desk 2, Desk 3" rather than a bare id list. Raw captured
 *      audio is never sent here or anywhere -- only the decoded integer
 *      codes the browser's own FFT already resolved locally.
 *
 * Business logic lives in inc/console-workstations.php so it can be
 * driven directly by tests, matching this project's established pattern.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/console-workstations.php';
require_once __DIR__ . '/../inc/matrix-control-client.php';

if (!rbac_can('screen.console')) {
    json_error('Forbidden', 403);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    json_error('Auth required', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}

$action = (string) ($input['action'] ?? '');

// Install-wide toggle -- doesn't need a resolved workstation at all
// (an admin flipping this may not even have engaged Matrix Audio on this
// browser yet). Gated on action.manage_config, matching plan.md's own
// "install-wide" framing for this ONE setting -- deliberately NOT
// action.manage_positions, the tier every other action in this file
// uses, so this can only ever be toggled by whoever the console-
// positions-admin.php page itself renders the checkbox for (see that
// page's own action.manage_config-gated conditional -- the two must
// never disagree on who can reach this).
if ($action === 'set_discovery_enabled') {
    if (!rbac_can('action.manage_config')) {
        json_error('Forbidden', 403);
    }
    $enabled = !empty($input['enabled']);
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "INSERT INTO `{$prefix}settings` (name, value) VALUES ('console_beacon_discovery_enabled', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [$enabled ? '1' : '0']
    );
    audit_log('config', 'update', 'settings', null,
        'Set console_beacon_discovery_enabled = ' . ($enabled ? '1' : '0'));
    json_response(['ok' => true]);
}

$token = (string) ($input['workstation_token'] ?? '');
if ($token === '') {
    json_error('workstation_token required', 400);
}

try {
    $mine = console_workstation_resolve($token);
    $myId = (int) $mine['id'];

    switch ($action) {
        case 'start':
            $enabled = (string) get_variable('console_beacon_discovery_enabled');
            if ($enabled === '0') {
                json_error('Acoustic discovery is disabled on this install', 403);
            }
            sse_publish('comm:beacon_request', ['initiator_workstation_token' => $token], null, 'entitled');
            audit_log('config', 'console.ws_beacon_search', 'console_workstation', $myId,
                'Started an acoustic-discovery search for nearby workstations');
            json_response(['ok' => true]);
            break;

        case 'report':
            $codes = $input['detected_codes'] ?? [];
            if (!is_array($codes)) { json_error('detected_codes must be an array', 400); }
            $matched = [];
            foreach ($codes as $code) {
                $code = (int) $code;
                $row = console_workstation_resolve_beacon_code($code, $myId);
                if ($row === null) { continue; }
                $matchId = (int) $row['id'];
                console_workstation_set_mute_pair($myId, $matchId, true, $uid);
                $matched[] = ['id' => $matchId, 'label' => $row['label']];

                // Best-effort live push, same as api/console-workstation-
                // mutes.php's own set_mute_pair action -- the DB write
                // above is already the source of truth.
                $matchToken = (string) $row['workstation_token'];
                matrix_control_apply_workstation_mute($token, $matchToken, true);
                matrix_control_apply_workstation_mute($matchToken, $token, true);
            }
            if (!empty($matched)) {
                audit_log('config', 'console.ws_beacon_matched', 'console_workstation', $myId,
                    'Acoustic discovery created ' . count($matched) . ' mute pairing(s)', ['matched' => $matched]);
            }
            json_response(['ok' => true, 'matched' => $matched]);
            break;

        default:
            json_error('Unknown action', 400);
    }
} catch (Exception $e) {
    json_error($e->getMessage(), 400);
}
