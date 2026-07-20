<?php
/**
 * NewUI v4.0 - SOP Wiki
 *
 * Standard Operating Procedures wiki. Two-column layout:
 *   Left:  Page tree navigation (collapsible, hierarchical)
 *   Right: Page viewer / editor with markdown support
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
rbac_require_screen('screen.sop');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level = current_role_name();
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
    <title><?php echo e(t('page.sop', 'Standard Operating Procedures')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/sop.css">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-journal-text text-primary me-2"></i><?php echo e(t('page.sop', 'Standard Operating Procedures')); ?>
        </h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btnBackToView">
                <i class="bi bi-arrow-left me-1"></i>Back
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary d-none" id="btnPrint">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <div class="row g-3">

        <!-- ═══════════ LEFT COLUMN: Page Tree ═══════════ -->
        <div class="col-md-3">
            <div class="card sop-sidebar">
                <div class="card-header d-flex align-items-center py-2">
                    <i class="bi bi-list-nested me-2"></i>
                    <span class="fw-semibold small">Pages</span>
                    <button type="button" class="btn btn-sm btn-outline-success ms-auto py-0 px-1" id="btnNewPage" title="New Page">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="sop-tree-search p-2">
                        <input type="text" class="form-control form-control-sm" id="treeSearch" placeholder="Filter pages...">
                    </div>
                    <div class="sop-tree" id="pageTree">
                        <div class="text-center text-body-secondary py-3">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                            Loading pages...
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ RIGHT COLUMN: Content ═══════════ -->
        <div class="col-md-9">

            <!-- View Mode -->
            <div id="viewMode">
                <div class="card">
                    <div class="card-header d-flex align-items-center py-2">
                        <nav aria-label="breadcrumb" class="flex-grow-1">
                            <ol class="breadcrumb breadcrumb-sm mb-0" id="breadcrumb">
                                <li class="breadcrumb-item text-body-secondary">Select a page</li>
                            </ol>
                        </nav>
                        <div class="d-flex gap-1" id="viewActions" style="display: none !important;">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnEdit" title="Edit Page">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHistory" title="Revision History">
                                <i class="bi bi-clock-history me-1"></i>History
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnNewChild" title="New Child Page">
                                <i class="bi bi-plus-lg me-1"></i>Child Page
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="btnDelete" title="Delete Page">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="pageTitle"></div>
                        <div id="pageContent" class="sop-content">
                            <div class="text-center text-body-secondary py-5">
                                <i class="bi bi-journal-text" style="font-size: 3rem;"></i>
                                <p class="mt-3">Select a page from the sidebar or create a new one.</p>
                            </div>
                        </div>
                        <div id="pageMeta" class="text-body-secondary small mt-3 pt-2 border-top d-none"></div>
                    </div>
                </div>
            </div>

            <!-- Edit Mode (hidden by default) -->
            <div id="editMode" class="d-none">
                <div class="card">
                    <div class="card-header d-flex align-items-center py-2">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>
                        <span class="fw-semibold small" id="editModeLabel">New Page</span>
                    </div>
                    <div class="card-body">
                        <form id="editForm" novalidate>
                            <input type="hidden" id="editPageId" value="">
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label for="editTitle" class="form-label">Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="editTitle" required maxlength="255">
                                </div>
                                <div class="col-md-3">
                                    <label for="editSlug" class="form-label">Slug</label>
                                    <input type="text" class="form-control form-control-sm" id="editSlug" maxlength="128"
                                           placeholder="auto-generated">
                                </div>
                                <div class="col-md-3">
                                    <label for="editParent" class="form-label">Parent Page</label>
                                    <select class="form-select form-select-sm" id="editParent">
                                        <option value="">— None (root) —</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Markdown Toolbar -->
                            <div class="sop-toolbar mb-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="bold" title="Bold (Ctrl+B)">
                                    <i class="bi bi-type-bold"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="italic" title="Italic (Ctrl+I)">
                                    <i class="bi bi-type-italic"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="heading" title="Heading">
                                    <i class="bi bi-type-h2"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="link" title="Link">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="ul" title="Bullet List">
                                    <i class="bi bi-list-ul"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="ol" title="Numbered List">
                                    <i class="bi bi-list-ol"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="code" title="Code Block">
                                    <i class="bi bi-code-square"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="table" title="Table">
                                    <i class="bi bi-table"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="quote" title="Block Quote">
                                    <i class="bi bi-blockquote-left"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-md="hr" title="Horizontal Rule">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <span class="vr mx-1"></span>
                                <button type="button" class="btn btn-sm btn-outline-info" id="btnTogglePreview" title="Toggle Preview">
                                    <i class="bi bi-eye me-1"></i>Preview
                                </button>
                            </div>

                            <!-- Editor / Preview Split -->
                            <div class="row g-2" id="editorArea">
                                <div class="col-12" id="editorCol">
                                    <textarea class="form-control sop-editor" id="editContent" rows="18"
                                              placeholder="Write your page content in Markdown..."></textarea>
                                </div>
                                <div class="col-6 d-none" id="previewCol">
                                    <div class="sop-preview sop-content" id="editPreview"></div>
                                </div>
                            </div>

                            <div class="row g-2 mt-2">
                                <div class="col-md-8">
                                    <label for="editSummary" class="form-label">Change Summary</label>
                                    <input type="text" class="form-control form-control-sm" id="editSummary"
                                           placeholder="Brief description of changes..." maxlength="255">
                                </div>
                                <div class="col-md-4 d-flex align-items-end gap-2">
                                    <button type="button" class="btn btn-sm btn-success flex-grow-1" id="btnSave">
                                        <i class="bi bi-check-lg me-1"></i>Save Page
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelEdit">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- History Panel (hidden by default) -->
            <div id="historyMode" class="d-none">
                <div class="card">
                    <div class="card-header d-flex align-items-center py-2">
                        <i class="bi bi-clock-history me-2 text-secondary"></i>
                        <span class="fw-semibold small">Revision History</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto py-0 px-1" id="btnCloseHistory" title="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Author</th>
                                        <th>Title</th>
                                        <th>Summary</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="historyBody">
                                    <tr>
                                        <td colspan="5" class="text-center text-body-secondary py-3">No revisions</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Revision content viewer -->
                <div class="card mt-3 d-none" id="revisionViewer">
                    <div class="card-header d-flex align-items-center py-2">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        <span class="fw-semibold small" id="revisionLabel">Revision</span>
                        <button type="button" class="btn btn-sm btn-outline-warning ms-auto py-0 px-2" id="btnRestoreRevision" title="Restore this version">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="revisionContent" class="sop-content"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Delete Page</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete <strong id="deletePageName"></strong>? Child pages will be re-parented.</p>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="btnConfirmDelete">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- Markdown parser -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/sop.js?v=<?php echo NEWUI_VERSION; ?>"></script>

</body>
</html>
