<?php
/**
 * NewUI v4.0 API — FCC 97.119 station-ID status + actions (Phase 148)
 *
 * GET  /api/dmr-station-id.php?action=status[&channel=<dmr_channels.id>]
 *   RBAC: action.dmr_receive OR action.dmr_transmit (same gate as
 *   api/dmr-token.php -- you can check ID status without transmit rights,
 *   same as you can listen without transmitting).
 *   Returns inc/fcc_station_id.php's fcc_status_payload() for the logged-in
 *   operator + resolved channel. Never errors when DMR isn't configured --
 *   returns a graceful "off" status so the widget doesn't break.
 *
 * POST /api/dmr-station-id.php?action=confirm_tx[&channel=N]
 *   Operator self-report: "the transmission I just released included my
 *   callsign." No speech-to-text in this phase (Phase 85e-5/Whisper is
 *   deferred, see inc/fcc_station_id.php's docblock) -- this is the honest
 *   substitute. The widget only surfaces this prompt when the ID timer was
 *   in the yellow/red zone at PTT-press time (assets/js/radio-widget.js).
 *   RBAC: action.dmr_transmit. CSRF required.
 *
 * POST /api/dmr-station-id.php?action=monitoring_id[&channel=N]
 *   Fires a standalone "<CALLSIGN> monitoring." TX via the bridge and, on
 *   confirmed success, records the ID event. Optional operator convenience
 *   -- never auto-fired by the system. RBAC: action.dmr_transmit. CSRF
 *   required.
 *
 * POST /api/dmr-station-id.php?action=end_conversation[&channel=N]
 *   Closes the informational conversation marker; fires a standalone
 *   "<CALLSIGN> clear." closing ID ONLY if the operator's most recent
 *   transmission did not already carry one. RBAC: action.dmr_transmit.
 *   CSRF required.
 *
 * There is deliberately NO client-controllable dry-run/simulate flag on
 * this endpoint: monitoring_id/end_conversation both write a real
 * dmr_id_log row -- an operator's own compliance record -- ONLY after
 * fcc_fire_station_tts() reports the bridge actually transmitted. A
 * request-controlled "pretend it worked" flag would let any authenticated
 * action.dmr_transmit holder fabricate a clean ID history without ever
 * keying the radio, defeating the entire audit trail this feature exists
 * to build. tests/test_fcc_station_id_integration.php exercises
 * fcc_monitoring_id()/fcc_end_conversation() directly with an injected
 * $tx callable (same convention as inc/weather_radio.php's own
 * weather_radio_tx() test injection) -- at the PHP function level, never
 * through this HTTP layer -- so no test-only bypass needs to exist here.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/dmr_token.php';
require_once __DIR__ . '/../inc/fcc_station_id.php';
ini_set('display_errors', '0');

$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? 'status'));
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    json_error('No authenticated user in session', 401);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

/** Current operator's callsign, read live from `user` -- never cached in session. */
function _fcc_operator_callsign(int $userId): string
{
    global $prefix;
    try {
        $v = db_fetch_value("SELECT `callsign` FROM `{$prefix}user` WHERE `id` = ?", [$userId]);
        return trim((string) $v);
    } catch (Throwable $e) {
        return '';
    }
}

$channelId = isset($_GET['channel']) ? (int) $_GET['channel'] : (isset($_POST['channel']) ? (int) $_POST['channel'] : 0);
$channel = dmr_resolve_channel($channelId > 0 ? $channelId : null);

// ── GET status ───────────────────────────────────────────────────────────
if ($action === 'status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('GET required', 405);

    $rbacOk = function_exists('rbac_can') && (
        rbac_can('action.dmr_receive') || rbac_can('action.dmr_transmit')
    );
    if (!is_admin() && !$rbacOk) {
        json_error('Missing required permission: action.dmr_receive or action.dmr_transmit', 403);
    }

    $callsign = _fcc_operator_callsign($userId);
    json_response(fcc_status_payload($channel, $userId, $callsign));
}

// ── POST actions ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$rbacOk = function_exists('rbac_can') && rbac_can('action.dmr_transmit');
if (!is_admin() && !$rbacOk) {
    json_error('Missing required permission: action.dmr_transmit', 403);
}

$raw = file_get_contents('php://input');
$input = [];
if ($raw !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}
$token = (string) ($input['csrf_token'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
if (!csrf_verify($token)) {
    json_error('Invalid CSRF token', 403);
}

if (!$channel) {
    json_error('No DMR channel available', 404);
}

$callsign = _fcc_operator_callsign($userId);
if (trim($callsign) === '') {
    json_error('No callsign on file -- set one in your profile before transmitting on amateur frequencies.', 422);
}

switch ($action) {
    case 'confirm_tx':
        $ok = fcc_record_id_event($channelId ?: (int) $channel['id'], $userId, $callsign, 'confirmed_tx');
        json_response([
            'ok' => $ok,
            'detail' => $ok ? 'Recorded -- thank you.' : 'Could not record confirmation.',
            'status' => fcc_status_payload($channel, $userId, $callsign),
        ]);
        break;

    case 'monitoring_id':
        $result = fcc_monitoring_id($channel, $userId, $callsign);
        json_response(array_merge($result, [
            'status' => fcc_status_payload(dmr_resolve_channel($channelId > 0 ? $channelId : (int) $channel['id']), $userId, $callsign),
        ]));
        break;

    case 'end_conversation':
        $result = fcc_end_conversation($channel, $userId, $callsign);
        json_response(array_merge($result, [
            'status' => fcc_status_payload(dmr_resolve_channel($channelId > 0 ? $channelId : (int) $channel['id']), $userId, $callsign),
        ]));
        break;

    default:
        json_error('Unknown action. Valid: status, confirm_tx, monitoring_id, end_conversation', 400);
}
