<?php
/**
 * NewUI v4.0 — Public Audio Stream Channel Admin (2026-09-08)
 *
 * Standalone admin page (same precedent as matrix-admin.php /
 * sip-trunks-admin.php / ics-form-type-admin.php) for public HTTP audio-
 * stream channels (Broadcastify, LiveATC, NOAA Weather Radio, Icecast, or
 * any plain HTTP MP3/AAC/OGG feed) — Eric: "a source of audio we can
 * listen to while testing services... I want to create multiple streams
 * as needed." Backend: api/http-stream-channels.php.
 *
 * Single-tier RBAC — action.manage_matrix (reused from the Patch Matrix
 * page this is a sibling of, not a new permission code).
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
    <title>Stream Channels — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Stream channel configuration requires the "Manage Audio Matrix"
           permission. Ask an administrator to grant your role <code>action.manage_matrix</code>.</p>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">Back to Settings</a>
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
    <title>Stream Channels — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-broadcast text-primary me-2"></i>Public Audio Stream Channels</h4>
        <a href="matrix-admin.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-diagram-3 me-1"></i>Patch Matrix</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="hscToast" class="alert d-none" role="status"></div>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        A <strong>stream channel</strong> is a public HTTP audio feed — Broadcastify, LiveATC,
        NOAA Weather Radio, Icecast, or any plain MP3/AAC/OGG stream — patched into the audio
        matrix like any other channel. It's read-only (nothing transmits back to the stream)
        and appears on the <a href="matrix-admin.php">Patch Matrix</a> once created, ready to
        route to Console strips. Saving here takes effect on the running matrix service
        immediately — no restart needed. Requires <code>ffmpeg</code> installed on the
        audio-matrix host. See
        <a href="documentation/?doc=AUDIO-MATRIX-SETUP#public-audio-stream-channels-broadcastify-liveatc-noaa-weather-radio-icecast" target="_blank" rel="noopener">the setup guide</a>.
    </div>

    <div class="card mb-3" id="hscListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Stream Channels</span>
            <button class="btn btn-sm btn-primary" id="hscBtnNew"><i class="bi bi-plus-lg me-1"></i>New Stream</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Channel Key</th>
                            <th>Regulatory Class</th>
                            <th>Stream URL</th>
                            <th>Status</th>
                            <th style="width:110px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="hscListRows"><tr><td colspan="6" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ══════════════════ Create/edit modal ══════════════════ -->
<div class="modal fade" id="hscModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="hscModalTitle"><i class="bi bi-plus-lg me-2"></i>New Stream</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="hscId" value="0">
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="hscChannelKey">Channel Key</label>
            <input type="text" class="form-control form-control-sm font-monospace" id="hscChannelKey"
                   maxlength="120" placeholder="e.g. noaa_wx_msp">
            <div class="form-text">Letters, numbers, and . _ : -  only. Cannot be changed after creation.</div>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="hscLabel">Label</label>
            <input type="text" class="form-control form-control-sm" id="hscLabel" maxlength="120"
                   placeholder="e.g. NOAA Weather Radio - Minneapolis/St. Paul">
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="hscUrl">Stream URL</label>
            <input type="text" class="form-control form-control-sm" id="hscUrl"
                   placeholder="http://example.org/stream.mp3">
            <div class="form-text">A direct, playable stream URL — not a "listen" web page. See the setup guide
                if you're not sure how to find one for a service like Broadcastify or TuneIn.</div>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="hscRegClass">Regulatory Class</label>
            <select class="form-select form-select-sm" id="hscRegClass">
                <option value="internal" selected>Internal (default — a public stream)</option>
                <option value="amateur">Amateur</option>
                <option value="commercial">Commercial</option>
                <option value="pstn">PSTN</option>
            </select>
        </div>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" id="hscEnabled" checked>
            <label class="form-check-label small" for="hscEnabled">Enabled</label>
        </div>
        <div id="hscModalError" class="alert alert-danger small mt-2 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="hscBtnDelete">
            <i class="bi bi-trash me-1"></i>Delete Stream</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="hscBtnSave"><i class="bi bi-save me-1"></i>Save Stream</button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/stream-channels-admin.js?v=<?php echo asset_v('assets/js/stream-channels-admin.js'); ?>"></script>
</body>
</html>
