<?php
/**
 * NewUI v4.0 — Dispatcher Message Tray (Phase 111 Slice B).
 *
 * One screen where a dispatcher SEES all inbound field traffic (Meshtastic /
 * Zello / DMR / local chat, read from the broker `messages` store), LOGS it to
 * incidents (active event / assign / copy / start sub-incident), and REPLIES to
 * it or composes new messages on any mode — the net-control workflow, keyboard-
 * first. Reuses the broker send paths + Slice A attach glue; this page is the
 * front-and-center UI over them.
 *
 * Backend: api/message-tray.php.
 * Gate: screen.message_tray (Super Admin + Org Admin + Dispatcher by default).
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

if (!rbac_can('screen.message_tray')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Message Tray — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">The Message Tray requires the "Message Tray" permission. Ask an administrator to grant your role <code>screen.message_tray</code>.</p>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Back to dashboard</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$canAssign   = rbac_can('action.assign_message');
$user        = e($_SESSION['user']);
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$active_page = 'message-tray';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Message Tray — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <style>
    #mtList { max-height: calc(100vh - 230px); overflow-y: auto; }
    .mt-card { border-left: 3px solid var(--bs-secondary); }
    .mt-card.mt-unresolved { border-left-color: var(--bs-warning); }
    .mt-card.mt-logged { border-left-color: var(--bs-success); }
    .mt-chan { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
    .mt-compose textarea { resize: vertical; }
    </style>
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1200px;">
    <div class="d-flex align-items-center mb-2 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-inbox-fill text-primary me-2"></i>Message Tray</h4>
        <span class="badge bg-secondary" id="mtActiveEvent">active event: —</span>
        <div class="btn-group btn-group-sm ms-auto" role="group" id="mtChannelFilter">
            <button class="btn btn-outline-secondary active" data-chan="">All</button>
            <button class="btn btn-outline-secondary" data-chan="meshtastic">Mesh</button>
            <button class="btn btn-outline-secondary" data-chan="zello">Zello</button>
            <button class="btn btn-outline-secondary" data-chan="dmr">DMR</button>
            <button class="btn btn-outline-secondary" data-chan="local_chat">Chat</button>
        </div>
        <button class="btn btn-sm btn-outline-primary" id="mtRefresh"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div id="mtToast" class="alert d-none py-2" role="status"></div>

    <div class="row g-3">
        <!-- Inbound list -->
        <div class="col-lg-8">
            <div id="mtList"><div class="text-body-secondary p-3">Loading…</div></div>
        </div>
        <!-- Compose / reply (keyboard-first) -->
        <div class="col-lg-4">
            <div class="card mt-compose">
                <div class="card-header py-2"><i class="bi bi-send me-2"></i><span id="mtComposeTitle">New message</span></div>
                <div class="card-body">
                    <input type="hidden" id="mtReplyTo">
                    <label class="form-label form-label-sm mb-1">Channel</label>
                    <select class="form-select form-select-sm mb-2" id="mtComposeChannel">
                        <option value="local_chat">Local chat</option>
                        <option value="meshtastic">Meshtastic</option>
                        <option value="zello">Zello</option>
                        <option value="dmr">DMR</option>
                    </select>
                    <label class="form-label form-label-sm mb-1">Message <span class="text-body-secondary">(Enter to send, Shift+Enter for newline)</span></label>
                    <textarea class="form-control form-control-sm mb-2" id="mtComposeBody" rows="3" placeholder="Type a message…"></textarea>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary" id="mtSend"><i class="bi bi-send me-1"></i>Send</button>
                        <button class="btn btn-sm btn-outline-secondary d-none" id="mtCancelReply">Cancel reply</button>
                    </div>
                    <div class="form-text">Reuses the broker send paths (mesh outbox / Zello proxy / DMR bridge / chat). Delivery depends on that channel being configured + online.</div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Assign / copy / sub-incident picker (Bootstrap modal) -->
<div class="modal fade" id="mtAssignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title" id="mtAssignTitle">Assign to incident</h6>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" id="mtAssignMsgId"><input type="hidden" id="mtAssignMode">
      <label class="form-label form-label-sm">Incident</label>
      <select class="form-select form-select-sm" id="mtAssignIncident"></select>
      <div class="form-text" id="mtAssignHint"></div>
    </div>
    <div class="modal-footer py-2">
      <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-sm btn-primary" id="mtAssignConfirm">Confirm</button>
    </div>
  </div></div>
</div>

<!-- Set-sender picker -->
<div class="modal fade" id="mtSenderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title">Attribute sender</h6>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" id="mtSenderMsgId">
      <label class="form-label form-label-sm">Member</label>
      <select class="form-select form-select-sm mb-2" id="mtSenderMember"></select>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="mtSenderRemember" checked>
        <label class="form-check-label" for="mtSenderRemember">Remember this handle → member for next time</label>
      </div>
    </div>
    <div class="modal-footer py-2">
      <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-sm btn-primary" id="mtSenderConfirm">Save</button>
    </div>
  </div></div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script>window.MT_CAN_ASSIGN = <?php echo $canAssign ? 'true' : 'false'; ?>;</script>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/message-tray.js?v=<?php echo asset_v('assets/js/message-tray.js'); ?>"></script>
</body>
</html>
