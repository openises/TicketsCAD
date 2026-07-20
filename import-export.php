<?php
/**
 * NewUI v4.0 - Import/Export Tool
 *
 * Admin-only tool for CSV import and export of personnel, constituent,
 * vehicle, and equipment data.
 */

require_once __DIR__ . '/config.php';

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


// Admin only
require_once __DIR__ . '/inc/rbac.php';
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'config';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Import / Export — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo NEWUI_VERSION; ?>">
    <style>
        .imex-step { display: none; }
        .imex-step.active { display: block; }
        .step-indicator {
            display: flex; gap: 8px; margin-bottom: 16px;
        }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem; font-weight: 700;
            background: var(--bs-secondary-bg); color: var(--bs-secondary);
            border: 2px solid var(--bs-border-color);
        }
        .step-dot.active { background: var(--bs-primary); color: #fff; border-color: var(--bs-primary); }
        .step-dot.done { background: var(--bs-success); color: #fff; border-color: var(--bs-success); }
        .step-label { font-size: 0.72rem; margin-top: 2px; }

        .mapping-row {
            display: flex; align-items: center; gap: 8px;
            padding: 4px 0; border-bottom: 1px solid var(--bs-border-color-translucent);
            font-size: 0.8rem;
        }
        .mapping-row:last-child { border-bottom: none; }
        .mapping-arrow { opacity: 0.4; font-size: 0.7rem; }

        .preview-table {
            font-size: 0.72rem; width: 100%; border-collapse: collapse;
        }
        .preview-table th {
            background: var(--bs-secondary-bg); padding: 4px 8px;
            border: 1px solid var(--bs-border-color); font-weight: 600;
            white-space: nowrap; font-size: 0.68rem; text-transform: uppercase;
        }
        .preview-table td {
            padding: 3px 8px; border: 1px solid var(--bs-border-color);
            max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .target-card {
            cursor: pointer; border: 2px solid var(--bs-border-color);
            border-radius: 8px; padding: 16px; text-align: center;
            transition: border-color 0.2s, background 0.2s;
        }
        .target-card:hover { border-color: var(--bs-primary); background: rgba(13,110,253,0.05); }
        .target-card.selected { border-color: var(--bs-primary); background: rgba(13,110,253,0.1); }
        .target-card .icon { font-size: 1.5rem; margin-bottom: 6px; }

        /* Mapping column labels */
        .mapping-label-csv {
            font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.03em;
            color: var(--bs-info); font-weight: 600; margin-bottom: 2px;
        }
        .mapping-label-db {
            font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.03em;
            color: var(--bs-success); font-weight: 600; margin-bottom: 2px;
        }

        /* Error review editor */
        .error-row-card {
            border: 1px solid var(--bs-danger);
            border-radius: 6px; padding: 8px 12px; margin-bottom: 8px;
            background: rgba(220,53,69,0.04);
        }
        .error-row-card.fixed {
            border-color: var(--bs-success);
            background: rgba(25,135,84,0.04);
        }
        .error-row-card .error-msg {
            font-size: 0.72rem; color: var(--bs-danger); margin-bottom: 4px;
        }
        .error-row-card.fixed .error-msg {
            color: var(--bs-success);
        }
        .error-row-fields {
            display: flex; flex-wrap: wrap; gap: 6px;
        }
        .error-row-fields .field-group {
            flex: 0 0 auto; min-width: 120px;
        }
        .error-row-fields label {
            font-size: 0.65rem; text-transform: uppercase; color: var(--bs-body-secondary);
            margin-bottom: 1px; display: block;
        }
        .error-row-fields input {
            font-size: 0.75rem; padding: 2px 6px; height: auto;
        }
        .error-row-fields input.is-invalid {
            border-color: var(--bs-danger);
        }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Config Layout: Sidebar + Content -->
<div class="config-layout">

    <?php $configActivePage = 'import-export'; include_once NEWUI_ROOT . '/inc/config-sidebar.php'; ?>

    <main class="config-content" id="configContent" style="padding: 1rem 1.5rem;">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-arrow-left-right text-primary me-2"></i>Import / Export
        </h5>
    </div>

    <!-- Tab: Import / Export toggle -->
    <ul class="nav nav-tabs nav-tabs-sm mb-3">
        <li class="nav-item">
            <button class="nav-link active small py-1 px-3" id="btnTabImport" data-bs-toggle="tab" data-bs-target="#panelImport">
                <i class="bi bi-upload me-1"></i>Import
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link small py-1 px-3" id="btnTabExport" data-bs-toggle="tab" data-bs-target="#panelExport">
                <i class="bi bi-download me-1"></i>Export
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ═══ IMPORT PANEL ═════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="panelImport">

            <!-- Step indicators -->
            <div class="step-indicator" id="stepIndicator">
                <div class="text-center">
                    <div class="step-dot active" id="stepDot1">1</div>
                    <div class="step-label">Target</div>
                </div>
                <div class="text-center">
                    <div class="step-dot" id="stepDot2">2</div>
                    <div class="step-label">Upload</div>
                </div>
                <div class="text-center">
                    <div class="step-dot" id="stepDot3">3</div>
                    <div class="step-label">Map</div>
                </div>
                <div class="text-center">
                    <div class="step-dot" id="stepDot4">4</div>
                    <div class="step-label">Import</div>
                </div>
            </div>

            <!-- Step 1: Select target -->
            <div class="imex-step active" id="step1">
                <h6 class="mb-3">Select what you want to import</h6>
                <div class="row g-3" id="targetCards">
                    <!-- Populated by JS -->
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" id="btnStep1Next" disabled>
                        Next <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>

            <!-- Step 2: Upload file -->
            <div class="imex-step" id="step2">
                <h6 class="mb-2">Upload CSV File</h6>
                <p class="text-body-secondary small mb-3">
                    Upload a CSV file with a header row. The system will try to auto-match your column headers to the database fields.
                </p>
                <div class="mb-3">
                    <input type="file" class="form-control form-control-sm" id="csvFile" accept=".csv,.txt,.tsv">
                </div>
                <div id="uploadStatus" class="mb-3" style="display:none"></div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" id="btnStep2Back">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </button>
                    <button class="btn btn-primary btn-sm" id="btnStep2Upload" disabled>
                        <i class="bi bi-upload me-1"></i>Upload & Preview
                    </button>
                </div>
            </div>

            <!-- Step 3: Column mapping + preview -->
            <div class="imex-step" id="step3">
                <div class="row g-3">
                    <div class="col-md-5">
                        <h6 class="mb-2">Column Mapping</h6>
                        <p class="text-body-secondary small mb-2">
                            Map columns from your <span class="text-info fw-semibold">CSV file</span>
                            to <span class="text-success fw-semibold">database fields</span>.
                            Auto-matched columns are pre-selected. Use <em>(skip)</em> to ignore a column.
                        </p>
                        <div class="d-flex gap-4 mb-2 small">
                            <span class="mapping-label-csv"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Your CSV Column</span>
                            <span class="mapping-label-db"><i class="bi bi-database me-1"></i>Database Field</span>
                        </div>
                        <div id="mappingContainer"></div>

                        <div class="mt-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="importMode" id="modeInsert" value="insert" checked>
                                <label class="form-check-label small" for="modeInsert">
                                    <strong>Insert only</strong> — skip rows that match existing records
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="importMode" id="modeUpsert" value="upsert">
                                <label class="form-check-label small" for="modeUpsert">
                                    <strong>Insert + Update</strong> — update existing records if matched
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <h6 class="mb-2">Data Preview <span class="text-body-secondary small" id="previewCount"></span></h6>
                        <div id="validationSummary" class="mb-2"></div>
                        <div style="max-height:300px;overflow:auto">
                            <table class="preview-table" id="previewTable">
                                <thead id="previewHead"></thead>
                                <tbody id="previewBody"></tbody>
                            </table>
                        </div>

                        <!-- Error Row Editor -->
                        <div id="errorReviewSection" style="display:none" class="mt-3">
                            <h6 class="mb-2 text-danger">
                                <i class="bi bi-exclamation-triangle me-1"></i>Review Problem Rows
                                <span class="badge bg-danger ms-1" id="errorRowCountBadge">0</span>
                            </h6>
                            <p class="text-body-secondary small mb-2">
                                These rows failed validation. Edit the fields below to fix them, then click
                                <strong>Import Fixed Rows</strong> to include_once them in the import.
                            </p>
                            <div id="errorRowEditor"></div>
                            <div class="d-flex gap-2 mt-2">
                                <button class="btn btn-sm btn-outline-success" id="btnImportFixed" disabled>
                                    <i class="bi bi-check-circle me-1"></i>Import Fixed Rows
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" id="btnSkipErrors">
                                    <i class="bi bi-skip-forward me-1"></i>Skip All Error Rows
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-outline-secondary btn-sm" id="btnStep3Back">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </button>
                    <button class="btn btn-primary btn-sm" id="btnStep3Import">
                        <i class="bi bi-check-circle me-1"></i>Import <span id="importRowCount"></span> rows
                    </button>
                </div>
            </div>

            <!-- Step 4: Results -->
            <div class="imex-step" id="step4">
                <div id="importResults"></div>
                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" id="btnStartOver">
                        <i class="bi bi-arrow-repeat me-1"></i>Import More
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" id="btnDownloadLog" style="display:none">
                        <i class="bi bi-download me-1"></i>Download Import Log
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══ EXPORT PANEL ═════════════════════════════════════ -->
        <div class="tab-pane fade" id="panelExport">
            <h6 class="mb-3">Export Data as CSV</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small">Table</label>
                    <select class="form-select form-select-sm" id="exportTarget">
                        <!-- Populated by JS -->
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Search filter (optional)</label>
                    <input type="text" class="form-control form-control-sm" id="exportSearch" placeholder="Filter by keyword...">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button class="btn btn-primary btn-sm" id="btnExport">
                        <i class="bi bi-download me-1"></i>Download CSV
                    </button>
                </div>
            </div>
            <div class="mt-3" id="exportInfo">
                <p class="text-body-secondary small">
                    <i class="bi bi-info-circle me-1"></i>
                    Exports all records from the selected table as a CSV file. Use the search filter to export a subset.
                </p>
            </div>
        </div>
    </div>

    </main>
</div>

<!-- CSRF token -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/import-export.js?v=<?php echo asset_v('assets/js/import-export.js'); ?>"></script>

<!-- Sidebar section expand/collapse -->
<script>
(function () {
    'use strict';
    var headers = document.querySelectorAll('.config-section-header');
    for (var i = 0; i < headers.length; i++) {
        headers[i].addEventListener('click', function () {
            var section = this.getAttribute('data-section');
            var list = document.querySelector('.config-tab-list[data-section="' + section + '"]');
            if (list) {
                this.classList.toggle('collapsed');
                list.classList.toggle('collapsed');
            }
        });
    }
    var tabBtns = document.querySelectorAll('.config-tab-link[data-tab]');
    for (var j = 0; j < tabBtns.length; j++) {
        tabBtns[j].addEventListener('click', function () {
            var tab = this.getAttribute('data-tab');
            if (tab) window.location.href = 'settings.php#' + tab;
        });
    }
})();
</script>

</body>
</html>
