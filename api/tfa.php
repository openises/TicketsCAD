<?php
/**
 * NewUI v4.0 API - Two-Factor Authentication Management
 *
 * Endpoints:
 *   GET                     — Check enrollment status for current user
 *   POST action=enroll      — Start enrollment (returns secret + QR URI + backup codes)
 *   POST action=confirm     — Verify first TOTP code to complete enrollment
 *   POST action=disable     — Disable 2FA (requires password + current TOTP code)
 *   POST action=regenerate  — Generate new backup codes (requires TOTP verification)
 *   POST action=admin_disable — Admin force-disable for another user (super only)
 *   POST action=save_settings — Save 2FA admin settings (admin only)
 *   GET  action=settings    — Get 2FA admin settings (admin only)
 *   GET  action=user_status — Get 2FA status for a specific user (admin only)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/tfa.php';
require_once __DIR__ . '/../inc/audit.php';

// Suppress display_errors to keep JSON clean
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

// ── CSRF on writes ──────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
} else {
    $input = [];
}

$action = $input['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════
//  GET — Current user 2FA status
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && ($action === '' || $action === 'status')) {
    $enrolled = tfa_is_enabled($current_user_id);
    $pending = tfa_has_pending_enrollment($current_user_id);
    $settings = tfa_get_settings();
    $required = tfa_is_required_for_user($current_user_id, $current_level);

    json_response([
        'enrolled'       => $enrolled,
        'pending'        => $pending,
        'required'       => $required,
        'global_enabled' => (bool) $settings['tfa_enabled'],
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  GET — Admin settings
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'settings') {
    if (!is_admin()) {
        json_error('Admin access required', 403);
    }
    json_response(tfa_get_settings());
}

// ═══════════════════════════════════════════════════════════════
//  GET — User 2FA status (admin)
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'user_status') {
    if (!is_admin()) {
        json_error('Admin access required', 403);
    }
    $userId = (int) ($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        json_error('user_id required');
    }

    $enrolled = tfa_is_enabled($userId);

    // Count remembered devices
    $deviceCount = 0;
    try {
        $deviceCount = (int) db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('tfa_remember_tokens')
            . " WHERE `user_id` = ? AND `expires_at` > NOW()",
            [$userId]
        );
    } catch (Exception $e) {}

    json_response([
        'user_id'      => $userId,
        'enrolled'     => $enrolled,
        'device_count' => $deviceCount,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Enroll (start)
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'enroll') {
    // Require password re-confirmation
    $password = $input['password'] ?? '';
    if ($password === '') {
        json_error('Password required to enable 2FA');
    }

    // Verify current password
    $userRow = db_fetch_one(
        "SELECT `passwd`, `user` FROM " . db_table('user') . " WHERE `id` = ? LIMIT 1",
        [$current_user_id]
    );
    if (!$userRow) {
        json_error('User not found', 404);
    }

    $authResult = verify_password($password, $userRow['passwd']);
    if (!$authResult['valid']) {
        json_error('Incorrect password');
    }

    // Check if already confirmed — must disable first
    if (tfa_is_enabled($current_user_id)) {
        json_error('2FA is already enabled. Disable it first to re-enroll.');
    }

    // If there's a pending (unconfirmed) enrollment, clear it so we can start fresh
    if (tfa_has_pending_enrollment($current_user_id)) {
        try {
            db_query("DELETE FROM " . db_table('user_tfa') . " WHERE `user_id` = ? AND `confirmed` = 0", [$current_user_id]);
        } catch (Exception $e) {}
    }

    $result = tfa_enroll($current_user_id, $userRow['user']);

    json_response([
        'success'      => true,
        'secret'       => $result['secret'],
        'uri'          => $result['uri'],
        'backup_codes' => $result['backup_codes'],
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Confirm enrollment
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'confirm') {
    $code = trim($input['code'] ?? '');
    if ($code === '') {
        json_error('Verification code required');
    }

    // Check for either a pending or confirmed enrollment row
    $hasRow = false;
    try {
        $row = db_fetch_one(
            "SELECT `id` FROM " . db_table('user_tfa') . " WHERE `user_id` = ? LIMIT 1",
            [$current_user_id]
        );
        $hasRow = ($row !== null);
    } catch (Exception $e) {}

    if (!$hasRow) {
        json_error('No pending 2FA enrollment found. Please start setup again.');
    }

    if (tfa_confirm_enroll($current_user_id, $code)) {
        // Clear the mandatory enrollment flag if it was set
        if (isset($_SESSION['tfa_enrollment_required'])) {
            unset($_SESSION['tfa_enrollment_required']);
        }
        audit_log('auth', 'tfa_enroll', 'user', $current_user_id,
            "2FA enrolled for user '{$current_user}'", null, AUDIT_HIGH);
        json_response(['success' => true, 'message' => 'Two-factor authentication enabled successfully.']);
    } else {
        json_error('Invalid verification code. Please try again.');
    }
}

// ═══════════════════════════════════════════════════════════════
//  POST — Disable 2FA
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'disable') {
    $password = $input['password'] ?? '';
    $code = trim($input['code'] ?? '');

    if ($password === '' || $code === '') {
        json_error('Password and TOTP code required to disable 2FA');
    }

    // Verify password
    $userRow = db_fetch_one(
        "SELECT `passwd` FROM " . db_table('user') . " WHERE `id` = ? LIMIT 1",
        [$current_user_id]
    );
    if (!$userRow) {
        json_error('User not found', 404);
    }

    $authResult = verify_password($password, $userRow['passwd']);
    if (!$authResult['valid']) {
        json_error('Incorrect password');
    }

    // Verify TOTP code
    if (!tfa_verify_login($current_user_id, $code)) {
        json_error('Invalid authentication code');
    }

    tfa_disable($current_user_id);
    audit_log('auth', 'tfa_disable', 'user', $current_user_id,
        "2FA disabled for user '{$current_user}'", null, AUDIT_HIGH);

    json_response(['success' => true, 'message' => 'Two-factor authentication has been disabled.']);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Regenerate backup codes
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'regenerate') {
    $code = trim($input['code'] ?? '');
    if ($code === '') {
        json_error('TOTP code required to regenerate backup codes');
    }

    if (!tfa_is_enabled($current_user_id)) {
        json_error('2FA is not enabled');
    }

    if (!tfa_verify_login($current_user_id, $code)) {
        json_error('Invalid authentication code');
    }

    $newCodes = tfa_regenerate_backup_codes($current_user_id);
    if ($newCodes === false) {
        json_error('Failed to regenerate backup codes', 500);
    }

    audit_log('auth', 'tfa_regen_backup', 'user', $current_user_id,
        "Backup codes regenerated for user '{$current_user}'", null, AUDIT_MEDIUM);

    json_response([
        'success'      => true,
        'backup_codes' => $newCodes,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Admin force-disable 2FA for another user
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'admin_disable') {
    if (!is_admin()) {
        json_error('Super admin access required', 403);
    }

    $targetUserId = (int) ($input['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        json_error('user_id required');
    }

    tfa_disable($targetUserId);

    $targetUser = db_fetch_value(
        "SELECT `user` FROM " . db_table('user') . " WHERE `id` = ?",
        [$targetUserId]
    );

    audit_log('auth', 'tfa_admin_disable', 'user', $targetUserId,
        "2FA force-disabled for user '{$targetUser}' by admin '{$current_user}'", null, AUDIT_HIGH);

    json_response(['success' => true, 'message' => '2FA disabled for user.']);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Save 2FA admin settings
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'save_settings') {
    if (!is_admin()) {
        json_error('Admin access required', 403);
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Save each setting
    $settingsMap = [
        'tfa_enabled'        => isset($input['tfa_enabled']) ? ($input['tfa_enabled'] ? '1' : '0') : null,
        'tfa_required_roles' => isset($input['tfa_required_roles']) ? json_encode($input['tfa_required_roles']) : null,
        'tfa_trusted_cidrs'  => isset($input['tfa_trusted_cidrs']) ? json_encode($input['tfa_trusted_cidrs']) : null,
        'tfa_remember_days'  => isset($input['tfa_remember_days']) ? (string) (int) $input['tfa_remember_days'] : null,
    ];

    foreach ($settingsMap as $key => $value) {
        if ($value !== null) {
            // Upsert pattern: try UPDATE, if no rows affected do INSERT
            $affected = db_query(
                "UPDATE `{$prefix}config` SET `value` = ? WHERE `key` = ?",
                [$value, $key]
            )->rowCount();

            if ($affected === 0) {
                try {
                    db_query(
                        "INSERT INTO `{$prefix}config` (`key`, `value`) VALUES (?, ?)",
                        [$key, $value]
                    );
                } catch (Exception $e) {
                    // May already exist with same value
                }
            }
        }
    }

    audit_log('config', 'tfa_settings', 'system', null,
        "2FA settings updated by '{$current_user}'", null, AUDIT_MEDIUM);

    json_response(['success' => true]);
}

// ═══════════════════════════════════════════════════════════════
//  GET — List remembered devices for current user
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'devices') {
    $devices = [];
    try {
        $devices = db_fetch_all(
            "SELECT `id`, `device_fingerprint`, `ip_address`, `user_agent`, `created_at`, `expires_at`
             FROM " . db_table('tfa_remember_tokens')
            . " WHERE `user_id` = ? AND `expires_at` > NOW()
             ORDER BY `created_at` DESC",
            [$current_user_id]
        );
    } catch (Exception $e) {
        // user_agent column may not exist yet — retry without it
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `device_fingerprint`, `ip_address`, `created_at`, `expires_at`
                 FROM " . db_table('tfa_remember_tokens')
                . " WHERE `user_id` = ? AND `expires_at` > NOW()
                 ORDER BY `created_at` DESC",
                [$current_user_id]
            );
            $devices = [];
            foreach ($rows as $r) {
                $r['user_agent'] = '';
                $devices[] = $r;
            }
        } catch (Exception $e2) {
            $devices = [];
        }
    }
    json_response(['devices' => $devices]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Revoke a single remembered device
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'revoke_device') {
    $deviceId = (int) ($input['device_id'] ?? 0);
    if ($deviceId <= 0) {
        json_error('device_id required');
    }

    try {
        db_query(
            "DELETE FROM " . db_table('tfa_remember_tokens')
            . " WHERE `id` = ? AND `user_id` = ?",
            [$deviceId, $current_user_id]
        );
    } catch (Exception $e) {
        json_error('Failed to revoke device', 500);
    }

    json_response(['success' => true]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Revoke all remembered devices
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'revoke_all_devices') {
    try {
        db_query(
            "DELETE FROM " . db_table('tfa_remember_tokens') . " WHERE `user_id` = ?",
            [$current_user_id]
        );
    } catch (Exception $e) {
        json_error('Failed to revoke devices', 500);
    }

    json_response(['success' => true]);
}

// ═══════════════════════════════════════════════════════════════
//  Fallback — unknown action
// ═══════════════════════════════════════════════════════════════
json_error('Unknown action: ' . ($action ?: '(none)'), 400);
