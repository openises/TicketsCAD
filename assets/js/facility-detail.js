/**
 * NewUI v4.0 - Facility Detail Page Logic
 *
 * Handles: fetch/render facility data, map, incident list,
 * transport stats.
 */

(function () {
    'use strict';

    var map = null;
    var marker = null;
    var facilityData = null;

    // ── Escape key → back to dashboard ──
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            window.location.href = 'index.php';
        }
    }, true);

    // ── Initialization ──
    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        var id = getFacilityId();
        if (!id) {
            showAlert('No facility ID specified. <a href="facilities.php" class="alert-link">Return to facilities list</a>', 'danger');
            document.getElementById('loadingSpinner').classList.add('d-none');
            return;
        }
        loadFacility(id);
    });

    function getFacilityId() {
        var params = new URLSearchParams(window.location.search);
        var id = parseInt(params.get('id'), 10);
        return id > 0 ? id : null;
    }

    // ── Theme toggle ──
    function initTheme() {
        var btns = document.querySelectorAll('#themeToggle button');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var theme = this.dataset.theme;
                document.documentElement.setAttribute('data-bs-theme', theme === 'Night' ? 'dark' : 'light');
                btns.forEach(function (b) {
                    b.className = 'btn ' + (b.dataset.theme === theme
                        ? (theme === 'Day' ? 'btn-warning' : 'btn-primary')
                        : 'btn-outline-secondary');
                });
            });
        });
    }

    // ── Load facility data from API ──
    function loadFacility(id) {
        fetch('api/facility-detail.php?id=' + id)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showAlert(escHtml(data.error), 'danger');
                    document.getElementById('loadingSpinner').classList.add('d-none');
                    return;
                }

                facilityData = data;

                // Update page title
                document.title = data.facility.name + ' — Facility — Tickets NewUI';

                renderHeader(data.facility);
                renderInfo(data.facility);
                renderLocation(data.facility);
                renderBeds(data.facility);
                renderIncidents(data.assigned_incidents);
                renderNotes(data.notes);
                renderStats(data.stats);
                initMap(data.facility);
                setupActions(data.facility);

                document.getElementById('loadingSpinner').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');
            })
            .catch(function (err) {
                showAlert('Failed to load facility: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Render Header ──
    function renderHeader(f) {
        var pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.innerHTML = '<i class="bi bi-hospital text-primary me-2"></i>' + escHtml(f.name);
        }

        var nameEl = document.getElementById('facilityName');
        if (nameEl) nameEl.textContent = f.name;

        var typeBadge = document.getElementById('typeBadge');
        if (typeBadge) {
            typeBadge.textContent = f.type_name || 'No Type';
            typeBadge.className = 'badge bg-info bg-opacity-75';
        }

        // GH #49 — this read f.status_bg / f.status_text, which the API
        // never sends (it sends bg_color / text_color), so the badge always
        // fell back to grey. Use the shared helper so the configured colour
        // shows here exactly as on every other facility surface.
        var statusBadge = document.getElementById('statusBadge');
        if (statusBadge && f.status_name) {
            var sb = window.FacilityStatus
                ? window.FacilityStatus.bits(f)
                : { label: f.status_name, bg: (f.bg_color || '#6c757d'), text: (f.text_color || '#ffffff') };
            statusBadge.textContent = sb.label;
            statusBadge.style.backgroundColor = sb.bg;
            statusBadge.style.color = sb.text;
        }

        var hiddenBadge = document.getElementById('hiddenBadge');
        if (hiddenBadge && f.hide) {
            hiddenBadge.classList.remove('d-none');
        }

        var meta = document.getElementById('facilityMeta');
        if (meta) {
            var parts = [];
            if (f.handle) parts.push('Handle: ' + f.handle);
            if (f.callsign) parts.push('Callsign: ' + f.callsign);
            if (f.updated) parts.push('Updated: ' + formatDate(f.updated));
            meta.textContent = parts.join(' | ') || '--';
        }
    }

    // ── Render Info / Contact ──
    function renderInfo(f) {
        setText('facilityDesc', f.description);
        setText('infoHandle', f.handle || '--');
        setText('infoCallsign', f.callsign || '--');
        setText('infoCapab', f.capab || '--');
        setText('contactName', f.contact_name || '--');
        setText('contactEmail', f.contact_email || '--');
        setText('contactPhone', f.contact_phone || '--');
    }

    // ── Render Location ──
    function renderLocation(f) {
        setText('locStreet', f.street || '--');
        setText('locCity', f.city || '--');
        setText('locState', f.state || '--');
        setText('locLat', f.lat != null ? f.lat.toFixed(6) : '--');
        setText('locLng', f.lng != null ? f.lng.toFixed(6) : '--');
    }

    // ── Render Beds / Capacity ──
    function renderBeds(f) {
        setText('bedsAvailable', f.beds_a || 0);
        setText('bedsOccupied', f.beds_o || 0);

        // Phase 103 — surface the bed-count automation mode as a badge next
        // to the "Bed Capacity" card header so a dispatcher knows whether
        // the counters are updating themselves or waiting for a human edit.
        var badge = document.getElementById('bedAutoModeBadge');
        if (badge) {
            var mode = (f.bed_auto_mode || 'manual').toLowerCase();
            if (mode === 'auto') {
                badge.textContent = 'Auto on delivery';
                badge.className = 'badge bg-info ms-2 small';
            } else {
                badge.textContent = 'Manual';
                badge.className = 'badge bg-secondary ms-2 small';
            }
            badge.classList.remove('d-none');
        }

        var total = (f.beds_a || 0) + (f.beds_o || 0);
        var pct = total > 0 ? Math.round((f.beds_o / total) * 100) : 0;
        var bar = document.getElementById('bedProgress');
        if (bar) {
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            bar.className = 'progress-bar ' + (pct > 80 ? 'bg-danger' : pct > 50 ? 'bg-warning' : 'bg-success');
        }

        // Bed info
        if (f.beds_info) {
            var infoRow = document.getElementById('bedInfoRow');
            if (infoRow) infoRow.classList.remove('d-none');
            setText('bedInfo', f.beds_info);
        }

        // Status about
        if (f.status_about) {
            var aboutRow = document.getElementById('statusAboutRow');
            if (aboutRow) aboutRow.classList.remove('d-none');
            setText('statusAbout', f.status_about);
        }
    }

    // ── Render Notes (GH #75) ──
    function renderNotes(notes) {
        notes = notes || [];
        var countEl = document.getElementById('noteCount');
        if (countEl) countEl.textContent = notes.length;
        var body = document.getElementById('notesBody');
        var noNotes = document.getElementById('noNotes');
        if (!body) return;
        if (notes.length === 0) {
            body.innerHTML = '';
            if (noNotes) noNotes.classList.remove('d-none');
            return;
        }
        if (noNotes) noNotes.classList.add('d-none');
        var html = '';
        for (var i = 0; i < notes.length; i++) {
            var n = notes[i];
            var meta = [];
            if (n.category && n.category !== 'general') { meta.push(escHtml(n.category)); }
            if (n.username) { meta.push(escHtml(n.username)); }
            if (n.created_at) { meta.push(escHtml(n.created_at)); }
            html += '<li class="list-group-item py-2">'
                  + '<div>' + escHtml(n.note || '') + '</div>'
                  + (n.detail ? '<div class="text-body-secondary">' + escHtml(n.detail) + '</div>' : '')
                  + '<div class="text-body-secondary" style="font-size:0.75rem">' + meta.join(' &middot; ') + '</div>'
                  + '</li>';
        }
        body.innerHTML = html;
    }

    // ── Render Incidents ──
    function renderIncidents(incidents) {
        var countEl = document.getElementById('incidentCount');
        if (countEl) countEl.textContent = incidents.length;

        var tbody = document.getElementById('incidentsBody');
        var noInc = document.getElementById('noIncidents');

        if (!incidents || incidents.length === 0) {
            if (tbody) tbody.innerHTML = '';
            if (noInc) noInc.classList.remove('d-none');
            return;
        }
        if (noInc) noInc.classList.add('d-none');

        var statusColors = { 1: 'secondary', 2: 'success', 3: 'info' };
        var html = '';
        incidents.forEach(function (inc) {
            var statusClass = statusColors[inc.status] || 'secondary';
            html += '<tr style="cursor:pointer;" onclick="window.location.href=\'incident-detail.php?id=' + inc.ticket_id + '\'">' +
                '<td class="ps-3">#' + inc.ticket_id + '</td>' +
                '<td>' + escHtml(inc.scope) + '</td>' +
                '<td>' + escHtml(inc.type_name || '--') + '</td>' +
                '<td><span class="badge bg-' + (inc.role === 'origin' ? 'primary' : 'warning') + ' bg-opacity-75">' +
                    escHtml(inc.role) + '</span></td>' +
                '<td><span class="badge bg-' + statusClass + '">' + escHtml(inc.status_text) + '</span></td>' +
                '<td class="small text-body-secondary">' + formatDate(inc.created) + '</td>' +
                '</tr>';
        });
        if (tbody) tbody.innerHTML = html;
    }

    // ── Render Stats ──
    function renderStats(stats) {
        setText('statTotal', stats.total_transports || 0);
        setText('statMonth', stats.transports_this_month || 0);
    }

    // ── Init Map ──
    function initMap(f) {
        var lat = f.lat || 39.8283;
        var lng = f.lng || -98.5795;
        var zoom = f.lat ? 15 : 5;

        map = L.map('facilityMap', { zoomControl: true }).setView([lat, lng], zoom);

        var baseLayer = null;
        if (window.MapPrefs) {
            baseLayer = window.MapPrefs.addDefaultBasemap(map);
            window.MapPrefs.addLayerControl(map, { currentBase: baseLayer });
        } else {
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
        }

        if (f.lat && f.lng) {
            marker = L.marker([f.lat, f.lng]).addTo(map);
            marker.bindPopup('<strong>' + escHtml(f.name) + '</strong>').openPopup();
        }

        setTimeout(function () { map.invalidateSize(); }, 200);
    }

    // ── Setup action links ──
    function setupActions(f) {
        var editBtn = document.getElementById('btnEdit');
        if (editBtn) {
            editBtn.href = 'facility-edit.php?id=' + f.id;
        }

        var newIncBtn = document.getElementById('btnNewIncident');
        if (newIncBtn) {
            newIncBtn.href = 'new-incident.php?facility=' + f.id;
        }
    }

    // ── Utilities ──
    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        var month = d.getMonth() + 1;
        var day = d.getDate();
        var year = d.getFullYear();
        var hours = d.getHours();
        var mins = d.getMinutes();
        return month + '/' + day + '/' + year + ' ' +
               (hours < 10 ? '0' : '') + hours + ':' +
               (mins < 10 ? '0' : '') + mins;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show small py-2" role="alert">' +
            message +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>' +
            '</div>';
    }

})();
