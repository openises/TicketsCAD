<?php
/**
 * Phase 94 Stage 2 — External API authentication helpers.
 *
 * Pure helpers, no side effects. The orchestrator at
 * api/external/v1/_auth.php composes them in the order spec'd by
 * plan.md §2.2.
 *
 * Token format: tcad_<env>_<32_random_chars> (see plan.md §2.1).
 * Storage: only sha256 of raw token lands in external_api_tokens.token_hash.
 * Raw token shown to admin ONCE at mint time, then thrown away.
 *
 * Auth model: bearer token in Authorization header. Never query string.
 * Never cookies. Token's scope LIMITS what the token can hit; the
 * underlying user's RBAC role GRANTS the actual capability (Decision
 * #1, real-user binding).
 */

declare(strict_types=1);

require_once __DIR__ . '/client-ip.php';

// ── Token format + minting ──────────────────────────────────────

/**
 * Mint a new external API token. Returns ['id' => N, 'raw_token' => '...'].
 * The raw token is shown to the admin ONCE then thrown away — only
 * sha256(raw_token) lands in the DB.
 *
 * Admin tool / Settings UI both call this. CLI variant in
 * tools/mint_external_api_token.php for ops use.
 *
 * @param int    $userId          Token's binding user (Decision #1)
 * @param array  $scopes          List of scope codes ['incidents:read', ...]
 * @param int    $createdByUserId Admin who's minting
 * @param array  $opts            Optional: name, description, ip_allowlist (array of CIDR), expires_at (Y-m-d H:i:s), rate_limit_per_hour
 * @return array ['id' => int, 'raw_token' => string, 'token_prefix' => string]
 * @throws RuntimeException if a unique token can't be generated (extremely unlikely)
 */
function ext_api_mint_token(int $userId, array $scopes, int $createdByUserId, array $opts = []): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $env = trim((string) (_ext_api_setting('external_api_env_letter') ?: 'p'));
    if (!preg_match('/^[a-z]$/', $env)) $env = 'p';

    // base62 encoding of 24 random bytes ≈ 32 chars, ~190 bits entropy
    $raw = 'tcad_' . $env . '_' . _ext_api_base62(random_bytes(24));
    $hash = hash('sha256', $raw);
    $visiblePrefix = substr($raw, 0, 14); // tcad_p_xxxxxxx (7 random chars visible)

    $rateLimit = (int) ($opts['rate_limit_per_hour'] ?? (_ext_api_setting('external_api_default_rate_limit') ?: 1000));
    $name = trim((string) ($opts['name'] ?? 'Unnamed token'));
    if ($name === '') $name = 'Unnamed token';

    db_query(
        "INSERT INTO `{$prefix}external_api_tokens`
         (name, description, token_prefix, token_hash, scopes_json, ip_allowlist_json,
          user_id, rate_limit_per_hour, created_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $name,
            $opts['description'] ?? null,
            $visiblePrefix,
            $hash,
            json_encode(array_values($scopes)),
            isset($opts['ip_allowlist']) && is_array($opts['ip_allowlist']) ? json_encode($opts['ip_allowlist']) : null,
            $userId,
            $rateLimit,
            $createdByUserId,
            $opts['expires_at'] ?? null,
        ]
    );
    return [
        'id'           => (int) db_insert_id(),
        'raw_token'    => $raw,
        'token_prefix' => $visiblePrefix,
    ];
}

// ── 7-step auth flow helpers (called by api/external/v1/_auth.php) ──

/**
 * Step 1 (TLS gate). Returns true if HTTPS is required per settings.
 */
function ext_api_require_tls(): bool {
    return (int) (_ext_api_setting('external_api_require_tls') ?? 1) === 1;
}

/**
 * Step 2 (bearer extract). Reads ONLY from Authorization header.
 * Never accepts the token in a query string or POST body.
 *
 * @return string|null Bearer token or null if absent/malformed
 */
function ext_api_extract_bearer(): ?string {
    // Apache + mod_php usually exposes the Authorization header here:
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    // Some Apache configs strip Authorization; getallheaders() may have it
    if (!$hdr && function_exists('getallheaders')) {
        $all = getallheaders() ?: [];
        foreach ($all as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $hdr = $v;
                break;
            }
        }
    }
    // Apache 2.4 sometimes only exposes it as REDIRECT_HTTP_AUTHORIZATION
    if (!$hdr && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!$hdr) return null;
    if (stripos($hdr, 'Bearer ') !== 0) return null;
    $tok = trim(substr($hdr, 7));
    return $tok !== '' ? $tok : null;
}

/**
 * Step 3 (token resolve). Returns the token row + decoded scopes/CIDR
 * or null if no match.
 *
 * @return array|null Token row with scopes_json + ip_allowlist_json decoded
 */
function ext_api_resolve(string $rawBearer): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!preg_match('/^tcad_[a-z]_[A-Za-z0-9]+$/', $rawBearer)) {
        // Doesn't match our format — early reject to avoid wasting a DB lookup
        return null;
    }
    $hash = hash('sha256', $rawBearer);
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}external_api_tokens` WHERE token_hash = ? LIMIT 1",
            [$hash]
        );
    } catch (Exception $e) {
        return null;
    }
    if (!$row) return null;
    $row['scopes']       = @json_decode($row['scopes_json'] ?? '[]', true) ?: [];
    $row['ip_allowlist'] = @json_decode($row['ip_allowlist_json'] ?? 'null', true);
    return $row;
}

/**
 * Step 4 (IP allowlist check). Returns true if the allowlist is empty
 * (no restriction) OR the IP matches any CIDR in the list.
 */
function ext_api_check_ip(array $token, string $clientIp): bool {
    $cidrs = $token['ip_allowlist'] ?? null;
    if (!is_array($cidrs) || empty($cidrs)) return true; // no restriction
    foreach ($cidrs as $cidr) {
        if (_ext_api_cidr_match($clientIp, (string) $cidr)) return true;
    }
    return false;
}

/**
 * Step 5 (rate limit). Sliding-window count over the last hour. Returns
 * true if under the per-token ceiling.
 *
 * 2026-06-28 security audit fix #3 — was fail-OPEN on DB exception,
 * which means a flood that fills DB connections or trips a lock
 * disables the limiter entirely. Now fail-CLOSED for safety: if we
 * can't check the limit, treat the request as if it exceeded the
 * limit. The expense is one false 429 to a legitimate caller during
 * an outage — which the integrator will see in their delivery log
 * and can retry. The alternative (silent unlimited) is worse.
 *
 * The one-time installer-missing-table case is handled out-of-band
 * (admin runs tools/install_fresh.php before exposing the API).
 */
function ext_api_check_rate_limit(int $tokenId, int $limit): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_value(
            "SELECT COALESCE(SUM(count), 0)
             FROM `{$prefix}external_api_rate_limits`
             WHERE token_id = ? AND bucket_min >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)",
            [$tokenId]
        );
    } catch (Exception $e) {
        error_log('[ext_api] rate-limit lookup failed (failing CLOSED): ' . $e->getMessage());
        return false; // fail-closed
    }
    return ((int) $row) < $limit;
}

/**
 * Returns the seconds until the rate-limit window most likely opens up
 * (roughly: time until the oldest bucket in the current window falls
 * out). Approximate — clients use it as a hint, not a strict promise.
 */
function ext_api_retry_after(int $tokenId): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $oldest = db_fetch_value(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(bucket_min), INTERVAL 60 MINUTE))
             FROM `{$prefix}external_api_rate_limits`
             WHERE token_id = ? AND bucket_min >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)",
            [$tokenId]
        );
        return max(60, (int) $oldest);
    } catch (Exception $e) {
        return 60;
    }
}

/**
 * Step 7 (touch last-used + increment rate-limit bucket). Best-effort.
 */
function ext_api_record_use(int $tokenId, string $clientIp): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}external_api_tokens`
             SET last_used_at = NOW(), last_used_ip = ?
             WHERE id = ?",
            [$clientIp, $tokenId]
        );
    } catch (Exception $e) { /* non-fatal */ }
    try {
        db_query(
            "INSERT INTO `{$prefix}external_api_rate_limits` (token_id, bucket_min, count)
             VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00'), 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            [$tokenId]
        );
    } catch (Exception $e) { /* non-fatal */ }
}

/**
 * Step 6 (hydrate session-shaped context). External endpoints' downstream
 * helpers (rbac_can, audit_log) read $_SESSION['user_id'], ['user'],
 * ['user_groups'], ['active_org_id']. Populate these from the token's
 * bound user without ever issuing a session cookie or calling
 * session_start().
 */
function ext_api_hydrate_session(array $token): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $userId = (int) $token['user_id'];

    // Make sure no real session is mounted (paranoia)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_abort(); // discard, don't save
    }

    // Populate the $_SESSION superglobal directly without starting a
    // session. Downstream code only reads these; we never persist.
    $_SESSION = [];
    $_SESSION['user_id'] = $userId;
    try {
        $u = db_fetch_one(
            "SELECT id, user FROM `{$prefix}user` WHERE id = ? LIMIT 1",
            [$userId]
        );
        if ($u) {
            $_SESSION['user'] = $u['user'];
        }
    } catch (Exception $e) { /* user table may differ; leave name unset */ }

    // Group membership (used by allocate() in incident-create and the
    // SSE visibility scoping). Schema audit 2026-07-07: this claimed to
    // "mirror login.php" but queried a `group_users` table that has never
    // existed — the catch handed every external-API token an EMPTY group
    // list, so group-scoped visibility differed from the same user's real
    // session. login.php's actual source is allocates type=4.
    try {
        $groups = db_fetch_all(
            "SELECT `group` FROM `{$prefix}allocates`
              WHERE `type` = 4 AND `resource_id` = ? ORDER BY `id` ASC",
            [$userId]
        );
        $_SESSION['user_groups'] = array_map(function ($r) { return (int) $r['group']; }, $groups);
    } catch (Exception $e) { $_SESSION['user_groups'] = []; }

    // Active org (if multi-org installed). Schema audit 2026-07-07: the
    // membership table is member_organizations via the user's linked
    // member row (there is no user_orgs table) — mirrors login.php.
    try {
        $orgId = db_fetch_value(
            "SELECT mo.org_id
               FROM `{$prefix}member` m
               JOIN `{$prefix}member_organizations` mo ON mo.member_id = m.id
              WHERE m.user_id = ? AND mo.status = 'active'
              LIMIT 1",
            [$userId]
        );
        if ($orgId) $_SESSION['active_org_id'] = (int) $orgId;
    } catch (Exception $e) { /* multi-org may not be enabled */ }
}

/**
 * Scope check. Called by each external endpoint AFTER _auth.php
 * succeeds. Exits 403 forbidden_scope on mismatch.
 *
 * Match rules:
 *   - exact match: 'incidents:write' satisfies 'incidents:write'
 *   - '*' satisfies anything
 *   - '*:read' satisfies any '<X>:read' scope (read-everything tokens)
 *   - '<X>:*' or '<X>' satisfies '<X>:read' and '<X>:write' (per-resource superuser)
 */
function ext_api_require_scope(string $requiredScope): void {
    $token = $GLOBALS['__ext_api_token'] ?? null;
    if (!$token) {
        ext_api_error('auth_not_resolved', 500);
    }
    $tokenScopes = $token['scopes'] ?? [];
    foreach ($tokenScopes as $s) {
        if ($s === '*' || $s === $requiredScope) return;
        // Read wildcard
        if ($s === '*:read' && substr($requiredScope, -5) === ':read') return;
        // Per-resource superuser ('incidents' satisfies 'incidents:write')
        if (strpos($requiredScope, ':') !== false) {
            $parts = explode(':', $requiredScope, 2);
            if ($s === $parts[0] || $s === $parts[0] . ':*') return;
        }
    }
    ext_api_error('forbidden_scope', 403, ['required' => $requiredScope, 'token_scopes' => $tokenScopes]);
}

// ── Response envelopes ─────────────────────────────────────────

/**
 * Success envelope. Adds request_id, api_version. Calls json_response()
 * if available (consistent with the rest of NewUI's JSON output), else
 * emits the JSON directly.
 */
function ext_api_response($data, int $http = 200): void {
    $payload = [
        'ok'          => true,
        'api_version' => 'v1',
        'request_id'  => $GLOBALS['__ext_api_request_id'] ?? null,
        'data'        => $data,
    ];
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/**
 * Error envelope. Standardized shape across all external errors.
 * Logs the failure to the audit log if token resolution already
 * succeeded (so admins can see auth failures).
 */
function ext_api_error(string $code, int $http = 400, array $extra = []): void {
    $payload = array_merge([
        'ok'          => false,
        'api_version' => 'v1',
        'request_id'  => $GLOBALS['__ext_api_request_id'] ?? null,
        'error'       => $code,
    ], $extra);
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    if ($http === 429 && !empty($extra['retry_after'])) {
        header('Retry-After: ' . (int) $extra['retry_after']);
    }
    echo json_encode($payload);
    exit;
}

/**
 * Standardized 500-response helper for DB exceptions. Logs the raw
 * exception (with request_id for correlation) to PHP's error log, then
 * emits a SCRUBBED error envelope to the API caller — no $e->getMessage(),
 * no schema names, no row counts. The caller gets the request_id so a
 * support request can be cross-referenced to the server log.
 *
 * 2026-06-28 security audit (subagent finding #1): the previous pattern
 * was `ext_api_error('db_error', 500, ['message' => $e->getMessage()])`
 * which leaked SQL state, table names, column names, and sometimes
 * parameter values to any authenticated token holder. Replaced
 * everywhere with this helper.
 */
function ext_api_db_error(string $stage = 'db_query', ?Throwable $e = null): void {
    $rid = $GLOBALS['__ext_api_request_id'] ?? 'no-rid';
    $tokenId = $GLOBALS['__ext_api_token_id'] ?? 0;
    if ($e !== null) {
        // Use PHP's error_log so it lands in Apache's error log alongside
        // other PHP warnings — admins already monitor that location.
        error_log(sprintf(
            '[ext_api rid=%s token=%d stage=%s] db error: %s',
            $rid, $tokenId, $stage, $e->getMessage()
        ));
    }
    ext_api_error('db_error', 500, ['stage' => $stage]);
}

/**
 * Companion to ext_api_db_error() — for non-DB internal failures
 * (file system, network, JSON parse mid-pipeline, etc.). Same scrubbing
 * behavior: log the exception, return a sanitized envelope.
 */
function ext_api_internal_error(string $stage = 'internal', ?Throwable $e = null): void {
    $rid = $GLOBALS['__ext_api_request_id'] ?? 'no-rid';
    $tokenId = $GLOBALS['__ext_api_token_id'] ?? 0;
    if ($e !== null) {
        error_log(sprintf(
            '[ext_api rid=%s token=%d stage=%s] internal error: %s',
            $rid, $tokenId, $stage, $e->getMessage()
        ));
    }
    ext_api_error('internal_error', 500, ['stage' => $stage]);
}

// ── Internal helpers ───────────────────────────────────────────

function _ext_api_setting(string $name, $default = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            [$name]
        );
        return $v !== null ? $v : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Base62 encode binary bytes. Used in token generation. Standard
 * alphabet (0-9, A-Z, a-z) — URL-safe, copy-paste-safe.
 */
function _ext_api_base62(string $bytes): string {
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $num = '';
    foreach (str_split($bytes) as $b) {
        $num .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    }
    // Convert binary string to base62 via gmp/bcmath if available; fall
    // back to a simpler chunked approach
    $out = '';
    if (function_exists('gmp_init')) {
        $gmp = gmp_init($num, 2);
        while (gmp_cmp($gmp, 0) > 0) {
            $r = gmp_intval(gmp_mod($gmp, 62));
            $out = $alphabet[$r] . $out;
            $gmp = gmp_div_q($gmp, 62);
        }
        return $out ?: '0';
    }
    // Fallback: chunk-based encoding — slightly less efficient packing
    // but no external deps. 6 bits per char (= 64 chars-of-alphabet
    // tolerance) so we group bits 6-by-6.
    for ($i = 0; $i + 6 <= strlen($num); $i += 6) {
        $chunk = bindec(substr($num, $i, 6));
        $out .= $alphabet[$chunk % 62];
    }
    return $out;
}

/**
 * IP-vs-CIDR match. Supports both IPv4 and IPv6.
 */
function _ext_api_cidr_match(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr; // bare IP comparison
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;
    $ipBin = @inet_pton($ip);
    $subBin = @inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) return false;
    $bytes = (int) ceil($bits / 8);
    $remainder = $bits % 8;
    if (substr($ipBin, 0, $bytes - ($remainder ? 1 : 0)) !==
        substr($subBin, 0, $bytes - ($remainder ? 1 : 0))) return false;
    if ($remainder === 0) return true;
    $mask = chr((0xff << (8 - $remainder)) & 0xff);
    return (ord($ipBin[$bytes - 1]) & ord($mask)) ===
           (ord($subBin[$bytes - 1]) & ord($mask));
}
