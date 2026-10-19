<?php
/**
 * NewUI v4.0 - ICS Forms Hub
 *
 * Unified ICS (Incident Command System) forms page.
 * Browse available form types, create new forms, view/edit saved forms.
 * Supports every form in the NIMS ICS Forms Booklet (FEMA 502-2) -- see
 * inc/ics-forms-builtin.php -- plus agency custom form types (Phase 140).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
// GH#154 -- the built-in FEMA form list the hub cards are drawn from.
require_once __DIR__ . '/inc/ics-forms-builtin.php';

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
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.ics_forms', 'ICS Forms')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo newui_version(); ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/ics-forms.css">
</head>
<body>

<?php
$active_page = 'ics-forms';
include_once NEWUI_ROOT . '/inc/navbar.php';
?>
</header>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-file-earmark-text text-primary me-2"></i>ICS Forms
        </h5>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- ═══════════ HUB SECTION ═══════════ -->
    <div id="hubSection">

        <!-- GH#154: the new-form pick-lists are generated from ics_builtin_form_types()
             (inc/ics-forms-builtin.php), grouped, with the ICS 219 resource
             status cards under one heading. "Find a form" filters them;
             Enter opens the first match (assets/js/ics-forms.js bindFindForm()). -->
        <div id="icsFormTypeMeta" hidden data-meta="<?php echo e(json_encode(ics_builtin_badge_map())); ?>"></div>
        <div class="d-flex align-items-center gap-2 mb-2">
            <label class="form-label mb-0 small fw-semibold" for="icsFormFind"><i class="bi bi-search me-1"></i>Find a form</label>
            <input type="search" class="form-control form-control-sm" id="icsFormFind" style="max-width:340px"
                   placeholder="Number or name, e.g. 205 or medical — Enter opens" autocomplete="off">
        </div>
        <?php
        // One compact pick-list per group: the form number (in the form's
        // colour), its FEMA title and a one-line description. 35 forms fit on
        // about one screen, so the saved forms below stay close at hand.
        $groupNotes = [
            'tcards' => 'Resource Status Cards for the Resources Unit\'s T-card rack; each colour is for a different kind of resource.',
        ];
        foreach (ics_builtin_groups() as $groupKey => $groupTitle):
            $groupTypes = array_values(array_filter(ics_builtin_form_types(), function ($m) use ($groupKey) {
                return $m['group'] === $groupKey;
            }));
            if (!$groupTypes) continue; ?>
        <div class="ics-form-group card mb-2">
            <div class="card-header py-1 d-flex align-items-baseline gap-2 flex-wrap">
                <span class="ics-group-title"><?php echo e($groupTitle); ?></span>
                <?php if (isset($groupNotes[$groupKey])): ?><small class="text-body-secondary"><?php echo e($groupNotes[$groupKey]); ?></small><?php endif; ?>
            </div>
            <div class="card-body p-2">
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 row-cols-xxl-4 g-1">
                    <?php foreach ($groupTypes as $m):
                        $c = ics_builtin_color_classes($m['color']); ?>
                    <div class="col" data-form-search="<?php echo e($m['number'] . ' ' . $m['title'] . ' ' . $m['slug']); ?>">
                        <button type="button" class="btn btn-sm ics-pick w-100 text-start" data-new-form="<?php echo e($m['slug']); ?>"
                                title="<?php echo e('New ' . $m['number'] . ': ' . $m['desc']); ?>">
                            <span class="ics-pick-number<?php echo $c['text_class']; ?>"<?php echo $c['text_style']; ?>><?php echo e($m['number']); ?></span>
                            <span class="ics-pick-title"><?php echo e($m['title']); ?></span>
                            <?php if (!empty($m['legacy'])): ?><span class="badge text-bg-secondary ms-1">pre-2010</span><?php endif; ?>
                            <small class="ics-pick-desc d-block text-body-secondary text-truncate"><?php echo e($m['desc']); ?></small>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Phase 140: id="formTypeCardsRow" is where JS appends one card
             per active, in-scope custom ICS form type (assets/js/ics-forms.js
             loadCustomTypeCards()); the group stays hidden until it has one. -->
        <div class="ics-form-group mb-4" id="customFormGroup" style="display:none">
            <h6 class="ics-group-title text-body-secondary mb-2">Your agency's custom forms</h6>
            <div class="row g-2" id="formTypeCardsRow"></div>
        </div>

        <!-- Saved Forms Table -->
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Saved Forms</span>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0 small" for="hubFilterType">Filter:</label>
                    <select class="form-select form-select-sm" id="hubFilterType" style="width:180px">
                        <option value="">All Types</option>
                        <?php foreach (ics_builtin_form_types() as $m): ?>
                        <option value="<?php echo e($m['slug']); ?>"><?php echo e($m['number']); ?></option>
                        <?php endforeach; ?>
                        <option value="custom">Custom</option>
                    </select>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:90px">Type</th>
                                <th>Title</th>
                                <th style="width:80px">Incident</th>
                                <th style="width:70px">Status</th>
                                <th style="width:120px">Created By</th>
                                <th style="width:140px">Last Updated</th>
                                <th style="width:60px" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="savedFormsBody">
                            <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /hubSection -->

    <!-- ═══════════ EDITOR SECTION (hidden by default) ═══════════ -->
    <div id="editorSection" style="display:none">

        <!-- Editor toolbar -->
        <div class="editor-toolbar d-flex align-items-center justify-content-between mb-3 no-print">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-outline-secondary" id="btnBackToHub">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </button>
                <h6 class="mb-0" id="editorTitle">ICS Form</h6>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-info" id="btnExportXml" style="display:none">
                    <i class="bi bi-filetype-xml me-1"></i>Export XML
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="btnPrint">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button class="btn btn-sm btn-outline-primary" id="btnSave">
                    <i class="bi bi-save me-1"></i>Save Draft
                </button>
                <button class="btn btn-sm btn-success" id="btnFinalize">
                    <i class="bi bi-check-lg me-1"></i>Finalize
                </button>
                <!-- Shown only for a saved form this user may delete; the server
                     decides (api/ics-forms.php emits can_delete) and re-checks
                     on the POST. Soft delete — it goes to the wastebasket. -->
                <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteForm" style="display:none">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </div>
        </div>

        <!-- Metadata row -->
        <div class="row g-3 mb-3 no-print">
            <div class="col-md-4">
                <label class="form-label" for="formTitle">Form Title <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="formTitle"
                       placeholder="e.g., Shelter Deployment Message" tabindex="0">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="formStatus">Status</label>
                <select class="form-select form-select-sm" id="formStatus" disabled>
                    <option value="draft">Draft</option>
                    <option value="final">Final</option>
                    <option value="sent">Sent</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="linkIncidentSearch">Link to Incident (optional)</label>
                <div class="position-relative">
                    <div class="input-group input-group-sm incident-link-bar">
                        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                        <input type="text" class="form-control form-control-sm" id="linkIncidentSearch"
                               placeholder="Search by #, address, type, or description..." autocomplete="off">
                        <input type="hidden" id="linkIncidentId" value="">
                        <button class="btn btn-outline-secondary" type="button" id="btnClearIncidentLink" title="Clear link" style="display:none">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="incident-search-results d-none" id="incidentSearchResults"></div>
                </div>
            </div>
        </div>

        <!-- Dynamic form fields rendered here by JS -->
        <div class="card">
            <div class="card-body" id="formFieldsContainer">
                <p class="text-body-secondary">Loading form fields...</p>
            </div>
        </div>

    </div><!-- /editorSection -->

</div><!-- /container-fluid -->

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/ics-forms.js?v=<?php echo asset_v('assets/js/ics-forms.js'); ?>"></script>

</body>
</html>
