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
$can_matrix   = rbac_can('action.manage_matrix');
// Console rebuild — the patch rail's own gate. Deliberately OR'd with
// action.manage_matrix even though api/matrix.php's own RBAC check
// already accepts either: an admin who can manage the FULL matrix admin
// panel should also see the operational rail, not just action.patch_
// create-tier dispatchers.
$can_patch    = rbac_can('action.patch_create') || $can_matrix;
// Phase 152 -- the thin position layer's own admin gate (sql/rbac.sql).
$can_positions_admin = rbac_can('action.manage_positions');
$active_page  = 'console';
// zello-proxy-port meta tag + the widget's CSS/template/JS all moved into
// inc/navbar.php on 2026-09-08 (see its own docblock there) -- this page
// no longer includes any of them directly.
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.console', 'Console')); ?> &mdash; <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo newui_version(); ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo newui_version(); ?>">
    <link rel="stylesheet" href="assets/css/console.css?v=<?php echo asset_v('assets/css/console.css'); ?>">
    <!-- zello-widget.css, its proxy-port meta tag, template, and JS all
         moved into inc/navbar.php on 2026-09-08 -- see that file's own
         docblock. This page no longer includes any of them directly. -->
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body data-can-tx="<?php echo $can_tx ? '1' : '0'; ?>" data-can-send="<?php echo $can_send ? '1' : '0'; ?>" data-can-patch="<?php echo $can_patch ? '1' : '0'; ?>">

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
            <?php else: ?>
            <!-- Phase 114b3 - any screen.console holder may build a PERSONAL
                 layout, no console.design needed. Reuses the designer's
                 canvas/inspector UI, scoped to "My Personal Views" (see
                 console-designer.php's gate + console-designer.js). -->
            <a href="console-designer.php" class="btn btn-sm btn-outline-secondary"
               title="Build your own personal layout for this console -- no admin permission needed">
                <i class="bi bi-person-workspace me-1"></i>My Views
            </a>
            <?php endif; ?>
            <?php if ($can_matrix): ?>
            <a href="matrix-admin.php" class="btn btn-sm btn-outline-primary"
               title="Create and manage audio patches between channels">
                <i class="bi bi-diagram-3 me-1"></i>Patch Matrix
            </a>
            <?php endif; ?>
            <?php if ($can_positions_admin): ?>
            <a href="console-positions-admin.php" class="btn btn-sm btn-outline-primary"
               title="Create and manage named console positions (seats)">
                <i class="bi bi-person-badge me-1"></i>Positions
            </a>
            <?php endif; ?>
            <!-- Recall tab (Console rebuild) -- a secondary, on-demand
                 panel, not a live-updating view; see console-recall.js. -->
            <button class="btn btn-sm btn-outline-secondary" id="consoleRecallToggle"
                    type="button" aria-expanded="false" title="Recent activity across every channel">
                <i class="bi bi-clock-history me-1"></i>Recall
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('nav.dashboard', 'Dashboard')); ?>
            </a>
        </div>
    </div>

    <div id="consoleToast" class="alert d-none py-2 mb-3" role="status" aria-live="polite"></div>

    <!-- Position bar (Phase 152 thin position layer) -- hidden entirely
         when the install has zero positions configured (see assets/js/
         console-positions.js's own docblock). -->
    <div class="mb-2 d-none console-position-bar" id="consolePositionBar"></div>

    <!-- Workstation identity + adjacent-transmit mute bar (Phase 152) --
         hidden entirely when window.ConsoleWorkstation is unavailable or
         resolution fails (see assets/js/console-workstation-panel.js). -->
    <div class="mb-2 d-none console-workstation-bar" id="consoleWorkstationBar"></div>

    <!-- View tabs (b2) — hidden until a designer view exists -->
    <ul class="nav nav-tabs mb-3 d-none" id="consoleTabs"></ul>

    <!-- Recall tab (Console rebuild) — closed by default; fetches only
         when opened (assets/js/console-recall.js's own "genuinely
         secondary/lightweight" framing, plan.md). console-recall.js
         toggles d-none on THIS element directly on open/close. -->
    <div class="console-recall-card d-none mb-3" id="consoleRecallPanel"></div>

    <!-- Patch rail (Console rebuild) — every active standing patch/group,
         live via comm:route_*/comm:group_* SSE. Hidden until at least one
         patch exists (assets/js/console-patch-rail.js). -->
    <div class="mb-2 d-none console-patch-rail-wrap" id="consolePatchRail"></div>

    <!-- Simulselect master PTT (Phase 114b3) - hidden until at least one
         TX-capable channel is a simulselect member (assets/js/console.js). -->
    <div class="mb-2 d-none" id="consoleSimulselectBar"></div>

    <!-- Strip bank: the active view, or the auto-generated all-channels view -->
    <div class="console-bank" id="consoleBank">
        <div class="text-body-secondary p-4" id="consoleLoading">
            <span class="spinner-border spinner-border-sm me-2"></span>Loading channels&hellip;
        </div>
    </div>

</div>

<!-- console-audio-logic.js, console-audio.js, and console-mic.js all moved
     into inc/navbar.php on 2026-09-08 (unification plan step 1/3) so the
     new navbar Simulselect widget can use them from any page. console.php
     keeps its own registerChannels()/load() calls further down (via
     console.js) — the strip bank still owns the full per-channel Select/
     Monitor/Mute/Volume UI; only the shared underlying files moved.
     console-workstation.js: since Phase 155 (GH#108 S1) it is loaded by
     inc/navbar.php, once, on every page -- the Phone widget needs the
     workstation token everywhere, not only here, and two definitions of the
     same identity helper is exactly how the widget ended up with an empty
     token on every other page. It is no longer loaded a second time from
     this file; it is always defined before any script below runs. -->
<script src="assets/js/console-playback.js?v=<?php echo asset_v('assets/js/console-playback.js'); ?>"></script>
<!-- Acoustic proximity auto-discovery -- loads before console-workstation-
     panel.js, which calls window.ConsoleBeacon.setMyBeaconCode()/
     startDetection() once it has the server's resolved beacon_code. -->
<script src="assets/js/console-beacon.js?v=<?php echo asset_v('assets/js/console-beacon.js'); ?>"></script>
<script src="assets/js/console-workstation-panel.js?v=<?php echo asset_v('assets/js/console-workstation-panel.js'); ?>"></script>
<script src="assets/js/console-patch-rail.js?v=<?php echo asset_v('assets/js/console-patch-rail.js'); ?>"></script>
<script src="assets/js/console-recall.js?v=<?php echo asset_v('assets/js/console-recall.js'); ?>"></script>
<!-- Phase 152 -- the thin position layer. Loads after console-mic.js so
     window.ConsoleMatrix.getSessionToken() is defined for its heartbeat
     piggyback, though it degrades fine if that global is absent (an
     install with no matrix service deployed). -->
<script src="assets/js/console-positions.js?v=<?php echo asset_v('assets/js/console-positions.js'); ?>"></script>
<script src="assets/js/console.js?v=<?php echo asset_v('assets/js/console.js'); ?>"></script>
<!-- Phase 152 prerequisite #8 -- physical PTT (Gamepad API / USB foot
     switch + keyboard fallback). Loads last: only ever queries the DOM
     and calls window.ConsoleAudio/window.ConsoleMatrix at key-press time,
     never at load time, so strict ordering relative to console.js's own
     execution doesn't matter -- placed last for readability only. -->
<script src="assets/js/console-hid.js?v=<?php echo asset_v('assets/js/console-hid.js'); ?>"></script>
</body>
</html>
