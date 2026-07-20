<?php
/**
 * Phase 94 Stage 2 — External API auth orchestrator.
 *
 * Required at the top of EVERY api/external/v1/*.php endpoint.
 * Executes the 7-step flow from plan.md §2.2 in order. On any failure,
 * emits the standardized error envelope and exits. On success, sets
 * up $_SESSION + $GLOBALS['__ext_api_token'] so downstream code
 * (rbac_can, audit_log, internal write helpers) just works.
 *
 * Does NOT include api/auth.php (the cookie/session-based gate). The
 * bearer token IS the auth. Cookies sent by the client are explicitly
 * ignored.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../inc/db.php';
require_once __DIR__ . '/../../../inc/client-ip.php';
require_once __DIR__ . '/../../../inc/external-auth.php';

// Suppress display_errors so we never leak PHP warnings/notices into a
// JSON response that integrators are parsing.
ini_set('display_errors', '0');

// Generate per-request id for correlation across audit + delivery rows
$GLOBALS['__ext_api_request_id'] = bin2hex(random_bytes(8));

// Standard security headers (HSTS, etc.) — same as the rest of NewUI
if (file_exists(__DIR__ . '/../../../inc/security-headers.php')) {
    require_once __DIR__ . '/../../../inc/security-headers.php';
    if (function_exists('set_security_headers')) set_security_headers();
}

$clientIp = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

// ── 1. HTTPS gate ────────────────────────────────────────────────
if (ext_api_require_tls() && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    ext_api_error('https_required', 426);
}

// ── 2. Bearer extract ────────────────────────────────────────────
$bearer = ext_api_extract_bearer();
if (!$bearer) ext_api_error('missing_token', 401);

// ── 3. Resolve token ─────────────────────────────────────────────
$token = ext_api_resolve($bearer);
if (!$token)                                  ext_api_error('invalid_token', 401);
if ($token['revoked_at'])                     ext_api_error('token_revoked', 401);
if ($token['expires_at'] && $token['expires_at'] < gmdate('Y-m-d H:i:s')) {
    ext_api_error('token_expired', 401);
}

// ── 4. IP allowlist ──────────────────────────────────────────────
if (!ext_api_check_ip($token, $clientIp)) {
    ext_api_error('ip_not_allowed', 403);
}

// ── 5. Rate limit ────────────────────────────────────────────────
if (!ext_api_check_rate_limit((int) $token['id'], (int) $token['rate_limit_per_hour'])) {
    ext_api_error('rate_limited', 429, ['retry_after' => ext_api_retry_after((int) $token['id'])]);
}

// ── 6. Hydrate session-shaped context ────────────────────────────
ext_api_hydrate_session($token);
$GLOBALS['__ext_api_token']   = $token;
$GLOBALS['__ext_api_token_id'] = (int) $token['id'];
$GLOBALS['__ext_api_client_ip'] = $clientIp;

// ── 7. Touch last_used + increment rate-limit bucket ─────────────
ext_api_record_use((int) $token['id'], $clientIp);

// Now the endpoint that included this file can:
//   ext_api_require_scope('incidents:write');
//   if (!rbac_can('action.create_incident')) ext_api_error('forbidden_rbac', 403, ['required' => 'action.create_incident']);
//   ... read input, call inc/incident-write.php helpers ...
//   ext_api_response(['id' => $newId], 201);
