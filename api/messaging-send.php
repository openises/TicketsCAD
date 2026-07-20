<?php
/**
 * Phase 99a (2026-06-28) — unified text-comms send endpoint.
 *
 * Routes a compose-form submission to the right destination based on
 * the `channel` field:
 *
 *   POST /api/messaging-send.php
 *     Body: {
 *       channel:    'inbox' | 'smtp' | 'sms' | (future: meshtastic | meshcore | zello | aprs | dmr)
 *       to:         channel-specific recipient string
 *                   - inbox: comma-separated user_ids OR 'all'
 *                   - smtp:  comma-separated email addresses
 *                   - sms:   comma-separated phone numbers
 *       subject:    optional (used by inbox + smtp)
 *       body:       required
 *       priority:   normal | high | urgent
 *       send_as:    'system' (default) | 'me' (only honored where applicable)
 *       incident_id: optional, only stamped on inbox messages
 *     }
 *
 * Internal-channel (inbox) sends are delegated to the existing
 * api/messaging.php?action=send path so we don't fork the
 * internal_messages logic. External-channel sends go through
 * broker_send() which routes to the appropriate inc/channels/*.php
 * handler.
 *
 * Audit: every send writes a row to broker_messages with
 * direction='outbound' so the Sent tab can show external sends
 * alongside internal messages (Phase 99b).
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/broker.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

if (function_exists('csrf_check')) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($body['_csrf'] ?? $body['csrf_token'] ?? '');
    if (!csrf_check($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
}

$channel  = strtolower(trim((string) ($body['channel'] ?? 'inbox')));
$to       = trim((string) ($body['to'] ?? ''));
$subject  = trim((string) ($body['subject'] ?? ''));
$msgBody  = trim((string) ($body['body'] ?? ''));
$priority = strtolower(trim((string) ($body['priority'] ?? 'normal')));
$sendAs   = strtolower(trim((string) ($body['send_as'] ?? 'system')));
$incident = isset($body['incident_id']) && $body['incident_id'] !== ''
    ? (int) $body['incident_id'] : null;
// Phase 99a-v2 (2026-06-28) — bridge picker + speak-on-channel.
$targetBridgeId = isset($body['target_bridge_id']) && $body['target_bridge_id'] !== ''
    ? (int) $body['target_bridge_id'] : 0;
$speakOnChannel = !empty($body['speak_on_channel']);

if (!in_array($priority, ['normal', 'high', 'urgent'], true)) $priority = 'normal';
if (!in_array($sendAs,   ['system', 'me'], true))             $sendAs   = 'system';

if ($msgBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message body is required']);
    exit;
}

// RBAC — same gate as internal-messaging send.
if (!rbac_can('action.send_chat') && !rbac_can('action.send_message')) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to send messages']);
    exit;
}

$userId   = (int) ($_SESSION['user_id'] ?? 0);
$userName = (string) ($_SESSION['user'] ?? 'unknown');

// Inbox sends still go through api/messaging.php?action=send (legacy
// path is unchanged). This endpoint is external-channel only — the JS
// branches to the right endpoint based on the channel picker.
if ($channel === 'inbox') {
    http_response_code(400);
    echo json_encode([
        'error' => "Inbox sends use api/messaging.php?action=send. This endpoint is for external channels only.",
    ]);
    exit;
}

// ── External channel via broker ───────────────────────────────────
// Validate channel handler exists.
global $_broker_channels;
if (!isset($_broker_channels[$channel])) {
    http_response_code(400);
    echo json_encode([
        'error' => "Channel '{$channel}' is not configured. Check Settings → Communications.",
    ]);
    exit;
}

// Parse comma-separated recipients.
$recipients = array_values(array_filter(array_map('trim', explode(',', $to))));
if (empty($recipients)) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one recipient is required']);
    exit;
}

// Per-channel recipient validation. Hard-stops on obvious garbage;
// soft-validates with a warning so admins can override edge cases.
$validate = _ms_validate_recipients($channel, $recipients);
if (!empty($validate['errors'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid recipient(s): ' . implode('; ', $validate['errors']),
    ]);
    exit;
}

// Channel-specific body augmentation for priority on text-only channels.
$bodyOut = $msgBody;
if (in_array($channel, ['sms'], true) && $priority === 'urgent') {
    $bodyOut = '[URGENT] ' . $bodyOut;
}

// Build the broker message payload + fan-out by recipient.
$results = [];
$successCount = 0;
$failCount = 0;
foreach ($recipients as $r) {
    $payload = [
        'to'              => $r,
        'subject'         => $subject,
        'body'            => $bodyOut,
        'priority'        => $priority,
        'user_id'         => $userId,
        'user_name'       => $userName,
        'send_as'         => $sendAs,
        '_origin'         => 'compose',
        // Phase 99a-v2 — forward to channel handler. Mesh uses
        // target_bridge_id to pin to a specific bridge; speak_on_channel
        // tells voice-capable handlers (Zello/DMR future) to TTS the body.
        'target_bridge_id' => $targetBridgeId,
        'speak_on_channel' => $speakOnChannel,
    ];
    try {
        $result = broker_send($channel, $payload);
        if (!empty($result['success'])) {
            $successCount++;
            $results[] = [
                'recipient' => $r,
                'ok'        => true,
                // Phase 99a-v2 follow-up — surface routing detail so the UI
                // can show 'Sent via bridge X' (mesh) or the re-route warning.
                // Channels that don't populate these just send null/empty.
                'note'                => $result['note'] ?? null,
                'target_bridge_id'    => $result['target_bridge_id'] ?? null,
                'target_bridge_label' => $result['target_bridge_label'] ?? null,
                'reroute_warning'     => !empty($result['reroute_warning']),
            ];
        } else {
            $failCount++;
            $results[] = [
                'recipient' => $r,
                'ok'        => false,
                'error'     => $result['error'] ?? 'unknown',
            ];
        }
    } catch (Throwable $e) {
        $failCount++;
        $results[] = [
            'recipient' => $r,
            'ok'        => false,
            'error'     => 'exception: ' . $e->getMessage(),
        ];
    }
}

// Audit log entry — captures the send regardless of per-recipient outcome.
if (function_exists('audit_log')) {
    try {
        audit_log(
            'messaging|send|' . $channel,
            'Sent to ' . count($recipients) . ' recipient(s) via ' . $channel
            . ($successCount === count($recipients) ? '' : " ({$successCount} ok, {$failCount} failed)"),
            ['channel' => $channel, 'recipients' => $recipients, 'results' => $results]
        );
    } catch (Throwable $e) { /* non-fatal */ }
}

http_response_code($failCount === count($recipients) ? 500 : 200);
echo json_encode([
    'channel'        => $channel,
    'recipient_count' => count($recipients),
    'success_count'  => $successCount,
    'failed_count'   => $failCount,
    'results'        => $results,
]);
exit;

// ── Helpers ────────────────────────────────────────────────────────

function _ms_validate_recipients(string $channel, array $recipients): array {
    $errors = [];
    foreach ($recipients as $r) {
        if ($channel === 'smtp') {
            if (!filter_var($r, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "'{$r}' is not a valid email address";
            }
        } elseif ($channel === 'sms') {
            // Loose phone validation — strip common formatting and check
            // 10-15 digits (e164-ish). Real Twilio validation happens
            // at the carrier; we just block obvious garbage.
            $digits = preg_replace('/\D/', '', $r);
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                $errors[] = "'{$r}' is not a valid phone number";
            }
        }
        // Other channels: address shape varies (callsign, mesh node,
        // DMR id). Let the channel handler decide what's valid.
    }
    return ['errors' => $errors];
}
