<?php
/**
 * NewUI v4.0 — Status Workflow Designer (Phase 105, a beta tester GH #16)
 *
 * Jira-style visual workflow builder for unit statuses. Statuses render
 * as draggable nodes on an SVG canvas; arrows between them define which
 * transitions are allowed; each arrow can carry conditions (v1:
 * requires an active incident assignment / requires NO assignment).
 *
 * Enforcement mode ('off' | 'warn' | 'enforce') lives in the settings
 * row `status_workflow_mode`; a missing row means 'off', so a fresh
 * install behaves exactly as before until an admin opts in here.
 *
 * Backend: api/status-workflow.php (GET snapshot / POST action=save).
 * Enforcement gate: inc/status-workflow.php via
 * responder_set_status_internal() — covers the status modal, mobile,
 * the /s command bar, and the external API.
 *
 * Admin-only (action.manage_status_workflow — seeded to Super Admin +
 * Org Admin by sql/run_status_workflow.php).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

// GH #13 — pick the session profile matching the client's cookie
// (TCADMOBILE vs PHPSESSID) BEFORE session_start().
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// RBAC gate — friendly 403 rather than a bare error so an operator who
// stumbles in via a shared link knows what to ask their admin for.
if (!rbac_can('action.manage_status_workflow')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e(t('workflow.title', 'Status Workflow')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i><?php echo e(t('workflow.403.title', 'Permission required')); ?></h5>
        <p class="mb-2"><?php echo e(t('workflow.403.body', 'The Status Workflow designer requires the "Manage Status Workflow" permission. Ask an administrator to grant your role action.manage_status_workflow.')); ?></p>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?php echo e(t('btn.back_to_dashboard', 'Back to Dashboard')); ?></a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

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
    <title><?php echo e(t('workflow.title', 'Status Workflow')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">

    <style>
    /* Designer canvas + panels — Bootstrap CSS variables so light and
       dark themes both render correctly. */
    #wfCanvasWrap {
        position: relative;
        border: 1px solid var(--bs-border-color);
        border-radius: .5rem;
        background: var(--bs-body-bg);
        overflow: hidden;
    }
    #wfCanvas {
        display: block;
        width: 100%;
        height: 70vh;
        min-height: 420px;
        user-select: none;
        -webkit-user-select: none;
        cursor: default;
    }
    #wfCanvas g.wf-node { cursor: grab; }
    #wfCanvas g.wf-node.wf-dragging { cursor: grabbing; }
    #wfCanvas .wf-node rect.wf-node-rect {
        stroke: var(--bs-border-color);
        stroke-width: 1.5;
    }
    #wfCanvas .wf-node.wf-selected rect.wf-node-rect {
        stroke: var(--bs-primary);
        stroke-width: 2.5;
    }
    #wfCanvas .wf-node.wf-hidden-status { opacity: .55; }
    #wfCanvas .wf-node.wf-hidden-status rect.wf-node-rect { stroke-dasharray: 5 3; }
    #wfCanvas .wf-port {
        fill: var(--bs-secondary-bg);
        stroke: var(--bs-secondary-color);
        stroke-width: 1;
        cursor: crosshair;
    }
    #wfCanvas .wf-port-plus {
        fill: none;
        stroke: var(--bs-secondary-color);
        stroke-width: 1.5;
        pointer-events: none;
    }
    #wfCanvas .wf-edge-hit {
        stroke: transparent;
        stroke-width: 14;
        fill: none;
        cursor: pointer;
    }
    #wfCanvas .wf-edge {
        stroke: var(--bs-secondary-color);
        stroke-width: 2;
        fill: none;
        pointer-events: none;
    }
    #wfCanvas .wf-edge.wf-edge-selected { stroke: var(--bs-primary); stroke-width: 3; }
    #wfCanvas .wf-edge.wf-edge-conditional { stroke-dasharray: 7 4; }
    #wfCanvas .wf-rubber {
        stroke: var(--bs-primary);
        stroke-width: 2;
        stroke-dasharray: 5 4;
        fill: none;
        pointer-events: none;
    }
    #wfCanvas .wf-any-badge-bg { fill: var(--bs-info-bg-subtle); stroke: var(--bs-info-border-subtle); }
    #wfCanvas .wf-any-badge-text { fill: var(--bs-info-text-emphasis); font-size: 10px; font-weight: 600; }
    .wf-floating-panel {
        position: absolute;
        z-index: 30;
        min-width: 260px;
        max-width: 320px;
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: .5rem;
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.25);
        padding: .75rem;
    }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">
            <i class="bi bi-diagram-3 me-2"></i><?php echo e(t('workflow.title', 'Status Workflow')); ?>
        </h4>
        <span class="text-body-secondary small ms-md-3 d-none d-md-inline">
            <?php echo e(t('workflow.subtitle', 'Draw the allowed unit-status transitions. Drag statuses to arrange; drag from a node\'s + port to another node to allow that transition.')); ?>
        </span>
        <div class="ms-auto d-flex gap-2">
            <a href="settings.php" class="btn btn-sm btn-outline-secondary" title="Back to Settings">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('btn.back_to_settings', 'Settings')); ?>
            </a>
        </div>
    </div>

    <!-- Inline alert region (alerts injected by JS) -->
    <div id="wfAlertSlot" aria-live="polite"></div>

    <!-- Persistent semantics warning -->
    <div class="alert alert-warning py-2 small" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong><?php echo e(t('workflow.semantics.title', 'How enforcement works:')); ?></strong>
        <?php echo e(t('workflow.semantics.body', 'When enforcement is on, a status change is only allowed if a matching arrow exists. Statuses with no incoming arrows become unreachable. A half-drawn workflow can lock units into their current status — keep the mode Off (or Warn only) until every transition you need is drawn.')); ?>
    </div>

    <!-- Toolbar -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-2">
                    <label for="wfMode" class="form-label mb-0 small fw-semibold"><?php echo e(t('workflow.mode', 'Mode')); ?></label>
                    <select id="wfMode" class="form-select form-select-sm" style="width: auto;">
                        <option value="off"><?php echo e(t('workflow.mode.off', 'Off')); ?></option>
                        <option value="warn"><?php echo e(t('workflow.mode.warn', 'Warn only')); ?></option>
                        <option value="enforce"><?php echo e(t('workflow.mode.enforce', 'Enforce')); ?></option>
                    </select>
                </div>
                <span id="wfModeHelp" class="small text-body-secondary flex-grow-1"></span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="wfBtnReset">
                        <i class="bi bi-arrow-counterclockwise me-1"></i><?php echo e(t('workflow.btn.reset', 'Reset from server')); ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" id="wfBtnSave">
                        <i class="bi bi-save me-1"></i><?php echo e(t('btn.save', 'Save')); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Canvas -->
        <div class="col-lg-9">
            <div id="wfCanvasWrap">
                <svg id="wfCanvas" xmlns="http://www.w3.org/2000/svg"></svg>
                <!-- Edge editor floating panel (positioned by JS) -->
                <div id="wfEdgePanel" class="wf-floating-panel d-none">
                    <div class="d-flex align-items-center mb-2">
                        <span class="fw-semibold small" id="wfEdgePanelTitle"></span>
                        <button type="button" class="btn-close btn-sm ms-auto" id="wfEdgePanelClose" aria-label="Close"></button>
                    </div>
                    <div class="form-check form-check-sm mb-1">
                        <input class="form-check-input" type="checkbox" id="wfCondAssign">
                        <label class="form-check-label small" for="wfCondAssign">
                            <?php echo e(t('workflow.cond.assign', 'Requires active assignment')); ?>
                        </label>
                    </div>
                    <div class="form-check form-check-sm mb-2">
                        <input class="form-check-input" type="checkbox" id="wfCondNoAssign">
                        <label class="form-check-label small" for="wfCondNoAssign">
                            <?php echo e(t('workflow.cond.noassign', 'Requires NO active assignment')); ?>
                        </label>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger w-100" id="wfBtnDeleteEdge">
                        <i class="bi bi-trash me-1"></i><?php echo e(t('workflow.btn.delete_transition', 'Delete Transition')); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Side panel: selected node -->
        <div class="col-lg-3">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="fw-semibold small"><i class="bi bi-square me-1"></i><?php echo e(t('workflow.node_panel', 'Selected status')); ?></span>
                </div>
                <div class="card-body">
                    <div id="wfNodePanelEmpty" class="text-body-secondary small">
                        <?php echo e(t('workflow.node_panel.empty', 'Click a status node to edit its settings.')); ?>
                    </div>
                    <div id="wfNodePanel" class="d-none">
                        <div class="fw-semibold mb-2" id="wfNodePanelName"></div>
                        <div class="form-check form-check-sm mb-2">
                            <input class="form-check-input" type="checkbox" id="wfNodeAnySource">
                            <label class="form-check-label small" for="wfNodeAnySource">
                                <?php echo e(t('workflow.node.any_source', 'Reachable from any status')); ?>
                            </label>
                        </div>
                        <div class="small text-body-secondary">
                            <?php echo e(t('workflow.node.any_source_hint', 'When checked, units may enter this status from any current status (a synthetic ANY arrow) — individual arrows into it are then optional.')); ?>
                        </div>
                        <hr>
                        <div class="small text-body-secondary" id="wfNodeStats"></div>
                    </div>
                    <hr>
                    <div class="small text-body-secondary">
                        <div class="mb-1"><i class="bi bi-mouse me-1"></i><?php echo e(t('workflow.help.drag', 'Drag a node to arrange the canvas.')); ?></div>
                        <div class="mb-1"><i class="bi bi-plus-circle me-1"></i><?php echo e(t('workflow.help.connect', 'Drag from the + port to another node to allow a transition.')); ?></div>
                        <div><i class="bi bi-arrow-right me-1"></i><?php echo e(t('workflow.help.edge', 'Click an arrow to edit its conditions or delete it.')); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/workflow-designer.js?v=<?php echo asset_v('assets/js/workflow-designer.js'); ?>"></script>
</body>
</html>
