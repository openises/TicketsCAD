/**
 * NewUI v4.0 — Global PAR-overdue alarm (Phase 28A, 2026-06-12).
 *
 * Loaded by inc/navbar.php on every page. Polls /api/par.php?action=overdue
 * every 10 seconds. If any active incident has its next PAR cycle past due,
 * displays a sticky red banner at the top of the viewport, plays the
 * parOverdue tone every 30 seconds, and pulses the browser tab title.
 *
 * Dispatchers can:
 *   * Click "Open #N" to jump to the specific incident's detail page
 *   * Snooze the alarm for 5 minutes (banner hides, tone mutes, badge still flashes)
 *
 * Skips quietly when:
 *   * PAR features are disabled at the master switch (par_enabled = false)
 *   * The user isn't authenticated (401 from the endpoint)
 *   * The user lacks RBAC view_incident + manage_par permissions (403)
 *
 * Pairs with incident-detail.js's incident-scoped banner — the global
 * banner shows ALL overdue incidents; the detail-page banner is specific
 * to whatever incident you're looking at. Both can be visible at the
 * same time; the global one sits above.
 */
(function () {
    'use strict';

    var POLL_MS  = 10000;     // 10s
    var TONE_MS  = 30000;     // 30s between repeated tones while overdue
    var BANNER_ID = 'parGlobalAlarmBanner';

    var snoozedUntil  = 0;    // epoch ms
    var lastToneAt    = 0;    // epoch ms
    var titleTimer    = null;
    var originalTitle = null;
    var bannerInjected = false;

    function injectBanner() {
        if (bannerInjected) return;
        bannerInjected = true;
        var html =
            '<div id="' + BANNER_ID + '" class="d-none" ' +
            '     style="position:sticky;top:0;z-index:1050;">' +
            '  <div class="alert alert-danger d-flex align-items-center gap-2 mb-2 mt-0 py-2 px-3" ' +
            '       style="border:2px solid #b02a37;box-shadow:0 2px 12px rgba(176,42,55,.5);' +
            '              animation: par-global-pulse 1.5s ease-in-out infinite;">' +
            '    <i class="bi bi-exclamation-triangle-fill fs-4"></i>' +
            '    <div class="flex-grow-1">' +
            '      <strong id="parGlobalAlarmTitle">PAR OVERDUE</strong>' +
            '      <span id="parGlobalAlarmDetail" class="ms-1"></span>' +
            '    </div>' +
            '    <div class="btn-group btn-group-sm" id="parGlobalAlarmIncidents"></div>' +
            '    <button type="button" class="btn btn-sm btn-outline-light" id="parGlobalAlarmSnooze" ' +
            '            title="Mute the alarm for 5 minutes">' +
            '      <i class="bi bi-bell-slash me-1"></i>Snooze 5m' +
            '    </button>' +
            '  </div>' +
            '</div>';
        var style = document.createElement('style');
        style.textContent = '@keyframes par-global-pulse { 0%,100% { opacity:1 } 50% { opacity:0.7 } }';
        document.head.appendChild(style);

        // Insert at the very top of <body>, after any navbar
        var firstChild = document.body.firstChild;
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        document.body.insertBefore(wrap.firstElementChild, firstChild);

        document.getElementById('parGlobalAlarmSnooze').addEventListener('click', function () {
            snoozedUntil = Date.now() + 5 * 60 * 1000;
            hideBanner();
        });
    }

    function showBanner(incidents) {
        injectBanner();
        var banner = document.getElementById(BANNER_ID);
        var detail = document.getElementById('parGlobalAlarmDetail');
        var btns   = document.getElementById('parGlobalAlarmIncidents');
        var title  = document.getElementById('parGlobalAlarmTitle');
        if (!banner || !detail || !btns || !title) return;

        if (Date.now() < snoozedUntil) return;  // muted

        banner.classList.remove('d-none');

        // Headline + per-incident jump buttons (cap at 4 to keep tidy)
        var worst = incidents[0];
        var overSecs = parseInt(worst.overdue_seconds, 10) || 0;
        var m = Math.floor(overSecs / 60);
        var s = overSecs % 60;
        var howLong = (m > 0 ? m + 'm ' : '') + s + 's overdue';
        if (incidents.length === 1) {
            title.textContent = 'PAR OVERDUE on Incident ' + (worst.incident_number || ('#' + worst.ticket_id));
            detail.textContent = '— ' + (worst.scope || '') + ' (' + howLong + ').';
        } else {
            title.textContent = 'PAR OVERDUE on ' + incidents.length + ' incidents';
            detail.textContent = '— worst: #' + worst.ticket_id + ' ' +
                                 (worst.scope ? '"' + worst.scope + '" ' : '') + '(' + howLong + ').';
        }

        var btnHtml = '';
        for (var i = 0; i < Math.min(incidents.length, 4); i++) {
            var inc = incidents[i];
            btnHtml += '<a class="btn btn-light" href="incident-detail.php?id=' + inc.ticket_id +
                       '" title="' + esc(inc.scope || '') + '">' +
                       '<i class="bi bi-box-arrow-up-right me-1"></i>#' + inc.ticket_id + '</a>';
        }
        btns.innerHTML = btnHtml;

        // Pulse the browser tab title
        if (originalTitle === null) originalTitle = document.title;
        if (!titleTimer) {
            var toggle = false;
            titleTimer = setInterval(function () {
                toggle = !toggle;
                document.title = toggle ? '🚨 PAR OVERDUE — ' + originalTitle : originalTitle;
            }, 1000);
        }

        // Audio every 30s while overdue.
        // Respect the user's Sound / Alerts → "PAR Overdue" toggle.
        // playTone() itself doesn't gate by per-tone pref because it's
        // also wired to the admin test button which should always
        // sound; we gate here at the auto-trigger.
        if (window.AudioAlerts && (Date.now() - lastToneAt) >= TONE_MS) {
            var p = (window.AudioAlerts.getPrefs && window.AudioAlerts.getPrefs()) || {};
            if (p.parOverdue !== false) {
                try { window.AudioAlerts.playTone('parOverdue'); } catch (e) {}
            }
            lastToneAt = Date.now();
        }
    }

    function hideBanner() {
        var banner = document.getElementById(BANNER_ID);
        if (banner) banner.classList.add('d-none');
        if (titleTimer) {
            clearInterval(titleTimer);
            titleTimer = null;
            if (originalTitle !== null) {
                document.title = originalTitle;
                originalTitle = null;
            }
        }
    }

    function poll() {
        fetch('api/par.php?action=overdue', { credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401 || r.status === 403) return null;   // not logged in / no perm — skip silently
                return r.json();
            })
            .then(function (data) {
                if (!data) return;
                if (!data.enabled || !data.incidents || data.incidents.length === 0) {
                    hideBanner();
                    return;
                }
                showBanner(data.incidents);
            })
            .catch(function () { /* network blip — try again on next poll */ });
    }

    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML.replace(/"/g, '&quot;');
    }

    function start() {
        // Don't run on login/register/public pages — they don't need this.
        var p = window.location.pathname || '';
        if (/(login|register|public|install)\.php/i.test(p)) return;
        poll();
        setInterval(poll, POLL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
