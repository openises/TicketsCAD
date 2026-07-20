<?php
/**
 * NewUI v4.0 — Password Policy module (Phase 10 CJIS hardening)
 *
 * Centralizes all password-policy logic so it's enforced consistently
 * across:
 *   - user self-change (api/profile.php)
 *   - admin create user (api/config-admin.php)
 *   - admin reset password (api/login-security.php)
 *   - forced first-login change (api/profile.php in forced mode)
 *
 * Pure functions, no HTTP, no UI. All five degrade gracefully when
 * schema or settings are missing (pre-Phase-10 install).
 *
 * Public functions:
 *   pw_min_length()       Min length setting (default 8, floor 4)
 *   pw_history_count()    History retention (default 10, 0 = off)
 *   pw_rotation_days()    Rotation reminder threshold (default 180, 0 = off)
 *   pw_snooze_days()      Snooze deferral (default 10)
 *
 *   pw_validate($candidate, $userId)
 *     Returns ['ok' => bool, 'error' => string|null, 'code' => string]
 *     Codes: 'ok', 'too_short', 'too_weak', 'too_common', 'in_history'
 *
 *   pw_record_history($userId, $hash)
 *     INSERTs the hash and DELETEs entries beyond pw_history_count().
 *
 *   pw_needs_rotation($userId)
 *     Returns ['needs' => bool, 'age_days' => int|null, 'snoozed_until' => string|null]
 *
 *   pw_snooze($userId)
 *     Sets password_reminder_snoozed_until = NOW() + snooze_days.
 *     Returns true on success, false on DB/column error.
 */

// ─── Setting readers ────────────────────────────────────────────────────────
// Each reader pulls from the `settings` table with a safe default. Cached
// in static for the duration of the request — no need to hit the DB
// repeatedly for the same value.

function pw_min_length(): int
{
    static $v = null;
    if ($v !== null) return $v;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
             WHERE `name` = 'password_min_length' LIMIT 1"
        );
        $v = $row !== null ? max(4, (int) $row) : 8;
    } catch (Exception $e) {
        $v = 8;
    }
    return $v;
}

function pw_history_count(): int
{
    static $v = null;
    if ($v !== null) return $v;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
             WHERE `name` = 'password_history_count' LIMIT 1"
        );
        $v = $row !== null ? max(0, (int) $row) : 10;
    } catch (Exception $e) {
        $v = 10;
    }
    return $v;
}

function pw_rotation_days(): int
{
    static $v = null;
    if ($v !== null) return $v;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
             WHERE `name` = 'password_rotation_reminder_days' LIMIT 1"
        );
        $v = $row !== null ? max(0, (int) $row) : 180;
    } catch (Exception $e) {
        $v = 180;
    }
    return $v;
}

function pw_snooze_days(): int
{
    static $v = null;
    if ($v !== null) return $v;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
             WHERE `name` = 'password_rotation_snooze_days' LIMIT 1"
        );
        $v = $row !== null ? max(0, (int) $row) : 10;
    } catch (Exception $e) {
        $v = 10;
    }
    return $v;
}

// ─── Complexity + common-password policy ─────────────────────────────────────

/**
 * Whether to require at least one letter AND one number. Defaults ON.
 * Admins can disable via settings.password_require_complexity = '0'.
 */
function pw_require_complexity(): bool
{
    static $v = null;
    if ($v !== null) return $v;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
             WHERE `name` = 'password_require_complexity' LIMIT 1"
        );
        // Default ON when the setting is absent (pre-this-phase installs).
        $v = $row === null ? true : ((string) $row !== '0');
    } catch (Exception $e) {
        $v = true;
    }
    return $v;
}

/**
 * Reject obviously-guessable passwords. This is a deliberately small,
 * embedded denylist of the most-abused passwords (and their length-padded
 * variants) — not a full breach corpus. It catches the "password",
 * "12345678", "qwerty123" class the training material warns about without
 * a dependency on an external wordlist file. Case-insensitive.
 */
function pw_is_common(string $candidate): bool
{
    static $set = null;
    if ($set === null) {
        $set = array_flip([
            'password', 'password1', 'password12', 'password123', 'passw0rd',
            '12345678', '123456789', '1234567890', '123123123', '000000000',
            'qwerty123', 'qwertyuiop', 'asdfghjkl', '1q2w3e4r', '1qaz2wsx',
            'iloveyou', 'admin123', 'letmein123', 'welcome123', 'changeme1',
            'football1', 'baseball1', 'sunshine1', 'princess1', 'monkey123',
            'abc12345', 'trustno1', 'dragon123', 'master123', 'superman1',
        ]);
    }
    return isset($set[strtolower($candidate)]);
}

// ─── Validation ────────────────────────────────────────────────────────────

/**
 * Validate a candidate password against current policy + user history.
 *
 * Returns:
 *   [
 *     'ok'    => true|false,
 *     'error' => null OR human-readable message,
 *     'code'  => 'ok' | 'too_short' | 'too_weak' | 'too_common' | 'in_history'
 *   ]
 *
 * Order of checks (cheapest / most-common-failure first): length →
 * complexity → common-password → history. The caller decides how to surface
 * the error (JSON, redirect, etc). The message is English; the caller wraps
 * with t() for localization.
 */
function pw_validate(string $candidate, int $userId): array
{
    $min = pw_min_length();
    if (strlen($candidate) < $min) {
        return [
            'ok'    => false,
            'error' => "Password must be at least {$min} characters.",
            'code'  => 'too_short',
        ];
    }

    // Complexity: at least one letter and one number. Matches the policy the
    // training material teaches and the CJIS baseline expectation.
    if (pw_require_complexity()) {
        $hasLetter = preg_match('/[A-Za-z]/', $candidate) === 1;
        $hasNumber = preg_match('/[0-9]/', $candidate) === 1;
        if (!$hasLetter || !$hasNumber) {
            return [
                'ok'    => false,
                'error' => 'Password must contain at least one letter and one number.',
                'code'  => 'too_weak',
            ];
        }
    }

    // Common-password rejection: block the most-abused passwords outright.
    if (pw_is_common($candidate)) {
        return [
            'ok'    => false,
            'error' => 'That password is too common. Please choose something harder to guess.',
            'code'  => 'too_common',
        ];
    }

    // History check: hash the candidate against each retained history hash.
    // bcrypt has the salt embedded in the hash, so we use password_verify()
    // on each — O(N) bcrypt verifications where N = history_count. With
    // count=10 and cost=12 (~75ms per verify), that's ~0.75s worst case
    // on first reuse-attempt rejection. Acceptable for the security gain.
    $histN = pw_history_count();
    if ($histN > 0 && $userId > 0) {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT `hash` FROM `{$prefix}user_password_history`
                 WHERE `user_id` = ?
                 ORDER BY `created_at` DESC
                 LIMIT {$histN}",
                [$userId]
            );
            foreach ($rows as $r) {
                if (password_verify($candidate, $r['hash'])) {
                    return [
                        'ok'    => false,
                        'error' => 'This password matches a recent password you have used. Please choose a different one.',
                        'code'  => 'in_history',
                    ];
                }
            }
        } catch (Exception $e) {
            // Table may not exist (pre-Phase-10) — skip history check.
        }
    }

    return ['ok' => true, 'error' => null, 'code' => 'ok'];
}

// ─── History recording ─────────────────────────────────────────────────────

/**
 * Add a hash to the user's password history and trim old entries
 * beyond pw_history_count(). Best-effort: missing table or column → no-op.
 *
 * Call this AFTER a successful password change with the NEW hash.
 * (We could also record the OLD hash for completeness, but storing the
 * NEW hash here matches the standard "last N you've used" semantic and
 * means a user changing pw 11 times has 10 in history + the current.)
 */
function pw_record_history(int $userId, string $hash): void
{
    if ($userId <= 0 || $hash === '') return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}user_password_history` (user_id, hash, created_at)
             VALUES (?, ?, NOW())",
            [$userId, $hash]
        );
        // Trim history beyond N. We keep the most recent N entries.
        $n = pw_history_count();
        if ($n > 0) {
            // Find the IDs of rows to keep, delete the rest.
            // This pattern is portable across MariaDB/MySQL.
            $keepIds = db_fetch_all(
                "SELECT `id` FROM `{$prefix}user_password_history`
                 WHERE `user_id` = ?
                 ORDER BY `created_at` DESC
                 LIMIT {$n}",
                [$userId]
            );
            if (!empty($keepIds)) {
                $keepIdList = array_map(function ($r) { return (int) $r['id']; }, $keepIds);
                $placeholders = implode(',', array_fill(0, count($keepIdList), '?'));
                $args = array_merge([$userId], $keepIdList);
                db_query(
                    "DELETE FROM `{$prefix}user_password_history`
                     WHERE `user_id` = ? AND `id` NOT IN ({$placeholders})",
                    $args
                );
            }
        } else {
            // count=0 → history disabled, but we already inserted one.
            // Clean up: keep nothing.
            db_query(
                "DELETE FROM `{$prefix}user_password_history` WHERE `user_id` = ?",
                [$userId]
            );
        }
    } catch (Exception $e) {
        // Table missing — pre-Phase-10. Skip silently.
    }
}

// ─── Rotation reminder check ───────────────────────────────────────────────

/**
 * Check whether the user should see the rotation-reminder banner.
 *
 * Returns:
 *   [
 *     'needs'         => true|false,
 *     'age_days'      => int|null (days since last password change),
 *     'snoozed_until' => 'YYYY-MM-DD HH:MM:SS'|null
 *   ]
 *
 * Reminders are suppressed if rotation_days is 0 (admin disabled the
 * feature) OR if password_reminder_snoozed_until is in the future.
 */
function pw_needs_rotation(int $userId): array
{
    $out = ['needs' => false, 'age_days' => null, 'snoozed_until' => null];
    if ($userId <= 0) return $out;

    $reminderDays = pw_rotation_days();
    if ($reminderDays <= 0) {
        // Feature disabled install-wide.
        return $out;
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT password_changed_at, password_reminder_snoozed_until
             FROM `{$prefix}user`
             WHERE `id` = ? LIMIT 1",
            [$userId]
        );
        if (!$row) return $out;

        $changedAt = $row['password_changed_at'] ?? null;
        if (!$changedAt) return $out; // backfill should have set this, but be defensive

        $snoozedUntil = $row['password_reminder_snoozed_until'] ?? null;
        $out['snoozed_until'] = $snoozedUntil;

        // Currently snoozed?
        if ($snoozedUntil) {
            $now = time();
            $snoozeTs = strtotime($snoozedUntil);
            if ($snoozeTs !== false && $snoozeTs > $now) {
                return $out;
            }
        }

        // Age check
        $changedTs = strtotime($changedAt);
        if ($changedTs === false) return $out;

        $ageDays = (int) floor((time() - $changedTs) / 86400);
        $out['age_days'] = $ageDays;

        if ($ageDays >= $reminderDays) {
            $out['needs'] = true;
        }
    } catch (Exception $e) {
        // Column missing — pre-Phase-10. Skip silently.
    }

    return $out;
}

// ─── Snooze ────────────────────────────────────────────────────────────────

/**
 * Defer the rotation reminder by pw_snooze_days() days.
 * Returns true on success, false otherwise.
 */
function pw_snooze(int $userId): bool
{
    if ($userId <= 0) return false;
    $days = pw_snooze_days();
    if ($days <= 0) return false; // snooze disabled — caller knew or didn't care

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}user`
             SET password_reminder_snoozed_until = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE id = ?",
            [$days, $userId]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ─── Helper: mark a password change ────────────────────────────────────────

/**
 * Convenience: update password_changed_at to NOW() and clear any snooze.
 * Call this from every successful password-change path AFTER the
 * passwd column is updated. Best-effort.
 */
function pw_mark_changed(int $userId): void
{
    if ($userId <= 0) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}user`
             SET password_changed_at = NOW(),
                 password_reminder_snoozed_until = NULL
             WHERE id = ?",
            [$userId]
        );
    } catch (Exception $e) {
        // Columns missing — pre-Phase-10. Skip.
    }
}
