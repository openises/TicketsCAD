<?php
/**
 * Phase 152 (Communications Console v2) — prerequisite #3, mints/revokes
 * the short-lived token a browser tab uses to authenticate its WebSocket
 * connection to the audio-matrix service's browser leg
 * (services/audio-matrix/legs/browser.py).
 *
 * POST action=create {csrf_token, workstation_token?}
 *     -> {ok:true, session_token, expires_at, ws_url}
 *   ws_url is NULL when the install hasn't configured `matrix_ws_url`
 *   (the admin-set public wss:// path proxied to the matrix service's
 *   browser-leg port, per this project's realtime-streaming-proxy /
 *   browser-audio-to-voice-service skills) -- an unconfigured install is
 *   not an error, it just means the browser audio leg isn't deployed here
 *   yet (Phase 152 ships off/undeployed by default like every prior
 *   phase). The caller (console.js, a later task) must treat a null
 *   ws_url as "no live browser audio on this install" and degrade, not
 *   throw.
 *
 * POST action=revoke {csrf_token, session_token}
 *     -> {ok:true} -- best-effort; a tab closing without calling this
 *   (crash, network loss) is the COMMON case and is handled by expires_at
 *   lapsing on its own, not by this call.
 *
 * screen.console only -- same gate as console.php itself and Phase
 * 114b3's console-audio-prefs.php. A session_token is scoped to the
 * calling user (denormalized user_id/username at mint time) but grants
 * NO permission by itself -- it only proves "this WS connection belongs
 * to this already-authenticated console session" to the Python leg,
 * which has no other way to consult PHP's session/RBAC state per frame.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/console-workstations.php';

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

$action = $input['action'] ?? 'create';

// Session-scoped username, matching this project's audit-log convention
// of denormalizing the acting user's name at write time so it survives a
// later account deletion.
$username = (string) ($_SESSION['username'] ?? $_SESSION['user'] ?? ('user_' . $uid));

if ($action === 'create') {
    $workstationToken = isset($input['workstation_token']) && is_string($input['workstation_token'])
        ? substr($input['workstation_token'], 0, 64)
        : null;

    // Phase 152 -- registers/touches the console_workstations row for
    // this token (inc/console-workstations.php). Best-effort: a session
    // must still mint even if the caller sent a malformed or absent
    // token (an install that hasn't deployed assets/js/console-
    // workstation.js yet, or a stray non-UUID value) -- the adjacent-
    // transmit-mute feature simply has nothing to offer that browser tab
    // until a valid token shows up on a later mint.
    if ($workstationToken !== null) {
        try {
            console_workstation_resolve($workstationToken);
        } catch (Exception $e) { /* best-effort, see above */ }
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        json_error_safe('Could not generate a session token', $e);
    }

    // Short-lived by design (see the migration docblock) -- the console
    // page mints a fresh session on load rather than persisting one
    // across days. 12 hours comfortably covers one shift.
    $expiresAt = date('Y-m-d H:i:s', time() + 12 * 3600);

    try {
        db_query(
            'INSERT INTO console_sessions
                (session_token, user_id, username, workstation_token, expires_at)
             VALUES (?, ?, ?, ?, ?)',
            [$token, $uid, $username, $workstationToken, $expiresAt]
        );
    } catch (Exception $e) {
        json_error_safe('Could not create console session', $e);
    }
    $sessionRowId = function_exists('db_insert_id') ? db_insert_id() : null;

    // Audited (added 2026-09-08, an RBAC-review persona finding): this is
    // the ONE mint point every matrix-backed browser-audio interaction
    // passes through -- including, whatever its own actual join mechanism
    // turns out to be, the dispatcher intercom (intercom_dd), the ONE
    // channel this console exists specifically to coordinate on WITHOUT
    // the field hearing. Session creation is a deliberate, lazy, once-
    // per-live-audio-engagement event (console-mic.js's connect() is
    // never called on page load, only when an operator actually turns on
    // Matrix Audio/Join Intercom/Group Transmit) -- not routine per-page
    // noise, so this does not flood the audit log the way auditing every
    // listen/monitor toggle would.
    audit_log(
        'communications', 'console.session_create', 'console_sessions', $sessionRowId,
        'Console browser-audio session opened',
        ['expires_at' => $expiresAt, 'has_workstation_token' => $workstationToken !== null]
    );

    $wsUrl = (string) get_variable('matrix_ws_url');
    json_response([
        'ok'            => true,
        'session_token' => $token,
        'expires_at'    => $expiresAt,
        'ws_url'        => $wsUrl !== '' ? $wsUrl : null,
    ]);
}

if ($action === 'revoke') {
    $token = (string) ($input['session_token'] ?? '');
    if ($token === '') {
        json_error('session_token required', 400);
    }
    try {
        // Scoped to the caller's own user_id -- a session token belongs to
        // whoever minted it, and this endpoint has no path for revoking
        // someone else's session (an admin-facing "kick" tool, if ever
        // wanted, is a separate, explicitly-audited action, not this one).
        db_query(
            'UPDATE console_sessions
                SET revoked_at = NOW()
              WHERE session_token = ? AND user_id = ? AND revoked_at IS NULL',
            [$token, $uid]
        );
    } catch (Exception $e) {
        json_error_safe('Could not revoke console session', $e);
    }
    json_response(['ok' => true]);
}

json_error('Unknown action', 400);
