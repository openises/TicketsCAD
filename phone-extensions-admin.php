<?php
/**
 * NewUI v4.0 — SIP Extension Admin (Phase 153, 2026-09-08)
 *
 * Standalone admin page (same precedent as sip-trunks-admin.php /
 * matrix-admin.php / stream-channels-admin.php) for `phone_extensions` --
 * general and direct-station SIP numbers the browser's own WebRTC phone
 * widget registers against. Backend: api/phone-extensions.php.
 *
 * Single-tier RBAC — action.manage_calls (reused from the Inbound Calls
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
    <title>Phone Extensions — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Phone extension configuration requires the "Manage Inbound Calls"
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
    <title>Phone Extensions — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-telephone text-primary me-2"></i>Phone Extensions</h4>
        <a href="sip-trunks-admin.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-telephone-inbound me-1"></i>Inbound Calls</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="peToast" class="alert d-none" role="status"></div>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        An <strong>extension</strong> is a SIP number the browser's own phone widget registers as
        directly (WebRTC, no separate softphone app). Mark exactly one as the shared
        <strong>general number</strong> — dialing it rings every registered direct-station
        workstation simultaneously, including whichever one places the call. Every other extension
        is a <strong>direct-station number</strong> — dialing
        it rings only the one workstation it's bound to. Binding an extension to a workstation
        targets ringing notifications to whichever operator is currently logged in there instead
        of broadcasting to everyone. A workstation's token is shown on its own Console page
        (the <strong>Phone token</strong> button in the workstation bar) and in the phone's
        &ldquo;not bound&rdquo; panel; on <em>this</em> browser, the <strong>This browser</strong>
        button below fills it in for you. The extension's password here must match the endpoint
        on the PBX &mdash; <strong>supply the PBX's existing password</strong> when you create it, or
        change the PBX to the generated one.
    </div>

    <div class="card mb-3" id="pePbxPanel">
        <div class="card-header"><i class="bi bi-hdd-network me-2"></i>PBX Connection</div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label form-label-sm mb-0" for="pePbxWssUrl">PBX WebSocket URL</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="pePbxWssUrl"
                           placeholder="wss://10.0.0.10:8089/ws">
                    <div class="form-text">Every browser's phone widget connects here. Self-signed
                        certs need a one-time visit + accept at the same host/port over plain
                        HTTPS before the WebSocket will connect.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0" for="pePbxGeneral">General Number (display only)</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="pePbxGeneral"
                           maxlength="10" placeholder="100">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100" id="peBtnSavePbx">Save</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (rbac_can('action.manage_config')): ?>
    <!-- Phase 155 (GH#108 S1) -- install-wide, so it needs action.manage_config
         (Super Admin), not the action.manage_calls this page opens with. -->
    <div class="card mb-3" id="peScopePanel">
        <div class="card-header"><i class="bi bi-window-stack me-2"></i>Where the phone registers</div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label form-label-sm mb-0" for="pePhoneScope">Register the browser phone on</label>
                    <select class="form-select form-select-sm" id="pePhoneScope">
                        <option value="phone_page">The Console and the Phone window only (recommended)</option>
                        <option value="every_page">Every page</option>
                    </select>
                    <div class="form-text">A phone registration lasts only as long as the page that made it, and
                        TicketsCAD is a multi-page app: with &ldquo;every page&rdquo; the extension drops off the PBX
                        (and an active call ends) every time an operator clicks to another page. The recommended
                        setting registers in the <strong>Phone window</strong> (<code>phone.php</code>, opened from
                        the navbar phone button), which never navigates, plus the Console. Only one window of a
                        browser holds the registration at a time. Choose &ldquo;every page&rdquo; only if your
                        operators stay on one page.</div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100" id="peBtnSaveScope">Save</button>
                </div>
            </div>
            <hr class="my-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-10">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="peInternalConstituents" checked>
                        <label class="form-check-label" for="peInternalConstituents">Record calls from our own
                            extensions as Constituents</label>
                    </div>
                    <div class="form-text">When a workstation (say extension 101) calls the general number, the caller
                        is added to the Constituents list under the extension's label, so the dispatcher who answers
                        sees who is calling and a second call from that desk finds the same record. Turn this off if you
                        do not want your own desks in the public contact list; a call from an extension then matches no
                        Constituent. Phone numbers from outside are matched however they are written (<code>+1 612 555 1234</code>,
                        <code>(612) 555-1234</code>, <code>612.555.1234</code> are one person).</div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100" id="peBtnSaveInternal">Save</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3" id="peListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Extensions</span>
            <button class="btn btn-sm btn-primary" id="peBtnNew"><i class="bi bi-plus-lg me-1"></i>New Extension</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Extension</th>
                            <th>Label</th>
                            <th>Type</th>
                            <th>Workstation Token</th>
                            <th>Password</th>
                            <th>Status</th>
                            <th style="width:110px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="peListRows"><tr><td colspan="7" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ══════════════════ Create/edit modal ══════════════════ -->
<div class="modal fade" id="peModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="peModalTitle"><i class="bi bi-plus-lg me-2"></i>New Extension</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="peId" value="0">
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="peExtension">Extension Number</label>
            <input type="text" class="form-control form-control-sm font-monospace" id="peExtension"
                   maxlength="10" placeholder="e.g. 100">
            <div class="form-text">2-10 digits. Cannot be changed after creation.</div>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="peLabel">Label</label>
            <input type="text" class="form-control form-control-sm" id="peLabel" maxlength="100"
                   placeholder="e.g. Workstation 1">
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="peIsGeneral">
            <label class="form-check-label small" for="peIsGeneral">
                This is the shared <strong>general number</strong> (rings every registered workstation)</label>
        </div>
        <div class="mb-2" id="peWsWrap">
            <label class="form-label form-label-sm mb-0" for="peWorkstationToken">Workstation Token (optional)</label>
            <div class="input-group input-group-sm">
                <input type="text" class="form-control form-control-sm font-monospace" id="peWorkstationToken"
                       placeholder="paste from that workstation's Console page">
                <button type="button" class="btn btn-outline-secondary" id="peUseMyToken"
                        title="Fill in the token of the browser you are using right now">This browser</button>
            </div>
            <div class="form-text">Binds this direct number to one workstation, so a ring targets only the
                operator currently logged in there instead of everyone. Leave blank for "not bound yet."
                Each browser profile has its own token (shown by the Console's <strong>Phone token</strong>
                button).</div>
        </div>
        <div class="mb-2" id="pePasswordWrap">
            <label class="form-label form-label-sm mb-0" for="peSipPassword">PBX password (optional)</label>
            <input type="password" class="form-control form-control-sm font-monospace" id="peSipPassword"
                   autocomplete="new-password" maxlength="128" placeholder="leave blank to generate one">
            <div class="form-text">Connecting to an endpoint that <strong>already exists</strong> on the PBX?
                Type the password it already has (8&ndash;128 characters, no spaces) and nothing on the PBX has to
                change. Leave it blank to have one generated (shown once, to copy into the PBX). On an existing
                extension, filling this in <strong>replaces</strong> the stored password. It is never displayed
                again.</div>
        </div>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" id="peEnabled" checked>
            <label class="form-check-label small" for="peEnabled">Enabled</label>
        </div>
        <div id="peCredentialWrap" class="d-none">
            <div class="alert alert-warning small py-2 px-2 mb-1">
                <i class="bi bi-key me-1"></i>
                <strong>SIP credentials — shown once, copy them now:</strong>
                <div class="row g-1 mt-1">
                    <div class="col-12">
                        <label class="form-label form-label-sm mb-0">Username</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="peCredUsername" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label form-label-sm mb-0">Password</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control form-control-sm font-monospace" id="peCredPassword" readonly>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="peBtnCopyPassword"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="peModalError" class="alert alert-danger small mt-2 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-warning btn-sm me-auto d-none" id="peBtnRotate">
            <i class="bi bi-arrow-repeat me-1"></i>Rotate Password</button>
        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="peBtnDelete">
            <i class="bi bi-trash me-1"></i>Delete Extension</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="peBtnSave"><i class="bi bi-save me-1"></i>Save Extension</button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/phone-extensions-admin.js?v=<?php echo asset_v('assets/js/phone-extensions-admin.js'); ?>"></script>
</body>
</html>
