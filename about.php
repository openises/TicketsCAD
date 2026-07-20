<?php
/**
 * NewUI v4.0 - About Page
 *
 * Application info, credits, license, system information.
 * Simple single-page layout, no AJAX needed.
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
$active_page = 'about';

// Gather system info
$php_version    = phpversion();
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

$db_version = 'Unknown';
try {
    $db_version = db_fetch_value("SELECT VERSION()");
} catch (Exception $ex) {
    // Graceful degradation
}

$db_driver = 'Unknown';
try {
    $db_driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Exception $ex) {
    // Graceful degradation
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.about', 'About')); ?> &mdash; <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3" style="max-width: 900px;">

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-info-circle text-primary me-2"></i>About TicketsCAD
        </h5>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <div class="row g-3">
        <!-- Application Info -->
        <div class="col-lg-8">

            <div class="card mb-3">
                <div class="card-body text-center py-4">
                    <img src="assets/logo-light.png" alt="TicketsCAD" height="64" class="mb-3">
                    <h3 class="mb-1">TicketsCAD</h3>
                    <p class="text-body-secondary mb-1">Computer-Aided Dispatch System</p>
                    <p class="mb-0">
                        <span class="badge bg-primary fs-6">NewUI v<?php echo NEWUI_VERSION; ?></span>
                    </p>
                </div>
            </div>

            <!-- Project History -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-clock-history me-2"></i>Project History
                </div>
                <div class="card-body">
                    <p>TicketsCAD was originally created by <strong>Arnie Shore</strong> in 2005 as a free, open-source Computer-Aided Dispatch system for volunteer emergency services organizations.</p>
                    <p>For over two decades, it has been used by volunteer fire departments, ARES/RACES amateur radio groups, CERT teams, search and rescue organizations, small EMS agencies, event medical services, and campus security operations around the world.</p>
                    <p class="mb-0">Since 2023, the project has been maintained by <strong>Eric Osterberg</strong>, who is modernizing the codebase while preserving its mission of providing free, reliable dispatch software to volunteer organizations.</p>
                </div>
            </div>

            <!-- License -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-file-text me-2"></i>License
                </div>
                <div class="card-body">
                    <p class="mb-2">TicketsCAD is free software released under the <strong>GNU General Public License v2.0</strong> (GPL-2.0).</p>
                    <p class="text-body-secondary small mb-0">You are free to use, modify, and distribute this software under the terms of the GPL. There is no warranty; see the license text for details.</p>
                </div>
            </div>

            <!-- Links -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-link-45deg me-2"></i>Links
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-github me-2"></i>
                            <a href="https://github.com/openises/tickets" target="_blank" rel="noopener noreferrer">GitHub Repository</a>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-book me-2"></i>
                            <a href="https://github.com/openises/tickets/wiki" target="_blank" rel="noopener noreferrer">Wiki / Documentation</a>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-people me-2"></i>
                            <a href="https://groups.google.com/g/tickets-cad" target="_blank" rel="noopener noreferrer">Google Group (Community)</a>
                        </li>
                        <li>
                            <i class="bi bi-download me-2"></i>
                            <a href="https://sourceforge.net/projects/openises/" target="_blank" rel="noopener noreferrer">SourceForge Downloads</a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Credits -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-heart me-2"></i>Credits
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <thead class="visually-hidden"><tr><th>Name</th><th>Role</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><strong>Arnie Shore</strong></td>
                                <td class="text-body-secondary">Creator and original developer</td>
                            </tr>
                            <tr>
                                <td><strong>Eric Osterberg</strong></td>
                                <td class="text-body-secondary">Current maintainer</td>
                            </tr>
                            <tr>
                                <td><strong>Andy Harvey</strong></td>
                                <td class="text-body-secondary">Contributor</td>
                            </tr>
                            <tr>
                                <td><strong>Alan Jump</strong></td>
                                <td class="text-body-secondary">Contributor</td>
                            </tr>
                            <tr>
                                <td><strong>Robert Austin</strong></td>
                                <td class="text-body-secondary">Contributor</td>
                            </tr>
                            <tr>
                                <td><strong>Mike Harris</strong></td>
                                <td class="text-body-secondary">Contributor</td>
                            </tr>
                            <tr>
                                <td><strong>Bill Kramer</strong></td>
                                <td class="text-body-secondary">Contributor</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Community -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-people-fill me-2"></i>Community
                </div>
                <div class="card-body">
                    <p>Join the TicketsCAD community to ask questions, share ideas, and get support from other users and administrators.</p>
                    <a href="https://groups.google.com/g/tickets-cad" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-envelope me-1"></i>Join the Google Group
                    </a>
                </div>
            </div>

        </div>

        <!-- Right column: System Info -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-cpu me-2"></i>System Information
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt class="text-body-secondary small">Application Version</dt>
                        <dd class="font-monospace"><?php echo NEWUI_VERSION; ?></dd>

                        <dt class="text-body-secondary small">PHP Version</dt>
                        <dd class="font-monospace"><?php echo e($php_version); ?></dd>

                        <dt class="text-body-secondary small">Database</dt>
                        <dd class="font-monospace"><?php echo e($db_driver); ?></dd>

                        <dt class="text-body-secondary small">Database Version</dt>
                        <dd class="font-monospace"><?php echo e($db_version); ?></dd>

                        <dt class="text-body-secondary small">Server Software</dt>
                        <dd class="font-monospace" style="word-break:break-all"><?php echo e($server_software); ?></dd>

                        <dt class="text-body-secondary small">Server Time</dt>
                        <dd class="font-monospace"><?php echo date('Y-m-d H:i:s T'); ?></dd>

                        <dt class="text-body-secondary small">Timezone</dt>
                        <dd class="font-monospace mb-0"><?php echo date_default_timezone_get(); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/theme-manager.js"></script>

</body>
</html>
