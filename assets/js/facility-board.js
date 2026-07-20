/**
 * NewUI v4.0 - Facility Board
 *
 * Wall-mount optimized display of all facilities with status and capacity.
 * Auto-refreshes via 30-second polling with SSE event support.
 * Color-coded capacity bars (green >50%, yellow 25-50%, red <25%, gray closed).
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── Configuration ───────────────────────────────────────────────
    var POLL_INTERVAL = 30000;     // 30 seconds
    var CLOCK_INTERVAL = 1000;     // 1 second

    // ── State ───────────────────────────────────────────────────────
    var facilities = [];
    var capacityData = {};          // facility_id -> [{category, total, occupied, available, status}]
    var hasCapacity = false;
    var filterType = 'all';
    var filterStatus = 'all';
    var pollTimer = null;
    var clockTimer = null;

    // ── DOM References ──────────────────────────────────────────────
    var gridEl = null;
    var emptyEl = null;
    var clockEl = null;
    var dateEl = null;
    var countEl = null;
    var openCountEl = null;
    var closedCountEl = null;
    var filterTypeEl = null;
    var filterStatusEl = null;

    // ── Initialization ──────────────────────────────────────────────
    function init() {
        gridEl = document.getElementById('fbGrid');
        emptyEl = document.getElementById('fbEmpty');
        clockEl = document.getElementById('fbClock');
        dateEl = document.getElementById('fbDate');
        countEl = document.getElementById('fbCount');
        openCountEl = document.getElementById('fbOpenCount');
        closedCountEl = document.getElementById('fbClosedCount');
        filterTypeEl = document.getElementById('fbFilterType');
        filterStatusEl = document.getElementById('fbFilterStatus');

        var capEl = document.getElementById('hasCapacity');
        hasCapacity = capEl && capEl.value === '1';

        // Bind filters
        if (filterTypeEl) {
            filterTypeEl.addEventListener('change', function () {
                filterType = this.value;
                render();
            });
        }
        if (filterStatusEl) {
            filterStatusEl.addEventListener('change', function () {
                filterStatus = this.value;
                render();
            });
        }

        // Start clock
        updateClock();
        clockTimer = setInterval(updateClock, CLOCK_INTERVAL);

        // Initial load
        loadData();

        // Polling
        pollTimer = setInterval(loadData, POLL_INTERVAL);

        // SSE: listen for facility updates if EventBus is available
        if (typeof window.EventBus !== 'undefined') {
            window.EventBus.on('facility_update', function () {
                loadData();
            });
        }
    }

    // ── Clock ───────────────────────────────────────────────────────
    function updateClock() {
        var now = new Date();
        var h = padZero(now.getHours());
        var m = padZero(now.getMinutes());
        var s = padZero(now.getSeconds());
        if (clockEl) clockEl.textContent = h + ':' + m + ':' + s;

        if (dateEl) {
            var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                          'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            dateEl.textContent = days[now.getDay()] + ' ' + months[now.getMonth()] + ' ' +
                now.getDate() + ', ' + now.getFullYear();
        }
    }

    function padZero(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    // ── Data Loading ────────────────────────────────────────────────
    function loadData() {
        // Load facilities
        fetch('api/facilities.php')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) return;
                facilities = data.facilities || [];

                // Load capacity data if available
                if (hasCapacity) {
                    loadCapacity();
                } else {
                    render();
                }
            })
            .catch(function () {
                // Silently fail on poll errors
            });
    }

    function loadCapacity() {
        // QA #10 — the board shows ALL facilities, so it must use summary mode.
        // Without ?summary=1 the endpoint returns {error:'facility_id required'}
        // and the board dropped all capacity data, never drawing a single bar.
        fetch('api/facility-capacity.php?summary=1')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    capacityData = {};
                } else {
                    capacityData = {};
                    var rows = data.capacity || [];
                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        var fid = parseInt(row.facility_id, 10);
                        if (!capacityData[fid]) capacityData[fid] = [];
                        capacityData[fid].push(row);
                    }
                }
                render();
            })
            .catch(function () {
                capacityData = {};
                render();
            });
    }

    // ── Rendering ───────────────────────────────────────────────────
    function render() {
        if (!gridEl) return;

        var filtered = facilities.filter(function (fac) {
            // Type filter
            if (filterType !== 'all' && String(fac.type_id) !== filterType) return false;
            // Status filter
            if (filterStatus !== 'all') {
                var facStatus = determineFacStatus(fac);
                if (facStatus !== filterStatus) return false;
            }
            return true;
        });

        // Update counts
        if (countEl) countEl.textContent = filtered.length;

        var openCount = 0;
        var closedCount = 0;
        for (var c = 0; c < filtered.length; c++) {
            var st = determineFacStatus(filtered[c]);
            if (st === 'open') openCount++;
            else closedCount++;
        }
        if (openCountEl) openCountEl.textContent = openCount + ' Open';
        if (closedCountEl) closedCountEl.textContent = closedCount + ' Closed/Full';

        if (filtered.length === 0) {
            gridEl.innerHTML = '';
            emptyEl.classList.remove('d-none');
            return;
        }

        emptyEl.classList.add('d-none');

        var html = '';
        for (var i = 0; i < filtered.length; i++) {
            html += renderCard(filtered[i]);
        }
        gridEl.innerHTML = html;
    }

    function renderCard(fac) {
        var status = determineFacStatus(fac);
        var statusLabel = status.charAt(0).toUpperCase() + status.slice(1);
        var statusBadgeClass = 'fb-status-' + status;
        var cardBorderClass = 'fb-card-' + status;

        // Type icon color — use facility type icon or default
        var typeIcon = fac.type_icon || 'bi-building';

        // Hours display
        var hoursText = fac.hours_today || '';

        // Capacity data for this facility
        var caps = capacityData[fac.id] || [];

        var html = '<div class="fb-card ' + cardBorderClass + '">';

        // Header: name + status badge
        html += '<div class="fb-card-header">';
        html += '<div>';
        html += '<a href="facility-detail.php?id=' + fac.id + '" class="fb-card-name" target="_blank">' +
            escHtml(fac.name) + '</a>';
        html += '<div class="fb-card-type">';
        if (fac.bg_color && fac.bg_color !== '#ffffff') {
            html += '<span class="fb-type-dot" style="background:' + escAttr(fac.bg_color) + '"></span>';
        }
        html += '<i class="bi ' + escHtml(typeIcon) + '"></i> ' + escHtml(fac.type_name || 'Unknown');
        html += '</div>';
        html += '</div>';
        // GH #49 — the status BADGE must show the configured fac_status
        // colour + real label (determineFacStatus only maps to a coarse
        // open/closed/full accent for the card border, and would mislabel a
        // custom status like "Unknown" as "Open"). Use the shared helper.
        if (window.FacilityStatus) {
            html += window.FacilityStatus.badge(fac, { emptyDash: true });
        } else {
            html += '<span class="fb-status-badge ' + statusBadgeClass + '">' + escHtml(statusLabel) + '</span>';
        }
        html += '</div>';

        // Info row: address, phone, hours
        html += '<div class="fb-card-info">';
        if (fac.street || fac.city) {
            var addr = fac.street || '';
            if (fac.city) addr += (addr ? ', ' : '') + fac.city;
            if (fac.state) addr += ', ' + fac.state;
            html += '<span><i class="bi bi-geo-alt"></i>' + escHtml(addr) + '</span>';
        }
        if (fac.phone) {
            html += '<span><i class="bi bi-telephone"></i>' + escHtml(fac.phone) + '</span>';
        }
        if (hoursText) {
            html += '<span><i class="bi bi-clock"></i>' + escHtml(hoursText) + '</span>';
        }
        html += '</div>';

        // Capacity bars (only if tracking enabled and data exists)
        if (hasCapacity && caps.length > 0) {
            html += '<div class="fb-capacity-section">';
            html += '<div class="fb-capacity-label"><i class="bi bi-bar-chart me-1"></i>Capacity</div>';

            for (var i = 0; i < caps.length; i++) {
                var cap = caps[i];
                // API contract audit 2026-07-07: the capacity API emits
                // total + AVAILABLE (never `occupied`) — the old read
                // parsed occupied as 0, so every capacity bar showed
                // full availability regardless of reality.
                var total = parseInt(cap.total, 10) || 0;
                var available = parseInt(cap.available, 10) || 0;
                if (available > total) available = total;
                var occupied = total - available;
                if (occupied < 0) occupied = 0;
                var pctAvailable = total > 0 ? (available / total) * 100 : 0;
                var pctOccupied = total > 0 ? (occupied / total) * 100 : 0;

                // Color based on available percentage
                var barColor = getCapacityColor(pctAvailable, cap.status);

                html += '<div class="fb-cap-row">';
                html += '<span class="fb-cap-category">' + escHtml(cap.category_name || cap.category || '') + '</span>';
                html += '<div class="fb-cap-bar-wrap">';
                html += '<div class="fb-cap-bar ' + barColor + '" style="width:' + Math.min(pctOccupied, 100) + '%"></div>';
                html += '</div>';
                html += '<span class="fb-cap-counts">' + available + '/' + total + '</span>';
                html += '</div>';
            }

            html += '</div>';
        }

        // Updated timestamp
        if (fac.updated) {
            html += '<div class="fb-card-updated">Updated: ' + escHtml(formatTimestamp(fac.updated)) + '</div>';
        }

        html += '</div>';
        return html;
    }

    // ── Status Determination ────────────────────────────────────────
    function determineFacStatus(fac) {
        // Check explicit status name
        var statusName = (fac.status_name || '').toLowerCase();
        if (statusName.indexOf('close') !== -1) return 'closed';
        if (statusName.indexOf('full') !== -1) return 'full';
        if (statusName.indexOf('open') !== -1) return 'open';

        // Check is_open from hours
        if (fac.is_open === false) return 'closed';
        if (fac.is_open === true) return 'open';

        // Default to open
        return 'open';
    }

    // ── Capacity Color ──────────────────────────────────────────────
    function getCapacityColor(pctAvailable, status) {
        if (status === 'closed') return 'fb-cap-gray';
        if (pctAvailable > 50) return 'fb-cap-green';
        if (pctAvailable > 25) return 'fb-cap-yellow';
        return 'fb-cap-red';
    }

    // ── Utilities ───────────────────────────────────────────────────
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatTimestamp(ts) {
        if (!ts) return '';
        var d = new Date(ts.replace(' ', 'T'));
        if (isNaN(d.getTime())) return ts;
        return padZero(d.getMonth() + 1) + '/' + padZero(d.getDate()) + ' ' +
            padZero(d.getHours()) + ':' + padZero(d.getMinutes());
    }

    // ── Boot ────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
