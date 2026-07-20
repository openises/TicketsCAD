/**
 * NewUI v4.0 — Recent Activity (audit log) dashboard widget — Phase 80c
 *
 * Renders the last N entries from newui_audit_log inside the
 * "audit_log" GridStack tile. Row click → Bootstrap modal with the
 * full audit row (including details JSON, IP, etc).
 *
 * Polls api/dashboard-audit.php every 30 seconds and re-renders on
 * the global 'system:refresh' SSE event (so a fresh audit row from
 * another tab shows up almost immediately).
 *
 * ES5 only — no arrow functions, no template literals, no let/const.
 * Wrapped in an IIFE so nothing leaks to window except the small
 * AuditLogWidget facade used by tests / external triggers.
 */
var AuditLogWidget = (function () {
    'use strict';

    var POLL_INTERVAL_MS = 30000;
    var DEFAULT_LIMIT = 50;
    var WIDGET_ID = 'audit_log';

    var pollTimer = null;
    var lastEntries = [];
    var modalEl = null;
    var modalInstance = null;

    function init() {
        // Self-bind to the widget lifecycle. We don't assume the widget
        // is visible at page load — the user may have hidden it. The
        // EventBus 'widget:shown' / 'widget:refresh' hooks below cover
        // toggling-on-after-init.
        if (typeof EventBus === 'undefined') {
            // No EventBus on this page (we're probably not on the
            // dashboard). Nothing to do.
            return;
        }

        EventBus.on('widget:shown', function (data) {
            if (data && data.widget === WIDGET_ID) {
                render(lastEntries);
                startPolling();
            }
        });
        EventBus.on('widget:refresh', function (data) {
            if (data && data.widget === WIDGET_ID) {
                fetchAndRender();
            }
        });

        // SSE — refresh whenever the server signals general activity.
        // We don't have a dedicated 'audit:new' channel, so 'system:refresh'
        // and the per-domain events are the best signals we have. Keep
        // the list of triggers narrow to avoid hammering the endpoint.
        EventBus.on('system:refresh', fetchAndRender);
        EventBus.on('incident:new',    fetchAndRender);
        EventBus.on('incident:close',  fetchAndRender);

        // First fetch — but only if the widget is currently in the DOM.
        // If it isn't, the 'widget:shown' handler picks it up later.
        if (getTbody()) {
            fetchAndRender();
            startPolling();
        }
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(fetchAndRender, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function getTbody() {
        return document.getElementById('auditLogBody');
    }

    function fetchAndRender() {
        var tbody = getTbody();
        if (!tbody) return;

        var url = 'api/dashboard-audit.php?limit=' + DEFAULT_LIMIT;
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = 'login.php';
                    throw new Error('Not authenticated');
                }
                if (res.status === 403) {
                    // User lost the widget permission mid-session — render
                    // a friendly empty state instead of an error.
                    lastEntries = [];
                    renderForbidden();
                    throw new Error('Forbidden');
                }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                lastEntries = (data && data.entries) || [];
                render(lastEntries);
            })
            .catch(function (err) {
                // Swallow — the widget body keeps the previous data.
                if (window.console && console.warn) {
                    console.warn('AuditLogWidget fetch failed:', err && err.message);
                }
            });
    }

    function render(entries) {
        var tbody = getTbody();
        if (!tbody) return;

        if (!entries || entries.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary small py-3">'
                + '<i class="bi bi-journal-text d-block mb-1" style="font-size:1.25rem"></i>'
                + 'No recent activity'
                + '</td></tr>';
            return;
        }

        var html = '';
        var i;
        for (i = 0; i < entries.length; i++) {
            var e = entries[i];
            var sev = sevBadge(e.severity);
            html += '<tr class="audit-log-row" data-audit-id="' + (e.id | 0) + '" style="cursor:pointer">'
                + '<td class="text-nowrap small">' + esc(relativeTime(e.ts)) + '</td>'
                + '<td class="small">' + esc(truncate(e.actor_name || '—', 18)) + '</td>'
                + '<td class="small">' + sev + ' ' + esc(e.event_type || '') + '</td>'
                + '<td class="small">' + esc(truncate(formatTarget(e), 28)) + '</td>'
                + '</tr>';
        }
        tbody.innerHTML = html;

        // Wire row clicks (event delegation on the tbody — survives re-render
        // because we re-attach each time).
        tbody.onclick = function (ev) {
            var tr = ev.target.closest ? ev.target.closest('.audit-log-row') : null;
            if (!tr) {
                // Fallback for older browsers without Element.closest.
                var node = ev.target;
                while (node && node !== tbody && (!node.classList || !node.classList.contains('audit-log-row'))) {
                    node = node.parentNode;
                }
                if (!node || node === tbody) return;
                tr = node;
            }
            var id = parseInt(tr.getAttribute('data-audit-id'), 10);
            if (id > 0) openDetail(id);
        };
    }

    function renderForbidden() {
        var tbody = getTbody();
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary small py-3">'
            + '<i class="bi bi-lock d-block mb-1"></i>'
            + 'You do not have permission to view recent activity.'
            + '</td></tr>';
    }

    function openDetail(id) {
        ensureModal();
        var body = document.getElementById('auditLogModalBody');
        if (body) {
            body.innerHTML = '<div class="text-center text-body-secondary py-3">'
                + '<div class="spinner-border spinner-border-sm" role="status"></div>'
                + ' Loading...</div>';
        }
        modalInstance.show();

        fetch('api/dashboard-audit.php?id=' + (id | 0), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                renderDetail(data.entry || null);
            })
            .catch(function (err) {
                if (body) {
                    body.innerHTML = '<div class="text-danger small">Failed to load: '
                        + esc(err && err.message ? err.message : 'unknown error')
                        + '</div>';
                }
            });
    }

    function renderDetail(entry) {
        var body = document.getElementById('auditLogModalBody');
        if (!body) return;
        if (!entry) {
            body.innerHTML = '<div class="text-body-secondary">No data.</div>';
            return;
        }

        var rows = '';
        rows += detailRow('Time',       esc(entry.ts || ''));
        rows += detailRow('Event',      esc(entry.event_type || ''));
        rows += detailRow('Category',   esc(entry.category || ''));
        rows += detailRow('Severity',   sevBadge(entry.severity) + ' ' + esc(sevLabel(entry.severity)));
        rows += detailRow('Actor',      esc((entry.actor_name || '—') + (entry.actor_id ? ' (#' + (entry.actor_id | 0) + ')' : '')));
        rows += detailRow('Target',     esc(formatTarget(entry)));
        rows += detailRow('IP address', esc(entry.ip || '—'));
        rows += detailRow('Summary',    esc(entry.summary || ''));

        var detailsBlock = '';
        if (entry.details !== null && entry.details !== undefined) {
            var pretty;
            try {
                pretty = JSON.stringify(entry.details, null, 2);
            } catch (e) {
                pretty = String(entry.details);
            }
            detailsBlock = '<div class="mt-3">'
                + '<div class="small text-body-secondary mb-1">Details</div>'
                + '<pre class="small p-2 border rounded bg-body-tertiary" style="max-height:300px;overflow:auto">'
                + esc(pretty)
                + '</pre>'
                + '</div>';
        }

        body.innerHTML = '<table class="table table-sm mb-0">'
            + '<tbody>' + rows + '</tbody>'
            + '</table>'
            + detailsBlock;
    }

    function detailRow(label, valueHtml) {
        return '<tr>'
            + '<th class="text-body-secondary small" style="width:35%">' + esc(label) + '</th>'
            + '<td class="small">' + valueHtml + '</td>'
            + '</tr>';
    }

    function ensureModal() {
        if (modalEl) return;
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'auditLogModal';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-labelledby', 'auditLogModalLabel');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable">'
            + '<div class="modal-content">'
            + '<div class="modal-header py-2">'
            + '<h6 class="modal-title" id="auditLogModalLabel">'
            + '<i class="bi bi-journal-text me-1"></i>Audit event'
            + '</h6>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
            + '</div>'
            + '<div class="modal-body" id="auditLogModalBody"></div>'
            + '<div class="modal-footer py-2">'
            + '<a href="settings.php#audit-log" class="btn btn-sm btn-outline-primary">'
            + '<i class="bi bi-box-arrow-up-right me-1"></i>Open full audit log</a>'
            + '<button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>'
            + '</div>'
            + '</div>'
            + '</div>';
        document.body.appendChild(modalEl);

        if (window.bootstrap && bootstrap.Modal) {
            modalInstance = new bootstrap.Modal(modalEl);
        } else {
            // Fallback — show/hide via class. Should not happen on the
            // dashboard because Bootstrap is loaded globally.
            modalInstance = {
                show: function () { modalEl.classList.add('show'); modalEl.style.display = 'block'; },
                hide: function () { modalEl.classList.remove('show'); modalEl.style.display = 'none'; }
            };
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        str = String(str);
        return str.length > len ? str.substring(0, len - 1) + '…' : str;
    }

    function relativeTime(tsStr) {
        if (!tsStr) return '—';
        // MariaDB DATETIME comes back as 'YYYY-MM-DD HH:MM:SS' in server
        // time. Browsers parse the ISO form reliably; the space-separated
        // legacy form is iffy in Safari. Swap the space for 'T'.
        var iso = String(tsStr).replace(' ', 'T');
        var d = new Date(iso);
        if (isNaN(d.getTime())) return tsStr;
        var deltaSec = Math.floor((Date.now() - d.getTime()) / 1000);
        if (deltaSec < 0) return 'now';
        if (deltaSec < 60) return deltaSec + 's ago';
        if (deltaSec < 3600) return Math.floor(deltaSec / 60) + 'm ago';
        if (deltaSec < 86400) return Math.floor(deltaSec / 3600) + 'h ago';
        return Math.floor(deltaSec / 86400) + 'd ago';
    }

    function sevBadge(level) {
        var sev = level | 0;
        var colors = {
            0: 'secondary',
            1: 'info',
            2: 'success',
            3: 'warning',
            4: 'danger',
            5: 'danger'
        };
        var color = colors[sev] || 'secondary';
        return '<span class="badge bg-' + color + '" style="font-size:0.6rem;padding:1px 4px;vertical-align:middle">'
            + sev + '</span>';
    }

    function sevLabel(level) {
        var labels = {
            0: 'Unknown',
            1: 'Info',
            2: 'Low',
            3: 'Medium',
            4: 'High',
            5: 'Critical'
        };
        return labels[level | 0] || 'Unknown';
    }

    function formatTarget(entry) {
        var t = entry.target_table || '';
        var id = entry.target_id || '';
        if (t && id) return t + ' #' + id;
        if (t) return t;
        if (id) return '#' + id;
        return '—';
    }

    // Public facade — exposed for the test harness and any external
    // trigger that wants to force a refresh.
    return {
        init: init,
        refresh: fetchAndRender,
        _render: render,  // for unit-testable rendering
        _projectTarget: formatTarget
    };
})();

// Auto-init on DOM ready. Safe to call before the widget tile exists —
// the EventBus 'widget:shown' hook covers late activation.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', AuditLogWidget.init);
} else {
    AuditLogWidget.init();
}
