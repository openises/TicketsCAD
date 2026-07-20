(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────────────
    var allEquipment = [];
    var allTypes = [];
    var allMembers = [];
    var allTeams = [];
    var selectedId = null;
    var selectedItem = null;
    var selectedLog = [];
    var sortCol = 'name';
    var sortDir = 'asc';
    var filters = { status: 'all', type: 'all', ownership: 'all' };
    var searchTerm = '';
    var isEditing = false;
    var isCheckout = false;

    // ── DOM refs ───────────────────────────────────────────────────
    var loadingEl = document.getElementById('loadingSpinner');
    var mainEl = document.getElementById('mainContent');
    var bodyEl = document.getElementById('equipmentBody');
    var countEl = document.getElementById('equipmentCount');
    var noResultsEl = document.getElementById('noResults');
    var searchInput = document.getElementById('searchInput');
    var clearBtn = document.getElementById('btnClearSearch');
    var alertArea = document.getElementById('alertArea');

    var detailEmpty = document.getElementById('detailEmpty');
    var detailView = document.getElementById('detailView');
    var editView = document.getElementById('editView');
    var checkoutView = document.getElementById('checkoutView');

    // ── Init ───────────────────────────────────────────────────────
    loadEquipment();
    bindEvents();

    // ── Data loading ───────────────────────────────────────────────
    function loadEquipment() {
        fetch('api/equipment.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                allEquipment = data.equipment || [];
                allTypes = data.types || [];
                allMembers = data.members || [];
                allTeams = data.teams || [];

                loadingEl.classList.add('d-none');
                mainEl.classList.remove('d-none');

                renderTypeFilters();
                renderTable();
                populateFormDropdowns();
            })
            .catch(function (err) {
                loadingEl.innerHTML = '<div class="text-danger">Failed to load equipment: ' + err.message + '</div>';
            });
    }

    // ── Type filter buttons ────────────────────────────────────────
    function renderTypeFilters() {
        var container = document.getElementById('typeFilters');
        var html = '<button type="button" class="btn btn-sm btn-outline-secondary filter-btn active" data-filter="type" data-value="all">All</button>';
        allTypes.forEach(function (t) {
            html += ' <button type="button" class="btn btn-sm btn-outline-secondary filter-btn" data-filter="type" data-value="' + t.id + '">'
                + '<i class="bi ' + esc(t.icon || 'bi-box') + ' me-1"></i>' + esc(t.name) + '</button>';
        });
        container.innerHTML = html;

        container.querySelectorAll('.filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                container.querySelectorAll('.filter-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                filters.type = btn.getAttribute('data-value');
                renderTable();
            });
        });
    }

    // ── Table rendering ────────────────────────────────────────────
    function renderTable() {
        var filtered = filterEquipment(allEquipment);
        filtered = sortEquipment(filtered);

        countEl.textContent = filtered.length;
        noResultsEl.classList.toggle('d-none', filtered.length > 0);

        var html = '';
        filtered.forEach(function (item) {
            var cls = item.id === selectedId ? 'selected' : '';
            var assigned = item.assigned_member_name || item.assigned_team_name || '—';
            if (item.assigned_member_callsign) {
                assigned = item.assigned_member_callsign + ' - ' + item.assigned_member_name;
            }
            var ownerIcon = (item.ownership === 'personal')
                ? '<i class="bi bi-person-fill text-info me-1" title="Personal"></i>'
                : '<i class="bi bi-building text-body-secondary me-1" title="Organization"></i>';
            html += '<tr class="' + cls + '" data-id="' + item.id + '">'
                + '<td>' + ownerIcon + esc(item.name || '—') + '</td>'
                + '<td><i class="bi ' + esc(item.type_icon || 'bi-box') + ' me-1"></i>' + esc(item.type_name || '—') + '</td>'
                + '<td class="text-body-secondary">' + esc(item.asset_tag || '—') + '</td>'
                + '<td>' + esc(assigned) + '</td>'
                + '<td>' + statusBadge(item.status) + '</td>'
                + '<td>' + conditionBadge(item.condition) + '</td>'
                + '</tr>';
        });
        bodyEl.innerHTML = html;

        bodyEl.querySelectorAll('tr').forEach(function (row) {
            row.addEventListener('click', function () {
                selectItem(parseInt(row.getAttribute('data-id'), 10));
            });
        });
    }

    function statusBadge(status) {
        var colors = {
            'Available': 'success',
            'Checked Out': 'warning',
            'In Repair': 'info',
            'Lost': 'danger',
            'Disposed': 'secondary'
        };
        var color = colors[status] || 'secondary';
        return '<span class="badge bg-' + color + '" style="font-size:0.7rem;">' + esc(status || '—') + '</span>';
    }

    function conditionBadge(cond) {
        var colors = {
            'New': 'success',
            'Good': 'success',
            'Fair': 'warning',
            'Poor': 'danger',
            'Out of Service': 'secondary',
            'Disposed': 'secondary'
        };
        var color = colors[cond] || 'secondary';
        return '<span class="badge bg-' + color + '-subtle text-' + color + '" style="font-size:0.65rem;">' + esc(cond || '—') + '</span>';
    }

    function filterEquipment(list) {
        return list.filter(function (item) {
            if (filters.status !== 'all' && item.status !== filters.status) return false;
            if (filters.type !== 'all' && String(item.equipment_type_id) !== String(filters.type)) return false;
            if (filters.ownership !== 'all' && (item.ownership || 'organization') !== filters.ownership) return false;
            if (searchTerm) {
                var s = searchTerm.toLowerCase();
                var haystack = [item.name, item.serial_number, item.asset_tag, item.make, item.model,
                    item.location, item.assigned_member_name, item.assigned_team_name].join(' ').toLowerCase();
                if (haystack.indexOf(s) === -1) return false;
            }
            return true;
        });
    }

    function sortEquipment(list) {
        return list.sort(function (a, b) {
            var va, vb;
            switch (sortCol) {
                case 'name':      va = (a.name || '').toLowerCase(); vb = (b.name || '').toLowerCase(); break;
                case 'type':      va = (a.type_name || '').toLowerCase(); vb = (b.type_name || '').toLowerCase(); break;
                case 'asset_tag': va = (a.asset_tag || '').toLowerCase(); vb = (b.asset_tag || '').toLowerCase(); break;
                case 'assigned':  va = (a.assigned_member_name || a.assigned_team_name || '').toLowerCase();
                                  vb = (b.assigned_member_name || b.assigned_team_name || '').toLowerCase(); break;
                case 'status':    va = (a.status || ''); vb = (b.status || ''); break;
                case 'condition': va = (a.condition || ''); vb = (b.condition || ''); break;
                default:          va = ''; vb = '';
            }
            var cmp = va < vb ? -1 : va > vb ? 1 : 0;
            return sortDir === 'desc' ? -cmp : cmp;
        });
    }

    // ── Detail view ────────────────────────────────────────────────
    function selectItem(id) {
        if (isEditing || isCheckout) return;
        selectedId = id;

        fetch('api/equipment.php?id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { showAlert('danger', data.error); return; }
                selectedItem = data.equipment;
                selectedLog = data.log || [];
                renderDetail();
                renderTable();
            })
            .catch(function (err) { showAlert('danger', 'Failed to load: ' + err.message); });
    }

    function renderDetail() {
        var item = selectedItem;
        if (!item) return;

        detailEmpty.classList.add('d-none');
        detailView.classList.remove('d-none');
        editView.classList.add('d-none');
        checkoutView.classList.add('d-none');

        // Header
        document.getElementById('detailItemName').textContent = item.name || '—';
        var subtitle = [item.make, item.model].filter(Boolean).join(' ');
        if (item.serial_number) subtitle += ' — S/N: ' + item.serial_number;
        document.getElementById('detailSubtitle').textContent = subtitle || '—';

        // Badges
        var badges = '';
        badges += statusBadge(item.status);
        badges += ' ' + conditionBadge(item.condition);
        if (item.type_name) {
            badges += ' <span class="badge bg-secondary" style="font-size:0.65rem;"><i class="bi ' + esc(item.type_icon || 'bi-box') + ' me-1"></i>' + esc(item.type_name) + '</span>';
        }
        if (item.ownership === 'personal') {
            badges += ' <span class="badge bg-info-subtle text-info" style="font-size:0.65rem;"><i class="bi bi-person-fill me-1"></i>Personal</span>';
            if (item.available_for_events) {
                badges += ' <span class="badge bg-success-subtle text-success" style="font-size:0.65rem;"><i class="bi bi-calendar-check me-1"></i>Available for Events</span>';
            }
        } else {
            badges += ' <span class="badge bg-secondary-subtle text-body-secondary" style="font-size:0.65rem;"><i class="bi bi-building me-1"></i>Organization</span>';
        }
        document.getElementById('detailBadges').innerHTML = badges;

        // Checkout/Checkin buttons
        var btnCheckout = document.getElementById('btnCheckout');
        var btnCheckin = document.getElementById('btnCheckin');
        if (item.status === 'Available' || (!item.assigned_member_id && !item.assigned_team_id)) {
            btnCheckout.classList.remove('d-none');
            btnCheckin.classList.add('d-none');
        } else if (item.status === 'Checked Out') {
            btnCheckout.classList.add('d-none');
            btnCheckin.classList.remove('d-none');
        } else {
            btnCheckout.classList.add('d-none');
            btnCheckin.classList.add('d-none');
        }

        // Equipment info
        var info = '<table class="table table-sm table-borderless mb-0">';
        info += row('Asset Tag', item.asset_tag);
        info += row('Make', item.make);
        info += row('Model', item.model);
        if (item.size) info += row('Size', item.size);
        info += row('Serial #', item.serial_number);
        info += row('Purchase Date', item.purchase_date ? formatDate(item.purchase_date) : null);
        info += row('Purchase Cost', item.purchase_cost ? '$' + parseFloat(item.purchase_cost).toFixed(2) : null);
        info += row('Warranty Exp.', item.warranty_exp ? formatDate(item.warranty_exp) : null);
        info += '</table>';
        document.getElementById('detailEquipInfo').innerHTML = info;

        // Assignment
        var assign = '<table class="table table-sm table-borderless mb-0">';
        if (item.ownership === 'personal') {
            var ownerLabel = item.owner_name || '—';
            if (item.owner_callsign) ownerLabel = item.owner_callsign + ' - ' + ownerLabel;
            assign += row('Owner', ownerLabel);
            assign += row('Available for Events', item.available_for_events ? 'Yes' : 'No');
        }
        assign += row('Assigned Member', item.assigned_member_name || '—');
        assign += row('Assigned Team', item.assigned_team_name || '—');
        assign += row('Location', item.location || '—');
        assign += '</table>';
        document.getElementById('detailEquipAssign').innerHTML = assign;

        // Activity log
        document.getElementById('logCount').textContent = selectedLog.length;
        var logHtml = '';
        if (selectedLog.length === 0) {
            logHtml = '<div class="text-body-secondary">No activity recorded.</div>';
        } else {
            selectedLog.forEach(function (entry) {
                logHtml += '<div class="log-entry action-' + esc(entry.action) + '">';
                logHtml += '<div class="d-flex justify-content-between">';
                logHtml += '<span class="fw-semibold">' + actionLabel(entry.action) + '</span>';
                logHtml += '<span class="text-body-secondary">' + formatDateTime(entry.created_at) + '</span>';
                logHtml += '</div>';
                if (entry.member_name) logHtml += '<div>Member: ' + esc(entry.member_name) + '</div>';
                if (entry.performed_by_name) logHtml += '<div class="text-body-secondary">By: ' + esc(entry.performed_by_name) + '</div>';
                if (entry.notes) logHtml += '<div class="text-body-secondary fst-italic">' + esc(entry.notes) + '</div>';
                logHtml += '</div>';
            });
        }
        document.getElementById('detailEquipLog').innerHTML = logHtml;

        // Notes
        document.getElementById('detailEquipNotes').innerHTML = item.notes ? esc(item.notes) : '<span class="text-body-secondary">No notes.</span>';
    }

    function actionLabel(action) {
        var labels = {
            'checkout': '<i class="bi bi-box-arrow-right text-warning me-1"></i>Checked Out',
            'checkin': '<i class="bi bi-box-arrow-in-left text-success me-1"></i>Checked In',
            'transfer': '<i class="bi bi-arrow-left-right text-info me-1"></i>Transferred',
            'condition_change': '<i class="bi bi-exclamation-triangle text-danger me-1"></i>Condition Changed',
            'repair': '<i class="bi bi-wrench text-primary me-1"></i>Repair',
            'note': '<i class="bi bi-sticky me-1"></i>Note'
        };
        return labels[action] || esc(action);
    }

    function row(label, value) {
        if (!value) return '';
        return '<tr><td class="text-body-secondary" style="width:40%;">' + label + '</td><td>' + esc(String(value)) + '</td></tr>';
    }

    // ── Edit form ──────────────────────────────────────────────────
    function showEditForm(item) {
        isEditing = true;
        detailEmpty.classList.add('d-none');
        detailView.classList.add('d-none');
        editView.classList.remove('d-none');
        checkoutView.classList.add('d-none');

        var title = item ? 'Edit Equipment' : 'Add Equipment';
        document.getElementById('editFormTitle').innerHTML = '<i class="bi bi-pencil-square me-1"></i>' + title;

        document.getElementById('editEquipmentId').value = item ? item.id : '';
        document.getElementById('editName').value = item ? (item.name || '') : '';
        document.getElementById('editType').value = item ? (item.equipment_type_id || '') : '';
        document.getElementById('editMake').value = item ? (item.make || '') : '';
        document.getElementById('editModel').value = item ? (item.model || '') : '';
        document.getElementById('editSerial').value = item ? (item.serial_number || '') : '';
        document.getElementById('editAssetTag').value = item ? (item.asset_tag || '') : '';

        // Size field (for clothing items)
        var sizeEl = document.getElementById('editSize');
        if (sizeEl) {
            sizeEl.value = item ? (item.size || '') : '';
        }
        toggleSizeField();
        document.getElementById('editCondition').value = item ? (item.condition || 'Good') : 'Good';
        document.getElementById('editStatus').value = item ? (item.status || 'Available') : 'Available';
        document.getElementById('editOwnership').value = item ? (item.ownership || 'organization') : 'organization';
        document.getElementById('editOwnerMember').value = item ? (item.owner_member_id || '') : '';
        document.getElementById('editAvailable').checked = item ? !!parseInt(item.available_for_events, 10) : false;
        document.getElementById('editMember').value = item ? (item.assigned_member_id || '') : '';
        document.getElementById('editTeam').value = item ? (item.assigned_team_id || '') : '';
        document.getElementById('editLocation').value = item ? (item.location || '') : '';
        document.getElementById('editPurchaseDate').value = item ? (item.purchase_date || '') : '';
        document.getElementById('editPurchaseCost').value = item ? (item.purchase_cost || '') : '';
        document.getElementById('editWarrantyExp').value = item ? (item.warranty_exp || '') : '';
        document.getElementById('editNotes').value = item ? (item.notes || '') : '';

        toggleOwnershipFields();
        document.getElementById('editName').focus();
    }

    function cancelEdit() {
        isEditing = false;
        editView.classList.add('d-none');
        if (selectedItem) {
            detailView.classList.remove('d-none');
        } else {
            detailEmpty.classList.remove('d-none');
        }
    }

    function saveEquipment() {
        var name = document.getElementById('editName').value.trim();
        if (!name) { showAlert('warning', 'Equipment name is required.'); return; }

        var payload = {
            id: document.getElementById('editEquipmentId').value || 0,
            name: name,
            equipment_type_id: document.getElementById('editType').value || null,
            ownership: document.getElementById('editOwnership').value,
            owner_member_id: document.getElementById('editOwnerMember').value || null,
            available_for_events: document.getElementById('editAvailable').checked ? 1 : 0,
            make: document.getElementById('editMake').value.trim(),
            model: document.getElementById('editModel').value.trim(),
            serial_number: document.getElementById('editSerial').value.trim(),
            size: document.getElementById('editSize') ? document.getElementById('editSize').value : '',
            asset_tag: document.getElementById('editAssetTag').value.trim(),
            condition: document.getElementById('editCondition').value,
            status: document.getElementById('editStatus').value,
            assigned_member_id: document.getElementById('editMember').value || null,
            assigned_team_id: document.getElementById('editTeam').value || null,
            location: document.getElementById('editLocation').value.trim(),
            purchase_date: document.getElementById('editPurchaseDate').value || null,
            purchase_cost: document.getElementById('editPurchaseCost').value || null,
            warranty_exp: document.getElementById('editWarrantyExp').value || null,
            notes: document.getElementById('editNotes').value.trim(),
            csrf_token: window.CSRF_TOKEN || ''
        };

        fetch('api/equipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showAlert('danger', data.error); return; }
            isEditing = false;
            selectedId = data.id;
            showAlert('success', 'Equipment saved.');
            loadEquipment();
            setTimeout(function () { selectItem(data.id); }, 500);
        })
        .catch(function (err) { showAlert('danger', 'Save failed: ' + err.message); });
    }

    function deleteEquipment() {
        if (!selectedItem) return;
        if (!confirm('Delete "' + selectedItem.name + '"? This cannot be undone.')) return;

        fetch('api/equipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: selectedItem.id, csrf_token: window.CSRF_TOKEN || '' })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showAlert('danger', data.error); return; }
            selectedId = null;
            selectedItem = null;
            detailView.classList.add('d-none');
            detailEmpty.classList.remove('d-none');
            showAlert('success', 'Equipment deleted.');
            loadEquipment();
        })
        .catch(function (err) { showAlert('danger', 'Delete failed: ' + err.message); });
    }

    // ── Checkout / Checkin ─────────────────────────────────────────
    function showCheckoutForm() {
        if (!selectedItem) return;
        isCheckout = true;
        detailView.classList.add('d-none');
        checkoutView.classList.remove('d-none');

        document.getElementById('checkoutItemName').textContent = selectedItem.name;
        document.getElementById('checkoutEquipmentId').value = selectedItem.id;
        document.getElementById('checkoutNotes').value = '';

        // Populate member dropdown
        var sel = document.getElementById('checkoutMember');
        sel.innerHTML = '<option value="">— Select Member —</option>';
        allMembers.forEach(function (m) {
            var label = m.last_name + ', ' + m.first_name;
            if (m.callsign) label = m.callsign + ' - ' + label;
            sel.innerHTML += '<option value="' + m.id + '">' + esc(label) + '</option>';
        });
        sel.focus();
    }

    function cancelCheckout() {
        isCheckout = false;
        checkoutView.classList.add('d-none');
        if (selectedItem) {
            detailView.classList.remove('d-none');
        } else {
            detailEmpty.classList.remove('d-none');
        }
    }

    function confirmCheckout() {
        var memberId = document.getElementById('checkoutMember').value;
        if (!memberId) { showAlert('warning', 'Please select a member.'); return; }

        var payload = {
            action: 'checkout',
            id: document.getElementById('checkoutEquipmentId').value,
            member_id: memberId,
            notes: document.getElementById('checkoutNotes').value.trim(),
            csrf_token: window.CSRF_TOKEN || ''
        };

        fetch('api/equipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showAlert('danger', data.error); return; }
            isCheckout = false;
            showAlert('success', 'Equipment checked out.');
            loadEquipment();
            setTimeout(function () { selectItem(parseInt(payload.id, 10)); }, 500);
        })
        .catch(function (err) { showAlert('danger', 'Checkout failed: ' + err.message); });
    }

    function checkinEquipment() {
        if (!selectedItem) return;
        if (!confirm('Check in "' + selectedItem.name + '"?')) return;

        fetch('api/equipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'checkin', id: selectedItem.id, csrf_token: window.CSRF_TOKEN || '' })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showAlert('danger', data.error); return; }
            showAlert('success', 'Equipment checked in.');
            loadEquipment();
            setTimeout(function () { selectItem(selectedItem.id); }, 500);
        })
        .catch(function (err) { showAlert('danger', 'Checkin failed: ' + err.message); });
    }

    // ── Form dropdown population ───────────────────────────────────
    function populateFormDropdowns() {
        // Types
        var typeSelect = document.getElementById('editType');
        typeSelect.innerHTML = '<option value="">— Select Type —</option>';
        allTypes.forEach(function (t) {
            typeSelect.innerHTML += '<option value="' + t.id + '">' + esc(t.name) + '</option>';
        });

        // Build member options HTML once
        var memberOpts = '<option value="">— None —</option>';
        allMembers.forEach(function (m) {
            var label = m.last_name + ', ' + m.first_name;
            if (m.callsign) label = m.callsign + ' - ' + label;
            memberOpts += '<option value="' + m.id + '">' + esc(label) + '</option>';
        });

        // Members (assignment)
        document.getElementById('editMember').innerHTML = memberOpts;

        // Owner member (personal equipment)
        document.getElementById('editOwnerMember').innerHTML = memberOpts;

        // Teams
        var teamSelect = document.getElementById('editTeam');
        teamSelect.innerHTML = '<option value="">— None —</option>';
        allTeams.forEach(function (t) {
            teamSelect.innerHTML += '<option value="' + t.id + '">' + esc(t.name) + '</option>';
        });

        // Ownership toggle
        document.getElementById('editOwnership').addEventListener('change', toggleOwnershipFields);

        // Type change → toggle size field visibility
        typeSelect.addEventListener('change', toggleSizeField);
    }

    function toggleOwnershipFields() {
        var isPersonal = document.getElementById('editOwnership').value === 'personal';
        var ownerGroup = document.getElementById('ownerMemberGroup');
        var availGroup = document.getElementById('availableGroup');
        if (ownerGroup) ownerGroup.style.display = isPersonal ? '' : 'none';
        if (availGroup) availGroup.style.display = isPersonal ? '' : 'none';
    }

    /**
     * Show/hide the Size dropdown based on whether the selected type is Clothing/Uniform.
     */
    function toggleSizeField() {
        var sizeGroup = document.getElementById('sizeGroup');
        if (!sizeGroup) return;
        var typeId = document.getElementById('editType').value;
        var isClothing = false;
        for (var i = 0; i < allTypes.length; i++) {
            if (String(allTypes[i].id) === String(typeId)) {
                var name = (allTypes[i].name || '').toLowerCase();
                if (name.indexOf('clothing') !== -1 || name.indexOf('uniform') !== -1) {
                    isClothing = true;
                }
                break;
            }
        }
        sizeGroup.style.display = isClothing ? '' : 'none';
    }

    // ── Event binding ──────────────────────────────────────────────
    function bindEvents() {
        // Search
        searchInput.addEventListener('input', function () {
            searchTerm = searchInput.value.trim();
            clearBtn.classList.toggle('d-none', !searchTerm);
            renderTable();
        });
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            searchTerm = '';
            clearBtn.classList.add('d-none');
            renderTable();
        });

        // Status filters
        document.querySelectorAll('.filter-btn[data-filter="status"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.filter-btn[data-filter="status"]').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                filters.status = btn.getAttribute('data-value');
                renderTable();
            });
        });

        // Ownership filters
        document.querySelectorAll('.filter-btn[data-filter="ownership"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.filter-btn[data-filter="ownership"]').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                filters.ownership = btn.getAttribute('data-value');
                renderTable();
            });
        });

        // Sort
        document.querySelectorAll('#equipmentTable .sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var col = th.getAttribute('data-sort');
                if (sortCol === col) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortCol = col;
                    sortDir = 'asc';
                }
                renderTable();
            });
        });

        // Buttons
        document.getElementById('btnNewEquipment').addEventListener('click', function () { showEditForm(null); });
        document.getElementById('btnEditEquipment').addEventListener('click', function () { showEditForm(selectedItem); });
        document.getElementById('btnDeleteEquipment').addEventListener('click', deleteEquipment);
        document.getElementById('btnSaveEquipment').addEventListener('click', saveEquipment);
        document.getElementById('btnCancelEdit').addEventListener('click', cancelEdit);

        // Checkout/Checkin
        document.getElementById('btnCheckout').addEventListener('click', showCheckoutForm);
        document.getElementById('btnCheckin').addEventListener('click', checkinEquipment);
        document.getElementById('btnConfirmCheckout').addEventListener('click', confirmCheckout);
        document.getElementById('btnCancelCheckout').addEventListener('click', cancelCheckout);

        // Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (isEditing) { cancelEdit(); }
                else if (isCheckout) { cancelCheckout(); }
                else { window.location.href = 'index.php'; }
            }
        });
    }

    // ── Helpers ────────────────────────────────────────────────────
    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatDate(d) {
        if (!d) return '';
        var parts = d.split('-');
        if (parts.length === 3) return parts[1] + '/' + parts[2] + '/' + parts[0];
        return d;
    }

    function formatDateTime(dt) {
        if (!dt) return '';
        try {
            var d = new Date(dt);
            return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dt;
        }
    }

    function showAlert(type, msg) {
        alertArea.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-1 small" role="alert">'
            + msg + '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
        setTimeout(function () { alertArea.innerHTML = ''; }, 5000);
    }

})();
