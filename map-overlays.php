<?php
/**
 * NewUI v4.0 — Event Map Overlays admin page (Phase 110 / GH #43)
 *
 * Upload special-event venue maps (JPG/PNG, or PDF page 1 converted
 * server-side) and geo-anchor them over the CAD base map. Positioned
 * overlays appear in the layer control of every MapPrefs map — above
 * base tiles, below markups and unit/incident markers.
 *
 * Layout:
 *   Top    — Upload form (name + file)
 *   Middle — Table of existing overlays (thumbnail, name, opacity,
 *            enabled toggle, Position / Rename / Delete)
 *   Bottom — Positioning editor: full-width Leaflet map with three
 *            corner drag handles + a centroid move handle, live
 *            opacity slider, Save / Cancel.
 *
 * Backend: api/map-image-overlays.php (GET list; POST create/update/delete).
 * Access:  action.manage_map_overlays (admins bypass via is_admin()).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

// GH #13 — pick the session profile matching the client's cookie
// (TCADMOBILE vs PHPSESSID) before session_start().
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// Friendly 403 (inc/denied.php) when the user lacks the manage permission.
// is_admin() bypasses inside rbac_require_screen.
rbac_require_screen('action.manage_map_overlays');

$user        = e($_SESSION['user']);
$level       = current_role_name();
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$active_page = 'settings';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.map_overlays', 'Event Map Overlays')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">

    <style>
        /* Drag handles for the positioning editor */
        .overlay-handle {
            width: 16px; height: 16px;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 0 4px rgba(0, 0, 0, .5);
            cursor: grab;
        }
        .overlay-handle.overlay-handle-move {
            background: #ffc107;
            border-radius: 4px;
            cursor: move;
        }
        #overlayEditorMap { height: 65vh; min-height: 320px; }
        .overlay-thumb { max-height: 40px; max-width: 80px; object-fit: contain; }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-image me-2"></i><?php echo e(t('page.map_overlays', 'Event Map Overlays')); ?>
        </h4>
        <span class="text-body-secondary small ms-3 d-none d-md-inline">
            <?php echo e(t('map_overlays.subtitle', 'Drape event venue maps (JPG, PNG or PDF) over the CAD map.')); ?>
        </span>
        <div class="ms-auto">
            <a href="settings.php" class="btn btn-sm btn-outline-secondary" title="Back to Settings">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('btn.back_to_settings', 'Settings')); ?>
            </a>
        </div>
    </div>

    <!-- Inline alert region (alerts injected by JS) -->
    <div id="overlayAlertSlot" aria-live="polite"></div>

    <!-- ── Upload form ─────────────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header py-2 fw-semibold">
            <i class="bi bi-upload me-1"></i><?php echo e(t('map_overlays.upload', 'Upload a new overlay')); ?>
        </div>
        <div class="card-body">
            <form id="overlayUploadForm" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="overlayName"><?php echo e(t('map_overlays.name', 'Name')); ?></label>
                    <input type="text" class="form-control form-control-sm" id="overlayName" name="name"
                           maxlength="128" placeholder="e.g. County Fair 2026 venue map">
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="overlayFile"><?php echo e(t('map_overlays.file', 'Image file (JPG, PNG or PDF — max 20 MB)')); ?></label>
                    <input type="file" class="form-control form-control-sm" id="overlayFile" name="file"
                           accept=".jpg,.jpeg,.png,.pdf" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary" id="overlayUploadBtn">
                        <i class="bi bi-upload me-1"></i><?php echo e(t('btn.upload', 'Upload')); ?>
                    </button>
                </div>
            </form>
            <div class="form-text mt-2">
                <?php echo e(t('map_overlays.upload_hint', 'PDFs use page 1 only. After upload, click Position to line the artwork up with the streets under it.')); ?>
            </div>
        </div>
    </div>

    <!-- ── Overlay table ───────────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header py-2 fw-semibold">
            <i class="bi bi-stack me-1"></i><?php echo e(t('map_overlays.existing', 'Overlays')); ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="overlayTable">
                    <thead>
                        <tr>
                            <th style="width:90px"><?php echo e(t('map_overlays.col.preview', 'Preview')); ?></th>
                            <th><?php echo e(t('map_overlays.col.name', 'Name')); ?></th>
                            <th style="width:110px"><?php echo e(t('map_overlays.col.opacity', 'Opacity')); ?></th>
                            <th style="width:110px"><?php echo e(t('map_overlays.col.positioned', 'Positioned')); ?></th>
                            <th style="width:90px"><?php echo e(t('map_overlays.col.enabled', 'Enabled')); ?></th>
                            <th style="width:230px" class="text-end"><?php echo e(t('map_overlays.col.actions', 'Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="overlayTableBody">
                        <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Positioning editor (hidden until Position is clicked) ───── -->
    <div class="card mb-3 d-none" id="overlayEditorCard">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
            <span class="fw-semibold">
                <i class="bi bi-arrows-move me-1"></i><?php echo e(t('map_overlays.position', 'Position')); ?>:
                <span id="overlayEditorName"></span>
            </span>
            <span class="text-body-secondary small d-none d-lg-inline">
                <?php echo e(t('map_overlays.position_hint', 'Drag the blue corner handles to scale/rotate, the yellow handle to move.')); ?>
            </span>
            <div class="ms-auto d-flex align-items-center gap-2">
                <label class="small mb-0" for="overlayOpacity"><?php echo e(t('map_overlays.opacity', 'Opacity')); ?></label>
                <input type="range" class="form-range" id="overlayOpacity"
                       min="0.1" max="1" step="0.05" value="0.7" style="width:120px">
                <span class="small text-body-secondary" id="overlayOpacityVal" style="width:2.5em">0.70</span>
                <button type="button" class="btn btn-sm btn-success" id="overlayEditorSave">
                    <i class="bi bi-check-lg me-1"></i><?php echo e(t('btn.save', 'Save')); ?>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="overlayEditorCancel">
                    <?php echo e(t('btn.cancel', 'Cancel')); ?>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="overlayEditorMap"></div>
        </div>
    </div>
</main>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo asset_v('assets/js/leaflet-mobile-fit.js'); ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>
<script src="assets/js/map-defaults.js?v=<?php echo asset_v('assets/js/map-defaults.js'); ?>"></script>
<script src="assets/js/map-image-overlays.js?v=<?php echo asset_v('assets/js/map-image-overlays.js'); ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/map-overlay-editor.js?v=<?php echo asset_v('assets/js/map-overlay-editor.js'); ?>"></script>

</body>
</html>
