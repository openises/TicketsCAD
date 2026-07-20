<?php
/**
 * NewUI v4.0 - Auth Helper
 *
 * Include this at the top of any API endpoint that requires authentication.
 * Returns 401 JSON if the user is not logged in.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/security-headers.php';
require_once __DIR__ . '/../inc/session-manager.php';
// Phase 104e (a beta tester GH #6) — pick the mobile session profile if
// the client sent the mobile cookie, otherwise fall through to the
// default desktop profile. Must fire BEFORE session_start().
require_once __DIR__ . '/../inc/session-bootstrap.php';
sess_bootstrap_auto();

// Hour-2 hardening: set security headers on every API response. Fires before
// any json_error()/json_response() so headers are always present even on the
// 401-not-authenticated reply below.
set_security_headers();

session_start();
if (function_exists('sess_touch_mobile_cookie')) sess_touch_mobile_cookie();

if (empty($_SESSION['user_id'])) {
    json_error('Not authenticated', 401);
}

// Hour-2 hardening: enforce session expiry recorded by sm_create_session().
// Constitution rule #4 — sessions older than the configured timeout
// (default 24 h via session-manager) must require re-auth. sm_is_session_valid
// degrades gracefully when the active_sessions table is missing.
if (!sm_is_session_valid()) {
    if (function_exists('audit_log')) {
        audit_log('auth', 'session_expired', 'user', (int) $_SESSION['user_id'],
            'Session expired — forced re-authentication');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
    json_error('Session expired', 401);
}

// Touch the active_sessions row so the rolling timeout extends with use.
sm_update_activity();

// Phase 9 (2026-06-08): force-password-change middleware.
// IMPORTANT: this MUST run before the RBAC fail-closed check below.
// Reasoning: a newly-created user with must_change_password=1 may have
// zero role grants yet (admin sets the password first, role-grant might
// not be applied until first login, or might be auto-granted lazily).
// If RBAC fail-closed fires first, the user gets 403 "No roles assigned"
// when trying to POST api/profile.php to change their password — locked
// out of the only endpoint they need. Putting this check first gives the
// user a clean path to /profile.php only, then RBAC enforces the rest.
//
// When the must_change_password flag is set, every API endpoint EXCEPT
// api/profile.php returns HTTP 423 Locked. JS callers detect this and
// surface the forced flow. Logout from the navbar still works because
// it hits login.php?logout=1 (a page, not an API).
if (!empty($_SESSION['must_change_password'])) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'profile.php') {
        json_response([
            'error' => 'Password change required',
            'code'  => 'force_pw_change',
        ], 423);
    }
    // For profile.php we fall through. We deliberately SKIP the RBAC
    // fail-closed check below — a forced user must be able to reach the
    // change-password endpoint regardless of role state. After the
    // password change clears the flag, subsequent requests go through
    // the normal RBAC check.
} elseif (!empty($_SESSION['tfa_enrollment_required'])) {
    // Phase 73aa — CRITICAL: previously this flag was only honoured by
    // index.php / profile.php page redirects. Every api/*.php endpoint
    // was fully accessible to a curl caller with the flag set, so a
    // user whose role required 2FA but who hadn't enrolled could
    // bypass the requirement entirely by using the API directly.
    //
    // Same lockout discipline as must_change_password: every endpoint
    // EXCEPT api/tfa.php (the enrollment surface) and api/profile.php
    // (where the enrollment wizard lives) returns 423 Locked. The
    // JS client detects the response code + 'force_tfa_enroll' and
    // redirects the browser to /profile.php#enroll-2fa.
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $allowed = ['profile.php', 'tfa.php', 'tfa-enroll.php', 'logout.php'];
    if (!in_array($script, $allowed, true)) {
        if (function_exists('audit_log')) {
            audit_log('auth', 'tfa_enroll_required', 'user',
                (int) ($_SESSION['user_id'] ?? 0),
                'API call blocked — pending 2FA enrollment');
        }
        json_response([
            'error' => '2FA enrollment required',
            'code'  => 'force_tfa_enroll',
        ], 423);
    }
    // Fall through for the enrollment endpoints. RBAC check still
    // runs for these (they require an authenticated user with a role).
} else {
    // RBAC v2 fail-closed (specs/rbac-redesign-2026-05/plan.md §B5):
    // once the v2 schema is in place, an authenticated user with zero
    // active role grants is denied at the API edge. We deliberately do
    // not reach for _rbac_legacy_check here — that path only fires when
    // the v2 schema is absent, in which case rbac_user_roles() returns
    // [] for everyone and we'd lock the whole site out. Only enforce
    // fail-closed when the v2 columns exist.
    require_once __DIR__ . '/../inc/rbac.php';
    if (_rbac_v2_schema_present() && empty(rbac_user_roles())) {
        if (function_exists('audit_log')) {
            audit_log('auth', 'no_roles', 'user', (int) $_SESSION['user_id'],
                'Authenticated user has zero active grants — denied');
        }
        json_error('No roles assigned — contact an administrator', 403);
    }
}

// Convenience variables available to any endpoint that includes this file
$current_user_id  = (int) $_SESSION['user_id'];
$current_user     = $_SESSION['user'] ?? '';
$current_level    = (int) ($_SESSION['level'] ?? 0);
$current_member_id = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
$current_org_id    = isset($_SESSION['active_org_id']) ? (int) $_SESSION['active_org_id'] : null;
