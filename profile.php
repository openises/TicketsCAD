<?php
/**
 * NewUI v4.0 - User Profile / Security
 *
 * Self-service Two-Factor Authentication management:
 *   - View 2FA enrollment status
 *   - Enroll (password confirm -> QR code -> verify code -> backup codes)
 *   - Manage remembered devices (list / revoke)
 *   - Regenerate backup codes
 *   - Disable 2FA (requires password + TOTP code)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/password-policy.php';

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level    = current_role_name();
// Phase 12 (2026-06-11): user's active role name(s) shown on the
// profile page. Driven entirely by RBAC.
$userRoles = '';
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT r.name FROM `{$prefix}user_roles` ur
         JOIN `{$prefix}roles` r ON r.id = ur.role_id
         WHERE ur.user_id = ?
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         ORDER BY r.sort_order, r.name",
        [(int) $_SESSION['user_id']]
    );
    if (!empty($rows)) {
        $names = [];
        foreach ($rows as $r) $names[] = $r['name'];
        $userRoles = implode(', ', $names);
    }
} catch (Exception $e) {
    // user_roles missing — silently leave $userRoles empty.
}
if ($userRoles === '') {
    $userRoles = $level;
}
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'profile';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('profile.title', 'My Account')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/profile.css?v=<?php echo asset_v('assets/css/profile.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<?php
// Phase 9 (2026-06-08): forced password-change mode.
// Active when either:
//   * the page was reached via ?force_pw=1 (the redirect target)
//   * the session flag is set (so a direct profile.php hit also locks down)
// In forced mode we show a top banner, auto-activate the Change Password
// tab, and hide the Profile + Security tabs so the user can only:
//   - change their password (which clears the flag)
//   - log out (via the navbar user menu)
$forcePwMode = !empty($_SESSION['must_change_password'])
            || (isset($_GET['force_pw']) && $_GET['force_pw'] === '1');
?>

<!-- Page Content -->
<div class="container-fluid p-3">

    <?php if ($forcePwMode): ?>
    <div class="alert alert-warning d-flex align-items-center mb-3" id="forcePwBanner" role="alert">
        <i class="bi bi-shield-exclamation me-2 fs-5"></i>
        <div>
            <strong><?php echo e(t('force_pw.banner_title', 'Password change required.')); ?></strong><br>
            <?php echo e(t('force_pw.banner_body', 'Your administrator requires you to choose your own password before continuing. Use the form below. Other pages are unavailable until you complete this step.')); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['tfa_enrollment_required'])): ?>
    <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div>
            <strong>Two-factor authentication is required for your account.</strong><br>
            Your administrator has enabled mandatory 2FA for your role. Please complete the setup below before continuing.
            You will be redirected here until 2FA enrollment is complete.
        </div>
    </div>
    <?php endif; ?>

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-person-circle text-primary me-2"></i><?php echo e(t('profile.title', 'My Account')); ?>
        </h5>
        <?php if (!$forcePwMode): ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
        </a>
        <?php else: ?>
        <a href="login.php?logout=1" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-box-arrow-right me-1"></i><?php echo e(t('nav.user.logout', 'Log Out')); ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- Tabs (Phase 9: in forced-pw-change mode, hide Profile + Security so
         the user can only complete the password change or log out) -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item<?php echo $forcePwMode ? ' d-none' : ''; ?>" role="presentation">
            <button class="nav-link<?php echo $forcePwMode ? '' : ' active'; ?>" id="tab-profile" data-bs-toggle="tab" data-bs-target="#pane-profile" type="button" role="tab" aria-selected="<?php echo $forcePwMode ? 'false' : 'true'; ?>"<?php echo $forcePwMode ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>
                <i class="bi bi-person me-1"></i><?php echo e(t('profile.tab.profile', 'Profile')); ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?php echo $forcePwMode ? ' active' : ''; ?>" id="tab-password" data-bs-toggle="tab" data-bs-target="#pane-password" type="button" role="tab" aria-selected="<?php echo $forcePwMode ? 'true' : 'false'; ?>">
                <i class="bi bi-key me-1"></i><?php echo e(t('profile.tab.password', 'Change Password')); ?>
            </button>
        </li>
        <li class="nav-item<?php echo $forcePwMode ? ' d-none' : ''; ?>" role="presentation">
            <button class="nav-link" id="tab-security" data-bs-toggle="tab" data-bs-target="#pane-security" type="button" role="tab"<?php echo $forcePwMode ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>
                <i class="bi bi-shield-lock me-1"></i><?php echo e(t('profile.tab.security', 'Security')); ?>
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- Profile Tab -->
    <div class="tab-pane fade<?php echo $forcePwMode ? '' : ' show active'; ?>" id="pane-profile" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-person-lines-fill me-1"></i> <?php echo e(t('profile.card.my_profile', 'My Profile')); ?></h6>
                <form id="profileForm">
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small"><?php echo e(t('profile.label.username', 'Username')); ?></label>
                            <input type="text" class="form-control form-control-sm" value="<?php echo $user; ?>" disabled>
                            <div class="form-text"><?php echo e(t('profile.username_locked', 'Username cannot be changed.')); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small"><?php echo e(t('profile.label.display_name', 'Display Name')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="profileDisplayName"
                                   value="<?php echo e($_SESSION['user'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small"><?php echo e(t('profile.label.role', 'Role')); ?></label>
                            <input type="text" class="form-control form-control-sm" value="<?php echo e($userRoles); ?>" disabled>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small"><?php echo e(t('profile.label.email', 'Email')); ?></label>
                            <input type="email" class="form-control form-control-sm" id="profileEmail" placeholder="your@email.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small"><?php echo e(t('profile.label.phone', 'Phone')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="profilePhone" placeholder="555-1234">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small"><?php echo e(t('profile.label.callsign', 'Callsign')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="profileCallsign" placeholder="N0CALL">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success mt-2" id="btnSaveProfile">
                        <i class="bi bi-check-lg me-1"></i><?php echo e(t('profile.btn.save_profile', 'Save Profile')); ?>
                    </button>
                    <span class="small text-success ms-2 d-none" id="profileSaved"><?php echo e(t('profile.saved', 'Saved!')); ?></span>
                </form>
            </div>
        </div>

        <!-- Phase 57 — PWA install (re-prompt). Chrome stashes one
             beforeinstallprompt event per page load; we capture it
             so the user can fire it on demand even after dismissing
             or uninstalling. -->
        <div class="card mt-3 d-none" id="pwaInstallCard">
            <div class="card-body">
                <h6 class="card-title">
                    <i class="bi bi-phone-fill me-1"></i> Install Tickets as a Mobile App
                </h6>
                <p class="text-body-secondary small mb-2" id="pwaInstallHint">
                    Install Tickets to your home screen for one-tap access and a full-screen view. Works on Android (Chrome / Edge) and desktop Chrome / Edge.
                </p>
                <button type="button" class="btn btn-sm btn-primary" id="btnPwaInstall">
                    <i class="bi bi-download me-1"></i>Install App
                </button>
                <span class="small text-success ms-2 d-none" id="pwaInstalledMsg">
                    <i class="bi bi-check-circle me-1"></i>Installed!
                </span>
                <div class="alert alert-info small mt-2 mb-0 d-none" id="pwaManualHint">
                    <strong>Already installed once, or browser hasn't offered yet?</strong><br>
                    Open Chrome / Edge → tap the three-dot menu → look for <em>Install app</em> or <em>Add to Home screen</em>. On iOS Safari → Share button → <em>Add to Home Screen</em>. The browser remembers if you previously uninstalled and may not auto-prompt for ~3 months — the manual menu always works.
                </div>
            </div>
        </div>

        <!-- Phase 54 — Personal-resource clock-in -->
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="card-title">
                    <i class="bi bi-person-fill-check me-1"></i> Personal Resource
                    <span class="badge ms-2" id="puBadge">…</span>
                </h6>
                <p class="text-body-secondary small mb-2">
                    Clock yourself in as a one-person resource. A unit named after your callsign or full name becomes assignable to incidents and visible on the dispatcher map.
                    Use this when you're working solo and not part of a multi-person unit.
                </p>
                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-success d-none" id="btnClockIn">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Clock In as Personal Resource
                    </button>
                    <button type="button" class="btn btn-sm btn-warning d-none" id="btnClockOut">
                        <i class="bi bi-box-arrow-right me-1"></i>Clock Out
                    </button>
                    <small class="text-body-secondary" id="puStatusLine"></small>
                </div>
            </div>
        </div>

        <!-- Display Preferences (per-browser, stored in localStorage) -->
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-palette me-1"></i> <?php echo e(t('profile.card.display_prefs', 'Display Preferences')); ?></h6>
                <p class="text-body-secondary small mb-2"><?php echo e(t('profile.display_prefs.note', 'These settings are saved per-browser and apply only to this device.')); ?></p>
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label small">Menu label fade delay</label>
                        <select class="form-select form-select-sm" id="prefNavFade">
                            <option value="0">Always show labels</option>
                            <option value="3000">3 seconds</option>
                            <option value="5000">5 seconds</option>
                            <option value="10000">10 seconds (default)</option>
                            <option value="15000">15 seconds</option>
                            <option value="30000">30 seconds</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Light theme basemap</label>
                        <select class="form-select form-select-sm" id="prefBasemapLight">
                            <option value="street">Street Map (OSM)</option>
                            <option value="dark">Dark (CartoDB)</option>
                            <option value="terrain">Terrain (OpenTopo)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Dark theme basemap</label>
                        <select class="form-select form-select-sm" id="prefBasemapDark">
                            <option value="street">Street Map (OSM)</option>
                            <option value="dark">Dark (CartoDB)</option>
                            <option value="terrain">Terrain (OpenTopo)</option>
                        </select>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-success" id="btnSaveDisplayPrefs">
                    <i class="bi bi-check-lg me-1"></i><?php echo e(t('profile.btn.save_display_prefs', 'Save Display Preferences')); ?>
                </button>
                <span class="small text-success ms-2 d-none" id="displayPrefsSaved"><?php echo e(t('profile.saved', 'Saved!')); ?></span>
            </div>
        </div>
    </div>

    <!-- Change Password Tab -->
    <div class="tab-pane fade<?php echo $forcePwMode ? ' show active' : ''; ?>" id="pane-password" role="tabpanel">
        <div class="card" style="max-width: 480px;">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-key me-1"></i> <?php echo e(t('profile.card.change_password', 'Change Password')); ?></h6>
                <form id="passwordForm">
                    <div class="mb-2">
                        <label class="form-label small"><?php echo e(t('profile.label.current_password', 'Current Password')); ?></label>
                        <input type="password" class="form-control form-control-sm" id="currentPassword"
                               autocomplete="current-password" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small"><?php echo e(t('profile.label.new_password', 'New Password')); ?></label>
                        <input type="password" class="form-control form-control-sm" id="newPassword"
                               autocomplete="new-password" required minlength="<?php echo (int) pw_min_length(); ?>">
                        <?php
                        // Policy text is driven by the live policy module, not a
                        // hardcoded string — keeps the UI honest about what the
                        // server actually enforces (length + complexity).
                        // Use a %d-format key (not the legacy literal
                        // 'profile.password_min', which was hardcoded "6") so the
                        // displayed minimum always tracks the real policy.
                        $pwHelp = sprintf(t('profile.password_min_fmt', 'Minimum %d characters.'), pw_min_length());
                        if (pw_require_complexity()) {
                            $pwHelp .= ' ' . t('profile.password_complexity', 'Must include at least one letter and one number. Common passwords are rejected.');
                        }
                        ?>
                        <div class="form-text"><?php echo e($pwHelp); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small"><?php echo e(t('profile.label.confirm_password', 'Confirm New Password')); ?></label>
                        <input type="password" class="form-control form-control-sm" id="confirmPassword"
                               autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnChangePassword">
                        <i class="bi bi-key me-1"></i><?php echo e(t('profile.btn.change_password', 'Change Password')); ?>
                    </button>
                    <div class="alert alert-success small mt-2 d-none" id="passwordSuccess">
                        <i class="bi bi-check-circle me-1"></i><span id="passwordSuccessText"><?php echo e(t('profile.password_changed', 'Password changed successfully.')); ?></span>
                    </div>
                    <div class="alert alert-danger small mt-2 d-none" id="passwordError"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Security Tab (existing 2FA content) -->
    <div class="tab-pane fade" id="pane-security" role="tabpanel">

    <div class="row g-3">
        <!-- Left column: 2FA Status + Enrollment -->
        <div class="col-lg-8">

            <!-- Section 1: 2FA Status -->
            <div class="card mb-3" id="tfaStatusCard">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-shield-check me-2"></i><?php echo e(t('nav.user.tfa', 'Two-Factor Authentication')); ?></span>
                    <span id="tfaStatusBadge" class="badge bg-secondary"><?php echo e(t('common.loading', 'Loading...')); ?></span>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary mb-3" id="tfaStatusText">Checking 2FA status...</p>
                    <div id="tfaActions">
                        <!-- Populated by JS based on enrollment status -->
                    </div>
                </div>
            </div>

            <!-- Section 2: Enrollment Wizard (hidden until "Set Up 2FA" clicked) -->
            <div class="card mb-3 d-none" id="enrollCard">
                <div class="card-header">
                    <i class="bi bi-gear me-2"></i>Set Up Two-Factor Authentication
                </div>
                <div class="card-body">

                    <!-- Wizard Step Indicators -->
                    <div class="tfa-wizard-steps mb-4" id="wizardSteps">
                        <div class="tfa-step active" data-step="1">
                            <span class="tfa-step-num">1</span>
                            <span class="tfa-step-label">Confirm Password</span>
                        </div>
                        <div class="tfa-step" data-step="2">
                            <span class="tfa-step-num">2</span>
                            <span class="tfa-step-label">Scan QR Code</span>
                        </div>
                        <div class="tfa-step" data-step="3">
                            <span class="tfa-step-num">3</span>
                            <span class="tfa-step-label">Verify Code</span>
                        </div>
                        <div class="tfa-step" data-step="4">
                            <span class="tfa-step-num">4</span>
                            <span class="tfa-step-label">Backup Codes</span>
                        </div>
                    </div>

                    <!-- Step 1: Confirm Password -->
                    <div class="tfa-wizard-panel" id="step1" style="display:block">
                        <p class="text-body-secondary mb-3">To begin setup, confirm your current password.</p>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="enrollPassword" class="form-label">Password</label>
                                <input type="password" class="form-control form-control-sm" id="enrollPassword"
                                       autocomplete="current-password" required>
                                <div class="invalid-feedback" id="enrollPasswordError"></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary mt-3" id="btnEnrollStep1">
                            <i class="bi bi-arrow-right me-1"></i>Continue
                        </button>
                    </div>

                    <!-- Step 2: QR Code -->
                    <div class="tfa-wizard-panel" id="step2" style="display:none">
                        <p class="text-body-secondary mb-2">Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.).</p>
                        <div class="tfa-qr-area text-center mb-3">
                            <div id="qrCodeContainer"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-body-secondary">Account</label>
                            <div class="font-monospace small" id="tfaAccountName"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-body-secondary">Can't scan? Enter this secret key manually:</label>
                            <div class="input-group input-group-sm" style="max-width:400px">
                                <input type="text" class="form-control form-control-sm font-monospace" id="tfaSecretKey" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="btnCopySecret" title="Copy secret">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" id="btnEnrollStep2">
                            <i class="bi bi-arrow-right me-1"></i>I've scanned it
                        </button>
                    </div>

                    <!-- Step 3: Verify Code -->
                    <div class="tfa-wizard-panel" id="step3" style="display:none">
                        <p class="text-body-secondary mb-3">Enter the 6-digit code from your authenticator app to confirm setup.</p>
                        <div class="row">
                            <div class="col-md-4">
                                <label for="enrollVerifyCode" class="form-label">Verification Code</label>
                                <input type="text" class="form-control tfa-code-input" id="enrollVerifyCode"
                                       maxlength="6" inputmode="numeric" pattern="[0-9]*"
                                       placeholder="000000" autocomplete="one-time-code">
                                <div class="invalid-feedback" id="enrollVerifyError"></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary mt-3" id="btnEnrollStep3">
                            <i class="bi bi-check-lg me-1"></i>Verify
                        </button>
                    </div>

                    <!-- Step 4: Backup Codes -->
                    <div class="tfa-wizard-panel" id="step4" style="display:none">
                        <div class="alert alert-success mb-3">
                            <i class="bi bi-check-circle-fill me-2"></i>Two-factor authentication is now active!
                        </div>
                        <p class="text-body-secondary mb-2">Save these backup codes in a safe place. Each code can only be used once.</p>
                        <div class="alert alert-warning py-2 small">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            If you lose your authenticator device and these codes, you will be locked out of your account.
                        </div>
                        <div class="tfa-backup-grid mb-3" id="backupCodesGrid">
                            <!-- Populated by JS -->
                        </div>
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnCopyCodes">
                                <i class="bi bi-clipboard me-1"></i>Copy All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnDownloadCodes">
                                <i class="bi bi-download me-1"></i>Download as Text
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" id="btnEnrollDone">
                            <i class="bi bi-check-lg me-1"></i>Done
                        </button>
                    </div>

                </div>
            </div>

            <!-- Section 3: Remembered Devices (shown when enrolled) -->
            <div class="card mb-3 d-none" id="devicesCard">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-laptop me-2"></i>Remembered Devices</span>
                    <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btnRevokeAll">
                        <i class="bi bi-x-circle me-1"></i>Revoke All
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="devicesTable">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>IP Address</th>
                                    <th>Remembered On</th>
                                    <th>Expires</th>
                                    <th style="width:80px"></th>
                                </tr>
                            </thead>
                            <tbody id="devicesBody">
                                <tr><td colspan="5" class="text-center text-body-secondary py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section 4: Regenerate Backup Codes (shown when enrolled) -->
            <div class="card mb-3 d-none" id="regenCard">
                <div class="card-header">
                    <i class="bi bi-arrow-repeat me-2"></i>Regenerate Backup Codes
                </div>
                <div class="card-body">
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        This will invalidate all previously issued backup codes. Any unused codes will stop working.
                    </div>
                    <div id="regenForm">
                        <div class="row align-items-end">
                            <div class="col-md-4">
                                <label for="regenCode" class="form-label">Current TOTP Code</label>
                                <input type="text" class="form-control form-control-sm tfa-code-input" id="regenCode"
                                       maxlength="6" inputmode="numeric" pattern="[0-9]*"
                                       placeholder="000000" autocomplete="one-time-code">
                                <div class="invalid-feedback" id="regenCodeError"></div>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-sm btn-warning" id="btnRegenCodes">
                                    <i class="bi bi-arrow-repeat me-1"></i>Regenerate
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="regenResult" class="d-none mt-3">
                        <p class="text-body-secondary mb-2">New backup codes generated. Save them now:</p>
                        <div class="tfa-backup-grid mb-3" id="regenCodesGrid"></div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnCopyRegenCodes">
                                <i class="bi bi-clipboard me-1"></i>Copy All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnDownloadRegenCodes">
                                <i class="bi bi-download me-1"></i>Download as Text
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right column: user info summary -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-circle me-2"></i>Account Info
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt class="text-body-secondary small">Username</dt>
                        <dd class="font-monospace"><?php echo $user; ?></dd>
                        <dt class="text-body-secondary small">Role</dt>
                        <dd><?php echo $level; ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Disable 2FA Modal -->
<div class="modal fade" id="disableModal" tabindex="-1" aria-labelledby="disableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="disableModalLabel">
                    <i class="bi bi-shield-x me-2 text-danger"></i>Disable 2FA
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary">This will remove two-factor authentication from your account. You will need both your password and a current TOTP code.</p>
                <div class="mb-3">
                    <label for="disablePassword" class="form-label">Password</label>
                    <input type="password" class="form-control form-control-sm" id="disablePassword"
                           autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label for="disableCode" class="form-label">Authentication Code</label>
                    <input type="text" class="form-control form-control-sm tfa-code-input" id="disableCode"
                           maxlength="6" inputmode="numeric" pattern="[0-9]*"
                           placeholder="000000" autocomplete="one-time-code">
                </div>
                <div class="alert alert-danger py-2 small d-none" id="disableError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="btnDisableConfirm">
                    <i class="bi bi-shield-x me-1"></i>Disable 2FA
                </button>
            </div>
        </div>
    </div>
</div>

<!-- CSRF token for JS -->
<script>var CSRF_TOKEN = <?php echo json_encode($csrf); ?>;</script>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

    </div><!-- /pane-security -->
    </div><!-- /tab-content -->
</div><!-- /container-fluid -->

<!-- Password change + profile save JS -->
<script>
(function() {
    'use strict';
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrf ? csrf.getAttribute('content') : '';

    // Password change
    var pwForm = document.getElementById('passwordForm');
    if (pwForm) {
        pwForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var cur = document.getElementById('currentPassword').value;
            var newPw = document.getElementById('newPassword').value;
            var confirm = document.getElementById('confirmPassword').value;
            var errDiv = document.getElementById('passwordError');
            var okDiv = document.getElementById('passwordSuccess');
            errDiv.classList.add('d-none');
            okDiv.classList.add('d-none');

            if (newPw.length < 6) {
                errDiv.textContent = 'New password must be at least 6 characters.';
                errDiv.classList.remove('d-none');
                return;
            }
            if (newPw !== confirm) {
                errDiv.textContent = 'New passwords do not match.';
                errDiv.classList.remove('d-none');
                return;
            }

            fetch('api/profile.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'change_password', current_password: cur, new_password: newPw })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    errDiv.textContent = data.error;
                    errDiv.classList.remove('d-none');
                } else {
                    // Phase 8b: report other-device logout if it happened.
                    var msgEl = document.getElementById('passwordSuccessText');
                    if (msgEl && data.other_sessions_ended > 0) {
                        msgEl.textContent = <?php echo json_encode(
                            t('profile.password_changed', 'Password changed successfully.') . ' ' .
                            t('profile.password_changed_logout', 'Other devices have been logged out.')
                        ); ?>;
                    } else if (msgEl) {
                        msgEl.textContent = <?php echo json_encode(
                            t('profile.password_changed', 'Password changed successfully.')
                        ); ?>;
                    }
                    okDiv.classList.remove('d-none');
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';

                    // If the change just cleared a forced-pw-change requirement,
                    // the user has been bouncing here from every other page. Get
                    // them out of the change-password screen and onto the main
                    // dashboard. Brief delay so the green "Password changed
                    // successfully" message is visible for a moment first.
                    if (data.forced_change_cleared) {
                        setTimeout(function () {
                            window.location.href = 'situation.php';
                        }, 1200);
                    }
                }
            })
            .catch(function(err) {
                errDiv.textContent = 'Failed: ' + err.message;
                errDiv.classList.remove('d-none');
            });
        });
    }

    // Profile save
    var profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            fetch('api/profile.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    action: 'update_profile',
                    display_name: document.getElementById('profileDisplayName').value,
                    email: document.getElementById('profileEmail').value,
                    phone: document.getElementById('profilePhone').value,
                    callsign: document.getElementById('profileCallsign').value
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { alert(data.error); return; }
                var saved = document.getElementById('profileSaved');
                saved.classList.remove('d-none');
                setTimeout(function() { saved.classList.add('d-none'); }, 3000);
            })
            .catch(function(err) { alert('Failed: ' + err.message); });
        });
    }

    // Display Preferences (localStorage)
    var fadeSel = document.getElementById('prefNavFade');
    var bmLight = document.getElementById('prefBasemapLight');
    var bmDark = document.getElementById('prefBasemapDark');
    var saveDispBtn = document.getElementById('btnSaveDisplayPrefs');

    // Load current values
    if (fadeSel) {
        var navPrefs = {};
        try { navPrefs = JSON.parse(localStorage.getItem('ticketsNavbarPrefs')) || {}; } catch(e) {}
        var currentFade = navPrefs.fadeDelay !== undefined ? String(navPrefs.fadeDelay) : '10000';
        fadeSel.value = currentFade;
    }
    if (bmLight || bmDark) {
        var mapPrefs = {};
        try { mapPrefs = JSON.parse(localStorage.getItem('ticketsMapPrefs')) || {}; } catch(e) {}
        if (bmLight) bmLight.value = mapPrefs.basemapLight || 'street';
        if (bmDark) bmDark.value = mapPrefs.basemapDark || 'dark';
    }

    // Save handler
    if (saveDispBtn) {
        saveDispBtn.addEventListener('click', function() {
            // Save navbar fade delay
            if (fadeSel && window.NavbarPrefs) {
                window.NavbarPrefs.setFadeDelay(parseInt(fadeSel.value, 10));
            } else if (fadeSel) {
                var np = {};
                try { np = JSON.parse(localStorage.getItem('ticketsNavbarPrefs')) || {}; } catch(e) {}
                np.fadeDelay = parseInt(fadeSel.value, 10);
                try { localStorage.setItem('ticketsNavbarPrefs', JSON.stringify(np)); } catch(e) {}
            }

            // Save basemap preferences
            var mp = {};
            try { mp = JSON.parse(localStorage.getItem('ticketsMapPrefs')) || {}; } catch(e) {}
            if (bmLight) mp.basemapLight = bmLight.value;
            if (bmDark) mp.basemapDark = bmDark.value;
            try { localStorage.setItem('ticketsMapPrefs', JSON.stringify(mp)); } catch(e) {}

            // Also update MapPrefs if available
            if (window.MapPrefs) {
                if (bmLight) window.MapPrefs.setBasemap('light', bmLight.value);
                if (bmDark) window.MapPrefs.setBasemap('dark', bmDark.value);
            }

            var saved = document.getElementById('displayPrefsSaved');
            if (saved) {
                saved.classList.remove('d-none');
                setTimeout(function() { saved.classList.add('d-none'); }, 3000);
            }
        });
    }

    // Auto-select tab from hash
    var tabMap = {
        '#profile': 'tab-profile',
        '#password': 'tab-password',
        '#security': 'tab-security',
        '#setup_2fa': 'tab-security'
    };

    function selectTabFromHash() {
        var hash = window.location.hash;
        var tabId = tabMap[hash];
        if (tabId && typeof bootstrap !== 'undefined') {
            var tabEl = document.getElementById(tabId);
            if (tabEl) {
                var tab = new bootstrap.Tab(tabEl);
                tab.show();
            }
        }
    }

    // Run on page load (with delay for Bootstrap JS)
    function initTabs() {
        setTimeout(selectTabFromHash, 150);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTabs);
    } else {
        initTabs();
    }

    // Also listen for hash changes (when user clicks a menu link while already on this page)
    window.addEventListener('hashchange', selectTabFromHash);
})();
</script>

<!-- App JS -->
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/profile-tfa.js?v=<?php echo asset_v('assets/js/profile-tfa.js'); ?>"></script>

<!-- Phase 57 — PWA install (beforeinstallprompt capture + manual trigger) -->
<script>
(function () {
    'use strict';
    function $(id) { return document.getElementById(id); }
    var card = $('pwaInstallCard');
    var btn  = $('btnPwaInstall');
    var hint = $('pwaManualHint');
    var done = $('pwaInstalledMsg');
    if (!card || !btn) return;
    var deferred = null;

    // Chrome / Edge fires this once per page load when the manifest
    // + scope + engagement criteria are met and the app isn't already
    // installed. We MUST keep the event object — calling .prompt()
    // after a user gesture is the only way to re-show the install
    // dialog. Browsers won't re-fire this for 90 days after a dismiss.
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferred = e;
        card.classList.remove('d-none');
        hint.classList.add('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-download me-1"></i>Install App';
    });

    // If the user previously installed (and then uninstalled), Chrome
    // doesn't re-fire beforeinstallprompt for a long time. Show the
    // card anyway with manual instructions so the user has a path.
    setTimeout(function () {
        if (!deferred && window.matchMedia('(display-mode: browser)').matches) {
            card.classList.remove('d-none');
            hint.classList.remove('d-none');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-download me-1"></i>Install (use browser menu)';
        }
    }, 1500);

    // If we land here in standalone display mode, the app is already
    // installed — celebrate that and hide the prompt.
    if (window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true) {
        card.classList.remove('d-none');
        btn.classList.add('d-none');
        hint.classList.add('d-none');
        done.classList.remove('d-none');
        $('pwaInstallHint').textContent = 'Running as an installed app — nice.';
    }

    window.addEventListener('appinstalled', function () {
        deferred = null;
        btn.classList.add('d-none');
        hint.classList.add('d-none');
        done.classList.remove('d-none');
    });

    btn.addEventListener('click', function () {
        if (!deferred) return;
        btn.disabled = true;
        deferred.prompt();
        deferred.userChoice.then(function (choice) {
            // 'accepted' = installed, 'dismissed' = user cancelled
            deferred = null;
            if (choice && choice.outcome === 'accepted') {
                btn.classList.add('d-none');
                done.classList.remove('d-none');
            } else {
                hint.classList.remove('d-none');
                btn.innerHTML = '<i class="bi bi-download me-1"></i>Install (use browser menu)';
                btn.disabled = true;
            }
        });
    });
})();
</script>

<!-- Phase 54 — personal-resource clock-in -->
<script>
(function () {
    'use strict';
    function $(id) { return document.getElementById(id); }
    var badge = $('puBadge'), inBtn = $('btnClockIn'), outBtn = $('btnClockOut'), line = $('puStatusLine');
    if (!badge || !inBtn || !outBtn) return;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function render(status) {
        if (!status || status.error) {
            badge.textContent = 'error'; badge.className = 'badge bg-danger ms-2';
            line.textContent = status && status.error ? status.error : '';
            inBtn.classList.add('d-none'); outBtn.classList.add('d-none');
            return;
        }
        // Phase 57 — RBAC. If the role doesn't allow self-clock-in,
        // show a quiet read-only state with an explanation instead
        // of buttons the user can't actually use.
        if (status.can_self_clock === false) {
            badge.textContent = 'not permitted'; badge.className = 'badge bg-secondary ms-2';
            line.textContent = 'Your role does not include the Self Clock-In permission. Ask an admin if you need it.';
            inBtn.classList.add('d-none'); outBtn.classList.add('d-none');
            return;
        }
        if (status.clocked_in) {
            badge.textContent = 'CLOCKED IN'; badge.className = 'badge bg-success ms-2';
            inBtn.classList.add('d-none'); outBtn.classList.remove('d-none');
            line.innerHTML = 'Unit <strong>' + (status.unit_handle || status.unit_name || '?') + '</strong> active since ' + (status.since || '?');
        } else if (status.exists) {
            badge.textContent = 'clocked out'; badge.className = 'badge bg-secondary ms-2';
            inBtn.classList.remove('d-none'); outBtn.classList.add('d-none');
            line.innerHTML = 'Personal unit <strong>' + (status.unit_handle || status.unit_name || '?') + '</strong> last status change ' + (status.since || '?');
        } else {
            badge.textContent = 'not yet activated'; badge.className = 'badge bg-info text-dark ms-2';
            inBtn.classList.remove('d-none'); outBtn.classList.add('d-none');
            line.textContent = 'No personal unit yet — clocking in will create one.';
        }
    }

    function load() {
        fetch('api/personal-unit.php?action=status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { render({ error: 'Failed to load status' }); });
    }

    function post(action) {
        inBtn.disabled = outBtn.disabled = true;
        fetch('api/personal-unit.php?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            inBtn.disabled = outBtn.disabled = false;
            if (d.error) { render({ error: d.error }); return; }
            render(d.status || d);
        })
        .catch(function () {
            inBtn.disabled = outBtn.disabled = false;
            render({ error: 'Request failed' });
        });
    }

    inBtn.addEventListener('click', function () { post('clock_in'); });
    outBtn.addEventListener('click', function () { post('clock_out'); });
    load();
})();
</script>

</body>
</html>
