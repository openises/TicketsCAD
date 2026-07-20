(function () {
    'use strict';

    var map = null;
    var marker = null;
    var livePositionMarker = null;  // Phase 64: distinct marker for the resolved live position
    var lastResolvedLocation = null; // Phase 64: cached for "Center map" button
    var editId = 0;
    var unitStatuses = []; // cached for dispatch level lookup

    // ── Initialization ──
    function init() {
        editId = getEditId();

        loadUnitTypes();
        loadFacilities();
        loadUnitStatuses();
        if (window.TCADStates) { window.TCADStates.fill(document.getElementById('state')); }
        initMap();
        initGeocode();
        initSave();
        initDelete();
        initStatusDropdown();

        if (editId > 0) {
            document.getElementById('loadingSpinner').classList.remove('d-none');
            // Show status section only in edit mode
            var statusCard = document.getElementById('secStatusCard');
            if (statusCard) statusCard.classList.remove('d-none');
            loadUnit(editId);
        }
    }

    function getEditId() {
        var el = document.getElementById('unitId');
        return el ? parseInt(el.value, 10) || 0 : 0;
    }

    function getCsrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    // ── Load unit data for editing ──
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

                var resp = data.responder;

                // Identity & Location
                setField('name', resp.name);
                setField('handle', resp.handle);
                setField('callsign', resp.callsign);
                setField('street', resp.street);
                setField('city', resp.city);
                setField('state', resp.state);
                if (resp.lat) setField('lat', resp.lat);
                if (resp.lng) setField('lng', resp.lng);

                // Contact & Messaging
                setField('phone', resp.phone);
                setField('cellphone', resp.cellphone);
                setField('contact_name', resp.contact_name);
                setField('contact_via', resp.contact_via);
                setField('smsg_id', resp.smsg_id);
                setField('pager_p', resp.pager_p);
                setField('pager_s', resp.pager_s);
                setField('send_no', resp.send_no);

                // Status
                setField('status_about', resp.status_about);

                // Configuration
                setField('capab', resp.capab);
                setField('description', resp.description);
                setField('icon_str', resp.icon_str);
                setField('other', resp.other);

                // Checkboxes
                setCheckbox('mobile', resp.mobile === 1);
                setCheckbox('multi', resp.multi === 1);
                setCheckbox('direcs', resp.direcs === 1);

                // Tracking
                setSelectValue('tracking_provider', resp.tracking_provider || '');

                // Boundaries
                setField('ring_fence', resp.ring_fence || '');
                setField('excl_zone', resp.excl_zone || '');

                // Dropdowns (set after they load via setTimeout)
                setTimeout(function () {
                    setSelectValue('type', resp.type_id);
                    setSelectValue('at_facility', resp.at_facility);
                    setSelectValue('un_status_id', resp.status_id);
                    updateDispatchBadge();
                }, 500);

                // Update map marker
                if (resp.lat && resp.lng) {
                    setMapMarker(resp.lat, resp.lng);
                }

                // Update page title
                document.title = 'Edit: ' + (resp.handle || resp.name) + ' — Tickets NewUI';
                document.getElementById('pageTitle').innerHTML =
                    '<i class="bi bi-pencil text-primary me-2"></i>Edit: ' + escHtml(resp.handle || resp.name);

                // Show delete button
                document.getElementById('deleteSection').classList.remove('d-none');

                // Update cancel link
                var cancelBtn = document.getElementById('btnCancel');
                if (cancelBtn) cancelBtn.href = 'unit-detail.php?id=' + id;

                // Phase 61 — suppress the multi-person assignment UI when
                // the unit IS a single personal resource. The roster
                // member IS the unit; no add/remove makes sense.
                if (resp.personal_for_member_id) {
                    _renderPersonalUnitCard(id, resp);
                } else {
                    loadPersonnelAssignments(id);
                }
                loadLocationSources(id);
                loadUnitOt(id, resp.personal_for_member_id);

                document.getElementById('loadingSpinner').classList.add('d-none');
            })
            .catch(function (err) {
                showAlert('Failed to load unit: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Load unit types for dropdown ──
    function loadUnitTypes() {
        fetch('api/unit-types.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var select = document.getElementById('type');
                if (!select || !data.types) return;
                for (var i = 0; i < data.types.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = data.types[i].id;
                    opt.textContent = data.types[i].name;
                    select.appendChild(opt);
                }
            })
            .catch(function () {});
    }

    // ── Load facilities for dropdown ──
    function loadFacilities() {
        fetch('api/facilities.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var select = document.getElementById('at_facility');
                if (!select) return;
                var facilities = data.facilities || data;
                if (!Array.isArray(facilities)) return;
                for (var i = 0; i < facilities.length; i++) {
                    var f = facilities[i];
                    var opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = f.name + (f.city ? ' (' + f.city + ')' : '');
                    select.appendChild(opt);
                }
            })
            .catch(function () {});
    }

    // ── Load unit statuses for dropdown ──
    function loadUnitStatuses() {
        fetch('api/unit-statuses.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                unitStatuses = data.statuses || [];
                var select = document.getElementById('un_status_id');
                if (!select) return;
                for (var i = 0; i < unitStatuses.length; i++) {
                    var s = unitStatuses[i];
                    if (s.hide === 'y') continue;
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    var dispLabel = '';
                    if (parseInt(s.dispatch, 10) === 0) dispLabel = ' \u2713';
                    else if (parseInt(s.dispatch, 10) === 1) dispLabel = ' \u26A0';
                    else dispLabel = ' \u2717';
                    opt.textContent = s.description + dispLabel;
                    select.appendChild(opt);
                }
            })
            .catch(function () {});
    }

    // ── Status dropdown change handler ──
    function initStatusDropdown() {
        var select = document.getElementById('un_status_id');
        if (select) {
            select.addEventListener('change', updateDispatchBadge);
        }
    }

    function updateDispatchBadge() {
        var select = document.getElementById('un_status_id');
        var badge = document.getElementById('statusDispatchBadge');
        var info = document.getElementById('statusDispatchInfo');
        if (!select || !badge) return;

        var selectedId = parseInt(select.value, 10);
        var status = null;
        for (var i = 0; i < unitStatuses.length; i++) {
            if (parseInt(unitStatuses[i].id, 10) === selectedId) {
                status = unitStatuses[i];
                break;
            }
        }

        if (!status) {
            badge.textContent = '';
            badge.className = 'badge ms-auto';
            if (info) info.textContent = '';
            return;
        }

        var dispatch = parseInt(status.dispatch, 10);
        if (dispatch === 0) {
            badge.textContent = '\u2713 Available';
            badge.className = 'badge bg-success ms-auto';
            if (info) info.textContent = 'Unit can be dispatched to incidents';
        } else if (dispatch === 1) {
            badge.textContent = '\u26A0 Inform Only';
            badge.className = 'badge bg-warning text-dark ms-auto';
            if (info) info.textContent = 'Dispatch allowed but operator will be informed';
        } else {
            badge.textContent = '\u2717 Unavailable';
            badge.className = 'badge bg-danger ms-auto';
            if (info) info.textContent = 'Unit cannot be dispatched';
        }
    }

    // ── Map initialization ──
    function initMap() {
        var container = document.getElementById('editMap');
        if (!container || typeof L === 'undefined') return;

        fetch('api/map-config.php')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                var defLat = cfg.def_lat || 39.8283;
                var defLng = cfg.def_lng || -98.5795;
                var defZoom = cfg.def_zoom || 5;

                map = L.map('editMap', { zoomControl: true }).setView([defLat, defLng], defZoom);

                if (window.MapPrefs) {
                    // Issue #46 — expose all layers on the unit-edit
                    // map so dispatchers can turn on the org's map
                    // overlays (Race markers, Zone 4, precincts,
                    // parade routes, etc.) when clicking to set a
                    // unit's location. Layer choices persist via the
                    // shared newui_map_layers localStorage key.
                    var bl2 = window.MapPrefs.addDefaultBasemap(map);
                    window.MapPrefs.addLayerControl(map, {
                        currentBase: bl2,
                        includeMarkupOverlays: true
                    });
                } else {
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
                }

                map.on('click', function (e) {
                    setMapMarker(e.latlng.lat, e.latlng.lng);
                    setField('lat', e.latlng.lat.toFixed(6));
                    setField('lng', e.latlng.lng.toFixed(6));
                    reverseGeocode(e.latlng.lat, e.latlng.lng);
                });

                setTimeout(function () { map.invalidateSize(); }, 200);
            })
            .catch(function () {
                map = L.map('editMap', { zoomControl: true }).setView([39.8283, -98.5795], 5);
                if (window.MapPrefs) {
                    // Issue #46 — expose all layers on the unit-edit
                    // map so dispatchers can turn on the org's map
                    // overlays (Race markers, Zone 4, precincts,
                    // parade routes, etc.) when clicking to set a
                    // unit's location. Layer choices persist via the
                    // shared newui_map_layers localStorage key.
                    var bl2 = window.MapPrefs.addDefaultBasemap(map);
                    window.MapPrefs.addLayerControl(map, {
                        currentBase: bl2,
                        includeMarkupOverlays: true
                    });
                } else {
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
                }
                map.on('click', function (e) {
                    setMapMarker(e.latlng.lat, e.latlng.lng);
                    setField('lat', e.latlng.lat.toFixed(6));
                    setField('lng', e.latlng.lng.toFixed(6));
                    reverseGeocode(e.latlng.lat, e.latlng.lng);
                });
                setTimeout(function () { map.invalidateSize(); }, 200);
            });
    }

    function setMapMarker(lat, lng) {
        if (!map) return;

        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);

            marker.on('dragend', function () {
                var pos = marker.getLatLng();
                setField('lat', pos.lat.toFixed(6));
                setField('lng', pos.lng.toFixed(6));
                reverseGeocode(pos.lat, pos.lng);
            });
        }

        map.setView([lat, lng], Math.max(map.getZoom(), 14));
    }

    // ── Geocoding ──
    function initGeocode() {
        var btn = document.getElementById('btnGeocode');
        if (!btn) return;

        btn.addEventListener('click', function () {
            forwardGeocode();
        });

        var streetInput = document.getElementById('street');
        if (streetInput) {
            streetInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    forwardGeocode();
                }
            });
        }
    }

    function forwardGeocode() {
        var street = (document.getElementById('street') || {}).value || '';
        var city = (document.getElementById('city') || {}).value || '';
        var state = (document.getElementById('state') || {}).value || '';

        var query = [street, city, state].filter(function (s) { return s.trim() !== ''; }).join(', ');
        if (!query) {
            showAlert('Enter an address to look up', 'warning');
            return;
        }

        var btn = document.getElementById('btnGeocode');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(query) + '&limit=1&countrycodes=us')
            .then(function (r) { return r.json(); })
            .then(function (results) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-search"></i> Lookup';

                if (!results || results.length === 0) {
                    showAlert('No results found for that address', 'warning');
                    return;
                }

                var result = results[0];
                var lat = parseFloat(result.lat);
                var lng = parseFloat(result.lon);

                setField('lat', lat.toFixed(6));
                setField('lng', lng.toFixed(6));
                setMapMarker(lat, lng);

                showAlert('Location found: ' + escHtml(result.display_name), 'success');
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-search"></i> Lookup';
                showAlert('Geocoding failed: ' + escHtml(err.message), 'danger');
            });
    }

    function reverseGeocode(lat, lng) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng)
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (!result || !result.address) return;
                var addr = result.address;

                if (addr.road || addr.house_number) {
                    var streetVal = (addr.house_number ? addr.house_number + ' ' : '') + (addr.road || '');
                    setField('street', streetVal);
                }
                if (addr.city || addr.town || addr.village) {
                    setField('city', addr.city || addr.town || addr.village || '');
                }
                if (addr.state) {
                    var stateVal = addr.state;
                    if (addr['ISO3166-2-lvl4']) {
                        var parts = addr['ISO3166-2-lvl4'].split('-');
                        if (parts.length === 2) stateVal = parts[1];
                    }
                    setField('state', stateVal);
                }
            })
            .catch(function () {});
    }

    // ── Save ──
    function initSave() {
        var saveBtn = document.getElementById('btnSave');
        var saveBtnBottom = document.getElementById('btnSaveBottom');

        if (saveBtn) saveBtn.addEventListener('click', saveUnit);
        if (saveBtnBottom) saveBtnBottom.addEventListener('click', saveUnit);

        // Ctrl+Enter to save
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveUnit();
            }
        });
    }

    function saveUnit() {
        var name = getFieldValue('name');
        var description = getFieldValue('description');

        if (!name) {
            showAlert('Name is required', 'warning');
            document.getElementById('name').focus();
            return;
        }
        if (!description) {
            showAlert('Description is required', 'warning');
            document.getElementById('description').focus();
            return;
        }

        var data = {
            // Identity
            name: name,
            handle: getFieldValue('handle'),
            callsign: getFieldValue('callsign'),
            description: description,
            // Location
            street: getFieldValue('street'),
            city: getFieldValue('city'),
            state: getFieldValue('state'),
            lat: getFieldValue('lat'),
            lng: getFieldValue('lng'),
            type: parseInt(getFieldValue('type') || '0', 10),
            // Contact & Messaging
            phone: getFieldValue('phone'),
            cellphone: getFieldValue('cellphone'),
            contact_name: getFieldValue('contact_name'),
            contact_via: getFieldValue('contact_via'),
            smsg_id: getFieldValue('smsg_id'),
            pager_p: getFieldValue('pager_p'),
            pager_s: getFieldValue('pager_s'),
            send_no: getFieldValue('send_no'),
            // Configuration
            mobile: getCheckboxValue('mobile'),
            multi: getCheckboxValue('multi'),
            direcs: getCheckboxValue('direcs'),
            capab: getFieldValue('capab'),
            at_facility: parseInt(getFieldValue('at_facility') || '0', 10),
            icon_str: getFieldValue('icon_str'),
            other: getFieldValue('other'),
            // Tracking
            tracking_provider: getFieldValue('tracking_provider'),
            // Boundaries
            ring_fence: parseInt(getFieldValue('ring_fence') || '0', 10),
            excl_zone: parseInt(getFieldValue('excl_zone') || '0', 10),
            // CSRF
            csrf_token: getCsrfToken()
        };

        // Status (only in edit mode)
        if (editId > 0) {
            var statusVal = getFieldValue('un_status_id');
            if (statusVal) {
                data.un_status_id = parseInt(statusVal, 10);
                data.status_about = getFieldValue('status_about');
            }
        }

        if (editId > 0) {
            data.id = editId;
        }

        var saveBtn = document.getElementById('btnSave');
        var saveBtnBottom = document.getElementById('btnSaveBottom');
        setBtnLoading(saveBtn, true);
        setBtnLoading(saveBtnBottom, true);

        fetch('api/responder-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            setBtnLoading(saveBtn, false, '<i class="bi bi-check-lg me-1"></i>Save Unit');
            setBtnLoading(saveBtnBottom, false, '<i class="bi bi-check-lg me-1"></i>Save Unit');

            if (result.error) {
                showAlert(escHtml(result.error), 'danger');
                return;
            }

            // If new unit, confirm then redirect to its detail page.
            if (!editId && result.id) {
                showAlert(escHtml(result.message), 'success');
                setTimeout(function () {
                    window.location.href = 'unit-detail.php?id=' + result.id;
                }, 1000);
            } else {
                // Existing unit — the success message used to scroll behind the
                // fixed navbar and out of view on a long form (Eric 2026-07-05).
                // Show a persistent success WITH a clear way back to the list,
                // and jump to the top so both the message and the title-bar
                // buttons are visible.
                var area = document.getElementById('alertArea');
                if (area) {
                    area.innerHTML =
                        '<div class="alert alert-success alert-dismissible fade show d-flex align-items-center flex-wrap gap-2" role="alert">' +
                        '<i class="bi bi-check-circle-fill me-1"></i>' +
                        '<span>' + escHtml(result.message) + '</span>' +
                        '<a href="units.php" class="btn btn-sm btn-success ms-auto">' +
                        '<i class="bi bi-arrow-left me-1"></i>Back to Units</a>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                        '</div>';
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        })
        .catch(function (err) {
            setBtnLoading(saveBtn, false, '<i class="bi bi-check-lg me-1"></i>Save Unit');
            setBtnLoading(saveBtnBottom, false, '<i class="bi bi-check-lg me-1"></i>Save Unit');
            showAlert('Failed to save: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Delete ──
    function initDelete() {
        var btn = document.getElementById('btnDelete');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!editId) return;
            var confirmed = confirm('Are you sure you want to delete this unit? This cannot be undone.');
            if (!confirmed) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting...';

            fetch('api/responder-delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: editId,
                    csrf_token: getCsrfToken()
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete Unit';
                    showAlert(escHtml(data.error), 'danger');
                    return;
                }
                showAlert('Unit deleted. Redirecting...', 'success');
                setTimeout(function () {
                    window.location.href = 'units.php';
                }, 1000);
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete Unit';
                showAlert('Failed to delete: ' + escHtml(err.message), 'danger');
            });
        });
    }

    // ── Helpers ──
    function setField(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        // Issue #42: the State field is now a DB-backed <select>. Route
        // it through the shared helper so a saved value that isn't in
        // states_translator (legacy free-text) is injected as an option
        // instead of silently blanking.
        if (el.tagName === 'SELECT' && window.TCADStates) {
            window.TCADStates.setValue(el, value);
        } else {
            el.value = value || '';
        }
    }

    function getFieldValue(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    function setSelectValue(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = String(value || '');
    }

    function setCheckbox(id, checked) {
        var el = document.getElementById(id);
        if (el) el.checked = checked;
    }

    function getCheckboxValue(id) {
        var el = document.getElementById(id);
        return el && el.checked ? 1 : 0;
    }

    function setBtnLoading(btn, loading, html) {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        } else {
            btn.disabled = false;
            btn.innerHTML = html || 'Save';
        }
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

    // ══════════════════════════════════════════════════════════════
    //  ASSIGNED PERSONNEL
    // ══════════════════════════════════════════════════════════════

    var _selectedMemberId = null;
    var _personnelSearchTimer = null;

    /**
     * Phase 61 — replacement for the Assigned Personnel section when
     * the unit is a personal resource (responder.personal_for_member_id
     * is set). Shows a read-only card explaining the relationship +
     * a link to the member's roster record. The add/remove personnel
     * UI is hidden because there's nothing to manage — the unit IS
     * the member, and they control their own clock-in/out from the
     * navbar user menu / mobile UI / profile.
     */
    function _renderPersonalUnitCard(unitId, resp) {
        var container = document.getElementById('personnelAssignmentsList');
        var badge = document.getElementById('personnelCountBadge');
        if (badge) badge.textContent = '1';

        // Hide the add-personnel form rows that live below the list.
        var addForm = container && container.parentElement
            ? container.parentElement.querySelector('.border-top.pt-2')
            : null;
        if (addForm) addForm.style.display = 'none';

        // Derive a name to display — responder.name follows the
        // "CALLSIGN — FIRST LAST" convention from pu_personal_unit_name().
        var displayName = escHtml(resp.name || resp.handle || ('Member #' + resp.personal_for_member_id));

        var rosterHref = 'roster.php?member=' + encodeURIComponent(resp.personal_for_member_id);

        if (!container) return;
        container.innerHTML =
            '<div class="alert alert-info py-2 px-3 mb-0">'
          +   '<div class="d-flex align-items-center gap-2 mb-1">'
          +     '<i class="bi bi-person-fill-check fs-4 text-primary"></i>'
          +     '<div>'
          +       '<div class="fw-semibold">Personal resource</div>'
          +       '<div class="small text-body-secondary">This unit IS a single member — ' + displayName + '</div>'
          +     '</div>'
          +   '</div>'
          +   '<div class="small mt-2">'
          +     '<a href="' + rosterHref + '" class="btn btn-sm btn-outline-primary">'
          +       '<i class="bi bi-person-lines-fill me-1"></i>View in Roster'
          +     '</a>'
          +     '<span class="text-body-secondary ms-2">'
          +       'They control their own clock-in/out from their navbar menu, profile page, or mobile view.'
          +     '</span>'
          +   '</div>'
          + '</div>';
    }

    function loadPersonnelAssignments(unitId) {
        if (!unitId) return;

        // Load roles for dropdown
        fetch('api/unit-assignments.php?roles=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var sel = document.getElementById('personnelRoleSelect');
                if (sel && data.roles) {
                    sel.innerHTML = '';
                    for (var i = 0; i < data.roles.length; i++) {
                        if (!parseInt(data.roles[i].active, 10)) continue;
                        var opt = document.createElement('option');
                        opt.value = data.roles[i].code;
                        opt.textContent = data.roles[i].name;
                        sel.appendChild(opt);
                    }
                }
            }).catch(function () {});

        // Load current assignments
        fetch('api/unit-assignments.php?responder_id=' + unitId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderPersonnelAssignments(data.assignments || []);
            }).catch(function () {});

        // Wire up search
        var searchBox = document.getElementById('personnelSearchBox');
        if (searchBox && !searchBox._bound) {
            searchBox._bound = true;
            searchBox.addEventListener('input', function () {
                clearTimeout(_personnelSearchTimer);
                var q = this.value.trim();
                var results = document.getElementById('personnelSearchResults');
                _selectedMemberId = null;
                document.getElementById('btnAssignPersonnelEdit').disabled = true;

                if (q.length < 2) {
                    results.style.display = 'none';
                    return;
                }
                _personnelSearchTimer = setTimeout(function () {
                    fetch('api/members.php?search=' + encodeURIComponent(q) + '&limit=10', { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var members = data.members || data.data || [];
                            if (!members.length) {
                                results.innerHTML = '<div class="text-body-secondary text-center small py-2">No results</div>';
                                results.style.display = 'block';
                                return;
                            }
                            var html = '';
                            for (var i = 0; i < members.length; i++) {
                                var m = members[i];
                                var name = (m.first_name || '') + ' ' + (m.last_name || '');
                                html += '<div class="px-2 py-1 border-bottom small personnel-search-item" style="cursor:pointer" data-id="' + m.id + '" data-name="' + escHtml(name.trim()) + '">';
                                html += '<strong>' + escHtml(name.trim()) + '</strong>';
                                if (m.callsign) html += ' <span class="text-body-secondary">(' + escHtml(m.callsign) + ')</span>';
                                html += '</div>';
                            }
                            results.innerHTML = html;
                            results.style.display = 'block';

                            var items = results.querySelectorAll('.personnel-search-item');
                            for (var j = 0; j < items.length; j++) {
                                items[j].addEventListener('click', function () {
                                    _selectedMemberId = parseInt(this.getAttribute('data-id'), 10);
                                    searchBox.value = this.getAttribute('data-name');
                                    results.style.display = 'none';
                                    document.getElementById('btnAssignPersonnelEdit').disabled = false;
                                });
                            }
                        });
                }, 300);
            });

            // Close dropdown on blur
            searchBox.addEventListener('blur', function () {
                setTimeout(function () {
                    document.getElementById('personnelSearchResults').style.display = 'none';
                }, 200);
            });
        }

        // Wire up assign button
        var assignBtn = document.getElementById('btnAssignPersonnelEdit');
        if (assignBtn && !assignBtn._bound) {
            assignBtn._bound = true;
            assignBtn.addEventListener('click', function () {
                if (!_selectedMemberId) return;
                var role = document.getElementById('personnelRoleSelect').value;
                doAssignPersonnel(_selectedMemberId, role, unitId);
            });
        }
    }

    function doAssignPersonnel(memberId, role, unitId, force) {
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
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data.error) { showAlert(escHtml(data.error), 'danger'); return; }
              if (data.needs_confirmation) {
                  if (confirm(data.message)) {
                      doAssignPersonnel(memberId, role, unitId, true);
                  }
                  return;
              }
              // Clear search
              document.getElementById('personnelSearchBox').value = '';
              _selectedMemberId = null;
              document.getElementById('btnAssignPersonnelEdit').disabled = true;
              // Reload both personnel and location sources (personnel affects location)
              loadPersonnelAssignments(unitId);
              loadLocationSources(unitId);
              showAlert('Personnel assigned. Location sources updated.', 'success');
          });
    }

    function renderPersonnelAssignments(assignments) {
        var container = document.getElementById('personnelAssignmentsList');
        var badge = document.getElementById('personnelCountBadge');
        if (!container) return;

        if (badge) badge.textContent = assignments.length;

        if (!assignments.length) {
            container.innerHTML = '<div class="text-center text-body-secondary py-2 small">No personnel assigned</div>';
            return;
        }

        var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.8rem"><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Since</th><th></th></tr></thead><tbody>';
        for (var i = 0; i < assignments.length; i++) {
            var a = assignments[i];
            var statusCls = a.status === 'active' ? 'bg-success' : 'bg-warning text-dark';
            var since = a.assigned_at ? a.assigned_at.substring(0, 10) : '--';
            html += '<tr>';
            html += '<td>' + escHtml(a.member_name || '') + (a.member_callsign ? ' <span class="text-body-secondary">(' + escHtml(a.member_callsign) + ')</span>' : '') + '</td>';
            html += '<td><span class="badge bg-secondary">' + escHtml(a.role) + '</span></td>';
            html += '<td><span class="badge ' + statusCls + '">' + escHtml(a.status) + '</span></td>';
            html += '<td class="small text-body-secondary">' + since + '</td>';
            html += '<td class="text-end text-nowrap">';
            // GH #84 — per-crew OwnTracks tracking token (shared module). Only
            // when the row has a member_id (skip legacy/orphan rows).
            if (a.member_id) {
                html += '<button type="button" class="btn btn-sm btn-outline-info py-0 px-1 me-1 ot-token-btn" data-member-id="' + a.member_id + '" data-idx="' + i + '" title="OwnTracks tracking token for this crew member"><i class="bi bi-geo-alt"></i></button>';
            }
            html += '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 release-personnel-btn" data-id="' + a.id + '" title="Release"><i class="bi bi-person-dash"></i></button>';
            html += '</td>';
            html += '</tr>';
            // Collapsible OwnTracks token panel for this crew member (lazy-mounted).
            html += '<tr class="ot-panel-row" data-idx="' + i + '" style="display:none">'
                  + '<td colspan="5" class="bg-body-tertiary">'
                  + '<div class="ot-panel small py-1"><div class="text-body-secondary">Loading…</div></div>'
                  + '</td></tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;

        // GH #84 — OwnTracks token toggle per crew member. Lazy-mounts the shared
        // provisioning panel on first expand so the unit page offers the same
        // per-member token provisioning as the roster.
        var otBtns = container.querySelectorAll('.ot-token-btn');
        for (var k = 0; k < otBtns.length; k++) {
            otBtns[k].addEventListener('click', function () {
                var idx = this.getAttribute('data-idx');
                var mid = parseInt(this.getAttribute('data-member-id'), 10);
                var panelRow = container.querySelector('.ot-panel-row[data-idx="' + idx + '"]');
                if (!panelRow) return;
                var isShown = panelRow.style.display !== 'none';
                panelRow.style.display = isShown ? 'none' : '';
                if (!isShown && window.OTProvision && !panelRow.getAttribute('data-mounted')) {
                    OTProvision.mount(panelRow.querySelector('.ot-panel'), mid);
                    panelRow.setAttribute('data-mounted', '1');
                }
            });
        }

        // Bind release buttons
        var relBtns = container.querySelectorAll('.release-personnel-btn');
        for (var j = 0; j < relBtns.length; j++) {
            relBtns[j].addEventListener('click', function () {
                var assignId = parseInt(this.getAttribute('data-id'), 10);
                if (!confirm('Release this person from the unit?')) return;
                fetch('api/unit-assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'release', id: assignId, csrf_token: getCsrfToken() })
                }).then(function () {
                    var unitId = getEditId();
                    loadPersonnelAssignments(unitId);
                    loadLocationSources(unitId); // Refresh — personnel bindings will be deactivated
                });
            });
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  LOCATION SOURCES — Unified Priority List
    // ══════════════════════════════════════════════════════════════

    var _locationSources = [];
    var _availableProviders = [];

    function loadLocationSources(unitId) {
        // Load available providers for the "add" dropdown
        fetch('api/location.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _availableProviders = data.providers || [];
                var sel = document.getElementById('addSourceProvider');
                if (sel) {
                    sel.innerHTML = '<option value="">-- add source --</option>';
                    for (var i = 0; i < _availableProviders.length; i++) {
                        var p = _availableProviders[i];
                        if (!parseInt(p.enabled, 10)) continue;
                        var opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.name;
                        sel.appendChild(opt);
                    }
                }
            }).catch(function () {});

        // Load this unit's location bindings
        fetch('api/location.php?responder_id=' + unitId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Get bindings from unit detail API instead (has more info)
                return fetch('api/responder-detail.php?id=' + unitId, { credentials: 'same-origin' });
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var bindings = data.location_bindings || [];
                var personnel = data.unit_personnel || [];

                // Phase 64: render the resolved live position (may be null)
                renderCurrentPosition(data.resolved_location || null);

                // Build unified source list
                _locationSources = [];
                for (var i = 0; i < bindings.length; i++) {
                    var b = bindings[i];
                    // Find personnel name if this is a personnel-sourced binding
                    var sourceName = 'Unit';
                    if (b.source === 'personnel' && b.assignment_id) {
                        for (var p = 0; p < personnel.length; p++) {
                            if (parseInt(personnel[p].assignment_id, 10) === parseInt(b.assignment_id, 10)) {
                                sourceName = personnel[p].member_name || 'Personnel';
                                break;
                            }
                        }
                        if (sourceName === 'Unit') sourceName = 'Personnel';
                    }

                    _locationSources.push({
                        id: b.id,
                        provider_id: b.provider_id,
                        provider_name: b.provider_name || b.provider_code || '?',
                        provider_icon: b.provider_icon || 'bi-geo-alt',
                        provider_color: b.provider_color || '#666',
                        identifier: b.unit_identifier || '',
                        priority: parseInt(b.priority, 10) || 50,
                        active: parseInt(b.active, 10),
                        source: b.source || 'manual',
                        source_name: sourceName,
                        max_age: parseInt(b.max_age_seconds, 10) || 300
                    });
                }

                // Add manual update as a virtual source (always last)
                _locationSources.push({
                    id: 0,
                    provider_id: 0,
                    provider_name: 'Manual Update',
                    provider_icon: 'bi-pencil-square',
                    provider_color: '#6c757d',
                    identifier: '(edit page)',
                    priority: 999,
                    active: 1,
                    source: 'manual',
                    source_name: 'Admin',
                    max_age: 0
                });

                // Sort by priority
                _locationSources.sort(function (a, b) { return a.priority - b.priority; });

                renderLocationSources();
            })
            .catch(function () {
                var body = document.getElementById('locationSourcesBody');
                if (body) body.innerHTML = '<tr><td colspan="8" class="text-center text-body-secondary py-2">No location sources configured</td></tr>';
            });

        // Wire up add button
        var addBtn = document.getElementById('btnAddSource');
        if (addBtn && !addBtn._bound) {
            addBtn._bound = true;
            addBtn.addEventListener('click', addLocationSource);
        }

        // Phase 64: wire up "Center map on current position" button (idempotent)
        var centerBtn = document.getElementById('btnCenterOnCurrent');
        if (centerBtn && !centerBtn._bound) {
            centerBtn._bound = true;
            centerBtn.addEventListener('click', function () {
                if (!lastResolvedLocation || !map) return;
                var lat = parseFloat(lastResolvedLocation.lat);
                var lng = parseFloat(lastResolvedLocation.lng);
                if (isNaN(lat) || isNaN(lng)) return;
                map.setView([lat, lng], Math.max(map.getZoom(), 14));
            });
        }
    }

    // Phase 64: render the live (resolved) position card + drop a non-editable
    // marker on the map distinct from the editable home-base marker.
    function renderCurrentPosition(loc) {
        lastResolvedLocation = loc;

        var card = document.getElementById('currentPositionCard');
        var empty = document.getElementById('currentPositionEmpty');

        if (!loc || loc.lat === null || loc.lng === null) {
            if (card) card.classList.add('d-none');
            if (empty) empty.classList.remove('d-none');
            removeLiveMarker();
            return;
        }

        var lat = parseFloat(loc.lat);
        var lng = parseFloat(loc.lng);
        if (isNaN(lat) || isNaN(lng)) {
            if (card) card.classList.add('d-none');
            if (empty) empty.classList.remove('d-none');
            removeLiveMarker();
            return;
        }

        if (card) card.classList.remove('d-none');
        if (empty) empty.classList.add('d-none');

        var coords = document.getElementById('currentPositionCoords');
        if (coords) coords.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);

        var icon = document.getElementById('currentPositionIcon');
        if (icon) {
            icon.className = 'bi ' + (loc.icon || 'bi-geo-alt-fill') + ' fs-5';
            icon.style.color = loc.color || '#3366FF';
        }

        var prov = document.getElementById('currentPositionProvider');
        if (prov) {
            prov.textContent = (loc.provider_name || 'Unknown') + ' via ' + (loc.unit_identifier || '');
        }

        var ageEl = document.getElementById('currentPositionAge');
        if (ageEl) {
            ageEl.textContent = (loc.age_seconds !== null && loc.age_seconds !== undefined)
                ? formatPositionAge(loc.age_seconds) + ' ago'
                : 'age unknown';
        }

        var badge = document.getElementById('currentPositionFreshBadge');
        if (badge) {
            var isFresh = parseInt(loc.is_fresh, 10) === 1;
            badge.className = 'badge ms-1 ' + (isFresh ? 'bg-success' : 'bg-warning text-dark');
            badge.textContent = isFresh ? 'Fresh' : 'Stale';
        }

        dropLiveMarker(lat, lng, loc);
    }

    function formatPositionAge(sec) {
        sec = parseInt(sec, 10);
        if (isNaN(sec) || sec < 0) return 'just now';
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h';
        return Math.floor(sec / 86400) + 'd';
    }

    function dropLiveMarker(lat, lng, loc) {
        if (!map || typeof L === 'undefined') return;

        var color = (loc && loc.color) ? loc.color : '#FF6600';
        var html = '<div style="background:' + color + ';border:2px solid #fff;border-radius:50%;'
                 + 'width:14px;height:14px;box-shadow:0 0 0 2px ' + color + '66"></div>';
        var divIcon = L.divIcon({
            className: 'live-position-marker',
            html: html,
            iconSize: [14, 14],
            iconAnchor: [7, 7]
        });

        if (livePositionMarker) {
            livePositionMarker.setLatLng([lat, lng]);
            livePositionMarker.setIcon(divIcon);
        } else {
            livePositionMarker = L.marker([lat, lng], {
                icon: divIcon,
                interactive: true,
                keyboard: false,
                zIndexOffset: -100  // sit below the editable home-base marker
            }).addTo(map);
        }

        var label = (loc && loc.provider_name ? loc.provider_name : 'Live position');
        livePositionMarker.bindTooltip(label, { direction: 'top', offset: [0, -8] });
    }

    function removeLiveMarker() {
        if (livePositionMarker && map) {
            map.removeLayer(livePositionMarker);
        }
        livePositionMarker = null;
    }

    function renderLocationSources() {
        var body = document.getElementById('locationSourcesBody');
        var badge = document.getElementById('locationSourceCount');
        if (!body) return;

        var activeCount = 0;
        for (var c = 0; c < _locationSources.length; c++) {
            if (_locationSources[c].active) activeCount++;
        }
        if (badge) badge.textContent = activeCount;

        if (_locationSources.length === 0) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-body-secondary py-2">No location sources. Add one below.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < _locationSources.length; i++) {
            var s = _locationSources[i];
            var isVirtual = (s.id === 0); // Manual update row
            var rowClass = s.active ? '' : ' class="text-body-secondary text-decoration-line-through"';
            var sourceIcon = s.source === 'personnel' ? '<i class="bi bi-person-fill text-info me-1" title="From assigned personnel"></i>' : '';
            var ageText = s.max_age > 0 ? _formatAge(s.max_age) : '<span class="text-body-tertiary">&infin;</span>';

            html += '<tr' + rowClass + ' data-source-idx="' + i + '" draggable="true">';

            // Drag handle
            html += '<td class="text-center text-body-tertiary loc-drag-handle" style="cursor:grab" title="Drag to reorder">';
            html += '<i class="bi bi-grip-vertical"></i>';
            html += '</td>';

            // Priority
            html += '<td class="text-center fw-bold">' + s.priority + '</td>';

            // Provider
            html += '<td>';
            html += '<i class="bi ' + escHtml(s.provider_icon) + ' me-1" style="color:' + escHtml(s.provider_color) + '"></i>';
            html += escHtml(s.provider_name);
            html += '</td>';

            // Identifier
            html += '<td class="font-monospace small">' + escHtml(s.identifier) + '</td>';

            // Source
            html += '<td class="small">' + sourceIcon + escHtml(s.source_name) + '</td>';

            // Max age
            html += '<td class="text-center small">' + ageText + '</td>';

            // Enable toggle
            html += '<td class="text-center">';
            if (!isVirtual) {
                html += '<div class="form-check form-switch d-inline-block">';
                html += '<input class="form-check-input loc-source-toggle" type="checkbox" data-id="' + s.id + '"' + (s.active ? ' checked' : '') + '>';
                html += '</div>';
            }
            html += '</td>';

            // Delete
            html += '<td class="text-center">';
            if (!isVirtual && s.source !== 'personnel') {
                html += '<button class="btn btn-sm btn-outline-danger py-0 px-1 loc-source-delete" data-id="' + s.id + '" title="Remove"><i class="bi bi-x-lg"></i></button>';
            }
            html += '</td>';

            html += '</tr>';
        }
        body.innerHTML = html;

        // Bind toggle handlers
        var toggles = body.querySelectorAll('.loc-source-toggle');
        for (var t = 0; t < toggles.length; t++) {
            toggles[t].addEventListener('change', function () {
                var bindId = parseInt(this.getAttribute('data-id'), 10);
                var enabled = this.checked ? 1 : 0;
                var action = enabled ? 'bind' : 'unbind';
                // For unbind, we deactivate; for bind, we re-activate
                if (!enabled) {
                    fetch('api/location.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ action: 'unbind', id: bindId, csrf_token: getCsrfToken() })
                    }).then(function () { loadLocationSources(getEditId()); });
                } else {
                    // Re-activate by calling bind with existing data
                    fetch('api/location.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            action: 'save_provider',
                            id: bindId,
                            enabled: 1,
                            csrf_token: getCsrfToken()
                        })
                    }).then(function () { loadLocationSources(getEditId()); });
                }
            });
        }

        // Bind delete handlers
        var delBtns = body.querySelectorAll('.loc-source-delete');
        for (var d = 0; d < delBtns.length; d++) {
            delBtns[d].addEventListener('click', function () {
                var bindId = parseInt(this.getAttribute('data-id'), 10);
                if (!confirm('Remove this location source?')) return;
                fetch('api/location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'unbind', id: bindId, csrf_token: getCsrfToken() })
                }).then(function () { loadLocationSources(getEditId()); });
            });
        }

        // ── Drag-and-drop reordering ──
        var dragSrcIdx = null;

        var rows = body.querySelectorAll('tr[draggable]');
        for (var r = 0; r < rows.length; r++) {
            rows[r].addEventListener('dragstart', function (e) {
                dragSrcIdx = parseInt(this.getAttribute('data-source-idx'), 10);
                this.style.opacity = '0.4';
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', dragSrcIdx);
            });

            rows[r].addEventListener('dragend', function () {
                this.style.opacity = '1';
                // Remove all drag-over highlights
                var allRows = body.querySelectorAll('tr');
                for (var x = 0; x < allRows.length; x++) {
                    allRows[x].style.borderTop = '';
                    allRows[x].style.borderBottom = '';
                }
            });

            rows[r].addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                // Highlight drop position
                var allRows = body.querySelectorAll('tr');
                for (var x = 0; x < allRows.length; x++) {
                    allRows[x].style.borderTop = '';
                    allRows[x].style.borderBottom = '';
                }
                this.style.borderTop = '2px solid var(--bs-primary)';
            });

            rows[r].addEventListener('drop', function (e) {
                e.preventDefault();
                var dropIdx = parseInt(this.getAttribute('data-source-idx'), 10);
                if (dragSrcIdx === null || dragSrcIdx === dropIdx) return;

                // Reorder the array
                var moved = _locationSources.splice(dragSrcIdx, 1)[0];
                _locationSources.splice(dropIdx, 0, moved);

                // Reassign priorities: 10, 20, 30, ... based on new order
                for (var p = 0; p < _locationSources.length; p++) {
                    _locationSources[p].priority = (p + 1) * 10;
                }

                // Save new priorities to server
                savePriorityOrder();

                // Re-render
                renderLocationSources();
            });
        }
    }

    function savePriorityOrder() {
        // Save each binding's new priority
        var promises = [];
        for (var i = 0; i < _locationSources.length; i++) {
            var s = _locationSources[i];
            if (s.id === 0) continue; // Skip virtual manual update row

            // Use the location API save_provider to update priority
            // Actually we need a dedicated endpoint — for now update via bind (re-bind with new priority)
            (function (src) {
                fetch('api/location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'bind',
                        responder_id: getEditId(),
                        provider_id: src.provider_id,
                        unit_identifier: src.identifier,
                        priority: src.priority,
                        csrf_token: getCsrfToken()
                    })
                });
            })(_locationSources[i]);
        }
    }

    function addLocationSource() {
        var providerSel = document.getElementById('addSourceProvider');
        var identInput = document.getElementById('addSourceIdentifier');
        var priInput = document.getElementById('addSourcePriority');

        var providerId = parseInt(providerSel ? providerSel.value : 0, 10);
        var identifier = identInput ? identInput.value.trim() : '';
        var priority = parseInt(priInput ? priInput.value : 50, 10);
        var unitId = getEditId();

        if (!providerId || !identifier || !unitId) {
            showAlert('Select a provider and enter an identifier', 'warning');
            return;
        }

        fetch('api/location.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
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
              if (identInput) identInput.value = '';
              if (priInput) priInput.value = '50';
              loadLocationSources(unitId);
          });
    }

    function _formatAge(seconds) {
        if (!seconds || seconds <= 0) return '--';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
        return Math.floor(seconds / 3600) + 'h';
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Boot ──
    document.addEventListener('DOMContentLoaded', init);

    // ── Phase 117 (GH #84, a beta tester/SAG) — unit-level OwnTracks device ──────
    // A unit (vehicle) can carry its OWN OwnTracks device, tracked independent
    // of crew. Reuses the Phase-89 device-token + unit_location_bindings stack
    // via api/owntracks-config.php's unit_status / unit_link / unit_revoke.
    var _unitOtWired = false;
    function loadUnitOt(id, isPersonal) {
        var card = document.getElementById('unitOtCard');
        if (!card) return;
        if (isPersonal) { card.classList.add('d-none'); return; }
        if (!_unitOtWired) {
            _unitOtWired = true;
            var f = document.getElementById('unitOtNewFile');
            var q = document.getElementById('unitOtNewQr');
            var u = document.getElementById('unitOtNewUrl');
            if (f) f.addEventListener('click', function () { _unitOtProvision('file'); });
            if (q) q.addEventListener('click', function () { _unitOtProvision('qr'); });
            if (u) u.addEventListener('click', function () { _unitOtProvision('url'); });
        }
        fetch('api/owntracks-config.php?action=unit_status&responder_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.error) { card.classList.add('d-none'); return; }
                card.classList.remove('d-none');
                var tidEl = document.getElementById('unitOtTid');
                if (d.tid) { tidEl.textContent = 'TID ' + d.tid; tidEl.classList.remove('d-none'); }
                else { tidEl.classList.add('d-none'); }
                var warn = document.getElementById('unitOtWarn');
                if (d.provider_enabled === false) {
                    warn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>The OwnTracks provider is currently <strong>disabled</strong>. Enable it in Settings &rarr; Location Providers, or reports from this device will not be accepted.';
                    warn.classList.remove('d-none');
                } else {
                    warn.classList.add('d-none');
                }
                _renderUnitOtTokens(d.tokens || [], id);
            })
            .catch(function () { card.classList.add('d-none'); });
    }
    function _renderUnitOtTokens(tokens, id) {
        var box = document.getElementById('unitOtTokens');
        if (!box) return;
        if (!tokens.length) {
            box.innerHTML = '<div class="text-body-secondary fst-italic">No device set up yet. Use a New button above to configure a phone or tablet for this unit.</div>';
            return;
        }
        var html = '<table class="table table-sm mb-0" style="font-size:0.78rem"><thead><tr>'
                 + '<th>Device</th><th>Created</th><th>Last report</th><th class="text-end">Action</th></tr></thead><tbody>';
        for (var i = 0; i < tokens.length; i++) {
            var t = tokens[i];
            html += '<tr>'
                 + '<td>' + escHtml(t.label || ('#' + t.id)) + '</td>'
                 + '<td class="text-body-secondary">' + escHtml((t.created_at || '').substring(0, 10)) + '</td>'
                 + '<td class="text-body-secondary">' + escHtml(t.last_used_at ? t.last_used_at.substring(0, 16) : '—') + '</td>'
                 + '<td class="text-end"><button type="button" class="btn btn-xs btn-outline-danger unit-ot-revoke" data-token-id="' + parseInt(t.id, 10) + '" title="Revoke this device"><i class="bi bi-x-octagon"></i></button></td>'
                 + '</tr>';
        }
        html += '</tbody></table>';
        box.innerHTML = html;
        var btns = box.querySelectorAll('.unit-ot-revoke');
        for (var k = 0; k < btns.length; k++) {
            btns[k].addEventListener('click', function () {
                var tokenId = parseInt(this.getAttribute('data-token-id'), 10);
                if (!confirm('Revoke this device? The phone/tablet will stop reporting this unit\'s location on its next post.')) return;
                fetch('api/owntracks-config.php?action=unit_revoke', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ responder_id: id, token_id: tokenId, csrf_token: getCsrfToken() })
                }).then(function (r) { return r.json(); })
                  .then(function (d) {
                      if (d && d.error) { showAlert(escHtml(d.error), 'danger'); return; }
                      loadUnitOt(id, false);
                  });
            });
        }
    }
    function _unitOtProvision(mode) {
        var id = getEditId();
        if (!id) return;
        var base = 'api/owntracks-config.php?action=unit_link&responder_id=' + encodeURIComponent(id) + '&mode=' + encodeURIComponent(mode);
        if (mode === 'file') {
            // Binary .otrc download — point a hidden iframe at it, then refresh.
            var ifr = document.createElement('iframe');
            ifr.style.display = 'none';
            ifr.src = base;
            document.body.appendChild(ifr);
            setTimeout(function () { if (ifr.parentNode) document.body.removeChild(ifr); loadUnitOt(id, false); }, 2500);
            return;
        }
        fetch(base, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.error) { showAlert('Provision failed: ' + escHtml((d && d.error) || 'unknown'), 'danger'); return; }
                if (mode === 'qr') {
                    var w = window.open('', '_blank');
                    if (w) {
                        w.document.write('<html><head><title>OwnTracks unit device QR</title>'
                            + '<scr' + 'ipt src="assets/vendor/qrcode/qrcode-generator.min.js"></scr' + 'ipt></head>'
                            + '<body style="margin:24px;font-family:system-ui;max-width:560px;line-height:1.4">'
                            + '<h3>OwnTracks device &mdash; TID ' + (d.tid || '') + '</h3><div id="qr"></div>'
                            + '<div style="background:#e7f3ff;border:1px solid #5aa9e6;border-radius:6px;padding:10px 12px;margin-top:14px">'
                            + '<strong>iOS:</strong> OwnTracks app &rarr; Settings &rarr; Configuration &rarr; Scan this QR. '
                            + '<strong>Android:</strong> use the <em>New: File</em> button instead (OwnTracks Android has no QR scanner).</div>'
                            + '<p style="color:#666;font-size:.8em;word-break:break-all;margin-top:12px">' + (d.qr_text || '') + '</p>'
                            + '<scr' + 'ipt>var t=qrcode(0,"L");t.addData(' + JSON.stringify(d.qr_text || '') + ');t.make();document.getElementById("qr").innerHTML=t.createSvgTag(6);</scr' + 'ipt>'
                            + '</body></html>');
                        w.document.close();
                    } else {
                        prompt('Pop-up blocked. Copy this URL and open it on the device (iOS):', d.qr_text || '');
                    }
                } else {
                    prompt('OwnTracks setup URL (TID ' + (d.tid || '') + ') — open on the device:', d.url || '');
                }
                loadUnitOt(id, false);
            })
            .catch(function (err) { showAlert('Provision failed: ' + escHtml(err.message), 'danger'); });
    }

})();
