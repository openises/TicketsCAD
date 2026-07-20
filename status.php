<?php
/**
 * NewUI v4.0 - System Status
 *
 * Admin-only page showing real-time component health: DB, PHP, OS,
 * Web Server, Zello Proxy, Disk, Sessions, Cache. Auto-refreshes.
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
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>System Status — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo NEWUI_VERSION; ?>">
    <style>
        .status-card {
            border-radius: 6px;
            border: 1px solid var(--bs-border-color);
            background: var(--bs-body-bg);
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .status-card:hover {
            border-color: var(--bs-primary);
        }
        .status-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--bs-secondary-bg);
            border-bottom: 1px solid var(--bs-border-color);
        }
        .status-card-icon {
            font-size: 1.4rem;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .status-card-icon.status-ok   { background: rgba(25, 135, 84, 0.15); color: var(--bs-success); }
        .status-card-icon.status-warn { background: rgba(255, 193, 7, 0.15); color: var(--bs-warning); }
        .status-card-icon.status-error { background: rgba(220, 53, 69, 0.15); color: var(--bs-danger); }
        .status-card-icon.status-unknown { background: rgba(108, 117, 125, 0.15); color: var(--bs-secondary); }

        .status-card-title {
            font-weight: 600;
            font-size: 0.9rem;
        }
        .status-card-message {
            font-size: 0.78rem;
            opacity: 0.7;
        }
        .status-badge {
            margin-left: auto;
            flex-shrink: 0;
        }
        .status-card-body {
            padding: 10px 14px;
            font-size: 0.8rem;
        }
        .status-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3px 0;
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }
        .status-detail-row:last-child {
            border-bottom: none;
        }
        .status-detail-label {
            opacity: 0.6;
            font-size: 0.75rem;
        }
        .status-detail-value {
            font-family: var(--bs-font-monospace);
            font-size: 0.78rem;
        }
        .status-overall {
            font-size: 1rem;
            padding: 10px 16px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .status-overall.overall-ok {
            background: rgba(25, 135, 84, 0.1);
            border: 1px solid rgba(25, 135, 84, 0.3);
            color: var(--bs-success);
        }
        .status-overall.overall-warn {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            color: var(--bs-warning);
        }
        .status-overall.overall-error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: var(--bs-danger);
        }
        .refresh-spinner {
            display: none;
            animation: spin 1s linear infinite;
        }
        .refreshing .refresh-spinner {
            display: inline-block;
        }
        .refreshing .refresh-icon {
            display: none;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .auto-refresh-label {
            font-size: 0.72rem;
            opacity: 0.6;
        }
        /* Event history */
        .event-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px;
            font-size: 0.78rem;
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }
        .event-row:last-child { border-bottom: none; }
        .event-row:hover { background: var(--bs-secondary-bg); }
        .event-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            flex-shrink: 0;
        }
        .event-icon.evt-start     { background: rgba(25,135,84,0.15); color: var(--bs-success); }
        .event-icon.evt-recovered { background: rgba(25,135,84,0.15); color: var(--bs-success); }
        .event-icon.evt-crash     { background: rgba(220,53,69,0.15); color: var(--bs-danger); }
        .event-icon.evt-stop      { background: rgba(220,53,69,0.15); color: var(--bs-danger); }
        .event-icon.evt-restart   { background: rgba(13,110,253,0.15); color: var(--bs-primary); }
        .event-icon.evt-degraded  { background: rgba(255,193,7,0.15); color: var(--bs-warning); }
        .event-svc {
            font-weight: 600;
            min-width: 80px;
        }
        .event-time {
            color: var(--bs-secondary);
            font-family: var(--bs-font-monospace);
            font-size: 0.72rem;
            min-width: 130px;
            flex-shrink: 0;
        }
        .event-type {
            text-transform: capitalize;
        }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Config Layout: Sidebar + Content -->
<div class="config-layout">

    <!-- Settings Sidebar -->
    <?php $configActivePage = 'system-health'; include_once NEWUI_ROOT . '/inc/config-sidebar.php'; ?>

    <!-- Main Content -->
    <main class="config-content" id="configContent" style="padding: 1rem 1.5rem;">

    <!-- Page title + controls -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-heart-pulse text-primary me-2"></i>System Status
        </h5>
        <div class="d-flex gap-3 align-items-center">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                <label class="form-check-label small" for="autoRefresh">
                    Auto-refresh <span class="auto-refresh-label" id="refreshCountdown">(30s)</span>
                </label>
            </div>
            <button class="btn btn-sm btn-outline-primary" id="refreshBtn" title="Refresh Now">
                <i class="bi bi-arrow-clockwise refresh-icon"></i>
                <i class="bi bi-arrow-clockwise refresh-spinner"></i>
                Refresh
            </button>
        </div>
    </div>

    <!-- Overall Status Banner -->
    <div class="status-overall overall-ok mb-3" id="overallBanner">
        <i class="bi bi-check-circle-fill"></i>
        <span id="overallText">Loading...</span>
        <span class="ms-auto text-body-secondary" style="font-size:0.75rem" id="lastChecked"></span>
    </div>

    <!-- Component Grid -->
    <div class="row g-3" id="statusGrid">
        <!-- Cards will be rendered by JS -->
        <div class="col-12 text-center text-body-secondary py-4">
            <div class="spinner-border spinner-border-sm me-2"></div>
            Loading component status...
        </div>
    </div>

    <!-- Service Event History -->
    <div class="mt-4 mb-2">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0">
                <i class="bi bi-clock-history text-primary me-2"></i>Service Event History
            </h6>
            <div class="d-flex gap-2 align-items-center">
                <select class="form-select form-select-sm" id="historyDays" style="width:auto">
                    <option value="1">Last 24 hours</option>
                    <option value="7" selected>Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="90">Last 90 days</option>
                </select>
                <select class="form-select form-select-sm" id="historyService" style="width:auto">
                    <option value="">All Services</option>
                    <option value="database">Database</option>
                    <option value="php">PHP</option>
                    <option value="os">Host OS</option>
                    <option value="webserver">Web Server</option>
                    <option value="zello_proxy">Zello Proxy</option>
                    <option value="disk">Disk</option>
                    <option value="sessions">Sessions</option>
                    <option value="cache">Cache</option>
                </select>
            </div>
        </div>
        <div id="eventHistory" class="border rounded" style="max-height:320px;overflow-y:auto">
            <div class="text-center text-body-secondary py-3 small">
                <div class="spinner-border spinner-border-sm me-1"></div>
                Loading event history...
            </div>
        </div>
    </div>

    <!-- File & Code Health (GH #41) — installation health / permission /
         opcache-staleness checks. Runs as the web user, so writability
         and readability answers here are authoritative. -->
    <div class="mt-4 mb-2" id="health">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0">
                <i class="bi bi-shield-check text-primary me-2"></i>File &amp; Code Health
                <span class="badge bg-secondary" id="fchOverallBadge">checking…</span>
            </h6>
            <button class="btn btn-sm btn-outline-primary" id="fchRefreshBtn" title="Re-run health check">
                <i class="bi bi-arrow-clockwise"></i> Re-check
            </button>
        </div>
        <div class="border rounded p-3" id="fchBody">
            <div class="text-center text-body-secondary py-3 small">
                <div class="spinner-border spinner-border-sm me-1"></div>
                Running installation health check...
            </div>
        </div>
        <small class="text-body-tertiary d-block mt-1">
            <i class="bi bi-info-circle me-1"></i>
            Checks run as the web server user — these answers are authoritative.
            Detection only; nothing is auto-fixed. CLI equivalent:
            <code>php tools/check-health.php</code>. See <code>docs/UPDATE-CHECKLIST.md</code>.
        </small>
    </div>

    <!-- Notes about Python Zello service (future) -->
    <div class="mt-3 mb-2">
        <small class="text-body-tertiary">
            <i class="bi bi-info-circle me-1"></i>
            Additional services (e.g., Python Zello service) will appear here as they are deployed.
            Status checks run against localhost services.
        </small>
    </div>

    </main>
</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- Sidebar section expand/collapse (shared with settings.php) -->
<script>
(function () {
    'use strict';
    // Section headers toggle their child lists
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
    // Tab links that are buttons → navigate to settings.php#tab
    var tabBtns = document.querySelectorAll('.config-tab-link[data-tab]');
    for (var j = 0; j < tabBtns.length; j++) {
        tabBtns[j].addEventListener('click', function () {
            var tab = this.getAttribute('data-tab');
            if (tab) window.location.href = 'settings.php#' + tab;
        });
    }
})();
</script>

<!-- Status Page JS -->
<script>
(function () {
    'use strict';

    var REFRESH_INTERVAL = 30; // seconds
    var timer = null;
    var countdown = REFRESH_INTERVAL;
    var refreshing = false;

    var grid           = document.getElementById('statusGrid');
    var overallBanner  = document.getElementById('overallBanner');
    var overallText    = document.getElementById('overallText');
    var lastCheckedEl  = document.getElementById('lastChecked');
    var refreshBtn     = document.getElementById('refreshBtn');
    var autoRefreshChk = document.getElementById('autoRefresh');
    var countdownEl    = document.getElementById('refreshCountdown');

    // Component metadata: icon, label
    var COMPONENT_META = {
        database:           { icon: 'bi-server',        label: 'Database',          color: 'primary' },
        php:                { icon: 'bi-filetype-php',   label: 'PHP Runtime',       color: 'info' },
        os:                 { icon: 'bi-pc-display',     label: 'Host OS',           color: 'secondary' },
        webserver:          { icon: 'bi-globe',          label: 'Web Server',        color: 'success' },
        zello_proxy:        { icon: 'bi-megaphone',      label: 'Zello Proxy',       color: 'warning' },
        disk:               { icon: 'bi-hdd',            label: 'Disk Space',        color: 'danger' },
        sessions:           { icon: 'bi-key',            label: 'Sessions',          color: 'primary' },
        cache:              { icon: 'bi-archive',        label: 'Cache',             color: 'info' },
        // Phase 26C (2026-06-11) — single card with expandable provider sub-rows.
        location_providers: { icon: 'bi-geo-alt-fill',   label: 'Location Providers', color: 'primary' }
    };

    // Detail labels
    var DETAIL_LABELS = {
        version: 'Version',
        uptime_text: 'Uptime',
        latency_ms: 'Latency',
        threads: 'Connections',
        queries: 'Total Queries',
        tables: 'Tables',
        size_mb: 'DB Size',
        sapi: 'SAPI',
        memory_limit: 'Memory Limit',
        max_execution_time: 'Max Exec Time',
        upload_max: 'Upload Max',
        extensions_loaded: 'Extensions',
        zend_version: 'Zend Version',
        os: 'Operating System',
        hostname: 'Hostname',
        architecture: 'Architecture',
        server_time: 'Server Time',
        timezone: 'Timezone',
        software: 'Software',
        protocol: 'Protocol',
        port: 'Port',
        https: 'HTTPS',
        service_type: 'Service Type',
        listening: 'Listening',
        pid: 'PID',
        channel: 'Dispatch Channel',
        used_pct: 'Used',
        total_text: 'Total',
        free_text: 'Free',
        handler: 'Handler',
        count: 'Active Sessions',
        writable: 'Writable',
        file_count: 'Files',
        size_text: 'Size'
    };

    // Skip these detail keys (shown elsewhere or not useful)
    var SKIP_KEYS = [
        'total_bytes', 'free_bytes', 'configured', 'uptime_sec', 'exists',
        'save_path', 'doc_root', 'path', 'os_family', 'missing_required'
    ];

    function fetchHealth() {
        if (refreshing) return;
        refreshing = true;
        refreshBtn.classList.add('refreshing');

        fetch('api/health.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                renderStatus(data);
            })
            .catch(function (err) {
                overallBanner.className = 'status-overall overall-error mb-3';
                overallText.textContent = 'Failed to load status: ' + err.message;
            })
            .finally(function () {
                refreshing = false;
                refreshBtn.classList.remove('refreshing');
                countdown = REFRESH_INTERVAL;
            });
    }

    function renderStatus(data) {
        // Overall banner
        var overall = data.overall || 'unknown';
        overallBanner.className = 'status-overall overall-' + overall + ' mb-3';

        var iconMap = { ok: 'bi-check-circle-fill', warn: 'bi-exclamation-triangle-fill', error: 'bi-x-circle-fill' };
        var textMap = { ok: 'All Systems Operational', warn: 'Some Components Need Attention', error: 'System Issues Detected' };

        overallBanner.innerHTML =
            '<i class="bi ' + (iconMap[overall] || 'bi-question-circle') + '"></i>' +
            '<span>' + (textMap[overall] || 'Unknown Status') + '</span>' +
            '<span class="ms-auto text-body-secondary" style="font-size:0.75rem">Last checked: ' +
            (data.timestamp || '') + '</span>';

        // Render component cards
        var components = data.components || {};
        var html = '';
        var order = ['database', 'php', 'os', 'webserver', 'zello_proxy', 'location_providers', 'disk', 'sessions', 'cache'];

        for (var i = 0; i < order.length; i++) {
            var key = order[i];
            var comp = components[key];
            if (!comp) continue;

            var meta = COMPONENT_META[key] || { icon: 'bi-question-circle', label: key, color: 'secondary' };
            html += renderCard(key, meta, comp);
        }

        grid.innerHTML = html;
    }

    function renderCard(key, meta, comp) {
        var status  = comp.status || 'unknown';
        var message = esc(comp.message || '');
        var details = comp.details || {};

        var badgeClass = {
            ok: 'bg-success', warn: 'bg-warning text-dark', error: 'bg-danger', unknown: 'bg-secondary'
        };
        var badgeText = { ok: 'OK', warn: 'Warning', error: 'Error', unknown: 'Unknown' };

        var html = '<div class="col-md-6 col-xl-4">';
        html += '<div class="status-card">';

        // Header
        html += '<div class="status-card-header">';
        html += '<div class="status-card-icon status-' + status + '"><i class="bi ' + meta.icon + '"></i></div>';
        html += '<div>';
        html += '<div class="status-card-title">' + esc(meta.label) + '</div>';
        html += '<div class="status-card-message">' + message + '</div>';
        html += '</div>';
        html += '<span class="badge ' + (badgeClass[status] || 'bg-secondary') + ' status-badge">' +
                (badgeText[status] || 'Unknown') + '</span>';
        html += '</div>';

        // Phase 26C (2026-06-11) — location_providers gets a dedicated
        // sub-row render (one expandable line per provider).
        if (key === 'location_providers' && details && details.providers) {
            html += '<div class="status-card-body">';
            var provs = details.providers;
            if (!provs.length) {
                html += '<div class="text-body-secondary text-center py-1" style="font-size:0.75rem">No providers configured</div>';
            } else {
                for (var pi = 0; pi < provs.length; pi++) {
                    var p = provs[pi];
                    var dot = 'bg-secondary';
                    if (p.status === 'ok')             dot = 'bg-success';
                    else if (p.status === 'passive')   dot = 'bg-info';        // Phase 41: browser-driven, awaiting reports
                    else if (p.status === 'stale')     dot = 'bg-warning';
                    else if (p.status === 'no_data')   dot = 'bg-warning';
                    else if (p.status === 'disabled')  dot = 'bg-secondary';
                    var ageTxt = p.age_seconds === null || p.age_seconds === undefined
                        ? 'never' : formatProviderAge(p.age_seconds);
                    // Phase 41: hint text on the second row when the provider is
                    // "passive" (browser-driven, no server-side reports yet) so
                    // the admin knows it's normal, not an error.
                    var statusHint = '';
                    if (p.status === 'passive') statusHint = ' <small class="text-info"><i class="bi bi-info-circle"></i> waiting for first browser report</small>';
                    else if (p.status === 'no_data') statusHint = ' <small class="text-warning"><i class="bi bi-exclamation-circle"></i> no data yet — verify provider service is running</small>';
                    else if (p.status === 'stale') statusHint = ' <small class="text-warning"><i class="bi bi-clock-history"></i> last report >30 min ago</small>';

                    html += '<div class="status-detail-row">';
                    html += '<span class="status-detail-label">' +
                            '<span class="badge ' + dot + '" style="font-size:0.65rem;margin-right:6px;">&nbsp;</span>' +
                            esc(p.name) + ' <small class="text-body-secondary">(' + esc(p.code) + ')</small></span>';
                    html += '<span class="status-detail-value">' +
                            (p.enabled ? '' : '<span class="badge bg-secondary me-1" style="font-size:0.65rem">off</span>') +
                            (p.receive_count_24h || 0) + ' / 24h · last: ' + esc(ageTxt) + statusHint + '</span>';
                    html += '</div>';
                }
            }
            html += '</div></div></div>';
            return html;
        }

        // Body: detail rows
        html += '<div class="status-card-body">';
        var detailKeys = Object.keys(details);
        var hasRows = false;
        for (var j = 0; j < detailKeys.length; j++) {
            var dk = detailKeys[j];
            if (SKIP_KEYS.indexOf(dk) !== -1) continue;
            var val = details[dk];
            if (val === null || val === undefined) continue;

            var label = DETAIL_LABELS[dk] || dk.replace(/_/g, ' ');

            // Format special values
            var displayVal = formatDetailValue(dk, val);

            html += '<div class="status-detail-row">';
            html += '<span class="status-detail-label">' + esc(label) + '</span>';
            html += '<span class="status-detail-value">' + displayVal + '</span>';
            html += '</div>';
            hasRows = true;
        }
        if (!hasRows) {
            html += '<div class="text-body-secondary text-center py-1" style="font-size:0.75rem">No details available</div>';
        }
        html += '</div>';

        html += '</div></div>';
        return html;
    }

    function formatDetailValue(key, val) {
        if (typeof val === 'boolean') {
            return val
                ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Yes</span>'
                : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> No</span>';
        }
        if (key === 'latency_ms') return esc(val + ' ms');
        if (key === 'used_pct')   return esc(val + '%');
        if (key === 'size_mb')    return esc(val + ' MB');
        if (key === 'max_execution_time') return esc(val + 's');
        if (key === 'queries')    return esc(Number(val).toLocaleString());
        return esc(String(val));
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatProviderAge(secs) {
        var s = parseInt(secs, 10);
        if (isNaN(s) || s < 0) return '—';
        if (s < 60)    return s + 's ago';
        if (s < 3600)  return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }

    // ── Auto-refresh ─────────────────────────────────────────────
    function startTimer() {
        if (timer) clearInterval(timer);
        countdown = REFRESH_INTERVAL;
        timer = setInterval(function () {
            if (!autoRefreshChk.checked) return;
            countdown--;
            countdownEl.textContent = '(' + countdown + 's)';
            if (countdown <= 0) {
                fetchHealth();
            }
        }, 1000);
    }

    refreshBtn.addEventListener('click', function () {
        fetchHealth();
    });

    autoRefreshChk.addEventListener('change', function () {
        if (autoRefreshChk.checked) {
            startTimer();
            countdownEl.style.display = '';
        } else {
            if (timer) clearInterval(timer);
            countdownEl.style.display = 'none';
        }
    });

    // ── Init ─────────────────────────────────────────────────────
    fetchHealth();
    startTimer();
})();

// ── Service Event History ──────────────────────────────────────
(function () {
    'use strict';

    var historyContainer = document.getElementById('eventHistory');
    var daysSelect       = document.getElementById('historyDays');
    var serviceSelect    = document.getElementById('historyService');

    var EVENT_ICONS = {
        start:     { icon: 'bi-play-circle-fill',      cls: 'evt-start' },
        recovered: { icon: 'bi-check-circle-fill',     cls: 'evt-recovered' },
        crash:     { icon: 'bi-x-circle-fill',         cls: 'evt-crash' },
        stop:      { icon: 'bi-stop-circle-fill',      cls: 'evt-stop' },
        restart:   { icon: 'bi-arrow-repeat',           cls: 'evt-restart' },
        degraded:  { icon: 'bi-exclamation-triangle-fill', cls: 'evt-degraded' }
    };

    var SVC_LABELS = {
        database: 'Database', php: 'PHP', os: 'Host OS', webserver: 'Web Server',
        zello_proxy: 'Zello Proxy', disk: 'Disk', sessions: 'Sessions', cache: 'Cache'
    };

    function fetchEvents() {
        var days = daysSelect.value;
        var svc = serviceSelect.value;
        var url = 'api/service-uptime.php?days=' + days;
        if (svc) url += '&service=' + encodeURIComponent(svc);

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                renderEvents(data.events || [], data.states || []);
            })
            .catch(function (err) {
                historyContainer.innerHTML = '<div class="text-center text-danger py-3 small">' +
                    'Failed to load history: ' + esc(err.message) + '</div>';
            });
    }

    function renderEvents(events, states) {
        if (events.length === 0) {
            historyContainer.innerHTML = '<div class="text-center text-body-secondary py-3 small">' +
                '<i class="bi bi-check-circle me-1"></i>No events recorded in this period — all quiet.</div>';
            return;
        }

        var html = '';
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            var meta = EVENT_ICONS[ev.event_type] || { icon: 'bi-question-circle', cls: '' };
            var svcLabel = SVC_LABELS[ev.service] || ev.service;

            html += '<div class="event-row">';
            html += '<div class="event-icon ' + meta.cls + '"><i class="bi ' + meta.icon + '"></i></div>';
            html += '<span class="event-time">' + esc(ev.detected_at) + '</span>';
            html += '<span class="event-svc">' + esc(svcLabel) + '</span>';
            html += '<span class="event-type badge bg-' + getBadgeColor(ev.event_type) + ' bg-opacity-75">' +
                    esc(ev.event_type) + '</span>';
            if (ev.uptime_seconds !== null) {
                html += '<span class="text-body-secondary small ms-auto">uptime: ' +
                        esc(formatUptime(parseInt(ev.uptime_seconds, 10))) + '</span>';
            }
            html += '</div>';
        }

        historyContainer.innerHTML = html;
    }

    function getBadgeColor(evtType) {
        var map = {
            start: 'success', recovered: 'success', crash: 'danger',
            stop: 'danger', restart: 'primary', degraded: 'warning'
        };
        return map[evtType] || 'secondary';
    }

    function formatUptime(sec) {
        if (isNaN(sec) || sec < 0) return '—';
        if (sec < 60) return sec + 's';
        var d = Math.floor(sec / 86400);
        var h = Math.floor((sec % 86400) / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var parts = [];
        if (d > 0) parts.push(d + 'd');
        if (h > 0) parts.push(h + 'h');
        if (m > 0) parts.push(m + 'm');
        return parts.join(' ');
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // Bind filter changes
    daysSelect.addEventListener('change', fetchEvents);
    serviceSelect.addEventListener('change', fetchEvents);

    // Initial load (slight delay to let health check populate initial states)
    setTimeout(fetchEvents, 2000);
})();

// ── File & Code Health (GH #41) ────────────────────────────────
(function () {
    'use strict';

    var body       = document.getElementById('fchBody');
    var badge      = document.getElementById('fchOverallBadge');
    var refreshBtn = document.getElementById('fchRefreshBtn');
    if (!body || !badge) return;

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str === null || str === undefined ? '' : String(str)));
        return div.innerHTML;
    }

    function sevBadge(sev) {
        if (sev === 'critical') return '<span class="badge bg-danger">Critical</span>';
        if (sev === 'warn')     return '<span class="badge bg-warning text-dark">Warning</span>';
        return '<span class="badge bg-success">OK</span>';
    }

    function yesNo(val) {
        if (val === null || val === undefined) return '<span class="text-body-secondary">n/a</span>';
        return val
            ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Yes</span>'
            : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> No</span>';
    }

    function render(data) {
        // Overall badge
        var crit = (data.summary && data.summary.critical) || 0;
        var warn = (data.summary && data.summary.warn) || 0;
        if (crit > 0) {
            badge.className = 'badge bg-danger';
            badge.textContent = crit + ' critical';
        } else if (warn > 0) {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = warn + ' warning' + (warn === 1 ? '' : 's');
        } else {
            badge.className = 'badge bg-success';
            badge.textContent = 'healthy';
        }

        var html = '';

        // ── Version row (stale-code detector) ─────────────────────
        var v = data.version || {};
        html += '<div class="status-detail-row">';
        html += '<span class="status-detail-label">Running code vs disk (opcache staleness)</span>';
        html += '<span class="status-detail-value">';
        if (v.severity === 'critical') {
            html += '<span class="text-danger fw-bold">STALE CODE</span> — running ' +
                    esc(v.running) + ', disk (' + esc(v.version_file || '?') + ') says ' + esc(v.on_disk) +
                    '. Reload: <code>sudo systemctl reload apache2</code> (or php-fpm)';
        } else {
            html += 'v' + esc(v.running) + ' matches ' + esc(v.version_file || 'disk') + ' ' + sevBadge('ok');
        }
        html += '</span></div>';

        // ── Opcache row ────────────────────────────────────────────
        var oc = data.opcache || {};
        html += '<div class="status-detail-row">';
        html += '<span class="status-detail-label">PHP opcache (' + esc(oc.sapi || '') + ')</span>';
        html += '<span class="status-detail-value">';
        if (!oc.enabled) {
            html += 'not enabled ' + sevBadge('ok');
        } else if (oc.validate_timestamps === false) {
            html += 'enabled, validate_timestamps=<strong>0</strong> ' + sevBadge('warn') +
                    ' — code changes on disk will NOT take effect until apache2/php-fpm is reloaded';
        } else {
            html += 'enabled, validate_timestamps=1, revalidate_freq=' +
                    esc(oc.revalidate_freq) + 's ' + sevBadge('ok');
        }
        html += '</span></div>';

        // ── Directories table ──────────────────────────────────────
        var dirs = (data.dirs && data.dirs.dirs) || [];
        html += '<div class="mt-3 mb-1 fw-semibold" style="font-size:0.8rem">' +
                '<i class="bi bi-folder2-open me-1"></i>Required-writable directories' +
                (data.process_user ? ' <small class="text-body-secondary">(as user: ' + esc(data.process_user) + ')</small>' : '') +
                '</div>';
        html += '<div class="table-responsive"><table class="table table-sm mb-2" style="font-size:0.78rem">';
        html += '<thead><tr><th>Path</th><th>Exists</th><th>Writable</th><th>Owner</th><th>Status</th></tr></thead><tbody>';
        for (var i = 0; i < dirs.length; i++) {
            var d = dirs[i];
            html += '<tr>';
            html += '<td><code>' + esc(d.path) + '</code><br><small class="text-body-secondary">' + esc(d.purpose || '') + '</small></td>';
            html += '<td>' + yesNo(d.exists) + '</td>';
            html += '<td>' + yesNo(d.exists ? d.writable : null) + '</td>';
            html += '<td>' + (d.owner ? '<code>' + esc(d.owner) + '</code>' : '<span class="text-body-secondary">n/a</span>') + '</td>';
            html += '<td>' + sevBadge(d.severity) + (d.note ? '<br><small class="text-body-secondary">' + esc(d.note) + '</small>' : '') + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';

        // ── Unreadable files list ──────────────────────────────────
        var un = data.unreadable || {};
        var files = un.unreadable || [];
        html += '<div class="mt-2 mb-1 fw-semibold" style="font-size:0.8rem">' +
                '<i class="bi bi-file-earmark-x me-1"></i>Unreadable files ' +
                '<small class="text-body-secondary">(assets/js/, api/, and the 20 most-recently-modified — ' +
                esc(un.scanned || 0) + ' probed)</small></div>';
        if (files.length === 0) {
            html += '<div class="text-success" style="font-size:0.78rem"><i class="bi bi-check-circle-fill me-1"></i>' +
                    'None — every probed file is readable by the web server.</div>';
        } else {
            html += '<div class="text-danger mb-1" style="font-size:0.78rem"><i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                    'These files exist on disk but the web server CANNOT read them — they 404 / silently fail ' +
                    '(a common result of <code>git pull</code> as root):</div>';
            html += '<ul class="mb-1" style="font-size:0.75rem;font-family:var(--bs-font-monospace)">';
            for (var j = 0; j < files.length; j++) {
                html += '<li>' + esc(files[j].path) + '</li>';
            }
            html += '</ul>';
            if (un.truncated) {
                html += '<div class="text-danger" style="font-size:0.75rem">…list truncated at 50 — there are more.</div>';
            }
        }

        body.innerHTML = html;
    }

    function fetchIt() {
        fetch('api/health-check.php', { credentials: 'same-origin' })
            .then(function (res) { return res.status === 200 ? res.json() : null; })
            .then(function (data) {
                if (!data) {
                    body.innerHTML = '<div class="text-body-secondary small py-2">Health check unavailable (requires admin access).</div>';
                    badge.className = 'badge bg-secondary';
                    badge.textContent = 'unavailable';
                    return;
                }
                render(data);
            })
            .catch(function (err) {
                body.innerHTML = '<div class="text-danger small py-2">Failed to run health check: ' + esc(err.message) + '</div>';
                badge.className = 'badge bg-secondary';
                badge.textContent = 'error';
            });
    }

    if (refreshBtn) refreshBtn.addEventListener('click', fetchIt);
    fetchIt();
})();
</script>

</body>
</html>
