<?php
/**
 * NewUI v4.0 — DMR call archive (Phase 86-archive)
 *
 * Full archive view for DMR recordings. The dashboard radio widget
 * only shows the 100 most recent calls; this page lets the operator
 * browse the entire `dmr_messages` table filtered by date range,
 * direction, callsign, or transcript text.
 *
 * Reuses api/dmr-history.php (extended in fix-16 to accept
 * date_from / date_to / direction / search filters) and the same
 * api/dmr-audio.php playback proxy as the radio widget.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

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

// Same RBAC gate as api/dmr-audio.php and api/dmr-history.php.
$canView = is_admin() || rbac_can('action.dmr_receive') || rbac_can('action.play_dmr_audio');
if (!$canView) {
    http_response_code(403);
    echo '<h1>403 — Missing permission: action.dmr_receive</h1>';
    exit;
}

// Suppress display_errors so any stray PHP notice doesn't leak into
// the rendered HTML and break layout (the way an undefined $level
// notice did on radio-ai.php before the same fix landed there).
ini_set('display_errors', '0');

$user        = e($_SESSION['user']);
$level       = current_role_name();        // navbar.php renders this
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$userPerms   = rbac_user_permissions();    // navbar gates some links on these
$active_page = 'dmr-archive';
?><!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo $csrf; ?>">
<title>DMR Archive — Tickets CAD</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
<!-- dashboard.css owns the .nav-main / .nav-menu-btn styles the
     shared navbar template relies on; without it the menu lays out
     as bare inline links instead of the proper toolbar. -->
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo file_exists(__DIR__ . '/assets/css/dashboard.css') ? filemtime(__DIR__ . '/assets/css/dashboard.css') : NEWUI_VERSION; ?>">
<link rel="stylesheet" href="assets/css/radio-widget.css?v=<?php echo file_exists(__DIR__ . '/assets/css/radio-widget.css') ? filemtime(__DIR__ . '/assets/css/radio-widget.css') : NEWUI_VERSION; ?>">
<style>
.dmr-archive-toolbar {
    position: sticky; top: 0; z-index: 10;
    background: var(--bs-body-bg);
    border-bottom: 1px solid var(--bs-border-color);
    padding: 0.75rem 1rem; margin-bottom: 1rem;
}
.dmr-archive-stats { color: var(--bs-secondary-color); font-size: 0.875rem; }
.dmr-archive-card {
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 0.5rem;
}
.dmr-archive-card.playing { border-color: var(--bs-primary); }
.dmr-archive-head { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
.dmr-archive-time { font-family: monospace; color: var(--bs-secondary-color); }
.dmr-archive-src  { font-weight: 600; }
.dmr-archive-dur  { color: var(--bs-secondary-color); font-size: 0.875rem; }
.dmr-archive-dir  { font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 3px; text-transform: uppercase; }
.dmr-archive-dir.rx { background: var(--bs-info-bg-subtle);    color: var(--bs-info-text-emphasis); }
.dmr-archive-dir.tx { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
.dmr-archive-transcript { margin-top: 0.4rem; color: var(--bs-body-color); font-size: 0.95rem; }
.dmr-archive-transcript.empty { color: var(--bs-secondary-color); font-style: italic; }
.dmr-archive-replay { margin-left: auto; }
.dmr-archive-loading { padding: 2rem; text-align: center; color: var(--bs-secondary-color); }
.dmr-archive-empty   { padding: 3rem; text-align: center; color: var(--bs-secondary-color); }
</style>
</head>
<body>
<?php include_once __DIR__ . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
<div class="dmr-archive-toolbar">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <label for="archDateFrom" class="form-label small mb-1">From</label>
            <input type="date" id="archDateFrom" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <label for="archDateTo" class="form-label small mb-1">To</label>
            <input type="date" id="archDateTo" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <label for="archDirection" class="form-label small mb-1">Direction</label>
            <select id="archDirection" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="rx">RX only</option>
                <option value="tx">TX only</option>
            </select>
        </div>
        <div class="col-auto" style="min-width:14rem;flex:1;">
            <label for="archSearch" class="form-label small mb-1">Search (callsign / DMR ID / transcript)</label>
            <input type="search" id="archSearch" class="form-control form-control-sm" placeholder="N0NKI, 3127202, weather, ...">
        </div>
        <div class="col-auto">
            <label for="archLimit" class="form-label small mb-1">Show up to</label>
            <select id="archLimit" class="form-select form-select-sm">
                <option value="100">100</option>
                <option value="250" selected>250</option>
                <option value="500">500</option>
                <option value="1000">1000</option>
            </select>
        </div>
        <div class="col-auto">
            <button id="archApply" type="button" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> Apply
            </button>
        </div>
        <div class="col-auto">
            <button id="archToday" type="button" class="btn btn-outline-secondary btn-sm">Today</button>
            <button id="arch7d"    type="button" class="btn btn-outline-secondary btn-sm">7d</button>
            <button id="arch30d"   type="button" class="btn btn-outline-secondary btn-sm">30d</button>
        </div>
    </div>
    <div class="dmr-archive-stats mt-2" id="archStats">&nbsp;</div>
</div>

<div id="archResults">
    <div class="dmr-archive-loading">
        <div class="spinner-border spinner-border-sm"></div>
        Loading recordings...
    </div>
</div>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<!-- Same toolbar wiring + day/night theme manager every other page
     uses; without them the navbar's buttons (theme toggle, language
     switcher, notification tray) are inert on this page. -->
<script src="assets/js/toolbar.js?v=<?php echo file_exists(__DIR__ . '/assets/js/toolbar.js') ? filemtime(__DIR__ . '/assets/js/toolbar.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/theme-manager.js?v=<?php echo file_exists(__DIR__ . '/assets/js/theme-manager.js') ? filemtime(__DIR__ . '/assets/js/theme-manager.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/dmr-archive.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dmr-archive.js'); ?>"></script>
</body>
</html>
