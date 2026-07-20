<?php
/**
 * NewUI v4.0 — Pending Time Approvals
 *
 * Per-org queue of time entries awaiting approval. Visible only to
 * users holding `time_entry.approve`. Each row offers approve / reject
 * with a single click. Implements the deferred E3 task from the
 * rbac-redesign-2026-05 phase.
 */

require_once __DIR__ . '/config.php';
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


if (!rbac_can('time_entry.approve')) {
    http_response_code(404);   // constitution rule #27 — 404, not 403
    echo '<h1>Not found</h1>';
    exit;
}

$user     = e($_SESSION['user']);
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Pending Time Approvals — TicketsCAD</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<?php include_once __DIR__ . '/inc/navbar.php'; ?>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Pending Time Approvals</h4>
        <span class="badge bg-warning ms-3" id="pendingCount">—</span>
        <button class="btn btn-sm btn-outline-secondary ms-auto" id="btnRefresh">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary ms-2">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        Entries listed below were logged by members and are waiting on your review.
        Approve marks the entry final; reject sends it back to the member with a
        rejected status. Auto-approval is configured under
        <a href="settings.php#panel-roles-levels">Settings → Roles &amp; Permissions</a>.
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="text-center py-5 d-none" id="loadingSpinner">
                <div class="spinner-border text-primary"></div>
            </div>
            <div class="text-center py-5" id="emptyState">
                <i class="bi bi-check-circle" style="font-size:2rem; opacity:0.3;"></i>
                <div class="mt-2 text-body-secondary">No entries are waiting on approval.</div>
            </div>
            <div class="table-responsive d-none" id="tableWrap">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr>
                        <th>Submitted</th>
                        <th>Member</th>
                        <th>Activity</th>
                        <th>Started</th>
                        <th>Ended</th>
                        <th class="text-end">Hours</th>
                        <th>Notes</th>
                        <th class="text-end">Actions</th>
                    </tr></thead>
                    <tbody id="pendingBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js"></script>
<script>
(function () {
    'use strict';
    var loading  = document.getElementById('loadingSpinner');
    var empty    = document.getElementById('emptyState');
    var wrap     = document.getElementById('tableWrap');
    var body     = document.getElementById('pendingBody');
    var counter  = document.getElementById('pendingCount');
    var refresh  = document.getElementById('btnRefresh');
    var csrf     = document.getElementById('csrfToken').value;

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s == null ? '' : String(s)));
        return d.innerHTML;
    }

    function load() {
        loading.classList.remove('d-none');
        empty.classList.add('d-none');
        wrap.classList.add('d-none');
        fetch('api/time-entries.php?pending=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function (e) { alert('Failed to load: ' + e.message); });
    }

    function render(d) {
        loading.classList.add('d-none');
        var entries = (d && d.entries) || [];
        counter.textContent = entries.length + ' pending';
        if (!entries.length) { empty.classList.remove('d-none'); return; }
        wrap.classList.remove('d-none');
        var html = '';
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            var name = (e.first_name || '') + ' ' + (e.last_name || '');
            if (e.callsign) name += ' (' + e.callsign + ')';
            html += '<tr data-id="' + e.id + '">';
            html += '<td><small>' + esc((e.created_at || '').substring(0, 16)) + '</small></td>';
            html += '<td>' + esc(name.trim() || '#' + e.member_id) + '</td>';
            html += '<td>' + esc(e.activity_type) + '</td>';
            html += '<td><small>' + esc((e.started_at || '').substring(0, 16)) + '</small></td>';
            html += '<td><small>' + esc((e.ended_at   || '').substring(0, 16)) + '</small></td>';
            html += '<td class="text-end">' + parseFloat(e.hours || 0).toFixed(1) + '</td>';
            html += '<td><small class="text-body-secondary">' + esc(e.notes || '') + '</small></td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-sm btn-success me-1 act-btn" data-action="approve" data-id="' + e.id + '">';
            html += '<i class="bi bi-check-lg"></i> Approve</button>';
            html += '<button class="btn btn-sm btn-warning act-btn" data-action="reject" data-id="' + e.id + '">';
            html += '<i class="bi bi-x-lg"></i> Reject</button>';
            html += '</td></tr>';
        }
        body.innerHTML = html;
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('.act-btn');
        if (!btn) return;
        var action = btn.getAttribute('data-action');
        var id     = parseInt(btn.getAttribute('data-id'), 10);
        if (action === 'reject' && !confirm('Reject this entry?')) return;
        fetch('api/time-entries.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf, action: action, id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert(d.error); return; }
                load();
            });
    });

    refresh.addEventListener('click', load);
    load();
})();
</script>
</body>
</html>
