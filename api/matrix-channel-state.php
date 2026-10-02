<?php
/**
 * NewUI v4.0 API — audio-matrix channel/TX state notification endpoint
 *
 * Phase 152 prerequisite #7 (fail-loud connection state + TX confirmation).
 * Called by services/audio-matrix/legs/browser.py the INSTANT a browser
 * session's WebSocket actually opens/closes (channel_state) or the matrix
 * core actually begins/stops mixing that session's mic frames into a live
 * route (tx_state) — never on a client-side click alone, which is the
 * whole point: a PTT press that never reached the server (dropped WS, an
 * FCC/regulatory gate refusal) must never make a strip's TX lamp light,
 * because this endpoint (and the SSE event it fires) is the ONLY thing
 * that lights it.
 *
 * Bearer-token auth against the SAME shared secret every other PHP<->
 * matrix-service endpoint in this phase uses (matrix_control_token) — see
 * api/matrix-id-check.php's docblock for the full one-secret-both-
 * directions reasoning.
 *
 * POST /api/matrix-channel-state.php
 * Headers: Authorization: Bearer <matrix_control_token>
 * Body (JSON):
 *   {
 *     "event": "channel_state" | "tx_state",
 *     "channel_id": "<matrix channel id, e.g. browser:501>",
 *     "channel_key": "<same value, kept for API-response-shape symmetry
 *                      with the other matrix-*.php endpoints>",
 *     "label": "<display label, e.g. a username>",
 *     // channel_state only:
 *     "state": "connected" | "disconnected",
 *     // tx_state only:
 *     "tx": "started" | "ended"
 *   }
 *
 * Fires comm:channel_state / comm:tx_state as an 'entitled' SSE event
 * (screen.console — api/stream.php's $entPermMap), a pure broadcast to
 * every console viewer, not org-scoped (the matrix service is install-
 * wide). Returns {ok:true} whether or not any client happened to be
 * listening — SSE has no delivery receipt, and this endpoint's job is
 * "did the event get queued," not "did a human see it."
 */

require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rate-limit.php';
require_once __DIR__ . '/../inc/sse.php';
ini_set('display_errors', '0');

function matrix_channel_state_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    matrix_channel_state_error('POST required', 405);
}

// Same ordering as api/matrix-id-check.php / api/sip-ingest.php: rate
// limit before any token comparison.
$srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_ok('matrix-channel-state:' . $srcIp, 1200, 60)) {
    rate_limit_reject(60);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) {
            $auth = $hVal;
            break;
        }
    }
}
if (stripos($auth, 'Bearer ') !== 0) {
    matrix_channel_state_error('Bearer token required', 401);
}
$token = substr($auth, 7);

$configured = (string) get_variable('matrix_control_token');
if ($configured === '') {
    error_log('[matrix-channel-state] matrix_control_token is not configured — refusing all calls');
    matrix_channel_state_error('Service not configured', 503);
}
if (!hash_equals($configured, $token)) {
    error_log('[matrix-channel-state] bearer mismatch from ' . $srcIp);
    matrix_channel_state_error('Invalid token', 401);
}

$raw = file_get_contents('php://input');
$input = $raw ? (json_decode($raw, true) ?: []) : [];

$event = (string) ($input['event'] ?? '');
$channelId = (string) ($input['channel_id'] ?? '');
$label = (string) ($input['label'] ?? '');

if ($channelId === '') {
    matrix_channel_state_error('channel_id required');
}

if ($event === 'channel_state') {
    $state = (string) ($input['state'] ?? '');
    if ($state !== 'connected' && $state !== 'disconnected') {
        matrix_channel_state_error("state must be 'connected' or 'disconnected'");
    }
    $ok = sse_publish('comm:channel_state', [
        'channel_id' => $channelId,
        'label'      => $label,
        'state'      => $state,
    ], null, 'entitled', null);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'published' => $ok]);
    exit;
}

if ($event === 'tx_state') {
    $tx = (string) ($input['tx'] ?? '');
    if ($tx !== 'started' && $tx !== 'ended') {
        matrix_channel_state_error("tx must be 'started' or 'ended'");
    }
    $ok = sse_publish('comm:tx_state', [
        'channel_id' => $channelId,
        'label'      => $label,
        'tx'         => $tx,
    ], null, 'entitled', null);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'published' => $ok]);
    exit;
}

matrix_channel_state_error('Unknown event');
