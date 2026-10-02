<?php
/**
 * Phase 152 (Console rebuild) — dispatcher-facing patch endpoint for a
 * console session's OWN browser-leg channel (`browser:<console_sessions.id>`,
 * see services/audio-matrix/legs/browser.py). This is what Select/Monitor/
 * Volume/PTT on a matrix-backed strip actually call, via assets/js/
 * console-mic.js / console-playback.js.
 *
 * Distinct from api/matrix.php (action.manage_matrix, admin-only, persists
 * to `comm_routes` for standing channel-to-channel patches) — this endpoint
 * is gated on the Dispatcher-tier `action.patch_create` and NEVER touches
 * `comm_routes`. A route touching a browser-leg channel is EPHEMERAL by
 * construction: applied directly to the live matrix service's control
 * plane and torn down automatically the instant the WebSocket closes
 * (MatrixCore.remove_channel()'s own doc — see inc/matrix-routes.php's
 * matrix_browser_leg_validate_channel() for the full reasoning on why no
 * `comm_channels`/`comm_routes` row is needed or wanted here).
 *
 * The caller's OWN browser-leg channel id is NEVER trusted from the
 * client — it's derived server-side from the session_token the client
 * already holds (minted by api/console-session.php), matched against
 * `console_sessions.user_id = $_SESSION['user_id']`. A client cannot claim
 * a different session's channel to create routes on someone else's mic.
 *
 * POST action=connect    {session_token, channel_id, direction, gain_db?}
 *   direction='listen' -> route channel -> me (I hear it); gain_db optional
 *                          (default 0.0 = unity)
 *   direction='talk'   -> route me -> channel (they hear me); gain_db not
 *                          accepted (a TX leg is unity or nothing — volume
 *                          shaping only makes sense on the receive side)
 *   Idempotent: connecting an already-connected pair updates its gain
 *   (listen only) rather than erroring.
 * POST action=set_gain    {session_token, channel_id, gain_db} — listen only
 * POST action=disconnect  {session_token, channel_id, direction}
 *   Idempotent: disconnecting an already-gone route is not an error.
 *
 * screen.console + action.patch_create gate every action (mirroring
 * api/matrix.php's screen.console-adjacent posture) — action.patch_cross_class
 * never comes into play here: the browser leg's own regulatory_class
 * ('internal') is never a member of a blocked pair, so no cross-class
 * override can ever be needed for this endpoint's routes.
 *
 * A 'talk' direction connect ALSO requires action.console_tx (added
 * 2026-09-08, an RBAC-review persona finding): action.patch_create is
 * "may create a patch," which is a real and distinct capability from "may
 * key a live mic" -- console.php, COMMS-CONSOLE-GUIDE.md, and console-
 * simulselect-widget.js all already treat action.console_tx as THE
 * permission governing transmit. Before this fix, revoking console_tx
 * from a role via the Roles & Permissions UI (to make it listen-only)
 * left this endpoint's own 'talk' leg still reachable for anyone who
 * still held patch_create -- the exact "the control's real name and its
 * enforced name diverge" shape this project has hit five times before
 * (see CLAUDE.md's several RBAC-exclusion-leak entries). 'listen' keeps
 * the original single gate; listening was never a transmit capability.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/matrix-routes.php';
require_once __DIR__ . '/../inc/matrix-control-client.php';

if (!rbac_can('screen.console') || !rbac_can('action.patch_create')) {
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

/**
 * Resolve the caller's OWN active session_token into its browser-leg
 * channel key. Never derived from anything the client asserts about
 * itself beyond the opaque token — mirrors services/audio-matrix/legs/
 * browser.py's default_validate_session() lookup shape exactly (same
 * table, same expiry/revoked conditions) so a token this endpoint accepts
 * is the same token the Python leg would accept for the WebSocket itself.
 */
function _console_patch_my_channel($sessionToken, $uid) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $token = (string) $sessionToken;
    if ($token === '') {
        json_error('session_token is required', 400);
    }
    $row = db_fetch_one(
        'SELECT id FROM `' . $prefix . 'console_sessions`
          WHERE session_token = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW()',
        [$token, $uid]
    );
    if (!$row) {
        json_error('session_token invalid, expired, or not yours', 401);
    }
    return 'browser:' . (int) $row['id'];
}

$action = $input['action'] ?? '';
$myChannel = _console_patch_my_channel($input['session_token'] ?? '', $uid);

if ($action === 'connect') {
    $direction = (string) ($input['direction'] ?? '');
    if ($direction !== 'listen' && $direction !== 'talk') {
        json_error("direction must be 'listen' or 'talk'", 400);
    }
    if ($direction === 'talk' && !rbac_can('action.console_tx')) {
        json_error('Forbidden — action.console_tx is required to key a channel', 403);
    }
    try {
        $other = matrix_browser_leg_validate_channel($input['channel_id'] ?? 0);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    }

    $gainDb = 0.0;
    if ($direction === 'listen' && array_key_exists('gain_db', $input)) {
        try {
            $gainDb = matrix_normalize_gain($input['gain_db']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        }
    }
    $src = $direction === 'listen' ? $other['channel_key'] : $myChannel;
    $dst = $direction === 'listen' ? $myChannel : $other['channel_key'];

    $applied = matrix_control_request('POST', '/routes', [
        'src' => $src, 'dst' => $dst, 'gain_db' => $gainDb,
        'priority' => 0, 'ducking' => true, 'enabled' => true,
        'allow_cross_class' => false, 'expires_at' => null,
    ]);
    if (!$applied['ok']) {
        // Idempotent connect: "route already exists" is the expected shape
        // of a client re-asserting state it thinks it already has (e.g. a
        // Select toggled on twice after a missed response) — treat it as
        // success rather than surfacing a scary error for a harmless retry.
        $already = is_array($applied['body']) && !empty($applied['body']['error'])
            && stripos((string) $applied['body']['error'], 'exists') !== false;
        if (!$already) {
            json_error('Matrix service unreachable or refused the connection: ' . $applied['error'], 502);
        }
    }
    // Audited on 'talk' only (added 2026-09-08, an RBAC-review persona
    // finding) -- a 'listen'/Monitor toggle fires on every ordinary strip
    // interaction and would flood the audit log with routine reads; a
    // live mic key -- including into intercom_dd, the ONE channel this
    // console exists specifically to coordinate on WITHOUT the field
    // hearing -- is exactly the "who was on this at 14:32" accountability
    // event this project's standing audit-trail rule exists for.
    if ($direction === 'talk') {
        audit_log(
            'communications', 'console.patch_talk_connect', 'comm_channels', $other['id'],
            'Console mic keyed into ' . ($other['label'] ?? $other['channel_key']),
            ['channel_key' => $other['channel_key'], 'my_channel' => $myChannel]
        );
    }
    json_response(['ok' => true, 'channel_id' => $other['id'], 'direction' => $direction]);
}

if ($action === 'set_gain') {
    try {
        $other = matrix_browser_leg_validate_channel($input['channel_id'] ?? 0);
        $gainDb = matrix_normalize_gain($input['gain_db'] ?? 0.0);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    }
    // set_gain only ever applies to the listen (RX) leg — see the file
    // docblock on why a talk/TX leg has no volume concept.
    $applied = matrix_control_request('POST', '/routes/update', [
        'old_src' => $other['channel_key'], 'old_dst' => $myChannel,
        'src' => $other['channel_key'], 'dst' => $myChannel,
        'gain_db' => $gainDb, 'priority' => 0, 'ducking' => true,
        'enabled' => true, 'allow_cross_class' => false, 'expires_at' => null,
    ]);
    if (!$applied['ok']) {
        json_error('Matrix service unreachable or refused the gain change: ' . $applied['error'], 502);
    }
    json_response(['ok' => true]);
}

if ($action === 'disconnect') {
    $direction = (string) ($input['direction'] ?? '');
    if ($direction !== 'listen' && $direction !== 'talk') {
        json_error("direction must be 'listen' or 'talk'", 400);
    }
    try {
        $other = matrix_browser_leg_validate_channel($input['channel_id'] ?? 0);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    }
    $src = $direction === 'listen' ? $other['channel_key'] : $myChannel;
    $dst = $direction === 'listen' ? $myChannel : $other['channel_key'];
    // Idempotent: a route already gone (e.g. the WS itself dropped and the
    // Python side already pruned it) is not an error — matches
    // matrix_control_apply_delete()'s own "removed:false is still ok"
    // shape from the admin endpoint's control-client caller.
    $applied = matrix_control_request('DELETE', '/routes?' . http_build_query(['src' => $src, 'dst' => $dst]));
    if (!$applied['ok'] && $applied['status'] !== 404) {
        json_error('Matrix service unreachable: ' . $applied['error'], 502);
    }
    json_response(['ok' => true]);
}

json_error('Unknown action', 400);
