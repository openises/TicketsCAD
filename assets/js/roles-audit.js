/**
 * NewUI v4.0 — Roles & Permissions: Audit Trail tab
 *
 * Filtered view of newui_audit_log entries where category = 'rbac'.
 * Pulls from api/audit-log.php (existing endpoint), renders a small
 * filterable table, and offers CSV export.
 */
(function () {
    'use strict';

    var listEl     = document.getElementById('auditLogList');
    var searchUser = document.getElementById('auditSearchUser');
    var filterAct  = document.getElementById('auditFilterActivity');
    var fromDate   = document.getElementById('auditFromDate');
    var toDate     = document.getElementById('auditToDate');
    var btnExport  = document.getElementById('btnAuditExport');

    if (!listEl) return;

    var lastEntries = [];

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s == null ? '' : String(s)));
        return d.innerHTML;
    }

    function buildQuery() {
        var q = ['category=rbac'];
        if (searchUser.value) q.push('user=' + encodeURIComponent(searchUser.value));
        if (filterAct.value)  q.push('activity=' + encodeURIComponent(filterAct.value));
        if (fromDate.value)   q.push('date_from=' + encodeURIComponent(fromDate.value));
        if (toDate.value)     q.push('date_to=' + encodeURIComponent(toDate.value));
        q.push('limit=200');
        return q.join('&');
    }

    function load() {
        listEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        fetch('api/audit-log.php?' + buildQuery(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function (e) {
                listEl.innerHTML = '<div class="alert alert-danger small">' + esc(e.message) + '</div>';
            });
    }

    function render(data) {
        var entries = (data && data.entries) || [];
        lastEntries = entries;
        if (!entries.length) {
            listEl.innerHTML = '<div class="text-body-secondary text-center py-3">No audit entries match the filters.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm table-striped mb-0">';
        html += '<thead><tr><th>When</th><th>Who</th><th>Activity</th><th>Target</th><th>Summary</th><th>Details</th></tr></thead><tbody>';
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            var details = e.details ? '<small class="text-body-tertiary">' + esc(typeof e.details === 'string' ? e.details : JSON.stringify(e.details)) + '</small>' : '';
            html += '<tr>';
            html += '<td><small>' + esc((e.event_time || '').substring(0, 19)) + '</small></td>';
            html += '<td>' + esc(e.user_name || ('#' + e.user_id)) + '</td>';
            html += '<td><span class="badge bg-secondary">' + esc(e.activity) + '</span></td>';
            html += '<td><small>' + esc(e.target_type) + ' #' + esc(e.target_id || '?') + '</small></td>';
            html += '<td>' + esc(e.summary || '') + '</td>';
            html += '<td>' + details + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        html += '<div class="text-end mt-2 small text-body-secondary">' + entries.length + ' entries' + (data.total > entries.length ? ' (of ' + data.total + ' total)' : '') + '</div>';
        listEl.innerHTML = html;
    }

    function exportCsv() {
        if (!lastEntries.length) { alert('Nothing to export — load some entries first.'); return; }
        var rows = [['event_time','user_name','activity','target_type','target_id','summary','details']];
        for (var i = 0; i < lastEntries.length; i++) {
            var e = lastEntries[i];
            rows.push([
                e.event_time || '',
                e.user_name || ('#' + e.user_id),
                e.activity || '',
                e.target_type || '',
                e.target_id || '',
                e.summary || '',
                typeof e.details === 'string' ? e.details : JSON.stringify(e.details || {})
            ]);
        }
        var csv = rows.map(function (r) {
            return r.map(function (c) {
                var s = String(c == null ? '' : c);
                if (/[,"\n]/.test(s)) s = '"' + s.replace(/"/g, '""') + '"';
                return s;
            }).join(',');
        }).join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = 'rbac-audit-' + (new Date().toISOString().substring(0, 10)) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Bind filters with debounce on text input.
    var debounce;
    searchUser.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(load, 300);
    });
    filterAct.addEventListener('change', load);
    fromDate.addEventListener('change', load);
    toDate.addEventListener('change', load);
    btnExport.addEventListener('click', exportCsv);

    // Lazy-load on first tab activation (if hosted in a tab).
    var auditTabBtn = document.querySelector('[data-bs-target="#tab-audit"]');
    if (auditTabBtn) {
        auditTabBtn.addEventListener('shown.bs.tab', function () {
            if (!lastEntries.length) load();
        });
    } else {
        // Hosted as a section (settings.php Roles & Permissions panel) — load now.
        load();
    }
})();
