<?php
/**
 * NewUI v4.0 - Auth Helper
 *
 * Include this at the top of any API endpoint that requires authentication.
 * Returns 401 JSON if the user is not logged in.
 */

// Fatal-to-JSON guard FIRST, before anything that can fail. Every
// session-authenticated api/*.php endpoint requires this file, so installing
// the guard here covers them all in one place. Anything that kills the request
// from here on — including an Error/TypeError that `catch (Exception)` cannot
// reach — still returns valid JSON instead of an empty body. See
// inc/api_guard.php for the full rationale (mesh-bridge delete, 2026-07-28).
require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();

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

// Phase 145 (2026-08-19, GH#90) — facility-account confinement. Must run
// BEFORE the must_change_password/tfa/RBAC-fail-closed block below so a
// facility-confined session is blocked from every endpoint outside its
// small allowlist regardless of those other states. api/auth.php is
// require_once'd by essentially every api/*.php endpoint, making this
// the single choke point that protects endpoints with no RBAC gate of
// their own — see inc/facility-scope.php's docblock for the full
// three-layer design. No-op for every non-facility session.
require_once __DIR__ . '/../inc/facility-scope.php';
facility_confine_api_or_deny();

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
    // RBAC fail-closed at the API edge.
    //
    // Phase 128 (2026-07-29): the two branches below used to be one
    // lenient check. When the v2 schema was absent we let every request
    // through, because rbac_can() would answer from `user.level` — that
    // fallback is gone, so "schema absent" is now a 503 naming the
    // migration, not a silent pass. An authenticated session that
    // predates the breakage cannot act on stale authority.
    require_once __DIR__ . '/../inc/rbac.php';
    if (!rbac_schema_ready()) {
        if (function_exists('audit_log')) {
            audit_log('auth', 'rbac_unmigrated', 'user', (int) ($_SESSION['user_id'] ?? 0),
                'API call blocked — RBAC v2 schema absent', null, AUDIT_HIGH);
        }
        json_error(rbac_unmigrated_message(), 503);
    }
    if (empty(rbac_user_roles())) {
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
// Phase 128 (2026-07-29): `$current_level` is gone. Every endpoint that
// used it for an access decision now calls is_admin() / rbac_can(). It is
// deliberately NOT left defined-but-unused: a convenience global named
// like an authority is an invitation to gate on it again, and
// tools/legacy_level_audit.php would fail the build the moment someone
// does. The column still exists in the database, read only by the
// one-time level->role migration.
$current_member_id = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
$current_org_id    = isset($_SESSION['active_org_id']) ? (int) $_SESSION['active_org_id'] : null;
