<?php
/**
 * POST   /api/push-subscribe.php   — store a subscription
 * DELETE /api/push-subscribe.php   — remove a subscription by endpoint
 *
 * Receives a PushSubscription (the browser's serialized output of
 * registration.pushManager.subscribe()) and stores it in
 * push_subscriptions keyed on (user_id, endpoint).
 *
 * POST body shape:
 *   {
 *     endpoint: 'https://fcm.googleapis.com/fcm/send/...',
 *     keys: { p256dh: 'base64url...', auth: 'base64url...' },
 *     device_label?: 'Eric iPhone'
 *   }
 *
 * Requires an authenticated session — the subscription is bound to
 * the logged-in user. CSRF token enforced on writes (matches the
 * rest of the app's posture).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = $raw ? @json_decode($raw, true) : null;
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json_body']);
    exit;
}

// CSRF
$csrf = (string) ($input['csrf_token'] ?? '');
if (!csrf_verify($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

if ($method === 'POST') {
    $endpoint = trim((string) ($input['endpoint'] ?? ''));
    $p256dh   = trim((string) ($input['keys']['p256dh'] ?? ''));
    $auth     = trim((string) ($input['keys']['auth'] ?? ''));
    $deviceLabel = trim((string) ($input['device_label'] ?? ''));
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_subscription_fields']);
        exit;
    }

    // Phase 99t (a beta tester beta 2026-06-29) — capture a notification
    // filter so mobile (field-responder) sessions don't get spammed
    // with every dispatcher event. Resolution order:
    //   1. caller-supplied filters_json     (explicit)
    //   2. caller-supplied source = 'mobile' → default {"scope":"assigned"}
    //   3. caller-supplied source = 'desktop' → null (= everything)
    //   4. no source → null  (legacy / desktop default)
    $filtersJson = null;
    if (isset($input['filters_json'])) {
        // Caller explicitly set filters — accept as JSON string OR array.
        $f = is_array($input['filters_json'])
            ? $input['filters_json']
            : json_decode((string) $input['filters_json'], true);
        if (is_array($f)) $filtersJson = json_encode($f);
    } elseif (($input['source'] ?? '') === 'mobile') {
        // mobile.php's default: see only events about incidents the
        // user's responder is currently assigned to.
        $filtersJson = json_encode(['scope' => 'assigned']);
    }

    try {
        // Upsert: same user re-subscribing the same endpoint replaces.
        // The UNIQUE KEY (user_id, endpoint(255)) makes ON DUPLICATE
        // KEY UPDATE the right idempotent path.
        db_query(
            "INSERT INTO `{$prefix}push_subscriptions`
             (`user_id`, `channel`, `endpoint`, `p256dh`, `auth`,
              `device_label`, `user_agent`, `filters_json`, `last_used_at`)
             VALUES (?, 'web', ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                `p256dh`     = VALUES(p256dh),
                `auth`       = VALUES(auth),
                `device_label` = VALUES(device_label),
                `user_agent` = VALUES(user_agent),
                `filters_json` = VALUES(filters_json),
                `last_used_at` = NOW(),
                `last_error` = NULL",
            [$userId, $endpoint, $p256dh, $auth,
             $deviceLabel !== '' ? $deviceLabel : null,
             $userAgent !== '' ? $userAgent : null,
             $filtersJson]
        );
        $subId = (int) db_insert_id();
    } catch (Exception $e) {
        error_log('[push-subscribe] insert failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_error']);
        exit;
    }

    echo json_encode(['ok' => true, 'subscription_id' => $subId]);
    exit;
}

if ($method === 'DELETE') {
    $endpoint = trim((string) ($input['endpoint'] ?? ''));
    if ($endpoint === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_endpoint']);
        exit;
    }
    try {
        db_query(
            "DELETE FROM `{$prefix}push_subscriptions`
             WHERE user_id = ? AND endpoint = ?",
            [$userId, $endpoint]
        );
    } catch (Exception $e) {
        error_log('[push-subscribe] delete failed: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
