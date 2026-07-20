<?php
/**
 * NewUI v4.0 API — External API Tokens (admin-only management).
 *
 * Phase 94 Stage 6 — the admin UI for minting / listing / revoking
 * external API bearer tokens. Pairs with tools/mint_external_api_token.php
 * (CLI variant). The Settings UI mounts this for a no-SSH path.
 *
 * GET    /api/external-api-tokens.php                    → list (active + revoked)
 * GET    /api/external-api-tokens.php?id=N               → detail (NO raw token; that's gone)
 * GET    /api/external-api-tokens.php?id=N&audit=1       → recent audit_log activity for this token
 * POST   /api/external-api-tokens.php                    → mint a new token
 *        Body: {name, scopes:[], user_id, description?, ip_allowlist:[]?, expires_at?, rate_limit_per_hour?}
 *        Response includes the RAW TOKEN ONCE; admin MUST capture immediately.
 * POST   /api/external-api-tokens.php  action=revoke     → revoke {id, reason}
 *        Sets revoked_at + revoked_by + revoked_reason on the row;
 *        token stops working immediately (bearer resolver checks revoked_at).
 *
 * Admin-gated by `action.manage_external_api_tokens` permission (seeded
 * by sql/run_phase94_external_api.php to Super Admin + Org Admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/external-auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// RBAC: per-action.manage_external_api_tokens
if (!rbac_can('action.manage_external_api_tokens') && !is_admin()) {
    json_error('Admin access required (action.manage_external_api_tokens)', 403);
}

// CSRF on writes
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET — list / detail / per-token audit activity
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) json_error('Invalid id', 400);

        try {
            $row = db_fetch_one(
                "SELECT t.id, t.name, t.description, t.token_prefix,
                        t.scopes_json, t.ip_allowlist_json, t.user_id,
                        t.rate_limit_per_hour, t.created_by, t.created_at,
                        t.expires_at, t.last_used_at, t.last_used_ip,
                        t.revoked_at, t.revoked_by, t.revoked_reason,
                        u.user AS user_name, cb.user AS created_by_name,
                        rb.user AS revoked_by_name
                 FROM `{$prefix}external_api_tokens` t
                 LEFT JOIN `{$prefix}user` u  ON u.id  = t.user_id
                 LEFT JOIN `{$prefix}user` cb ON cb.id = t.created_by
                 LEFT JOIN `{$prefix}user` rb ON rb.id = t.revoked_by
                 WHERE t.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            json_error('Lookup failed: ' . $e->getMessage(), 500);
        }
        if (!$row) json_error('Token not found', 404);

        $row['scopes']       = @json_decode($row['scopes_json'] ?? '[]', true) ?: [];
        $row['ip_allowlist'] = @json_decode($row['ip_allowlist_json'] ?? 'null', true);
        unset($row['scopes_json'], $row['ip_allowlist_json']);

        // Optional: recent audit activity for this token (last 50 rows)
        if (!empty($_GET['audit'])) {
            try {
                $auditRows = db_fetch_all(
                    "SELECT id, event_time, category, activity, target_type, target_id, summary
                     FROM " . db_table('newui_audit_log') . "
                     WHERE JSON_EXTRACT(details, '$.token_id') = ?
                     ORDER BY id DESC LIMIT 50",
                    [$id]
                );
                $row['recent_activity'] = $auditRows;
            } catch (Exception $e) {
                $row['recent_activity'] = [];
            }
        }

        json_response(['token' => $row]);
    }

    // List — both active + revoked, ordered by status then created
    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.name, t.description, t.token_prefix,
                    t.scopes_json, t.user_id, t.rate_limit_per_hour,
                    t.created_at, t.expires_at, t.last_used_at,
                    t.last_used_ip, t.revoked_at, t.revoked_reason,
                    u.user AS user_name
             FROM `{$prefix}external_api_tokens` t
             LEFT JOIN `{$prefix}user` u ON u.id = t.user_id
             ORDER BY (t.revoked_at IS NOT NULL) ASC, t.created_at DESC"
        );
        foreach ($rows as &$r) {
            $r['scopes'] = @json_decode($r['scopes_json'] ?? '[]', true) ?: [];
            unset($r['scopes_json']);
        }
        unset($r);
    } catch (Exception $e) {
        json_error('List failed: ' . $e->getMessage(), 500);
    }
    json_response(['tokens' => $rows]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — mint or revoke
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $action = trim($input['action'] ?? 'mint');

    // ── REVOKE ────────────────────────────────────────────────
    if ($action === 'revoke') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('Missing id', 400);
        $reason = trim((string) ($input['reason'] ?? ''));

        try {
            $existing = db_fetch_one(
                "SELECT id, name, revoked_at FROM `{$prefix}external_api_tokens` WHERE id = ?",
                [$id]
            );
        } catch (Exception $e) {
            json_error('Lookup failed: ' . $e->getMessage(), 500);
        }
        if (!$existing) json_error('Token not found', 404);
        if (!empty($existing['revoked_at'])) json_error('Already revoked', 400);

        try {
            db_query(
                "UPDATE `{$prefix}external_api_tokens`
                 SET revoked_at = NOW(), revoked_by = ?, revoked_reason = ?
                 WHERE id = ?",
                [(int) ($_SESSION['user_id'] ?? 0), $reason, $id]
            );
        } catch (Exception $e) {
            json_error('Revoke failed: ' . $e->getMessage(), 500);
        }

        audit_log('config', 'revoke', 'external_api_token', $id,
            "Revoked external API token '{$existing['name']}'" . ($reason ? " — {$reason}" : ''));

        json_response(['revoked' => true, 'id' => $id]);
    }

    // ── MINT (default) ────────────────────────────────────────
    $name        = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $scopes      = $input['scopes'] ?? [];
    $userId      = (int) ($input['user_id'] ?? ($_SESSION['user_id'] ?? 0));
    $ipAllow     = $input['ip_allowlist'] ?? null;
    $expiresAt   = trim((string) ($input['expires_at'] ?? ''));
    $rateLimit   = isset($input['rate_limit_per_hour']) ? (int) $input['rate_limit_per_hour'] : null;

    if ($name === '') json_error('Token name is required', 400);
    if (!is_array($scopes) || empty($scopes)) {
        json_error('At least one scope is required', 400);
    }
    if ($userId <= 0) json_error('Valid user_id is required', 400);

    // 2026-06-28 security audit fix #2 — privilege escalation clamp.
    //
    // Previously this endpoint accepted any user_id from the caller, so
    // an Org Admin (who has action.manage_external_api_tokens by seed)
    // could mint a '*'-scope token bound to the Super Admin's user_id
    // and then call any external endpoint AS the Super Admin. Same
    // class of bug as the classic "create an admin user from a
    // non-admin form".
    //
    // Two-layer fix:
    //   (a) The caller must be is_admin() to mint a token for ANY
    //       user_id other than their own. Non-admins are clamped to
    //       binding the token to themselves.
    //   (b) The requested scopes are clamped to the BINDING user's
    //       RBAC envelope, not the CALLER's — the token will run as
    //       that user, so giving it scopes that user can't exercise
    //       is meaningless AND attempts to fan out to scopes the
    //       caller couldn't normally grant.
    //
    // The 'star' scope ['*'] stays admin-only — non-admins minting a
    // token for themselves still cannot grant a wildcard scope.
    $callerUserId = (int) ($_SESSION['user_id'] ?? 0);
    $callerIsAdmin = function_exists('is_admin') && is_admin();

    if ($userId !== $callerUserId && !$callerIsAdmin) {
        json_error('Cannot mint a token bound to another user — admin permission required', 403);
    }
    if (in_array('*', $scopes, true) && !$callerIsAdmin) {
        json_error('Wildcard scope (*) requires admin permission', 403);
    }

    // Verify the binding user exists
    try {
        $boundUser = db_fetch_one(
            "SELECT id, user FROM `{$prefix}user` WHERE id = ?", [$userId]
        );
    } catch (Exception $e) {
        json_error('User lookup failed: database error', 500);
        error_log('[external-api-tokens MINT] user lookup failed: ' . $e->getMessage());
    }
    if (!$boundUser) json_error('Binding user not found', 404);

    $opts = [
        'name'        => $name,
        'description' => $description ?: null,
    ];
    if (is_array($ipAllow) && !empty($ipAllow)) {
        $opts['ip_allowlist'] = array_values($ipAllow);
    }
    if ($expiresAt !== '') $opts['expires_at'] = $expiresAt;
    if ($rateLimit !== null && $rateLimit > 0) {
        $opts['rate_limit_per_hour'] = $rateLimit;
    }

    try {
        $result = ext_api_mint_token($userId, $scopes, (int) ($_SESSION['user_id'] ?? 0), $opts);
    } catch (Exception $e) {
        json_error('Mint failed: ' . $e->getMessage(), 500);
    }

    audit_log('config', 'create', 'external_api_token', $result['id'],
        "Minted external API token '{$name}' bound to user {$boundUser['user']}",
        ['scopes' => $scopes, 'bound_user_id' => $userId]);

    // CRITICAL: the raw token is shown to the admin ONCE and then never
    // returned again from any endpoint. Front-end MUST surface this in
    // a copy-once modal with the "will never be shown again" warning.
    json_response([
        'id'           => $result['id'],
        'name'         => $name,
        'token_prefix' => $result['token_prefix'],
        'raw_token'    => $result['raw_token'],
        'bound_to'     => ['id' => $userId, 'user' => $boundUser['user']],
        'scopes'       => array_values($scopes),
        'message'      => 'Copy the raw_token now — it will never be shown again.',
    ]);
}

json_error('Method not allowed', 405);
