<?php
/**
 * Phase 153 (2026-09-08) — mock AllStar relay endpoint.
 *
 * GET  ?action=settings        — current relay connection config (admin only; secret masked)
 * POST action=save_settings    — update relay connection config (admin only)
 * POST action=trigger          — {ticket_id, message?} -- relay a spoken summary of an
 *                                 incident (or a custom override message) to the mock
 *                                 AllStar node and verify real audio was delivered.
 *
 * RBAC: action.dispatch_unit -- reused from the existing dispatch domain
 * (this is "call a responder about an incident", not a new capability
 * area) rather than minting a new permission code, matching this
 * project's own established preference (Phase 153's phone_extensions
 * reused action.manage_calls the same way).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/allstar-relay.php';
ini_set('display_errors', '0');
set_time_limit(60); // synthesis + delivery + real-time playback can take 30+ seconds

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

function ar_csrf_check(array $input): void
{
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

function ar_read_json_body(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

if ($method === 'GET' && $action === 'settings') {
    if (!is_admin()) { json_error('Insufficient permissions', 403); }
    $s = allstar_relay_settings();
    $s['ami_secret'] = $s['ami_secret'] !== '' ? '(set)' : '';
    json_response(['settings' => $s]);
    exit;
}

if ($method === 'POST' && $action === 'save_settings') {
    if (!is_admin()) { json_error('Insufficient permissions', 403); }
    $input = ar_read_json_body();
    ar_csrf_check($input);
    try {
        // A masked "(set)" placeholder round-tripped from the GET above
        // must never overwrite the real secret with that literal string.
        if (($input['ami_secret'] ?? '') === '(set)') { unset($input['ami_secret']); }
        $saved = allstar_relay_settings_save($input);
        $saved['ami_secret'] = $saved['ami_secret'] !== '' ? '(set)' : '';
        audit_log('comms', 'allstar_relay_settings.update', 'settings', 0, 'Updated AllStar relay connection settings');
        json_response(['ok' => true, 'settings' => $saved]);
    } catch (Exception $e) {
        error_log('[allstar-relay save_settings] ' . $e->getMessage());
        json_error('Failed to save settings', 500);
    }
    exit;
}

if ($method === 'POST' && $action === 'trigger') {
    if (!rbac_can('action.dispatch_unit')) {
        json_error('Insufficient permissions: dispatch a unit', 403);
    }
    $input = ar_read_json_body();
    ar_csrf_check($input);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) { json_error('ticket_id required'); }
    $message = isset($input['message']) ? (string) $input['message'] : null;

    try {
        $result = allstar_relay_trigger($ticketId, $message);
        audit_log('comms', 'allstar_relay.trigger', 'ticket', $ticketId,
            'AllStar relay triggered: ' . $result['duration_sec'] . 's, RMS ' . $result['rms']
            . ($result['ok'] ? ' (verified audible)' : ' (verification inconclusive)'));
        json_response($result);
    } catch (RuntimeException $e) {
        error_log('[allstar-relay trigger] ' . $e->getMessage());
        json_error($e->getMessage(), 502);
    } catch (Exception $e) {
        error_log('[allstar-relay trigger] ' . $e->getMessage());
        json_error('Relay trigger failed unexpectedly', 500);
    }
    exit;
}

json_error('Unknown action', 404);
