<?php
/**
 * Admin API for Phase 96 Web Push.
 *
 * GET  /api/push-admin.php
 *   Returns the current push config + a summary of subscribers.
 *   Public key is sent as-is (it's already public). Private key is
 *   NEVER returned — only a boolean flag indicating whether it's
 *   set.
 *
 * POST /api/push-admin.php?action=save
 *   Body: {push_enabled: '0'|'1', push_vapid_subject: 'mailto:...'}
 *   Saves the admin-tunable fields. The VAPID keypair is set via
 *   action=regenerate (NOT here — we don't want admins to be able
 *   to paste a key that doesn't match its public counterpart).
 *
 * POST /api/push-admin.php?action=regenerate
 *   Generates a fresh P-256 ECDSA VAPID keypair using minishlink/web-push
 *   and stores both halves. Existing subscriptions are NOT invalidated
 *   automatically (the keys themselves don't rotate per-subscription)
 *   but new browsers registering after a rotation will use the new
 *   public key. To force re-subscription of every browser, run the
 *   companion clear-subscriptions action (not yet implemented; v2
 *   polish).
 *
 * 2026-06-28 — added so admins can manage Web Push from Settings
 * instead of the tools/generate_vapid_keys.php CLI + raw SQL UPDATE.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function _push_get_setting(string $key): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name = ? LIMIT 1",
            [$key]
        );
        return (string) ($v ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function _push_save_setting(string $key, string $value): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    // Upsert via REPLACE — the settings table has a unique key on `name`.
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

if ($method === 'GET') {
    try {
        $publicKey = _push_get_setting('push_vapid_public_key');
        $privateKey = _push_get_setting('push_vapid_private_key');
        $subject   = _push_get_setting('push_vapid_subject');
        $enabled   = _push_get_setting('push_enabled');

        $subCount  = 0;
        $userCount = 0;
        try {
            $row = db_fetch_one(
                "SELECT COUNT(*) AS sub_count,
                        COUNT(DISTINCT user_id) AS user_count
                 FROM `{$prefix}push_subscriptions`"
            );
            $subCount  = (int) ($row['sub_count']  ?? 0);
            $userCount = (int) ($row['user_count'] ?? 0);
        } catch (Throwable $e) {
            // Table might not exist on a brand-new install — that's fine.
        }

        echo json_encode([
            'push_enabled'           => $enabled,
            'push_vapid_subject'     => $subject,
            'push_vapid_public_key'  => $publicKey,
            // Don't ever send the private key. Just say whether it's set.
            'push_vapid_private_set' => ($privateKey !== ''),
            'subscriptions'          => $subCount,
            'subscribed_users'       => $userCount,
            'keys_configured'        => ($publicKey !== '' && $privateKey !== ''),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read push config: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];

    // CSRF check using the shared helper (auth.php loaded it).
    // CSRF. Call csrf_verify() directly and unguarded: the previous
    // `if (function_exists('csrf_check'))` named a function that exists NOWHERE
    // in the codebase, so the guard was always false and this check never ran
    // on any request. A missing CSRF helper must fail loudly, not disable the
    // control silently. (Found 2026-07-28.)
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? '');
    if (!csrf_verify($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }

    if ($action === 'save') {
        try {
            $enabled = isset($body['push_enabled']) && (string) $body['push_enabled'] === '1' ? '1' : '0';
            $subject = trim((string) ($body['push_vapid_subject'] ?? ''));

            // Subject MUST be mailto: or https:// per RFC 8292.
            if ($subject !== '' &&
                !preg_match('#^(mailto:[^\s@]+@[^\s@]+\.[^\s@]+|https?://[^\s]+)$#i', $subject)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'push_vapid_subject must be a mailto: address or https:// URL per RFC 8292'
                ]);
                exit;
            }

            _push_save_setting('push_enabled', $enabled);
            _push_save_setting('push_vapid_subject', $subject);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'test') {
        // 2026-06-28 — send a test notification to ALL of the calling
        // admin's own push subscriptions. Useful right after first-time
        // VAPID setup to confirm the keypair + service worker + browser
        // permission stack actually works end-to-end.
        require_once __DIR__ . '/../inc/push.php';
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['error' => 'No active session']);
            exit;
        }
        try {
            $subs = db_fetch_all(
                "SELECT id, endpoint FROM `{$prefix}push_subscriptions` WHERE user_id = ?",
                [$userId]
            );
            if (empty($subs)) {
                echo json_encode([
                    'ok' => false,
                    'sent' => 0,
                    'error' => 'No push subscriptions registered for your account. Open this page in a browser that supports Web Push (Chrome/Firefox/Edge desktop, Chrome Android, iOS 16.4+ as an installed PWA), then grant push permission when prompted.'
                ]);
                exit;
            }
            $sent = 0;
            $failed = 0;
            $errors = [];
            foreach ($subs as $sub) {
                $result = push_send_test(
                    (int) $sub['id'],
                    'TicketsCAD Test Push',
                    'If you see this, Web Push is working. Sent ' . date('H:i:s')
                );
                if (!empty($result['ok'])) {
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = ($result['error'] ?? 'unknown') . ': ' . ($result['reason'] ?? '');
                }
            }
            echo json_encode([
                'ok' => $sent > 0,
                'sent' => $sent,
                'failed' => $failed,
                'total' => count($subs),
                'errors' => $errors,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Test send failed: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'regenerate') {
        // Generate a new VAPID keypair. Requires the minishlink/web-push lib.
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Web Push library not installed. Run composer install in the project root.'
            ]);
            exit;
        }
        require_once __DIR__ . '/../inc/vendor-autoload.php';
        newui_require_vendor_autoload();

        if (!class_exists('Minishlink\\WebPush\\VAPID')) {
            http_response_code(500);
            echo json_encode([
                'error' => 'minishlink/web-push class not found. Reinstall vendor dir with composer install.'
            ]);
            exit;
        }

        // GH TicketsCAD#8: the old code called the library directly and echoed
        // whatever it threw, which on stock Windows PHP is "Unable to create
        // the key" — accurate and unactionable, while openssl_error_string()
        // was sitting right there saying "configuration file routines::no such
        // file". vapid_generate_keypair() prefers the library exactly as
        // before, falls back to generating with an explicit openssl.cnf when
        // the host cannot find its own, and only if BOTH fail returns a message
        // that names the cause and the repair.
        require_once __DIR__ . '/../inc/vapid-keygen.php';

        try {
            $keys = vapid_generate_keypair();
            _push_save_setting('push_vapid_public_key',  $keys['publicKey']);
            _push_save_setting('push_vapid_private_key', $keys['privateKey']);

            // GH#31: a valid keypair does not mean sends will work — the
            // library generates a SEPARATE key for every message, through a
            // call the GH#8 fallback never touched. Test that directly rather
            // than reporting success on half the picture, the same way this
            // endpoint already prefers a round-trip check over trusting byte
            // lengths.
            $selftest = vapid_encryption_selftest();

            $response = [
                'ok'                    => true,
                'push_vapid_public_key' => $keys['publicKey'],
                'send_capable'          => $selftest['ok'],
                'note'                  => 'Existing browser subscriptions remain valid; new registrations after this rotation use the new public key. Force-clear all subscriptions to require re-consent.'
            ];
            if (($keys['via'] ?? '') === 'openssl-direct') {
                $response['note'] .= ' This host\'s OpenSSL could not find its own'
                    . ' configuration file, so the key was generated using '
                    . ($keys['config'] ?? 'a located openssl.cnf') . '.';
            }
            if ($selftest['ok']) {
                $response['note'] .= ' Verified: a live test of the per-message'
                    . ' encryption step also succeeded, so push sends should work'
                    . ' on this host, not just key generation.';
            } else {
                // Do not let "Keypair configured" stand as the headline result
                // when we have direct evidence sends will fail. This is the
                // exact gap GH#31 reported: generation succeeding read as the
                // whole answer, and the send failure surfaced later, from
                // vendored code, phrased differently, with nothing tying the
                // two together.
                $response['ok'] = false;
                $response['warning'] = 'Keypair generated successfully, but push notifications will NOT send on this host.';
                $response['error'] = vapid_send_capability_advice($selftest['error'] ?? 'unknown error');
            }
            echo json_encode($response);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
