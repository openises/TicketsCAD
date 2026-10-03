<?php
/**
 * NewUI v4.0 — Digital Voice Bridges admin page (Phase 155, GH#151 + GH#129)
 *
 * Standalone admin page (same precedent as stream-channels-admin.php /
 * matrix-admin.php) for digital-voice bridge channels: a DVMProject
 * `dvmbridge` (P25 / DMR / analog) or any other USRP-speaking bridge
 * (DVSwitch Analog_Bridge, AllStar chan_usrp), patched into the audio matrix
 * like any other channel. LISTEN-ONLY in this release. Backend:
 * api/voice-bridge-channels.php.
 *
 * Single-tier RBAC — action.manage_voice_bridges (tier 1: Org Admin and
 * above). No `|| is_admin()` fallback: see the API's docblock.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/voice-bridge-channels.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

if (!rbac_can('action.manage_voice_bridges')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Digital Voice Bridges — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Digital voice bridge configuration requires the "Manage Digital Voice Bridges"
           permission. Ask an administrator to grant your role <code>action.manage_voice_bridges</code>.</p>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">Back to Settings</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user     = e($_SESSION['user']);   // read by inc/navbar.php (display name)
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
    <title>Digital Voice Bridges — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-broadcast-pin text-primary me-2"></i>Digital Voice Bridges</h4>
        <a href="matrix-admin.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-diagram-3 me-1"></i>Patch Matrix</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="vbToast" class="alert d-none" role="status" aria-live="polite"></div>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        A <strong>digital voice bridge channel</strong> brings the audio of a P25 / DMR / analog
        talkgroup into the Communications Console and the
        <a href="matrix-admin.php">Patch Matrix</a>. TicketsCAD does not speak any radio network
        protocol and bundles no vocoder: a bridge program you install and run yourself
        (DVMProject's <code>dvmbridge</code>, DVSwitch's Analog_Bridge, AllStar's <code>chan_usrp</code>)
        joins the network and exchanges plain 8 kHz audio with TicketsCAD over UDP in the
        <em>USRP</em> format.
        <strong>This version is listen-only</strong>: it receives audio and never transmits, and nothing
        can be patched <em>into</em> these channels. Saving takes effect on the running audio-matrix
        service immediately.
        See <a href="documentation/?doc=DIGITAL-VOICE-USRP" target="_blank" rel="noopener">the setup guide</a>.
        This has been tested against a simulator of the USRP wire format only, not against a live
        DVMProject network.
    </div>

    <!-- ───────── DVMProject usage-policy statement ───────── -->
    <div class="card mb-3" id="vbPolicyCard">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-file-earmark-text me-2"></i>DVMProject usage-policy statement</span>
            <span id="vbPolicyBadge" class="badge text-bg-secondary">Loading&hellip;</span>
        </div>
        <div class="card-body small">
            <div id="vbPolicyText" class="mb-2"></div>
            <div id="vbPolicyWho" class="text-body-secondary mb-2"></div>
            <button type="button" class="btn btn-sm btn-primary" id="vbBtnAck">
                <i class="bi bi-check2-square me-1"></i>I have read this and acknowledge it</button>
            <button type="button" class="btn btn-sm btn-outline-danger d-none" id="vbBtnUnack">
                <i class="bi bi-x-octagon me-1"></i>Withdraw acknowledgment (disables DVMProject channels)</button>
            <div class="form-text">Only the <em>DVMProject</em> channel type needs this. The generic
                <em>USRP voice bridge</em> type does not.</div>
        </div>
    </div>

    <!-- ───────── channel list ───────── -->
    <div class="card mb-3" id="vbListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Channels</span>
            <button type="button" class="btn btn-sm btn-primary" id="vbBtnNew"><i class="bi bi-plus-lg me-1"></i>New Channel</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Type</th>
                            <th>Class</th>
                            <th>Mode / TG</th>
                            <th>Bridge &rarr; listen</th>
                            <th>Status</th>
                            <th style="width:150px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="vbListRows"><tr><td colspan="7" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ───────── FNE status card ───────── -->
    <div class="card mb-3" id="vbFneCard">
        <div class="card-header"><i class="bi bi-activity me-2"></i>Network link status (optional, DVMProject FNE REST)</div>
        <div class="card-body small">
            <p class="text-body-secondary mb-2">
                Leave this blank unless your FNE has its REST interface enabled. Without it a channel's
                link light reads <strong>unknown</strong> &mdash; it is never shown as connected just because the
                audio path is quiet. Only read-only questions are asked of the FNE (is this peer connected,
                does this talkgroup exist).</p>
            <div class="row g-2">
                <div class="col-md-5">
                    <label class="form-label form-label-sm mb-0" for="vbFneUrl">FNE REST address</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="vbFneUrl"
                           placeholder="http://127.0.0.1:9990" autocomplete="off">
                    <div class="form-text">An IPv4 address on this host or your private network (default port 9990, or 9443 for https).</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0" for="vbFnePassword">REST password</label>
                    <input type="password" class="form-control form-control-sm" id="vbFnePassword"
                           placeholder="" autocomplete="new-password">
                    <div class="form-text" id="vbFnePwHelp">Write-only. Leave blank to keep the stored one.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0" for="vbFneStale">Degraded after (s)</label>
                    <input type="number" class="form-control form-control-sm" id="vbFneStale" min="5" max="3600" value="30">
                    <div class="form-text">Last ping older than this.</div>
                </div>
            </div>
            <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" id="vbFneVerify" checked>
                <label class="form-check-label" for="vbFneVerify">Verify the FNE's TLS certificate (https only)</label>
            </div>
            <div id="vbFneWarn" class="alert alert-warning py-1 px-2 mt-2 mb-0 d-none"></div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-primary" id="vbFneSave"><i class="bi bi-save me-1"></i>Save</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="vbFneCheck"><i class="bi bi-arrow-repeat me-1"></i>Check now</button>
            </div>
            <div id="vbFneResult" class="mt-2" aria-live="polite"></div>
        </div>
    </div>
</main>

<!-- ══════════════════ Create/edit modal ══════════════════ -->
<div class="modal fade" id="vbModal" tabindex="-1" aria-hidden="true" aria-labelledby="vbModalTitle">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="vbModalTitle">New Digital Voice Bridge Channel</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="vbId" value="0">
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label form-label-sm mb-0" for="vbAdapter">Type</label>
                <select class="form-select form-select-sm" id="vbAdapter">
                    <option value="dvmproject">DVMProject (P25 / DMR via dvmbridge)</option>
                    <option value="usrp_bridge">USRP voice bridge (DVSwitch Analog_Bridge / AllStar chan_usrp)</option>
                </select>
                <div class="form-text" id="vbAdapterHelp"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label form-label-sm mb-0" for="vbSlug">Channel key</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text font-monospace" id="vbSlugPrefix">dvm:</span>
                    <input type="text" class="form-control form-control-sm font-monospace" id="vbSlug"
                           maxlength="40" placeholder="e.g. p25-tg1" autocomplete="off">
                </div>
                <div class="form-text">Lower-case letters, numbers, - and _. Cannot be changed after creation.</div>
            </div>
            <div class="col-md-8">
                <label class="form-label form-label-sm mb-0" for="vbLabel">Label</label>
                <input type="text" class="form-control form-control-sm" id="vbLabel" maxlength="120"
                       placeholder="e.g. County P25 - Event TG">
            </div>
            <div class="col-md-4">
                <label class="form-label form-label-sm mb-0" for="vbRegClass">Regulatory class</label>
                <select class="form-select form-select-sm" id="vbRegClass">
                    <?php $vbClassLabels = ['amateur' => 'Amateur (Part 97)', 'commercial' => 'Commercial / Part 90'];
                    foreach (vbc_allowed_classes() as $vbClass) { ?>
                    <option value="<?php echo e($vbClass); ?>"><?php echo e($vbClassLabels[$vbClass] ?? $vbClass); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-12">
                <div class="form-text">Choose the class of the <em>radio network the bridge joins</em>. A Part 90
                    network must be <strong>Commercial</strong>: patching it to an amateur channel then needs the audited
                    cross-class override. There is no &ldquo;internal&rdquo; choice, because an internal class would
                    exempt a radio network from that guard.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbMode">Mode</label>
                <select class="form-select form-select-sm" id="vbMode">
                    <?php foreach (vbc_modes() as $vbModeKey => $vbModeLabel) { ?>
                    <option value="<?php echo e($vbModeKey); ?>"><?php echo e($vbModeLabel); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbTg">Talkgroup</label>
                <input type="text" class="form-control form-control-sm" id="vbTg" maxlength="8" inputmode="numeric" placeholder="optional">
            </div>
            <div class="col-md-3 vb-dvm-only">
                <label class="form-label form-label-sm mb-0" for="vbPeer">FNE peer ID</label>
                <input type="text" class="form-control form-control-sm" id="vbPeer" maxlength="10" inputmode="numeric" placeholder="optional">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbHang">End-of-call silence (ms)</label>
                <input type="number" class="form-control form-control-sm" id="vbHang" min="100" max="5000" value="400">
            </div>
            <div class="col-12">
                <div class="form-text">Mode and talkgroup describe the channel and fill in the bridge-config text below; the real
                    mode and talkgroup are whatever the bridge program itself is configured for &mdash; TicketsCAD cannot change them.
                    The FNE peer ID lets the optional link check find this bridge. A call also ends after the end-of-call silence
                    with no audio, in case the bridge never sends a clean end.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbBridgeHost">Bridge address</label>
                <input type="text" class="form-control form-control-sm font-monospace" id="vbBridgeHost" value="127.0.0.1" autocomplete="off">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbBridgeTxPort">Bridge receive port</label>
                <input type="number" class="form-control form-control-sm" id="vbBridgeTxPort" min="1024" max="65535" value="32001">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbListenHost">Listen address</label>
                <input type="text" class="form-control form-control-sm font-monospace" id="vbListenHost" value="127.0.0.1" autocomplete="off">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-0" for="vbListenPort">Listen port</label>
                <input type="number" class="form-control form-control-sm" id="vbListenPort" min="1024" max="65535" value="34001">
            </div>
            <div class="col-12">
                <div class="form-text">The bridge sends audio to <em>Listen address : Listen port</em> (each channel needs its own port);
                    TicketsCAD accepts audio only from the <em>Bridge address</em>. &ldquo;Bridge receive port&rdquo; is where the bridge
                    listens for audio from TicketsCAD &mdash; unused in this listen-only version, recorded so the bridge is configured once.</div>
                <div id="vbNetWarn" class="alert alert-warning py-1 px-2 mt-1 mb-0 d-none small"></div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="vbEnabled" checked>
                    <label class="form-check-label small" for="vbEnabled">Enabled (the leg binds its UDP port while enabled)</label>
                </div>
            </div>
        </div>
        <div id="vbModalError" class="alert alert-danger small mt-2 d-none" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger btn-sm d-none me-auto" id="vbBtnDelete">
            <i class="bi bi-trash me-1"></i>Delete Channel</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="vbBtnSave"><i class="bi bi-save me-1"></i>Save Channel</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════ Bridge-config snippet modal ══════════════════ -->
<div class="modal fade" id="vbSnipModal" tabindex="-1" aria-hidden="true" aria-labelledby="vbSnipTitle">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="vbSnipTitle">Bridge configuration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary mb-2">Use these values in the <strong>bridge program's own</strong> configuration so its ports and
            audio format mirror this channel. The FNE password is a placeholder: TicketsCAD never has it.</p>
        <pre class="border rounded p-2 small mb-0" id="vbSnipText" style="white-space:pre-wrap"></pre>
      </div>
      <div class="modal-footer">
        <span id="vbSnipCopied" class="small text-success me-auto d-none">Copied</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="vbSnipCopy"><i class="bi bi-clipboard me-1"></i>Copy</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/voice-bridges-admin.js?v=<?php echo asset_v('assets/js/voice-bridges-admin.js'); ?>"></script>
</body>
</html>
