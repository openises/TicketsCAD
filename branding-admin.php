<?php
/**
 * NewUI v4.0 - Agency Logo & Branding admin (GH#142, Phase 155).
 *
 * Standalone admin page (same precedent as public-board-admin.php), reached at
 * Settings > Application - Presentation > Agency Logo & Branding. Backend:
 * api/branding-admin.php. Library: inc/branding.php. Guide: docs/AGENCY-BRANDING.md.
 *
 * TWO-TIER RBAC. This page is reachable by EITHER permission, but the panels
 * each holder sees differ:
 *
 *   action.manage_branding      install-wide (Super Admin only): the install-wide
 *       logo, every organization's logo, and all of the settings.
 *   action.manage_branding_org  org-scoped self-service (Super Admin + Org Admin):
 *       ONLY the caller's own organization's logo.
 *
 * This file's gating is DISPLAY ONLY. Every write goes through
 * api/branding-admin.php, which independently re-checks the permission AND
 * forces the organization from the caller's own grants. Hiding a panel here is
 * not a security control.
 *
 * Deliberately NOT `rbac_can(...) || is_admin()`: is_admin() also returns true
 * for anyone holding action.manage_config, which a correctly-scoped Org Admin can
 * end up holding, and that would show the install-wide panels to them. See
 * api/public-board-admin.php for the long account.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/branding.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$isBrandAdmin = rbac_can('action.manage_branding');
$isOrgSelf    = rbac_can('action.manage_branding_org');

if (!$isBrandAdmin && !$isOrgSelf) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agency Logo &amp; Branding — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Agency logo and branding needs either the "Manage Agency Branding" or the
           "Manage Own Org's Logo" permission. Ask an administrator to grant your role
           <code>action.manage_branding</code> or <code>action.manage_branding_org</code>.</p>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Back to dashboard</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user        = e($_SESSION['user']);
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$brAltMax    = (int) (branding_setting_definitions()['branding_logo_alt']['max'] ?? 100);

/** assets/css and assets/js links versioned by mtime, the pattern inc/navbar.php uses. */
function _br_asset(string $rel): string
{
    $full = __DIR__ . '/' . $rel;
    return $rel . '?v=' . (is_file($full) ? filemtime($full) : newui_version());
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Agency Logo &amp; Branding — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?php echo e(_br_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(_br_asset('assets/css/branding.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(_br_asset('assets/css/branding-admin.css')); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" id="main-content" style="max-width: 1200px;"
      data-can-install="<?php echo $isBrandAdmin ? '1' : '0'; ?>"
      data-can-org="<?php echo $isOrgSelf ? '1' : '0'; ?>">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-image text-primary me-2"></i>Agency Logo &amp; Branding</h4>
        <a href="documentation/?doc=AGENCY-BRANDING" target="_blank" rel="noopener" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-question-circle me-1"></i>Guide</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Settings</a>
    </div>

    <div id="brToast" class="alert d-none" role="status" aria-live="polite"></div>
    <div id="brSchemaBanner" class="alert alert-danger d-none" role="alert">
        <i class="bi bi-exclamation-octagon me-1"></i>
        The branding tables are missing on this install. Run <code>php sql/run_migrations.php</code> on the server, then reload this page.
    </div>
    <noscript><div class="alert alert-warning">This page needs JavaScript.</div></noscript>

    <div class="row g-3">
        <!-- ═══════════════ Left: forms ═══════════════ -->
        <div class="col-lg-7">

            <div id="brGdWarning" class="alert alert-warning small d-none" role="note">
                <i class="bi bi-exclamation-triangle me-1"></i>
                The PHP <strong>GD</strong> extension is not enabled on this server. Uploads are still accepted
                (PNG and JPEG up to the size limit), but they are stored exactly as you upload them, so any hidden
                metadata in the file (camera or location tags) is <strong>not</strong> removed. Ask your administrator
                to enable GD.
            </div>

            <!-- Install-wide logo -->
            <div class="card mb-3" id="brInstallCard">
                <div class="card-header"><i class="bi bi-building me-2"></i>Install-wide logo</div>
                <div class="card-body">
                    <p class="text-body-secondary small mb-3" id="brInstallIntro">
                        Shown on the login screen and, unless an organization has its own, on printed pages. PNG or JPEG,
                        up to <span id="brMaxUpload">2</span> MB. SVG is not accepted (it can carry script): export your logo as a PNG.
                        The image is re-encoded and reduced to fit <span id="brMaxStored">256</span> KB, so hidden metadata is removed.
                    </p>
                    <p class="alert alert-info small d-none" id="brInstallReadOnly">
                        <i class="bi bi-info-circle me-1"></i>
                        Changing the install-wide logo needs the "Manage Agency Branding" permission. Ask a Super Admin.
                    </p>
                    <div id="brInstallSlots"></div>
                </div>
            </div>

            <!-- Organization logos -->
            <div class="card mb-3 d-none" id="brOrgCard">
                <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Organization logos</div>
                <div class="card-body">
                    <p class="text-body-secondary small mb-2" id="brOrgIntro">
                        An organization with its own logo uses it on its own printed pages. One without a logo uses its
                        parent organization's, then the install-wide logo.
                    </p>
                    <p class="alert alert-info small d-none" id="brOrgOff">
                        <i class="bi bi-info-circle me-1"></i>
                        Organization logos are switched off for this installation, so every page uses the install-wide
                        logo. Any logo you upload here is kept but ignored until a Super Admin switches them back on.
                    </p>
                    <p class="alert alert-warning small d-none" id="brOrgNone">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Your account is not scoped to one single organization, so there is no "own organization" to
                        manage here. Ask a Super Admin.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3" id="brOrgTable">
                            <caption class="visually-hidden">Organizations and their logos</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Organization</th>
                                    <th scope="col">Logo</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="brOrgManage" class="d-none">
                        <h6 class="mb-2" id="brOrgHeading">Organization</h6>
                        <div id="brOrgSlots"></div>
                    </div>
                </div>
            </div>

            <?php if ($isBrandAdmin): ?>
            <!-- Settings -->
            <div class="card mb-3" id="brSettingsCard">
                <div class="card-header"><i class="bi bi-sliders me-2"></i>Where it appears, size and placement</div>
                <div class="card-body">
                    <h6 class="mb-2">Where it appears</h6>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="brSetLogin" data-setting="branding_login">
                        <label class="form-check-label" for="brSetLogin">Show the logo on the login screen</label>
                        <div class="form-text">Some agencies do not want their name on a public sign-in page. Turn this off to keep the standard icon there.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="brSetNavbar">Top bar of every page</label>
                        <select class="form-select form-select-sm" id="brSetNavbar" data-setting="branding_navbar">
                            <option value="product">Keep the product mark (default)</option>
                            <option value="agency">Show my agency's logo</option>
                        </select>
                        <div class="form-text">The About page always keeps the product mark and credits.</div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="brSetPrint" data-setting="branding_print">
                        <label class="form-check-label" for="brSetPrint">Print the logo as a letterhead (printed pages, reports and ICS forms)</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="brSetBoard" data-setting="branding_public_board">
                        <label class="form-check-label" for="brSetBoard">Show the logo on the public incident board</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="brSetOrgLogos" data-setting="branding_org_logos">
                        <label class="form-check-label" for="brSetOrgLogos">Let organizations have their own logo</label>
                        <div class="form-text">When off, every page uses the install-wide logo. Organization logos are kept, just ignored.</div>
                    </div>

                    <h6 class="mb-2">Size and placement</h6>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" for="brSetLoginSize">Login screen logo size</label>
                            <select class="form-select form-select-sm" id="brSetLoginSize" data-setting="branding_login_size">
                                <option value="small">Small (48 px high)</option>
                                <option value="medium">Medium (72 px high)</option>
                                <option value="large">Large (96 px high)</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" for="brSetPrintSize">Printed letterhead size</label>
                            <select class="form-select form-select-sm" id="brSetPrintSize" data-setting="branding_print_size">
                                <option value="small">Small (36 px high)</option>
                                <option value="medium">Medium (60 px high)</option>
                                <option value="large">Large (84 px high)</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" for="brSetPrintAlign">Letterhead alignment</label>
                            <select class="form-select form-select-sm" id="brSetPrintAlign" data-setting="branding_print_align">
                                <option value="left">Left</option>
                                <option value="center">Centered</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" for="brSetPrintBanner">Printed "Tickets CAD - Printed" text line</label>
                            <select class="form-select form-select-sm" id="brSetPrintBanner" data-setting="branding_print_banner">
                                <option value="replace">Replace it with the logo</option>
                                <option value="keep">Keep it and add the logo above it</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="brSetDarkFallback">Dark theme, when there is no logo for dark backgrounds</label>
                            <select class="form-select form-select-sm" id="brSetDarkFallback" data-setting="branding_dark_fallback">
                                <option value="plate">Put the logo on a white plate (recommended)</option>
                                <option value="as_is">Show it as it is</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="mb-2 mt-3">Accessibility</h6>
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="brSetAlt">Alternative text for the logo</label>
                        <input type="text" class="form-control form-control-sm" id="brSetAlt" data-setting="branding_logo_alt"
                               maxlength="<?php echo (int) $brAltMax; ?>" placeholder="Agency logo" autocomplete="off">
                        <div class="form-text">Read aloud by screen readers. Up to <?php echo (int) $brAltMax; ?> characters. Name your agency, for example "your deployment Fire Department".</div>
                    </div>

                    <button type="button" class="btn btn-sm btn-primary" id="brSaveSettings">
                        <i class="bi bi-check-lg me-1"></i>Save settings
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════ Right: live preview ═══════════════ -->
        <div class="col-lg-5">
            <div class="br-preview-sticky">
                <div class="card">
                    <div class="card-header"><i class="bi bi-eye me-2"></i>Live preview</div>
                    <div class="card-body">
                        <p class="text-body-secondary small">
                            Updates as you change the controls and as you choose a file. Nothing is saved until you
                            click Upload or Save.
                        </p>

                        <div class="br-preview-panel">
                            <div class="d-flex align-items-center mb-1">
                                <span class="small fw-semibold">Login screen</span>
                                <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Preview theme">
                                    <button type="button" class="btn btn-outline-secondary active" id="brPvDay" aria-pressed="true">Day</button>
                                    <button type="button" class="btn btn-outline-secondary" id="brPvNight" aria-pressed="false">Night</button>
                                </div>
                            </div>
                            <div class="card br-preview-login" id="brPvLoginCard" data-bs-theme="light">
                                <div class="card-body text-center" id="brPvLogin"></div>
                            </div>
                        </div>

                        <div class="br-preview-panel">
                            <div class="small fw-semibold mb-1">Top bar</div>
                            <nav class="navbar border rounded px-2 py-1" id="brPvNav" data-bs-theme="light" aria-label="Top bar preview"></nav>
                        </div>

                        <div class="br-preview-panel">
                            <div class="small fw-semibold mb-1">Printed page</div>
                            <div class="br-preview-page" id="brPvPage" aria-label="Printed page preview"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?php echo e(_br_asset('assets/js/branding-admin.js')); ?>"></script>
</body>
</html>
