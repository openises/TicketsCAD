<?php
/**
 * NewUI v4.0 — Roles & Permissions admin page (Phase 80b)
 *
 * Dedicated front door for RBAC editing. Replaces the previous tabbed
 * embed that wrapped the settings.php logic — this page is a focused
 * 3-column editor that gives admins everything they need to see, edit,
 * and manage roles without navigating the Settings panel labyrinth.
 *
 * Layout:
 *   Left   — Role list with name, description, user-count + "New Role"
 *   Middle — Selected role's name/description editor + assigned users
 *   Right  — Permission matrix grouped by category (screen/widget/
 *            action/field), checkboxes auto-save with debounce
 *
 * Backend reuse (no new endpoints):
 *   GET  /api/rbac.php                       — list roles
 *   GET  /api/rbac.php?role_id=N             — role + permission matrix
 *   GET  /api/rbac.php?permissions=1         — full permission registry
 *   GET  /api/config-admin.php?section=users — user list (for assignments)
 *   POST /api/rbac.php action=save_role       — name/description edit
 *   POST /api/rbac.php action=delete_role     — soft-delete role
 *   POST /api/rbac.php action=set_permissions — replace permission set
 *   POST /api/rbac.php action=remove_role     — strip user grant
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

// Phase 80b: admin-only access. Non-admins bounce to /index.php so the
// page never reveals it exists. is_admin() short-circuits when the
// session holds a super-admin grant; otherwise checks action.manage_config.
// We also accept the specific action.manage_roles permission so a custom
// "Permissions Manager" role can reach this page without full admin.
if (!is_admin() && !rbac_can('action.manage_roles')) {
    header('Location: index.php');
    exit;
}

$user        = e($_SESSION['user']);
$level       = current_role_name();
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
// Roles & Permissions lives under the Personnel dropdown in the navbar
// (see inc/navbar.php), so highlight that menu item when this page is open.
$active_page = 'personnel';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.roles_permissions', 'Roles & Permissions')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-shield-lock me-2"></i><?php echo e(t('page.roles_permissions', 'Roles & Permissions')); ?>
        </h4>
        <span class="text-body-secondary small ms-3 d-none d-md-inline">
            <?php echo e(t('roles.subtitle', 'Manage RBAC roles, their permissions, and user assignments.')); ?>
        </span>
        <div class="ms-auto d-flex gap-2">
            <a href="roles-matrix.php" class="btn btn-sm btn-outline-primary" title="Open the permissions matrix">
                <i class="bi bi-grid-3x3-gap me-1"></i>Matrix
            </a>
            <a href="settings.php" class="btn btn-sm btn-outline-secondary" title="Back to Settings">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('btn.back_to_settings', 'Settings')); ?>
            </a>
        </div>
    </div>

    <!-- Inline alert region (alerts injected by JS) -->
    <div id="rolesAlertSlot" aria-live="polite"></div>

    <div class="row g-3">
        <!-- ── Column A: Role list ───────────────────────────────────── -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center py-2">
                    <span class="fw-semibold">
                        <i class="bi bi-people me-1"></i><?php echo e(t('roles.col.roles', 'Roles')); ?>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="btnNewRole">
                        <i class="bi bi-plus-lg me-1"></i><?php echo e(t('roles.btn.new_role', 'New Role')); ?>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="rolesList" class="list-group list-group-flush">
                        <div class="text-center py-4 text-body-secondary">
                            <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                            <div class="small mt-2"><?php echo e(t('roles.loading', 'Loading roles...')); ?></div>
                        </div>
                    </div>
                </div>
                <?php /* Phase 11 (preserved): legacy-account migration banner.
                       The dedicated Roles & Permissions front door is where
                       admins land to fix RBAC plumbing, so the migration
                       prompt stays available here. JS shows the button only
                       when api/rbac.php?action=migration_status reports
                       accounts still need a role. */ ?>
                <div class="card-footer p-2 border-top" id="migrateLegacyWrap" style="display:none;">
                    <button class="btn btn-sm btn-outline-warning w-100" id="btnMigrateLevels">
                        <i class="bi bi-arrow-repeat me-1"></i><?php echo e(t('migrate_legacy.button', 'Migrate Legacy Accounts to Roles')); ?>
                    </button>
                    <div class="text-body-secondary small mt-1" id="migrateLegacyHint"></div>
                </div>
                <div class="card-footer p-2 border-top" id="migrateLegacyDone" style="display:none;">
                    <div class="text-success small">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        <?php echo e(t('migrate_legacy.done', 'All user accounts are on the Roles & Permissions system.')); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Column B: Selected role detail ────────────────────────── -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="fw-semibold">
                        <i class="bi bi-card-text me-1"></i><?php echo e(t('roles.col.detail', 'Role Detail')); ?>
                    </span>
                </div>
                <div class="card-body" id="roleDetailPanel">
                    <div class="text-center text-body-secondary py-5">
                        <i class="bi bi-arrow-left-circle display-6 d-block mb-2 opacity-25"></i>
                        <span class="small"><?php echo e(t('roles.detail.empty', 'Select a role on the left to edit.')); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Column C: Permission matrix ───────────────────────────── -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center py-2">
                    <span class="fw-semibold">
                        <i class="bi bi-grid-3x3-gap me-1"></i><?php echo e(t('roles.col.permissions', 'Permissions')); ?>
                    </span>
                    <span class="text-body-secondary small ms-auto" id="permsSaveStatus" aria-live="polite"></span>
                </div>
                <div class="card-body p-2" id="permissionMatrix" style="max-height: 70vh; overflow-y: auto;">
                    <div class="text-center text-body-secondary py-5">
                        <i class="bi bi-shield-check display-6 d-block mb-2 opacity-25"></i>
                        <span class="small"><?php echo e(t('roles.perms.empty', 'Select a role to see its permissions.')); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Hidden form template for new-role modal -->
<div class="modal fade" id="newRoleModal" tabindex="-1" aria-labelledby="newRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="newRoleForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="newRoleModalLabel">
                        <i class="bi bi-shield-plus me-1"></i><?php echo e(t('roles.modal.new_role', 'Create New Role')); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="newRoleName" class="form-label form-label-sm">
                            <?php echo e(t('roles.field.name', 'Name')); ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="newRoleName" maxlength="64" required>
                    </div>
                    <div class="mb-3">
                        <label for="newRoleDesc" class="form-label form-label-sm">
                            <?php echo e(t('roles.field.description', 'Description')); ?>
                        </label>
                        <textarea class="form-control form-control-sm" id="newRoleDesc" rows="3" maxlength="255"
                                  placeholder="<?php echo e(t('roles.field.description_hint', 'Short description shown on the role card.')); ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <?php echo e(t('btn.cancel', 'Cancel')); ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check-lg me-1"></i><?php echo e(t('btn.create', 'Create')); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/roles.js?v=<?php echo asset_v('assets/js/roles.js'); ?>"></script>
</body>
</html>
