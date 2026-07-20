<?php
/**
 * NewUI v4.0 API — User Profile & Password
 *
 * POST action=change_password  — Change current user's password
 * POST action=update_profile   — Update display name, email, phone, callsign
 * GET                          — Get current user's profile info
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/session-manager.php';
require_once __DIR__ . '/../inc/password-policy.php';

$method = $_SERVER['REQUEST_METHOD'];
$userId = (int) $_SESSION['user_id'];

if ($method === 'GET') {
    try {
        $user = db_fetch_one(
            "SELECT id, user, info, email, phone_p AS phone, callsign, level FROM " . db_table('user') . " WHERE id = ?",
            [$userId]
        );
        if (!$user) json_error('User not found', 404);
        json_response(['profile' => $user]);
    } catch (Exception $e) {
        json_error('Failed to load profile');
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';

    // Verify CSRF
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrf_verify($csrfHeader) && !csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }

    if ($action === 'change_password') {
        $currentPw = $input['current_password'] ?? '';
        $newPw = $input['new_password'] ?? '';

        // Phase 10 (2026-06-08): validate via the central password-policy
        // module — enforces minimum length AND history reuse check.
        // Replaces the old hardcoded 6-char check; min is now configurable
        // via settings.password_min_length (default 8).
        $polCheck = pw_validate($newPw, $userId);
        if (!$polCheck['ok']) {
            json_error($polCheck['error']);
        }

        // Verify current password
        try {
            $row = db_fetch_one("SELECT passwd FROM " . db_table('user') . " WHERE id = ?", [$userId]);
            if (!$row) json_error('User not found');

            require_once __DIR__ . '/../inc/security.php';
            if (function_exists('verify_password')) {
                $result = verify_password($currentPw, $row['passwd']);
                if (!$result['valid']) {
                    json_error('Current password is incorrect');
                }
            } else {
                // Fallback: bcrypt verify
                if (!password_verify($currentPw, $row['passwd'])) {
                    json_error('Current password is incorrect');
                }
            }

            // Phase 9 (2026-06-08): if the user was in forced-pw-change mode
            // ($_SESSION['must_change_password']), atomically clear that flag
            // in the SAME UPDATE that writes the password. We don't want a
            // window where the password is changed but the column still
            // says "must change." Best-effort: if the column is absent (pre-
            // phase-9 install) MySQL would error on the column reference, so
            // we branch.
            $wasForced = !empty($_SESSION['must_change_password']);
            $newHash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            try {
                db_query(
                    "UPDATE " . db_table('user')
                    . " SET passwd = ?, must_change_password = 0 WHERE id = ?",
                    [$newHash, $userId]
                );
            } catch (Exception $e) {
                // Column doesn't exist — fall back to the legacy SQL.
                db_query("UPDATE " . db_table('user') . " SET passwd = ? WHERE id = ?", [$newHash, $userId]);
            }
            if ($wasForced) {
                unset($_SESSION['must_change_password']);
            }

            // Phase 10 (2026-06-08): record the new hash to password
            // history (for reuse-detection on future changes) and update
            // password_changed_at + clear any snooze. Best-effort —
            // missing schema is silently no-op.
            pw_record_history($userId, $newHash);
            pw_mark_changed($userId);
            // Clear any in-session rotation reminder so the banner
            // disappears immediately without waiting for next page load.
            unset($_SESSION['rotation_reminder_age']);

            audit_log(
                'auth', 'password_change', 'user', $userId,
                "Password changed for user #{$userId}" . ($wasForced ? ' (forced by admin)' : '')
            );

            // Per Eric 2026-06-08: changing your password should log out every
            // OTHER session you have open elsewhere. Keep the current request's
            // session alive (no need to make the user re-enter creds they just
            // verified two API calls ago); kill the rest so a stale cookie on
            // another device can't continue to act after a password change.
            // Common reasons a user changes their password: suspected
            // compromise, leaving a friend's laptop, or just hygiene. In all
            // three cases the other-device sessions should die.
            $otherSessionsKilled = 0;
            try {
                $otherSessionsKilled = sm_destroy_all_except_current($userId);
                if ($otherSessionsKilled > 0) {
                    audit_log(
                        'auth', 'sessions_invalidated', 'user', $userId,
                        "Password change invalidated {$otherSessionsKilled} other session(s) for user #{$userId}",
                        null,
                        AUDIT_MEDIUM
                    );
                }
            } catch (Exception $e) {
                // Best-effort. If session-manager fails, the password change
                // itself still stands — we don't roll back just because the
                // logout-others step had a hiccup.
                error_log('sm_destroy_all_except_current failed: ' . $e->getMessage());
            }

            json_response([
                'success'                => true,
                'message'                => 'Password changed successfully',
                'other_sessions_ended'   => $otherSessionsKilled,
                'forced_change_cleared'  => $wasForced,
            ]);

        } catch (Exception $e) {
            json_error('Failed to change password: ' . $e->getMessage());
        }
    }

    // Phase 10 (2026-06-08): snooze the rotation-reminder banner.
    // Defers the next reminder by pw_snooze_days() days. Audited so an
    // admin can see who's been deferring.
    if ($action === 'snooze_password_reminder') {
        try {
            $okSnooze = pw_snooze($userId);
            if ($okSnooze) {
                // Clear session flag so banner disappears immediately
                unset($_SESSION['rotation_reminder_age']);
                audit_log(
                    'auth', 'password_rotation_snoozed', 'user', $userId,
                    "User snoozed the rotation reminder for " . pw_snooze_days() . " days"
                );
                // Read back the snoozed_until so the JS knows when next reminder fires.
                $prefix = $GLOBALS['db_prefix'] ?? '';
                $until = db_fetch_value(
                    "SELECT password_reminder_snoozed_until FROM `{$prefix}user` WHERE id = ?",
                    [$userId]
                );
                json_response([
                    'success'        => true,
                    'snoozed_until'  => $until,
                    'snooze_days'    => pw_snooze_days(),
                ]);
            } else {
                json_error('Snooze is disabled or failed', 400);
            }
        } catch (Exception $e) {
            json_error('Snooze failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'update_profile') {
        $displayName = trim($input['display_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $callsign = trim($input['callsign'] ?? '');

        if (!$displayName) json_error('Display name is required');

        try {
            db_query(
                "UPDATE " . db_table('user') . " SET info = ?, email = ?, phone_p = ?, callsign = ? WHERE id = ?",
                [$displayName, $email, $phone, $callsign, $userId]
            );

            // Update session
            $_SESSION['user'] = $displayName;

            audit_log('personnel', 'update', 'user', $userId, "Profile updated for user #{$userId}");
            json_response(['success' => true]);

        } catch (Exception $e) {
            json_error('Failed to update profile: ' . $e->getMessage());
        }
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
