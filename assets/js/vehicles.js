/**
 * NewUI v4.0 - Vehicles (Fleet Management)
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 * Manages vehicle list, detail view, edit form with privacy controls.
 */
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────
    var allVehicles    = [];
    var lookupTypes    = [];
    var lookupMembers  = [];
    var selectedId     = null;
    var sortField      = 'callsign';
    var sortDir        = 'asc';
    var filterStatus   = 'all';
    var filterAgency   = 'all';
    var filterType     = 'all';
    var searchTerm     = '';
    var searchTimer    = null;

    // ── DOM refs ─────────────────────────────────────────────────
    var $loading      = document.getElementById('loadingSpinner');
    var $main         = document.getElementById('mainContent');
    var $tbody        = document.getElementById('vehicleBody');
    var $noResults    = document.getElementById('noResults');
    var $vehicleCount = document.getElementById('vehicleCount');
    var $searchInput  = document.getElementById('searchInput');
    var $clearSearch  = document.getElementById('btnClearSearch');
    var $alertArea    = document.getElementById('alertArea');

    var $detailEmpty  = document.getElementById('detailEmpty');
    var $detailView   = document.getElementById('detailView');
    var $editView     = document.getElementById('editView');

    // ── Helpers ──────────────────────────────────────────────────
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function showAlert(msg, type) {
        type = type || 'danger';
        $alertArea.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-1 small" role="alert">' +
            esc(msg) +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
        if (type === 'success') {
            setTimeout(function () { $alertArea.innerHTML = ''; }, 3000);
        }
    }

    function formatDate(d) {
        if (!d) return '';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString();
    }

    function isExpired(dateStr) {
        if (!dateStr) return false;
        return new Date(dateStr) < new Date();
    }

    // ── API helpers ──────────────────────────────────────────────
    function apiGet(url, cb) {
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) { cb(null, data); })
            .catch(function (err) { cb(err); });
    }

    function apiPost(data, cb) {
        fetch('api/vehicles.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.error) { cb(new Error(result.error)); }
            else { cb(null, result); }
        })
        .catch(function (err) { cb(err); });
    }

    // ── Load Vehicles ────────────────────────────────────────────
    function loadVehicles() {
        apiGet('api/vehicles.php', function (err, data) {
            if (err) {
                showAlert('Failed to load vehicles: ' + err.message);
                $loading.classList.add('d-none');
                $main.classList.remove('d-none');
                return;
            }
            allVehicles   = data.vehicles || [];
            lookupTypes   = data.types || [];
            lookupMembers = data.members || [];

            buildTypeFilters();
            populateFormDropdowns();
            renderTable();

            $loading.classList.add('d-none');
            $main.classList.remove('d-none');
        });
    }

    // ── Type Filter Buttons ──────────────────────────────────────
    function buildTypeFilters() {
        var container = document.getElementById('typeFilters');
        if (!container) return;
        var html = '<button type="button" class="btn btn-sm btn-outline-secondary filter-btn active" data-filter="type" data-value="all">All</button>';
        for (var i = 0; i < lookupTypes.length; i++) {
            html += '<button type="button" class="btn btn-sm btn-outline-secondary filter-btn" ' +
                    'data-filter="type" data-value="' + esc(lookupTypes[i].id) + '">' +
                    esc(lookupTypes[i].name) + '</button>';
        }
        container.innerHTML = html;
    }

    // ── Populate Form Dropdowns ──────────────────────────────────
    function populateFormDropdowns() {
        var typeSelect = document.getElementById('editVehicleType');
        var ownerSelect = document.getElementById('editOwner');

        if (typeSelect) {
            var html = '<option value="">-- None --</option>';
            for (var i = 0; i < lookupTypes.length; i++) {
                html += '<option value="' + lookupTypes[i].id + '">' + esc(lookupTypes[i].name) + '</option>';
            }
            typeSelect.innerHTML = html;
        }

        if (ownerSelect) {
            var html2 = '<option value="">-- No Owner --</option>';
            for (var j = 0; j < lookupMembers.length; j++) {
                var m = lookupMembers[j];
                html2 += '<option value="' + m.id + '">' +
                    esc(m.last_name + ', ' + m.first_name) +
                    (m.callsign ? ' (' + esc(m.callsign) + ')' : '') +
                    '</option>';
            }
            ownerSelect.innerHTML = html2;
        }
    }

    // ── Filter & Sort ────────────────────────────────────────────
    function getFilteredVehicles() {
        var result = [];
        var term = searchTerm.toLowerCase();

        for (var i = 0; i < allVehicles.length; i++) {
            var v = allVehicles[i];

            if (filterStatus !== 'all' && v.status !== filterStatus) continue;
            if (filterAgency !== 'all' && String(v.is_agency_vehicle) !== String(filterAgency)) continue;
            if (filterType !== 'all' && String(v.vehicle_type_id) !== String(filterType)) continue;

            if (term) {
                var haystack = (
                    (v.make || '') + ' ' + (v.model || '') + ' ' +
                    (v.callsign || '') + ' ' + (v.color || '') + ' ' +
                    (v.owner_name || '') + ' ' + (v.owner_callsign || '') + ' ' +
                    (v.year || '')
                ).toLowerCase();
                if (haystack.indexOf(term) === -1) continue;
            }

            result.push(v);
        }

        result.sort(function (a, b) {
            var va, vb;
            switch (sortField) {
                case 'callsign':
                    va = (a.callsign || 'zzz').toLowerCase();
                    vb = (b.callsign || 'zzz').toLowerCase();
                    break;
                case 'vehicle':
                    va = ((a.year || '') + ' ' + (a.make || '') + ' ' + (a.model || '')).toLowerCase();
                    vb = ((b.year || '') + ' ' + (b.make || '') + ' ' + (b.model || '')).toLowerCase();
                    break;
                case 'type':
                    va = (a.type_name || '').toLowerCase();
                    vb = (b.type_name || '').toLowerCase();
                    break;
                case 'owner':
                    va = (a.owner_name || '').toLowerCase();
                    vb = (b.owner_name || '').toLowerCase();
                    break;
                case 'status':
                    va = (a.status || '').toLowerCase();
                    vb = (b.status || '').toLowerCase();
                    break;
                default:
                    va = ''; vb = '';
            }
            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        return result;
    }

    // ── Render Table ─────────────────────────────────────────────
    function renderTable() {
        var vehicles = getFilteredVehicles();
        $vehicleCount.textContent = vehicles.length;

        if (vehicles.length === 0) {
            $tbody.innerHTML = '';
            $noResults.classList.remove('d-none');
            return;
        }
        $noResults.classList.add('d-none');

        var statusColors = { Active: 'success', 'Out of Service': 'warning', Disposed: 'secondary' };
        var html = '';
        for (var i = 0; i < vehicles.length; i++) {
            var v = vehicles[i];
            var isSelected = (selectedId && String(v.id) === String(selectedId));
            var vehicleDesc = [v.year, v.make, v.model].filter(Boolean).join(' ') || '—';
            var privacyIcon = v.is_private && !v.is_agency_vehicle
                ? '<i class="bi bi-lock-fill text-warning privacy-badge" title="Private"></i>'
                : '<i class="bi bi-unlock text-success privacy-badge" title="Public"></i>';
            var agencyBadge = v.is_agency_vehicle
                ? '<span class="badge bg-primary bg-opacity-75" style="font-size:0.6rem;">Agency</span> '
                : '';

            html += '<tr class="vehicle-row' + (isSelected ? ' selected' : '') + '" data-id="' + v.id + '">' +
                '<td class="fw-semibold font-monospace">' + esc(v.callsign || '—') + '</td>' +
                '<td>' + agencyBadge + esc(vehicleDesc) + (v.color ? ' <small class="text-body-secondary">(' + esc(v.color) + ')</small>' : '') + '</td>' +
                '<td><span class="badge bg-secondary bg-opacity-50" style="font-size:0.65rem;">' + esc(v.type_name || '—') + '</span></td>' +
                '<td>' + esc(v.owner_name || '—') + '</td>' +
                '<td><span class="badge bg-' + (statusColors[v.status] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem;">' + esc(v.status) + '</span></td>' +
                '<td class="text-center">' + privacyIcon + '</td>' +
                '</tr>';
        }
        $tbody.innerHTML = html;

        // Update sort icons
        var headers = document.querySelectorAll('#vehicleTable th.sortable');
        for (var j = 0; j < headers.length; j++) {
            var th = headers[j];
            var icon = th.querySelector('.sort-icon');
            if (th.getAttribute('data-sort') === sortField) {
                icon.className = 'bi sort-icon ' + (sortDir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down');
            } else {
                icon.className = 'bi bi-arrow-down-up sort-icon';
            }
        }
    }

    // ── Select Vehicle ───────────────────────────────────────────
    function selectVehicle(id) {
        selectedId = id;
        renderTable();

        apiGet('api/vehicles.php?id=' + id, function (err, data) {
            if (err || !data.vehicle) {
                showAlert('Failed to load vehicle details.');
                return;
            }
            renderDetail(data.vehicle);
        });
    }

    // ── Render Detail ────────────────────────────────────────────
    function renderDetail(v) {
        $detailEmpty.classList.add('d-none');
        $editView.classList.add('d-none');
        $detailView.classList.remove('d-none');

        // Header
        var vehicleDesc = [v.year, v.make, v.model].filter(Boolean).join(' ') || 'Unknown Vehicle';
        document.getElementById('detailVehicleName').textContent = vehicleDesc;
        document.getElementById('detailSubtitle').textContent = v.callsign ? 'Unit: ' + v.callsign : '';

        // Badges
        var badgeHtml = '';
        if (v.type_name) {
            badgeHtml += '<span class="badge bg-secondary">' + esc(v.type_name) + '</span>';
        }
        var statusColors = { Active: 'success', 'Out of Service': 'warning', Disposed: 'secondary' };
        badgeHtml += '<span class="badge bg-' + (statusColors[v.status] || 'secondary') + '">' + esc(v.status) + '</span>';
        if (v.is_agency_vehicle) {
            badgeHtml += '<span class="badge bg-primary">Agency</span>';
        }
        if (v.redacted) {
            badgeHtml += '<span class="badge bg-warning text-dark"><i class="bi bi-lock-fill me-1"></i>Private — some fields hidden</span>';
        }
        document.getElementById('detailBadges').innerHTML = badgeHtml;

        // Vehicle Info
        document.getElementById('detailVehicleInfo').innerHTML =
            '<div class="row g-2">' +
            '<div class="col-md-3"><div class="text-body-secondary">Year</div><div>' + esc(v.year || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Make</div><div>' + esc(v.make || '—') + '</div></div>' +
            '<div class="col-md-5"><div class="text-body-secondary">Model</div><div>' + esc(v.model || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Color</div><div>' + esc(v.color || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Unit / Callsign</div><div>' + esc(v.callsign || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Owner</div><div>' +
                (v.owner_name ? esc(v.owner_name) + (v.owner_callsign ? ' (' + esc(v.owner_callsign) + ')' : '') : '—') +
            '</div></div>' +
            '</div>';

        // Registration & Insurance
        var regHtml = '';
        if (v.redacted) {
            regHtml = '<div class="text-body-secondary fst-italic py-2">' +
                      '<i class="bi bi-lock-fill text-warning me-1"></i>' +
                      'Private vehicle — plate, VIN, and insurance information is restricted. ' +
                      'Only the vehicle owner and supervisors can view these fields.</div>';
        } else {
            var regExpClass = isExpired(v.registration_exp) ? ' text-danger fw-bold' : '';
            var insExpClass = isExpired(v.insurance_exp) ? ' text-danger fw-bold' : '';
            regHtml =
                '<div class="row g-2">' +
                '<div class="col-md-4"><div class="text-body-secondary">Plate</div><div>' +
                    esc(v.plate_number || '—') + (v.plate_state ? ' ' + esc(v.plate_state) : '') + '</div></div>' +
                '<div class="col-md-8"><div class="text-body-secondary">VIN</div><div class="font-monospace small">' + esc(v.vin || '—') + '</div></div>' +
                '<div class="col-md-6"><div class="text-body-secondary">Reg. Expiry</div><div class="' + regExpClass + '">' +
                    (v.registration_exp ? formatDate(v.registration_exp) + (isExpired(v.registration_exp) ? ' <i class="bi bi-exclamation-triangle-fill"></i> Expired' : '') : '—') +
                '</div></div>' +
                '<div class="col-md-6"><div class="text-body-secondary">Insurance Carrier</div><div>' + esc(v.insurance_carrier || '—') + '</div></div>' +
                '<div class="col-md-6"><div class="text-body-secondary">Policy #</div><div>' + esc(v.insurance_policy || '—') + '</div></div>' +
                '<div class="col-md-6"><div class="text-body-secondary">Ins. Expiry</div><div class="' + insExpClass + '">' +
                    (v.insurance_exp ? formatDate(v.insurance_exp) + (isExpired(v.insurance_exp) ? ' <i class="bi bi-exclamation-triangle-fill"></i> Expired' : '') : '—') +
                '</div></div>' +
                '</div>';
        }
        document.getElementById('detailVehicleReg').innerHTML = regHtml;

        // Notes
        document.getElementById('detailVehicleNotes').innerHTML =
            '<div style="white-space:pre-wrap;">' + esc(v.notes || '—') + '</div>';

        // Store vehicle on edit button
        document.getElementById('btnEditVehicle').setAttribute('data-vehicle', JSON.stringify(v));
        document.getElementById('btnDeleteVehicle').setAttribute('data-id', v.id);
    }

    // ── Show Edit Form ───────────────────────────────────────────
    function showEditForm(vehicle) {
        $detailEmpty.classList.add('d-none');
        $detailView.classList.add('d-none');
        $editView.classList.remove('d-none');

        var isNew = !vehicle;
        document.getElementById('editFormTitle').innerHTML =
            '<i class="bi bi-' + (isNew ? 'plus-lg' : 'pencil-square') + ' me-1"></i>' +
            (isNew ? 'Add Vehicle' : 'Edit Vehicle');

        document.getElementById('editVehicleId').value = vehicle ? vehicle.id : '';
        document.getElementById('editYear').value = vehicle ? (vehicle.year || '') : '';
        document.getElementById('editMake').value = vehicle ? (vehicle.make || '') : '';
        document.getElementById('editModel').value = vehicle ? (vehicle.model || '') : '';
        document.getElementById('editColor').value = vehicle ? (vehicle.color || '') : '';
        document.getElementById('editCallsign').value = vehicle ? (vehicle.callsign || '') : '';
        document.getElementById('editVehicleType').value = vehicle ? (vehicle.vehicle_type_id || '') : '';
        document.getElementById('editOwner').value = vehicle ? (vehicle.member_id || '') : '';
        document.getElementById('editStatus').value = vehicle ? (vehicle.status || 'Active') : 'Active';
        document.getElementById('editAgency').checked = vehicle ? !!parseInt(vehicle.is_agency_vehicle) : false;
        document.getElementById('editPlate').value = vehicle ? (vehicle.plate_number || '') : '';
        // Issue #42: plate State is now a DB-backed <select>; route through
        // the shared helper so a legacy free-text value isn't blanked.
        if (window.TCADStates) {
            window.TCADStates.setValue(document.getElementById('editPlateState'), vehicle ? (vehicle.plate_state || '') : '');
        } else {
            document.getElementById('editPlateState').value = vehicle ? (vehicle.plate_state || '') : '';
        }
        document.getElementById('editVin').value = vehicle ? (vehicle.vin || '') : '';
        document.getElementById('editRegExp').value = vehicle ? (vehicle.registration_exp || '') : '';
        document.getElementById('editInsCarrier').value = vehicle ? (vehicle.insurance_carrier || '') : '';
        document.getElementById('editInsPolicy').value = vehicle ? (vehicle.insurance_policy || '') : '';
        document.getElementById('editInsExp').value = vehicle ? (vehicle.insurance_exp || '') : '';
        document.getElementById('editPrivate').checked = vehicle ? !!parseInt(vehicle.is_private) : true;
        document.getElementById('editNotes').value = vehicle ? (vehicle.notes || '') : '';

        setTimeout(function () {
            document.getElementById('editYear').focus();
        }, 100);
    }

    // ── Save Vehicle ─────────────────────────────────────────────
    function saveVehicle() {
        var data = {
            id:                 document.getElementById('editVehicleId').value || 0,
            year:               document.getElementById('editYear').value || null,
            make:               document.getElementById('editMake').value.trim(),
            model:              document.getElementById('editModel').value.trim(),
            color:              document.getElementById('editColor').value.trim(),
            callsign:           document.getElementById('editCallsign').value.trim(),
            vehicle_type_id:    document.getElementById('editVehicleType').value || null,
            member_id:          document.getElementById('editOwner').value || null,
            status:             document.getElementById('editStatus').value,
            is_agency_vehicle:  document.getElementById('editAgency').checked ? 1 : 0,
            plate_number:       document.getElementById('editPlate').value.trim(),
            plate_state:        document.getElementById('editPlateState').value.trim().toUpperCase(),
            vin:                document.getElementById('editVin').value.trim(),
            registration_exp:   document.getElementById('editRegExp').value,
            insurance_carrier:  document.getElementById('editInsCarrier').value.trim(),
            insurance_policy:   document.getElementById('editInsPolicy').value.trim(),
            insurance_exp:      document.getElementById('editInsExp').value,
            is_private:         document.getElementById('editPrivate').checked ? 1 : 0,
            notes:              document.getElementById('editNotes').value.trim()
        };

        apiPost(data, function (err, result) {
            if (err) {
                showAlert('Failed to save vehicle: ' + err.message);
                return;
            }
            showAlert('Vehicle saved.', 'success');
            selectedId = result.id || selectedId;
            loadVehicles();
            setTimeout(function () {
                selectVehicle(selectedId);
            }, 300);
        });
    }

    // ── Delete Vehicle ───────────────────────────────────────────
    function deleteVehicle(id) {
        if (!confirm('Are you sure you want to delete this vehicle? This cannot be undone.')) return;

        apiPost({ action: 'delete', id: id }, function (err) {
            if (err) {
                showAlert('Failed to delete vehicle: ' + err.message);
                return;
            }
            showAlert('Vehicle deleted.', 'success');
            selectedId = null;
            $detailView.classList.add('d-none');
            $editView.classList.add('d-none');
            $detailEmpty.classList.remove('d-none');
            loadVehicles();
        });
    }

    // ── Event Binding ────────────────────────────────────────────
    function bindEvents() {

        // Table row click
        $tbody.addEventListener('click', function (e) {
            var row = e.target.closest('tr.vehicle-row');
            if (!row) return;
            var id = row.getAttribute('data-id');
            if (id) selectVehicle(parseInt(id, 10));
        });

        // Sort headers
        var headers = document.querySelectorAll('#vehicleTable th.sortable');
        for (var i = 0; i < headers.length; i++) {
            (function (th) {
                th.addEventListener('click', function () {
                    var field = th.getAttribute('data-sort');
                    if (sortField === field) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortField = field;
                        sortDir = 'asc';
                    }
                    renderTable();
                });
            })(headers[i]);
        }

        // Search with debounce
        $searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var val = $searchInput.value;
            $clearSearch.classList.toggle('d-none', !val);
            searchTimer = setTimeout(function () {
                searchTerm = val;
                renderTable();
            }, 300);
        });

        $clearSearch.addEventListener('click', function () {
            $searchInput.value = '';
            searchTerm = '';
            $clearSearch.classList.add('d-none');
            renderTable();
            $searchInput.focus();
        });

        // Filter buttons (delegated)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.filter-btn');
            if (!btn) return;

            var filterGroup = btn.getAttribute('data-filter');
            var filterValue = btn.getAttribute('data-value');
            if (!filterGroup) return;

            // Deactivate siblings of same group
            var card = btn.closest('.card-body') || document;
            var siblings = card.querySelectorAll('.filter-btn[data-filter="' + filterGroup + '"]');
            for (var j = 0; j < siblings.length; j++) {
                siblings[j].classList.remove('active');
            }
            btn.classList.add('active');

            if (filterGroup === 'status') filterStatus = filterValue;
            else if (filterGroup === 'agency') filterAgency = filterValue;
            else if (filterGroup === 'type') filterType = filterValue;

            renderTable();
        });

        // New vehicle button
        document.getElementById('btnNewVehicle').addEventListener('click', function () {
            selectedId = null;
            renderTable();
            showEditForm(null);
        });

        // Edit vehicle button
        document.getElementById('btnEditVehicle').addEventListener('click', function () {
            var json = this.getAttribute('data-vehicle');
            if (json) {
                try { showEditForm(JSON.parse(json)); }
                catch (ex) { showAlert('Error loading vehicle data.'); }
            }
        });

        // Delete vehicle button
        document.getElementById('btnDeleteVehicle').addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (id) deleteVehicle(parseInt(id, 10));
        });

        // Save button
        document.getElementById('btnSaveVehicle').addEventListener('click', function () {
            saveVehicle();
        });

        // Cancel edit
        document.getElementById('btnCancelEdit').addEventListener('click', function () {
            $editView.classList.add('d-none');
            if (selectedId) {
                selectVehicle(selectedId);
            } else {
                $detailEmpty.classList.remove('d-none');
            }
        });

        // Keyboard: Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (!$editView.classList.contains('d-none')) {
                    $editView.classList.add('d-none');
                    if (selectedId) {
                        selectVehicle(selectedId);
                    } else {
                        $detailEmpty.classList.remove('d-none');
                    }
                } else {
                    window.location.href = 'index.php';
                }
            }
        });
    }

    // ── Init ─────────────────────────────────────────────────────
    function init() {
        bindEvents();
        loadVehicles();
        if (window.TCADStates) { window.TCADStates.fill(document.getElementById('editPlateState')); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
