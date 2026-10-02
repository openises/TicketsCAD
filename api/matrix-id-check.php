<?php
/**
 * NewUI v4.0 API — audio-matrix station-ID check/record endpoint
 *
 * Phase 152 prerequisite #2. Called by services/audio-matrix/fcc_gate.py
 * before the matrix relays audio onto an AUTONOMOUSLY-keyed leg of a
 * coupling/patch (no direct human PTT press on that specific leg — see
 * inc/comm_fcc_gate.php's docblock for the exact scope). A strip's own
 * direct human PTT never calls this endpoint; that stays on the existing
 * client-side gate (inc/fcc_station_id.php, unchanged).
 *
 * Bearer-token auth against the SAME shared secret
 * services/audio-matrix/service.py's config calls "control_token" — one
 * secret for the whole PHP<->matrix-service relationship, in both
 * directions (this endpoint validates an INCOMING call from the matrix;
 * Prerequisite 4's live-route-apply work is the OUTGOING direction, PHP
 * calling the matrix's control_http.py with the same token). Stored in
 * the settings table (get_variable(), NOT the separate `config` table —
 * see CLAUDE.md's "TWO settings stores" pitfall) as matrix_control_token.
 *
 * POST /api/matrix-id-check.php
 * Headers: Authorization: Bearer <matrix_control_token>
 * Body (JSON):
 *   {
 *     "action": "check" | "record",
 *     "channel_id": <comm_channels.id>,
 *     "regulatory_class": "amateur|commercial|pstn|internal",
 *     "configured_interval_secs": <int|null>,
 *     "enforce": "soft|hard",
 *     "callsign": "<channel's configured callsign>",
 *     // "record" only:
 *     "source": "autonomous_relay" (the only source this endpoint may write)
 *   }
 *
 * "check" returns {ok:true, allowed:bool, reason:string, zone:string} —
 * see inc/comm_fcc_gate.php::comm_fcc_may_transmit() for the reason enum.
 * "record" appends a comm_id_log row (user_id=0 — no individual operator
 * for an autonomous leg) and returns {ok:true}.
 */

require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rate-limit.php';
require_once __DIR__ . '/../inc/id-policy.php';
require_once __DIR__ . '/../inc/comm_fcc_gate.php';
ini_set('display_errors', '0');

function matrix_id_check_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    matrix_id_check_error('POST required', 405);
}

// Rate limit before any token comparison, matching api/sip-ingest.php's
// established ordering.
$srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_ok('matrix-id-check:' . $srcIp, 1200, 60)) {
    rate_limit_reject(60);
}

// ── Bearer-token auth — same portability handling as api/sip-ingest.php
// (some Apache configs never populate HTTP_AUTHORIZATION even when the
// header genuinely arrived). ──────────────────────────────────────────
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
    matrix_id_check_error('Bearer token required', 401);
}
$token = substr($auth, 7);

$configured = (string) get_variable('matrix_control_token');
if ($configured === '') {
    // Matches services/audio-matrix/service.py's own posture: an unset
    // token is a loud warning, not a silent open door on THIS side —
    // refuse rather than accept an empty-matches-empty comparison.
    error_log('[matrix-id-check] matrix_control_token is not configured — refusing all calls');
    matrix_id_check_error('Service not configured', 503);
}
if (!hash_equals($configured, $token)) {
    error_log('[matrix-id-check] bearer mismatch from ' . $srcIp);
    matrix_id_check_error('Invalid token', 401);
}

$raw = file_get_contents('php://input');
$input = $raw ? (json_decode($raw, true) ?: []) : [];

$action = (string) ($input['action'] ?? '');
$channelId = (int) ($input['channel_id'] ?? 0);
$regulatoryClass = (string) ($input['regulatory_class'] ?? 'internal');
$configuredInterval = isset($input['configured_interval_secs']) ? (int) $input['configured_interval_secs'] : null;
$enforce = (string) ($input['enforce'] ?? 'soft');
$callsign = (string) ($input['callsign'] ?? '');

if ($channelId <= 0) {
    matrix_id_check_error('channel_id required');
}

if ($action === 'check') {
    $result = comm_fcc_may_transmit($channelId, $regulatoryClass, $configuredInterval, $enforce, $callsign);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $result);
    exit;
}

if ($action === 'record') {
    $source = (string) ($input['source'] ?? 'autonomous_relay');
    if ($source !== 'autonomous_relay') {
        // This endpoint is the autonomous-relay audit sink only — a
        // confirmed_tx/monitoring_id/end_of_conversation event implies a
        // human operator, which belongs on the DMR widget's own path
        // (inc/fcc_station_id.php), not here.
        matrix_id_check_error('This endpoint only records autonomous_relay events');
    }
    $ok = comm_fcc_record_id_event($channelId, 0, $callsign, $source, 'recorded via matrix-id-check');
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

matrix_id_check_error('Unknown action');
