<?php
/**
 * NewUI v4.0 — Facility Self-Service Portal (Phase 145, GH#90)
 *
 * The ONE page a facility-confined account can reach (see
 * inc/facility-scope.php for the full confinement design). Deliberately
 * self-contained — does NOT include inc/navbar.php. A facility account
 * has nothing else in the app to navigate to, so a full admin/dispatch
 * navbar would be dead weight at best and a confusing surface to audit
 * for leaks at worst. This page's own tiny header (facility name +
 * logout) is the entire chrome.
 *
 * Shows incidents at/inbound to this session's own facility (status
 * OPEN or SCHEDULED — matches v3's facboard_incidents.php filter) and a
 * self-service form for the facility's own status/diversion + bed
 * capacity. All data comes from api/facility-portal.php, which scopes
 * every query server-side to $_SESSION['facility_id'] — nothing here
 * ever requests another facility's data.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/facility-scope.php';
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// Reachable by anyone holding screen.facility_portal (role 7 by default,
// or is_admin() previewing) — same convention as rbac_require_screen(),
// but rendered inline here rather than via inc/denied.php since this
// page deliberately has none of that file's assumptions (no navbar) to
// worry about either way.
if (!(function_exists('is_admin') && is_admin()) && !(function_exists('rbac_can') && rbac_can('screen.facility_portal'))) {
    http_response_code(403);
    require_once __DIR__ . '/inc/denied.php';
    exit;
}

$user     = e($_SESSION['user']);
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
    <title><?php echo e(t('page.facility_portal', 'Facility Portal')); ?> &mdash; <?php echo e(t('login.title', 'Tickets CAD')); ?> <?php echo newui_version(); ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/facility-portal.css?v=<?php echo asset_v('assets/css/facility-portal.css'); ?>">
</head>
<body>

<header class="fp-header d-flex align-items-center justify-content-between px-3 py-2">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-hospital fs-4"></i>
        <div>
            <div class="fw-bold" id="fpFacilityName">&hellip;</div>
            <div class="small text-body-secondary"><?php echo e(t('page.facility_portal', 'Facility Portal')); ?></div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="small text-body-secondary"><?php echo e($user); ?></span>
        <a href="login.php?logout=1" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-right me-1"></i><?php echo e(t('nav.logout', 'Log Out')); ?>
        </a>
    </div>
</header>

<main class="container-fluid py-3">
    <div class="row g-3">
        <!-- Incidents -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-clipboard-pulse me-1"></i>Incidents at / Inbound to This Facility</span>
                    <span class="badge bg-secondary" id="fpIncidentCount">0</span>
                </div>
                <div class="card-body p-0">
                    <div id="fpIncidentList" class="list-group list-group-flush"></div>
                    <div id="fpIncidentEmpty" class="text-center text-body-secondary p-4 d-none">
                        <i class="bi bi-check-circle fs-3 d-block mb-2"></i>
                        No open or scheduled incidents right now.
                    </div>
                </div>
            </div>
        </div>

        <!-- Self-service status/capacity -->
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-broadcast me-1"></i>Report Facility Status</div>
                <div class="card-body">
                    <div class="mb-2">
                        <label for="fpStatusSelect" class="form-label form-label-sm">Status</label>
                        <select id="fpStatusSelect" class="form-select form-select-sm"></select>
                    </div>
                    <div class="mb-2">
                        <label for="fpStatusAbout" class="form-label form-label-sm">Note (optional)</label>
                        <textarea id="fpStatusAbout" class="form-control form-control-sm" rows="2" maxlength="512"></textarea>
                    </div>
                    <button type="button" id="fpSaveStatus" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-save me-1"></i>Update Status
                    </button>
                </div>
            </div>

            <!-- GH#102: the dispatch-facing "Bed Capacity" card
                 (facility-detail.php) shows facilities.beds_a/beds_o --
                 the number inc/bed_auto.php's automatic mode actually
                 decrements on delivery. That number was already fetched
                 by this page's own status API but never displayed here,
                 so a facility using Automatic mode had no way to see the
                 figure dispatch was routing patients against, let alone
                 correct it. This card shows it and, when it's occupied,
                 offers the release action that was missing entirely
                 before GH#102 -- the facility's own inverse of the
                 automatic decrement (see inc/facility-bed-release.php). -->
            <div class="card mb-3" id="fpSimpleBedsCard">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-hospital me-1"></i>Bed Count</span>
                    <span class="small text-body-secondary" title="This is the number dispatch sees on the facility's own detail page. Releasing a bed here moves it from Occupied back to Available -- the mirror image of what happens automatically when a unit delivers a patient here.">
                        <i class="bi bi-info-circle"></i>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center mb-2">
                        <div class="col-6">
                            <div class="text-body-secondary small">Available</div>
                            <div class="fs-4 fw-bold text-success" id="fpBedsAvailable">&ndash;</div>
                        </div>
                        <div class="col-6">
                            <div class="text-body-secondary small">Occupied</div>
                            <div class="fs-4 fw-bold text-warning" id="fpBedsOccupied">&ndash;</div>
                        </div>
                    </div>
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text">Release</span>
                        <input type="number" min="1" max="50" value="1" class="form-control form-control-sm" id="fpReleaseCount" style="max-width:5rem;">
                        <button type="button" id="fpReleaseBed" class="btn btn-outline-success">
                            <i class="bi bi-arrow-up-circle me-1"></i>Release Bed(s)
                        </button>
                    </div>
                    <input type="text" class="form-control form-control-sm" id="fpReleaseNote"
                           placeholder="Note (optional) -- e.g. patient discharged" maxlength="500">
                    <div class="small text-body-secondary mt-1" id="fpReleaseHint"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-clipboard-data me-1"></i>Bed / Capacity by Category</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="fpCapacityTable">
                        <thead>
                            <tr><th>Category</th><th style="width:5rem">Total</th><th style="width:5rem">Avail</th><th style="width:3rem"></th></tr>
                        </thead>
                        <tbody id="fpCapacityBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/facility-portal.js?v=<?php echo asset_v('assets/js/facility-portal.js'); ?>"></script>

</body>
</html>
