<?php
/**
 * NewUI v4.0 - Login Page
 *
 * Standalone authentication against the newui database.
 * On success, sets session variables and redirects to index.php.
 *
 * Two-factor authentication (TOTP) is checked after password verification:
 *   1. Password validated -> check if 2FA is enrolled for this user
 *   2. If 2FA enrolled -> check device remembered cookie -> if not, show 2FA form
 *   3. 2FA code verified -> complete login
 */

// Defense in depth: suppress error display on the login page regardless of
// config.php's setting. The login layout uses flex centering on <body>, so
// any PHP Warning/Notice rendered as a sibling of the login card breaks the
// centering. Errors still go to the Apache error log via error_reporting().
// (Triggered by the bug Eric caught on your-server.example.com 2026-05-20.)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/audit.php';
require_once __DIR__ . '/inc/field-encrypt.php';
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/login-security.php';
require_once __DIR__ . '/inc/session-manager.php';
require_once __DIR__ . '/inc/tfa.php';
require_once __DIR__ . '/inc/i18n.php';
// Phase 104e (a beta tester GH #6) — pick the mobile session profile if
// the client is coming from a mobile PWA (either cookie is set or
// the URL / referer indicates mobile.php was involved). Must fire
// BEFORE session_start().
require_once __DIR__ . '/inc/session-bootstrap.php';
$_login_mobile_hint =
    sess_client_is_mobile()
    || !empty($_GET['mobile'])
    || (strpos($_SERVER['HTTP_REFERER'] ?? '', '/mobile.php') !== false);
sess_bootstrap_auto($_login_mobile_hint);

session_start();
if (function_exists('sess_touch_mobile_cookie')) sess_touch_mobile_cookie();

// Phase 8b: language picker on the login page itself.
//
// The login form is pre-authentication, so we can't yet read a
// user.preferred_lang. But a visitor whose install default is German
// (or who clicked a "Deutsch" link from a friend) should see the form
// in their language. Accept ?lang=de on the URL; validate against the
// enabled-language registry; stash in $_SESSION['lang'] for this and
// subsequent renders of the login page. The post-login flow overrides
// this with the user's persisted preference if set, so this is purely
// a pre-auth convenience.
if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    $reqLang = preg_replace('/[^a-z0-9\-]/', '', strtolower(substr((string) $_GET['lang'], 0, 8)));
    if ($reqLang !== '') {
        try {
            $row = db_fetch_one(
                "SELECT 1 FROM " . db_table('languages') . " WHERE code = ? AND enabled = 1 LIMIT 1",
                [$reqLang]
            );
            if ($row) {
                $_SESSION['lang'] = $reqLang;
            }
        } catch (Exception $e) {
            // Pre-8b install or DB issue — silently ignore the param.
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    // Audit the logout before destroying session
    if (!empty($_SESSION['user_id'])) {
        audit_login($_SESSION['user_id'], $_SESSION['user'] ?? '', 'logout', 'User logged out');
        sm_destroy_session(session_id());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        // Clear the active session cookie.
        setcookie(session_name(), '', time() - 86400, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        // Issue #6 (a beta tester 2026-07-03) — defensively clear BOTH
        // session cookies on logout. A browser that used both mobile.php
        // (mobile session, TCADMOBILE cookie) and index.php (desktop
        // session, PHPSESSID cookie) in the same window would only get
        // the active-profile cookie cleared, leaving the stale
        // opposite-profile cookie in the jar. On the next login.php
        // request that stale cookie could pin sess_client_is_mobile()
        // to a profile whose $_SESSION['csrf_token'] was never
        // populated for the new form render — surfacing as
        // "Security token expired" on the first login POST.
        $opposite = (session_name() === SESS_MOBILE_COOKIE_NAME) ? 'PHPSESSID' : SESS_MOBILE_COOKIE_NAME;
        setcookie($opposite, '', time() - 86400, '/', $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// Already logged in? Go to dashboard or mobile interface.
//
// Phase 11d (2026-06-11): the "go to mobile" decision is now driven
// by the `roles.mobile_first` flag instead of the previous hardcoded
// (level === 4 OR role_id == 6) test. The old check was wrong on
// two counts:
//   - Hardcoded role_id=6 broke once admins started renaming the
//     built-in Field Unit role (Phase 11c).
//   - Phase 11's user.level=4 fallback for custom roles incorrectly
//     redirected users on admin-created roles like "Internal Auditor"
//     to the mobile interface. Caught 2026-06-11.
//
// The new check joins user_roles → roles and asks: does this user
// have at least one active role with mobile_first=1? If yes, mobile;
// otherwise dashboard.
if (!empty($_SESSION['user_id'])) {
    $redirectTo = 'index.php';
    try {
        $mobileRole = db_fetch_one(
            "SELECT ur.id FROM " . db_table('user_roles') . " ur
             JOIN " . db_table('roles') . " r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND r.mobile_first = 1
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             LIMIT 1",
            [(int) $_SESSION['user_id']]
        );
        if ($mobileRole) {
            $redirectTo = 'mobile.php';
        }
    } catch (Exception $e) {
        // mobile_first column / user_roles table may not exist on
        // a pre-Phase-11d install — fall back to the dashboard.
    }
    header('Location: ' . $redirectTo);
    exit;
}

$error = '';
$lockoutSeconds = 0;
$recentFailures = 0;
$submittedUsername = '';
$showTfaForm = false;
$tfaBackupMode = false;

/**
 * Complete login: set session variables and redirect.
 * Extracted so both normal and 2FA flows share the same finalization.
 */
function complete_login($row, $theme, $clientIp)
{
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    // Phase 73cc — rotate CSRF on privilege change. The pre-login
    // session may carry a CSRF token that an attacker harvested via
    // XSS or other channel on the login page; that token must not
    // remain valid in the post-auth session. Force a fresh mint here
    // (csrf_token() generates one if the slot is empty).
    unset($_SESSION['csrf_token']);

    // Clear any 2FA pending state
    unset($_SESSION['tfa_pending_user_id']);
    unset($_SESSION['tfa_pending_theme']);
    unset($_SESSION['tfa_pending_ip']);

    // Set session
    $_SESSION['user_id']   = (int) $row['id'];
    $_SESSION['user']      = $row['user'];
    // Phase 12 (2026-06-11): keep $_SESSION['level'] populated as a
    // defensive shim for any third-party / extension code that might
    // still read it. NewUI runtime code no longer references it for
    // gating — is_admin() and current_role_name() drive every check.
    $_SESSION['level']     = (int) ($row['level'] ?? 0);
    $_SESSION['day_night'] = $theme;
    $_SESSION['login_at']  = date('Y-m-d H:i:s');

    // Phase 12 (2026-06-11): cache the user's primary active role name
    // (and id) into the session so navbar/current_role_name() can read
    // it without an extra DB hit on every page. Refreshed on next login.
    try {
        $roleRow = db_fetch_one(
            "SELECT r.id, r.name
             FROM " . db_table('user_roles') . " ur
             JOIN " . db_table('roles') . " r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             ORDER BY r.sort_order, ur.granted_at DESC
             LIMIT 1",
            [(int) $row['id']]
        );
        if ($roleRow) {
            $_SESSION['role_id']   = (int) $roleRow['id'];
            $_SESSION['role_name'] = (string) $roleRow['name'];
        }
    } catch (Exception $e) {
        // user_roles table missing — session role_name stays unset and
        // current_role_name() will fall back to "—".
    }

    // Phase 8b i18n: seed session lang from the user's persisted
    // preferred_lang if it's still enabled in the registry. Otherwise
    // i18n_lang() will fall through to Accept-Language → install default.
    // Best-effort — silently skipped if either column or registry is missing
    // (pre-8b installs).
    try {
        $prefLang = db_fetch_value(
            "SELECT u.preferred_lang
             FROM " . db_table('user') . " u
             LEFT JOIN " . db_table('languages') . " l ON l.code = u.preferred_lang
             WHERE u.id = ?
               AND u.preferred_lang IS NOT NULL
               AND u.preferred_lang <> ''
               AND (l.enabled = 1 OR l.enabled IS NULL)
             LIMIT 1",
            [(int) $row['id']]
        );
        if ($prefLang) {
            $_SESSION['lang'] = preg_replace('/[^a-z0-9\-]/', '', strtolower(substr((string)$prefLang, 0, 8)));
        }
    } catch (Exception $e) {
        // user.preferred_lang or languages table missing — skip silently
    }

    // Phase 10 (2026-06-08): seed the rotation-reminder session flag.
    // pw_needs_rotation() reads settings.password_rotation_reminder_days
    // and the user's password_changed_at/snoozed_until columns; returns
    // an array with `needs` (bool) and `age_days` (int|null). The navbar
    // banner renders only when this session flag is set.
    try {
        require_once __DIR__ . '/inc/password-policy.php';
        $rot = pw_needs_rotation((int) $row['id']);
        if (!empty($rot['needs']) && isset($rot['age_days'])) {
            $_SESSION['rotation_reminder_age'] = (int) $rot['age_days'];
        } else {
            unset($_SESSION['rotation_reminder_age']);
        }
    } catch (Exception $e) {
        // password-policy.php missing or schema absent — silently skip.
        unset($_SESSION['rotation_reminder_age']);
    }

    // Phase 9 (2026-06-08): seed the force-pw-change flag from the user row.
    // If user.must_change_password is 1 the user lands in profile.php?force_pw=1
    // and stays there until they pick a new password. The middleware that
    // enforces this lives in inc/force-pw-change.php (page) + api/auth.php (API).
    // Best-effort — silently skipped if the column is missing (pre-phase-9 installs).
    try {
        $mustChange = db_fetch_value(
            "SELECT must_change_password FROM " . db_table('user') . " WHERE id = ? LIMIT 1",
            [(int) $row['id']]
        );
        if ($mustChange !== null && (int) $mustChange === 1) {
            $_SESSION['must_change_password'] = 1;
        } else {
            unset($_SESSION['must_change_password']);
        }
    } catch (Exception $e) {
        // Column missing — pre-phase-9 install, skip silently.
        unset($_SESSION['must_change_password']);
    }

    // Create tracked session record
    sm_create_session((int) $row['id']);

    // Audit successful login. Include cjis_notice_accepted=true in
    // the detail payload whenever the notice was active for this
    // login attempt, so the audit trail records the click-through.
    $auditDetails = ['ip' => $clientIp];
    if (!empty($cjisNoticeEnabled)) {
        $auditDetails['cjis_notice_accepted'] = true;
    }
    audit_login((int) $row['id'], $row['user'], 'login', 'Login successful', $auditDetails);

    // Load user group allocations (type=4 = user groups)
    $groups = db_fetch_all(
        "SELECT `group` FROM " . db_table('allocates')
        . " WHERE `type` = 4 AND `resource_id` = ? ORDER BY `id` ASC",
        [(int) $row['id']]
    );
    $_SESSION['user_groups'] = array_map(function ($g) {
        return (int) $g['group'];
    }, $groups);

    // Load linked member ID and organization memberships
    try {
        $memberRow = db_fetch_one(
            "SELECT id FROM " . db_table('member') . " WHERE user_id = ?",
            [(int) $row['id']]
        );
        $_SESSION['member_id'] = $memberRow ? (int) $memberRow['id'] : null;

        // (a) Data membership: orgs the linked member belongs to.
        if ($_SESSION['member_id']) {
            $userOrgs = db_fetch_all(
                "SELECT mo.org_id, o.name AS org_name, o.short_name, mo.member_type_id
                 FROM " . db_table('member_organizations') . " mo
                 JOIN " . db_table('organizations') . " o ON mo.org_id = o.id
                 WHERE mo.member_id = ? AND mo.status = 'active' AND o.active = 1
                 ORDER BY o.sort_order, o.name",
                [$_SESSION['member_id']]
            );
        } else {
            $userOrgs = [];
        }

        // (b) Issue #56 (a beta tester 2026-07-04): ALSO include orgs the user holds
        // an org-scoped RBAC role for. An "Org Admin" assigned via user_roles
        // with scope_kind='org' but no matching member_organizations row was
        // landing with active_org_id = NULL. Because _rbac_scope_satisfied()
        // requires active_org_id === grant.scope_id for every org-scoped
        // grant, that NULL made EVERY screen/widget/action check fail — the
        // user saw the top menu but got "access denied" on every page despite
        // all permission boxes being checked. Union the role-scoped orgs in so
        // active_org_id matches the grant scope and the org-switcher lists
        // them. Guarded independently so a query failure (pre-RBAC-v2 schema,
        // etc.) never blocks login and never discards the member-org list.
        $seenOrg = [];
        $roleOrgIds = []; // GH #56 (Billy/K9OH): orgs this user holds an org-scoped role for
        foreach ($userOrgs as $o) { $seenOrg[(int) $o['org_id']] = true; }
        try {
            $roleOrgs = db_fetch_all(
                "SELECT DISTINCT ur.scope_id AS org_id, o.name AS org_name,
                        o.short_name, NULL AS member_type_id, o.sort_order AS _sort
                 FROM " . db_table('user_roles') . " ur
                 JOIN " . db_table('organizations') . " o ON o.id = ur.scope_id
                 WHERE ur.user_id = ?
                   AND ur.scope_kind = 'org'
                   AND ur.scope_id IS NOT NULL
                   AND o.active = 1
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY o.sort_order, o.name",
                [(int) $row['id']]
            );
            foreach ($roleOrgs as $ro) {
                $roleOrgIds[] = (int) $ro['org_id'];
                if (!isset($seenOrg[(int) $ro['org_id']])) {
                    $userOrgs[] = $ro;
                    $seenOrg[(int) $ro['org_id']] = true;
                }
            }
        } catch (Exception $e) {
            // Pre-RBAC-v2 (no scope_kind) or org-table drift — the member-org
            // list still stands; just skip the role-org union.
        }

        $_SESSION['user_orgs'] = $userOrgs;
        // active_org_id preference (GH #56):
        //   1. an org this user holds an org-scoped ROLE for — their authority
        //      lives there, so land in it (Billy/K9OH 2026-07-04: a user who is
        //      an org-2 admin but a member of org 1 was landing on org 1, so
        //      the org-2 grant never satisfied and they were denied everywhere);
        //   2. else the first member org;
        //   3. else NULL — no-org GLOBAL users still see all data (no org
        //      filter), per Eric 2026-06-02.
        if (!empty($roleOrgIds)) {
            $_SESSION['active_org_id'] = $roleOrgIds[0];
        } else {
            $_SESSION['active_org_id'] = !empty($userOrgs) ? (int) $userOrgs[0]['org_id'] : null;
        }
    } catch (Exception $e) {
        // Graceful fallback if org tables don't exist yet
        $_SESSION['member_id'] = null;
        $_SESSION['user_orgs'] = [];
        $_SESSION['active_org_id'] = null;
    }

    // Cleanup expired sessions periodically
    sm_cleanup_expired();

    // Phase 11d (2026-06-11): mobile-first routing driven by
    // roles.mobile_first instead of hardcoded level/role-id.
    // See the comment block at the top of login.php for the rationale.
    $loginRedirect = 'index.php';
    try {
        $mobileRole = db_fetch_one(
            "SELECT ur.id FROM " . db_table('user_roles') . " ur
             JOIN " . db_table('roles') . " r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND r.mobile_first = 1
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             LIMIT 1",
            [(int) $row['id']]
        );
        if ($mobileRole) {
            $loginRedirect = 'mobile.php';
        }
    } catch (Exception $e) {
        // Schema may be pre-Phase-11d — fall back to dashboard.
    }
    // a beta tester GH #13-followup (2026-07-03) — if this login is bound
    // for mobile.php, the session_start() there will run under the
    // MOBILE profile (TCADMOBILE cookie) and won't see the login
    // data we just wrote under the DESKTOP profile (PHPSESSID). That
    // meant every mobile-first user was kicked back to login on the
    // very redirect that was supposed to send them to their unit
    // interface. Migrate the session snapshot into the mobile
    // profile before emitting the redirect so mobile.php's auth
    // check finds a populated $_SESSION on the next request.
    if ($loginRedirect === 'mobile.php') {
        sess_migrate_to_mobile();
    }
    header('Location: ' . $loginRedirect);
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  HANDLE 2FA VERIFICATION (step 2)
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tfa_verify'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Please try again.';
    } else {
        $pendingUserId = (int) ($_SESSION['tfa_pending_user_id'] ?? 0);
        $pendingTheme = $_SESSION['tfa_pending_theme'] ?? 'Day';
        // 2026-06-11 (Phase 10c): trusted-proxy-aware client IP.
        require_once __DIR__ . '/inc/client-ip.php';
        $pendingIp = $_SESSION['tfa_pending_ip']
            ?? (function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        if ($pendingUserId <= 0) {
            $error = 'Session expired. Please log in again.';
        } else {
            // Hour-3 hardening: rate-limit TFA verification. Without this,
            // an attacker who has captured a valid password can brute-force
            // the 6-digit TOTP code (~10^6 keys). We piggy-back on the same
            // login_security counter used for password attempts so a TFA
            // failure burns the per-user budget too.
            $tfaUser = $_SESSION['tfa_pending_user'] ?? '';
            if ($tfaUser === '') {
                // Resolve username from id once and cache for the session.
                $u = db_fetch_one(
                    "SELECT `user` FROM " . db_table('user') . " WHERE `id` = ? LIMIT 1",
                    [$pendingUserId]
                );
                $tfaUser = $u['user'] ?? ('user#' . $pendingUserId);
                $_SESSION['tfa_pending_user'] = $tfaUser;
            }

            if (ls_is_locked($tfaUser)) {
                $lockoutSeconds = ls_get_lockout_remaining($tfaUser);
                $lockoutMin = (int) ceil($lockoutSeconds / 60);
                $error = 'Too many TFA failures — account locked for ' . $lockoutMin
                    . ' minute' . ($lockoutMin !== 1 ? 's' : '') . '.';
                ls_record_attempt($tfaUser, false, $pendingIp, 'tfa_locked');
            } else {
                $tfaCode = trim($_POST['tfa_code'] ?? '');

                if ($tfaCode === '') {
                    $error = 'Please enter your authentication code.';
                    $showTfaForm = true;
                } else {
                    if (tfa_verify_login($pendingUserId, $tfaCode)) {
                        // Successful TFA — clear the failure counter
                        ls_record_attempt($tfaUser, true, $pendingIp);
                        ls_clear_attempts($tfaUser);

                        // Phase 104e (a beta tester GH #6) — stash the client-collected
                        // fingerprint attributes so tfa_device_fingerprint() picks
                        // them up when issuing the remember-device cookie below.
                        $_tfaClientFP = trim((string) ($_POST['tfa_client_fp'] ?? ''));
                        if ($_tfaClientFP !== '') {
                            $decoded = @json_decode($_tfaClientFP, true);
                            if (is_array($decoded)) {
                                $_SESSION['_tfa_client_fp'] = array_intersect_key($decoded, [
                                    'tz' => 1, 'sc' => 1, 'pl' => 1
                                ]);
                            }
                        }

                        // 2FA verified — handle "remember device" checkbox
                        if (!empty($_POST['tfa_remember']) && tfa_check_trusted_network()) {
                            tfa_remember_device($pendingUserId);
                        }

                        // Load user row and complete login
                        $row = db_fetch_one(
                            "SELECT * FROM " . db_table('user') . " WHERE `id` = ? LIMIT 1",
                            [$pendingUserId]
                        );
                        if ($row) {
                            complete_login($row, $pendingTheme, $pendingIp);
                        } else {
                            $error = 'Account not found. Please log in again.';
                        }
                    } else {
                        // Failed TFA — record attempt; ls_record_attempt
                        // triggers lockout once max_attempts is reached.
                        ls_record_attempt($tfaUser, false, $pendingIp, 'wrong_tfa');
                        audit_login($pendingUserId, $tfaUser, 'tfa_failed',
                            "TFA verification failed for '{$tfaUser}'", ['ip' => $pendingIp]);
                        $error = 'Invalid authentication code. Please try again.';
                        $showTfaForm = true;
                        $tfaBackupMode = !empty($_POST['tfa_backup_mode']);
                    }
                }
            }
        }
    }
}

// Phase 99i (Billy beta 2026-06-29) — CJIS click-through notice.
// Admin-configurable banner shown above the login form. When
// enabled, the user must check a "I have read and agree" box
// before login is accepted. Settings keys live in the `settings`
// table; render path reads + applies them; POST path enforces.
$cjisNoticeEnabled = false;
$cjisNoticeText    = '';
try {
    $cjisNoticeEnabled = db_fetch_value(
        "SELECT value FROM " . db_table('settings') . " WHERE name = ? LIMIT 1",
        ['cjis_login_notice_enabled']
    ) === '1';
    if ($cjisNoticeEnabled) {
        $cjisNoticeText = (string) db_fetch_value(
            "SELECT value FROM " . db_table('settings') . " WHERE name = ? LIMIT 1",
            ['cjis_login_notice_text']
        );
    }
} catch (Exception $e) {
    // settings table missing or pre-99i — silently leave notice off.
}

// ═══════════════════════════════════════════════════════════════
//  HANDLE LOGIN (step 1 — username/password)
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['tfa_verify'])) {
    // CSRF check
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Please try again.';
    } elseif ($cjisNoticeEnabled && empty($_POST['cjis_accepted'])) {
        // Phase 99i: CJIS click-through enforcement. The checkbox
        // is required: missing it = explicit non-acceptance = no
        // access. Server-side validation; the form also has JS to
        // disable the submit button until checked.
        $error = 'You must read and accept the system-use notice before logging in.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $submittedUsername = $username;
        // 2026-06-11 (Phase 10c): trusted-proxy-aware client IP for
        // lockout tracking + audit. This was REMOTE_ADDR (= NPM
        // loopback in the reverse-proxied deploy).
        require_once __DIR__ . '/inc/client-ip.php';
        $clientIp = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Decrypt password if it was encrypted by field-encrypt.js
        $password = fe_decrypt_field($password);
        if ($password === false) {
            $error = 'Decryption failed. Please try again.';
            $password = '';
        }

        if ($username === '' || $password === '') {
            $error = t('login.err.missing', 'Please enter both username and password.');
        // Check lockout BEFORE password verification
        } elseif (ls_is_locked($username)) {
            $lockoutSeconds = ls_get_lockout_remaining($username);
            $lockoutMin = (int) ceil($lockoutSeconds / 60);
            // Lockout message kept English for now: it embeds a duration that
            // pluralizes in English-specific ways. Future enhancement: split
            // into 'login.err.locked_one' / 'login.err.locked_other' to
            // support proper plural forms in other languages.
            $error = t('login.err.locked', 'Account temporarily locked due to too many failed attempts.') .
                     ' Try again in ' . $lockoutMin . ' minute' . ($lockoutMin !== 1 ? 's' : '') . '.';
            ls_record_attempt($username, false, $clientIp, 'account_locked');
            audit_login(null, $username, 'login_blocked', "Login blocked: account locked for '{$username}'");
        } else {
            $row = db_fetch_one(
                "SELECT * FROM " . db_table('user') . " WHERE `user` = ? LIMIT 1",
                [$username]
            );

            $auth = $row ? verify_password($password, $row['passwd']) : ['valid' => false, 'needs_rehash' => false];

            if ($auth['valid']) {
                // Check can_login flag (CAD user toggle)
                $canLogin = true;
                try {
                    if (isset($row['can_login']) && (int) $row['can_login'] === 0) {
                        $canLogin = false;
                    }
                } catch (Exception $e) {}

                if (!$canLogin) {
                    $error = t('login.err.disabled', 'This account is disabled.') . ' Contact an administrator.';
                    ls_record_attempt($username, false, $clientIp, 'account_disabled');
                    audit_log('auth', 'login', 'user', $row['id'], "Login denied: account disabled for '{$username}'", null, AUDIT_HIGH);
                } else {

                // Rehash legacy MD5 to bcrypt
                if ($auth['needs_rehash']) {
                    db_query(
                        "UPDATE " . db_table('user') . " SET `passwd` = ? WHERE `id` = ? LIMIT 1",
                        [hash_new_password($password), $row['id']]
                    );
                }

                // Record successful password verification and clear failed attempts
                ls_record_attempt($username, true, $clientIp);
                ls_clear_attempts($username);

                // ── 2FA check ──────────────────────────────────────────
                $tfaSettings = tfa_get_settings();
                $userHasTfa = tfa_is_enabled((int) $row['id']);
                $tfaRequired = tfa_is_required_for_user((int) $row['id'], (int) $row['level']);

                if ($userHasTfa && $tfaSettings['tfa_enabled']) {
                    // User has 2FA enrolled — check if device is remembered
                    if (tfa_is_device_remembered((int) $row['id'])) {
                        // Device remembered — skip 2FA, complete login
                        complete_login($row, $_POST['theme'] ?? 'Day', $clientIp);
                    } else {
                        // Need 2FA verification — store pending state in session
                        session_regenerate_id(true);
                        $_SESSION['tfa_pending_user_id'] = (int) $row['id'];
                        $_SESSION['tfa_pending_theme'] = $_POST['theme'] ?? 'Day';
                        $_SESSION['tfa_pending_ip'] = $clientIp;
                        $showTfaForm = true;
                    }
                } elseif (!$userHasTfa && $tfaSettings['tfa_enabled'] && $tfaRequired) {
                    // User's role requires 2FA but they haven't enrolled yet
                    // Log them in with a restricted session — force redirect to enrollment
                    complete_login($row, $_POST['theme'] ?? 'Day', $clientIp);
                    $_SESSION['tfa_enrollment_required'] = true;
                } else {
                    // No 2FA required or not enabled — complete login normally
                    complete_login($row, $_POST['theme'] ?? 'Day', $clientIp);
                }

                } // end canLogin else
            } else {
                // Record failed attempt
                ls_record_attempt($username, false, $clientIp, 'wrong_password');
                audit_login(null, $username, 'login_failed', "Login failed for '{$username}'", [
                    'ip' => $clientIp,
                ]);

                // Check if this failure triggers a lockout
                $recentFailures = ls_get_recent_failure_count($username);
                $settings = ls_get_settings();
                if ($recentFailures >= $settings['max_attempts']) {
                    ls_alert_admin($username, $clientIp);
                    $lockoutSeconds = ls_get_lockout_remaining($username);
                    $lockoutMin = (int) ceil($lockoutSeconds / 60);
                    $error = 'Too many failed attempts. Account locked for ' . $lockoutMin . ' minute' . ($lockoutMin !== 1 ? 's' : '') . '.';
                } else {
                    $remaining = $settings['max_attempts'] - $recentFailures;
                    $error = t('login.err.invalid', 'Invalid username or password.');
                    if ($remaining <= 2) {
                        $error .= ' ' . $remaining . ' attempt' . ($remaining !== 1 ? 's' : '') . ' remaining before lockout.';
                    }
                }
            }
        }
    }
}

$csrf = csrf_token();
$isTrustedNetwork = tfa_check_trusted_network();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?> - <?php echo e(t('login.btn.submit', 'Log In')); ?></title>
    <!-- 2026-06-14 (Phase 49): PWA installability bits also live on the
         login page — it's the first page mobile users hit, and Chrome
         needs <link rel="manifest"> on the *current* page to enable
         the "Install app" prompt. Without these, tapping "Install"
         on the login page produced "URL could not be found" because
         the install handshake had no manifest to reference. -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1570ef">
    <link rel="apple-touch-icon" href="assets/logo-light.png">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            border-radius: 1rem;
        }
        /* Phase 99i follow-on (Eric beta 2026-06-29): when the CJIS
           notice is enabled, the default 420px login card is too
           narrow — full system-use notices typically run several
           hundred words and forcing internal scroll defeats the
           "read before you log in" purpose. .login-card-wide
           expands the card to 720px so a typical CJIS banner is
           fully readable without scrolling. */
        .login-card-wide {
            max-width: 720px;
        }
        @media (max-width: 760px) {
            .login-card-wide {
                max-width: 100%;
            }
        }
        .theme-toggle {
            cursor: pointer;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.2s;
            /* Phase 44 (a11y): reset <button> chrome since these used to be
               <span>s before — keeps the visual identical. */
            border: 0;
            background: transparent;
            font: inherit;
            color: inherit;
            line-height: 1.5;
        }
        .theme-toggle.active {
            background-color: var(--bs-primary);
            color: white;
        }
        .theme-toggle:not(.active) {
            background-color: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
        }
        .tfa-code-input {
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5em;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="login-card<?php echo $cjisNoticeEnabled ? ' login-card-wide' : ''; ?> card shadow-lg">
        <div class="card-body p-4">
            <?php echo https_warning_banner(); ?>
            <div class="text-center mb-4">
                <i class="bi bi-broadcast-pin fs-1 text-primary"></i>
                <h4 class="mt-2"><?php echo e(t('login.title', 'Tickets NewUI')); ?></h4>
                <small class="text-body-secondary">v<?php echo e(NEWUI_VERSION); ?></small>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <?php echo e($error); ?>
                    <?php if ($lockoutSeconds > 0): ?>
                        <div class="mt-1 small" id="lockoutCountdown" data-seconds="<?php echo (int) $lockoutSeconds; ?>"></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // Show recent failure count if there were recent failures but no lockout
            if ($submittedUsername !== '' && $lockoutSeconds === 0 && $recentFailures > 0 && $error !== ''):
            ?>
                <div class="alert alert-warning py-2 small" role="alert">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    <?php echo (int) $recentFailures; ?> <?php echo e(t('login.recent_failures', 'recent failed login attempt(s) on this account.')); ?>
                </div>
            <?php endif; ?>

            <?php if ($showTfaForm): ?>
            <!-- ═══════════ 2FA VERIFICATION FORM ═══════════ -->
            <div class="text-center mb-3">
                <i class="bi bi-shield-lock fs-3 text-warning"></i>
                <p class="small text-body-secondary mt-1 mb-0"><?php echo e(t('login.tfa.heading', 'Two-factor authentication required')); ?></p>
            </div>

            <form method="post" action="login.php" id="tfaForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="tfa_verify" value="1">
                <input type="hidden" name="tfa_backup_mode" id="tfaBackupMode" value="<?php echo $tfaBackupMode ? '1' : '0'; ?>">

                <div class="mb-3" id="tfaCodeGroup">
                    <label for="tfaCode" class="form-label" id="tfaCodeLabel">
                        <?php echo e($tfaBackupMode
                            ? t('login.tfa.label_backup', 'Backup Code')
                            : t('login.tfa.label_auth',   'Authentication Code')); ?>
                    </label>
                    <input type="text" class="form-control tfa-code-input" id="tfaCode" name="tfa_code"
                           maxlength="<?php echo $tfaBackupMode ? '8' : '6'; ?>"
                           inputmode="numeric" pattern="[0-9]*"
                           placeholder="<?php echo $tfaBackupMode ? '12345678' : '000000'; ?>"
                           autocomplete="one-time-code" autofocus required>
                    <div class="form-text text-center" id="tfaHelpText">
                        <?php if ($tfaBackupMode): ?>
                            <?php echo e(t('login.tfa.help_backup', 'Enter one of your 8-digit backup codes.')); ?>
                        <?php else: ?>
                            <?php echo e(t('login.tfa.help_auth', 'Enter the 6-digit code from your authenticator app.')); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isTrustedNetwork): ?>
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="tfaRemember" name="tfa_remember" value="1">
                        <label class="form-check-label small" for="tfaRemember">
                            <?php echo e(t('login.tfa.remember', 'Remember this device for')); ?>
                            <?php echo (int) tfa_get_settings()['tfa_remember_days']; ?>
                            <?php echo e(t('login.tfa.days', 'days')); ?>
                        </label>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Phase 104e (a beta tester GH #6) — client-side attributes stitched
                     into the device fingerprint so a stolen tfa_remember cookie
                     has to reproduce the timezone + screen + platform of the
                     original device, not just the UA + Accept-Language. -->
                <input type="hidden" name="tfa_client_fp" id="tfaClientFp" value="">
                <script>
                (function () {
                    try {
                        var fp = {
                            tz: (Intl && Intl.DateTimeFormat)
                                ? (new Intl.DateTimeFormat()).resolvedOptions().timeZone || ''
                                : '',
                            sc: (window.screen && screen.availWidth && screen.availHeight)
                                ? (screen.availWidth + 'x' + screen.availHeight)
                                : '',
                            pl: (navigator && navigator.platform) ? navigator.platform : ''
                        };
                        var el = document.getElementById('tfaClientFp');
                        if (el) el.value = JSON.stringify(fp);
                    } catch (e) { /* silent — server treats missing as empty */ }
                })();
                </script>

                <button type="submit" class="btn btn-primary w-100 py-2 mb-2">
                    <i class="bi bi-shield-check me-1"></i> <?php echo e(t('login.tfa.verify', 'Verify')); ?>
                </button>

                <div class="text-center">
                    <button type="button" class="btn btn-link btn-sm text-body-secondary" id="toggleBackupMode">
                        <?php echo e($tfaBackupMode
                            ? t('login.tfa.use_auth',   'Use authenticator app instead')
                            : t('login.tfa.use_backup', 'Use a backup code instead')); ?>
                    </button>
                </div>

                <div class="text-center mt-2">
                    <a href="login.php" class="btn btn-link btn-sm text-body-secondary">
                        <i class="bi bi-arrow-left me-1"></i><?php echo e(t('login.tfa.back', 'Back to login')); ?>
                    </a>
                </div>
            </form>

            <?php else: ?>
            <!-- ═══════════ NORMAL LOGIN FORM ═══════════ -->
            <form method="post" action="login.php" id="loginForm" data-encrypt="true">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="theme" id="themeInput" value="Day">

                <?php if ($cjisNoticeEnabled && $cjisNoticeText !== ''): ?>
                <!-- Phase 99i (Billy beta 2026-06-29) — CJIS click-
                     through notice. Admin-configurable text above the
                     form; user must check the box to enable submit.
                     Server-side validation enforces this regardless
                     of JS (POST handler above). -->
                <div class="mb-3">
                    <div class="card border-warning">
                        <!-- Phase 99i follow-on (Eric beta 2026-06-29):
                             max-height bumped from 240px to 50vh and the
                             card itself widens via .login-card-wide above,
                             so a typical CJIS notice reads without forced
                             scrolling on a normal-sized screen. -->
                        <div class="card-body" style="max-height: 50vh; overflow-y: auto; font-size: 0.85rem; white-space: pre-wrap;"><?php echo e($cjisNoticeText); ?></div>
                    </div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="cjisAccept" name="cjis_accepted" value="1" required>
                    <label class="form-check-label small" for="cjisAccept">
                        I have read and agree to the system-use notice above.
                    </label>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="username" class="form-label"><?php echo e(t('login.form.username', 'Username')); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               maxlength="255" autocomplete="username" autofocus required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label"><?php echo e(t('login.form.password', 'Password')); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               maxlength="255" autocomplete="current-password" required data-sensitive="true">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label d-block"><?php echo e(t('login.form.theme', 'Theme')); ?></label>
                    <div class="d-flex gap-2 justify-content-center">
                        <!-- Phase 44 (a11y): <button> instead of <span onclick> so the
                             theme picker gets keyboard focus + Enter/Space activation
                             for free. The .theme-toggle CSS class drives the look. -->
                        <button type="button" class="theme-toggle active" id="dayBtn" onclick="setTheme('Day')">
                            <i class="bi bi-sun-fill me-1"></i> <?php echo e(t('login.form.theme_day', 'Day')); ?>
                        </button>
                        <button type="button" class="theme-toggle" id="nightBtn" onclick="setTheme('Night')">
                            <i class="bi bi-moon-fill me-1"></i> <?php echo e(t('login.form.theme_night', 'Night')); ?>
                        </button>
                    </div>
                </div>

                <?php
                // Phase 8b: language picker on login page. Only render if
                // ≥2 languages are enabled in the registry (single-language
                // installs don't need a switcher). The picker is a plain
                // <select> wrapped in a form-style label so it visually
                // matches the Theme block above.
                $_login_langs = i18n_language_registry();
                $_login_curr  = i18n_lang();
                if (count($_login_langs) >= 2):
                ?>
                <div class="mb-4">
                    <label for="loginLangSelect" class="form-label d-block"><?php echo e(t('login.form.language', 'Language')); ?></label>
                    <select id="loginLangSelect" class="form-select form-select-sm" onchange="onLoginLangChange(this.value)">
                        <?php foreach ($_login_langs as $L): ?>
                        <option value="<?php echo e($L['code']); ?>"<?php echo $L['code'] === $_login_curr ? ' selected' : ''; ?>>
                            <?php
                            // Prefer admin-customized native name; fall back to display name.
                            echo e($L['native_name'] !== '' ? $L['native_name'] : $L['display_name']);
                            echo ' (' . e(strtoupper($L['code'])) . ')';
                            ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary w-100 py-2" id="loginSubmitBtn"
                        <?php echo $cjisNoticeEnabled ? 'disabled' : ''; ?>>
                    <i class="bi bi-box-arrow-in-right me-1"></i> <?php echo e(t('login.btn.submit', 'Log In')); ?>
                </button>
            </form>
            <?php if ($cjisNoticeEnabled): ?>
            <script>
                // Phase 99i: gate submit button on the CJIS checkbox.
                (function () {
                    var cb = document.getElementById('cjisAccept');
                    var btn = document.getElementById('loginSubmitBtn');
                    if (!cb || !btn) return;
                    cb.addEventListener('change', function () {
                        btn.disabled = !this.checked;
                    });
                })();
            </script>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <?php echo fe_inject_js(); ?>
    <script>
        function setTheme(theme) {
            document.getElementById('themeInput').value = theme;
            var dayBtn = document.getElementById('dayBtn');
            var nightBtn = document.getElementById('nightBtn');
            if (theme === 'Day') {
                dayBtn.classList.add('active');
                nightBtn.classList.remove('active');
                document.documentElement.setAttribute('data-bs-theme', 'light');
            } else {
                nightBtn.classList.add('active');
                dayBtn.classList.remove('active');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            }
            try { localStorage.setItem('ticketsTheme', theme); } catch (e) {}
        }

        // Phase 8b: language selector on the login page. Selecting a
        // language reloads the page with ?lang=<code>, which the server
        // validates against the registry and stashes in the session.
        // Username/password aren't yet entered so a reload is cheap.
        function onLoginLangChange(code) {
            if (!code) return;
            // Stay on login.php; if we're already there with a path,
            // append/replace the lang query param without losing other
            // params (rare, but defensive).
            var url = new URL(window.location.href);
            url.searchParams.set('lang', code);
            window.location.href = url.toString();
        }
        // Restore theme from localStorage on page load
        (function () {
            try {
                var saved = localStorage.getItem('ticketsTheme');
                if (saved) { setTheme(saved); }
            } catch (e) {}
        })();

        // Lockout countdown timer
        (function () {
            var el = document.getElementById('lockoutCountdown');
            if (!el) return;
            var secs = parseInt(el.getAttribute('data-seconds'), 10) || 0;
            if (secs <= 0) return;

            function update() {
                if (secs <= 0) {
                    el.textContent = 'Lockout expired. You may try again.';
                    var btn = document.querySelector('button[type="submit"]');
                    if (btn) btn.disabled = false;
                    return;
                }
                var m = Math.floor(secs / 60);
                var s = secs % 60;
                el.textContent = 'Time remaining: ' + m + ':' + (s < 10 ? '0' : '') + s;
                secs--;
                setTimeout(update, 1000);
            }

            // Disable submit while locked
            var btn = document.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            update();
        })();

        // 2FA form interactions
        (function () {
            'use strict';

            // Backup code toggle
            var toggleBtn = document.getElementById('toggleBackupMode');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    var modeInput = document.getElementById('tfaBackupMode');
                    var codeInput = document.getElementById('tfaCode');
                    var label = document.getElementById('tfaCodeLabel');
                    var helpText = document.getElementById('tfaHelpText');
                    var isBackup = modeInput.value === '1';

                    if (isBackup) {
                        // Switch to TOTP mode
                        modeInput.value = '0';
                        codeInput.maxLength = 6;
                        codeInput.placeholder = '000000';
                        label.textContent = 'Authentication Code';
                        helpText.textContent = 'Enter the 6-digit code from your authenticator app.';
                        toggleBtn.textContent = 'Use a backup code instead';
                    } else {
                        // Switch to backup mode
                        modeInput.value = '1';
                        codeInput.maxLength = 8;
                        codeInput.placeholder = '12345678';
                        label.textContent = 'Backup Code';
                        helpText.textContent = 'Enter one of your 8-digit backup codes.';
                        toggleBtn.textContent = 'Use authenticator app instead';
                    }
                    codeInput.value = '';
                    codeInput.focus();
                });
            }

            // Auto-submit when 6 digits entered (TOTP mode only)
            var tfaCode = document.getElementById('tfaCode');
            if (tfaCode) {
                tfaCode.addEventListener('input', function () {
                    var modeInput = document.getElementById('tfaBackupMode');
                    var val = this.value.replace(/[^0-9]/g, '');
                    this.value = val;
                    if (modeInput.value === '0' && val.length === 6) {
                        document.getElementById('tfaForm').submit();
                    }
                });
            }
        })();
    </script>
</body>
</html>
