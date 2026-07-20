<?php
/**
 * NewUI v4.0 API — Zello inbox + reply (Phase E, messaging-send-gaps-2026-06).
 *
 * The Zello-transport sibling of api/mesh.php's Phase-C inbox/reply. It
 * surfaces inbound Zello TEXT reply-ably and queues a reply via the unified
 * send path (a `zello_outbox` row the proxy drains), so an operator can:
 *
 *   - reply to the CHANNEL the inbound text came from, or
 *   - DM the SENDER back (when the inbound was itself a DM, i.e. it carried
 *     a `for` recipient — recorded in zello_messages.recipient).
 *
 *   GET  ?action=inbox          — reply-able inbound Zello TEXT, newest first,
 *                                 each tagged transport=zello + origin
 *                                 (channel | sender user) + reply kind.
 *   POST ?action=reply          — queue a zello_outbox reply to an inbound row.
 *                                 Body: { message_id, mode?:channel|user, text,
 *                                         csrf_token }
 *   GET  ?action=reply_status    — status of one/several zello_outbox rows.
 *                                 ?ids=1,2,3
 *   GET  ?action=originate_targets — units/people that resolve to a Zello
 *                                 username (feeds the Send-tab picker). No
 *                                 address is returned, only whether it resolves.
 *   POST ?action=originate       — Phase F: dispatcher-INITIATED Zello send
 *                                 (not a reply). DM a unit/person (resolved →
 *                                 Zello username) or a typed username, or
 *                                 broadcast to a channel. Body:
 *                                 { csrf_token, text,
 *                                   unit_id|member_id|recipient?, channel?,
 *                                   tts? }
 *                                 Gap 1 (zello-config-video-brief.md): when
 *                                 `tts` is truthy the row is queued with
 *                                 kind='tts' — the proxy synthesises the text
 *                                 to speech and keys it onto the CHANNEL as
 *                                 Opus audio. Zello voice has no per-user
 *                                 address, so a TTS send is always a channel
 *                                 broadcast (any DM target is ignored).
 *
 * Auth: any authenticated dispatcher may read the inbox + reply (the Zello
 * radio is a console-wide tool, same trust level as api/zello-messages.php
 * and api/zello-user.php). State-changing POSTs verify a CSRF token. The
 * heavier ORIGINATE actions additionally require action.manage_mesh_bridges
 * (mirroring the mesh Send tab's gate).
 *
 * This NEVER sends over the network itself — a reply only writes a queued
 * zello_outbox row; the long-running proxy daemon relays it on its loop
 * timer (ZelloProxyApp::pollZelloOutbox) and marks the row sent/failed.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';   // exits if not authenticated

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// JSON body for POST.
$input = [];
if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : [];
    if ($action === '' && !empty($input['action'])) $action = $input['action'];
}

/**
 * Does zello_messages have the Phase-E `recipient` column? (Added by
 * sql/run_zello_dm.php.) Cached per request. If absent, every inbound row is
 * treated as channel-only (no DM partner) — graceful degradation.
 */
function _zinbox_has_recipient(string $prefix): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'recipient'",
            [$prefix . 'zello_messages']
        );
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/** Is the Zello outbox table present? (Created by sql/run_route_subaddress.php.) */
function _zinbox_has_outbox(string $prefix): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'zello_outbox']
        );
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Originate (Phase F, messaging-send-gaps-2026-06) — auth for the
 * dispatcher-initiated Zello send actions (originate / originate_targets).
 *
 * Reading the inbox + replying to an inbound row is open to any
 * authenticated dispatcher (a console-wide tool). But ORIGINATING a Zello
 * send to a named unit/person — or broadcasting to an arbitrary channel —
 * is a heavier capability, so it mirrors the mesh Send tab's gate
 * (api/mesh.php admin_auth → action.manage_mesh_bridges). Same trust level:
 * if you can configure the bridges you can originate over them.
 */
function _zinbox_admin_auth(): void
{
    require_once __DIR__ . '/../inc/rbac.php';
    if (function_exists('rbac_can') && !rbac_can('action.manage_mesh_bridges')) {
        json_error('Forbidden — need action.manage_mesh_bridges', 403);
    }
}

// ── inbox: reply-able inbound Zello TEXT ──────────────────────────────
if ($action === 'inbox' && $method === 'GET') {
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 60)));
    $since = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

    $hasRecipient = _zinbox_has_recipient($prefix);
    $recipCol     = $hasRecipient ? '`recipient`' : "'' AS `recipient`";

    $where  = "`direction` = 'incoming' AND `message_type` = 'text'
               AND `content` IS NOT NULL AND `content` <> ''";
    $params = [];
    if ($since > 0) { $where .= " AND `id` > ?"; $params[] = $since; }
    $params[] = $limit;

    try {
        $rows = db_fetch_all(
            "SELECT `id`, `channel`, {$recipCol}, `sender_username`, `sender_display`,
                    `content`, `created`
               FROM `{$prefix}zello_messages`
              WHERE {$where}
              ORDER BY `id` DESC
              LIMIT ?",
            $params
        );

        foreach ($rows as &$r) {
            // A row whose `recipient` matches its own sender was an inbound DM
            // (we record the SENDER as the DM partner so a reply DMs them back).
            $isDm = ($r['recipient'] !== '' && $r['recipient'] !== null);
            $r['transport']    = 'zello';
            $r['is_dm']        = $isDm;
            $r['reply_kind']   = $isDm ? 'user' : 'channel';
            $r['reply_target'] = $isDm ? (string) $r['sender_username'] : (string) $r['channel'];
            $r['friendly']     = $r['sender_display'] !== ''
                                    ? $r['sender_display'] : $r['sender_username'];
        }
        unset($r);

        json_response(['transport' => 'zello', 'messages' => $rows]);
    } catch (Exception $e) {
        json_error('inbox query failed: ' . $e->getMessage(), 500);
    }
}

// ── reply: queue a zello_outbox reply threaded to an inbound row ──────
//
// mode=channel → reply to the channel the inbound came in on (broadcast).
// mode=user    → DM the inbound sender (only valid when the row was a DM,
//                or the caller explicitly chooses to DM a known sender).
// Default mode follows the inbound row's own kind (DM → user; else channel).
if ($action === 'reply' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    if (!_zinbox_has_outbox($prefix)) {
        json_error('zello_outbox table missing — run sql/run_route_subaddress.php', 503);
    }

    $msgId = (int) ($input['message_id'] ?? 0);
    $text  = trim((string) ($input['text'] ?? ''));
    $mode  = strtolower(trim((string) ($input['mode'] ?? '')));
    if ($msgId <= 0) json_error('message_id required');
    if ($text === '') json_error('text required');
    if ($mode !== '' && !in_array($mode, ['channel', 'user'], true)) {
        json_error('mode must be channel or user');
    }

    $hasRecipient = _zinbox_has_recipient($prefix);
    $recipCol     = $hasRecipient ? '`recipient`' : "'' AS `recipient`";

    try {
        $row = db_fetch_one(
            "SELECT `id`, `channel`, {$recipCol}, `sender_username`, `direction`
               FROM `{$prefix}zello_messages` WHERE `id` = ? LIMIT 1",
            [$msgId]
        );
    } catch (Exception $e) {
        json_error('message lookup failed: ' . $e->getMessage(), 500);
    }
    if (!$row) json_error('inbound message not found', 404);
    if ($row['direction'] !== 'incoming') {
        json_error('can only reply to an inbound message', 422);
    }

    $wasDm   = ($row['recipient'] !== '' && $row['recipient'] !== null);
    // Resolve the effective reply mode.
    if ($mode === '') $mode = $wasDm ? 'user' : 'channel';

    $channel   = (string) $row['channel'];
    $recipient = '';
    if ($mode === 'user') {
        // DM the original sender. Zello requires a channel context even for a
        // DM, so we keep the inbound channel; the `for` field (recipient)
        // makes it a per-user message.
        $recipient = (string) $row['sender_username'];
        if ($recipient === '') {
            json_error('inbound message has no sender username — cannot DM a reply', 422);
        }
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}zello_outbox`
                (`kind`, `channel`, `recipient`, `body`, `status`, `queued_by`, `source`)
             VALUES ('text', ?, ?, ?, 'queued', ?, 'inbox')",
            [
                substr($channel, 0, 100),
                substr($recipient, 0, 100),
                substr($text, 0, 1000),
                (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]
        );
        $oid = (int) db_insert_id();
        json_response([
            'queued'       => true,
            'id'           => $oid,
            'transport'    => 'zello',
            'reply_kind'   => $mode,
            'channel'      => $channel,
            'recipient'    => $mode === 'user' ? $recipient : null,
            'in_reply_to'  => $msgId,
            'status'       => 'queued',
        ]);
    } catch (Exception $e) {
        json_error('reply queue failed: ' . $e->getMessage(), 500);
    }
}

// ── reply_status: status of one/several zello_outbox rows ─────────────
if ($action === 'reply_status' && $method === 'GET') {
    if (!_zinbox_has_outbox($prefix)) {
        json_response(['statuses' => []]);
    }
    $idsRaw = (string) ($_GET['ids'] ?? $_GET['id'] ?? '');
    $ids = array_values(array_filter(
        array_map('intval', explode(',', $idsRaw)),
        static fn($v) => $v > 0
    ));
    if (!$ids) json_error('id or ids required');
    $ids = array_slice($ids, 0, 100);

    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `status`, `error`, `channel`, `recipient`,
                    `queued_at`, `claimed_at`, `completed_at`
               FROM `{$prefix}zello_outbox`
              WHERE `id` IN ($in)",
            $ids
        );
        json_response(['statuses' => $rows]);
    } catch (Exception $e) {
        json_error('status query failed: ' . $e->getMessage(), 500);
    }
}

// ── originate_targets: units/people that resolve to a Zello username ───
//
// Feeds the Send tab's Zello "To unit / person" picker (the Zello sibling of
// api/mesh.php?action=send_targets). The actual username is NOT returned —
// the resolver runs server-side at send time so an address can't be scraped
// from this list. We only report whether each member/unit resolves on Zello.
if ($action === 'originate_targets' && $method === 'GET') {
    _zinbox_admin_auth();
    require_once __DIR__ . '/../inc/comm_resolve.php';
    try {
        // Members holding at least one zello identifier.
        $memberRows = db_fetch_all(
            "SELECT DISTINCT mci.member_id,
                    m.first_name, m.last_name, m.callsign
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
               JOIN `{$prefix}member` m ON m.id = mci.member_id
              WHERE cm.code = 'zello'
                AND cm.enabled = 1
              ORDER BY m.last_name, m.first_name"
        );
        $members = [];
        foreach ($memberRows as $r) {
            $mid = (int) $r['member_id'];
            if (comm_resolve_member_address($mid, 'zello') === null) continue;
            $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
            if ($name === '') $name = 'Member #' . $mid;
            if (!empty($r['callsign'])) $name .= ' (' . $r['callsign'] . ')';
            $members[] = ['member_id' => $mid, 'name' => $name, 'zello' => true];
        }

        // Units (responders) that map to a zello-resolvable member via either
        // linkage (personal-unit column or an active personnel assignment).
        $units = [];
        $seen  = [];
        try {
            $personal = db_fetch_all(
                "SELECT r.id, r.name
                   FROM `{$prefix}responder` r
                  WHERE r.personal_for_member_id IS NOT NULL"
            );
        } catch (Exception $e) { $personal = []; }
        try {
            $assigned = db_fetch_all(
                "SELECT DISTINCT r.id, r.name
                   FROM `{$prefix}unit_personnel_assignments` upa
                   JOIN `{$prefix}responder` r ON r.id = upa.responder_id
                  WHERE upa.status = 'active' AND upa.released_at IS NULL"
            );
        } catch (Exception $e) { $assigned = []; }

        foreach (array_merge($personal, $assigned) as $r) {
            $rid = (int) $r['id'];
            if (isset($seen[$rid])) continue;
            if (comm_resolve_unit_address_by_responder($rid, 'zello') === null) continue;
            $seen[$rid] = true;
            $units[] = [
                'unit_id' => $rid,
                'name'    => (string) ($r['name'] ?? ('Unit #' . $rid)),
                'zello'   => true,
            ];
        }

        json_response(['transport' => 'zello', 'members' => $members, 'units' => $units]);
    } catch (Exception $e) {
        json_error('originate_targets failed: ' . $e->getMessage(), 500);
    }
}

// ── originate: dispatcher-initiated Zello send (DM a unit/person, or a ──
//    plain channel broadcast). The originate sibling of `reply`, which is
//    threaded to an inbound row; originate is a fresh outbound send.
//
// Body (JSON):
//   { csrf_token,
//     text,                          (required)
//     // exactly one targeting choice:
//     unit_id   | member_id          → resolve → Zello username (a DM), OR
//     recipient                      → a Zello username typed directly (a DM), OR
//     (none of the above)            → channel broadcast
//     channel?                       (optional; blank = the proxy's configured
//                                      dispatch channel. A DM still rides on a
//                                      channel context, which the proxy fills.)
//   }
//
// Ends, exactly like reply, at a queued zello_outbox row. The running proxy
// drains it (pollZelloOutbox) and relays it; we never touch the network.
if ($action === 'originate' && $method === 'POST') {
    _zinbox_admin_auth();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    if (!_zinbox_has_outbox($prefix)) {
        json_error('zello_outbox table missing — run sql/run_route_subaddress.php', 503);
    }

    $text = trim((string) ($input['text'] ?? ''));
    if ($text === '') json_error('text required');

    // An explicit channel (optional). Blank → proxy uses zello_dispatch_channel.
    $channel = trim((string) ($input['channel'] ?? ''));

    // Gap 1: a TTS send synthesises the text to speech and keys it onto the
    // channel as Opus audio. Zello voice streams have no per-user `for` field,
    // so a TTS send is ALWAYS a channel broadcast — any DM targeting is dropped
    // (with a clear note in the response so the UI can explain it).
    $isTts = !empty($input['tts'])
        || (isset($input['kind']) && strtolower((string) $input['kind']) === 'tts');

    // Resolve the recipient (a DM) from whichever targeting input is present.
    // Precedence: unit_id → member_id → recipient(username) → (none = channel
    // broadcast). A resolve that misses is an error the dispatcher can act on
    // (the unit/person has no Zello identifier on file), not a silent broadcast.
    $unitId    = (int) ($input['unit_id'] ?? 0);
    $memberId  = (int) ($input['member_id'] ?? 0);
    $recipient = trim((string) ($input['recipient'] ?? ''));
    $resolvedFrom = null;

    // A TTS audio send can only go to the channel — force a broadcast and skip
    // recipient resolution entirely (audio has no DM addressing on Zello).
    if ($isTts) {
        $unitId = 0;
        $memberId = 0;
        $recipient = '';
    }

    if (!$isTts && ($unitId > 0 || $memberId > 0)) {
        require_once __DIR__ . '/../inc/comm_resolve.php';
        if ($unitId > 0) {
            $addr = resolve_unit_address($unitId, 'zello', 'unit');
            $resolvedFrom = 'unit:' . $unitId;
        } else {
            $addr = resolve_unit_address($memberId, 'zello', 'member');
            $resolvedFrom = 'member:' . $memberId;
        }
        if ($addr === null || $addr === '') {
            json_error('The chosen unit/person has no Zello username on file.', 422);
        }
        $recipient = $addr;
    }
    // else: $recipient is either a directly-typed username (a DM) or empty
    // (a channel broadcast). Both are valid.

    $outboxKind = $isTts ? 'tts' : 'text';
    try {
        db_query(
            "INSERT INTO `{$prefix}zello_outbox`
                (`kind`, `channel`, `recipient`, `body`, `status`, `queued_by`, `source`)
             VALUES (?, ?, ?, ?, 'queued', ?, 'originate')",
            [
                $outboxKind,
                substr($channel, 0, 100),
                substr($recipient, 0, 100),
                substr($text, 0, 1000),
                (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]
        );
        $oid = (int) db_insert_id();
        json_response([
            'queued'        => true,
            'id'            => $oid,
            'transport'     => 'zello',
            'outbox_kind'   => $outboxKind,                         // 'text' | 'tts'
            'kind'          => $isTts ? 'tts'
                                : ($recipient !== '' ? 'dm' : 'channel'),
            'direct'        => !$isTts && $recipient !== '',
            'spoken'        => $isTts,                              // audio keyed onto channel
            'channel'       => $channel !== '' ? $channel : null,  // null = proxy default
            'recipient'     => $recipient !== '' ? $recipient : null,
            'resolved_from' => $resolvedFrom,
            'status'        => 'queued',
        ]);
    } catch (Exception $e) {
        json_error('originate queue failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action: ' . $action);
