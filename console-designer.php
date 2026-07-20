<?php
/**
 * NewUI v4.0 — Console Designer (Phase 114b, slice b2)
 *
 * Admin surface for authoring the shared console views shown as tabs on
 * console.php. Three panes (console-designer.md §2):
 *   left   — view list (create / rename / delete)
 *   middle — canvas: the selected view's strip bank; drag to reorder,
 *            click a strip to select it
 *   right  — channel list (click to add a strip) + strip inspector
 *            (label / short label / colours / width / control palette,
 *            capability-gated so a saved view can't hold a dead button)
 *
 * Designer mode never keys TX — strips render presentation only.
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

if (!rbac_can('console.design')) {
    http_response_code(403);
    echo 'Forbidden — missing console.design permission';
    exit;
}

$user     = e($_SESSION['user']);
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'console';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.console_designer', 'Console Designer')); ?> &mdash; <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/gridstack/gridstack.min.css">
    <link rel="stylesheet" href="assets/vendor/gridstack/gridstack-extra.min.css">

    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/console.css?v=<?php echo asset_v('assets/css/console.css'); ?>">
    <link rel="stylesheet" href="assets/css/console-designer.css?v=<?php echo asset_v('assets/css/console-designer.css'); ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div class="container-fluid p-3">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-easel text-primary me-2"></i><?php echo e(t('console.designer_title', 'Console Designer')); ?>
        </h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-success d-none" id="cdSaveBtn">
                <i class="bi bi-cloud-upload me-1"></i>Publish View
            </button>
            <a href="console.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Console
            </a>
        </div>
    </div>

    <div class="row g-3">
        <!-- Left: views -->
        <div class="col-lg-2">
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small">Views</span>
                    <button class="btn btn-sm btn-outline-primary py-0 px-1" id="cdNewViewBtn" title="New view">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="list-group list-group-flush" id="cdViewList"></div>
            </div>
        </div>

        <!-- Middle: canvas -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small" id="cdCanvasTitle">Select or create a view</span>
                    <span class="text-body-secondary small" id="cdDirtyFlag"></span>
                </div>
                <div class="card-body">
                    <div class="console-bank cd-canvas" id="cdCanvas"></div>
                </div>
            </div>
        </div>

        <!-- Right: channel list + inspector -->
        <div class="col-lg-3">
            <div class="card mb-3">
                <div class="card-header py-2 fw-semibold small">Channels — click to add a strip</div>
                <div class="list-group list-group-flush cd-channel-list" id="cdChannelList"></div>
            </div>
            <div class="card mb-3 d-none" id="cdPalette">
                <div class="card-header py-2 fw-semibold small">Components — click to add to the selected strip</div>
                <div class="card-body py-2 d-flex flex-wrap gap-1" id="cdPaletteBody"></div>
            </div>
            <div class="card d-none" id="cdInspector">
                <div class="card-header py-2 fw-semibold small">Settings</div>
                <div class="card-body py-2" id="cdInspectorBody"></div>
            </div>
        </div>
    </div>

</div>

<script src="assets/vendor/gridstack/gridstack-all.js"></script>
<script src="assets/js/console-designer.js?v=<?php echo asset_v('assets/js/console-designer.js'); ?>"></script>
</body>
</html>
