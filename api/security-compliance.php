<?php
/**
 * NewUI v4.0 API — Security Compliance Dashboard (Phase 10 CJIS hardening)
 *
 * GET /api/security-compliance.php
 *   Returns a single JSON object summarizing the install's compliance
 *   posture: current password policy, lockout settings, 2FA stats,
 *   session timeout, recent auth event counts, audit log health.
 *
 * Admin-only (action.manage_config). Read-only.
 *
 * Naming note: api/compliance.php is taken by the personnel-certification
 * compliance feature. This file is the security/CJIS compliance dashboard.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/password-policy.php';

ini_set('display_errors', '0');

if (!rbac_can('action.manage_config') && !is_admin()) {
    json_error('Admin access required', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

function _seccomp_setting(string $name, $default = null) {
    global $prefix;
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            [$name]
        );
        return $v !== null ? $v : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// CJIS recommendations — used to compute pass/fail badges in the UI.
$cjisExpected = [
    'password_min_length'         => 8,
    'password_history_count'      => 10,
    'lockout_max_attempts'        => 5,
    'lockout_duration_minutes'    => 10,
    'session_timeout_minutes_max' => 30,
];

$pwPolicy = [
    'min_length'      => (int) _seccomp_setting('password_min_length', 8),
    'history_count'   => (int) _seccomp_setting('password_history_count', 10),
    'rotation_days'   => (int) _seccomp_setting('password_rotation_reminder_days', 180),
    'snooze_days'     => (int) _seccomp_setting('password_rotation_snooze_days', 10),
    'force_new_users' => ((int) _seccomp_setting('force_pw_change_for_new_users', 1)) === 1,
];
$pwPolicy['min_length_meets_cjis']    = $pwPolicy['min_length']    >= $cjisExpected['password_min_length'];
$pwPolicy['history_count_meets_cjis'] = $pwPolicy['history_count'] >= $cjisExpected['password_history_count'];

$lockout = [
    'max_attempts'     => (int) _seccomp_setting('lockout_max_attempts', 5),
    'window_minutes'   => (int) _seccomp_setting('lockout_window_minutes', 15),
    'duration_minutes' => (int) _seccomp_setting('lockout_duration_minutes', 30),
];
// 2026-06-28 fix (Eric beta): the previous check accepted 0 because
// 0 <= 5 is true — but max_attempts=0 means "no lockout enforced",
// which violates the policy. Require an actual enabled value in
// the range [1, 5].
$lockout['attempts_meets_cjis'] =
    $lockout['max_attempts'] >= 1
    && $lockout['max_attempts'] <= $cjisExpected['lockout_max_attempts'];
$lockout['duration_meets_cjis'] = $lockout['duration_minutes'] >= $cjisExpected['lockout_duration_minutes'];

// tfa_enabled is the one exception in this file: api/tfa.php saves it
// to the `config` table (keyed by `key`), not the `settings` table that
// _seccomp_setting() reads from. Read it directly so this page agrees
// with the actual stored value (and with the Welcome page hint, after
// the matching fix in api/config-summary.php — both readers now point
// at `config` to match the save side). Other _seccomp_setting calls in
// this file legitimately read from `settings` (lockout, password
// rules, session timeout — those save endpoints DO write there).
$tfaEnabledRaw = null;
try {
    $tfaEnabledRaw = db_fetch_value(
        "SELECT `value` FROM `{$prefix}config` WHERE `key` = 'tfa_enabled' LIMIT 1"
    );
} catch (Exception $e) {
    $tfaEnabledRaw = null;
}
$tfa = [
    'system_enabled' => $tfaEnabledRaw !== null && ((int) $tfaEnabledRaw) === 1,
    'enrolled_count' => 0,
    'total_users'    => 0,
    'enrollment_pct' => 0,
];
try {
    // TFA secrets live in the user_tfa table (Phase 8 schema), NOT as
    // a tfa_secret column on the user table. The previous version of
    // this query targeted user.tfa_secret which doesn't exist on any
    // current install — it always silently caught the exception and
    // reported 0 enrolled, even when the Welcome-page 2FA widget
    // (which queries user_tfa correctly) reported a higher number.
    // Beta tester a beta tester flagged the discrepancy 2026-06-26.
    //
    // Match api/config-summary.php's query so both widgets agree:
    // count users with a CONFIRMED user_tfa row (confirmed=1 means
    // they completed enrollment + verified a TOTP code at least once).
    $tfa['total_users']    = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
    $tfa['enrolled_count'] = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_tfa` WHERE `confirmed` = 1"
    );
    if ($tfa['total_users'] > 0) {
        $tfa['enrollment_pct'] = round(
            100.0 * $tfa['enrolled_count'] / $tfa['total_users'], 1
        );
    }
} catch (Exception $e) {
    // user_tfa table may not exist on a partial install — leave defaults.
}

$session = [
    'timeout_minutes' => (int) _seccomp_setting('session_timeout_minutes', 480),
];
// 2026-06-28 fix (Eric beta): the previous check accepted 0 because
// 0 <= 30 is true — but timeout=0 means "no idle timeout enforced",
// which violates the policy. Require an actual enabled value in
// the range [1, 30].
$session['timeout_meets_cjis_for_cji'] =
    $session['timeout_minutes'] >= 1
    && $session['timeout_minutes'] <= $cjisExpected['session_timeout_minutes_max'];

$now = new DateTime();
$weekAgo = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s');
$auth7d = [
    'logins'        => 0,
    'login_failed'  => 0,
    'lockouts'      => 0,
    'pw_changes'    => 0,
    'admin_resets'  => 0,
    'tfa_enrols'    => 0,
];
try {
    $rows = db_fetch_all(
        "SELECT verb, COUNT(*) AS n
         FROM `{$prefix}newui_audit_log`
         WHERE created_at >= ?
           AND category = 'auth'
         GROUP BY verb",
        [$weekAgo]
    );
    foreach ($rows as $r) {
        $verb = $r['verb'];
        $n = (int) $r['n'];
        if ($verb === 'login')           $auth7d['logins']       += $n;
        if ($verb === 'login_failed')    $auth7d['login_failed'] += $n;
        if ($verb === 'login_blocked')   $auth7d['lockouts']     += $n;
        if ($verb === 'password_change') $auth7d['pw_changes']   += $n;
        if ($verb === 'tfa_enrolled')    $auth7d['tfa_enrols']   += $n;
    }
    // Admin resets are recorded as audit_admin entries (category 'admin').
    // The Phase 10 reset includes "Admin reset password" in the details.
    $auth7d['admin_resets'] = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
         WHERE created_at >= ?
           AND details LIKE '%Admin reset password%'",
        [$weekAgo]
    );
} catch (Exception $e) {
    // audit log table missing
}

$auditHealth = [
    'total_rows'   => 0,
    'oldest_entry' => null,
    'newest_entry' => null,
];
// 2026-06-28 fix (Eric beta): the timestamp column on newui_audit_log
// is `event_time`, NOT `created_at`. The previous queries silently
// failed via the try/catch and oldest/newest displayed empty. Use
// the correct column name.
try {
    $auditHealth['total_rows']   = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`"
    );
    $auditHealth['oldest_entry'] = db_fetch_value(
        "SELECT MIN(event_time) FROM `{$prefix}newui_audit_log`"
    );
    $auditHealth['newest_entry'] = db_fetch_value(
        "SELECT MAX(event_time) FROM `{$prefix}newui_audit_log`"
    );
} catch (Exception $e) {
    // audit log missing
}

$rotation = [
    'reminder_days' => $pwPolicy['rotation_days'],
    'overdue_users' => 0,
    'snoozed_users' => 0,
];
try {
    if ($pwPolicy['rotation_days'] > 0) {
        $cutoff = (clone $now)
            ->modify('-' . $pwPolicy['rotation_days'] . ' days')
            ->format('Y-m-d H:i:s');
        $rotation['overdue_users'] = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user`
             WHERE password_changed_at IS NOT NULL
               AND password_changed_at < ?
               AND (password_reminder_snoozed_until IS NULL
                    OR password_reminder_snoozed_until < NOW())",
            [$cutoff]
        );
        $rotation['snoozed_users'] = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user`
             WHERE password_reminder_snoozed_until > NOW()"
        );
    }
} catch (Exception $e) {
    // pre-Phase-10 columns missing
}

json_response([
    'generated_at'       => $now->format('c'),
    'cjis_expected'      => $cjisExpected,
    'password_policy'    => $pwPolicy,
    'account_lockout'    => $lockout,
    'two_factor_auth'    => $tfa,
    'session_management' => $session,
    'auth_activity_7d'   => $auth7d,
    'audit_log_health'   => $auditHealth,
    'rotation'           => $rotation,
]);
