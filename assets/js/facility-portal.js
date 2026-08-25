/**
 * NewUI v4.0 — Facility Self-Service Portal (Phase 145, GH#90)
 *
 * ES5 IIFE, no build step — matches this codebase's JS convention.
 * Talks exclusively to api/facility-portal.php, which scopes every
 * query server-side to the session's own facility_id.
 */
(function () {
    'use strict';

    var csrfToken = '';
    var statusesById = {};
    var categoriesById = {};

    function getCsrfToken() {
        if (csrfToken) return csrfToken;
        var meta = document.querySelector('meta[name="csrf-token"]');
        csrfToken = meta ? meta.getAttribute('content') : '';
        return csrfToken;
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtTime(iso) {
        if (!iso) return null;
        var d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) return null;
        var hh = d.getHours();
        var mm = d.getMinutes();
        return (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
    }

    function apiGet(action) {
        var url = 'api/facility-portal.php';
        if (action) url += '?action=' + encodeURIComponent(action);
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('Request failed (' + r.status + ')');
            return r.json();
        });
    }

    function apiPost(payload) {
        payload.csrf_token = getCsrfToken();
        return fetch('api/facility-portal.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok || d.error) throw new Error(d.error || ('Request failed (' + r.status + ')'));
                return d;
            });
        });
    }

    // ── Incidents ────────────────────────────────────────────────
    function renderIncidents(data) {
        var facNameEl = document.getElementById('fpFacilityName');
        if (facNameEl && data.facility) {
            facNameEl.textContent = data.facility.name || ('Facility #' + data.facility.id);
        }

        var list = document.getElementById('fpIncidentList');
        var empty = document.getElementById('fpIncidentEmpty');
        var countEl = document.getElementById('fpIncidentCount');
        var incidents = data.incidents || [];
        countEl.textContent = String(incidents.length);

        if (incidents.length === 0) {
            list.innerHTML = '';
            empty.classList.remove('d-none');
            return;
        }
        empty.classList.add('d-none');

        var legLabels = { origin: 'At This Facility', receiving: 'Inbound', inbound: 'Inbound' };
        var html = '';
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];
            var legBadges = '';
            for (var j = 0; j < (inc.legs || []).length; j++) {
                legBadges += '<span class="badge bg-secondary fp-leg-badge me-1">' +
                    esc(legLabels[inc.legs[j]] || inc.legs[j]) + '</span>';
            }
            var addr = [inc.street, inc.city, inc.state].filter(function (v) { return v; }).join(', ');
            var unitsHtml = '';
            for (var k = 0; k < (inc.units || []).length; k++) {
                var u = inc.units[k];
                var enRoute = fmtTime(u.en_route_at);
                var arrived = fmtTime(u.arrived_at);
                // GH#99: a unit's facility-leg clock is qualified with its
                // actual destination when that isn't this facility, so
                // "arrived 14:32" never misreads as "arrived here" for a
                // unit that transported somewhere else.
                var destName = u.destination_elsewhere ? (u.destination_name || 'another facility') : '';
                var timing = '';
                if (arrived) {
                    timing = destName ? ('transported to ' + destName + ', ' + arrived) : ('arrived ' + arrived);
                } else if (enRoute) {
                    timing = destName ? ('en route to ' + destName + ' since ' + enRoute) : ('en route since ' + enRoute);
                }
                unitsHtml += '<div class="fp-unit-row">' +
                    '<span class="fp-unit-status-dot" style="background-color:' + esc(u.bg_color || '#999') + '"></span>' +
                    esc(u.handle || u.responder_name || 'Unit') +
                    (u.status_val ? ' &mdash; ' + esc(u.status_val) : '') +
                    (timing ? ' <span class="text-body-secondary">(' + esc(timing) + ')</span>' : '') +
                    '</div>';
            }

            html += '<div class="list-group-item fp-incident-item" style="border-left-color:' + esc(inc.severity_color || '#ccc') + '">' +
                '<div class="d-flex justify-content-between align-items-start">' +
                '  <div>' +
                '    <span class="fp-severity-dot" style="background-color:' + esc(inc.severity_color || '#ccc') + '"></span>' +
                '    <strong>' + esc(inc.type_name || 'Incident') + '</strong>' +
                (inc.incident_number ? ' <span class="text-body-secondary small">#' + esc(inc.incident_number) + '</span>' : '') +
                '    ' + legBadges +
                '  </div>' +
                '  <span class="badge bg-light text-dark">' + esc(inc.severity_label || '') + '</span>' +
                '</div>' +
                (addr ? '<div class="small text-body-secondary mt-1"><i class="bi bi-geo-alt"></i> ' + esc(addr) + '</div>' : '') +
                (inc.scope ? '<div class="small mt-1">' + esc(inc.scope) + '</div>' : '') +
                (inc.patient_count > 0 ? '<div class="small mt-1"><i class="bi bi-bandaid"></i> ' + inc.patient_count + ' patient' + (inc.patient_count === 1 ? '' : 's') + '</div>' : '') +
                (unitsHtml ? '<div class="mt-2">' + unitsHtml + '</div>' : '') +
                '</div>';
        }
        list.innerHTML = html;
    }

    function loadIncidents() {
        apiGet('incidents').then(renderIncidents).catch(function (err) {
            console.error('[facility-portal] incidents load failed', err);
        });
    }

    // ── Status / capacity self-report ───────────────────────────────
    function renderStatus(data) {
        var facNameEl = document.getElementById('fpFacilityName');
        if (facNameEl && data.facility) {
            facNameEl.textContent = data.facility.name || ('Facility #' + data.facility.id);
        }

        statusesById = {};
        var statusSel = document.getElementById('fpStatusSelect');
        var statusHtml = '';
        for (var i = 0; i < (data.statuses || []).length; i++) {
            var s = data.statuses[i];
            statusesById[s.id] = s;
            statusHtml += '<option value="' + s.id + '">' + esc(s.name) + '</option>';
        }
        statusSel.innerHTML = statusHtml;
        if (data.facility && data.facility.status_id) {
            statusSel.value = String(data.facility.status_id);
        }
        document.getElementById('fpStatusAbout').value = (data.facility && data.facility.status_about) || '';

        // GH#102 — beds_a/beds_o are the automation's own number (see
        // inc/bed_auto.php); already returned by this same API call but
        // never rendered before this fix.
        var bedsAvailEl = document.getElementById('fpBedsAvailable');
        var bedsOccEl = document.getElementById('fpBedsOccupied');
        if (bedsAvailEl && bedsOccEl) {
            var ba = (data.facility && data.facility.beds_a !== '' && data.facility.beds_a != null) ? data.facility.beds_a : '–';
            var bo = (data.facility && data.facility.beds_o !== '' && data.facility.beds_o != null) ? data.facility.beds_o : '–';
            bedsAvailEl.textContent = ba;
            bedsOccEl.textContent = bo;
        }

        categoriesById = {};
        for (var c = 0; c < (data.categories || []).length; c++) {
            categoriesById[data.categories[c].id] = data.categories[c];
        }
        var byCategory = {};
        for (var r = 0; r < (data.capacity || []).length; r++) {
            byCategory[data.capacity[r].category_id] = data.capacity[r];
        }

        var body = document.getElementById('fpCapacityBody');
        var html = '';
        for (var catId in categoriesById) {
            if (!categoriesById.hasOwnProperty(catId)) continue;
            var cat = categoriesById[catId];
            var row = byCategory[catId] || { total: 0, available: 0 };
            html += '<tr data-cat="' + catId + '">' +
                '<td>' + esc(cat.name) + '</td>' +
                '<td><input type="number" min="0" class="form-control form-control-sm fp-cap-total" value="' + (parseInt(row.total, 10) || 0) + '"></td>' +
                '<td><input type="number" min="0" class="form-control form-control-sm fp-cap-avail" value="' + (parseInt(row.available, 10) || 0) + '"></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-primary fp-cap-save"><i class="bi bi-check"></i></button></td>' +
                '</tr>';
        }
        body.innerHTML = html;
    }

    function loadStatus() {
        apiGet('status').then(renderStatus).catch(function (err) {
            console.error('[facility-portal] status load failed', err);
        });
    }

    function saveStatus() {
        var statusId = parseInt(document.getElementById('fpStatusSelect').value || '0', 10);
        var about = document.getElementById('fpStatusAbout').value || '';
        if (!statusId) return;
        var btn = document.getElementById('fpSaveStatus');
        btn.disabled = true;
        apiPost({ action: 'set_status', status_id: statusId, status_about: about })
            .then(function () {
                btn.disabled = false;
            })
            .catch(function (err) {
                btn.disabled = false;
                alert(err.message || 'Failed to update status');
            });
    }

    // GH#102 — the facility's own inverse of inc/bed_auto.php's automatic
    // decrement. See inc/facility-bed-release.php's docblock for the full
    // design rationale (targets beds_a/beds_o directly, floor-at-occupied
    // is the safety ceiling, coarse "release N" rather than a per-delivery
    // undo).
    function releaseBed() {
        var countEl = document.getElementById('fpReleaseCount');
        var noteEl = document.getElementById('fpReleaseNote');
        var hintEl = document.getElementById('fpReleaseHint');
        var btn = document.getElementById('fpReleaseBed');
        var count = parseInt(countEl.value || '1', 10);
        if (!count || count < 1) count = 1;

        btn.disabled = true;
        hintEl.textContent = '';
        apiPost({ action: 'release_bed', count: count, note: noteEl.value || '' })
            .then(function (resp) {
                btn.disabled = false;
                if (resp && resp.beds_a !== undefined) {
                    document.getElementById('fpBedsAvailable').textContent = resp.beds_a;
                    document.getElementById('fpBedsOccupied').textContent = resp.beds_o;
                }
                var releasedCount = resp && resp.released ? resp.released : count;
                hintEl.textContent = 'Released ' + releasedCount + ' bed' + (releasedCount === 1 ? '' : 's') + '.';
                hintEl.className = 'small text-success mt-1';
                noteEl.value = '';
            })
            .catch(function (err) {
                btn.disabled = false;
                hintEl.textContent = err.message || 'Failed to release a bed';
                hintEl.className = 'small text-danger mt-1';
            });
    }

    function saveCapacityRow(tr) {
        var catId = parseInt(tr.getAttribute('data-cat'), 10);
        var total = parseInt(tr.querySelector('.fp-cap-total').value || '0', 10);
        var available = parseInt(tr.querySelector('.fp-cap-avail').value || '0', 10);
        apiPost({ action: 'set_capacity', category_id: catId, total: total, available: available })
            .catch(function (err) {
                alert(err.message || 'Failed to update capacity');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadIncidents();
        loadStatus();

        document.getElementById('fpSaveStatus').addEventListener('click', saveStatus);
        var releaseBtn = document.getElementById('fpReleaseBed');
        if (releaseBtn) releaseBtn.addEventListener('click', releaseBed);
        document.getElementById('fpCapacityBody').addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.fp-cap-save') : null;
            if (!btn) return;
            saveCapacityRow(btn.closest('tr'));
        });

        // 25s poll for incidents (matches this app's other unattended
        // display boards). Status/capacity is deliberately NOT
        // auto-refreshed — this form is being actively edited by the
        // facility user, and clobbering an in-progress edit with a
        // background reload would be actively hostile to the one thing
        // this page exists for.
        setInterval(loadIncidents, 25000);
    });
})();
