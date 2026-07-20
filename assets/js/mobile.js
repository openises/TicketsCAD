/**
 * NewUI v4.0 - Mobile Unit Interface
 *
 * ES5 IIFE — touch-friendly field responder interface.
 * Handles: status updates, assignments, quick notes, mileage,
 * GPS location sharing, and SSE real-time notifications.
 */
(function () {
    'use strict';

    // ── State ───────────────────────────────────────────────────
    var csrf = '';
    var responderId = 0;
    var currentStatusId = 0;
    var activeMileageId = 0;
    var gpsWatchId = null;
    var gpsIntervalId = null;
    var lastGpsLat = null;
    var lastGpsLng = null;
    var sseSource = null;

    // ── DOM refs ────────────────────────────────────────────────
    var statusGrid = document.getElementById('statusGrid');
    var unitName = document.getElementById('unitName');
    var unitHandle = document.getElementById('unitHandle');
    var assignmentCard = document.getElementById('assignmentCard');
    var noAssignment = document.getElementById('noAssignment');
    var assignmentDetail = document.getElementById('assignmentDetail');
    var assignType = document.getElementById('assignType');
    var assignNature = document.getElementById('assignNature');
    var assignAddress = document.getElementById('assignAddress');
    var assignDesc = document.getElementById('assignDesc');
    var assignMapLink = document.getElementById('assignMapLink');
    var quickNoteInput = document.getElementById('quickNoteInput');
    var btnAddNote = document.getElementById('btnAddNote');
    var mileageStartForm = document.getElementById('mileageStartForm');
    var mileageActive = document.getElementById('mileageActive');
    var startOdoInput = document.getElementById('startOdoInput');
    var endOdoInput = document.getElementById('endOdoInput');
    var mileageStartTime = document.getElementById('mileageStartTime');
    var mileageStartOdo = document.getElementById('mileageStartOdo');
    var btnStartMileage = document.getElementById('btnStartMileage');
    var btnStopMileage = document.getElementById('btnStopMileage');
    var gpsToggle = document.getElementById('gpsToggle');
    var gpsStatus = document.getElementById('gpsStatus');
    var gpsCoords = document.getElementById('gpsCoords');
    var recentList = document.getElementById('recentList');
    var noRecent = document.getElementById('noRecent');
    var statusBadge = document.getElementById('mobileStatusBadge');
    var btnRefresh = document.getElementById('btnRefresh');
    var mobileClock = document.getElementById('mobileClock');

    // ── Helpers ─────────────────────────────────────────────────

    function getCsrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function showToast(msg, type) {
        var toast = document.getElementById('mobileToast');
        var body = document.getElementById('mobileToastBody');
        if (!toast || !body) return;
        body.textContent = msg;
        toast.className = 'toast align-items-center border-0';
        if (type) toast.classList.add('toast-' + type);
        var bsToast = new bootstrap.Toast(toast, { delay: 3000 });
        bsToast.show();
    }

    function apiPost(action, data, cb) {
        var payload = Object.assign({}, data || {});
        payload.action = action;
        payload.csrf_token = getCsrf();

        fetch('api/mobile-data.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.error) {
                showToast(json.error, 'error');
                if (cb) cb(null, json.error);
            } else {
                if (cb) cb(json);
            }
        })
        .catch(function (err) {
            showToast('Network error', 'error');
            if (cb) cb(null, err.message);
        });
    }

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function formatTime(dtStr) {
        if (!dtStr) return '--';
        var d = new Date(dtStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dtStr;
        var h = d.getHours();
        var m = d.getMinutes();
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    function formatTimeAgo(dtStr) {
        if (!dtStr) return '';
        var d = new Date(dtStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        var mins = Math.floor((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return mins + 'm ago';
        var hrs = Math.floor(mins / 60);
        if (hrs < 24) return hrs + 'h ago';
        var days = Math.floor(hrs / 24);
        return days + 'd ago';
    }

    // ── Clock ───────────────────────────────────────────────────

    function updateClock() {
        var now = new Date();
        var h = now.getHours();
        var m = now.getMinutes();
        if (mobileClock) {
            mobileClock.textContent = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
        }
    }
    updateClock();
    setInterval(updateClock, 15000);

    // ── Load Statuses ───────────────────────────────────────────

    function loadStatuses() {
        fetch('api/mobile-data.php?action=statuses', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.statuses || !data.statuses.length) {
                    statusGrid.innerHTML = '<div class="text-body-secondary text-center p-3">No statuses configured</div>';
                    return;
                }
                var html = '';
                for (var i = 0; i < data.statuses.length; i++) {
                    var s = data.statuses[i];
                    var color = s.color || '#6c757d';
                    var isActive = parseInt(s.id, 10) === currentStatusId;
                    html += '<button type="button" class="status-btn' + (isActive ? ' active' : '') + '"'
                        + ' data-status-id="' + s.id + '"'
                        + ' data-dispatch="' + (s.dispatch || '0') + '"'
                        + ' style="--status-color:' + escHtml(color) + '">'
                        + '<span class="status-dot" style="background:' + escHtml(color) + '"></span>'
                        + escHtml(s.status_val || s.description)
                        + '</button>';
                }
                statusGrid.innerHTML = html;

                // Phase 95: cache the statuses for later extra_data lookup
                allMobileStatuses = data.statuses;

                // Bind click handlers
                var btns = statusGrid.querySelectorAll('.status-btn');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].addEventListener('click', onStatusClick);
                }
            })
            .catch(function () {
                statusGrid.innerHTML = '<div class="text-danger text-center p-3">Failed to load statuses</div>';
            });
    }

    // Phase 95 — keep the loaded status list around so we can look up
    // a status's extra_data_* config when the user picks it.
    var allMobileStatuses = [];

    function findMobileStatus(statusId) {
        for (var i = 0; i < allMobileStatuses.length; i++) {
            if (parseInt(allMobileStatuses[i].id, 10) === parseInt(statusId, 10)) {
                return allMobileStatuses[i];
            }
        }
        return null;
    }

    // Phase 95 — prompt for extra_data. Touch-friendly modal (Bootstrap
    // is loaded on mobile.php for the other modals already in this UI).
    function ensureMobileExtraDataModal() {
        if (document.getElementById('mobileExtraDataModal')) return;
        var html =
            '<div class="modal fade" id="mobileExtraDataModal" tabindex="-1" aria-hidden="true">' +
              '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header py-2">' +
                  '<h6 class="modal-title" id="mobileExtraDataLabel">Additional info</h6>' +
                  '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                '</div>' +
                '<div class="modal-body py-3"><div id="mobileExtraDataBody"></div></div>' +
                '<div class="modal-footer py-2">' +
                  '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                  '<button type="button" class="btn btn-primary" id="mobileExtraDataConfirm">Confirm</button>' +
                '</div>' +
              '</div></div>' +
            '</div>';
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        document.body.appendChild(wrap.firstChild);
    }

    function promptMobileExtraData(status) {
        return new Promise(function (resolve) {
            ensureMobileExtraDataModal();
            var type  = status.extra_data_type;
            var label = status.extra_data_label
                        || ({facility:'Destination facility', mileage:'Mileage',
                             location:'Location', note:'Note', numeric:'Value'})[type]
                        || 'Additional info';
            var req   = parseInt(status.extra_data_required || 0, 10) === 1;

            document.getElementById('mobileExtraDataLabel').textContent =
                status.status_val + (req ? ' — ' + label + ' required' : '');
            var body = document.getElementById('mobileExtraDataBody');
            body.innerHTML = '<label class="form-label mb-1">' + escHtml(label) + (req ? ' *' : ' (optional)') + '</label>';

            var input;
            if (type === 'facility') {
                input = document.createElement('select');
                input.className = 'form-select form-select-lg';
                input.innerHTML = '<option value="">— select —</option>';
                fetch('api/facilities.php', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var rows = data.facilities || data.rows || [];
                        for (var i = 0; i < rows.length; i++) {
                            var o = document.createElement('option');
                            o.value = rows[i].id;
                            o.textContent = rows[i].name;
                            input.appendChild(o);
                        }
                    })
                    .catch(function () {});
            } else if (type === 'mileage' || type === 'numeric') {
                input = document.createElement('input');
                input.type = 'number';
                input.className = 'form-control form-control-lg';
                input.inputMode = 'numeric';
                input.placeholder = type === 'mileage' ? 'Odometer reading' : 'Value';
            } else if (type === 'note') {
                input = document.createElement('textarea');
                input.className = 'form-control form-control-lg';
                input.rows = 3;
                input.placeholder = 'Enter a note';
            } else if (type === 'location') {
                input = document.createElement('div');
                input.innerHTML =
                    '<div class="d-flex gap-1 mb-2">' +
                      '<input type="number" step="any" class="form-control" placeholder="lat" id="mExtraLat">' +
                      '<input type="number" step="any" class="form-control" placeholder="lng" id="mExtraLng">' +
                    '</div>' +
                    '<button type="button" class="btn btn-outline-primary w-100" id="mExtraUseGps">Use my GPS</button>';
            } else {
                input = document.createElement('input');
                input.className = 'form-control form-control-lg';
            }
            body.appendChild(input);

            if (type === 'location') {
                var btnGps = document.getElementById('mExtraUseGps') || body.querySelector('#mExtraUseGps');
                if (btnGps) {
                    btnGps.addEventListener('click', function () {
                        if (!navigator.geolocation) return;
                        btnGps.disabled = true;
                        navigator.geolocation.getCurrentPosition(function (pos) {
                            body.querySelector('#mExtraLat').value = pos.coords.latitude.toFixed(6);
                            body.querySelector('#mExtraLng').value = pos.coords.longitude.toFixed(6);
                            btnGps.disabled = false;
                        }, function () { btnGps.disabled = false; });
                    });
                }
            }

            var modalEl = document.getElementById('mobileExtraDataModal');
            var modal = new bootstrap.Modal(modalEl);
            var resolved = false;
            function cleanup() {
                modalEl.removeEventListener('hidden.bs.modal', onDismiss);
                btnConfirm.removeEventListener('click', onConfirm);
            }
            function onDismiss() {
                if (resolved) return;
                resolved = true;
                cleanup();
                resolve(null);
            }
            function onConfirm() {
                var value;
                if (type === 'facility') {
                    value = input.value === '' ? null : parseInt(input.value, 10);
                } else if (type === 'mileage' || type === 'numeric') {
                    value = input.value === '' ? null : parseFloat(input.value);
                } else if (type === 'note') {
                    value = input.value.trim();
                } else if (type === 'location') {
                    var lat = parseFloat(body.querySelector('#mExtraLat').value);
                    var lng = parseFloat(body.querySelector('#mExtraLng').value);
                    value = (!isNaN(lat) && !isNaN(lng)) ? [lat, lng] : null;
                }
                if (req && (value === null || value === '' || (Array.isArray(value) && value.length === 0))) {
                    alert(label + ' is required.');
                    return;
                }
                resolved = true;
                cleanup();
                modal.hide();
                resolve({ type: type, value: value });
            }
            var btnConfirm = document.getElementById('mobileExtraDataConfirm');
            btnConfirm.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onDismiss);
            modal.show();
        });
    }

    // Issue #10 regression (a beta tester 2026-07-03): bindings at line 170
    // did `addEventListener('click', onStatusClick)`, which meant the
    // click Event object was passed in as `extraData` — Event is
    // truthy, so the `!extraData` guards below all short-circuited,
    // the extra-data prompt never opened, and the request was fired
    // with a bogus body.extra_data = <Event>. Split the click entry
    // point (never has a payload) from the recursive dispatcher
    // (always does) so the argument shape is unambiguous.
    function onStatusClick() {
        _dispatchStatusChange.call(this, null);
    }

    function _dispatchStatusChange(extraData) {
        if (!responderId) {
            showToast('No unit linked to your account', 'error');
            return;
        }
        var statusId = parseInt(this.getAttribute('data-status-id'), 10);
        if (statusId === currentStatusId) return;

        // Phase 95: if the status has extra_data, prompt first.
        var status = findMobileStatus(statusId);
        if (status && status.extra_data_type && status.extra_data_type !== 'none' && !extraData) {
            var btn = this;
            promptMobileExtraData(status).then(function (ed) {
                if (ed === null) return;
                _dispatchStatusChange.call(btn, ed);
            });
            return;
        }

        var btn = this;
        btn.disabled = true;

        var body = {
            responder_id: responderId,
            status_id: statusId,
            status_about: '',
            csrf_token: getCsrf()
        };
        if (extraData) body.extra_data = extraData;

        fetch('api/responder-status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) {
            return r.json().then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
            var data = res.data;
            btn.disabled = false;
            // Phase 104f (a beta tester GH #10) — server rejected because the
            // status needs extra_data and we didn't send any (either
            // the status cache was stale, or the check at line 338
            // missed). Open the inline prompt and retry with the
            // collected value.
            //
            // Issue #10 followup (a beta tester 2026-07-02): the previous check
            // scanned data.error for the string 'extra_data_required',
            // but the server was only returning the human-readable
            // "Extra data required for this status: destination" with
            // no machine code. The check missed and the prompt never
            // opened. api/responder-status.php now sends an explicit
            // { code: 'extra_data_required', label: ..., error: ... }
            // payload; key on data.code first, fall back to the old
            // substring scan for deployments that haven't updated the
            // API yet (belt + suspenders during rollout).
            var wantsExtra = !!(data && (
                data.code === 'extra_data_required' ||
                data.error === 'extra_data_required' ||
                (data.error && String(data.error).indexOf('extra_data_required') !== -1) ||
                (data.error && /extra data required/i.test(String(data.error)))
            ));
            if (wantsExtra && !extraData) {
                var status = findMobileStatus(statusId);
                if (!status) {
                    // Best-effort — synth a shape promptMobileExtraData
                    // can render from server-provided label + type.
                    status = {
                        id: statusId,
                        status_val: (data.label || 'this status'),
                        extra_data_type: (data.extra_data_type || 'note'),
                        extra_data_label: (data.label || ''),
                        extra_data_required: 1
                    };
                }
                promptMobileExtraData(status).then(function (ed) {
                    if (ed === null) return;
                    _dispatchStatusChange.call(btn, ed);
                });
                return;
            }
            if (data && data.error) {
                showToast(data.error, 'error');
            } else {
                currentStatusId = statusId;
                showToast('Status: ' + ((data && data.status_name) || 'Updated'), 'success');
                highlightActiveStatus();
                updateStatusBadge(data && data.status_name, btn.style.getPropertyValue('--status-color'));
            }
        })
        .catch(function () {
            btn.disabled = false;
            showToast('Failed to update status', 'error');
        });
    }

    function highlightActiveStatus() {
        var btns = statusGrid.querySelectorAll('.status-btn');
        for (var i = 0; i < btns.length; i++) {
            var id = parseInt(btns[i].getAttribute('data-status-id'), 10);
            if (id === currentStatusId) {
                btns[i].classList.add('active');
            } else {
                btns[i].classList.remove('active');
            }
        }
    }

    function updateStatusBadge(name, color) {
        if (!statusBadge) return;
        statusBadge.textContent = name || '--';
        statusBadge.style.backgroundColor = color || '#6c757d';
        statusBadge.style.color = '#fff';
    }

    // ── Load Dashboard Data ─────────────────────────────────────

    function loadDashboard() {
        if (btnRefresh) btnRefresh.classList.add('spinning');

        fetch('api/mobile-data.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btnRefresh) btnRefresh.classList.remove('spinning');
                if (data.error) {
                    showToast(data.error, 'error');
                    return;
                }

                // Update CSRF
                if (data.csrf_token) {
                    csrf = data.csrf_token;
                    var el = document.getElementById('csrfToken');
                    if (el) el.value = csrf;
                }

                responderId = data.responder_id || 0;

                // Unit info
                if (data.responder) {
                    unitName.textContent = data.responder.name || data.responder.handle || 'Unit';
                    if (unitHandle) unitHandle.textContent = data.responder.handle || data.user || '';
                } else {
                    unitName.textContent = 'No unit linked';
                    unitName.classList.add('text-warning');
                }

                // Current status
                if (data.current_status) {
                    currentStatusId = parseInt(data.current_status.id, 10);
                    updateStatusBadge(data.current_status.status_val, data.current_status.color);
                }

                // Assignment
                renderAssignment(data.assignment);

                // Recent assignments
                renderRecent(data.recent_assignments || []);

                // Mileage
                renderMileage(data.active_mileage);

                // Reload statuses to highlight correct one
                loadStatuses();

                // Phase 69: now that responderId is known, fire the
                // deferred GPS auto-start. Safe to call repeatedly —
                // the helper no-ops once consumed.
                tickGpsAutostart();
            })
            .catch(function () {
                if (btnRefresh) btnRefresh.classList.remove('spinning');
                showToast('Failed to load data', 'error');
            });
    }

    function renderAssignment(assign) {
        if (!assign) {
            noAssignment.classList.remove('d-none');
            assignmentDetail.classList.add('d-none');
            return;
        }
        noAssignment.classList.add('d-none');
        assignmentDetail.classList.remove('d-none');

        assignType.textContent = assign.incident_type || 'Incident';
        assignType.style.backgroundColor = assign.type_color || '#0d6efd';
        assignType.style.color = '#fff';

        assignNature.textContent = assign.nature || assign.description || '--';

        var addr = assign.address || '';
        if (assign.apt) addr += ' #' + assign.apt;
        if (assign.city) addr += ', ' + assign.city;
        if (assign.state) addr += ', ' + assign.state;
        assignAddress.textContent = addr || 'No address';

        assignDesc.textContent = assign.description || '';

        // Phase 71 — big "Navigate to Scene" button using
        // NavigateLauncher (Apple Maps / Google Maps / Waze chooser
        // with localStorage memory). Falls back to the old icon-only
        // browser link if launcher isn't loaded.
        var navBtn = document.getElementById('assignNavBtn');
        if (assign.lat && assign.lon && navBtn && typeof NavigateLauncher !== 'undefined') {
            navBtn.classList.remove('d-none');
            assignMapLink.classList.add('d-none');
            // Re-attach on every dashboard refresh so the coords are
            // current. attach() is idempotent for the same element.
            NavigateLauncher.attach(navBtn, {
                lat: parseFloat(assign.lat),
                lng: parseFloat(assign.lon),
                address: addr || null
            });
        } else if (assign.lat && assign.lon) {
            // Launcher script missing — fall back to old behavior.
            var isIos = /iPhone|iPad|iPod/i.test(navigator.userAgent);
            assignMapLink.href = isIos
                ? 'maps://?daddr=' + assign.lat + ',' + assign.lon
                : 'https://www.google.com/maps/dir/?api=1&destination=' + assign.lat + ',' + assign.lon;
            assignMapLink.classList.remove('d-none');
            if (navBtn) navBtn.classList.add('d-none');
        } else if (assign.address) {
            var q = encodeURIComponent(addr);
            assignMapLink.href = 'https://www.google.com/maps/search/?api=1&query=' + q;
            assignMapLink.classList.remove('d-none');
            if (navBtn) navBtn.classList.add('d-none');
        } else {
            assignMapLink.classList.add('d-none');
            if (navBtn) navBtn.classList.add('d-none');
        }

        // Store ticket ID for quick note
        btnAddNote.setAttribute('data-ticket-id', assign.ticket_id || '');

        // Phase 104i (a beta tester GH #14) — refresh the optional detail
        // sections whenever the assignment reloads. The user's
        // localStorage prefs drive which sections actually render.
        try { mobileDetailRefresh(assign.ticket_id || null); } catch (e) {}
    }

    // Phase 104i (a beta tester GH #14) — partial parity for mobile.
    // Optional sections drawn from api/incident-detail.php:
    // facilities, patients, call history, action log. User picks
    // via checkboxes; state lives in localStorage.
    function mobileDetailInit() {
        var togglesBtn = document.getElementById('mobileDetailTogglesBtn');
        var togglesBox = document.getElementById('mobileDetailToggles');
        if (!togglesBtn || !togglesBox) return;
        togglesBtn.addEventListener('click', function () {
            togglesBox.classList.toggle('d-none');
        });
        // Issue #14 followup (a beta tester, 2026-07-02): "Notes" is a
        // separate toggle from "Action log". Notes filters to
        // action_type=0 (user-authored notes/comments only).
        // Action log is the full chronology (all action_type values,
        // including status changes and system events).
        var keys = ['Facilities', 'Patients', 'CallHistory', 'Actions', 'Notes'];
        keys.forEach(function (k) {
            var cb = document.getElementById('mobileShow' + k);
            if (!cb) return;
            var stored = false;
            try { stored = localStorage.getItem('mob_detail_' + k) === '1'; } catch (e) {}
            cb.checked = stored;
            cb.addEventListener('change', function () {
                try { localStorage.setItem('mob_detail_' + k, cb.checked ? '1' : '0'); } catch (e) {}
                mobileDetailRefresh(btnAddNote.getAttribute('data-ticket-id'));
            });
        });
    }
    function mobileDetailPref(k) {
        var cb = document.getElementById('mobileShow' + k);
        return !!(cb && cb.checked);
    }
    function mobileDetailRefresh(ticketId) {
        var facEl   = document.getElementById('mobileDetailFacilities');
        var patEl   = document.getElementById('mobileDetailPatients');
        var chEl    = document.getElementById('mobileDetailCallHist');
        var actEl   = document.getElementById('mobileDetailActions');
        var notesEl = document.getElementById('mobileDetailNotes');
        [facEl, patEl, chEl, actEl, notesEl].forEach(function (el) {
            if (el) { el.classList.add('d-none'); el.innerHTML = ''; }
        });
        if (!ticketId) return;
        var anyOn = mobileDetailPref('Facilities') || mobileDetailPref('Patients')
                 || mobileDetailPref('CallHistory') || mobileDetailPref('Actions')
                 || mobileDetailPref('Notes');
        if (!anyOn) return;
        fetch('api/incident-detail.php?id=' + encodeURIComponent(ticketId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                function escH(s) {
                    return String(s == null ? '' : s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }
                // Issue #14 followup (a beta tester, 2026-07-02) — compact
                // HH:MM MM/DD timestamp for on-scene readability.
                // Used by Call history + Action log + Notes sections.
                function fmtActionTs(iso) {
                    if (!iso) return '—';
                    var m = String(iso).match(
                        /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
                    if (!m) return escH(iso);
                    return m[4] + ':' + m[5] + ' ' + m[2] + '/' + m[3];
                }
                if (mobileDetailPref('Facilities') && facEl) {
                    var facs = [];
                    if (data.incident) {
                        if (data.incident.facility_name)  facs.push('Origin: ' + data.incident.facility_name);
                        if (data.incident.rec_facility_name) facs.push('Receiving: ' + data.incident.rec_facility_name);
                    }
                    facEl.innerHTML = '<div class="small fw-semibold mb-1"><i class="bi bi-hospital me-1"></i>Facilities</div>'
                        + (facs.length ? '<ul class="small mb-0">' + facs.map(function (f) { return '<li>' + escH(f) + '</li>'; }).join('') + '</ul>'
                                       : '<div class="text-body-secondary small">None</div>');
                    facEl.classList.remove('d-none');
                }
                if (mobileDetailPref('Patients') && patEl) {
                    var pats = (data.patients || []);
                    patEl.innerHTML = '<div class="small fw-semibold mb-1"><i class="bi bi-person-plus me-1"></i>Patients (' + pats.length + ')</div>'
                        + (pats.length
                            ? '<ul class="small mb-0">' + pats.slice(0, 10).map(function (p) {
                                var name = p.fullname || p.name || 'Patient';
                                var desc = p.description || '';
                                return '<li>' + escH(name) + (desc ? ' — ' + escH(desc) : '') + '</li>';
                            }).join('') + '</ul>'
                            : '<div class="text-body-secondary small">None</div>');
                    patEl.classList.remove('d-none');
                }
                if (mobileDetailPref('CallHistory') && chEl) {
                    // Issue #14 re-reopen (a beta tester 2026-07-04): this
                    // section read data.call_history, a field
                    // api/incident-detail.php has NEVER returned — so
                    // it rendered "None" forever. Fetch from
                    // api/call-history.php with the incident's phone +
                    // street, exactly like the CAD incident-detail
                    // page does. Excludes the current incident from
                    // its own history, same as desktop.
                    var incInfo = data.incident || {};
                    var chPhone = (incInfo.phone || '').trim();
                    var chStreet = (incInfo.street || '').trim();
                    var chHeader = '<div class="small fw-semibold mb-1"><i class="bi bi-telephone me-1"></i>Call history</div>';
                    if (!chPhone && !chStreet) {
                        chEl.innerHTML = chHeader
                            + '<div class="text-body-secondary small">No phone or address on this incident to search by</div>';
                        chEl.classList.remove('d-none');
                    } else {
                        var chParams = new URLSearchParams();
                        if (chPhone) chParams.set('phone', chPhone);
                        if (chStreet) chParams.set('street', chStreet);
                        fetch('api/call-history.php?' + chParams.toString(), { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (histData) {
                                var hist = (histData.results || []).filter(function (h) {
                                    return h.id !== incInfo.id;
                                }).slice(0, 5);
                                chEl.innerHTML = chHeader
                                    + (hist.length
                                        ? '<ul class="small mb-0">' + hist.map(function (h) {
                                            var ts = h.date || h.created_at || h.datetime;
                                            var tsHtml = ts ? '<span class="text-body-secondary me-1">'
                                                + fmtActionTs(ts) + '</span>' : '';
                                            return '<li>' + tsHtml
                                                + escH(h.scope || h.summary || h.description || '(no summary)') + '</li>';
                                        }).join('') + '</ul>'
                                        : '<div class="text-body-secondary small">No prior calls for this phone/address</div>');
                                chEl.classList.remove('d-none');
                            })
                            .catch(function () {
                                chEl.innerHTML = chHeader
                                    + '<div class="text-body-secondary small">Could not load call history</div>';
                                chEl.classList.remove('d-none');
                            });
                    }
                }
                // Issue #14 followup (a beta tester, 2026-07-02):
                //   * Each entry leads with a compact timestamp
                //     instead of an unlabeled bullet.
                //   * "Action log" = full chronology (all action_type).
                //   * "Notes"     = user-authored notes only
                //                    (action_type === 0, matching
                //                    incident_add_note_internal).
                function renderActionRow(a) {
                    var who = a.user_name ? ' <span class="text-body-secondary">'
                        + escH(a.user_name) + '</span>' : '';
                    return '<li><span class="text-body-secondary small me-1">'
                        + fmtActionTs(a.date) + '</span>'
                        + escH(a.description || '')
                        + who + '</li>';
                }
                if (mobileDetailPref('Actions') && actEl) {
                    var acts = (data.actions || []).slice(0, 12);
                    actEl.innerHTML = '<div class="small fw-semibold mb-1"><i class="bi bi-chat-left-text me-1"></i>Action log</div>'
                        + (acts.length
                            ? '<ul class="small mb-0 list-unstyled ps-0">'
                                + acts.map(renderActionRow).join('')
                                + '</ul>'
                            : '<div class="text-body-secondary small">None</div>');
                    actEl.classList.remove('d-none');
                }
                if (mobileDetailPref('Notes') && notesEl) {
                    // Issue #14 re-reopen (a beta tester 2026-07-04): notes
                    // added FROM MOBILE are written by api/mobile-data.php
                    // with action_type=11 (the legacy responder-note
                    // convention — 55 pre-existing rows use it). The
                    // filter below only accepted 0 (dispatcher notes via
                    // incident_add_note_internal), so a unit's own note
                    // never showed in its Notes section. Both are
                    // user-authored notes; accept both.
                    var notes = (data.actions || []).filter(function (a) {
                        var at = a.action_type | 0;
                        return at === 0 || at === 11;
                    }).slice(0, 12);
                    notesEl.innerHTML = '<div class="small fw-semibold mb-1"><i class="bi bi-sticky me-1"></i>Notes</div>'
                        + (notes.length
                            ? '<ul class="small mb-0 list-unstyled ps-0">'
                                + notes.map(renderActionRow).join('')
                                + '</ul>'
                            : '<div class="text-body-secondary small">None</div>');
                    notesEl.classList.remove('d-none');
                }
            })
            .catch(function () { /* silent */ });
    }
    // Wire on DOMContentLoaded — safe if elements aren't present.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mobileDetailInit);
    } else {
        mobileDetailInit();
    }

    function renderRecent(items) {
        if (!items || !items.length) {
            noRecent.classList.remove('d-none');
            recentList.innerHTML = '';
            return;
        }
        noRecent.classList.add('d-none');
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            html += '<div class="recent-item">'
                + '<div>'
                + '<div class="recent-type">' + escHtml(item.incident_type || 'Incident') + '</div>'
                + '<div class="recent-addr">' + escHtml(item.address || '') + (item.city ? ', ' + escHtml(item.city) : '') + '</div>'
                + '</div>'
                + '<div class="recent-time">' + formatTimeAgo(item.cleared_at) + '</div>'
                + '</div>';
        }
        recentList.innerHTML = html;
    }

    function renderMileage(active) {
        if (active) {
            activeMileageId = parseInt(active.id, 10);
            mileageStartForm.classList.add('d-none');
            mileageActive.classList.remove('d-none');
            mileageStartTime.textContent = formatTime(active.started_at);
            mileageStartOdo.textContent = active.start_odo || '--';

            // Restore from localStorage
            var savedOdo = null;
            try { savedOdo = localStorage.getItem('mobile_start_odo'); } catch (e) {}
            if (savedOdo && !active.start_odo) {
                mileageStartOdo.textContent = savedOdo;
            }
        } else {
            activeMileageId = 0;
            mileageStartForm.classList.remove('d-none');
            mileageActive.classList.add('d-none');

            // Restore last odo from localStorage
            try {
                var lastOdo = localStorage.getItem('mobile_last_odo');
                if (lastOdo && startOdoInput) startOdoInput.value = lastOdo;
            } catch (e) {}
        }
    }

    // ── Quick Note ──────────────────────────────────────────────

    btnAddNote.addEventListener('click', function () {
        var ticketId = parseInt(btnAddNote.getAttribute('data-ticket-id'), 10);
        var note = quickNoteInput.value.trim();
        if (!ticketId) {
            showToast('No active assignment', 'error');
            return;
        }
        if (!note) {
            quickNoteInput.focus();
            return;
        }
        btnAddNote.disabled = true;
        apiPost('add_note', { ticket_id: ticketId, note: note }, function (data, err) {
            btnAddNote.disabled = false;
            if (data && data.success) {
                quickNoteInput.value = '';
                showToast('Note added', 'success');
            }
        });
    });

    // Submit note on Enter key
    quickNoteInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            btnAddNote.click();
        }
    });

    // ── Mileage ─────────────────────────────────────────────────

    btnStartMileage.addEventListener('click', function () {
        if (!responderId) {
            showToast('No unit linked', 'error');
            return;
        }
        var odo = startOdoInput.value ? parseFloat(startOdoInput.value) : null;
        btnStartMileage.disabled = true;

        // Save to localStorage
        try {
            if (odo) localStorage.setItem('mobile_start_odo', odo);
        } catch (e) {}

        apiPost('start_mileage', {
            responder_id: responderId,
            start_odo: odo,
            ticket_id: btnAddNote.getAttribute('data-ticket-id') || null
        }, function (data, err) {
            btnStartMileage.disabled = false;
            if (data && data.success) {
                activeMileageId = data.mileage_id || 0;
                showToast('Mileage trip started', 'success');
                loadDashboard();
            }
        });
    });

    btnStopMileage.addEventListener('click', function () {
        if (!activeMileageId) {
            showToast('No active trip', 'error');
            return;
        }
        var odo = endOdoInput.value ? parseFloat(endOdoInput.value) : null;
        btnStopMileage.disabled = true;

        // Save last odo for next trip pre-fill
        try {
            if (odo) localStorage.setItem('mobile_last_odo', String(odo));
        } catch (e) {}

        apiPost('stop_mileage', {
            mileage_id: activeMileageId,
            end_odo: odo,
            notes: ''
        }, function (data, err) {
            btnStopMileage.disabled = false;
            if (data && data.success) {
                activeMileageId = 0;
                showToast('Trip ended', 'success');
                loadDashboard();
            }
        });
    });

    // ── GPS Location Sharing ────────────────────────────────────

    gpsToggle.addEventListener('change', function () {
        if (this.checked) {
            startGps();
        } else {
            stopGps();
        }
    });

    // Auto-start GPS on mobile login (default: on unless explicitly disabled).
    // Phase 69: do NOT call startGps() inline — that races loadDashboard()
    // and fires "No unit linked" on every page refresh because
    // responderId is still 0 at script-eval time. Defer the auto-start
    // until loadDashboard sets responderId; tickGpsAutostart() is
    // invoked from there.
    var _gpsAutostartPending = false;
    try {
        var gpsPref = localStorage.getItem('mobile_gps_on');
        if (gpsPref !== '0') {
            _gpsAutostartPending = true;
            // Reflect the intended state in the toggle right away so the
            // checkbox doesn't visibly flip on after the data loads.
            gpsToggle.checked = true;
        }
    } catch (e) {}

    function tickGpsAutostart() {
        if (!_gpsAutostartPending) return;
        if (!responderId) return;
        _gpsAutostartPending = false;
        startGps();
    }

    function startGps() {
        if (!navigator.geolocation) {
            showToast('Geolocation not supported', 'error');
            gpsToggle.checked = false;
            return;
        }
        if (!responderId) {
            showToast('No unit linked', 'error');
            gpsToggle.checked = false;
            return;
        }

        gpsStatus.textContent = 'Acquiring...';

        // Eric 2026-07-08 — honor the Internal GPS provider's configured
        // update_interval / high_accuracy (embedded by mobile.php as
        // window.MOBILE_GPS). Previously hardcoded 30 s / high accuracy,
        // which made the Settings values silently dead.
        var gpsCfg = window.MOBILE_GPS || {};
        var reportEveryMs = parseInt(gpsCfg.intervalMs, 10) || 30000;
        var highAccuracy = (gpsCfg.highAccuracy !== false);

        gpsWatchId = navigator.geolocation.watchPosition(
            function (pos) {
                lastGpsLat = pos.coords.latitude;
                lastGpsLng = pos.coords.longitude;
                var acc = pos.coords.accuracy ? Math.round(pos.coords.accuracy) : null;
                gpsStatus.textContent = 'Active';
                gpsCoords.classList.remove('d-none');
                gpsCoords.textContent = lastGpsLat.toFixed(5) + ', ' + lastGpsLng.toFixed(5)
                    + (acc ? ' (\u00B1' + acc + 'm)' : '');
            },
            function (err) {
                gpsStatus.textContent = 'Error: ' + err.message;
            },
            { enableHighAccuracy: highAccuracy, timeout: 15000, maximumAge: 10000 }
        );

        // Report location on the configured cadence (default 30 s)
        gpsIntervalId = setInterval(function () {
            if (lastGpsLat !== null && lastGpsLng !== null && responderId) {
                // Report to legacy responder table (backward compat)
                apiPost('report_location', {
                    responder_id: responderId,
                    lat: lastGpsLat,
                    lng: lastGpsLng,
                    accuracy: null
                });
                // Also report to new location_reports table for tracking overlay
                var csrfEl = document.querySelector('meta[name="csrf-token"]');
                var csrfTok = csrfEl ? csrfEl.content : '';
                fetch('api/location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'report',
                        provider_code: 'internal',
                        unit_identifier: 'unit-' + responderId,
                        lat: lastGpsLat,
                        lng: lastGpsLng,
                        accuracy: null,
                        battery: typeof navigator.getBattery === 'function' ? null : null,
                        csrf_token: csrfTok
                    })
                }).catch(function () {});
            }
        }, 30000);

        try { localStorage.setItem('mobile_gps_on', '1'); } catch (e) {}
    }

    function stopGps() {
        if (gpsWatchId !== null) {
            navigator.geolocation.clearWatch(gpsWatchId);
            gpsWatchId = null;
        }
        if (gpsIntervalId !== null) {
            clearInterval(gpsIntervalId);
            gpsIntervalId = null;
        }
        gpsStatus.textContent = 'Off';
        gpsCoords.classList.add('d-none');
        lastGpsLat = null;
        lastGpsLng = null;
        try { localStorage.setItem('mobile_gps_on', '0'); } catch (e) {}
    }

    // ── Refresh Button ──────────────────────────────────────────

    btnRefresh.addEventListener('click', function () {
        loadDashboard();
    });

    // ── SSE Real-Time Updates ───────────────────────────────────

    function connectSSE() {
        if (typeof EventSource === 'undefined') return;
        try {
            // #48 / #13 (a beta tester 2026-07-03): was 'api/sse.php', which
            // doesn't exist in this project — the endpoint is
            // api/stream.php (see EventBus on desktop). a beta tester's
            // Apache returned its 404 HTML page as the "SSE stream
            // body," EventSource silently errored, and mobile has
            // been permanently disconnected from real-time events —
            // which is exactly the bidirectional CAD↔mobile sync gap
            // a beta tester reported in #13. Both training and Bloomington
            // had the same silent failure but nobody noticed because
            // desktop dispatchers don't use mobile.js.
            sseSource = new EventSource('api/stream.php');
            sseSource.addEventListener('assignment', function (e) {
                // Reload when assignment changes
                loadDashboard();
            });
            sseSource.addEventListener('status_change', function (e) {
                loadDashboard();
            });
            sseSource.addEventListener('new_incident', function (e) {
                // Could show a notification
            });
            // Phase 104h (a beta tester GH #13) — dispatcher-side edits to the
            // incident the mobile unit is assigned to should push through
            // in real-time so field responders aren't looking at stale
            // notes / status / patients. incident:update fires on any
            // change to the ticket row; action:added fires on every new
            // action-log row (notes are the primary case). Both trigger
            // a full loadDashboard which repulls the current-assignment
            // panel.
            //
            // #13 followup (a beta tester 2026-07-02): loadDashboard() only
            // refreshed the dashboard cards, not the mobileDetailInit()-
            // populated Notes / Action Log / Facilities / Patients /
            // Call-History expandable sections on the assignment card.
            // Those stayed stale until a full page refresh — matching
            // a beta tester's "have to manually refresh" report. Chain
            // mobileDetailInit() alongside loadDashboard() on every SSE
            // event, so the detail sections re-fetch too. Guarded by
            // typeof so a page without mobile-detail markup is a no-op.
            function refreshMobileEverything() {
                loadDashboard();
                if (typeof mobileDetailInit === 'function') {
                    try { mobileDetailInit(); } catch (e) { /* non-fatal */ }
                }
            }
            sseSource.addEventListener('incident:update', function (e) { refreshMobileEverything(); });
            sseSource.addEventListener('incident:close',  function (e) { refreshMobileEverything(); });
            // Issue #13 (a beta tester 2026-07-05) — notes emit 'incident:note' and
            // status changes emit 'responder:status'; the old list only had
            // action:added/assign:update (which nothing publishes), so a
            // dispatcher's note/status change never reached the mobile view.
            sseSource.addEventListener('incident:note',   function (e) { refreshMobileEverything(); });
            sseSource.addEventListener('responder:status',function (e) { refreshMobileEverything(); });
            sseSource.addEventListener('action:added',    function (e) { refreshMobileEverything(); });
            sseSource.addEventListener('assign:update',   function (e) { refreshMobileEverything(); });
            sseSource.addEventListener('patient:add',     function (e) { refreshMobileEverything(); });
            sseSource.addEventListener('patient:update',  function (e) { refreshMobileEverything(); });
            sseSource.onerror = function () {
                // Reconnect after delay
                if (sseSource) sseSource.close();
                setTimeout(connectSSE, 10000);
            };
        } catch (e) {
            // SSE not available — fall back to polling
            setInterval(loadDashboard, 60000);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PAR BANNER (Phase 16c, 2026-06-11)
    //
    //  Non-modal banner at the top of mobile UI when a PAR check is
    //  pending for the user's assigned unit. Tap to expand into a
    //  comment + member-count form, then submit. The banner doesn't
    //  interrupt other mobile activity — user can dismiss without
    //  acking (banner will return on next poll if still pending).
    // ═══════════════════════════════════════════════════════════════
    var parBanner       = document.getElementById('parBanner');
    var parRow          = document.getElementById('parBannerRow');
    var parAckBtn       = document.getElementById('parBannerAckBtn');
    var parForm         = document.getElementById('parBannerForm');
    var parUnitName     = document.getElementById('parBannerUnitName');
    var parCountdown    = document.getElementById('parBannerCountdown');
    var parMembers      = document.getElementById('parBannerMembers');
    var parComments     = document.getElementById('parBannerComments');
    var parConfirmBtn   = document.getElementById('parBannerConfirmBtn');
    var parCancelBtn    = document.getElementById('parBannerCancelBtn');
    var parActive       = null;
    var parCountdownTimer = null;

    function showPARBanner() {
        if (!parBanner) return;
        parBanner.classList.remove('d-none');
        document.body.classList.add('par-banner-active');
    }
    function hidePARBanner() {
        if (!parBanner) return;
        parBanner.classList.add('d-none');
        document.body.classList.remove('par-banner-active');
        if (parForm) parForm.classList.add('d-none');
        if (parCountdownTimer) { clearInterval(parCountdownTimer); parCountdownTimer = null; }
        parActive = null;
    }
    function refreshCountdown() {
        if (!parActive || !parCountdown) return;
        var now = Math.floor(Date.now() / 1000);
        var rem = (parActive.expires_at || 0) - now;
        parCountdown.textContent = (rem > 0)
            ? Math.floor(rem / 60) + 'm ' + (rem % 60) + 's left'
            : 'OVERDUE';
    }

    function pollPAR() {
        if (!parBanner) return;
        if (!responderId) return;
        fetch('api/par.php?action=for_responder&responder_id=' + responderId,
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) return;
                if (!data.enabled || !data.active) {
                    hidePARBanner();
                    return;
                }
                parActive = data.active;
                if (parUnitName) parUnitName.textContent = data.active.unit_name || 'Your unit';
                refreshCountdown();
                if (!parCountdownTimer) {
                    parCountdownTimer = setInterval(refreshCountdown, 1000);
                }
                showPARBanner();
            })
            .catch(function () {});
    }

    if (parRow) {
        parRow.addEventListener('click', function (e) {
            // Tapping the row (not the Ack button) toggles the form open.
            if (e.target === parAckBtn || parAckBtn.contains(e.target)) return;
            if (parForm) parForm.classList.toggle('d-none');
        });
    }
    if (parAckBtn) {
        parAckBtn.addEventListener('click', function () {
            // Quick-ack with no member count / comments.
            sendParAck({});
        });
    }
    if (parConfirmBtn) {
        parConfirmBtn.addEventListener('click', function () {
            sendParAck({
                member_count: parMembers && parMembers.value ? parseInt(parMembers.value, 10) : null,
                comments:     parComments ? parComments.value : ''
            });
        });
    }
    if (parCancelBtn) {
        parCancelBtn.addEventListener('click', function () {
            if (parForm) parForm.classList.add('d-none');
        });
    }

    function sendParAck(extra) {
        if (!parActive) return;
        var payload = {
            action: 'ack',
            cycle_id: parActive.cycle_id,
            responder_id: parActive.responder_id,
            via: 'mobile',
            csrf_token: csrf
        };
        if (extra.member_count) payload.member_count = extra.member_count;
        if (extra.comments)     payload.comments     = extra.comments;
        fetch('api/par.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.error) {
                showToast(data.error, 'error');
                return;
            }
            showToast('PAR ack sent.', 'success');
            if (parMembers)  parMembers.value  = '';
            if (parComments) parComments.value = '';
            hidePARBanner();
        });
    }

    // Poll once on load, then every 15s.
    setTimeout(pollPAR, 2000);
    setInterval(pollPAR, 15000);

    // ── Init ────────────────────────────────────────────────────

    loadDashboard();
    connectSSE();

})();
