/**
 * NewUI v4.0 - New Incident Page Logic
 *
 * Handles: form population, map interaction, geocoding,
 * responder selection, validation, and submission.
 */

(function () {
    'use strict';

    // ── State ──
    var formData = {};          // Loaded lookup data (types, facilities, responders, states)
    var incidentMarker = null;  // Leaflet marker for incident location
    var map = null;             // Leaflet map instance
    var selectedResponders = new Set();

    // ── Initialise on DOM ready ──
    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initMap();
        loadFormData();
        bindEvents();
        setDefaultTimes();

        // Auto-focus the Description field so the operator can start typing
        // the caller narrative immediately. On Tab-out (blur), the regex
        // auto-match runs against in_types.match_pattern — first hit wins
        // and populates Incident Type, which triggers Severity auto-fill
        // and the Protocol panel above the map. Keyboard flow from here:
        //   Tab → Street → Lookup → City → State → Contact → Phone → Responder search
        //   Shift+Tab → Scope → Severity → Type
        // Operators who prefer to pick the type manually can Shift+Tab back
        // before typing description, or click the dropdown.
        var descEl = document.getElementById('description');
        if (descEl) {
            setTimeout(function () { descEl.focus(); }, 300);
        }
    });

    // ── Theme toggle (reuses pattern from dashboard) ──
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
        // Use the shared MapDefaults loader (assets/js/map-defaults.js,
        // included globally via inc/navbar.php). One canonical source for
        // map default coords / zoom across every Leaflet map in the app.
        // MapDefaults.load() resolves to {lat, lng, zoom} — fetch failures
        // already fall back to the hardcoded Minneapolis center inside
        // the loader, so this caller doesn't need its own .catch().
        var loader = (window.MapDefaults && window.MapDefaults.load)
            ? window.MapDefaults.load()
            : Promise.resolve({ lat: 44.9778, lng: -93.2650, zoom: 10 });
        loader.then(function (d) {
            map = L.map('incidentMap', { zoomControl: true }).setView([d.lat, d.lng], d.zoom);
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

        if (incidentMarker) {
            incidentMarker.setLatLng([lat, lng]);
        } else {
            incidentMarker = L.marker([lat, lng], { draggable: true }).addTo(map);
            incidentMarker.on('dragend', function (e) {
                var pos = e.target.getLatLng();
                document.getElementById('lat').value = pos.lat.toFixed(6);
                document.getElementById('lng').value = pos.lng.toFixed(6);
                reverseGeocode(pos.lat, pos.lng);
                checkProximityWarnings(pos.lat, pos.lng);
            });
        }

        map.setView([lat, lng], Math.max(map.getZoom(), 14));

        // Check for nearby location warnings
        checkProximityWarnings(lat, lng);
    }

    function clearMarker() {
        document.getElementById('lat').value = '';
        document.getElementById('lng').value = '';
        if (incidentMarker) {
            map.removeLayer(incidentMarker);
            incidentMarker = null;
        }
        // Clear any proximity warnings
        renderProximityWarnings([]);
    }

    // ── Geocoding (using Nominatim — free, no API key) ──
    // Normalize common street-spelling variants that Nominatim is
    // strict about. Word-form ordinals (First, Second…) fail outright;
    // digit-form (1st, 2nd…) works. Same for spelled-out cardinal
    // directions vs single letters. Generate a list of variants to
    // try in order; first hit wins.
    function geocodeVariants(query) {
        var out = [query];
        var ord = [
            [/\bfirst\b/gi,     '1st'],
            [/\bsecond\b/gi,    '2nd'],
            [/\bthird\b/gi,     '3rd'],
            [/\bfourth\b/gi,    '4th'],
            [/\bfifth\b/gi,     '5th'],
            [/\bsixth\b/gi,     '6th'],
            [/\bseventh\b/gi,   '7th'],
            [/\beighth\b/gi,    '8th'],
            [/\bninth\b/gi,     '9th'],
            [/\btenth\b/gi,     '10th'],
            [/\beleventh\b/gi,  '11th'],
            [/\btwelfth\b/gi,   '12th']
        ];
        var hadOrd = false;
        var normOrd = query;
        for (var i = 0; i < ord.length; i++) {
            if (ord[i][0].test(normOrd)) {
                normOrd = normOrd.replace(ord[i][0], ord[i][1]);
                hadOrd = true;
            }
        }
        if (hadOrd && out.indexOf(normOrd) === -1) out.push(normOrd);
        return out;
    }

    function geocodeAddress() {
        var street = document.getElementById('street').value.trim();
        var city   = document.getElementById('city').value.trim();
        var state  = document.getElementById('state').value.trim();

        if (!street && !city) {
            showAlert('Enter a street address or city to look up.', 'warning');
            return;
        }

        var baseQuery = [street, city, state].filter(Boolean).join(', ');
        var variants = geocodeVariants(baseQuery);

        function buildUrl(q) {
            var u = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=1&q=' + encodeURIComponent(q);
            if (map) {
                var bounds = map.getBounds();
                u += '&viewbox=' + bounds.getWest().toFixed(4) + ',' + bounds.getNorth().toFixed(4) +
                     ',' + bounds.getEast().toFixed(4) + ',' + bounds.getSouth().toFixed(4);
                u += '&bounded=0';
            }
            return u;
        }

        // Try each variant in order; resolve with first non-empty result.
        function tryNext(i) {
            if (i >= variants.length) {
                showAlert('Address not found. Try a different format or click the map.', 'warning');
                return;
            }
            fetch(buildUrl(variants[i]))
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    if (!results || results.length === 0) {
                        // Try the next variant after a small delay so we don't
                        // flood Nominatim (their TOS asks for ~1 rps).
                        setTimeout(function () { tryNext(i + 1); }, 600);
                        return;
                    }
                    handleGeocodeResult(results[0], variants[i] !== variants[0]);
                })
                .catch(function () { setTimeout(function () { tryNext(i + 1); }, 600); });
        }
        tryNext(0);
    }

    function handleGeocodeResult(r, fromNormalized) {
        setMarker(parseFloat(r.lat), parseFloat(r.lon));

        var foundCity = '';
        // Fill city/state from forward geocode result so user can just Tab over them
        if (r.address) {
            var cityEl  = document.getElementById('city');
            var stateEl = document.getElementById('state');

            foundCity = r.address.city || r.address.town || r.address.village || '';
            if (foundCity) cityEl.value = foundCity;

            if (r.address.state) {
                var options = stateEl.options;
                for (var i = 0; i < options.length; i++) {
                    if (options[i].textContent.toLowerCase() === r.address.state.toLowerCase() ||
                        options[i].value.toLowerCase() === r.address.state.toLowerCase()) {
                        stateEl.value = options[i].value;
                        break;
                    }
                }
            }

            // Auto-fill cross street from geocoder neighbourhood/suburb
            var crossParts = [];
            if (r.address.neighbourhood) crossParts.push(r.address.neighbourhood);
            if (r.address.suburb && r.address.suburb !== foundCity) crossParts.push(r.address.suburb);
            var crossEl = document.getElementById('address_about');
            if (crossParts.length && crossEl && !crossEl.value.trim()) {
                crossEl.value = crossParts.join(' / ');
            }

            // Auto-fill zipcode if field is visible
            var zipEl = document.getElementById('zipcode');
            if (zipEl && r.address.postcode) {
                zipEl.value = r.address.postcode;
            }
        }

        if (fromNormalized) {
            // Tell the dispatcher we matched a normalized form so the
            // displayed street can be updated to the canonical version.
            showAlert('Geocoded using normalized spelling (e.g. "First" → "1st").', 'info');
        }

        // Geocode succeeded — restore normal tab order
        restoreTabOrder();

        // Move focus to city and select text so user can type over it instantly
        var cityField = document.getElementById('city');
        cityField.focus();
        cityField.select();
    }

    function reverseGeocode(lat, lng) {
        var url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng;

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (!result || !result.address) return;
                var addr = result.address;

                var streetEl = document.getElementById('street');
                var cityEl   = document.getElementById('city');
                var stateEl  = document.getElementById('state');

                // Always update address fields when user clicks or drags on the map
                var num  = addr.house_number || '';
                var road = addr.road || '';
                streetEl.value = (num + ' ' + road).trim();

                cityEl.value = addr.city || addr.town || addr.village || '';

                if (addr.state) {
                    var options = stateEl.options;
                    for (var i = 0; i < options.length; i++) {
                        if (options[i].textContent.toLowerCase() === addr.state.toLowerCase() ||
                            options[i].value.toLowerCase() === addr.state.toLowerCase()) {
                            stateEl.value = options[i].value;
                            break;
                        }
                    }
                }

                // Cross street / neighbourhood
                var crossParts = [];
                var foundCity = addr.city || addr.town || addr.village || '';
                if (addr.neighbourhood) crossParts.push(addr.neighbourhood);
                if (addr.suburb && addr.suburb !== foundCity) crossParts.push(addr.suburb);
                var crossEl = document.getElementById('address_about');
                if (crossParts.length && crossEl) {
                    crossEl.value = crossParts.join(' / ');
                }

                // Zipcode
                var zipEl = document.getElementById('zipcode');
                if (zipEl && addr.postcode) {
                    zipEl.value = addr.postcode;
                }
            })
            .catch(function () {
                // Reverse geocode is best-effort — silent fail
            });
    }

    // ── Load form data from API ──
    function loadFormData() {
        fetch('api/incident-types.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                formData = data;
                populateTypes(data.types || []);
                populateFacilities(data.facilities || []);
                populateResponders(data.responders || []);
                populateStates(data.states || []);
                populateSignals(data.signals || []);
                populateMajorIncidents(data.major_incidents || []);
            })
            .catch(function (err) {
                showAlert('Failed to load form data: ' + err.message, 'danger');
            });
    }

    function populateTypes(types) {
        var sel = document.getElementById('in_types_id');
        // Group by group field
        var groups = {};
        var ungrouped = [];

        types.forEach(function (t) {
            var item = { id: t.id, label: t.type + (t.description ? ' — ' + t.description : ''), data: t };
            if (t.group) {
                if (!groups[t.group]) groups[t.group] = [];
                groups[t.group].push(item);
            } else {
                ungrouped.push(item);
            }
        });

        // Add ungrouped first
        ungrouped.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.label;
            opt.dataset.severity = item.data.set_severity || 0;
            opt.dataset.protocol = item.data.protocol || '';
            opt.dataset.matchPattern = item.data.match_pattern || '';
            sel.appendChild(opt);
        });

        // Then grouped
        Object.keys(groups).sort().forEach(function (groupName) {
            var optgroup = document.createElement('optgroup');
            optgroup.label = groupName;
            groups[groupName].forEach(function (item) {
                var opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.label;
                opt.dataset.severity = item.data.set_severity || 0;
                opt.dataset.protocol = item.data.protocol || '';
                opt.dataset.matchPattern = item.data.match_pattern || '';
                optgroup.appendChild(opt);
            });
            sel.appendChild(optgroup);
        });
    }

    function populateFacilities(facilities) {
        var sels = [document.getElementById('facility'), document.getElementById('rec_facility')];
        sels.forEach(function (sel) {
            facilities.forEach(function (f) {
                var opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.name + (f.type ? ' (' + f.type + ')' : '');
                sel.appendChild(opt);
            });
        });
        // Phase 115 (Eric 2026-07-06) — the dashboard facilities widget's
        // "Incident@" button links here with ?facility=<id>. Pre-select the
        // "Incident at Facility" dropdown once its options exist.
        try {
            var params = new URLSearchParams(window.location.search);
            var facId = params.get('facility');
            var facSel = document.getElementById('facility');
            if (facId && facSel) facSel.value = facId;
        } catch (e) { /* URLSearchParams unsupported — non-fatal */ }
    }

    function populateResponders(responders) {
        var container = document.getElementById('responderList');
        if (responders.length === 0) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3">No responders available</div>';
            return;
        }

        var html = '';
        responders.forEach(function (r) {
            var activeCount = parseInt(r.active_assignments) || 0;
            var badgeClass, badgeText;
            if (activeCount > 0) {
                badgeClass = 'bg-warning text-dark';
                badgeText = 'Assigned (' + activeCount + ')';
            } else if (r.status && r.status.toLowerCase() !== 'available') {
                badgeClass = 'bg-secondary';
                badgeText = r.status;
            } else {
                badgeClass = 'bg-success';
                badgeText = 'Available';
            }
            // tabindex="0" makes each row reachable via keyboard once Tab
            // lands in the responder list. Space toggles selection (handled
            // by the list-level keydown). Arrow keys move focus between rows.
            // role="option" + aria-selected support the listbox semantics.
            html += '<label class="responder-item d-flex align-items-center gap-2 px-2 py-1 border-bottom" ' +
                'tabindex="0" role="option" aria-selected="false" data-id="' + r.id + '">' +
                '<input type="checkbox" class="form-check-input responder-check" value="' + r.id + '" tabindex="-1">' +
                '<span class="fw-semibold small">' + escHtml(r.name) + '</span>' +
                (r.handle ? '<span class="text-body-secondary small">' + escHtml(r.handle) + '</span>' : '') +
                '<span class="ms-auto badge ' + badgeClass + ' small">' + escHtml(badgeText) + '</span>' +
                '</label>';
        });
        container.innerHTML = html;

        // Bind checkbox changes (still works via mouse click on the checkbox)
        container.querySelectorAll('.responder-check').forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (this.checked) {
                    selectedResponders.add(parseInt(this.value));
                } else {
                    selectedResponders.delete(parseInt(this.value));
                }
                var label = this.closest('.responder-item');
                if (label) label.setAttribute('aria-selected', this.checked ? 'true' : 'false');
                updateAssignedCount();
            });
        });
    }

    function populateStates(states) {
        var sel = document.getElementById('state');
        states.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.code;
            opt.textContent = s.name;
            sel.appendChild(opt);
        });
    }

    function populateSignals(signals) {
        var sel = document.getElementById('signal');
        if (!sel) return;
        signals.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.description || s.name || ('Signal ' + s.id);
            sel.appendChild(opt);
        });
    }

    function populateMajorIncidents(majors) {
        var sel = document.getElementById('major_incident');
        if (!sel) return;
        majors.forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.id;
            // a beta tester beta 2026-06-29 — majors live in their own table
            // (newui_major_incidents) with `name` as the canonical
            // human label. Phase 99p's incident_number/scope fallback
            // was inherited from an earlier wrong-table query that
            // searched tickets — neither column exists on majors.
            var label = m.name || m.scope || m.description || ('Major Incident #' + m.id);
            opt.textContent = label;
            sel.appendChild(opt);
        });
    }

    // ── Patient Management ──
    var patientIndex = 0;

    function addPatient() {
        patientIndex++;
        var container = document.getElementById('patientList');
        var div = document.createElement('div');
        div.className = 'patient-entry card card-body p-2 mb-2';
        div.dataset.index = patientIndex;
        div.innerHTML =
            '<div class="d-flex align-items-center justify-content-between mb-1">' +
                '<strong class="small">Patient #' + patientIndex + '</strong>' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-patient" title="Remove">' +
                    '<i class="bi bi-x-lg"></i>' +
                '</button>' +
            '</div>' +
            '<div class="row g-2">' +
                '<div class="col-md-6">' +
                    '<input type="text" class="form-control form-control-sm" name="patient_name_' + patientIndex + '" placeholder="Name" data-sensitive="true">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<div class="input-group input-group-sm">' +
                        '<input type="text" class="form-control form-control-sm patient-dob-input" name="patient_dob_' + patientIndex + '" placeholder="DOB" data-sensitive="true" title="Accepts 10/12/70, 101270, 12/1964, Dec 1964, 1964">' +
                        '<span class="input-group-text bg-body-tertiary text-body-secondary small patient-dob-age d-none" style="min-width:48px; padding:0.25rem 0.4rem;"></span>' +
                    '</div>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<select class="form-select form-select-sm" name="patient_gender_' + patientIndex + '">' +
                        '<option value="0">—</option>' +
                        '<option value="1">Male</option>' +
                        '<option value="2">Female</option>' +
                        '<option value="3">Other</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-12">' +
                    '<textarea class="form-control form-control-sm" name="patient_desc_' + patientIndex + '" rows="1" placeholder="Condition / notes" data-sensitive="true"></textarea>' +
                '</div>' +
            '</div>';
        container.appendChild(div);

        // Remove button
        div.querySelector('.btn-remove-patient').addEventListener('click', function () {
            div.remove();
            updatePatientCount();
        });

        // 2026-06-28 (Eric beta) — partial-DOB parser. On blur,
        // normalizes whatever the user typed (101270, 12/1964,
        // "Dec 1964", 1964, etc) into an ISO-ish display + computes
        // an age badge next to the input. Form submit reads the
        // canonical ISO via data-iso to send 1964-12-00 form.
        if (window.DobHelper) {
            var dobIn   = div.querySelector('.patient-dob-input');
            var dobAge  = div.querySelector('.patient-dob-age');
            window.DobHelper.bindInput(dobIn, dobAge);
        }

        updatePatientCount();

        // Focus the name field
        div.querySelector('input[name^="patient_name"]').focus();
    }

    function updatePatientCount() {
        var count = document.querySelectorAll('.patient-entry').length;
        var badge = document.getElementById('patientCount');
        badge.textContent = count;
        badge.className = 'badge ms-auto ' + (count > 0 ? 'bg-danger' : 'bg-secondary');
    }

    // ── Call History Search (with Constituent Lookup) ──
    function searchCallHistory() {
        var phone = document.getElementById('phone').value.trim();
        var street = document.getElementById('street').value.trim();
        var container = document.getElementById('callHistoryResults');

        if (!phone && !street) {
            container.innerHTML = '<span class="text-body-secondary small">Enter a phone number or address first</span>';
            return;
        }

        container.innerHTML = '<div class="text-center py-2"><span class="spinner-border spinner-border-sm"></span> Searching...</div>';

        var params = new URLSearchParams();
        if (phone) params.set('phone', phone);
        if (street) params.set('street', street);

        // Run both constituent lookup and call history in parallel
        var constituentPromise = phone
            ? fetch('api/constituents.php?phone=' + encodeURIComponent(phone))
                .then(function (r) { return r.json(); })
                .catch(function () { return { constituents: [] }; })
            : Promise.resolve({ constituents: [] });

        var historyPromise = fetch('api/call-history.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .catch(function () { return { results: [] }; });

        Promise.all([constituentPromise, historyPromise])
            .then(function (results) {
                var constituents = results[0].constituents || [];
                var data = results[1];
                var html = '';

                // Show constituent matches first (contact info + warnings)
                if (constituents.length > 0) {
                    html += '<div class="alert alert-info py-1 px-2 mb-2 small">';
                    html += '<strong><i class="bi bi-person-lines-fill me-1"></i>Contact Found</strong>';
                    constituents.forEach(function (c) {
                        html += '<div class="mt-1">';
                        html += '<strong>' + escHtml(c.contact) + '</strong>';
                        var addr = [c.street, c.city, c.state].filter(Boolean).join(', ');
                        if (addr) html += ' &mdash; ' + escHtml(addr);
                        html += '</div>';
                        if (c.miscellaneous) {
                            html += '<div class="alert alert-warning py-1 px-2 mt-1 mb-0">';
                            html += '<i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(c.miscellaneous);
                            html += '</div>';
                        }
                    });
                    html += '</div>';
                }

                // Show call history
                if (!data.results || data.results.length === 0) {
                    if (constituents.length === 0) {
                        container.innerHTML = '<span class="text-body-secondary small">No previous calls or contacts found</span>';
                        document.getElementById('callHistoryCount').textContent = '0';
                        return;
                    }
                    // Just constituent, no history
                    document.getElementById('callHistoryCount').textContent = constituents.length;
                    document.getElementById('callHistoryCount').className = 'badge ms-auto bg-info';
                    container.innerHTML = html;
                    return;
                }

                var totalCount = (data.results ? data.results.length : 0) + constituents.length;
                document.getElementById('callHistoryCount').textContent = totalCount;
                document.getElementById('callHistoryCount').className = 'badge ms-auto bg-info';

                html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0 small">' +
                    '<thead><tr><th>Date</th><th>Type</th><th>Scope</th><th>Status</th></tr></thead><tbody>';
                data.results.forEach(function (r) {
                    var statusClass = r.status === 2 ? 'text-success' : (r.status === 3 ? 'text-warning' : 'text-body-secondary');
                    var statusText = r.status === 2 ? 'Open' : (r.status === 3 ? 'Scheduled' : 'Closed');
                    html += '<tr>' +
                        '<td>' + escHtml(r.date || '') + '</td>' +
                        '<td>' + escHtml(r.incident_type || '') + '</td>' +
                        '<td>' + escHtml(r.scope || '') + '</td>' +
                        '<td class="' + statusClass + '">' + statusText + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
                container.innerHTML = html;
            })
            .catch(function () {
                container.innerHTML = '<span class="text-danger small">Failed to search call history</span>';
            });
    }

    function updateAssignedCount() {
        var badge = document.getElementById('assignedCount');
        var n = selectedResponders.size;
        badge.textContent = n + ' selected';
        badge.className = 'badge ms-auto ' + (n > 0 ? 'bg-success' : 'bg-primary');
    }

    // ── Event bindings ──
    function bindEvents() {
        // Submit buttons (top and bottom)
        document.getElementById('btnSubmit').addEventListener('click', submitForm);
        document.getElementById('btnSubmitBottom').addEventListener('click', submitForm);

        // Reset
        document.getElementById('btnReset').addEventListener('click', resetForm);

        // Geocode lookup
        document.getElementById('btnGeocode').addEventListener('click', geocodeAddress);

        // Clear coordinates
        document.getElementById('btnClearCoords').addEventListener('click', clearMarker);

        // Enter key on street field triggers geocode
        document.getElementById('street').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                geocodeAddress();
            }
        });

        // Phase 41-fix (2026-06-27, a beta tester): wire the saved-places
        // typeahead on the street input. Debounced 250ms; only fires for
        // 2+ chars; results live in the #streetPlacesDropdown div that
        // sits below the input-group inside a position-relative parent.
        // Click a result → fill the address fields + drop the map marker.
        bindPlacesTypeahead();

        // Phase 41-fix-2 (2026-06-27, Eric): constituent lookup on phone
        // blur. Fires when the dispatcher tabs out of #phone. Looks up
        // api/constituents.php?phone=X. Single match → auto-fill Reported
        // By (if empty) + show Caller Info panel. Multi-match → show a
        // small picker below the phone input. The Caller Info panel
        // surfaces the `miscellaneous` field as a warning callout per
        // Eric's "popular callers / problem callers" use case.
        bindConstituentLookup();

        // Incident type change — show protocol panel + auto-set severity
        document.getElementById('in_types_id').addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            var protocol = opt.dataset.protocol || '';
            var autoSev  = parseInt(opt.dataset.severity) || 0;

            // Small inline hint under the dropdown
            var protocolEl = document.getElementById('protocolText');

            // Protocol panel in right column (above map)
            var panel = document.getElementById('protocolPanel');
            var content = document.getElementById('protocolContent');

            if (protocol) {
                protocolEl.innerHTML = '<i class="bi bi-info-circle text-info"></i> Protocol available — see right panel';
                content.innerHTML = '<div class="small">' + formatProtocol(protocol) + '</div>';
                panel.classList.remove('d-none');
            } else {
                protocolEl.innerHTML = '';
                panel.classList.add('d-none');
                content.innerHTML = '';
            }

            if (autoSev > 0) {
                // in_types.set_severity is a legacy 1-5 scale; the Severity dropdown is 0-2
                // (Normal / Elevated / Critical). Map the 1-5 value onto 0-2 so the auto-fill
                // selects a real option — previously set_severity >= 3 set a nonexistent value
                // and left the dropdown blank. 1->Normal, 2-3->Elevated, 4-5->Critical.
                var mappedSev = autoSev <= 1 ? 0 : (autoSev <= 3 ? 1 : 2);
                document.getElementById('severity').value = mappedSev;
            }
        });

        // Dismiss protocol panel
        document.getElementById('btnCloseProtocol').addEventListener('click', function () {
            document.getElementById('protocolPanel').classList.add('d-none');
        });

        // Dismiss proximity warnings panel
        var btnDismissWarnings = document.getElementById('btnDismissWarnings');
        if (btnDismissWarnings) {
            btnDismissWarnings.addEventListener('click', function () {
                document.getElementById('proximityWarningPanel').classList.add('d-none');
            });
        }

        // Status change — show/hide booked date
        document.getElementById('status').addEventListener('change', function () {
            var bookedGroup = document.getElementById('bookedDateGroup');
            bookedGroup.style.display = this.value === '3' ? '' : 'none';
        });

        // ── Smart city/state: when user edits city, clear state and re-route tab to Lookup ──
        var cityEl = document.getElementById('city');
        var stateEl = document.getElementById('state');
        var cityOriginal = '';

        // Track what the geocoder set so we know if user changed it
        cityEl.addEventListener('focus', function () {
            cityOriginal = this.value;
        });

        cityEl.addEventListener('input', function () {
            // User is typing in city — they're correcting the geocoder
            // Clear state so it doesn't keep the wrong one
            if (this.value !== cityOriginal) {
                stateEl.value = '';
                // Re-route tab: skip state, go to Lookup so user can re-geocode
                stateEl.setAttribute('tabindex', '-1');
                document.getElementById('btnGeocode').setAttribute('tabindex', '8');
            }
        });

        // When state gets focus (via click), restore its tabindex
        stateEl.addEventListener('focus', function () {
            this.setAttribute('tabindex', '8');
        });

        // After a successful geocode, restore normal tab order
        // (handled in geocodeAddress and reverseGeocode by resetting tabindexes)

        // Add Patient button
        document.getElementById('btnAddPatient').addEventListener('click', addPatient);

        // Call History search
        document.getElementById('btnSearchHistory').addEventListener('click', searchCallHistory);

        // New Major Incident button
        document.getElementById('btnNewMajor').addEventListener('click', function () {
            // Open new incident page in new tab with major=1 flag
            window.open('new-incident.php?major=1', '_blank');
        });

        // ── Responder search + keyboard navigation ───────────────────────
        // Search box (tabindex=12, after Phone=11): typing filters the
        // visible list. The search element exists in static HTML so the
        // bind is safe to do once at page load.
        var searchEl  = document.getElementById('responderSearch');
        var listEl    = document.getElementById('responderList');

        function filterResponders() {
            var query = (searchEl.value || '').toLowerCase().trim();
            var items = listEl.querySelectorAll('.responder-item');
            items.forEach(function (item) {
                var text = (item.textContent || '').toLowerCase();
                var match = (query === '' || text.indexOf(query) !== -1);
                // Toggle Bootstrap's d-none class instead of inline style.
                // The row carries class="responder-item d-flex …", and
                // Bootstrap's d-flex has display:flex !important — which
                // beats inline style.display='none'. d-none uses
                // !important too, so it correctly overrides d-flex.
                item.classList.toggle('d-none', !match);
            });
        }
        // Bind both 'input' (modern) and 'keyup' (fallback for any browser
        // that swallows input events on framework-managed fields). Belt and
        // braces — Eric reported the search wasn't filtering on the demo
        // install 2026-05-21; defensive coverage so the next session sees
        // the new behaviour even if one event flavour misbehaves.
        searchEl.addEventListener('input',  filterResponders);
        searchEl.addEventListener('keyup',  filterResponders);
        searchEl.addEventListener('change', filterResponders);

        // From search: Tab lands on the first VISIBLE responder item. We
        // override the default Tab behaviour so an empty search (which
        // shows all rows) and a filtered list both feel the same.
        searchEl.addEventListener('keydown', function (e) {
            if (e.key === 'Tab' && !e.shiftKey) {
                var first = listEl.querySelector('.responder-item:not(.d-none)');
                if (first) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        // Inside the responder list: Space toggles selection on the
        // focused item. Up/Down arrows move focus between visible items.
        // Shift+Tab from any item returns to the search input. Tab from
        // the LAST visible item moves to the next form control (default
        // browser behaviour — we don't intercept).
        listEl.addEventListener('keydown', function (e) {
            var current = e.target.closest('.responder-item');
            if (!current) return;

            // Space (or Enter) toggles selection
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                var cb = current.querySelector('.responder-check');
                if (cb) {
                    cb.checked = !cb.checked;
                    // Trigger change event so the existing handler updates
                    // selectedResponders + the assigned-count badge.
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }

            // Up / Down arrow: move focus between VISIBLE items
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var visible = listEl.querySelectorAll('.responder-item:not(.d-none)');
                var arr = Array.prototype.slice.call(visible);
                var idx = arr.indexOf(current);
                if (idx < 0) return;
                var next = (e.key === 'ArrowDown')
                    ? arr[Math.min(idx + 1, arr.length - 1)]
                    : arr[Math.max(idx - 1, 0)];
                if (next) next.focus();
                return;
            }

            // Shift+Tab returns to the search input regardless of which
            // item is focused, so the operator can type another search to
            // add more units without mouse interaction.
            if (e.key === 'Tab' && e.shiftKey) {
                e.preventDefault();
                searchEl.focus();
                searchEl.select();
                return;
            }
        });

        // Zip code toggle
        var chkZip = document.getElementById('chkShowZip');
        if (chkZip) {
            chkZip.addEventListener('change', function () {
                var zipGroup = document.getElementById('zipcodeGroup');
                if (this.checked) {
                    zipGroup.classList.remove('d-none');
                } else {
                    zipGroup.classList.add('d-none');
                    document.getElementById('zipcode').value = '';
                }
            });
        }

        // Keyboard shortcut: Ctrl+Enter to submit
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                submitForm();
            }
            // Esc returns to dashboard (only when not in a focused input with content)
            if (e.key === 'Escape') {
                var el = document.activeElement;
                var tag = el ? el.tagName : '';
                if ((tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') && el.value) {
                    el.blur(); // first Esc blurs the field
                    return;
                }
                window.location.href = 'index.php';
            }
        });

        // ── Regex auto-type matching on description blur ──
        // When operator finishes typing description, check against incident type
        // match_pattern regexes. First match wins, but only if type is still unselected.
        var descEl = document.getElementById('description');
        if (descEl) {
            descEl.addEventListener('blur', function () {
                var typeSelect = document.getElementById('in_types_id');
                // Only auto-match if no type is selected yet
                if (typeSelect.value && typeSelect.value !== '') return;

                var text = (this.value || '').trim();
                if (!text) return;

                // Iterate through types in DOM order (which matches sort order from server)
                var options = typeSelect.options;
                for (var i = 0; i < options.length; i++) {
                    var pattern = options[i].dataset.matchPattern || '';
                    if (!pattern) continue;
                    try {
                        var re = new RegExp(pattern, 'i');
                        if (re.test(text)) {
                            typeSelect.value = options[i].value;
                            // Trigger change event to show protocol and set severity
                            typeSelect.dispatchEvent(new Event('change'));
                            break;
                        }
                    } catch (ex) {
                        // Invalid regex — skip silently
                    }
                }
            });
        }
    }

    // ── Format protocol text (supports line breaks and numbered steps) ──
    function formatProtocol(text) {
        if (!text) return '';
        // Escape HTML first
        var safe = escHtml(text);
        // Convert line breaks to <br>
        safe = safe.replace(/\n/g, '<br>');
        // Bold numbered steps like "1." "2." etc at start of lines
        safe = safe.replace(/(^|\<br\>)(\d+\.)/g, '$1<strong>$2</strong>');
        return safe;
    }

    // ── Restore normal tab order after geocode ──
    function restoreTabOrder() {
        var stateEl = document.getElementById('state');
        var geocodeBtn = document.getElementById('btnGeocode');
        stateEl.setAttribute('tabindex', '8');
        geocodeBtn.setAttribute('tabindex', '6');
    }

    // ── Set default times ──
    function setDefaultTimes() {
        var now = new Date();
        var local = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
            .toISOString().slice(0, 16);
        document.getElementById('problemstart').value = local;
    }

    // ── Form submission ──
    function submitForm() {
        // Clear previous validation
        document.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
        });

        var form = document.getElementById('incidentForm');
        var data = {};

        // 2026-06-28 (Eric beta) — normalize patient DOBs to ISO before
        // serializing. The visible input shows '10/12/1970' but we want
        // '1970-10-12' in the DB so partial dates ('1964-12-00') round-trip
        // cleanly. DobHelper.readIso prefers the data-iso set on blur,
        // falls back to re-parsing the raw value (covers the case where
        // the user typed-then-immediately-submitted without blurring).
        if (window.DobHelper) {
            var dobInputs = form.querySelectorAll('.patient-dob-input');
            for (var di = 0; di < dobInputs.length; di++) {
                var iso = window.DobHelper.readIso(dobInputs[di]);
                if (iso) dobInputs[di].value = iso;
            }
        }

        // Collect all form fields
        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if (el.name && el.name !== 'csrf_token') {
                data[el.name] = el.value;
            }
        }

        // Add CSRF token
        data.csrf_token = form.querySelector('[name="csrf_token"]').value;

        // Add selected responders
        data.assign_responders = Array.from(selectedResponders);

        // Client-side validation
        var errors = [];
        if (!data.in_types_id) {
            errors.push('Incident type is required');
            document.getElementById('in_types_id').classList.add('is-invalid');
        }
        if (!data.scope || !data.scope.trim()) {
            errors.push('Incident name / scope is required');
            document.getElementById('scope').classList.add('is-invalid');
        }
        if (!data.description || !data.description.trim()) {
            errors.push('Description is required');
            document.getElementById('description').classList.add('is-invalid');
        }
        if (data.status === '3' && !data.booked_date) {
            errors.push('Scheduled date is required for scheduled incidents');
            document.getElementById('booked_date').classList.add('is-invalid');
        }

        if (errors.length > 0) {
            showAlert(errors.join('<br>'), 'danger');
            // Scroll to first invalid field
            var firstInvalid = form.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
            return;
        }

        // Disable submit buttons
        var btns = [document.getElementById('btnSubmit'), document.getElementById('btnSubmitBottom')];
        btns.forEach(function (b) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting...'; });

        fetch('api/incident-create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            if (result.errors) {
                showAlert(result.errors.join('<br>'), 'danger');
                btns.forEach(function (b) { b.disabled = false; b.innerHTML = '<i class="bi bi-check-lg me-1"></i>Submit Incident'; });
                return;
            }

            if (result.success) {
                showAlert(
                    '<strong>Incident ' + (result.incident_number || ('#' + result.ticket_id)) + ' created successfully!</strong>' +
                    (result.protocol ? '<br><small>Protocol: ' + escHtml(result.protocol) + '</small>' : '') +
                    '<br><a href="index.php" class="alert-link">Return to dashboard</a>' +
                    ' &nbsp;|&nbsp; <a href="new-incident.php" class="alert-link">Create another</a>' +
                    ' &nbsp;|&nbsp; <a href="incident-detail.php?id=' + result.ticket_id + '" class="alert-link">View Incident</a>',
                    'success'
                );

                // Update submit buttons to show success
                btns.forEach(function (b) {
                    b.className = 'btn btn-success';
                    b.innerHTML = '<i class="bi bi-check-circle me-1"></i>Incident ' + (result.incident_number || ('#' + result.ticket_id)) + ' Created';
                });

                // Disable form to prevent double submission
                var fieldset = document.createElement('fieldset');
                fieldset.disabled = true;
                var formEl = document.getElementById('incidentForm');
                while (formEl.firstChild) {
                    fieldset.appendChild(formEl.firstChild);
                }
                formEl.appendChild(fieldset);
            } else {
                showAlert(result.error || 'Unknown error creating incident', 'danger');
                btns.forEach(function (b) { b.disabled = false; b.innerHTML = '<i class="bi bi-check-lg me-1"></i>Submit Incident'; });
            }
        })
        .catch(function (err) {
            showAlert('Network error: ' + err.message, 'danger');
            btns.forEach(function (b) { b.disabled = false; b.innerHTML = '<i class="bi bi-check-lg me-1"></i>Submit Incident'; });
        });
    }

    // ── Reset form ──
    function resetForm() {
        document.getElementById('incidentForm').reset();
        clearMarker();
        selectedResponders.clear();
        updateAssignedCount();
        document.querySelectorAll('.responder-check').forEach(function (cb) { cb.checked = false; });
        document.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        document.getElementById('protocolText').innerHTML = '';
        document.getElementById('protocolPanel').classList.add('d-none');
        document.getElementById('protocolContent').innerHTML = '';
        document.getElementById('bookedDateGroup').style.display = 'none';
        restoreTabOrder();
        setDefaultTimes();
        dismissAlert();
    }

    // ── Proximity Warnings ──
    function checkProximityWarnings(lat, lng) {
        if (!lat || !lng) return;

        fetch('api/proximity-warnings.php?lat=' + lat + '&lng=' + lng)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var warnings = data.warnings || [];
                renderProximityWarnings(warnings);
            })
            .catch(function () {
                // Silent fail — proximity warnings are supplemental
            });
    }

    function renderProximityWarnings(warnings) {
        var panel = document.getElementById('proximityWarningPanel');
        var content = document.getElementById('proximityWarningContent');
        var badge = document.getElementById('warningCount');

        if (!panel || !content) return;

        if (warnings.length === 0) {
            panel.classList.add('d-none');
            content.innerHTML = '';
            return;
        }

        badge.textContent = warnings.length;
        panel.classList.remove('d-none');

        var html = '';
        warnings.forEach(function (w) {
            html += '<div class="border-bottom pb-2 mb-2">';
            html += '<div class="d-flex justify-content-between align-items-start">';
            html += '<strong class="text-danger">' + escHtml(w.title) + '</strong>';
            html += '<span class="badge bg-secondary ms-2">' + w.distance + ' ' + (w.unit || 'mi') + '</span>';
            html += '</div>';
            if (w.street || w.city) {
                html += '<div class="small text-body-secondary">';
                html += '<i class="bi bi-geo-alt me-1"></i>';
                html += escHtml([w.street, w.city, w.state].filter(Boolean).join(', '));
                html += '</div>';
            }
            if (w.description) {
                html += '<div class="small mt-1">' + escHtml(w.description) + '</div>';
            }
            html += '</div>';
        });

        content.innerHTML = html;

        // Scroll warning panel into view so dispatcher notices it
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Alert helpers ──
    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
        area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function dismissAlert() {
        document.getElementById('alertArea').innerHTML = '';
    }

    // ── Utility ──
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Phase 41-fix (2026-06-27): Places typeahead on the street input ──
    // a beta tester: "Configure a 'place' in the settings, when typing that
    // into a new incident street address, nothing happens." Wires
    // api/places.php?action=search as a debounced typeahead. Results
    // render into the #streetPlacesDropdown div (added in new-incident.php).
    // Picking a result fills street/city/state/lat/lon and drops the map
    // marker. Pure additive: the existing Lookup button + Enter-to-geocode
    // still work as before.
    function bindPlacesTypeahead() {
        var streetEl   = document.getElementById('street');
        var dropdownEl = document.getElementById('streetPlacesDropdown');
        if (!streetEl || !dropdownEl) return;

        var timer = null;
        var lastFetched = '';

        function hideDropdown() {
            dropdownEl.classList.add('d-none');
            dropdownEl.innerHTML = '';
        }

        function renderResults(places) {
            if (!places || places.length === 0) {
                hideDropdown();
                return;
            }
            var html = '';
            for (var i = 0; i < places.length; i++) {
                var p = places[i];
                var sub = [p.street, p.city, p.state].filter(function (v) { return v && v.length; }).join(', ');
                html += '<button type="button" class="list-group-item list-group-item-action py-1 px-2 small"' +
                        ' data-place-id="' + escHtml(String(p.id)) + '"' +
                        ' data-street="' + escHtml(p.street || '') + '"' +
                        ' data-city="' + escHtml(p.city || '') + '"' +
                        ' data-state="' + escHtml(p.state || '') + '"' +
                        ' data-lat="' + escHtml(String(p.lat || '')) + '"' +
                        ' data-lon="' + escHtml(String(p.lon || '')) + '">' +
                        '<i class="bi bi-bookmark-fill text-primary me-1"></i>' +
                        '<strong>' + escHtml(p.name) + '</strong>' +
                        (sub ? ' <span class="text-body-secondary">— ' + escHtml(sub) + '</span>' : '') +
                        '</button>';
            }
            dropdownEl.innerHTML = html;
            dropdownEl.classList.remove('d-none');

            var items = dropdownEl.querySelectorAll('[data-place-id]');
            for (var j = 0; j < items.length; j++) {
                items[j].addEventListener('mousedown', function (ev) {
                    ev.preventDefault();
                    fillFromPlace(this);
                });
            }
        }

        function fillFromPlace(btn) {
            var streetVal = btn.getAttribute('data-street') || '';
            var cityVal   = btn.getAttribute('data-city')   || '';
            var stateVal  = btn.getAttribute('data-state')  || '';
            var latVal    = parseFloat(btn.getAttribute('data-lat'));
            var lonVal    = parseFloat(btn.getAttribute('data-lon'));

            streetEl.value = streetVal;
            document.getElementById('city').value = cityVal;

            // State dropdown match — same pattern reverseGeocode uses
            // (search options by code OR name, case-insensitive)
            if (stateVal) {
                var stateEl = document.getElementById('state');
                var options = stateEl.options;
                for (var k = 0; k < options.length; k++) {
                    if (options[k].textContent.toLowerCase() === stateVal.toLowerCase() ||
                        options[k].value.toLowerCase() === stateVal.toLowerCase()) {
                        stateEl.value = options[k].value;
                        break;
                    }
                }
            }

            // Drop the marker via the existing map helper if coords are valid
            if (!isNaN(latVal) && !isNaN(lonVal) && typeof setMarker === 'function') {
                setMarker(latVal, lonVal);
            }

            hideDropdown();

            // Move focus to city (mirrors the geocoder's post-success behavior)
            var cityField = document.getElementById('city');
            cityField.focus();
            cityField.select();
        }

        streetEl.addEventListener('input', function () {
            clearTimeout(timer);
            var q = this.value.trim();
            if (q.length < 2) {
                hideDropdown();
                return;
            }
            if (q === lastFetched) return; // dedupe identical fetches
            timer = setTimeout(function () {
                lastFetched = q;
                fetch('api/places.php?action=search&q=' + encodeURIComponent(q), {
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (streetEl.value.trim() !== q) return; // user kept typing; result is stale
                    renderResults(data && data.places ? data.places : []);
                })
                .catch(function () { hideDropdown(); });
            }, 250);
        });

        // Hide on blur (with small delay so click-on-result fires first via mousedown)
        streetEl.addEventListener('blur', function () {
            setTimeout(hideDropdown, 200);
        });

        // Re-show on focus if there's already a 2+ char query and stale results
        streetEl.addEventListener('focus', function () {
            if (this.value.trim().length >= 2 && dropdownEl.innerHTML.length > 0) {
                dropdownEl.classList.remove('d-none');
            }
        });
    }

    // ── Phase 41-fix-2 (2026-06-27, Eric): Constituent (contacts) lookup ──
    // Wires the #phone field's blur to api/constituents.php?phone=X.
    // - 0 matches → hide the Caller Info panel + multi-match picker
    // - 1 match  → auto-fill Reported By (only if currently empty so we
    //   don't clobber the dispatcher's typing), show the Caller Info panel
    // - >1 match → show the multi-match picker below the phone input;
    //   click an entry to commit (same fill behavior as 1-match)
    // The Caller Info panel renders the matched constituent's name +
    // primary address + phone numbers, and treats the `miscellaneous`
    // column as a "warning / notes" field — rendered as an alert callout
    // when non-empty. Per Eric 2026-06-27: use existing miscellaneous field
    // as the warning text, no schema change.
    function bindConstituentLookup() {
        var phoneEl   = document.getElementById('phone');
        var pickerEl  = document.getElementById('constituentPicker');
        var panelEl   = document.getElementById('callerInfoPanel');
        var contentEl = document.getElementById('callerInfoContent');
        var closeBtn  = document.getElementById('btnCloseCallerInfo');
        if (!phoneEl || !panelEl || !contentEl) return;

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                panelEl.classList.add('d-none');
            });
        }

        function hidePicker() {
            if (!pickerEl) return;
            pickerEl.classList.add('d-none');
            pickerEl.innerHTML = '';
        }

        function showCallerInfo(c) {
            var name = c.contact || '';
            var addr = [c.street, c.apartment, c.city, c.state, c.post_code]
                .filter(function (v) { return v && String(v).length; }).join(', ');
            var phones = [];
            ['phone', 'phone_2', 'phone_3', 'phone_4'].forEach(function (k) {
                if (c[k] && String(c[k]).length) {
                    var typ = c[k + '_type'] ? ' (' + c[k + '_type'] + ')' : '';
                    phones.push(escHtml(c[k]) + escHtml(typ));
                }
            });
            var misc = (c.miscellaneous || '').trim();

            var html = '<div class="fw-semibold mb-1">' + escHtml(name) + '</div>';
            if (addr) {
                html += '<div class="small text-body-secondary mb-1"><i class="bi bi-geo-alt me-1"></i>' +
                        escHtml(addr) + '</div>';
            }
            if (phones.length) {
                html += '<div class="small text-body-secondary mb-1"><i class="bi bi-telephone me-1"></i>' +
                        phones.join(' &middot; ') + '</div>';
            }
            if (c.email) {
                html += '<div class="small text-body-secondary mb-1"><i class="bi bi-envelope me-1"></i>' +
                        escHtml(c.email) + '</div>';
            }
            if (misc) {
                // Warning-style callout — same visual weight as a protocol
                // panel so it can't be missed for known problem callers.
                html += '<div class="alert alert-warning small mt-2 mb-0 py-2 px-2">' +
                        '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                        '<strong>Notes / Warnings:</strong> ' + escHtml(misc) + '</div>';
            }
            contentEl.innerHTML = html;
            panelEl.classList.remove('d-none');
        }

        function fillFromConstituent(c) {
            var contactEl = document.getElementById('contact');
            // Only auto-fill Reported By if the dispatcher hasn't already
            // typed something there. The miscellaneous warning + name
            // always go in the panel regardless.
            if (contactEl && !contactEl.value.trim() && c.contact) {
                contactEl.value = c.contact;
            }
            showCallerInfo(c);
            hidePicker();
        }

        function renderPicker(matches) {
            if (!pickerEl) {
                // No picker container — fall back to first match silently
                fillFromConstituent(matches[0]);
                return;
            }
            var html = '';
            for (var i = 0; i < matches.length; i++) {
                var c = matches[i];
                var sub = [c.street, c.city, c.state].filter(function (v) {
                    return v && String(v).length;
                }).join(', ');
                html += '<button type="button" class="list-group-item list-group-item-action py-1 px-2 small"' +
                        ' data-idx="' + i + '">' +
                        '<i class="bi bi-person-vcard text-primary me-1"></i>' +
                        '<strong>' + escHtml(c.contact || '(no name)') + '</strong>' +
                        (sub ? ' <span class="text-body-secondary">— ' + escHtml(sub) + '</span>' : '') +
                        '</button>';
            }
            pickerEl.innerHTML = html;
            pickerEl.classList.remove('d-none');

            var items = pickerEl.querySelectorAll('[data-idx]');
            for (var j = 0; j < items.length; j++) {
                items[j].addEventListener('mousedown', function (ev) {
                    ev.preventDefault();
                    var idx = parseInt(this.getAttribute('data-idx'), 10);
                    if (!isNaN(idx) && matches[idx]) fillFromConstituent(matches[idx]);
                });
            }
        }

        function lookup() {
            var raw = phoneEl.value.trim();
            var digits = raw.replace(/\D/g, '');
            // Match server-side minimum (avoid showing every constituent
            // in the database on partial input).
            if (digits.length < 4) {
                hidePicker();
                return;
            }
            fetch('api/constituents.php?phone=' + encodeURIComponent(raw), {
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var matches = (data && data.constituents) ? data.constituents : [];
                hidePicker();
                if (matches.length === 0) {
                    return; // nothing to show; leave any previously-shown panel as-is
                }
                if (matches.length === 1) {
                    fillFromConstituent(matches[0]);
                } else {
                    renderPicker(matches);
                }
            })
            .catch(function () { /* network/auth issue — silent */ });
        }

        phoneEl.addEventListener('blur', function () {
            // Slight delay so a click on the picker (which causes blur)
            // fires its mousedown handler first.
            setTimeout(lookup, 50);
        });
        // Hide picker if user resumes typing — they're overriding the choice
        phoneEl.addEventListener('input', function () {
            hidePicker();
        });
    }

})();
