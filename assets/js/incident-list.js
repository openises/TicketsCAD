(function () {
    'use strict';

    var currentStatus = 0;  // 0=All
    var currentGroup = '';
    var currentSeverity = '';
    var currentSort = 'date';
    var currentOrder = 'desc';
    var currentOffset = 0;
    var pageLimit = 50;
    var autoRefreshInterval = null;
    var autoRefreshSeconds = 30;
    var countdownValue = 0;
    var countdownTimer = null;

    // Status counts (fetched separately)
    var statusCounts = { all: 0, open: 0, closed: 0, scheduled: 0 };

    // ── Initialization ──
    function init() {
        bindStatusTabs();
        bindFilters();
        bindSortHeaders();
        bindPagination();
        bindAutoRefresh();
        bindKeyboard();
        bindRealtime();

        // Load initial data
        loadIncidents();
        loadStatusCounts();
    }

    // ── Live updates (GH #13) ──
    // Refresh the list the instant an incident changes anywhere — not only on
    // the 30s poll. Debounced so a burst of SSE events coalesces into a single
    // refetch. Silent no-op if EventBus/SSE isn't available; the poll still
    // covers that case. EventBus is loaded globally via navbar.
    var _rtDebounce = null;
    function bindRealtime() {
        if (!window.EventBus || typeof window.EventBus.on !== 'function') { return; }
        var refresh = function () {
            if (_rtDebounce) { return; }
            _rtDebounce = setTimeout(function () {
                _rtDebounce = null;
                loadIncidents();
                loadStatusCounts();
            }, 600);
        };
        var events = ['incident:new', 'incident:update', 'incident:close', 'incident:note'];
        for (var i = 0; i < events.length; i++) {
            window.EventBus.on(events[i], refresh);
        }
    }

    // ── Bind status tabs ──
    function bindStatusTabs() {
        var tabs = document.querySelectorAll('.status-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function () {
                // Remove active from all
                for (var j = 0; j < tabs.length; j++) {
                    tabs[j].classList.remove('active');
                }
                this.classList.add('active');
                currentStatus = parseInt(this.getAttribute('data-status'), 10);
                currentOffset = 0;
                loadIncidents();
            });
        }
    }

    // ── Bind group and severity filters ──
    function bindFilters() {
        var groupFilter = document.getElementById('groupFilter');
        if (groupFilter) {
            groupFilter.addEventListener('change', function () {
                currentGroup = this.value;
                currentOffset = 0;
                loadIncidents();
            });
        }

        var sevFilter = document.getElementById('sevFilter');
        if (sevFilter) {
            sevFilter.addEventListener('change', function () {
                currentSeverity = this.value;
                currentOffset = 0;
                loadIncidents();
            });
        }
    }

    // ── Bind sort headers ──
    function bindSortHeaders() {
        var headers = document.querySelectorAll('.sortable');
        for (var i = 0; i < headers.length; i++) {
            headers[i].addEventListener('click', function () {
                var field = this.getAttribute('data-sort');
                if (currentSort === field) {
                    currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort = field;
                    currentOrder = field === 'date' || field === 'id' || field === 'updated' ? 'desc' : 'asc';
                }
                currentOffset = 0;
                updateSortIndicators();
                loadIncidents();
            });
        }
    }

    function updateSortIndicators() {
        var headers = document.querySelectorAll('.sortable');
        for (var i = 0; i < headers.length; i++) {
            var field = headers[i].getAttribute('data-sort');
            var existing = headers[i].querySelector('.sort-indicator');
            if (existing) existing.remove();

            if (field === currentSort) {
                var indicator = document.createElement('i');
                indicator.className = 'bi bi-caret-' + (currentOrder === 'asc' ? 'up' : 'down') + '-fill ms-1 sort-indicator';
                indicator.style.fontSize = '0.6rem';
                headers[i].appendChild(indicator);
            }
        }
    }

    // ── Bind pagination ──
    function bindPagination() {
        document.getElementById('paginationList').addEventListener('click', function (e) {
            var link = e.target.closest('[data-offset]');
            if (!link) return;
            e.preventDefault();
            currentOffset = parseInt(link.getAttribute('data-offset'), 10);
            loadIncidents();
            document.getElementById('incidentTable').scrollIntoView({ behavior: 'smooth' });
        });
    }

    // ── Auto-refresh toggle ──
    function bindAutoRefresh() {
        var toggle = document.getElementById('autoRefreshToggle');
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            if (this.checked) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
    }

    function startAutoRefresh() {
        stopAutoRefresh();
        countdownValue = autoRefreshSeconds;
        var countdownEl = document.getElementById('refreshCountdown');
        if (countdownEl) countdownEl.classList.remove('d-none');

        countdownTimer = setInterval(function () {
            countdownValue--;
            var countdownEl = document.getElementById('refreshCountdown');
            if (countdownEl) countdownEl.textContent = '(' + countdownValue + 's)';

            if (countdownValue <= 0) {
                loadIncidents();
                loadStatusCounts();
                countdownValue = autoRefreshSeconds;
            }
        }, 1000);
    }

    function stopAutoRefresh() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        var countdownEl = document.getElementById('refreshCountdown');
        if (countdownEl) {
            countdownEl.classList.add('d-none');
            countdownEl.textContent = '';
        }
    }

    // ── Keyboard navigation ──
    function bindKeyboard() {
        document.addEventListener('keydown', function (e) {
            if (e.target.matches('input, select, textarea')) return;

            if (e.key === 'Escape') {
                window.location.href = 'index.php';
                return;
            }

            // 'n' for new incident
            if (e.key === 'n' && !e.ctrlKey && !e.altKey) {
                window.location.href = 'new-incident.php';
                return;
            }

            // '/' for search
            if (e.key === '/') {
                e.preventDefault();
                window.location.href = 'search.php';
                return;
            }

            // 'r' to refresh
            if (e.key === 'r' && !e.ctrlKey) {
                loadIncidents();
                loadStatusCounts();
                return;
            }
        });
    }

    // ── Load incidents ──
    function loadIncidents() {
        var params = [];
        if (currentStatus > 0) params.push('status=' + currentStatus);
        if (currentGroup) params.push('group=' + encodeURIComponent(currentGroup));
        if (currentSeverity !== '') params.push('severity=' + currentSeverity);
        params.push('sort=' + currentSort);
        params.push('order=' + currentOrder);
        params.push('limit=' + pageLimit);
        params.push('offset=' + currentOffset);

        var url = 'api/incident-list.php?' + params.join('&');

        fetch(url)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showAlert(escHtml(data.error), 'danger');
                    return;
                }

                renderIncidents(data.incidents || []);
                renderPagination(data.total, data.limit, data.offset);
                populateGroupFilter(data.groups || []);
                updateSortIndicators();

                // Summary
                var summary = document.getElementById('listSummary');
                if (data.total === 0) {
                    summary.textContent = 'No incidents found.';
                } else {
                    var from = data.offset + 1;
                    var to = Math.min(data.offset + data.limit, data.total);
                    summary.textContent = from + '-' + to + ' of ' + data.total;
                }
            })
            .catch(function (err) {
                var body = document.getElementById('incidentBody');
                body.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">' +
                    'Failed to load: ' + escHtml(err.message) + '</td></tr>';
            });
    }

    // ── Load status counts for the tabs ──
    function loadStatusCounts() {
        // Fetch counts for each status
        var fetches = [
            fetch('api/incident-list.php?limit=1&offset=0').then(function (r) { return r.json(); }),
            fetch('api/incident-list.php?status=2&limit=1&offset=0').then(function (r) { return r.json(); }),
            fetch('api/incident-list.php?status=1&limit=1&offset=0').then(function (r) { return r.json(); }),
            fetch('api/incident-list.php?status=3&limit=1&offset=0').then(function (r) { return r.json(); })
        ];

        Promise.all(fetches).then(function (results) {
            setCount('countAll', results[0].total);
            setCount('countOpen', results[1].total);
            setCount('countClosed', results[2].total);
            setCount('countScheduled', results[3].total);
        }).catch(function () {
            // non-fatal
        });
    }

    function setCount(id, count) {
        var el = document.getElementById(id);
        if (el) el.textContent = count || 0;
    }

    // ── Populate group filter dropdown ──
    function populateGroupFilter(groups) {
        var select = document.getElementById('groupFilter');
        if (!select || select.options.length > 1) return; // Only populate once

        for (var i = 0; i < groups.length; i++) {
            var opt = document.createElement('option');
            opt.value = groups[i];
            opt.textContent = groups[i];
            select.appendChild(opt);
        }
    }

    // ── Render incident rows ──
    function renderIncidents(incidents) {
        var body = document.getElementById('incidentBody');

        if (incidents.length === 0) {
            body.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary py-4">' +
                '<i class="bi bi-inbox me-1"></i>No incidents in this view.</td></tr>';
            return;
        }

        var statusClasses = { 1: 'bg-secondary', 2: 'bg-success', 3: 'bg-info' };
        var sevLabels = ['Low', 'Med', 'High'];

        var html = '';
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];

            var sevStyle = 'background-color:' + escHtml(inc.severity_color) + ';';

            var location = escHtml(inc.city || '');
            if (inc.street) {
                location = escHtml(inc.street) + (inc.city ? ', ' + escHtml(inc.city) : '');
            }

            // Phase 99p — show the admin-configured case number, not
            // the internal id. data-id stays on the row for clicks.
            var caseNum = inc.incident_number || ('#' + inc.id);
            html += '<tr class="incident-row" data-id="' + inc.id + '" data-case-num="' + escHtml(caseNum) + '" style="cursor:pointer;">' +
                '<td class="ps-3 fw-semibold text-primary font-monospace small">' + escHtml(caseNum) + '</td>' +
                '<td><span class="sev-stripe" style="' + sevStyle + '" title="' + (sevLabels[inc.severity] || '') + '"></span></td>' +
                '<td class="text-nowrap">' + formatDate(inc.date) + '</td>' +
                '<td>' + escHtml(truncate(inc.scope, 50)) + '</td>' +
                '<td class="text-body-secondary">' + escHtml(inc.type_name || '--') +
                    (inc.type_group ? ' <small class="text-body-tertiary">(' + escHtml(inc.type_group) + ')</small>' : '') + '</td>' +
                '<td>' + location + '</td>' +
                '<td><span class="badge ' + (statusClasses[inc.status] || 'bg-secondary') + '" style="font-size:0.65rem;">' + escHtml(inc.status_text) + '</span></td>' +
                '<td class="text-center">' + (inc.active_responders > 0 ? '<span class="badge bg-primary">' + inc.active_responders + '</span>' : '<span class="text-body-tertiary">0</span>') + '</td>' +
                '<td class="text-nowrap text-body-secondary">' + formatDate(inc.updated) + '</td>' +
                '</tr>';
        }

        body.innerHTML = html;

        // Click rows to navigate
        var rows = body.querySelectorAll('.incident-row');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                window.location.href = 'incident-detail.php?id=' + this.getAttribute('data-id');
            });
        }
    }

    // ── Pagination ──
    function renderPagination(total, limit, offset) {
        var list = document.getElementById('paginationList');
        var info = document.getElementById('pageInfo');
        if (!list) return;

        if (total <= limit) {
            list.innerHTML = '';
            if (info) info.textContent = '';
            return;
        }

        var totalPages = Math.ceil(total / limit);
        var currentPage = Math.floor(offset / limit) + 1;

        var html = '';

        // Previous
        if (currentPage > 1) {
            html += '<li class="page-item"><a class="page-link" href="#" data-offset="' + ((currentPage - 2) * limit) + '">&laquo;</a></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
        }

        var startPage = Math.max(1, currentPage - 3);
        var endPage = Math.min(totalPages, startPage + 6);
        if (endPage - startPage < 6) startPage = Math.max(1, endPage - 6);

        for (var p = startPage; p <= endPage; p++) {
            if (p === currentPage) {
                html += '<li class="page-item active"><span class="page-link">' + p + '</span></li>';
            } else {
                html += '<li class="page-item"><a class="page-link" href="#" data-offset="' + ((p - 1) * limit) + '">' + p + '</a></li>';
            }
        }

        // Next
        if (currentPage < totalPages) {
            html += '<li class="page-item"><a class="page-link" href="#" data-offset="' + (currentPage * limit) + '">&raquo;</a></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        }

        list.innerHTML = html;
        if (info) info.textContent = 'Page ' + currentPage + ' of ' + totalPages;
    }

    // ── Utilities ──
    function formatDate(dt) {
        if (!dt) return '--';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return month + '/' + day + ' ' + hours + ':' + mins;
    }

    function truncate(str, max) {
        if (!str) return '';
        return str.length > max ? str.substring(0, max) + '...' : str;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Boot ──
    document.addEventListener('DOMContentLoaded', init);

})();
