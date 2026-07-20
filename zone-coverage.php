<?php
/**
 * NewUI v4.0 — Zone Coverage board (Phase 115, GH #64)
 *
 * The volunteer-facing companion to Net Control. Big, phone-friendly cards —
 * one per zone — showing how many units are in each zone right now, so a
 * roaming volunteer can decide to meet up or spread out. A volunteer who is on
 * a unit can report their own zone in one tap.
 *
 * Data: api/zone-coverage.php. Self-report: api/zone-self-report.php.
 * Real-time via the shared EventBus SSE (responder:status / zone_update),
 * loaded by inc/navbar.php; 15 s poll fallback.
 *
 * See specs/phase-115-zone-coverage/spec.md.
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
require_once __DIR__ . '/inc/rbac.php';
rbac_require_screen('screen.zone_coverage');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$user     = e($_SESSION['user'] ?? '');
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();

$canSetOwnZone = (rbac_can('action.set_own_zone') || is_admin()) ? 1 : 0;
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('zonecov.title', 'Zone Coverage')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/zone-coverage.css?v=<?php echo asset_v('assets/css/zone-coverage.css'); ?>">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div class="container-fluid py-3 zc-wrap">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0 me-2">
            <i class="bi bi-diagram-3 text-primary"></i>
            <?php echo e(t('zonecov.heading', 'Zone Coverage')); ?>
        </h1>
        <span id="zcEventName" class="text-body-secondary small"></span>

        <!-- Event picker — only shown by JS when >1 event has zones. -->
        <select id="zcEventPicker" class="form-select form-select-sm w-auto ms-auto d-none"
                aria-label="<?php echo e(t('zonecov.pick_event', 'Choose event')); ?>"></select>

        <button id="zcRefresh" type="button" class="btn btn-sm btn-outline-secondary"
                title="<?php echo e(t('zonecov.refresh', 'Refresh now')); ?>">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
        <span id="zcLive" class="zc-live small text-body-secondary" title="Real-time updates">
            <i class="bi bi-broadcast"></i> <span id="zcLiveLabel">live</span>
        </span>
    </div>

    <!-- Self-report strip — hidden until the API confirms the viewer is on a unit. -->
    <div id="zcSelfReport" class="zc-selfreport card mb-3 d-none">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold small">
                    <i class="bi bi-geo-alt-fill text-danger"></i>
                    <?php echo e(t('zonecov.im_in', "I'm in:")); ?>
                </span>
                <span id="zcSelfButtons" class="d-flex flex-wrap gap-1"></span>
                <span id="zcSelfStatus" class="small ms-1"></span>
            </div>
        </div>
    </div>

    <!-- Zone cards -->
    <div id="zcZones" class="row g-3">
        <div class="col-12 text-center text-body-secondary py-5" id="zcLoading">
            <div class="spinner-border spinner-border-sm me-2"></div>
            <?php echo e(t('zonecov.loading', 'Loading zone coverage…')); ?>
        </div>
    </div>

    <!-- Units not yet in any zone -->
    <div id="zcUnassignedWrap" class="mt-3 d-none">
        <h2 class="h6 text-body-secondary">
            <i class="bi bi-question-circle"></i>
            <?php echo e(t('zonecov.no_zone', 'No zone reported yet')); ?>
            <span id="zcUnassignedCount" class="badge bg-secondary"></span>
        </h2>
        <div id="zcUnassigned" class="d-flex flex-wrap gap-1"></div>
    </div>

    <!-- Empty state (no event has zones) -->
    <div id="zcEmpty" class="text-center text-body-secondary py-5 d-none">
        <i class="bi bi-diagram-3 fs-1 d-block mb-2 opacity-50"></i>
        <p class="mb-1"><?php echo e(t('zonecov.empty_title', 'No zones are set up for an active event yet.')); ?></p>
        <p class="small mb-0"><?php echo e(t('zonecov.empty_hint', 'A dispatcher defines zones on the Net Control board. Once zones exist and units are assigned, coverage shows here.')); ?></p>
    </div>

</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
    window.ZC_CONFIG = {
        csrf: <?php echo json_encode($csrf); ?>,
        canSetOwnZone: <?php echo $canSetOwnZone ? 'true' : 'false'; ?>
    };
</script>
<script src="assets/js/zone-coverage.js?v=<?php echo asset_v('assets/js/zone-coverage.js'); ?>"></script>
</body>
</html>
