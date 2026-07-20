/**
 * NewUI v4.0 - Reports
 *
 * Handles report type selection, period filtering, data fetching,
 * table rendering, client-side sorting, CSV export, and printing.
 */
(function () {
    'use strict';

    // ── State ─────────────────────────────────────────────────────────────────

    var currentReport = 'incident_report';
    var currentPeriod = 'this_month';
    var reportData = null;   // { report_title, period_label, columns, rows, summary }
    var statsData = null;
    var sortColumn = -1;
    var sortAsc = true;
    var responderList = [];

    // ── DOM refs ──────────────────────────────────────────────────────────────

    var reportTypeBtns    = document.getElementById('reportTypeBtns');
    var personnelReportBtns = document.getElementById('personnelReportBtns');
    var periodSelect      = document.getElementById('periodSelect');
    var customDateRange   = document.getElementById('customDateRange');
    var customDateRange2  = document.getElementById('customDateRange2');
    var startDateInput    = document.getElementById('startDate');
    var endDateInput      = document.getElementById('endDate');
    var responderFilter   = document.getElementById('responderFilter');
    var responderFilterCol = document.getElementById('responderFilterCol');
    var incidentFilterCol = document.getElementById('incidentFilterCol');
    var incidentFilter    = document.getElementById('incidentFilter');
    var btnRunReport      = document.getElementById('btnRunReport');
    var btnExportCSV      = document.getElementById('btnExportCSV');
    var btnPrint          = document.getElementById('btnPrint');
    var summaryCards      = document.getElementById('summaryCards');
    var reportHeader      = document.getElementById('reportHeader');
    var reportTitle       = document.getElementById('reportTitle');
    var periodLabel       = document.getElementById('periodLabel');
    var rowCount          = document.getElementById('rowCount');
    var loadingSpinner    = document.getElementById('loadingSpinner');
    var emptyState        = document.getElementById('emptyState');
    var reportTableWrap   = document.getElementById('reportTableWrap');
    var reportTableHead   = document.getElementById('reportTableHead');
    var reportTableBody   = document.getElementById('reportTableBody');
    var noDataState       = document.getElementById('noDataState');
    var afterActionPanel  = document.getElementById('afterActionPanel');
    var afterActionInfo   = document.getElementById('afterActionInfo');

    // ── Init ──────────────────────────────────────────────────────────────────

    function init() {
        bindEvents();
        loadResponders();
        setDefaultDates();
    }

    function bindEvents() {
        // Report type buttons (incident reports)
        var btns = reportTypeBtns.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function () {
                selectReportType(this.getAttribute('data-report'));
            });
        }

        // Personnel report buttons
        if (personnelReportBtns) {
            var pbtns = personnelReportBtns.querySelectorAll('button');
            for (var j = 0; j < pbtns.length; j++) {
                pbtns[j].addEventListener('click', function () {
                    selectReportType(this.getAttribute('data-report'));
                });
            }
        }

        // Period selector
        periodSelect.addEventListener('change', function () {
            currentPeriod = this.value;
            toggleCustomDates();
        });

        // Run report
        btnRunReport.addEventListener('click', function () {
            runReport();
        });

        // CSV export
        btnExportCSV.addEventListener('click', function () {
            exportCSV();
        });

        // Print
        btnPrint.addEventListener('click', function () {
            printReport();
        });

        // Enter key on incident filter
        incidentFilter.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                runReport();
            }
        });
    }

    function setDefaultDates() {
        var today = new Date();
        var yyyy = today.getFullYear();
        var mm = ('0' + (today.getMonth() + 1)).slice(-2);
        var dd = ('0' + today.getDate()).slice(-2);
        var todayStr = yyyy + '-' + mm + '-' + dd;

        endDateInput.value = todayStr;
        startDateInput.value = yyyy + '-' + mm + '-01';
    }

    function toggleCustomDates() {
        var show = (currentPeriod === 'custom');
        customDateRange.classList.toggle('d-none', !show);
        customDateRange2.classList.toggle('d-none', !show);
    }

    // ── Report Type Selection ─────────────────────────────────────────────────

    function selectReportType(type) {
        currentReport = type;

        // Update active state across BOTH button groups
        function paintGroup(group) {
            if (!group) { return; }
            var btns = group.querySelectorAll('button');
            for (var i = 0; i < btns.length; i++) {
                var btn = btns[i];
                if (btn.getAttribute('data-report') === type) {
                    btn.classList.remove('btn-outline-primary');
                    btn.classList.add('btn-primary');
                } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-outline-primary');
                }
            }
        }
        paintGroup(reportTypeBtns);
        paintGroup(personnelReportBtns);

        // Show/hide filters based on report type
        var showResponder = (type === 'unit_log' || type === 'dispatch_log');
        responderFilterCol.classList.toggle('d-none', !showResponder);

        var showIncident = (type === 'after_action');
        incidentFilterCol.classList.toggle('d-none', !showIncident);

        // Personnel reports that don't use the period filter at all
        var personnelNoPeriod = (type === 'roster_snapshot' ||
                                 type === 'license_expirations' ||
                                 type === 'membership_due' ||
                                 type === 'inactive_members' ||
                                 type === 'dmr_inventory');

        // Hide period for after_action (uses incident_id) and snapshot-style personnel reports.
        // Toggle only the Period COLUMN (periodSelect.parentElement) — NOT its grandparent, which
        // is the whole filter row; hiding the row also hid the Incident # input + Run Report button,
        // making the After Action report impossible to run.
        var hidePeriod = (type === 'after_action') || personnelNoPeriod;
        periodSelect.parentElement.classList.toggle('d-none', hidePeriod);
        customDateRange.classList.toggle('d-none', hidePeriod || currentPeriod !== 'custom');
        customDateRange2.classList.toggle('d-none', hidePeriod || currentPeriod !== 'custom');
    }

    // ── Load Responders for Filter ────────────────────────────────────────────

    function loadResponders() {
        fetch('api/responders.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.responders) {
                    responderList = data.responders;
                    var sel = responderFilter;
                    for (var i = 0; i < data.responders.length; i++) {
                        var r = data.responders[i];
                        var opt = document.createElement('option');
                        opt.value = r.id;
                        opt.textContent = r.name + (r.handle ? ' (' + r.handle + ')' : '');
                        sel.appendChild(opt);
                    }
                }
            })
            .catch(function () {
                // Responder list load failed — filters still work without it
            });
    }

    // ── Run Report ────────────────────────────────────────────────────────────

    function runReport() {
        var params = 'report=' + encodeURIComponent(currentReport);
        params += '&period=' + encodeURIComponent(currentPeriod);

        if (currentPeriod === 'custom') {
            params += '&start_date=' + encodeURIComponent(startDateInput.value);
            params += '&end_date=' + encodeURIComponent(endDateInput.value);
        }

        var rid = parseInt(responderFilter.value, 10) || 0;
        if (rid > 0) {
            params += '&responder_id=' + rid;
        }

        var iid = parseInt(incidentFilter.value, 10) || 0;
        if (iid > 0) {
            params += '&incident_id=' + iid;
        }

        showLoading();

        var isPersonnelReport = (currentReport === 'roster_snapshot' ||
                                 currentReport === 'license_expirations' ||
                                 currentReport === 'membership_due' ||
                                 currentReport === 'inactive_members' ||
                                 currentReport === 'dmr_inventory' ||
                                 currentReport === 'time_summary');

        // Fetch report data and stats in parallel (skip stats for personnel reports — they aren't incident-period scoped)
        var reportUrl = 'api/reports.php?' + params;
        var reportPromise = fetch(reportUrl, { credentials: 'same-origin' }).then(function (r) { return r.json(); });

        var statsPromise;
        if (isPersonnelReport) {
            statsPromise = Promise.resolve(null);
        } else {
            var statsUrl = 'api/statistics.php?mode=reports&period=' + encodeURIComponent(currentPeriod);
            if (currentPeriod === 'custom') {
                statsUrl += '&start_date=' + encodeURIComponent(startDateInput.value);
                statsUrl += '&end_date=' + encodeURIComponent(endDateInput.value);
            }
            statsPromise = fetch(statsUrl, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
        }

        Promise.all([reportPromise, statsPromise])
            .then(function (results) {
                reportData = results[0];
                statsData = results[1];

                if (reportData.error) {
                    showError(reportData.error);
                    return;
                }

                sortColumn = -1;
                sortAsc = true;
                renderReport();
                renderSummaryCards();
            })
            .catch(function (err) {
                showError('Failed to load report: ' + err.message);
            });
    }

    // ── Show/Hide States ──────────────────────────────────────────────────────

    function showLoading() {
        loadingSpinner.classList.remove('d-none');
        emptyState.classList.add('d-none');
        reportTableWrap.classList.add('d-none');
        noDataState.classList.add('d-none');
        reportHeader.classList.add('d-none');
        summaryCards.classList.add('d-none');
        afterActionPanel.classList.add('d-none');
        btnExportCSV.disabled = true;
        btnPrint.disabled = true;
    }

    function showError(msg) {
        loadingSpinner.classList.add('d-none');
        noDataState.classList.remove('d-none');
        var msgEl = noDataState.querySelector('.text-body-secondary');
        if (msgEl) {
            msgEl.textContent = msg;
        }
    }

    // ── Render Report ─────────────────────────────────────────────────────────

    function renderReport() {
        loadingSpinner.classList.add('d-none');

        if (!reportData || !reportData.rows || reportData.rows.length === 0) {
            noDataState.classList.remove('d-none');
            reportTableWrap.classList.add('d-none');
            reportHeader.classList.remove('d-none');
            reportTitle.textContent = reportData ? reportData.report_title : '';
            periodLabel.textContent = reportData ? reportData.period_label : '';
            rowCount.textContent = '0 rows';
            btnExportCSV.disabled = true;
            btnPrint.disabled = true;
            return;
        }

        noDataState.classList.add('d-none');
        reportTableWrap.classList.remove('d-none');
        reportHeader.classList.remove('d-none');

        reportTitle.textContent = reportData.report_title;
        periodLabel.textContent = reportData.period_label;
        rowCount.textContent = reportData.rows.length + ' row' + (reportData.rows.length !== 1 ? 's' : '');

        btnExportCSV.disabled = false;
        btnPrint.disabled = false;

        // Build table header
        reportTableHead.innerHTML = '';
        var cols = reportData.columns;
        for (var c = 0; c < cols.length; c++) {
            var th = document.createElement('th');
            th.className = 'small sortable-header';
            th.setAttribute('data-col', c);
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.style.whiteSpace = 'nowrap';

            var text = document.createTextNode(cols[c] + ' ');
            th.appendChild(text);

            var icon = document.createElement('i');
            icon.className = 'bi bi-arrow-down-up text-body-tertiary';
            icon.style.fontSize = '0.65rem';
            if (c === sortColumn) {
                icon.className = sortAsc ? 'bi bi-sort-up text-primary' : 'bi bi-sort-down text-primary';
            }
            th.appendChild(icon);

            th.addEventListener('click', (function (colIdx) {
                return function () {
                    sortByColumn(colIdx);
                };
            })(c));

            reportTableHead.appendChild(th);
        }

        // Build table body
        renderRows(reportData.rows);

        // After-action panel
        if (currentReport === 'after_action' && reportData.summary) {
            renderAfterActionPanel(reportData.summary);
        } else {
            afterActionPanel.classList.add('d-none');
        }
    }

    function renderRows(rows) {
        reportTableBody.innerHTML = '';
        for (var r = 0; r < rows.length; r++) {
            var tr = document.createElement('tr');
            for (var c = 0; c < rows[r].length; c++) {
                var td = document.createElement('td');
                td.className = 'small';
                var val = rows[r][c];
                td.textContent = (val !== null && val !== undefined) ? String(val) : '';
                tr.appendChild(td);
            }
            reportTableBody.appendChild(tr);
        }
    }

    // ── Client-side Sorting ───────────────────────────────────────────────────

    function sortByColumn(colIdx) {
        if (!reportData || !reportData.rows) return;

        if (sortColumn === colIdx) {
            sortAsc = !sortAsc;
        } else {
            sortColumn = colIdx;
            sortAsc = true;
        }

        var rows = reportData.rows.slice(); // copy
        rows.sort(function (a, b) {
            var va = a[colIdx];
            var vb = b[colIdx];

            // Try numeric comparison
            var na = parseFloat(va);
            var nb = parseFloat(vb);
            if (!isNaN(na) && !isNaN(nb)) {
                return sortAsc ? na - nb : nb - na;
            }

            // String comparison
            va = (va !== null && va !== undefined) ? String(va).toLowerCase() : '';
            vb = (vb !== null && vb !== undefined) ? String(vb).toLowerCase() : '';
            if (va < vb) return sortAsc ? -1 : 1;
            if (va > vb) return sortAsc ? 1 : -1;
            return 0;
        });

        reportData.rows = rows;

        // Re-render header icons
        var ths = reportTableHead.querySelectorAll('th');
        for (var i = 0; i < ths.length; i++) {
            var icon = ths[i].querySelector('i');
            if (icon) {
                var idx = parseInt(ths[i].getAttribute('data-col'), 10);
                if (idx === sortColumn) {
                    icon.className = sortAsc ? 'bi bi-sort-up text-primary' : 'bi bi-sort-down text-primary';
                } else {
                    icon.className = 'bi bi-arrow-down-up text-body-tertiary';
                }
                icon.style.fontSize = '0.65rem';
            }
        }

        renderRows(rows);
    }

    // ── Summary Cards ─────────────────────────────────────────────────────────

    function renderSummaryCards() {
        summaryCards.innerHTML = '';
        summaryCards.classList.add('d-none');

        if (!statsData && (!reportData || !reportData.summary)) return;

        var cards = [];

        if (statsData) {
            cards.push({ label: 'Open Incidents', value: statsData.open_tickets || 0, color: 'danger', icon: 'bi-exclamation-triangle' });
            cards.push({ label: 'Closed (Period)', value: statsData.closed_in_period || 0, color: 'success', icon: 'bi-check-circle' });
            cards.push({ label: 'Total (Period)', value: statsData.total_in_period || 0, color: 'primary', icon: 'bi-hash' });
            cards.push({ label: 'Available Units', value: statsData.available_responders || 0, color: 'info', icon: 'bi-people' });

            if (statsData.avg_response_time) {
                cards.push({ label: 'Avg Response', value: statsData.avg_response_time, color: 'warning', icon: 'bi-stopwatch' });
            }
            if (statsData.avg_close_time) {
                cards.push({ label: 'Avg Close Time', value: statsData.avg_close_time, color: 'secondary', icon: 'bi-clock-history' });
            }
        }

        // Report-specific summary
        var summary = reportData ? reportData.summary : {};
        if (currentReport === 'unit_log' && summary.avg_response_time) {
            cards.push({ label: 'Avg Unit Response', value: summary.avg_response_time, color: 'warning', icon: 'bi-stopwatch' });
        }
        if (currentReport === 'dispatch_log' && summary.avg_total_time) {
            cards.push({ label: 'Avg Dispatch Time', value: summary.avg_total_time, color: 'warning', icon: 'bi-stopwatch' });
        }
        if (currentReport === 'incident_summary' && summary.avg_close_time_mins !== null && summary.avg_close_time_mins !== undefined) {
            var hrs = Math.floor(summary.avg_close_time_mins / 60);
            var mins = summary.avg_close_time_mins % 60;
            var timeStr = hrs > 0 ? hrs + 'h ' + mins + 'm' : mins + 'm';
            cards.push({ label: 'Avg Close (Mins)', value: timeStr, color: 'secondary', icon: 'bi-clock' });
        }

        if (cards.length === 0) return;

        summaryCards.classList.remove('d-none');

        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var col = document.createElement('div');
            col.className = 'col-6 col-sm-4 col-md-3 col-lg-2';

            var cardEl = document.createElement('div');
            cardEl.className = 'card summary-card border-' + card.color;

            var body = document.createElement('div');
            body.className = 'card-body py-2 px-3 text-center';

            var iconEl = document.createElement('i');
            iconEl.className = 'bi ' + card.icon + ' text-' + card.color;
            iconEl.style.fontSize = '1.2rem';
            body.appendChild(iconEl);

            var valEl = document.createElement('div');
            valEl.className = 'fw-bold';
            valEl.textContent = String(card.value);
            body.appendChild(valEl);

            var lblEl = document.createElement('div');
            lblEl.className = 'text-body-secondary small';
            lblEl.textContent = card.label;
            body.appendChild(lblEl);

            cardEl.appendChild(body);
            col.appendChild(cardEl);
            summaryCards.appendChild(col);
        }
    }

    // ── After Action Panel ────────────────────────────────────────────────────

    function renderAfterActionPanel(summary) {
        afterActionPanel.classList.remove('d-none');
        afterActionInfo.innerHTML = '';

        var fields = [
            { label: 'Incident', value: '#' + summary.incident_id + ' - ' + (summary.scope || '') },
            { label: 'Type', value: summary.incident_type || '' },
            { label: 'Severity', value: summary.severity || '' },
            { label: 'Status', value: summary.status || '' },
            { label: 'Location', value: summary.location || '' },
            { label: 'Description', value: summary.description || '' },
            { label: 'Problem Start', value: summary.problem_start || '' },
            { label: 'Problem End', value: summary.problem_end || '' },
            { label: 'Units Assigned', value: summary.units_assigned || 0 },
            { label: 'Actions', value: summary.actions_count || 0 }
        ];

        var row = document.createElement('div');
        row.className = 'row g-2';

        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            var col = document.createElement('div');
            col.className = (f.label === 'Description' || f.label === 'Location') ? 'col-md-6' : 'col-md-3';

            var lbl = document.createElement('div');
            lbl.className = 'text-body-secondary';
            lbl.textContent = f.label;
            col.appendChild(lbl);

            var val = document.createElement('div');
            val.textContent = String(f.value);
            if (f.label === 'Description') {
                val.style.whiteSpace = 'pre-wrap';
            }
            col.appendChild(val);

            row.appendChild(col);
        }

        afterActionInfo.appendChild(row);

        // Protocol text
        if (summary.protocol) {
            var protoDiv = document.createElement('div');
            protoDiv.className = 'mt-2 p-2 border rounded bg-info bg-opacity-10';

            var protoLabel = document.createElement('div');
            protoLabel.className = 'fw-semibold text-info small mb-1';
            protoLabel.textContent = 'Response Protocol';
            protoDiv.appendChild(protoLabel);

            var protoText = document.createElement('div');
            protoText.style.whiteSpace = 'pre-wrap';
            protoText.textContent = summary.protocol;
            protoDiv.appendChild(protoText);

            afterActionInfo.appendChild(protoDiv);
        }
    }

    // ── CSV Export ─────────────────────────────────────────────────────────────

    function exportCSV() {
        if (!reportData || !reportData.rows || reportData.rows.length === 0) return;

        var lines = [];

        // Header row
        lines.push(reportData.columns.map(csvEscape).join(','));

        // Data rows
        for (var i = 0; i < reportData.rows.length; i++) {
            lines.push(reportData.rows[i].map(function (v) {
                return csvEscape((v !== null && v !== undefined) ? String(v) : '');
            }).join(','));
        }

        var csv = lines.join('\r\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);

        var a = document.createElement('a');
        a.href = url;
        a.download = currentReport + '_' + currentPeriod + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function csvEscape(val) {
        if (val === null || val === undefined) return '""';
        var s = String(val);
        if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    // ── Print ─────────────────────────────────────────────────────────────────

    function printReport() {
        window.print();
    }

    // ── Init on DOMContentLoaded ──────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
