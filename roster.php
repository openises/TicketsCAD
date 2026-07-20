<?php
/**
 * NewUI v4.0 - Personnel Roster
 *
 * View and manage organization members. Two-column layout:
 *   Left:  Searchable/filterable member table
 *   Right: Detail panel / edit form
 *
 * Data loaded client-side via api/members.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';

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
require_once __DIR__ . '/inc/rbac.php';
rbac_require_screen('screen.roster');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
require_once __DIR__ . '/inc/rbac.php';
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();

// Roster page passes the time-entry approval permission to JS so the
// Time Log card can show / hide approve/reject controls per-row. Read
// once at page load — rbac_can() is per-request cached anyway.
require_once __DIR__ . '/inc/rbac.php';
$canApproveTime = rbac_can('time_entry.approve') ? 1 : 0;
// GH #55 follow-on (Billy/K9OH) — bulk roster removal. Its own permission
// (NOT action.manage_members): Eric wants bulk removal narrowly held, so the
// multi-select + "Delete selected" controls only appear for users granted
// action.bulk_delete_members (Super Admin by default). is_admin() is the
// super-admin escape hatch. api/members.php enforces the same gate server-side.
$canBulkDeleteMembers = (rbac_can('action.bulk_delete_members') || is_admin()) ? 1 : 0;
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('roster.title', 'Personnel Roster')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/roster.css?v=<?php echo asset_v('assets/css/roster.css'); ?>">

    <!-- Print CSS -->
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <?php $personnel_active = 'roster'; include_once __DIR__ . '/inc/personnel-nav.php'; ?>

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-person-badge text-primary me-2"></i><?php echo e(t('roster.title', 'Personnel Roster')); ?>
            <span class="badge bg-secondary ms-2" id="memberCount" style="font-size: 0.65rem;">0</span>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRosterCols" title="Customize visible columns">
                <i class="bi bi-layout-three-columns"></i>
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="btnNewMember">
                <i class="bi bi-person-plus me-1"></i><?php echo e(t('roster.btn.new_member', 'New Member')); ?>
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i><?php echo e(t('roster.btn.print', 'Print')); ?>
            </button>
        </div>
    </div>

    <!-- Search & Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <label for="searchInput" class="visually-hidden"><?php echo e(t('roster.search_placeholder', 'Search by name, callsign, phone, email...')); ?></label>
                        <input type="text" class="form-control form-control-sm" id="searchInput"
                               placeholder="<?php echo e(t('roster.search_placeholder', 'Search by name, callsign, phone, email...')); ?>" autocomplete="off">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btnClearSearch" aria-label="Clear search">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="d-flex flex-wrap gap-1">
                        <span class="text-body-secondary small me-1 align-self-center"><?php echo e(t('roster.label.status_prefix', 'Status:')); ?></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                data-filter="status" data-value="all">All</button>
                        <span id="statusFilters"></span>

                        <span class="text-body-secondary small ms-2 me-1 align-self-center">Team:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                data-filter="team" data-value="all">All</button>
                        <span id="teamFilters"></span>

                        <span class="text-body-secondary small ms-2 me-1 align-self-center">Type:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                data-filter="type" data-value="all">All</button>
                        <span id="typeFilters"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading roster...</div>
    </div>

    <!-- Main content (hidden until data loads) -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Member Table ═══════════ -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-0">
                    <?php if ($canBulkDeleteMembers): ?>
                    <!-- GH #55 follow-on (Billy/K9OH) — bulk-actions bar. Hidden
                         until at least one member is selected via the trailing
                         checkbox column. -->
                    <div id="rosterBulkBar" class="d-none align-items-center gap-2 px-2 py-2 border-bottom bg-body-tertiary small">
                        <span id="rosterBulkCount" class="fw-semibold">0 selected</span>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnRosterBulkDelete">
                            <i class="bi bi-trash me-1"></i>Delete selected
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRosterBulkClear">Clear</button>
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="rosterTable" role="table">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="name"     data-col-id="name"     data-col-label="Name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="callsign" data-col-id="callsign" data-col-label="Callsign">Callsign <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="type"     data-col-id="type"     data-col-label="Type">Type <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="status"   data-col-id="status"   data-col-label="Status">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="team"     data-col-id="team"     data-col-label="Team">Team <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th                                       data-col-id="phone"    data-col-label="Phone">Phone</th>
                                    <th class="sortable" data-sort="available" data-col-id="avail"   data-col-label="Availability">Avail <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <?php if ($canBulkDeleteMembers): ?>
                                    <!-- Trailing select column (no data-col-id, so ScreenPrefs'
                                         nth-child column-customization is unaffected). -->
                                    <th class="roster-sel-col text-center" style="width:38px" title="Select for bulk actions">
                                        <input type="checkbox" id="rosterSelectAll" class="form-check-input" aria-label="Select all visible members">
                                    </th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="rosterBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center text-body-secondary py-3 small d-none" id="noResults">
                        No members match your search or filters.
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ RIGHT COLUMN: Detail / Edit Panel ═══════════ -->
        <div class="col-lg-5">
            <div class="sticky-top" style="top: 100px;">

                <!-- Empty state -->
                <div class="card" id="detailEmpty">
                    <div class="card-body text-center text-body-secondary py-5">
                        <i class="bi bi-person-badge" style="font-size: 3rem;"></i>
                        <p class="mt-2 mb-0">Select a member from the table to view details.</p>
                    </div>
                </div>

                <!-- Detail view (hidden by default) -->
                <div class="d-none" id="detailView">
                    <div class="card mb-3" id="detailHeader">
                        <div class="card-body py-2">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h5 class="mb-0" id="detailName">—</h5>
                                    <div class="small text-body-secondary" id="detailTitle">—</div>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditMember" title="Edit" aria-label="Edit member">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteMember" title="Delete" aria-label="Delete member">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-1" id="detailBadges"></div>
                            <div class="d-none" id="detailLinkedUser"></div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailContact" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-telephone me-2"></i>
                            <span class="fw-semibold small">Contact</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailContact">
                            <div class="card-body py-2 small" id="detailContact"></div>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailAddress" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-house me-2"></i>
                            <span class="fw-semibold small">Address</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailAddress">
                            <div class="card-body py-2 small" id="detailAddress"></div>
                        </div>
                    </div>

                    <!-- Communications Licenses -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailLicenses" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-broadcast me-2"></i>
                            <span class="fw-semibold small">Communications Licenses</span>
                            <span class="badge bg-info ms-auto" id="licenseCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailLicenses">
                            <div class="card-body py-2 small" id="detailLicenses"></div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailEmergency" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span class="fw-semibold small">Emergency Contact</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseDetailEmergency">
                            <div class="card-body py-2 small" id="detailEmergency"></div>
                        </div>
                    </div>

                    <!-- Medical & Notes -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailMedical" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-heart-pulse me-2"></i>
                            <span class="fw-semibold small">Medical &amp; Notes</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseDetailMedical">
                            <div class="card-body py-2 small" id="detailMedical"></div>
                        </div>
                    </div>

                    <!-- Time Log (item #21) -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailTimeLog" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-clock-history me-2"></i>
                            <span class="fw-semibold small">Time Log</span>
                            <span class="badge bg-secondary ms-auto" id="timeLogTotal">0&nbsp;h</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseDetailTimeLog">
                            <div class="card-body py-2 small">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="small text-body-secondary" id="timeLogSummary"></div>
                                    <button type="button" class="btn btn-sm btn-success"
                                            id="btnLogTime" data-bs-toggle="modal"
                                            data-bs-target="#logTimeModal">
                                        <i class="bi bi-plus-lg me-1"></i>Log Time
                                    </button>
                                </div>
                                <div id="detailTimeLog"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Training Records -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailTraining" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-mortarboard me-2"></i>
                            <span class="fw-semibold small">Training Records</span>
                            <span class="badge bg-warning text-dark ms-auto" id="trainingCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailTraining">
                            <div class="card-body py-2 small" id="detailTraining"></div>
                        </div>
                    </div>

                    <!-- Certifications -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailCerts" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-award me-2"></i>
                            <span class="fw-semibold small">Certifications</span>
                            <span class="badge bg-primary ms-auto" id="certCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailCerts">
                            <div class="card-body py-2 small" id="detailCerts"></div>
                        </div>
                    </div>

                    <!-- ICS Qualifications -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailIcs" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-shield-check me-2"></i>
                            <span class="fw-semibold small">ICS Qualifications</span>
                            <span class="badge bg-info ms-auto" id="icsQualCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailIcs">
                            <div class="card-body py-2 small" id="detailIcsQuals"></div>
                        </div>
                    </div>

                    <!-- Team Memberships -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailTeams" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-people me-2"></i>
                            <span class="fw-semibold small">Team Memberships</span>
                            <span class="badge bg-secondary ms-auto" id="teamMembershipCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailTeams">
                            <div class="card-body py-2 small" id="detailTeamMemberships"></div>
                        </div>
                    </div>

                    <!-- Organizations -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailOrgs" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-building me-2"></i>
                            <span class="fw-semibold small">Organizations</span>
                            <span class="badge bg-info ms-auto" id="orgCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailOrgs">
                            <div class="card-body py-2 small" id="detailOrganizations"></div>
                        </div>
                    </div>

                    <!-- Comm / Location IDs -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailCommIds" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-broadcast me-2"></i>
                            <span class="fw-semibold small">Comm / Location IDs</span>
                            <span class="badge bg-warning text-dark ms-auto" id="commIdCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailCommIds">
                            <div class="card-body py-2 small" id="detailCommIds"></div>
                        </div>
                    </div>

                    <!-- Phase 41 — OwnTracks tracking tokens -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailOtTokens" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-shield-lock me-2"></i>
                            <span class="fw-semibold small">OwnTracks Tracking Tokens</span>
                            <span class="badge bg-info text-dark ms-auto" id="otTokenCount">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseDetailOtTokens">
                            <div class="card-body py-2 small" id="detailOtTokens"></div>
                        </div>
                    </div>

                    <!-- Phase 51 — OwnTracks per-member overrides -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailOtOverrides" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-sliders me-2"></i>
                            <span class="fw-semibold small">OwnTracks Overrides for this member</span>
                            <span class="badge bg-secondary ms-auto" id="otOverridesBadge">inherit</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseDetailOtOverrides">
                            <div class="card-body py-2 small" id="detailOtOverrides">
                                <div class="text-body-secondary fst-italic">Open to load this member's OwnTracks overrides.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicles -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailVehicles" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-truck me-2"></i>
                            <span class="fw-semibold small">Vehicles</span>
                            <span class="badge bg-secondary ms-auto" id="vehicleCountBadge">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailVehicles">
                            <div class="card-body py-2 small" id="detailVehicles"></div>
                        </div>
                    </div>

                    <!-- Equipment -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseDetailEquipment" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                            <i class="bi bi-box-seam me-2"></i>
                            <span class="fw-semibold small">Equipment</span>
                            <span class="badge bg-secondary ms-auto" id="equipmentCountBadge">0</span>
                            <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseDetailEquipment">
                            <div class="card-body py-2 small" id="detailEquipment"></div>
                        </div>
                    </div>
                </div>

                <!-- Edit form (hidden by default) -->
                <div class="d-none" id="editView">
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between py-1">
                            <span class="fw-semibold small" id="editFormTitle">
                                <i class="bi bi-pencil-square me-1"></i>Edit Member
                            </span>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary" id="btnSaveMember">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelEdit">
                                    <i class="bi bi-x-lg me-1"></i>Cancel
                                </button>
                            </div>
                        </div>
                        <div class="card-body py-2" id="editFormBody">
                            <input type="hidden" id="editMemberId" value="">
                            <input type="hidden" id="editPhotoFileId" value="">

                            <!-- Photo (PRE-RELEASE-FIXES #19) -->
                            <div class="mb-2 d-flex align-items-center gap-2">
                                <img id="editPhotoPreview" class="d-none"
                                     style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:1px solid var(--bs-border-color);"
                                     alt="Member photo">
                                <div id="editPhotoControls" class="d-none">
                                    <label class="btn btn-sm btn-outline-secondary mb-0">
                                        <i class="bi bi-camera me-1"></i>Photo
                                        <input type="file" id="editPhotoInput" class="d-none"
                                               accept="image/jpeg,image/png,image/webp,image/gif">
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            id="editPhotoRemove" title="Remove photo">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                    <div class="form-text small mt-0">JPEG/PNG/WebP/GIF, max 2&nbsp;MB</div>
                                </div>
                            </div>

                            <!-- Personal Info section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1 cursor-pointer"
                                     data-bs-toggle="collapse" data-bs-target="#collapseEditPersonal">
                                    <i class="bi bi-person me-1"></i>Personal Info
                                    <i class="bi bi-chevron-down collapse-icon float-end"></i>
                                </div>
                                <div class="collapse show" id="collapseEditPersonal">
                                    <div class="row g-2">
                                        <div class="col-5">
                                            <label class="form-label form-label-sm mb-0">First Name *</label>
                                            <input type="text" class="form-control form-control-sm" id="editFirstName" required>
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Last Name *</label>
                                            <input type="text" class="form-control form-control-sm" id="editLastName" required>
                                        </div>
                                        <div class="col-3">
                                            <label class="form-label form-label-sm mb-0">Middle</label>
                                            <input type="text" class="form-control form-control-sm" id="editMiddleName">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Title</label>
                                            <input type="text" class="form-control form-control-sm" id="editTitle">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">DOB</label>
                                            <input type="date" class="form-control form-control-sm" id="editDOB">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Callsigns (multi-callsign, Google Contacts style) -->
                            <div class="mb-2">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="fw-semibold small text-body-secondary">
                                        <i class="bi bi-broadcast me-1"></i>Callsigns
                                    </span>
                                    <span class="badge bg-info ms-2" id="callsignCount">0</span>
                                    <button type="button" class="btn btn-xs btn-outline-primary ms-auto" id="btnAddCallsignRow"
                                            title="Add a callsign" aria-label="Add a callsign">
                                        <i class="bi bi-plus-lg me-1"></i>Add
                                    </button>
                                </div>
                                <div id="callsignsList"></div>
                                <div id="callsignAddRow" class="d-none">
                                    <div class="row g-1 align-items-end border-top pt-1">
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Callsign</label>
                                            <input type="text" class="form-control form-control-sm" id="addCallsignInput"
                                                   placeholder="e.g. W1AW" style="text-transform:uppercase;">
                                        </div>
                                        <div class="col-3">
                                            <label class="form-label form-label-sm mb-0">Type</label>
                                            <select class="form-select form-select-sm" id="addCallsignType">
                                                <option value="amateur">Amateur</option>
                                                <option value="gmrs">GMRS</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-5 d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-info flex-grow-1" id="btnCallsignLookup"
                                                    title="Look up via FCC database">
                                                <i class="bi bi-search me-1"></i>Lookup
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary" id="btnSaveCallsign"
                                                    title="Save callsign" aria-label="Save callsign">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelCallsign"
                                                    title="Cancel" aria-label="Cancel adding callsign">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div id="callsignResult" class="d-none mt-1"></div>
                                </div>
                            </div>

                            <!-- FCC License Info (shown after callsign lookup) -->
                            <div id="fccLicensePanel" class="d-none mb-2">
                                <div class="card border-info">
                                    <div class="card-header bg-info bg-opacity-10 py-1 d-flex align-items-center">
                                        <i class="bi bi-broadcast me-1"></i>
                                        <span class="fw-semibold small">FCC License Info</span>
                                        <button type="button" class="btn-close btn-close-sm ms-auto" id="btnCloseFccPanel"></button>
                                    </div>
                                    <div class="card-body py-2 small" id="fccLicenseBody">
                                        <!-- Populated by JS -->
                                    </div>
                                    <div class="card-footer py-1 text-end" id="fccLicenseFooter">
                                        <button type="button" class="btn btn-sm btn-outline-success" id="btnApplyFccData">
                                            <i class="bi bi-check-lg me-1"></i>Apply to Form
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="btnGmrsLookup"
                                                title="Search GMRS licenses by name + zip">
                                            <i class="bi bi-broadcast-pin me-1"></i>GMRS Lookup
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- GMRS Results (shown after GMRS lookup) -->
                            <div id="gmrsResultsPanel" class="d-none mb-2">
                                <div class="card border-warning">
                                    <div class="card-header bg-warning bg-opacity-10 py-1 d-flex align-items-center">
                                        <i class="bi bi-broadcast-pin me-1"></i>
                                        <span class="fw-semibold small">GMRS License Results</span>
                                        <button type="button" class="btn-close btn-close-sm ms-auto" id="btnCloseGmrsPanel"></button>
                                    </div>
                                    <div class="card-body py-2 small" id="gmrsResultsBody">
                                        <!-- Populated by JS -->
                                    </div>
                                </div>
                            </div>

                            <!-- Contact section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1 cursor-pointer"
                                     data-bs-toggle="collapse" data-bs-target="#collapseEditContact">
                                    <i class="bi bi-telephone me-1"></i>Contact
                                    <i class="bi bi-chevron-down collapse-icon float-end"></i>
                                </div>
                                <div class="collapse show" id="collapseEditContact">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label class="form-label form-label-sm mb-0">Email</label>
                                            <input type="email" class="form-control form-control-sm" id="editEmail">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Home Phone</label>
                                            <input type="text" class="form-control form-control-sm" id="editPhoneHome">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Work Phone</label>
                                            <input type="text" class="form-control form-control-sm" id="editPhoneWork">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Cell Phone</label>
                                            <input type="text" class="form-control form-control-sm" id="editPhoneCell">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Address section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1 cursor-pointer"
                                     data-bs-toggle="collapse" data-bs-target="#collapseEditAddress">
                                    <i class="bi bi-house me-1"></i>Address
                                    <i class="bi bi-chevron-down collapse-icon float-end"></i>
                                </div>
                                <div class="collapse show" id="collapseEditAddress">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label class="form-label form-label-sm mb-0"><?php echo e(t('field.street', 'Street')); ?></label>
                                            <input type="text" class="form-control form-control-sm" id="editStreet">
                                        </div>
                                        <div class="col-5">
                                            <label class="form-label form-label-sm mb-0"><?php echo e(t('form.city', 'City')); ?></label>
                                            <input type="text" class="form-control form-control-sm" id="editCity">
                                        </div>
                                        <div class="col-3">
                                            <label class="form-label form-label-sm mb-0"><?php echo e(t('form.state', 'State')); ?></label>
                                            <select class="form-select form-select-sm" id="editState"></select>
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0"><?php echo e(t('form.zip', 'ZIP')); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control form-control-sm" id="editZip"
                                                       placeholder="e.g. 55401" maxlength="10">
                                                <button class="btn btn-outline-info" type="button" id="btnZipLookup"
                                                        title="Auto-fill city &amp; state from ZIP code" aria-label="Look up city and state from ZIP code">
                                                    <i class="bi bi-geo-alt"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <!-- Phase 99i (Billy beta 2026-06-29) — county
                                             field on personnel. Useful for ARES + similar
                                             agencies that organize by county. Free text
                                             today; future work can swap to a state-driven
                                             dropdown (Billy has the JSON for U.S. counties). -->
                                        <div class="col-12">
                                            <label class="form-label form-label-sm mb-0">County</label>
                                            <input type="text" class="form-control form-control-sm" id="editCounty"
                                                   placeholder="e.g. Hennepin" maxlength="64">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Organization section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1 cursor-pointer"
                                     data-bs-toggle="collapse" data-bs-target="#collapseEditOrg">
                                    <i class="bi bi-building me-1"></i>Organization
                                    <i class="bi bi-chevron-down collapse-icon float-end"></i>
                                </div>
                                <div class="collapse show" id="collapseEditOrg">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0">Type</label>
                                            <select class="form-select form-select-sm" id="editType"></select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0">Status</label>
                                            <select class="form-select form-select-sm" id="editStatus"></select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0">Team</label>
                                            <select class="form-select form-select-sm" id="editTeam"></select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0">Available</label>
                                            <select class="form-select form-select-sm" id="editAvailable">
                                                <option value="Yes">Yes</option>
                                                <option value="No">No</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0">Join Date</label>
                                            <input type="date" class="form-control form-control-sm" id="editJoinDate">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0">Membership Due</label>
                                            <input type="date" class="form-control form-control-sm" id="editMembershipDue">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Emergency Contact section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1 cursor-pointer"
                                     data-bs-toggle="collapse" data-bs-target="#collapseEditEmergency">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Emergency Contact
                                    <i class="bi bi-chevron-down collapse-icon float-end"></i>
                                </div>
                                <div class="collapse show" id="collapseEditEmergency">
                                    <div class="row g-2">
                                        <div class="col-5">
                                            <label class="form-label form-label-sm mb-0">Name</label>
                                            <input type="text" class="form-control form-control-sm" id="editEmergencyContact">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label form-label-sm mb-0">Phone</label>
                                            <input type="text" class="form-control form-control-sm" id="editEmergencyPhone">
                                        </div>
                                        <div class="col-3">
                                            <label class="form-label form-label-sm mb-0">Relation</label>
                                            <input type="text" class="form-control form-control-sm" id="editEmergencyRelation">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Medical & Notes section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1 cursor-pointer"
                                     data-bs-toggle="collapse" data-bs-target="#collapseEditMedical">
                                    <i class="bi bi-heart-pulse me-1"></i>Medical &amp; Notes
                                    <i class="bi bi-chevron-down collapse-icon float-end"></i>
                                </div>
                                <div class="collapse show" id="collapseEditMedical">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label class="form-label form-label-sm mb-0">Medical Info</label>
                                            <textarea class="form-control form-control-sm" id="editMedicalInfo" rows="2"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label form-label-sm mb-0">Notes</label>
                                            <textarea class="form-control form-control-sm" id="editNotes" rows="2"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<input type="hidden" id="canApproveTime" value="<?php echo $canApproveTime; ?>">
<input type="hidden" id="canBulkDeleteMembers" value="<?php echo $canBulkDeleteMembers; ?>">

<?php if ($canBulkDeleteMembers): ?>
<!-- GH #55 follow-on (Billy/K9OH) — bulk roster removal confirmation. -->
<div class="modal fade" id="rosterBulkDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Delete selected members?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">You are about to remove <strong id="rosterBulkModalCount">0</strong> member(s) from the roster.</p>
                <p class="small text-body-secondary mb-0">They move to the wastebasket (soft delete) and can be restored, unless this install predates the wastebasket. This is logged in the audit trail.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnRosterBulkDeleteConfirm">
                    <i class="bi bi-trash me-1"></i>Delete <span id="rosterBulkModalCount2">0</span> member(s)
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Log Time Modal (item #21) -->
<div class="modal fade" id="logTimeModal" tabindex="-1" aria-labelledby="logTimeModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="logTimeModalTitle"><i class="bi bi-clock me-1"></i>Log Time</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <input type="hidden" id="logTimeMemberId">
                <input type="hidden" id="logTimeEntryId" value="">
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label form-label-sm mb-0">Start *</label>
                        <input type="datetime-local" class="form-control form-control-sm" id="logTimeStart" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label form-label-sm mb-0">End *</label>
                        <input type="datetime-local" class="form-control form-control-sm" id="logTimeEnd" required>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Activity *</label>
                    <select class="form-select form-select-sm" id="logTimeActivity" required></select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Incident (optional)</label>
                    <input type="number" min="1" class="form-control form-control-sm" id="logTimeIncident"
                           placeholder="Incident # if applicable">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Notes</label>
                    <textarea class="form-control form-control-sm" id="logTimeNotes" rows="2"
                              placeholder="Optional — what did you do?"></textarea>
                </div>
                <div class="text-body-secondary small mb-0">
                    <i class="bi bi-info-circle me-1"></i>Self-reported entries can be edited until an admin approves them.
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSaveTimeEntry">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Organization Membership Modal -->
<div class="modal fade" id="editOrgModal" tabindex="-1" aria-labelledby="editOrgModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="editOrgModalTitle"><i class="bi bi-building me-1"></i>Edit Membership</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <input type="hidden" id="editOrgMemberId">
                <input type="hidden" id="editOrgOrgId">
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0 fw-semibold" id="editOrgName"></label>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Type / Rank</label>
                    <select class="form-select form-select-sm" id="editOrgType"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Status</label>
                    <select class="form-select form-select-sm" id="editOrgStatus">
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Role</label>
                    <select class="form-select form-select-sm" id="editOrgRole">
                        <option value="">— None —</option>
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="member">Member</option>
                        <option value="viewer">Viewer</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Join Date</label>
                    <input type="date" class="form-control form-control-sm" id="editOrgJoinDate">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Notes</label>
                    <textarea class="form-control form-control-sm" id="editOrgNotes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSaveOrgMembership">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Communication Identifier Modal -->
<div class="modal fade" id="editCommModal" tabindex="-1" aria-labelledby="commModalTitleLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="commModalTitleLabel"><i class="bi bi-broadcast me-1"></i><span id="commModalTitle">Add Identifier</span></h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <input type="hidden" id="commModalId">
                <input type="hidden" id="commModalMemberId">
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label form-label-sm mb-0">Mode</label>
                        <select class="form-select form-select-sm" id="commModalMode"></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label form-label-sm mb-0">Label <small class="text-body-secondary">(optional)</small></label>
                        <input type="text" class="form-control form-control-sm" id="commModalLabel" placeholder="e.g. Mobile HT">
                    </div>
                </div>
                <div id="commModalFields" class="mb-2">
                    <!-- Dynamic fields rendered by JS based on mode's fields_json -->
                </div>
                <div id="commModalLookupArea" class="mb-2"></div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Notes</label>
                    <textarea class="form-control form-control-sm" id="commModalNotes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSaveCommModal">
                    <i class="bi bi-check-lg me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Issue #25 (2026-07-02 rev): FCC-overwrite confirmation modal.
     Shown when applyFccDataToForm() finds one or more fields where the
     FCC lookup value DIFFERS from the current value. Empty fields fill
     silently; matching fields skip silently; only the ambiguous cases
     land here. Per-field checkboxes let the operator pick which to
     overwrite instead of an all-or-nothing prompt. -->
<div class="modal fade" id="fccOverwriteModal" tabindex="-1"
     aria-labelledby="fccOverwriteModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="fccOverwriteModalTitle">
                    <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                    Overwrite fields with FCC data?
                </h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body py-2">
                <p class="small text-body-secondary mb-2">
                    The FCC lookup returned different values than what's currently in the form.
                    Uncheck any field you want to keep unchanged.
                    <span id="fccOverwriteEmptyNote" class="d-none">
                        <br>Empty fields will be filled automatically.
                    </span>
                </p>
                <div id="fccOverwriteRows" class="small"></div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnFccOverwriteNone">Uncheck all</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnFccOverwriteAll">Check all</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnFccOverwriteApply">
                    <i class="bi bi-check-lg me-1"></i>Apply selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Print date helper -->
<script>window.addEventListener('beforeprint', function () { document.body.setAttribute('data-print-date', new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString()); });</script>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo asset_v('assets/js/screen-prefs.js'); ?>"></script>
<script src="assets/js/states-select.js?v=<?php echo asset_v('assets/js/states-select.js'); ?>"></script>
<script src="assets/js/owntracks-provision.js?v=<?php echo asset_v('assets/js/owntracks-provision.js'); ?>"></script>
<script src="assets/js/roster.js?v=<?php echo asset_v('assets/js/roster.js'); ?>"></script>
<script>
    // Phase 17 follow-on (2026-06-11): roster column customization.
    document.addEventListener('DOMContentLoaded', function () {
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            window.ScreenPrefs.applyToTable('roster', '#rosterTable', { openerSelector: '#btnRosterCols' });
        }
    });
</script>

</body>
</html>
