<?php
/**
 * NewUI v4.0 API — DVSwitch DMR proxy admin endpoint
 *
 * GET    ?action=channels                  — list dmr_channels
 * GET    ?action=channel&id=N              — single row
 * POST   action=channel_create             — new linked talkgroup
 * POST   action=channel_update             — edit
 * POST   action=channel_toggle             — enable / disable
 * POST   action=channel_delete             — soft delete (sets enabled=0 + clears token)
 * POST   action=channel_rotate_token       — mint a new bridge bearer (returned ONCE)
 * GET    ?action=channel_test_health&id=N  — proxy /health to the bridge HTTP control
 * POST   action=channel_test_tx            — proxy /tx/test to the bridge (1 kHz tone)
 * GET    ?action=channel_recent_calls&id=N — proxy /calls/recent to the bridge
 * GET    ?action=channel_recent_messages&id=N — persisted dmr_messages rows for this channel
 *                                              (includes transcripts written by api/dmr-ingest.php)
 * POST   action=channel_tx_text             — proxy /tx/text to the bridge (Piper synthesises and keys)
 *
 * The token-mint pattern mirrors api/mesh.php's mint_token /
 * revoke_token convention: server stores the SHA-256 hash, returns
 * the raw token exactly once when minted, and the admin is
 * responsible for copying it into the bridge's env file.
 *
 * Phase 73j (2026-06-14).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input  = ($method === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
if ($method === 'POST') {
    $action = $input['action'] ?? $action;
}

function dvs_admin_check(): void
{
    // Phase 82b — backwards-compatible default. Existing callers that
    // pre-date the three-permission split fall through to the configure
    // permission (which is also what is_admin granted them before).
    dvs_require_perm('action.dmr_configure');
}

/**
 * Phase 82b — granular DMR permission check. Three permissions split
 * the old is_admin() gate into discrete capabilities:
 *
 *   action.dmr_configure  — channel CRUD, token rotation, TTS/STT edits
 *   action.dmr_transmit   — key the radio (TTS or future voice PTT)
 *   action.dmr_receive    — view transcripts, play DVR audio, live listen
 *
 * Backwards-compat: anyone who has is_admin() implicitly has all three
 * (the legacy super-admin role inherits permissions via the normal RBAC
 * path; this just keeps small installs working before they migrate).
 */
function dvs_require_perm(string $code): void
{
    require_once __DIR__ . '/../inc/rbac.php';
    if (is_admin()) return;
    if (function_exists('rbac_can') && rbac_can($code)) return;
    json_error('Missing required permission: ' . $code, 403);
}

function dvs_csrf_check(array $input): void
{
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
}

/**
 * Probe a channel's bridge over HTTP. Returns
 * ['ok' => bool, 'status' => int, 'body' => assoc].
 * Short timeout — we don't want admin UI tabs hanging on a dead VM.
 */
function dvs_bridge_call(array $ch, string $pathAndQuery, string $verb = 'GET', $body = null): array
{
    $url = sprintf(
        'http://%s:%d%s',
        $ch['bridge_host'],
        (int) $ch['bridge_port'],
        $pathAndQuery
    );
    $token = $ch['_token_plain'] ?? null;  // attached by caller
    $h = curl_init();
    curl_setopt_array($h, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CUSTOMREQUEST  => $verb,
        CURLOPT_HTTPHEADER     => array_filter([
            $token ? 'Authorization: Bearer ' . $token : null,
            'Content-Type: application/json',
            'Accept: application/json',
        ]),
    ]);
    if ($body !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    $err  = curl_error($h);
    curl_close($h);
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'body' => ['error' => $err ?: 'connect failed']];
    }
    $decoded = json_decode($raw, true);
    return [
        'ok'     => $code >= 200 && $code < 300,
        'status' => $code,
        'body'   => is_array($decoded) ? $decoded : ['raw' => $raw],
    ];
}

// ─── GET endpoints ────────────────────────────────────────────────
if ($method === 'GET') {

    if ($action === 'channels') {
        dvs_require_perm('action.dmr_receive');
        try {
            $rows = db_fetch_all(
                "SELECT id, label, talkgroup, network, bridge_host, bridge_port,
                        link_mode, chat_channel, tts_engine, tts_voice,
                        stt_engine, stt_partials, route_to_broker,
                        enabled, last_seen_at, last_error,
                        usrp_listen_port, usrp_send_port,
                        created_at, updated_at,
                        (`bridge_token` IS NOT NULL
                          AND `bridge_token` <> '') AS has_token
                 FROM `{$prefix}dmr_channels`
                 ORDER BY enabled DESC, label"
            );
            json_response(['channels' => $rows]);
        } catch (Exception $e) {
            error_log('[dvswitch channels] ' . $e->getMessage());
            json_error('channels query failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel') {
        dvs_require_perm('action.dmr_receive');
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $row = db_fetch_one(
                "SELECT id, label, talkgroup, network, bridge_host, bridge_port,
                        link_mode, chat_channel, tts_engine, tts_voice,
                        stt_engine, stt_partials, route_to_broker,
                        enabled, last_seen_at, last_error,
                        usrp_listen_port, usrp_send_port,
                        created_at, updated_at
                 FROM `{$prefix}dmr_channels` WHERE id = ?",
                [$id]
            );
            if (!$row) json_error('not found', 404);
            json_response(['channel' => $row]);
        } catch (Exception $e) {
            json_error('query failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_test_health') {
        dvs_require_perm('action.dmr_receive');
        $id = (int) ($_GET['id'] ?? 0);
        $token = (string) ($_GET['token'] ?? '');
        if ($id <= 0) json_error('id required');
        if ($token === '') json_error('bridge token required (paste the value the admin saved at mint time)');
        try {
            $ch = db_fetch_one("SELECT * FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
            if (!$ch) json_error('not found', 404);
            $ch['_token_plain'] = $token;
            $resp = dvs_bridge_call($ch, '/health', 'GET');
            // Update last_seen on success
            if ($resp['ok']) {
                try {
                    db_query(
                        "UPDATE `{$prefix}dmr_channels`
                            SET last_seen_at = NOW(), last_error = NULL
                          WHERE id = ?",
                        [$id]
                    );
                } catch (Exception $e) {}
            } else {
                try {
                    db_query(
                        "UPDATE `{$prefix}dmr_channels`
                            SET last_error = ?
                          WHERE id = ?",
                        [substr(json_encode($resp['body']), 0, 500), $id]
                    );
                } catch (Exception $e) {}
            }
            json_response($resp);
        } catch (Exception $e) {
            json_error('health probe failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_recent_calls') {
        dvs_require_perm('action.dmr_receive');
        $id = (int) ($_GET['id'] ?? 0);
        $token = (string) ($_GET['token'] ?? '');
        if ($id <= 0) json_error('id required');
        if ($token === '') json_error('bridge token required');
        try {
            $ch = db_fetch_one("SELECT * FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
            if (!$ch) json_error('not found', 404);
            $ch['_token_plain'] = $token;
            json_response(dvs_bridge_call($ch, '/calls/recent', 'GET'));
        } catch (Exception $e) {
            json_error('call list failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_recent_messages') {
        // Phase 73o — pulls persisted dmr_messages rows so the panel
        // shows transcripts even when the bridge is offline or has
        // rolled its in-memory ring buffer. Unlike channel_recent_calls
        // this needs no bridge bearer; data is fully local.
        dvs_require_perm('action.dmr_receive');
        $id = (int) ($_GET['id'] ?? 0);
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 25)));
        if ($id <= 0) json_error('id required');
        try {
            $rows = db_fetch_all(
                "SELECT id, direction, call_started_at, call_ended_at, duration_ms,
                        talkgroup, radio_id, radio_callsign, member_id,
                        transcript, transcript_engine, audio_path, ticket_id, error
                   FROM `{$prefix}dmr_messages`
                  WHERE channel_id = ?
                  ORDER BY call_started_at DESC, id DESC
                  LIMIT {$limit}",
                [$id]
            );
            json_response(['messages' => $rows ?: []]);
        } catch (Exception $e) {
            error_log('[dvswitch channel_recent_messages] ' . $e->getMessage());
            json_error('message list failed: ' . $e->getMessage(), 500);
        }
    }
}

// ─── POST endpoints ───────────────────────────────────────────────
if ($method === 'POST') {

    if ($action === 'channel_create') {
        dvs_require_perm('action.dmr_configure');
        dvs_csrf_check($input);
        $label     = trim((string) ($input['label'] ?? ''));
        $tg        = trim((string) ($input['talkgroup'] ?? ''));
        $network   = trim((string) ($input['network'] ?? 'BrandMeister'));
        $bridgeHost = trim((string) ($input['bridge_host'] ?? ''));
        $bridgePort = (int) ($input['bridge_port'] ?? 18091);
        $listenPort = (int) ($input['usrp_listen_port'] ?? 0);
        $sendPort   = (int) ($input['usrp_send_port'] ?? 0);
        $linkMode   = (string) ($input['link_mode'] ?? 'rx_only');
        $chatChan   = trim((string) ($input['chat_channel'] ?? 'dispatch'));
        if ($label === '')      json_error('label required');
        if ($tg === '')         json_error('talkgroup required');
        if ($bridgeHost === '') json_error('bridge_host required');
        if (!in_array($linkMode, ['rx_only','tx_only','bidirectional'], true)) {
            json_error('invalid link_mode');
        }
        // Allocate ports if admin didn't specify.
        if ($listenPort === 0 || $sendPort === 0) {
            try {
                $maxListen = (int) db_fetch_value(
                    "SELECT MAX(usrp_listen_port) FROM `{$prefix}dmr_channels`"
                );
                $base = max(33000, $maxListen + 1);
                if ($listenPort === 0) $listenPort = $base + 100;
                if ($sendPort === 0)   $sendPort   = $listenPort - 1;
            } catch (Exception $e) {
                $listenPort = $listenPort ?: 33101;
                $sendPort   = $sendPort   ?: 33100;
            }
        }
        try {
            // Mint a bearer token for the bridge.  Stored hashed.
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            db_query(
                "INSERT INTO `{$prefix}dmr_channels`
                   (label, talkgroup, network, bridge_host, bridge_port,
                    bridge_token, usrp_listen_port, usrp_send_port,
                    link_mode, chat_channel, enabled, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)",
                [
                    $label, $tg, $network, $bridgeHost, $bridgePort,
                    $hash, $listenPort, $sendPort,
                    $linkMode, $chatChan,
                    (int) ($_SESSION['user_id'] ?? 0) ?: null,
                ]
            );
            $id = (int) db_insert_id();
            audit_log('comms', 'create', 'dmr_channel', $id,
                "Created DMR channel '{$label}' (TG {$tg})");
            json_response([
                'channel_id' => $id,
                'bridge_token' => $token,   // shown ONCE
                'note' => 'Paste this token into /etc/ticketscad/dvswitch-' . $label
                          . '.env as DMR_BEARER_TOKEN. It will not be shown again.',
                'suggested_env' => [
                    'DMR_INSTANCE'            => $label,
                    'DMR_USRP_LISTEN_PORT'    => $listenPort,
                    'DMR_USRP_SEND_PORT'      => $sendPort,
                    'DMR_USRP_SEND_HOST'      => '127.0.0.1',
                    'DMR_HTTP_PORT'           => $bridgePort,
                    'DMR_BEARER_TOKEN'        => $token,
                    'DMR_AUDIT_DIR'           => '/var/log/ticketscad-dvswitch',
                    'DMR_LOG_LEVEL'           => 'INFO',
                ],
            ]);
        } catch (Exception $e) {
            error_log('[dvswitch channel_create] ' . $e->getMessage());
            json_error('create failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_update') {
        dvs_require_perm('action.dmr_configure');
        dvs_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        // Whitelist of editable columns. bridge_token is rotated via
        // channel_rotate_token, never set directly.
        $editable = [
            'label', 'talkgroup', 'network', 'bridge_host', 'bridge_port',
            'usrp_listen_port', 'usrp_send_port',
            'link_mode', 'chat_channel', 'tts_engine', 'tts_voice',
            'stt_engine', 'stt_partials', 'route_to_broker',
        ];
        $set = [];
        $params = [];
        foreach ($editable as $col) {
            if (array_key_exists($col, $input)) {
                $set[] = "`{$col}` = ?";
                $params[] = is_bool($input[$col]) ? (int) $input[$col] : $input[$col];
            }
        }
        if (!$set) json_error('no editable fields supplied');
        $params[] = $id;
        try {
            db_query(
                "UPDATE `{$prefix}dmr_channels` SET " . implode(', ', $set) . " WHERE id = ?",
                $params
            );
            audit_log('comms', 'update', 'dmr_channel', $id, 'Channel updated', $input);
            json_response(['ok' => true]);
        } catch (Exception $e) {
            error_log('[dvswitch channel_update] ' . $e->getMessage());
            json_error('update failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_toggle') {
        dvs_require_perm('action.dmr_configure');
        dvs_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        if ($id <= 0) json_error('id required');
        try {
            db_query(
                "UPDATE `{$prefix}dmr_channels` SET enabled = ? WHERE id = ?",
                [$enabled, $id]
            );
            audit_log('comms', 'update', 'dmr_channel', $id,
                $enabled ? 'Channel enabled' : 'Channel disabled');
            json_response(['ok' => true, 'enabled' => $enabled]);
        } catch (Exception $e) {
            json_error('toggle failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_delete') {
        dvs_require_perm('action.dmr_configure');
        dvs_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            // Soft-delete pattern: disable + clear token so a re-enable
            // requires re-mint. Mirrors the Phase 35A bridge model.
            db_query(
                "UPDATE `{$prefix}dmr_channels`
                    SET enabled = 0, bridge_token = ''
                  WHERE id = ?",
                [$id]
            );
            audit_log('comms', 'delete', 'dmr_channel', $id, 'Channel soft-deleted');
            json_response(['ok' => true]);
        } catch (Exception $e) {
            json_error('delete failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_rotate_token') {
        dvs_require_perm('action.dmr_configure');
        dvs_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            db_query(
                "UPDATE `{$prefix}dmr_channels`
                    SET bridge_token = ?, updated_at = NOW()
                  WHERE id = ?",
                [$hash, $id]
            );
            audit_log('comms', 'rotate', 'dmr_channel', $id, 'Bearer token rotated');
            json_response([
                'bridge_token' => $token,
                'note' => 'Update /etc/ticketscad/dvswitch-<instance>.env then restart the systemd unit.',
            ]);
        } catch (Exception $e) {
            json_error('rotate failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_test_tx') {
        dvs_require_perm('action.dmr_transmit');
        dvs_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        $token = (string) ($input['token'] ?? '');
        $tg = (int) ($input['talkgroup'] ?? 0);
        $duration = (float) ($input['duration_s'] ?? 0.5);
        if ($id <= 0) json_error('id required');
        if ($token === '') json_error('bridge token required');
        if ($duration > 5) $duration = 5;
        try {
            $ch = db_fetch_one("SELECT * FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
            if (!$ch) json_error('not found', 404);
            $ch['_token_plain'] = $token;
            $tgToUse = $tg ?: (int) $ch['talkgroup'];
            $resp = dvs_bridge_call($ch, '/tx/test', 'POST', [
                'talkgroup' => $tgToUse,
                'duration_s' => $duration,
            ]);
            json_response($resp);
        } catch (Exception $e) {
            json_error('tx test failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'channel_tx_text') {
        // Phase 73m — TTS TX via Piper; reaches the radio. Phase 82b
        // gates on action.dmr_transmit instead of is_admin so a
        // dispatcher role can fire TTS without full configure rights.
        // Phase 73o — Piper-synthesised text-to-talkgroup. Audit each
        // call: dispatch ops want to know who keyed the radio with
        // what message. Bridge re-records into dmr_messages on its
        // own via _track_call + _post_ingest.
        dvs_require_perm('action.dmr_transmit');
        dvs_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        $token = (string) ($input['token'] ?? '');
        $tg = (int) ($input['talkgroup'] ?? 0);
        $text = trim((string) ($input['text'] ?? ''));
        if ($id <= 0)        json_error('id required');
        if ($token === '')   json_error('bridge token required');
        if ($text === '')    json_error('text required');
        if (mb_strlen($text) > 280) json_error('text too long (max 280 chars)');
        try {
            $ch = db_fetch_one("SELECT * FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
            if (!$ch) json_error('not found', 404);
            $ch['_token_plain'] = $token;
            $tgToUse = $tg ?: (int) $ch['talkgroup'];
            $resp = dvs_bridge_call($ch, '/tx/text', 'POST', [
                'talkgroup' => $tgToUse,
                'text'      => $text,
            ]);
            audit_log('comms', 'tx', 'dmr_channel', $id,
                'Text TX to TG' . $tgToUse . ': ' . mb_substr($text, 0, 120));
            json_response($resp);
        } catch (Exception $e) {
            error_log('[dvswitch channel_tx_text] ' . $e->getMessage());
            json_error('tx text failed: ' . $e->getMessage(), 500);
        }
    }
}

json_error('Method or action not supported (got method=' . $method
    . ', action=' . $action . ')', 405);
