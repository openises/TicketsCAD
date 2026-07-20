<?php
/**
 * NewUI v4.0 — Volunteer Time Entries (Phase 80d)
 *
 * Two-column page for logging and managing volunteer hours:
 *   - Left:  monthly/category/status filter + entry list
 *   - Right: detail/edit form for the selected entry
 *   - Bottom: monthly + annual rollup summary
 *
 * Approvers see an additional "Approval queue" tab listing every
 * self_reported entry in the org with bulk approve / reject.
 *
 * RBAC: any authenticated user with a member record can use the page.
 * The approval queue is gated on time_entry.approve.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/rbac.php';
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
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$user        = e($_SESSION['user']);
$level       = current_role_name();
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$active_page = 'personnel';
$can_approve = rbac_can('time_entry.approve');
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>My Time — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <style>
        .te-entry-row { cursor: pointer; }
        .te-entry-row.active { background-color: rgba(var(--bs-primary-rgb), 0.12); }
        .te-month-hdr {
            background: var(--bs-tertiary-bg);
            font-weight: 600;
            padding: 4px 10px;
            font-size: 0.85rem;
        }
        .te-totals .stat-card {
            background: var(--bs-secondary-bg);
            border-radius: 8px;
            padding: 12px 4px;
        }
        .te-totals .stat-value { font-size: 1.8rem; font-weight: 600; }
        .te-totals .stat-label { font-size: 0.8rem; color: var(--bs-secondary-color); }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-clock-history text-primary me-2"></i>My Time
            <small class="text-body-secondary">— volunteer hours</small>
        </h5>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
<?php if ($can_approve): ?>
            <a href="time-approvals.php" class="btn btn-sm btn-outline-warning">
                <i class="bi bi-clipboard-check me-1"></i>Approval queue
            </a>
<?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="teTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-my-tab" data-bs-toggle="tab"
                    data-bs-target="#tab-my" type="button" role="tab">My entries</button>
        </li>
<?php if ($can_approve): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-approval-tab" data-bs-toggle="tab"
                    data-bs-target="#tab-approval" type="button" role="tab">
                Approval queue <span class="badge bg-warning text-dark ms-1" id="pendingBadge">0</span>
            </button>
        </li>
<?php endif; ?>
    </ul>

    <div class="tab-content">
        <!-- ─── My entries tab ───────────────────────────────────────── -->
        <div class="tab-pane fade show active" id="tab-my" role="tabpanel">
            <!-- Summary totals -->
            <div class="row g-2 mb-3 te-totals">
                <div class="col-4 col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-value" id="sumWeek">—</div>
                        <div class="stat-label">This week</div>
                    </div>
                </div>
                <div class="col-4 col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-value" id="sumMonth">—</div>
                        <div class="stat-label">This month</div>
                    </div>
                </div>
                <div class="col-4 col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-value" id="sumYear">—</div>
                        <div class="stat-label">This year</div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <div class="stat-card text-center">
                        <div class="stat-value text-warning" id="sumPending">—</div>
                        <div class="stat-label">Awaiting approval</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- Left: filter + list -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header py-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label form-label-sm mb-0">Month</label>
                                    <input type="month" class="form-control form-control-sm" id="filtMonth">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm mb-0">Category</label>
                                    <select class="form-select form-select-sm" id="filtCategory">
                                        <option value="">All</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm mb-0">Status</label>
                                    <select class="form-select form-select-sm" id="filtStatus">
                                        <option value="">All</option>
                                        <option value="self_reported">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0" id="entryListBody" style="max-height: 600px; overflow-y: auto;">
                            <div class="text-center p-4 text-body-secondary">Loading...</div>
                        </div>
                    </div>
                </div>

                <!-- Right: detail / edit form -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span id="detailTitle">New entry</span>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary" id="btnNew">
                                    <i class="bi bi-plus-lg"></i> New
                                </button>
                                <button class="btn btn-sm btn-outline-danger d-none" id="btnDelete">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <form id="entryForm">
                                <input type="hidden" id="fId">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label form-label-sm">Start</label>
                                        <input type="datetime-local" class="form-control form-control-sm" id="fStart" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label form-label-sm">End</label>
                                        <input type="datetime-local" class="form-control form-control-sm" id="fEnd" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label form-label-sm">Category</label>
                                        <select class="form-select form-select-sm" id="fCategory"></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label form-label-sm">Activity type</label>
                                        <select class="form-select form-select-sm" id="fActivity"></select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label form-label-sm">Notes</label>
                                        <textarea class="form-control form-control-sm" id="fNotes" rows="3"
                                            maxlength="500" placeholder="What did you do?"></textarea>
                                    </div>
                                    <div class="col-12" id="statusBox">
                                        <div class="small">
                                            Status: <span id="fStatusLabel" class="badge bg-secondary">new</span>
                                            <span class="text-body-secondary ms-2" id="fHours"></span>
                                        </div>
                                        <div class="small text-danger mt-1 d-none" id="fRejectReason"></div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-3 justify-content-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancel">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-primary" id="btnSave">
                                        <i class="bi bi-check-lg"></i> Save
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<?php if ($can_approve): ?>
        <!-- ─── Approval queue tab ───────────────────────────────────── -->
        <div class="tab-pane fade" id="tab-approval" role="tabpanel">
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span>Submitted entries awaiting review</span>
                    <div>
                        <button class="btn btn-sm btn-outline-success" id="btnBulkApprove">
                            <i class="bi bi-check2-all"></i> Approve selected
                        </button>
                        <button class="btn btn-sm btn-outline-danger" id="btnBulkReject">
                            <i class="bi bi-x-circle"></i> Reject selected
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="bg-body-tertiary">
                                <tr>
                                    <th style="width:30px"><input type="checkbox" id="approvalSelectAll" aria-label="Select all"></th>
                                    <th>Submitted</th>
                                    <th>Member</th>
                                    <th>Category</th>
                                    <th>Activity</th>
                                    <th>Started</th>
                                    <th class="text-end">Hours</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody id="approvalBody">
                                <tr><td colspan="8" class="text-center p-3 text-body-secondary">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php endif; ?>
    </div>

    <p class="text-body-secondary small mt-3">
        Tip: hours roll up monthly and annually for agency reporting. Export raw entries via
        <code>GET /api/time-entries.php?member_id=N</code> or the rollup via
        <code>GET /api/time-entries.php?summary=1&amp;member_id=N</code>.
        See <a href="docs/NEWUI-USER-GUIDE.md#part-13b-logging-volunteer-hours">user guide</a>.
    </p>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script>window.CAN_APPROVE_TIME = <?php echo $can_approve ? 'true' : 'false'; ?>;</script>
<script src="assets/js/time-entries.js?v=<?php echo asset_v('assets/js/time-entries.js'); ?>"></script>
</body>
</html>
