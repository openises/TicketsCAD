<?php
/**
 * Phase 152 (Communications Console v2) — the thin position layer API.
 *
 * GET                    — {ok, positions:[...], presence:[...], my_position}
 *                           screen.console. Every occupied position (from
 *                           console_position_sessions) plus the full
 *                           position catalog plus the caller's own current
 *                           position (or null) in one round trip, since the
 *                           console page needs all three to render its
 *                           position picker + presence list together.
 * POST action=create     — {label, default_channel_ids[], sort_order?}
 *                           action.manage_positions (admin)
 * POST action=update     — {id, label?, default_channel_ids?, sort_order?}
 *                           action.manage_positions (admin)
 * POST action=delete     — {id} action.manage_positions (admin) -- closes
 *                           any open sessions on that position and drops
 *                           its comm_channel_reads rows first (inc/console-
 *                           positions.php::console_position_delete()).
 * POST action=log_in     — {position_id} any screen.console holder (spec.md:
 *                           "any screen.console holder"). Closes the
 *                           caller's own other open position first; never
 *                           evicts a DIFFERENT user already in the target
 *                           position (no forced takeover).
 * POST action=log_out    — {} closes the caller's own open position(s).
 * POST action=heartbeat  — {session_token?} bumps the caller's open
 *                           position_sessions row AND (if a session_token
 *                           is supplied) console_sessions.last_seen_at --
 *                           one call, piggybacking both liveness signals
 *                           per plan.md section 3.
 * POST action=clear_handoff — {position_id} resets that position's
 *                           comm_channel_reads to "seen as of now" for
 *                           every one of its default channels. Requires
 *                           the caller currently occupy the position, OR
 *                           action.manage_positions (admin override) --
 *                           enforced in inc/console-positions.php so a
 *                           test driving the function directly still
 *                           proves the boundary.
 *
 * Business logic lives in inc/console-positions.php so it can be driven
 * directly by tests, matching this project's established pattern for
 * admin-CRUD-UI endpoints (inc/console-views.php, inc/matrix-routes.php).
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/console-positions.php';

if (!rbac_can('screen.console')) {
    json_error('Forbidden', 403);
}

$uid      = (int) ($_SESSION['user_id'] ?? 0);
$username = (string) ($_SESSION['username'] ?? $_SESSION['user'] ?? ('user_' . $uid));
if ($uid <= 0) {
    json_error('Auth required', 401);
}

$canManage = rbac_can('action.manage_positions');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $current = console_position_current_for_user($uid);
        json_response([
            'ok'           => true,
            'positions'    => console_positions_list(),
            'presence'     => console_positions_presence(),
            'my_position'  => $current ? (int) $current['position_id'] : null,
            'heartbeat_interval_seconds' => CONSOLE_POSITION_HEARTBEAT_INTERVAL_SECONDS,
        ]);
    } catch (Exception $e) {
        json_error_safe('Could not load console positions', $e);
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

try {
    switch ($action) {
        case 'create':
            if (!$canManage) { json_error('Forbidden', 403); }
            $id = console_position_create($input, $uid);
            audit_log('config', 'console.position_create', 'console_position', $id,
                "Created console position '" . ($input['label'] ?? '') . "'", $input);
            json_response(['ok' => true, 'id' => $id]);
            break;

        case 'update':
            if (!$canManage) { json_error('Forbidden', 403); }
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { json_error('id required', 400); }
            console_position_update($id, $input);
            audit_log('config', 'console.position_update', 'console_position', $id,
                'Updated console position', $input);
            json_response(['ok' => true]);
            break;

        case 'delete':
            if (!$canManage) { json_error('Forbidden', 403); }
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { json_error('id required', 400); }
            console_position_delete($id);
            audit_log('config', 'console.position_delete', 'console_position', $id,
                'Deleted console position');
            json_response(['ok' => true]);
            break;

        case 'log_in':
            $positionId = (int) ($input['position_id'] ?? 0);
            if ($positionId <= 0) { json_error('position_id required', 400); }
            $consoleSessionId = isset($input['console_session_id']) ? (int) $input['console_session_id'] : null;
            console_position_log_in($positionId, $uid, $username, $consoleSessionId);
            audit_log('config', 'console.position_log_in', 'console_position', $positionId,
                "$username logged into a console position");
            json_response(['ok' => true]);
            break;

        case 'log_out':
            console_position_log_out_all($uid);
            audit_log('config', 'console.position_log_out', 'console_position', null,
                "$username logged out of their console position");
            json_response(['ok' => true]);
            break;

        case 'heartbeat':
            console_position_heartbeat($uid);
            $token = (string) ($input['session_token'] ?? '');
            if ($token !== '') {
                // Best-effort -- a heartbeat must never fail the whole
                // request because the session-token half of it didn't
                // match (e.g. an already-expired console session).
                try {
                    db_query(
                        'UPDATE console_sessions SET last_seen_at = NOW() WHERE session_token = ? AND user_id = ?',
                        [$token, $uid]
                    );
                } catch (Exception $e) { /* best-effort, see above */ }
            }
            json_response(['ok' => true]);
            break;

        case 'clear_handoff':
            $positionId = (int) ($input['position_id'] ?? 0);
            if ($positionId <= 0) { json_error('position_id required', 400); }
            console_channel_reads_clear_handoff($positionId, $uid, $canManage);
            audit_log('config', 'console.position_clear_handoff', 'console_position', $positionId,
                "$username cleared read-state at handoff");
            json_response(['ok' => true]);
            break;

        default:
            json_error('Unknown action', 400);
    }
} catch (Exception $e) {
    json_error($e->getMessage(), 400);
}
