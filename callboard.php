<?php
/**
 * NewUI v4.0 - Dispatch Call Board
 *
 * Full-width display optimized for wall-mounted dispatch monitors.
 * Shows all active incidents with color-coded elapsed time progression.
 * Auto-refreshes via SSE with polling fallback.
 *
 * Opens in a new window/tab (target="_blank" from navbar).
 * Default dark theme for dispatch center readability.
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
$theme    = $_SESSION['day_night'] ?? 'Night'; // Default dark for call board
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'callboard';

// Fetch initial incidents server-side for no-JS fallback
$prefix = $GLOBALS['db_prefix'] ?? '';
$initial_incidents = [];
try {
    $recent_mins = (int) (get_variable('recent_close_mins') ?: 30);
    $sql = "SELECT
        `t`.`id`,
        `t`.`scope`,
        `t`.`street`,
        `t`.`city`,
        `t`.`state`,
        `t`.`severity`,
        `t`.`status`,
        `t`.`date` AS `created`,
        `t`.`updated`,
        `t`.`problemstart`,
        `t`.`problemend`,
        `it`.`type` AS `incident_type`,
        `it`.`color` AS `type_color`,
        (SELECT COUNT(*) FROM `{$prefix}assigns`
         WHERE `ticket_id` = `t`.`id` AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00'))
         AS `units_assigned`
    FROM `{$prefix}ticket` `t`
    LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
    WHERE (
        `t`.`status` = 2
        OR `t`.`status` = 3
        OR (`t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? MINUTE))
    )
    ORDER BY `t`.`severity` DESC, `t`.`updated` DESC";
    $initial_incidents = db_fetch_all($sql, [$recent_mins]);
} catch (Exception $e) {
    $initial_incidents = [];
}

/**
 * PHP helper for elapsed time display (no-JS fallback).
 */
function cb_elapsed_class($created) {
    if (!$created) return 'cb-elapsed-green';
    $diff = time() - strtotime($created);
    if ($diff < 0) $diff = 0;
    if ($diff >= 3600) return 'cb-elapsed-critical';
    if ($diff >= 1800) return 'cb-elapsed-red';
    if ($diff >= 900)  return 'cb-elapsed-orange';
    if ($diff >= 300)  return 'cb-elapsed-yellow';
    return 'cb-elapsed-green';
}

function cb_elapsed_text($created) {
    if (!$created) return '--:--';
    $diff = time() - strtotime($created);
    if ($diff < 0) $diff = 0;
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    $s = $diff % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function cb_sev_label($sev) {
    switch ((int)$sev) {
        case 0: return 'Normal';
        case 1: return 'Medium';
        case 2: return 'High';
        default: return 'Normal';
    }
}

function cb_status_label($status) {
    switch ((int)$status) {
        case 1: return 'Closed';
        case 2: return 'Open';
        case 3: return 'Scheduled';
        default: return 'Unknown';
    }
}

function cb_status_class($status) {
    switch ((int)$status) {
        case 1: return 'cb-status-closed';
        case 2: return 'cb-status-open';
        case 3: return 'cb-status-dispatched';
        default: return 'cb-status-open';
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <meta http-equiv="refresh" content="60">
    <title><?php echo e(t('page.dispatch_board', 'Dispatch Call Board')); ?> &mdash; <?php echo e(t('login.title', 'Tickets CAD')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/callboard.css?v=<?php echo asset_v('assets/css/callboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div id="cbContainer">
    <!-- Header Bar -->
    <div class="cb-header">
        <div class="cb-header-left">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-grid-3x3-gap me-2"></i><?php echo e(t('page.dispatch_board', 'Dispatch Call Board')); ?>
            </h5>
            <span class="badge cb-count-badge bg-primary">
                Active: <span id="cbCount"><?php echo count($initial_incidents); ?></span>
            </span>
        </div>
        <div class="cb-header-right">
            <!-- Filters -->
            <div class="cb-filter-bar">
                <select class="form-select" id="cbFilterSev" title="Filter by severity">
                    <option value="all">All Severities</option>
                    <option value="2">High</option>
                    <option value="1">Medium</option>
                    <option value="0">Normal</option>
                </select>
                <select class="form-select" id="cbFilterType" title="Filter by type">
                    <option value="all">All Types</option>
                </select>
            </div>

            <!-- Sound toggle -->
            <button type="button" class="btn btn-sm btn-outline-secondary" id="cbSoundBtn" title="Mute alerts" aria-label="Toggle sound alerts">
                <i class="bi bi-volume-up"></i>
            </button>

            <!-- Date -->
            <span class="cb-date" id="cbDate"></span>

            <!-- Clock -->
            <span class="cb-clock" id="cbClock">00:00:00</span>
        </div>
    </div>

    <!-- Incident Table -->
    <table class="table table-hover cb-table">
        <thead>
            <tr>
                <th>Ticket</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Location</th>
                <th>Units</th>
                <th>Status</th>
                <th>Opened</th>
                <th>Elapsed</th>
                <th>Progression</th>
            </tr>
        </thead>
        <tbody id="cbTableBody">
<?php if (empty($initial_incidents)): ?>
            <tr><td colspan="9" class="cb-empty">
                <i class="bi bi-check-circle"></i>No active incidents
            </td></tr>
<?php else: ?>
<?php foreach ($initial_incidents as $inc):
    $addr = $inc['street'] ?? '';
    if (!empty($inc['city'])) $addr .= ($addr ? ', ' : '') . $inc['city'];
    $sevClass = 'cb-row-sev-' . ((int)($inc['severity'] ?? 0));
    $elClass = cb_elapsed_class($inc['created']);
    $elText = cb_elapsed_text($inc['created']);
?>
            <tr id="cb-row-<?php echo (int)$inc['id']; ?>" class="<?php echo $sevClass; ?>">
                <td><a href="incident-detail.php?id=<?php echo (int)$inc['id']; ?>" class="cb-ticket-link" target="_blank">#<?php echo (int)$inc['id']; ?></a></td>
                <td>
<?php if (!empty($inc['type_color'])): ?>
                    <span class="cb-type-dot" style="background:<?php echo e($inc['type_color']); ?>;"></span>
<?php endif; ?>
                    <?php echo e($inc['incident_type'] ?? 'Unknown'); ?>
                </td>
                <td><span class="cb-sev-badge cb-sev-<?php echo (int)($inc['severity'] ?? 0); ?>"><?php echo cb_sev_label($inc['severity'] ?? 0); ?></span></td>
                <td><?php echo e($addr); ?></td>
                <td><span class="cb-units-count"><?php echo (int)($inc['units_assigned'] ?? 0); ?></span></td>
                <td><span class="cb-status-badge <?php echo cb_status_class($inc['status'] ?? 2); ?>"><?php echo cb_status_label($inc['status'] ?? 2); ?></span></td>
                <td class="cb-timestamp"><?php echo e(date('m/d H:i', strtotime($inc['created']))); ?></td>
                <td><span class="cb-elapsed <?php echo $elClass; ?>" data-created="<?php echo e($inc['created']); ?>"><?php echo $elText; ?></span></td>
                <td class="cb-progression">
                    <span class="cb-progression-step">Created <?php echo e(date('H:i', strtotime($inc['created']))); ?></span>
<?php if (!empty($inc['problemstart'])): ?>
                    <i class="bi bi-arrow-right" style="font-size:0.6rem"></i>
                    <span class="cb-progression-step cb-progression-active">Dispatched <?php echo e(date('H:i', strtotime($inc['problemstart']))); ?></span>
<?php endif; ?>
<?php if (!empty($inc['problemend']) && (int)($inc['status'] ?? 0) === 1): ?>
                    <i class="bi bi-arrow-right" style="font-size:0.6rem"></i>
                    <span class="cb-progression-step cb-progression-active">Closed <?php echo e(date('H:i', strtotime($inc['problemend']))); ?></span>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/event-bus.js"></script>
<script src="assets/js/audio-alerts.js"></script>
<script src="assets/js/callboard.js?v=<?php echo asset_v('assets/js/callboard.js'); ?>"></script>

</body>
</html>
