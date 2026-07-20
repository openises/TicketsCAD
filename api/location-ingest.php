<?php
/**
 * Phase 89 — Location ingest admin API.
 *
 * Three things, one endpoint:
 *
 *   GET  /api/location-ingest.php?action=recent[&provider=traccar][&limit=50]
 *       Last N reports across all providers (or one). Used by the
 *       "Recent Reports" panel under Config → Location Ingest so
 *       operators can verify ingest is working without dropping to
 *       a shell.
 *
 *   GET  /api/location-ingest.php?action=tokens
 *       List all per-device ingest tokens (NOT including the secret).
 *
 *   POST /api/location-ingest.php?action=mint_token
 *       Body: {label, provider_code?, device_unique_id?, notes?, csrf_token}
 *       Mints a new token, returns the raw value ONCE — never recoverable.
 *
 *   POST /api/location-ingest.php?action=revoke_token
 *       Body: {id, csrf_token}
 *       Sets revoked_at. The token row stays for audit.
 *
 * Auth: action.manage_ingest_tokens (or legacy admin).
 *
 * The recent-reports endpoint deliberately does NOT include raw_data
 * by default — that field can contain device-side secrets, IMEIs, or
 * GPRMC sentences. Pass &include_raw=1 to see it (admin only).
 */

require_once __DIR__ . '/../config.php';

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function _li_can_manage(): bool {
    if (is_admin()) return true;
    if (function_exists('rbac_can')) {
        return rbac_can('action.manage_ingest_tokens') || rbac_can('action.manage_config');
    }
    return false;
}

if (!_li_can_manage()) {
    json_error('Forbidden — manage_ingest_tokens permission required', 403);
}

// CSRF on writes — matches the convention in api/location.php.
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

$action = $_GET['action'] ?? ($input['action'] ?? '');

// ═══════════════════════════════════════════════════════════════
//  GET ?action=recent — latest N location_reports rows
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'recent') {
    $limit       = max(1, min(500, (int) ($_GET['limit'] ?? 50)));
    $providerArg = trim($_GET['provider'] ?? '');
    $includeRaw  = !empty($_GET['include_raw']);

    $where  = '';
    $params = [];
    if ($providerArg !== '') {
        $where = "WHERE lp.code = ?";
        $params[] = $providerArg;
    }

    $rawCol = $includeRaw ? ", lr.raw_data" : "";

    try {
        $rows = db_fetch_all(
            "SELECT lr.id, lr.unit_identifier, lr.lat, lr.lng, lr.altitude,
                    lr.speed, lr.heading, lr.accuracy, lr.battery,
                    lr.reported_at, lr.received_at, lr.auth_token_id,
                    lp.code  AS provider_code,
                    lp.name  AS provider_name,
                    lp.icon  AS provider_icon,
                    lp.color AS provider_color,
                    TIMESTAMPDIFF(SECOND, lr.received_at, NOW()) AS age_seconds,
                    (SELECT label FROM `{$prefix}location_ingest_tokens` t
                       WHERE t.id = lr.auth_token_id LIMIT 1) AS token_label
                    {$rawCol}
               FROM `{$prefix}location_reports` lr
               JOIN `{$prefix}location_providers` lp ON lp.id = lr.provider_id
               {$where}
              ORDER BY lr.id DESC
              LIMIT {$limit}",
            $params
        );
    } catch (Exception $e) {
        $rows = [];
    }

    json_response(['reports' => $rows, 'count' => count($rows)]);
}

// ═══════════════════════════════════════════════════════════════
//  GET ?action=tokens — list per-device ingest tokens
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'tokens') {
    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.label, t.provider_id, t.device_unique_id,
                    t.created_at, t.created_by, t.last_used_at, t.last_used_ip,
                    t.revoked_at, t.notes,
                    lp.code AS provider_code, lp.name AS provider_name,
                    (SELECT u.user FROM `{$prefix}user` u WHERE u.id = t.created_by LIMIT 1) AS created_by_user,
                    (SELECT COUNT(*) FROM `{$prefix}location_reports` r
                       WHERE r.auth_token_id = t.id) AS report_count
               FROM `{$prefix}location_ingest_tokens` t
          LEFT JOIN `{$prefix}location_providers` lp ON lp.id = t.provider_id
              ORDER BY t.revoked_at IS NULL DESC, t.id DESC"
        );
    } catch (Exception $e) {
        // Table not yet created — return empty so the panel still renders
        $rows = [];
    }
    json_response(['tokens' => $rows, 'count' => count($rows)]);
}

// ═══════════════════════════════════════════════════════════════
//  POST ?action=mint_token — generate a new per-device token
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'mint_token') {
    $label = trim($input['label'] ?? '');
    if ($label === '' || strlen($label) > 120) {
        json_error('label is required (max 120 chars)', 400);
    }

    $providerId = null;
    $providerCode = trim($input['provider_code'] ?? '');
    if ($providerCode !== '') {
        try {
            $p = db_fetch_one(
                "SELECT id FROM `{$prefix}location_providers` WHERE code = ?",
                [$providerCode]
            );
        } catch (Exception $e) { $p = null; }
        if (!$p) {
            json_error("Unknown provider_code: '{$providerCode}'", 404);
        }
        $providerId = (int) $p['id'];
    }

    $deviceId = trim($input['device_unique_id'] ?? '');
    if ($deviceId === '') $deviceId = null;
    elseif (strlen($deviceId) > 120) {
        json_error('device_unique_id must be 120 chars or less', 400);
    }

    $notes = trim($input['notes'] ?? '');
    if (strlen($notes) > 255) {
        json_error('notes must be 255 chars or less', 400);
    }
    if ($notes === '') $notes = null;

    // Generate the token: 32 bytes from CSPRNG, base64url-encoded so it
    // travels safely in URLs and Authorization: Bearer headers. The
    // RAW value is what the device uses; only the sha256 hash is stored.
    try {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    } catch (Exception $e) {
        json_error('Failed to generate token: ' . $e->getMessage(), 500);
    }
    $hash = hash('sha256', $raw);

    $createdBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;

    try {
        db_query(
            "INSERT INTO `{$prefix}location_ingest_tokens`
                (label, secret_hash, provider_id, device_unique_id, created_by, notes)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$label, $hash, $providerId, $deviceId, $createdBy, $notes]
        );
        $tokenId = (int) db_insert_id();
    } catch (Exception $e) {
        // Hash collision (vanishingly rare) shows up as a duplicate-key
        // error — caller can retry.
        json_error('Mint failed: ' . $e->getMessage(), 500);
    }

    audit_log('config', 'create', 'location_ingest_token', $tokenId,
        "Minted location ingest token #{$tokenId} '{$label}'", [
            'provider_code'   => $providerCode ?: null,
            'device_unique_id' => $deviceId,
        ]
    );

    json_response([
        'saved'   => true,
        'id'      => $tokenId,
        'token'   => $raw, // shown ONCE — operator must copy now
        'label'   => $label,
        'warning' => 'This token will not be shown again. Copy it now.',
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST ?action=revoke_token — mark a token revoked (soft delete)
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'revoke_token') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) {
        json_error('id is required', 400);
    }
    try {
        // Find first so we can include the label in the audit log.
        $row = db_fetch_one(
            "SELECT id, label, revoked_at FROM `{$prefix}location_ingest_tokens` WHERE id = ?",
            [$id]
        );
        if (!$row) {
            json_error('Token not found', 404);
        }
        if ($row['revoked_at']) {
            json_response(['saved' => true, 'note' => 'already revoked']);
        }
        db_query(
            "UPDATE `{$prefix}location_ingest_tokens` SET revoked_at = NOW() WHERE id = ?",
            [$id]
        );
        audit_log('config', 'update', 'location_ingest_token', $id,
            "Revoked location ingest token #{$id} '{$row['label']}'");
        json_response(['saved' => true]);
    } catch (Exception $e) {
        json_error('Revoke failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action: ' . $action, 400);
