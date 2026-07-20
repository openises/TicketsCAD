<?php
/**
 * NewUI v4.0 — Voice & Speech admin (Phase 113).
 *
 * Standalone admin page (same pattern as weather-alerts.php) for configuring
 * text-to-speech ENGINES and routing each speech APPLICATION (weather
 * bulletins, radio-AI replies, Zello read-outs, announcements, phone callouts)
 * to an engine + voice. Every engine has a Test-Listen button so an admin can
 * hear it in the browser before committing. Piper stays the offline default +
 * mandatory fallback.
 *
 * Backend: api/tts.php. Admin-only: action.manage_tts.
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

if (!rbac_can('action.manage_tts')) {
    http_response_code(403);
    $bs_theme = (($_SESSION['day_night'] ?? 'Day') === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Voice &amp; Speech — Tickets NewUI</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css"></head>
<body><main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Voice &amp; Speech configuration requires the "Manage Voice &amp; Speech" permission
        (<code>action.manage_tts</code>). Ask an administrator to grant it to your role.</p>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Back to dashboard</a>
    </div>
</main></body></html>
    <?php
    exit;
}

$bs_theme    = (($_SESSION['day_night'] ?? 'Day') === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$active_page = 'settings';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Voice &amp; Speech — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-soundwave text-primary me-2"></i>Voice &amp; Speech</h4>
        <a href="docs/TTS-DEPLOYMENT.md" target="_blank" class="ms-auto btn btn-sm btn-outline-secondary"><i class="bi bi-question-circle me-1"></i>Guide</a>
    </div>

    <p class="text-body-secondary small">
        TicketsCAD speaks in several places — weather bulletins on the radio, operator-approved radio-AI replies,
        Zello read-outs, station announcements, and phone callouts. Configure a <strong>text-to-speech engine</strong>
        below, then choose which engine + voice each <strong>speech application</strong> uses.
        <strong>Piper</strong> is the built-in offline default and the automatic fallback if a hosted engine is
        unreachable — so speech never goes silent during an internet outage.
    </p>

    <div id="ttsToast" class="alert d-none" role="status"></div>

    <!-- ── Engines ─────────────────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center">
            <i class="bi bi-cpu me-2"></i>Engines
            <button class="btn btn-sm btn-primary ms-auto" id="ttsAddEngine"><i class="bi bi-plus-lg me-1"></i>Add engine</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr>
                    <th>Engine</th><th>Driver</th><th>Status</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody id="ttsEnginesBody">
                    <tr><td colspan="4" class="text-center text-body-secondary py-3">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Speech applications ─────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-list-check me-2"></i>Speech applications</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr>
                    <th>Application</th><th>Engine</th><th>Voice (override)</th><th>Rate</th><th>Fallback</th><th></th>
                </tr></thead>
                <tbody id="ttsAppsBody">
                    <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ── Engine editor modal ─────────────────────────────────────────── -->
<div class="modal fade" id="ttsEngineModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="ttsEngineModalTitle">Add engine</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body py-2">
        <input type="hidden" id="ttsEngId" value="0">
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0">Name</label>
            <input type="text" class="form-control form-control-sm" id="ttsEngKey" placeholder="e.g. kokoro-local">
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0">Driver</label>
            <select class="form-select form-select-sm" id="ttsEngDriver"></select>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0">Label (shown in menus)</label>
            <input type="text" class="form-control form-control-sm" id="ttsEngLabel" placeholder="Kokoro (local, Docker)">
        </div>
        <div id="ttsEngFields"></div>
        <div class="mb-2" id="ttsEngKeyWrap">
            <label class="form-label form-label-sm mb-0">API key <span class="text-body-secondary">(stored server-side, 0640; leave blank to keep existing)</span></label>
            <input type="password" class="form-control form-control-sm" id="ttsEngKey2" autocomplete="off" placeholder="•••••••• (leave blank to keep)">
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="ttsEngEnabled" checked>
            <label class="form-check-label small" for="ttsEngEnabled">Enabled</label>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-outline-info me-auto" id="ttsEngTest"><i class="bi bi-play-circle me-1"></i>Test — Listen</button>
        <button type="button" class="btn btn-sm btn-primary" id="ttsEngSave">Save</button>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
      <audio id="ttsTestAudio" class="d-none"></audio>
    </div>
  </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/tts-config.js?v=<?php echo asset_v('assets/js/tts-config.js'); ?>"></script>
</body>
</html>
