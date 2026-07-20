<?php
/**
 * NewUI v4.0 — Communications Console (Phase 114b, slice b1)
 *
 * Multi-channel console: one strip per enabled channel from the registry
 * (comm_channels). Slice b1 renders a single auto-generated view — the
 * designer, named views/tabs, and select/simulselect land in b2/b3
 * (specs/phase-114-audio-matrix/console-designer.md).
 *
 * Voice strips bind to today's backends: Zello opens the Zello widget
 * (EventBus 'zello:toggle'), DMR opens the radio widget (data-action=
 * "radio" delegator). Text strips are fully functional: activity feed +
 * send drawer through the broker.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

if (!rbac_can('screen.console')) {
    http_response_code(403);
    echo 'Forbidden — missing screen.console permission';
    exit;
}

$user     = e($_SESSION['user']);
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$can_tx       = rbac_can('action.console_tx');
$can_send     = rbac_can('action.send_chat') || $can_tx;
$can_design   = rbac_can('console.design');
$active_page  = 'console';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.console', 'Console')); ?> &mdash; <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/console.css?v=<?php echo asset_v('assets/css/console.css'); ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body data-can-tx="<?php echo $can_tx ? '1' : '0'; ?>" data-can-send="<?php echo $can_send ? '1' : '0'; ?>">

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div class="container-fluid p-3">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-broadcast-pin text-primary me-2"></i><?php echo e(t('console.title', 'Communications Console')); ?>
            <span class="badge bg-secondary ms-2" id="consoleChannelCount">0</span>
        </h5>
        <div class="d-flex gap-2">
            <?php if ($can_design): ?>
            <a href="console-designer.php" class="btn btn-sm btn-outline-primary"
               title="Author the shared console views shown as tabs">
                <i class="bi bi-easel me-1"></i>Design Views
            </a>
            <button class="btn btn-sm btn-outline-secondary" id="consoleSyncBtn"
                    title="Re-scan configured channels into the registry">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Channels
            </button>
            <?php endif; ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('nav.dashboard', 'Dashboard')); ?>
            </a>
        </div>
    </div>

    <!-- View tabs (b2) — hidden until a designer view exists -->
    <ul class="nav nav-tabs mb-3 d-none" id="consoleTabs"></ul>

    <!-- Strip bank: the active view, or the auto-generated all-channels view -->
    <div class="console-bank" id="consoleBank">
        <div class="text-body-secondary p-4" id="consoleLoading">
            <span class="spinner-border spinner-border-sm me-2"></span>Loading channels&hellip;
        </div>
    </div>

</div>

<!-- Zello widget so the Zello strip's Open control works here (it is
     dashboard-loaded elsewhere; the radio widget is already navbar-global).
     2026-07-08: zello-widget.js loaded but #tpl-zello-widget did NOT — its
     init() bailed, the zello:toggle listener never attached, and "Open
     Zello" fired into the void. Include the shared template so it inits. -->
<?php include_once NEWUI_ROOT . '/inc/zello-widget-template.php'; ?>
<script src="assets/js/zello-widget.js?v=<?php echo asset_v('assets/js/zello-widget.js'); ?>"></script>
<script src="assets/js/console.js?v=<?php echo asset_v('assets/js/console.js'); ?>"></script>
</body>
</html>
