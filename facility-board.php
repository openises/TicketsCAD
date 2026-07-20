<?php
/**
 * NewUI v4.0 - Facility Board
 *
 * Wall-mount optimized status overview of all facilities.
 * Card grid showing name, type, status, hours, and bed/capacity data.
 * Auto-refreshes via 30-second polling. Dark theme by default.
 *
 * Opens in a new window/tab (target="_blank" from navbar).
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
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


require_once __DIR__ . '/inc/rbac.php';

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Night'; // Default dark for wall display
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'facility-board';

// Check if bed/capacity tracking table exists
$has_capacity = false;
try {
    $check = db_fetch_value(
        "SELECT COUNT(*) FROM `information_schema`.`TABLES`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'newui_facility_capacity'"
    );
    $has_capacity = ((int) $check > 0);
} catch (Exception $e) {
    $has_capacity = false;
}

// Fetch facility types for filter dropdown
$fac_types = [];
try {
    $fac_types = db_fetch_all("SELECT `id`, `name` FROM `fac_types` ORDER BY `name`");
} catch (Exception $e) {
    $fac_types = [];
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <meta http-equiv="refresh" content="120">
    <title><?php echo e(t('page.facility_board', 'Facility Board')); ?> &mdash; <?php echo e(t('login.title', 'Tickets CAD')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/facility-board.css?v=<?php echo asset_v('assets/css/facility-board.css'); ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div id="fbContainer">
    <!-- Header Bar -->
    <div class="fb-header">
        <div class="fb-header-left">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-hospital me-2"></i>Facility Board
            </h5>
            <span class="badge fb-count-badge bg-primary">
                Total: <span id="fbCount">0</span>
            </span>
            <span class="badge bg-success" id="fbOpenCount" title="Open facilities">0 Open</span>
            <span class="badge bg-danger" id="fbClosedCount" title="Closed/Full facilities">0 Closed</span>
        </div>
        <div class="fb-header-right">
            <!-- Filters -->
            <div class="fb-filter-bar">
                <select class="form-select" id="fbFilterType" title="Filter by facility type">
                    <option value="all">All Types</option>
<?php foreach ($fac_types as $ft): ?>
                    <option value="<?php echo (int) $ft['id']; ?>"><?php echo e($ft['name']); ?></option>
<?php endforeach; ?>
                </select>
                <select class="form-select" id="fbFilterStatus" title="Filter by status">
                    <option value="all">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                    <option value="full">Full</option>
                </select>
            </div>

            <!-- Date -->
            <span class="fb-date" id="fbDate"></span>

            <!-- Clock -->
            <span class="fb-clock" id="fbClock">00:00:00</span>
        </div>
    </div>

    <!-- Card Grid -->
    <div class="fb-grid" id="fbGrid">
        <!-- Populated by JS -->
    </div>

    <!-- Empty state -->
    <div class="fb-empty d-none" id="fbEmpty">
        <i class="bi bi-hospital"></i>
        <div>No facilities to display</div>
    </div>
</div>

<!-- Hidden flags for JS -->
<input type="hidden" id="hasCapacity" value="<?php echo $has_capacity ? '1' : '0'; ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/event-bus.js"></script>
<script src="assets/js/facility-status.js?v=<?php echo asset_v('assets/js/facility-status.js'); ?>"></script>
<script src="assets/js/facility-board.js?v=<?php echo asset_v('assets/js/facility-board.js'); ?>"></script>

</body>
</html>
