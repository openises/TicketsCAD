<?php
/**
 * NewUI v4.0 - Scheduling & Shift Management
 *
 * Two-tab layout:
 *   1. Shifts — weekly calendar grid with template/role/slot management
 *   2. Events — drill/exercise/deployment/meeting management with participant tracking
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
rbac_require_screen('screen.scheduling');
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
    <title><?php echo e(t('page.scheduling', 'Scheduling')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/scheduling.css?v=<?php echo asset_v('assets/css/scheduling.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div class="container-fluid p-3">

    <?php $personnel_active = 'scheduling'; include_once __DIR__ . '/inc/personnel-nav.php'; ?>

    <!-- Page title + tabs -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-calendar3 text-primary me-2"></i><?php echo e(t('page.scheduling', 'Scheduling')); ?>
        </h5>
    </div>

    <!-- Tab nav -->
    <ul class="nav nav-tabs nav-tabs-sm mb-3" id="schedTabs">
        <li class="nav-item">
            <button class="nav-link active small py-1 px-3" data-bs-toggle="tab" data-bs-target="#tabShifts">
                <i class="bi bi-clock me-1"></i>Shifts
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link small py-1 px-3" data-bs-toggle="tab" data-bs-target="#tabEvents">
                <i class="bi bi-calendar-event me-1"></i>Events
                <span class="badge bg-primary ms-1" id="upcomingBadge" style="display:none">0</span>
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ═══ SHIFTS TAB ═══════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="tabShifts">
            <div class="row g-3">

                <!-- Left: Template picker + week navigation -->
                <div class="col-lg-3">
                    <!-- Template selector -->
                    <div class="card mb-3">
                        <div class="card-header py-1 d-flex align-items-center">
                            <i class="bi bi-collection me-2"></i>
                            <span class="fw-semibold small">Shift Templates</span>
                            <button class="btn btn-sm btn-outline-primary ms-auto py-0 px-2" id="btnNewTemplate" title="New Template">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                        <div class="card-body p-0" id="templateList">
                            <div class="text-center text-body-secondary py-3 small">
                                <div class="spinner-border spinner-border-sm me-1"></div>Loading...
                            </div>
                        </div>
                    </div>

                    <!-- Week navigation (only when template selected) -->
                    <div class="card mb-3" id="weekNavCard" style="display:none">
                        <div class="card-header py-1">
                            <span class="fw-semibold small"><i class="bi bi-calendar-week me-2"></i>Week View</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <button class="btn btn-sm btn-outline-secondary py-0" id="btnPrevWeek"><i class="bi bi-chevron-left"></i></button>
                                <span class="small fw-semibold" id="weekLabel">—</span>
                                <button class="btn btn-sm btn-outline-secondary py-0" id="btnNextWeek"><i class="bi bi-chevron-right"></i></button>
                            </div>
                            <button class="btn btn-sm btn-outline-primary w-100 py-0" id="btnToday">Today</button>
                        </div>
                    </div>

                    <!-- Template detail/edit (only when template selected) -->
                    <div class="card mb-3" id="templateDetailCard" style="display:none">
                        <div class="card-header py-1 d-flex align-items-center">
                            <span class="fw-semibold small"><i class="bi bi-gear me-2"></i>Template Settings</span>
                            <button class="btn btn-sm btn-outline-danger ms-auto py-0 px-2" id="btnDeleteTemplate" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div class="card-body py-2 small">
                            <div class="mb-2">
                                <label class="form-label mb-0" for="tmplName">Name</label>
                                <input type="text" class="form-control form-control-sm" id="tmplName">
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-0" for="tmplDesc">Description</label>
                                <textarea class="form-control form-control-sm" id="tmplDesc" rows="2"></textarea>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label mb-0" for="tmplWeeks">Rotation Weeks</label>
                                    <input type="number" class="form-control form-control-sm" id="tmplWeeks" min="1" max="52" value="1">
                                </div>
                                <div class="col-6">
                                    <label class="form-label mb-0" for="tmplTimezone">Timezone</label>
                                    <input type="text" class="form-control form-control-sm" id="tmplTimezone" value="America/Chicago">
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="tmplActive" checked>
                                <label class="form-check-label" for="tmplActive">Active</label>
                            </div>
                            <button class="btn btn-sm btn-primary w-100" id="btnSaveTemplate">Save Template</button>

                            <!-- Roles section -->
                            <hr class="my-2">
                            <div class="d-flex align-items-center mb-1">
                                <span class="fw-semibold">Roles</span>
                                <button class="btn btn-sm btn-outline-primary ms-auto py-0 px-1" id="btnAddRole" title="Add Role">
                                    <i class="bi bi-plus-circle"></i>
                                </button>
                            </div>
                            <div id="rolesList"></div>

                            <!-- Slots section -->
                            <hr class="my-2">
                            <div class="d-flex align-items-center mb-1">
                                <span class="fw-semibold">Time Slots</span>
                            </div>
                            <div id="slotsList"></div>

                            <!-- Add slot form -->
                            <div class="border rounded p-2 mt-2" style="font-size:0.75rem">
                                <div class="fw-semibold mb-1">Add Slot</div>
                                <div class="row g-1 mb-1">
                                    <div class="col-6">
                                        <label class="form-label mb-0" style="font-size:0.65rem">Day</label>
                                        <select class="form-select form-select-sm" id="slotDay" style="font-size:0.75rem">
                                            <option value="1">Monday</option>
                                            <option value="2">Tuesday</option>
                                            <option value="3">Wednesday</option>
                                            <option value="4">Thursday</option>
                                            <option value="5">Friday</option>
                                            <option value="6">Saturday</option>
                                            <option value="0">Sunday</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label mb-0" style="font-size:0.65rem">Week #</label>
                                        <input type="number" class="form-control form-control-sm" id="slotWeek" value="1" min="1" max="4" style="font-size:0.75rem">
                                    </div>
                                </div>
                                <div class="row g-1 mb-1">
                                    <div class="col-6">
                                        <label class="form-label mb-0" style="font-size:0.65rem">Start Time</label>
                                        <input type="time" class="form-control form-control-sm" id="slotStart" value="21:00" style="font-size:0.75rem">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label mb-0" style="font-size:0.65rem">End Time</label>
                                        <input type="time" class="form-control form-control-sm" id="slotEnd" value="08:00" style="font-size:0.75rem">
                                    </div>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label mb-0" style="font-size:0.65rem">Label (optional)</label>
                                    <input type="text" class="form-control form-control-sm" id="slotLabel" placeholder="e.g. Night Shift" style="font-size:0.75rem">
                                </div>
                                <button class="btn btn-sm btn-primary w-100" id="btnAddSlot">
                                    <i class="bi bi-plus-lg me-1"></i>Add Slot
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Week grid -->
                <div class="col-lg-9">
                    <div id="shiftGrid">
                        <div class="text-center text-body-secondary py-5">
                            <i class="bi bi-calendar3 display-6 d-block mb-2 opacity-25"></i>
                            <span class="small">Select a shift template to view the schedule</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ EVENTS TAB ═══════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabEvents">
            <div class="row g-3">
                <!-- Left: Event list -->
                <div class="col-lg-5">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <input type="text" class="form-control form-control-sm" id="eventSearch" placeholder="Search events...">
                        <select class="form-select form-select-sm" id="eventTypeFilter" style="width:auto">
                            <option value="">All Types</option>
                            <option value="drill">Drill</option>
                            <option value="exercise">Exercise</option>
                            <option value="deployment">Deployment</option>
                            <option value="meeting">Meeting</option>
                            <option value="training">Training</option>
                            <option value="other">Other</option>
                        </select>
                        <button class="btn btn-sm btn-primary" id="btnNewEvent"><i class="bi bi-plus me-1"></i>New</button>
                    </div>
                    <div id="eventList" style="max-height:calc(100vh - 240px);overflow-y:auto">
                        <div class="text-center text-body-secondary py-3 small">
                            <div class="spinner-border spinner-border-sm me-1"></div>Loading...
                        </div>
                    </div>
                </div>

                <!-- Right: Event detail -->
                <div class="col-lg-7">
                    <div id="eventDetail">
                        <div class="text-center text-body-secondary py-5">
                            <i class="bi bi-calendar-event display-6 d-block mb-2 opacity-25"></i>
                            <span class="small">Select an event to view details</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- CSRF token -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<input type="hidden" id="currentUserId" value="<?php echo (int) $_SESSION['user_id']; ?>">
<!-- QA #2 — the schedule grid's clickable ids are MEMBER ids; self-signup
     must compare against the logged-in user's member id, not their user id. -->
<input type="hidden" id="currentMemberId" value="<?php echo (int) ($_SESSION['member_id'] ?? 0); ?>">
<input type="hidden" id="currentLevel" value="<?php echo (int) $_SESSION['level']; ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- Personnel Assignment Modal (typeahead search) -->
<div class="modal fade" id="assignMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="assignModalTitle">Assign Personnel</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="text" class="form-control form-control-sm mb-2" id="assignSearchInput"
                       placeholder="Search by name, callsign..." autocomplete="off">
                <div id="assignSearchResults" style="max-height:250px;overflow-y:auto;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Assignment Detail Modal (view/update status) -->
<div class="modal fade" id="assignDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="assignDetailTitle">Assignment</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-2">
                    <strong id="assignDetailRole"></strong>
                    <div class="text-body-secondary small" id="assignDetailMember"></div>
                    <div class="text-body-secondary small" id="assignDetailDate"></div>
                    <div class="mt-1"><span class="badge" id="assignDetailStatus"></span></div>
                </div>
                <div id="assignDetailActions" class="d-flex flex-column gap-1 mt-3"></div>
                <!-- Swap section (hidden until Swap button clicked) -->
                <div id="assignSwapSection" class="mt-2 d-none">
                    <hr class="my-2">
                    <label class="form-label form-label-sm mb-1 fw-semibold">Swap with:</label>
                    <input type="text" class="form-control form-control-sm mb-1" id="swapSearchInput"
                           placeholder="Search by name, callsign..." autocomplete="off">
                    <div id="swapSearchResults" style="max-height:150px;overflow-y:auto;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Event Participant Registration Modal (typeahead search) -->
<div class="modal fade" id="registerParticipantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="registerParticipantTitle">Register Participant</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="text" class="form-control form-control-sm mb-2" id="participantSearchInput"
                       placeholder="Search by name, callsign..." autocomplete="off">
                <div id="participantSearchResults" style="max-height:250px;overflow-y:auto;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Scheduling JS -->
<script src="assets/js/scheduling.js?v=<?php echo asset_v('assets/js/scheduling.js'); ?>"></script>

</body>
</html>
