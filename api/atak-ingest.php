<?php
/**
 * Phase 91 Slice 3 — Inbound ATAK CoT receiver.
 *
 * Accepts either:
 *   Content-Type: application/json
 *     Body = an entity dict in the shape inc/cot.php uses (the bridge
 *     decodes Meshtastic ATAK plugin protobuf in Python and POSTs
 *     this form).
 *   Content-Type: application/xml or text/xml
 *     Body = raw CoT XML — convenient for curl smoke tests AND for
 *     the v1.5 TAK Server bridge if it forwards raw rather than
 *     pre-decoded events.
 *
 * Auth:
 *   Bearer token in Authorization: Bearer <token> OR ?token=<token>.
 *   Token must exist in location_ingest_tokens, scoped to the ATAK
 *   provider, not revoked. Anonymous accepted only when
 *   settings.atak_inbound_require_token = 0.
 *
 * Routing of accepted events (delegated to inc/atak_route.php):
 *   - kind=responder (position) → location_reports row, attributed to
 *     bound personnel via comm_identifiers OR upserted into
 *     atak_unbound_uids if no binding exists
 *   - kind=marker subtype=u-d-c-c (circle) → always create new
 *     geofenced incident (decision 3 override)
 *   - kind=marker subtype=b-m-p-w (waypoint) → channel's
 *     marker_default_action decides (new incident OR note on nearest)
 *   - kind=chat → broker_send('local_chat', ...)
 *
 * Always logs the event to atak_push_log with direction='in' for
 * the operator's Recent CoT events panel, even if routing fails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rate-limit.php';
require_once __DIR__ . '/../inc/client-ip.php';
require_once __DIR__ . '/../inc/cot.php';
require_once __DIR__ . '/../inc/atak_route.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ── Per-IP rate limit (mirrors Phase 88/89 pattern) ─────────────
$srcIp = client_ip();
if (!rate_limit_ok('atak-ingest:' . $srcIp, 600, 60)) {
    rate_limit_reject(60);
}

// ── Token auth ──────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $h = (string) $_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($h, 'bearer ') === 0) $token = trim(substr($h, 7));
}

$atakProviderId = null;
try {
    $atakProviderId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}location_providers` WHERE code = ?",
        ['atak']
    );
} catch (Exception $e) { /* migration not run */ }

if (!$atakProviderId) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ATAK provider not configured — run sql/run_atak_schema.php']);
    exit;
}

$authTokenId = null;
if ($token !== '') {
    try {
        $hash = hash('sha256', $token);
        $row = db_fetch_one(
            "SELECT id, provider_id FROM `{$prefix}location_ingest_tokens`
              WHERE secret_hash = ? AND revoked_at IS NULL LIMIT 1",
            [$hash]
        );
        if ($row) {
            if ($row['provider_id'] !== null
                && (int) $row['provider_id'] !== $atakProviderId) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'token not valid for ATAK provider']);
                exit;
            }
            $authTokenId = (int) $row['id'];
            try {
                db_query(
                    "UPDATE `{$prefix}location_ingest_tokens`
                        SET last_used_at = NOW(), last_used_ip = ?
                      WHERE id = ?",
                    [$srcIp, $authTokenId]
                );
            } catch (Exception $e) { /* non-fatal */ }
        }
    } catch (Exception $e) {
        // location_ingest_tokens missing (pre-Phase-89) — fall through
    }
}

$requireToken = (int) get_variable('atak_inbound_require_token');
if ($authTokenId === null && $requireToken) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'token required (atak_inbound_require_token=1)']);
    exit;
}

// ── Parse body — JSON or XML ────────────────────────────────────
$rawBody    = file_get_contents('php://input') ?: '';
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
$entity     = null;
$wireKind   = 'json';

if (strpos($contentType, 'xml') !== false || (strlen($rawBody) > 0 && $rawBody[0] === '<')) {
    $entity = cot_decode_xml($rawBody);
    $wireKind = 'xml';
} else {
    $entity = json_decode($rawBody, true);
}

if (!is_array($entity)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'could not parse body as ' . ($wireKind === 'xml' ? 'CoT XML' : 'JSON entity')]);
    exit;
}

// Required fields after parse: uid, kind, lat, lng
$uid = trim((string) ($entity['uid'] ?? ''));
$kind = (string) ($entity['kind'] ?? '');
$lat = isset($entity['lat']) ? (float) $entity['lat'] : null;
$lng = isset($entity['lng']) ? (float) $entity['lng'] : null;

// Inferred kind for callers who don't set it explicitly
if ($kind === '' && isset($entity['cot_type'])) {
    $kind = (strpos($entity['cot_type'], 'b-t-f') === 0) ? 'chat' :
            (strpos($entity['cot_type'], 'u-d-c-c') === 0 || strpos($entity['cot_type'], 'b-m-p-w') === 0
                ? 'marker' : 'responder');
    $entity['kind'] = $kind;
}

$transport   = (string) ($entity['transport']   ?? 'meshtastic');
$channelRef  = (string) ($entity['channel_ref'] ?? 'unknown');

// Sanity validation
$errors = [];
if ($uid === '' || preg_match('/\s/', $uid))     $errors[] = 'uid required, no whitespace';
if (!in_array($kind, ['responder','marker','chat','incident','facility','other'], true))
    $errors[] = "kind must be one of responder|marker|chat|incident|facility|other (got '$kind')";
if ($kind !== 'chat' && ($lat === null || $lng === null)) $errors[] = 'lat and lng required for non-chat events';
if ($lat !== null && ($lat < -90 || $lat > 90))    $errors[] = 'lat out of range';
if ($lng !== null && ($lng < -180 || $lng > 180)) $errors[] = 'lng out of range';
if (!in_array($transport, ['meshtastic','tak_server'], true))
    $errors[] = "transport must be meshtastic|tak_server (got '$transport')";

if ($errors) {
    // Log even the rejected event so the operator can see what's
    // being attempted — useful for diagnosing a misconfigured device.
    _atak_audit_log($prefix, 'in', $transport, $channelRef, $entity,
                    $authTokenId, strlen($rawBody), 'dropped',
                    implode('; ', $errors));
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'validation failed', 'details' => $errors]);
    exit;
}

// ── Route the event ─────────────────────────────────────────────
$routeResult = null;
try {
    $routeResult = atak_route_inbound($entity, $transport, $channelRef, $authTokenId);
} catch (Exception $e) {
    _atak_audit_log($prefix, 'in', $transport, $channelRef, $entity,
                    $authTokenId, strlen($rawBody), 'failed', $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'route failed: ' . $e->getMessage()]);
    exit;
}

_atak_audit_log($prefix, 'in', $transport, $channelRef, $entity,
                $authTokenId, strlen($rawBody), 'ok', null);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok'        => true,
    'routed_as' => $routeResult,
    'entity'    => [
        'uid'  => $uid,
        'kind' => $kind,
    ],
]);

// ─── Helpers ────────────────────────────────────────────────────

function _atak_audit_log(string $prefix, string $direction, string $transport,
                         string $channelRef, array $entity, ?int $authTokenId,
                         int $size, string $status, ?string $err): void {
    // Schema audit 2026-07-07: atak_push_log was deliberately DROPPED by
    // sql/run_atak_consolidation.php (mesh_packet_log took over packet
    // logging), but this helper still inserted into it — every ATAK audit
    // write failed into error_log. Route the audit intent to the standard
    // append-only audit_log instead.
    try {
        if (!function_exists('audit_log')) {
            require_once __DIR__ . '/../inc/audit.php';
        }
        audit_log(
            'atak', $direction, 'atak_' . ($entity['kind'] ?? 'packet'),
            $entity['id'] ?? null,
            "ATAK $direction via $transport ($channelRef): "
                . ($entity['cot_type'] ?? '?') . ' ' . ($entity['uid'] ?? '')
                . " — $status" . ($err ? " ($err)" : ''),
            [
                'transport'      => $transport,
                'channel_ref'    => $channelRef,
                'cot_type'       => $entity['cot_type'] ?? null,
                'cot_uid'        => $entity['uid'] ?? null,
                'auth_token_id'  => $authTokenId,
                'raw_size_bytes' => $size,
                'status'         => $status,
                'error'          => $err,
            ]
        );
    } catch (Throwable $e) {
        // Never let an audit-write failure block the ingest response.
        error_log("[atak-ingest] audit log failed: " . $e->getMessage());
    }
}
