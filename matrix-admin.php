<?php
/**
 * NewUI v4.0 — Audio Matrix Patch Admin (Phase 114c, closes SPEC-STATUS.md §B1)
 *
 * Standalone admin page (same precedent as ics-form-type-admin.php /
 * public-board-admin.php / voice-speech.php) for `comm_routes` — the audio
 * patch matrix's route table. Before this page the table had a schema and
 * a reader (services/audio-matrix/service.py) but no writer anywhere in
 * the application: a patch between two channels could only be created by
 * hand-written SQL. Backend: api/matrix.php; validation shared with the
 * live matrix service's own rules in inc/matrix-routes.php.
 *
 * Single-tier RBAC — action.manage_matrix, seeded to Super Admin + Org
 * Admin by sql/run_phase114c_comm_routes.php. Unlike the two-tier
 * install-wide/org-scoped permissions elsewhere in this codebase,
 * comm_channels/comm_routes carry no org_id column at all — patching is
 * an install-wide concept (the audio bus is one process per install), so
 * there is no narrower scope to split out.
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

if (!rbac_can('action.manage_matrix')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audio Matrix — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Audio-matrix patch management requires the "Manage Audio Matrix Routes"
           permission. Ask an administrator to grant your role <code>action.manage_matrix</code>.</p>
        <a href="console.php" class="btn btn-sm btn-outline-secondary">Back to Console</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user     = e($_SESSION['user']);
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
    <title>Audio Matrix — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/matrix-admin.css?v=<?php echo asset_v('assets/css/matrix-admin.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1200px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Audio Matrix — Patch Routes</h4>
        <a href="console.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Console</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="mxToast" class="alert d-none" role="status"></div>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        A <strong>patch</strong> routes one channel's audio into another (e.g. a DMR
        talkgroup &harr; a Zello channel). Patches are directional — a two-way patch
        is two rows, one each direction. <strong>No silent routes:</strong> every
        active patch below is exactly what <code>services/audio-matrix</code> loads;
        nothing can be patched that isn't visible here. Amateur&harr;commercial and
        amateur&harr;PSTN patches are blocked by FCC Part&nbsp;97.113 unless created
        with the audited cross-class override.
    </div>

    <!-- ══════════════════ Grid ══════════════════ -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-grid-3x3 me-2"></i>Patch Matrix</span>
            <span class="text-body-secondary small">rows = source &rarr; columns = destination</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 mx-grid" id="mxGridTable">
                    <thead><tr><th id="mxGridEmptyCell">Loading&hellip;</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="d-flex gap-3 mt-2 small text-body-secondary flex-wrap">
                <span><span class="mx-swatch bg-success"></span> active patch</span>
                <span><span class="mx-swatch bg-secondary"></span> disabled patch</span>
                <span><span class="mx-swatch bg-warning"></span> cross-class override</span>
                <span><span class="mx-swatch mx-swatch-empty"></span> click to create</span>
            </div>
        </div>
    </div>

    <!-- ══════════════════ List panel ══════════════════ -->
    <div class="card mb-3" id="mxListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>All Patches</span>
            <button class="btn btn-sm btn-primary" id="mxBtnNew"><i class="bi bi-plus-lg me-1"></i>New Patch</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th></th>
                            <th>Destination</th>
                            <th class="text-end">Gain</th>
                            <th class="text-end">Priority</th>
                            <th>Ducking</th>
                            <th>Status</th>
                            <th>Note</th>
                            <th style="width:110px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="mxListRows"><tr><td colspan="9" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ══════════════════ Create/edit modal ══════════════════ -->
<div class="modal fade" id="mxModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mxModalTitle"><i class="bi bi-plus-lg me-2"></i>New Patch</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="mxId" value="0">
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="mxSrc">Source channel</label>
            <select class="form-select form-select-sm" id="mxSrc"></select>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="mxDst">Destination channel</label>
            <select class="form-select form-select-sm" id="mxDst"></select>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label form-label-sm mb-0" for="mxGain">Gain (dB)</label>
                <input type="number" class="form-control form-control-sm" id="mxGain" value="0.0" step="0.1" min="-60" max="20">
            </div>
            <div class="col-6">
                <label class="form-label form-label-sm mb-0" for="mxPriority">Priority</label>
                <input type="number" class="form-control form-control-sm" id="mxPriority" value="0" step="1">
                <div class="form-text">Higher wins ducking contests into the same destination.</div>
            </div>
        </div>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" id="mxDucking" checked>
            <label class="form-check-label small" for="mxDucking">Ducking — attenuate under a higher-priority source</label>
        </div>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" id="mxEnabled" checked>
            <label class="form-check-label small" for="mxEnabled">Enabled</label>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="mxNote">Note (optional)</label>
            <input type="text" class="form-control form-control-sm" id="mxNote" maxlength="255" placeholder="e.g. exercise patch, remove after drill">
        </div>
        <div id="mxCrossClassWrap" class="d-none">
            <div class="alert alert-warning small py-2 px-2 mb-1">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <span id="mxCrossClassText"></span>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="mxAllowCrossClass">
                <label class="form-check-label small" for="mxAllowCrossClass">
                    I acknowledge this cross-class patch (audited) and authorize it.
                </label>
            </div>
        </div>
        <div id="mxModalError" class="alert alert-danger small mt-2 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger btn-sm me-auto d-none" id="mxBtnDelete">
            <i class="bi bi-trash me-1"></i>Delete Patch</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="mxBtnSave"><i class="bi bi-save me-1"></i>Save Patch</button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/matrix-admin.js?v=<?php echo asset_v('assets/js/matrix-admin.js'); ?>"></script>
</body>
</html>
