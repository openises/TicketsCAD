<?php
/**
 * APRS-IS helpers (Phase 99a #13 + #14, 2026-06-28).
 *
 * Two functions exposed:
 *
 *   aprs_passcode($callsign)
 *     Computes the APRS-IS passcode for a callsign. The algorithm
 *     is the canonical Brad McCusker / aprslib algorithm — public,
 *     non-secret (callsigns are public; the passcode just enforces
 *     "you know how to compute it"). This is what you'd otherwise
 *     paste a callsign into one of the many online calculators
 *     for. Result is a 15-bit positive integer.
 *
 *   aprs_send_message_via_is($from, $passcode, $to, $body)
 *     Opens a short-lived TCP connection to an APRS-IS tier-2
 *     server, logs in, sends a formatted APRS message packet
 *     ('::TOCALL___:body{seq'), and closes. Returns
 *     ['success' => bool, 'error' => ?string].
 *
 * Why short-lived per message instead of a persistent socket:
 *   - APRS-IS doesn't love rapid connect/disconnect but a few
 *     dispatcher-initiated messages per hour is well within
 *     reasonable load. Persistent-socket sending would require
 *     a long-running PHP / Python daemon that the dispatcher
 *     interacts with via IPC — significantly more moving parts.
 *     The listener.py service that handles RECEIVE is a separate
 *     long-running daemon (different concern).
 *   - For high-volume sends (alerts to a whole roster), the right
 *     answer is to extend listener.py with a send-from-queue
 *     mode (similar to mesh_outbox). Out of scope for v1.
 */

declare(strict_types=1);

/**
 * Compute the APRS-IS passcode for a callsign.
 *
 * Algorithm (public — same one every APRS-IS client uses):
 *   1. Uppercase the callsign; strip any '-N' SSID suffix.
 *   2. Start with stride = 0x73E2.
 *   3. For each character in the resulting base call, XOR into
 *      stride: high byte for even-indexed chars, low byte for
 *      odd-indexed.
 *   4. Return stride AND 0x7FFF (15-bit positive int).
 *
 * Returns -1 when the input doesn't look like a callsign
 * (which is the receive-only sentinel APRS-IS recognizes).
 */
function aprs_passcode(string $callsign): int {
    $cs = strtoupper(trim($callsign));
    // Strip SSID (anything from the first '-' onward).
    $dashAt = strpos($cs, '-');
    if ($dashAt !== false) $cs = substr($cs, 0, $dashAt);
    // Basic shape check: alphanumeric, 3-10 chars typical.
    if (!preg_match('/^[A-Z0-9]{3,10}$/', $cs)) return -1;

    $stride = 0x73E2;
    $len = strlen($cs);
    for ($i = 0; $i < $len; $i++) {
        $byte = ord($cs[$i]);
        if ($i % 2 === 0) {
            $stride ^= ($byte << 8);
        } else {
            $stride ^= $byte;
        }
    }
    return $stride & 0x7FFF;
}

/**
 * Send a text message via APRS-IS using an ephemeral TCP
 * connection.
 *
 * @param string $fromCallsign Full callsign incl. SSID, e.g. 'N0NKI-10'
 * @param int    $passcode     APRS-IS passcode for the base call
 * @param string $toCallsign   Destination callsign (the addressee)
 * @param string $body         Message body — APRS spec says max 67
 *                             ASCII chars; this function silently
 *                             truncates.
 * @param array  $opts         Optional: server, port, message_no
 * @return array {success, error?, raw_packet?}
 */
function aprs_send_message_via_is(
    string $fromCallsign,
    int $passcode,
    string $toCallsign,
    string $body,
    array $opts = []
): array {
    $server = $opts['server']     ?? 'rotate.aprs2.net';
    $port   = (int) ($opts['port'] ?? 14580);
    $msgNo  = $opts['message_no'] ?? null;

    $from = strtoupper(trim($fromCallsign));
    $to   = strtoupper(trim($toCallsign));
    if ($from === '' || $passcode < 0) {
        return ['success' => false, 'error' => 'Callsign + valid passcode required to transmit'];
    }
    if ($to === '') {
        return ['success' => false, 'error' => 'Destination callsign required'];
    }
    // APRS message addressee field is exactly 9 chars, space-padded.
    $toField = str_pad(substr($to, 0, 9), 9);
    // APRS message body: ASCII printable, max 67 chars per spec.
    $body = preg_replace('/[\x00-\x1F\x7F]/', ' ', $body);
    if (strlen($body) > 67) $body = substr($body, 0, 67);
    // Optional message number for ack tracking ('{NN' suffix, 1-5 chars).
    $suffix = ($msgNo !== null && $msgNo !== '') ? ('{' . substr((string) $msgNo, 0, 5)) : '';

    // Build the packet. Path: APRS,TCPIP* is the standard for IS-originated traffic.
    $packet = $from . '>APRS,TCPIP*::' . $toField . ':' . $body . $suffix;

    // Open TCP socket. Short timeouts so a stuck server doesn't hang the request.
    $errno  = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        'tcp://' . $server . ':' . $port,
        $errno,
        $errstr,
        5  // 5-second connect timeout
    );
    if (!$fp) {
        return ['success' => false, 'error' => "TCP connect failed: {$errstr} ({$errno})"];
    }
    stream_set_timeout($fp, 5);

    try {
        // APRS-IS server sends a banner first ('# javAPRSSrvr ...').
        // Read + discard up to 256 bytes so the buffer is clean.
        $banner = fread($fp, 256);

        // Send login line. Format:
        //   user CALLSIGN-SSID pass PASSCODE vers SOFTWARE VERSION filter X
        $login = "user {$from} pass {$passcode} vers TicketsCAD-NewUI 4.0\r\n";
        fwrite($fp, $login);

        // Server replies with '# logresp CALLSIGN verified, server X'.
        $loginResp = fread($fp, 256);
        if (strpos($loginResp, 'verified') === false &&
            strpos($loginResp, 'unverified') === false &&
            strpos($loginResp, 'logresp') === false) {
            fclose($fp);
            return [
                'success' => false,
                'error'   => 'APRS-IS login failed; server said: ' . trim((string) $loginResp),
            ];
        }
        // Receive-only login (unverified passcode) cannot transmit.
        if (strpos($loginResp, 'unverified') !== false) {
            fclose($fp);
            return [
                'success' => false,
                'error'   => 'APRS-IS passcode rejected as unverified — receive-only login cannot transmit. Recompute the passcode for ' . $from . '.',
            ];
        }

        // Send the packet.
        fwrite($fp, $packet . "\r\n");

        // APRS-IS doesn't ack our send back to us inline. The packet is
        // either accepted (silently) or dropped. Real delivery confirmation
        // would require receiving the destination's ack via the listener
        // service — out of scope for this v1 send path.
        usleep(200000);  // 200ms grace so the server processes before close
        fclose($fp);

        return [
            'success'    => true,
            'raw_packet' => $packet,
        ];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['success' => false, 'error' => 'Send exception: ' . $e->getMessage()];
    }
}
