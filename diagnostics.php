<?php
/**
 * NewUI v4.0 — Self-service Diagnostics (GH #8 / #13 tester assist).
 *
 * A page a beta tester opens in THEIR browser to see, in plain language, whether
 * real-time updates and push notifications actually work on their machine —
 * then screenshot it instead of digging through logs. Runs the live client-side
 * tests (SSE connect, Web Push subscribe + test-send) and shows the server-side
 * prerequisites from api/diagnostics.php.
 *
 * Any signed-in user (testers aren't admins).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/rbac.php';   // navbar.php uses is_admin() / rbac_can()
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$user     = e($_SESSION['user'] ?? '');
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('diag.title', 'Diagnostics')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <style>
        .diag-card { max-width: 900px; }
        .diag-row { display: flex; align-items: flex-start; gap: .6rem; padding: .45rem 0; border-bottom: 1px solid var(--bs-border-color); }
        .diag-row:last-child { border-bottom: 0; }
        .diag-ico { font-size: 1.1rem; line-height: 1.4; flex: 0 0 1.3rem; text-align: center; }
        .diag-ok    { color: var(--bs-success); }
        .diag-warn  { color: var(--bs-warning); }
        .diag-bad   { color: var(--bs-danger); }
        .diag-pend  { color: var(--bs-secondary); }
        .diag-label { font-weight: 600; }
        .diag-detail { color: var(--bs-secondary-color); font-size: .88rem; }
        .diag-copy { font-family: monospace; font-size: .82rem; white-space: pre-wrap; }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div class="container py-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-heart-pulse text-danger"></i> <?php echo e(t('diag.heading', 'Diagnostics')); ?></h1>
        <span class="text-body-secondary small">— <?php echo e(t('diag.sub', 'Check that real-time updates and notifications work on this device')); ?></span>
        <button id="diagRerun" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-clockwise"></i> <?php echo e(t('diag.rerun', 'Re-run')); ?></button>
        <button id="diagCopy" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard"></i> <?php echo e(t('diag.copy', 'Copy report')); ?></button>
    </div>

    <p class="text-body-secondary small">
        <?php echo e(t('diag.intro', 'If something on the dashboard or mobile isn\'t updating on its own, or you\'re not getting notifications, run this and send the results (use "Copy report" or a screenshot) so we can see exactly where it breaks.')); ?>
    </p>

    <!-- Real-time updates (SSE) -->
    <div class="card diag-card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-broadcast"></i> <?php echo e(t('diag.rt', 'Real-time updates (live refresh)')); ?></div>
        <div class="card-body py-2" id="diagSse">
            <div class="diag-row"><span class="diag-ico diag-pend"><i class="bi bi-hourglass"></i></span>
                <span><span class="diag-label"><?php echo e(t('diag.testing', 'Testing…')); ?></span></span></div>
        </div>
    </div>

    <!-- Push notifications -->
    <div class="card diag-card mb-3">
        <div class="card-header py-2 fw-semibold d-flex align-items-center">
            <span><i class="bi bi-bell"></i> <?php echo e(t('diag.push', 'Push notifications')); ?></span>
            <button id="diagPushTest" class="btn btn-sm btn-outline-primary ms-auto d-none">
                <?php echo e(t('diag.push_test', 'Send a test to this device')); ?>
            </button>
        </div>
        <div class="card-body py-2" id="diagPush">
            <div class="diag-row"><span class="diag-ico diag-pend"><i class="bi bi-hourglass"></i></span>
                <span><span class="diag-label"><?php echo e(t('diag.testing', 'Testing…')); ?></span></span></div>
        </div>
    </div>

    <!-- Radio (Zello) connection — GH task #67 widget "flapping" -->
    <div class="card diag-card mb-3" id="diagZelloCard" style="display:none">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-broadcast-pin"></i> <?php echo e(t('diag.zello', 'Radio (Zello) connection')); ?></div>
        <div class="card-body py-2" id="diagZello">
            <div class="diag-row"><span class="diag-ico diag-pend"><i class="bi bi-hourglass"></i></span>
                <span><span class="diag-label"><?php echo e(t('diag.testing', 'Testing…')); ?></span></span></div>
        </div>
    </div>

    <!-- Environment -->
    <div class="card diag-card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-window"></i> <?php echo e(t('diag.env', 'This device & browser')); ?></div>
        <div class="card-body py-2" id="diagEnv"></div>
    </div>

    <!-- Server prerequisites -->
    <div class="card diag-card mb-4">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-hdd-network"></i> <?php echo e(t('diag.server', 'Server settings')); ?></div>
        <div class="card-body py-2" id="diagServer">
            <div class="diag-row"><span class="diag-ico diag-pend"><i class="bi bi-hourglass"></i></span>
                <span><span class="diag-label"><?php echo e(t('diag.testing', 'Testing…')); ?></span></span></div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>window.DIAG_CSRF = <?php echo json_encode($csrf); ?>;</script>
<script src="assets/js/push-client.js?v=<?php echo asset_v('assets/js/push-client.js'); ?>"></script>
<script src="assets/js/diagnostics.js?v=<?php echo asset_v('assets/js/diagnostics.js'); ?>"></script>
</body>
</html>
