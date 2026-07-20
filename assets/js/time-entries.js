/**
 * NewUI v4.0 -- time-entries.php page controller (Phase 80d)
 *
 * Drives the volunteer-hours page:
 *   - left panel: filterable, grouped-by-month entry list
 *   - right panel: detail/edit form
 *   - bottom: hour totals (week / month / year / pending)
 *   - approval queue tab (visible iff window.CAN_APPROVE_TIME)
 *
 * ES5, IIFE-wrapped, no template literals / arrows / let.
 */
(function () {
    'use strict';

    var CATEGORIES = [
        'training', 'drill', 'event', 'radio_net', 'meeting',
        'admin', 'public_education', 'deployment', 'response', 'other'
    ];

    var state = {
        memberId: null,
        entries: [],
        selectedId: null,
        filters: { month: '', category: '', status: '' },
        activityTypes: [],
        approvalQueue: []
    };

    // ── DOM helpers ─────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s == null ? '' : String(s)));
        return d.innerHTML;
    }
    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }
    function fmtLocalDt(dt) {
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()) +
               'T' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
    }
    function toDbDt(s) {
        // datetime-local -> 'Y-m-d H:i:s'
        if (!s) return null;
        return s.replace('T', ' ') + (s.length === 16 ? ':00' : '');
    }
    function statusBadge(st) {
        if (st === 'approved') return '<span class="badge bg-success">approved</span>';
        if (st === 'rejected') return '<span class="badge bg-danger">rejected</span>';
        return '<span class="badge bg-warning text-dark">pending</span>';
    }

    // ── API ─────────────────────────────────────────────────────────────
    function api(opts) {
        var url = opts.url;
        var init = { credentials: 'same-origin' };
        if (opts.method === 'POST') {
            init.method = 'POST';
            init.headers = { 'Content-Type': 'application/json' };
            init.body = JSON.stringify(opts.body || {});
        }
        return fetch(url, init).then(function (r) {
            return r.json().then(function (j) {
                if (j && j.error) {
                    throw new Error(j.error);
                }
                return j;
            });
        });
    }

    function loadActivityTypes() {
        return api({ url: 'api/time-entries.php?activity_types=1' })
            .then(function (d) {
                state.activityTypes = d.activity_types || [];
                var sel = $('fActivity');
                sel.innerHTML = '';
                for (var i = 0; i < state.activityTypes.length; i++) {
                    var t = state.activityTypes[i];
                    var opt = document.createElement('option');
                    opt.value = t.name;
                    opt.textContent = t.name;
                    sel.appendChild(opt);
                }
            });
    }

    function loadSummary() {
        return api({ url: 'api/time-entries.php?summary=1' })
            .then(function (d) {
                state.memberId = d.member_id;
                $('sumWeek').textContent  = (Math.round((d.week_hours  || 0) * 10) / 10).toFixed(1);
                $('sumMonth').textContent = (Math.round((d.month_hours || 0) * 10) / 10).toFixed(1);
                $('sumYear').textContent  = (Math.round((d.year_hours  || 0) * 10) / 10).toFixed(1);
                $('sumPending').textContent = d.pending_count || 0;
            });
    }

    function loadEntries() {
        if (!state.memberId) {
            $('entryListBody').innerHTML =
                '<div class="p-3 text-body-secondary">Your user is not linked to a member record.</div>';
            return Promise.resolve();
        }
        var qs = 'member_id=' + state.memberId;
        if (state.filters.month) {
            var firstDay = state.filters.month + '-01';
            qs += '&start_date=' + firstDay;
            // Compute last day inline
            var parts = state.filters.month.split('-');
            var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
            var lastDay = new Date(y, m, 0); // day 0 of next month
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            qs += '&end_date=' + lastDay.getFullYear() + '-' +
                  pad(lastDay.getMonth() + 1) + '-' + pad(lastDay.getDate());
        }
        return api({ url: 'api/time-entries.php?' + qs })
            .then(function (d) {
                state.entries = d.entries || [];
                renderEntryList();
            });
    }

    function renderEntryList() {
        var body = $('entryListBody');
        var filtered = state.entries.filter(function (e) {
            if (state.filters.category && (e.category || '') !== state.filters.category) return false;
            if (state.filters.status && (e.status || 'self_reported') !== state.filters.status) return false;
            return true;
        });
        if (!filtered.length) {
            body.innerHTML = '<div class="p-3 text-body-secondary text-center">No entries match.</div>';
            return;
        }
        // Group by YYYY-MM
        var groups = {};
        for (var i = 0; i < filtered.length; i++) {
            var key = (filtered[i].started_at || '').substring(0, 7);
            if (!groups[key]) groups[key] = [];
            groups[key].push(filtered[i]);
        }
        var keys = Object.keys(groups).sort().reverse();
        var html = '';
        for (var j = 0; j < keys.length; j++) {
            var k = keys[j];
            var rows = groups[k];
            var sum = 0;
            for (var x = 0; x < rows.length; x++) sum += parseFloat(rows[x].hours || 0);
            html += '<div class="te-month-hdr d-flex justify-content-between border-top">' +
                      '<span>' + esc(k) + '</span>' +
                      '<span class="text-body-secondary">' +
                        (Math.round(sum * 10) / 10).toFixed(1) + ' h</span>' +
                    '</div>';
            for (var y = 0; y < rows.length; y++) {
                var e = rows[y];
                var sel = (state.selectedId === e.id) ? ' active' : '';
                html += '<div class="te-entry-row border-bottom px-2 py-1' + sel +
                    '" data-id="' + e.id + '">' +
                    '<div class="d-flex justify-content-between align-items-center">' +
                      '<div class="small">' +
                        '<strong>' + esc((e.started_at || '').substring(0, 16)) + '</strong>' +
                        '<span class="ms-2 text-body-secondary">' +
                          esc(e.category || e.activity_type || '') + '</span>' +
                      '</div>' +
                      '<div>' +
                        '<span class="me-2 small">' +
                          (Math.round(parseFloat(e.hours || 0) * 10) / 10).toFixed(1) + ' h</span>' +
                        statusBadge(e.status || 'self_reported') +
                      '</div>' +
                    '</div>' +
                    (e.notes ? '<div class="small text-body-secondary text-truncate">' +
                        esc(e.notes) + '</div>' : '') +
                    '</div>';
            }
        }
        body.innerHTML = html;

        var rows2 = body.querySelectorAll('.te-entry-row');
        for (var z = 0; z < rows2.length; z++) {
            rows2[z].addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                selectEntry(id);
            });
        }
    }

    function selectEntry(id) {
        state.selectedId = id;
        var e = null;
        for (var i = 0; i < state.entries.length; i++) {
            if (state.entries[i].id === id) { e = state.entries[i]; break; }
        }
        if (!e) { clearForm(); return; }
        $('detailTitle').textContent = 'Edit entry #' + e.id;
        $('fId').value = e.id;
        $('fStart').value = (e.started_at || '').replace(' ', 'T').substring(0, 16);
        $('fEnd').value   = (e.ended_at   || '').replace(' ', 'T').substring(0, 16);
        $('fCategory').value = e.category || 'other';
        $('fActivity').value = e.activity_type || (state.activityTypes[0] && state.activityTypes[0].name) || '';
        $('fNotes').value = e.notes || '';
        $('fStatusLabel').innerHTML = statusBadge(e.status || 'self_reported');
        $('fHours').textContent = '(' + (Math.round(parseFloat(e.hours || 0) * 10) / 10).toFixed(1) + ' h)';
        var reject = $('fRejectReason');
        if (e.rejection_reason) {
            reject.textContent = 'Rejected: ' + e.rejection_reason;
            reject.classList.remove('d-none');
        } else {
            reject.classList.add('d-none');
        }
        var del = $('btnDelete');
        var locked = (e.status === 'approved');
        del.classList.toggle('d-none', locked);
        $('btnSave').disabled = locked;
        renderEntryList();
    }

    function clearForm() {
        state.selectedId = null;
        $('detailTitle').textContent = 'New entry';
        $('fId').value = '';
        var now = new Date();
        var hourAgo = new Date(now.getTime() - 60 * 60 * 1000);
        $('fStart').value = fmtLocalDt(hourAgo);
        $('fEnd').value   = fmtLocalDt(now);
        $('fCategory').value = 'other';
        $('fActivity').value = (state.activityTypes[0] && state.activityTypes[0].name) || '';
        $('fNotes').value = '';
        $('fStatusLabel').innerHTML = '<span class="badge bg-secondary">new</span>';
        $('fHours').textContent = '';
        $('fRejectReason').classList.add('d-none');
        $('btnDelete').classList.add('d-none');
        $('btnSave').disabled = false;
        renderEntryList();
    }

    function saveEntry(ev) {
        ev.preventDefault();
        var id = $('fId').value;
        var payload = {
            csrf_token: getCsrf(),
            started_at: toDbDt($('fStart').value),
            ended_at:   toDbDt($('fEnd').value),
            activity_type: $('fActivity').value,
            category: $('fCategory').value,
            notes: $('fNotes').value || null
        };
        if (id) {
            payload.action = 'update';
            payload.id = parseInt(id, 10);
        } else {
            payload.action = 'create';
            payload.member_id = state.memberId;
        }
        api({ url: 'api/time-entries.php', method: 'POST', body: payload })
            .then(function () {
                return Promise.all([loadSummary(), loadEntries()]);
            })
            .then(function () {
                clearForm();
            })
            .catch(function (e) { alert('Save failed: ' + e.message); });
    }

    function deleteEntry() {
        var id = parseInt($('fId').value, 10);
        if (!id) return;
        if (!confirm('Delete this entry?')) return;
        api({
            url: 'api/time-entries.php', method: 'POST',
            body: { csrf_token: getCsrf(), action: 'delete', id: id }
        })
        .then(function () {
            clearForm();
            return Promise.all([loadSummary(), loadEntries()]);
        })
        .catch(function (e) { alert('Delete failed: ' + e.message); });
    }

    // ── Filters ─────────────────────────────────────────────────────────
    function initFilters() {
        var catSel = $('filtCategory');
        for (var i = 0; i < CATEGORIES.length; i++) {
            var opt = document.createElement('option');
            opt.value = CATEGORIES[i];
            opt.textContent = CATEGORIES[i].replace(/_/g, ' ');
            catSel.appendChild(opt);
        }
        var formCat = $('fCategory');
        for (var j = 0; j < CATEGORIES.length; j++) {
            var opt2 = document.createElement('option');
            opt2.value = CATEGORIES[j];
            opt2.textContent = CATEGORIES[j].replace(/_/g, ' ');
            formCat.appendChild(opt2);
        }

        $('filtMonth').addEventListener('change', function () {
            state.filters.month = this.value;
            loadEntries();
        });
        $('filtCategory').addEventListener('change', function () {
            state.filters.category = this.value;
            renderEntryList();
        });
        $('filtStatus').addEventListener('change', function () {
            state.filters.status = this.value;
            renderEntryList();
        });
    }

    // ── Approval queue ──────────────────────────────────────────────────
    function loadApprovalQueue() {
        if (!window.CAN_APPROVE_TIME) return;
        return api({ url: 'api/time-entries.php?pending=1' })
            .then(function (d) {
                state.approvalQueue = d.entries || [];
                var badge = $('pendingBadge');
                if (badge) badge.textContent = state.approvalQueue.length;
                renderApprovalQueue();
            });
    }

    function renderApprovalQueue() {
        var body = $('approvalBody');
        if (!body) return;
        if (!state.approvalQueue.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center p-3 text-body-secondary">' +
                '<i class="bi bi-check-circle me-1"></i>Queue is empty.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < state.approvalQueue.length; i++) {
            var e = state.approvalQueue[i];
            var name = (e.first_name || '') + ' ' + (e.last_name || '');
            html += '<tr data-id="' + e.id + '">' +
                '<td><input type="checkbox" class="approval-cb" value="' + e.id + '" aria-label="Select"></td>' +
                '<td class="small"><small>' + esc((e.created_at || '').substring(0, 16)) + '</small></td>' +
                '<td class="small">' + esc(name.trim() || '#' + e.member_id) + '</td>' +
                '<td class="small">' + esc(e.category || '') + '</td>' +
                '<td class="small">' + esc(e.activity_type || '') + '</td>' +
                '<td class="small">' + esc((e.started_at || '').substring(0, 16)) + '</td>' +
                '<td class="text-end small">' +
                  (Math.round(parseFloat(e.hours || 0) * 10) / 10).toFixed(1) + '</td>' +
                '<td><small class="text-body-secondary">' + esc(e.notes || '') + '</small></td>' +
                '</tr>';
        }
        body.innerHTML = html;
    }

    function bulkAction(act) {
        var cbs = document.querySelectorAll('.approval-cb:checked');
        if (!cbs.length) { alert('Select at least one entry.'); return; }
        var ids = [];
        for (var i = 0; i < cbs.length; i++) ids.push(parseInt(cbs[i].value, 10));
        var reason = null;
        if (act === 'reject') {
            reason = prompt('Rejection reason (optional, max 255 chars):') || '';
        }
        var pending = ids.length;
        var failures = [];
        function done() {
            pending--;
            if (pending === 0) {
                if (failures.length) alert('Errors:\n' + failures.join('\n'));
                loadApprovalQueue();
                loadSummary();
            }
        }
        for (var k = 0; k < ids.length; k++) {
            (function (id) {
                var body = { csrf_token: getCsrf(), action: act, id: id };
                if (reason) body.rejection_reason = reason;
                api({ url: 'api/time-entries.php', method: 'POST', body: body })
                    .then(done)
                    .catch(function (e) { failures.push('#' + id + ': ' + e.message); done(); });
            })(ids[k]);
        }
    }

    // ── Boot ────────────────────────────────────────────────────────────
    function boot() {
        initFilters();
        $('btnNew').addEventListener('click', clearForm);
        $('btnCancel').addEventListener('click', clearForm);
        $('btnDelete').addEventListener('click', deleteEntry);
        $('entryForm').addEventListener('submit', saveEntry);

        if (window.CAN_APPROVE_TIME) {
            var ba = $('btnBulkApprove');
            var br = $('btnBulkReject');
            if (ba) ba.addEventListener('click', function () { bulkAction('approve'); });
            if (br) br.addEventListener('click', function () { bulkAction('reject'); });
            var selAll = $('approvalSelectAll');
            if (selAll) selAll.addEventListener('change', function () {
                var cbs = document.querySelectorAll('.approval-cb');
                for (var i = 0; i < cbs.length; i++) cbs[i].checked = selAll.checked;
            });
            // Lazy-load approval queue when tab is shown
            var tabBtn = $('tab-approval-tab');
            if (tabBtn) tabBtn.addEventListener('shown.bs.tab', loadApprovalQueue);
        }

        // Default month filter = current month
        var now = new Date();
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        $('filtMonth').value = now.getFullYear() + '-' + pad(now.getMonth() + 1);
        state.filters.month = $('filtMonth').value;

        loadActivityTypes()
            .then(loadSummary)
            .then(loadEntries)
            .then(clearForm)
            .then(function () {
                if (window.CAN_APPROVE_TIME) loadApprovalQueue();
            })
            .catch(function (e) {
                console.error('time-entries init failed:', e);
                var body = $('entryListBody');
                if (body) body.innerHTML = '<div class="p-3 text-danger">Failed to load: ' +
                    esc(e.message) + '</div>';
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
