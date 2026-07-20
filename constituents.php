<?php
/**
 * NewUI v4.0 - Constituents (Contact Database)
 *
 * Manage community contacts for phone-based caller lookup.
 * Constituents are searched during incident creation to auto-fill
 * caller information and display important notes/warnings.
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
rbac_require_screen('screen.constituents');
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
    <title><?php echo e(t('page.constituents', 'Contacts')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>
    <meta name="csrf-token" content="<?php echo $csrf; ?>">

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/constituents.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-person-lines-fill me-2"></i><?php echo e(t('page.constituents', 'Contacts')); ?>
            <small class="text-body-secondary" id="totalCount"></small>
        </h5>
        <div class="d-flex gap-2">
            <div class="input-group input-group-sm" style="max-width: 300px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control form-control-sm" id="searchInput"
                       placeholder="Search name, phone, address...">
            </div>
            <button class="btn btn-sm btn-outline-success" id="btnExport" title="Export CSV">
                <i class="bi bi-download me-1"></i>Export
            </button>
            <button class="btn btn-sm btn-outline-primary" id="btnImport" title="Import CSV">
                <i class="bi bi-upload me-1"></i>Import
            </button>
            <button class="btn btn-sm btn-outline-secondary d-none" id="btnMerge" title="Merge selected contacts">
                <i class="bi bi-intersect me-1"></i>Merge
            </button>
            <button class="btn btn-sm btn-primary" id="btnNew">
                <i class="bi bi-plus-lg me-1"></i>New Contact
            </button>
        </div>
    </div>

    <div id="alertArea"></div>

    <!-- List / Detail Split View -->
    <div class="row g-3">
        <!-- Left: Contact List -->
        <div class="col-lg-7" id="listPanel">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover mb-0" id="constituentsTable">
                        <thead>
                            <tr>
                                <th style="width:30px;"><input type="checkbox" class="form-check-input" id="selectAll" title="Select all"></th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th>City</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody id="constituentsBody">
                            <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center py-1" id="paginationFooter">
                    <small class="text-body-secondary" id="pageInfo"></small>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" id="btnPrev" disabled><i class="bi bi-chevron-left"></i></button>
                        <button class="btn btn-outline-secondary" id="btnNext" disabled><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Detail / Edit Form -->
        <div class="col-lg-5" id="detailPanel">
            <div class="card" id="detailCard">
                <div class="card-header d-flex align-items-center py-1">
                    <i class="bi bi-person-vcard me-2"></i>
                    <span class="fw-semibold small" id="detailTitle">Select a contact</span>
                    <div class="ms-auto d-flex gap-1" id="detailActions" style="display: none !important;">
                        <button class="btn btn-xs btn-outline-primary" id="btnEdit" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-xs btn-outline-danger" id="btnDelete" title="Delete"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
                <div class="card-body py-2 px-3" id="detailContent">
                    <div class="text-center text-body-secondary py-4">
                        <i class="bi bi-person-lines-fill" style="font-size: 3rem; opacity: 0.3;"></i>
                        <p class="mt-2">Click a contact to view details, or create a new one.</p>
                    </div>
                </div>
            </div>

            <!-- Edit Form (hidden by default) -->
            <div class="card d-none" id="editCard">
                <div class="card-header d-flex align-items-center py-1">
                    <i class="bi bi-pencil-square me-2"></i>
                    <span class="fw-semibold small" id="editTitle">New Contact</span>
                </div>
                <div class="card-body py-2 px-3">
                    <form id="constituentForm" autocomplete="off">
                        <input type="hidden" id="editId" value="">

                        <div class="row g-2 mb-2">
                            <div class="col-8">
                                <label class="form-label small mb-0">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="editContact" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label small mb-0">Reference</label>
                                <input type="text" class="form-control form-control-sm" id="editReference">
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small mb-0">Phone <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="editPhone" required>
                            </div>
                            <div class="col-5">
                                <label class="form-label small mb-0">Type</label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-select-sm phone-type-select" id="editPhoneType">
                                        <option value="">--</option>
                                        <option value="Mobile">Mobile</option>
                                        <option value="Home">Home</option>
                                        <option value="Work">Work</option>
                                        <option value="Day">Day</option>
                                        <option value="Night">Night</option>
                                        <option value="Text">Text/SMS</option>
                                        <option value="Fax">Fax</option>
                                        <option value="custom">Custom...</option>
                                    </select>
                                    <input type="text" class="form-control d-none phone-type-custom" id="editPhoneTypeCustom"
                                           placeholder="Label">
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small mb-0">Phone 2</label>
                                <input type="text" class="form-control form-control-sm" id="editPhone2">
                            </div>
                            <div class="col-5">
                                <label class="form-label small mb-0">Type</label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-select-sm phone-type-select" id="editPhone2Type">
                                        <option value="">--</option>
                                        <option value="Mobile">Mobile</option>
                                        <option value="Home">Home</option>
                                        <option value="Work">Work</option>
                                        <option value="Day">Day</option>
                                        <option value="Night">Night</option>
                                        <option value="Text">Text/SMS</option>
                                        <option value="Fax">Fax</option>
                                        <option value="custom">Custom...</option>
                                    </select>
                                    <input type="text" class="form-control d-none phone-type-custom" id="editPhone2TypeCustom"
                                           placeholder="Label">
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small mb-0">Phone 3</label>
                                <input type="text" class="form-control form-control-sm" id="editPhone3">
                            </div>
                            <div class="col-5">
                                <label class="form-label small mb-0">Type</label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-select-sm phone-type-select" id="editPhone3Type">
                                        <option value="">--</option>
                                        <option value="Mobile">Mobile</option>
                                        <option value="Home">Home</option>
                                        <option value="Work">Work</option>
                                        <option value="Day">Day</option>
                                        <option value="Night">Night</option>
                                        <option value="Text">Text/SMS</option>
                                        <option value="Fax">Fax</option>
                                        <option value="custom">Custom...</option>
                                    </select>
                                    <input type="text" class="form-control d-none phone-type-custom" id="editPhone3TypeCustom"
                                           placeholder="Label">
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small mb-0">Phone 4</label>
                                <input type="text" class="form-control form-control-sm" id="editPhone4">
                            </div>
                            <div class="col-5">
                                <label class="form-label small mb-0">Type</label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-select-sm phone-type-select" id="editPhone4Type">
                                        <option value="">--</option>
                                        <option value="Mobile">Mobile</option>
                                        <option value="Home">Home</option>
                                        <option value="Work">Work</option>
                                        <option value="Day">Day</option>
                                        <option value="Night">Night</option>
                                        <option value="Text">Text/SMS</option>
                                        <option value="Fax">Fax</option>
                                        <option value="custom">Custom...</option>
                                    </select>
                                    <input type="text" class="form-control d-none phone-type-custom" id="editPhone4TypeCustom"
                                           placeholder="Label">
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small mb-0">Email</label>
                            <input type="email" class="form-control form-control-sm" id="editEmail">
                        </div>

                        <hr class="my-2">

                        <div class="row g-2 mb-2">
                            <div class="col-8">
                                <label class="form-label small mb-0">Street</label>
                                <input type="text" class="form-control form-control-sm" id="editStreet">
                            </div>
                            <div class="col-4">
                                <label class="form-label small mb-0">Apt/Unit</label>
                                <input type="text" class="form-control form-control-sm" id="editApartment">
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-5">
                                <label class="form-label small mb-0">City</label>
                                <input type="text" class="form-control form-control-sm" id="editCity">
                            </div>
                            <div class="col-3">
                                <label class="form-label small mb-0"><?php echo e(t('form.state', 'State')); ?></label>
                                <select class="form-select form-select-sm" id="editState"></select>
                            </div>
                            <div class="col-4">
                                <label class="form-label small mb-0"><?php echo e(t('form.zip', 'ZIP')); ?></label>
                                <input type="text" class="form-control form-control-sm" id="editPostCode">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small mb-0">Community/Neighborhood</label>
                            <input type="text" class="form-control form-control-sm" id="editCommunity">
                        </div>

                        <div class="mb-2">
                            <label class="form-label small mb-0">Notes / Warnings</label>
                            <textarea class="form-control form-control-sm" id="editMisc" rows="3"
                                      placeholder="Important info about this contact (e.g., medical conditions, pets, access instructions)"></textarea>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelEdit">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal (4-step wizard) -->
<div class="modal fade" id="importModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-upload me-2"></i>Import Contacts</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <!-- Step indicators -->
            <div class="d-flex border-bottom px-3 py-2 bg-body-tertiary small">
                <span class="import-step-indicator active" data-step="1"><i class="bi bi-1-circle-fill me-1"></i>Upload</span>
                <span class="import-step-indicator" data-step="2"><i class="bi bi-2-circle me-1"></i>Map Columns</span>
                <span class="import-step-indicator" data-step="3"><i class="bi bi-3-circle me-1"></i>Preview</span>
                <span class="import-step-indicator" data-step="4"><i class="bi bi-4-circle me-1"></i>Results</span>
            </div>

            <div class="modal-body">
                <div id="importAlertArea"></div>

                <!-- Step 1: Upload -->
                <div class="import-step" data-step="1">
                    <div class="text-center py-4">
                        <i class="bi bi-file-earmark-spreadsheet" style="font-size: 3rem; opacity: 0.5;"></i>
                        <h6 class="mt-2">Upload a Contact List</h6>
                        <p class="small text-body-secondary mb-3">
                            Supports CSV, TSV, and tab-delimited text files (max 5MB, up to 5,000 rows).<br>
                            Column names will be auto-detected. You can adjust mappings in the next step.
                        </p>
                        <div class="d-inline-block text-start" style="max-width: 400px;">
                            <input type="file" class="form-control form-control-sm" id="importFileInput"
                                   accept=".csv,.tsv,.txt">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Column Mapping -->
                <div class="import-step d-none" data-step="2">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Column Mapping</h6>
                            <small class="text-body-secondary" id="importFileInfo"></small>
                        </div>
                        <div class="table-responsive" style="max-height: 350px;">
                            <table class="table table-sm table-bordered small mb-0" id="mappingTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>File Column</th>
                                        <th>Preview Data</th>
                                        <th>Maps To</th>
                                    </tr>
                                </thead>
                                <tbody id="mappingBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- First + Last + Middle Name toggle -->
                    <div class="card card-body py-2 px-3 mb-3 bg-body-tertiary">
                        <div class="form-check form-check-inline small">
                            <input class="form-check-input" type="checkbox" id="chkFirstLast">
                            <label class="form-check-label" for="chkFirstLast">
                                File has separate First Name &amp; Last Name columns
                            </label>
                        </div>
                        <div class="row g-2 mt-1 d-none" id="firstLastRow">
                            <div class="col-md-4">
                                <label class="form-label small mb-0">First Name Column</label>
                                <select class="form-select form-select-sm" id="firstNameCol"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-0">Last Name Column</label>
                                <select class="form-select form-select-sm" id="lastNameCol"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-0">Middle Name Column <span class="text-body-secondary">(optional)</span></label>
                                <select class="form-select form-select-sm" id="middleNameCol"></select>
                            </div>
                            <div class="col-12 mt-2">
                                <label class="form-label small mb-1">Name Order</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check form-check-inline small">
                                        <input class="form-check-input" type="radio" name="nameOrder" id="nameOrderFL" value="first_last" checked>
                                        <label class="form-check-label" for="nameOrderFL">First Last <span class="text-body-secondary">(Aaron Larson)</span></label>
                                    </div>
                                    <div class="form-check form-check-inline small">
                                        <input class="form-check-input" type="radio" name="nameOrder" id="nameOrderLF" value="last_first">
                                        <label class="form-check-label" for="nameOrderLF">Last, First <span class="text-body-secondary">(Larson, Aaron)</span></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Defaults -->
                    <div class="card card-body py-2 px-3 bg-body-tertiary">
                        <h6 class="small mb-2">Default values for missing fields</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small mb-0">City</label>
                                <input type="text" class="form-control form-control-sm" id="defaultCity">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-0"><?php echo e(t('form.state', 'State')); ?></label>
                                <select class="form-select form-select-sm" id="defaultState"></select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-0">Community</label>
                                <input type="text" class="form-control form-control-sm" id="defaultCommunity">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Preview & Conflicts -->
                <div class="import-step d-none" data-step="3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="badge bg-success me-1" id="previewNewCount">0 new</span>
                            <span class="badge bg-warning text-dark" id="previewConflictCount">0 conflicts</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label small mb-0">Default for conflicts:</label>
                            <select class="form-select form-select-sm" id="globalConflictAction" style="width: auto;">
                                <option value="skip">Skip (keep existing)</option>
                                <option value="overwrite">Overwrite with import</option>
                                <option value="merge">Merge (fill blanks)</option>
                            </select>
                        </div>
                    </div>

                    <div id="conflictsList"></div>

                    <div class="text-center text-body-secondary py-3 d-none" id="noConflictsMsg">
                        <i class="bi bi-check-circle text-success me-1"></i>No conflicts found. All records are new.
                    </div>

                    <div class="text-center py-3 d-none" id="previewLoading">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <div class="small mt-1">Checking for duplicates...</div>
                    </div>
                </div>

                <!-- Step 4: Results -->
                <div class="import-step d-none" data-step="4">
                    <div class="text-center py-4" id="importResults">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        <h5 class="mt-2">Import Complete</h5>
                        <div class="d-flex justify-content-center gap-3 mt-3">
                            <div class="text-center">
                                <div class="fs-4 fw-bold text-success" id="resultInserted">0</div>
                                <div class="small text-body-secondary">Inserted</div>
                            </div>
                            <div class="text-center">
                                <div class="fs-4 fw-bold text-primary" id="resultUpdated">0</div>
                                <div class="small text-body-secondary">Updated</div>
                            </div>
                            <div class="text-center">
                                <div class="fs-4 fw-bold text-warning" id="resultSkipped">0</div>
                                <div class="small text-body-secondary">Skipped</div>
                            </div>
                        </div>
                        <div class="mt-3 d-none" id="resultErrors">
                            <div class="alert alert-warning text-start small py-2" id="resultErrorList"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" id="importCloseBtn">Cancel</button>
                <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="importBackBtn">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="importNextBtn">
                    <i class="bi bi-upload me-1"></i>Upload &amp; Parse
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Merge Modal -->
<div class="modal fade" id="mergeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-intersect me-2"></i>Merge Contacts</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="mergeAlertArea"></div>
                <p class="small text-body-secondary mb-2">
                    Choose which value to keep for each field. Click "Auto-merge" to pick non-empty values automatically.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered small mb-0" id="mergeTable">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Record A</th>
                                <th>Record B</th>
                                <th>Keep</th>
                            </tr>
                        </thead>
                        <tbody id="mergeBody"></tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <label class="form-label small mb-1">Which record survives?</label>
                    <div class="form-check form-check-inline small">
                        <input class="form-check-input" type="radio" name="mergeSurvivor" id="survivorA" value="a" checked>
                        <label class="form-check-label" for="survivorA">Record A</label>
                    </div>
                    <div class="form-check form-check-inline small">
                        <input class="form-check-input" type="radio" name="mergeSurvivor" id="survivorB" value="b">
                        <label class="form-check-label" for="survivorB">Record B</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-outline-info" id="btnAutoMerge">
                    <i class="bi bi-magic me-1"></i>Auto-merge
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="btnDoMerge">
                    <i class="bi bi-intersect me-1"></i>Merge
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- App JS -->
<script src="assets/js/states-select.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/states-select.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/constituents.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/constituents-import.js?v=<?php echo NEWUI_VERSION; ?>"></script>
</body>
</html>
