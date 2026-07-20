/**
 * NewUI v4.0 -- "My time" dashboard widget (Phase 80d).
 *
 * Three big-number totals (this week / this month / this year) plus a
 * quick-add row at the bottom for logging hours without leaving the
 * dashboard. Clicking any total opens a modal with the recent entry
 * list and edit/submit controls.
 *
 * ES5, IIFE-wrapped, no dependencies beyond Bootstrap 5 + fetch().
 * Render hook: window.TimeEntriesWidget.render('#containerId') -- called
 * from index.php after WidgetManager spins up the GridStack tile.
 */
(function () {
    'use strict';

    var CATEGORIES = [
        'training', 'drill', 'event', 'radio_net', 'meeting',
        'admin', 'public_education', 'deployment', 'response', 'other'
    ];

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s == null ? '' : String(s)));
        return d.innerHTML;
    }

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function fetchSummary(cb) {
        fetch('api/time-entries.php?summary=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { cb(null, d); })
            .catch(function (e) { cb(e, null); });
    }

    function fetchRecent(cb) {
        fetch('api/time-entries.php?recent=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { cb(null, d); })
            .catch(function (e) { cb(e, null); });
    }

    function paint(container, summary) {
        var w = summary.week_hours  || 0;
        var m = summary.month_hours || 0;
        var y = summary.year_hours  || 0;
        var p = summary.pending_count || 0;
        container.innerHTML =
            '<div class="d-flex flex-column h-100 p-2">' +
              '<div class="row g-2 text-center mb-2 flex-grow-1 align-content-center">' +
                '<div class="col-4">' +
                  '<div class="stat-card te-stat" data-bucket="week" role="button" tabindex="0">' +
                    '<div class="stat-value">' + (Math.round(w * 10) / 10).toFixed(1) + '</div>' +
                    '<div class="stat-label">Week</div>' +
                  '</div>' +
                '</div>' +
                '<div class="col-4">' +
                  '<div class="stat-card te-stat" data-bucket="month" role="button" tabindex="0">' +
                    '<div class="stat-value">' + (Math.round(m * 10) / 10).toFixed(1) + '</div>' +
                    '<div class="stat-label">Month</div>' +
                  '</div>' +
                '</div>' +
                '<div class="col-4">' +
                  '<div class="stat-card te-stat" data-bucket="year" role="button" tabindex="0">' +
                    '<div class="stat-value">' + (Math.round(y * 10) / 10).toFixed(1) + '</div>' +
                    '<div class="stat-label">Year</div>' +
                  '</div>' +
                '</div>' +
              '</div>' +
              (p > 0 ? '<div class="text-center small text-warning mb-1">' +
                '<i class="bi bi-clock-history"></i> ' + p + ' awaiting approval</div>' : '') +
              '<div class="te-quickadd border-top pt-2">' +
                '<div class="input-group input-group-sm">' +
                  '<select class="form-select form-select-sm te-cat" aria-label="Category">' +
                    CATEGORIES.map(function (c) {
                        return '<option value="' + esc(c) + '">' + esc(c.replace(/_/g, ' ')) + '</option>';
                    }).join('') +
                  '</select>' +
                  '<input type="number" class="form-control form-control-sm te-mins" ' +
                    'value="60" min="15" max="1440" step="15" aria-label="Minutes">' +
                  '<button class="btn btn-sm btn-primary te-save" type="button">' +
                    '<i class="bi bi-plus-lg"></i> Log' +
                  '</button>' +
                '</div>' +
                '<div class="form-text small mt-1">Minutes (15-min increments). Logs ending now.</div>' +
              '</div>' +
            '</div>';

        // Click totals -> open modal with recent entries
        var stats = container.querySelectorAll('.te-stat');
        for (var i = 0; i < stats.length; i++) {
            stats[i].addEventListener('click', openListModal);
            stats[i].addEventListener('keypress', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    openListModal();
                }
            });
        }

        var saveBtn = container.querySelector('.te-save');
        saveBtn.addEventListener('click', function () {
            var cat  = container.querySelector('.te-cat').value;
            var mins = parseInt(container.querySelector('.te-mins').value, 10) || 0;
            if (mins < 15) { alert('Minimum 15 minutes.'); return; }
            quickAdd(cat, mins, function (err) {
                if (err) { alert('Save failed: ' + err); return; }
                refresh(container);
            });
        });
    }

    function refresh(container) {
        fetchSummary(function (err, data) {
            if (err) {
                container.innerHTML = '<div class="p-3 text-danger small">' +
                    'Failed to load time totals.</div>';
                return;
            }
            paint(container, data || {});
        });
    }

    function quickAdd(category, minutes, cb) {
        var end = new Date();
        var start = new Date(end.getTime() - minutes * 60 * 1000);
        function fmt(d) {
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }

        // Look up the actor's member_id first. The API requires it on create.
        fetch('api/time-entries.php?summary=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (s) {
                if (!s.member_id) { cb('Your user is not linked to a member record.'); return; }
                var payload = {
                    csrf_token: getCsrf(),
                    action: 'create',
                    member_id: s.member_id,
                    started_at: fmt(start),
                    ended_at:   fmt(end),
                    // The existing API gates activity_type against a lookup;
                    // the volunteer widget reuses the lookup row whose name
                    // matches "Other" so the create succeeds. Category is
                    // the volunteer-reporting bucket.
                    activity_type: 'Other',
                    category: category,
                    notes: 'Logged via dashboard quick-add'
                };
                fetch('api/time-entries.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) { cb(d.error); return; }
                    cb(null);
                })
                .catch(function (e) { cb(e.message); });
            })
            .catch(function (e) { cb(e.message); });
    }

    // ── Modal: recent entry list ────────────────────────────────────────
    function ensureModal() {
        var modal = document.getElementById('teListModal');
        if (modal) return modal;
        var div = document.createElement('div');
        div.innerHTML =
            '<div class="modal fade" id="teListModal" tabindex="-1" aria-hidden="true">' +
              '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
                '<div class="modal-content">' +
                  '<div class="modal-header">' +
                    '<h5 class="modal-title">My recent time</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                  '</div>' +
                  '<div class="modal-body p-0" id="teListBody">' +
                    '<div class="text-center p-4 text-body-secondary">Loading...</div>' +
                  '</div>' +
                  '<div class="modal-footer">' +
                    '<a href="time-entries.php" class="btn btn-sm btn-primary">' +
                      '<i class="bi bi-list-ul"></i> Open full page' +
                    '</a>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(div.firstChild);
        return document.getElementById('teListModal');
    }

    function openListModal() {
        var modal = ensureModal();
        var body  = document.getElementById('teListBody');
        body.innerHTML = '<div class="text-center p-4 text-body-secondary">Loading...</div>';

        var bsModal;
        if (window.bootstrap && window.bootstrap.Modal) {
            bsModal = window.bootstrap.Modal.getOrCreateInstance(modal);
            bsModal.show();
        } else {
            modal.style.display = 'block';
        }

        fetchRecent(function (err, data) {
            if (err) { body.innerHTML = '<div class="p-3 text-danger">Failed to load.</div>'; return; }
            var entries = (data && data.entries) || [];
            if (!entries.length) {
                body.innerHTML = '<div class="text-center p-4 text-body-secondary">' +
                    'No entries in the last 90 days.</div>';
                return;
            }
            // Group by YYYY-MM
            var groups = {};
            for (var i = 0; i < entries.length; i++) {
                var key = (entries[i].started_at || '').substring(0, 7);
                if (!groups[key]) groups[key] = [];
                groups[key].push(entries[i]);
            }
            var keys = Object.keys(groups).sort().reverse();
            var html = '';
            for (var j = 0; j < keys.length; j++) {
                var k = keys[j];
                var rows = groups[k];
                var sum = 0;
                for (var x = 0; x < rows.length; x++) sum += parseFloat(rows[x].hours || 0);
                html += '<div class="px-3 py-2 fw-semibold bg-body-tertiary border-bottom small">' +
                          esc(k) + ' <span class="text-body-secondary ms-2">' +
                          (Math.round(sum * 10) / 10).toFixed(1) + ' h total</span></div>';
                html += '<table class="table table-sm mb-0"><tbody>';
                for (var y = 0; y < rows.length; y++) {
                    var e = rows[y];
                    var status = e.status || 'self_reported';
                    var badge =
                        status === 'approved' ? '<span class="badge bg-success">approved</span>' :
                        status === 'rejected' ? '<span class="badge bg-danger">rejected</span>' :
                                                '<span class="badge bg-warning text-dark">pending</span>';
                    html += '<tr>' +
                              '<td class="small"><small>' + esc((e.started_at || '').substring(0, 16)) +
                                '</small></td>' +
                              '<td class="small">' + esc(e.category || e.activity_type || '') + '</td>' +
                              '<td class="text-end small">' +
                                (Math.round(parseFloat(e.hours || 0) * 10) / 10).toFixed(1) + ' h</td>' +
                              '<td class="text-end">' + badge + '</td>' +
                            '</tr>';
                }
                html += '</tbody></table>';
            }
            body.innerHTML = html;
        });
    }

    // ── Public surface ──────────────────────────────────────────────────
    window.TimeEntriesWidget = {
        render: function (selOrEl) {
            var container = typeof selOrEl === 'string' ? $(selOrEl) : selOrEl;
            if (!container) return;
            refresh(container);
            // Refresh on SSE event when audit_log writes a time-entry row.
            if (window.EventBus && typeof window.EventBus.on === 'function') {
                window.EventBus.on('audit.personnel', function (msg) {
                    if (msg && /time_entry/.test(msg.target_type || '')) {
                        refresh(container);
                    }
                });
            }
        }
    };
})();
