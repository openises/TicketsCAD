<?php
/**
 * NewUI v4.0 - NTP Time Check
 *
 * Compares server time with browser time to detect clock drift.
 * Important for dispatch timestamp accuracy.
 *
 * Accessible from the System Health panel in settings.
 */

require_once __DIR__ . '/config.php';

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


// Admin only
require_once __DIR__ . '/inc/rbac.php';
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();

// Get server time data
$server_time_epoch = time();
$server_time_iso   = date('c');
$server_timezone   = date_default_timezone_get();
$server_tz_offset  = date('P');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Time Check &mdash; Tickets NewUI <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo NEWUI_VERSION; ?>">
    <style>
        .time-card {
            border-radius: 8px;
            border: 1px solid var(--bs-border-color);
            background: var(--bs-body-bg);
            padding: 20px;
            text-align: center;
        }
        .time-display {
            font-family: var(--bs-font-monospace);
            font-size: 2rem;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        .time-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            opacity: 0.6;
            margin-bottom: 4px;
        }
        .time-sub {
            font-size: 0.78rem;
            opacity: 0.5;
            font-family: var(--bs-font-monospace);
        }
        .drift-display {
            font-family: var(--bs-font-monospace);
            font-size: 1.6rem;
            font-weight: 700;
        }
        .drift-ok     { color: var(--bs-success); }
        .drift-warn   { color: var(--bs-warning); }
        .drift-danger  { color: var(--bs-danger); }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Config Layout: Sidebar + Content -->
<div class="config-layout">

    <?php $configActivePage = 'time-check'; include_once NEWUI_ROOT . '/inc/config-sidebar.php'; ?>

    <main class="config-content" id="configContent" style="padding: 1rem 1.5rem;">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="settings.php">Settings</a></li>
            <li class="breadcrumb-item"><a href="status.php">System Health</a></li>
            <li class="breadcrumb-item active">Time Check</li>
        </ol>
    </nav>

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>NTP Time Check</h5>
        <a href="status.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to System Health
        </a>
    </div>

    <div class="row g-3 mb-3">
        <!-- Server Time -->
        <div class="col-md-4">
            <div class="time-card">
                <div class="time-label"><i class="bi bi-hdd-rack me-1"></i>Server Time (PHP)</div>
                <div class="time-display" id="serverTime">--:--:--</div>
                <div class="time-sub" id="serverDate">Loading...</div>
                <div class="time-sub mt-1">TZ: <?php echo e($server_timezone); ?> (<?php echo e($server_tz_offset); ?>)</div>
            </div>
        </div>

        <!-- Browser Time -->
        <div class="col-md-4">
            <div class="time-card">
                <div class="time-label"><i class="bi bi-laptop me-1"></i>Browser Time</div>
                <div class="time-display" id="browserTime">--:--:--</div>
                <div class="time-sub" id="browserDate">Loading...</div>
                <div class="time-sub mt-1" id="browserTZ">TZ: detecting...</div>
            </div>
        </div>

        <!-- Drift -->
        <div class="col-md-4">
            <div class="time-card">
                <div class="time-label"><i class="bi bi-arrow-left-right me-1"></i>Clock Drift</div>
                <div class="drift-display" id="driftValue">--</div>
                <div id="driftStatus" class="mt-1"></div>
            </div>
        </div>
    </div>

    <!-- Warning banner (hidden by default) -->
    <div class="alert alert-danger d-none" id="driftWarning">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Clock drift detected!</strong> The difference between server and browser time exceeds 5 seconds.
        This can cause dispatch timestamps to be inaccurate. Please synchronize your system clock with an NTP server.
    </div>

    <!-- Details -->
    <div class="card border-secondary-subtle">
        <div class="card-header small fw-semibold">
            <i class="bi bi-info-circle me-1"></i>Time Synchronization Details
        </div>
        <div class="card-body small">
            <table class="table table-sm table-borderless mb-0" style="max-width:500px;">
                <thead class="visually-hidden"><tr><th>Property</th><th>Value</th></tr></thead>
                <tbody>
                <tr>
                    <td class="text-body-secondary" style="width:200px">Server epoch (PHP)</td>
                    <td class="font-monospace" id="detailServerEpoch"><?php echo $server_time_epoch; ?></td>
                </tr>
                <tr>
                    <td class="text-body-secondary">Browser epoch (JS)</td>
                    <td class="font-monospace" id="detailBrowserEpoch">--</td>
                </tr>
                <tr>
                    <td class="text-body-secondary">Difference (seconds)</td>
                    <td class="font-monospace" id="detailDiffSec">--</td>
                </tr>
                <tr>
                    <td class="text-body-secondary">Server timezone</td>
                    <td class="font-monospace"><?php echo e($server_timezone . ' ' . $server_tz_offset); ?></td>
                </tr>
                <tr>
                    <td class="text-body-secondary">Browser timezone</td>
                    <td class="font-monospace" id="detailBrowserTZ">--</td>
                </tr>
                <tr>
                    <td class="text-body-secondary">Page loaded at</td>
                    <td class="font-monospace"><?php echo e($server_time_iso); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="mt-3 small text-body-secondary">
        <i class="bi bi-lightbulb me-1"></i>
        This page compares the PHP server time with your browser's JavaScript time.
        A difference greater than 5 seconds may indicate clock drift on the server or client.
        For accurate dispatch timestamps, ensure both are synchronized via NTP.
    </div>

    </main>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- Sidebar section expand/collapse -->
<script>
(function () {
    'use strict';
    var headers = document.querySelectorAll('.config-section-header');
    for (var i = 0; i < headers.length; i++) {
        headers[i].addEventListener('click', function () {
            var section = this.getAttribute('data-section');
            var list = document.querySelector('.config-tab-list[data-section="' + section + '"]');
            if (list) {
                this.classList.toggle('collapsed');
                list.classList.toggle('collapsed');
            }
        });
    }
    var tabBtns = document.querySelectorAll('.config-tab-link[data-tab]');
    for (var j = 0; j < tabBtns.length; j++) {
        tabBtns[j].addEventListener('click', function () {
            var tab = this.getAttribute('data-tab');
            if (tab) window.location.href = 'settings.php#' + tab;
        });
    }
})();
</script>

<script>
(function() {
    'use strict';

    // Server epoch at page render time — we add elapsed JS seconds to keep it ticking
    var serverEpochAtLoad = <?php echo $server_time_epoch; ?>;
    var pageLoadMs = Date.now();

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function formatTime(d) {
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function formatDate(d) {
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function update() {
        var now = new Date();
        var elapsedSec = Math.floor((Date.now() - pageLoadMs) / 1000);
        var serverNow = new Date((serverEpochAtLoad + elapsedSec) * 1000);

        // Server time display
        document.getElementById('serverTime').textContent = formatTime(serverNow);
        document.getElementById('serverDate').textContent = formatDate(serverNow);

        // Browser time display
        document.getElementById('browserTime').textContent = formatTime(now);
        document.getElementById('browserDate').textContent = formatDate(now);

        // Browser timezone
        var tzName = '';
        try { tzName = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { tzName = 'Unknown'; }
        var tzOffset = now.getTimezoneOffset();
        var tzSign = tzOffset <= 0 ? '+' : '-';
        var tzHrs = Math.abs(Math.floor(tzOffset / 60));
        var tzMin = Math.abs(tzOffset % 60);
        var tzStr = 'TZ: ' + tzName + ' (' + tzSign + pad(tzHrs) + ':' + pad(tzMin) + ')';
        document.getElementById('browserTZ').textContent = tzStr;
        document.getElementById('detailBrowserTZ').textContent = tzName + ' ' + tzSign + pad(tzHrs) + ':' + pad(tzMin);

        // Calculate drift (compare raw epoch seconds)
        var browserEpoch = Math.floor(now.getTime() / 1000);
        var serverEpoch = serverEpochAtLoad + elapsedSec;
        var diffSec = browserEpoch - serverEpoch;
        var absDiff = Math.abs(diffSec);

        document.getElementById('detailBrowserEpoch').textContent = browserEpoch;
        document.getElementById('detailDiffSec').textContent = diffSec + 's';

        // Drift display
        var driftEl = document.getElementById('driftValue');
        var statusEl = document.getElementById('driftStatus');
        var warningEl = document.getElementById('driftWarning');

        if (absDiff === 0) {
            driftEl.textContent = '0s';
            driftEl.className = 'drift-display drift-ok';
            statusEl.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Synchronized</span>';
            warningEl.classList.add('d-none');
        } else if (absDiff <= 2) {
            driftEl.textContent = (diffSec > 0 ? '+' : '') + diffSec + 's';
            driftEl.className = 'drift-display drift-ok';
            statusEl.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Within tolerance</span>';
            warningEl.classList.add('d-none');
        } else if (absDiff <= 5) {
            driftEl.textContent = (diffSec > 0 ? '+' : '') + diffSec + 's';
            driftEl.className = 'drift-display drift-warn';
            statusEl.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Minor drift</span>';
            warningEl.classList.add('d-none');
        } else {
            driftEl.textContent = (diffSec > 0 ? '+' : '') + diffSec + 's';
            driftEl.className = 'drift-display drift-danger';
            statusEl.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Significant drift</span>';
            warningEl.classList.remove('d-none');
        }
    }

    // Update every second
    update();
    setInterval(update, 1000);
})();
</script>

</body>
</html>
