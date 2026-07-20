<?php
/**
 * NewUI v4.0 - Unit Detail
 *
 * View and manage a single responder/unit. Two-column layout:
 *   Left:  Unit info cards (identity, contact, location, assignment history)
 *   Right: Map + active assignments + quick status + stats
 *
 * Data loaded client-side via api/responder-detail.php
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
rbac_require_screen('screen.unit_detail');
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
    <title><?php echo e(t('page.unit_detail_header', 'Unit Detail')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/units.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-person-badge text-primary me-2"></i><?php echo e(t('page.unit_detail_header', 'Unit Detail')); ?>
        </h5>
        <div class="d-flex gap-2">
            <a href="units.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('units.title', 'Units')); ?>
            </a>
            <a href="#" class="btn btn-sm btn-outline-primary" id="btnEditUnit">
                <i class="bi bi-pencil me-1"></i><?php echo e(t('btn.edit', 'Edit')); ?>
            </a>
            <!-- 2026-07-03 (Eric) — same title-bar affordances the
                 situation view uses per unit. Dispatch → open incident
                 picker → api/incident-assign.php. Status → open status
                 picker → api/responder-status.php (mirrors the inline
                 status panel below for dispatchers who want it up top).
                 Note → open note modal → api/responder-note.php.
                 Handlers wired below via UnitActions.* once unitData
                 loads. -->
            <button type="button" class="btn btn-sm btn-outline-warning" id="btnUnitDispatch" disabled
                    title="<?php echo e(t('unit.btn.dispatch.title', 'Dispatch this unit to an open incident')); ?>">
                <i class="bi bi-send me-1"></i><?php echo e(t('unit.btn.dispatch', 'Dispatch')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-info" id="btnUnitStatus" disabled
                    title="<?php echo e(t('unit.btn.status.title', 'Change this unit\'s status')); ?>">
                <i class="bi bi-toggles me-1"></i><?php echo e(t('unit.btn.status', 'Status')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" id="btnUnitNote" disabled
                    title="<?php echo e(t('unit.btn.note.title', 'Record a note about this unit')); ?>">
                <i class="bi bi-journal-plus me-1"></i><?php echo e(t('unit.btn.note', 'Note')); ?>
            </button>
            <a href="#" class="btn btn-sm btn-outline-secondary" id="btnUnitHistory"
               title="Unit history + notes (Phase 104g)">
                <i class="bi bi-clock-history me-1"></i>History
            </a>
            <script>
            (function () {
                // Phase 104g — carry the responder_id from the current
                // ?id=X so the History button lands on the right unit.
                var m = new URLSearchParams(location.search);
                var rid = m.get('id');
                var btn = document.getElementById('btnUnitHistory');
                if (btn && rid) btn.href = 'unit-history.php?responder_id=' + rid;
            })();
            </script>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i><?php echo e(t('roster.btn.print', 'Print')); ?>
            </button>
            <div class="btn-group btn-group-sm" role="group">
                <!-- 2026-06-28 (Eric beta report): the dropdown menu was
                     rendering UNDER the Leaflet map below the header.
                     Bootstrap 5.3+ supports data-bs-strategy="fixed"
                     which renders the popper with position:fixed, taking
                     it out of any parent overflow/z-index context.
                     Plus dropdown-menu-end keeps the right edge anchored
                     so it doesn't overflow the viewport. -->
                <button type="button" class="btn btn-outline-secondary dropdown-toggle"
                        data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false"
                        title="<?php echo e(t('unit.btn.ics214.title', 'Export ICS-214 activity log from PAR + assignment timestamps')); ?>">
                    <i class="bi bi-file-earmark-text me-1"></i><?php echo e(t('unit.btn.ics214', 'ICS-214')); ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="z-index:1080;">
                    <li><a class="dropdown-item" href="#" id="btnIcs214Json24h"><?php echo e(t('unit.btn.ics214.json24', 'View timeline (last 24h)')); ?></a></li>
                    <li><a class="dropdown-item" href="#" id="btnIcs214Xml24h"><?php echo e(t('unit.btn.ics214.xml24', 'Download ICS-214 XML (last 24h)')); ?></a></li>
                    <li><a class="dropdown-item" href="#" id="btnIcs214Xml7d"><?php echo e(t('unit.btn.ics214.xml7d', 'Download ICS-214 XML (last 7 days)')); ?></a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading unit...</div>
    </div>

    <!-- Main content -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- Left Column: Unit Info -->
        <div class="col-lg-7 col-xl-6">

            <!-- Header Card -->
            <div class="card mb-3" id="headerCard">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge" id="statusBadge">--</span>
                        <span class="badge bg-secondary" id="typeBadge">--</span>
                        <span class="badge d-none" id="dispatchBadge">--</span>
                        <span class="badge d-none" id="trackingBadge">--</span>
                    </div>
                    <h5 class="mb-1" id="unitName">--</h5>
                    <div class="text-body-secondary small" id="unitMeta">--</div>
                </div>
            </div>

            <!-- Contact Info -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseContact" role="button">
                    <i class="bi bi-telephone me-2"></i>
                    <span class="fw-semibold small">Contact Information</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseContact">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-body-secondary">Phone</div>
                                <div id="contactPhone">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Cell Phone</div>
                                <div id="contactCell">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Contact Name</div>
                                <div id="contactName">--</div>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-12">
                                <div class="text-body-secondary">Capabilities</div>
                                <div id="unitCapab">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messaging -->
            <div class="card mb-3 d-none" id="messagingCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseMessaging" role="button">
                    <i class="bi bi-chat-dots me-2"></i>
                    <span class="fw-semibold small">Messaging</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseMessaging">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-body-secondary">Contact Via</div>
                                <div id="msgContactVia">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Short Message ID</div>
                                <div id="msgSmsgId">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Send Number</div>
                                <div id="msgSendNo">--</div>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-6">
                                <div class="text-body-secondary">Pager Primary</div>
                                <div id="msgPagerP">--</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-body-secondary">Pager Secondary</div>
                                <div id="msgPagerS">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Communication Identifiers -->
            <div class="card mb-3 d-none" id="commIdsCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseCommIds" role="button">
                    <i class="bi bi-broadcast-pin me-2"></i>
                    <span class="fw-semibold small">Communication Identifiers</span>
                    <span class="badge bg-secondary ms-2" id="commIdsBadge" style="font-size:0.65rem;">0</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseCommIds">
                    <div class="card-body py-2 small" id="commIdsBody">
                        <span class="text-body-secondary">No communication identifiers configured.</span>
                    </div>
                </div>
            </div>

            <!-- Notes (GH #75) -->
            <div class="card mb-3 d-none" id="notesCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseNotes" role="button">
                    <i class="bi bi-sticky me-2"></i>
                    <span class="fw-semibold small">Notes</span>
                    <span class="badge bg-secondary ms-2" id="notesBadge" style="font-size:0.65rem;">0</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseNotes">
                    <ul class="list-group list-group-flush small" id="unitNotesBody"></ul>
                </div>
            </div>

            <!-- Tracking & Boundaries -->
            <div class="card mb-3 d-none" id="trackingCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseTracking" role="button">
                    <i class="bi bi-broadcast me-2"></i>
                    <span class="fw-semibold small">Location &amp; Boundaries</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseTracking">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-body-secondary">Location Provider</div>
                                <div id="trackProvider">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Ring Fence</div>
                                <div id="trackRingFence">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Exclusion Zone</div>
                                <div id="trackExclZone">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Details -->
            <div class="card mb-3 d-none" id="additionalCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseAdditional" role="button">
                    <i class="bi bi-info-circle me-2"></i>
                    <span class="fw-semibold small">Additional Details</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseAdditional">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-body-secondary">Directions</div>
                                <div id="addlDirecs">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Icon</div>
                                <div id="addlIconStr">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Other</div>
                                <div id="addlOther">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseLoc" role="button">
                    <i class="bi bi-geo-alt me-2"></i>
                    <span class="fw-semibold small">Location</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseLoc">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="text-body-secondary">Street</div>
                                <div id="locStreet">--</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary">City</div>
                                <div id="locCity">--</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary"><?php echo e(t('form.state', 'State')); ?></div>
                                <div id="locState">--</div>
                            </div>
                        </div>
                        <div class="row g-2 mt-1" id="locCoordsRow">
                            <div class="col-auto">
                                <span class="text-body-secondary">Lat:</span>
                                <span id="locLat">--</span>
                            </div>
                            <div class="col-auto">
                                <span class="text-body-secondary">Lng:</span>
                                <span id="locLng">--</span>
                            </div>
                        </div>
                        <div class="row g-2 mt-1 d-none" id="facilityRow">
                            <div class="col-12">
                                <div class="text-body-secondary">Home Facility</div>
                                <div id="locFacility">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assignment History -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseHistory" role="button">
                    <i class="bi bi-clock-history me-2"></i>
                    <span class="fw-semibold small">Assignment History</span>
                    <span class="badge bg-secondary ms-auto" id="historyCount">0</span>
                    <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseHistory">
                    <div class="card-body p-0">
                        <div id="historyList">
                            <div class="text-center text-body-secondary py-3 small">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Column: Map + Active + Quick Status + Stats -->
        <div class="col-lg-5 col-xl-6">
            <div class="sticky-top" style="top: 70px;">

                <!-- Map -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small">Location</span>
                        <div class="ms-auto d-flex gap-1">
                            <select class="form-select form-select-sm py-0" id="routeHours" style="width:100px;font-size:0.75rem">
                                <option value="1">Last 1h</option>
                                <option value="4">Last 4h</option>
                                <option value="8">Last 8h</option>
                                <option value="24" selected>Last 24h</option>
                                <option value="72">Last 3 days</option>
                                <option value="168">Last 7 days</option>
                            </select>
                            <button class="btn btn-sm btn-outline-info py-0 px-2" id="btnRoutePlayback" title="Route Playback">
                                <i class="bi bi-play-circle"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="unitMap" style="height: 350px;"></div>
                        <div id="routePlaybackContainer"></div>
                    </div>
                </div>

                <!-- Personnel Assigned to Unit -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-people me-2"></i>
                        <span class="fw-semibold small">Assigned Personnel</span>
                        <span class="badge bg-primary ms-2" id="personnelCount">0</span>
                        <button class="btn btn-sm btn-outline-primary ms-auto py-0 px-2" id="btnAssignPersonnel" title="Assign Personnel">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div id="personnelList">
                            <div class="text-center text-body-secondary py-3 small">No personnel assigned</div>
                        </div>
                    </div>
                </div>

                <!-- Location Sources -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-broadcast me-2"></i>
                        <span class="fw-semibold small">Location Sources</span>
                        <button class="btn btn-sm btn-outline-primary ms-auto py-0 px-2" id="btnAddBinding" title="Add Location Source">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div id="locationBindingsList">
                            <div class="text-center text-body-secondary py-3 small">No location sources configured</div>
                        </div>
                    </div>
                    <div id="resolvedLocationBar" class="card-footer py-1 small d-none">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-geo-alt-fill text-success" id="resolvedIcon"></i>
                            <span id="resolvedProvider">--</span>
                            <span class="text-body-secondary" id="resolvedAge">--</span>
                            <span class="badge" id="resolvedFreshBadge">--</span>
                        </div>
                    </div>
                </div>

                <!-- Active Assignments -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-lightning me-2"></i>
                        <span class="fw-semibold small">Active Assignments</span>
                        <span class="badge bg-primary ms-auto" id="activeCount">0</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="activeList">
                            <div class="text-center text-body-secondary py-3 small">Loading...</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Status -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-toggles me-2"></i>
                        <span class="fw-semibold small">Quick Status Change</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap gap-2 mb-2" id="statusButtons">
                            <div class="text-body-secondary small">Loading statuses...</div>
                        </div>
                        <div class="d-none mt-2 mb-2" id="dispatchLevelRow">
                            <div class="text-body-secondary small mb-1">Dispatch Level</div>
                            <div class="btn-group btn-group-sm" id="dispatchButtons">
                                <button type="button" class="btn btn-outline-success dispatch-btn" data-dispatch="0">
                                    &#x2713; Available
                                </button>
                                <button type="button" class="btn btn-outline-warning dispatch-btn" data-dispatch="1">
                                    &#x26A0; Inform
                                </button>
                                <button type="button" class="btn btn-outline-danger dispatch-btn" data-dispatch="2">
                                    &#x2717; Unavailable
                                </button>
                            </div>
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Notes</span>
                            <input type="text" class="form-control form-control-sm" id="statusNotes"
                                   placeholder="Optional status notes...">
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-bar-chart me-2"></i>
                        <span class="fw-semibold small">Statistics</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="row g-2 text-center small">
                            <div class="col-4">
                                <div class="text-body-secondary">Total Calls</div>
                                <div class="fs-5 fw-bold" id="statTotalCalls">--</div>
                            </div>
                            <div class="col-4">
                                <div class="text-body-secondary">Avg Response</div>
                                <div class="fs-5 fw-bold" id="statAvgResponse">--</div>
                            </div>
                            <div class="col-4">
                                <div class="text-body-secondary">This Month</div>
                                <div class="fs-5 fw-bold" id="statThisMonth">--</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- Assign Personnel Modal -->
<div class="modal fade" id="assignPersonnelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Assign Personnel to Unit</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="text" class="form-control form-control-sm mb-2" id="personnelSearchInput"
                       placeholder="Search by name, callsign..." autocomplete="off">
                <div class="mb-2">
                    <label class="form-label mb-0 small">Role</label>
                    <select class="form-select form-select-sm" id="assignRoleSelect">
                        <option value="operator">Operator</option>
                    </select>
                </div>
                <div id="personnelSearchResults" style="max-height:250px;overflow-y:auto;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Add Location Binding Modal -->
<div class="modal fade" id="addBindingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Add Location Source</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="mb-2">
                    <label class="form-label mb-0 small">Provider</label>
                    <select class="form-select form-select-sm" id="bindProviderSelect"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-0 small">Identifier (callsign, device ID, etc.)</label>
                    <input type="text" class="form-control form-control-sm" id="bindIdentifier"
                           placeholder="e.g. N0NKI, OT-MyDevice">
                </div>
                <div class="mb-2">
                    <label class="form-label mb-0 small">Priority (lower = higher priority)</label>
                    <input type="number" class="form-control form-control-sm" id="bindPriority"
                           value="50" min="1" max="100">
                </div>
                <button class="btn btn-sm btn-primary w-100" id="btnSaveBinding">
                    <i class="bi bi-plus-lg me-1"></i>Add Binding
                </button>
            </div>
        </div>
    </div>
</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>
<script src="assets/js/map-image-overlays.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/map-image-overlays.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/route-playback.js"></script>

<!-- App CSS for tracking -->
<link rel="stylesheet" href="assets/css/unit-tracking.css">

<!-- Shared unit-action modal (Dispatch / Status / Note) -->
<?php include_once NEWUI_ROOT . '/inc/unit-actions-modal.php'; ?>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/unit-actions.js?v=<?php echo asset_v('assets/js/unit-actions.js'); ?>"></script>
<script src="assets/js/unit-detail.js?v=<?php echo asset_v('assets/js/unit-detail.js'); ?>"></script>

<!-- Wire title-bar Dispatch/Status/Note buttons once unit data lands.
     unit-detail.js loads the responder via api/responder-detail.php
     and fires a window event 'unit-detail:loaded' with the resolved
     handle so this snippet can enable + bind the buttons. -->
<script>
(function () {
    'use strict';
    var uid = 0, handle = '';
    var params = new URLSearchParams(location.search);
    uid = parseInt(params.get('id'), 10) || 0;

    function bindOnce() {
        if (!window.UnitActions || !uid) return;
        var dispatchBtn = document.getElementById('btnUnitDispatch');
        var statusBtn = document.getElementById('btnUnitStatus');
        var noteBtn = document.getElementById('btnUnitNote');
        if (dispatchBtn) {
            dispatchBtn.disabled = false;
            dispatchBtn.addEventListener('click', function () {
                window.UnitActions.dispatch(uid, handle);
            });
        }
        if (statusBtn) {
            statusBtn.disabled = false;
            statusBtn.addEventListener('click', function () {
                window.UnitActions.status(uid, handle);
            });
        }
        if (noteBtn) {
            noteBtn.disabled = false;
            noteBtn.addEventListener('click', function () {
                window.UnitActions.note(uid, handle);
            });
        }
        // After a mutation, ask unit-detail.js to reload the current unit.
        window.UnitActions.onMutate = function () {
            if (typeof window.loadUnit === 'function') window.loadUnit(uid);
            else location.reload();
        };
    }

    // unit-detail.js sets window.unitData once the fetch resolves; poll
    // briefly to catch the handle for prettier modal titles, then bind.
    var tries = 0;
    var pollId = setInterval(function () {
        tries++;
        if (window.unitData && window.unitData.responder) {
            handle = window.unitData.responder.handle
                  || window.unitData.responder.name
                  || ('unit #' + uid);
            clearInterval(pollId);
            bindOnce();
        } else if (tries >= 40) {
            // 8s deadline. Bind anyway — dispatch/status/note still work
            // without a handle; the modal shows "unit #ID" instead.
            clearInterval(pollId);
            bindOnce();
        }
    }, 200);
})();
</script>

</body>
</html>
