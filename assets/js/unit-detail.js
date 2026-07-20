(function () {
    'use strict';

    var map = null;
    var marker = null;
    var unitData = null;
    var allStatuses = [];

    // ── Initialization ──
    function init() {
        var id = getUnitId();
        if (!id) {
            showAlert('No unit ID specified. <a href="units.php" class="alert-link">Return to units list</a>', 'danger');
            document.getElementById('loadingSpinner').classList.add('d-none');
            return;
        }
        loadUnit(id);
        loadStatuses();
    }

    function getUnitId() {
        var params = new URLSearchParams(window.location.search);
        var id = parseInt(params.get('id'), 10);
        return id > 0 ? id : null;
    }

    function getCsrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    // ── Load unit data from API ──
    function loadUnit(id) {
        fetch('api/responder-detail.php?id=' + id)
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

                unitData = data;
                // 2026-07-03 — expose to the title-bar action snippet
                // in unit-detail.php so it can render the modal with
                // the handle instead of "unit #ID".
                window.unitData = data;

                // Update page title
                var resp = data.responder;
                document.title = resp.handle || resp.name + ' — Tickets NewUI';

                // Set edit link
                var editBtn = document.getElementById('btnEditUnit');
                if (editBtn) editBtn.href = 'unit-edit.php?id=' + resp.id;

                // Phase 26A (2026-06-11) — ICS-214 PAR-derived activity log links
                wireIcs214Buttons(resp.id);

                // Render all sections
                renderHeader(resp);
                renderContact(resp);
                renderMessaging(resp);
                renderCommIdentifiers(data.comm_identifiers || []);
                renderTracking(resp);
                renderAdditional(resp);
                renderLocation(resp);
                renderActiveAssignments(data.active_assignments);
                renderHistory(data.recent_assignments);
                renderStats(data.stats);
                renderUnitPersonnel(data.unit_personnel || []);
                renderNotes(data.notes || []);
                renderLocationBindings(data.location_bindings || []);
                renderResolvedLocation(data.resolved_location);
                initMap(resp, data.resolved_location || null);

                // Show content, hide spinner
                document.getElementById('loadingSpinner').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');
            })
            .catch(function (err) {
                showAlert('Failed to load unit: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Load available statuses for quick-change buttons ──
    function loadStatuses() {
        fetch('api/responders.php')
            .then(function (r) { return r.json(); })
            .then(function () {
                // We need the un_status table directly; fetch it separately
                // For now, build from known common statuses
            })
            .catch(function () {});

        // Fetch statuses by querying a simple endpoint or extracting from responders
        // We use a lightweight approach: fetch un_status directly
        fetchStatuses();
    }

    // ── Phase 95: Helpers for the per-status extra-data prompt ──

    // Find a status row by id in the loaded allStatuses array.
    function findStatusById(statusId) {
        if (!allStatuses) return null;
        for (var i = 0; i < allStatuses.length; i++) {
            if (parseInt(allStatuses[i].id, 10) === parseInt(statusId, 10)) {
                return allStatuses[i];
            }
        }
        return null;
    }

    // Show a Bootstrap modal asking for the configured extra-data
    // input. Resolves to { type, value } on confirm, or null on cancel.
    // Uses a generic modal template inserted into the page on first
    // call.
    function ensureExtraDataModal() {
        if (document.getElementById('extraDataModal')) return;
        var html =
            '<div class="modal fade" id="extraDataModal" tabindex="-1" aria-hidden="true">' +
              '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header py-2">' +
                  '<h6 class="modal-title" id="extraDataModalLabel">Additional info</h6>' +
                  '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                '</div>' +
                '<div class="modal-body py-2"><div id="extraDataModalBody"></div></div>' +
                '<div class="modal-footer py-2">' +
                  '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>' +
                  '<button type="button" class="btn btn-primary btn-sm" id="extraDataModalConfirm">Confirm</button>' +
                '</div>' +
              '</div></div>' +
            '</div>';
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        document.body.appendChild(wrap.firstChild);
    }

    function promptForExtraData(status) {
        return new Promise(function (resolve) {
            ensureExtraDataModal();
            var type   = status.extra_data_type;
            var label  = status.extra_data_label
                         || ({facility:'Destination facility', mileage:'Mileage',
                              location:'Location', note:'Note', numeric:'Value'})[type]
                         || 'Additional info';
            var req    = parseInt(status.extra_data_required || 0, 10) === 1;

            document.getElementById('extraDataModalLabel').textContent =
                'Status: ' + status.status_val + (req ? ' — ' + label + ' required' : '');
            var body = document.getElementById('extraDataModalBody');
            body.innerHTML = '';

            var input;
            if (type === 'facility') {
                input = document.createElement('select');
                input.className = 'form-select form-select-sm';
                input.innerHTML = '<option value="">— select a facility —</option>';
                fetch('api/facilities.php')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var rows = data.facilities || data.rows || data || [];
                        for (var i = 0; i < rows.length; i++) {
                            var f = rows[i];
                            var opt = document.createElement('option');
                            opt.value = f.id;
                            opt.textContent = f.name + (f.type ? ' (' + f.type + ')' : '');
                            input.appendChild(opt);
                        }
                    })
                    .catch(function () { /* leave empty */ });
            } else if (type === 'mileage' || type === 'numeric') {
                input = document.createElement('input');
                input.type = 'number';
                input.step = '1';
                input.className = 'form-control form-control-sm';
                input.placeholder = type === 'mileage' ? 'Odometer reading' : 'Numeric value';
            } else if (type === 'note') {
                input = document.createElement('textarea');
                input.className = 'form-control form-control-sm';
                input.rows = 3;
                input.placeholder = 'Enter a note';
            } else if (type === 'location') {
                input = document.createElement('div');
                input.innerHTML =
                    '<div class="d-flex gap-1 mb-1">' +
                      '<input type="number" step="any" class="form-control form-control-sm" placeholder="lat" id="extraLat">' +
                      '<input type="number" step="any" class="form-control form-control-sm" placeholder="lng" id="extraLng">' +
                    '</div>' +
                    '<button type="button" class="btn btn-outline-primary btn-sm" id="extraUseGps">' +
                      '<i class="bi bi-geo-alt me-1"></i>Use my GPS</button>';
            } else {
                input = document.createElement('input');
                input.className = 'form-control form-control-sm';
            }
            var lbl = document.createElement('label');
            lbl.className = 'form-label small mb-1';
            lbl.textContent = label + (req ? ' *' : ' (optional)');
            body.appendChild(lbl);
            body.appendChild(input);

            // GPS button wiring for location type
            if (type === 'location') {
                var btnGps = document.getElementById('extraUseGps') || body.querySelector('#extraUseGps');
                if (btnGps) {
                    btnGps.addEventListener('click', function () {
                        if (!navigator.geolocation) return;
                        btnGps.disabled = true;
                        navigator.geolocation.getCurrentPosition(function (pos) {
                            body.querySelector('#extraLat').value = pos.coords.latitude.toFixed(6);
                            body.querySelector('#extraLng').value = pos.coords.longitude.toFixed(6);
                            btnGps.disabled = false;
                        }, function () { btnGps.disabled = false; });
                    });
                }
            }

            var modalEl = document.getElementById('extraDataModal');
            var modal = new bootstrap.Modal(modalEl);

            // Cleanup + resolve helpers — must be defined BEFORE confirm
            // and dismiss handlers (and confirm needs to NOT trigger
            // the dismiss path, hence the resolved flag).
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
                if (type === 'facility' || type === 'mileage' || type === 'numeric') {
                    value = input.value;
                    if (type !== 'facility') value = value === '' ? null : parseFloat(value);
                    else value = value === '' ? null : parseInt(value, 10);
                } else if (type === 'note') {
                    value = input.value.trim();
                } else if (type === 'location') {
                    var lat = parseFloat(body.querySelector('#extraLat').value);
                    var lng = parseFloat(body.querySelector('#extraLng').value);
                    value = (!isNaN(lat) && !isNaN(lng)) ? [lat, lng] : null;
                }
                if (req && (value === null || value === '' || (Array.isArray(value) && value.length === 0))) {
                    alert(label + ' is required for this status.');
                    return;
                }
                resolved = true;
                cleanup();
                modal.hide();
                resolve({ type: type, value: value });
            }
            var btnConfirm = document.getElementById('extraDataModalConfirm');
            btnConfirm.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onDismiss);
            modal.show();
        });
    }

    function fetchStatuses() {
        // Try to get statuses from responder data or use a dedicated query
        // Since we don't have a separate status endpoint, we fetch via responders
        // and extract unique statuses. But let's build a dedicated fetch.
        fetch('api/responder-detail.php?id=' + getUnitId())
            .then(function () {
                // Statuses are loaded differently; build buttons after unit loads
            })
            .catch(function () {});

        // Use a simple inline fetch for status list
        fetch('api/unit-statuses.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.statuses) {
                    allStatuses = data.statuses;
                    renderStatusButtons();
                }
            })
            .catch(function () {
                // Fallback: show basic buttons
                renderFallbackStatusButtons();
            });
    }

    function renderStatusButtons() {
        var container = document.getElementById('statusButtons');
        if (!container || allStatuses.length === 0) {
            renderFallbackStatusButtons();
            return;
        }

        var html = '';
        for (var i = 0; i < allStatuses.length; i++) {
            var s = allStatuses[i];
            // QA #15 — un_status.hide is enum('n','y'); parseInt('y')===1 never
            // matched, so hidden statuses leaked into the picker.
            if (s.hide === 'y' || s.hide === 1 || s.hide === '1') continue;

            var style = '';
            if (s.bg_color) {
                style = 'background-color:' + escHtml(s.bg_color) + ';';
                if (s.text_color) style += 'color:' + escHtml(s.text_color) + ';';
                style += 'border-color:' + escHtml(s.bg_color) + ';';
            }

            var isActive = unitData && unitData.responder && unitData.responder.status_id === parseInt(s.id, 10);

            html += '<button type="button" class="btn btn-sm status-btn' + (isActive ? ' active' : '') + '"'
                + ' data-status-id="' + s.id + '"'
                + (style ? ' style="' + style + '"' : '')
                + '>' + escHtml(s.status_val) + '</button>';
        }

        container.innerHTML = html;

        // Bind click handlers
        var buttons = container.querySelectorAll('.status-btn');
        for (var j = 0; j < buttons.length; j++) {
            buttons[j].addEventListener('click', function () {
                var statusId = parseInt(this.getAttribute('data-status-id'), 10);
                changeStatus(statusId);
            });
        }

        renderDispatchLevel();
    }

    function renderFallbackStatusButtons() {
        var container = document.getElementById('statusButtons');
        if (!container) return;

        container.innerHTML =
            '<button type="button" class="btn btn-sm btn-success status-btn" data-status-id="1">Available</button>' +
            '<button type="button" class="btn btn-sm btn-warning status-btn" data-status-id="2">Busy</button>' +
            '<button type="button" class="btn btn-sm btn-danger status-btn" data-status-id="3">Unavailable</button>';

        var buttons = container.querySelectorAll('.status-btn');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                var statusId = parseInt(this.getAttribute('data-status-id'), 10);
                changeStatus(statusId);
            });
        }

        renderDispatchLevel();
    }

    // ── Render: Dispatch Level Buttons ──
    function renderDispatchLevel() {
        var row = document.getElementById('dispatchLevelRow');
        if (!row || !unitData || !unitData.responder) return;
        var resp = unitData.responder;
        if (resp.status_dispatch === undefined || resp.status_dispatch === null) return;

        row.classList.remove('d-none');
        var dl = parseInt(resp.status_dispatch, 10);
        var btns = document.querySelectorAll('.dispatch-btn');
        for (var i = 0; i < btns.length; i++) {
            var val = parseInt(btns[i].getAttribute('data-dispatch'), 10);
            if (val === dl) {
                btns[i].classList.remove('btn-outline-success', 'btn-outline-warning', 'btn-outline-danger');
                if (val === 0) btns[i].classList.add('btn-success');
                else if (val === 1) btns[i].classList.add('btn-warning');
                else btns[i].classList.add('btn-danger');
            }
            btns[i].addEventListener('click', function () {
                // Placeholder: dispatch level change not yet wired to API
                var v = parseInt(this.getAttribute('data-dispatch'), 10);
                showAlert('Dispatch level change to ' + v + ' not yet implemented.', 'info');
            });
        }
    }

    // ── Change status via API ──
    function changeStatus(statusId, extraData) {
        var id = getUnitId();
        if (!id) return;

        var notesInput = document.getElementById('statusNotes');
        var notes = notesInput ? notesInput.value.trim() : '';

        // Phase 95 (2026-06-28): if the status has extra_data_type set,
        // prompt for the configured input before POSTing. The prompt
        // returns a Promise so this function awaits it; cancel aborts.
        var status = findStatusById(statusId);
        if (status && status.extra_data_type && status.extra_data_type !== 'none' && !extraData) {
            promptForExtraData(status).then(function (ed) {
                if (ed === null) return; // user cancelled
                changeStatus(statusId, ed); // recurse with the data
            });
            return;
        }

        var body = {
            responder_id: id,
            status_id: statusId,
            status_about: notes,
            csrf_token: getCsrfToken()
        };
        if (extraData) body.extra_data = extraData;

        fetch('api/responder-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }
            showAlert(escHtml(data.message), 'success');
            if (notesInput) notesInput.value = '';
            // Reload unit data
            loadUnit(id);
        })
        .catch(function (err) {
            showAlert('Failed to update status: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Render: Header ──
    function renderHeader(resp) {
        document.getElementById('pageTitle').innerHTML =
            '<i class="bi bi-person-badge text-primary me-2"></i>' + escHtml(resp.handle || resp.name);

        // Status badge
        var statusBadge = document.getElementById('statusBadge');
        statusBadge.textContent = resp.status_name || 'Unknown';
        if (resp.status_bg_color) {
            statusBadge.style.backgroundColor = resp.status_bg_color;
            statusBadge.style.color = resp.status_text_color || '#fff';
        } else {
            statusBadge.className = 'badge bg-secondary';
        }

        // Type badge
        var typeBadge = document.getElementById('typeBadge');
        typeBadge.textContent = resp.type_name || 'No Type';

        // Dispatch level badge
        var dispatchBadge = document.getElementById('dispatchBadge');
        if (dispatchBadge && resp.status_dispatch !== undefined && resp.status_dispatch !== null) {
            var dl = parseInt(resp.status_dispatch, 10);
            var dlMap = [
                { label: '\u2713 Available', cls: 'bg-success' },
                { label: '\u26A0 Inform',    cls: 'bg-warning text-dark' },
                { label: '\u2717 Unavailable', cls: 'bg-danger' }
            ];
            var dlInfo = dlMap[dl] || dlMap[0];
            dispatchBadge.textContent = dlInfo.label;
            dispatchBadge.className = 'badge ' + dlInfo.cls;
            dispatchBadge.classList.remove('d-none');
        }

        // Tracking provider badge
        var trackBadge = document.getElementById('trackingBadge');
        if (trackBadge && resp.tracking_provider) {
            trackBadge.textContent = resp.tracking_provider.toUpperCase();
            trackBadge.className = 'badge bg-info';
            trackBadge.title = 'Tracking: ' + resp.tracking_provider;
            trackBadge.classList.remove('d-none');
        }

        // Name
        document.getElementById('unitName').textContent = resp.name;

        // Meta line
        var meta = 'ID: ' + resp.id;
        if (resp.handle) meta += ' | Handle: ' + resp.handle;
        if (resp.callsign) meta += ' | Callsign: ' + resp.callsign;
        if (resp.status_about) meta += ' | Status: ' + resp.status_about;
        document.getElementById('unitMeta').textContent = meta;
    }

    // ── Render: Contact ──
    function renderContact(resp) {
        document.getElementById('contactPhone').textContent = resp.phone || '--';
        document.getElementById('contactCell').textContent = resp.cellphone || '--';
        document.getElementById('contactName').textContent = resp.contact_name || '--';
        document.getElementById('unitCapab').textContent = resp.capab || '--';
    }

    // ── Render: Messaging ──
    function renderMessaging(resp) {
        var hasData = resp.contact_via || resp.smsg_id || resp.pager_p
            || resp.pager_s || resp.send_no;
        var card = document.getElementById('messagingCard');
        if (!card) return;
        if (!hasData) return; // leave card hidden

        card.classList.remove('d-none');
        document.getElementById('msgContactVia').textContent = resp.contact_via || '--';
        document.getElementById('msgSmsgId').textContent = resp.smsg_id || '--';
        document.getElementById('msgSendNo').textContent = resp.send_no || '--';
        document.getElementById('msgPagerP').textContent = resp.pager_p || '--';
        document.getElementById('msgPagerS').textContent = resp.pager_s || '--';
    }

    // ── Render: Tracking & Boundaries ──
    function renderTracking(resp) {
        var hasTracking = resp.tracking_provider;
        var hasRingFence = resp.ring_fence && parseInt(resp.ring_fence, 10) !== 0;
        var hasExclZone = resp.excl_zone && parseInt(resp.excl_zone, 10) !== 0;
        var card = document.getElementById('trackingCard');
        if (!card) return;
        if (!hasTracking && !hasRingFence && !hasExclZone) return;

        card.classList.remove('d-none');
        document.getElementById('trackProvider').textContent =
            resp.tracking_provider ? resp.tracking_provider.toUpperCase() : 'None';

        document.getElementById('trackRingFence').textContent =
            hasRingFence ? resp.ring_fence + ' m' : 'Not set';

        document.getElementById('trackExclZone').textContent =
            hasExclZone ? resp.excl_zone + ' m' : 'Not set';
    }

    // ── Render: Communication Identifiers ──
    function renderCommIdentifiers(ids) {
        var card = document.getElementById('commIdsCard');
        var body = document.getElementById('commIdsBody');
        var badge = document.getElementById('commIdsBadge');
        if (!card || !body) return;
        if (!ids || ids.length === 0) return;

        card.classList.remove('d-none');
        if (badge) badge.textContent = ids.length;

        // Mode icon map (Bootstrap Icons)
        var modeIcons = {
            'aprs':       'bi-broadcast',
            'dmr':        'bi-headset',
            'meshtastic': 'bi-diagram-3',
            'zello':      'bi-mic',
            'owntracks':  'bi-geo-alt',
            'generic_radio': 'bi-volume-up',
            'phone':      'bi-phone',
            'email':      'bi-envelope'
        };

        var html = '<div class="row g-2">';
        for (var i = 0; i < ids.length; i++) {
            var ci = ids[i];
            var icon = modeIcons[ci.mode_code] || ci.mode_icon || 'bi-link-45deg';
            var primary = ci.is_primary ? ' <span class="badge bg-success" style="font-size:0.55rem;">Primary</span>' : '';
            var notes = ci.notes ? '<div class="text-body-tertiary" style="font-size:0.7rem;">' + escHtml(ci.notes) + '</div>' : '';

            html += '<div class="col-md-6">' +
                '<div class="d-flex align-items-start gap-2 py-1">' +
                '<i class="bi ' + icon + ' text-primary mt-1" style="font-size:0.85rem;"></i>' +
                '<div>' +
                '<div class="text-body-secondary" style="font-size:0.7rem;">' + escHtml(ci.mode_name) + primary + '</div>' +
                '<div class="fw-semibold" style="font-family:monospace;font-size:0.85rem;">' + escHtml(ci.value) + '</div>' +
                notes +
                '</div>' +
                '</div>' +
                '</div>';
        }
        html += '</div>';
        body.innerHTML = html;
    }

    // ── Render: Additional Details ──
    function renderAdditional(resp) {
        var hasData = resp.direcs || resp.icon_str || resp.other;
        var card = document.getElementById('additionalCard');
        if (!card) return;
        if (!hasData) return;

        card.classList.remove('d-none');
        document.getElementById('addlDirecs').textContent = resp.direcs || '--';
        document.getElementById('addlIconStr').textContent = resp.icon_str || '--';
        document.getElementById('addlOther').textContent = resp.other || '--';
    }

    // ── Render: Location ──
    function renderLocation(resp) {
        document.getElementById('locStreet').textContent = resp.street || '--';
        document.getElementById('locCity').textContent = resp.city || '--';
        document.getElementById('locState').textContent = resp.state || '--';

        if (resp.lat && resp.lng) {
            document.getElementById('locLat').textContent = resp.lat.toFixed(6);
            document.getElementById('locLng').textContent = resp.lng.toFixed(6);
        } else {
            document.getElementById('locCoordsRow').classList.add('d-none');
        }

        if (resp.facility_name) {
            document.getElementById('facilityRow').classList.remove('d-none');
            document.getElementById('locFacility').textContent = resp.facility_name;
        }
    }

    // ── Render: Active Assignments ──
    function renderActiveAssignments(assignments) {
        var container = document.getElementById('activeList');
        var badge = document.getElementById('activeCount');

        badge.textContent = assignments.length;

        if (!assignments || assignments.length === 0) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">' +
                '<i class="bi bi-check-circle me-1"></i>No active assignments</div>';
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0 small">' +
            '<thead><tr><th>Incident</th><th>Type</th><th>Status</th><th>Dispatched</th></tr></thead><tbody>';

        for (var i = 0; i < assignments.length; i++) {
            var a = assignments[i];
            html += '<tr class="unit-row" data-ticket-id="' + a.ticket_id + '" style="cursor:pointer;">'
                + '<td class="fw-semibold">' + escHtml(a.scope) + '</td>'
                + '<td>' + escHtml(a.type_name || '') + '</td>'
                + '<td><span class="badge bg-info">' + escHtml(a.status) + '</span></td>'
                + '<td>' + formatDateTime(a.dispatched) + '</td>'
                + '</tr>';
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;

        // Click to go to incident
        var rows = container.querySelectorAll('.unit-row');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var tid = this.getAttribute('data-ticket-id');
                window.location.href = 'incident-detail.php?id=' + tid;
            });
        }
    }

    // ── Render: Assignment History ──
    function renderHistory(assignments) {
        var container = document.getElementById('historyList');
        var badge = document.getElementById('historyCount');

        badge.textContent = assignments.length;

        if (!assignments || assignments.length === 0) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">' +
                '<i class="bi bi-clock-history me-1"></i>No assignment history</div>';
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-sm table-striped table-hover mb-0 small">' +
            '<thead><tr><th>Incident</th><th>Type</th><th>Dispatched</th><th>On Scene</th><th>Cleared</th><th>Resp. Time</th></tr></thead><tbody>';

        for (var i = 0; i < assignments.length; i++) {
            var a = assignments[i];
            html += '<tr class="unit-row" data-ticket-id="' + a.ticket_id + '" style="cursor:pointer;">'
                + '<td class="fw-semibold">' + escHtml(a.scope) + '</td>'
                + '<td>' + escHtml(a.type_name || '') + '</td>'
                + '<td>' + formatDateTime(a.dispatched) + '</td>'
                + '<td>' + formatTime(a.on_scene) + '</td>'
                + '<td>' + formatTime(a.clear) + '</td>'
                + '<td>' + (a.response_time !== null ? a.response_time + ' min' : '--') + '</td>'
                + '</tr>';
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;

        // Click to go to incident
        var rows = container.querySelectorAll('.unit-row');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var tid = this.getAttribute('data-ticket-id');
                window.location.href = 'incident-detail.php?id=' + tid;
            });
        }
    }

    // ── Render: Stats ──
    function renderStats(stats) {
        document.getElementById('statTotalCalls').textContent = stats.total_calls || 0;
        document.getElementById('statAvgResponse').textContent =
            stats.avg_response_time !== null ? stats.avg_response_time + ' min' : 'N/A';
        document.getElementById('statThisMonth').textContent = stats.calls_this_month || 0;
    }

    // ── Map ──
    function initMap(resp, resolved) {
        var container = document.getElementById('unitMap');
        if (!container || typeof L === 'undefined') return;

        // Phase 67: prefer the resolved (live) position from
        // unit_location_bindings; fall back to the responder row's
        // static lat/lng (the "home base" form field). A personal unit
        // has no static coords until you set one, so without this
        // fallback the map renders centered on the default and the
        // OwnTracks dot never appears here even though the badge
        // above shows the resolved fix.
        var lat = null, lng = null, sourceTag = null;
        if (resolved && resolved.lat !== null && resolved.lng !== null) {
            var rl = parseFloat(resolved.lat);
            var rg = parseFloat(resolved.lng);
            if (!isNaN(rl) && !isNaN(rg) && (rl !== 0 || rg !== 0)) {
                lat = rl;
                lng = rg;
                sourceTag = resolved.provider_name || resolved.provider_code || 'live';
            }
        }
        if (lat === null && resp.lat && resp.lng && (resp.lat !== 0 || resp.lng !== 0)) {
            lat = parseFloat(resp.lat);
            lng = parseFloat(resp.lng);
            sourceTag = 'static';
        }
        var hasCoords = lat !== null && lng !== null;

        if (hasCoords) {
            map = L.map('unitMap', { zoomControl: true }).setView([lat, lng], 15);
        } else {
            fetch('api/map-config.php')
                .then(function (r) { return r.json(); })
                .then(function (cfg) {
                    map = L.map('unitMap', { zoomControl: true })
                        .setView([cfg.def_lat || 39.8283, cfg.def_lng || -98.5795], cfg.def_zoom || 5);
                    var bl = null;
                    if (window.MapPrefs) {
                        bl = window.MapPrefs.addDefaultBasemap(map);
                        // Issue #46 — include markup overlays so the
                        // dispatcher can see the org's categorised
                        // markups (Race markers, Zone 4, precincts,
                        // parade routes, etc.) while placing / viewing
                        // this unit. Preferences share the situation
                        // view's newui_map_layers localStorage key.
                        window.MapPrefs.addLayerControl(map, {
                            currentBase: bl,
                            includeMarkupOverlays: true
                        });
                    } else {
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
                    }
                    setTimeout(function () { map.invalidateSize(); }, 200);
                })
                .catch(function () {});
            return;
        }

        var baseLayer = null;
        if (window.MapPrefs) {
            baseLayer = window.MapPrefs.addDefaultBasemap(map);
            // Attach the same layer picker the units listing uses so
            // the operator can switch to Satellite / Terrain / etc.
            // without leaving the detail page. includeMarkupOverlays
            // adds the org's map-overlay categories (Race markers,
            // Zone 4, parade routes, etc.) — issue #46. Prefs share
            // localStorage 'newui_map_layers' with situation view.
            window.MapPrefs.addLayerControl(map, {
                currentBase: baseLayer,
                includeMarkupOverlays: true
            });
        } else {
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
        }

        // Marker color — prefer the resolved provider's brand color
        // when we're plotting a live fix; otherwise the unit's status
        // background. Falls back to bootstrap primary if neither set.
        var color;
        if (resolved && resolved.color && resolved.lat !== null) {
            color = resolved.color;
        } else {
            color = resp.status_bg_color || '#0d6efd';
        }

        marker = L.circleMarker([lat, lng], {
            radius: 10,
            color: color,
            fillColor: color,
            fillOpacity: 0.8,
            weight: 2
        }).addTo(map);

        var popupHtml = '<strong>' + escHtml(resp.handle || resp.name) + '</strong>';
        if (sourceTag && sourceTag !== 'static') {
            popupHtml += '<br><small class="text-body-secondary">via ' + escHtml(sourceTag) + '</small>';
        } else if (resp.street || resp.city) {
            popupHtml += '<br><small>' + escHtml(resp.street || '') + (resp.city ? ', ' + escHtml(resp.city) : '') + '</small>';
        }
        marker.bindPopup(popupHtml);

        setTimeout(function () { map.invalidateSize(); }, 200);
    }

    // ── Utilities ──
    function formatDateTime(dt) {
        if (!dt) return '--';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return month + '/' + day + '/' + d.getFullYear() + ' ' + hours + ':' + mins;
    }

    // ══════════════════════════════════════════════════════════════
    //  UNIT PERSONNEL ASSIGNMENTS
    // ══════════════════════════════════════════════════════════════

    // GH #75 — recent unit notes (added from dashboard quick-action or the
    // unit-detail title bar; now persisted to responder_notes and shown here).
    function renderNotes(notes) {
        notes = notes || [];
        var card = document.getElementById('notesCard');
        var body = document.getElementById('unitNotesBody');
        var badge = document.getElementById('notesBadge');
        if (!body) return;
        if (badge) badge.textContent = notes.length;
        if (!notes.length) {
            if (card) card.classList.add('d-none');
            body.innerHTML = '';
            return;
        }
        if (card) card.classList.remove('d-none');
        var html = '';
        for (var i = 0; i < notes.length; i++) {
            var n = notes[i];
            var meta = [];
            if (n.by_username) { meta.push(escHtml(n.by_username)); }
            if (n.created_at) { meta.push(escHtml(n.created_at)); }
            html += '<li class="list-group-item py-2">'
                  + '<div>' + escHtml(n.note || '') + '</div>'
                  + '<div class="text-body-secondary" style="font-size:0.75rem">' + meta.join(' &middot; ') + '</div>'
                  + '</li>';
        }
        body.innerHTML = html;
    }

    function renderUnitPersonnel(personnel) {
        var container = document.getElementById('personnelList');
        var badge = document.getElementById('personnelCount');
        if (!container) return;

        if (badge) badge.textContent = personnel.length;

        if (!personnel.length) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">No personnel assigned</div>';
            return;
        }

        var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.8rem">';
        html += '<thead><tr><th>Name</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>';
        for (var i = 0; i < personnel.length; i++) {
            var p = personnel[i];
            var statusCls = p.status === 'active' ? 'bg-success' : 'bg-warning text-dark';
            html += '<tr>';
            html += '<td>' + escHtml(p.member_name || '') +
                    (p.member_callsign ? ' <span class="text-body-secondary">(' + escHtml(p.member_callsign) + ')</span>' : '') + '</td>';
            html += '<td><span class="badge bg-secondary">' + escHtml(p.role) + '</span></td>';
            html += '<td><span class="badge ' + statusCls + '">' + escHtml(p.status) + '</span></td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-sm btn-outline-danger py-0 px-1 btn-release-personnel" data-id="' + p.assignment_id + '" title="Release">';
            html += '<i class="bi bi-person-dash"></i>';
            html += '</button>';
            html += '</td></tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;

        // Bind release buttons
        var btns = container.querySelectorAll('.btn-release-personnel');
        for (var j = 0; j < btns.length; j++) {
            btns[j].addEventListener('click', function () {
                var assignId = parseInt(this.getAttribute('data-id'), 10);
                if (!confirm('Release this person from the unit?')) return;
                fetch('api/unit-assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'release',
                        id: assignId,
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (data.error) { showAlert(escHtml(data.error), 'danger'); return; }
                      loadUnit(getUnitId());
                  });
            });
        }
    }

    // ── Assign Personnel Button + Search ──
    (function initAssignPersonnel() {
        var btn = document.getElementById('btnAssignPersonnel');
        if (!btn) return;

        var searchTimer = null;

        btn.addEventListener('click', function () {
            // Load roles for the dropdown
            fetch('api/unit-assignments.php?roles=1')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var sel = document.getElementById('assignRoleSelect');
                    if (sel && data.roles) {
                        sel.innerHTML = '';
                        for (var i = 0; i < data.roles.length; i++) {
                            if (!data.roles[i].active) continue;
                            var opt = document.createElement('option');
                            opt.value = data.roles[i].code;
                            opt.textContent = data.roles[i].name;
                            sel.appendChild(opt);
                        }
                    }
                });

            var modal = new bootstrap.Modal(document.getElementById('assignPersonnelModal'));
            modal.show();

            setTimeout(function () {
                var inp = document.getElementById('personnelSearchInput');
                if (inp) { inp.value = ''; inp.focus(); }
                var res = document.getElementById('personnelSearchResults');
                if (res) res.innerHTML = '<div class="text-body-secondary text-center small py-2">Type to search...</div>';
            }, 200);
        });

        var searchInp = document.getElementById('personnelSearchInput');
        if (searchInp) {
            searchInp.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var q = this.value.trim();
                if (q.length < 2) {
                    document.getElementById('personnelSearchResults').innerHTML =
                        '<div class="text-body-secondary text-center small py-2">Type to search...</div>';
                    return;
                }
                searchTimer = setTimeout(function () {
                    fetch('api/members.php?search=' + encodeURIComponent(q) + '&limit=15')
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var container = document.getElementById('personnelSearchResults');
                            var members = data.members || data.data || [];
                            if (!members.length) {
                                container.innerHTML = '<div class="text-body-secondary text-center small py-2">No results</div>';
                                return;
                            }
                            var html = '';
                            for (var i = 0; i < members.length; i++) {
                                var m = members[i];
                                var name = (m.first_name || '') + ' ' + (m.last_name || '');
                                html += '<div class="d-flex align-items-center py-1 px-2 border-bottom personnel-result" ' +
                                        'style="cursor:pointer" data-member-id="' + m.id + '">';
                                html += '<div class="small"><strong>' + escHtml(name.trim()) + '</strong>';
                                if (m.callsign) html += ' <span class="text-body-secondary">(' + escHtml(m.callsign) + ')</span>';
                                html += '</div></div>';
                            }
                            container.innerHTML = html;

                            // Bind click handlers
                            var items = container.querySelectorAll('.personnel-result');
                            for (var j = 0; j < items.length; j++) {
                                items[j].addEventListener('click', function () {
                                    var mid = parseInt(this.getAttribute('data-member-id'), 10);
                                    var role = document.getElementById('assignRoleSelect').value;
                                    doAssignPersonnel(mid, role);
                                });
                            }
                        });
                }, 300);
            });
        }
    })();

    function doAssignPersonnel(memberId, role, force) {
        var unitId = getUnitId();
        if (!unitId) return;

        var payload = {
            action: 'assign',
            responder_id: unitId,
            member_id: memberId,
            role: role,
            csrf_token: getCsrfToken()
        };
        if (force) payload.force = true;

        fetch('api/unit-assignments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data.error) { showAlert(escHtml(data.error), 'danger'); return; }

              // One-unit-per-person: API returns needs_confirmation if member is on another unit
              if (data.needs_confirmation) {
                  if (confirm(data.message)) {
                      doAssignPersonnel(memberId, role, true);
                  }
                  return;
              }

              bootstrap.Modal.getInstance(document.getElementById('assignPersonnelModal')).hide();
              loadUnit(unitId);
          });
    }

    // ══════════════════════════════════════════════════════════════
    //  LOCATION BINDINGS & RESOLVED LOCATION
    // ══════════════════════════════════════════════════════════════

    function renderLocationBindings(bindings) {
        var container = document.getElementById('locationBindingsList');
        if (!container) return;

        if (!bindings.length) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">No location sources configured</div>';
            return;
        }

        var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.8rem">';
        html += '<thead><tr><th>Provider</th><th>Identifier</th><th>Priority</th><th>Max Age</th><th></th></tr></thead><tbody>';
        for (var i = 0; i < bindings.length; i++) {
            var b = bindings[i];
            var activeCls = b.active ? '' : ' class="text-decoration-line-through text-body-secondary"';
            html += '<tr' + activeCls + '>';
            html += '<td>';
            if (b.provider_icon) html += '<i class="bi ' + escHtml(b.provider_icon) + ' me-1" style="color:' + escHtml(b.provider_color || '') + '"></i>';
            html += escHtml(b.provider_name || b.provider_code || '') + '</td>';
            html += '<td class="font-monospace">' + escHtml(b.unit_identifier) + '</td>';
            html += '<td>' + b.priority + '</td>';
            html += '<td>' + formatSeconds(b.max_age_seconds) + '</td>';
            html += '<td class="text-end">';
            if (b.active) {
                html += '<button class="btn btn-sm btn-outline-danger py-0 px-1 btn-unbind" data-id="' + b.id + '" title="Remove">';
                html += '<i class="bi bi-x-lg"></i>';
                html += '</button>';
            }
            html += '</td></tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;

        // Bind unbind buttons
        var btns = container.querySelectorAll('.btn-unbind');
        for (var j = 0; j < btns.length; j++) {
            btns[j].addEventListener('click', function () {
                var bindId = parseInt(this.getAttribute('data-id'), 10);
                if (!confirm('Remove this location binding?')) return;
                fetch('api/location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'unbind',
                        id: bindId,
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (data.error) { showAlert(escHtml(data.error), 'danger'); return; }
                      loadUnit(getUnitId());
                  });
            });
        }
    }

    function renderResolvedLocation(loc) {
        var bar = document.getElementById('resolvedLocationBar');
        if (!bar) return;

        if (!loc) {
            bar.classList.add('d-none');
            return;
        }

        bar.classList.remove('d-none');
        var icon = document.getElementById('resolvedIcon');
        var provider = document.getElementById('resolvedProvider');
        var age = document.getElementById('resolvedAge');
        var badge = document.getElementById('resolvedFreshBadge');

        if (icon) {
            icon.className = 'bi ' + (loc.icon || 'bi-geo-alt-fill') + ' me-1';
            icon.style.color = loc.color || '#3366FF';
        }
        if (provider) provider.textContent = (loc.provider_name || 'Unknown') + ' via ' + (loc.unit_identifier || '');
        if (age) age.textContent = loc.age_seconds !== null ? formatSeconds(loc.age_seconds) + ' ago' : '';
        if (badge) {
            var isFresh = parseInt(loc.is_fresh, 10);
            badge.className = 'badge ' + (isFresh ? 'bg-success' : 'bg-warning text-dark');
            badge.textContent = isFresh ? 'Fresh' : 'Stale';
        }
    }

    function formatSeconds(sec) {
        sec = parseInt(sec, 10);
        if (isNaN(sec)) return '--';
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        return Math.floor(sec / 3600) + 'h ' + Math.floor((sec % 3600) / 60) + 'm';
    }

    // ── Add Location Binding Button ──
    (function initAddBinding() {
        var btn = document.getElementById('btnAddBinding');
        if (!btn) return;

        btn.addEventListener('click', function () {
            // Load providers for dropdown
            fetch('api/location.php')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var sel = document.getElementById('bindProviderSelect');
                    if (sel && data.providers) {
                        sel.innerHTML = '';
                        for (var i = 0; i < data.providers.length; i++) {
                            var p = data.providers[i];
                            if (!p.enabled) continue;
                            var opt = document.createElement('option');
                            opt.value = p.id;
                            opt.textContent = p.name + ' (pri ' + p.priority + ', max age ' + formatSeconds(p.max_age_seconds) + ')';
                            sel.appendChild(opt);
                        }
                    }
                });

            document.getElementById('bindIdentifier').value = '';
            document.getElementById('bindPriority').value = '50';

            var modal = new bootstrap.Modal(document.getElementById('addBindingModal'));
            modal.show();
        });

        var saveBtn = document.getElementById('btnSaveBinding');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var providerId = parseInt(document.getElementById('bindProviderSelect').value, 10);
                var identifier = document.getElementById('bindIdentifier').value.trim();
                var priority = parseInt(document.getElementById('bindPriority').value, 10);
                var unitId = getUnitId();

                if (!providerId || !identifier || !unitId) {
                    showAlert('Provider and identifier are required', 'warning');
                    return;
                }

                fetch('api/location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bind',
                        responder_id: unitId,
                        provider_id: providerId,
                        unit_identifier: identifier,
                        priority: priority,
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (data.error) { showAlert(escHtml(data.error), 'danger'); return; }
                      bootstrap.Modal.getInstance(document.getElementById('addBindingModal')).hide();
                      loadUnit(unitId);
                  });
            });
        }
    })();

    // ══════════════════════════════════════════════════════════════
    //  ROUTE PLAYBACK
    // ══════════════════════════════════════════════════════════════

    var _routePlayer = null;

    (function initRoutePlayback() {
        var btn = document.getElementById('btnRoutePlayback');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!map) { showAlert('Map not ready', 'warning'); return; }
            var unitId = getUnitId();
            if (!unitId) return;

            // Destroy previous playback
            if (_routePlayer) {
                _routePlayer.destroy();
                _routePlayer = null;
                btn.innerHTML = '<i class="bi bi-play-circle"></i>';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-outline-info');
                return;
            }

            var hours = parseInt(document.getElementById('routeHours').value, 10) || 24;

            btn.innerHTML = '<i class="bi bi-x-circle"></i>';
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-danger');

            if (typeof RoutePlayback === 'undefined') {
                showAlert('Route playback not available', 'warning');
                return;
            }

            _routePlayer = RoutePlayback.init(map, {
                responderId: unitId,
                hours: hours,
                controlContainer: document.getElementById('routePlaybackContainer'),
                onEmpty: function () {
                    showAlert('No location history found for the selected time range', 'info');
                    btn.innerHTML = '<i class="bi bi-play-circle"></i>';
                    btn.classList.remove('btn-danger');
                    btn.classList.add('btn-outline-info');
                    _routePlayer = null;
                }
            });
            _routePlayer.load();
        });
    })();

    // ── Utility Functions ──

    function formatTime(dt) {
        if (!dt) return '<span class="text-body-tertiary">--</span>';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return hours + ':' + mins;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
        area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Esc returns to dashboard ──
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            window.location.href = 'index.php';
        }
    }, true);

    // ── Phase 26A (2026-06-11) ── ICS-214 PAR-derived export buttons ──
    function wireIcs214Buttons(responderId) {
        var jsonBtn = document.getElementById('btnIcs214Json24h');
        var xml24Btn = document.getElementById('btnIcs214Xml24h');
        var xml7dBtn = document.getElementById('btnIcs214Xml7d');
        if (jsonBtn) {
            jsonBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openIcs214Preview(responderId);
            });
        }
        if (xml24Btn) {
            xml24Btn.addEventListener('click', function (e) {
                e.preventDefault();
                downloadIcs214Xml(responderId, 1);
            });
        }
        if (xml7dBtn) {
            xml7dBtn.addEventListener('click', function (e) {
                e.preventDefault();
                downloadIcs214Xml(responderId, 7);
            });
        }
    }

    function downloadIcs214Xml(responderId, days) {
        var to = new Date();
        var from = new Date(); from.setDate(to.getDate() - days);
        var url = 'api/ics214-par-export.php?responder_id=' + responderId +
                  '&from=' + isoDate(from) + '&to=' + isoDate(to) + '&format=xml';
        window.open(url, '_blank');
    }

    function openIcs214Preview(responderId) {
        var to = new Date();
        var from = new Date(); from.setDate(to.getDate() - 1);
        fetch('api/ics214-par-export.php?responder_id=' + responderId +
              '&from=' + isoDate(from) + '&to=' + isoDate(to), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) {
                    alert('Failed to load ICS-214 timeline: ' + ((data && data.error) || 'unknown'));
                    return;
                }
                var w = window.open('', '_blank');
                if (!w) {
                    alert('Pop-up blocked. Please allow pop-ups for this site.');
                    return;
                }
                var html = '<!doctype html><html><head><meta charset="utf-8">' +
                    '<title>ICS-214 — ' + escHtml(data.person_name) + '</title>' +
                    '<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem;}' +
                    'h1{font-size:1.2rem;}table{width:100%;border-collapse:collapse;}' +
                    'th,td{border:1px solid #ccc;padding:0.4rem 0.6rem;font-size:0.85rem;text-align:left;vertical-align:top;}' +
                    'th{background:#f5f5f5;}.src{color:#666;font-size:0.75rem;}</style>' +
                    '</head><body>' +
                    '<h1>ICS-214 Activity Log — ' + escHtml(data.person_name) + '</h1>' +
                    '<p><strong>Operational period:</strong> ' + escHtml(data.op_start) + ' to ' + escHtml(data.op_end) + '<br>' +
                    '<strong>Callsign:</strong> ' + escHtml(data.callsign || '—') +
                    ' &nbsp; <strong>Handle:</strong> ' + escHtml(data.handle || '—') + '</p>' +
                    '<table><thead><tr><th>Time</th><th>Activity</th></tr></thead><tbody>';
                if (!data.entries || data.entries.length === 0) {
                    html += '<tr><td colspan="2"><em>No entries in this period.</em></td></tr>';
                } else {
                    for (var i = 0; i < data.entries.length; i++) {
                        var e = data.entries[i];
                        html += '<tr><td>' + escHtml(e.t) + '</td>' +
                            '<td>' + escHtml(e.note) + ' <span class="src">[' + escHtml(e.source) + ']</span></td></tr>';
                    }
                }
                html += '</tbody></table></body></html>';
                w.document.write(html);
                w.document.close();
            })
            .catch(function (err) { alert('Network error: ' + err.message); });
    }

    function isoDate(d) {
        var m = (d.getMonth() + 1).toString().padStart(2, '0');
        var day = d.getDate().toString().padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // 2026-07-03 — expose the reloader so the title-bar action bar
    // in unit-detail.php can refresh after status/dispatch/note.
    window.loadUnit = loadUnit;

    // ── Boot ──
    document.addEventListener('DOMContentLoaded', init);

})();
