<?php
/**
 * NewUI v4.0 — Zello message archive (Phase 101, 2026-07-01)
 *
 * Full archive view for Zello traffic. The dashboard Zello widget's
 * loadHistory() only shows the 50 most recent messages; this page
 * lets the operator browse the full history filtered by date range,
 * direction, sender, or type (voice / text / image).
 *
 * Reuses api/zello-messages.php with the ?limit, ?after_id, and
 * per-column filters already supported.
 *
 * Mirrors the dmr-archive.php pattern so the two pages feel the same.
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

// api/zello-messages.php gates on session-auth only, not RBAC — so
// this page mirrors that. If a Zello-specific RBAC permission lands
// later, add the check here.

ini_set('display_errors', '0');

$user        = e($_SESSION['user']);
$level       = current_role_name();
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$userPerms   = rbac_user_permissions();
$active_page = 'zello-archive';
?><!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo $csrf; ?>">
<title>Zello Archive — Tickets CAD</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo file_exists(__DIR__ . '/assets/css/dashboard.css') ? filemtime(__DIR__ . '/assets/css/dashboard.css') : NEWUI_VERSION; ?>">
<link rel="stylesheet" href="assets/css/zello-widget.css?v=<?php echo file_exists(__DIR__ . '/assets/css/zello-widget.css') ? filemtime(__DIR__ . '/assets/css/zello-widget.css') : NEWUI_VERSION; ?>">
<style>
.z-arch-toolbar {
    position: sticky; top: 0; z-index: 10;
    background: var(--bs-body-bg);
    border-bottom: 1px solid var(--bs-border-color);
    padding: 0.75rem 1rem; margin-bottom: 1rem;
}
.z-arch-stats { color: var(--bs-secondary-color); font-size: 0.875rem; }
.z-arch-card {
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 0.5rem;
}
.z-arch-head { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
.z-arch-time { font-family: monospace; color: var(--bs-secondary-color); }
.z-arch-src  { font-weight: 600; }
.z-arch-dir  { font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 3px; text-transform: uppercase; }
.z-arch-dir.incoming { background: var(--bs-info-bg-subtle);    color: var(--bs-info-text-emphasis); }
.z-arch-dir.outgoing { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
.z-arch-type { font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 3px; background: var(--bs-secondary-bg); color: var(--bs-body-color); }
.z-arch-body { margin-top: 0.4rem; color: var(--bs-body-color); font-size: 0.95rem; }
.z-arch-body.empty { color: var(--bs-secondary-color); font-style: italic; }
.z-arch-img { max-width: 240px; max-height: 240px; border-radius: 4px; cursor: pointer; display: block; margin-top: 0.4rem; }
.z-arch-audio { display: block; width: 100%; max-width: 400px; margin-top: 0.4rem; }
.z-arch-loading { padding: 2rem; text-align: center; color: var(--bs-secondary-color); }
.z-arch-empty   { padding: 3rem; text-align: center; color: var(--bs-secondary-color); }
</style>
</head>
<body>
<?php include_once __DIR__ . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
<div class="z-arch-toolbar">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <label for="zArchType" class="form-label small mb-1">Type</label>
            <select id="zArchType" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="voice">Voice</option>
                <option value="text">Text</option>
                <option value="image">Image</option>
            </select>
        </div>
        <div class="col-auto">
            <label for="zArchDir" class="form-label small mb-1">Direction</label>
            <select id="zArchDir" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="incoming">Incoming</option>
                <option value="outgoing">Outgoing</option>
            </select>
        </div>
        <div class="col-auto" style="min-width:14rem;flex:1;">
            <label for="zArchSearch" class="form-label small mb-1">Search (sender / channel / body)</label>
            <input type="search" id="zArchSearch" class="form-control form-control-sm" placeholder="ejosterberg, TicketsCAD-Group, ...">
        </div>
        <div class="col-auto">
            <label for="zArchLimit" class="form-label small mb-1">Show up to</label>
            <select id="zArchLimit" class="form-select form-select-sm">
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200" selected>200</option>
            </select>
        </div>
        <div class="col-auto">
            <button id="zArchApply" type="button" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> Apply
            </button>
        </div>
    </div>
    <div class="z-arch-stats mt-2" id="zArchStats">&nbsp;</div>
</div>

<div id="zArchResults">
    <div class="z-arch-loading">
        <div class="spinner-border spinner-border-sm"></div>
        Loading messages...
    </div>
</div>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo file_exists(__DIR__ . '/assets/js/toolbar.js') ? filemtime(__DIR__ . '/assets/js/toolbar.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/theme-manager.js?v=<?php echo file_exists(__DIR__ . '/assets/js/theme-manager.js') ? filemtime(__DIR__ . '/assets/js/theme-manager.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/zello-archive.js?v=<?php echo file_exists(__DIR__ . '/assets/js/zello-archive.js') ? filemtime(__DIR__ . '/assets/js/zello-archive.js') : NEWUI_VERSION; ?>"></script>
</body>
</html>
