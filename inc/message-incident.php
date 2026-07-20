<?php
/**
 * Phase 111 Slice A — Message → active-event incident auto-logging.
 *
 * The single entry point the router calls when a matched route is marked
 * attach_action='add_note'. Keeps inc/router.php lean: all the "which event
 * is active, who sent this, format the note, write it" logic lives here.
 *
 * SAFETY CONTRACT (this is a live-radio install):
 *   - mi_attach_message_to_active_event() is a hard NO-OP when no active
 *     event is configured (active_event_ticket_id unset or 0). The router's
 *     existing forwarding behaviour is byte-for-byte unchanged in that case.
 *   - Nothing in here EVER throws into the caller. Every DB / resolve /
 *     format step is wrapped; a failure is logged (error_log) and swallowed
 *     so a bad message can never break the router's forward loop.
 *   - Note text is ASCII-sanitised before writing. The legacy `action`
 *     table is latin1_swedish_ci and rejects multibyte unicode; the
 *     net-control code learned this the hard way.
 *
 * Dependencies (loaded lazily so a fresh install without them degrades):
 *   inc/incident-write.php  → incident_add_note_internal()
 *   inc/comm_resolve.php    → comm_resolve_member_by_address() (Link 1)
 */

if (!function_exists('db_query')) {
    require_once __DIR__ . '/db.php';
}

/**
 * Read the active-event incident id from the `settings` table.
 *
 * Returns a positive ticket id, or 0 when the feature is off (setting unset,
 * empty, or non-positive). Static-cached per process — the router may call
 * mi_attach_message_to_active_event() many times in one request during a
 * message burst, and the active event doesn't change mid-request.
 *
 * @return int
 */
function mi_active_event_ticket_id(): int {
    static $cached = null;

    // A caller (test harness / the active-event API right after a write)
    // can force a re-read via mi_reset_active_event_cache().
    if (!empty($GLOBALS['__mi_active_event_force_reload'])) {
        $cached = null;
        unset($GLOBALS['__mi_active_event_force_reload']);
    }

    if ($cached !== null) return $cached;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $cached = 0;
    try {
        $val = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['active_event_ticket_id']
        );
        if ($val !== false && $val !== null) {
            $id = (int) $val;
            if ($id > 0) $cached = $id;
        }
    } catch (Exception $e) {
        // settings table missing / query failed — feature stays off.
        $cached = 0;
    }
    return $cached;
}

/**
 * Reset the mi_active_event_ticket_id() static cache.
 *
 * Only needed by tests (which flip the setting mid-process) and by the
 * active-event API right after it writes a new value. Production request
 * paths read the setting once and never change it within the same request.
 */
function mi_reset_active_event_cache(): void {
    // Re-derive on next call by poking the static through a fresh read.
    // PHP has no direct "unset static", so we mirror the value in a global
    // the reader consults first when present.
    $GLOBALS['__mi_active_event_force_reload'] = true;
}

/**
 * ASCII-safe a note for the latin1 `action.description` column.
 *
 * Strips characters outside printable ASCII (plus tab/newline), collapsing
 * anything multibyte to a '?' placeholder so the write can't fail on a
 * unicode emoji / smart-quote a field radio app might inject. Trims to a
 * sane length.
 */
function _mi_ascii_note(string $text): string {
    // Replace any non-ASCII byte sequence with '?'. iconv is the cleanest
    // available transliteration; fall back to a regex strip if it's absent
    // or errors on malformed input.
    $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($out === false || $out === null) {
        $out = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
    } else {
        // iconv//TRANSLIT can still emit stray bytes on some libc builds;
        // final-pass strip to guarantee pure printable ASCII.
        $out = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $out);
    }
    $out = trim((string) $out);
    if (strlen($out) > 1000) {
        $out = substr($out, 0, 1000);
    }
    return $out;
}

/**
 * Human label for a source channel, used in the note prefix.
 * e.g. 'zello' → 'Zello', 'dmr' → 'DMR', 'meshtastic' → 'Meshtastic'.
 */
function _mi_channel_label(string $channel): string {
    $c = strtolower(trim($channel));
    static $labels = [
        'zello'      => 'Zello',
        'dmr'        => 'DMR',
        'meshtastic' => 'Meshtastic',
        'meshcore'   => 'MeshCore',
        'aprs'       => 'APRS',
        'local_chat' => 'Chat',
        'sms'        => 'SMS',
        'email'      => 'Email',
    ];
    if (isset($labels[$c])) return $labels[$c];
    // Fallback: title-case a clean token.
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $channel);
    return $clean !== '' ? ucfirst($clean) : 'Message';
}

/**
 * Pull the sender handle out of a broker-shaped message array. Different
 * channels populate different keys; try them in likely order.
 */
function _mi_message_sender(array $message): string {
    foreach (['from', 'sender', 'sender_username', 'from_handle', 'callsign', 'node_id', 'radio_id'] as $k) {
        if (!empty($message[$k]) && is_scalar($message[$k])) {
            return trim((string) $message[$k]);
        }
    }
    return '';
}

/**
 * Build the note text for an inbound message, e.g.:
 *   "[Zello: alice_sar] crowd heavy at bandshell"
 * When the sender is empty:
 *   "[Zello] crowd heavy at bandshell"
 *
 * @param array  $message        Broker message array (body + sender fields)
 * @param string $sourceChannel  Channel code the message arrived on
 * @return string  ASCII-safe note text (already sanitised)
 */
function _mi_build_note(array $message, string $sourceChannel): string {
    $label  = _mi_channel_label($sourceChannel);
    $sender = _mi_message_sender($message);
    $body   = (string) ($message['body'] ?? '');

    $prefix = $sender !== '' ? "[{$label}: {$sender}] " : "[{$label}] ";
    return _mi_ascii_note($prefix . $body);
}

/**
 * THE single entry point the router calls for a matched attach_action route.
 *
 * If no active event is configured this returns IMMEDIATELY (the router's
 * behaviour is unchanged). Otherwise it resolves the sender to a member
 * (Link 1), formats an ASCII note, and appends it to the active event's
 * ICS-214 activity log via incident_add_note_internal(), tagged with the
 * source channel + resolved author.
 *
 * Attribution to the acting user: there is no logged-in user in the router
 * path (it runs on an inbound message, not an HTTP session). We stamp
 * action.user = 0 (system) — the human attribution lives in
 * author_member_id (the resolved sender), which is what the per-person 214
 * pulls by.
 *
 * NEVER throws. A resolve/format/write failure is logged and swallowed.
 *
 * @param array  $message        Broker message array (body + sender fields,
 *                               and optionally _source_message_id set by the
 *                               router forward, or source_message_id).
 * @param string $sourceChannel  Channel code the message arrived on.
 * @return void
 */
function mi_attach_message_to_active_event(array $message, string $sourceChannel): void {
    try {
        $ticketId = mi_active_event_ticket_id();
        if ($ticketId <= 0) {
            return; // Feature off — hard no-op. Router unaffected.
        }

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            return; // Nothing to log (e.g. a voice PTT with no transcript).
        }

        // Resolve the sender to a member (Link 1). Null when unknown — the
        // note still logs, just without author attribution (a dispatcher
        // can attribute it later in Slice B's tray).
        $authorMemberId = null;
        $sender = _mi_message_sender($message);
        if ($sender !== '' && function_exists('comm_resolve_member_by_address')) {
            try {
                $authorMemberId = comm_resolve_member_by_address($sourceChannel, $sender);
            } catch (Exception $e) {
                $authorMemberId = null; // resolution is best-effort
            }
        }

        // Source message id: prefer the router-forward metadata, then a
        // plain key, else null.
        $srcMsgId = null;
        foreach (['_source_message_id', 'source_message_id', 'message_id', 'id'] as $k) {
            if (isset($message[$k]) && $message[$k] !== null && (int) $message[$k] > 0) {
                $srcMsgId = (int) $message[$k];
                break;
            }
        }

        $note = _mi_build_note($message, $sourceChannel);
        if ($note === '') {
            return;
        }

        if (!function_exists('incident_add_note_internal')) {
            require_once __DIR__ . '/incident-write.php';
        }

        $meta = [
            'source_channel'    => strtolower(trim($sourceChannel)),
            'source_message_id' => $srcMsgId,
            'author_member_id'  => $authorMemberId,
        ];

        // System user (0) — see docblock. The note writer is defensive
        // about the meta columns' existence, so this is safe pre-migration.
        $result = incident_add_note_internal($ticketId, $note, 0, $meta);

        if (!empty($result['errors'])) {
            error_log('[message-incident] add_note returned errors for ticket '
                . $ticketId . ': ' . implode('; ', $result['errors']));
        }
    } catch (Throwable $e) {
        // ABSOLUTE guarantee: never propagate into the router.
        error_log('[message-incident] attach failed (swallowed): ' . $e->getMessage());
    }
}
