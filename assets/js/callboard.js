/**
 * NewUI v4.0 - Dispatch Call Board
 *
 * Provides real-time incident display for wall-mounted monitors.
 * Auto-updates elapsed time every second, color-codes by threshold.
 * Subscribes to EventBus SSE for live updates with polling fallback.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── Configuration ───────────────────────────────────────────────
    var POLL_INTERVAL = 10000;      // Fallback polling (ms)
    var ELAPSED_INTERVAL = 1000;    // Elapsed time update (ms)
    var SCROLL_DELAY = 3000;        // Delay before auto-scrolling to new incident

    // Elapsed time thresholds (seconds)
    var THRESH_YELLOW   = 5 * 60;   //  5 minutes
    var THRESH_ORANGE   = 15 * 60;  // 15 minutes
    var THRESH_RED      = 30 * 60;  // 30 minutes
    var THRESH_CRITICAL = 60 * 60;  // 60 minutes

    // ── State ───────────────────────────────────────────────────────
    var incidents = [];
    var knownIds = {};
    var pollTimer = null;
    var elapsedTimer = null;
    var filterSeverity = 'all';
    var filterType = 'all';
    var soundEnabled = true;
    var incidentTypes = [];

    // ── DOM References ──────────────────────────────────────────────
    var tableBody = null;
    var clockEl = null;
    var dateEl = null;
    var countEl = null;
    var filterSevEl = null;
    var filterTypeEl = null;
    var soundBtn = null;

    // ── Initialization ──────────────────────────────────────────────
    function init() {
        tableBody = document.getElementById('cbTableBody');
        clockEl = document.getElementById('cbClock');
        dateEl = document.getElementById('cbDate');
        countEl = document.getElementById('cbCount');
        filterSevEl = document.getElementById('cbFilterSev');
        filterTypeEl = document.getElementById('cbFilterType');
        soundBtn = document.getElementById('cbSoundBtn');

        // Start the clock
        updateClock();
        setInterval(updateClock, 1000);

        // Bind filter controls
        if (filterSevEl) {
            filterSevEl.addEventListener('change', function () {
                filterSeverity = filterSevEl.value;
                renderTable();
            });
        }
        if (filterTypeEl) {
            filterTypeEl.addEventListener('change', function () {
                filterType = filterTypeEl.value;
                renderTable();
            });
        }

        // Sound toggle
        if (soundBtn) {
            soundBtn.addEventListener('click', function () {
                soundEnabled = !soundEnabled;
                var icon = soundBtn.querySelector('i');
                if (icon) {
                    icon.className = soundEnabled ? 'bi bi-volume-up' : 'bi bi-volume-mute';
                }
                soundBtn.setAttribute('title', soundEnabled ? 'Mute alerts' : 'Unmute alerts');
            });
        }

        // Keyboard: Escape to close window
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                window.close();
            }
        });

        // Initial data load
        loadIncidents();

        // Start elapsed time updater
        elapsedTimer = setInterval(updateAllElapsed, ELAPSED_INTERVAL);

        // Start fallback polling
        pollTimer = setInterval(loadIncidents, POLL_INTERVAL);

        // Connect SSE for real-time updates
        initSSE();
    }

    // ── Clock ───────────────────────────────────────────────────────
    function updateClock() {
        var now = new Date();
        var hh = now.getHours();
        var mm = now.getMinutes();
        var ss = now.getSeconds();

        if (clockEl) {
            clockEl.textContent =
                (hh < 10 ? '0' : '') + hh + ':' +
                (mm < 10 ? '0' : '') + mm + ':' +
                (ss < 10 ? '0' : '') + ss;
        }

        if (dateEl) {
            var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                          'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            dateEl.textContent = days[now.getDay()] + ' ' +
                months[now.getMonth()] + ' ' + now.getDate() + ', ' +
                now.getFullYear();
        }
    }

    // ── Data Loading ────────────────────────────────────────────────
    function loadIncidents() {
        fetch('api/callboard.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;

                var newIncidents = data.incidents || [];
                var previousIds = knownIds;

                // Rebuild known IDs
                knownIds = {};
                for (var i = 0; i < newIncidents.length; i++) {
                    knownIds[newIncidents[i].id] = true;
                }

                // Detect new incidents for sound alert
                for (var j = 0; j < newIncidents.length; j++) {
                    if (!previousIds[newIncidents[j].id] && Object.keys(previousIds).length > 0) {
                        newIncidents[j]._isNew = true;
                        playNewIncidentAlert(newIncidents[j]);
                    }
                }

                incidents = newIncidents;

                // Populate type filter if not yet done
                if (incidentTypes.length === 0 && data.types) {
                    incidentTypes = data.types;
                    populateTypeFilter();
                }

                // Update count
                if (countEl) {
                    countEl.textContent = incidents.length;
                }

                renderTable();
            })
            .catch(function () {
                // Silently ignore — will retry on next poll
            });
    }

    // ── Type Filter Population ──────────────────────────────────────
    function populateTypeFilter() {
        if (!filterTypeEl || incidentTypes.length === 0) return;

        for (var i = 0; i < incidentTypes.length; i++) {
            var opt = document.createElement('option');
            opt.value = incidentTypes[i];
            opt.textContent = incidentTypes[i];
            filterTypeEl.appendChild(opt);
        }
    }

    // ── Table Rendering ─────────────────────────────────────────────
    function renderTable() {
        if (!tableBody) return;

        var filtered = getFilteredIncidents();

        if (filtered.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="9" class="cb-empty">' +
                '<i class="bi bi-check-circle"></i>No active incidents</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < filtered.length; i++) {
            html += renderRow(filtered[i]);
        }
        tableBody.innerHTML = html;

        // Auto-scroll to new incidents
        for (var j = 0; j < filtered.length; j++) {
            if (filtered[j]._isNew) {
                var row = document.getElementById('cb-row-' + filtered[j].id);
                if (row) {
                    setTimeout((function (el) {
                        return function () {
                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        };
                    })(row), SCROLL_DELAY);
                }
                filtered[j]._isNew = false;
            }
        }
    }

    function renderRow(inc) {
        var sevClass = 'cb-row-sev-' + (inc.severity || 0);
        var newClass = inc._isNew ? ' cb-row-new' : '';
        var elapsedInfo = getElapsedInfo(inc.created);

        var addr = inc.street || '';
        if (inc.city) addr += (addr ? ', ' : '') + inc.city;

        var statusLabel = getStatusLabel(inc.status);
        var statusClass = getStatusClass(inc.status);
        var sevLabel = getSeverityLabel(inc.severity);
        var sevBadgeClass = 'cb-sev-' + (inc.severity || 0);

        var html = '<tr id="cb-row-' + inc.id + '" class="' + sevClass + newClass + '">';

        // Phase 99p — show admin-configured case number, fall back
        // to "#<id>" for legacy tickets without one.
        var caseNum = inc.incident_number || ('#' + inc.id);
        html += '<td><a href="incident-detail.php?id=' + inc.id + '" class="cb-ticket-link" target="_blank">' + esc(caseNum) + '</a></td>';

        // Type
        html += '<td>';
        if (inc.type_color) {
            html += '<span class="cb-type-dot" style="background:' + escAttr(inc.type_color) + ';"></span>';
        }
        html += esc(inc.incident_type || 'Unknown') + '</td>';

        // Severity
        html += '<td><span class="cb-sev-badge ' + sevBadgeClass + '">' + esc(sevLabel) + '</span></td>';

        // Location
        html += '<td>' + esc(addr) + '</td>';

        // Units
        html += '<td>';
        html += '<span class="cb-units-count">' + (inc.units_assigned || 0) + '</span>';
        if (inc.unit_names) {
            html += '<div class="cb-units-names" title="' + escAttr(inc.unit_names) + '">' + esc(inc.unit_names) + '</div>';
        }
        html += '</td>';

        // Status
        html += '<td><span class="cb-status-badge ' + statusClass + '">' + esc(statusLabel) + '</span></td>';

        // Opened
        html += '<td class="cb-timestamp">' + formatTimestamp(inc.created) + '</td>';

        // Elapsed
        html += '<td><span class="cb-elapsed ' + elapsedInfo.cssClass + '" data-created="' + escAttr(inc.created || '') + '">'
            + elapsedInfo.text + '</span></td>';

        // Progression
        html += '<td class="cb-progression">' + renderProgression(inc) + '</td>';

        html += '</tr>';
        return html;
    }

    // ── Filtering ───────────────────────────────────────────────────
    function getFilteredIncidents() {
        var result = [];
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];

            if (filterSeverity !== 'all' && String(inc.severity) !== filterSeverity) {
                continue;
            }
            if (filterType !== 'all' && inc.incident_type !== filterType) {
                continue;
            }

            result.push(inc);
        }
        return result;
    }

    // ── Elapsed Time ────────────────────────────────────────────────
    function getElapsedInfo(created) {
        if (!created) return { text: '--:--', cssClass: 'cb-elapsed-green', seconds: 0 };

        var now = new Date();
        var start = new Date(created);
        var diff = Math.floor((now.getTime() - start.getTime()) / 1000);

        if (diff < 0) diff = 0;

        var text = formatElapsed(diff);
        var cssClass = getElapsedClass(diff);

        return { text: text, cssClass: cssClass, seconds: diff };
    }

    function formatElapsed(totalSeconds) {
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        if (hours > 0) {
            return hours + ':' +
                (minutes < 10 ? '0' : '') + minutes + ':' +
                (seconds < 10 ? '0' : '') + seconds;
        }
        return (minutes < 10 ? '0' : '') + minutes + ':' +
            (seconds < 10 ? '0' : '') + seconds;
    }

    function getElapsedClass(seconds) {
        if (seconds >= THRESH_CRITICAL) return 'cb-elapsed-critical';
        if (seconds >= THRESH_RED)      return 'cb-elapsed-red';
        if (seconds >= THRESH_ORANGE)   return 'cb-elapsed-orange';
        if (seconds >= THRESH_YELLOW)   return 'cb-elapsed-yellow';
        return 'cb-elapsed-green';
    }

    function updateAllElapsed() {
        var cells = document.querySelectorAll('.cb-elapsed[data-created]');
        for (var i = 0; i < cells.length; i++) {
            var created = cells[i].getAttribute('data-created');
            if (!created) continue;

            var info = getElapsedInfo(created);
            cells[i].textContent = info.text;

            // Update CSS class for color transition
            cells[i].className = 'cb-elapsed ' + info.cssClass;
        }
    }

    // ── Status Helpers ──────────────────────────────────────────────
    function getStatusLabel(status) {
        switch (parseInt(status, 10)) {
            case 1: return 'Closed';
            case 2: return 'Open';
            case 3: return 'Scheduled';
            default: return 'Unknown';
        }
    }

    function getStatusClass(status) {
        switch (parseInt(status, 10)) {
            case 1: return 'cb-status-closed';
            case 2: return 'cb-status-open';
            case 3: return 'cb-status-dispatched';
            default: return 'cb-status-open';
        }
    }

    function getSeverityLabel(sev) {
        switch (parseInt(sev, 10)) {
            case 0: return 'Normal';
            case 1: return 'Medium';
            case 2: return 'High';
            default: return 'Normal';
        }
    }

    // ── Progression ─────────────────────────────────────────────────
    function renderProgression(inc) {
        var steps = [];

        // Created
        if (inc.created) {
            steps.push({ label: 'Created', time: inc.created, active: true });
        }

        // Dispatched (problemstart)
        if (inc.problemstart) {
            steps.push({ label: 'Dispatched', time: inc.problemstart, active: true });
        }

        // Closed (problemend)
        if (inc.problemend && parseInt(inc.status, 10) === 1) {
            steps.push({ label: 'Closed', time: inc.problemend, active: true });
        }

        if (steps.length === 0) return '';

        var html = '';
        var lastIdx = steps.length - 1;
        for (var i = 0; i < steps.length; i++) {
            var isLast = (i === lastIdx);
            html += '<span class="cb-progression-step' + (isLast ? ' cb-progression-active' : '') + '">'
                + esc(steps[i].label) + ' ' + formatShortTime(steps[i].time)
                + '</span>';
            if (!isLast) html += ' <i class="bi bi-arrow-right" style="font-size:0.6rem"></i> ';
        }
        return html;
    }

    // ── Sound Alerts ────────────────────────────────────────────────
    function playNewIncidentAlert(inc) {
        if (!soundEnabled) return;

        // Use AudioAlerts if available
        if (typeof AudioAlerts !== 'undefined') {
            if (parseInt(inc.severity, 10) >= 2) {
                AudioAlerts.playTone('highSeverity');
            } else {
                AudioAlerts.playTone('newIncident');
            }
            return;
        }

        // Simple beep fallback using Web Audio API
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);

            var isHigh = parseInt(inc.severity, 10) >= 2;
            osc.frequency.value = isHigh ? 880 : 660;
            osc.type = 'sine';
            gain.gain.value = 0.3;

            osc.start();
            osc.stop(ctx.currentTime + (isHigh ? 0.5 : 0.3));
        } catch (e) {
            // Audio not available — ignore
        }
    }

    // ── SSE Real-Time Updates ───────────────────────────────────────
    function initSSE() {
        if (typeof EventBus === 'undefined') return;

        EventBus.on('incident:new', function () {
            loadIncidents();
        });
        EventBus.on('incident:update', function () {
            loadIncidents();
        });
        EventBus.on('incident:close', function () {
            loadIncidents();
        });
        EventBus.on('responder:assign', function () {
            loadIncidents();
        });
        EventBus.on('system:refresh', function () {
            loadIncidents();
        });
    }

    // ── Formatting Utilities ────────────────────────────────────────
    function formatTimestamp(dt) {
        if (!dt) return '';
        try {
            var d = new Date(dt);
            if (isNaN(d.getTime())) return dt;
            var mo = d.getMonth() + 1;
            var day = d.getDate();
            var hh = d.getHours();
            var mm = d.getMinutes();
            return (mo < 10 ? '0' : '') + mo + '/' + (day < 10 ? '0' : '') + day + ' ' +
                (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
        } catch (e) {
            return dt;
        }
    }

    function formatShortTime(dt) {
        if (!dt) return '';
        try {
            var d = new Date(dt);
            if (isNaN(d.getTime())) return '';
            var hh = d.getHours();
            var mm = d.getMinutes();
            return (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
        } catch (e) {
            return '';
        }
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                  .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ── Start ───────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
