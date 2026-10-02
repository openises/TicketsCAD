<?php
/**
 * Phase 152 (Console rebuild) — workstation identity + adjacent-transmit
 * mute API.
 *
 * GET  ?workstation_token=<token>
 *      -> {ok, my_workstation_id, my_label, nearby:[{id,label,last_seen_at}],
 *          muted_ids:[...]} -- resolves/creates the CALLER's own workstation
 *      row (best-effort registration, same as api/console-session.php's own
 *      mint call), then returns the recency-based "nearby" list (see inc/
 *      console-workstations.php's docblock for why this is recency-based,
 *      not real-time channel overlap) plus which of those the caller has
 *      currently muted. screen.console only.
 *
 * POST action=set_label
 *      {workstation_token, workstation_id?, label}
 *      Renaming the CALLER's own workstation (workstation_id omitted, or
 *      equal to the token's own resolved id) needs only screen.console.
 *      Renaming a DIFFERENT workstation needs action.manage_positions.
 *
 * POST action=set_mute_pair
 *      {workstation_token, source_workstation_id?, target_workstation_id, muted}
 *      Sets/clears a pairing in BOTH directions (inc/console-workstations
 *      .php::console_workstation_set_mute_pair()) -- plan.md's own
 *      explicit design: fixing "I hear myself" needs the NEIGHBOR muting
 *      the caller, which the caller cannot set up themselves under a
 *      strictly-directional-and-self-only model, so the UI always creates
 *      both directions in one action. source_workstation_id omitted (or
 *      equal to the caller's own resolved id) needs only screen.console --
 *      it always involves the caller's own current workstation. An
 *      EXPLICIT source_workstation_id naming a DIFFERENT workstation (an
 *      admin configuring a pairing between two desks neither of which
 *      they're sitting at) needs action.manage_positions.
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
require_once __DIR__ . '/../inc/console-workstations.php';
require_once __DIR__ . '/../inc/matrix-control-client.php';

if (!rbac_can('screen.console')) {
    json_error('Forbidden', 403);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    json_error('Auth required', 401);
}

$canManage = rbac_can('action.manage_positions');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = (string) ($_GET['workstation_token'] ?? '');
    if ($token === '') {
        json_error('workstation_token required', 400);
    }
    try {
        $mine = console_workstation_resolve($token);
        $myId = (int) $mine['id'];
        json_response([
            'ok'                       => true,
            'my_workstation_id'        => $myId,
            'my_label'                 => $mine['label'],
            'my_beacon_code'           => $mine['beacon_code'] !== null ? (int) $mine['beacon_code'] : null,
            'nearby'                   => console_workstations_nearby($myId),
            'muted_ids'                => console_workstation_muted_ids($myId),
            // Acoustic proximity auto-discovery (plan.md section 3.5's
            // own sub-section) -- a plain settings-table flag (get_
            // variable()), default on, seeded by sql/run_phase152_beacon_
            // discovery.php. When off, the search control simply doesn't
            // render (assets/js/console-workstation-panel.js) rather than
            // being present-and-broken; manual entry is unaffected either way.
            'discovery_enabled'        => (string) get_variable('console_beacon_discovery_enabled') !== '0',
        ]);
    } catch (Exception $e) {
        json_error($e->getMessage(), 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}

$action = (string) ($input['action'] ?? '');
$token = (string) ($input['workstation_token'] ?? '');
if ($token === '') {
    json_error('workstation_token required', 400);
}

try {
    $mine = console_workstation_resolve($token);
    $myId = (int) $mine['id'];

    switch ($action) {
        case 'set_label':
            $targetId = isset($input['workstation_id']) ? (int) $input['workstation_id'] : $myId;
            if ($targetId !== $myId && !$canManage) {
                json_error('Forbidden', 403);
            }
            console_workstation_set_label($targetId, (string) ($input['label'] ?? ''));
            audit_log('config', 'console.workstation_set_label', 'console_workstation', $targetId,
                'Renamed a console workstation', $input);
            json_response(['ok' => true]);
            break;

        case 'set_mute_pair':
            $sourceId = isset($input['source_workstation_id']) ? (int) $input['source_workstation_id'] : $myId;
            $targetId = (int) ($input['target_workstation_id'] ?? 0);
            $muted = !empty($input['muted']);
            if ($targetId <= 0) { json_error('target_workstation_id required', 400); }
            if ($sourceId !== $myId && !$canManage) {
                json_error('Forbidden', 403);
            }
            console_workstation_set_mute_pair($sourceId, $targetId, $muted, $uid);
            audit_log('config', $muted ? 'console.workstation_mute' : 'console.workstation_unmute',
                'console_workstation', $sourceId,
                'Set a workstation mute pairing (both directions)', $input);
            // Best-effort live push (matrix-control-client.php's own
            // docblock explains why this one is best-effort, unlike the
            // route-apply functions it sits next to): the DB write above
            // is already the source of truth, and service.py's boot-time
            // load_workstation_mutes() re-syncs from it regardless, so a
            // failed push here (no matrix service deployed, unreachable)
            // is never a silent permanent gap.
            $sourceToken = console_workstation_token_for_id($sourceId);
            $targetToken = console_workstation_token_for_id($targetId);
            if ($sourceToken !== null && $targetToken !== null) {
                matrix_control_apply_workstation_mute($sourceToken, $targetToken, $muted);
                matrix_control_apply_workstation_mute($targetToken, $sourceToken, $muted);
            }
            json_response(['ok' => true]);
            break;

        default:
            json_error('Unknown action', 400);
    }
} catch (Exception $e) {
    json_error($e->getMessage(), 400);
}
