<?php
// GH #41 (2026-07-04): installation-health banner.
//   Self-contained include modeled on the pending-migrations banner in
//   inc/navbar.php: hidden div + one fetch per page load + sessionStorage
//   dismiss. The orchestrator wires it into navbar.php with:
//     include_once __DIR__ . '/health-banner.php';
//
//   Shows ONLY when api/health-check.php reports summary.critical > 0 —
//   i.e. a required dir is unwritable by the web user, files under
//   assets/js/ or api/ are unreadable (the "new JS 404s silently after a
//   git pull as root" failure), or the server is executing stale opcache
//   code. Non-admins get a 403 from the API and the banner silently
//   no-ops. Detect and warn only — never auto-fix.
?>
<div class="alert alert-danger d-flex align-items-center justify-content-between mb-0 rounded-0 py-2 px-3 d-none" role="alert" id="healthCheckBanner">
    <div class="small">
        <i class="bi bi-shield-exclamation me-2"></i>
        <strong>Installation health:</strong>
        <span id="healthCheckBannerCount"></span>
        &mdash; <a href="status.php#health" class="alert-link">view details</a>.
        New files may be unreadable by the web server or the server may be running stale code.
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="status.php#health" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-heart-pulse me-1"></i>System Health
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDismissHealthBanner" title="Hide for this session">
            <i class="bi bi-x"></i>
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';
    // Only admins see this — the API returns 403 to non-admins, in which
    // case we silently no-op. Skip entirely if dismissed this session.
    if (sessionStorage.getItem('healthBannerDismissed') === '1') return;
    fetch('api/health-check.php', { credentials: 'same-origin' })
        .then(function (r) { return r.status === 200 ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.summary) return;
            var crit = data.summary.critical || 0;
            if (crit <= 0) return;
            var b = document.getElementById('healthCheckBanner');
            if (!b) return;
            var span = document.getElementById('healthCheckBannerCount');
            if (span) {
                span.textContent = crit + ' critical issue' + (crit === 1 ? '' : 's');
            }
            b.classList.remove('d-none');
        })
        .catch(function () { /* silent */ });

    var dismiss = document.getElementById('btnDismissHealthBanner');
    if (dismiss) dismiss.addEventListener('click', function () {
        sessionStorage.setItem('healthBannerDismissed', '1');
        var b = document.getElementById('healthCheckBanner');
        if (b) b.classList.add('d-none');
    });
})();
</script>
