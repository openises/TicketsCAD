<?php
/**
 * NewUI v4.0 - Quick Start Guide
 *
 * Welcome page for first-time users with a 5-step setup checklist.
 * Progress is tracked in localStorage so it persists across sessions.
 * Accessible from Help menu or shown after first login.
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
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'help';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.quick_start', 'Quick Start')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">

    <style>
        .qs-card {
            transition: border-color 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .qs-card:hover {
            box-shadow: 0 0.25rem 0.75rem rgba(0,0,0,0.15);
        }
        .qs-card.completed {
            border-color: var(--bs-success) !important;
            opacity: 0.75;
        }
        .qs-card.completed .qs-check {
            color: var(--bs-success);
        }
        .qs-step-num {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .qs-card:not(.completed) .qs-step-num {
            background-color: var(--bs-primary);
            color: #fff;
        }
        .qs-card.completed .qs-step-num {
            background-color: var(--bs-success);
            color: #fff;
        }
        .qs-progress-bar {
            height: 6px;
            border-radius: 3px;
            background-color: var(--bs-border-color);
            overflow: hidden;
        }
        .qs-progress-fill {
            height: 100%;
            background-color: var(--bs-success);
            transition: width 0.4s ease;
        }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <!-- Welcome Header -->
            <div class="text-center mb-4">
                <i class="bi bi-rocket-takeoff text-primary" style="font-size: 3rem;"></i>
                <h3 class="mt-2">Welcome to Tickets CAD</h3>
                <p class="text-body-secondary">
                    Get your system up and running in 5 simple steps.
                    Check off each task as you complete it.
                </p>
                <!-- Progress -->
                <div class="d-flex align-items-center gap-3 justify-content-center mt-3">
                    <span class="small fw-semibold" id="progressLabel">0 of 5 complete</span>
                    <div class="qs-progress-bar" style="width: 200px;">
                        <div class="qs-progress-fill" id="progressFill" style="width: 0%;"></div>
                    </div>
                </div>
            </div>

            <!-- Step Cards -->
            <div class="d-flex flex-column gap-3" id="stepContainer">

                <!-- Step 1: Change Password -->
                <div class="card qs-card" data-step="1" data-href="profile.php#password">
                    <div class="card-body py-3 d-flex align-items-start gap-3">
                        <div class="qs-step-num">1</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0">Change Your Password</h6>
                                <i class="bi bi-check-circle-fill qs-check d-none"></i>
                            </div>
                            <p class="text-body-secondary small mb-1">
                                Set a strong, unique password to secure your account.
                                If 2FA is available, enable it from the same page.
                            </p>
                            <a href="profile.php#password" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-key me-1"></i>Go to Profile
                            </a>
                        </div>
                        <div class="form-check form-check-lg">
                            <input class="form-check-input qs-checkbox" type="checkbox" data-step="1" id="check1">
                            <label class="visually-hidden" for="check1">Mark step 1 complete</label>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Set Organization Name -->
                <div class="card qs-card" data-step="2" data-href="settings.php#general">
                    <div class="card-body py-3 d-flex align-items-start gap-3">
                        <div class="qs-step-num">2</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0">Set Organization Name</h6>
                                <i class="bi bi-check-circle-fill qs-check d-none"></i>
                            </div>
                            <p class="text-body-secondary small mb-1">
                                Configure your organization name, timezone, and default map location
                                in the General Settings panel.
                            </p>
                            <a href="settings.php#general" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-gear me-1"></i>General Settings
                            </a>
                        </div>
                        <div class="form-check form-check-lg">
                            <input class="form-check-input qs-checkbox" type="checkbox" data-step="2" id="check2">
                            <label class="visually-hidden" for="check2">Mark step 2 complete</label>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Configure Incident Types -->
                <div class="card qs-card" data-step="3" data-href="settings.php#incident-types">
                    <div class="card-body py-3 d-flex align-items-start gap-3">
                        <div class="qs-step-num">3</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0">Configure Incident Types</h6>
                                <i class="bi bi-check-circle-fill qs-check d-none"></i>
                            </div>
                            <p class="text-body-secondary small mb-1">
                                Review the built-in incident types and customize them for your
                                organization. Add response protocols that dispatchers can read to callers.
                            </p>
                            <a href="settings.php#incident-types" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-list-check me-1"></i>Incident Types
                            </a>
                        </div>
                        <div class="form-check form-check-lg">
                            <input class="form-check-input qs-checkbox" type="checkbox" data-step="3" id="check3">
                            <label class="visually-hidden" for="check3">Mark step 3 complete</label>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Add Your First Unit -->
                <div class="card qs-card" data-step="4" data-href="units.php">
                    <div class="card-body py-3 d-flex align-items-start gap-3">
                        <div class="qs-step-num">4</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0">Add Your First Unit</h6>
                                <i class="bi bi-check-circle-fill qs-check d-none"></i>
                            </div>
                            <p class="text-body-secondary small mb-1">
                                Create a responder unit (person, vehicle, or team) that can
                                be dispatched to incidents. Set their callsign and default status.
                            </p>
                            <a href="units.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-people me-1"></i>Manage Units
                            </a>
                        </div>
                        <div class="form-check form-check-lg">
                            <input class="form-check-input qs-checkbox" type="checkbox" data-step="4" id="check4">
                            <label class="visually-hidden" for="check4">Mark step 4 complete</label>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Create a Test Incident -->
                <div class="card qs-card" data-step="5" data-href="new-incident.php">
                    <div class="card-body py-3 d-flex align-items-start gap-3">
                        <div class="qs-step-num">5</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="mb-0">Create a Test Incident</h6>
                                <i class="bi bi-check-circle-fill qs-check d-none"></i>
                            </div>
                            <p class="text-body-secondary small mb-1">
                                Try creating an incident to see the full workflow: pick a type,
                                enter a location, assign a unit, and close the incident when done.
                            </p>
                            <a href="new-incident.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus-circle me-1"></i>New Incident
                            </a>
                        </div>
                        <div class="form-check form-check-lg">
                            <input class="form-check-input qs-checkbox" type="checkbox" data-step="5" id="check5">
                            <label class="visually-hidden" for="check5">Mark step 5 complete</label>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Completion Banner (hidden until all done) -->
            <div class="card border-success mt-4 d-none" id="completionBanner">
                <div class="card-body text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 2.5rem;"></i>
                    <h5 class="mt-2">All Set!</h5>
                    <p class="text-body-secondary mb-3">
                        Your system is configured and ready for use.
                        Head to the dashboard to start dispatching.
                    </p>
                    <a href="index.php" class="btn btn-success">
                        <i class="bi bi-display me-1"></i>Go to Dashboard
                    </a>
                </div>
            </div>

            <!-- Helpful Links -->
            <div class="card mt-4">
                <div class="card-body py-3">
                    <h6 class="mb-2"><i class="bi bi-info-circle text-info me-1"></i>Need More Help?</h6>
                    <div class="row g-2 small">
                        <div class="col-md-4">
                            <a href="help.php" class="text-decoration-none">
                                <i class="bi bi-question-circle me-1"></i>Help Topics
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="help.php#keyboard-shortcuts" class="text-decoration-none">
                                <i class="bi bi-keyboard me-1"></i>Keyboard Shortcuts
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="sop.php" class="text-decoration-none">
                                <i class="bi bi-journal-text me-1"></i>SOPs
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>

<!-- Quick Start JS -->
<script>
(function () {
    'use strict';

    var STORAGE_KEY = 'ticketsQsProgress';
    var TOTAL_STEPS = 5;

    // Load saved progress from localStorage
    function loadProgress() {
        var saved = {};
        try {
            saved = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
        } catch (e) {
            saved = {};
        }
        return saved;
    }

    function saveProgress(progress) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(progress));
        } catch (e) {
            // localStorage unavailable — fail silently
        }
    }

    function updateUI(progress) {
        var completed = 0;
        var cards = document.querySelectorAll('.qs-card');

        for (var i = 0; i < cards.length; i++) {
            var step = cards[i].getAttribute('data-step');
            var checkbox = cards[i].querySelector('.qs-checkbox');
            var checkIcon = cards[i].querySelector('.qs-check');

            if (progress[step]) {
                cards[i].classList.add('completed');
                if (checkbox) checkbox.checked = true;
                if (checkIcon) checkIcon.classList.remove('d-none');
                completed++;
            } else {
                cards[i].classList.remove('completed');
                if (checkbox) checkbox.checked = false;
                if (checkIcon) checkIcon.classList.add('d-none');
            }
        }

        // Update progress bar
        var pct = Math.round((completed / TOTAL_STEPS) * 100);
        document.getElementById('progressFill').style.width = pct + '%';
        document.getElementById('progressLabel').textContent = completed + ' of ' + TOTAL_STEPS + ' complete';

        // Show completion banner when all done
        var banner = document.getElementById('completionBanner');
        if (completed === TOTAL_STEPS) {
            banner.classList.remove('d-none');
        } else {
            banner.classList.add('d-none');
        }
    }

    function init() {
        var progress = loadProgress();
        updateUI(progress);

        // Bind checkbox changes
        var checkboxes = document.querySelectorAll('.qs-checkbox');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].addEventListener('change', function () {
                var step = this.getAttribute('data-step');
                var prog = loadProgress();
                if (this.checked) {
                    prog[step] = true;
                } else {
                    delete prog[step];
                }
                saveProgress(prog);
                updateUI(prog);
            });
        }

        // Clicking the card body (not the checkbox or links) toggles the checkbox
        var cards = document.querySelectorAll('.qs-card');
        for (var j = 0; j < cards.length; j++) {
            cards[j].addEventListener('click', function (e) {
                // Don't toggle if clicking a link, button, or the checkbox itself
                if (e.target.closest('a') || e.target.closest('button') ||
                    e.target.closest('.form-check-input')) {
                    return;
                }
                var checkbox = this.querySelector('.qs-checkbox');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

</body>
</html>
