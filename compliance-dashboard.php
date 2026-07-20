<?php
/**
 * NewUI v4.0 — Security Compliance Dashboard (Phase 10 CJIS hardening)
 *
 * Admin-gated single-page view summarizing the install's security
 * posture against CJIS recommended values. Data loads asynchronously
 * from /api/security-compliance.php.
 *
 * Filename note: there's already a /compliance.php for personnel
 * certifications. This file is the *security* compliance dashboard.
 * The sidebar link distinguishes them.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';

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

require_once __DIR__ . '/inc/rbac.php';

// Admin gate. Allow super and admin levels (0, 1) OR holders of
// action.manage_config. Match the api/security-compliance.php check.
$canManage = function_exists('rbac_can') && rbac_can('action.manage_config');
$lvl = (int) ($_SESSION['level'] ?? 99);
if (!$canManage && $lvl > 1) {
    header('Location: index.php?err=admin_required');
    exit;
}

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'compliance-dashboard';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('compliance_dash.title', 'Security Compliance Dashboard')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo asset_v('assets/css/config.css'); ?>">
    <link rel="stylesheet" href="assets/css/compliance.css?v=<?php echo asset_v('assets/css/compliance.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div class="config-layout">
    <?php $configActivePage = 'compliance-dashboard'; include_once NEWUI_ROOT . '/inc/config-sidebar.php'; ?>

    <main class="config-content" id="configContent" style="padding:1rem 1.5rem;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-shield-check text-primary me-2"></i>
            <?php echo e(t('compliance_dash.title', 'Security Compliance Dashboard')); ?>
        </h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshCompliance">
                <i class="bi bi-arrow-clockwise me-1"></i><?php echo e(t('btn.refresh', 'Refresh')); ?>
            </button>
            <a href="docs/SECURITY-POLICY.md" target="_blank" class="btn btn-sm btn-outline-info">
                <i class="bi bi-file-text me-1"></i><?php echo e(t('compliance_dash.btn_policy_doc', 'Security Policy Doc')); ?>
            </a>
            <a href="settings.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('nav.menu.config', 'Config')); ?>
            </a>
        </div>
    </div>

    <p class="text-body-secondary small mb-3">
        <?php echo e(t('compliance_dash.intro', 'Live snapshot of how this install measures against CJIS Security Policy v6.0 (aligned with NIST SP 800-63B). Green badge = meets recommendation; yellow = below; red = significantly below. Click "Refresh" to re-poll.')); ?>
    </p>

    <div id="complianceContent">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="mt-2 text-body-secondary"><?php echo e(t('common.loading', 'Loading...')); ?></div>
        </div>
    </div>
    </main>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/compliance-dashboard.js?v=<?php echo asset_v('assets/js/compliance-dashboard.js'); ?>"></script>
</body>
</html>
