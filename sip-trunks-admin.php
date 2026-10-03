<?php
/**
 * NewUI v4.0 — Inbound SIP/PBX Trunk Admin (Phase 149, plan.md §8)
 *
 * Standalone admin page (same precedent as matrix-admin.php /
 * ics-form-type-admin.php / public-board-admin.php / voice-speech.php)
 * for `pbx_trunks` — configure one or more SIP trunks/lines that
 * api/sip-ingest.php accepts webhooks for. Backend: api/sip-trunks.php.
 *
 * Single-tier RBAC — action.manage_calls (Super Admin + Org Admin by
 * default, plan.md §5's table).
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

if (!rbac_can('action.manage_calls')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inbound Calls — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Inbound-call trunk configuration requires the "Manage Inbound Calls"
           permission. Ask an administrator to grant your role <code>action.manage_calls</code>.</p>
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
    <title>Inbound Calls — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-telephone-inbound text-primary me-2"></i>Inbound Calls (SIP/PBX)</h4>
        <a href="settings.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="stToast" class="alert d-none" role="status"></div>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        A <strong>trunk</strong> represents one SIP trunk or PBX line. A small companion program
        (the <strong>bridge</strong>, in <code>services/sip-bridge/</code>) has to run somewhere that can
        reach both your phone system and this server; it watches your PBX (3CX, Asterisk/FreePBX, or a
        hosted provider) and reports calls here using the trunk's token. <strong>Creating a trunk does
        not connect anything by itself</strong> &mdash; use the <strong>Setup</strong> button on a trunk
        for a ready-to-paste configuration and the steps to start the bridge. The
        <strong>Bridge</strong> column turns green when the bridge is running and talking to this server.
        See <a href="documentation/?doc=INBOUND-SIP-CALLS" target="_blank" rel="noopener">the setup guide</a>
        for the full walkthrough.
    </div>

    <div class="card mb-3" id="stListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Trunks</span>
            <button class="btn btn-sm btn-primary" id="stBtnNew"><i class="bi bi-plus-lg me-1"></i>New Trunk</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Org</th>
                            <th>Mute bypass</th>
                            <th class="text-end">Wrap-up (s)</th>
                            <th class="text-end">Reassign grace (s)</th>
                            <th>Token</th>
                            <th>Bridge</th>
                            <th>Status</th>
                            <th style="width:210px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="stListRows"><tr><td colspan="9" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ══════════════════ Create/edit modal ══════════════════ -->
<div class="modal fade" id="stModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stModalTitle"><i class="bi bi-plus-lg me-2"></i>New Trunk</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="stId" value="0">
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="stLabel">Label</label>
            <input type="text" class="form-control form-control-sm" id="stLabel" maxlength="100" placeholder="e.g. Main Dispatch Line">
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="stOrgId">Organization (optional)</label>
            <select class="form-select form-select-sm" id="stOrgId">
                <option value="">Install-wide (visible to every organization)</option>
            </select>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label form-label-sm mb-0" for="stWrapup">Wrap-up seconds</label>
                <input type="number" class="form-control form-control-sm" id="stWrapup" value="90" min="0" step="1">
            </div>
            <div class="col-6">
                <label class="form-label form-label-sm mb-0" for="stGrace">Reassign grace seconds</label>
                <input type="number" class="form-control form-control-sm" id="stGrace" value="20" min="0" step="1">
                <div class="form-text">How long a fresh claim can be instantly self-corrected (FR-18a).</div>
            </div>
        </div>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" id="stMuteBypass" checked>
            <label class="form-check-label small" for="stMuteBypass">Ringing tone bypasses mute (recommended for a single emergency line)</label>
        </div>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" id="stEnabled" checked>
            <label class="form-check-label small" for="stEnabled">Enabled</label>
        </div>
        <div id="stTokenWrap" class="d-none">
            <div class="alert alert-warning small py-2 px-2 mb-1">
                <i class="bi bi-key me-1"></i>
                <strong>New bearer token — shown once, copy it now:</strong>
                <div class="input-group input-group-sm mt-1">
                    <input type="text" class="form-control form-control-sm font-monospace" id="stTokenValue" readonly>
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="stBtnCopyToken"><i class="bi bi-clipboard"></i></button>
                </div>
            </div>
        </div>
        <div id="stModalError" class="alert alert-danger small mt-2 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-warning btn-sm me-auto d-none" id="stBtnRotate">
            <i class="bi bi-arrow-repeat me-1"></i>Rotate Token</button>
        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="stBtnDelete">
            <i class="bi bi-trash me-1"></i>Delete Trunk</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="stBtnSave"><i class="bi bi-save me-1"></i>Save Trunk</button>
      </div>
    </div>
  </div>
</div>


<!-- ══════════════════ Setup / connect-your-phone-system modal ══════════════════ -->
<div class="modal fade" id="stSetupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plug me-2"></i>Connect your phone system &mdash; <span id="stSetupTrunkLabel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <span class="fw-semibold">Bridge status:</span>
            <span id="stSetupStatus" class="badge text-bg-secondary">Checking&hellip;</span>
            <span id="stSetupStatusNote" class="small text-body-secondary"></span>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-md-5">
                <label class="form-label form-label-sm mb-0" for="stPbxType">Which phone system?</label>
                <select class="form-select form-select-sm" id="stPbxType">
                    <option value="threecx">3CX (Call Control API)</option>
                    <option value="ami">Asterisk / FreePBX (Manager Interface)</option>
                    <option value="webhook">Hosted SIP provider (webhook)</option>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label form-label-sm mb-0" for="stSetupUrl">TicketsCAD address <span class="text-body-secondary">(as the bridge computer will reach it)</span></label>
                <input type="text" class="form-control form-control-sm font-monospace" id="stSetupUrl">
            </div>
        </div>

        <ol class="small ps-3 mb-2" id="stSetupSteps"></ol>

        <div class="d-flex align-items-center mb-1">
            <span class="fw-semibold small">bridge.ini</span>
            <span id="stSetupTokenNote" class="small text-body-secondary ms-2"></span>
            <button class="btn btn-outline-secondary btn-sm ms-auto" type="button" id="stBtnCopyConfig"><i class="bi bi-clipboard me-1"></i>Copy</button>
        </div>
        <pre class="border rounded p-2 small mb-2" id="stConfigOut" style="max-height:260px; overflow:auto; white-space:pre;"></pre>

        <div class="border rounded p-2 small">
            <div class="fw-semibold mb-1"><i class="bi bi-megaphone me-1"></i>Test the TicketsCAD side first</div>
            Rings one clearly-labelled <strong>TEST CALL</strong> on every dispatcher's screen for about 12 seconds
            (banner and tone), then clears itself. It proves the part inside TicketsCAD works even before your
            phone system is connected.
            <div class="mt-1">
                <button class="btn btn-sm btn-outline-primary" type="button" id="stBtnTestCall"><i class="bi bi-telephone-inbound me-1"></i>Send test call</button>
                <span id="stTestCallNote" class="ms-2 text-body-secondary"></span>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/sip-trunks-admin.js?v=<?php echo asset_v('assets/js/sip-trunks-admin.js'); ?>"></script>
</body>
</html>
