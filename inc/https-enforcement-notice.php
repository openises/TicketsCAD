<?php
/**
 * SPEC-STATUS.md gap B16 (2026-08-2x) — "Require HTTPS" enforcement banner.
 *
 * Self-contained include modeled on inc/health-banner.php and the
 * pending-migrations banner in inc/navbar.php: hidden div + one fetch per
 * page load + sessionStorage dismiss. Admin-only — api/https-enforcement-
 * status.php returns 403 to non-admins, and the fetch silently no-ops.
 *
 * Distinct from inc/http-encryption-notice.php's "operating without HTTPS
 * at all" weekly-acknowledge banner: that one fires whenever the BEST-
 * EFFORT check (fe_is_https(), i.e. is_https()) says no, regardless of any
 * setting. This one fires only when an admin has explicitly turned
 * require_https ON and the VERIFIED check (is_https_verified()) still
 * says no — a narrower, more specific, more actionable condition, so it
 * gets its own banner rather than piggybacking on that one. An install
 * that has never touched require_https never sees this banner at all.
 *
 * Wired into inc/navbar.php with:
 *   include_once __DIR__ . '/https-enforcement-notice.php';
 *
 * NEVER blocks anything — see inc/https-enforcement.php's docblock. This
 * file only decides whether to show a dismissible, informational strip.
 */
?>
<div class="alert alert-warning d-flex align-items-center justify-content-between mb-0 rounded-0 py-2 px-3 d-none" role="alert" id="httpsEnforcementBanner">
    <div class="small">
        <i class="bi bi-shield-exclamation me-2"></i>
        <strong>Require HTTPS is on, but this connection isn't verified as encrypted.</strong>
        <span id="httpsEnforcementBannerMsg"></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="settings.php#login-settings" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-gear me-1"></i>Review setting
        </a>
        <a href="documentation/?doc=HTTPS-VERIFICATION" target="_blank" rel="noopener" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-book me-1"></i>How this works
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDismissHttpsEnforcementBanner" title="Hide for this session">
            <i class="bi bi-x"></i>
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';
    // Only admins see this — the API returns 403 to non-admins, in which
    // case we silently no-op. Skip entirely if dismissed this session.
    if (sessionStorage.getItem('httpsEnforcementBannerDismissed') === '1') return;
    fetch('api/https-enforcement-status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.status === 200 ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.show_banner) return;
            var b = document.getElementById('httpsEnforcementBanner');
            if (!b) return;
            var span = document.getElementById('httpsEnforcementBannerMsg');
            if (span) span.textContent = ' ' + (data.message || '');
            b.classList.remove('d-none');
        })
        .catch(function () { /* silent */ });

    var dismiss = document.getElementById('btnDismissHttpsEnforcementBanner');
    if (dismiss) dismiss.addEventListener('click', function () {
        sessionStorage.setItem('httpsEnforcementBannerDismissed', '1');
        var b = document.getElementById('httpsEnforcementBanner');
        if (b) b.classList.add('d-none');
    });
})();
</script>
