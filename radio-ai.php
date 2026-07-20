<?php
/**
 * NewUI v4.0 — Radio AI operator console (Phase 85f-5b)
 *
 * Operator-facing page for the AI-on-radio feature. Lists pending
 * approval drafts, lets the operator approve / edit / reject each
 * one, and supports dry-run rehearsal so the workflow can be
 * exercised without going on-air.
 *
 * Lives on its own page (not embedded in the radio widget) so the
 * primary CAD experience stays focused on dispatch. The Radio AI
 * feature is experimental — net-control assistant, message relay,
 * stand-up Skywarn net controller — and will earn its way deeper
 * into the UI as it matures. Until then, deliberately separate.
 *
 * Same auth + RBAC gates as the API endpoints it polls
 * (api/radio-ai-pending.php, api/radio-ai-decide.php).
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

// action.dmr_transmit is what authorizes a TX; approving a draft IS
// causing a TX, so it's the right gate.
$canView = is_admin() || rbac_can('action.dmr_transmit');
if (!$canView) {
    http_response_code(403);
    echo '<h1>403 — Missing permission: action.dmr_transmit</h1>';
    exit;
}

// Suppress display_errors so a stray PHP notice can't leak into the
// rendered HTML and corrupt the page layout (the way an undefined
// $level warning did on first deploy).
ini_set('display_errors', '0');

$user        = e($_SESSION['user']);
$level       = current_role_name();        // navbar.php renders this
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$userPerms   = rbac_user_permissions();    // navbar gates some links on these
$active_page = 'radio-ai';
?><!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo $csrf; ?>">
<title>Radio AI — Tickets CAD</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
<!-- dashboard.css owns the .nav-main / .nav-menu-btn styles the
     shared navbar template relies on; without it the menu lays out
     as bare inline links instead of the proper toolbar. -->
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo file_exists(__DIR__ . '/assets/css/dashboard.css') ? filemtime(__DIR__ . '/assets/css/dashboard.css') : NEWUI_VERSION; ?>">
<link rel="stylesheet" href="assets/css/radio-widget.css?v=<?php echo file_exists(__DIR__ . '/assets/css/radio-widget.css') ? filemtime(__DIR__ . '/assets/css/radio-widget.css') : NEWUI_VERSION; ?>">
<style>
.radio-ai-page-toolbar {
    position: sticky; top: 0; z-index: 10;
    background: var(--bs-body-bg);
    border-bottom: 1px solid var(--bs-border-color);
    padding: 0.75rem 1rem; margin-bottom: 1rem;
}
#radioAiPanelList { max-width: 900px; }
#radioAiPanelList .radio-ai-card { margin-bottom: 0.75rem; padding: 0.75rem 1rem; }
#radioAiPanelList .radio-ai-actions { margin-top: 0.5rem; }
#radioAiEmpty {
    color: var(--bs-secondary-color);
    text-align: center;
    padding: 4rem 1rem;
}
</style>
</head>
<body>
<?php require_once __DIR__ . '/inc/navbar.php'; ?>

<main class="container-fluid py-3">
    <div class="radio-ai-page-toolbar d-flex align-items-center flex-wrap gap-3">
        <h5 class="mb-0">
            <i class="bi bi-robot me-2"></i>
            <span id="radioAiPanelTitle">Pending approvals</span>
        </h5>
        <label class="form-check ms-auto mb-0" title="Skip on-air TX — pipeline runs but produces no audio">
            <input class="form-check-input me-1" type="checkbox" id="radioAiDryRun">
            <span class="form-check-label">Dry run</span>
        </label>
        <label class="form-check mb-0"
               title="When ON, pending_approval drafts are sent automatically. Filtered drafts always require human review. Auto-approve turns itself OFF after 2 hours regardless of activity, and resets if you close the page.">
            <input class="form-check-input me-1" type="checkbox" id="radioAiAutoApprove">
            <span class="form-check-label">Auto-approve</span>
        </label>
        <span id="radioAiAutoApproveStatus" class="badge bg-warning text-dark d-none"
              title="Auto-approve is ON. It will switch OFF at the listed time.">
            <i class="bi bi-lightning-charge-fill me-1"></i>
            <span id="radioAiAutoApproveExpiry">--:--</span>
        </span>
    </div>

    <div class="px-3">
        <div id="radioAiPanelList"></div>

        <div id="radioAiEmpty" class="d-none">
            <i class="bi bi-inbox" style="font-size:3rem"></i>
            <p class="mt-3 mb-1">No drafts waiting for review.</p>
            <p class="small">
                When the listener daemon picks up a wake-word transcript on
                an enabled channel, Claude's draft response will appear here
                for an operator to approve, edit, or reject before it goes
                on-air.
            </p>
        </div>
    </div>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<!-- Same toolbar wiring + day/night theme manager every other page
     uses; without them the navbar's buttons (theme toggle, language
     switcher, notification tray) are inert on this page. -->
<script src="assets/js/toolbar.js?v=<?php echo file_exists(__DIR__ . '/assets/js/toolbar.js') ? filemtime(__DIR__ . '/assets/js/toolbar.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/theme-manager.js?v=<?php echo file_exists(__DIR__ . '/assets/js/theme-manager.js') ? filemtime(__DIR__ . '/assets/js/theme-manager.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/radio-ai-approval.js?v=<?php echo filemtime(__DIR__ . '/assets/js/radio-ai-approval.js'); ?>"></script>
</body>
</html>
