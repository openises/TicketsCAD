<?php
/**
 * NewUI v4.0 API — Dispatcher Message Tray (Phase 111 Slice B).
 *
 * GET  ?action=list&channel=X   inbound messages (broker store) + active event
 * GET  ?action=incidents        open incidents for the assign/copy pickers
 * GET  ?action=members          members for the set-sender picker
 * POST action=log_active        attach message → active-event incident
 * POST action=assign            attach message → chosen incident
 * POST action=copy              attach a copy → chosen incident (independent row)
 * POST action=sub_incident      create a child incident from the message + copy note
 * POST action=set_sender        attribute handle → member (+ optionally remember)
 * POST action=reply             reply to a message on its channel (broker_send)
 * POST action=compose           new outbound message on a channel (broker_send)
 *
 * Reuses: broker_send() (outbound), incident_add_note_internal() (+Slice A meta),
 * incident_create_internal() (child), comm_resolve_member_by_address() (Link 1),
 * mi_active_event_ticket_id() (active event). Read gate screen.message_tray;
 * mutation gate action.assign_message (reply/compose need only the screen).
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/broker.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/message-incident.php';
require_once __DIR__ . '/../inc/comm_resolve.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!rbac_can('screen.message_tray')) {
    json_error('Insufficient permissions: message tray', 403);
}

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$canAssign = rbac_can('action.assign_message');

/** Mutation gate + CSRF (for the incident-logging actions). */
function _mt_require_assign(array $input): void {
    global $canAssign;
    if (!$canAssign) json_error('Insufficient permissions: assign messages', 403);
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}
/** CSRF only (reply/compose — any tray user may send). */
function _mt_require_csrf(array $input): void {
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

/** Resolve a member's display name (cached). */
function _mt_member_name(int $memberId): string {
    static $cache = [];
    if ($memberId <= 0) return '';
    if (isset($cache[$memberId])) return $cache[$memberId];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $m = db_fetch_one("SELECT fname, lname FROM `{$prefix}member` WHERE id = ?", [$memberId]);
        $name = $m ? trim(($m['fname'] ?? '') . ' ' . ($m['lname'] ?? '')) : '';
    } catch (Throwable $e) { $name = ''; }
    if ($name === '') $name = 'Member #' . $memberId;
    return $cache[$memberId] = $name;
}

/** Which incident (if any) a message was already logged to (via Slice A meta). */
function _mt_logged_ticket(int $messageId): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT a.ticket_id, t.scope
               FROM `{$prefix}action` a
               LEFT JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
              WHERE a.source_message_id = ? ORDER BY a.id DESC LIMIT 1",
            [$messageId]
        );
        if ($row && (int) $row['ticket_id'] > 0) {
            return ['ticket_id' => (int) $row['ticket_id'],
                    'label' => trim((string) ($row['scope'] ?? '')) ?: ('#' . (int) $row['ticket_id'])];
        }
    } catch (Throwable $e) { /* pre-Slice-A: no source_message_id column */ }
    return null;
}

/** Active-event descriptor for the header. */
function _mt_active_event(): array {
    $tid = function_exists('mi_active_event_ticket_id') ? mi_active_event_ticket_id() : 0;
    if ($tid <= 0) return ['id' => 0, 'label' => ''];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $scope = '';
    try { $scope = (string) db_fetch_value("SELECT scope FROM `{$prefix}ticket` WHERE id = ?", [$tid]); } catch (Throwable $e) {}
    return ['id' => $tid, 'label' => ($scope !== '' ? $scope : ('#' . $tid))];
}

/** Attach a message's body as a note on a ticket, with Slice A attribution. */
function _mt_attach(int $ticketId, array $msg, int $userId): array {
    $channel = (string) ($msg['channel'] ?? '');
    $sender  = (string) ($msg['sender'] ?? '');
    $body    = trim((string) ($msg['body'] ?? ''));
    if ($body === '') return ['errors' => ['empty message body']];
    $memberId = null;
    if ($sender !== '' && function_exists('comm_resolve_member_by_address')) {
        try { $memberId = comm_resolve_member_by_address($channel, $sender); } catch (Throwable $e) {}
    }
    $prefix = '[' . strtoupper($channel !== '' ? $channel : 'msg') . ($sender !== '' ? ': ' . $sender : '') . '] ';
    return incident_add_note_internal($ticketId, $prefix . $body, $userId, [
        'source_channel'    => $channel !== '' ? $channel : null,
        'source_message_id' => (int) ($msg['id'] ?? 0) ?: null,
        'author_member_id'  => $memberId,
    ]);
}

/** Load one inbound message row. */
function _mt_message(int $id): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_one(
            "SELECT id, channel, direction, sender, recipient, body, created_at
               FROM `{$prefix}messages` WHERE id = ? LIMIT 1", [$id]);
    } catch (Throwable $e) { return null; }
}

// ── GET list ───────────────────────────────────────────────────────────────
if ($method === 'GET' && ($action === 'list' || $action === '')) {
    $channel = trim((string) ($_GET['channel'] ?? ''));
    $rows = [];
    try {
        $sql = "SELECT id, channel, direction, sender, recipient, body, created_at
                  FROM `{$prefix}messages` WHERE direction = 'inbound'";
        $params = [];
        if ($channel !== '') { $sql .= " AND channel = ?"; $params[] = $channel; }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 100";
        $rows = db_fetch_all($sql, $params);
    } catch (Throwable $e) { $rows = []; }

    $messages = [];
    foreach ($rows as $r) {
        $mid = (int) $r['id'];
        $senderMember = null;
        if (!empty($r['sender']) && function_exists('comm_resolve_member_by_address')) {
            try { $senderMember = comm_resolve_member_by_address((string) $r['channel'], (string) $r['sender']); }
            catch (Throwable $e) {}
        }
        $logged = _mt_logged_ticket($mid);
        $messages[] = [
            'id'               => $mid,
            'channel'          => (string) $r['channel'],
            'sender'           => (string) $r['sender'],
            'sender_member_id' => $senderMember ?: null,
            'sender_name'      => $senderMember ? _mt_member_name((int) $senderMember) : '',
            'body'             => (string) $r['body'],
            'created_at'       => (string) $r['created_at'],
            'ticket_id'        => $logged ? $logged['ticket_id'] : null,
            'ticket_label'     => $logged ? $logged['label'] : null,
        ];
    }
    json_response(['active_event' => _mt_active_event(), 'messages' => $messages]);
}

// ── GET incidents (open) ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'incidents') {
    $out = [];
    try {
        // status 1=open/active, 2=pending; exclude closed (>=4) + deleted.
        $rows = db_fetch_all(
            "SELECT id, scope, status FROM `{$prefix}ticket`
              WHERE (deleted_at IS NULL OR deleted_at = '')
                AND (status IS NULL OR status < 4)
              ORDER BY id DESC LIMIT 200");
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'],
                      'label' => (trim((string) ($r['scope'] ?? '')) ?: ('Incident')) . ' (#' . (int) $r['id'] . ')'];
        }
    } catch (Throwable $e) {
        // Fallback without deleted_at/status columns.
        try {
            foreach (db_fetch_all("SELECT id, scope FROM `{$prefix}ticket` ORDER BY id DESC LIMIT 200") as $r) {
                $out[] = ['id' => (int) $r['id'], 'label' => (trim((string) ($r['scope'] ?? '')) ?: 'Incident') . ' (#' . (int) $r['id'] . ')'];
            }
        } catch (Throwable $e2) {}
    }
    json_response(['incidents' => $out]);
}

// ── GET members ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'members') {
    $out = [];
    try {
        foreach (db_fetch_all(
            "SELECT id, fname, lname FROM `{$prefix}member` ORDER BY lname, fname LIMIT 1000") as $m) {
            $name = trim(($m['fname'] ?? '') . ' ' . ($m['lname'] ?? ''));
            $out[] = ['id' => (int) $m['id'], 'name' => $name !== '' ? $name : ('Member #' . (int) $m['id'])];
        }
    } catch (Throwable $e) {}
    json_response(['members' => $out]);
}

// ── POST log_active ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'log_active') {
    _mt_require_assign($input);
    $msg = _mt_message((int) ($input['message_id'] ?? 0));
    if (!$msg) json_error('Message not found', 404);
    $tid = function_exists('mi_active_event_ticket_id') ? mi_active_event_ticket_id() : 0;
    if ($tid <= 0) json_error('No active event is set. Set one first (Situation/Events).');
    $res = _mt_attach($tid, $msg, $userId);
    if (!empty($res['errors'])) json_error(implode('; ', $res['errors']));
    audit_log('message_tray', 'log_active', 'ticket', $tid, 'Logged message #' . $msg['id'] . ' to active event');
    json_response(['ok' => true, 'ticket_id' => $tid]);
}

// ── POST assign / copy (both = attach an independent note to a chosen ticket) ─
if ($method === 'POST' && ($action === 'assign' || $action === 'copy')) {
    _mt_require_assign($input);
    $msg = _mt_message((int) ($input['message_id'] ?? 0));
    if (!$msg) json_error('Message not found', 404);
    $tid = (int) ($input['ticket_id'] ?? 0);
    if ($tid <= 0) json_error('Pick an incident');
    $res = _mt_attach($tid, $msg, $userId);
    if (!empty($res['errors'])) json_error(implode('; ', $res['errors']));
    audit_log('message_tray', $action, 'ticket', $tid, ucfirst($action) . ' message #' . $msg['id'] . ' → incident #' . $tid);
    json_response(['ok' => true, 'ticket_id' => $tid]);
}

// ── POST sub_incident ────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'sub_incident') {
    _mt_require_assign($input);
    $msg = _mt_message((int) ($input['message_id'] ?? 0));
    if (!$msg) json_error('Message not found', 404);
    $body = trim((string) $msg['body']);
    if ($body === '') json_error('Message has no body to seed an incident');

    // Configurable default incident type; else the first available.
    $typeId = (int) (function_exists('get_variable') ? (int) get_variable('message_tray_subincident_type_id') : 0);
    if ($typeId <= 0) {
        try { $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id ASC LIMIT 1"); }
        catch (Throwable $e) { $typeId = 0; }
    }
    if ($typeId <= 0) json_error('No incident type available to create a sub-incident. Configure one first.');

    $res = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => mb_substr($body, 0, 250),
        'description' => $body,
    ], $userId);
    if (!empty($res['errors']) || empty($res['id'])) {
        json_error('Could not create sub-incident: ' . (!empty($res['errors']) ? implode('; ', $res['errors']) : 'unknown'));
    }
    $childId = (int) $res['id'];
    _mt_attach($childId, $msg, $userId);  // copy the originating message into it
    audit_log('message_tray', 'sub_incident', 'ticket', $childId,
        'Created sub-incident #' . $childId . ' from message #' . $msg['id']);
    json_response(['ok' => true, 'ticket_id' => $childId]);
}

// ── POST set_sender ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'set_sender') {
    _mt_require_assign($input);
    $msg = _mt_message((int) ($input['message_id'] ?? 0));
    if (!$msg) json_error('Message not found', 404);
    $memberId = (int) ($input['member_id'] ?? 0);
    if ($memberId <= 0) json_error('Pick a member');
    $remember = !empty($input['remember']);
    $channel  = (string) $msg['channel'];
    $handle   = (string) $msg['sender'];

    if ($remember && $handle !== '') {
        // Persist handle → member so future inbound auto-resolves (Link 1).
        // member_comm_identifiers keyed by comm_modes.code = channel; values_json
        // holds the transport-specific field.
        $fieldByChannel = ['zello' => 'username', 'dmr' => 'radio_id', 'meshtastic' => 'node_id', 'aprs' => 'callsign_ssid'];
        $field = $fieldByChannel[$channel] ?? 'username';
        try {
            $modeId = (int) db_fetch_value(
                "SELECT id FROM `{$prefix}comm_modes` WHERE code = ? LIMIT 1", [$channel]);
            if ($modeId > 0) {
                $exists = db_fetch_one(
                    "SELECT id FROM `{$prefix}member_comm_identifiers`
                      WHERE member_id = ? AND comm_mode_id = ?
                        AND JSON_UNQUOTE(JSON_EXTRACT(values_json, ?)) = ? LIMIT 1",
                    [$memberId, $modeId, '$.' . $field, $handle]);
                if (!$exists) {
                    db_query(
                        "INSERT INTO `{$prefix}member_comm_identifiers`
                            (member_id, comm_mode_id, values_json, is_primary)
                         VALUES (?, ?, ?, 0)",
                        [$memberId, $modeId, json_encode([$field => $handle])]);
                }
            }
        } catch (Throwable $e) { /* non-fatal; attribution below still applies */ }
    }
    audit_log('message_tray', 'set_sender', 'member', $memberId,
        'Attributed handle "' . $handle . '" (' . $channel . ') → member #' . $memberId);
    json_response(['ok' => true, 'member_id' => $memberId, 'remembered' => $remember]);
}

// ── POST reply / compose (any tray user; CSRF) ───────────────────────────────
if ($method === 'POST' && ($action === 'reply' || $action === 'compose')) {
    _mt_require_csrf($input);
    $channel = (string) ($input['channel'] ?? '');
    $body    = trim((string) ($input['body'] ?? ''));
    if ($body === '') json_error('Message body is required');
    if (!function_exists('broker_send')) json_error('Messaging broker unavailable', 500);

    $to = 'all';
    if ($action === 'reply') {
        $orig = _mt_message((int) ($input['message_id'] ?? 0));
        if ($orig && !empty($orig['sender'])) $to = (string) $orig['sender'];
        if ($channel === '') $channel = $orig ? (string) $orig['channel'] : '';
    }
    if ($channel === '') json_error('Channel is required');

    $result = broker_send($channel, ['to' => $to, 'body' => $body, 'type' => 'dispatch']);
    $ok = !empty($result['success']);
    audit_log('message_tray', $action, 'channel', null,
        ucfirst($action) . ' on ' . $channel . ($ok ? '' : ' (failed: ' . ($result['error'] ?? '?') . ')'));
    json_response(['ok' => true, 'delivered' => $ok, 'error' => $result['error'] ?? null]);
}

json_error('Unknown action', 400);
