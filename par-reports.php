<?php
/**
 * NewUI v4.0 — PAR Reports (Phase 16-followup, 2026-06-11)
 *
 * Two reports:
 *   1. Per-incident timeline — every cycle for a specific ticket
 *   2. Per-agency compliance — counts + ack rates over a date window
 *
 * Read-only; gated to anyone who can view incidents (RBAC).
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

// Allow anyone who can manage PAR OR view incidents.
if (!rbac_can('action.manage_par') && !rbac_can('action.view_incident') && !is_admin()) {
    header('Location: index.php?err=forbidden');
    exit;
}

$user     = e($_SESSION['user']);
$level    = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'par-reports';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>PAR Reports — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div class="container-fluid p-3">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-shield-check text-primary me-2"></i>PAR Reports
        </h5>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <ul class="nav nav-tabs mb-3" id="parReportTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-compliance" type="button">Agency Compliance</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-timeline" type="button">Incident Timeline</button></li>
    </ul>

    <div class="tab-content">
        <!-- ── Per-agency compliance ───────────────────────────────────── -->
        <div class="tab-pane fade show active" id="tab-compliance">
            <div class="card">
                <div class="card-body">
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">From</label>
                            <input type="date" class="form-control form-control-sm" id="compFrom">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">To</label>
                            <input type="date" class="form-control form-control-sm" id="compTo">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-primary" id="btnRunCompliance">
                                <i class="bi bi-play-fill me-1"></i>Run
                            </button>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExportCompliance" disabled>
                                <i class="bi bi-download me-1"></i>CSV
                            </button>
                        </div>
                    </div>

                    <div id="complianceResults" class="small text-body-secondary">
                        Pick a date range and click Run.
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Per-incident timeline ───────────────────────────────────── -->
        <div class="tab-pane fade" id="tab-timeline">
            <div class="card">
                <div class="card-body">
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Incident #</label>
                            <input type="number" class="form-control form-control-sm" id="tlTicket" min="1" placeholder="123">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-primary" id="btnRunTimeline">
                                <i class="bi bi-play-fill me-1"></i>Run
                            </button>
                        </div>
                    </div>
                    <div id="timelineResults" class="small text-body-secondary">
                        Enter an incident # and click Run.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    function esc(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    // Default to last 30 days
    var today = new Date();
    var from = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 30);
    function ymd(d) { return d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2); }
    document.getElementById('compFrom').value = ymd(from);
    document.getElementById('compTo').value   = ymd(today);

    document.getElementById('btnRunCompliance').addEventListener('click', function () {
        var f = document.getElementById('compFrom').value;
        var t = document.getElementById('compTo').value;
        var results = document.getElementById('complianceResults');
        results.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        fetch('api/par.php?action=report_compliance&from=' + encodeURIComponent(f) + '&to=' + encodeURIComponent(t),
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (data) {
                if (data.error) { results.innerHTML = '<div class="alert alert-danger small">' + esc(data.error) + '</div>'; return; }
                var s = data.summary || {};
                var html = '<div class="row g-2 mb-3">' +
                    '<div class="col-md-3"><div class="card border-info"><div class="card-body py-2"><div class="small text-body-secondary">Cycles</div><div class="display-6">' + (s.total_cycles || 0) + '</div></div></div></div>' +
                    '<div class="col-md-3"><div class="card border-success"><div class="card-body py-2"><div class="small text-body-secondary">Unit acks</div><div class="display-6 text-success">' + (s.acks || 0) + '</div></div></div></div>' +
                    '<div class="col-md-3"><div class="card border-danger"><div class="card-body py-2"><div class="small text-body-secondary">Missed</div><div class="display-6 text-danger">' + (s.missed || 0) + '</div></div></div></div>' +
                    '<div class="col-md-3"><div class="card border-warning"><div class="card-body py-2"><div class="small text-body-secondary">Ack rate</div><div class="display-6">' + (s.ack_rate_pct || 0) + '%</div></div></div></div>' +
                    '</div>';
                var rows = data.by_kind || [];
                html += '<h6 class="small fw-semibold">By cycle kind</h6>';
                html += '<table class="table table-sm"><thead><tr><th>Kind</th><th class="text-end">Cycles</th><th class="text-end">Acked</th><th class="text-end">Missed</th><th class="text-end">Ack %</th></tr></thead><tbody>';
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    html += '<tr><td>' + esc(r.kind) + '</td>' +
                            '<td class="text-end">' + r.cycles + '</td>' +
                            '<td class="text-end text-success">' + r.acked + '</td>' +
                            '<td class="text-end text-danger">' + r.missed + '</td>' +
                            '<td class="text-end">' + r.ack_rate_pct + '%</td></tr>';
                }
                html += '</tbody></table>';
                results.innerHTML = html;
                document.getElementById('btnExportCompliance').disabled = false;
                document.getElementById('btnExportCompliance').onclick = function () {
                    window.location.href = 'api/par.php?action=report_compliance&format=csv&from=' + encodeURIComponent(f) + '&to=' + encodeURIComponent(t);
                };
            });
    });

    document.getElementById('btnRunTimeline').addEventListener('click', function () {
        var tid = parseInt(document.getElementById('tlTicket').value, 10);
        var results = document.getElementById('timelineResults');
        if (!tid) { results.innerHTML = '<div class="alert alert-warning small">Enter an incident number.</div>'; return; }
        results.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        fetch('api/par.php?action=history&ticket=' + tid, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (data) {
                if (data.error) { results.innerHTML = '<div class="alert alert-danger small">' + esc(data.error) + '</div>'; return; }
                var cycles = data.history || [];
                if (cycles.length === 0) {
                    results.innerHTML = '<div class="alert alert-info small">No PAR cycles for incident #' + tid + '.</div>';
                    return;
                }
                var html = '<h6 class="small fw-semibold mb-2">Incident #' + tid + ' — ' + cycles.length + ' cycle(s)</h6>';
                html += '<table class="table table-sm"><thead><tr><th>Started</th><th>Kind</th><th>Status</th><th class="text-end">Units</th><th class="text-end">Acked</th><th class="text-end">Missed</th></tr></thead><tbody>';
                for (var i = 0; i < cycles.length; i++) {
                    var c = cycles[i];
                    html += '<tr><td>' + esc(c.initiated_at) + '</td>' +
                            '<td>' + esc(c.initiated_kind) + '</td>' +
                            '<td>' + esc(c.status) + '</td>' +
                            '<td class="text-end">' + c.units + '</td>' +
                            '<td class="text-end text-success">' + c.acked + '</td>' +
                            '<td class="text-end ' + (c.missed > 0 ? 'text-danger' : '') + '">' + c.missed + '</td></tr>';
                }
                html += '</tbody></table>';
                results.innerHTML = html;
            });
    });
})();
</script>
</body>
</html>
