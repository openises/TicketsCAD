<?php
/**
 * NewUI v4.0 API — DMR WebSocket Proxy Auth Token
 *
 * GET /api/dmr-token.php[?channel=<dmr_channels.id>]
 *
 * Returns a short-lived (2 min, single-use) token the browser sends
 * to the DMR proxy at wss://host/dmr-ws as the first WS message.
 * Same model as zello-token.php / zello_ws_tokens.
 *
 * Response:
 *   { "token": "...", "user": "ejosterberg", "user_id": 132,
 *     "channel_id": 3, "proxy_path": "/dmr-ws" }
 *
 * RBAC: action.dmr_receive OR action.dmr_transmit (you can listen
 * without transmitting). Issued at most every 2 minutes per token,
 * but the same authenticated user can request as many fresh tokens
 * as they need (one per browser tab is typical).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$rbacOk = function_exists('rbac_can') && (
    rbac_can('action.dmr_receive') || rbac_can('action.dmr_transmit')
);
if (!is_admin() && !$rbacOk) {
    json_error('Missing required permission: action.dmr_receive or action.dmr_transmit', 403);
}

$prefix    = $GLOBALS['db_prefix'] ?? '';
$channelId = (int) ($_GET['channel'] ?? 0);
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$user      = (string) ($_SESSION['user'] ?? 'unknown');
$level     = (int) ($_SESSION['level'] ?? 99);

if ($userId <= 0) {
    json_error('No authenticated user in session', 401);
}

$token = bin2hex(random_bytes(32));

try {
    // Purge expired tokens
    db_query(
        "DELETE FROM `{$prefix}dmr_ws_tokens` WHERE `created` < DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );

    db_query(
        "INSERT INTO `{$prefix}dmr_ws_tokens`
            (`token`, `user_id`, `user`, `user_level`, `channel_id`, `created`)
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$token, $userId, $user, $level, $channelId > 0 ? $channelId : null]
    );
} catch (Exception $e) {
    json_error('Failed to create auth token: ' . $e->getMessage(), 500);
}

json_response([
    'token'      => $token,
    'user'       => $user,
    'user_id'    => $userId,
    'level'      => $level,
    'channel_id' => $channelId > 0 ? $channelId : null,
    'proxy_path' => '/dmr-ws',
    'expires_in' => 120,
]);
