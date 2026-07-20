<?php
/**
 * NewUI v4.0 API — Login Security
 *
 * Admin-only endpoints for viewing active sessions and login attempts.
 *
 * GET ?action=sessions       — List active sessions
 * GET ?action=attempts       — List recent login attempts
 * POST ?action=force_logout  — Force-logout a specific session
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/login-security.php';
require_once __DIR__ . '/../inc/session-manager.php';
require_once __DIR__ . '/../inc/password-policy.php';

// Require admin level
if (!is_admin()) {
    json_error('Admin access required', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET: Active Sessions ──
if ($method === 'GET' && $action === 'sessions') {
    $sessions = sm_get_all_sessions(100);
    json_response(['sessions' => $sessions]);
}

// ── GET: Recent Login Attempts ──
if ($method === 'GET' && $action === 'attempts') {
    $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 200) : 50;
    $attempts = ls_get_all_recent($limit);
    json_response(['attempts' => $attempts]);
}

// ── POST: Force Logout a Session ──
if ($method === 'POST' && $action === 'force_logout') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    $sessionId = $input['session_id'] ?? '';
    if (empty($sessionId)) {
        json_error('session_id is required');
    }

    // Don't let admin kill their own session
    if ($sessionId === session_id()) {
        json_error('Cannot force-logout your own session');
    }

    $result = sm_destroy_session($sessionId);
    if ($result) {
        audit_admin($current_user_id, 'delete', 'session', "Force-logout session: " . substr($sessionId, 0, 16) . '...', [
            'session_id_prefix' => substr($sessionId, 0, 16),
        ]);
        json_response(['success' => true, 'message' => 'Session terminated']);
    } else {
        json_error('Session not found or already expired');
    }
}

// ── POST: Unlock a locked account ──
if ($method === 'POST' && $action === 'unlock_account') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    $username = trim($input['username'] ?? '');
    if (empty($username)) {
        json_error('username is required');
    }

    ls_clear_attempts($username);

    audit_admin($current_user_id, 'update', 'login_attempts',
        "Admin unlocked account '{$username}'",
        ['username' => $username]);

    json_response(['success' => true, 'message' => "Account '{$username}' unlocked"]);
}

// ── POST: Admin reset a user's password ──
if ($method === 'POST' && $action === 'reset_password') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Phase 73cc — the file-top guard requires is_admin(), but admin
    // does not implicitly include "manage users". An admin role that
    // grants screen.audit_log + manage_orgs (but not manage_users)
    // shouldn't be able to silently reset another super-admin's
    // password. Add an explicit permission check.
    require_once __DIR__ . '/../inc/rbac.php';
    if (!is_admin() && !rbac_can('action.manage_users')) {
        json_error('Forbidden — managing user passwords requires action.manage_users', 403);
    }

    $userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
    $newPass = $input['new_password'] ?? '';
    // Phase 10 (2026-06-08): required reason for the reset action. CJIS
    // expects a paper trail explaining WHY an admin reset another user's
    // authenticator. Minimum 3 chars (sanity); ceiling 2000 chars (audit
    // log row reasonably sized).
    $reason = isset($input['reason']) ? trim((string)$input['reason']) : '';

    if (!$userId) {
        json_error('user_id is required');
    }
    // Phase 73cc — verify the target user actually exists before
    // pretending the reset succeeded. Previously a typo'd user_id
    // returned 200 (UPDATE of zero rows succeeds in MySQL).
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $exists = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user` WHERE `id` = ? LIMIT 1",
            [$userId]
        );
    } catch (Exception $e) {
        $exists = 0;
    }
    if ($exists === 0) {
        json_error('User not found', 404);
    }
    if ($reason === '' || strlen($reason) < 3) {
        json_error('A reason for the reset is required (minimum 3 characters). This is recorded in the audit log for compliance review.', 400);
    }
    if (strlen($reason) > 2000) {
        json_error('Reason must be 2000 characters or fewer', 400);
    }
    // Phase 10: validate via central policy module. Replaces the
    // hardcoded 6-char check. Min comes from settings.password_min_length.
    $polCheck = pw_validate($newPass, $userId);
    if (!$polCheck['ok']) {
        json_error($polCheck['error'], 400);
    }

    $hash = password_hash($newPass, PASSWORD_DEFAULT);

    try {
        // NewUI column is `passwd` (not legacy `pass`). The auth pipeline
        // in login.php verifies against `passwd`; writing to `pass` would
        // silently fail (the row update succeeds but auth uses a different
        // column). Same fix Eric applied to api/config-admin.php's
        // user-save flow on 2026-06-08.
        db_query(
            "UPDATE `{$prefix}user` SET `passwd` = ? WHERE `id` = ?",
            [$hash, $userId]
        );
    } catch (Exception $e) {
        json_error('Password reset failed: ' . $e->getMessage(), 500);
    }

    // Phase 10: record the new hash to history and reset the rotation
    // timer. Best-effort — no-ops on pre-Phase-10 schema.
    pw_record_history($userId, $hash);
    pw_mark_changed($userId);

    // Phase 10: force the reset user to change pw on their next login.
    // The admin chose this temp password; the user must rotate it. This
    // is the Phase 9 forced-change flag, set whenever an admin resets.
    try {
        db_query(
            "UPDATE `{$prefix}user` SET `must_change_password` = 1 WHERE `id` = ?",
            [$userId]
        );
    } catch (Exception $e) { /* pre-phase-9 column missing — fine */ }

    // Force logout all sessions for this user
    try {
        sm_destroy_all_for_user($userId);
    } catch (Exception $e) {
        // Non-fatal
    }

    // Phase 10: include the reason verbatim in the audit log details.
    // audit_admin's 4th param is an associative array of details that
    // gets serialized to JSON in the audit_log row. CJIS auditors can
    // search audit_log WHERE category='auth' AND verb='reset_password'
    // and read the reasons.
    audit_admin($current_user_id, 'update', 'user',
        "Admin reset password for user #{$userId}",
        [
            'target_user_id' => $userId,
            'reason'         => $reason,
        ]);

    json_response([
        'success' => true,
        'message' => 'Password reset. User has been logged out of all sessions and must change the password on next login.',
    ]);
}

json_error('Unknown action', 404);
