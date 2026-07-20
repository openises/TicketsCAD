<?php
/**
 * Phase 99a #14 follow-on (2026-06-28) — FCC license attestation endpoint.
 *
 *   POST /api/aprs-license-accept.php
 *     Body: {} (no payload — the act of POSTing IS the acceptance)
 *
 *   Response:
 *     { ok: true, accepted_at: "YYYY-MM-DD HH:MM:SS", accepted_by: "username" }
 *
 * Records the admin's attestation that they hold a current FCC
 * Amateur Radio license (or equivalent in their jurisdiction). This
 * gates the APRS-IS sending configuration fields on the Settings
 * panel — until accepted, the callsign/passcode/server fields stay
 * disabled.
 *
 * The settings table gets two rows:
 *   aprs_license_attestation_accepted_at  ('2026-06-28 22:15:00')
 *   aprs_license_attestation_accepted_by  ('ejosterberg')
 *
 * audit_log captures the event with the user's IP for legal-trail
 * purposes. There's no "revoke acceptance" — if the licensee
 * changes, the new admin re-accepts.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF
if (function_exists('csrf_check')) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($body['_csrf'] ?? ($body['csrf_token'] ?? ''));
    if (!csrf_check($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
}

$prefix    = $GLOBALS['db_prefix'] ?? '';
$userName  = (string) ($_SESSION['user'] ?? 'unknown');
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$now       = date('Y-m-d H:i:s');
$ipAddr    = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    // Upsert both settings rows.
    foreach ([
        'aprs_license_attestation_accepted_at' => $now,
        'aprs_license_attestation_accepted_by' => $userName,
    ] as $key => $val) {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$key, $val]
        );
    }

    // Audit-log entry — legal trail. Captures user + IP + UA.
    if (function_exists('audit_log')) {
        audit_log(
            'settings|aprs|license_attestation',
            "FCC Amateur Radio license attestation accepted by {$userName} (user_id={$userId})",
            [
                'user_id'    => $userId,
                'user_name'  => $userName,
                'ip'         => $ipAddr,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'accepted_at'=> $now,
            ]
        );
    }

    echo json_encode([
        'ok'           => true,
        'accepted_at'  => $now,
        'accepted_by'  => $userName,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Acceptance failed: ' . $e->getMessage()]);
}
