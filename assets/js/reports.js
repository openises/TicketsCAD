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
    var memberList = [];

    // Incident Summary → incident type → filtered Incidents list (Eric,
    // 2026-08-13). Unlike every other drill-down kind, clicking a type
    // doesn't open one record — it switches the active report tab to
    // Incidents and re-runs it filtered to that type. typeFilterId is sent
    // as in_types_id on every incident_report request while it's set.
    var typeFilterId = 0;
    var typeFilterLabel = '';

    // Drill-down link targets by kind (GH#51 follow-up, 2026-08-13) — keyed
    // to match the 'kind' string api/reports.php sends in reportData.links.
    var LINK_KIND_URL = {
        incident: 'incident-detail.php?id=',
        unit: 'unit-detail.php?id=',
        facility: 'facility-detail.php?id=',
        member: 'roster.php?id=',
        team: 'teams.php?id='
    };

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
    var memberFilter      = document.getElementById('memberFilter');
    var memberFilterCol   = document.getElementById('memberFilterCol');
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
    // GH#64 — Interval Report by-type/by-unit breakdown panel.
    var intervalBreakdownPanel = document.getElementById('intervalBreakdownPanel');
    var intervalByTypeBody     = document.getElementById('intervalByTypeBody');
    var intervalByUnitBody     = document.getElementById('intervalByUnitBody');

    // ── Init ──────────────────────────────────────────────────────────────────

    function init() {
        bindEvents();
        loadResponders();
        loadMembers();
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

        // A type filter only makes sense on the Incidents report — clicking
        // any OTHER tab manually clears it, same discipline as GH#57's
        // incidentFilter clear-on-hide (a stale filter surviving a tab
        // switch would silently narrow a report the user never asked to
        // filter). drillIntoIncidentType() sets it back AFTER calling this,
        // so drilling in still works.
        if (type !== 'incident_report') {
            typeFilterId = 0;
            typeFilterLabel = '';
            hideTypeFilterBanner();
        }

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
        // GH#57 follow-up (2026-08-14): facility_log and notes_log both
        // accept a responder_id filter server-side (api/reports.php) but
        // were missing from this whitelist, so the dropdown never appeared
        // for them at all -- reported as "Facility log, notes log has the
        // same error" as Incidents (which never needed the field). Also
        // clear the value the moment the field is hidden, the same fix
        // GH#57 already applied to incidentFilter just below: runReport()
        // reads responderFilter.value unconditionally, so a responder
        // picked on Unit Log kept being sent to every other report tab
        // until a full page reload -- the "have to click Reports from the
        // main menu to get it to reappear correctly" part of the report.
        // GH#57 follow-up (cbyrdmo, 2026-08-15): same missing-capability
        // shape as the facility_log/notes_log fix above -- incident_report
        // was left off this whitelist too. "Show me every incident this
        // unit was on" is exactly the kind of question the Incidents tab
        // should answer, and api/reports.php's incident_report case now
        // supports it (a responder_id EXISTS filter against assigns,
        // scoped so a ticket with multiple assigned units doesn't get
        // duplicated).
        // GH#64 — Interval Report accepts the same responder_id filter as
        // unit_log/dispatch_log (api/reports.php's interval_report case).
        var showResponder = (type === 'unit_log' || type === 'dispatch_log' ||
                             type === 'facility_log' || type === 'notes_log' ||
                             type === 'incident_report' || type === 'interval_report');
        responderFilterCol.classList.toggle('d-none', !showResponder);
        if (!showResponder) {
            responderFilter.value = '0';
        }

        var showIncident = (type === 'after_action');
        incidentFilterCol.classList.toggle('d-none', !showIncident);
        // GH#57 follow-up (2026-08-13): hiding the field left its VALUE in
        // place, so runReport() -- which reads incidentFilter.value
        // unconditionally, regardless of which tab is active -- kept
        // sending the After Action tab's incident_number filter to every
        // other report tab until a full page reload. Clear it the moment
        // it's hidden, not just on page load.
        if (!showIncident) {
            incidentFilter.value = '';
        }

        // GH#57 follow-up (cbyrdmo, 2026-08-15) -- Personnel reports had no
        // way to scope to one person at all. This is a Member filter, not a
        // Responder one: "Responder" above sources api/responders.php (the
        // `responder` table -- units/vehicles), while Personnel reports are
        // built from the `member` table (people/roster) -- two different
        // entities, so they get their own dropdown rather than overloading
        // the Responder one with two shapes of data.
        var showMember = (type === 'roster_snapshot' || type === 'time_summary' ||
                          type === 'license_expirations' || type === 'membership_due' ||
                          type === 'inactive_members' || type === 'dmr_inventory');
        memberFilterCol.classList.toggle('d-none', !showMember);
        if (!showMember) {
            memberFilter.value = '0';
        }

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

    // ── Incident Summary → type drill-down ──────────────────────────────────────

    /** Switch to the Incidents report, filtered to one incident type, and run it. */
    function drillIntoIncidentType(typeId, typeLabel) {
        selectReportType('incident_report');
        typeFilterId = typeId;
        typeFilterLabel = typeLabel;
        showTypeFilterBanner();
        runReport();
    }

    function showTypeFilterBanner() {
        hideTypeFilterBanner();
        var banner = document.createElement('div');
        banner.id = 'typeFilterBanner';
        banner.className = 'alert alert-info alert-dismissible fade show d-flex align-items-center py-2 mb-2';

        var icon = document.createElement('i');
        icon.className = 'bi bi-funnel-fill me-2';
        banner.appendChild(icon);

        var span = document.createElement('span');
        span.appendChild(document.createTextNode('Showing incidents of type '));
        var strong = document.createElement('strong');
        strong.textContent = typeFilterLabel;
        span.appendChild(strong);
        banner.appendChild(span);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'btn btn-sm btn-outline-info ms-3';
        clearBtn.textContent = 'Clear Filter';
        clearBtn.addEventListener('click', clearTypeFilter);
        banner.appendChild(clearBtn);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('aria-label', 'Clear filter');
        closeBtn.addEventListener('click', clearTypeFilter);
        banner.appendChild(closeBtn);

        reportHeader.parentNode.insertBefore(banner, reportHeader);
    }

    function hideTypeFilterBanner() {
        var el = document.getElementById('typeFilterBanner');
        if (el) { el.remove(); }
    }

    function clearTypeFilter() {
        typeFilterId = 0;
        typeFilterLabel = '';
        hideTypeFilterBanner();
        runReport();
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

    // ── Load Members for Filter ─────────────────────────────────────────────────
    // GH#57 follow-up (cbyrdmo, 2026-08-15) -- Personnel reports' own filter,
    // sourced from api/members.php (the `member`/roster table), not
    // api/responders.php (the `responder`/units table loadResponders() uses).

    function loadMembers() {
        fetch('api/members.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.members) {
                    memberList = data.members;
                    var sel = memberFilter;
                    for (var i = 0; i < data.members.length; i++) {
                        var m = data.members[i];
                        var name = ((m.first_name || '') + ' ' + (m.last_name || '')).trim() || 'Member #' + m.id;
                        var opt = document.createElement('option');
                        opt.value = m.id;
                        opt.textContent = name + (m.callsign ? ' (' + m.callsign + ')' : '');
                        sel.appendChild(opt);
                    }
                }
            })
            .catch(function () {
                // Member list load failed — filters still work without it
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

        var mid = parseInt(memberFilter.value, 10) || 0;
        if (mid > 0) {
            params += '&member_id=' + mid;
        }

        // GH#51 — send the dispatcher's own case number as typed (e.g.
        // "26-0091"); the server resolves it to the internal ticket id.
        var iid = (incidentFilter.value || '').trim();
        if (iid !== '') {
            params += '&incident_number=' + encodeURIComponent(iid);
        }

        // Incident Summary → type drill-down (only applies to Incidents).
        if (currentReport === 'incident_report' && typeFilterId > 0) {
            params += '&in_types_id=' + typeFilterId;
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
        intervalBreakdownPanel.classList.add('d-none');
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

        // GH#64 — Interval Report by-type/by-unit breakdown panel.
        if (currentReport === 'interval_report') {
            renderIntervalBreakdown();
        } else {
            intervalBreakdownPanel.classList.add('d-none');
        }
    }

    // Eric, 2026-08-13 (GH#51 follow-up) — "hyperlink the ID so a user can
    // view the actual incident", applied to every report that has one, not
    // just the Incidents tab. reportData.incident_link_col/unit_link_col
    // name WHICH column (if any) gets linked for the CURRENT report;
    // row_ticket_ids/row_responder_ids are parallel arrays (same order as
    // rows) of the internal id to link to. The cell keeps showing whatever
    // it always showed (incident_number, a unit's name/handle) — only the
    // link TARGET carries the internal id, never the visible text.
    function renderRows(rows) {
        reportTableBody.innerHTML = '';

        // Map column index -> {col, kind, ids} from the generic links list
        // api/reports.php sends. A given column carries at most one kind.
        var linksByCol = {};
        var linkDescs = (reportData && reportData.links) || [];
        for (var i = 0; i < linkDescs.length; i++) {
            linksByCol[linkDescs[i].col] = linkDescs[i];
        }

        for (var r = 0; r < rows.length; r++) {
            var tr = document.createElement('tr');
            for (var c = 0; c < rows[r].length; c++) {
                var td = document.createElement('td');
                td.className = 'small';
                var val = rows[r][c];
                var text = (val !== null && val !== undefined) ? String(val) : '';
                var desc = linksByCol[c];

                // team_multi: a cell listing several teams (e.g. roster's
                // comma-joined Teams column) — one <a> per team, not one
                // link wrapping the whole joined string.
                if (desc && desc.kind === 'team_multi') {
                    var items = (desc.items && desc.items[r]) || [];
                    if (items.length && LINK_KIND_URL.team) {
                        for (var ii = 0; ii < items.length; ii++) {
                            if (ii > 0) td.appendChild(document.createTextNode(', '));
                            var mLink = document.createElement('a');
                            mLink.href = LINK_KIND_URL.team + items[ii].id;
                            mLink.textContent = items[ii].name;
                            td.appendChild(mLink);
                        }
                    } else {
                        td.textContent = text;
                    }
                    tr.appendChild(td);
                    continue;
                }

                // incident_type: doesn't open a single record — it switches
                // the active tab to Incidents, filtered to this type. Needs
                // a click handler, not a plain href, so it's handled apart
                // from the generic id-based link path below.
                if (desc && desc.kind === 'incident_type' && desc.ids && desc.ids[r] > 0 && text !== '') {
                    var typeLink = document.createElement('a');
                    typeLink.href = '#';
                    typeLink.textContent = text;
                    typeLink.addEventListener('click', (function (typeId, typeLabel) {
                        return function (evt) {
                            evt.preventDefault();
                            drillIntoIncidentType(typeId, typeLabel);
                        };
                    })(desc.ids[r], text));
                    td.appendChild(typeLink);
                    tr.appendChild(td);
                    continue;
                }

                var linkHref = null;
                if (desc && desc.ids && desc.ids[r] > 0 && LINK_KIND_URL[desc.kind]) {
                    linkHref = LINK_KIND_URL[desc.kind] + desc.ids[r];
                }

                if (linkHref && text !== '') {
                    var a = document.createElement('a');
                    a.href = linkHref;
                    a.textContent = text;
                    td.appendChild(a);
                } else {
                    td.textContent = text;
                }
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

        var rows = reportData.rows;

        // Eric 2026-08-12 — parseFloat() only reads a LEADING number, so
        // "26-0091" (Incident #) and "2026-06-15 14:22:09" (Dispatched /
        // Clear) both parsed to their shared year prefix for every row.
        // Every comparison "tied" at the same number, so the visible
        // order barely moved in either sort direction. Only take the
        // numeric path when the WHOLE trimmed value is a number; anything
        // else (dates, case numbers, "M:SS" durations) falls through to
        // string comparison, which sorts ISO-style dates correctly by
        // construction (lexicographic order = chronological order for
        // "YYYY-MM-DD HH:MM:SS").
        var REPORTS_NUMERIC_RE = /^-?\d+(\.\d+)?$/;
        function isWhollyNumeric(v) {
            return v !== null && v !== undefined && REPORTS_NUMERIC_RE.test(String(v).trim());
        }

        // GH#51 follow-up (2026-08-13) — sort a permutation of INDICES
        // rather than the rows array directly. reportData.links[].ids is
        // row-parallel (ids[r] is the drill-down id for rows[r]); sorting
        // rows alone leaves those id arrays in the original order, so
        // every link would point at the wrong record after a column sort.
        // The same permutation that reorders rows must reorder each ids
        // array identically.
        var order = [];
        for (var oi = 0; oi < rows.length; oi++) { order.push(oi); }

        order.sort(function (ia, ib) {
            var va = rows[ia][colIdx];
            var vb = rows[ib][colIdx];

            if (isWhollyNumeric(va) && isWhollyNumeric(vb)) {
                var na = parseFloat(va);
                var nb = parseFloat(vb);
                return sortAsc ? na - nb : nb - na;
            }

            // String comparison
            va = (va !== null && va !== undefined) ? String(va).toLowerCase() : '';
            vb = (vb !== null && vb !== undefined) ? String(vb).toLowerCase() : '';
            if (va < vb) return sortAsc ? -1 : 1;
            if (va > vb) return sortAsc ? 1 : -1;
            return 0;
        });

        reportData.rows = order.map(function (i) { return rows[i]; });

        var links = reportData.links || [];
        for (var li = 0; li < links.length; li++) {
            var ids = links[li].ids;
            if (ids) {
                links[li].ids = order.map(function (i) { return ids[i]; });
            }
            // team_multi carries 'items' (an array-of-arrays) instead of a
            // flat 'ids' array — same row-parallel permutation, different key.
            var items = links[li].items;
            if (items) {
                links[li].items = order.map(function (i) { return items[i]; });
            }
        }

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

        renderRows(reportData.rows);
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
        // GH#64 — Interval Report's own period-wide averages. Each is 'N/A'
        // (not sent as null) when zero rows in the period had that leg's
        // pair of milestones, so a call-heavy period with no transports at
        // all just quietly omits the "Avg Transport" card rather than
        // rendering it as a confusing "N/A" — 'N/A' still passes the
        // summary.avg_x_time truthy check below, so guard on it explicitly.
        if (currentReport === 'interval_report') {
            if (summary.avg_turnout_time && summary.avg_turnout_time !== 'N/A') {
                cards.push({ label: 'Avg Turnout', value: summary.avg_turnout_time, color: 'info', icon: 'bi-hourglass-split' });
            }
            if (summary.avg_travel_time && summary.avg_travel_time !== 'N/A') {
                cards.push({ label: 'Avg Travel', value: summary.avg_travel_time, color: 'info', icon: 'bi-signpost-split' });
            }
            if (summary.avg_response_time && summary.avg_response_time !== 'N/A') {
                cards.push({ label: 'Avg Response', value: summary.avg_response_time, color: 'warning', icon: 'bi-stopwatch' });
            }
            if (summary.avg_scene_time && summary.avg_scene_time !== 'N/A') {
                cards.push({ label: 'Avg Scene Time', value: summary.avg_scene_time, color: 'secondary', icon: 'bi-clock-history' });
            }
            if (summary.avg_transport_time && summary.avg_transport_time !== 'N/A') {
                cards.push({ label: 'Avg Transport', value: summary.avg_transport_time, color: 'primary', icon: 'bi-truck' });
            }
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

        // GH#57 — lead with the number an operator actually recognizes;
        // the internal id rides along in parentheses for cross-referencing.
        var incidentLabel = summary.incident_number
            ? summary.incident_number + ' (#' + summary.incident_id + ')'
            : '#' + summary.incident_id;
        var fields = [
            { label: 'Incident', value: incidentLabel + ' - ' + (summary.scope || '') },
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

    // ── Interval Report Breakdown Panel (GH#64) ──────────────────────────────

    /**
     * Populate the By Incident Type / By Unit mini-tables from
     * reportData.interval_by_type / interval_by_unit (api/reports.php's
     * 'interval_report' case — one entry per type/unit seen in the period,
     * each carrying count + formatted avg_response_time/avg_scene_time,
     * already sorted by count descending server-side). Built with plain DOM
     * methods (textContent, not innerHTML) — same convention every other
     * render function in this file already follows.
     */
    function renderIntervalBreakdown() {
        var byType = (reportData && reportData.interval_by_type) || [];
        var byUnit = (reportData && reportData.interval_by_unit) || [];

        if (byType.length === 0 && byUnit.length === 0) {
            intervalBreakdownPanel.classList.add('d-none');
            return;
        }
        intervalBreakdownPanel.classList.remove('d-none');

        function fillTable(tbody, items, labelFallback) {
            tbody.innerHTML = '';
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var tr = document.createElement('tr');

                var tdLabel = document.createElement('td');
                tdLabel.className = 'small';
                tdLabel.textContent = item.label || labelFallback;
                tr.appendChild(tdLabel);

                var tdCount = document.createElement('td');
                tdCount.className = 'small text-end';
                tdCount.textContent = String(item.count);
                tr.appendChild(tdCount);

                var tdResp = document.createElement('td');
                tdResp.className = 'small text-end';
                tdResp.textContent = item.avg_response_time || '—';
                tr.appendChild(tdResp);

                var tdScene = document.createElement('td');
                tdScene.className = 'small text-end';
                tdScene.textContent = item.avg_scene_time || '—';
                tr.appendChild(tdScene);

                tbody.appendChild(tr);
            }
        }

        fillTable(intervalByTypeBody, byType, 'Unknown');
        fillTable(intervalByUnitBody, byUnit, 'Unknown Unit');
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
