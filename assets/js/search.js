(function () {
    'use strict';

    var currentSort = 'date';
    var currentOrder = 'desc';
    var currentOffset = 0;
    var lastTotal = 0;
    var searchTimeout = null;

    // ── Initialization ──
    function init() {
        loadIncidentTypes();
        bindSearchForm();
        bindSortHeaders();
        bindPagination();

        // Check for URL params (deep-link search)
        var params = new URLSearchParams(window.location.search);
        if (params.get('q') || params.get('status') || params.get('type_id')) {
            restoreFromUrl(params);
            doSearch();
        }

        // Esc returns to dashboard
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !e.target.matches('input, select, textarea')) {
                window.location.href = 'index.php';
            }
        });
    }

    // ── Load incident types for the dropdown ──
    function loadIncidentTypes() {
        fetch('api/incident-types.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var select = document.getElementById('searchType');
                if (!select) return;

                var types = data.types || data.incident_types || [];
                for (var i = 0; i < types.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = types[i].id;
                    opt.textContent = types[i].type || types[i].name;
                    if (types[i].group) {
                        opt.textContent += ' (' + types[i].group + ')';
                    }
                    select.appendChild(opt);
                }
            })
            .catch(function () {
                // Non-fatal — type dropdown just stays empty
            });
    }

    // ── Bind form events ──
    function bindSearchForm() {
        var form = document.getElementById('searchForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            currentOffset = 0;
            doSearch();
        });

        // Clear button
        var clearBtn = document.getElementById('btnClear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                document.getElementById('searchText').value = '';
                document.getElementById('searchType').value = '';
                document.getElementById('searchStatus').value = '';
                document.getElementById('searchSeverity').value = '';
                document.getElementById('searchDateFrom').value = '';
                document.getElementById('searchDateTo').value = '';
                document.getElementById('searchCity').value = '';
                currentOffset = 0;

                var body = document.getElementById('resultsBody');
                body.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary py-4">' +
                    '<i class="bi bi-search me-1"></i>Use the form above to search for incidents.</td></tr>';
                document.getElementById('resultsSummary').textContent = 'Enter search criteria above.';
                document.getElementById('paginationList').innerHTML = '';
                document.getElementById('pageInfo').textContent = '';
                document.getElementById('searchText').focus();
            });
        }

        // Page size change
        var pageSize = document.getElementById('pageSize');
        if (pageSize) {
            pageSize.addEventListener('change', function () {
                currentOffset = 0;
                doSearch();
            });
        }

        // Auto-search on Enter in any field (already handled by form submit)
        // Also auto-search when dropdowns change
        var autoFields = ['searchType', 'searchStatus', 'searchSeverity'];
        for (var i = 0; i < autoFields.length; i++) {
            var el = document.getElementById(autoFields[i]);
            if (el) {
                el.addEventListener('change', function () {
                    currentOffset = 0;
                    doSearch();
                });
            }
        }
    }

    // ── Bind sortable column headers ──
    function bindSortHeaders() {
        var headers = document.querySelectorAll('.sortable');
        for (var i = 0; i < headers.length; i++) {
            headers[i].addEventListener('click', function () {
                var field = this.getAttribute('data-sort');
                if (currentSort === field) {
                    currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort = field;
                    currentOrder = field === 'date' || field === 'id' ? 'desc' : 'asc';
                }
                currentOffset = 0;
                updateSortIndicators();
                doSearch();
            });
        }
    }

    function updateSortIndicators() {
        var headers = document.querySelectorAll('.sortable');
        for (var i = 0; i < headers.length; i++) {
            var field = headers[i].getAttribute('data-sort');
            // Remove existing indicators
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
            doSearch();
            // Scroll to top of results
            document.getElementById('resultsTable').scrollIntoView({ behavior: 'smooth' });
        });
    }

    // ── Restore search params from URL ──
    function restoreFromUrl(params) {
        if (params.get('q')) document.getElementById('searchText').value = params.get('q');
        if (params.get('type_id')) document.getElementById('searchType').value = params.get('type_id');
        if (params.get('status')) document.getElementById('searchStatus').value = params.get('status');
        if (params.get('severity')) document.getElementById('searchSeverity').value = params.get('severity');
        if (params.get('date_from')) document.getElementById('searchDateFrom').value = params.get('date_from');
        if (params.get('date_to')) document.getElementById('searchDateTo').value = params.get('date_to');
        if (params.get('city')) document.getElementById('searchCity').value = params.get('city');
        if (params.get('sort')) currentSort = params.get('sort');
        if (params.get('order')) currentOrder = params.get('order');
    }

    // ── Execute search ──
    function doSearch() {
        var q        = document.getElementById('searchText').value.trim();
        var typeId   = document.getElementById('searchType').value;
        var status   = document.getElementById('searchStatus').value;
        var severity = document.getElementById('searchSeverity').value;
        var dateFrom = document.getElementById('searchDateFrom').value;
        var dateTo   = document.getElementById('searchDateTo').value;
        var city     = document.getElementById('searchCity').value.trim();
        var limit    = parseInt(document.getElementById('pageSize').value, 10) || 50;

        // Build query string
        var params = [];
        if (q)        params.push('q=' + encodeURIComponent(q));
        if (typeId)   params.push('type_id=' + typeId);
        if (status)   params.push('status=' + status);
        if (severity !== '') params.push('severity=' + severity);
        if (dateFrom) params.push('date_from=' + dateFrom);
        if (dateTo)   params.push('date_to=' + dateTo);
        if (city)     params.push('city=' + encodeURIComponent(city));
        params.push('sort=' + currentSort);
        params.push('order=' + currentOrder);
        params.push('limit=' + limit);
        params.push('offset=' + currentOffset);

        var url = 'api/incident-search.php?' + params.join('&');

        // Show loading
        var body = document.getElementById('resultsBody');
        body.innerHTML = '<tr><td colspan="9" class="text-center py-4">' +
            '<div class="spinner-border spinner-border-sm text-primary me-2"></div>Searching...</td></tr>';

        fetch(url)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showAlert(escHtml(data.error), 'danger');
                    body.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">' +
                        escHtml(data.error) + '</td></tr>';
                    return;
                }

                lastTotal = data.total || 0;
                renderResults(data.results || []);
                renderPagination(data.total, data.limit, data.offset);
                updateSortIndicators();

                // Summary
                var summary = document.getElementById('resultsSummary');
                if (lastTotal === 0) {
                    summary.textContent = 'No incidents found.';
                } else {
                    var from = data.offset + 1;
                    var to = Math.min(data.offset + data.limit, data.total);
                    summary.textContent = 'Showing ' + from + '-' + to + ' of ' + data.total + ' incidents';
                }
            })
            .catch(function (err) {
                body.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">' +
                    'Search failed: ' + escHtml(err.message) + '</td></tr>';
            });
    }

    // ── Render search results ──
    function renderResults(results) {
        var body = document.getElementById('resultsBody');

        if (results.length === 0) {
            body.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary py-4">' +
                '<i class="bi bi-inbox me-1"></i>No incidents match your search criteria.</td></tr>';
            return;
        }

        var statusClasses = { 1: 'bg-secondary', 2: 'bg-success', 3: 'bg-info' };
        var sevLabels = ['Low', 'Med', 'High'];

        var html = '';
        for (var i = 0; i < results.length; i++) {
            var r = results[i];

            var sevBadgeStyle = 'background-color:' + escHtml(r.severity_color) + ';color:' +
                (r.severity >= 2 ? '#fff' : '#000') + ';';

            html += '<tr class="search-result-row" data-id="' + r.id + '" style="cursor:pointer;">' +
                '<td class="ps-3 fw-semibold text-primary">' + r.id + '</td>' +
                '<td class="text-nowrap">' + formatDate(r.date) + '</td>' +
                '<td>' + escHtml(truncate(r.scope, 60)) + '</td>' +
                '<td><span class="text-body-secondary">' + escHtml(r.type_name || '--') + '</span></td>' +
                '<td><span class="badge" style="' + sevBadgeStyle + 'font-size:0.65rem;">' + (sevLabels[r.severity] || '--') + '</span></td>' +
                '<td><span class="badge ' + (statusClasses[r.status] || 'bg-secondary') + '" style="font-size:0.65rem;">' + escHtml(r.status_text) + '</span></td>' +
                '<td>' + escHtml(r.city || '--') + '</td>' +
                '<td class="text-body-secondary">' + escHtml(truncate(r.contact || r.phone || '', 20)) + '</td>' +
                '<td class="text-center">' + (r.active_responders > 0 ? '<span class="badge bg-primary">' + r.active_responders + '</span>' : '<span class="text-body-tertiary">0</span>') + '</td>' +
                '</tr>';
        }

        body.innerHTML = html;

        // Click rows to navigate to detail
        var rows = body.querySelectorAll('.search-result-row');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                window.location.href = 'incident-detail.php?id=' + id;
            });
        }
    }

    // ── Render pagination controls ──
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

        // Page numbers (show max 7 pages centered around current)
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

        if (info) {
            info.textContent = 'Page ' + currentPage + ' of ' + totalPages;
        }
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
