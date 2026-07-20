<?php
/**
 * NewUI v4.0 - External Links
 *
 * Configurable external links page. Links displayed in a card grid
 * grouped by category. Administrators can add/edit/delete links inline.
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


$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$is_admin = ((int) $_SESSION['level'] <= 1);
$active_page = 'links';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.links', 'Links')); ?> &mdash; <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/links.css?v=<?php echo asset_v('assets/css/links.css'); ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-link-45deg text-primary me-2"></i>Links
            <span class="badge bg-secondary ms-2" id="linkCount">0</span>
        </h5>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
<?php if ($is_admin): ?>
            <button type="button" class="btn btn-sm btn-success" id="btnAddLink">
                <i class="bi bi-plus-lg me-1"></i>Add Link
            </button>
<?php endif; ?>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Category filter bar -->
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <div class="input-group input-group-sm" style="max-width: 280px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control form-control-sm" id="linkSearch"
                   placeholder="Search links..." autocomplete="off">
        </div>
        <div class="btn-group btn-group-sm" role="group" id="categoryFilters">
            <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
            <!-- Category buttons populated by JS -->
        </div>
    </div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading links...</div>
    </div>

    <!-- Link cards container -->
    <div class="d-none" id="linksContainer">
        <!-- Sections rendered by JS -->
    </div>

    <!-- Empty state -->
    <div class="text-center py-5 d-none" id="emptyState">
        <i class="bi bi-link-45deg" style="font-size:3rem;"></i>
        <div class="mt-2 text-body-secondary">No links configured yet.</div>
<?php if ($is_admin): ?>
        <button type="button" class="btn btn-sm btn-success mt-3" id="btnAddLinkEmpty">
            <i class="bi bi-plus-lg me-1"></i>Add the first link
        </button>
<?php endif; ?>
    </div>
</div>

<!-- ═══════════ Edit Link Modal ═══════════ -->
<?php if ($is_admin): ?>
<div class="modal fade" id="linkModal" tabindex="-1" aria-labelledby="linkModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="linkModalLabel">Add Link</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editLinkId" value="0">
                <div class="mb-3">
                    <label for="editLinkTitle" class="form-label form-label-sm">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="editLinkTitle" maxlength="128" required>
                </div>
                <div class="mb-3">
                    <label for="editLinkUrl" class="form-label form-label-sm">URL <span class="text-danger">*</span></label>
                    <input type="url" class="form-control form-control-sm" id="editLinkUrl" maxlength="512" placeholder="https://" required>
                </div>
                <div class="mb-3">
                    <label for="editLinkDesc" class="form-label form-label-sm">Description</label>
                    <input type="text" class="form-control form-control-sm" id="editLinkDesc" maxlength="255">
                </div>
                <div class="row g-2">
                    <div class="col-md-6 mb-3">
                        <label for="editLinkIcon" class="form-label form-label-sm">Icon Class</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" id="iconPreview"><i class="bi bi-link-45deg"></i></span>
                            <input type="text" class="form-control form-control-sm" id="editLinkIcon"
                                   value="bi-link-45deg" maxlength="64" placeholder="bi-link-45deg">
                        </div>
                        <div class="form-text">Bootstrap Icons class (e.g., bi-globe, bi-github)</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="editLinkCategory" class="form-label form-label-sm">Category</label>
                        <input type="text" class="form-control form-control-sm" id="editLinkCategory"
                               value="General" maxlength="64" list="categoryList">
                        <datalist id="categoryList"></datalist>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6 mb-3">
                        <label for="editLinkSort" class="form-label form-label-sm">Sort Order</label>
                        <input type="number" class="form-control form-control-sm" id="editLinkSort" value="0" min="0">
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="editLinkActive" checked>
                            <label class="form-check-label" for="editLinkActive">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSaveLink">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Delete Link</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong id="deleteLinkName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="deleteLinkId" value="0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="btnConfirmDelete">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<input type="hidden" id="isAdmin" value="<?php echo $is_admin ? '1' : '0'; ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/links.js?v=<?php echo asset_v('assets/js/links.js'); ?>"></script>

</body>
</html>
