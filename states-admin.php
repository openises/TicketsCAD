<?php
/**
 * NewUI v4.0 — States / Provinces (GH#153).
 *
 * Settings → Application — Geographic Data → States / Provinces. Edits the
 * one list behind every State dropdown in the application (table
 * states_translator), with a configuration wizard of built-in presets (US 48 /
 * 50 / territories, Canada, Mexico, Australia, New Zealand, UK, Ireland,
 * Germany, Brazil, India), CSV import and export, and the field label.
 *
 * Backend: api/states.php. Library: inc/states.php. Preset data:
 * inc/state-presets.php. Guide: docs/STATES-AND-PROVINCES.md.
 *
 * RBAC: action.manage_config — the same permission api/states.php checks on
 * every action (a page gate and its API gate name the same permission). This
 * page's gate is not the security control; the API's is.
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

$pageTitle = t('sidebar.tab.states_provinces', 'States / Provinces');

if (!rbac_can('action.manage_config')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?> — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Changing the State / Province list requires the "Manage configuration"
           permission. Ask an administrator to grant your role <code>action.manage_config</code>.</p>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">Back to Settings</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

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
    <title><?php echo e($pageTitle); ?> — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" id="main-content" style="max-width: 1200px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-signpost-split text-primary me-2"></i><?php echo e($pageTitle); ?></h4>
        <a href="documentation/?doc=STATES-AND-PROVINCES" target="_blank" rel="noopener" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-question-circle me-1"></i>Guide</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Settings</a>
    </div>

    <div id="stToast" class="alert d-none" role="status" aria-live="polite"></div>
    <div id="stSchemaBanner" class="alert alert-warning small d-none" role="alert"></div>
    <noscript><div class="alert alert-warning">This page needs JavaScript.</div></noscript>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        This list fills <strong>every State dropdown</strong> in TicketsCAD &mdash; incidents, members, units,
        facilities, vehicles, constituents, places and warn locations. A change shows up the next time a page
        loads, so <strong>reload any page that is already open</strong> to see it. Saved records are never
        changed: a record keeps the code it was saved with even after that code is removed from the list, and
        its form still shows it.
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-list-ul me-2"></i>Current list
                        <span class="badge bg-secondary ms-1" id="stCount">&hellip;</span></span>
                    <div class="ms-auto d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-primary" id="stBtnWizard">
                            <i class="bi bi-magic me-1"></i>Configuration wizard</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="stBtnImport">
                            <i class="bi bi-upload me-1"></i>Import CSV</button>
                        <a class="btn btn-sm btn-outline-secondary" id="stBtnExport" href="api/states.php?action=export">
                            <i class="bi bi-download me-1"></i>Export CSV</a>
                        <button type="button" class="btn btn-sm btn-outline-success" id="stBtnAdd">
                            <i class="bi bi-plus-lg me-1"></i>Add entry</button>
                    </div>
                </div>
                <div class="card-body pb-0">
                    <div class="row g-2 align-items-end mb-2 d-none" id="stAddRow">
                        <div class="col-3">
                            <label class="form-label form-label-sm mb-0" for="stAddCode">Code</label>
                            <input type="text" class="form-control form-control-sm font-monospace text-uppercase" id="stAddCode" maxlength="4" autocomplete="off">
                        </div>
                        <div class="col">
                            <label class="form-label form-label-sm mb-0" for="stAddName">Name</label>
                            <input type="text" class="form-control form-control-sm" id="stAddName" maxlength="64" autocomplete="off">
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-success" id="stAddSave"><i class="bi bi-check-lg me-1"></i>Add</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="stAddCancel">Cancel</button>
                        </div>
                        <div class="col-12 form-text mt-0">Code: 1 to 4 letters, digits or hyphens (stored in capitals). Name: up to 64 characters.</div>
                    </div>
                    <input type="search" class="form-control form-control-sm mb-2" id="stFilter" placeholder="Filter by code or name" aria-label="Filter the list">
                </div>
                <div class="table-responsive" style="max-height: 65vh;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="sticky-top">
                            <tr><th style="width: 90px;">Code</th><th>Name</th><th class="text-end" style="width: 150px;">Actions</th></tr>
                        </thead>
                        <tbody id="stRows"><tr><td colspan="3" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-tag me-2"></i>Field label</div>
                <div class="card-body">
                    <p class="small text-body-secondary mb-2">What the State fields are called on every form.
                        Currently <strong id="stLabelCurrent">&hellip;</strong>.</p>
                    <label class="form-label form-label-sm mb-0" for="stLabelChoice">Call the field</label>
                    <select class="form-select form-select-sm mb-2" id="stLabelChoice"></select>
                    <input type="text" class="form-control form-control-sm mb-2 d-none" id="stLabelCustom" maxlength="40" placeholder="Your own wording" aria-label="Custom field label">
                    <label class="form-label form-label-sm mb-0" for="stLabelLang">Language</label>
                    <select class="form-select form-select-sm mb-2" id="stLabelLang"></select>
                    <button type="button" class="btn btn-sm btn-primary" id="stLabelSave"><i class="bi bi-save me-1"></i>Save label</button>
                    <div class="form-text">Other languages keep their own wording; change them here or in
                        Settings &rarr; Translations (caption <code>form.state</code>).</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Built-in lists as CSV</div>
                <div class="card-body small">
                    <p class="text-body-secondary mb-2">Download a built-in list, edit it in a spreadsheet, and bring it
                        back with <strong>Import CSV</strong>. The file has two columns, <code>code,name</code>.</p>
                    <ul class="list-unstyled mb-0" id="stPresetDownloads"><li class="text-body-secondary">Loading&hellip;</li></ul>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ═══════════════ Configuration wizard ═══════════════ -->
<div class="modal fade" id="stWizard" tabindex="-1" aria-labelledby="stWizardTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stWizardTitle"><i class="bi bi-magic me-2"></i>Configuration wizard</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <ol class="list-inline small mb-3" id="stWizSteps" aria-label="Wizard steps">
          <li class="list-inline-item" data-step="1">1. Choose</li>
          <li class="list-inline-item" data-step="2">2. Options</li>
          <li class="list-inline-item" data-step="3">3. Replace or add</li>
          <li class="list-inline-item" data-step="4">4. Preview</li>
          <li class="list-inline-item" data-step="5">5. Field label</li>
        </ol>

        <section id="stWizStep1">
          <p class="small mb-1">One click:</p>
          <div class="d-flex flex-wrap gap-2 mb-3" id="stQuickPicks"></div>
          <p class="small mb-1">Or choose one or more lists:</p>
          <div id="stPresetChecks"></div>
        </section>

        <section id="stWizStep2" class="d-none">
          <div id="stOptionGroups"></div>
        </section>

        <section id="stWizStep3" class="d-none">
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="stWizMode" id="stWizModeReplace" value="replace" checked>
            <label class="form-check-label" for="stWizModeReplace"><strong>Replace the list</strong> &mdash; the list
              becomes exactly what you chose. Entries not in your choice are removed.</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="stWizMode" id="stWizModeAdd" value="add">
            <label class="form-check-label" for="stWizModeAdd"><strong>Add to the list</strong> &mdash; keep everything
              already there and add what is missing. Codes already on the list are skipped.</label>
          </div>
        </section>

        <section id="stWizStep4" class="d-none">
          <div id="stWizPreview"></div>
        </section>

        <section id="stWizStep5" class="d-none">
          <div id="stWizResult" class="alert alert-success small"></div>
          <p class="small mb-1">Optionally, rename the field to match the list. Suggested:
            <strong id="stWizLabelSuggest"></strong>.</p>
          <div class="row g-2 align-items-end">
            <div class="col-sm-6">
              <label class="form-label form-label-sm mb-0" for="stWizLabelChoice">Call the field</label>
              <select class="form-select form-select-sm" id="stWizLabelChoice"></select>
            </div>
            <div class="col-sm-6">
              <input type="text" class="form-control form-control-sm d-none" id="stWizLabelCustom" maxlength="40" placeholder="Your own wording" aria-label="Custom field label">
            </div>
          </div>
        </section>

        <div id="stWizError" class="alert alert-danger small mt-3 d-none" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary me-auto" id="stWizBack">Back</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="stWizDownload">
            <i class="bi bi-download me-1"></i>Download this selection</button>
        <button type="button" class="btn btn-sm btn-primary" id="stWizNext">Next</button>
        <button type="button" class="btn btn-sm btn-danger d-none" id="stWizApply"><i class="bi bi-check2-circle me-1"></i>Apply</button>
        <button type="button" class="btn btn-sm btn-primary d-none" id="stWizSetLabel">Set label</button>
        <button type="button" class="btn btn-sm btn-outline-secondary d-none" data-bs-dismiss="modal" id="stWizDone">Done</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════ Import ═══════════════ -->
<div class="modal fade" id="stImport" tabindex="-1" aria-labelledby="stImportTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stImportTitle"><i class="bi bi-upload me-2"></i>Import a CSV list</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary">A CSV file with the header <code>code,name</code>, saved as UTF-8
          (in Excel: <em>Save As &rarr; CSV UTF-8</em>). Up to 2,000 rows and 256 KB. Nothing changes until you press
          <strong>Apply</strong>.</p>
        <label class="form-label form-label-sm mb-0" for="stImportFile">File</label>
        <input type="file" class="form-control form-control-sm mb-2" id="stImportFile" accept=".csv,text/csv,text/plain">
        <label class="form-label form-label-sm mb-0" for="stImportText">&hellip;or paste the CSV text</label>
        <textarea class="form-control form-control-sm font-monospace mb-2" id="stImportText" rows="5" spellcheck="false"></textarea>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="stImportMode" id="stImportModeReplace" value="replace" checked>
          <label class="form-check-label small" for="stImportModeReplace">Replace the list</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="stImportMode" id="stImportModeAdd" value="add">
          <label class="form-check-label small" for="stImportModeAdd">Add to the list</label>
        </div>
        <div id="stImportPreview" class="mt-3"></div>
        <div id="stImportError" class="alert alert-danger small mt-3 d-none" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary me-auto" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-sm btn-primary" id="stImportPreviewBtn">Preview</button>
        <button type="button" class="btn btn-sm btn-danger d-none" id="stImportApply"><i class="bi bi-check2-circle me-1"></i>Apply</button>
      </div>
    </div>
  </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/states-admin.js?v=<?php echo asset_v('assets/js/states-admin.js'); ?>"></script>
</body>
</html>
