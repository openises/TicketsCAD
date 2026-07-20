<?php
/**
 * Channel: APRS-IS text messaging (Phase 99a #14, 2026-06-28).
 *
 * Sends APRS messages via an ephemeral TCP connection to APRS-IS.
 * Config reads:
 *   aprs_send_callsign  — full callsign+SSID we transmit AS
 *                         (e.g. 'N0NKI-10' for a TicketsCAD
 *                         dispatch console)
 *   aprs_send_passcode  — computed from the base call; the
 *                         Settings UI auto-fills this when admin
 *                         types/changes the callsign
 *   aprs_is_server      — default 'rotate.aprs2.net'
 *   aprs_is_port        — default 14580
 *
 * Recipient (`message['to']`): destination callsign-SSID. The
 * APRS spec allows 9 chars (5 base + '-' + 1-2 SSID); longer
 * strings get silently truncated.
 *
 * Body limit: 67 ASCII printable chars per APRS spec. The
 * Compose form's char counter is set to 67 for this channel.
 *
 * 'success' here means "the packet hit the APRS-IS server."
 * Whether the destination station actually receives + acks is
 * out of band — APRS messages can ride RF or IS-only paths, and
 * the ack (if the destination sends one) comes back through the
 * receive path (listener.py) rather than the send connection.
 */

require_once __DIR__ . '/../aprs.php';

broker_register('aprs', [
    'name'    => 'APRS-IS',
    'send'    => '_aprs_send',
    'receive' => null,  // ingest is listener.py + tools/aprs-poller.php
    'status'  => '_aprs_status',
]);

function _aprs_send(array $message): array {
    $to   = trim((string) ($message['to'] ?? ''));
    $body = trim((string) ($message['body'] ?? ''));
    if ($body === '') {
        return ['success' => false, 'error' => 'Message body is required'];
    }
    if ($to === '') {
        return ['success' => false, 'error' => 'Destination callsign is required'];
    }

    $from     = trim((string) (get_variable('aprs_send_callsign') ?? ''));
    $passcode = (int) (get_variable('aprs_send_passcode') ?? -1);
    if ($from === '' || $passcode <= 0) {
        return [
            'success' => false,
            'error'   => 'APRS-IS send is not configured. Set aprs_send_callsign + aprs_send_passcode in Settings → Communications → APRS.',
        ];
    }

    $server = get_variable('aprs_is_server') ?: 'rotate.aprs2.net';
    $port   = (int) (get_variable('aprs_is_port') ?: 14580);

    // Use the message_id from the outer broker as a short ack-tracker
    // suffix. Truncate to 5 chars per APRS spec.
    $msgNo = substr((string) ($message['_msg_id'] ?? (string) time()), -5);

    return aprs_send_message_via_is($from, $passcode, $to, $body, [
        'server'     => $server,
        'port'       => $port,
        'message_no' => $msgNo,
    ]);
}

function _aprs_status(): string {
    $from     = trim((string) (get_variable('aprs_send_callsign') ?? ''));
    $passcode = (int) (get_variable('aprs_send_passcode') ?? -1);
    if ($from === '' || $passcode <= 0) return 'unconfigured';
    // We don't keep a persistent socket; status reflects whether
    // configuration is complete, not live connectivity. Future:
    // ping aprs2.net periodically + cache.
    return 'active';
}
