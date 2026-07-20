<?php
/**
 * NewUI v4.0 — Permissions Matrix (Phase 99u-2, 2026-06-29).
 *
 * Dense permission × role grid for admins to review which non-system
 * role grants which permission, flip individual cells with one click,
 * and dismiss permissions that are intentionally admin-only on this
 * install (which clears them from the "un-reviewed" banner count).
 *
 * Sibling to /roles.php — that page is the 3-column role editor, this
 * page is the wide read-and-bulk-edit view that closes the audit gap
 * a beta tester/Eric flagged on 2026-06-29.
 *
 * Backend reuse (no new endpoints beyond Phase 99u-1/2):
 *   GET  /api/rbac.php?action=permission_audit  — full payload incl. dismissed flag
 *   GET  /api/rbac.php                          — role list (for matrix columns)
 *   POST /api/rbac.php action=set_role_permission { role_id, permission_id, grant }
 *   POST /api/rbac.php action=dismiss_permission   { permission_id }
 *   POST /api/rbac.php action=undismiss_permission { permission_id }
 *
 * Admin-only (action.manage_roles); non-admins bounce to /index.php.
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

if (!is_admin() && !rbac_can('action.manage_roles')) {
    header('Location: index.php');
    exit;
}

$user        = e($_SESSION['user']);
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$active_page = 'personnel';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Permissions Matrix — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">

    <style>
        /* Dense matrix layout: sticky first column (permission), sticky
           header row (roles). Cells are tight; checkbox-style buttons. */
        .perm-matrix-wrap { overflow: auto; max-height: calc(100vh - 240px); }
        .perm-matrix { font-size: 0.85rem; border-collapse: separate; border-spacing: 0; }
        .perm-matrix thead th {
            position: sticky; top: 0; z-index: 3;
            background: var(--bs-body-bg);
            border-bottom: 2px solid var(--bs-border-color);
            white-space: nowrap; padding: 0.4rem 0.5rem;
            vertical-align: bottom;
        }
        .perm-matrix thead th.role-col {
            writing-mode: vertical-rl; transform: rotate(180deg);
            min-width: 32px; max-width: 32px; height: 140px;
            text-align: left; font-weight: 500;
        }
        .perm-matrix th.perm-col, .perm-matrix td.perm-col {
            position: sticky; left: 0; z-index: 2;
            background: var(--bs-body-bg);
            border-right: 2px solid var(--bs-border-color);
            min-width: 320px; max-width: 320px;
            padding: 0.3rem 0.5rem;
        }
        .perm-matrix thead th.perm-col { z-index: 4; }
        .perm-matrix tbody tr:nth-child(even) td { background: var(--bs-tertiary-bg); }
        .perm-matrix tbody tr:nth-child(even) td.perm-col { background: var(--bs-tertiary-bg); }
        .perm-matrix tbody tr.unreviewed td.perm-col { border-left: 3px solid var(--bs-warning); }
        .perm-matrix tbody tr.dismissed td.perm-col { border-left: 3px solid var(--bs-info); opacity: 0.65; }
        .perm-matrix td.cell {
            text-align: center; padding: 0.15rem;
            min-width: 32px; max-width: 32px;
            cursor: pointer; user-select: none;
        }
        .perm-matrix td.cell:hover { background: var(--bs-primary-bg-subtle) !important; }
        .perm-matrix td.cell .bi-check-lg { color: var(--bs-success); font-size: 1.1rem; }
        .perm-matrix td.cell .bi-dash { color: var(--bs-secondary); opacity: 0.25; font-size: 0.9rem; }
        .perm-matrix td.cell.pending { opacity: 0.4; }
        .perm-matrix .perm-actions { font-size: 0.75rem; margin-top: 0.15rem; }
        .perm-matrix .perm-actions a { text-decoration: none; }
        .perm-matrix .badge-cat { font-size: 0.65rem; padding: 0.15em 0.4em; }
        .perm-matrix code { font-size: 0.78rem; }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3 flex-wrap">
        <h4 class="mb-0">
            <i class="bi bi-grid-3x3-gap me-2"></i>Permissions Matrix
        </h4>
        <span class="text-body-secondary small ms-3 d-none d-md-inline">
            Review every permission against every non-system role. Click any cell to grant/revoke. Dismiss permissions that are intentionally admin-only.
        </span>
        <div class="ms-auto d-flex gap-2">
            <a href="roles.php" class="btn btn-sm btn-outline-secondary" title="Back to Roles &amp; Permissions">
                <i class="bi bi-people me-1"></i>Roles
            </a>
            <a href="settings.php" class="btn btn-sm btn-outline-secondary" title="Back to Settings">
                <i class="bi bi-arrow-left me-1"></i>Settings
            </a>
        </div>
    </div>

    <div id="matrixAlertSlot" aria-live="polite"></div>

    <!-- Filter / summary bar -->
    <div class="card mb-3">
        <div class="card-body py-2 d-flex align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label small mb-0 text-body-secondary">Show:</label>
                <select class="form-select form-select-sm" id="filterScope" style="width: auto;">
                    <option value="all">All permissions</option>
                    <option value="unreviewed" selected>Un-reviewed only</option>
                    <option value="dismissed">Dismissed only</option>
                    <option value="granted">Granted (≥1 non-system role)</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="form-label small mb-0 text-body-secondary">Category:</label>
                <select class="form-select form-select-sm" id="filterCategory" style="width: auto;">
                    <option value="">All categories</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <input type="search" class="form-control form-control-sm" id="filterText"
                       placeholder="Filter code/name..." style="width: 200px;">
            </div>
            <div class="ms-auto text-body-secondary small" id="matrixSummary">
                Loading…
            </div>
        </div>
    </div>

    <!-- Matrix table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="perm-matrix-wrap">
                <table class="table table-sm mb-0 perm-matrix" id="permMatrix">
                    <thead>
                        <tr id="permMatrixHead">
                            <th class="perm-col">Permission</th>
                            <!-- role columns injected by JS -->
                        </tr>
                    </thead>
                    <tbody id="permMatrixBody">
                        <tr><td class="perm-col text-center text-body-secondary" colspan="50">
                            <div class="spinner-border spinner-border-sm me-2"></div>
                            Loading matrix…
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/roles-matrix.js?v=<?php echo asset_v('assets/js/roles-matrix.js'); ?>"></script>

</body>
</html>
