<?php
/**
 * NewUI v4.0 API — Inbound SIP/PBX call-lifecycle webhook receiver
 *
 * Phase 149 (2026-08-22). Modeled directly on api/dmr-ingest.php's shape
 * (bearer-token-per-channel, rate-limited, idempotent) — the closest
 * existing analog for "external system pushes call data in" (plan.md §2).
 *
 * A dedicated external adapter process (services/sip-bridge/) speaks
 * whatever protocol the PBX vendor needs (Asterisk AMI/ARI, or a hosted
 * SIP-trunk provider's own webhook shape) and normalizes it to this ONE
 * canonical JSON contract. TicketsCAD's PHP tree never speaks SIP/AMI/ARI
 * directly.
 *
 * POST /api/sip-ingest.php
 * Headers: Authorization: Bearer <plaintext pbx_trunks.bearer_token>
 * Body (JSON):
 *   {
 *     "event": "ringing | claimed_externally | ended | abandoned",
 *     "call_id": "<PBX Uniqueid/Linkedid or SIP Call-ID>",  // idempotency key
 *     "caller_number": "+16125551234",
 *     "caller_name": "CNAM string or null",
 *     "called_number": "<DID dialed>",
 *     "event_ts": "2026-08-22T14:03:11Z"
 *   }
 *
 * Trunk identity comes ONLY from the bearer token, never from a field in
 * the body (plan.md §2) — a compromised or misconfigured adapter cannot
 * claim to be a different trunk than its token authorizes.
 *
 * Returns: { ok: true, call_id: N, applied: bool }
 */

// Fatal-to-JSON guard — bearer-token endpoint, never requires api/auth.php.
require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rate-limit.php';
require_once __DIR__ . '/../inc/sip_token.php';
// Found via live verification (2026-08-22): inbound_calls_ingest_event()'s
// SSE publish (inc/inbound-calls.php's _p149_sse()) is guarded by
// function_exists('sse_publish_for_call') so ingest never fatals if this
// file is missing — but that guard also means a webhook that arrives
// without inc/sse.php loaded silently ingests correctly (the row is
// created/updated) while publishing NOTHING, so no logged-in browser ever
// sees the ring. Confirmed live: three real webhooks landed correct rows
// in `inbound_calls` with zero rows ever appearing in `sse_events`.
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/inbound-calls.php';
ini_set('display_errors', '0');

function sip_ingest_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sip_ingest_error('POST required', 405);
}

// Rate limit BEFORE any token comparison work, exactly matching
// dmr-ingest.php's ordering — a flood of invalid-token POSTs must not be
// able to burn CPU on hash_equals() comparisons against every trunk.
$srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_ok('sip-ingest:' . $srcIp, 600, 60)) {
    rate_limit_reject(60);
}

// ── Bearer-token auth ────────────────────────────────────────────────
// Some Apache configurations (confirmed on this project's own local
// XAMPP dev box — mod_php without CGIPassAuth/an Authorization
// SetEnvIf) never populate $_SERVER['HTTP_AUTHORIZATION'] at all, even
// though the header genuinely arrived — getallheaders() sees it. Rather
// than depend on server config an operator may not control, fall back to
// it (and to REDIRECT_HTTP_AUTHORIZATION, the CGI/FastCGI proxy shape),
// so this endpoint works portably across Apache/nginx/IIS configurations
// without requiring a specific server-side auth-passthrough directive.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) {
            $auth = $hVal;
            break;
        }
    }
}
if (stripos($auth, 'Bearer ') !== 0) {
    sip_ingest_error('Bearer token required', 401);
}
$token = substr($auth, 7);

$trunk = sip_token_resolve_trunk($token);
if ($trunk === null) {
    error_log('[sip-ingest] bearer mismatch from ' . $srcIp);
    sip_ingest_error('bad bearer', 403);
}
if ((int) ($trunk['enabled'] ?? 0) !== 1) {
    // Drop politely — an admin disabled the trunk; the adapter should
    // notice via a future health probe and stop. Don't 4xx; it would retry.
    json_response(['ok' => true, 'dropped' => 'trunk disabled']);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    sip_ingest_error('JSON body required');
}

$result = inbound_calls_ingest_event($trunk, $input);
if (!$result['ok']) {
    sip_ingest_error((string) ($result['reason'] ?? 'ingest failed'));
}

header('Content-Type: application/json');
echo json_encode([
    'ok'      => true,
    'call_id' => $result['call_id'],
    'applied' => $result['applied'],
]);
