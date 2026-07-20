<?php
/**
 * NewUI v4.0 API — DMR call-record ingest
 *
 * The DVSwitch bridge daemon (services/dvswitch/bridge.py) POSTs here
 * when a call finishes. We:
 *
 *   1. Look up the dmr_channels row by `label`.
 *   2. Verify the bridge's bearer token matches the hashed token
 *      stored on the row (same scheme as Phase 35A meshbridge and
 *      Phase 41 OwnTracks tokens — server stores SHA-256(token); the
 *      bridge holds the plaintext in its env file).
 *   3. INSERT a dmr_messages row for the call.
 *   4. Update dmr_channels.last_seen_at so the admin panel renders a
 *      fresh status badge.
 *
 * Phase 73l (2026-06-14). Bridge.py will be wired to call this in
 * the same slice as STT/TTS integration (Phase 73m+).
 *
 * POST /api/dmr-ingest.php
 * Headers: Authorization: Bearer <plain-token>
 * Body (JSON):
 *   {
 *     "label": "tg91",                 // dmr_channels.label
 *     "direction": "rx" | "tx",
 *     "talkgroup": 91,
 *     "radio_id": "1234567",           // optional, BrandMeister RID
 *     "radio_callsign": "N0NKI",       // optional, looked up from RID
 *     "started_at": "2026-06-14 23:01:00",   // ISO timestamp
 *     "ended_at":   "2026-06-14 23:01:08",   // optional, omit for ongoing
 *     "duration_ms": 7800,             // optional
 *     "transcript": "all clear at scene", // optional
 *     "transcript_engine": "vosk",     // optional
 *     "transcript_partials": "[{...}]",// optional JSON of partials
 *     "audio_path": "/var/log/.../call-12.wav", // optional
 *     "audio_format": "wav",           // optional
 *     "routed_to": "broker:dispatch",  // optional, where we forwarded
 *     "ticket_id": 95,                 // optional, if bound to incident
 *     "error": "stt timeout"           // optional
 *   }
 *
 * Returns: { ok: true, message_id: N }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rate-limit.php';
ini_set('display_errors', '0');

// Phase 73x — per-source-IP rate limit. 600 ingest POSTs per minute
// covers normal traffic by a wide margin (a typical talkgroup sees
// 10-60 calls/min peak). Anything past that is either runaway flood
// or token compromise; respond 429 + Retry-After.
$srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_ok('dmr-ingest:' . $srcIp, 600, 60)) {
    rate_limit_reject(60);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

function dmr_ingest_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

/**
 * Phase 85c-fix-8: accept either a Unix epoch timestamp (with or
 * without fractional seconds — echo_bot sends time.time()) or a
 * pre-formatted MySQL DATETIME string. Empty/missing → now.
 * Returns a 'YYYY-MM-DD HH:MM:SS' string suitable for INSERT.
 */
function dmr_ingest_normalize_ts($value): string
{
    if ($value === null || $value === '') {
        return date('Y-m-d H:i:s');
    }
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int) round((float) $value));
    }
    $s = (string) $value;
    // Already MySQL DATETIME-ish? Trust it as-is. Anything malformed
    // will be caught by the DB INSERT and surfaced as a 500.
    return $s;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dmr_ingest_error('POST required', 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    dmr_ingest_error('JSON body required');
}

$label = trim((string) ($input['label'] ?? ''));
if ($label === '') dmr_ingest_error('label required');

// ── Bearer-token auth ─────────────────────────────────────────────
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (stripos($auth, 'Bearer ') !== 0) {
    dmr_ingest_error('Bearer token required', 401);
}
$token = substr($auth, 7);

try {
    $channel = db_fetch_one(
        "SELECT id, label, talkgroup, route_to_broker, bridge_token, enabled
         FROM `{$prefix}dmr_channels`
         WHERE label = ? LIMIT 1",
        [$label]
    );
} catch (Exception $e) {
    error_log('[dmr-ingest] channel lookup failed: ' . $e->getMessage());
    dmr_ingest_error('lookup failed', 500);
}
if (!$channel) dmr_ingest_error('channel not found', 404);
// Phase 84-followup: bridge_token is stored as plaintext to match
// the outbound-auth path used by api/dmr-stream.php and
// api/dmr-tx-audio.php (which forward it back to the bridge as a
// Bearer header). Accept both formats so legacy installs that
// stored a hash still work.
$stored   = (string) $channel['bridge_token'];
$incoming = $token;
$incomingHash = hash('sha256', $token);
$ok = hash_equals($stored, $incoming) || hash_equals($stored, $incomingHash);
if (!$ok) {
    error_log('[dmr-ingest] bearer mismatch on label=' . $label);
    dmr_ingest_error('bad bearer', 403);
}
if ((int) $channel['enabled'] !== 1) {
    // Drop politely — admin disabled the channel; bridge should
    // notice via /health probe and stop. Don't 4xx; the bridge
    // would retry.
    json_response(['ok' => true, 'dropped' => 'channel disabled']);
}

// ── Validate + normalize the call record ──────────────────────────
$direction = (string) ($input['direction'] ?? '');
if (!in_array($direction, ['rx', 'tx'], true)) {
    dmr_ingest_error('direction must be rx or tx');
}

// Phase 85c-fix-8: echo_bot was sending Unix epoch (1781663357.535)
// while the column expects 'YYYY-MM-DD HH:MM:SS'. Accept either
// numeric (epoch seconds, with optional fractional) OR pre-formatted
// MySQL datetime, normalize to the latter before INSERT. Without
// this, every echo_bot ingest since Phase 82 has been silently
// failing with HTTPError 500 and no RX rows have landed in the DB
// since 2026-06-15 even though RX audio was being decoded fine.
$rawStartedAt = $input['started_at'] ?? '';
$startedAt = dmr_ingest_normalize_ts($rawStartedAt);
$endedAt   = isset($input['ended_at']) ? dmr_ingest_normalize_ts($input['ended_at']) : null;
$tg        = isset($input['talkgroup']) ? (string) $input['talkgroup']
                                        : (string) $channel['talkgroup'];

$durMs     = isset($input['duration_ms'])      ? (int) $input['duration_ms'] : null;
$radioId   = isset($input['radio_id'])         ? (string) $input['radio_id'] : null;
$radioCs   = isset($input['radio_callsign'])   ? (string) $input['radio_callsign'] : null;

// Phase 86-archive followup (#61): if the ingester didn't supply a
// callsign but we already know this DMR ID from the radioid_users
// cache, populate it now so the row lands clean instead of needing a
// read-side JOIN later. Cache hit is a single indexed SELECT — no
// latency added to the ingest hot path. For genuinely unknown IDs we
// leave it NULL; tools/radioid_fetch_unknowns.php fills those in via
// a polite live lookup that we deliberately do NOT do here (a 10 s
// radioid.net round-trip on every ingest call would block echo_bot
// and the proxy).
if (($radioCs === null || trim($radioCs) === '')
    && $radioId !== null && ctype_digit($radioId)) {
    try {
        $hit = db_fetch_one(
            "SELECT callsign FROM `{$prefix}radioid_users` WHERE dmr_id = ?",
            [(int) $radioId]
        );
        if ($hit && !empty($hit['callsign'])) {
            $radioCs = $hit['callsign'];
        }
    } catch (Exception $e) {
        // Cache table missing or unreadable — fall through with NULL
        // callsign, exactly as before. Don't fail the ingest write.
    }
}
$transcript     = $input['transcript']        ?? null;
$transcriptEng  = $input['transcript_engine'] ?? null;
$transcriptPar  = $input['transcript_partials'] ?? null;
$audioPath  = $input['audio_path']   ?? null;
$audioFmt   = $input['audio_format'] ?? null;
// Phase 73x / Phase 82 — audio_path comes from the bridge as a path
// RELATIVE to its recordings_dir (Phase 82 change). The dispatcher
// UI never interpolates this value into a URL directly; instead it
// calls api/dmr-audio.php which forwards the value to the bridge's
// /recording endpoint, where bridge.py does its own realpath +
// containment check against recordings_dir. So we don't need
// basename-only here — just enforce that:
//   * the path is relative (no leading slash, no Windows drive),
//   * no parent-traversal segments (.., literal or URL-encoded),
//   * a tight whitelisted character set,
//   * length cap.
// This protects against a compromised bridge token supplying weird
// paths and still lets us preserve the per-day/per-instance dir
// structure the bridge writes WAVs into.
// Phase 85c-fix-10: echo_bot writes WAVs to the bridge's recordings
// root as absolute paths (e.g.
// /var/cache/ticketscad-dvswitch/minnesota-statewide-...wav). The
// stricter "relative only" rule from Phase 82 was silently nulling
// every RX audio_path. The bridge's /recording handler does the
// authoritative realpath + containment check, so we just need to
// reject obviously hostile inputs (parent-traversal, weird chars)
// and accept anything else. Absolute paths must live under the
// known recordings root; relative paths are anchored by the bridge.
const RADIO_RECORDINGS_ROOT = '/var/cache/ticketscad-dvswitch/';
if (is_string($audioPath) && $audioPath !== '') {
    $candidate = str_replace('\\', '/', $audioPath);
    $rejected = false;
    if ($candidate === '') {
        $rejected = true;
    } elseif ($candidate[0] === '/') {
        // Absolute path — must live under the recordings root.
        if (strpos($candidate, RADIO_RECORDINGS_ROOT) !== 0) $rejected = true;
    }
    if (!$rejected && preg_match('#(^|/)\.\.(/|$)#', $candidate)) $rejected = true;
    if (!$rejected && stripos($candidate, '%2e%2e') !== false) $rejected = true;
    if (!$rejected && !preg_match('#^[A-Za-z0-9._/-]{1,256}$#', $candidate)) $rejected = true;
    $audioPath = $rejected ? null : $candidate;
} else {
    $audioPath = null;
}
if (is_string($audioFmt) && !preg_match('/^[a-z0-9]{1,8}$/i', $audioFmt)) {
    $audioFmt = null;
}
$routedTo   = $input['routed_to']    ?? null;
$ticketId   = isset($input['ticket_id']) ? (int) $input['ticket_id'] : null;
$err        = $input['error']        ?? null;

// Best-effort: bind callsign back to a member row so the message
// log can render the dispatcher name on the incident view.
$memberId = null;
if ($radioCs) {
    try {
        $memberId = (int) db_fetch_value(
            "SELECT id FROM `{$prefix}member` WHERE callsign = ? LIMIT 1",
            [$radioCs]
        );
        if (!$memberId) $memberId = null;
    } catch (Exception $e) {
        // member.callsign may not exist on legacy installs — non-fatal.
        $memberId = null;
    }
}

// ── INSERT ─────────────────────────────────────────────────────────
try {
    db_query(
        "INSERT INTO `{$prefix}dmr_messages`
           (channel_id, direction, call_started_at, call_ended_at, duration_ms,
            talkgroup, radio_id, radio_callsign, member_id,
            transcript, transcript_engine, transcript_partials,
            audio_path, audio_format, routed_to, ticket_id, error)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            (int) $channel['id'], $direction, $startedAt, $endedAt, $durMs,
            $tg, $radioId, $radioCs, $memberId,
            $transcript, $transcriptEng, $transcriptPar,
            $audioPath, $audioFmt, $routedTo, $ticketId, $err,
        ]
    );
    $msgId = (int) db_insert_id();
} catch (Exception $e) {
    error_log('[dmr-ingest] INSERT failed: ' . $e->getMessage());
    dmr_ingest_error('insert failed: ' . $e->getMessage(), 500);
}

// Refresh last_seen so the panel's status badge updates.
try {
    db_query(
        "UPDATE `{$prefix}dmr_channels`
            SET last_seen_at = NOW(), last_error = NULL
          WHERE id = ?",
        [(int) $channel['id']]
    );
} catch (Exception $e) {
    error_log('[dmr-ingest] last_seen update failed: ' . $e->getMessage());
}

// Future Phase 73m: if direction=rx, channel.route_to_broker=1, and
// transcript is non-empty, broker_send() the transcript as a chat
// message to channel.chat_channel. Skipped for now — needs the
// schema decision on `messages`/`chat_messages` first.

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'message_id' => $msgId]);
