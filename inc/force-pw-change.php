<?php
/**
 * NewUI v4.0 — Force password-change middleware (Phase 9)
 *
 * Page-entry helper that mirrors the api/auth.php logic. When the
 * session flag $_SESSION['must_change_password'] is set, every
 * authenticated PAGE redirects to profile.php?force_pw=1, except:
 *
 *   - profile.php itself (the change-password tab lives there)
 *   - login.php?logout=1 (let the user abandon if they want)
 *
 * Call AFTER the existing session check (the one that sends a logged-out
 * user to login.php), so we know $_SESSION['user_id'] is real before we
 * even bother looking at the force flag.
 *
 * Usage at the top of every authenticated page:
 *
 *   if (empty($_SESSION['user_id'])) {
 *       header('Location: login.php');
 *       exit;
 *   }
 *   require_once __DIR__ . '/inc/force-pw-change.php';
 *   force_pw_change_redirect();
 *
 * For the API path, the same rule is enforced inside api/auth.php (which
 * every authenticated API endpoint already includes). It returns HTTP
 * 423 Locked rather than redirecting, so JS callers can detect cleanly.
 */

/**
 * Redirect to the forced password-change UI if needed.
 *
 * Optional `$script` argument lets the caller specify the current
 * script name explicitly. If omitted, we derive it from
 * $_SERVER['SCRIPT_NAME']. The argument is mostly for tests.
 */
function force_pw_change_redirect(?string $script = null): void
{
    if (empty($_SESSION['must_change_password'])) {
        return;
    }

    $script = $script ?? basename($_SERVER['SCRIPT_NAME'] ?? '');

    // Profile page itself is the destination — never bounce away from it.
    if ($script === 'profile.php') {
        return;
    }

    // login.php handles logout and the login form. Both must remain
    // reachable: the user must be able to abandon the forced flow by
    // logging out, and the post-logout redirect lands them back here.
    if ($script === 'login.php') {
        return;
    }

    header('Location: profile.php?force_pw=1');
    exit;
}
