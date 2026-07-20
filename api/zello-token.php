<?php
/**
 * NewUI v4.0 API - Zello WebSocket Auth Token
 *
 * GET /api/zello-token.php
 *   Returns a short-lived token the browser sends to the proxy for auth.
 *   Token is stored in the DB so the proxy can verify it without needing
 *   access to PHP session files.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$token  = bin2hex(random_bytes(32));
$user   = $_SESSION['user'] ?? 'unknown';
$level  = (int) ($_SESSION['level'] ?? 99);

try {
    // Purge expired tokens (older than 2 minutes)
    db_query(
        "DELETE FROM `{$prefix}zello_ws_tokens` WHERE `created` < DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );

    // Insert fresh token
    db_query(
        "INSERT INTO `{$prefix}zello_ws_tokens` (`token`, `user`, `user_level`, `created`) VALUES (?, ?, ?, NOW())",
        [$token, $user, $level]
    );
} catch (Exception $e) {
    json_error('Failed to create auth token: ' . $e->getMessage(), 500);
}

// Zello multi-channel (Phase 112-sibling, 2026-07-05) — hand the browser the
// configured channel list so the widget can label RX traffic per channel and
// (later) render a channel bank. Dispatch channel first, then the comma-list of
// extras. Zello Work only; consumer Zello is single-channel by design.
$zSetting = function ($k) {
    if (function_exists('get_setting')) {
        $v = get_setting($k, '');
        if ($v !== null && $v !== '') return (string) $v;
    }
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $v = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = ?", [$k]);
        return $v !== null ? (string) $v : '';
    } catch (Exception $e) { return ''; }
};
$dispatchChannel = trim($zSetting('zello_dispatch_channel'));
$channels = [];
if ($dispatchChannel !== '') { $channels[] = $dispatchChannel; }
foreach (explode(',', $zSetting('zello_extra_channels')) as $c) {
    $c = trim($c);
    if ($c !== '' && !in_array($c, $channels, true)) { $channels[] = $c; }
}

json_response([
    'token'            => $token,
    'user'             => $user,
    'level'            => $level,
    'dispatch_channel' => $dispatchChannel,
    'channels'         => $channels,
]);
