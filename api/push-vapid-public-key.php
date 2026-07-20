<?php
/**
 * GET /api/push-vapid-public-key.php
 *
 * Returns the Web Push VAPID public key. The browser needs this to
 * register its subscription (it's part of the applicationServerKey
 * parameter passed to PushManager.subscribe()).
 *
 * No auth required — the public key is, by definition, public.
 * (Without the matching private key it can't be used to send
 * pushes, only to receive subscription registrations.)
 *
 * If push is disabled or VAPID isn't configured, returns 503 so the
 * client knows not to try.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $enabled = db_fetch_value(
        "SELECT value FROM `{$prefix}settings` WHERE name = 'push_enabled' LIMIT 1"
    );
    $pubKey = db_fetch_value(
        "SELECT value FROM `{$prefix}settings` WHERE name = 'push_vapid_public_key' LIMIT 1"
    );
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'settings_unavailable']);
    exit;
}

if ((string) $enabled !== '1' || !$pubKey) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'push_disabled']);
    exit;
}

echo json_encode(['ok' => true, 'public_key' => (string) $pubKey]);
