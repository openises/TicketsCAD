/**
 * NewUI v4.0 - Facility Edit / Create Page Logic
 *
 * Handles: form population (edit mode), map interaction, geocoding,
 * type/status dropdowns, save, delete.
 */

(function () {
    'use strict';

    // ── State ──
    var map = null;
    var facilityMarker = null;
    var editId = null;      // null = new, number = editing
    var facTypes = [];       // Loaded from API
    var facStatuses = [];    // Loaded from API

    // GH#125 — Sun-Sat, matching facility_decode_hours()'s 0-6 indexing.
    var HOURS_DAY_LABELS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    // ── Initialise on DOM ready ──
    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        editId = getEditId();
        initMap();
        loadDropdowns();
        buildHoursTable();
        bindEvents();

        if (editId) {
            document.getElementById('pageTitle').innerHTML =
                '<i class="bi bi-hospital text-primary me-2"></i>Edit Facility';
            document.title = 'Edit Facility — Tickets NewUI';
            document.getElementById('btnDelete').classList.remove('d-none');
            document.getElementById('loadingSpinner').classList.remove('d-none');
            loadFacility(editId);
        } else {
            // New mode — focus the name field
            setTimeout(function () {
                var nameEl = document.getElementById('name');
                if (nameEl) nameEl.focus();
            }, 300);
        }
    });

    function getEditId() {
        var params = new URLSearchParams(window.location.search);
        var id = parseInt(params.get('id'), 10);
        return id > 0 ? id : null;
    }

    function getCsrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
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

    // ── Map ──
    function initMap() {
        // Shared MapDefaults loader (assets/js/map-defaults.js) — one
        // canonical source for default coords / zoom. Falls back internally
        // on fetch failure so this caller doesn't need a .catch.
        var loader = (window.MapDefaults && window.MapDefaults.load)
            ? window.MapDefaults.load()
            : Promise.resolve({ lat: 44.9778, lng: -93.2650, zoom: 12 });
        loader.then(function (d) {
            map = L.map('facilityMap', { zoomControl: true }).setView([d.lat, d.lng], d.zoom);
            if (window.MapPrefs) { window.MapPrefs.addDefaultBasemap(map); }
            else { L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map); }
            map.on('click', function (e) {
                setMarker(e.latlng.lat, e.latlng.lng);
                reverseGeocode(e.latlng.lat, e.latlng.lng);
            });
            setTimeout(function () { map.invalidateSize(); }, 200);
        });
    }

    function setMarker(lat, lng) {
        document.getElementById('lat').value = lat.toFixed(6);
        document.getElementById('lng').value = lng.toFixed(6);

        if (facilityMarker) {
            facilityMarker.setLatLng([lat, lng]);
        } else {
            facilityMarker = L.marker([lat, lng], { draggable: true }).addTo(map);
            facilityMarker.on('dragend', function (e) {
                var pos = e.target.getLatLng();
                document.getElementById('lat').value = pos.lat.toFixed(6);
                document.getElementById('lng').value = pos.lng.toFixed(6);
                reverseGeocode(pos.lat, pos.lng);
            });
        }

        map.setView([lat, lng], Math.max(map.getZoom(), 14));
    }

    // ── Geocoding ──
    function geocodeAddress() {
        var street = document.getElementById('street').value.trim();
        var city = document.getElementById('city').value.trim();
        var state = document.getElementById('state').value.trim();

        if (!street && !city) {
            showAlert('Enter a street address or city to look up.', 'warning');
            return;
        }

        var query = [street, city, state].filter(Boolean).join(', ');

        Geocode.search({ q: query, limit: 1, viewbox: Geocode.viewboxFromMap(map) })
            .then(function (res) {
                if (!res.ok) {
                    showAlert(res.message, 'warning');
                    return;
                }
                if (res.results.length === 0) {
                    showAlert('Address not found. Try a different format or click the map.', 'warning');
                    return;
                }
                var r = res.results[0];
                var lat = parseFloat(r.lat);
                var lng = parseFloat(r.lon);
                setMarker(lat, lng);

                // Fill in address fields from geocoder
                var addr = r.address || {};
                if (addr.city || addr.town || addr.village) {
                    document.getElementById('city').value = addr.city || addr.town || addr.village || '';
                }
                if (addr.state) {
                    // Try to match state select option
                    var stateSelect = document.getElementById('state');
                    var stateVal = addr.state;
                    for (var i = 0; i < stateSelect.options.length; i++) {
                        if (stateSelect.options[i].text === stateVal || stateSelect.options[i].value === stateVal) {
                            stateSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            });
    }

    function reverseGeocode(lat, lng) {
        Geocode.reverse(lat, lng)
            .then(function (res) {
                if (!res.ok || !res.results.length) return;
                var addr = res.results[0].address;

                var streetEl = document.getElementById('street');
                var cityEl = document.getElementById('city');

                // Only fill if currently empty
                if (streetEl && !streetEl.value.trim()) {
                    var road = addr.road || '';
                    var house = addr.house_number || '';
                    streetEl.value = house ? house + ' ' + road : road;
                }
                if (cityEl && !cityEl.value.trim()) {
                    cityEl.value = addr.city || addr.town || addr.village || '';
                }
                if (addr.state) {
                    var stateSelect = document.getElementById('state');
                    for (var i = 0; i < stateSelect.options.length; i++) {
                        if (stateSelect.options[i].text === addr.state || stateSelect.options[i].value === addr.state) {
                            stateSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            });
        // Reverse geocode failure is non-critical — the pin is already placed.
        // No .catch: Geocode.reverse() resolves with { ok:false }, so one would
        // only hide a bug in the handler above.
    }

    // ── Load dropdown options ──
    function loadDropdowns() {
        // Load facility types
        fetch('api/facilities.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // We need type list — extract unique types from facilities data or load separately
                // For now, populate from categories
            })
            .catch(function () {});

        // Load fac_types and fac_status via a small helper
        // We'll fetch the facility list API which gives us categories,
        // but for type/status dropdowns we need the raw lookup tables.
        // Use a simple inline fetch to get those.
        loadFacTypes();
        loadFacStatuses();
        loadStates();
    }

    function loadFacTypes() {
        // Issue #29 (a beta tester + a beta tester, 2026-07-02) — populate from the
        // authoritative fac_types lookup rather than deriving from
        // existing facilities. On a fresh install with zero facilities
        // the derived approach left the dropdown empty even when types
        // WERE configured in settings.
        fetch('api/facilities.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var select = document.getElementById('type_id');
                if (!select) return;
                (data.fac_types || []).forEach(function (t) {
                    var opt = document.createElement('option');
                    opt.value = t.id;
                    opt.textContent = t.name;
                    select.appendChild(opt);
                });
            })
            .catch(function () {});
    }

    function loadFacStatuses() {
        // Issue #29 — same fix as loadFacTypes above.
        fetch('api/facilities.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var select = document.getElementById('status_id');
                if (!select) return;
                (data.fac_statuses || []).forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name;
                    select.appendChild(opt);
                });
            })
            .catch(function () {});
    }

    function loadStates() {
        // Issue #42 (a beta tester 2026-07-03): the state dropdown was
        // hardcoded to 51 US codes, so Canadian provinces (or any
        // other row an admin adds to states_translator) never showed
        // up here — even after the admin added them. Fetch the live
        // list from api/incident-types.php (which already returns
        // { code, name } from the states_translator table for the
        // new-incident form). Falls back to the hardcoded US list if
        // the API call fails, so a broken fetch doesn't leave an
        // empty dropdown.
        var select = document.getElementById('state');
        if (!select) return;

        var fallbackUS = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
            'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
            'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
            'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
            'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC'
        ];

        function appendStates(list) {
            list.forEach(function (st) {
                var opt = document.createElement('option');
                // st can be either a string code (fallback path) or
                // an object {code, name} (API path).
                if (typeof st === 'string') {
                    opt.value = st;
                    opt.textContent = st;
                } else {
                    opt.value = st.code || '';
                    opt.textContent = st.name ? (st.code + ' — ' + st.name) : (st.code || '');
                }
                select.appendChild(opt);
            });
        }

        fetch('api/incident-types.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && Array.isArray(data.states) && data.states.length) {
                    appendStates(data.states);
                } else {
                    appendStates(fallbackUS);
                }
            })
            .catch(function () { appendStates(fallbackUS); });
    }

    // ── Load existing facility for editing ──
    // GH#125 — build the 7 static rows once (day name + enabled checkbox +
    // two time inputs). loadFacility()/saveFacility() only ever read/write
    // the row's own inputs — this never rebuilds the table.
    function buildHoursTable() {
        var tbody = document.getElementById('hoursTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < 7; i++) {
            html += '<tr>'
                + '<td><input type="checkbox" class="form-check-input hours-enabled" data-day="' + i + '" tabindex="19"></td>'
                + '<td>' + HOURS_DAY_LABELS[i] + '</td>'
                + '<td><input type="time" class="form-control form-control-sm hours-open" data-day="' + i + '" value="09:00" tabindex="19"></td>'
                + '<td><input type="time" class="form-control form-control-sm hours-close" data-day="' + i + '" value="17:00" tabindex="19"></td>'
                + '</tr>';
        }
        tbody.innerHTML = html;
    }

    // hoursWeek: array of 7 {enabled, open, close} — facility_decode_hours()'s
    // own return shape, so the server and client agree on indexing (0=Sun).
    function populateHoursTable(hoursWeek) {
        if (!Array.isArray(hoursWeek)) return;
        for (var i = 0; i < 7 && i < hoursWeek.length; i++) {
            var day = hoursWeek[i] || {};
            var enabledEl = document.querySelector('.hours-enabled[data-day="' + i + '"]');
            var openEl = document.querySelector('.hours-open[data-day="' + i + '"]');
            var closeEl = document.querySelector('.hours-close[data-day="' + i + '"]');
            if (enabledEl) enabledEl.checked = !!day.enabled;
            if (openEl) openEl.value = day.open || '09:00';
            if (closeEl) closeEl.value = day.close || '17:00';
        }
    }

    function collectHoursWeek() {
        var week = [];
        for (var i = 0; i < 7; i++) {
            var enabledEl = document.querySelector('.hours-enabled[data-day="' + i + '"]');
            var openEl = document.querySelector('.hours-open[data-day="' + i + '"]');
            var closeEl = document.querySelector('.hours-close[data-day="' + i + '"]');
            week.push({
                enabled: !!(enabledEl && enabledEl.checked),
                open: (openEl && openEl.value) || '09:00',
                close: (closeEl && closeEl.value) || '17:00'
            });
        }
        return week;
    }

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

                var f = data.facility;
                document.getElementById('facilityId').value = f.id;

                // Populate form fields
                setVal('name', f.name);
                setVal('handle', f.handle);
                setVal('description', f.description);
                setVal('street', f.street);
                setVal('city', f.city);
                setVal('contact_name', f.contact_name);
                setVal('contact_email', f.contact_email);
                setVal('contact_phone', f.contact_phone);
                setVal('capab', f.capab);
                setVal('beds_a', f.beds_a || 0);
                setVal('beds_o', f.beds_o || 0);
                setVal('beds_info', f.beds_info);
                setVal('bed_auto_mode', f.bed_auto_mode || 'manual');
                setVal('status_about', f.status_about);
                setVal('lat', f.lat != null ? f.lat.toFixed(6) : '');
                setVal('lng', f.lng != null ? f.lng.toFixed(6) : '');

                // GH#125
                setVal('access_rules', f.access_rules);
                setVal('security_reqs', f.security_reqs);
                var direcsEl = document.getElementById('direcs');
                if (direcsEl) direcsEl.checked = (f.direcs === undefined || f.direcs === null) ? true : !!f.direcs;
                populateHoursTable(f.hours_week);

                // Select dropdowns (wait a tick for options to load)
                setTimeout(function () {
                    selectOption('type_id', f.type_id);
                    selectOption('status_id', f.status_id);
                    selectOption('state', f.state);
                }, 500);

                // Place marker on map
                if (f.lat && f.lng) {
                    setTimeout(function () {
                        if (map) {
                            setMarker(f.lat, f.lng);
                        }
                    }, 600);
                }

                document.getElementById('loadingSpinner').classList.add('d-none');

                // Update cancel link
                var cancelBtn = document.getElementById('btnCancel');
                if (cancelBtn) {
                    cancelBtn.href = 'facility-detail.php?id=' + f.id;
                }
            })
            .catch(function (err) {
                showAlert('Failed to load facility: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Bind events ──
    function bindEvents() {
        // Geocode button
        var geocodeBtn = document.getElementById('btnGeocode');
        if (geocodeBtn) {
            geocodeBtn.addEventListener('click', geocodeAddress);
        }

        // Save buttons
        var saveBtn = document.getElementById('btnSave');
        var saveBottomBtn = document.getElementById('btnSaveBottom');
        if (saveBtn) saveBtn.addEventListener('click', saveFacility);
        if (saveBottomBtn) saveBottomBtn.addEventListener('click', saveFacility);

        // Delete button
        var deleteBtn = document.getElementById('btnDelete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', deleteFacility);
        }

        // Keyboard shortcut: Ctrl+Enter to save
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveFacility();
            }
        });
    }

    // ── Save facility ──
    function saveFacility() {
        // Client-side validation
        var name = document.getElementById('name').value.trim();
        var description = document.getElementById('description').value.trim();

        if (!name) {
            showAlert('Facility name is required.', 'warning');
            document.getElementById('name').focus();
            return;
        }
        if (!description) {
            showAlert('Description is required.', 'warning');
            document.getElementById('description').focus();
            return;
        }

        var body = {
            csrf_token: getCsrfToken(),
            name: name,
            handle: document.getElementById('handle').value.trim(),
            description: description,
            street: document.getElementById('street').value.trim(),
            city: document.getElementById('city').value.trim(),
            state: document.getElementById('state').value,
            lat: document.getElementById('lat').value || null,
            lng: document.getElementById('lng').value || null,
            type_id: parseInt(document.getElementById('type_id').value, 10) || 0,
            status_id: parseInt(document.getElementById('status_id').value, 10) || 0,
            contact_name: document.getElementById('contact_name').value.trim(),
            contact_email: document.getElementById('contact_email').value.trim(),
            contact_phone: document.getElementById('contact_phone').value.trim(),
            capab: document.getElementById('capab').value.trim(),
            beds_a: parseInt(document.getElementById('beds_a').value, 10) || 0,
            beds_o: parseInt(document.getElementById('beds_o').value, 10) || 0,
            beds_info: document.getElementById('beds_info').value.trim(),
            bed_auto_mode: (document.getElementById('bed_auto_mode') || {value:'manual'}).value,
            status_about: document.getElementById('status_about').value.trim(),
            // GH#125
            access_rules: document.getElementById('access_rules').value.trim(),
            security_reqs: document.getElementById('security_reqs').value.trim(),
            direcs: document.getElementById('direcs').checked ? 1 : 0,
            hours_week: collectHoursWeek()
        };

        // Include id if editing
        var idVal = document.getElementById('facilityId').value;
        if (idVal) {
            body.id = parseInt(idVal, 10);
        }

        // Disable save buttons
        var btns = document.querySelectorAll('#btnSave, #btnSaveBottom');
        btns.forEach(function (b) { b.disabled = true; });

        fetch('api/facility-save.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btns.forEach(function (b) { b.disabled = false; });

            if (data.errors) {
                showAlert(data.errors.join('<br>'), 'danger');
                return;
            }
            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }

            showAlert(data.message || 'Facility saved.', 'success');

            // Redirect to detail page after short delay
            if (data.id) {
                setTimeout(function () {
                    window.location.href = 'facility-detail.php?id=' + data.id;
                }, 800);
            }
        })
        .catch(function (err) {
            btns.forEach(function (b) { b.disabled = false; });
            showAlert('Save failed: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Delete facility ──
    function deleteFacility() {
        if (!editId) return;
        if (!confirm('Are you sure you want to delete this facility? It will be hidden from active use.')) {
            return;
        }

        // Issue #52 (a beta tester 2026-07-03): api/facility-save.php's DELETE
        // branch requires csrf_token via query string OR X-CSRF-Token
        // header; the old fetch sent neither, so every delete failed
        // with "Invalid CSRF token". Send it via the header — keeps
        // the token out of the URL / Apache access log.
        fetch('api/facility-save.php?id=' + editId, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': getCsrfToken() }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }
            showAlert('Facility deleted.', 'success');
            setTimeout(function () {
                window.location.href = 'facilities.php';
            }, 800);
        })
        .catch(function (err) {
            showAlert('Delete failed: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Utilities ──
    function setVal(id, value) {
        var el = document.getElementById(id);
        if (el) el.value = value || '';
    }

    function selectOption(id, value) {
        var el = document.getElementById(id);
        if (!el || !value) return;
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].value == value) {
                el.selectedIndex = i;
                return;
            }
        }
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show small py-2" role="alert">' +
            message +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>' +
            '</div>';
        // Scroll to alert
        area.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

})();
