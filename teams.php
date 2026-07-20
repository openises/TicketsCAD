<?php
/**
 * NewUI v4.0 - Teams Management
 *
 * Two-column layout:
 *   Left:  Team list with member counts
 *   Right: Team detail panel / edit form with member roster
 *
 * Data loaded client-side via api/teams.php
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
rbac_require_screen('screen.teams');
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
    <title><?php echo e(t('page.teams', 'Teams')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/teams.css?v=<?php echo asset_v('assets/css/teams.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <?php $personnel_active = 'teams'; include_once __DIR__ . '/inc/personnel-nav.php'; ?>

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-people text-primary me-2"></i><?php echo e(t('page.teams', 'Teams')); ?>
            <span class="badge bg-secondary ms-2" id="teamCount" style="font-size: 0.65rem;">0</span>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-primary" id="btnNewTeam">
                <i class="bi bi-plus-circle me-1"></i>New Team
            </button>
            <a href="roster.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-person-badge me-1"></i>Roster
            </a>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Two-column layout -->
    <div class="row g-3" id="mainContent" style="display: none;">

        <!-- ═══ LEFT COLUMN: Team List ═══ -->
        <div class="col-lg-5 col-xl-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent py-2 d-flex align-items-center justify-content-between">
                    <span class="small fw-semibold">All Teams</span>
                    <div class="input-group input-group-sm" style="width: 200px;">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm" id="searchInput"
                               placeholder="Search teams..." autocomplete="off">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: calc(100vh - 220px); overflow-y: auto;">
                        <table class="table table-hover table-sm mb-0 align-middle" id="teamsTable">
                            <thead class="sticky-top">
                                <tr>
                                    <th class="ps-3" style="cursor:pointer;" data-sort="name">Name <i class="bi bi-chevron-expand text-muted"></i></th>
                                    <th style="cursor:pointer;" data-sort="type">Type</th>
                                    <th class="text-center" style="cursor:pointer;" data-sort="members">Members</th>
                                    <th style="cursor:pointer;" data-sort="nims">NIMS</th>
                                </tr>
                            </thead>
                            <tbody id="teamsBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ RIGHT COLUMN: Detail / Edit Panel ═══ -->
        <div class="col-lg-7 col-xl-8">

            <!-- Empty state -->
            <div id="emptyState" class="card border-0 shadow-sm">
                <div class="card-body text-center text-body-secondary py-5">
                    <i class="bi bi-people display-4 mb-3 d-block"></i>
                    <p class="mb-0">Select a team to view details, or create a new one.</p>
                </div>
            </div>

            <!-- Detail View -->
            <div id="detailView" style="display: none;">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-transparent py-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold" id="detailName">--</span>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnEditTeam" title="Edit Team">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteTeam" title="Delete Team">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body py-2">
                        <div class="row g-2 small">
                            <div class="col-md-6">
                                <div class="text-body-secondary mb-1">Description</div>
                                <div id="detailDesc">--</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary mb-1">Type</div>
                                <div id="detailType">--</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary mb-1">Formed</div>
                                <div id="detailFormed">--</div>
                            </div>
                        </div>
                        <!-- NIMS Info -->
                        <div class="row g-2 small mt-1" id="nimsRow" style="display: none;">
                            <div class="col-md-4">
                                <div class="text-body-secondary mb-1">NIMS Resource Type</div>
                                <div id="detailNimsType">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary mb-1">NIMS Typing Level</div>
                                <div id="detailNimsLevel">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary mb-1">RTLT Code</div>
                                <div id="detailRtlt">--</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team Members -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-transparent py-2 d-flex align-items-center justify-content-between">
                        <span class="small fw-semibold">
                            <i class="bi bi-people-fill me-1"></i>Team Members
                            <span class="badge bg-secondary ms-1" id="memberCount">0</span>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnAddMember">
                            <i class="bi bi-person-plus me-1"></i>Add
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-hover table-sm mb-0 align-middle">
                                <thead class="sticky-top">
                                    <tr>
                                        <th class="ps-3">Name</th>
                                        <th>Callsign</th>
                                        <th>Role</th>
                                        <th>ICS Position</th>
                                        <th>Assigned</th>
                                        <th class="text-center" style="width: 60px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="membersBody">
                                </tbody>
                            </table>
                        </div>
                        <div id="noMembers" class="text-center text-body-secondary py-3 d-none">
                            <small>No members assigned to this team yet.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Form -->
            <div id="editView" style="display: none;">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent py-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold" id="editTitle">New Team</span>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-primary" id="btnSaveTeam">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelEdit">
                                Cancel
                            </button>
                        </div>
                    </div>
                    <div class="card-body py-2">
                        <input type="hidden" id="editId" value="0">
                        <div class="row g-2">
                            <div class="col-md-8">
                                <label class="form-label form-label-sm mb-0">Team Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="editName" required>
                                <div class="invalid-feedback">Team name is required.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-0">Type</label>
                                <select class="form-select form-select-sm" id="editType">
                                    <option value="">-- Select --</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label form-label-sm mb-0">Description / Mission</label>
                                <textarea class="form-control form-control-sm" id="editDescription" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label form-label-sm mb-0">Team Leader</label>
                                <select class="form-select form-select-sm" id="editLeader">
                                    <option value="">-- None --</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label form-label-sm mb-0">Deputy Leader</label>
                                <select class="form-select form-select-sm" id="editDeputy">
                                    <option value="">-- None --</option>
                                </select>
                            </div>
                        </div>

                        <!-- NIMS Section (collapsible) -->
                        <div class="mt-2">
                            <button class="btn btn-sm btn-link text-decoration-none p-0" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#nimsCollapse">
                                <i class="bi bi-chevron-right me-1"></i>NIMS / Resource Typing
                            </button>
                            <div class="collapse mt-2" id="nimsCollapse">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label form-label-sm mb-0">NIMS Resource Type</label>
                                        <input type="text" class="form-control form-control-sm" id="editNimsType"
                                               placeholder="e.g. Communications Unit" list="nimsTypeList"
                                               autocomplete="off">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label form-label-sm mb-0">Typing Level (1-4)</label>
                                        <select class="form-select form-select-sm" id="editNimsLevel">
                                            <option value="">-- N/A --</option>
                                            <option value="1">Type 1 (Most Capable)</option>
                                            <option value="2">Type 2</option>
                                            <option value="3">Type 3</option>
                                            <option value="4">Type 4 (Least Capable)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label form-label-sm mb-0">RTLT Code</label>
                                        <input type="text" class="form-control form-control-sm" id="editRtlt"
                                               placeholder="FEMA RTLT code">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /right column -->
    </div><!-- /row -->
</div><!-- /container -->

<!-- NIMS Resource Type datalist (autocomplete with free text entry) -->
<datalist id="nimsTypeList">
    <option value="Communications Unit">
    <option value="Communications Unit Leader">
    <option value="Communications Technician">
    <option value="Emergency Medical Services Unit">
    <option value="Engine">
    <option value="Fire Crew">
    <option value="Ground Search and Rescue Team">
    <option value="Hazardous Materials Entry Team">
    <option value="Incident Management Team">
    <option value="Mass Care Unit">
    <option value="Medical Task Force">
    <option value="Mobile Communications Unit">
    <option value="Radio Operator">
    <option value="Search and Rescue Team">
    <option value="Shelter Management Team">
    <option value="Swift Water/Flood Search and Rescue Team">
    <option value="Technical Search and Rescue Team">
    <option value="Urban Search and Rescue Team">
    <option value="Volunteer Reception Center Team">
    <option value="Water Rescue Team">
</datalist>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Add Team Member</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Member</label>
                    <select class="form-select form-select-sm" id="modalMember">
                        <option value="">-- Select --</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Role</label>
                    <select class="form-select form-select-sm" id="modalRole">
                        <option value="Member">Member</option>
                        <option value="Leader">Leader</option>
                        <option value="Deputy">Deputy</option>
                        <option value="Observer">Observer</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">ICS Position</label>
                    <select class="form-select form-select-sm" id="modalPosition">
                        <option value="">-- None --</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnConfirmAdd">Add</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Member Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Edit Member Role</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <input type="hidden" id="editRoleAssignmentId" value="0">
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Role</label>
                    <select class="form-select form-select-sm" id="editRoleRole">
                        <option value="Member">Member</option>
                        <option value="Leader">Leader</option>
                        <option value="Deputy">Deputy</option>
                        <option value="Observer">Observer</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">ICS Position</label>
                    <select class="form-select form-select-sm" id="editRolePosition">
                        <option value="">-- None --</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm mb-0">Notes</label>
                    <input type="text" class="form-control form-control-sm" id="editRoleNotes">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnConfirmRoleEdit">Save</button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo $csrf; ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/js/teams.js?v=<?php echo asset_v('assets/js/teams.js'); ?>"></script>

</body>
</html>
